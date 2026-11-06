-- Local Blizzard Appearance API observations. Character identity is the same
-- source_game + character_key used by marketlens_import.characters. The census
-- table's primary key also includes game (realm/faction scan origin), so this
-- table deliberately does not invent a user/account identity or pick one game.
CREATE TABLE IF NOT EXISTS marketlens_import.appearance_sample_runs (
  run_id text PRIMARY KEY,
  report_name text NOT NULL,
  report_sha256 text NOT NULL UNIQUE,
  report_file_mtime timestamptz NOT NULL,
  swatch_source_url text,
  swatch_retrieved_on date,
  requested_count integer NOT NULL CHECK (requested_count > 0),
  sampled_at timestamptz,
  imported_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS marketlens_import.character_appearance_observations (
  run_id text NOT NULL REFERENCES marketlens_import.appearance_sample_runs(run_id),
  source_game text NOT NULL,
  character_key text NOT NULL,
  name text NOT NULL,
  realm text NOT NULL,
  race_at_query text,
  level_at_query integer,
  fetch_status text NOT NULL,
  fetched_at timestamptz,
  customization_count integer,
  skin_option_id bigint,
  skin_choice_id bigint,
  skin_display_order integer,
  swatch_hex text CHECK (swatch_hex IS NULL OR swatch_hex ~ '^#[0-9A-F]{6}$'),
  PRIMARY KEY (run_id, source_game, character_key),
  CHECK ((skin_choice_id IS NULL AND swatch_hex IS NULL) OR
         (skin_choice_id IS NOT NULL AND skin_option_id IS NOT NULL AND swatch_hex IS NOT NULL))
);

-- Existing Retail runs include swatch provenance; Classic choice IDs can be
-- imported before their client-version-specific swatches are mapped.
ALTER TABLE marketlens_import.appearance_sample_runs
  ALTER COLUMN swatch_source_url DROP NOT NULL,
  ALTER COLUMN swatch_retrieved_on DROP NOT NULL;
ALTER TABLE marketlens_import.appearance_sample_runs
  ADD COLUMN IF NOT EXISTS sampled_at timestamptz;
ALTER TABLE marketlens_import.character_appearance_observations
  DROP CONSTRAINT IF EXISTS character_appearance_observations_source_game_check;

-- One appearance response can contain several Skin Color options (for example
-- separate MoP forms). The old one-choice columns remain for Retail consumers.
CREATE TABLE IF NOT EXISTS marketlens_import.character_appearance_skin_choices (
  run_id text NOT NULL,
  source_game text NOT NULL,
  character_key text NOT NULL,
  option_id bigint NOT NULL,
  choice_id bigint NOT NULL,
  display_order integer,
  swatch_hex text CHECK (swatch_hex IS NULL OR swatch_hex ~ '^#[0-9A-F]{6}$'),
  PRIMARY KEY (run_id, source_game, character_key, option_id),
  FOREIGN KEY (run_id, source_game, character_key)
    REFERENCES marketlens_import.character_appearance_observations
      (run_id, source_game, character_key) ON DELETE CASCADE
);

INSERT INTO marketlens_import.character_appearance_skin_choices
  (run_id, source_game, character_key, option_id, choice_id, display_order, swatch_hex)
SELECT run_id, source_game, character_key, skin_option_id, skin_choice_id,
       skin_display_order, swatch_hex
FROM marketlens_import.character_appearance_observations
WHERE skin_option_id IS NOT NULL AND skin_choice_id IS NOT NULL
ON CONFLICT (run_id, source_game, character_key, option_id) DO NOTHING;

CREATE INDEX IF NOT EXISTS idx_appearance_skin_choice
  ON marketlens_import.character_appearance_skin_choices (source_game, choice_id);

CREATE INDEX IF NOT EXISTS idx_character_appearance_identity
  ON marketlens_import.character_appearance_observations (source_game, character_key);
CREATE INDEX IF NOT EXISTS idx_character_appearance_race_choice
  ON marketlens_import.character_appearance_observations (race_at_query, skin_choice_id)
  WHERE skin_choice_id IS NOT NULL;

CREATE OR REPLACE VIEW marketlens_import.character_appearance_latest AS
SELECT DISTINCT ON (o.source_game, o.character_key)
  o.*, r.report_file_mtime
FROM marketlens_import.character_appearance_observations o
JOIN marketlens_import.appearance_sample_runs r USING (run_id)
ORDER BY o.source_game, o.character_key,
  COALESCE(o.fetched_at, r.sampled_at, r.report_file_mtime) DESC, o.run_id DESC;

CREATE OR REPLACE VIEW marketlens_import.character_appearance_skin_choices_latest AS
SELECT o.source_game, o.character_key, o.name, o.realm, o.race_at_query,
       o.level_at_query, o.fetch_status, o.fetched_at, o.run_id,
       c.option_id, c.choice_id, c.display_order, c.swatch_hex
FROM marketlens_import.character_appearance_latest o
JOIN marketlens_import.character_appearance_skin_choices c
  USING (run_id, source_game, character_key);
