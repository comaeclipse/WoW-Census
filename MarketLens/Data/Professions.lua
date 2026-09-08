
local ML = MarketLens
local D = ML.Data

-- Top-level economic sectors (display order in the dashboard).
D.Sectors = {
    "Raw Materials",
    "Crafting",
    "Consumables",
    "Gear",
    "Recipes",
    "Class Supplies", -- class spell reagents (seeds, candles, runes, Ankh, ...)
    "Other",
}

-- Order here is the default dashboard order.
D.Professions = {
    { key = "Alchemy",        sector = "Crafting"      },
    { key = "Enchanting",     sector = "Crafting"      },
    { key = "Jewelcrafting",  sector = "Crafting"      },
    { key = "Blacksmithing",  sector = "Crafting"      },
    { key = "Leatherworking", sector = "Crafting"      },
    { key = "Tailoring",      sector = "Crafting"      },
    { key = "Engineering",    sector = "Crafting"      },
    { key = "Inscription",    sector = "Crafting"      }, -- unused in TBC, kept for portability
    { key = "Mining",         sector = "Raw Materials" },
    { key = "Herbalism",      sector = "Raw Materials" },
    { key = "Skinning",       sector = "Raw Materials" },
    { key = "Cloth",          sector = "Raw Materials" },
    { key = "Cooking",        sector = "Consumables"   },
    { key = "Gear",           sector = "Gear"          },
    { key = "Class Reagents", sector = "Class Supplies"},
    { key = "Unknown",        sector = "Other"         },
}

D.ProfessionByKey = {}
for i, p in ipairs(D.Professions) do
    p.order = i
    D.ProfessionByKey[p.key] = p
end

function D:ProfessionOrder(key)
    local p = self.ProfessionByKey[key]
    return p and p.order or 999
end

-- Representative WoW icons so market/profession rows have a visual anchor,
-- matching Blizzard's icon-forward Auction House rows.
local ICON = "Interface\\Icons\\"
D.ProfessionIcons = {
    Alchemy        = ICON .. "Trade_Alchemy",
    Enchanting     = ICON .. "Trade_Engraving",
    Jewelcrafting  = ICON .. "INV_Misc_Gem_01",
    Blacksmithing  = ICON .. "Trade_BlackSmithing",
    Leatherworking = ICON .. "Trade_LeatherWorking",
    Tailoring      = ICON .. "Trade_Tailoring",
    Engineering    = ICON .. "Trade_Engineering",
    Inscription    = ICON .. "INV_Inscription_Tradeskill01",
    Mining         = ICON .. "Trade_Mining",
    Herbalism      = ICON .. "Trade_Heralism", -- Blizzard's actual (misspelled) icon file
    Skinning       = ICON .. "INV_Misc_Pelt_Wolf_01",
    Cloth          = ICON .. "INV_Fabric_Netherweave",
    Cooking        = ICON .. "INV_Misc_Food_15",
    Gear           = ICON .. "INV_Chest_Chain",
    ["Class Reagents"] = ICON .. "Spell_Nature_Reincarnation", -- Ankh/Rebirth reagent motif
    Unknown        = ICON .. "INV_Misc_QuestionMark",
}

function D:ProfessionIcon(key)
    return self.ProfessionIcons[key] or self.ProfessionIcons.Unknown
end
