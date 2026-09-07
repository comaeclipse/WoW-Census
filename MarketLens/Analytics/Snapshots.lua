-- Storage layout (per realm):
--   realm.items[itemID] = {
--       name=, link=, class={profession,sector,market},
--       snaps = { {t=,q=,a=,s=,l=,m=,w=,tc=}, ... }  -- oldest first, capped
--   }
-- Compact keys keep SavedVariables small:
--   t timestamp   q quantity     a auctions   s sellers
--   l lowest      m median       w wtd-avg    tc top-seller concentration (0-100)

local ML = MarketLens
local Snap = ML.Snapshots
local U = ML.Util
local D = ML.Data

local function summarize(it)
    local sellerCount = U.CountKeys(it.sellers)

    -- Top-seller concentration = largest single seller's share of quantity.
    local topQty = 0
    for _, qty in pairs(it.sellers) do
        if qty > topQty then topQty = qty end
    end
    local tc = (it.quantity > 0) and U.Round(topQty / it.quantity * 100) or 0

    return {
        t  = time(),
        q  = it.quantity,
        a  = it.auctions,
        s  = sellerCount,
        l  = it.minPrice or 0,
        m  = U.WeightedPercentile(it.prices, 0.5),
        w  = U.WeightedAverage(it.prices),
        tc = tc,
    }
end

-- How many snapshots to retain, derived from retention days assuming a
-- generous ~8 scans/day, with a floor so short-retention still keeps history.
local function snapCap()
    local days = (ML.db.settings.snapshotRetentionDays or 14)
    return math.max(days * 8, 24)
end

function Snap:Record(items)
    local realm = ML.realm
    local cap = snapCap()

    for itemID, it in pairs(items) do
        local rec = realm.items[itemID]
        if not rec then
            rec = { snaps = {} }
            realm.items[itemID] = rec
        end
        -- Refresh lightweight display metadata + classification each scan.
        rec.name  = it.name or rec.name
        rec.link  = it.link or rec.link
        rec.class = D:Classify(itemID)

        U.PushCapped(rec.snaps, summarize(it), cap)
    end
    realm.lastScan = time()
end

function Snap:Latest(itemID)
    local rec = ML.realm.items[itemID]
    if not rec or #rec.snaps == 0 then return nil end
    return rec.snaps[#rec.snaps], rec
end

-- Most recent snapshot at or before (now - seconds). Used for windowed trends.
function Snap:At(itemID, secondsAgo)
    local rec = ML.realm.items[itemID]
    if not rec then return nil end
    local cutoff = time() - secondsAgo
    local best
    for _, sn in ipairs(rec.snaps) do
        if sn.t <= cutoff then best = sn else break end
    end
    -- If nothing is old enough, fall back to the oldest we have.
    return best or rec.snaps[1]
end

function Snap:SampleCount(itemID)
    local rec = ML.realm.items[itemID]
    return rec and #rec.snaps or 0
end

-- Drop snapshots older than the retention window; prune emptied items.
function Snap:Purge()
    local cutoff = time() - (ML.db.settings.snapshotRetentionDays or 14) * 86400
    local removed = 0
    for itemID, rec in pairs(ML.realm.items) do
        local kept = {}
        for _, sn in ipairs(rec.snaps) do
            if sn.t >= cutoff then
                kept[#kept + 1] = sn
            else
                removed = removed + 1
            end
        end
        rec.snaps = kept
        if #kept == 0 then
            ML.realm.items[itemID] = nil
        end
    end
    return removed
end
