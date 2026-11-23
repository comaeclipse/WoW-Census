
local ADDON, _ns = ...

MarketLens = MarketLens or {}
local ML = MarketLens

ML.ADDON = ADDON
ML.VERSION = "0.1.4"
ML.DB_VERSION = 5

BINDING_HEADER_MARKETLENS = "MarketLens"
BINDING_NAME_MARKETLENS_TOGGLE = "Toggle MarketLens window"

-- Modules populate these tables as their files load (see .toc order).
ML.Scanner    = ML.Scanner    or {}
ML.Parser     = ML.Parser     or {}
ML.Snapshots  = ML.Snapshots  or {}
ML.Trends     = ML.Trends     or {}
ML.Demand     = ML.Demand     or {}
ML.Saturation = ML.Saturation or {}
ML.Scores     = ML.Scores     or {}
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

-- The WoW: Forever beta runs a 1.x build (interface 16xxx; Era and SoD are
-- 115xx). Detect it by build, not WOW_PROJECT_ID: it reported as mainline
-- until the 2026-10-01 patch gave it a project id no constant matches.
function ML:IsForeverBeta()
    local toc = select(4, GetBuildInfo()) or 0
    return toc >= 16000 and toc < 20000
end

-- Stable user-facing client flavor used as part of /who character identity.
function ML:GameFlavor()
    -- The release client starts a fresh collection identity.
    if self:IsForeverBeta() then return "forever" end
    if WOW_PROJECT_ID and WOW_PROJECT_MAINLINE and WOW_PROJECT_ID == WOW_PROJECT_MAINLINE then
        return "retail"
    end
    if WOW_PROJECT_ID and WOW_PROJECT_MISTS_CLASSIC and WOW_PROJECT_ID == WOW_PROJECT_MISTS_CLASSIC then
        return "mop-classic"
    end
    if WOW_PROJECT_ID and WOW_PROJECT_CLASSIC and WOW_PROJECT_ID == WOW_PROJECT_CLASSIC then
        return "classic-era"
    end
    return "tbc-anniversary"
end

-- Collection region, resolved the same way as WoWCensus. Bucket keys are
-- "Realm-Faction" with no region, and many realm names exist in both US and
-- EU, so each bucket records the region it was collected in (StampIdentity).
function ML:Region()
    local portal = GetCVar and tostring(GetCVar("portal") or ""):upper() or ""
    -- KR is ambiguous here: Taiwan clients have been observed on that portal.
    local byPortal = { US = "us", EU = "eu", TW = "tw", CN = "cn" }
    if byPortal[portal] then return byPortal[portal] end
    local id = GetCurrentRegion and GetCurrentRegion()
    local byID = { [1] = "us", [3] = "eu", [4] = "tw", [5] = "cn" }
    local flavor = self:GameFlavor()
    return (flavor == "retail" or flavor == "forever") and (byID[id] or "unknown") or "unknown"
end

-- `since` is when the bucket was first stamped: rows older than that were
-- collected before regions were recorded. A bucket played from a second
-- region keeps its first region and records the conflict for the importer.
function ML:StampIdentity(realm)
    local region = self:Region()
    if region == "unknown" then return end
    local id = realm.identity
    if not id or not id.region then
        realm.identity = {
            game = self:GameFlavor(), region = region,
            regionID = GetCurrentRegion and GetCurrentRegion() or nil,
            realmID = GetRealmID and GetRealmID() or nil,
            realmName = GetRealmName and GetRealmName() or nil,
            faction = UnitFactionGroup and UnitFactionGroup("player") or nil,
            since = time(),
        }
    elseif id.region ~= region then
        id.conflicts = id.conflicts or {}
        id.conflicts[region] = id.conflicts[region] or time()
        self:Print("|cffff5555Region conflict:|r %s was recorded in %s; this session is %s. Its scans will be flagged on import.",
            self:RealmKey(), id.region:upper(), region:upper())
    end
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
    local realm, key = self:Realm()
    self.realm = realm
    -- Item history is stored packed (see Snapshots.lua); only this realm's
    -- is unpacked into tables. Older saves hold every realm as tables, so
    -- pack those now and let the collector drop them before play starts.
    if self.Snapshots:PackInactive(key) > 0 then collectgarbage("collect") end
    self.Snapshots:PurgeInactive(key)
    if realm.itemsPacked then
        realm.items = self.Snapshots:Unpack(realm.itemsPacked, realm.items)
        realm.itemsPacked = nil
    end
    realm.items = realm.items or {}
    self.Snapshots:Purge()
    -- Old builds cached two large JSON copies alongside the authoritative Lua
    -- tables. The uploader rebuilds both payloads directly, so discard them.
    self.db.export = nil
    self.db.popExport = nil
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

-- Legacy compatibility hook. Export payloads are now rebuilt by the companion
-- uploader instead of being duplicated in SavedVariables during play.
function ML:RefreshExports()
    if not self.db then return end
    self.db.exportRealm = self:RealmKey()
    self.db.export = nil
    self.db.popExport = nil
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
        if ML.realm then ML:StampIdentity(ML.realm) end
        if ML.Scanner.Init then ML.Scanner:Init() end
        if ML.UI.Init then ML.UI:Init() end
        ML:Print("v%s loaded. Type |cffffff00/ml|r to open, |cffffff00/ml scan|r at the AH.", ML.VERSION)
    elseif event == "PLAYER_LOGOUT" then
        -- Record the last active bucket; payloads are rebuilt by the uploader.
        ML:RefreshExports()
        -- Write this realm's item history packed. On failure the tables are
        -- left in place and saved as-is (the next load packs them).
        local realm = ML.realm
        if realm and realm.items then
            local ok, packed = pcall(ML.Snapshots.Pack, ML.Snapshots, realm.items)
            if ok then
                realm.itemsPacked = packed
                realm.items = nil
            end
        end
    end
end)

SLASH_MARKETLENS1 = "/marketlens"
SLASH_MARKETLENS2 = "/ml"
SlashCmdList["MARKETLENS"] = function(msg)
    local raw = (msg or ""):gsub("^%s+", ""):gsub("%s+$", "")
    msg = raw:lower()
    if msg == "scan" then
        ML.Scanner:StartScan()
    elseif msg == "scan paged" then
        ML.Scanner:StartScan(true)
    elseif msg:match("^scan paged fast%s*(%d*)%s*(%d*)$") then
        local startPage, stopPage = msg:match("^scan paged fast%s*(%d*)%s*(%d*)$")
        ML.Scanner:StartScan(true, true, true,
            startPage ~= "" and tonumber(startPage) or nil,
            stopPage ~= "" and tonumber(stopPage) or nil)
    elseif msg == "scan sellers full" then
        ML.Scanner:StartScan(true, true)
    elseif msg == "scan item" then
        ML:Print("Usage: /ml scan item <name>")
    elseif msg:match("^scan item%s+%S") then
        local _, prefixEnd = msg:find("^scan item%s+")
        local itemName = raw:sub(prefixEnd + 1)
        ML.Scanner:StartScan(true, true, false, nil, nil, itemName)
    elseif msg == "scan replicate" then
        if ML.Scanner.StartReplicate then ML.Scanner:StartReplicate() end
    elseif msg == "who" or msg:match("^who%s")
        or msg == "spec" or msg:match("^spec%s")
        or msg == "plan" or msg:match("^plan%s")
        or msg == "census" or msg:match("^census%s")
        or msg == "zones"
        or msg == "sweep" or msg:match("^sweep%s") then
        ML:Print("Population collection has moved to the |cffffff00WoWCensus|r addon.")
    elseif msg == "purge" then
        local snaps = ML.Snapshots:Purge()
        ML:Print("Purged %d expired snapshot(s).", snaps)
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
