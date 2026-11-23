-- Typed equipped-item rows for Armory item pages ("who wears this").
--
-- Item pages need, for one item, the characters wearing it and in which slot.
-- Reading every character's equipment JSON for that is far too large (about
-- 1.5 million slots for Retail), so pages read these two tables instead:
--
--   character_armory_equipment_chars  each character's newest snapshot that has
--                                     an equipment response (one row per character)
--   character_armory_equipped         one row per equipped slot of that snapshot
--
-- A trigger keeps both current for every snapshot insert or update.
-- Idempotent; run on local and live PostgreSQL. The final block backfills.

SET search_path = marketlens_import, public;

CREATE TABLE IF NOT EXISTS marketlens_import.character_armory_equipment_chars (
  source_game text NOT NULL, region text NOT NULL, character_id bigint NOT NULL,
  snapshot_at timestamptz NOT NULL,
  PRIMARY KEY (source_game, region, character_id));

CREATE TABLE IF NOT EXISTS marketlens_import.character_armory_equipped (
  source_game text NOT NULL, region text NOT NULL, character_id bigint NOT NULL,
  slot text NOT NULL, item_id integer NOT NULL,
  PRIMARY KEY (source_game, region, character_id, slot));

CREATE INDEX IF NOT EXISTS character_armory_equipped_item
  ON marketlens_import.character_armory_equipped (source_game, item_id);

-- The equipped slots of one equipment response; a repeated slot keeps its last item.
CREATE OR REPLACE FUNCTION marketlens_import.armory_equipped_rows(q jsonb)
RETURNS TABLE (slot text, item_id integer)
LANGUAGE sql IMMUTABLE AS $$
  SELECT DISTINCT ON (s) s, i FROM (
    SELECT x #>> '{slot,type}' s, (x #>> '{item,id}')::int i, o
    FROM jsonb_array_elements(CASE WHEN jsonb_typeof(q -> 'equipped_items') = 'array' THEN q -> 'equipped_items' ELSE '[]'::jsonb END)
      WITH ORDINALITY e(x, o)
    WHERE COALESCE(x #>> '{slot,type}', '') <> '' AND (x #>> '{item,id}') ~ '^[0-9]{1,9}$' AND (x #>> '{item,id}')::int > 0) r
  ORDER BY s, o DESC
$$;

CREATE OR REPLACE FUNCTION marketlens_import.character_armory_equipped_trg() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE current_at timestamptz;
BEGIN
  IF NEW.equipment_json IS NULL OR jsonb_typeof(NEW.equipment_json) <> 'object' THEN RETURN NEW; END IF;
  SELECT snapshot_at INTO current_at FROM marketlens_import.character_armory_equipment_chars
    WHERE source_game = NEW.source_game AND region = NEW.region AND character_id = NEW.character_id
    FOR UPDATE;
  IF current_at IS NOT NULL AND current_at > NEW.snapshot_at THEN RETURN NEW; END IF;
  INSERT INTO marketlens_import.character_armory_equipment_chars (source_game, region, character_id, snapshot_at)
  VALUES (NEW.source_game, NEW.region, NEW.character_id, NEW.snapshot_at)
  ON CONFLICT (source_game, region, character_id) DO UPDATE SET snapshot_at = EXCLUDED.snapshot_at;
  DELETE FROM marketlens_import.character_armory_equipped
    WHERE source_game = NEW.source_game AND region = NEW.region AND character_id = NEW.character_id;
  INSERT INTO marketlens_import.character_armory_equipped (source_game, region, character_id, slot, item_id)
  SELECT NEW.source_game, NEW.region, NEW.character_id, r.slot, r.item_id
  FROM marketlens_import.armory_equipped_rows(NEW.equipment_json) r;
  RETURN NEW;
END $$;

DROP TRIGGER IF EXISTS character_armory_equipped ON marketlens_import.character_armory_snapshots;
CREATE TRIGGER character_armory_equipped
  AFTER INSERT OR UPDATE OF equipment_json ON marketlens_import.character_armory_snapshots
  FOR EACH ROW EXECUTE FUNCTION marketlens_import.character_armory_equipped_trg();

-- Backfill: each character's newest snapshot with an equipment response, read once by key.
BEGIN;
LOCK TABLE marketlens_import.character_armory_equipment_chars, marketlens_import.character_armory_equipped IN EXCLUSIVE MODE;
TRUNCATE marketlens_import.character_armory_equipment_chars, marketlens_import.character_armory_equipped;
INSERT INTO marketlens_import.character_armory_equipment_chars (source_game, region, character_id, snapshot_at)
SELECT source_game, region, character_id, max(snapshot_at)
FROM marketlens_import.character_armory_snapshots
WHERE equipment_json IS NOT NULL AND jsonb_typeof(equipment_json) = 'object'
GROUP BY source_game, region, character_id;
INSERT INTO marketlens_import.character_armory_equipped (source_game, region, character_id, slot, item_id)
SELECT c.source_game, c.region, c.character_id, r.slot, r.item_id
FROM marketlens_import.character_armory_equipment_chars c
JOIN marketlens_import.character_armory_snapshots s
  ON s.source_game = c.source_game AND s.region = c.region AND s.character_id = c.character_id AND s.snapshot_at = c.snapshot_at
CROSS JOIN LATERAL marketlens_import.armory_equipped_rows(s.equipment_json) r;
COMMIT;

ANALYZE marketlens_import.character_armory_equipment_chars;
ANALYZE marketlens_import.character_armory_equipped;
