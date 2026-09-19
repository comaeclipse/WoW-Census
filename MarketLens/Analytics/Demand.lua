-- Demand is INFERRED, not observed. We never claim units "sold".

local ML = MarketLens
local Dem = ML.Demand
local T = ML.Trends
local Snap = ML.Snapshots
local U = ML.Util

local WEIGHTS = {
    supply     = 0.35, -- inventory draining
    price      = 0.25, -- price holding / rising
    turnover   = 0.20, -- auction count draining
    seller     = 0.10, -- fewer sellers competing
    persistence= 0.10, -- confidence from repeated observation
}

local LABELS = {
    { min = 85, text = "Very High", icon = "|cffff4400\240\159\148\165|r" }, -- 🔥
    { min = 70, text = "High",      icon = "|cff33ff33\226\150\178|r"     }, -- ▲
    { min = 50, text = "Medium",    icon = "|cffffff33=|r"                },
    { min = 30, text = "Low",       icon = "|cffff9933\226\150\188|r"     }, -- ▼
    { min = 0,  text = "Very Low",  icon = "|cffff4444\226\150\188|r"     },
}

function Dem:Label(score)
    for _, l in ipairs(LABELS) do
        if score >= l.min then return l.text, l.icon end
    end
    return "Very Low", ""
end

-- Returns score(0-100), label, samples. Until enough local history exists the
-- score is nil ("collecting").
function Dem:Score(itemID)
    local samples = Snap:SampleCount(itemID)
    local minimum = ML.db.settings.minimumSamples or 3
    if samples < minimum then
        return nil, "Collecting data", samples
    end

    local w = T:Primary(itemID)
    if not w then
        return nil, "Collecting data", samples
    end

    local supplyScore = U.Scale100(-w.supplyPct, 0, 0.5)          -- 50% drain -> 100
    local priceScore  = U.Scale100(w.pricePct, -0.15, 0.15)       -- +15% -> 100, flat -> 50
    local turnoverScore
    if not ML.realm or ML.realm.auctionsAvailable ~= false then
        local turnoverPct = w.past.a > 0 and (w.past.a - w.latest.a) / w.past.a or 0
        turnoverScore = U.Scale100(turnoverPct, -0.2, 0.5)
    end
    local sellerScore
    if ML.realm and ML.realm.ownersAvailable then
        sellerScore = U.Scale100(-w.sellerPct, -0.2, 0.5)
    end
    local persistScore = U.Scale100(samples, minimum, minimum * 3)

    local score = supplyScore * WEIGHTS.supply
        + priceScore * WEIGHTS.price
        + persistScore * WEIGHTS.persistence
    local weight = WEIGHTS.supply + WEIGHTS.price + WEIGHTS.persistence
    if turnoverScore then
        score = score + turnoverScore * WEIGHTS.turnover
        weight = weight + WEIGHTS.turnover
    end
    if sellerScore then
        score = score + sellerScore * WEIGHTS.seller
        weight = weight + WEIGHTS.seller
    end
    score = score / weight

    score = U.Round(U.Clamp(score, 0, 100))
    local label = self:Label(score)
    return score, label, samples
end
