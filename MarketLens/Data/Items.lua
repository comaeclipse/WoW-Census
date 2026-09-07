
local ML = MarketLens
local D = ML.Data

-- Curated overrides. These pin economically important items to the exact
-- market we want them screened under, regardless of Blizzard's subclass.
-- IDs are high-confidence TBC 2.5.x values; extend freely.
D.Overrides = {
    [23424] = { profession = "Mining", sector = "Raw Materials", market = "Outland Ore" }, -- Fel Iron Ore
    [23425] = { profession = "Mining", sector = "Raw Materials", market = "Outland Ore" }, -- Adamantite Ore
    [23426] = { profession = "Mining", sector = "Raw Materials", market = "Outland Ore" }, -- Khorium Ore
    [23445] = { profession = "Mining", sector = "Raw Materials", market = "Outland Bars" }, -- Fel Iron Bar
    [23446] = { profession = "Mining", sector = "Raw Materials", market = "Outland Bars" }, -- Adamantite Bar

    [22785] = { profession = "Herbalism", sector = "Raw Materials", market = "Outland Herbs" }, -- Felweed
    [22786] = { profession = "Herbalism", sector = "Raw Materials", market = "Outland Herbs" }, -- Ragveil
    [22789] = { profession = "Herbalism", sector = "Raw Materials", market = "Outland Herbs" }, -- Terocone
    [22790] = { profession = "Herbalism", sector = "Raw Materials", market = "Outland Herbs" }, -- Ancient Lichen
    [22791] = { profession = "Herbalism", sector = "Raw Materials", market = "Outland Herbs" }, -- Dreaming Glory
    [22792] = { profession = "Herbalism", sector = "Raw Materials", market = "Outland Herbs" }, -- Netherbloom
    [22793] = { profession = "Herbalism", sector = "Raw Materials", market = "Outland Herbs" }, -- Nightmare Vine
    [22794] = { profession = "Herbalism", sector = "Raw Materials", market = "Outland Herbs" }, -- Mana Thistle

    [21877] = { profession = "Cloth", sector = "Raw Materials", market = "Netherweave" }, -- Netherweave Cloth

    [21887] = { profession = "Skinning", sector = "Raw Materials", market = "Knothide" }, -- Knothide Leather
    [23793] = { profession = "Skinning", sector = "Raw Materials", market = "Knothide" }, -- Heavy Knothide Leather

    [21884] = { profession = "Jewelcrafting", sector = "Raw Materials", market = "Primals" }, -- Primal Fire
    [21885] = { profession = "Jewelcrafting", sector = "Raw Materials", market = "Primals" }, -- Primal Water
    [21886] = { profession = "Jewelcrafting", sector = "Raw Materials", market = "Primals" }, -- Primal Life
    [22451] = { profession = "Jewelcrafting", sector = "Raw Materials", market = "Primals" }, -- Primal Air
    [22452] = { profession = "Jewelcrafting", sector = "Raw Materials", market = "Primals" }, -- Primal Earth
    [22456] = { profession = "Jewelcrafting", sector = "Raw Materials", market = "Primals" }, -- Primal Shadow
    [22457] = { profession = "Jewelcrafting", sector = "Raw Materials", market = "Primals" }, -- Primal Mana

    [22445] = { profession = "Enchanting", sector = "Crafting", market = "Dust & Essence" }, -- Arcane Dust
    [22446] = { profession = "Enchanting", sector = "Crafting", market = "Dust & Essence" }, -- Greater Planar Essence
    [22447] = { profession = "Enchanting", sector = "Crafting", market = "Dust & Essence" }, -- Lesser Planar Essence
    [22448] = { profession = "Enchanting", sector = "Crafting", market = "Shards & Crystals" }, -- Small Prismatic Shard
    [22449] = { profession = "Enchanting", sector = "Crafting", market = "Shards & Crystals" }, -- Large Prismatic Shard
    [22450] = { profession = "Enchanting", sector = "Crafting", market = "Shards & Crystals" }, -- Void Crystal

    [22829] = { profession = "Alchemy", sector = "Consumables", market = "Potions" }, -- Super Healing Potion
    [22832] = { profession = "Alchemy", sector = "Consumables", market = "Potions" }, -- Super Mana Potion
    [22838] = { profession = "Alchemy", sector = "Consumables", market = "Potions" }, -- Haste Potion
    [22839] = { profession = "Alchemy", sector = "Consumables", market = "Potions" }, -- Destruction Potion
    [22841] = { profession = "Alchemy", sector = "Consumables", market = "Potions" }, -- Major Dreamless Sleep Potion

    [22851] = { profession = "Alchemy", sector = "Consumables", market = "Flasks" }, -- Flask of Fortification
    [22853] = { profession = "Alchemy", sector = "Consumables", market = "Flasks" }, -- Flask of Mighty Restoration
    [22854] = { profession = "Alchemy", sector = "Consumables", market = "Flasks" }, -- Flask of Relentless Assault
    [22861] = { profession = "Alchemy", sector = "Consumables", market = "Flasks" }, -- Flask of Blinding Light
    [22866] = { profession = "Alchemy", sector = "Consumables", market = "Flasks" }, -- Flask of Pure Death

    [21841] = { profession = "Tailoring", sector = "Crafting", market = "Bags" }, -- Netherweave Bag
}

-- Resolve GetItemInfoInstant across possible namespaces.
local function classOf(itemID)
    if C_Item and C_Item.GetItemInfoInstant then
        local _, _, _, _, _, classID, subClassID = C_Item.GetItemInfoInstant(itemID)
        return classID, subClassID
    elseif GetItemInfoInstant then
        local _, _, _, _, _, classID, subClassID = GetItemInfoInstant(itemID)
        return classID, subClassID
    end
    return nil, nil
end

-- Per-session classification cache to avoid repeated API calls during a scan.
D.classifyCache = D.classifyCache or {}

-- Public: returns { profession=, sector=, market= }. Never nil (falls back to
-- an "Unknown / Other" bucket) so every scanned item lands somewhere.
function D:Classify(itemID)
    if not itemID then
        return { profession = "Unknown", sector = "Other", market = "Uncategorized" }
    end
    local cached = self.classifyCache[itemID]
    if cached then return cached end

    local result = self.Overrides[itemID]
    local classID, subClassID
    if not result then
        classID, subClassID = classOf(itemID)
        if classID then
            result = self:ClassifyByClass(classID, subClassID)
        end
    end

    if result then
        -- Confident classification (override or real class): cache it.
        self.classifyCache[itemID] = result
        return result
    end

    -- classID was nil (item data not cached yet) -> return a placeholder but do
    -- NOT cache, so it reclassifies once the client loads the item's info.
    return { profession = "Unknown", sector = "Other", market = "Uncategorized" }
end
