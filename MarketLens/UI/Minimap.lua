-- A lightweight, dependency-free minimap button so the dashboard can be opened
-- from anywhere with one click. Draggable around the minimap; position persists.

local ML = MarketLens
local UI = ML.UI
local MM = {}
UI.Minimap = MM

local RADIUS = 80

local function settings()
    local s = ML.db.settings
    if type(s.minimap) ~= "table" then s.minimap = {} end
    if s.minimap.angle == nil then s.minimap.angle = 214 end
    if s.minimap.hide == nil then s.minimap.hide = false end
    return s.minimap
end

local function updatePosition(btn)
    local angle = math.rad(settings().angle)
    btn:SetPoint("CENTER", Minimap, "CENTER",
        math.cos(angle) * RADIUS, math.sin(angle) * RADIUS)
end

local function onDragUpdate(btn)
    local mx, my = Minimap:GetCenter()
    local scale = Minimap:GetEffectiveScale()
    local cx, cy = GetCursorPosition()
    cx, cy = cx / scale, cy / scale
    settings().angle = math.deg(math.atan2(cy - my, cx - mx))
    updatePosition(btn)
end

function MM:Init()
    if self.button then return end

    local btn = CreateFrame("Button", "MarketLensMinimapButton", Minimap)
    btn:SetSize(31, 31)
    btn:SetFrameStrata("MEDIUM")
    btn:SetFrameLevel(8)
    btn:RegisterForClicks("LeftButtonUp", "RightButtonUp")
    btn:RegisterForDrag("LeftButton")

    local icon = btn:CreateTexture(nil, "BACKGROUND")
    icon:SetTexture("Interface\\Icons\\INV_Misc_Coin_01")
    icon:SetSize(19, 19)
    icon:SetPoint("CENTER", 0, 1)
    icon:SetTexCoord(0.08, 0.92, 0.08, 0.92)

    local border = btn:CreateTexture(nil, "OVERLAY")
    border:SetTexture("Interface\\Minimap\\MiniMap-TrackingBorder")
    border:SetSize(53, 53)
    border:SetPoint("TOPLEFT")

    btn:SetHighlightTexture("Interface\\Minimap\\UI-Minimap-ZoomButton-Highlight")

    btn:SetScript("OnClick", function(_, mouseButton)
        if mouseButton == "RightButton" then
            ML.Scanner:StartScan()   -- right-click: quick scan (works at the AH)
        else
            ML.UI:Toggle()           -- left-click: open/close the dashboard
        end
    end)

    btn:SetScript("OnDragStart", function(self)
        self:SetScript("OnUpdate", onDragUpdate)
    end)
    btn:SetScript("OnDragStop", function(self)
        self:SetScript("OnUpdate", nil)
    end)

    btn:SetScript("OnEnter", function(self)
        GameTooltip:SetOwner(self, "ANCHOR_LEFT")
        GameTooltip:AddLine("|cffffd100MarketLens|r")
        GameTooltip:AddLine("Auction House market intelligence", 0.8, 0.8, 0.8)
        GameTooltip:AddLine(" ")
        GameTooltip:AddLine("|cffffffffLeft-click|r  Open dashboard", 0.6, 0.9, 0.6)
        GameTooltip:AddLine("|cffffffffRight-click|r  Scan (at the AH)", 0.6, 0.9, 0.6)
        GameTooltip:AddLine("|cffffffffDrag|r  Move this button", 0.6, 0.9, 0.6)
        GameTooltip:Show()
    end)
    btn:SetScript("OnLeave", function() GameTooltip:Hide() end)

    self.button = btn
    updatePosition(btn)
    self:Refresh()
end

function MM:Refresh()
    if not self.button then return end
    if settings().hide then self.button:Hide() else self.button:Show() end
    updatePosition(self.button)
end
