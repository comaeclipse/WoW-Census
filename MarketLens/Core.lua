
local ADDON, _ns = ...

MarketLens = MarketLens or {}
local ML = MarketLens

ML.ADDON = ADDON
ML.VERSION = "0.1.1"
ML.DB_VERSION = 4

-- Modules populate these tables as their files load (see .toc order).
ML.Scanner    = ML.Scanner    or {}
ML.Parser     = ML.Parser     or {}
ML.Snapshots  = ML.Snapshots  or {}
ML.Trends     = ML.Trends     or {}
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

-- Population collection is intentionally headless. Detailed census progress
-- belongs on the website; keep it out of chat unless addon debugging is on.
function ML:CensusPrint(fmt, ...)
    self:Debug(fmt, ...)
end

local DEFAULT_SETTINGS = {
    snapshotRetentionDays = 14,
    populationRetentionDays = 35, -- daily identity observations retained for 30-day metrics
    censusPassive         = false, -- advance an active census from normal play input
    censusAutoStart       = false, -- start/resume a census when this character logs in
    specRetentionDays     = 35, -- successful inspect observations retained
    specSampling          = true, -- opportunistically inspect exposed nearby/group unit tokens
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
    -- The Forever beta's identities were keyed "retail" while it reported as
    -- mainline; keep that so its characters stay the same records.
    if self:IsForeverBeta() then return "retail" end
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
    if realm.itemsPacked then
        realm.items = self.Snapshots:Unpack(realm.itemsPacked, realm.items)
        realm.itemsPacked = nil
    end
    realm.items = realm.items or {}
    -- Old builds cached two large JSON copies alongside the authoritative Lua
    -- tables. The uploader rebuilds both payloads directly, so discard them.
    self.db.export = nil
    self.db.popExport = nil
    if self.Population.CompactStorage then self.Population:CompactStorage() end
    if self.Population.RepairForeverKeys then self.Population:RepairForeverKeys() end
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
                '{"k":%s,"fn":%s,"n":%s,"first":%s,"last":%s,"r":%s,"g":%s,"l":%d,"race":%s,"class":%s,"cf":%s,"z":%s,"fs":%d,"ls":%d,"sc":%d}',
                jstr(key), jstr(c.fullName), jstr(c.name), jstr(c.firstName), jstr(c.lastName), jstr(c.realm), jstr(c.guild),
                c.level or 0, jstr(c.race), jstr(c.class), jstr(c.classFile),
                jstr(c.zone), c.firstSeen or 0, c.lastSeen or 0, c.seenCount or 0)
            for day, d in pairs(c.days or {}) do
                observations[#observations + 1] = string.format('[%s,%d,%d,%d,%d]',
                jstr(key), tonumber(day) or 0, d.count or d[1] or 0,
                d.firstSeen or d[2] or 0, d.lastSeen or d[3] or 0)
            end
        end
    end
    local sweeps, sweepQueries, locationObservations = {}, {}, {}
    if pop and pop.sweeps then
        for id, sweep in pairs(pop.sweeps) do
            local queryCount, cappedCount = 0, 0
            for _, q in ipairs(sweep.queries or {}) do
                queryCount = queryCount + 1
                if q.capped then cappedCount = cappedCount + 1 end
                sweepQueries[#sweepQueries + 1] = string.format(
                    '[%s,%d,%d,%s,%d,%d,%s]', jstr(id), q.index or queryCount,
                    q.t or 0, jstr(q.filter), q.observed or 0, q.total or q.observed or 0,
                    q.capped and "true" or "false")
            end
            local characterCount = 0
            for key, o in pairs(sweep.observations or {}) do
                characterCount = characterCount + 1
                locationObservations[#locationObservations + 1] = string.format(
                    '[%s,%s,%d,%d,%s,%d,%s,%s]', jstr(id), jstr(key),
                    o.queryIndex or o[1] or 0, o.observedAt or o[2] or 0,
                    jstr(o.zone or o[3]), o.level or o[4] or 0,
                    jstr(o.classFile or o[5]), jstr(o.race or o[6]))
            end
            sweeps[#sweeps + 1] = string.format(
                '{"id":%s,"startedAt":%d,"completedAt":%d,"status":%s,"label":%s,"faction":%s,"queryCount":%d,"characterCount":%d,"cappedCount":%d}',
                jstr(id), sweep.startedAt or 0, sweep.completedAt or 0,
                jstr(sweep.status or "partial"), jstr(sweep.label), jstr(sweep.faction),
                queryCount, characterCount, cappedCount)
        end
    end
    table.sort(characters)
    table.sort(observations)
    table.sort(sweeps)
    table.sort(sweepQueries)
    table.sort(locationObservations)
    return '{"type":"ml-pop-v3","realm":' .. jstr(self:RealmKey())
        .. ',"flavor":' .. jstr(self:GameFlavor())
        .. ',"exportedAt":' .. time() .. ',"samples":[' .. table.concat(samples, ",")
        .. '],"characters":[' .. table.concat(characters, ",")
        .. '],"observations":[' .. table.concat(observations, ",")
        .. '],"sweeps":[' .. table.concat(sweeps, ",")
        .. '],"sweepQueries":[' .. table.concat(sweepQueries, ",")
        .. '],"locationObservations":[' .. table.concat(locationObservations, ",") .. "]}"
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
        if ML.Scanner.Init then ML.Scanner:Init() end
        if ML.Population.Init then ML.Population:Init() end
        if ML.Population.Passive and ML.Population.Passive.Init then ML.Population.Passive:Init() end
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
    -- Keep the raw text (case preserved) so /who filters like z-"Shattrath City"
    -- survive; match keywords case-insensitively off a lowered copy.
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
    elseif msg == "who" or msg:match("^who%s") then
        -- Slash execution is a hardware event, so SendWho is allowed here.
        -- A typed query matching the census's next step runs as that step.
        local filter = raw:sub(4)
        if not ML.Population.Census:TryChatQuery(filter) then ML.Population:Scan(filter) end
    elseif msg == "spec on" or msg == "spec off" then
        local on = msg == "spec on"
        ML.db.settings.specSampling = on
        if ML.Population.Inspect then ML.Population.Inspect:Status() end
    elseif msg == "spec" then
        if ML.Population.Inspect then ML.Population.Inspect:Status() end
    elseif msg == "spec status" then
        if ML.Population.Inspect then ML.Population.Inspect:Status() end
    elseif msg == "plan" or msg == "plan snapshot" or msg == "plan full" or msg == "plan auto" or msg == "plan new" then
        -- The old plan commands are retained only so existing macros do not
        -- break. Population collection now lives in the main addon window.
        ML.UI:ShowCensus()
    elseif msg == "census" then
        local C = ML.Population.Census
        if C:IsActive() then C:RunNext() else C:Start() end
    elseif msg == "census next" then
        -- Hardware event: a macro with /ml census next can step it by key.
        -- Never starts a new census, so a finished run is not silently restarted.
        ML.Population.Census:RunNext()
    elseif msg == "census start" then
        ML.Population.Census:Start()
    elseif msg == "census stop" then
        ML.Population.Census:Stop()
    elseif msg == "census status" then
        ML.Population.Census:Status()
    elseif msg == "census passive on" or msg == "census passive off" then
        local on = msg == "census passive on"
        ML.db.settings.censusPassive = on
        if on then ML.Population.Census:SetViaChat(false, true) end
        ML:Print("Passive census: %s. %s", on and "|cff40c040on|r" or "off",
            on and "Normal movement, turning and world clicks will advance an active census."
                or "Use /ml census next, the Run Next button, or chat mode.")
    elseif msg == "census auto on" or msg == "census auto off" then
        local on = msg == "census auto on"
        ML.db.settings.censusAutoStart = on
        ML:Print("Census at login: %s.%s", on and "|cff40c040on|r" or "off",
            on and " It will advance passively only when passive census is also on." or "")
    elseif msg == "census profile" then
        ML.Population.Census:PrintProfile()
    elseif msg == "census fixed on" or msg == "census fixed off" then
        -- Applies to the next census started on this client.
        ML.db.settings.censusFixed = (msg == "census fixed on")
        ML:Print("Census list: %s.", ML.db.settings.censusFixed
            and "fixed -- decided at start, never grows" or "adaptive -- capped queries are split")
    elseif msg == "census chat on" or msg == "census chat off" then
        ML.Population.Census:SetViaChat(msg == "census chat on")
    elseif msg:match("^census cap%s") then
        -- Forever beta: pin the census to the current level cap (or "auto").
        local n = tonumber(msg:match("^census cap%s+(%d+)$"))
        ML.db.settings.censusLevelCap = n
        ML.Population.Census.profile = nil
        ML:Print("Census level cap: %s.", n and tostring(n) or "auto (from observed levels)")
    elseif msg == "census forget" then
        ML.Population.Census:Forget()
    elseif msg == "zones" then
        if ML.Population.DumpZones then ML.Population:DumpZones() end
    elseif msg == "sweep start" or msg:match("^sweep start%s+") then
        local label = raw:match("^[Ss][Ww][Ee][Ee][Pp]%s+[Ss][Tt][Aa][Rr][Tt]%s*(.*)$") or ""
        ML.Population:StartSweep(label)
    elseif msg == "sweep complete" then
        ML.Population:FinishSweep("complete")
    elseif msg == "sweep partial" then
        ML.Population:FinishSweep("partial")
    elseif msg == "sweep status" then
        ML.Population:SweepStatus()
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
