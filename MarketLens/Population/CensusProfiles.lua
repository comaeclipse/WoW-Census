-- Census scan profiles: which /who queries make sense for this client + faction.
--
-- A profile is picked automatically from the running client (WOW_PROJECT_ID,
-- the TOC build, and the Classic season/ruleset APIs) and the player's faction.
-- It only supplies the STARTING plan and the split vocabulary; Population/
-- Census.lua refines it adaptively from actual results (a capped query is split
-- level -> class -> race -> hotspot zone) and remembers learned splits per realm.
--
-- Bands are deliberately coarse and disjoint: every online player matches
-- exactly one backbone query, so a fully-resolved census counts nobody twice.
-- Zone lists are NOT a separate pass (a zone sweep on top of a level sweep only
-- re-sees the same players); they are the last-resort split for a cell that is
-- still capped after level, class and race are all pinned.
--
-- Race/class names are English and used verbatim in r-"..." filters, so this
-- targets enUS clients. Class filters use the client's localized class names.

local ML = MarketLens
local Pop = ML.Population
local P = {}
Pop.Profiles = P

local ALL_CLASSIC = { "WARRIOR", "PALADIN", "HUNTER", "ROGUE", "PRIEST", "SHAMAN", "MAGE", "WARLOCK", "DRUID" }

-- Vanilla race/class rules (Era, Hardcore, SoD). TBC adds the Draenei/Blood Elf
-- rows below. Profiles without a table (MoP, Retail, Forever) try every race.
local VANILLA_RACES_BY_CLASS = {
    WARRIOR = { "Human", "Dwarf", "Night Elf", "Gnome", "Orc", "Undead", "Tauren", "Troll" },
    PALADIN = { "Human", "Dwarf" },
    HUNTER  = { "Dwarf", "Night Elf", "Orc", "Tauren", "Troll" },
    ROGUE   = { "Human", "Dwarf", "Night Elf", "Gnome", "Orc", "Undead", "Troll" },
    PRIEST  = { "Human", "Dwarf", "Night Elf", "Undead", "Troll" },
    SHAMAN  = { "Orc", "Tauren", "Troll" },
    MAGE    = { "Human", "Gnome", "Undead", "Troll" },
    WARLOCK = { "Human", "Gnome", "Orc", "Undead" },
    DRUID   = { "Night Elf", "Tauren" },
}

local TBC_RACES_BY_CLASS = {}
do
    local extra = {
        WARRIOR = { "Draenei" }, PALADIN = { "Draenei", "Blood Elf" },
        HUNTER = { "Draenei", "Blood Elf" }, ROGUE = { "Blood Elf" },
        PRIEST = { "Draenei", "Blood Elf" }, SHAMAN = { "Draenei" },
        MAGE = { "Draenei", "Blood Elf" }, WARLOCK = { "Blood Elf" },
    }
    for class, races in pairs(VANILLA_RACES_BY_CLASS) do
        local list = {}
        for _, r in ipairs(races) do list[#list + 1] = r end
        for _, r in ipairs(extra[class] or {}) do list[#list + 1] = r end
        TBC_RACES_BY_CLASS[class] = list
    end
end

local VANILLA_RACES = {
    Alliance = { "Human", "Dwarf", "Night Elf", "Gnome" },
    Horde    = { "Orc", "Undead", "Tauren", "Troll" },
}

local VANILLA_HOTSPOTS = {
    Alliance = { "Stormwind City", "Ironforge", "Darnassus", "Elwynn Forest", "Dun Morogh", "Teldrassil", "Westfall", "Darkshore" },
    Horde    = { "Orgrimmar", "Undercity", "Thunder Bluff", "Durotar", "Tirisfal Glades", "Mulgore", "The Barrens" },
    shared   = { "Stranglethorn Vale", "Tanaris", "Eastern Plaguelands", "Winterspring", "Silithus", "Burning Steppes" },
}

P.profiles = {
    ["classic-era"] = {
        label = "Classic Era",
        bands = { {1,9}, {10,19}, {20,29}, {30,39}, {40,49}, {50,59}, {60,60} },
        classes = {
            Alliance = { "WARRIOR", "PALADIN", "HUNTER", "ROGUE", "PRIEST", "MAGE", "WARLOCK", "DRUID" },
            Horde    = { "WARRIOR", "SHAMAN", "HUNTER", "ROGUE", "PRIEST", "MAGE", "WARLOCK", "DRUID" },
        },
        races = VANILLA_RACES, racesByClass = VANILLA_RACES_BY_CLASS,
        hotspots = VANILLA_HOTSPOTS,
    },
    ["hardcore"] = {
        label = "Classic Hardcore",
        -- Hardcore populations sit far lower; weight the leveling range heavily.
        bands = { {1,5}, {6,10}, {11,15}, {16,20}, {21,25}, {26,30}, {31,35}, {36,40},
                  {41,45}, {46,50}, {51,55}, {56,59}, {60,60} },
        classes = {
            Alliance = { "WARRIOR", "PALADIN", "HUNTER", "ROGUE", "PRIEST", "MAGE", "WARLOCK", "DRUID" },
            Horde    = { "WARRIOR", "SHAMAN", "HUNTER", "ROGUE", "PRIEST", "MAGE", "WARLOCK", "DRUID" },
        },
        races = VANILLA_RACES, racesByClass = VANILLA_RACES_BY_CLASS,
        hotspots = VANILLA_HOTSPOTS,
    },
    ["sod"] = {
        label = "Season of Discovery",
        bands = { {1,9}, {10,19}, {20,29}, {30,39}, {40,49}, {50,59}, {60,60} },
        classes = {
            Alliance = { "WARRIOR", "PALADIN", "HUNTER", "ROGUE", "PRIEST", "MAGE", "WARLOCK", "DRUID" },
            Horde    = { "WARRIOR", "SHAMAN", "HUNTER", "ROGUE", "PRIEST", "MAGE", "WARLOCK", "DRUID" },
        },
        races = VANILLA_RACES, racesByClass = VANILLA_RACES_BY_CLASS,
        hotspots = VANILLA_HOTSPOTS,
    },
    ["tbc-anniversary"] = {
        label = "TBC Anniversary",
        bands = { {1,19}, {20,39}, {40,49}, {50,57}, {58,60}, {61,64}, {65,67}, {68,69}, {70,70} },
        classes = { Alliance = ALL_CLASSIC, Horde = ALL_CLASSIC },
        races = {
            Alliance = { "Human", "Dwarf", "Night Elf", "Gnome", "Draenei" },
            Horde    = { "Orc", "Undead", "Tauren", "Troll", "Blood Elf" },
        },
        racesByClass = TBC_RACES_BY_CLASS,
        hotspots = {
            Alliance = { "Stormwind City", "Ironforge", "The Exodar", "Azuremyst Isle", "Bloodmyst Isle" },
            Horde    = { "Orgrimmar", "Undercity", "Silvermoon City", "Eversong Woods", "Ghostlands" },
            shared   = { "Shattrath City", "Hellfire Peninsula", "Zangarmarsh", "Terokkar Forest", "Nagrand",
                         "Blade's Edge Mountains", "Netherstorm", "Shadowmoon Valley" },
        },
    },
    ["mop-classic"] = {
        label = "MoP Classic",
        bands = { {1,9}, {10,19}, {20,29}, {30,39}, {40,49}, {50,59}, {60,69}, {70,79},
                  {80,84}, {85,86}, {87,88}, {89,89}, {90,90} },
        classes = {
            Alliance = { "WARRIOR", "PALADIN", "HUNTER", "ROGUE", "PRIEST", "DEATHKNIGHT", "SHAMAN", "MAGE", "WARLOCK", "MONK", "DRUID" },
            Horde    = { "WARRIOR", "PALADIN", "HUNTER", "ROGUE", "PRIEST", "DEATHKNIGHT", "SHAMAN", "MAGE", "WARLOCK", "MONK", "DRUID" },
        },
        races = {
            Alliance = { "Human", "Dwarf", "Night Elf", "Gnome", "Draenei", "Worgen", "Pandaren" },
            Horde    = { "Orc", "Undead", "Tauren", "Troll", "Blood Elf", "Goblin", "Pandaren" },
        },
        hotspots = {
            Alliance = { "Stormwind City", "Shrine of Seven Stars", "Elwynn Forest" },
            Horde    = { "Orgrimmar", "Shrine of Two Moons", "Durotar" },
            shared   = { "Timeless Isle", "Vale of Eternal Blossoms", "The Jade Forest", "Valley of the Four Winds",
                         "Krasarang Wilds", "Kun-Lai Summit", "Townlong Steppes", "Dread Wastes" },
        },
    },
    ["retail"] = {
        label = "Retail",
        -- Retail's population sits at the cap; low bands are near-empty.
        bands = { {1,59}, {60,79}, {80,84}, {85,89}, {90,90} },
        classes = {
            Alliance = { "WARRIOR", "PALADIN", "HUNTER", "ROGUE", "PRIEST", "DEATHKNIGHT", "SHAMAN", "MAGE", "WARLOCK", "MONK", "DRUID", "DEMONHUNTER", "EVOKER" },
            Horde    = { "WARRIOR", "PALADIN", "HUNTER", "ROGUE", "PRIEST", "DEATHKNIGHT", "SHAMAN", "MAGE", "WARLOCK", "MONK", "DRUID", "DEMONHUNTER", "EVOKER" },
        },
        races = {
            Alliance = { "Human", "Dwarf", "Night Elf", "Gnome", "Draenei", "Worgen", "Pandaren", "Void Elf",
                         "Lightforged Draenei", "Dark Iron Dwarf", "Kul Tiran", "Mechagnome", "Dracthyr", "Earthen", "Haranir" },
            Horde    = { "Orc", "Undead", "Tauren", "Troll", "Blood Elf", "Goblin", "Pandaren", "Nightborne",
                         "Highmountain Tauren", "Mag'har Orc", "Zandalari Troll", "Vulpera", "Dracthyr", "Earthen", "Haranir" },
        },
        hotspots = {
            Alliance = { "Stormwind City" },
            Horde    = { "Orgrimmar" },
            shared   = { "Silvermoon City", "Eversong Woods", "Zul'Aman", "Harandar", "Voidstorm" },
        },
        -- Retail returned 46-49 on queries that were demonstrably capped.
        capAt = 45,
    },
    ["forever"] = {
        label = "WoW: Forever (beta)",
        -- Bands are generated from the live level cap (it moves during beta).
        dynamicBands = true,
        classes = { Alliance = ALL_CLASSIC, Horde = ALL_CLASSIC },
        races = {
            Alliance = { "Human", "Dwarf", "Night Elf", "Gnome", "High Order Skyborne" },
            Horde    = { "Orc", "Undead", "Tauren", "Troll", "Windshaper Skyborne" },
        },
        hotspots = {
            Alliance = { "Stormwind City", "Ironforge", "Elwynn Forest", "Dun Morogh", "Teldrassil" },
            Horde    = { "Orgrimmar", "Undercity", "Durotar", "Tirisfal Glades", "Mulgore", "The Barrens" },
            shared   = { "Zephras Isle", "Dalaran", "Riverglades", "Alterac Mountains" },
        },
    },
}

local ENGLISH_CLASS = {
    WARRIOR = "Warrior", PALADIN = "Paladin", HUNTER = "Hunter", ROGUE = "Rogue", PRIEST = "Priest",
    DEATHKNIGHT = "Death Knight", SHAMAN = "Shaman", MAGE = "Mage", WARLOCK = "Warlock", MONK = "Monk",
    DRUID = "Druid", DEMONHUNTER = "Demon Hunter", EVOKER = "Evoker",
}

function P.ClassName(token)
    return (LOCALIZED_CLASS_NAMES_MALE and LOCALIZED_CLASS_NAMES_MALE[token]) or ENGLISH_CLASS[token] or token
end

local function activeSeason()
    if not (C_Seasons and C_Seasons.GetActiveSeason) then return nil end
    local ok, season = pcall(C_Seasons.GetActiveSeason)
    return ok and season or nil
end

local function hardcoreActive()
    if C_GameRules and C_GameRules.IsHardcoreActive then
        local ok, on = pcall(C_GameRules.IsHardcoreActive)
        if ok and on then return true end
    end
    local hc = Enum and Enum.SeasonID and Enum.SeasonID.Hardcore or 3
    return activeSeason() == hc
end

-- Which profile id this client should use. Separate from ML:GameFlavor(),
-- which is part of stored character identity and must not change.
function P.DetectID()
    local toc = select(4, GetBuildInfo()) or 0
    local id = WOW_PROJECT_ID
    if id and WOW_PROJECT_MAINLINE and id == WOW_PROJECT_MAINLINE then
        -- The Forever beta reports as mainline but runs a 1.x-series build.
        if toc > 0 and toc < 20000 then return "forever" end
        return "retail"
    end
    if id and WOW_PROJECT_MISTS_CLASSIC and id == WOW_PROJECT_MISTS_CLASSIC then return "mop-classic" end
    if id and WOW_PROJECT_BURNING_CRUSADE_CLASSIC and id == WOW_PROJECT_BURNING_CRUSADE_CLASSIC then
        return "tbc-anniversary"
    end
    if id and WOW_PROJECT_CLASSIC and id == WOW_PROJECT_CLASSIC then
        local sod = Enum and Enum.SeasonID and Enum.SeasonID.SeasonOfDiscovery or 2
        if activeSeason() == sod then return "sod" end
        if hardcoreActive() then return "hardcore" end
        return "classic-era"
    end
    local flavor = ML:GameFlavor()
    return P.profiles[flavor] and flavor or "tbc-anniversary"
end

-- The live beta cap: the highest level at least 3 characters on this realm
-- bucket reached in the last week (3 so one odd sighting cannot move it).
-- GetMaxPlayerLevel() may report the ruleset's cap rather than the beta's.
local function observedCap()
    local store = ML.realm and ML.realm.population
    local cutoff = time() - 7 * 86400
    local perLevel, best = {}, nil
    for _, c in pairs(store and store.characters or {}) do
        local L = tonumber(c.level) or 0
        if L > 0 and (c.lastSeen or 0) >= cutoff then
            perLevel[L] = (perLevel[L] or 0) + 1
            if perLevel[L] >= 3 and (not best or L > best) then best = L end
        end
    end
    return best
end

-- Decade bands up to the live cap, the cap on its own (everyone piles up
-- there), and one catch-all band above it in case the cap was just raised.
local function foreverBands(apiMax)
    local cap = math.min(observedCap() or apiMax, apiMax)
    local bands, lo = {}, 1
    while lo < cap do
        local hi = math.min(lo + 9 - (lo == 1 and 1 or 0), cap - 1)
        bands[#bands + 1] = { lo, hi }
        lo = hi + 1
    end
    bands[#bands + 1] = { cap, cap }
    if apiMax > cap then bands[#bands + 1] = { cap + 1, apiMax } end
    return bands
end

-- The resolved profile for this character: { id, label, faction, bands,
-- classes, races, racesByClass, hotspots, capAt, maxLevel }, or nil + reason
-- when the character has no faction yet (e.g. a neutral Pandaren).
function P.Current()
    local faction = UnitFactionGroup and UnitFactionGroup("player")
    if faction ~= "Alliance" and faction ~= "Horde" then
        return nil, "Choose a faction first -- /who only lists your own faction."
    end
    local id = P.DetectID()
    local def = P.profiles[id]
    local maxL = (GetMaxPlayerLevel and GetMaxPlayerLevel()) or 60
    local bands = def.dynamicBands and foreverBands(maxL) or def.bands
    local hot = {}
    for _, z in ipairs(def.hotspots[faction] or {}) do hot[#hot + 1] = z end
    for _, z in ipairs(def.hotspots.shared or {}) do hot[#hot + 1] = z end
    return {
        id = id, label = def.label, faction = faction, bands = bands,
        classes = def.classes[faction], races = def.races[faction],
        racesByClass = def.racesByClass, hotspots = hot,
        capAt = def.capAt or 49, maxLevel = maxL,
    }
end
