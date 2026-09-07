-- Column specs + row data for item lists: the drill-down level (items within a
-- market) and the flat "Items" (by opportunity) and "Trends" (by movement) views.

local ML = MarketLens
local UI = ML.UI
local U = ML.Util
local IT = {}
UI.ItemTable = IT

local function itemIcon(itemID)
    if C_Item and C_Item.GetItemIconByID then
        return C_Item.GetItemIconByID(itemID)
    elseif GetItemIcon then
        return GetItemIcon(itemID)
    end
    return "Interface\\Icons\\INV_Misc_QuestionMark"
end

local function itemName(row)
    return row.link or row.name or ("item:" .. row.itemID)
end

local function demandCell(row)
    if row.demand then return UI.ScoreText(row.demand) end
    return string.format("|cff808080%d/%d|r", row.samples or 0, ML.db.settings.minimumSamples or 3)
end

local function oppCell(row)
    if row.opportunity then
        return (row.oppIcon or "") .. " " .. UI.ScoreText(row.opportunity)
    end
    return "|cff808080…|r"
end

-- Seller count is meaningless when the scan API returns no owner names
-- (bulk getAll/replicate on Classic/Anniversary) -- show a dash instead.
local function sellersCell(row)
    if ML.realm and ML.realm.ownersAvailable then return tostring(row.latest.s) end
    return "|cff808080\226\128\148|r"
end

local function velocityCell(row)
    if not row.trend then return "|cff808080--|r" end
    local net = -(row.trend.velocity or 0) -- net supply/hr; negative = draining (demand)
    local hex = net < 0 and "ff40c040" or (net > 0 and "ffc04040" or "ff808080")
    return string.format("|c%s%+.0f/hr|r", hex, net)
end

function IT:Columns(level)
    return {
        { label = "Item",     width = 168, justify = "LEFT"  },
        { label = "Price",    width = 78,  justify = "RIGHT" },
        { label = "Qty",      width = 42,  justify = "RIGHT" },
        { label = "Sellers",  width = 50,  justify = "RIGHT" },
        { label = "\206\148 Supply", width = 62, justify = "RIGHT" },
        { label = "Demand",   width = 56,  justify = "RIGHT" },
        { label = "Opp.",     width = 56,  justify = "RIGHT" },
    }
end

function IT:TrendColumns()
    return {
        { label = "Item",     width = 168, justify = "LEFT"  },
        { label = "Price",    width = 78,  justify = "RIGHT" },
        { label = "\206\148 Price",  width = 56, justify = "RIGHT" },
        { label = "\206\148 Supply", width = 62, justify = "RIGHT" },
        { label = "Velocity", width = 62,  justify = "RIGHT" },
        { label = "Demand",   width = 56,  justify = "RIGHT" },
        { label = "Opp.",     width = 56,  justify = "RIGHT" },
    }
end

local function itemCells(row)
    local supplyDelta = row.trend and UI.PctText(row.trend.supplyPct) or "|cff808080--|r"
    return {
        itemName(row),
        U.MoneyShort(row.latest.w),
        tostring(row.latest.q),
        sellersCell(row),
        supplyDelta,
        demandCell(row),
        oppCell(row),
    }
end

local function trendCells(row)
    return {
        itemName(row),
        U.MoneyShort(row.latest.w),
        row.trend and UI.PctText(row.trend.pricePct) or "|cff808080--|r",
        row.trend and UI.PctText(row.trend.supplyPct) or "|cff808080--|r",
        velocityCell(row),
        demandCell(row),
        oppCell(row),
    }
end

local function makeEntry(row, cellFn)
    return {
        itemID = row.itemID,
        icon   = itemIcon(row.itemID),
        cells  = cellFn(row),
    }
end

-- Drill-down: items within the selected market.
function IT:Rows(model, nav)
    local p = model.professions[nav.profession]; if not p then return {} end
    local m = p.markets[nav.market];             if not m then return {} end

    local rows = {}
    for _, itemID in ipairs(m.items) do rows[#rows + 1] = model.items[itemID] end
    table.sort(rows, function(a, b)
        local ao, bo = a.opportunity, b.opportunity
        if ao and bo and ao ~= bo then return ao > bo end
        if ao and not bo then return true end
        if bo and not ao then return false end
        return (a.marketValue or 0) > (b.marketValue or 0)
    end)

    local out = {}
    for _, row in ipairs(rows) do out[#out + 1] = makeEntry(row, itemCells) end
    return out
end

-- Deals view: local price vs region average sale

function IT:DealColumns()
    return {
        { label = "Item",       width = 168, justify = "LEFT"  },
        { label = "Your buyout",width = 84,  justify = "RIGHT" },
        { label = "Region avg", width = 84,  justify = "RIGHT" },
        { label = "Margin",     width = 60,  justify = "RIGHT" },
        { label = "Rate",       width = 48,  justify = "RIGHT" },
        { label = "Sold/day",   width = 58,  justify = "RIGHT" },
    }
end

function IT:DealRows(model)
    local rows = {}
    for _, row in pairs(model.items) do
        -- Only real opportunities: cheaper here than region-wide, and it sells.
        if row.dealPct and row.dealPct >= 0.10
            and (row.saleRate or 0) >= 0.05
            and row.latest.l and row.latest.l > 0 then
            row._dealScore = row.dealPct * (row.saleRate or 0)
            rows[#rows + 1] = row
        end
    end
    table.sort(rows, function(a, b) return a._dealScore > b._dealScore end)

    local out = {}
    for _, row in ipairs(rows) do
        out[#out + 1] = {
            itemID = row.itemID,
            icon   = itemIcon(row.itemID),
            cells  = {
                itemName(row),
                U.MoneyShort(row.latest.l),
                U.MoneyShort(row.regionAvg),
                string.format("|cff40c040+%.0f%%|r", row.dealPct * 100),
                string.format("%.0f%%", (row.saleRate or 0) * 100),
                string.format("%.1f", row.soldPerDay or 0),
            },
        }
    end
    return out
end

-- Flat views across every item.
function IT:FlatRows(model, mode)
    local rows = {}
    for _, row in pairs(model.items) do rows[#rows + 1] = row end

    if mode == "trend" then
        table.sort(rows, function(a, b)
            local am = a.trend and math.abs(a.trend.pricePct) or -1
            local bm = b.trend and math.abs(b.trend.pricePct) or -1
            return am > bm
        end)
        local out = {}
        for _, row in ipairs(rows) do out[#out + 1] = makeEntry(row, trendCells) end
        return out
    else -- opportunity
        table.sort(rows, function(a, b)
            local ao, bo = a.opportunity, b.opportunity
            if ao and bo and ao ~= bo then return ao > bo end
            if ao and not bo then return true end
            if bo and not ao then return false end
            return (a.marketValue or 0) > (b.marketValue or 0)
        end)
        local out = {}
        for _, row in ipairs(rows) do out[#out + 1] = makeEntry(row, itemCells) end
        return out
    end
end