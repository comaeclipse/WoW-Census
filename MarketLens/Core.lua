
local ADDON, _ns = ...

MarketLens = MarketLens or {}
local ML = MarketLens

ML.ADDON = ADDON
ML.VERSION = "0.1.0"
ML.DB_VERSION = 3

-- Modules populate these tables as their files load (see .toc order).
ML.Scanner    = ML.Scanner    or {}
ML.Parser     = ML.Parser     or {}
ML.Snapshots  = ML.Snapshots  or {}
ML.Trends     = ML.Trends     or {}
ML.Region     = ML.Region     or {}
ML.Demand     = ML.Demand     or {}
ML.Saturation = ML.Saturation or {}
ML.Scores     = ML.Scores     or {}
ML.Population  = ML.Population  or {}
ML.Data       = ML.Data       or {}
ML.UI         = ML.UI         or {}
ML.Util       = ML.Util       or {}

-- Simple event bus so modules can react without each owning a frame.
ML.callbacks = {}
function ML:On(event, fn)
    self.callbacks[event] = self.callbacks[event] or {}
    table.insert(self.callbacks[event], fn)
end
function ML:Fire(event, ...)
    local list = self.callbacks[event]
    if not list then return end
    for _, fn in ipairs(list) do
        local ok, err = pcall(fn, ...)
        if not ok then
            self:Debug("callback error on '%s': %s", event, tostring(err))
        end
    end
end

local PREFIX = "|cff33aaffMarketLens|r: "

function ML:Print(fmt, ...)
    local msg = (select("#", ...) > 0) and string.format(fmt, ...) or fmt
    DEFAULT_CHAT_FRAME:AddMessage(PREFIX .. msg)
end

function ML:Debug(fmt, ...)
    if not (self.db and self.db.settings and self.db.settings.debug) then return end
    local msg = (select("#", ...) > 0) and string.format(fmt, ...) or fmt
    DEFAULT_CHAT_FRAME:AddMessage(PREFIX .. "|cff888888[dbg]|r " .. msg)
end

local DEFAULT_SETTINGS = {
    snapshotRetentionDays = 14,
    populationRetentionDays = 35, -- daily identity observations retained for 30-day metrics
    minimumSamples        = 3,   -- snapshots needed before demand is scored
    scanThrottle          = 0.5, -- seconds between paged AH queries
    sellerSampleSeconds   = 1200, -- hard budget for /ml scan paged seller sampling
    sellerSamplePages     = 60,  -- evenly-spread pages for /ml scan paged
    ownerResolveDelay     = 0.15, -- local re-read delay for nil seller names
    ownerResolvePasses    = 2,   -- local re-reads before accepting the page
    debug                 = false,
    minimap               = { angle = 214, hide = false },
}

local function defaultDB()
    return {
        version = ML.DB_VERSION,
        realms  = {},
        settings = CopyTable and CopyTable(DEFAULT_SETTINGS) or DEFAULT_SETTINGS,
    }
end

function ML:RealmKey()
    local realm = GetRealmName() or "UnknownRealm"
    local faction = UnitFactionGroup and UnitFactionGroup("player") or "Neutral"
    return realm .. "-" .. (faction or "Neutral")
end

-- Stable user-facing client flavor used as part of /who character identity.
function ML:GameFlavor()
    if WOW_PROJECT_ID and WOW_PROJECT_MAINLINE and WOW_PROJECT_ID == WOW_PROJECT_MAINLINE then
        return "retail"
    end
    if WOW_PROJECT_ID and WOW_PROJECT_CLASSIC and WOW_PROJECT_ID == WOW_PROJECT_CLASSIC then
        return "classic-era"
    end
    return "tbc-anniversary"
end

function ML:Realm()
    local key = self:RealmKey()
    local realms = self.db.realms
    if not realms[key] then
        realms[key] = { snapshots = {}, items = {}, markets = {} }
    end
    return realms[key], key
end

local function migrate(db)
    if not db.version then db.version = 1 end
    -- v1 -> v2: settings.debug added; harmless to backfill any missing keys.
    for k, v in pairs(DEFAULT_SETTINGS) do
        if db.settings[k] == nil then db.settings[k] = v end
    end
    db.version = ML.DB_VERSION
end

function ML:InitDB()
    if type(MarketLensDB) ~= "table" then
        MarketLensDB = defaultDB()
    end
    self.db = MarketLensDB
    self.db.settings = self.db.settings or {}
    migrate(self.db)
    self.realm = self:Realm()
end

-- Build a compact JSON string of this realm's per-item snapshot history for
-- the website (paste into the site's Import page, or an item page's overlay).
-- Shape: {"type":"ml-realm-v1","realm":"..","exportedAt":<unix>,
--          "capabilities":{"auctions":bool,"sellers":bool,"priceDistribution":bool},
--          "items":{"<id>":{"n":"Name","s":[[t,q,a,s,l,m,w,tc],...]}}}
function ML:BuildExport()
    local parts = {}
    for itemID, rec in pairs(self.realm.items) do
        if rec.snaps and #rec.snaps > 0 then
            local sp = {}
            for _, sn in ipairs(rec.snaps) do
                sp[#sp + 1] = string.format("[%d,%d,%d,%d,%d,%d,%d,%d]",
                    sn.t, sn.q or 0, sn.a or 0, sn.s or 0, sn.l or 0, sn.m or 0, sn.w or 0, sn.tc or 0)
            end
            local name = (rec.name or ""):gsub("\\", "\\\\"):gsub('"', '\\"')
            parts[#parts + 1] = '"' .. itemID .. '":{"n":"' .. name .. '","s":['
                .. table.concat(sp, ",") .. "]}"
        end
    end
    local caps = self.realm or {}
    local function bool(v) return v and "true" or "false" end
    return '{"type":"ml-realm-v1","realm":"' .. self:RealmKey() .. '","exportedAt":' .. time()
        .. ',"capabilities":{"auctions":' .. bool(caps.auctionsAvailable ~= false)
        .. ',"sellers":' .. bool(caps.ownersAvailable == true)
        .. ',"priceDistribution":' .. bool(caps.priceDistributionAvailable ~= false) .. '}'
        .. ',"items":{' .. table.concat(parts, ",") .. "}}"
end

-- Build population samples plus first/last-seen character identity and rolling
-- daily observations. /who exposes names and roster attributes, but no GUID.
function ML:BuildPopExport()
    local function jstr(s)
        return '"' .. tostring(s or ""):gsub("\\", "\\\\"):gsub('"', '\\"') .. '"'
    end
    local function countObj(map)
        local kv = {}
        for k, v in pairs(map or {}) do kv[#kv + 1] = jstr(k) .. ":" .. tostring(v) end
        return "{" .. table.concat(kv, ",") .. "}"
    end

    local pop = self.realm.population
    local samples = {}
    if pop and pop.samples then
        for _, s in ipairs(pop.samples) do
            samples[#samples + 1] = string.format(
                '{"t":%d,"f":%s,"o":%d,"tot":%d,"flt":%s,"c":%s,"r":%s}',
                s.t or 0, jstr(s.faction), s.observed or 0, s.total or 0,
                jstr(s.filter), countObj(s.classes), countObj(s.races))
        end
    end

    local characters, observations = {}, {}
    if pop and pop.characters then
        for key, c in pairs(pop.characters) do
            characters[#characters + 1] = string.format(
                '{"k":%s,"fn":%s,"n":%s,"r":%s,"g":%s,"l":%d,"race":%s,"class":%s,"cf":%s,"z":%s,"fs":%d,"ls":%d,"sc":%d}',
                jstr(key), jstr(c.fullName), jstr(c.name), jstr(c.realm), jstr(c.guild),
                c.level or 0, jstr(c.race), jstr(c.class), jstr(c.classFile),
                jstr(c.zone), c.firstSeen or 0, c.lastSeen or 0, c.seenCount or 0)
            for day, d in pairs(c.days or {}) do
                observations[#observations + 1] = string.format('[%s,%d,%d,%d,%d]',
                    jstr(key), tonumber(day) or 0, d.count or 0, d.firstSeen or 0, d.lastSeen or 0)
            end
        end
    end
    table.sort(characters)
    table.sort(observations)
    return '{"type":"ml-pop-v2","realm":' .. jstr(self:RealmKey())
        .. ',"flavor":' .. jstr(self:GameFlavor())
        .. ',"exportedAt":' .. time() .. ',"samples":[' .. table.concat(samples, ",")
        .. '],"characters":[' .. table.concat(characters, ",")
        .. '],"observations":[' .. table.concat(observations, ",") .. "]}"
end

-- Refresh both on-disk export strings so the companion uploader can read them.
function ML:RefreshExports()
    if not self.db then return end
    self.db.export = self:BuildExport()
    self.db.exportRealm = self:RealmKey()
    self.db.popExport = self:BuildPopExport()
end

local boot = CreateFrame("Frame")
boot:RegisterEvent("ADDON_LOADED")
boot:RegisterEvent("PLAYER_LOGIN")
boot:RegisterEvent("PLAYER_LOGOUT")
boot:SetScript("OnEvent", function(_, event, arg1)
    if event == "ADDON_LOADED" and arg1 == ADDON then
        ML:InitDB()
        ML:Fire("DB_READY")
    elseif event == "PLAYER_LOGIN" then
        ML:Fire("LOGIN")
        if ML.Scanner.Init then ML.Scanner:Init() end
        if ML.Population.Init then ML.Population:Init() end
        if ML.UI.Init then ML.UI:Init() end
        -- Keep the on-disk exports fresh after each scan (flushed on logout/reload).
        ML:On("SCAN_COMPLETE", function() ML:RefreshExports() end)
        ML:On("POP_SCAN_COMPLETE", function() ML:RefreshExports() end)
        ML:Print("v%s loaded. Type |cffffff00/ml|r to open, |cffffff00/ml scan|r at the AH.", ML.VERSION)
    elseif event == "PLAYER_LOGOUT" then
        -- Stash fresh single-line JSON exports into SavedVariables so the
        -- companion uploader (tools/upload-realm.ps1) can read them from disk.
        ML:RefreshExports()
    end
end)

SLASH_MARKETLENS1 = "/marketlens"
SLASH_MARKETLENS2 = "/ml"
SlashCmdList["MARKETLENS"] = function(msg)
    -- Keep the raw text (case preserved) so /who filters like z-"Shattrath City"
    -- survive; match keywords case-insensitively off a lowered copy.
    local raw = (msg or ""):gsub("^%s+", ""):gsub("%s+$", "")
    msg = raw:lower()
    if msg == "scan" then
        ML.Scanner:StartScan()
    elseif msg == "scan paged" then
        ML.Scanner:StartScan(true)
    elseif msg == "scan sellers full" then
        ML.Scanner:StartScan(true, true)
    elseif msg == "scan replicate" then
        if ML.Scanner.StartReplicate then ML.Scanner:StartReplicate() end
    elseif msg == "who" or msg:match("^who%s") then
        -- Slash execution is a hardware event, so SendWho is allowed here.
        ML.Population:Scan(raw:sub(4))
    elseif msg == "purge" then
        local snaps = ML.Snapshots:Purge()
        local pops  = ML.Population:Purge()
        ML:Print("Purged %d expired snapshot(s), %d population sample(s).", snaps, pops)
    elseif msg == "export" then
        if ML.UI.ShowExport then ML.UI:ShowExport() end
    elseif msg == "minimap" or msg == "hide" then
        local mm = ML.db.settings.minimap
        mm.hide = not mm.hide
        if ML.UI.Minimap then ML.UI.Minimap:Refresh() end
        ML:Print("Minimap button %s.", mm.hide and "hidden (use /ml to open)" or "shown")
    elseif msg == "debug" then
        ML.db.settings.debug = not ML.db.settings.debug
        ML:Print("Debug %s.", ML.db.settings.debug and "on" or "off")
    elseif msg == "reset" then
        MarketLensDB = defaultDB()
        ML:InitDB()
        ML:Print("Database reset.")
    else
        if ML.UI.Toggle then ML.UI:Toggle() end
    end
end
