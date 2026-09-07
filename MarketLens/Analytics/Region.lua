-- Access layer for imported TradeSkillMaster region data (saleRate, soldPerDay,
-- avgSalePrice, marketValue). This is the authoritative DEMAND signal that local
-- scans cannot produce. Data is injected out-of-game into Data/TSMRegion.lua by
-- tools/update-data.ps1 (WoW addons cannot fetch URLs themselves).

local ML = MarketLens
local R = ML.Region

function R:IsLoaded()
    return type(MarketLensRegionData) == "table"
        and type(MarketLensRegionData.items) == "table"
        and MarketLensRegionData.count and MarketLensRegionData.count > 0
end

function R:Meta()
    if not self:IsLoaded() then return nil end
    return {
        region    = MarketLensRegionData.region,
        gameType  = MarketLensRegionData.gameType,
        updatedAt = MarketLensRegionData.updatedAt,
        count     = MarketLensRegionData.count,
    }
end

-- Raw record for an item: { mv, hist, asp, sr, spd } or nil.
function R:Get(itemID)
    if not self:IsLoaded() then return nil end
    return MarketLensRegionData.items[itemID]
end

function R:HasData(itemID)
    local d = self:Get(itemID)
    return d ~= nil
end

function R:SaleRate(itemID)     local d = self:Get(itemID); return d and d.sr end
function R:SoldPerDay(itemID)   local d = self:Get(itemID); return d and d.spd end
function R:AvgSalePrice(itemID) local d = self:Get(itemID); return d and d.asp end
function R:MarketValue(itemID)  local d = self:Get(itemID); return d and d.mv end

-- Human-readable freshness, e.g. "2d ago", from the ISO updatedAt string.
function R:Age()
    local meta = self:Meta()
    if not meta or not meta.updatedAt then return nil end
    local y, mo, d, h, mi, s = meta.updatedAt:match(
        "(%d+)-(%d+)-(%d+)T(%d+):(%d+):(%d+)Z")
    if not y then return nil end
    local epoch = time({ year = y, month = mo, day = d, hour = h, min = mi, sec = s })
    -- time() is local; updatedAt is UTC. Close enough for a freshness hint.
    local delta = time() - epoch
    if delta < 0 then delta = 0 end
    if SecondsToTime then return SecondsToTime(delta, true) .. " ago" end
    return math.floor(delta / 86400) .. "d ago"
end
