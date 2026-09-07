-- Column specs + row data for the aggregate levels:
--   level 0 = professions, level 1 = markets within a profession.

local ML = MarketLens
local UI = ML.UI
local MT = {}
UI.MarketTable = MT

-- Saturation reads "high = bad", so color it inverted vs. a normal score.
local function SatText(v)
    if v == nil then return "|cff888888--|r" end
    local hex
    if v >= 80 then hex = "ffff4444"
    elseif v >= 60 then hex = "ffff9933"
    elseif v >= 40 then hex = "ffffcc00"
    else hex = "ff33ff33" end
    return "|c" .. hex .. tostring(v) .. "|r"
end

local GOLD_ICON = "|TInterface\\MoneyFrame\\UI-GoldIcon:12:12:0:0|t"
local function goldText(copper)
    local g = math.floor((copper or 0) / 10000)
    if g >= 1000000 then
        return string.format("%.1fM%s", g / 1000000, GOLD_ICON)
    elseif g >= 1000 then
        return string.format("%.0fk%s", g / 1000, GOLD_ICON)
    end
    return g .. GOLD_ICON
end

function MT:Columns(level)
    local nameLabel = (level == 0) and "Profession" or "Market"
    return {
        { label = nameLabel,  width = 150, justify = "LEFT"  },
        { label = "Demand",   width = 70,  justify = "RIGHT" },
        { label = "Satur.",   width = 60,  justify = "RIGHT" },
        { label = "Trend",    width = 60,  justify = "RIGHT" },
        { label = "Value",    width = 80,  justify = "RIGHT" },
        { label = "Opp.",     width = 60,  justify = "RIGHT" },
    }
end

-- Sort aggregate entries: highest opportunity first, nil opp last, then value.
local function sortAgg(a, b)
    local ao, bo = a.summary.opportunity, b.summary.opportunity
    if ao and bo and ao ~= bo then return ao > bo end
    if ao and not bo then return true end
    if bo and not ao then return false end
    return (a.summary.value or 0) > (b.summary.value or 0)
end

local function buildRow(key, summary, iconProfession, level)
    return {
        key = key,
        summary = summary,
        label = key,
        level = level,
        icon = ML.Data:ProfessionIcon(iconProfession or key),
        cells = {
            key .. "  |cff808080(" .. summary.count .. ")|r",
            summary.demand and UI.ScoreText(summary.demand) or "|cff808080…|r",
            SatText(summary.saturation),
            UI.PctText(summary.trendPct),
            goldText(summary.value),
            summary.opportunity and UI.ScoreText(summary.opportunity) or "|cff808080…|r",
        },
    }
end

function MT:Rows(model, nav)
    local list = {}
    local iconProf

    if nav.level == 0 then
        for _, p in pairs(model.professions) do
            list[#list + 1] = { key = p.key, summary = p.summary, prof = p.key }
        end
    else
        iconProf = nav.profession
        local p = model.professions[nav.profession]
        if p then
            for _, m in pairs(p.markets) do
                list[#list + 1] = { key = m.key, summary = m.summary, prof = nav.profession }
            end
        end
    end

    table.sort(list, sortAgg)

    local rows = {}
    for _, e in ipairs(list) do
        rows[#rows + 1] = buildRow(e.key, e.summary, e.prof, nav.level)
    end
    return rows
end
