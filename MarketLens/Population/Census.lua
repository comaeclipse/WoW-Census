-- Adaptive /who census: one button press = one query, MarketLens decides which.
--
-- A census starts from the profile's disjoint level backbone (see
-- Population/CensusProfiles.lua) and refines any query that hits the /who cap:
--
--   level range  -> narrower level ranges   (weighted by recent sightings)
--   single level -> one query per class     (exhaustive)
--   level+class  -> one query per race      (exhaustive)
--   level+class+race still capped -> hottest zones for that cell (NOT
--                   exhaustive: the cell is counted as "unresolved", so the
--                   census is reported as a lower bound / partial sweep)
--
-- Because the exhaustive splits are disjoint, a resolved census sees each online
-- player once; duplicates only come from zone fallbacks or players who level
-- between queries. Cells that capped on a previous census are remembered per
-- realm bucket and pre-split on the next run, so we do not spend a press on a
-- query we already know will cap.
--
-- SendWho needs a hardware event and is server-throttled, so the census never
-- fires on its own: RunNext is only called from the button or /ml census next
-- (which can be bound to a key through a macro). State lives in SavedVariables
-- and survives /reload mid-census.

local ML = MarketLens
local Pop = ML.Population
local P = Pop.Profiles
local U = ML.Util
local C = {}
Pop.Census = C

local TARGET = 35          -- aim each level split at about this many online players
local HISTORY_DAYS = 7     -- recent-sighting window used to weight splits and pick zones
local LEARN_DAYS = 14      -- how long a learned "this cell caps" split is trusted
local ZONE_SPLITS = 6      -- hotspot zones tried for a fully-pinned capped cell
local DEFAULT_BUDGET = 300 -- max queries a single census may plan
local MERGE_TARGET = 40    -- merge learned level parts while their last counts sum under this
local MAX_PRESPLIT_DEPTH = 8 -- level -> class -> level -> race chains, with room for nested level splits

-- Node helpers. A node is { lo=, hi=, class=token?, race=?, zone=? }.

local function nodeKey(n)
    return string.format("%d-%d|%s|%s|%s", n.lo, n.hi, n.class or "", n.race or "", n.zone or "")
end

-- Level, class and race splits partition their parent; a zone split does not.
local function splitsExhaustively(n)
    return n.hi > n.lo or not n.class or not n.race
end

function C:Filter(n)
    local parts = { n.lo == n.hi and tostring(n.lo) or (n.lo .. "-" .. n.hi) }
    if n.class then parts[#parts + 1] = 'c-"' .. P.ClassName(n.class) .. '"' end
    if n.race then parts[#parts + 1] = 'r-"' .. n.race .. '"' end
    if n.zone then parts[#parts + 1] = 'z-"' .. n.zone .. '"' end
    return table.concat(parts, " ")
end

function C:State()
    local store = Pop:Store()
    store.censusLearned = store.censusLearned or {}
    store.censusSeen = store.censusSeen or {} -- last uncapped count per node, for Compact
    return store.census, store
end

function C:Profile()
    if not self.profile then self.profile = P.Current() end
    return self.profile
end

-- The /who result count at which a query is treated as truncated.
function Pop:IsCapped(observed)
    local prof = C:Profile()
    return (observed or 0) >= ((prof and prof.capAt) or 49)
end

-- Sightings for this realm bucket: the recent character list (weights level
-- splits, estimates cell sizes, ranks fallback zones) and every race ever seen
-- per class (prunes race splits). Cached per session.
function C:History()
    if self.hist then return self.hist end
    local store = Pop:Store()
    local cutoff = time() - HISTORY_DAYS * 86400
    local recent, racesByClass, classCount = {}, {}, {}
    for _, c in pairs(store.characters or {}) do
        local L = tonumber(c.level) or 0
        if L > 0 and (c.lastSeen or 0) >= cutoff then recent[#recent + 1] = c end
        if c.classFile and c.race and c.race ~= "" then
            racesByClass[c.classFile] = racesByClass[c.classFile] or {}
            racesByClass[c.classFile][c.race] = true
            classCount[c.classFile] = (classCount[c.classFile] or 0) + 1
        end
    end
    self.hist = { recent = recent, racesByClass = racesByClass, classCount = classCount }
    return self.hist
end

local function matchesNode(c, n)
    local L = tonumber(c.level) or 0
    return L >= n.lo and L <= n.hi
        and (not n.class or c.classFile == n.class)
        and (not n.race or c.race == n.race)
end

-- Recent sightings matching a node (ignoring zone).
function C:Weight(n)
    local w = 0
    for _, c in ipairs(self:History().recent) do
        if matchesNode(c, n) then w = w + 1 end
    end
    return w
end

-- Expected online players for a node: its recent sightings scaled by the
-- online/sighting ratio this census has measured on uncapped queries. nil
-- until the first uncapped query calibrates it.
function C:Estimate(n, st)
    if (st.ratioHist or 0) <= 0 then return nil end
    return self:Weight(n) * st.ratioObs / st.ratioHist
end

-- Split a capped level range into k contiguous parts of roughly equal recent
-- weight, k sized so each part lands near TARGET; without a calibrated
-- estimate we just halve. Class/race pins carry over to the parts.
function C:SplitLevels(n, st, known)
    local width = n.hi - n.lo + 1
    local counts = {}
    for _, c in ipairs(self:History().recent) do
        if matchesNode(c, n) then
            local L = tonumber(c.level)
            counts[L] = (counts[L] or 0) + 1
        end
    end
    local weights, total = {}, 0
    for L = n.lo, n.hi do
        local w = (counts[L] or 0) + 1 -- +1 so an unseen level still gets a share
        weights[#weights + 1] = w
        total = total + w
    end

    local k = 2
    local est = known or self:Estimate(n, st)
    if width <= 3 then
        k = width
    elseif est then
        k = math.ceil(math.max(est, self:Profile().capAt) / TARGET)
    end
    k = U.Clamp(k, 2, width)

    local kids, lo, acc, part = {}, n.lo, 0, 1
    for i = 1, width do
        local L = n.lo + i - 1
        acc = acc + weights[i]
        local mustCut = (width - i) == (k - part) -- one level left per remaining part
        if L == n.hi or (part < k and (acc >= total * part / k or mustCut)) then
            kids[#kids + 1] = { lo = lo, hi = L, class = n.class, race = n.race }
            lo, part = L + 1, part + 1
        end
    end
    return kids
end

-- Races to try for a class. Uses the ruleset's race/class table when the
-- profile has one; otherwise (MoP, Retail, Forever) the races actually seen
-- playing that class here, once there is enough history to trust, plus any
-- race the static list does not know yet (a new allied race).
local MIN_CLASS_HISTORY = 20
local function raceList(prof, class, hist)
    local list, seen = {}, {}
    local function add(r)
        if r and r ~= "" and not seen[r] then seen[r] = true; list[#list + 1] = r end
    end
    local allowed = prof.racesByClass and prof.racesByClass[class]
    if allowed then
        local mine = {}
        for _, r in ipairs(prof.races) do mine[r] = true end
        for _, r in ipairs(allowed) do if mine[r] then add(r) end end
        return list
    end
    local observed = hist.racesByClass[class] or {}
    local trusted = (hist.classCount[class] or 0) >= MIN_CLASS_HISTORY
    for _, r in ipairs(prof.races) do
        if not trusted or observed[r] then add(r) end
    end
    local extra = {}
    for r in pairs(observed) do if not seen[r] then extra[#extra + 1] = r end end
    table.sort(extra)
    for _, r in ipairs(extra) do add(r) end
    return list
end

local function zoneList(prof, n, hist)
    local counts = {}
    local function tally(strict)
        for _, c in ipairs(hist.recent) do
            local L = tonumber(c.level) or 0
            local hit = strict and matchesNode(c, n) or (not strict and L >= n.lo and L <= n.hi)
            if hit and c.zone and c.zone ~= "" then
                counts[c.zone] = (counts[c.zone] or 0) + 1
            end
        end
    end
    tally(true)
    if not next(counts) then tally(false) end

    local zones = {}
    for z in pairs(counts) do zones[#zones + 1] = z end
    table.sort(zones, function(a, b)
        if counts[a] ~= counts[b] then return counts[a] > counts[b] end
        return a < b
    end)
    local out, seen = {}, {}
    for _, z in ipairs(zones) do
        if #out >= ZONE_SPLITS then break end
        out[#out + 1] = z; seen[z] = true
    end
    for _, z in ipairs(prof.hotspots) do
        if #out >= ZONE_SPLITS then break end
        if not seen[z] then out[#out + 1] = z; seen[z] = true end
    end
    return out
end

function C:Learned(n)
    local _, store = self:State()
    local e = store.censusLearned[nodeKey(n)]
    if type(e) == "table" and (time() - (e.t or 0)) < LEARN_DAYS * 86400 then return e end
    return nil
end

-- Which dimension to split a capped node on. A wide level range whose levels
-- would each overflow on their own is split by class first: that keeps leaves
-- near TARGET instead of shattering every level into near-empty class cells.
--
-- Returns the kind plus, when known, the node's true online count (the sum of
-- its children's last results), which SplitLevels uses instead of an estimate.
function C:SplitKind(n, st)
    local learned = self:Learned(n)
    local width = n.hi - n.lo + 1
    if learned and learned.kind == "class" and width > 1 then
        -- The first split was chosen from an estimate. Now that the class
        -- children have run, their sum is the range's real size: if the levels
        -- fit under the cap on their own, a level split is far cheaper (87-88
        -- took 11 class queries of 1-7 players; 87 + 88 would have taken 2).
        local total = self:ChildSum(n)
        if total and total / width <= self:Profile().capAt * 0.8 then return "level", total end
        return "class"
    end
    if learned and learned.kind then return learned.kind end
    if width > 1 then
        if not n.class then
            local est = self:Estimate(n, st)
            if est and est / width > TARGET then return "class" end
        end
        return "level"
    end
    if not n.class then return "class" end
    if not n.race then return "race" end
    if not n.zone then return "zone" end
    return nil
end

-- Children of a capped node, whether together they cover it exactly, and the
-- split kind (stored so the next census can replay the identical split).
function C:Children(n, st)
    local prof = self:Profile()
    local kind, total = self:SplitKind(n, st)
    local kids = {}
    if kind == "level" then
        local learned = self:Learned(n)
        if learned and learned.levels then
            for i = 1, #learned.levels, 2 do
                kids[#kids + 1] = { lo = learned.levels[i], hi = learned.levels[i + 1], class = n.class, race = n.race }
            end
            kids = self:Compact(n, learned, kids)
        else
            kids = self:SplitLevels(n, st, total)
            -- Switched from a learned class split: persist the new plan.
            if learned then self:Learn(n, kind, kids) end
        end
        return kids, true, kind
    elseif kind == "class" then
        for _, token in ipairs(prof.classes) do
            kids[#kids + 1] = { lo = n.lo, hi = n.hi, class = token }
        end
        return kids, true, kind
    elseif kind == "race" then
        for _, race in ipairs(raceList(prof, n.class, self:History())) do
            kids[#kids + 1] = { lo = n.lo, hi = n.hi, class = n.class, race = race }
        end
        return kids, true, kind
    elseif kind == "zone" then
        for _, zone in ipairs(zoneList(prof, n, self:History())) do
            kids[#kids + 1] = { lo = n.lo, hi = n.hi, class = n.class, race = n.race, zone = zone }
        end
        return kids, false, kind
    end
    return kids, false, nil
end

-- Sum of the class children's last results: the node's real online count.
-- nil unless every child has a fresh result and none of them still caps.
function C:ChildSum(n)
    local _, store = self:State()
    local cutoff = time() - LEARN_DAYS * 86400
    local total = 0
    for _, token in ipairs(self:Profile().classes) do
        local k = { lo = n.lo, hi = n.hi, class = token }
        if self:Learned(k) then return nil end
        local s = store.censusSeen[nodeKey(k)]
        if not s or s.t < cutoff then return nil end
        total = total + s.n
    end
    return total
end

-- Merge adjacent parts of a learned level split whose last results together
-- fit comfortably under the cap. A first split sized from estimates is often
-- finer than needed (60-69 went to five parts averaging 15); this lets the
-- next census use the real counts. The merged partition is saved back, and a
-- part that later overflows is simply split again.
function C:Compact(n, learned, kids)
    local _, store = self:State()
    local cutoff = time() - LEARN_DAYS * 86400
    local function count(k)
        if self:Learned(k) then return nil end -- still caps: never merge it
        local s = store.censusSeen[nodeKey(k)]
        return s and s.t >= cutoff and s.n or nil
    end
    local out, cur, curN, curT = {}, nil, nil, nil
    local function flush()
        if not cur then return end
        out[#out + 1] = cur
        if curN then store.censusSeen[nodeKey(cur)] = { t = curT, n = curN } end
    end
    for _, k in ipairs(kids) do
        local c = count(k)
        local t = c and store.censusSeen[nodeKey(k)].t
        if cur and curN and c and curN + c <= MERGE_TARGET then
            cur = { lo = cur.lo, hi = k.hi, class = n.class, race = n.race }
            curN, curT = curN + c, math.min(curT, t)
        else
            flush()
            cur, curN, curT = k, c, t
        end
    end
    flush()
    if #out < #kids then
        learned.levels = {}
        for _, k in ipairs(out) do
            learned.levels[#learned.levels + 1] = k.lo
            learned.levels[#learned.levels + 1] = k.hi
        end
    end
    return out
end

-- Remember that a node caps and exactly how it was split.
function C:Learn(n, kind, kids)
    local _, store = self:State()
    local e = { t = time(), kind = kind }
    if kind == "level" then
        e.levels = {}
        for _, k in ipairs(kids) do
            e.levels[#e.levels + 1] = k.lo
            e.levels[#e.levels + 1] = k.hi
        end
    end
    store.censusLearned[nodeKey(n)] = e
end

-- Replace any node we already know caps with its children, recursively.
function C:Expand(nodes, st, out, depth)
    for _, n in ipairs(nodes) do
        if depth < MAX_PRESPLIT_DEPTH and splitsExhaustively(n) and self:Learned(n) then
            st.preSplit = st.preSplit + 1
            self:Expand((self:Children(n, st)), st, out, depth + 1)
        else
            out[#out + 1] = n
        end
    end
end

-- Add nodes to the queue (front = right after the current query, keeping a
-- split's children together). Respects the budget; returns how many were added.
function C:Enqueue(nodes, st, front)
    local planned = {}
    self:Expand(nodes, st, planned, 0)
    local room = math.max(st.budget - st.done - #st.queue, 0)
    if #planned > room then
        st.unresolved = st.unresolved + (#planned - room)
        st.overBudget = true
        for i = #planned, room + 1, -1 do planned[i] = nil end
    end
    if front then
        for i = #planned, 1, -1 do table.insert(st.queue, 1, planned[i]) end
    else
        for _, n in ipairs(planned) do st.queue[#st.queue + 1] = n end
    end
    return #planned
end

function C:PurgeLearned()
    local _, store = self:State()
    local cutoff = time() - LEARN_DAYS * 86400
    for key, e in pairs(store.censusLearned) do
        if type(e) ~= "table" or (e.t or 0) < cutoff then store.censusLearned[key] = nil end
    end
    for key, s in pairs(store.censusSeen) do
        if (s.t or 0) < cutoff then store.censusSeen[key] = nil end
    end
end

-- Seed censusSeen from the latest census sweep's uncapped level and
-- level+class queries, so splits learned before counts were recorded can be
-- compacted or re-chosen right away.
function C:SeedSeen()
    local _, store = self:State()
    local latest
    for _, sweep in pairs(store.sweeps or {}) do
        if (sweep.label or ""):match("^census ") and (not latest or sweep.startedAt > latest.startedAt) then
            latest = sweep
        end
    end
    local tokenOf = {}
    for _, token in ipairs(self:Profile().classes) do tokenOf[P.ClassName(token)] = token end
    for _, q in ipairs(latest and latest.queries or {}) do
        local f = q.filter or ""
        local levels, className = f:match('^(%S+) c%-"([^"]+)"$')
        levels = levels or f
        local lo, hi = levels:match("^(%d+)%-(%d+)$")
        if not lo then lo = levels:match("^(%d+)$"); hi = lo end
        local class = className and tokenOf[className]
        if lo and not q.capped and (class or not className) then
            local key = nodeKey({ lo = tonumber(lo), hi = tonumber(hi), class = class })
            if not store.censusSeen[key] then store.censusSeen[key] = { t = q.t, n = q.observed or 0 } end
        end
    end
end

function C:Start(budget)
    local prof, why = P.Current()
    if not prof then ML:Print(why); return end
    self.profile, self.hist = prof, nil
    local st, store = self:State()
    if st then
        ML:Print("A census is already running -- |cffffff00/ml census next|r to continue or |cffffff00/ml census stop|r.")
        return
    end
    self:PurgeLearned()
    self:SeedSeen()

    local sweepID = Pop:StartSweep("census " .. prof.id, true)
    st = {
        profile = prof.id, label = prof.label, faction = prof.faction,
        startedAt = time(), sweepID = sweepID, queue = {},
        done = 0, generated = 0, preSplit = 0, capped = 0, unresolved = 0, retries = 0,
        budget = budget or ML.db.settings.censusBudget or DEFAULT_BUDGET,
        ratioObs = 0, ratioHist = 0,
    }
    store.census = st

    -- Highest levels first: if the census is stopped early, the most
    -- economically relevant part of the population is already covered.
    local backbone = {}
    for i = #prof.bands, 1, -1 do
        local b = prof.bands[i]
        if b[1] <= prof.maxLevel then
            backbone[#backbone + 1] = { lo = b[1], hi = math.min(b[2], prof.maxLevel) }
        end
    end
    self:Enqueue(backbone, st, false)
    ML:Print("Census started: |cffffffff%s|r %s, %d queries planned%s. Press |cffffff00Run Next|r (or /ml census next) for each.",
        prof.label, prof.faction, #st.queue,
        st.preSplit > 0 and string.format(" (%d pre-split from earlier runs)", st.preSplit) or "")
    ML:Fire("CENSUS_CHANGED")
    self:PromptNext()
end

-- Get the queue ready for its next send. Returns the next node, or nil when
-- the last /who is still in flight (quiet=false prints why).
function C:Settle(st, quiet)
    if st.inflight then
        if Pop.pending and GetTime() - Pop.lastSend < Pop.COOLDOWN then
            if not quiet then ML:Print("Still waiting on the last /who...") end
            return nil
        end
        -- No WHO_LIST_UPDATE came back (throttled or a /reload): retry it.
        table.insert(st.queue, 1, st.inflight)
        st.inflight = nil
        st.retries = st.retries + 1
    end
    -- Drop anything planned above the current level cap (e.g. a census started
    -- before the Forever beta cap was pinned).
    local maxL = self:Profile().maxLevel
    while st.queue[1] and st.queue[1].lo > maxL do table.remove(st.queue, 1) end
    return st.queue[1]
end

local function trim(s) return (tostring(s or ""):gsub("^%s+", ""):gsub("%s+$", "")) end

-- Some clients (the Forever beta) block SendWho from the census's own code
-- path but allow it when the filter arrives as a typed /ml who. In chat mode,
-- Run Next types the next query into the chat box; the player sends it with
-- Enter, and TryChatQuery recognizes it as the census step.
function C:ViaChat()
    return ML.db.settings.censusViaChat
end

function C:SetViaChat(on)
    ML.db.settings.censusViaChat = on or nil
    ML:Print("Census chat mode %s.", on
        and "|cff40c040on|r -- press Enter on each /ml who the census types for you"
        or "off -- Run Next sends queries directly")
    if on then self:PromptNext() end
    ML:Fire("CENSUS_CHANGED")
end

-- Put the next census query into the chat box once the cooldown allows it.
function C:PromptNext()
    local st = self:State()
    if not st or not self:ViaChat() then return end
    local n = self:Settle(st, true)
    if not n then return end
    local filter = self:Filter(n)
    C_Timer.After(Pop:CooldownRemaining(), function()
        local s = self:State()
        if not s or s.inflight or not s.queue[1] or self:Filter(s.queue[1]) ~= filter then return end
        local open = ChatFrame_OpenChat or (ChatFrameUtil and ChatFrameUtil.OpenChat)
        if open then
            open("/ml who " .. filter)
        else
            ML:Print("Next: |cffffff00/ml who %s|r", filter)
        end
    end)
end

-- A typed /ml who: if it is the census's next query, run it as that step.
-- Returns true when the census took it.
function C:TryChatQuery(filter)
    local st = self:State()
    if not st then return false end
    filter = trim(filter)
    local n = self:Settle(st, true)
    if not n or self:Filter(n) ~= filter then return false end
    self:Send(st, n, filter)
    return true
end

-- Mark the node in flight BEFORE sending: a refused SendWho raises
-- ADDON_ACTION_BLOCKED during the call itself, and its handler must find the
-- query to put it back.
function C:Send(st, n, filter)
    table.remove(st.queue, 1)
    st.inflight = n
    if not Pop:Scan(filter, "census") and st.inflight == n then
        -- Not sent (client cooldown): leave it next in line.
        table.insert(st.queue, 1, n)
        st.inflight = nil
        return
    end
    ML:Fire("CENSUS_CHANGED")
end

-- Must be reached from a hardware event (button click or slash command).
function C:RunNext()
    local st = self:State()
    if not st then
        ML:Print("No census is running -- |cffffff00/ml census start|r.")
        return
    end
    if self:ViaChat() then
        -- Nothing to send from here: type the query in for the player.
        self:PromptNext()
        return
    end
    local n = self:Settle(st)
    if not n then
        if not st.inflight then self:Finish() end
        return
    end
    self:Send(st, n, self:Filter(n))
end

function C:OnScanComplete(sample, tag)
    local st, store = self:State()
    if tag ~= "census" or not st or not st.inflight then return end
    local n = st.inflight
    st.inflight = nil
    st.done = st.done + 1

    local observed = sample.observed or 0
    local key = nodeKey(n)
    local last = { filter = sample.filter, observed = observed, capped = false, split = 0 }
    if Pop:IsCapped(observed) then
        last.capped = true
        st.capped = st.capped + 1
        local kids, exhaustive, kind = self:Children(n, st)
        if exhaustive then
            self:Learn(n, kind, kids)
        elseif not n.zone then
            st.unresolved = st.unresolved + 1 -- zones cannot fully cover this cell
        end
        last.split = self:Enqueue(kids, st, true)
        st.generated = st.generated + last.split
        -- Tell the sweep export this cap is covered by its children, so the
        -- site's capped_count reflects real coverage gaps only.
        local sweep = store.sweeps[st.sweepID]
        local q = sweep and sweep.queries and sweep.queries[#sweep.queries]
        if exhaustive and last.split == #kids and q and q.filter == sample.filter then
            q.split = true
        end
    else
        store.censusLearned[key] = nil
        if not n.zone then store.censusSeen[key] = { t = time(), n = observed } end
        -- Calibrate online/sighting: uncapped counts are exact.
        if not n.zone then
            local raw = self:Weight(n)
            if raw > 0 then
                st.ratioObs = st.ratioObs + observed
                st.ratioHist = st.ratioHist + raw
            end
        end
    end
    st.last = last
    -- One line per step: progress, query, result.
    ML:Print("|cff808080%d/%d|r %s |cff808080=|r %s", st.done, st.done + #st.queue, sample.filter or "",
        last.capped and string.format("|cffff8040%d, split %d|r", observed, last.split) or tostring(observed))

    if #st.queue == 0 then
        self:Finish()
    else
        ML:Fire("CENSUS_CHANGED")
        self:PromptNext()
    end
end

-- Unique characters and total rows seen by this census's sweep.
function C:Counts(st)
    local _, store = self:State()
    local sweep = st and store.sweeps[st.sweepID]
    if not sweep then return 0, 0 end
    local unique, rows = 0, 0
    for _ in pairs(sweep.observations or {}) do unique = unique + 1 end
    for _, q in ipairs(sweep.queries or {}) do rows = rows + (q.observed or 0) end
    return unique, rows
end

function C:Finish(stopped)
    local st, store = self:State()
    if not st then return end
    local drained = #st.queue == 0 and not st.inflight
    local status = (not stopped and drained and st.unresolved == 0) and "complete" or "partial"
    local unique, rows = self:Counts(st)
    if store.activeSweepID == st.sweepID then Pop:FinishSweep(status) end
    store.censusLast = {
        profile = st.profile, label = st.label, faction = st.faction,
        startedAt = st.startedAt, finishedAt = time(), status = status, stopped = stopped and true or nil,
        done = st.done, generated = st.generated, preSplit = st.preSplit,
        unresolved = st.unresolved, remaining = #st.queue, unique = unique, rows = rows,
    }
    store.census = nil
    ML:Print("Census %s: %d queries, %d unique characters (%d duplicate sightings), %d unresolved capped cell(s).",
        stopped and "stopped" or "finished", st.done, unique, math.max(rows - unique, 0), st.unresolved)
    if st.unresolved > 0 then
        ML:Print("Unresolved cells still hit the /who cap after every split -- treat counts as a lower bound.")
    end
    ML:Fire("CENSUS_CHANGED")
end

function C:Stop()
    if not self:State() then
        ML:Print("No census is running.")
        return
    end
    self:Finish(true)
end

function C:Forget()
    local _, store = self:State()
    local n = U.CountKeys(store.censusLearned)
    store.censusLearned = {}
    ML:Print("Forgot %d learned census split(s); the next census starts from the plain backbone.", n)
end

function C:PrintProfile()
    local prof, why = P.Current()
    if not prof then ML:Print(why); return end
    local _, store = self:State()
    local bands = {}
    for _, b in ipairs(prof.bands) do
        bands[#bands + 1] = b[1] == b[2] and tostring(b[1]) or (b[1] .. "-" .. b[2])
    end
    ML:Print("Census profile |cffffffff%s|r (%s), level cap %d, /who cap at %d results.",
        prof.label, prof.faction, prof.maxLevel, prof.capAt)
    ML:Print("  Backbone: %s", table.concat(bands, ", "))
    ML:Print("  Learned splits for %s: %d", ML:RealmKey(), U.CountKeys(store.censusLearned))
end

function C:Status()
    local st = self:State()
    if not st then
        ML:Print("No census is running -- |cffffff00/ml census start|r.")
        return
    end
    local unique, rows = self:Counts(st)
    ML:Print("Census %s %s: %d/%d queries, next %s, %d unique, %d unresolved.",
        st.label, st.faction, st.done, st.done + #st.queue,
        st.queue[1] and self:Filter(st.queue[1]) or "--", unique, st.unresolved)
end

-- Snapshot for the UI (nil when idle).
function C:Summary()
    local st, store = self:State()
    if not st then return nil, store.censusLast end
    local unique, rows = self:Counts(st)
    return {
        label = st.label, faction = st.faction, done = st.done,
        total = st.done + #st.queue + (st.inflight and 1 or 0),
        inflight = st.inflight and self:Filter(st.inflight),
        next = st.queue[1] and self:Filter(st.queue[1]),
        last = st.last, generated = st.generated, preSplit = st.preSplit,
        unresolved = st.unresolved, overBudget = st.overBudget, retries = st.retries,
        unique = unique, duplicates = math.max(rows - unique, 0),
    }, store.censusLast
end

function C:IsActive()
    return self:State() ~= nil
end

ML:On("POP_SCAN_COMPLETE", function(sample, tag) C:OnScanComplete(sample, tag) end)

-- The client refused to send our /who: put the query back at the front, and
-- switch to chat mode, the path this client does accept.
ML:On("POP_SCAN_FAILED", function(_, tag)
    local st = C:State()
    if tag ~= "census" or not st or not st.inflight then return end
    table.insert(st.queue, 1, st.inflight)
    st.inflight = nil
    ML:Fire("CENSUS_CHANGED")
    if C:ViaChat() then C:PromptNext() else C:SetViaChat(true) end
end)
