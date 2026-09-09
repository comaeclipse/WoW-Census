
local ML = MarketLens
local D = ML.Data

-- Item SOURCE is a second classification axis, orthogonal to the
-- profession/sector/market buckets in Categories.lua. Those answer "what
-- market does this item trade in?"; source answers "where does supply come
-- from?" -- which is what determines whether a seller can *make more* of it.
--
-- The client's item API (GetItemInfoInstant) exposes class/subclass but has no
-- notion of source: it cannot tell a crafted plate helm from a dropped one, nor
-- which profession's recipe produces it. That relationship lives in Blizzard's
-- spell data (a crafting spell's CREATE_ITEM effect -> itemID, and the recipe's
-- SkillLine -> profession). We fold that knowledge in here as a static table.
--
-- Normalized source types. Kept small and stable so the site and analytics can
-- group on them; extend deliberately.
D.Source = {
    CRAFTED    = "crafted",     -- produced by a profession recipe (incl. smelting, transmute)
    DISENCHANT = "disenchant",  -- Enchanting mats pulled from gear (dust/essence/shard)
    GATHERED   = "gathered",    -- Mining/Herbalism/Skinning/Fishing node output
    DROP       = "drop",        -- world/dungeon/raid mob or container drop
    VENDOR     = "vendor",      -- sold by an NPC vendor
    QUEST      = "quest",       -- quest reward
    REPUTATION = "reputation",  -- reputation quartermaster
    EVENT      = "event",       -- holiday / world event
    OTHER      = "other",
    UNKNOWN    = "unknown",
}

-- Professions capable of *producing* an item, for the crafter attribution below.
-- (A subset of Data.Professions -- gathering professions produce GATHERED, not
-- CRAFTED, so they are not crafters.)

-- itemID -> { source = D.Source.*, profession = <crafter or gatherer>, spellID = <recipe> }
--
-- profession is the profession that yields the item: the crafter for CRAFTED,
-- the gathering profession for GATHERED, Enchanting for DISENCHANT. spellID is
-- the producing recipe when known (optional; enables recipe-level analytics).
--
-- This seed is intentionally limited to IDs already validated elsewhere in the
-- addon (Data/Items.lua overrides) so it ships correct on day one. The generator
-- in tools/build-item-sources.js is meant to expand it to full coverage -- treat
-- hand edits here as high-confidence pins, the same convention as D.Overrides.
D.ItemSources = {
    -- Gathered raw materials (node output; the gatherer, not a crafter) --------
    [23424] = { source = D.Source.GATHERED, profession = "Mining" },     -- Fel Iron Ore
    [23425] = { source = D.Source.GATHERED, profession = "Mining" },     -- Adamantite Ore
    [23426] = { source = D.Source.GATHERED, profession = "Mining" },     -- Khorium Ore
    [22785] = { source = D.Source.GATHERED, profession = "Herbalism" },  -- Felweed
    [22786] = { source = D.Source.GATHERED, profession = "Herbalism" },  -- Ragveil
    [22789] = { source = D.Source.GATHERED, profession = "Herbalism" },  -- Terocone
    [22790] = { source = D.Source.GATHERED, profession = "Herbalism" },  -- Ancient Lichen
    [22791] = { source = D.Source.GATHERED, profession = "Herbalism" },  -- Dreaming Glory
    [22792] = { source = D.Source.GATHERED, profession = "Herbalism" },  -- Netherbloom
    [22793] = { source = D.Source.GATHERED, profession = "Herbalism" },  -- Nightmare Vine
    [22794] = { source = D.Source.GATHERED, profession = "Herbalism" },  -- Mana Thistle
    [21877] = { source = D.Source.GATHERED, profession = "Cloth" },      -- Netherweave Cloth (mob drop, tracked as Cloth supply)
    [21887] = { source = D.Source.GATHERED, profession = "Skinning" },   -- Knothide Leather
    [23793] = { source = D.Source.GATHERED, profession = "Skinning" },   -- Heavy Knothide Leather

    -- Crafted: smelted bars (Mining recipe) -----------------------------------
    [23445] = { source = D.Source.CRAFTED, profession = "Mining", spellID = 29356 }, -- Fel Iron Bar
    [23446] = { source = D.Source.CRAFTED, profession = "Mining", spellID = 29358 }, -- Adamantite Bar

    -- Crafted: Alchemy consumables --------------------------------------------
    [22829] = { source = D.Source.CRAFTED, profession = "Alchemy" }, -- Super Healing Potion
    [22832] = { source = D.Source.CRAFTED, profession = "Alchemy" }, -- Super Mana Potion
    [22838] = { source = D.Source.CRAFTED, profession = "Alchemy" }, -- Haste Potion
    [22839] = { source = D.Source.CRAFTED, profession = "Alchemy" }, -- Destruction Potion
    [22841] = { source = D.Source.CRAFTED, profession = "Alchemy" }, -- Major Dreamless Sleep Potion
    [22851] = { source = D.Source.CRAFTED, profession = "Alchemy" }, -- Flask of Fortification
    [22853] = { source = D.Source.CRAFTED, profession = "Alchemy" }, -- Flask of Mighty Restoration
    [22854] = { source = D.Source.CRAFTED, profession = "Alchemy" }, -- Flask of Relentless Assault
    [22861] = { source = D.Source.CRAFTED, profession = "Alchemy" }, -- Flask of Blinding Light
    [22866] = { source = D.Source.CRAFTED, profession = "Alchemy" }, -- Flask of Pure Death

    -- Crafted: Tailoring ------------------------------------------------------
    [21841] = { source = D.Source.CRAFTED, profession = "Tailoring" }, -- Netherweave Bag

    -- Disenchant products (Enchanting supply pulled from gear) -----------------
    [22445] = { source = D.Source.DISENCHANT, profession = "Enchanting" }, -- Arcane Dust
    [22446] = { source = D.Source.DISENCHANT, profession = "Enchanting" }, -- Greater Planar Essence
    [22447] = { source = D.Source.DISENCHANT, profession = "Enchanting" }, -- Lesser Planar Essence
    [22448] = { source = D.Source.DISENCHANT, profession = "Enchanting" }, -- Small Prismatic Shard
    [22449] = { source = D.Source.DISENCHANT, profession = "Enchanting" }, -- Large Prismatic Shard
    [22450] = { source = D.Source.DISENCHANT, profession = "Enchanting" }, -- Void Crystal
}

-- Public: returns the raw source record for an item, or nil if unknown.
-- Hand-curated pins above take priority; the long tail of crafted items lives in
-- the generated D.ItemSourcesGenerated table (Data/ItemSourcesGen.lua, produced
-- by tools/build-item-sources.js from Blizzard DB2 data). The generated file is
-- optional -- the addon works with the curated map alone if it isn't present.
function D:ItemSource(itemID)
    if not itemID then return nil end
    return self.ItemSources[itemID]
        or (self.ItemSourcesGenerated and self.ItemSourcesGenerated[itemID])
        or nil
end

-- Human-readable label for a normalized source type (for UI / tooltips).
D.SourceLabels = {
    [D.Source.CRAFTED]    = "Crafted",
    [D.Source.DISENCHANT] = "Disenchanted",
    [D.Source.GATHERED]   = "Gathered",
    [D.Source.DROP]       = "Drop",
    [D.Source.VENDOR]     = "Vendor",
    [D.Source.QUEST]      = "Quest",
    [D.Source.REPUTATION] = "Reputation",
    [D.Source.EVENT]      = "World Event",
    [D.Source.OTHER]      = "Other",
    [D.Source.UNKNOWN]    = "Unknown",
}

function D:SourceLabel(source)
    return self.SourceLabels[source] or self.SourceLabels[D.Source.UNKNOWN]
end
