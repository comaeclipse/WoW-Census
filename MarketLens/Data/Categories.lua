-- IMPORTANT: TBC 2.5.x subclass numbering is used below. If a subclass looks
-- misfiled on your client, tune it here rather than in the curated list.

local ML = MarketLens
local D = ML.Data

-- Retail (Mainline) reshuffled some subclass numbering vs the TBC 2.5.x values
-- used throughout this file; gate the divergent cases on this.
local IS_RETAIL = (WOW_PROJECT_ID ~= nil and WOW_PROJECT_MAINLINE ~= nil
                   and WOW_PROJECT_ID == WOW_PROJECT_MAINLINE) or false

local CLASS_CONSUMABLE = 0
local CLASS_CONTAINER  = 1
local CLASS_WEAPON     = 2
local CLASS_GEM        = 3
local CLASS_ARMOR      = 4
local CLASS_REAGENT    = 5
local CLASS_PROJECTILE = 6
local CLASS_TRADEGOODS = 7
local CLASS_RECIPE     = 9
local CLASS_QUIVER     = 11
local CLASS_QUEST      = 12
local CLASS_KEY        = 13
local CLASS_MISC       = 15
local CLASS_GLYPH      = 16  -- Retail only (Inscription output; absent in TBC)

-- Trade Goods subclasses (classID 7).
local TG = {
    PARTS      = 1,
    EXPLOSIVES = 2,
    DEVICES    = 3,
    JEWELCRAFT = 4,
    CLOTH      = 5,
    LEATHER    = 6,
    METAL      = 7,  -- Metal & Stone (ore, bars, stone)
    MEAT       = 8,
    HERB       = 9,
    ELEMENTAL  = 10, -- Primals / motes
    ENCHANTING = 12,
    MATERIALS  = 13,
}

-- Consumable subclasses (classID 0).
local CN = {
    POTION   = 1,
    ELIXIR   = 2,
    FLASK    = 3,
    SCROLL   = 4,
    FOOD     = 5,
    ENHANCE  = 6, -- weapon oils / sharpening stones
    BANDAGE  = 7,
}

-- Armor subclass 0 (Miscellaneous) is a grab-bag: rings, necklaces, trinkets,
-- shirts, tabards, and held off-hand items all share it. Subclass alone can't
-- tell them apart, so split by equip slot. (A novelty holdable like Simple
-- Wildflowers and a stat caster off-hand are both HOLDABLE, so both land in
-- "Off-Hand" -- the client class API doesn't expose the cosmetic/novelty flag.)
local function miscArmorMarket(equipLoc)
    if equipLoc == "INVTYPE_HOLDABLE" then return "Off-Hand"
    elseif equipLoc == "INVTYPE_BODY" or equipLoc == "INVTYPE_TABARD" then return "Cosmetic" end
    return "Armor" -- rings, necks, trinkets, or an unrecognized slot
end

-- Returns { profession=, sector=, market= } or nil. equipLoc (an INVTYPE_*
-- string) is optional and only consulted to disambiguate miscellaneous armor.
function D:ClassifyByClass(classID, subClassID, equipLoc)
    if classID == CLASS_TRADEGOODS then
        if subClassID == TG.METAL then
            return { profession = "Mining", sector = "Raw Materials", market = "Ore & Bars" }
        elseif subClassID == TG.HERB then
            return { profession = "Herbalism", sector = "Raw Materials", market = "Herbs" }
        elseif subClassID == TG.LEATHER then
            return { profession = "Skinning", sector = "Raw Materials", market = "Leather & Hides" }
        elseif subClassID == TG.CLOTH then
            return { profession = "Cloth", sector = "Raw Materials", market = "Cloth" }
        elseif subClassID == TG.ELEMENTAL then
            return { profession = "Jewelcrafting", sector = "Raw Materials", market = "Primals & Motes" }
        elseif subClassID == TG.ENCHANTING then
            return { profession = "Enchanting", sector = "Crafting", market = "Enchanting Mats" }
        elseif subClassID == TG.JEWELCRAFT then
            return { profession = "Jewelcrafting", sector = "Crafting", market = "JC Supplies" }
        elseif subClassID == TG.PARTS or subClassID == TG.DEVICES or subClassID == TG.EXPLOSIVES then
            return { profession = "Engineering", sector = "Crafting", market = "Engineering Supplies" }
        elseif subClassID == TG.MEAT then
            return { profession = "Cooking", sector = "Consumables", market = "Cooking Ingredients" }
        else
            return { profession = "Unknown", sector = "Raw Materials", market = "Trade Goods" }
        end
    elseif classID == CLASS_CONSUMABLE then
        if subClassID == CN.POTION then
            return { profession = "Alchemy", sector = "Consumables", market = "Potions" }
        elseif subClassID == CN.ELIXIR then
            return { profession = "Alchemy", sector = "Consumables", market = "Elixirs" }
        elseif subClassID == CN.FLASK then
            return { profession = "Alchemy", sector = "Consumables", market = "Flasks" }
        elseif subClassID == CN.FOOD then
            return { profession = "Cooking", sector = "Consumables", market = "Food & Drink" }
        elseif subClassID == CN.ENHANCE then
            return { profession = "Enchanting", sector = "Consumables", market = "Oils & Stones" }
        elseif subClassID == CN.SCROLL then
            return { profession = "Enchanting", sector = "Crafting", market = "Scrolls" }
        else
            return { profession = "Alchemy", sector = "Consumables", market = "Consumables" }
        end
    elseif classID == CLASS_GEM then
        return { profession = "Jewelcrafting", sector = "Crafting", market = "Gems" }
    elseif classID == CLASS_RECIPE then
        -- Recipe subclass -> the profession it teaches.
        local prof = ({
            [1] = "Leatherworking", [2] = "Tailoring", [3] = "Engineering",
            [4] = "Blacksmithing", [5] = "Cooking", [6] = "Alchemy",
            [8] = "Enchanting", [10] = "Jewelcrafting",
        })[subClassID] or "Unknown"
        return { profession = prof, sector = "Recipes", market = "Recipes" }
    elseif classID == CLASS_CONTAINER then
        return { profession = "Tailoring", sector = "Crafting", market = "Bags" }
    elseif classID == CLASS_QUIVER then
        return { profession = "Gear", sector = "Gear", market = "Quivers" }
    elseif classID == CLASS_PROJECTILE then
        return { profession = "Gear", sector = "Gear", market = "Ammo" }
    elseif classID == CLASS_REAGENT then
        return { profession = "Unknown", sector = "Raw Materials", market = "Reagents" }
    elseif classID == CLASS_WEAPON then
        return { profession = "Gear", sector = "Gear", market = "Weapons" }
    elseif classID == CLASS_ARMOR then
        -- Split armor: shields, relics, and body armor. Subclass numbering
        -- differs by client. TBC/Classic: 5=Shield, 6-9=Libram/Idol/Totem/Sigil.
        -- Retail inserted 5=Cosmetic, pushing Shield to 6 and relics to 7-11.
        if IS_RETAIL then
            if subClassID == 6 then
                return { profession = "Gear", sector = "Gear", market = "Shields" }
            elseif subClassID == 5 then
                return { profession = "Gear", sector = "Gear", market = "Cosmetic" }
            elseif subClassID and subClassID >= 7 then
                return { profession = "Gear", sector = "Gear", market = "Relics" }
            elseif subClassID == 0 then
                return { profession = "Gear", sector = "Gear", market = miscArmorMarket(equipLoc) }
            else
                return { profession = "Gear", sector = "Gear", market = "Armor" }
            end
        elseif subClassID == 5 then
            return { profession = "Gear", sector = "Gear", market = "Shields" }
        elseif subClassID and subClassID >= 6 then
            return { profession = "Gear", sector = "Gear", market = "Relics" }
        elseif subClassID == 0 then
            return { profession = "Gear", sector = "Gear", market = miscArmorMarket(equipLoc) }
        else
            return { profession = "Gear", sector = "Gear", market = "Armor" }
        end
    elseif classID == CLASS_GLYPH then
        return { profession = "Inscription", sector = "Crafting", market = "Glyphs" }
    elseif classID == CLASS_QUEST then
        return { profession = "Unknown", sector = "Other", market = "Quest Items" }
    elseif classID == CLASS_KEY then
        return { profession = "Unknown", sector = "Other", market = "Keys" }
    elseif classID == CLASS_MISC then
        if subClassID == 2 then
            return { profession = "Unknown", sector = "Other", market = "Companion Pets" }
        elseif subClassID == 5 then
            return { profession = "Unknown", sector = "Other", market = "Mounts" }
        elseif subClassID == 1 then
            return { profession = "Unknown", sector = "Raw Materials", market = "Reagents" }
        else
            return { profession = "Unknown", sector = "Other", market = "Miscellaneous" }
        end
    end
    return nil
end
