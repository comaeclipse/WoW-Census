CREATE TABLE IF NOT EXISTS population_sweeps (
  game             TEXT    NOT NULL,
  source_game      TEXT    NOT NULL,
  sweep_id         TEXT    NOT NULL,
  started_at       INTEGER NOT NULL,
  completed_at     INTEGER,
  status           TEXT    NOT NULL CHECK (status IN ('active', 'complete', 'partial')),
  label            TEXT,
  faction          TEXT,
  query_count      INTEGER NOT NULL DEFAULT 0,
  character_count  INTEGER NOT NULL DEFAULT 0,
  capped_count     INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (game, source_game, sweep_id)
);
CREATE INDEX IF NOT EXISTS idx_population_sweeps_game_time
  ON population_sweeps(game, started_at DESC);

CREATE TABLE IF NOT EXISTS population_sweep_queries (
  game          TEXT    NOT NULL,
  source_game   TEXT    NOT NULL,
  sweep_id      TEXT    NOT NULL,
  query_index   INTEGER NOT NULL,
  observed_at   INTEGER NOT NULL,
  filter        TEXT,
  observed      INTEGER NOT NULL DEFAULT 0,
  total         INTEGER NOT NULL DEFAULT 0,
  capped        INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (game, source_game, sweep_id, query_index)
);
CREATE INDEX IF NOT EXISTS idx_population_sweep_queries_sweep
  ON population_sweep_queries(game, source_game, sweep_id);

CREATE TABLE IF NOT EXISTS character_location_observations (
  game          TEXT    NOT NULL,
  source_game   TEXT    NOT NULL,
  sweep_id      TEXT    NOT NULL,
  character_key TEXT    NOT NULL,
  query_index   INTEGER NOT NULL,
  observed_at   INTEGER NOT NULL,
  zone          TEXT,
  level         INTEGER,
  class_file    TEXT,
  race          TEXT,
  PRIMARY KEY (game, source_game, sweep_id, character_key)
);
CREATE INDEX IF NOT EXISTS idx_character_location_observations_sweep
  ON character_location_observations(game, source_game, sweep_id);
CREATE INDEX IF NOT EXISTS idx_character_location_observations_zone
  ON character_location_observations(game, zone, observed_at DESC);
