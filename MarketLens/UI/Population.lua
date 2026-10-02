-- Column specs + row data for the Population tab: race/class distribution of
-- the observed player sample, plus an inferred crafting-demand ranking derived
-- from the class mix (see Population/WhoScan.lua). Three modes cycle on the
-- crumb click: class | race | demand | characters.

local ML = MarketLens
local UI = ML.UI
local U = ML.Util
local D = ML.Data
local PT = {}
UI.PopTable = PT

local BAR_W = 16
local FULL, EMPTY = "\226\150\136", "\226\150\145" -- █ ░

-- A compact share bar drawn from block glyphs (gold filled, gray remainder).
local function bar(pct)
    local n = U.Round((pct or 0) * BAR_W)
    if n < 0 then n = 0 elseif n > BAR_W then n = BAR_W end
    return "|cffffd100" .. string.rep(FULL, n) .. "|r|cff404040" .. string.rep(EMPTY, BAR_W - n) .. "|r"
end

local function classColor(token)
    local c = RAID_CLASS_COLORS and RAID_CLASS_COLORS[token]
    return (c and c.colorStr) or "ffffffff"
end

local function className(token)
    return (LOCALIZED_CLASS_NAMES_MALE and LOCALIZED_CLASS_NAMES_MALE[token]) or token
end

function PT:Columns(mode)
    if mode == "census" then
        return {
            { label = "Census",  width = 120, justify = "LEFT" },
            { label = "",        width = 170, justify = "LEFT" },
            { label = "",        width = 210, justify = "LEFT" },
        }
    end
    if mode == "characters" then
        return {
            { label = "Metric",     width = 190, justify = "LEFT"  },
            { label = "Characters", width = 90,  justify = "RIGHT" },
            { label = "Meaning",    width = 210, justify = "LEFT"  },
        }
    end
    if mode == "demand" then
        return {
            { label = "Profession", width = 150, justify = "LEFT"  },
            { label = "Inferred",   width = 70,  justify = "RIGHT" },
            { label = "",           width = 220, justify = "LEFT"  },
        }
    end
    return {
        { label = (mode == "race") and "Race" or "Class", width = 130, justify = "LEFT"  },
        { label = "Seen",  width = 54,  justify = "RIGHT" },
        { label = "Share", width = 54,  justify = "RIGHT" },
        { label = "",      width = 220, justify = "LEFT"  },
    }
end

local function distRows(dist, colorFn, nameFn)
    local rows, total = {}, 0
    for key, n in pairs(dist) do
        rows[#rows + 1] = { key = key, n = n }
        total = total + n
    end
    table.sort(rows, function(a, b)
        if a.n ~= b.n then return a.n > b.n end
        return tostring(a.key) < tostring(b.key)
    end)

    local out = {}
    for _, r in ipairs(rows) do
        local share = total > 0 and r.n / total or 0
        local label = nameFn and nameFn(r.key) or r.key
        if colorFn then label = "|c" .. colorFn(r.key) .. label .. "|r" end
        out[#out + 1] = {
            cells = {
                label,
                tostring(r.n),
                string.format("%.0f%%", share * 100),
                bar(share),
            },
        }
    end
    return out
end

local GRAY, WHITE, WARN, OK = "|cff808080", "|cffffffff", "|cffff8040", "|cff40c040"

local function censusIdleRows(last)
    local prof, why = ML.Population.Profiles.Current()
    local out = {}
    if not prof then
        out[#out + 1] = { cells = { "Profile", GRAY .. "--|r", why } }
    else
        out[#out + 1] = { cells = { "Profile", WHITE .. prof.label .. "|r " .. prof.faction, ML:RealmKey() } }
        out[#out + 1] = { cells = { "Collection", "|cffffd100Ready|r",
            ML.db.settings.censusPassive and OK .. "Passive collection is on|r"
                or GRAY .. "Start when you want a fresh sample|r" } }
        out[#out + 1] = { cells = { "At login", ML.db.settings.censusAutoStart and OK .. "On|r" or GRAY .. "Off|r",
            "Change it with the At login button below" } }
    end
    if last then
        out[#out + 1] = { cells = { "", "", "" } }
        out[#out + 1] = { cells = { "Last census", date("%b %d %H:%M", last.finishedAt or 0),
            (last.status == "complete" and OK or WARN) .. (last.status or "?") .. "|r"
            .. (last.stopped and GRAY .. " (stopped)|r" or "") } }
        out[#out + 1] = { cells = { "Queries run", tostring(last.done or 0),
            string.format("%d overflow, %d pre-split", last.generated or 0, last.preSplit or 0) } }
        out[#out + 1] = { cells = { "Unique characters", tostring(last.unique or 0),
            string.format("%d duplicate sightings removed", math.max((last.rows or 0) - (last.unique or 0), 0)) } }
        out[#out + 1] = { cells = { "Unresolved cells", tostring(last.unresolved or 0),
            (last.unresolved or 0) > 0 and "Still capped \226\128\148 counts are a lower bound" or "Every cell under the cap" } }
    end
    return out
end

local function censusRows()
    local s, last = ML.Population.Census:Summary()
    if not s then return censusIdleRows(last) end
    local out = {}
    local chat = ML.Population.Census:ViaChat()
    out[#out + 1] = { cells = { "Collection", OK .. "Active|r",
        chat and "Press Enter after the next query appears in chat" or (s.passive and "Passive collection is on" or "Use Collect Next Now") } }
    out[#out + 1] = { cells = { "Progress", string.format("%s%d|r / %d queries", WHITE, s.done, s.total),
        bar(s.total > 0 and s.done / s.total or 0) } }
    local l = s.last
    if l then
        local result
        if not l.capped then
            result = string.format("%s%d players|r |TInterface\\RaidFrame\\ReadyCheck-Ready:12|t", OK, l.observed)
        elseif l.split > 0 then
            result = string.format("%s%d \226\128\148 capped, split into %d|r", WARN, l.observed, l.split)
        else
            result = string.format("%s%d \226\128\148 capped, cannot split further|r", WARN, l.observed)
        end
        out[#out + 1] = { cells = { "Last result", l.filter or "", result } }
    end
    if s.inflight then
        out[#out + 1] = { cells = { "Waiting", WHITE .. s.inflight .. "|r",
            s.passive and GRAY .. "A later world click will retry it|r" or GRAY .. "Collect Next Now retries it|r" } }
    end
    if (s.backoff or 0) > 0 then
        out[#out + 1] = { cells = { "Server backoff", tostring(s.backoff) .. "s", GRAY .. "Collection resumes from a later input|r" } }
    end
    out[#out + 1] = { cells = { "Next", s.next and (WHITE .. s.next .. "|r") or GRAY .. "--|r",
        chat and "Use Show Next Query, then press Enter" or (s.passive and "Keep playing or use Collect Next Now" or "Use Collect Next Now") } }
    out[#out + 1] = { cells = { "Characters observed", tostring(s.unique),
        string.format("%d capped cell(s): counts there are lower bounds", s.unresolved or 0) } }
    return out
end

function PT:Rows(mode)
    if mode == "census" then return censusRows() end
    if mode == "characters" then
        local s = ML.Population:CharacterStats()
        return {
            { cells = { "Seen today", tostring(s.today), "Unique character names" } },
            { cells = { "Seen in 7 days", tostring(s.week), "Rolling observation window" } },
            { cells = { "Seen in 30 days", tostring(s.month), "Rolling observation window" } },
            { cells = { "Returning (7 days)", tostring(s.returningWeek), "First seen before this week" } },
            { cells = { "New (7 days)", tostring(s.newWeek), "New to this dataset" } },
            { cells = { "Seen on 3+ days", tostring(s.active3), "Within the last 30 days" } },
            { cells = { "7-day return rate", tostring(s.returningRate) .. "%", "Returning / unique this week" } },
            { cells = { "Lifetime known", tostring(s.lifetime), "Since identity tracking began" } },
        }
    end
    local agg = ML.Population:Aggregate()
    if not agg then return {} end

    if mode == "demand" then
        local list = ML.Population:ProfessionDemand(agg)
        local out = {}
        for _, e in ipairs(list) do
            out[#out + 1] = {
                icon  = D:ProfessionIcon(e.prof),
                cells = { e.prof, UI.ScoreText(e.score), bar(e.score / 100) },
            }
        end
        return out
    elseif mode == "race" then
        return distRows(agg.races, nil, nil)
    else
        return distRows(agg.classes, classColor, className)
    end
end
