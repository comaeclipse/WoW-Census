-- Per-seller listing profiles for the website's seller pages.
--
-- Storage layout (per realm):
--   realm.sellers[owner] = {
--       name=, firstSeen=, lastSeen=, seenCount=,
--       listings = { [itemID] = { q=, l=, n= } },      -- latest seller sample; replaced each time
--       hist = { { t=, items=, qty=, value= }, ... }    -- rolling summary, oldest first, capped
--   }
--
-- Only paged scans on the legacy AH return owner names, so this table is
-- realm-only and populated solely by /ml scan paged. Large realms use a
-- time-boxed seller sample; getAll/browse/replicate never call Record (the
-- scanner gates on ownersSeen), so an owner-less scan leaves stored profiles
-- intact.
--
-- We keep the latest sampled listing set plus a small rolling SUMMARY history
-- (item count / quantity / gold value per sample) rather than a full listing set
-- per sample -- that bounds the SavedVariables footprint while still supporting
-- "usually posts ~N items".

local ML = MarketLens
local Sellers = {}
ML.Sellers = Sellers
local U = ML.Util

local HIST_CAP = 20 -- rolling summary points retained per seller

-- Fold this scan's per-owner listings into the persistent per-realm store.
-- accSellers is Scanner.acc.sellers: { [owner] = { [itemID] = {q=,l=,n=} } }.
function Sellers:Record(accSellers, scanStats)
    if not accSellers then return 0 end
    local realm = ML.realm
    realm.sellers = realm.sellers or {}
    local now = time()
    local scanID = scanStats and scanStats.id or tostring(now)
    realm.lastSellerScanID = scanID
    local n = 0

    for owner, listings in pairs(accSellers) do
        n = n + 1
        local rec = realm.sellers[owner]
        if not rec then
            rec = { name = owner, firstSeen = now, seenCount = 0, hist = {} }
            realm.sellers[owner] = rec
        end

        -- Summarize the current listing set before we store it.
        local items, qty, value = 0, 0, 0
        for _, li in pairs(listings) do
            items = items + 1
            qty = qty + (li.q or 0)
            value = value + (li.q or 0) * (li.l or 0)
        end

        rec.listings = listings -- replace the prior snapshot with the current one
        rec.lastSeen = now
        rec.lastSellerScanID = scanID
        rec.seenCount = (rec.seenCount or 0) + 1
        rec.hist = rec.hist or {}
        U.PushCapped(rec.hist, { t = now, items = items, qty = qty, value = value }, HIST_CAP)
    end

    return n
end

-- Drop sellers not seen within the snapshot retention window so the save stays
-- bounded. Mirrors Snapshots:Purge's cutoff.
function Sellers:Purge()
    local realm = ML.realm
    if not realm.sellers then return end
    local cutoff = time() - (ML.db.settings.snapshotRetentionDays or 14) * 86400
    for owner, rec in pairs(realm.sellers) do
        if (rec.lastSeen or 0) < cutoff then
            realm.sellers[owner] = nil
        end
    end
end

-- How many sellers currently have a stored profile.
function Sellers:Count()
    local realm = ML.realm
    if not realm or not realm.sellers then return 0 end
    local n = 0
    for _ in pairs(realm.sellers) do n = n + 1 end
    return n
end
