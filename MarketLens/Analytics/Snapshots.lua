-- Storage layout (per realm):
--   realm.items[itemID] = {
--       name=, link=, class={profession,sector,market} (set by a scan) or
--       mkt=/src=/cr= strings (restored from the packed save, see Snap:Pack),
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

-- SavedVariables packing. WoW loads every realm bucket in the save, but only
-- the realm you are on is ever read, and as tables each item costs ~5 Lua
-- tables (record, snaps, class, one per snapshot). On disk a bucket's item
-- history is one string instead (realm.itemsPacked): the current realm is
-- unpacked at load and packed again at logout/reload, the others stay a
-- single inert string in memory.
--
-- "ML1\n", then one line per item:
--   id \t name \t link \t market \t source \t crafter \t t,q,a,s,l,m,w,tc[,t,q,...]
-- tools/export-realm-from-savedvariables.js reads the same format.
local PACK_HEADER = "ML1\n"
local FIELDS = { "t", "q", "a", "s", "l", "m", "w", "tc" }
local NF = #FIELDS

local function clean(s)
    return (tostring(s or ""):gsub("[\t\n]", " "))
end

function Snap:Pack(items)
    local lines, nums = { PACK_HEADER }, {}
    for itemID, rec in pairs(items or {}) do
        local snaps = rec.snaps
        if snaps and #snaps > 0 then
            local k = 0
            for _, sn in ipairs(snaps) do
                for f = 1, NF do
                    k = k + 1
                    nums[k] = string.format("%.0f", sn[FIELDS[f]] or 0)
                end
            end
            local cls = rec.class
            lines[#lines + 1] = table.concat({
                tostring(itemID), clean(rec.name), clean(rec.link),
                clean(cls and cls.market or rec.mkt),
                clean(cls and cls.source or rec.src),
                clean(cls and cls.crafter or rec.cr),
                table.concat(nums, ",", 1, k),
            }, "\t") .. "\n"
        end
    end
    return table.concat(lines)
end

local function orNil(s) if s ~= "" then return s end end

-- Unpack into `into` (items already there win). Returns into, count.
function Snap:Unpack(packed, into)
    into = into or {}
    if type(packed) ~= "string" or packed:sub(1, #PACK_HEADER) ~= PACK_HEADER then return into, 0 end
    local count, vals = 0, {}
    local line = "(%d+)\t([^\t\n]*)\t([^\t\n]*)\t([^\t\n]*)\t([^\t\n]*)\t([^\t\n]*)\t([^\t\n]*)\n"
    for id, name, link, mkt, src, cr, nums in packed:gmatch(line) do
        local itemID = tonumber(id)
        if not into[itemID] then
            local k = 0
            for v in nums:gmatch("[^,]+") do
                k = k + 1
                vals[k] = tonumber(v) or 0
            end
            local snaps = {}
            for i = 1, k - NF + 1, NF do
                -- Constructor syntax sizes each table once (no rehash growth).
                snaps[#snaps + 1] = { t = vals[i], q = vals[i + 1], a = vals[i + 2], s = vals[i + 3],
                    l = vals[i + 4], m = vals[i + 5], w = vals[i + 6], tc = vals[i + 7] }
            end
            if #snaps > 0 then
                -- No per-item class table: Scores falls back to the shared
                -- Classify cache; the strings carry the market for packing.
                into[itemID] = { name = orNil(name), link = orNil(link), mkt = orNil(mkt),
                    src = orNil(src), cr = orNil(cr), snaps = snaps }
                count = count + 1
            end
        end
    end
    return into, count
end

-- Pack every realm bucket except the current one. Only does work for saves
-- written before packing existed (or a realm whose unpack was interrupted).
function Snap:PackInactive(currentKey)
    local packed = 0
    for key, r in pairs(ML.db.realms or {}) do
        if key ~= currentKey and type(r.items) == "table" and next(r.items) then
            if r.itemsPacked then self:Unpack(r.itemsPacked, r.items) end
            r.itemsPacked = self:Pack(r.items)
            r.items = nil
            packed = packed + 1
        end
    end
    return packed
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
