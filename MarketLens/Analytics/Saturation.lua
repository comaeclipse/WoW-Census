-- Saturation (0-100): lots of goods chasing relatively little demand.

local ML = MarketLens
local Sat = ML.Saturation
local T = ML.Trends
local Snap = ML.Snapshots
local U = ML.Util

-- Hours of market inventory = current quantity / net drain per hour.
-- Returns (hours, isDraining). When supply isn't draining, inventory is
-- effectively unlimited -> returns a large sentinel.
function Sat:SupplyPressure(itemID)
    local latest = Snap:Latest(itemID)
    if not latest then return nil end
    local w = T:Primary(itemID)
    if not w or w.velocity <= 0 then
        return math.huge, false
    end
    return latest.q / w.velocity, true
end

-- Renormalized weighted blend over whatever components are available.
local function blend(components)
    local sum, wsum = 0, 0
    for _, c in ipairs(components) do
        sum = sum + c.value * c.weight
        wsum = wsum + c.weight
    end
    if wsum == 0 then return 0 end
    return U.Round(U.Clamp(sum / wsum, 0, 100))
end

function Sat:Score(itemID)
    local latest = Snap:Latest(itemID)
    if not latest then return nil end

    local components = {}
    local function push(weight, value) components[#components+1] = { weight = weight, value = value } end

    -- One-scan signals (available immediately)

    -- Seller crowding: many concurrent sellers = crowded market. Only a real
    -- signal when the client returns seller names (blend renormalizes if not).
    if ML.realm and ML.realm.ownersAvailable then
        push(0.15, U.Scale100(latest.s, 3, 40))
    end

    -- Local oversupply: a lot of units sitting on the realm.
    push(0.20, U.Scale100(latest.q, 50, 2000))

    -- Region slow-mover: a low sale rate means demand can't absorb supply.
    -- This is the strongest one-scan saturation signal.
    local sr = ML.Region:SaleRate(itemID)
    if sr then
        push(0.30, U.Scale100(1 - sr, 0.5, 0.97)) -- sr 0.5 -> 0, sr 0.03 -> ~100
    end

    -- Multi-scan signals (need history)

    local w = T:Primary(itemID)
    if w then
        push(0.20, U.Scale100(-w.pricePct, -0.15, 0.15)) -- price falling
        push(0.20, U.Scale100(w.supplyPct, -0.3, 0.5))   -- supply growing
        local hours = self:SupplyPressure(itemID)
        push(0.20, (hours == math.huge) and 100 or U.Scale100(hours, 6, 100))
    end

    return blend(components)
end
