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

-- Merge one item's listings from a targeted /ml scan item query into existing
-- seller profiles. Unlike Record (a full-scan snapshot that REPLACES a
-- seller's whole listing set and repoints realm.lastSellerScanID at itself),
-- this only touches the one item found, leaves every other listing alone, and
-- never reassigns realm.lastSellerScanID -- a single-item lookup must not make
-- the exporter think it saw the whole realm. A brand new seller (not in any
-- prior full scan) inherits the realm's current lastSellerScanID so they still
-- qualify for the next seller upload instead of being silently dropped; an
-- already-known seller keeps whatever scan last fully captured them.
-- accSellers is Scanner.acc.sellers: { [owner] = { [itemID] = {q=,l=,n=} } }.
function Sellers:MergeItem(accSellers, itemID)
    if not accSellers then return 0 end
    local realm = ML.realm
    realm.sellers = realm.sellers or {}
    local now = time()
    local n = 0

    for owner, listings in pairs(accSellers) do
        local li = listings[itemID]
        if li then
            n = n + 1
            local rec = realm.sellers[owner]
            if not rec then
                rec = { name = owner, firstSeen = now, seenCount = 0, hist = {},
                    listings = {}, lastSellerScanID = realm.lastSellerScanID }
                realm.sellers[owner] = rec
            end
            rec.listings = rec.listings or {}
            rec.listings[itemID] = li
            rec.lastSeen = now
            rec.seenCount = (rec.seenCount or 0) + 1

            -- Resummarize from the seller's full current listing set (not just
            -- this item) so hist keeps reflecting their whole known posting.
            local items, qty, value = 0, 0, 0
            for _, l in pairs(rec.listings) do
                items = items + 1
                qty = qty + (l.q or 0)
                value = value + (l.q or 0) * (l.l or 0)
            end
            rec.hist = rec.hist or {}
            U.PushCapped(rec.hist, { t = now, items = items, qty = qty, value = value }, HIST_CAP)
        end
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
