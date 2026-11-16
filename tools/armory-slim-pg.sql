-- Slim copies of Armory snapshots for aggregate pages.
--
-- character_armory_snapshots keeps Blizzard's responses verbatim (an archive to
-- re-parse later). A Retail specializations response carries every spec, every
-- saved loadout and full tooltip text: ~350 KB per character. Aggregate pages only
-- need the active loadout's talent ids, ranks and names, and the equipped items'
-- slot, id, name, quality and whether they are enchanted.
--
-- character_armory_slim holds exactly that, in the same JSON shape as the
-- original (so the PHP parser is unchanged): one specialization (the active one,
-- else the first) with one loadout (the active one, else the first), and trimmed
-- talents and items. A trigger fills it for every snapshot insert or update.
--
-- Idempotent; run on local and live PostgreSQL. The final INSERT backfills.

SET search_path = marketlens_import, public;

CREATE TABLE IF NOT EXISTS marketlens_import.character_armory_slim (
  source_game text NOT NULL, region text NOT NULL, character_id bigint NOT NULL,
  snapshot_at timestamptz NOT NULL,
  specializations jsonb, equipment jsonb,
  PRIMARY KEY (source_game, region, character_id, snapshot_at));

CREATE OR REPLACE FUNCTION marketlens_import.armory_slim_talents(t jsonb) RETURNS jsonb
LANGUAGE sql IMMUTABLE AS $$
  SELECT COALESCE(jsonb_agg(jsonb_strip_nulls(jsonb_build_object(
      'id', x->'id', 'rank', x->'rank', 'default_points', x->'default_points',
      'tooltip', jsonb_build_object(
        'talent', jsonb_build_object('id', x#>'{tooltip,talent,id}', 'name', x#>'{tooltip,talent,name}'),
        'spell_tooltip', jsonb_build_object('spell', jsonb_build_object(
          'id', x#>'{tooltip,spell_tooltip,spell,id}', 'name', x#>'{tooltip,spell_tooltip,spell,name}')))))
    ORDER BY o), '[]'::jsonb)
  FROM jsonb_array_elements(CASE WHEN jsonb_typeof(t) = 'array' THEN t ELSE '[]'::jsonb END) WITH ORDINALITY e(x, o)
$$;

CREATE OR REPLACE FUNCTION marketlens_import.armory_slim_specializations(s jsonb) RETURNS jsonb
LANGUAGE sql IMMUTABLE AS $$
  WITH a AS (
    SELECT CASE WHEN (s#>>'{active_specialization,id}') ~ '^-?[0-9]+$'
             THEN (s#>>'{active_specialization,id}')::bigint ELSE 0 END active_id),
  spec AS (
    SELECT x FROM a, jsonb_array_elements(CASE WHEN jsonb_typeof(s->'specializations') = 'array'
                                               THEN s->'specializations' ELSE '[]'::jsonb END) WITH ORDINALITY e(x, o)
    ORDER BY (CASE WHEN (x#>>'{specialization,id}') ~ '^-?[0-9]+$'
                   THEN (x#>>'{specialization,id}')::bigint ELSE 0 END) = a.active_id DESC, o
    LIMIT 1),
  lo AS (
    SELECT l FROM spec, jsonb_array_elements(CASE WHEN jsonb_typeof(spec.x->'loadouts') = 'array'
                                                  THEN spec.x->'loadouts' ELSE '[]'::jsonb END) WITH ORDINALITY e(l, o)
    ORDER BY COALESCE((l->'is_active') = 'true'::jsonb, false) DESC, o
    LIMIT 1)
  SELECT CASE WHEN s IS NULL OR jsonb_typeof(s) <> 'object' THEN NULL ELSE jsonb_strip_nulls(jsonb_build_object(
    'active_specialization', jsonb_build_object('id', s#>'{active_specialization,id}', 'name', s#>'{active_specialization,name}'),
    'specializations', COALESCE((SELECT jsonb_build_array(jsonb_build_object(
        'specialization', jsonb_build_object('id', spec.x#>'{specialization,id}'),
        'loadouts', COALESCE((SELECT jsonb_build_array(jsonb_build_object(
            'is_active', lo.l->'is_active',
            'selected_hero_talent_tree', jsonb_build_object('name', lo.l#>'{selected_hero_talent_tree,name}'),
            'selected_class_talents', marketlens_import.armory_slim_talents(lo.l->'selected_class_talents'),
            'selected_spec_talents', marketlens_import.armory_slim_talents(lo.l->'selected_spec_talents'),
            'selected_hero_talents', marketlens_import.armory_slim_talents(lo.l->'selected_hero_talents')))
          FROM lo), '[]'::jsonb)))
      FROM spec), '[]'::jsonb))) END
$$;

CREATE OR REPLACE FUNCTION marketlens_import.armory_slim_equipment(q jsonb) RETURNS jsonb
LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE WHEN q IS NULL OR jsonb_typeof(q) <> 'object' THEN NULL ELSE jsonb_build_object('equipped_items',
    COALESCE((SELECT jsonb_agg(jsonb_strip_nulls(jsonb_build_object(
        'slot', jsonb_build_object('type', x#>'{slot,type}'),
        'item', jsonb_build_object('id', x#>'{item,id}'),
        'name', x->'name',
        'quality', jsonb_build_object('type', x#>'{quality,type}'),
        'enchantments', CASE WHEN x->'enchantments' IS NULL
                               OR x->'enchantments' IN ('[]'::jsonb, '{}'::jsonb, 'null'::jsonb, 'false'::jsonb, '0'::jsonb, '""'::jsonb)
                             THEN NULL ELSE 'true'::jsonb END)) ORDER BY o)
      FROM jsonb_array_elements(CASE WHEN jsonb_typeof(q->'equipped_items') = 'array'
                                     THEN q->'equipped_items' ELSE '[]'::jsonb END) WITH ORDINALITY e(x, o)), '[]'::jsonb)) END
$$;

CREATE OR REPLACE FUNCTION marketlens_import.character_armory_slim_trg() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO marketlens_import.character_armory_slim
    (source_game, region, character_id, snapshot_at, specializations, equipment)
  VALUES (NEW.source_game, NEW.region, NEW.character_id, NEW.snapshot_at,
          marketlens_import.armory_slim_specializations(NEW.specializations_json),
          marketlens_import.armory_slim_equipment(NEW.equipment_json))
  ON CONFLICT (source_game, region, character_id, snapshot_at) DO UPDATE
    SET specializations = EXCLUDED.specializations, equipment = EXCLUDED.equipment;
  RETURN NEW;
END $$;

DROP TRIGGER IF EXISTS character_armory_slim ON marketlens_import.character_armory_snapshots;
CREATE TRIGGER character_armory_slim
  AFTER INSERT OR UPDATE OF specializations_json, equipment_json ON marketlens_import.character_armory_snapshots
  FOR EACH ROW EXECUTE FUNCTION marketlens_import.character_armory_slim_trg();

-- Backfill snapshots that have no slim row yet.
INSERT INTO marketlens_import.character_armory_slim
  (source_game, region, character_id, snapshot_at, specializations, equipment)
SELECT s.source_game, s.region, s.character_id, s.snapshot_at,
       marketlens_import.armory_slim_specializations(s.specializations_json),
       marketlens_import.armory_slim_equipment(s.equipment_json)
FROM marketlens_import.character_armory_snapshots s
WHERE NOT EXISTS (SELECT 1 FROM marketlens_import.character_armory_slim x
  WHERE x.source_game = s.source_game AND x.region = s.region
    AND x.character_id = s.character_id AND x.snapshot_at = s.snapshot_at);

ANALYZE marketlens_import.character_armory_slim;
