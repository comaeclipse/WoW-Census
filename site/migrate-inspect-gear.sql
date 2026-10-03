-- Equipped gear captured alongside inspected talents (NameplateInspect 3.1.0,
-- Forever first): a JSON array of {slot,id,name,quality,enchant,link}.
-- wrangler d1 execute marketlens --remote --file=migrate-inspect-gear.sql
ALTER TABLE character_inspects ADD COLUMN gear_json TEXT;
