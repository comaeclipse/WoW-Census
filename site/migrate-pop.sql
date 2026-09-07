-- Observed-population samples uploaded from the addon (ml-pop-v1).
-- One row per /who scan, keyed by (game, t) so re-uploads replace by timestamp.
-- classes/races are stored as JSON objects {TOKEN: count}. Safe to run once.
CREATE TABLE IF NOT EXISTS pop_samples (
  game     TEXT    NOT NULL,      -- realm:<Realm-Faction>
  t        INTEGER NOT NULL,      -- unix timestamp of the sample
  faction  TEXT,
  observed INTEGER,               -- rows the /who returned (<= server cap)
  total    INTEGER,               -- server-reported online matching the filter
  filter   TEXT,                  -- the /who filter used, e.g. "1-70"
  classes  TEXT,                  -- JSON {classToken: count}
  races    TEXT,                  -- JSON {race: count}
  PRIMARY KEY (game, t)
);
CREATE INDEX IF NOT EXISTS idx_pop_game ON pop_samples(game, t);
