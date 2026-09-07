
local ML = MarketLens
local P = ML.Parser

-- Returns a normalized auction table, or nil if the row is unusable
-- (no itemID, or no buyout -- we screen on buyout markets only).
function P:GetAuction(index)
    local name, texture, count, quality, canUse, level, levelColHeader,
          minBid, minIncrement, buyoutPrice, bidAmount, highBidder,
          bidderFullName, owner, ownerFullName, saleStatus, itemID, hasAllInfo =
        GetAuctionItemInfo("list", index)

    if not itemID then return nil end
    if not buyoutPrice or buyoutPrice == 0 then return nil end -- bid-only: skip

    count = math.max(count or 1, 1)

    return {
        itemID    = itemID,
        link      = GetAuctionItemLink("list", index),
        name      = name,
        quantity  = count,
        buyout    = buyoutPrice,
        unitPrice = math.floor(buyoutPrice / count),
        -- nil when the client didn't return a seller name. Bulk (getAll) scans
        -- on Classic/Anniversary omit owners, so this is nil for every row --
        -- the scanner detects that and seller stats are hidden downstream.
        owner     = ownerFullName or owner,
        quality   = quality,
        level     = level,
    }
end

-- Modern (Retail / Cata+ Classic) replicate row. index is 0-based. The tuple
-- matches the legacy order, so itemID is still the 17th return.
-- NOTE: for commodities, buyoutPrice is already per-unit -- see the caveat in
-- Scanner:GetReplicate handling; refine on a real Retail client.
function P:GetReplicate(index)
    local name, texture, count, quality, usable, level, levelType, minBid,
          minIncrement, buyoutPrice, bidAmount, highBidder, bidderFullName,
          owner, ownerFullName, saleStatus, itemID, hasAllInfo =
        C_AuctionHouse.GetReplicateItemInfo(index)

    if not itemID then return nil end
    if not buyoutPrice or buyoutPrice == 0 then return nil end
    count = math.max(count or 1, 1)

    return {
        itemID    = itemID,
        link      = C_AuctionHouse.GetReplicateItemLink(index),
        name      = name,
        quantity  = count,
        buyout    = buyoutPrice,
        unitPrice = math.floor(buyoutPrice / count),
        owner     = ownerFullName or owner, -- nil when hidden (common on Retail/replicate)
        quality   = quality,
        level     = level,
    }
end
