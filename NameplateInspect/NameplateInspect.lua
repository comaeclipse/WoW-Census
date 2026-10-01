-- NameplateInspect: silently caches spec + talent loadout strings for nearby
-- players by inspecting them through their nameplate unit tokens.
--
-- Inspection works differently on each WoW client, so each one has its own
-- reader. They are deliberately kept separate; never let one fall through
-- into another's API (see store()).
--
--   Classic Era / SoD / TBC Anniversary   (classicTalents, readClassicTalents)
--     Ranked talent trees via the old talent-tree APIs. There is no spec ID;
--     the "spec" is inferred from the point split (primaryTree).
--
--   Mists of Pandaria Classic              (mop, readMoPInspectTalents)
--     Real spec IDs via GetInspectSpecialization(), plus the six-tier /
--     three-column talent grid. NOT C_Traits.
--
--   Retail / WoW: Forever (classic-beta)   (readTalents)
--     Real spec IDs via GetInspectSpecialization(), plus C_Traits node trees
--     and a loadout import string.

local THROTTLE  = 5     -- conservative minimum seconds between our inspect requests
local TIMEOUT   = 10    -- allow slow inspect responses before retrying
local MAX_BACKOFF = 60  -- exponential cooldown after empty responses/timeouts
local TTL       = 600   -- re-inspect someone we cached more than 10 minutes ago
local MAX_TRIES = 2     -- attempts per player before we stop retrying this session

local unitByGUID = {}   -- guid -> nameplate token currently showing them
local guidByUnit = {}   -- nameplate token -> guid (for cleanup on removal)
local queue, queued, tries = {}, {}, {}
local pending           -- { guid, unit, t } for the request we have in flight
local lastRequest = 0
local nextRequest = 0
local failures = 0
local selfCalling = false
local collecting = true
local inspectedThisSession = {}
local clientVersion = GetBuildInfo()
local clientMajor = tonumber(clientVersion:match("^(%d+)")) or 0
local clientMinor = tonumber(clientVersion:match("^%d+%.(%d+)")) or 0
local era = clientMajor == 1 and clientMinor < 60
local mop = clientMajor == 5
local classicTalents = era or clientMajor == 2
-- SoD shares the Era client and save file; tag it so uploads keep the games apart.
local function seasonOfDiscovery()
  if not (C_Seasons and C_Seasons.GetActiveSeason) then return false end
  local ok, season = pcall(C_Seasons.GetActiveSeason)
  local sod = Enum and Enum.SeasonID and Enum.SeasonID.SeasonOfDiscovery or 2
  return ok and season == sod
end
local flavor = era and (seasonOfDiscovery() and "sod" or "classic-era") or clientMajor == 2 and "tbc-anniversary"
    or mop and "mop-classic" or clientMajor >= 10 and clientMajor < 20 and "retail" or "classic-beta"
local stats = { plates = 0, requests = 0, ready = 0, saved = 0, timeouts = 0 }
local lastResult = "No inspect response yet"

local function trace(message)
  if NPI_DEBUG then print("NPI: " .. message) end
end

local function probe(label, fn, ...)
  if type(fn) ~= "function" then print("NPI: " .. label .. " = unavailable"); return end
  local function pack(...) return { n = select("#", ...), ... } end
  local result = pack(pcall(fn, ...))
  local values = {}
  for i = 2, result.n do
    local v = result[i]
    values[#values + 1] = (issecretvalue and issecretvalue(v)) and "<restricted>" or tostring(v)
  end
  print("NPI: " .. label .. " " .. (result[1] and "= " or "ERROR: ") .. table.concat(values, ", "))
end

local function probeTalents()
  if not classicTalents then return end
  local api = C_SpecializationInfo
  probe("GetNumTalentTabs(inspect)", GetNumTalentTabs, true, false)
  probe("GetNumTalents(tree 1, inspect)", GetNumTalents, 1, true, false)
  local group = 1
  local fn = (api and api.GetActiveSpecGroup) or GetActiveTalentGroup
  if fn then
    local ok, value = pcall(fn, true)
    if ok and type(value) == "number" then group = value end
    probe("active inspect group", fn, true)
  end
  probe("legacy tree 1", GetTalentTabInfo, 1, true, false, group)
  probe("namespaced tree 1", api and api.GetSpecializationInfo, 1, true, false, nil, nil, group)
  probe("legacy talent 1", GetTalentInfo, 1, 1, true, false, group)
  if api and api.GetTalentInfo then
    local ok, talent = pcall(api.GetTalentInfo, { specializationIndex = 1, talentIndex = 1,
      isInspect = true, isPet = false, groupIndex = group })
    if ok and type(talent) == "table" then
      for _, key in ipairs({ "name", "rank", "maxRank", "tier", "column" }) do
        probe("namespaced talent 1." .. key, function() return talent[key] end)
      end
    else
      print("NPI: namespaced talent 1 = " .. tostring(talent))
    end
  end
end

local function backoff(now)
  failures = math.min(failures + 1, 5)
  nextRequest = now + math.min(MAX_BACKOFF, THROTTLE * 2 ^ failures)
end

local function isSecret(v)
  return issecretvalue and issecretvalue(v)
end

local function hasSecret(value)
  if isSecret(value) then return true end
  if type(value) == "table" then
    for key, child in pairs(value) do
      if hasSecret(key) or hasSecret(child) then return true end
    end
  end
  return false
end

local function safeGUID(unit)
  local g = UnitGUID(unit)
  if not isSecret(g) and g then return g end
end

local function fresh(guid)
  local e = NameplateInspectDB and NameplateInspectDB[guid]
  return e and e.flavor == flavor and type(e.time) == "number" and (time() - e.time) < TTL
end

local function enqueue(guid)
  if not collecting then return end
  if queued[guid] or fresh(guid) or (pending and pending.guid == guid) then return end
  if (tries[guid] or 0) >= MAX_TRIES then return end
  queued[guid] = true
  queue[#queue + 1] = guid
end

-- Find a unit token that currently points at this GUID.
local function unitFor(guid)
  local u = unitByGUID[guid]
  if u and safeGUID(u) == guid then return u end
  for _, t in ipairs({ "target", "mouseover", "focus" }) do
    if safeGUID(t) == guid then return t end
  end
  local prefix, count = IsInRaid() and "raid" or "party", IsInRaid() and 40 or 4
  for i = 1, count do
    local unit = prefix .. i
    if safeGUID(unit) == guid then return unit end
  end
end

local function discover(unit)
  if InCombatLockdown() or not UnitExists(unit) or not UnitIsPlayer(unit)
      or UnitIsUnit(unit, "player") then return end
  local guid = safeGUID(unit)
  if guid then enqueue(guid) end
end

local function discoverGroup()
  if InCombatLockdown() then return end
  local prefix, count = IsInRaid() and "raid" or "party", IsInRaid() and 40 or 4
  for i = 1, count do discover(prefix .. i) end
end

local function inspectWindowOpen()
  return (InspectFrame and InspectFrame:IsShown())
      or (PlayerSpellsFrame and PlayerSpellsFrame:IsShown())
end

-- Spec-based clients only (MoP Classic, Retail, Forever). Classic Era/TBC have
-- no spec IDs; their spec is inferred from the talent point split instead.
-- Only valid after INSPECT_READY: straight after NotifyInspect() this returns
-- 0 (verified on MoP Classic), then the real ID (e.g. 73, 262) once ready.
local function getInspectSpec(unit)
  if classicTalents then return nil end
  if C_SpecializationInfo and C_SpecializationInfo.GetInspectSpecialization then
    return C_SpecializationInfo.GetInspectSpecialization(unit)
  elseif GetInspectSpecialization then
    return GetInspectSpecialization(unit)
  end
end

-- Classic has ranked trees, not Retail specialization IDs or import strings.
-- Support both the current namespaced APIs and older Classic globals.
local function readClassicTalents()
  if not GetNumTalentTabs or not GetNumTalents then error("Classic talent count API unavailable") end
  local api = C_SpecializationInfo
  local group = (api and api.GetActiveSpecGroup and api.GetActiveSpecGroup(true))
      or (GetActiveTalentGroup and GetActiveTalentGroup(true)) or 1
  local list, trees, split = {}, {}, {}
  local best, bestPoints, tied = nil, 0, false
  local count = GetNumTalentTabs(true, false)
  if not count or count == 0 then return nil end
  for tab = 1, count do
    local name, icon, points
    if api and api.GetSpecializationInfo then
      local info = { api.GetSpecializationInfo(tab, true, false, nil, nil, group) }
      name, icon, points = info[2], info[4], info[7]
    elseif GetTalentTabInfo then
      name, icon, points = GetTalentTabInfo(tab, true, false, group)
    end
    if not name or type(points) ~= "number" then error("Tree " .. tab .. " has no name or numeric pointsSpent") end
    trees[tab] = { index = tab, name = name, icon = icon, points = points }
    split[tab] = tostring(points)
    if points > bestPoints then
      best, bestPoints, tied = name, points, false
    elseif points == bestPoints then
      tied = true
    end
    local numTalents = GetNumTalents(tab, true, false)
    if not numTalents or numTalents == 0 then return nil end
    for index = 1, numTalents do
      local talent
      if api and api.GetTalentInfo then
        talent = api.GetTalentInfo({ specializationIndex = tab, talentIndex = index,
          isInspect = true, isPet = false, groupIndex = group })
      elseif GetTalentInfo then
        local n, texture, tier, column, rank, maxRank = GetTalentInfo(tab, index, true, false, group)
        if n then talent = { name = n, icon = texture, tier = tier, column = column,
          rank = rank, maxRank = maxRank } end
      end
      if not talent then error("Talent " .. tab .. ":" .. index .. " returned nil") end
      if (talent.rank or 0) > 0 then
        list[#list + 1] = { tree = tab, index = index, name = talent.name,
          spell = talent.spellID, talent = talent.talentID, icon = talent.icon,
          rank = talent.rank, max = talent.maxRank, x = talent.column, y = talent.tier }
      end
    end
  end
  -- Empty responses cannot be distinguished from an unspent build; retry them.
  if #list == 0 then return nil end
  return list, trees, table.concat(split, "/"), not tied and best or nil
end

-- Must run right at INSPECT_READY: the inspect config reads back empty
-- once the inspect data is cleared, even if a talent window still shows it.
local function readTalents()
  if not (C_Traits and Constants and Constants.TraitConsts) then return nil end
  local k = Constants.TraitConsts.INSPECT_TRAIT_CONFIG_ID
  local cfg = C_Traits.GetConfigInfo(k)
  if not cfg then return nil end
  local list = {}
  for _, treeID in ipairs(cfg.treeIDs or {}) do
    for _, nodeID in ipairs(C_Traits.GetTreeNodes(treeID) or {}) do
      local n = C_Traits.GetNodeInfo(k, nodeID)
      if n and (n.currentRank or 0) > 0 and n.activeEntry then
        local e = C_Traits.GetEntryInfo(k, n.activeEntry.entryID)
        local d = e and e.definitionID and C_Traits.GetDefinitionInfo(e.definitionID)
        local spellID = d and (d.spellID or d.overriddenSpellID)
        local groups = {}
        for _, groupID in ipairs(n.groupIDs or {}) do
          groups[#groups + 1] = groupID
        end
        list[#list + 1] = {
          node = nodeID, tree = treeID, spell = spellID,
          name = (spellID and C_Spell.GetSpellName(spellID)) or ("node " .. nodeID),
          rank = n.currentRank, max = n.maxRanks, x = n.posX, y = n.posY,
          groups = groups, entry = n.activeEntry.entryID,
          definition = e and e.definitionID,
        }
      end
    end
  end
  return list
end

------------------------------------------------------------------------
-- MISTS OF PANDARIA CLASSIC INSPECTION
-- Verified on MoP Classic. Not used by Retail, TBC, Classic Era, or Forever.
------------------------------------------------------------------------
-- MoP Classic has real specialization IDs (getInspectSpec above), so the spec
-- is read directly rather than inferred from talents like TBC. Its talents are
-- a six-tier / three-column grid (one choice per tier), not Retail/Forever's
-- C_Traits node trees and not TBC's ranked trees.
--
-- IMPORTANT (MoP Classic):
-- Do NOT pass groupIndex when inspecting another player's talents.
-- The inspected talent API returned nil when groupIndex was supplied
-- (e.g. C_SpecializationInfo.GetActiveSpecGroup(true, false)), even though
-- Blizzard's Inspect UI showed the talents. Omitting it works.
--
-- `selected` is the field that marks the inspected player's chosen talent.
-- Do NOT use `known`: a selected talent was observed as selected = true,
-- known = false while inspecting someone else.
--
-- Verified example, level-68 Windwalker Monk (spec 269):
--   T1 C1 Celerity   talentID 19302  spellID 115173
--   T2 C3 Chi Burst  talentID 19823  spellID 123986
--   T3 C2 Ascension  talentID 19771  spellID 115396
--   T4 C3 Leg Sweep  talentID 19995  spellID 119381
--   T1 C2 Tiger's Lust -> selected = false (correctly skipped)
-- i.e. scanning all 18 slots and keeping only selected == true is correct.
--
-- Stored entries keep the raw IDs (talent = talentID, spell = spellID) so
-- names can be backfilled later, and use the same field shape as the other
-- readers (tree = tier, index = column, rank/max = 1) for the site importer.
local function readMoPInspectTalents(unit)
  local api = C_SpecializationInfo
  if not (api and api.GetTalentInfo) then return nil end
  local list = {}
  for tier = 1, 6 do
    for column = 1, 3 do
      -- No groupIndex here; see the warning above.
      local info = api.GetTalentInfo({ tier = tier, column = column, isInspect = true, target = unit })
      if info and info.selected then
        local y, x = info.tier or tier, info.column or column
        list[#list + 1] = { talent = info.talentID, tree = y, index = x, name = info.name,
          icon = info.icon, spell = info.spellID, rank = 1, max = 1, x = x, y = y }
      end
    end
  end
  -- Low-level players may have no talents yet; store() still keeps the spec.
  return #list > 0 and list or nil
end
------------------------------------------------------------------------
-- END MISTS OF PANDARIA CLASSIC INSPECTION
------------------------------------------------------------------------

local function store(guid, unit)
  local talents, list, trees, pointSplit, primaryTree
  -- One reader per client family. The MoP branch must never fall through to
  -- the C_Traits reader below: MoP Classic talents are not trait nodes.
  if classicTalents then
    list, trees, pointSplit, primaryTree = readClassicTalents()
  elseif mop then
    list = readMoPInspectTalents(unit)
  else
    if C_Traits and C_Traits.GenerateInspectImportString then
      local ok, result = pcall(C_Traits.GenerateInspectImportString, unit)
      if ok and not isSecret(result) then talents = result end
    end
    local ok, result = pcall(readTalents)
    if ok then list = result end
  end
  local spec = getInspectSpec(unit)
  if isSecret(spec) then return false end
  -- On MoP Classic a valid spec ID (> 0) alone is worth storing, e.g. players
  -- too low-level to have picked talents yet.
  if (not talents or talents == "") and (not list or #list == 0)
      and not (mop and type(spec) == "number" and spec > 0) then return false end
  local name, realm = UnitName(unit)
  if isSecret(name) or isSecret(realm) or not name then return false end
  local observerRealm = GetRealmName()
  local forever = observerRealm and observerRealm:match("^Classic Beta")
  -- Forever exposes surname in UnitName's second return, rather than realm.
  -- Keep both raw components; do not split arbitrary hyphens in a surname.
  local rawName = (realm and realm ~= "") and (name .. "-" .. realm) or name
  local surname
  if forever then
    surname = realm
    name = name .. ((surname and surname ~= "") and (" " .. surname) or "")
    local relation = UnitRealmRelationship and UnitRealmRelationship(unit)
    realm = (relation and not isSecret(relation) and LE_REALM_RELATION_SAME
      and relation == LE_REALM_RELATION_SAME) and observerRealm or nil
  elseif UnitFullName then
    local fullName, fullRealm = UnitFullName(unit)
    if isSecret(fullName) or isSecret(fullRealm) then return false end
    name, realm = fullName or name, fullRealm or realm
    realm = (realm and realm ~= "") and realm or observerRealm
  end
  local _, build = GetBuildInfo()
  local _, class = UnitClass(unit)
  local race = UnitRace(unit)
  local specInfo = (C_SpecializationInfo and C_SpecializationInfo.GetSpecializationInfoByID)
      or GetSpecializationInfoByID
  local role = spec and spec > 0 and specInfo and select(5, specInfo(spec)) or nil
  -- flavor + talentFormat tell later processing which system produced the
  -- record: MoP Classic records are flavor "mop-classic" with talentFormat
  -- "mop-tier-talents", distinct from C_Traits ("trait-import-string") and TBC
  -- ("classic-ranked-trees"). Raw IDs (spec, talent, spell) are kept so names
  -- can be backfilled on the site.
  local record = {
    schemaVersion = 3,
    flavor = flavor,
    talentFormat = classicTalents and "classic-ranked-trees" or mop and "mop-tier-talents" or "trait-import-string",
    trees = trees,
    pointSplit = pointSplit,
    primaryTree = primaryTree,
    rawName = rawName,
    name = name,
    surname = surname,
    realm = realm,
    realmSource = forever and (realm and "same-realm-unit" or "unresolved-forever-realm") or "unit-full-name",
    faction = UnitFactionGroup(unit),
    observerRealm = observerRealm,
    observerFaction = UnitFactionGroup("player"),
    observerZone = GetRealZoneText(),
    clientBuild = tostring(build),
    locale = GetLocale(),
    class   = class,
    race    = race,
    level   = UnitLevel(unit),
    guild   = GetGuildInfo(unit),
    role    = role,
    spec    = spec,
    talents = talents,
    list    = list,
    time    = time(),
  }
  -- SavedVariables cannot safely persist Retail's restricted values.
  if hasSecret(record) then return false end
  NameplateInspectDB[guid] = record
  inspectedThisSession[guid] = true
  return true
end

local function tick()
  if not collecting then return end
  local now = GetTime()

  if pending then
    if now - pending.t < TIMEOUT then return end
    local g = pending.guid
    stats.timeouts = stats.timeouts + 1
    lastResult = "Timed out waiting for INSPECT_READY"
    trace(lastResult)
    pending = nil
    backoff(now)
    enqueue(g)
  end

  if now - lastRequest < THROTTLE or now < nextRequest or inspectWindowOpen()
      or InCombatLockdown() then return end

  while #queue > 0 do
    local guid = table.remove(queue, 1)
    queued[guid] = nil
    local unit = unitFor(guid)
    if unit and not fresh(guid) and (tries[guid] or 0) < MAX_TRIES and CanInspect(unit) then
      tries[guid] = (tries[guid] or 0) + 1
      pending = { guid = guid, unit = unit, t = now }
      lastRequest = now
      stats.requests = stats.requests + 1
      trace("NotifyInspect " .. unit)
      selfCalling = true
      local ok, err = pcall(NotifyInspect, unit)
      selfCalling = false
      if not ok then
        lastResult = "NotifyInspect error: " .. tostring(err)
        trace(lastResult)
        pending = nil
        backoff(now)
        enqueue(guid)
      end
      return
    end
  end
end

-- If the default UI or another addon starts an inspect, ours gets overwritten.
-- Requeue our player and back off instead of fighting over the slot.
hooksecurefunc("NotifyInspect", function()
  if selfCalling then return end
  if pending then
    local g = pending.guid
    pending = nil
    enqueue(g)
  end
  lastRequest = GetTime()
  nextRequest = math.max(nextRequest, lastRequest + TIMEOUT)
end)

local f = CreateFrame("Frame")
f:RegisterEvent("ADDON_LOADED")
f:RegisterEvent("NAME_PLATE_UNIT_ADDED")
f:RegisterEvent("NAME_PLATE_UNIT_REMOVED")
f:RegisterEvent("INSPECT_READY")
f:RegisterEvent("PLAYER_TARGET_CHANGED")
f:RegisterEvent("UPDATE_MOUSEOVER_UNIT")
f:RegisterEvent("PLAYER_FOCUS_CHANGED")
f:RegisterEvent("GROUP_ROSTER_UPDATE")
f:RegisterEvent("PLAYER_ENTERING_WORLD")
f:RegisterEvent("PLAYER_REGEN_ENABLED")

f:SetScript("OnEvent", function(_, event, arg)
  if event == "ADDON_LOADED" then
    if arg ~= "NameplateInspect" then return end
    NameplateInspectDB = NameplateInspectDB or {}
    NameplateInspect_Ready = true
    C_Timer.NewTicker(0.5, tick)
    discover("target")
    discover("mouseover")
    discover("focus")
    discoverGroup()
    f:UnregisterEvent("ADDON_LOADED")

  elseif event == "PLAYER_TARGET_CHANGED" then
    discover("target")
  elseif event == "UPDATE_MOUSEOVER_UNIT" then
    discover("mouseover")
  elseif event == "PLAYER_FOCUS_CHANGED" then
    discover("focus")
  elseif event == "GROUP_ROSTER_UPDATE" or event == "PLAYER_ENTERING_WORLD" then
    discoverGroup()
    discover("target")
    discover("mouseover")
    discover("focus")
  elseif event == "PLAYER_REGEN_ENABLED" then
    -- Retail may hide GUIDs during combat. Discover those plates once safe.
    if C_NamePlate and C_NamePlate.GetNamePlates then
      for _, plate in ipairs(C_NamePlate.GetNamePlates()) do
        local unit = plate.namePlateUnitToken
        if unit then f:GetScript("OnEvent")(f, "NAME_PLATE_UNIT_ADDED", unit) end
      end
    end
    discoverGroup()
    discover("target")
    discover("mouseover")
    discover("focus")

  elseif event == "NAME_PLATE_UNIT_ADDED" then
    stats.plates = stats.plates + 1
    local unit = arg
    if InCombatLockdown() then return end
    if not UnitIsPlayer(unit) or UnitIsUnit(unit, "player") then return end
    local guid = safeGUID(unit)
    if not guid then return end
    unitByGUID[guid] = unit
    guidByUnit[unit] = guid
    enqueue(guid)

  elseif event == "NAME_PLATE_UNIT_REMOVED" then
    local guid = guidByUnit[arg]
    guidByUnit[arg] = nil
    if guid and unitByGUID[guid] == arg then unitByGUID[guid] = nil end

  -- Inspect flow (all clients; the timing was verified on MoP Classic):
  --   NotifyInspect(unit)            tick() / "/npi test"
  --     -> wait for INSPECT_READY    here; never read right after NotifyInspect,
  --                                  GetInspectSpecialization() returns 0 then
  --     -> verify the GUID matches   unitFor(guid) must still point at them
  --     -> read specialization       store() -> getInspectSpec()
  --     -> read talents              store() -> per-client reader (MoP: selected tier talents)
  --     -> store result              NameplateInspectDB[guid]
  elseif event == "INSPECT_READY" then
    stats.ready = stats.ready + 1
    if not collecting then return end
    local guid = arg
    if isSecret(guid) then return end
    local mine = pending and pending.guid == guid
    local unit = unitFor(guid)
    trace("INSPECT_READY; matched unit=" .. tostring(unit) .. ", ours=" .. tostring(not not mine))
    if mine and pending.test then probeTalents() end
    -- Also opportunistically cache inspects started by other addons or the UI.
    local ok, saved = false, false
    if unit and not InCombatLockdown() then ok, saved = pcall(store, guid, unit) end
    local readError = not ok and saved
    saved = ok and saved
    if saved then
      stats.saved = stats.saved + 1
      lastResult = "Cached successfully"
    elseif not unit then
      lastResult = "INSPECT_READY had no matching unit"
    elseif InCombatLockdown() then
      lastResult = "Skipped response during combat"
    elseif not ok then
      lastResult = "Talent/cache read ERROR: " .. tostring(readError)
    else
      lastResult = "Talent read returned no cacheable build"
    end
    trace(lastResult)
    if saved and NPI_DEBUG then
      local e = NameplateInspectDB[guid]
      print(("NPI: cached %s (%s %s, %d talents)"):format(e.name, e.race or "?", e.class or "?", #(e.list or {})))
    end
    if mine then
      pending = nil
      if saved then
        tries[guid] = nil
        failures = 0
      else
        backoff(GetTime())
        enqueue(guid)
      end
      if not inspectWindowOpen() then ClearInspectPlayer() end
    end
  end
end)

-- Public lookup for other addons/macros: accepts a GUID or a unit token.
function NameplateInspect_Get(guidOrUnit)
  if isSecret(guidOrUnit) or type(guidOrUnit) ~= "string" then return nil end
  local guid = guidOrUnit
  if guid and not guid:find("^Player%-") then guid = safeGUID(guidOrUnit) end
  return guid and NameplateInspectDB and NameplateInspectDB[guid]
end

-- Copyable popup for talent strings.
StaticPopupDialogs.NAMEPLATEINSPECT_COPY = {
  text = "%s",
  button1 = OKAY,
  hasEditBox = true,
  editBoxWidth = 350,
  OnShow = function(self, data)
    local eb = self.GetEditBox and self:GetEditBox() or self.editBox
    eb:SetText(data or "")
    eb:HighlightText()
    eb:SetFocus()
  end,
  EditBoxOnEscapePressed = function(self) self:GetParent():Hide() end,
  timeout = 0, whileDead = true, hideOnEscape = true,
}

local function specName(id)
  if not id or id == 0 then return "?" end
  local api = (C_SpecializationInfo and C_SpecializationInfo.GetSpecializationInfoByID)
      or GetSpecializationInfoByID
  if not api then return tostring(id) end
  local _, n = api(id)
  return n or tostring(id)
end

local function buildName(e)
  if e.pointSplit then return (e.primaryTree or "Hybrid") .. " " .. e.pointSplit end
  return specName(e.spec)
end

local function show(e)
  print("NPI: build " .. buildName(e))
  print(("NPI: %s — level %s %s %s (%s)%s"):format(e.name, tostring(e.level or "?"), e.race or "?",
    e.class or "?", e.role or "?", e.guild and (" <" .. e.guild .. ">") or ""))
  local total = 0
  for _, t in ipairs(e.list or {}) do
    total = total + t.rank
    print(("   %s %d/%d  (x=%s y=%s)"):format(t.name or "?", t.rank, t.max or t.rank, tostring(t.x), tostring(t.y)))
  end
  print(("   %d points"):format(total))
  if not e.talents or e.talents == "" then return end
  StaticPopup_Show("NAMEPLATEINSPECT_COPY", e.name .. " — " .. specName(e.spec), nil, e.talents)
end

-- Snapshot current nameplates; stats.plates counts events since login instead.
local function showNearby()
  if InCombatLockdown() then
    print("NPI: nearby count unavailable during combat; retry afterward.")
    return
  end
  if not (C_NamePlate and C_NamePlate.GetNamePlates) then
    print("NPI: this client does not expose a current nameplate list.")
    return
  end
  local ok, plates = pcall(C_NamePlate.GetNamePlates)
  if not ok or type(plates) ~= "table" then
    print("NPI: could not read current nameplates.")
    return
  end
  local players, inRange, inspectable = 0, 0, 0
  local counted = {}
  for _, plate in ipairs(plates) do
    local unit = plate.namePlateUnitToken
    if type(unit) == "string" and not isSecret(unit) then
      local valid, isPlayer = pcall(UnitIsPlayer, unit)
      local guid = valid and not isSecret(isPlayer) and isPlayer and safeGUID(unit)
      if guid and not counted[guid] then
        counted[guid] = true
        players = players + 1
        local rangeOK, near = pcall(CheckInteractDistance, unit, 1)
        if rangeOK and not isSecret(near) and near then inRange = inRange + 1 end
        local inspectOK, eligible = pcall(CanInspect, unit, false)
        if inspectOK and not isSecret(eligible) and eligible then inspectable = inspectable + 1 end
      end
    end
  end
  print(("NPI: now: %d nameplate(s), %d player(s), %d in inspect distance, %d CanInspect; %d queued.")
    :format(#plates, players, inRange, inspectable, #queue))
  print("NPI: this counts visible nameplates only; targets and group members can also be inspected.")
end

SLASH_NAMEPLATEINSPECT1 = "/npi"
SlashCmdList.NAMEPLATEINSPECT = function(msg)
  msg = strtrim(msg or "")
  local lower = msg:lower()

  if lower == "" then
    local e = NameplateInspect_Get("target")
    if e then show(e) else print("NPI: target not cached yet. Try /npi list or /npi <name>.") end

  elseif lower == "stop" then
    if pending then
      local guid = pending.guid
      pending = nil
      enqueue(guid)
    end
    collecting = false
    print("NPI: collection stopped. Cached data preserved.")

  elseif lower == "start" then
    collecting = true
    for guid in pairs(unitByGUID) do enqueue(guid) end
    print("NPI: collection started.")

  elseif lower == "status" then
    print(("NPI: %s, collection %s, %d queued."):format(flavor, collecting and "running" or "stopped", #queue))

  elseif lower == "nearby" then
    showNearby()

  elseif lower == "diag" then
    local _, build, _, interface = GetBuildInfo()
    print(("NPI: diagnostics v3.0.3, %s, client=%s build=%s interface=%s"):format(flavor,
      clientVersion, tostring(build), tostring(interface)))
    print(("NPI: plates=%d requests=%d ready=%d saved=%d timeouts=%d queued=%d pending=%s"):format(
      stats.plates, stats.requests, stats.ready, stats.saved, stats.timeouts, #queue,
      pending and pending.unit or "none"))
    print("NPI: last result: " .. lastResult)
    probe("target GUID", UnitGUID, "target")
    probe("target player", UnitIsPlayer, "target")
    probe("target CanInspect", CanInspect, "target", false)
    probe("target inspect distance", CheckInteractDistance, "target", 1)
    probe("combat", InCombatLockdown)
    print("NPI: inspect window=" .. tostring(not not inspectWindowOpen()))
    print("NPI: API availability: tabs=" .. type(GetNumTalentTabs) .. " talents=" .. type(GetNumTalents)
      .. " legacyTree=" .. type(GetTalentTabInfo) .. " legacyTalent=" .. type(GetTalentInfo)
      .. " namespacedTree=" .. type(C_SpecializationInfo and C_SpecializationInfo.GetSpecializationInfo)
      .. " namespacedTalent=" .. type(C_SpecializationInfo and C_SpecializationInfo.GetTalentInfo))

  elseif lower == "test" then
    if InCombatLockdown() then print("NPI: test blocked: leave combat first."); return end
    if inspectWindowOpen() then print("NPI: test blocked: close the inspect/talent window first."); return end
    local guid = safeGUID("target")
    if not guid or not UnitIsPlayer("target") or UnitIsUnit("target", "player") then
      print("NPI: target another nearby player first."); return
    end
    local ok, eligible = pcall(CanInspect, "target", false)
    if not ok or not eligible then print("NPI: target cannot be inspected; run /npi diag."); return end
    if pending then print("NPI: inspect already pending; wait 10 seconds and retry."); return end
    local now = GetTime()
    local delay = math.max(lastRequest + THROTTLE, nextRequest) - now
    if delay > 0 then print(("NPI: inspect cooldown; retry in %.1f seconds."):format(delay)); return end
    collecting = true
    NPI_DEBUG = true
    pending = { guid = guid, unit = "target", t = now, test = true }
    lastRequest = now
    stats.requests = stats.requests + 1
    selfCalling = true
    local sent, err = pcall(NotifyInspect, "target")
    selfCalling = false
    if not sent then
      pending = nil
      lastResult = "NotifyInspect error: " .. tostring(err)
      print("NPI: " .. lastResult)
    else
      print("NPI: test request sent. Keep this player targeted; waiting up to 10 seconds for INSPECT_READY.")
    end

  elseif lower == "list" then
    local n = 0
    for guid in pairs(inspectedThisSession) do
      local e = NameplateInspectDB[guid]
      if e and fresh(guid) then
        n = n + 1
        print(("NPI: %s — %s (%dm ago)"):format(e.name, buildName(e), math.floor((time() - e.time) / 60)))
      end
    end
    print(("NPI: %d fresh inspect(s) this session, %d queued."):format(n, #queue))

  elseif lower == "debug" then
    NPI_DEBUG = not NPI_DEBUG
    print("NPI: debug " .. (NPI_DEBUG and "on - inspect requests, responses, failures, and caches" or "off"))

  elseif lower == "clear" then
    wipe(NameplateInspectDB)
    wipe(inspectedThisSession)
    wipe(tries)
    print("NPI: cache cleared.")

  else
    for _, e in pairs(NameplateInspectDB) do
      if e.name:lower():find(lower, 1, true) == 1 then show(e) return end
    end
    print("NPI: no cached player matching '" .. msg .. "'.")
  end
end
