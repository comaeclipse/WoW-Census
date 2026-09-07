
local ML = MarketLens
local Sc = ML.Scores
local Dem = ML.Demand
local Sat = ML.Saturation
local T = ML.Trends
local Snap = ML.Snapshots
local U = ML.Util

local OPP_WEIGHTS = {
    demand      = 0.35,
    scarcity    = 0.25, -- 100 - saturation
    priceTrend  = 0.15,
    marketSize  = 0.15,
    competition = 0.10, -- fewer sellers = more controllable
}

local OPP_LABELS = {
    { min = 85, icon = "|cffff4400\240\159\148\165|r", text = "Hot" },   -- 🔥
    { min = 70, icon = "|cff33ff33\226\151\143|r",    text = "Good" },  -- 🟢
    { min = 50, icon = "|cffffcc00\226\151\143|r",    text = "Fair" },  -- 🟡
    { min = 0,  icon = "|cffff4444\226\151\143|r",    text = "Weak" },  -- 🔴
}

function Sc:OpportunityLabel(score)
    for _, l in ipairs(OPP_LABELS) do
        if score >= l.min then return l.icon, l.text end
    end
    return "", "Weak"
end

function Sc:CompetitionLevel(concentration)
    if concentration >= 60 then return "High"
    elseif concentration >= 35 then return "Medium"
    else return "Low" end
end

function Sc:Item(itemID)
    local latest, rec = Snap:Latest(itemID)
    if not latest then return nil end

    local demand, demandLabel, samples, demandSource = Dem:Score(itemID)
    local saturation = Sat:Score(itemID) or 0
    local scarcity = 100 - saturation
    local w = T:Primary(itemID)
    local pricePct = w and w.pricePct or 0

    local marketValue = latest.q * latest.w -- copper currently listed
    local marketValueGold = marketValue / 10000

    -- Thin-market proxy: how far the median sits above the lowest listing.
    local thinness = latest.m > 0 and (latest.m - latest.l) / latest.m or 0

    -- Region (TSM) cross-reference: real sale data + local-vs-region deal signal.
    local regionAvg  = ML.Region:AvgSalePrice(itemID)
    local saleRate   = ML.Region:SaleRate(itemID)
    local soldPerDay = ML.Region:SoldPerDay(itemID)
    local dealPct, isDeal
    if regionAvg and regionAvg > 0 and latest.l and latest.l > 0 then
        -- Positive = your realm's lowest buyout sits below what it sells for
        -- region-wide, i.e. a buy-low / undercut-value opportunity.
        dealPct = (regionAvg - latest.l) / regionAvg
        isDeal = dealPct >= 0.15 and (saleRate or 0) >= 0.10
    end

    local row = {
        itemID       = itemID,
        name         = rec.name or ("item:" .. itemID),
        link         = rec.link,
        class        = rec.class or ML.Data:Classify(itemID),
        latest       = latest,
        trend        = w,
        pricePct     = pricePct,
        demand       = demand,
        demandLabel  = demandLabel,
        demandSource = demandSource,
        samples      = samples,
        regionAvg    = regionAvg,
        saleRate     = saleRate,
        soldPerDay   = soldPerDay,
        dealPct      = dealPct,
        isDeal       = isDeal,
        saturation   = saturation,
        scarcity     = scarcity,
        marketValue  = marketValue,
        thinness     = thinness,
        competition  = {
            concentration = latest.tc or 0,
            level = self:CompetitionLevel(latest.tc or 0),
        },
    }

    -- Opportunity requires a demand read; otherwise it's "collecting".
    if demand then
        local priceTrendScore = U.Scale100(pricePct, -0.15, 0.15)
        local marketSizeScore = U.Scale100(marketValueGold, 5, 500)
        -- Fewer sellers = more controllable, but only when the client returns
        -- seller names; otherwise stay neutral so opportunity isn't inflated.
        local competitionScore = (ML.realm and ML.realm.ownersAvailable)
            and (100 - U.Scale100(latest.s, 5, 60)) or 50

        local opp =
              demand           * OPP_WEIGHTS.demand
            + scarcity         * OPP_WEIGHTS.scarcity
            + priceTrendScore  * OPP_WEIGHTS.priceTrend
            + marketSizeScore  * OPP_WEIGHTS.marketSize
            + competitionScore * OPP_WEIGHTS.competition

        row.opportunity = U.Round(U.Clamp(opp, 0, 100))
        row.oppIcon, row.oppText = self:OpportunityLabel(row.opportunity)
    end

    return row
end

-- Value-weighted accumulator helper.
local function newAgg()
    return { demandVW = 0, satVW = 0, trendVW = 0, oppVW = 0,
             wDemand = 0, wOpp = 0, wSat = 0, wTrend = 0,
             value = 0, count = 0, sellers = 0 }
end

-- Aggregation weight: money velocity (region copper/day changing hands) when we
-- have region data, else fall back to locally-listed value. This stops expensive
-- slow-movers from dominating a market's demand -- a cheap item that actually
-- sells counts for what it moves, not what it's listed at.
local function velocityWeight(row)
    if row.soldPerDay and row.regionAvg and row.soldPerDay > 0 and row.regionAvg > 0 then
        return math.max(row.soldPerDay * row.regionAvg, 1)
    end
    return math.max(row.marketValue, 1)
end

local function addToAgg(agg, row)
    local w = velocityWeight(row)          -- weight for the scored metrics
    agg.value = agg.value + row.marketValue -- market SIZE stays listed-value
    agg.count = agg.count + 1
    agg.sellers = agg.sellers + (row.latest.s or 0)
    agg.satVW  = agg.satVW  + row.saturation * w
    agg.wSat   = agg.wSat + w
    agg.trendVW = agg.trendVW + row.pricePct * w
    agg.wTrend  = agg.wTrend + w
    if row.demand then
        agg.demandVW = agg.demandVW + row.demand * w
        agg.wDemand = agg.wDemand + w
    end
    if row.opportunity then
        agg.oppVW = agg.oppVW + row.opportunity * w
        agg.wOpp = agg.wOpp + w
    end
end

local function finalizeAgg(agg)
    return {
        demand     = agg.wDemand > 0 and U.Round(agg.demandVW / agg.wDemand) or nil,
        saturation = agg.wSat > 0 and U.Round(agg.satVW / agg.wSat) or 0,
        trendPct   = agg.wTrend > 0 and (agg.trendVW / agg.wTrend) or 0,
        opportunity= agg.wOpp > 0 and U.Round(agg.oppVW / agg.wOpp) or nil,
        value      = agg.value,
        count      = agg.count,
    }
end

function Sc:BuildAll()
    local model = { items = {}, markets = {}, professions = {} }

    for itemID in pairs(ML.realm.items) do
        local row = self:Item(itemID)
        if row then
            model.items[itemID] = row
            local prof = row.class.profession
            local mkt  = row.class.market

            local pAgg = model.professions[prof]
            if not pAgg then
                pAgg = { key = prof, sector = row.class.sector, agg = newAgg(),
                         markets = {} }
                model.professions[prof] = pAgg
            end
            addToAgg(pAgg.agg, row)

            local mAgg = pAgg.markets[mkt]
            if not mAgg then
                mAgg = { key = mkt, profession = prof, sector = row.class.sector,
                         agg = newAgg(), items = {} }
                pAgg.markets[mkt] = mAgg
            end
            addToAgg(mAgg.agg, row)
            mAgg.items[#mAgg.items + 1] = itemID
        end
    end

    for _, p in pairs(model.professions) do
        p.summary = finalizeAgg(p.agg)
        for _, m in pairs(p.markets) do
            m.summary = finalizeAgg(m.agg)
        end
    end

    return model
end
