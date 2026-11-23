-- Typed profession rows for the profession census pages.
--
-- character_armory_snapshots keeps Blizzard's professions response verbatim:
-- every known recipe with its name and links, about 1 GB for 86k Retail
-- characters. The profession census needs five numbers per trade, so pages
-- read these two tables instead and never touch professions_json:
--
--   character_armory_profession_chars  each character's newest snapshot that
--                                      has a professions response (one row per
--                                      character, including those with no trade)
--   character_armory_professions       one row per trade of that snapshot
--
-- A trigger keeps both current for every snapshot insert or update.
-- Idempotent; run on local and live PostgreSQL. The final block backfills.

SET search_path = marketlens_import, public;

CREATE TABLE IF NOT EXISTS marketlens_import.character_armory_profession_chars (
  source_game text NOT NULL, region text NOT NULL, character_id bigint NOT NULL,
  snapshot_at timestamptz NOT NULL,
  PRIMARY KEY (source_game, region, character_id));

CREATE TABLE IF NOT EXISTS marketlens_import.character_armory_professions (
  source_game text NOT NULL, region text NOT NULL, character_id bigint NOT NULL,
  kind text NOT NULL CHECK (kind IN ('primary', 'secondary')),
  profession text NOT NULL,
  skill integer NOT NULL, max_skill integer NOT NULL, known_recipes integer NOT NULL,
  PRIMARY KEY (source_game, region, character_id, kind, profession));

CREATE INDEX IF NOT EXISTS character_armory_professions_trade
  ON marketlens_import.character_armory_professions (source_game, kind, profession);

-- The trades of one professions response, one row per kind and name.
CREATE OR REPLACE FUNCTION marketlens_import.armory_profession_rows(p jsonb)
RETURNS TABLE (kind text, profession text, skill integer, max_skill integer, known_recipes integer)
LANGUAGE sql IMMUTABLE AS $$
  WITH trades AS (
    SELECT k.kind, k.o * 1000 + e.o AS o, e.x
    FROM (VALUES ('primaries', 'primary', 1), ('secondaries', 'secondary', 2)) k(field, kind, o),
         jsonb_array_elements(CASE WHEN jsonb_typeof(p -> k.field) = 'array' THEN p -> k.field ELSE '[]'::jsonb END)
           WITH ORDINALITY e(x, o)),
  named AS (
    SELECT t.kind, t.o, t.x,
           btrim(COALESCE(t.x #>> '{profession,name}', t.x #>> '{tiers,0,tier,name}', '')) AS profession
    FROM trades t),
  scored AS (
    SELECT n.kind, n.profession,
           COALESCE((SELECT max(CASE WHEN (r ->> 'skill_points') ~ '^-?[0-9]+$' THEN (r ->> 'skill_points')::int END)
                     FROM jsonb_array_elements(CASE WHEN jsonb_typeof(n.x -> 'tiers') = 'array' THEN n.x -> 'tiers' ELSE '[]'::jsonb END) r), 0) AS skill,
           COALESCE((SELECT max(CASE WHEN (r ->> 'max_skill_points') ~ '^-?[0-9]+$' THEN (r ->> 'max_skill_points')::int END)
                     FROM jsonb_array_elements(CASE WHEN jsonb_typeof(n.x -> 'tiers') = 'array' THEN n.x -> 'tiers' ELSE '[]'::jsonb END) r), 0) AS max_skill,
           COALESCE((SELECT sum(CASE WHEN jsonb_typeof(r -> 'known_recipes') = 'array' THEN jsonb_array_length(r -> 'known_recipes') ELSE 0 END)
                     FROM jsonb_array_elements(CASE WHEN jsonb_typeof(n.x -> 'tiers') = 'array' THEN n.x -> 'tiers' ELSE '[]'::jsonb END) r), 0)::int AS known_recipes
    FROM named n WHERE n.profession <> '')
  SELECT kind, profession, max(skill), max(max_skill), sum(known_recipes)::int
  FROM scored GROUP BY kind, profession
$$;

-- Replace a character's rows when a snapshot at least as new as the stored one arrives.
CREATE OR REPLACE FUNCTION marketlens_import.character_armory_professions_trg() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE current_at timestamptz;
BEGIN
  IF NEW.professions_json IS NULL OR jsonb_typeof(NEW.professions_json) <> 'object' THEN RETURN NEW; END IF;
  SELECT snapshot_at INTO current_at FROM marketlens_import.character_armory_profession_chars
    WHERE source_game = NEW.source_game AND region = NEW.region AND character_id = NEW.character_id
    FOR UPDATE;
  IF current_at IS NOT NULL AND current_at > NEW.snapshot_at THEN RETURN NEW; END IF;
  INSERT INTO marketlens_import.character_armory_profession_chars (source_game, region, character_id, snapshot_at)
  VALUES (NEW.source_game, NEW.region, NEW.character_id, NEW.snapshot_at)
  ON CONFLICT (source_game, region, character_id) DO UPDATE SET snapshot_at = EXCLUDED.snapshot_at;
  DELETE FROM marketlens_import.character_armory_professions
    WHERE source_game = NEW.source_game AND region = NEW.region AND character_id = NEW.character_id;
  INSERT INTO marketlens_import.character_armory_professions
    (source_game, region, character_id, kind, profession, skill, max_skill, known_recipes)
  SELECT NEW.source_game, NEW.region, NEW.character_id, r.kind, r.profession, r.skill, r.max_skill, r.known_recipes
  FROM marketlens_import.armory_profession_rows(NEW.professions_json) r;
  RETURN NEW;
END $$;

DROP TRIGGER IF EXISTS character_armory_professions ON marketlens_import.character_armory_snapshots;
CREATE TRIGGER character_armory_professions
  AFTER INSERT OR UPDATE OF professions_json ON marketlens_import.character_armory_snapshots
  FOR EACH ROW EXECUTE FUNCTION marketlens_import.character_armory_professions_trg();

-- Backfill: each character's newest snapshot with a professions response.
-- The newest keys are found without reading the JSON, then each response is read once by key.
BEGIN;
LOCK TABLE marketlens_import.character_armory_profession_chars, marketlens_import.character_armory_professions IN EXCLUSIVE MODE;
TRUNCATE marketlens_import.character_armory_profession_chars, marketlens_import.character_armory_professions;
INSERT INTO marketlens_import.character_armory_profession_chars (source_game, region, character_id, snapshot_at)
SELECT source_game, region, character_id, max(snapshot_at)
FROM marketlens_import.character_armory_snapshots
WHERE professions_json IS NOT NULL AND jsonb_typeof(professions_json) = 'object'
GROUP BY source_game, region, character_id;
INSERT INTO marketlens_import.character_armory_professions
  (source_game, region, character_id, kind, profession, skill, max_skill, known_recipes)
SELECT c.source_game, c.region, c.character_id, r.kind, r.profession, r.skill, r.max_skill, r.known_recipes
FROM marketlens_import.character_armory_profession_chars c
JOIN marketlens_import.character_armory_snapshots s
  ON s.source_game = c.source_game AND s.region = c.region AND s.character_id = c.character_id AND s.snapshot_at = c.snapshot_at
CROSS JOIN LATERAL marketlens_import.armory_profession_rows(s.professions_json) r;
COMMIT;

ANALYZE marketlens_import.character_armory_profession_chars;
ANALYZE marketlens_import.character_armory_professions;
