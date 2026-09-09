-- Injects MarketLens analytics into item tooltips (AH, bags, links) and powers
-- the dashboard's per-row hover.

local ML = MarketLens
local UI = ML.UI
local U = ML.Util
local Tip = {}
UI.Tooltips = Tip

local function itemIDFromLink(link)
    if not link then return nil end
    local id = link:match("item:(%d+)")
    return id and tonumber(id) or nil
end

-- Append MarketLens lines for an item to any tooltip. Safe if no data exists.
function Tip:Append(tooltip, itemID)
    if not itemID then return end
    local row = ML.Scores:Item(itemID)
    if not row then return end

    tooltip:AddLine(" ")
    -- Source tag: where this item's supply comes from, and (for crafted/gathered)
    -- the profession that produces it -- so a hover answers "can a seller make more?"
    local srcTag = ""
    local src = row.class.source
    if src then
        local label = ML.Data:SourceLabel(src)
        if row.class.crafter then label = label .. " \194\183 " .. row.class.crafter end
        srcTag = "  |cff70c070" .. label .. "|r"
    end
    tooltip:AddLine("|cff33aaffMarketLens|r  |cff888888(" .. (row.class.market or "?") .. ")|r" .. srcTag)

    local srcTag = row.demandSource == "region" and " |cff808080(region)|r"
                or row.demandSource == "local" and " |cff808080(local)|r" or ""
    local demand = row.demand and (UI.ScoreText(row.demand) .. " " .. (row.demandLabel or "") .. srcTag)
        or string.format("|cff888888collecting %d/%d|r", row.samples or 0,
            ML.db.settings.minimumSamples or 3)
    tooltip:AddDoubleLine("Demand", demand)
    tooltip:AddDoubleLine("Saturation", UI.ScoreText(row.saturation))
    if row.opportunity then
        tooltip:AddDoubleLine("Opportunity",
            (row.oppIcon or "") .. " " .. UI.ScoreText(row.opportunity))
    end

    -- TSM region cross-reference: real sale data + local-vs-region deal.
    if row.saleRate then
        tooltip:AddLine(" ")
        tooltip:AddDoubleLine("Sells (region)",
            string.format("%.0f%% |cff888888rate|r  \194\183  %.1f/day",
                (row.saleRate or 0) * 100, row.soldPerDay or 0))
        if row.regionAvg then
            tooltip:AddDoubleLine("Region avg sale", U.MoneyShort(row.regionAvg))
        end
        if row.dealPct then
            local hex = row.dealPct >= 0 and "ff40c040" or "ffc04040"
            tooltip:AddDoubleLine("Local vs region",
                string.format("|c%s%+.0f%%|r", hex, row.dealPct * 100))
            if row.isDeal then
                tooltip:AddLine("Buy-low opportunity: cheaper here than it sells region-wide.",
                    0.3, 0.9, 0.3, true)
            end
        end
    end

    tooltip:AddDoubleLine("Listed price", U.MoneyShort(row.latest.w))
    if ML.realm and ML.realm.ownersAvailable then
        tooltip:AddDoubleLine("Supply / sellers",
            string.format("%d |cff888888in|r %d", row.latest.q, row.latest.s))
    else
        tooltip:AddDoubleLine("Supply", string.format("%d", row.latest.q))
    end
    if row.trend then
        tooltip:AddDoubleLine("Supply / price (24h)",
            UI.PctText(row.trend.supplyPct) .. " |cff888888/|r " .. UI.PctText(row.trend.pricePct))
    end
    if ML.realm and ML.realm.ownersAvailable and row.competition.concentration >= 35 then
        tooltip:AddDoubleLine("Top seller share",
            string.format("|cffffcc00%d%%|r (%s)", row.competition.concentration,
                row.competition.level))
    end

    if row.saleRate and ML.Region.Meta then
        local m = ML.Region:Meta()
        if m then
            tooltip:AddLine(string.format("|cff707070Region data: TSM (%s) \194\183 %s|r",
                m.region or "?", ML.Region:Age() or "?"))
        end
    end
end

-- Dashboard row hover.
function Tip:ShowItemRow(row, itemID)
    local rec = ML.realm.items[itemID]
    GameTooltip:SetOwner(row, "ANCHOR_RIGHT")
    if rec and rec.link then
        GameTooltip:SetHyperlink(rec.link)
    else
        GameTooltip:SetText(rec and rec.name or ("item:" .. itemID))
        self:Append(GameTooltip, itemID)
    end
    GameTooltip:Show()
end

-- Dashboard market/profession row hover.
function Tip:ShowMarketRow(row, entry)
    local s = entry.summary
    GameTooltip:SetOwner(row, "ANCHOR_RIGHT")
    GameTooltip:AddLine(entry.label, 1, 0.82, 0)
    GameTooltip:AddLine(string.format("%d item%s tracked",
        s.count, s.count == 1 and "" or "s"), 0.7, 0.7, 0.7)
    GameTooltip:AddLine(" ")

    local function pair(k, v) GameTooltip:AddDoubleLine(k, v, 1, 1, 1, 1, 1, 1) end
    pair("Demand", s.demand and (tostring(s.demand) .. " / 100") or "collecting")
    pair("Saturation", tostring(s.saturation) .. " / 100")
    pair("24h price trend", string.format("%+.0f%%", (s.trendPct or 0) * 100))
    pair("Listed value", math.floor((s.value or 0) / 10000) .. "g")
    if s.opportunity then
        GameTooltip:AddDoubleLine("Opportunity", tostring(s.opportunity) .. " / 100",
            1, 0.82, 0, 1, 0.82, 0)
    end

    GameTooltip:AddLine(" ")
    if entry.level == 0 then
        GameTooltip:AddLine("Click to view this profession's markets.", 0.5, 0.7, 1, true)
    elseif entry.level == 1 then
        GameTooltip:AddLine("Click to view items in this market.", 0.5, 0.7, 1, true)
    end
    GameTooltip:AddLine("Demand is inferred from repeated snapshots, not observed sales.",
        0.6, 0.6, 0.6, true)
    GameTooltip:Show()
end

-- Live tooltip hook (AH, bags, chat links).
local function onTooltipSetItem(tooltip)
    if tooltip ~= GameTooltip then return end
    local _, link = tooltip:GetItem()
    local itemID = itemIDFromLink(link)
    if itemID then Tip:Append(tooltip, itemID) end
end

if TooltipDataProcessor and Enum and Enum.TooltipDataType then
    -- Newer tooltip API (forward-compatible).
    TooltipDataProcessor.AddTooltipPostCall(Enum.TooltipDataType.Item, function(tooltip)
        if tooltip == GameTooltip then onTooltipSetItem(tooltip) end
    end)
elseif GameTooltip.HookScript then
    -- Classic TBC path.
    GameTooltip:HookScript("OnTooltipSetItem", onTooltipSetItem)
end
