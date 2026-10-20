-- Per-row capture of bulk scans (legacy getAll, modern replicate) so two scans
-- of one realm-faction a few hours apart can be diffed for likely sales
-- (tools/ah-liquidity-diff.py). Owner names are never stored: bulk scans do
-- not return them, and Retail / WoW Forever never do.
--
-- realm.rowScans = { { t=, mode=, rows=, data= }, ... }  -- oldest first
--   t     unix time the server's dump arrived (the moment the rows describe)
--   mode  "getAll" (legacy time-left buckets) or "replicate" (modern buckets)
--   rows  auction rows kept (buyout rows only, like the aggregates)
--   data  "MLR1\n", then one line per item:
--         itemID \t stack:buyout:bucket[*n],stack:buyout:bucket[*n],...
--         buyout is the whole auction's buyout in copper, bucket the time-left
--         band 1 Short, 2 Medium, 3 Long, 4 Very Long (0 unknown); *n repeats
--         an identical row n times.
-- tools/export-scan-rows.js reads the same format.

local ML = MarketLens
ML.ScanRows = ML.ScanRows or {}
local R = ML.ScanRows

local HEADER = "MLR1\n"
local KEEP = 3              -- scans kept per realm-faction
local MAX_AGE = 36 * 3600   -- the importer copies them to PostgreSQL long before this

-- Legacy GetAuctionItemTimeLeft returns 1-4 already; replicate returns
-- Enum.AuctionHouseTimeLeftBand 0-3, so shift that one up.
local function bucket(value, zeroBased)
    value = tonumber(value)
    if not value then return 0 end
    if zeroBased then value = value + 1 end
    if value < 1 or value > 4 then return 0 end
    return value
end

function R:Begin(mode)
    self.mode = mode
    self.startedAt = time()
    self.items = {}
    self.count = 0
end

function R:Cancel()
    self.items = nil
end

-- One parsed auction row (see Parser) plus the raw time-left value.
function R:Add(a, timeLeft)
    local items = self.items
    if not items or not a then return end
    local key = a.quantity .. ":" .. string.format("%.0f", a.buyout) .. ":"
        .. bucket(timeLeft, self.mode == "replicate")
    local it = items[a.itemID]
    if not it then it = {}; items[a.itemID] = it end
    it[key] = (it[key] or 0) + 1
    self.count = self.count + 1
end

function R:LegacyTimeLeft(index)
    if not GetAuctionItemTimeLeft then return nil end
    local ok, v = pcall(GetAuctionItemTimeLeft, "list", index)
    return ok and v or nil
end

function R:ReplicateTimeLeft(index)
    local fn = C_AuctionHouse and C_AuctionHouse.GetReplicateItemTimeLeft
    if not fn then return nil end
    local ok, v = pcall(fn, index)
    return ok and v or nil
end

local function encode(items)
    local lines, ids = { HEADER }, {}
    for itemID in pairs(items) do ids[#ids + 1] = itemID end
    table.sort(ids)
    for _, itemID in ipairs(ids) do
        local parts = {}
        for key, n in pairs(items[itemID]) do
            parts[#parts + 1] = n > 1 and (key .. "*" .. n) or key
        end
        table.sort(parts)
        lines[#lines + 1] = itemID .. "\t" .. table.concat(parts, ",") .. "\n"
    end
    return table.concat(lines)
end

-- Store the finished scan on the current realm-faction and trim old ones.
function R:Finish()
    local items = self.items
    self.items = nil
    if not items or self.count == 0 or not ML.realm then return end
    local scans = ML.realm.rowScans or {}
    ML.realm.rowScans = scans
    scans[#scans + 1] = { t = self.startedAt, mode = self.mode, rows = self.count, data = encode(items) }
    local cutoff = time() - MAX_AGE
    while #scans > KEEP or (#scans > 1 and (scans[1].t or 0) < cutoff) do
        table.remove(scans, 1)
    end
    ML:Debug("row scan kept: %d rows, %d bytes (%d scans stored)",
        self.count, #scans[#scans].data, #scans)
end
