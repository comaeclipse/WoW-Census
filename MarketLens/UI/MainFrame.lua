-- Native Auction House integration. MarketLens appears as a real tab on the
-- classic AuctionFrame (Browse | Bids | Auctions | MarketLens), using Blizzard's
-- own AuctionTabTemplate / PanelTemplates, WoW fonts, item icons and tooltips.
-- Away from the AH, /ml shows the same board in a standalone Blizzard-style window.

local ML = MarketLens
local UI = ML.UI

local ROW_HEIGHT   = 18
local VISIBLE_ROWS = 15
local COL_PAD      = 8
local ICON_GUTTER  = 22   -- left space reserved for a row icon
local BOARD_W      = 560
local BOARD_H      = 392

UI.view    = "markets"                     -- markets | items | trends | deals | population
UI.nav     = { level = 0 }                 -- drill state for the markets view
UI.popMode = "class"                       -- population: class | race | demand | characters

function UI.ScoreText(v)
    if v == nil then return "|cff808080--|r" end
    local hex
    if v >= 85 then hex = "ff40c040"
    elseif v >= 70 then hex = "ff80c040"
    elseif v >= 50 then hex = "ffd0b030"
    elseif v >= 30 then hex = "ffd08030"
    else hex = "ffc04040" end
    return "|c" .. hex .. tostring(v) .. "|r"
end

function UI.PctText(p)
    if p == nil then return "|cff808080--|r" end
    local pct = p * 100
    local hex = pct >= 0 and "ff40c040" or "ffc04040"
    return string.format("|c%s%s%.0f%%|r", hex, pct >= 0 and "+" or "", pct)
end

local function makeSubTab(board, label, view, anchor)
    local b = CreateFrame("Button", nil, board)
    b:SetSize(80, 20)
    if anchor then
        b:SetPoint("LEFT", anchor, "RIGHT", 10, 0)
    else
        b:SetPoint("TOPLEFT", board, "TOPLEFT", 14, -34)
    end
    local fs = b:CreateFontString(nil, "OVERLAY", "GameFontNormal")
    fs:SetPoint("LEFT")
    fs:SetText(label)
    b.text = fs
    b.view = view
    b:SetScript("OnClick", function() UI:SetView(view) end)
    b:SetScript("OnEnter", function() fs:SetTextColor(1, 1, 1) end)
    b:SetScript("OnLeave", function() UI:StyleSubTabs() end)
    b:SetWidth(fs:GetStringWidth() + 8)
    return b
end

function UI:StyleSubTabs()
    for _, b in ipairs(self.subtabs) do
        if b.view == self.view then
            b.text:SetTextColor(1, 0.82, 0)         -- active: gold
        else
            b.text:SetTextColor(0.5, 0.5, 0.5)      -- inactive: gray
        end
    end
end

function UI:BuildBoard()
    if self.board then return self.board end

    local board = CreateFrame("Frame", "MarketLensBoard", UIParent)
    board:SetSize(BOARD_W, BOARD_H)
    board:Hide()
    self.board = board

    -- Own background so we never depend on the AuctionFrame's per-tab textures
    -- (which blank out when switching Blizzard tabs and back).
    local bg = board:CreateTexture(nil, "BACKGROUND")
    bg:SetAllPoints()
    bg:SetTexture("Interface\\Tooltips\\UI-Tooltip-Background")
    bg:SetHorizTile(true)
    bg:SetVertTile(true)
    bg:SetVertexColor(0.06, 0.06, 0.07, 0.94)
    board.bg = bg

    local heading = board:CreateFontString(nil, "OVERLAY", "GameFontNormalLarge")
    heading:SetPoint("TOPLEFT", 14, -10)
    heading:SetText("|cffffd100MarketLens|r |cff808080Market Intelligence|r")
    board.heading = heading

    local sub = board:CreateFontString(nil, "OVERLAY", "GameFontHighlightSmall")
    sub:SetPoint("TOPRIGHT", -14, -14)
    sub:SetJustifyH("RIGHT")
    board.subtitle = sub

    self.subtabs = {}
    local t1 = makeSubTab(board, "Markets",    "markets")
    local t2 = makeSubTab(board, "Items",      "items", t1)
    local t3 = makeSubTab(board, "Trends",     "trends", t2)
    local t4 = makeSubTab(board, "Deals",      "deals", t3)
    local t5 = makeSubTab(board, "Population", "population", t4)
    self.subtabs = { t1, t2, t3, t4, t5 }

    local scan = CreateFrame("Button", nil, board, "UIPanelButtonTemplate")
    scan:SetSize(120, 22)
    scan:SetPoint("BOTTOMRIGHT", -12, 10)
    scan:SetText("Scan Auction House")
    -- The button press is the hardware event SendWho requires, so a Population
    -- scan can be launched straight from OnClick.
    scan:SetScript("OnClick", function()
        if UI.view == "population" then
            ML.Population:Scan()
        else
            ML.Scanner:StartScan()
        end
    end)
    board.scanButton = scan

    local refresh = CreateFrame("Button", nil, board, "UIPanelButtonTemplate")
    refresh:SetSize(70, 22)
    refresh:SetPoint("RIGHT", scan, "LEFT", -6, 0)
    refresh:SetText("Refresh")
    refresh:SetScript("OnClick", function() UI:Refresh() end)

    local crumb = CreateFrame("Button", nil, board)
    crumb:SetSize(400, 16)
    crumb:SetPoint("TOPLEFT", 14, -58)
    local ct = crumb:CreateFontString(nil, "OVERLAY", "GameFontNormalSmall")
    ct:SetPoint("LEFT"); ct:SetJustifyH("LEFT")
    crumb.text = ct
    crumb:SetScript("OnClick", function()
        if UI.view == "population" then UI:CyclePopMode() else UI:NavigateUp() end
    end)
    board.crumb = crumb

    local header = CreateFrame("Frame", nil, board)
    header:SetPoint("TOPLEFT", 12, -78)
    header:SetPoint("TOPRIGHT", -30, -78)
    header:SetHeight(16)
    header.cells = {}
    board.header = header

    local line = board:CreateTexture(nil, "ARTWORK")
    line:SetColorTexture(1, 0.82, 0, 0.25)
    line:SetPoint("TOPLEFT", header, "BOTTOMLEFT", 0, -2)
    line:SetPoint("TOPRIGHT", header, "BOTTOMRIGHT", 0, -2)
    line:SetHeight(1)

    local scroll = CreateFrame("ScrollFrame", "MarketLensScroll", board, "FauxScrollFrameTemplate")
    scroll:SetPoint("TOPLEFT", header, "BOTTOMLEFT", 0, -6)
    scroll:SetPoint("BOTTOMRIGHT", board, "BOTTOMRIGHT", -30, 40)
    scroll:SetScript("OnVerticalScroll", function(self, offset)
        FauxScrollFrame_OnVerticalScroll(self, offset, ROW_HEIGHT, function() UI:Paint() end)
    end)
    board.scroll = scroll

    board.rows = {}
    for i = 1, VISIBLE_ROWS do
        local row = CreateFrame("Button", nil, board)
        row:SetHeight(ROW_HEIGHT)
        if i == 1 then
            row:SetPoint("TOPLEFT", scroll, "TOPLEFT", 0, 0)
        else
            row:SetPoint("TOPLEFT", board.rows[i-1], "BOTTOMLEFT", 0, 0)
        end
        row:SetPoint("RIGHT", scroll, "RIGHT", 0, 0)

        local hl = row:CreateTexture(nil, "HIGHLIGHT")
        hl:SetAllPoints()
        hl:SetColorTexture(1, 0.82, 0, 0.12)
        hl:SetBlendMode("ADD")

        local icon = row:CreateTexture(nil, "ARTWORK")
        icon:SetSize(16, 16)
        icon:SetPoint("LEFT", row, "LEFT", 3, 0)
        icon:SetTexCoord(0.08, 0.92, 0.08, 0.92) -- trim default icon border
        row.icon = icon

        row.cells = {}
        for c = 1, 8 do
            local fs = row:CreateFontString(nil, "OVERLAY", "GameFontHighlightSmall")
            fs:SetHeight(ROW_HEIGHT)
            row.cells[c] = fs
        end
        row:SetScript("OnClick", function() UI:OnRowClick(row) end)
        row:SetScript("OnEnter", function() UI:OnRowEnter(row) end)
        row:SetScript("OnLeave", function() GameTooltip:Hide() end)
        board.rows[i] = row
    end

    local hint = board:CreateFontString(nil, "OVERLAY", "GameFontDisableSmall")
    hint:SetPoint("BOTTOMLEFT", 14, 14)
    hint:SetPoint("BOTTOMRIGHT", scan, "BOTTOMLEFT", -12, 0)
    hint:SetJustifyH("LEFT")
    hint:SetWordWrap(true)
    hint:SetText("Demand: region sale data where available, else inferred from your scans \226\128\148 never observed sales.")
    board.hint = hint

    local status = board:CreateFontString(nil, "OVERLAY", "GameFontNormalLarge")
    status:SetPoint("CENTER", scroll, "CENTER", 0, 0)
    status:SetJustifyH("CENTER")
    status:SetTextColor(1, 0.82, 0)
    status:Hide()
    board.status = status

    self:StyleSubTabs()
    return board
end

-- Standalone window (used when not at the Auction House)

function UI:BuildStandalone()
    if self.win then return self.win end
    local win = CreateFrame("Frame", "MarketLensWindow", UIParent, "BackdropTemplate")
    win:SetSize(BOARD_W + 40, BOARD_H + 40)
    win:SetPoint("CENTER")
    win:SetMovable(true); win:EnableMouse(true)
    win:RegisterForDrag("LeftButton")
    win:SetScript("OnDragStart", win.StartMoving)
    win:SetScript("OnDragStop", win.StopMovingOrSizing)
    win:SetClampedToScreen(true)
    win:SetFrameStrata("HIGH")
    if win.SetBackdrop then
        win:SetBackdrop({
            bgFile = "Interface\\DialogFrame\\UI-DialogBox-Background",
            edgeFile = "Interface\\DialogFrame\\UI-DialogBox-Border",
            tile = true, tileSize = 32, edgeSize = 32,
            insets = { left = 11, right = 12, top = 12, bottom = 11 },
        })
    end
    win:Hide()
    tinsert(UISpecialFrames, "MarketLensWindow")
    local close = CreateFrame("Button", nil, win, "UIPanelCloseButton")
    close:SetPoint("TOPRIGHT", -4, -4)
    self.win = win
    return win
end

function UI:ApplyColumns(spec)
    local header = self.board.header
    self.colX = {}
    local x = ICON_GUTTER
    for i, col in ipairs(spec) do
        self.colX[i] = x
        local fs = header.cells[i]
        if not fs then
            fs = header:CreateFontString(nil, "OVERLAY", "GameFontNormalSmall")
            fs:SetHeight(16)
            header.cells[i] = fs
        end
        fs:ClearAllPoints()
        fs:SetPoint("LEFT", header, "LEFT", x, 0)
        fs:SetWidth(col.width)
        fs:SetJustifyH(col.justify or "LEFT")
        fs:SetText("|cffffd100" .. col.label .. "|r")
        fs:Show()
        x = x + col.width + COL_PAD
    end
    for i = #spec + 1, #header.cells do header.cells[i]:Hide() end
    self.curColumns = spec
end

function UI:SetView(view)
    self.view = view
    if view == "markets" then self.nav = { level = 0 } end
    self:StyleSubTabs()
    self:Refresh()
end

function UI:NavigateUp()
    if self.view ~= "markets" then return end
    if self.nav.level == 2 then
        self.nav.level, self.nav.market = 1, nil
    elseif self.nav.level == 1 then
        self.nav.level, self.nav.profession = 0, nil
    end
    self:Refresh()
end

-- Population tab cycles class -> race -> demand -> unique characters.
local POP_NEXT = { class = "race", race = "demand", demand = "characters", characters = "class" }
function UI:CyclePopMode()
    self.popMode = POP_NEXT[self.popMode or "class"] or "class"
    self:Refresh()
end

function UI:OnRowClick(row)
    local entry = row.entry
    if not entry or self.view ~= "markets" then return end
    if self.nav.level == 0 and entry.key then
        self.nav.profession, self.nav.level = entry.key, 1
        self:Refresh()
    elseif self.nav.level == 1 and entry.key then
        self.nav.market, self.nav.level = entry.key, 2
        self:Refresh()
    end
end

function UI:OnRowEnter(row)
    local entry = row.entry
    if not entry or not ML.UI.Tooltips then return end
    if entry.itemID then
        ML.UI.Tooltips:ShowItemRow(row, entry.itemID)
    elseif entry.summary then
        ML.UI.Tooltips:ShowMarketRow(row, entry)
    end
end

local function crumbLabel(nav)
    if nav.level == 0 then
        return "|cff808080Select a market to drill in|r"
    elseif nav.level == 1 then
        return "|cff33aaff< Back|r   All > |cffffffff" .. (nav.profession or "?") .. "|r"
    else
        return "|cff33aaff< Back|r   " .. (nav.profession or "?")
            .. " > |cffffffff" .. (nav.market or "?") .. "|r"
    end
end

local POP_NAME = { class = "Class", race = "Race", demand = "Inferred profession demand", characters = "Unique characters" }
local POP_HINT = { class = "Race", race = "Demand", demand = "Unique characters", characters = "Class" }
local function popCrumb(mode)
    local agg = ML.Population and ML.Population:Aggregate()
    if not agg then
        return "|cff808080No samples yet \226\128\148 press Scan Population (a /who of the realm; works anywhere)|r"
    end
    return string.format(
        "|cff33aaff%s|r  |cff808080\194\183 %d sighting(s) across %d scan(s) \226\128\148 click for %s|r",
        POP_NAME[mode] or "Class", agg.observed, agg.samples, POP_HINT[mode] or "Race")
end

-- Data confidence 0-100 from scan freshness, snapshot depth, and whether
-- region (TSM) data is loaded. Mirrors the site's freshness indicator.
function UI:Confidence()
    local last = ML.realm.lastScan
    if not last then return 0 end
    local ageH = (time() - last) / 3600
    local fresh = ML.Util.Clamp((48 - ageH) / (48 - 1) * 100, 0, 100)
    local tot, items = 0, 0
    for _, rec in pairs(ML.realm.items) do
        items = items + 1
        tot = tot + (rec.snaps and #rec.snaps or 0)
    end
    local avg = items > 0 and tot / items or 0
    local depth = ML.Util.Clamp((avg - 1) / (6 - 1) * 100, 0, 100)
    local region = ML.Region:IsLoaded() and 100 or 0
    return ML.Util.Round(0.45 * fresh + 0.30 * depth + 0.25 * region)
end

function UI:Refresh()
    if not self.board then return end
    self.model = ML.Scores:BuildAll()

    local last = ML.realm.lastScan
    local when
    if not last then
        when = "never"
    else
        local secs = time() - last
        if SecondsToTime and secs >= 60 then
            when = SecondsToTime(secs, true) .. " ago"
        else
            when = "just now"
        end
    end
    local regionLine = ""
    if ML.Region:IsLoaded() then
        local m = ML.Region:Meta()
        regionLine = string.format("\n|cff707070Region demand: TSM %s \194\183 %s|r",
            m.region or "?", ML.Region:Age() or "?")
    else
        regionLine = "\n|cff707070Region demand: not imported (run update-data)|r"
    end
    local conf = self:Confidence()
    local confLine = string.format("\n|cff808080Confidence:|r %s", UI.ScoreText(conf))
    self.board.subtitle:SetText(string.format("%s\n|cff808080Last scan:|r %s%s%s",
        ML:RealmKey(), when, confLine, regionLine))

    local mod, cols, entries
    if self.view == "markets" then
        mod = (self.nav.level == 2) and ML.UI.ItemTable or ML.UI.MarketTable
        cols = mod:Columns(self.nav.level)
        entries = mod:Rows(self.model, self.nav)
        self.board.crumb:Show()
        self.board.crumb.text:SetText(crumbLabel(self.nav))
    elseif self.view == "items" then
        cols = ML.UI.ItemTable:Columns(2)
        entries = ML.UI.ItemTable:FlatRows(self.model, "opportunity")
        self.board.crumb:Hide()
    elseif self.view == "deals" then
        cols = ML.UI.ItemTable:DealColumns()
        entries = ML.UI.ItemTable:DealRows(self.model)
        self.board.crumb:Show()
        self.board.crumb.text:SetText("|cff808080Cheaper on your realm than the region average sale price \226\128\148 buy-low / flip candidates|r")
    elseif self.view == "population" then
        local mode = self.popMode or "class"
        cols = ML.UI.PopTable:Columns(mode)
        entries = ML.UI.PopTable:Rows(mode)
        self.board.crumb:Show()
        self.board.crumb.text:SetText(popCrumb(mode))
    else -- trends
        cols = ML.UI.ItemTable:TrendColumns()
        entries = ML.UI.ItemTable:FlatRows(self.model, "trend")
        self.board.crumb:Hide()
    end

    -- Keep the scan button in step with the active tab (unless mid-scan).
    if not (ML.Scanner.scanning or (ML.Population and ML.Population.pending)) then
        self.board.scanButton:SetText(self:ScanButtonLabel())
    end

    self:ApplyColumns(cols)
    self.entries = entries
    self:Paint()
end

function UI:Paint()
    local entries = self.entries or {}
    local scroll = self.board.scroll
    FauxScrollFrame_Update(scroll, #entries, VISIBLE_ROWS, ROW_HEIGHT)
    local offset = FauxScrollFrame_GetOffset(scroll)

    for i = 1, VISIBLE_ROWS do
        local row = self.board.rows[i]
        local entry = entries[i + offset]
        if entry then
            row.entry = entry
            if entry.icon then
                row.icon:SetTexture(entry.icon)
                row.icon:Show()
            else
                row.icon:Hide()
            end
            for c = 1, 8 do
                local fs = row.cells[c]
                local col = self.curColumns[c]
                local cell = entry.cells[c]
                if col and cell ~= nil then
                    fs:ClearAllPoints()
                    fs:SetPoint("LEFT", row, "LEFT", self.colX[c], 0)
                    fs:SetWidth(col.width)
                    fs:SetJustifyH(col.justify or "LEFT")
                    fs:SetText(cell)
                    fs:Show()
                else
                    fs:Hide()
                end
            end
            row:Show()
        else
            row.entry = nil
            row:Hide()
        end
    end
end

function UI:ShowAttached()
    self:BuildBoard()
    -- Blizzard's AuctionFrameTab_OnClick only manages tabs 1-3, so hide the
    -- default panels (and the money frame that would bleed through) ourselves.
    if AuctionFrameBrowse then AuctionFrameBrowse:Hide() end
    if AuctionFrameBid then AuctionFrameBid:Hide() end
    if AuctionFrameAuctions then AuctionFrameAuctions:Hide() end
    if AuctionFrameMoneyFrame then AuctionFrameMoneyFrame:Hide() end

    -- Fill the AuctionFrame interior: clear the title strip at the top and the
    -- tab row (+ money frame) at the bottom so nothing overlaps.
    local board = self.board
    board:SetParent(AuctionFrame)
    board:ClearAllPoints()
    board:SetPoint("TOPLEFT", AuctionFrame, "TOPLEFT", 20, -62)
    board:SetPoint("BOTTOMRIGHT", AuctionFrame, "BOTTOMRIGHT", -20, 40)
    board:SetFrameLevel(AuctionFrame:GetFrameLevel() + 5)
    board:Show()
    self:Refresh()
end

function UI:HideBoard()
    if self.board then self.board:Hide() end
    -- Restore the AH money frame for the Blizzard tabs.
    if AuctionFrameMoneyFrame and AuctionFrame and AuctionFrame:IsShown() then
        AuctionFrameMoneyFrame:Show()
    end
end

-- Route to the right integration for this client's Auction House. The legacy
-- AuctionFrame (Classic/TBC) takes a native 4th tab; the modern AuctionHouseFrame
-- (Retail / Cata+ Classic) has no compatible tab template here, so we dock our
-- board beside it with a toggle button.
function UI:AttachToAH()
    if AuctionFrame then
        self:AttachLegacy()
    elseif AuctionHouseFrame then
        self:AttachModern()
    end
end

-- Create the native AH tab once the AuctionFrame exists.
function UI:AttachLegacy()
    if self.ahTab or not AuctionFrame then return end

    local index = (AuctionFrame.numTabs or 3) + 1
    local tab = CreateFrame("Button", "AuctionFrameTab" .. index, AuctionFrame, "AuctionTabTemplate")
    tab:SetID(index)
    tab:SetText("MarketLens")
    tab:SetPoint("LEFT", _G["AuctionFrameTab" .. (index - 1)], "RIGHT", -15, 0)
    if PanelTemplates_SetNumTabs then PanelTemplates_SetNumTabs(AuctionFrame, index) end
    if PanelTemplates_EnableTab then PanelTemplates_EnableTab(AuctionFrame, index) end
    if PanelTemplates_TabResize then PanelTemplates_TabResize(tab, 0, nil, 36) end
    self.ahTab = tab

    -- Blizzard's tab template calls AuctionFrameTab_OnClick; we hook it to show
    -- or hide our board depending on which tab is now active.
    if not self._hooked and _G.AuctionFrameTab_OnClick then
        hooksecurefunc("AuctionFrameTab_OnClick", function(clicked)
            local id = clicked and clicked.GetID and clicked:GetID()
            if id == UI.ahTab:GetID() then
                UI:ShowAttached()
            else
                UI:HideBoard()
            end
        end)
        self._hooked = true
    end
end

-- Modern AH (Retail / Cata+ Classic): a real Blizzard tab in the AH tab strip
-- (next to Buy/Sell/Auctions) via LibAHTab-1-0. The library shows our board when
-- the tab is clicked and hides it when a
-- Blizzard tab is clicked. The board is read-only (no protected AH calls), so
-- parenting it to the AH frame is taint-safe.
local function getLibAHTab()
    return _G.LibStub and _G.LibStub("LibAHTab-1-0", true) or nil
end

function UI:AttachModern()
    if self.modernTab or not AuctionHouseFrame then return end
    local LibAHTab = getLibAHTab()
    if not (LibAHTab and AuctionHouseFrame.Tabs and #AuctionHouseFrame.Tabs > 0) then
        ML:Print("Couldn't add the AH tab on this client \226\128\148 use |cffffff00/ml|r for the window.")
        return
    end

    self:BuildBoard()
    local board, ahf = self.board, AuctionHouseFrame
    -- Park the board so it fills the AH content area; LibAHTab toggles it.
    board:SetParent(ahf)
    board:ClearAllPoints()
    board:SetPoint("TOPLEFT", ahf, "TOPLEFT", 12, -60)
    board:SetPoint("BOTTOMRIGHT", ahf, "BOTTOMRIGHT", -12, 32)
    board:SetFrameLevel(ahf:GetFrameLevel() + 10)

    LibAHTab:CreateTab("MarketLens", board, "MarketLens", "|cffffd100MarketLens|r |cff808080Market Intelligence|r")
    self.modernTab = LibAHTab:GetButton("MarketLens")

    -- Repaint whenever the tab reveals our board.
    board:HookScript("OnShow", function() UI:Refresh() end)
end

-- Export dialog (copy realm history to the website)

function UI:ShowExport()
    if not self.exportFrame then
        local f = CreateFrame("Frame", "MarketLensExport", UIParent, "BackdropTemplate")
        f:SetSize(460, 300); f:SetPoint("CENTER"); f:SetFrameStrata("DIALOG")
        f:SetMovable(true); f:EnableMouse(true); f:RegisterForDrag("LeftButton")
        f:SetScript("OnDragStart", f.StartMoving); f:SetScript("OnDragStop", f.StopMovingOrSizing)
        if f.SetBackdrop then
            f:SetBackdrop({ bgFile = "Interface\\DialogFrame\\UI-DialogBox-Background",
                edgeFile = "Interface\\DialogFrame\\UI-DialogBox-Border", tile = true,
                tileSize = 32, edgeSize = 32, insets = { left = 11, right = 12, top = 12, bottom = 11 } })
        end
        tinsert(UISpecialFrames, "MarketLensExport")
        local title = f:CreateFontString(nil, "OVERLAY", "GameFontNormal")
        title:SetPoint("TOP", 0, -14); title:SetText("|cff33aaffMarketLens|r Export")
        local tip = f:CreateFontString(nil, "OVERLAY", "GameFontHighlightSmall")
        tip:SetPoint("TOP", title, "BOTTOM", 0, -4)
        tip:SetText("Ctrl+C to copy, then paste into an item page on the website.")
        local close = CreateFrame("Button", nil, f, "UIPanelCloseButton")
        close:SetPoint("TOPRIGHT", -4, -4)

        local scroll = CreateFrame("ScrollFrame", "MarketLensExportScroll", f, "UIPanelScrollFrameTemplate")
        scroll:SetPoint("TOPLEFT", 16, -48); scroll:SetPoint("BOTTOMRIGHT", -34, 16)
        local edit = CreateFrame("EditBox", nil, scroll)
        edit:SetMultiLine(true); edit:SetAutoFocus(false); edit:SetFontObject(ChatFontNormal)
        edit:SetWidth(400); edit:SetScript("OnEscapePressed", function(self) self:ClearFocus(); f:Hide() end)
        scroll:SetScrollChild(edit)
        f.edit = edit
        self.exportFrame = f
    end
    self.exportFrame.edit:SetText(ML:BuildExport())
    self.exportFrame.edit:HighlightText()
    self.exportFrame.edit:SetFocus()
    self.exportFrame:Show()
end

function UI:Toggle()
    self:BuildBoard()
    if AuctionFrame and AuctionFrame:IsShown() and self.ahTab and _G.AuctionFrameTab_OnClick then
        -- Behave like clicking our native tab.
        AuctionFrameTab_OnClick(self.ahTab)
        return
    end
    if AuctionHouseFrame and AuctionHouseFrame:IsShown() and self.modernTab then
        -- Modern AH open: select our native tab (same as clicking it).
        local LibAHTab = getLibAHTab()
        if LibAHTab then LibAHTab:SetSelected("MarketLens") end
        return
    end
    local win = self:BuildStandalone()
    if win:IsShown() then
        win:Hide()
        self:HideBoard()
    else
        local board = self.board
        board:SetParent(win)
        board:ClearAllPoints()
        board:SetSize(BOARD_W, BOARD_H) -- restore fixed size (attach mode uses 2 anchors)
        board:SetPoint("TOPLEFT", win, "TOPLEFT", 16, -14)
        board:SetFrameLevel(win:GetFrameLevel() + 2)
        win:Show()
        board:Show()
        self:Refresh()
    end
end

function UI:SetStatus(text, show)
    if not self.board then return end
    self.board.status:SetText(text or "")
    if show == false then self.board.status:Hide() else self.board.status:Show() end
end

function UI:ScanButtonLabel()
    return (self.view == "population") and "Scan Population" or "Scan Auction House"
end

function UI:SetScanBusy(busy)
    if not self.board then return end
    local b = self.board.scanButton
    if busy then
        b:Disable(); b:SetText("Scanning...")
    else
        b:Enable(); b:SetText(self:ScanButtonLabel())
    end
end

function UI:OnScanStart()
    self:SetScanBusy(true)
    self:SetStatus("Starting scan...")
end

function UI:OnScanProgress(p)
    if type(p) ~= "table" then return end
    if p.mode == "getAll" then
        self:SetStatus(string.format("Reading auctions...\n|cffffffff%d / %d|r",
            p.done or 0, p.total or 0))
    elseif p.mode == "browse" then
        self:SetStatus(string.format("Scanning Retail summaries...\n|cffffffff%d results read|r",
            p.rows or 0))
    elseif p.resolving and p.resolving > 0 then
        local st = p.stats or {}
        self:SetStatus(string.format("Scanning...  page %d / %d\n|cffffffff%d rows; %d%% owners; %d names resolving|r",
            p.page or 0, p.pages or 0, p.rows or 0, st.ownerCoverage or 0, p.resolving or 0))
    else
        local st = p.stats or {}
        if st.ownerCoverage then
            self:SetStatus(string.format("Scanning...  page %d / %d\n|cffffffff%d rows; %d%% owners; ~%ds full|r",
                p.page or 0, p.pages or 0, p.rows or 0,
                st.ownerCoverage or 0, st.projectedFullSeconds or 0))
        else
            self:SetStatus(string.format("Scanning...  page %d / %d\n|cffffffff%d auctions read|r",
                p.page or 0, p.pages or 0, p.rows or 0))
        end
    end
end

function UI:OnScanDone(msg)
    self:SetScanBusy(false)
    self:SetStatus(msg)
    if self.board:IsShown() then self:Refresh() end
    -- Clear the message shortly after, unless another scan started.
    if C_Timer and C_Timer.After then
        C_Timer.After(4, function()
            if not ML.Scanner.scanning then UI:SetStatus("", false) end
        end)
    end
end

function UI:OnPopScanStart()
    if self.view ~= "population" then return end
    self:SetScanBusy(true)
    self:SetStatus("Scanning /who...")
end

function UI:OnPopScanDone(sample)
    if not self.board then return end
    self:SetScanBusy(false)
    if self.view ~= "population" then return end
    if sample then
        self:SetStatus(string.format("Sampled %d of %d online\n|cffffffff%s|r",
            sample.observed or 0, sample.total or 0, sample.filter or ""))
    end
    self:Refresh()
    if C_Timer and C_Timer.After then
        C_Timer.After(4, function()
            if not (ML.Population and ML.Population.pending) then UI:SetStatus("", false) end
        end)
    end
end

function UI:Init()
    self:BuildBoard()
    if self.Minimap then self.Minimap:Init() end

    ML:On("SCAN_START",    function() UI:OnScanStart() end)
    ML:On("SCAN_PROGRESS", function(p) UI:OnScanProgress(p) end)
    ML:On("SCAN_ABORT",    function(reason) UI:OnScanDone("Scan aborted: " .. tostring(reason)) end)
    ML:On("POP_SCAN_START",    function() UI:OnPopScanStart() end)
    ML:On("POP_SCAN_COMPLETE", function(sample) UI:OnPopScanDone(sample) end)

    -- Attach the native tab when the AH opens; hide our board when it closes.
    local ah = CreateFrame("Frame")
    ah:RegisterEvent("AUCTION_HOUSE_SHOW")
    ah:RegisterEvent("AUCTION_HOUSE_CLOSED")
    -- Retail may not fire AUCTION_HOUSE_SHOW (esp. after /reload at the AH); the
    -- interaction-manager event is the reliable one there.
    pcall(ah.RegisterEvent, ah, "PLAYER_INTERACTION_MANAGER_FRAME_SHOW")
    local AUCTIONEER = Enum and Enum.PlayerInteractionType and Enum.PlayerInteractionType.Auctioneer
    ah:SetScript("OnEvent", function(_, event, arg1)
        if event == "AUCTION_HOUSE_SHOW"
           or (event == "PLAYER_INTERACTION_MANAGER_FRAME_SHOW" and (AUCTIONEER == nil or arg1 == AUCTIONEER)) then
            UI:AttachToAH()
        elseif event == "AUCTION_HOUSE_CLOSED" then
            UI:HideBoard()
        end
    end)

    -- If we loaded (or /reloaded) with the AH already open, its show event was
    -- missed -- attach right now so the tab still appears.
    if (AuctionHouseFrame and AuctionHouseFrame:IsShown())
       or (AuctionFrame and AuctionFrame:IsShown()) then
        UI:AttachToAH()
    end

    -- Repaint + report when a scan completes.
    ML:On("SCAN_COMPLETE", function(items, rows, mode, meta)
        local unit = mode == "browse" and "summaries" or "auctions"
        if meta and meta.sellerSample then
            local st = meta.stats or {}
            local title = meta.partial and "Seller sample complete" or "Seller scan complete"
            UI:OnScanDone(string.format("%s\n|cffffffff%d items \226\128\162 %d %s \226\128\162 %d%% owners|r",
                title, items or 0, rows or 0, unit, st.ownerCoverage or 0))
        else
            UI:OnScanDone(string.format("Scan complete\n|cffffffff%d items \226\128\162 %d %s|r",
                items or 0, rows or 0, unit))
        end
    end)
end
