-- Indexes on marketlens_import.characters that page queries rely on.
--
-- source_game: load_inspects() counts an edition's characters
-- (SELECT COUNT(*) FROM characters WHERE source_game=?). Without this index each
-- count read the whole table from disk (1.7 GB on live, ~3 s, 13.5 GB read in
-- 20 minutes of page refreshes on 2026-11-16); with it the count is an
-- index-only scan.
--
-- Idempotent. Run with psql -f outside a transaction (CONCURRENTLY).

CREATE INDEX CONCURRENTLY IF NOT EXISTS characters_source_game
  ON marketlens_import.characters (source_game);

VACUUM (ANALYZE) marketlens_import.characters;
