-- Seller profile tables (realm datasets only; populated by legacy paged scans).
-- Safe to run once on an existing database.
CREATE TABLE IF NOT EXISTS sellers (
  game       TEXT    NOT NULL,
  owner      TEXT    NOT NULL,
  slug       TEXT    NOT NULL,
  first_seen INTEGER,
  last_seen  INTEGER,
  seen_count INTEGER,
  items      INTEGER,
  qty        INTEGER,
  value      INTEGER,
  hist       TEXT,
  PRIMARY KEY (game, owner)
);
CREATE INDEX IF NOT EXISTS idx_sellers_value ON sellers(game, value DESC);
CREATE INDEX IF NOT EXISTS idx_sellers_slug  ON sellers(game, slug);

CREATE TABLE IF NOT EXISTS seller_listings (
  game  TEXT    NOT NULL,
  owner TEXT    NOT NULL,
  id    INTEGER NOT NULL,
  q     INTEGER,
  l     INTEGER,
  PRIMARY KEY (game, owner, id)
);
CREATE INDEX IF NOT EXISTS idx_seller_listings_owner ON seller_listings(game, owner);
CREATE INDEX IF NOT EXISTS idx_seller_listings_item  ON seller_listings(game, id);
