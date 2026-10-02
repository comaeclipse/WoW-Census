-- /ml plan: a copy-paste /who plan for the client, flavor and faction you are
-- on. Click a line (or Next) to put it in chat, then press Enter: typed /who
-- is a hardware event on every client, including the ones that block the
-- census's own SendWho. Results fill in as they return. The queries run inside
-- a census sweep so the site sees the pass as one bounded collection.
--
-- Two modes, picked per pass:
--
--   Full count  the census's disjoint query list, split until no line caps, so
--               every online player is seen once. For realms a finished pass
--               has shown to fit in FULL_LIMIT queries.
--   Snapshot    for realms where that would take hundreds (Stormrage: every
--               level-90 class caps; Ashkandi: single levels 10-14 each cap).
--               The profile's coarse level bands plus one query per class at
--               the level cap -- ~14 queries on Era -- so every bracket and
--               class gets sampled each pass. A line that caps is simply kept
--               as its 50: no digging by name or zone.
--
-- The point is the realm's race and class lean, not a head count. The window
-- shows it the way the site does: every character census queries found in the
-- last CENSUS_WINDOW_DAYS, once each. A capped line's 50 differ from session to
-- session as people log on and off, so repeated passes fill the picture in.

local ML = MarketLens
local UI = ML.UI
local Pop = ML.Population
local Plan = {}
UI.Plan = Plan

local VISIBLE = 16
local ROW_H = 16
local W, H = 480, 440
local MAX_DEPTH = 6
local PASS_TTL = 3 * 3600      -- a pass older than this is over; opening starts fresh
local LAST_PASS_DAYS = 14      -- how long an exact pass keeps shaping the next one
local FULL_LIMIT = 40          -- a full count longer than this runs as a snapshot
local CENSUS_WINDOW_DAYS = 14  -- keep in step with site/src/census.mjs

-- Retail's cap-level population is too large for one class query to be useful.
-- Rotate two classes per local calendar day and stratify them by race. These
-- cells are observational samples, not additive bins: Retail's text matching
-- can make Hunter/Demon Hunter and Dwarf/Dark Iron Dwarf overlap. Character
-- identity deduplication across the rolling window is the measurement.
local RETAIL_ROTATION = {
    { 1, 2 }, { 3, 4 }, { 5, 6 }, { 7, 8 },
    { 9, 10 }, { 11, 12 }, { 13 },
}

local function norm(f)
    return (tostring(f or ""):lower():gsub("%s+", " "):gsub("^ ", ""):gsub(" $", ""))
end

-- Level, class and race splits partition their parent; letter/zone do not.
local function exhaustive(n)
    return n.hi > n.lo or not n.class or not n.race
end

local function openChat(text)
    local open = ChatFrame_OpenChat or (ChatFrameUtil and ChatFrameUtil.OpenChat)
    if open then open(text) else ML:Print("|cffffff00%s|r", text) end
end

-- Pass state ----------------------------------------------------------------

function Plan:EndPass()
    local store = Pop:Store()
    local p = store.planPass
    -- A finished pass already closed its sweep; an open one ends unfinished.
    if p and p.sweepID and store.activeSweepID == p.sweepID then Pop:FinishSweep("partial") end
    -- A finished pass is the next pass's model of this realm (see Seed) only
    -- if every line was exact: a snapshot saw 50 of each capped line, which
    -- would make a mega-realm look small enough for a full count.
    if p and p.finished and p.exact then store.planLastStart = p.startedAt end
    store.planPass = nil
end

function Plan:Pass(prof)
    local store = Pop:Store()
    local p = store.planPass
    if p and (time() - (p.startedAt or 0) > PASS_TTL or p.profile ~= prof.id) then
        self:EndPass()
        p = nil
    end
    if not p then
        p = { startedAt = time(), profile = prof.id }
        store.planPass = p
    end
    -- The query list is decided once per pass. Re-planning as results arrive
    -- let the level-90 split flip from class to race mid-pass, and every
    -- player the abandoned half had seen was queried again.
    if not p.nodes then
        -- Model: the last exact plan pass, else the newest complete census
        -- sweep (so realms censused before /ml plan existed tailor at once).
        local lastStart = store.planLastStart
        if not lastStart then
            for _, sw in pairs(store.sweeps or {}) do
                if sw.status == "complete" and (sw.label or ""):match("^census ")
                    and (not lastStart or sw.startedAt > lastStart) then lastStart = sw.startedAt end
            end
        end
        -- Auto: a full count only for a realm an exact pass has shown to fit
        -- in FULL_LIMIT queries. Anything else starts as a snapshot, which is
        -- exact on a small realm anyway (nothing caps) -- a full plan guessed
        -- from a week of sightings snowballs once lines start capping.
        local mode = store.planMode -- "full" | "snapshot" | nil (auto)
        local nodes, seedMeta
        if mode == "full" or (mode == nil and lastStart) then
            nodes, seedMeta = self:Seed(prof, lastStart, "full")
        end
        local retailAutoSnapshot = prof.id == "retail" and mode ~= "full"
        if mode == "snapshot" or retailAutoSnapshot or (mode == nil and (not nodes or #nodes > FULL_LIMIT)) then
            nodes, seedMeta = self:Seed(prof, nil, "snapshot")
            p.mode = "snapshot"
        else
            p.mode = "full"
        end
        p.nodes, p.splits, p.seedMeta = nodes, {}, seedMeta
    end
    p.mode = p.mode or "full"
    p.splits = p.splits or {}
    return p
end

-- Open the pass's sweep before its first query is sent (a /who is filed under
-- the sweep active when it goes out). A running census owns its own sweep.
function Plan:EnsureSweep()
    local prof = Pop.Profiles.Current()
    if not prof or Pop.Census:IsActive() then return end
    local store = Pop:Store()
    local p = self:Pass(prof)
    if p.finished then return end
    if not (p.sweepID and store.activeSweepID == p.sweepID) then
        p.sweepID = Pop:StartSweep("census plan " .. prof.id, true)
    end
end

-- Plan ----------------------------------------------------------------------

-- Latest result per filter since the pass began (samples are oldest-first).
local function results(since)
    local out = {}
    for _, s in ipairs(Pop:Store().samples or {}) do
        if (s.t or 0) >= since then out[norm(s.filter)] = s end
    end
    return out
end

-- Plan a pass from this realm bucket's own data. Full count: with an exact
-- earlier pass, its characters are a snapshot of who was online, so level
-- ranges are cut to hold about `target` each (1-79 on a realm with 15 players
-- there is one query, not three) and any cell that held more is split up
-- front by whichever axis (class or race) costs fewer queries here.
-- Snapshot: the profile's coarse bands, with the level cap split by class when
-- the last week's sightings say it is crowded.
function Plan:Seed(prof, lastStart, mode)
    local C = Pop.Census
    local store = Pop:Store()
    local target = math.floor(prof.capAt * 0.75) -- headroom for a busier hour
    if not C:IsActive() then C.profile = prof end
    C.hist = nil
    local hist = C:History()
    local last = mode == "full" and lastStart and time() - lastStart < LAST_PASS_DAYS * 86400
    if last then
        local recent = {}
        for _, c in pairs(store.characters or {}) do
            if (c.lastSeen or 0) >= lastStart and (tonumber(c.level) or 0) > 0 then recent[#recent + 1] = c end
        end
        hist.recent = recent
    end

    local bands = {}
    if last then
        local counts = {}
        for _, c in ipairs(hist.recent) do
            local L = tonumber(c.level)
            counts[L] = (counts[L] or 0) + 1
        end
        local lo, acc = 1, 0
        for L = 1, prof.maxLevel do
            local n = counts[L] or 0
            if L > lo and acc + n > target then
                bands[#bands + 1] = { lo = lo, hi = L - 1 }
                lo, acc = L, 0
            end
            acc = acc + n
        end
        bands[#bands + 1] = { lo = lo, hi = prof.maxLevel }
    else
        for _, b in ipairs(prof.bands) do
            if b[1] <= prof.maxLevel then bands[#bands + 1] = { lo = b[1], hi = math.min(b[2], prof.maxLevel) } end
        end
    end

    local out = {}
    if mode == "snapshot" then
        if prof.id == "retail" and prof.maxLevel >= 80 then
            -- Broad leveling indicators, then individual near-cap levels.
            for _, range in ipairs({ {1, 39}, {40, 59}, {60, 79} }) do
                if range[1] < prof.maxLevel then
                    out[#out + 1] = { lo = range[1], hi = math.min(range[2], prof.maxLevel - 1) }
                end
            end
            for level = 80, prof.maxLevel - 1 do out[#out + 1] = { lo = level, hi = level } end

            -- Two cap-level classes per day (the thirteenth class gets Sunday).
            -- RaceList learns viable combinations from this realm's identities;
            -- before it has enough history it safely starts with every race.
            local weekday = tonumber(date("%w")) or 0 -- Sunday=0
            local slot = ((weekday + 6) % 7) + 1       -- Monday=1
            local picked = {}
            for _, classIndex in ipairs(RETAIL_ROTATION[slot]) do
                local token = prof.classes[classIndex]
                if token then
                    picked[#picked + 1] = token
                    for _, race in ipairs(C:RaceList(token)) do
                        out[#out + 1] = { lo = prof.maxLevel, hi = prof.maxLevel, class = token, race = race }
                    end
                end
            end
            C.hist = nil
            return out, { retailRotation = true, classes = picked, slot = slot }
        end
        for _, b in ipairs(bands) do
            if b.lo == b.hi and b.lo == prof.maxLevel and C:Weight(b) > target then
                for _, token in ipairs(prof.classes) do out[#out + 1] = { lo = b.lo, hi = b.hi, class = token } end
            else
                out[#out + 1] = b
            end
        end
        C.hist = nil
        return out
    end

    -- Fixed-list context: the counts above taken at face value.
    local st = { fixed = true, preSplit = 0, ratioObs = 1, ratioHist = 1 }
    local function expand(n, depth)
        local big = C:Learned(n) or (last and C:Weight(n) > target) or (not last and C:ShouldPreSplit(n, st))
        if depth < MAX_DEPTH and exhaustive(n) and big then
            local kids = C:Children(n, st)
            if #kids > 0 then
                for _, k in ipairs(kids) do expand(k, depth + 1) end
                return
            end
        end
        out[#out + 1] = { lo = n.lo, hi = n.hi, class = n.class, race = n.race, letter = n.letter, zone = n.zone }
    end
    for _, b in ipairs(bands) do expand(b, 0) end
    C.hist = nil -- the census keeps its own 7-day view
    return out
end

-- Children of a capped line, chosen once per pass: { kids = {...}, full = bool }.
function Plan:CapSplit(n, depth, pass, prof)
    local C = Pop.Census
    if pass.mode == "snapshot" then
        -- The level cap with no class yet (history did not pre-split it): one
        -- query per class. Any other capped line stays as its sample.
        if n.lo == n.hi and n.lo == prof.maxLevel and not n.class and not n.race then
            local kids = {}
            for _, token in ipairs(prof.classes) do kids[#kids + 1] = { lo = n.lo, hi = n.hi, class = token } end
            return { kids = kids, full = true }
        end
        return { kids = {}, full = false }
    end
    local st = { fixed = true, preSplit = 0, ratioObs = 1, ratioHist = 1 }
    local kids, full, kind = {}, false, nil
    if not n.refreshFilter and depth < MAX_DEPTH then kids, full, kind = C:Children(n, st) end
    -- Fixed-plan profiles (Forever) keep a fully pinned cell as its sample:
    -- name/zone digging there once took 102 queries.
    if not full and prof.fixedPlan then kids = {} end
    -- Remember the split so the next pass (and census) plans it up front.
    if full and #kids > 0 and not C:Learned(n) then C:Learn(n, kind, kids) end
    return { kids = kids, full = full }
end

-- Rows: { filter, depth, state, n, overlap }. States: todo | done (under the
-- cap: exact) | split (capped, children below) | capped (kept as its sample).
function Plan:Build()
    local prof, why = Pop.Profiles.Current()
    if not prof then return nil, why end
    local C = Pop.Census
    if not C:IsActive() then C.profile = prof end
    local pass = self:Pass(prof)
    local seen = results(pass.startedAt)
    local rows = {}

    local function add(n, depth, overlap)
        local filter = C:Filter(n)
        local key = norm(filter)
        local s = seen[key]
        local row = { filter = filter, depth = depth, overlap = overlap }
        rows[#rows + 1] = row
        if not s then row.state = "todo"; return end
        row.n = s.observed or 0
        if not Pop:IsCapped(row.n) then row.state = "done"; return end
        local sp = pass.splits[key]
        if not sp then
            sp = self:CapSplit(n, depth, pass, prof)
            pass.splits[key] = sp
        end
        -- (kind "anchor": a pass saved by the build that sized capped lines.)
        if #sp.kids == 0 or sp.kind == "anchor" then row.state = "capped"; return end
        row.state = "split"
        for _, k in ipairs(sp.kids) do add(k, depth + 1, overlap or not sp.full) end
    end

    for _, n in ipairs(pass.nodes) do add(n, 0, false) end
    return rows, prof, pass
end

-- The realm bucket's lean, the same way the site computes it: every character
-- a census sweep found with a level/class/race query in the last
-- CENSUS_WINDOW_DAYS (ending at the newest such sighting), once each. Zone
-- queries are left out: they favor whoever idles in cities. Returns class and
-- race shares (0-1) and the character count behind them.
function Plan:Lean()
    local store = Pop:Store()
    local latest, chars = 0, {}
    for _, sw in pairs(store.sweeps or {}) do
        if (sw.label or ""):match("^census ") then
            for key, o in pairs(sw.observations or {}) do
                local qi, t = o[1] or o.queryIndex, o[2] or o.observedAt or 0
                local q = sw.queries and sw.queries[qi]
                if q and not tostring(q.filter or ""):find("z%-") then
                    if t > latest then latest = t end
                    local prev = chars[key]
                    if not prev or t > prev.t then
                        chars[key] = { t = t, class = o[5] or o.classFile, race = o[6] or o.race }
                    end
                end
            end
        end
    end
    local cutoff = latest - CENSUS_WINDOW_DAYS * 86400
    local classes, races, n = {}, {}, 0
    for _, c in pairs(chars) do
        if c.t >= cutoff then
            n = n + 1
            if c.class and c.class ~= "" then classes[c.class] = (classes[c.class] or 0) + 1 end
            if c.race and c.race ~= "" then races[c.race] = (races[c.race] or 0) + 1 end
        end
    end
    if n > 0 then
        for k, v in pairs(classes) do classes[k] = v / n end
        for k, v in pairs(races) do races[k] = v / n end
    end
    return classes, races, n
end

-- "Undead 32%  ·  Orc 28%  ·  Troll 22%" (top `limit`, largest first).
local function shareText(map, name, limit)
    local list = {}
    for k, v in pairs(map) do list[#list + 1] = { k = k, v = v } end
    table.sort(list, function(a, b) return a.v > b.v end)
    local parts = {}
    for i = 1, math.min(limit or #list, #list) do
        parts[#parts + 1] = string.format("%s %d%%", name and name(list[i].k) or list[i].k, math.floor(list[i].v * 100 + 0.5))
    end
    return table.concat(parts, "  \194\183  ")
end

local function nextTodo(rows)
    for i, r in ipairs(rows or {}) do
        if r.state == "todo" then return r, i end
    end
end

-- Window ----------------------------------------------------------------------

local function button(parent, label, width)
    local b = CreateFrame("Button", nil, parent, "UIPanelButtonTemplate")
    b:SetSize(width, 22)
    b:SetText(label)
    return b
end

function Plan:SetMode(mode)
    local store = Pop:Store()
    store.planMode = mode
    self:EndPass()
    ML:Print("Plan mode: %s. A new pass has started.", mode == "snapshot" and "snapshot"
        or mode == "full" and "full count" or "auto (snapshot until an exact pass shows a full count fits)")
    if self.f and self.f:IsShown() then self:Refresh() else self:Toggle() end
end

function Plan:Frame()
    if self.f then return self.f end
    local f = CreateFrame("Frame", "MarketLensPlan", UIParent, "BackdropTemplate")
    f:SetSize(W, H)
    f:SetPoint("CENTER", 240, 40)
    f:SetFrameStrata("HIGH")
    f:SetMovable(true); f:EnableMouse(true); f:SetClampedToScreen(true)
    f:RegisterForDrag("LeftButton")
    f:SetScript("OnDragStart", f.StartMoving)
    f:SetScript("OnDragStop", f.StopMovingOrSizing)
    if f.SetBackdrop then
        f:SetBackdrop({
            bgFile = "Interface\\DialogFrame\\UI-DialogBox-Background",
            edgeFile = "Interface\\DialogFrame\\UI-DialogBox-Border",
            tile = true, tileSize = 32, edgeSize = 32,
            insets = { left = 11, right = 12, top = 12, bottom = 11 },
        })
    end
    f:Hide()
    tinsert(UISpecialFrames, "MarketLensPlan")
    self.f = f

    local close = CreateFrame("Button", nil, f, "UIPanelCloseButton")
    close:SetPoint("TOPRIGHT", -4, -4)

    local title = f:CreateFontString(nil, "OVERLAY", "GameFontNormal")
    title:SetPoint("TOPLEFT", 18, -16)
    title:SetText("|cff33aaffMarketLens|r /who plan")

    -- Switch this realm between snapshot and full count (starts a new pass).
    local modeBtn = CreateFrame("Button", nil, f, "UIPanelButtonTemplate")
    modeBtn:SetSize(96, 20)
    modeBtn:SetPoint("TOPRIGHT", -34, -12)
    modeBtn:SetScript("OnClick", function()
        local p = Pop:Store().planPass
        Plan:SetMode(p and p.mode == "snapshot" and "full" or "snapshot")
    end)
    modeBtn:SetScript("OnEnter", function(b)
        GameTooltip:SetOwner(b, "ANCHOR_RIGHT")
        GameTooltip:AddLine("Switch mode (starts a new pass)", 1, 1, 1)
        GameTooltip:AddLine("Full count: split until every line is under the /who cap.", 0.8, 0.8, 0.8, true)
        GameTooltip:AddLine("Snapshot: level bands plus one query per class at the cap; capped lines keep their sample.",
            0.8, 0.8, 0.8, true)
        GameTooltip:Show()
    end)
    modeBtn:SetScript("OnLeave", function() GameTooltip:Hide() end)
    f.modeBtn = modeBtn

    local sub = f:CreateFontString(nil, "OVERLAY", "GameFontHighlightSmall")
    sub:SetPoint("TOPLEFT", title, "BOTTOMLEFT", 0, -8)
    sub:SetPoint("RIGHT", f, "RIGHT", -18, 0)
    sub:SetJustifyH("LEFT")
    sub:SetWordWrap(true)
    f.sub = sub

    local scroll = CreateFrame("ScrollFrame", "MarketLensPlanScroll", f, "FauxScrollFrameTemplate")
    scroll:SetPoint("TOPLEFT", 16, -100)
    scroll:SetPoint("BOTTOMRIGHT", -36, 70)
    scroll:SetScript("OnVerticalScroll", function(s, offset)
        FauxScrollFrame_OnVerticalScroll(s, offset, ROW_H, function() Plan:Paint() end)
    end)
    f.scroll = scroll

    f.rows = {}
    for i = 1, VISIBLE do
        local row = CreateFrame("Button", nil, f)
        row:SetHeight(ROW_H)
        row:SetPoint("TOPLEFT", scroll, "TOPLEFT", 0, -(i - 1) * ROW_H)
        row:SetPoint("RIGHT", scroll, "RIGHT", 0, 0)
        local hl = row:CreateTexture(nil, "HIGHLIGHT")
        hl:SetAllPoints()
        hl:SetColorTexture(1, 0.82, 0, 0.12)
        hl:SetBlendMode("ADD")
        local status = row:CreateFontString(nil, "OVERLAY", "GameFontHighlightSmall")
        status:SetPoint("LEFT", 4, 0)
        status:SetWidth(62)
        status:SetJustifyH("RIGHT")
        row.status = status
        local cmd = row:CreateFontString(nil, "OVERLAY", "GameFontHighlightSmall")
        cmd:SetPoint("LEFT", status, "RIGHT", 10, 0)
        cmd:SetPoint("RIGHT", row, "RIGHT", -4, 0)
        cmd:SetJustifyH("LEFT")
        cmd:SetWordWrap(false)
        row.cmd = cmd
        row:SetScript("OnClick", function(r) if r.entry then Plan:Put(r.entry.filter) end end)
        row:SetScript("OnEnter", function(r)
            local e = r.entry
            if not e then return end
            GameTooltip:SetOwner(r, "ANCHOR_RIGHT")
            GameTooltip:AddLine("/ml who " .. e.filter, 1, 1, 1)
            GameTooltip:AddLine("Click to put it in chat, then press Enter.", 0.7, 0.7, 0.7)
            if e.state == "capped" then
                GameTooltip:AddLine("Hit the /who cap: its 50 go into the census as a sample. Other passes "
                    .. "catch a different 50 as people log on and off.", 1, 0.5, 0.25, true)
            elseif e.overlap then
                GameTooltip:AddLine("Name/zone split: overlaps its siblings.", 1, 0.5, 0.25, true)
            end
            GameTooltip:Show()
        end)
        row:SetScript("OnLeave", function() GameTooltip:Hide() end)
        f.rows[i] = row
    end

    local nextBtn = button(f, "Next", 80)
    nextBtn:SetPoint("BOTTOMRIGHT", -16, 16)
    nextBtn:SetScript("OnClick", function()
        local r = nextTodo(Plan.list)
        if r then Plan:Put(r.filter) else ML:Print("Nothing left in this pass -- New pass starts another.") end
    end)

    local copyBtn = button(f, "Copy list", 80)
    copyBtn:SetPoint("RIGHT", nextBtn, "LEFT", -6, 0)
    copyBtn:SetScript("OnClick", function() Plan:ShowCopy() end)

    local newBtn = button(f, "New pass", 80)
    newBtn:SetPoint("RIGHT", copyBtn, "LEFT", -6, 0)
    newBtn:SetScript("OnClick", function()
        Plan:EndPass()
        Plan:Refresh()
    end)

    local auto = CreateFrame("CheckButton", nil, f, "UICheckButtonTemplate")
    auto:SetSize(24, 24)
    auto:SetPoint("BOTTOMLEFT", 14, 14)
    auto:SetScript("OnClick", function(b) ML.db.settings.planAutoNext = b:GetChecked() and true or false end)
    local autoText = f:CreateFontString(nil, "OVERLAY", "GameFontHighlightSmall")
    autoText:SetPoint("LEFT", auto, "RIGHT", 2, 0)
    autoText:SetText("Auto-type next")
    f.auto = auto

    local hint = f:CreateFontString(nil, "OVERLAY", "GameFontDisableSmall")
    hint:SetPoint("BOTTOMLEFT", 18, 44)
    hint:SetPoint("RIGHT", f, "RIGHT", -18, 0)
    hint:SetJustifyH("LEFT")
    f.hint = hint

    return f
end

function Plan:Put(filter)
    if not filter then return end
    self:EnsureSweep()
    openChat("/ml who " .. filter)
end

-- Close the pass's sweep once every line has an answer and print the lean.
function Plan:Finish(pass, rows)
    local store = Pop:Store()
    local exactAll = true
    for _, r in ipairs(rows) do
        if r.state == "capped" then exactAll = false break end
    end
    if pass.sweepID and store.activeSweepID == pass.sweepID then
        Pop:FinishSweep(exactAll and "complete" or "partial")
    end
    pass.finished, pass.exact = true, exactAll
    local classes, races, n = self:Lean()
    ML:Print("Plan pass done%s. Lean over the last %d days (%d characters):",
        exactAll and ", every line counted exactly" or "", CENSUS_WINDOW_DAYS, n)
    ML:Print("  Races: %s", shareText(races))
    ML:Print("  Classes: %s", shareText(classes, Pop.Profiles.ClassName))
end

function Plan:Refresh()
    local f = self.f
    if not (f and f:IsShown()) then return end
    f.auto:SetChecked(ML.db.settings.planAutoNext ~= false)
    local rows, prof, pass = self:Build()
    if not rows then
        self.list = {}
        f.sub:SetText("|cffff8040" .. tostring(prof) .. "|r")
        self:Paint()
        return
    end
    self.list = rows
    local snapshot = pass.mode == "snapshot"
    f.modeBtn:SetText(snapshot and "Snapshot" or "Full count")
    local retailRotation = snapshot and pass.seedMeta and pass.seedMeta.retailRotation
    local rotationNames = {}
    if retailRotation then
        for _, token in ipairs(pass.seedMeta.classes or {}) do
            rotationNames[#rotationNames + 1] = Pop.Profiles.ClassName(token)
        end
    end
    f.hint:SetText(retailRotation
        and ("Retail rotation today: " .. table.concat(rotationNames, " + ")
            .. " at level " .. prof.maxLevel .. " by race; identities are deduplicated across passes.")
        or snapshot
        and "Snapshot: every bracket and class sampled each pass; a capped line keeps its 50."
        or "Run one faction in one sitting. A line that hits the /who cap splits below it.")

    local todo, done, capped = 0, 0, 0
    for _, r in ipairs(rows) do
        if r.state == "todo" then todo = todo + 1 else done = done + 1 end
        if r.state == "capped" then capped = capped + 1 end
    end
    if todo == 0 and #rows > 0 and not pass.finished and pass.sweepID then
        self:Finish(pass, rows)
    end

    local classes, races, n = self:Lean()
    local mins = math.floor((time() - pass.startedAt) / 60)
    local lines = {
        string.format("|cffffffff%s|r %s  |cff808080\194\183|r  %s  |cff808080\194\183|r  %s",
            prof.label, prof.faction, ML:RealmKey(), snapshot and "|cffffd100snapshot|r" or "full count"),
        string.format("%d of %d run  |cff808080\194\183|r  %d capped  |cff808080\194\183|r  %d min  |cff808080\194\183|r  lean: last %d days, %d characters",
            done, #rows, capped, mins, CENSUS_WINDOW_DAYS, n),
        n > 0 and ("|cff808080Races|r  " .. shareText(races, nil, 4)) or "|cff808080Races|r  --",
        n > 0 and ("|cff808080Classes|r  " .. shareText(classes, Pop.Profiles.ClassName, 4)) or "|cff808080Classes|r  --",
    }
    if Pop.Census:IsActive() then
        lines[#lines + 1] = "|cffff8040A census is running: a typed line that matches its next step runs as that step.|r"
    end
    f.sub:SetText(table.concat(lines, "\n"))

    -- Keep the next line in view.
    local _, idx = nextTodo(rows)
    local offset = FauxScrollFrame_GetOffset(f.scroll)
    if idx and (idx <= offset or idx > offset + VISIBLE) then
        local sb = f.scroll.ScrollBar or _G[f.scroll:GetName() .. "ScrollBar"]
        FauxScrollFrame_Update(f.scroll, #rows, VISIBLE, ROW_H)
        if sb then sb:SetValue(math.max(idx - 3, 0) * ROW_H) end
    end
    self:Paint()
end

local STATUS = {
    done   = function(r) return "|cff40c040" .. r.n .. "|r" end,
    split  = function(r) return "|cffff8040" .. r.n .. " split|r" end,
    capped = function(r) return "|cffff8040" .. r.n .. "+|r" end,
}

function Plan:Paint()
    local f = self.f
    local rows = self.list or {}
    FauxScrollFrame_Update(f.scroll, #rows, VISIBLE, ROW_H)
    local offset = FauxScrollFrame_GetOffset(f.scroll)
    local _, nextIdx = nextTodo(rows)
    for i = 1, VISIBLE do
        local row, r = f.rows[i], rows[i + offset]
        row.entry = r
        if r then
            local status
            if r.state == "todo" then
                status = (i + offset == nextIdx) and "|cffffd100next|r" or "|cff808080--|r"
            else
                status = STATUS[r.state](r)
            end
            row.status:SetText(status)
            local color = r.state == "todo" and "|cffffffff" or "|cff9d9d9d"
            row.cmd:SetText(string.rep("   ", r.depth) .. color .. "/ml who " .. r.filter .. "|r"
                .. (r.overlap and " |cff808080(overlaps)|r" or ""))
            row:Show()
        else
            row:Hide()
        end
    end
end

-- Every unanswered line, one per line, selected for Ctrl+C.
function Plan:ShowCopy()
    if not self.copy then
        local c = CreateFrame("Frame", "MarketLensPlanCopy", UIParent, "BackdropTemplate")
        c:SetSize(360, 320)
        c:SetPoint("CENTER")
        c:SetFrameStrata("DIALOG")
        c:SetMovable(true); c:EnableMouse(true); c:RegisterForDrag("LeftButton")
        c:SetScript("OnDragStart", c.StartMoving); c:SetScript("OnDragStop", c.StopMovingOrSizing)
        if c.SetBackdrop then
            c:SetBackdrop({ bgFile = "Interface\\DialogFrame\\UI-DialogBox-Background",
                edgeFile = "Interface\\DialogFrame\\UI-DialogBox-Border", tile = true,
                tileSize = 32, edgeSize = 32, insets = { left = 11, right = 12, top = 12, bottom = 11 } })
        end
        tinsert(UISpecialFrames, "MarketLensPlanCopy")
        local title = c:CreateFontString(nil, "OVERLAY", "GameFontNormal")
        title:SetPoint("TOP", 0, -14)
        title:SetText("Ctrl+C to copy")
        local close = CreateFrame("Button", nil, c, "UIPanelCloseButton")
        close:SetPoint("TOPRIGHT", -4, -4)
        local scroll = CreateFrame("ScrollFrame", "MarketLensPlanCopyScroll", c, "UIPanelScrollFrameTemplate")
        scroll:SetPoint("TOPLEFT", 16, -36)
        scroll:SetPoint("BOTTOMRIGHT", -34, 16)
        local edit = CreateFrame("EditBox", nil, scroll)
        edit:SetMultiLine(true); edit:SetAutoFocus(false); edit:SetFontObject(ChatFontNormal)
        edit:SetWidth(300)
        edit:SetScript("OnEscapePressed", function(e) e:ClearFocus(); c:Hide() end)
        scroll:SetScrollChild(edit)
        c.edit = edit
        self.copy = c
    end
    local out = {}
    for _, r in ipairs(self.list or {}) do
        if r.state == "todo" then out[#out + 1] = "/ml who " .. r.filter end
    end
    self.copy.edit:SetText(#out > 0 and table.concat(out, "\n") or "-- nothing left in this pass --")
    self.copy.edit:HighlightText()
    self.copy.edit:SetFocus()
    self.copy:Show()
end

function Plan:Toggle()
    local f = self:Frame()
    if f:IsShown() then
        f:Hide()
    else
        f:Show()
        self:Refresh()
    end
end

-- A result came back: repaint, then (optionally) type the next line.
ML:On("POP_SCAN_COMPLETE", function()
    if not (Plan.f and Plan.f:IsShown()) then return end
    Plan:Refresh()
    if ML.db.settings.planAutoNext == false or Pop.Census:IsActive() then return end
    local r = nextTodo(Plan.list)
    if not r then return end
    local filter = r.filter
    C_Timer.After(Pop:CooldownRemaining(), function()
        -- A line already sent (e.g. via Next) is still "todo" until its answer
        -- arrives; typing it again ran 80-84 twice. Its own answer re-arms this.
        if Pop.pending then return end
        local again = nextTodo(Plan.list)
        if Plan.f:IsShown() and again and again.filter == filter then Plan:Put(filter) end
    end)
end)
ML:On("POP_SCAN_STALE", function() Plan:Refresh() end)
ML:On("CENSUS_CHANGED", function() Plan:Refresh() end)
