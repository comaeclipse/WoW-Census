-- Canonical (English) race and class names in marketlens_import.
--
-- /who results and UnitRace()/UnitClass() come back in the client's locale, so
-- a Spanish or German client stores "No-muerto", "Chamán", "Blutelfe",
-- "Krieger"... and the site showed them next to the English names. ForeverPulse's
-- Spanish Horde scope even produced class_file = 'CHAMÁN'.
--
-- This file is idempotent. It installs:
--   locale_names                 localized -> English name (race / class)
--   canonical_race(), canonical_class(), class_token()
--   triggers that canonicalize race/class/class_file on every INSERT/UPDATE of
--   the tables importers write, so local imports, the merge SQL files and
--   tools/import-live.py all store English names
-- and then rewrites the rows already stored. Rebuild the census cache after.
--
--   psql "<dsn>" -v ON_ERROR_STOP=1 -f tools/canonical-names-pg.sql

SET client_encoding = 'UTF8';
BEGIN;
SELECT pg_advisory_xact_lock(hashtext('marketlens-import'));
SET LOCAL search_path TO marketlens_import;

CREATE TABLE IF NOT EXISTS locale_names (
  kind text NOT NULL CHECK (kind IN ('race', 'class')),
  localized text NOT NULL,
  canonical text NOT NULL,
  PRIMARY KEY (kind, localized)
);
CREATE UNIQUE INDEX IF NOT EXISTS locale_names_lower ON locale_names (kind, lower(localized));

INSERT INTO locale_names (kind, localized, canonical) VALUES
  -- Spanish (esES / esMX), both genders
  ('race', 'Humano', 'Human'), ('race', 'Humana', 'Human'),
  ('race', 'Enano', 'Dwarf'), ('race', 'Enana', 'Dwarf'),
  ('race', 'Elfo de la noche', 'Night Elf'), ('race', 'Elfa de la noche', 'Night Elf'),
  ('race', 'Gnomo', 'Gnome'), ('race', 'Gnoma', 'Gnome'),
  ('race', 'Orco', 'Orc'),
  ('race', 'No-muerto', 'Undead'), ('race', 'No-muerta', 'Undead'),
  ('race', 'Trol', 'Troll'),
  ('race', 'Elfo de sangre', 'Blood Elf'), ('race', 'Elfa de sangre', 'Blood Elf'),
  ('race', 'Huargen', 'Worgen'),
  ('race', 'Elfo del Vacío', 'Void Elf'), ('race', 'Elfa del Vacío', 'Void Elf'),
  ('race', 'Cielonato Formavientos', 'Windshaper Skyborne'),
  ('race', 'Cielonata Formavientos', 'Windshaper Skyborne'),
  ('class', 'Guerrero', 'Warrior'), ('class', 'Guerrera', 'Warrior'),
  ('class', 'Paladín', 'Paladin'),
  ('class', 'Cazador', 'Hunter'), ('class', 'Cazadora', 'Hunter'),
  ('class', 'Pícaro', 'Rogue'), ('class', 'Pícara', 'Rogue'),
  ('class', 'Sacerdote', 'Priest'), ('class', 'Sacerdotisa', 'Priest'),
  ('class', 'Chamán', 'Shaman'),
  ('class', 'Mago', 'Mage'), ('class', 'Maga', 'Mage'),
  ('class', 'Brujo', 'Warlock'), ('class', 'Bruja', 'Warlock'),
  ('class', 'Druida', 'Druid'),
  ('class', 'Caballero de la Muerte', 'Death Knight'),
  ('class', 'Monje', 'Monk'), ('class', 'Monja', 'Monk'),
  ('class', 'Cazador de demonios', 'Demon Hunter'), ('class', 'Cazadora de demonios', 'Demon Hunter'),
  ('class', 'Evocador', 'Evoker'), ('class', 'Evocadora', 'Evoker'),
  -- German (deDE), both genders
  ('race', 'Mensch', 'Human'),
  ('race', 'Zwerg', 'Dwarf'), ('race', 'Zwergin', 'Dwarf'),
  ('race', 'Nachtelf', 'Night Elf'), ('race', 'Nachtelfe', 'Night Elf'),
  ('race', 'Gnom', 'Gnome'), ('race', 'Gnomin', 'Gnome'),
  ('race', 'Untoter', 'Undead'), ('race', 'Untote', 'Undead'),
  ('race', 'Blutelf', 'Blood Elf'), ('race', 'Blutelfe', 'Blood Elf'),
  ('race', 'Leerenelf', 'Void Elf'), ('race', 'Leerenelfe', 'Void Elf'),
  ('race', 'Lichtgeschmiedeter Draenei', 'Lightforged Draenei'), ('race', 'Lichtgeschmiedete Draenei', 'Lightforged Draenei'),
  ('race', 'Dunkeleisenzwerg', 'Dark Iron Dwarf'), ('race', 'Dunkeleisenzwergin', 'Dark Iron Dwarf'),
  ('race', 'Kul Tiraner', 'Kul Tiran'), ('race', 'Kul Tiranerin', 'Kul Tiran'),
  ('race', 'Mechagnom', 'Mechagnome'), ('race', 'Mechagnomin', 'Mechagnome'),
  ('race', 'Nachtgeborener', 'Nightborne'), ('race', 'Nachtgeborene', 'Nightborne'),
  ('race', 'Hochbergtauren', 'Highmountain Tauren'),
  ('race', 'Mag''har', 'Mag''har Orc'),
  ('race', 'Zandalaritroll', 'Zandalari Troll'),
  ('race', 'Irdener', 'Earthen'), ('race', 'Irdene', 'Earthen'),
  ('class', 'Krieger', 'Warrior'), ('class', 'Kriegerin', 'Warrior'),
  ('class', 'Jäger', 'Hunter'), ('class', 'Jägerin', 'Hunter'),
  ('class', 'Schurke', 'Rogue'), ('class', 'Schurkin', 'Rogue'),
  ('class', 'Priester', 'Priest'), ('class', 'Priesterin', 'Priest'),
  ('class', 'Todesritter', 'Death Knight'), ('class', 'Todesritterin', 'Death Knight'),
  ('class', 'Schamane', 'Shaman'), ('class', 'Schamanin', 'Shaman'),
  ('class', 'Magier', 'Mage'), ('class', 'Magierin', 'Mage'),
  ('class', 'Hexenmeister', 'Warlock'), ('class', 'Hexenmeisterin', 'Warlock'),
  ('class', 'Mönch', 'Monk'), ('class', 'Mönchin', 'Monk'),
  ('class', 'Druide', 'Druid'), ('class', 'Druidin', 'Druid'),
  ('class', 'Dämonenjäger', 'Demon Hunter'), ('class', 'Dämonenjägerin', 'Demon Hunter'),
  ('class', 'Rufer', 'Evoker'), ('class', 'Ruferin', 'Evoker'),
  -- French (frFR), both genders
  ('race', 'Humain', 'Human'), ('race', 'Humaine', 'Human'),
  ('race', 'Nain', 'Dwarf'), ('race', 'Naine', 'Dwarf'),
  ('race', 'Elfe de la nuit', 'Night Elf'),
  ('race', 'Orque', 'Orc'),
  ('race', 'Mort-vivant', 'Undead'), ('race', 'Morte-vivante', 'Undead'),
  ('race', 'Taurène', 'Tauren'), ('race', 'Trollesse', 'Troll'),
  ('race', 'Elfe de sang', 'Blood Elf'),
  ('race', 'Draeneï', 'Draenei'),
  ('class', 'Guerrier', 'Warrior'), ('class', 'Guerrière', 'Warrior'),
  ('class', 'Chasseur', 'Hunter'), ('class', 'Chasseresse', 'Hunter'),
  ('class', 'Voleur', 'Rogue'), ('class', 'Voleuse', 'Rogue'),
  ('class', 'Prêtre', 'Priest'), ('class', 'Prêtresse', 'Priest'),
  ('class', 'Chaman', 'Shaman'), ('class', 'Chamane', 'Shaman'),
  ('class', 'Démoniste', 'Warlock'),
  ('class', 'Druidesse', 'Druid'),
  ('class', 'Chevalier de la mort', 'Death Knight'),
  ('class', 'Moine', 'Monk'), ('class', 'Moniale', 'Monk'),
  ('class', 'Chasseur de démons', 'Demon Hunter'), ('class', 'Chasseresse de démons', 'Demon Hunter'),
  ('class', 'Évocateur', 'Evoker'), ('class', 'Évocatrice', 'Evoker')
ON CONFLICT (kind, localized) DO UPDATE SET canonical = EXCLUDED.canonical;

CREATE OR REPLACE FUNCTION canonical_race(v text) RETURNS text
LANGUAGE sql STABLE AS $$
  SELECT COALESCE((SELECT canonical FROM marketlens_import.locale_names
                   WHERE kind = 'race' AND lower(localized) = lower(v)), v)
$$;

CREATE OR REPLACE FUNCTION canonical_class(v text) RETURNS text
LANGUAGE sql STABLE AS $$
  SELECT COALESCE((SELECT canonical FROM marketlens_import.locale_names
                   WHERE kind = 'class' AND lower(localized) = lower(v)), v)
$$;

-- 'CHAMÁN' -> 'SHAMAN'; real tokens ('DEATHKNIGHT') pass through unchanged.
CREATE OR REPLACE FUNCTION class_token(v text) RETURNS text
LANGUAGE sql STABLE AS $$
  SELECT CASE WHEN v IS NULL THEN NULL
    ELSE COALESCE((SELECT upper(replace(canonical, ' ', '')) FROM marketlens_import.locale_names
                   WHERE kind = 'class' AND lower(localized) = lower(v)), v) END
$$;

-- {"Blutelfe":1,"Blood Elf":2} -> {"Blood Elf":3}
CREATE OR REPLACE FUNCTION canonical_race_counts(v text) RETURNS text
LANGUAGE sql STABLE AS $$
  SELECT CASE WHEN v IS NULL OR v !~ '^\s*\{' THEN v ELSE
    COALESCE((SELECT json_object_agg(r, n)::text FROM (
      SELECT marketlens_import.canonical_race(key) r, sum(value::text::numeric) n
      FROM json_each(v::json) GROUP BY 1) x), v) END
$$;

CREATE OR REPLACE FUNCTION canonical_names_trg() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF TG_TABLE_NAME = 'characters' THEN
    NEW.race := marketlens_import.canonical_race(NEW.race);
    NEW.class := marketlens_import.canonical_class(NEW.class);
    NEW.class_file := marketlens_import.class_token(NEW.class_file);
  ELSIF TG_TABLE_NAME IN ('external_who_sightings', 'external_verified_characters') THEN
    NEW.race := marketlens_import.canonical_race(NEW.race);
    NEW.class := marketlens_import.canonical_class(NEW.class);
  ELSIF TG_TABLE_NAME IN ('character_location_observations', 'character_inspects') THEN
    NEW.race := marketlens_import.canonical_race(NEW.race);
    NEW.class_file := marketlens_import.class_token(NEW.class_file);
  ELSIF TG_TABLE_NAME = 'character_appearance_observations' THEN
    NEW.race_at_query := marketlens_import.canonical_race(NEW.race_at_query);
  ELSIF TG_TABLE_NAME = 'pop_samples' THEN
    NEW.races := marketlens_import.canonical_race_counts(NEW.races);
  END IF;
  RETURN NEW;
END $$;

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['characters', 'external_who_sightings', 'external_verified_characters',
    'character_location_observations', 'character_inspects', 'character_appearance_observations', 'pop_samples'] LOOP
    EXECUTE format('DROP TRIGGER IF EXISTS canonical_names ON marketlens_import.%I', t);
    EXECUTE format('CREATE TRIGGER canonical_names BEFORE INSERT OR UPDATE ON marketlens_import.%I
                    FOR EACH ROW EXECUTE FUNCTION marketlens_import.canonical_names_trg()', t);
  END LOOP;
END $$;

-- Rewrite what is already stored (the triggers do the mapping on UPDATE).
CREATE TEMP TABLE ln ON COMMIT DROP AS SELECT kind, lower(localized) l FROM locale_names;
UPDATE characters SET race = race
  WHERE lower(race) IN (SELECT l FROM ln WHERE kind = 'race') OR lower(class) IN (SELECT l FROM ln WHERE kind = 'class')
     OR lower(class_file) IN (SELECT l FROM ln WHERE kind = 'class');
UPDATE external_who_sightings SET race = race
  WHERE lower(race) IN (SELECT l FROM ln WHERE kind = 'race') OR lower(class) IN (SELECT l FROM ln WHERE kind = 'class');
UPDATE external_verified_characters SET race = race
  WHERE lower(race) IN (SELECT l FROM ln WHERE kind = 'race') OR lower(class) IN (SELECT l FROM ln WHERE kind = 'class');
UPDATE character_location_observations SET race = race
  WHERE lower(race) IN (SELECT l FROM ln WHERE kind = 'race') OR lower(class_file) IN (SELECT l FROM ln WHERE kind = 'class');
UPDATE character_inspects SET race = race
  WHERE lower(race) IN (SELECT l FROM ln WHERE kind = 'race') OR lower(class_file) IN (SELECT l FROM ln WHERE kind = 'class');
UPDATE character_appearance_observations SET race_at_query = race_at_query
  WHERE lower(race_at_query) IN (SELECT l FROM ln WHERE kind = 'race');
UPDATE pop_samples SET races = races
  WHERE EXISTS (SELECT 1 FROM json_object_keys(CASE WHEN races ~ '^\s*\{' THEN races::json ELSE '{}'::json END) k
                WHERE lower(k) IN (SELECT l FROM ln WHERE kind = 'race'));

COMMIT;
