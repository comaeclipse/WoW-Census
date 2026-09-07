-- Observed-population sampling via /who.
--
-- A user-triggered scan issues one C_FriendList.SendWho, then captures the
-- returned roster on WHO_LIST_UPDATE and folds race/class/level counts into a
-- stored sample. This mirrors how the AH Scanner captures a listings snapshot,
-- but for the realm's player directory rather than the Auction House.
--
-- Two Blizzard constraints shape the design (see warcraft.wiki.gg):
--   * SendWho must originate from a HARDWARE EVENT (a real key/mouse action)
--     and is rate-limited server-side; requests sent too fast are silently
--     dropped. So a scan is only ever kicked off from a button/slash press,
--     never from a timer, and we add a small client-side cooldown on top.
--   * Small result sets print to chat and never reach GetWhoInfo unless
--     SetWhoToUi(true) routes them into the API. We enable it around a scan
--     and restore the default afterward so normal /who still prints to chat.
--
-- A /who is a SAMPLE of currently-visible online players (capped ~50 by the
-- server), never a realm census. The UI labels it "Observed Population" and
-- counts sightings, not unique characters.

local ML = MarketLens
local Pop = ML.Population
local U = ML.Util

local COOLDOWN = 6      -- seconds we enforce between our own SendWho calls
local SCAN_CAP = 1000   -- retained samples per realm (also purged by retention)

Pop.pending = false
Pop.lastSend = 0

-- API shims: C_FriendList (Classic/Retail) with legacy global fallbacks

local function sendWho(filter)
    if C_FriendList and C_FriendList.SendWho then
        C_FriendList.SendWho(filter)
    elseif SendWho then
        SendWho(filter)
    end
end

local function setWhoToUi(on)
    if C_FriendList and C_FriendList.SetWhoToUi then
        C_FriendList.SetWhoToUi(on)
    elseif SetWhoToUI then
        SetWhoToUI(on)
    end
end

local function numResults()
    if C_FriendList and C_FriendList.GetNumWhoResults then
        return C_FriendList.GetNumWhoResults()
    elseif GetNumWhoResults then
        return GetNumWhoResults()
    end
    return 0, 0
end

local function whoInfo(i)
    if C_FriendList and C_FriendList.GetWhoInfo then
        return C_FriendList.GetWhoInfo(i)
    elseif GetWhoInfo then
        -- Legacy positional return -> normalize to the modern table shape.
        local name, guild, level, race, class, zone, classFileName, sex = GetWhoInfo(i)
        return { fullName = name, fullGuildName = guild, level = level,
                 raceStr = race, classStr = class, area = zone,
                 filename = classFileName, gender = sex }
    end
end

local function trim(s)
    return tostring(s or ""):gsub("^%s+", ""):gsub("%s+$", "")
end

local function identity(info)
    local fullName = trim(info.fullName)
    if fullName == "" then return nil end
    local name, realm = fullName:match("^([^-]+)%-(.+)$")
    name = trim(name or fullName)
    realm = trim(realm or GetRealmName() or "UnknownRealm")
    if name == "" then return nil end
    local normalizedRealm = realm:lower():gsub("[%s']", "")
    local key = ML:GameFlavor() .. ":" .. normalizedRealm .. ":" .. name:lower()
    return key, name .. "-" .. realm, name, realm
end

-- Class-mix -> inferred crafting demand
--
-- Heuristic weights: which crafting markets a class buys from. Every raiding
-- class demands Enchanting (chants), Alchemy (flasks/potions) and gems
-- (Jewelcrafting); the differentiator is the armor-type profession that makes
-- their gear (plate=Blacksmithing, mail/leather=Leatherworking, cloth=Tailoring).
-- Keys are locale-independent class tokens (WhoInfo.filename); values map to
-- ML.Data.Professions keys. This is a demand HINT, never observed sales.
Pop.ClassAffinity = {
    WARRIOR = { Blacksmithing = 3, Gear = 2, Enchanting = 2, Alchemy = 1, Jewelcrafting = 1 },
    PALADIN = { Blacksmithing = 3, Gear = 2, Enchanting = 2, Alchemy = 1, Jewelcrafting = 1 },
    HUNTER  = { Leatherworking = 3, Gear = 2, Engineering = 1, Enchanting = 1, Alchemy = 1 },
    ROGUE   = { Leatherworking = 3, Gear = 2, Alchemy = 2, Enchanting = 1 },
    SHAMAN  = { Leatherworking = 3, Gear = 2, Enchanting = 1, Jewelcrafting = 1, Alchemy = 1 },
    DRUID   = { Leatherworking = 3, Gear = 2, Alchemy = 1, Enchanting = 1 },
    PRIEST  = { Tailoring = 3, Gear = 2, Enchanting = 2, Alchemy = 1, Jewelcrafting = 1 },
    MAGE    = { Tailoring = 3, Gear = 2, Enchanting = 2, Jewelcrafting = 1, Alchemy = 1 },
    WARLOCK = { Tailoring = 3, Gear = 2, Enchanting = 2, Alchemy = 1 },
    -- Retail-only classes. Harmless on Classic/TBC where these tokens never
    -- appear in /who results. Armor-type -> crafter follows modern rules
    -- (plate=Blacksmithing, leather & mail=Leatherworking, cloth=Tailoring).
    DEATHKNIGHT = { Blacksmithing = 3, Gear = 2, Enchanting = 2, Alchemy = 1, Jewelcrafting = 1 },
    MONK        = { Leatherworking = 3, Gear = 2, Alchemy = 1, Enchanting = 1, Jewelcrafting = 1 },
    DEMONHUNTER = { Leatherworking = 3, Gear = 2, Alchemy = 2, Enchanting = 1 },
    EVOKER      = { Leatherworking = 3, Gear = 2, Enchanting = 2, Alchemy = 1, Jewelcrafting = 1 },
}

-- Rank professions by summed class affinity across an aggregate's class mix.
-- Scores are normalized 0-100 relative to the top profession. Returns a list
-- of { prof=, weight=, score= } sorted by weight, or {} if no class data.
function Pop:ProfessionDemand(agg)
    if not agg or not agg.classes then return {} end
    local weights = {}
    for token, n in pairs(agg.classes) do
        local aff = self.ClassAffinity[token]
        if aff then
            for prof, w in pairs(aff) do
                weights[prof] = (weights[prof] or 0) + w * n
            end
        end
    end

    local max = 0
    for _, w in pairs(weights) do if w > max then max = w end end

    local out = {}
    for prof, w in pairs(weights) do
        out[#out + 1] = {
            prof   = prof,
            weight = w,
            score  = max > 0 and U.Round(w / max * 100) or 0,
        }
    end
    table.sort(out, function(a, b) return a.weight > b.weight end)
    return out
end

-- Kick off one population sample. MUST be reached from a hardware event
-- (a button or slash-command press) or Blizzard drops the SendWho.
function Pop:Scan(filter)
    filter = filter and filter:gsub("^%s+", ""):gsub("%s+$", "") or ""
    if filter == "" then
        local maxL = (GetMaxPlayerLevel and GetMaxPlayerLevel()) or 70
        filter = "1-" .. maxL
    end

    local now = GetTime()
    -- A still-pending scan within the cooldown is genuinely in flight; once the
    -- cooldown has elapsed with no WHO_LIST_UPDATE, treat it as dropped by the
    -- server (SendWho throttling) and let this press start a fresh one.
    if self.pending and now - self.lastSend < COOLDOWN then
        ML:Print("Population scan already in progress...")
        return
    end
    if now - self.lastSend < COOLDOWN then
        ML:Print("Population scans are throttled \226\128\148 wait a few seconds and retry.")
        return
    end

    self.pending = true
    self.pendingFilter = filter
    self.lastSend = now
    setWhoToUi(true)
    ML:Print("Population scan: |cffffffff%s|r ...", filter)
    ML:Fire("POP_SCAN_START", filter)
    sendWho(filter)
end

-- Fold the current WHO_LIST results into a stored sample. Called on
-- WHO_LIST_UPDATE, but only acts on a scan we started (self.pending).
function Pop:Capture()
    if not self.pending then return end
    self.pending = false
    setWhoToUi(false) -- restore default so manual /who prints to chat again

    local shown, total = numResults()
    shown = shown or 0

    local classes, races, roster = {}, {}, {}
    local observed = 0
    for i = 1, shown do
        local info = whoInfo(i)
        if info then
            local token = info.filename or info.classStr or "UNKNOWN"
            local race  = info.raceStr or "Unknown"
            classes[token] = (classes[token] or 0) + 1
            races[race]    = (races[race] or 0) + 1
            observed = observed + 1
            local key, fullName, name, realm = identity(info)
            if key and not roster[key] then
                roster[key] = {
                    key = key, fullName = fullName, name = name, realm = realm,
                    guild = info.fullGuildName or "", level = info.level or 0,
                    race = race, class = info.classStr or token,
                    classFile = token, zone = info.area or "",
                }
            end
        end
    end

    local sample = {
        t        = time(),
        faction  = UnitFactionGroup and UnitFactionGroup("player") or "Neutral",
        filter   = self.pendingFilter,
        observed = observed,
        total    = total or observed, -- server-reported online matching the filter
        classes  = classes,
        races    = races,
    }
    local unique = self:Record(sample, roster)
    ML:Print("Sampled %d of %d online player(s); recorded %d unique character(s).",
        observed, sample.total, unique)
    ML:Fire("POP_SCAN_COMPLETE", sample)
end

function Pop:Store()
    local realm = ML.realm
    realm.population = realm.population or { samples = {}, characters = {} }
    realm.population.samples = realm.population.samples or {}
    realm.population.characters = realm.population.characters or {}
    return realm.population
end

function Pop:Record(sample, roster)
    local store = self:Store()
    U.PushCapped(store.samples, sample, SCAN_CAP)
    store.lastScan = sample.t
    local day = math.floor(sample.t / 86400)
    local unique = 0
    for key, seen in pairs(roster or {}) do
        unique = unique + 1
        local c = store.characters[key]
        if not c then
            c = { key = key, firstSeen = sample.t, seenCount = 0, days = {} }
            store.characters[key] = c
        end
        c.fullName, c.name, c.realm = seen.fullName, seen.name, seen.realm
        c.guild, c.level, c.race = seen.guild, seen.level, seen.race
        c.class, c.classFile, c.zone = seen.class, seen.classFile, seen.zone
        c.firstSeen = math.min(c.firstSeen or sample.t, sample.t)
        c.lastSeen = math.max(c.lastSeen or 0, sample.t)
        c.seenCount = (c.seenCount or 0) + 1
        c.days = c.days or {}
        local d = c.days[day] or { count = 0, firstSeen = sample.t, lastSeen = sample.t }
        d.count = (d.count or 0) + 1
        d.firstSeen = math.min(d.firstSeen or sample.t, sample.t)
        d.lastSeen = math.max(d.lastSeen or 0, sample.t)
        c.days[day] = d
    end
    self:Purge()
    return unique
end

-- Drop old samples and daily observations. Lifetime character records remain so
-- returning/new-to-dataset status survives beyond the rolling analytics window.
function Pop:Purge()
    local store = ML.realm.population
    if not store or not store.samples then return 0 end
    local days = ML.db.settings.populationRetentionDays or 35
    local cutoff = time() - days * 86400
    local cutoffDay = math.floor(cutoff / 86400)
    local kept, removed = {}, 0
    for _, s in ipairs(store.samples) do
        if s.t >= cutoff then kept[#kept + 1] = s else removed = removed + 1 end
    end
    store.samples = kept
    for _, c in pairs(store.characters or {}) do
        for day in pairs(c.days or {}) do
            if (tonumber(day) or 0) < cutoffDay then c.days[day] = nil end
        end
    end
    return removed
end

-- Unique-character metrics from the rolling observation window. These are
-- characters, not Battle.net accounts; /who cannot associate alts or renames.
function Pop:CharacterStats()
    local store = self:Store()
    local today = math.floor(time() / 86400)
    local stats = { lifetime = 0, today = 0, week = 0, month = 0,
                    newWeek = 0, returningWeek = 0, active3 = 0 }
    local weekStart, monthStart = today - 6, today - 29
    for _, c in pairs(store.characters) do
        stats.lifetime = stats.lifetime + 1
        local inToday, inWeek, inMonth, monthDays = false, false, false, 0
        for day in pairs(c.days or {}) do
            day = tonumber(day) or 0
            if day == today then inToday = true end
            if day >= weekStart then inWeek = true end
            if day >= monthStart then inMonth, monthDays = true, monthDays + 1 end
        end
        if inToday then stats.today = stats.today + 1 end
        if inWeek then
            stats.week = stats.week + 1
            if (c.firstSeen or 0) >= weekStart * 86400 then
                stats.newWeek = stats.newWeek + 1
            else
                stats.returningWeek = stats.returningWeek + 1
            end
        end
        if inMonth then stats.month = stats.month + 1 end
        if monthDays >= 3 then stats.active3 = stats.active3 + 1 end
    end
    stats.returningRate = stats.week > 0 and U.Round(stats.returningWeek / stats.week * 100) or 0
    return stats
end

-- Sum class/race sightings across samples within `secondsAgo` (nil = all
-- retained). Returns { classes=, races=, observed=, samples=, lastScan= } or
-- nil when nothing has been sampled yet.
function Pop:Aggregate(secondsAgo)
    local store = ML.realm.population
    if not store or not store.samples then return nil end
    local cutoff = secondsAgo and (time() - secondsAgo) or 0

    local classes, races = {}, {}
    local observed, samples, lastScan = 0, 0, nil
    for _, s in ipairs(store.samples) do
        if s.t >= cutoff then
            samples  = samples + 1
            observed = observed + (s.observed or 0)
            lastScan = s.t
            for k, v in pairs(s.classes or {}) do classes[k] = (classes[k] or 0) + v end
            for k, v in pairs(s.races or {})   do races[k]   = (races[k] or 0) + v end
        end
    end
    if samples == 0 then return nil end
    return { classes = classes, races = races, observed = observed,
             samples = samples, lastScan = lastScan }
end

function Pop:Latest()
    local store = ML.realm.population
    if not store or not store.samples or #store.samples == 0 then return nil end
    return store.samples[#store.samples]
end

local frame = CreateFrame("Frame")
frame:RegisterEvent("WHO_LIST_UPDATE")
frame:SetScript("OnEvent", function(_, event)
    if event == "WHO_LIST_UPDATE" then
        Pop:Capture()
    end
end)

function Pop:Init()
    -- Nothing to warm up; the WHO_LIST_UPDATE handler is live from file load.
end
