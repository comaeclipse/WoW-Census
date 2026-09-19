-- Two tables: latest snapshot per item (fast index + slug lookup), and a
-- daily history row per item (the time series the graphs draw). History is
-- day-bucketed (ts = YYYYMMDD) with a composite PK so re-uploading a realm on
-- the same day replaces rather than duplicates.
--
-- Only realm datasets ("realm:<Realm-Faction>", uploaded from the addon) carry
-- market data. The mv/asp/sr/spd/hist columns predate that: sr/spd/hist are
-- unused for realm rows, and rows keyed by a bare flavor ("classic-progression",
-- "classic", "retail") are frozen leftovers kept only to resolve item names.

CREATE TABLE IF NOT EXISTS items (
  game       TEXT    NOT NULL,      -- realm:<Realm-Faction> (or a legacy bare flavor key)
  id         INTEGER NOT NULL,      -- WoW item id
  name       TEXT    NOT NULL,
  slug       TEXT    NOT NULL,      -- url-safe name, e.g. fel-iron-ore
  mv         INTEGER,               -- realm listed value = q * asp (copper)
  asp        INTEGER,               -- realm buyout, weighted median unit price (copper)
  sr         REAL,                  -- unused (legacy)
  spd        REAL,                  -- unused (legacy)
  hist       INTEGER,               -- unused (legacy)
  updated_at TEXT,                  -- upload time (ISO)
  q          INTEGER,               -- realm quantity listed in the latest scan
  pq         INTEGER,               -- quantity in the scan before it (NULL when no earlier snapshot)
  sc         INTEGER,               -- realm seller count (NULL when unavailable)
  tc         INTEGER,               -- realm top-seller concentration 0-100 (NULL when sellers unknown)
  cat        TEXT,                  -- addon-computed market (site maps it to a chip)
  src        TEXT,                  -- source axis: crafted | gathered | disenchant | ... (NULL when unknown)
  crafter    TEXT,                  -- producing/gathering profession for src (NULL when unknown)
  PRIMARY KEY (game, id)
);
CREATE INDEX IF NOT EXISTS idx_items_slug ON items(game, slug);
CREATE INDEX IF NOT EXISTS idx_items_q    ON items(game, q DESC);

CREATE TABLE IF NOT EXISTS history (
  game TEXT    NOT NULL,
  id   INTEGER NOT NULL,
  ts   INTEGER NOT NULL,            -- YYYYMMDD (UTC day bucket)
  mv   INTEGER,
  asp  INTEGER,
  sr   REAL,
  spd  REAL,
  q    INTEGER,                     -- quantity at each snapshot
  PRIMARY KEY (game, id, ts)
);
CREATE INDEX IF NOT EXISTS idx_history_item ON history(game, id, ts);

-- Flavor provenance for uploaded datasets plus optional latest seller scan/sample
-- metadata for realm seller pages.
CREATE TABLE IF NOT EXISTS datasets (
  game        TEXT PRIMARY KEY,
  source_game TEXT NOT NULL,
  updated_at  TEXT,
  seller_updated_at TEXT,
  seller_meta TEXT
);

-- Seller profiles for realm datasets (game = "realm:<Realm-Faction>"). Populated
-- only by legacy paged scans, which return owner names. `sellers` is one row per
-- owner (latest observed summary + rolling history JSON); `seller_listings`
-- keeps each owner's latest observed per-item listing so stale listings can be
-- shown instead of erased after a later scan misses them.
CREATE TABLE IF NOT EXISTS sellers (
  game       TEXT    NOT NULL,
  owner      TEXT    NOT NULL,
  slug       TEXT    NOT NULL,      -- url-safe owner name
  first_seen INTEGER,
  last_seen  INTEGER,
  seen_count INTEGER,
  items      INTEGER,               -- current distinct items listed
  qty        INTEGER,               -- current total quantity listed
  value      INTEGER,               -- current total listed value (copper)
  hist       TEXT,                  -- JSON [[t,items,qty,value],...] rolling summary
  PRIMARY KEY (game, owner)
);
CREATE INDEX IF NOT EXISTS idx_sellers_value ON sellers(game, value DESC);
CREATE INDEX IF NOT EXISTS idx_sellers_slug  ON sellers(game, slug);

CREATE TABLE IF NOT EXISTS seller_listings (
  game  TEXT    NOT NULL,
  owner TEXT    NOT NULL,
  id    INTEGER NOT NULL,           -- WoW item id
  q     INTEGER,                    -- quantity this seller lists
  l     INTEGER,                    -- this seller's lowest unit price (copper)
  last_seen INTEGER,                -- latest scan timestamp that saw this listing
  PRIMARY KEY (game, owner, id)
);
CREATE INDEX IF NOT EXISTS idx_seller_listings_owner ON seller_listings(game, owner);
CREATE INDEX IF NOT EXISTS idx_seller_listings_item  ON seller_listings(game, id);

CREATE TABLE IF NOT EXISTS pop_samples (
  game       TEXT    NOT NULL,
  t          INTEGER NOT NULL,
  faction    TEXT,
  observed   INTEGER NOT NULL,
  total      INTEGER NOT NULL,
  filter     TEXT,
  classes    TEXT    NOT NULL,
  races      TEXT    NOT NULL,
  PRIMARY KEY (game, t)
);
CREATE INDEX IF NOT EXISTS idx_pop_game ON pop_samples(game, t);

CREATE TABLE IF NOT EXISTS characters (
  game          TEXT    NOT NULL,
  source_game   TEXT    NOT NULL,
  character_key TEXT    NOT NULL,
  full_name     TEXT    NOT NULL,
  name          TEXT    NOT NULL,
  realm         TEXT    NOT NULL,
  guild         TEXT,
  level         INTEGER,
  race          TEXT,
  class         TEXT,
  class_file    TEXT,
  zone          TEXT,
  first_seen    INTEGER NOT NULL,
  last_seen     INTEGER NOT NULL,
  seen_count    INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (game, source_game, character_key)
);
CREATE INDEX IF NOT EXISTS idx_characters_game_seen ON characters(game, last_seen DESC);

CREATE TABLE IF NOT EXISTS character_observations (
  game          TEXT    NOT NULL,
  source_game   TEXT    NOT NULL,
  character_key TEXT    NOT NULL,
  day           INTEGER NOT NULL,
  sightings     INTEGER NOT NULL DEFAULT 0,
  first_seen    INTEGER NOT NULL,
  last_seen     INTEGER NOT NULL,
  PRIMARY KEY (game, source_game, character_key, day)
);
CREATE INDEX IF NOT EXISTS idx_character_observations_game_day
  ON character_observations(game, day DESC);
