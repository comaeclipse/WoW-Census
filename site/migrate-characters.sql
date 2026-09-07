CREATE TABLE IF NOT EXISTS characters (
  game TEXT NOT NULL,
  source_game TEXT NOT NULL,
  character_key TEXT NOT NULL,
  full_name TEXT NOT NULL,
  name TEXT NOT NULL,
  realm TEXT NOT NULL,
  guild TEXT,
  level INTEGER,
  race TEXT,
  class TEXT,
  class_file TEXT,
  zone TEXT,
  first_seen INTEGER NOT NULL,
  last_seen INTEGER NOT NULL,
  seen_count INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (game, source_game, character_key)
);
CREATE INDEX IF NOT EXISTS idx_characters_game_seen ON characters(game, last_seen DESC);

CREATE TABLE IF NOT EXISTS character_observations (
  game TEXT NOT NULL,
  source_game TEXT NOT NULL,
  character_key TEXT NOT NULL,
  day INTEGER NOT NULL,
  sightings INTEGER NOT NULL DEFAULT 0,
  first_seen INTEGER NOT NULL,
  last_seen INTEGER NOT NULL,
  PRIMARY KEY (game, source_game, character_key, day)
);
CREATE INDEX IF NOT EXISTS idx_character_observations_game_day
  ON character_observations(game, day DESC);
