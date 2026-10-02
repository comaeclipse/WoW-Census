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
local ZONE_SPLITS = 4      -- zones tried for a fully-pinned capped cell
local NAME_SPLITS = 12     -- high-yield name letters tried before zones
local MIN_ZONE_SUPPORT = 3 -- recent sightings a zone needs to be worth a query
local FIXED_SPLIT_AT = 40  -- fixed list: split a cell up front once this many were seen recently
local DEFAULT_BUDGET = 300 -- max queries a single census may plan
local MERGE_TARGET = 40    -- merge learned level parts while their last counts sum under this
local MAX_PRESPLIT_DEPTH = 8 -- level -> class -> level -> race chains, with room for nested level splits
local ANSWER_TIMEOUT = 15
local BACKOFF_MIN = 30
local BACKOFF_MAX = 90

-- Node helpers. A node is { lo=, hi=, class=token?, race=?, letter=?, zone=? }.

local function nodeKey(n)
    if n.refreshFilter then return "refresh|" .. n.refreshFilter end
    return string.format("%d-%d|%s|%s|%s|%s", n.lo, n.hi, n.class or "", n.race or "",
        n.letter or "", n.zone or "")
end

-- Level, class and race splits partition their parent; a zone split does not.
local function splitsExhaustively(n)
    if n.refreshFilter then return false end
    return n.hi > n.lo or not n.class or not n.race
end

function C:Filter(n)
    if n.refreshFilter then return n.refreshFilter end
    local parts = { n.lo == n.hi and tostring(n.lo) or (n.lo .. "-" .. n.hi) }
    if n.class then parts[#parts + 1] = 'c-"' .. P.ClassName(n.class) .. '"' end
    if n.race then parts[#parts + 1] = 'r-"' .. n.race .. '"' end
    if n.letter then parts[#parts + 1] = 'n-"' .. n.letter .. '"' end
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
    local recent, racesByClass, classCount, raceCount = {}, {}, {}, {}
    for _, c in pairs(store.characters or {}) do
        local L = tonumber(c.level) or 0
        if L > 0 and (c.lastSeen or 0) >= cutoff then recent[#recent + 1] = c end
        if c.classFile and c.race and c.race ~= "" then
            racesByClass[c.classFile] = racesByClass[c.classFile] or {}
            racesByClass[c.classFile][c.race] = true
            classCount[c.classFile] = (classCount[c.classFile] or 0) + 1
            raceCount[c.race] = (raceCount[c.race] or 0) + 1
        end
    end
    self.hist = { recent = recent, racesByClass = racesByClass, classCount = classCount, raceCount = raceCount }
    return self.hist
end

-- /who matches c- and r- as substrings: c-"Hunter" also returns Demon
-- Hunters, r-"Dwarf" Dark Iron Dwarves, r-"Draenei" Lightforged. Count the
-- way the server does, so a cell's expected size includes its overlap.
local function contains(have, want)
    return have ~= nil and tostring(have):lower():find(tostring(want):lower(), 1, true) ~= nil
end

local function matchesNode(c, n)
    local L = tonumber(c.level) or 0
    return L >= n.lo and L <= n.hi
        and (not n.class or c.classFile == n.class
            or (c.classFile and contains(P.ClassName(c.classFile), P.ClassName(n.class))))
        and (not n.race or contains(c.race, n.race))
        and (not n.letter or tostring(c.name or c.fullName or ""):lower():find(n.letter, 1, true))
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
            kids[#kids + 1] = { lo = lo, hi = L, class = n.class, race = n.race,
                letter = n.letter, zone = n.zone }
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
local MIN_RACE_HISTORY = 60
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

-- Public wrapper used by the manual plan. Retail does not ship a complete
-- race/class matrix here, so this returns the profile list until the realm has
-- enough identity history, then prunes combinations never observed locally.
function C:RaceList(class)
    return raceList(self:Profile(), class, self:History())
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
    -- Only zones with real support: a zone this cell was seen in once or
    -- twice almost always comes back empty (44 of 102 beta zone queries did).
    local function ranked()
        local zones = {}
        for z, c in pairs(counts) do
            if c >= MIN_ZONE_SUPPORT then zones[#zones + 1] = z end
        end
        table.sort(zones, function(a, b)
            if counts[a] ~= counts[b] then return counts[a] > counts[b] end
            return a < b
        end)
        return zones
    end
    tally(true)
    local zones = ranked()
    if #zones < 2 then
        counts = {}
        tally(false) -- fall back to where anyone at these levels gathers
        zones = ranked()
    end
    local out = {}
    for _, z in ipairs(zones) do
        if #out >= ZONE_SPLITS then break end
        out[#out + 1] = z
    end
    if #out == 0 then -- no history at all: the profile's biggest hubs
        for i = 1, math.min(2, #prof.hotspots) do out[#out + 1] = prof.hotspots[i] end
    end
    return out
end

-- n- matches anywhere in a name, so these children overlap and remain
-- coverage-labeled. Greedily choose the letters expected to reveal the most
-- not-yet-covered characters instead of spending 26 mostly duplicate queries.
local function letterList(n, hist)
    local names = {}
    for _, c in ipairs(hist.recent or {}) do
        if matchesNode(c, n) then
            local name = tostring(c.name or c.fullName or ""):lower()
            if name ~= "" then names[#names + 1] = name end
        end
    end
    local candidates = {}
    local order = "aeinorstludmchpgbyfkvwxqjz"
    for i = 1, #order do candidates[#candidates + 1] = order:sub(i, i) end
    local chosen, covered = {}, {}
    while #chosen < NAME_SPLITS and #candidates > 0 do
        local bestIndex, bestGain = 1, -1
        for i, letter in ipairs(candidates) do
            local gain = 0
            for j, name in ipairs(names) do
                if not covered[j] and name:find(letter, 1, true) then gain = gain + 1 end
            end
            if gain > bestGain then bestIndex, bestGain = i, gain end
        end
        local letter = table.remove(candidates, bestIndex)
        chosen[#chosen + 1] = letter
        for j, name in ipairs(names) do
            if name:find(letter, 1, true) then covered[j] = true end
        end
    end
    return chosen
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
    if not n.class and not n.race then
        -- Pick the cheaper exhaustive first axis from recent realm sightings.
        -- Cost includes the first set of queries plus a rough penalty for bins
        -- expected to cap and therefore need the opposite axis as well.
        local prof, hist = self:Profile(), self:History()
        local function axisCost(axis, values)
            local counts, support = {}, 0
            for _, c in ipairs(hist.recent or {}) do
                if matchesNode(c, n) then
                    local value = axis == "class" and c.classFile or c.race
                    if value and value ~= "" then
                        counts[value] = (counts[value] or 0) + 1
                        support = support + 1
                    end
                end
            end
            if support < 20 then return nil end
            local cost = #values
            for _, value in ipairs(values) do
                if (counts[value] or 0) >= prof.capAt * 0.8 then
                    if axis == "class" then
                        cost = cost + math.max(#raceList(prof, value, hist) - 1, 1)
                    else
                        local possible = 0
                        for _, token in ipairs(prof.classes) do
                            local allowed = prof.racesByClass and prof.racesByClass[token]
                            if not allowed then
                                possible = possible + 1
                            else
                                for _, race in ipairs(allowed) do
                                    if race == value then possible = possible + 1 break end
                                end
                            end
                        end
                        cost = cost + math.max(possible - 1, 1)
                    end
                end
            end
            return cost
        end
        local classCost = axisCost("class", prof.classes)
        local raceCost = axisCost("race", prof.races)
        if raceCost and (not classCost or raceCost < classCost) then return "race" end
        return "class"
    end
    if not n.class then return "class" end
    if not n.race then return "race" end
    if not n.letter then return "letter" end
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
                kids[#kids + 1] = { lo = learned.levels[i], hi = learned.levels[i + 1],
                    class = n.class, race = n.race, letter = n.letter, zone = n.zone }
            end
            kids = self:Compact(n, learned, kids)
        else
            kids = self:SplitLevels(n, st, total)
            -- Switched from a learned class split: persist the new plan.
            if learned then self:Learn(n, kind, kids) end
        end
        return kids, true, kind
    elseif kind == "class" then
        -- Rulesets without a race/class table (MoP, Retail, Forever): once a
        -- race has MIN_RACE_HISTORY lifetime sightings here, skip classes it
        -- was never seen playing (Human Evoker, Night Elf Paladin, ...), the
        -- same trust rule raceList applies in the other direction.
        local hist = n.race and not prof.racesByClass and self:History()
        local trusted = hist and (hist.raceCount[n.race] or 0) >= MIN_RACE_HISTORY
        for _, token in ipairs(prof.classes) do
            local include = true
            if n.race and prof.racesByClass and prof.racesByClass[token] then
                include = false
                for _, race in ipairs(prof.racesByClass[token]) do
                    if race == n.race then include = true break end
                end
            elseif trusted then
                include = (hist.racesByClass[token] or {})[n.race] and true or false
            end
            if include then kids[#kids + 1] = { lo = n.lo, hi = n.hi, class = token,
                race = n.race, letter = n.letter, zone = n.zone } end
        end
        return kids, true, kind
    elseif kind == "race" then
        for _, race in ipairs(raceList(prof, n.class, self:History())) do
            kids[#kids + 1] = { lo = n.lo, hi = n.hi, class = n.class, race = race,
                letter = n.letter, zone = n.zone }
        end
        return kids, true, kind
    elseif kind == "letter" then
        for _, letter in ipairs(letterList(n, self:History())) do
            kids[#kids + 1] = { lo = n.lo, hi = n.hi, class = n.class, race = n.race,
                letter = letter, zone = n.zone }
        end
        return kids, false, kind
    elseif kind == "zone" then
        if not prof.zoneFallback then return kids, false, kind end -- stays a lower bound
        for _, zone in ipairs(zoneList(prof, n, self:History())) do
            kids[#kids + 1] = { lo = n.lo, hi = n.hi, class = n.class, race = n.race,
                letter = n.letter, zone = zone }
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
            cur = { lo = cur.lo, hi = k.hi, class = n.class, race = n.race,
                letter = n.letter, zone = n.zone }
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

-- Fixed-list mode: the whole query list is decided when the census starts and
-- never grows; a query that still hits the cap is recorded as a lower bound
-- (and learned, so the next census's list splits it up front). On by default
-- where adaptive splitting would snowball (the Forever beta); /ml census fixed
-- on|off overrides it per client.
function C:Fixed(st)
    if st and st.fixed ~= nil then return st.fixed end
    local set = ML.db.settings.censusFixed
    if set ~= nil then return set end
    -- Passive collection has time to refine capped cells while the player is
    -- already playing. Keep Forever's short fixed refresh only for deliberate
    -- manual sessions; passive runs stay bounded by censusBudget instead.
    if ML.db.settings.censusPassive then return false end
    return self:Profile().fixedPlan and true or false
end

-- Whether planning should split a node up front: known to cap, or (fixed
-- list) recent sightings alone already put it near the cap.
function C:ShouldPreSplit(n, st)
    if n.refreshFilter then return false end
    if self:Learned(n) then return true end
    return st.fixed and self:Weight(n) >= FIXED_SPLIT_AT
end

-- Replace any node we already know caps with its children, recursively.
function C:Expand(nodes, st, out, depth)
    for _, n in ipairs(nodes) do
        if depth < MAX_PRESPLIT_DEPTH and splitsExhaustively(n) and self:ShouldPreSplit(n, st) then
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

local function shuffle(list)
    for i = #list, 2, -1 do
        local j = math.random(i)
        list[i], list[j] = list[j], list[i]
    end
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
    st.fixed = self:Fixed()
    store.census = st
    -- A fixed list is planned from recent sightings taken at face value.
    if st.fixed then st.ratioObs, st.ratioHist = 1, 1 end

    local backbone = {}
    local adaptiveForever = prof.id == "forever" and ML.db.settings.censusPassive
    if prof.simpleRefresh and not adaptiveForever then
        -- Forever and Retail are enormous, realmless populations. This is a
        -- deliberately small refresh sample, not an attempt to enumerate
        -- everyone online: levels 1-20, then every faction race and class.
        for level = 1, math.min(prof.maxLevel, 20) do
            backbone[#backbone + 1] = { refreshFilter = tostring(level) }
        end
        for _, race in ipairs(prof.races) do
            backbone[#backbone + 1] = { refreshFilter = 'r-"' .. race .. '"' }
        end
        for _, class in ipairs(prof.classes) do
            backbone[#backbone + 1] = { refreshFilter = 'c-"' .. P.ClassName(class) .. '"' }
        end
        st.fixed = true
    else
        -- Highest levels first: if the census is stopped early, the most
        -- economically relevant part of the population is already covered.
        for i = #prof.bands, 1, -1 do
            local b = prof.bands[i]
            if b[1] <= prof.maxLevel then
                backbone[#backbone + 1] = { lo = b[1], hi = math.min(b[2], prof.maxLevel) }
            end
        end
    end
    self:Enqueue(backbone, st, false)
    -- Passive sessions may span a long play period. Shuffling avoids always
    -- measuring low/high levels at the same point in that period. Deliberate
    -- manual sessions retain the economically useful high-level-first order.
    if ML.db.settings.censusPassive then shuffle(st.queue) end
    if st.fixed then st.ratioObs, st.ratioHist = 0, 0 end
    ML:Print("Census started: |cffffffff%s|r %s, %s%d queries%s.",
        prof.label, prof.faction, st.fixed and "fixed list of " or "", #st.queue,
        st.fixed and " -- it will not grow; anything over 50 is recorded as 50+"
            or (st.preSplit > 0 and string.format(" planned (%d pre-split from earlier runs)", st.preSplit) or " planned"))
    ML:Fire("CENSUS_CHANGED")
    self:PromptNext()
end

-- Get the queue ready for its next send. Returns the next node, or nil when
-- the last /who is still in flight (quiet=false prints why).
function C:Settle(st, quiet)
    if st.inflight then
        local sentAt = st.inflightSentAt or time()
        if Pop.pending and time() - sentAt < ANSWER_TIMEOUT then
            if not quiet then ML:Print("Still waiting on the last /who...") end
            return nil
        end
        -- One silent miss can be an input the client declined. A second miss is
        -- treated as server throttling and persisted as a real backoff, so a
        -- passive census cannot hammer /who while the player keeps moving.
        table.insert(st.queue, 1, st.inflight)
        st.inflight = nil
        st.inflightSentAt = nil
        st.retries = st.retries + 1
        st.misses = (st.misses or 0) + 1
        if st.misses >= 2 then
            st.nextAttemptAt = time() + math.random(BACKOFF_MIN, BACKOFF_MAX)
            st.misses = 0
            if not quiet then ML:Print("/who appears throttled; retrying after a short backoff.") end
        end
    end
    if st.nextAttemptAt and time() < st.nextAttemptAt then return nil end
    st.nextAttemptAt = nil
    -- Drop anything planned above the current level cap (e.g. a census started
    -- before the Forever beta cap was pinned).
    local prof = self:Profile()
    while st.queue[1] and st.queue[1].lo and st.queue[1].lo > prof.maxLevel do table.remove(st.queue, 1) end
    -- Profiles without zone fallback drop zone queries planned before that
    -- changed (they sit at the back of the queue).
    if not prof.zoneFallback then
        for i = #st.queue, 1, -1 do
            if st.queue[i].zone then table.remove(st.queue, i) end
        end
    end
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

function C:SetViaChat(on, quiet)
    ML.db.settings.censusViaChat = on or nil
    if not quiet then
        ML:Print("Census chat mode %s.", on
            and "|cff40c040on|r -- press Enter on each /ml who the census types for you"
            or "off -- Run Next sends queries directly")
    end
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
        local command = "/ml who " .. filter
        -- Never leave chat-mode progress dependent on the edit box opening.
        -- Forever occasionally drops ChatFrame_OpenChat after a long series of
        -- commands, so keep the exact command visible as a reliable fallback.
        ML:Print("Next: |cffffff00%s|r", command)
        local open = ChatFrame_OpenChat or (ChatFrameUtil and ChatFrameUtil.OpenChat)
        if open then
            open(command)
            -- A chat box can close immediately when this callback lands on the
            -- tail of the previous Enter. If no edit box remains active, try
            -- once more on the next frame; the printed command still remains.
            C_Timer.After(0.1, function()
                local current = self:State()
                if not current or current.inflight or not current.queue[1]
                    or self:Filter(current.queue[1]) ~= filter then return end
                local active = ChatEdit_GetActiveWindow and ChatEdit_GetActiveWindow()
                if not active then open(command) end
            end)
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
function C:Send(st, n, filter, source)
    table.remove(st.queue, 1)
    st.inflight = n
    st.inflightSentAt = time()
    st.inflightSource = source
    if not Pop:Scan(filter, "census") and st.inflight == n then
        -- Not sent (client cooldown): leave it next in line, and in chat
        -- mode type it again once the cooldown clears.
        table.insert(st.queue, 1, n)
        st.inflight = nil
        st.inflightSentAt = nil
        st.inflightSource = nil
        self:PromptNext()
        return
    end
    ML:Fire("CENSUS_CHANGED")
end

-- Must be reached from a hardware event (button click or slash command).
function C:RunNext(quiet, source)
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
    local n = self:Settle(st, quiet)
    if not n then
        -- A persisted throttle backoff has no in-flight query, but it is not
        -- completion. A future hardware event will try again after the wait.
        if not st.inflight and not (st.nextAttemptAt and time() < st.nextAttemptAt) then self:Finish() end
        return
    end
    self:Send(st, n, self:Filter(n), source)
end

function C:OnScanComplete(sample, tag)
    local st, store = self:State()
    if tag ~= "census" or not st or not st.inflight then return end
    local n = st.inflight
    st.inflight = nil
    st.inflightSentAt = nil
    st.inflightSource = nil
    st.misses = 0
    st.nextAttemptAt = nil
    st.done = st.done + 1

    local observed = sample.observed or 0
    local key = nodeKey(n)
    local last = { filter = sample.filter, observed = observed, capped = false, split = 0 }
    if Pop:IsCapped(observed) then
        last.capped = true
        st.capped = st.capped + 1
        local kids, exhaustive, kind = {}, false, nil
        if not n.refreshFilter then kids, exhaustive, kind = self:Children(n, st) end
        if exhaustive then
            self:Learn(n, kind, kids)
        elseif not n.zone then
            st.unresolved = st.unresolved + 1 -- zones cannot fully cover this cell
        end
        if self:Fixed(st) then
            -- Fixed list: record the lower bound, learn the split for next
            -- time, and add nothing to this census.
            if exhaustive then st.unresolved = st.unresolved + 1 end
            kids = {}
        end
        -- Exact splits go next (depth-first keeps a cell's parts together);
        -- zone fallbacks go to the back, so every class and race is covered
        -- before any lower-bound cell gets extra digging. A census stopped
        -- early then still has the whole picture.
        last.split = self:Enqueue(kids, st, exhaustive)
        st.generated = st.generated + last.split
        -- Tell the sweep export this cap is covered by its children, so the
        -- site's capped_count reflects real coverage gaps only.
        local sweep = store.sweeps[st.sweepID]
        local q = sweep and sweep.queries and sweep.queries[#sweep.queries]
        if exhaustive and #kids > 0 and last.split == #kids and q and q.filter == sample.filter then
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
    ML:CensusPrint("|cff808080%d/%d|r %s |cff808080=|r %s", st.done, st.done + #st.queue, sample.filter or "",
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
    local wait = st.nextAttemptAt and math.max(st.nextAttemptAt - time(), 0) or 0
    ML:Print("Census %s %s: %d/%d queries, next %s%s, %d unique, %d unresolved.",
        st.label, st.faction, st.done, st.done + #st.queue,
        st.queue[1] and self:Filter(st.queue[1]) or "--",
        wait > 0 and string.format(" (backoff %ds)", wait) or "", unique, st.unresolved)
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
        passive = ML.db.settings.censusPassive and true or false,
        backoff = st.nextAttemptAt and math.max(st.nextAttemptAt - time(), 0) or 0,
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
    local source = st.inflightSource
    table.insert(st.queue, 1, st.inflight)
    st.inflight = nil
    st.inflightSentAt = nil
    st.inflightSource = nil
    ML:Fire("CENSUS_CHANGED")
    if ML.db.settings.censusPassive and Pop.Passive then
        Pop.Passive:OnSendFailure(source)
    elseif C:ViaChat() then
        C:PromptNext()
    else
        C:SetViaChat(true)
    end
end)

ML:On("POP_SCAN_STALE", function(_, tag)
    local st = C:State()
    if tag ~= "census" or not st or not st.inflight then return end
    table.insert(st.queue, 1, st.inflight)
    st.inflight, st.inflightSentAt, st.inflightSource = nil, nil, nil
    ML:Fire("CENSUS_CHANGED")
end)
