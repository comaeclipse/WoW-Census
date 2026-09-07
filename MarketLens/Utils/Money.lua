
local ML = MarketLens
local U = ML.Util

local GOLD   = "|cffffd700g|r"
local SILVER = "|cffc7c7cfs|r"
local COPPER = "|cffeda55fc|r"

function U.Money(copper, opts)
    copper = math.floor(tonumber(copper) or 0)
    opts = opts or {}
    local g = math.floor(copper / 10000)
    local s = math.floor((copper % 10000) / 100)
    local c = copper % 100

    local parts = {}
    if g > 0 then parts[#parts+1] = g .. GOLD end
    if s > 0 or g > 0 then parts[#parts+1] = s .. SILVER end
    -- Only show copper when it's the whole story or explicitly wanted.
    if not opts.short or (g == 0 and s == 0) then
        parts[#parts+1] = c .. COPPER
    end
    return table.concat(parts, " ")
end

function U.MoneyShort(copper)
    return U.Money(copper, { short = true })
end
