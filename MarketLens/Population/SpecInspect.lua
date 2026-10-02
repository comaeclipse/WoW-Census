-- Opportunistic specialization sampling through WoW's normal Inspect API.
-- Candidates only come from unit tokens WoW already exposed: nearby player
-- nameplates, mouseover/target, and party or raid members. /who names are
-- deliberately never inspect targets. This is a sampled distribution, not a
-- census of every player seen in a zone.

local ML = MarketLens
local Pop = ML.Population
local Inspect = {}
Pop.Inspect = Inspect

local SAMPLE_CAP, REQUEST_INTERVAL, REQUEST_TIMEOUT = 1000, 4, 10
local queue, queued, seen, failed = {}, {}, {}, {}
local pending
local nextRequestAt = 0
local lastBlockedNotice = 0
local companionSeen = {}
local frame = CreateFrame("Frame")

local function specializationID(unit)
    if C_SpecializationInfo and C_SpecializationInfo.GetInspectSpecialization then
        return C_SpecializationInfo.GetInspectSpecialization(unit)
    end
    if GetInspectSpecialization then return GetInspectSpecialization(unit) end
end

local function specializationName(id)
    if not id or id == 0 then return nil end
    if GetSpecializationInfoForSpecID then
        local _, name = GetSpecializationInfoForSpecID(id)
        if name and name ~= "" then return name end
    end
    if C_SpecializationInfo and C_SpecializationInfo.GetSpecializationInfoByID then
        local _, name = C_SpecializationInfo.GetSpecializationInfoByID(id)
        if name and name ~= "" then return name end
    end
end

-- Pre-MoP-style clients expose inspected talent trees even where their
-- specialization API only reports a class-level initial spec. The signatures
-- differ by client family, so accept both the Classic (name, icon, points)
-- and later (id, name, description, icon, points) return layouts.
local function inspectedTalentTree()
    if type(GetNumTalentTabs) ~= "function" or type(GetTalentTabInfo) ~= "function" then return nil end
    local tabs = tonumber(GetNumTalentTabs(true)) or 0
    local bestName, bestPoints, tied, profile = nil, 0, false, {}
    for i = 1, tabs do
        local a, b, c, _, e = GetTalentTabInfo(i, true)
        local name, points
        if type(a) == "number" then name, points = b, e else name, points = a, c end
        points = tonumber(points) or 0
        if name and name ~= "" then
            profile[#profile + 1] = tostring(name) .. "=" .. points
            if points > bestPoints then
                bestName, bestPoints, tied = name, points, false
            elseif points > 0 and points == bestPoints then
                tied = true
            end
        end
    end
    if bestPoints <= 0 then return nil, table.concat(profile, "/") end
    return tied and "Hybrid" or bestName, table.concat(profile, "/")
end

local function now() return GetTime and GetTime() or 0 end

function Inspect:IsSupported()
    return type(NotifyInspect) == "function"
        and (type(GetInspectSpecialization) == "function"
            or (C_SpecializationInfo and type(C_SpecializationInfo.GetInspectSpecialization) == "function"))
end

function Inspect:IsEnabled()
    return self:IsSupported() and ML.db and ML.db.settings and ML.db.settings.specSampling
end

function Inspect:QueueUnit(unit)
    if NameplateInspect_Ready then return end
    if not self:IsEnabled() or not unit or not UnitExists(unit) or not UnitIsPlayer(unit) then return end
    if InCombatLockdown and InCombatLockdown() then return end
    if UnitIsUnit and UnitIsUnit(unit, "player") then return end
    if CanInspect and not CanInspect(unit) then return end
    if CheckInteractDistance and not CheckInteractDistance(unit, 1) then return end
    local guid = UnitGUID(unit)
    if not guid or seen[guid] or queued[guid] or (pending and pending.guid == guid) then return end
    queued[guid] = true
    queue[#queue + 1] = { unit = unit, guid = guid }
end

function Inspect:QueueGroup()
    if IsInRaid and IsInRaid() then
        for i = 1, 40 do self:QueueUnit("raid" .. i) end
    elseif IsInGroup and IsInGroup() then
        for i = 1, 4 do self:QueueUnit("party" .. i) end
    end
end

function Inspect:QueueVisibleNameplates()
    if not (C_NamePlate and C_NamePlate.GetNamePlates) then return end
    for _, plate in ipairs(C_NamePlate.GetNamePlates() or {}) do
        self:QueueUnit(plate.namePlateUnitToken)
    end
end

-- NameplateInspect owns the shared inspect slot when installed. Copy its cache
-- into our realm store without requesting or clearing another addon's inspect.
function Inspect:ConsumeCompanion()
    if not NameplateInspect_Ready or not NameplateInspectDB then return end
    local store = Pop:Store()
    store.inspectBuilds = store.inspectBuilds or {}
    local cutoff = time() - ((ML.db.settings and ML.db.settings.specRetentionDays) or 14) * 86400
    for guid, entry in pairs(store.inspectBuilds) do
        if (entry.time or 0) < cutoff then store.inspectBuilds[guid] = nil end
    end
    for guid, entry in pairs(NameplateInspectDB) do
        if entry.schemaVersion == 2 and (entry.time or 0) >= cutoff and entry.observerRealm == GetRealmName()
            and entry.observerFaction == UnitFactionGroup("player")
            and companionSeen[guid] ~= entry.time then
            store.inspectBuilds[guid] = entry
            companionSeen[guid] = entry.time
        end
    end
end

function Inspect:RequestNext()
    if NameplateInspect_Ready then
        pending = nil
        self:ConsumeCompanion()
        return
    end
    if pending or #queue == 0 or now() < nextRequestAt then return end
    if InCombatLockdown and InCombatLockdown() then return end
    local candidate = table.remove(queue, 1)
    queued[candidate.guid] = nil
    if not UnitExists(candidate.unit) or UnitGUID(candidate.unit) ~= candidate.guid
        or (CanInspect and not CanInspect(candidate.unit))
        or (CheckInteractDistance and not CheckInteractDistance(candidate.unit, 1)) then return end
    pending = { unit = candidate.unit, guid = candidate.guid, startedAt = now() }
    local statsStore = Pop:Store()
    statsStore.specStats = statsStore.specStats or { requests = 0, ready = 0, rejected = 0 }
    statsStore.specStats.requests = (statsStore.specStats.requests or 0) + 1
    nextRequestAt = now() + REQUEST_INTERVAL
    local ok = pcall(NotifyInspect, candidate.unit)
    if not ok then pending = nil end
end

function Inspect:Capture(guid)
    if NameplateInspect_Ready then pending = nil; return end
    if not pending or guid ~= pending.guid then return end
    local request = pending
    pending = nil
    if not UnitExists(request.unit) or UnitGUID(request.unit) ~= guid then
        local lost = Pop:Store()
        lost.specStats = lost.specStats or { requests = 0, ready = 0, rejected = 0 }
        lost.specStats.tokenLost = (lost.specStats.tokenLost or 0) + 1
        return
    end
    local id = specializationID(request.unit)
    local name = specializationName(id)
    local fullName, realm
    if UnitFullName then fullName, realm = UnitFullName(request.unit) end
    fullName = fullName or UnitName(request.unit) or "Unknown"
    local forever = Pop.Profiles and Pop.Profiles.DetectID and Pop.Profiles.DetectID() == "forever"
    if forever then
        fullName = fullName .. ((realm and realm ~= "") and (" " .. realm) or "")
        realm = GetRealmName()
    else
        realm = realm or GetRealmName() or "UnknownRealm"
    end
    local className, classFile = UnitClass(request.unit)
    local race = UnitRace(request.unit)
    local store = Pop:Store()
    store.specStats = store.specStats or { requests = 0, ready = 0, rejected = 0 }
    store.specStats.ready = (store.specStats.ready or 0) + 1
    local talentTree, talentProfile = inspectedTalentTree()
    local source, resolvedName = nil, nil
    if talentTree then
        source, resolvedName = "talent-tree", talentTree
    elseif name and name ~= className then
        source, resolvedName = "specialization-api", name
    else
        store.specStats.rejected = (store.specStats.rejected or 0) + 1
        local placeholder = id and id ~= 0 and (not name or name == className)
        store.specStats.lastReason = placeholder and "placeholder spec id (no spec chosen, or not exposed for other players)"
            or talentProfile ~= "" and "no allocated inspected talent points"
            or "no spec or talent data returned"
        store.specStats.lastTalentProfile = talentProfile or ""
        store.specStats.lastSpecDiag = string.format("specID=%s name=%s class=%s level=%s",
            tostring(id), tostring(name), tostring(className), tostring(UnitLevel(request.unit)))
        failed[guid] = (failed[guid] or 0) + 1
        if failed[guid] >= 2 then seen[guid] = true end
        if ClearInspectPlayer then pcall(ClearInspectPlayer) end
        return
    end
    local sample = {
        t = time(), guid = guid, name = fullName, realm = realm,
        level = UnitLevel(request.unit) or 0, class = className or "",
        classFile = classFile or "", race = race or "", specID = id, spec = resolvedName,
        source = source, talentProfile = talentProfile or "",
        observerZone = GetRealZoneText and GetRealZoneText() or "",
    }
    ML.Util.PushCapped(store.specSamples, sample, SAMPLE_CAP)
    seen[guid] = true
    if ClearInspectPlayer then pcall(ClearInspectPlayer) end
    Pop:Purge()
    ML:Fire("POP_SPEC_CAPTURED", sample)
end

function Inspect:Status()
    local store = Pop:Store()
    local stats = store.specStats or {}
    if NameplateInspect_Ready then
        self:ConsumeCompanion()
        local count = 0
        for _ in pairs(store.inspectBuilds or {}) do count = count + 1 end
        ML:Print("Spec Census: NameplateInspect owns collection; %d contextual build(s) copied. /npi status shows its queue.", count)
        return
    end
    ML:Print("Spec Census: %s; %d retained successful inspect sample(s); %d queued.",
        self:IsEnabled() and "|cff40c040on|r" or "off/unavailable", #(store.specSamples or {}), #queue)
    ML:Print("Inspect diagnostics: %d request(s), %d ready response(s), %d rejected (%s).",
        stats.requests or 0, stats.ready or 0, stats.rejected or 0, stats.lastReason or "none")
    if stats.lastTalentProfile and stats.lastTalentProfile ~= "" then
        ML:Print("Last inspected talent profile: %s", stats.lastTalentProfile)
    end
    if stats.lastSpecDiag then ML:Print("Last rejected read: %s", stats.lastSpecDiag) end
    if (stats.tokenLost or 0) > 0 then
        ML:Print("%d response(s) dropped because the unit token changed before INSPECT_READY.", stats.tokenLost)
    end
    if not self:IsSupported() then ML:Print("This client does not expose inspected specialization reads.") end
end

frame:RegisterEvent("INSPECT_READY")
frame:RegisterEvent("NAME_PLATE_UNIT_ADDED")
frame:RegisterEvent("UPDATE_MOUSEOVER_UNIT")
frame:RegisterEvent("PLAYER_TARGET_CHANGED")
frame:RegisterEvent("GROUP_ROSTER_UPDATE")
frame:RegisterEvent("PLAYER_ENTERING_WORLD")
frame:RegisterEvent("ADDON_ACTION_FORBIDDEN")
frame:RegisterEvent("ADDON_ACTION_BLOCKED")
frame:SetScript("OnEvent", function(_, event, ...)
    if event == "INSPECT_READY" then
        Inspect:Capture(...)
    elseif event == "ADDON_ACTION_FORBIDDEN" or event == "ADDON_ACTION_BLOCKED" then
        local addon, func = ...
        if addon == ML.ADDON and func == "NotifyInspect" then
            pending = nil
            if now() - lastBlockedNotice >= 60 then
                lastBlockedNotice = now()
                ML:Print("This client blocked automatic inspect requests; Spec Census will keep only requests WoW accepts.")
            end
        end
    elseif event == "NAME_PLATE_UNIT_ADDED" then
        Inspect:QueueUnit(...)
    elseif event == "UPDATE_MOUSEOVER_UNIT" then
        Inspect:QueueUnit("mouseover")
    elseif event == "PLAYER_TARGET_CHANGED" then
        Inspect:QueueUnit("target")
    else
        Inspect:QueueGroup()
        Inspect:QueueVisibleNameplates()
    end
end)
local companionTick = 0
frame:SetScript("OnUpdate", function(_, elapsed)
    if NameplateInspect_Ready then
        companionTick = companionTick + elapsed
        if companionTick >= 5 then companionTick = 0; Inspect:ConsumeCompanion() end
        pending = nil
        return
    end
    if pending and now() - pending.startedAt >= REQUEST_TIMEOUT then
        pending = nil
        if ClearInspectPlayer then pcall(ClearInspectPlayer) end
    end
    Inspect:RequestNext()
end)
