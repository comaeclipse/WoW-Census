-- Two paths on the legacy AH API:
--   * getAll  -- one QueryAuctionItems(..., getAll=true) dumps the whole AH at
--                once; rate-limited to ~once / 15 min (CanSendAuctionQuery's 2nd
--                return, canDoGetAll). The huge result is read in 150-row batches
--                that yield via C_Timer so the client never freezes. Preferred:
--                one clean point-in-time snapshot.
--   * paged   -- QueryAuctionItems(..., page) 50 rows at a time, paced by the
--                throttle. Fallback for when getAll is on cooldown/unavailable.

local ML = MarketLens
local S = ML.Scanner
local P = ML.Parser
local U = ML.Util

local PAGE_SIZE  = 50
local READ_BATCH = 150   -- rows per frame when reading a getAll dump
local BROWSE_REPLY_TIMEOUT = 30

S.scanning = false
S.atAH = false
S.mode = nil

-- Driver frame paces paged queries via OnUpdate.
local driver = CreateFrame("Frame")
driver:Hide()

local function resetAccumulator()
    -- acc.sellers[owner] = { [itemID] = { q=, l=, n= } } is built only when the
    -- scan returns owner names (paged legacy scans); it feeds seller profiles.
    S.acc = { items = {}, sellers = {}, totalRows = 0 }
    S.page = 0
    S.awaitingPage = false
    S.throttle = 0
    S.readIndex = 1
    S.readTotal = 0
    S.ownersSeen = false -- flips true if any auction returned a seller name
end

local function accumulate(items, a)
    local it = items[a.itemID]
    if not it then
        it = {
            itemID   = a.itemID,
            link     = a.link,
            name     = a.name,
            quality  = a.quality,
            quantity = 0,
            auctions = 0,
            sellers  = {},
            prices   = {},
            minPrice = nil,
        }
        items[a.itemID] = it
    end
    it.quantity = it.quantity + a.quantity
    it.auctions = it.auctions + 1
    -- Seller names are nil on bulk scans (getAll/replicate). Only count real
    -- owners; S.ownersSeen records whether this client returns them at all.
    if a.owner then
        it.sellers[a.owner] = (it.sellers[a.owner] or 0) + a.quantity
        S.ownersSeen = true
        -- Per-owner listing detail (quantity + this owner's lowest unit price per
        -- item) so the site can build a profile of everything a seller posts.
        local sacc = S.acc and S.acc.sellers
        if sacc then
            local owned = sacc[a.owner]
            if not owned then owned = {}; sacc[a.owner] = owned end
            local li = owned[a.itemID]
            if not li then
                li = { q = 0, l = a.unitPrice, n = a.name }
                owned[a.itemID] = li
            end
            li.q = li.q + a.quantity
            if a.unitPrice and (not li.l or a.unitPrice < li.l) then li.l = a.unitPrice end
            if not li.n and a.name then li.n = a.name end
        end
    end
    it.prices[#it.prices + 1] = { price = a.unitPrice, quantity = a.quantity }
    if not it.minPrice or a.unitPrice < it.minPrice then
        it.minPrice = a.unitPrice
    end
    if not it.link and a.link then it.link = a.link end
    if not it.name and a.name then it.name = a.name end
end

-- Fold one Retail browse-summary row into the accumulator. Unlike replicate,
-- browse results expose total quantity and the current minimum price, not each
-- individual auction or seller. Multiple item keys (item level/suffix) can map
-- to the same item ID, so retain each key's minimum as a quantity-weighted
-- price observation while explicitly leaving the auction count unknown (zero).
local function accumulateBrowse(items, result)
    local itemKey = result and result.itemKey
    local itemID = itemKey and itemKey.itemID
    local quantity = result and result.totalQuantity
    local minPrice = result and result.minPrice
    if not itemID or not quantity or quantity <= 0 or not minPrice or minPrice <= 0 then
        return false
    end

    local it = items[itemID]
    if not it then
        local name, link
        if C_AuctionHouse and C_AuctionHouse.GetItemKeyInfo then
            local info = C_AuctionHouse.GetItemKeyInfo(itemKey)
            name = info and info.itemName
        end
        if C_Item and C_Item.GetItemInfo then
            local itemName, itemLink = C_Item.GetItemInfo(itemID)
            name = name or itemName
            link = itemLink
        elseif GetItemInfo then
            name, link = GetItemInfo(itemID)
        end
        it = {
            itemID   = itemID,
            link     = link,
            name     = name,
            quality  = nil,
            quantity = 0,
            auctions = 0, -- unavailable in browse-summary mode
            sellers  = {},
            prices   = {},
            minPrice = nil,
            summary  = true,
        }
        items[itemID] = it
    end

    it.quantity = it.quantity + quantity
    it.prices[#it.prices + 1] = { price = minPrice, quantity = quantity }
    if not it.minPrice or minPrice < it.minPrice then it.minPrice = minPrice end
    return true
end

-- True when the AH is usable. Checked against LIVE frame state, not just the
-- cached event flag, because AUCTION_HOUSE_SHOW can be missed on retail -- most
-- notably when /reload happens with the AH already open (the show event fired
-- before we reloaded), which otherwise leaves atAH stuck false.
function S:AtAuctionHouse()
    if AuctionHouseFrame and AuctionHouseFrame:IsShown() then return true end
    if AuctionFrame and AuctionFrame:IsShown() then return true end
    return self.atAH == true
end

-- forcePaged: skip Get All even when it's available. The bulk dump omits seller
-- names, so a paged scan is the only legacy path that captures owners (and thus
-- seller counts). Slower, but the trade-off callers opt into via /ml scan paged.
function S:StartScan(forcePaged)
    if not self.eventDriverReady then
        ML:Print("Scanner initialization failed before its event handler loaded. Enable Lua errors and /reload.")
        return
    end
    if not self:AtAuctionHouse() then
        ML:Print("Open the Auction House first, then |cffffff00/ml scan|r.")
        return
    end
    if self.scanning then
        ML:Print("Scan already in progress...")
        return
    end

    -- Modern clients (Retail / Cata+ Classic) have no QueryAuctionItems. Use
    -- the normal browse-summary API by default: replicate is globally
    -- throttled and can be silently ignored, while browse is the reliable path
    -- for a full market summary.
    if type(QueryAuctionItems) ~= "function" then
        if C_AuctionHouse and C_AuctionHouse.SendBrowseQuery then
            return self:StartModern()
        end
        ML:Print("This client has no supported Auction House scan API.")
        return
    end

    local canQuery, canGetAll = CanSendAuctionQuery()
    if not canQuery then
        ML:Print("AH is busy; try again in a moment.")
        return
    end

    resetAccumulator()
    self.scanning = true
    self.auctionsAvailable = true
    self.priceDistributionAvailable = true
    ML.Data.classifyCache = {} -- refresh in case item info arrived since last scan
    ML:Fire("SCAN_START")

    if canGetAll and not forcePaged then
        self.mode = "getAll"
        ML:Print("Starting full scan (Get All)...")
        self.awaitingPage = true
        -- QueryAuctionItems(name, minLevel, maxLevel, page, usable, rarity, getAll, exactMatch)
        QueryAuctionItems("", nil, nil, 0, nil, nil, true, false)
    else
        self.mode = "paged"
        ML:Print(forcePaged
            and "Running a paged scan to capture seller names (slower)..."
            or "Get All on cooldown \226\128\148 running a paged scan (slower)...")
        driver:Show()
        self:QueryCurrentPage()
    end
end

function S:Abort(reason)
    if not self.scanning then return end
    self.scanning = false
    self.awaitingPage = false
    driver:Hide()
    ML:Print("Scan aborted: %s", reason or "unknown")
    ML:Fire("SCAN_ABORT", reason)
end

function S:Finish()
    self.scanning = false
    self.awaitingPage = false
    driver:Hide()
    if self.mode == "getAll" then self.lastGetAll = GetTime() end

    local itemCount = 0
    for _ in pairs(self.acc.items) do itemCount = itemCount + 1 end

    -- Whether this client's scan API exposes seller names. When false, seller
    -- count / concentration are meaningless and the UI hides them.
    ML.realm.ownersAvailable = self.ownersSeen
    ML.realm.auctionsAvailable = self.auctionsAvailable ~= false
    ML.realm.priceDistributionAvailable = self.priceDistributionAvailable ~= false

    local unit = self.mode == "browse"
        and "summary rows" or "auction rows"
    ML:Print("Scan complete: %d %s, %d unique items.", self.acc.totalRows, unit, itemCount)
    ML.Snapshots:Record(self.acc.items)
    ML.Snapshots:Purge()
    -- Seller profiles only exist when this scan captured owners (paged legacy).
    -- getAll/browse/replicate leave acc.sellers empty, so stored profiles persist
    -- untouched rather than being wiped by an owner-less scan.
    if self.ownersSeen and ML.Sellers then
        ML.Sellers:Record(self.acc.sellers)
        ML.Sellers:Purge()
    end
    ML:Fire("SCAN_COMPLETE", itemCount, self.acc.totalRows, self.mode)
end

-- Begin reading a full-AH dump in batches that yield between frames.
function S:BeginGetAllRead()
    local shown = GetNumAuctionItems("list")
    self.readTotal = shown or 0
    self.readIndex = 1
    if self.readTotal == 0 then
        self:Finish()
        return
    end
    self:ReadBatch()
end

function S:ReadBatch()
    if not self.scanning then return end
    local stop = math.min(self.readIndex + READ_BATCH - 1, self.readTotal)
    for i = self.readIndex, stop do
        local a = P:GetAuction(i)
        if a then accumulate(self.acc.items, a) end
    end
    self.acc.totalRows = stop
    self.readIndex = stop + 1

    ML:Fire("SCAN_PROGRESS", { mode = "getAll", done = stop, total = self.readTotal })

    if self.readIndex > self.readTotal then
        self:Finish()
    elseif C_Timer and C_Timer.After then
        C_Timer.After(0, function() S:ReadBatch() end)
    else
        self:ReadBatch()
    end
end

local function browseReady()
    return not C_AuctionHouse.IsThrottledMessageSystemReady
        or C_AuctionHouse.IsThrottledMessageSystemReady()
end

function S:ArmBrowseWatchdog()
    self.browseStamp = (self.browseStamp or 0) + 1
    local stamp = self.browseStamp
    if C_Timer and C_Timer.After then
        C_Timer.After(BROWSE_REPLY_TIMEOUT, function()
            if S.scanning and S.mode == "browse" and S.awaitingPage
               and S.browseStamp == stamp then
                S:Abort("no browse reply from the auction house after 30 seconds")
            end
        end)
    end
end

function S:TryBrowseAction()
    if not self.scanning or self.mode ~= "browse" or not self.browseAction then return end
    if not browseReady() then return end

    local action = self.browseAction
    self.browseAction = nil
    self.browseQueuedAt = nil
    self.awaitingPage = true
    self:ArmBrowseWatchdog()

    if action == "start" then
        C_AuctionHouse.SendBrowseQuery({
            searchString = "", sorts = {}, filters = {}, itemClassFilters = {},
        })
    else
        C_AuctionHouse.RequestMoreBrowseResults()
    end
end

function S:QueueBrowseAction(action)
    self.browseAction = action
    self.browseQueuedAt = GetTime and GetTime() or 0
    driver:Show()
    self:TryBrowseAction()
end

function S:StartModern()
    resetAccumulator()
    self.scanning = true
    self.mode = "browse"
    self.auctionsAvailable = false
    self.priceDistributionAvailable = false
    self.browseAction = nil
    ML.Data.classifyCache = {}
    ML.realm.ownersAvailable = false
    ML:Print("Starting full scan (Retail summary)...")
    ML:Fire("SCAN_START")
    self:QueueBrowseAction("start")
end

function S:AddBrowseResults(results)
    local added = 0
    for _, result in ipairs(results or {}) do
        if accumulateBrowse(self.acc.items, result) then added = added + 1 end
    end
    self.acc.totalRows = self.acc.totalRows + added
end

function S:ContinueBrowse()
    self.awaitingPage = false
    if C_AuctionHouse.HasFullBrowseResults() then
        -- Read one authoritative snapshot after all pages have arrived. This
        -- avoids double-counting if Blizzard repeats or replaces a batch while
        -- the browse result set is being assembled.
        local results = C_AuctionHouse.GetBrowseResults()
        resetAccumulator()
        self.scanning = true
        self.mode = "browse"
        local ok, err = pcall(self.AddBrowseResults, self, results)
        if not ok then
            self:Abort("could not read Retail browse results: " .. tostring(err))
            return
        end
        if self.acc.totalRows == 0 then
            self:Abort("Retail browse completed but returned no usable market rows")
        else
            self:Finish()
        end
    else
        local results = C_AuctionHouse.GetBrowseResults()
        ML:Fire("SCAN_PROGRESS", { mode = "browse", rows = results and #results or 0 })
        self:QueueBrowseAction("more")
    end
end

function S:OnBrowseUpdated()
    if not self.scanning or self.mode ~= "browse" or not self.awaitingPage then return end
    self:ContinueBrowse()
end

function S:OnBrowseAdded()
    if not self.scanning or self.mode ~= "browse" or not self.awaitingPage then return end
    self:ContinueBrowse()
end

-- Optional high-detail replicate path. Kept for callers that explicitly need
-- individual auction rows; it is not the default Retail scan because Blizzard
-- globally throttles it and silently drops requests during the cooldown. There
-- is deliberately no per-addon cooldown guess or arbitrary response timeout:
-- wait for REPLICATE_ITEM_LIST_UPDATE or AUCTION_HOUSE_CLOSED. A local clock
-- cannot know whether another addon consumed the throttle.

function S:StartReplicate()
    if not self:AtAuctionHouse() then
        ML:Print("Open the Auction House first, then |cffffff00/ml scan replicate|r.")
        return
    end
    if self.scanning then
        ML:Print("Scan already in progress...")
        return
    end
    if not (C_AuctionHouse and C_AuctionHouse.ReplicateItems) then
        ML:Print("This client has no replicate scan API.")
        return
    end

    resetAccumulator()
    self.scanning = true
    self.mode = "replicate"
    self.auctionsAvailable = true
    self.priceDistributionAvailable = true
    self.awaitingPage = true
    ML.Data.classifyCache = {}
    ML:Print("Starting deep scan (replicate; server-throttled)...")
    ML:Fire("SCAN_START")
    C_AuctionHouse.ReplicateItems()
end

-- Fired on REPLICATE_ITEM_LIST_UPDATE when a replicate dump is ready.
function S:OnReplicate()
    if not self.scanning or self.mode ~= "replicate" or not self.awaitingPage then return end
    self.awaitingPage = false
    self.readTotal = C_AuctionHouse.GetNumReplicateItems() or 0
    self.readIndex = 0 -- replicate indices are 0-based
    if self.readTotal == 0 then self:Finish() return end
    self:ReadModernBatch()
end

function S:ReadModernBatch()
    if not self.scanning then return end
    local stop = math.min(self.readIndex + READ_BATCH - 1, self.readTotal - 1)
    for i = self.readIndex, stop do
        local a = P:GetReplicate(i)
        if a then accumulate(self.acc.items, a) end
    end
    self.readIndex = stop + 1
    self.acc.totalRows = self.readIndex

    ML:Fire("SCAN_PROGRESS", { mode = "getAll", done = self.readIndex, total = self.readTotal })

    if self.readIndex >= self.readTotal then
        self:Finish()
    elseif C_Timer and C_Timer.After then
        C_Timer.After(0, function() S:ReadModernBatch() end)
    else
        self:ReadModernBatch()
    end
end

function S:QueryCurrentPage()
    self.awaitingPage = true
    QueryAuctionItems("", nil, nil, self.page, false, 0, false, false)
end

-- Process the current results page. Returns true when the scan is complete.
function S:ProcessPage()
    local shown, total = GetNumAuctionItems("list")
    shown = shown or 0
    total = total or 0

    for i = 1, shown do
        local a = P:GetAuction(i)
        if a then accumulate(self.acc.items, a) end
    end
    self.acc.totalRows = self.acc.totalRows + shown

    local totalPages = math.max(math.ceil(total / PAGE_SIZE), 1)
    ML:Fire("SCAN_PROGRESS", { mode = "paged", page = self.page + 1,
        pages = totalPages, rows = self.acc.totalRows })

    local nextStart = (self.page + 1) * PAGE_SIZE
    if nextStart < total and shown > 0 then
        self.page = self.page + 1
        self.awaitingPage = false
        self.throttle = ML.db.settings.scanThrottle or 0.5
        return false -- more pages; driver OnUpdate fires the next query
    end
    return true
end

-- OnUpdate pacing for the paged fallback.
driver:SetScript("OnUpdate", function(_, elapsed)
    if not S.scanning then return end
    if S.mode == "browse" then
        -- Do not rely on seeing one particular throttle transition event. The
        -- AH message system is shared, so another query can make it busy just
        -- as this scan is queued. Poll until our action can actually be sent.
        if S.browseAction then
            local queuedFor = (GetTime and GetTime() or 0) - (S.browseQueuedAt or 0)
            if queuedFor >= BROWSE_REPLY_TIMEOUT then
                S:Abort("auction house remained busy for 30 seconds before the browse request could be sent")
            else
                S:TryBrowseAction()
            end
        end
        return
    end
    if S.mode ~= "paged" then return end
    if S.awaitingPage then return end
    S.throttle = (S.throttle or 0) - elapsed
    if S.throttle <= 0 and CanSendAuctionQuery() then
        S:QueryCurrentPage()
    end
end)

-- Called on each AUCTION_ITEM_LIST_UPDATE while scanning.
function S:OnResults()
    if not self.scanning or not self.awaitingPage then return end
    if self.mode == "getAll" then
        self.awaitingPage = false
        self:BeginGetAllRead()
    else
        local done = self:ProcessPage()
        if done then self:Finish() end
    end
end

local events = CreateFrame("Frame")

-- Event names differ between the legacy and modern Auction House APIs. An
-- invalid RegisterEvent call raises a Lua error and stops the remainder of this
-- file from loading, so every flavor-specific event must be registered
-- defensively before the OnEvent handler is installed.
local function registerEvent(name)
    local ok = pcall(events.RegisterEvent, events, name)
    if not ok then ML:Debug("event unavailable on this client: %s", name) end
    return ok
end

registerEvent("AUCTION_HOUSE_SHOW")
registerEvent("AUCTION_HOUSE_CLOSED")
registerEvent("PLAYER_INTERACTION_MANAGER_FRAME_SHOW")
registerEvent("PLAYER_INTERACTION_MANAGER_FRAME_HIDE")

if type(QueryAuctionItems) == "function" then
    registerEvent("AUCTION_ITEM_LIST_UPDATE")
else
    registerEvent("REPLICATE_ITEM_LIST_UPDATE")
    registerEvent("AUCTION_HOUSE_BROWSE_RESULTS_UPDATED")
    registerEvent("AUCTION_HOUSE_BROWSE_RESULTS_ADDED")
    registerEvent("AUCTION_HOUSE_BROWSE_FAILURE")
    registerEvent("AUCTION_HOUSE_THROTTLED_MESSAGE_DROPPED")
    registerEvent("AUCTION_HOUSE_THROTTLED_SYSTEM_READY")
end

local AUCTIONEER = Enum and Enum.PlayerInteractionType and Enum.PlayerInteractionType.Auctioneer

events:SetScript("OnEvent", function(_, event, arg1)
    if event == "AUCTION_HOUSE_SHOW" then
        S.atAH = true
    elseif event == "PLAYER_INTERACTION_MANAGER_FRAME_SHOW" then
        if AUCTIONEER == nil or arg1 == AUCTIONEER then S.atAH = true end
    elseif event == "AUCTION_HOUSE_CLOSED" then
        S.atAH = false
        if S.scanning then S:Abort("auction house closed") end
    elseif event == "PLAYER_INTERACTION_MANAGER_FRAME_HIDE" then
        if AUCTIONEER == nil or arg1 == AUCTIONEER then
            S.atAH = false
            if S.scanning then S:Abort("auction house closed") end
        end
    elseif event == "AUCTION_ITEM_LIST_UPDATE" then
        S:OnResults()
    elseif event == "REPLICATE_ITEM_LIST_UPDATE" then
        S:OnReplicate()
    elseif event == "AUCTION_HOUSE_BROWSE_RESULTS_UPDATED" then
        S:OnBrowseUpdated()
    elseif event == "AUCTION_HOUSE_BROWSE_RESULTS_ADDED" then
        S:OnBrowseAdded()
    elseif event == "AUCTION_HOUSE_THROTTLED_SYSTEM_READY" then
        S:TryBrowseAction()
    elseif event == "AUCTION_HOUSE_BROWSE_FAILURE"
        or event == "AUCTION_HOUSE_THROTTLED_MESSAGE_DROPPED" then
        if S.scanning and S.mode == "browse" then
            S:Abort(event == "AUCTION_HOUSE_BROWSE_FAILURE"
                and "the auction house rejected the browse query"
                or "the auction house dropped the throttled browse query")
        end
    end
end)

-- Set only after every top-level registration and the dispatch handler have
-- loaded successfully. StartScan checks this so a future initialization error
-- cannot masquerade as a server timeout again.
S.eventDriverReady = true

function S:Init()
    resetAccumulator()
end
