-- Terminology is deliberately rigorous: we measure NET SUPPLY change, never
-- "sales". A drop in quantity may be purchases, cancellations, or expirations.

local ML = MarketLens
local T = ML.Trends
local Snap = ML.Snapshots

T.Windows = {
    { key = "1h",  seconds = 3600 },
    { key = "6h",  seconds = 6 * 3600 },
    { key = "24h", seconds = 24 * 3600 },
    { key = "3d",  seconds = 3 * 86400 },
    { key = "7d",  seconds = 7 * 86400 },
}

-- Compare the latest snapshot with the newest snapshot at least `seconds` old.
-- Returns a table of deltas, or nil if there isn't a distinct earlier sample.
function T:Window(itemID, seconds)
    local latest = Snap:Latest(itemID)
    local past = Snap:At(itemID, seconds)
    if not latest or not past or past == latest then return nil end

    local hours = (latest.t - past.t) / 3600
    if hours <= 0 then return nil end

    local pastQ = math.max(past.q, 1)
    local pastW = math.max(past.w, 1)

    return {
        hours       = hours,
        dQuantity   = latest.q - past.q,
        supplyPct   = (latest.q - past.q) / pastQ,          -- +growth / -contraction
        pricePct    = (latest.w - past.w) / pastW,          -- weighted-avg movement
        -- Net supply velocity: units disappearing per hour (positive = draining).
        velocity    = (past.q - latest.q) / hours,
        sellerPct   = past.s > 0 and (latest.s - past.s) / past.s or 0,
        latest      = latest,
        past        = past,
    }
end

-- Default trend window used by the dashboard (24h, falling back to widest
-- available if 24h has no earlier sample yet).
function T:Primary(itemID)
    return self:Window(itemID, 24 * 3600)
        or self:Window(itemID, 6 * 3600)
        or self:Window(itemID, 3600)
end
