CREATE INDEX CONCURRENTLY IF NOT EXISTS character_armory_profiles_freshness
  ON marketlens_import.character_armory_profiles (source_game, fetched_at DESC NULLS LAST);

CREATE INDEX CONCURRENTLY IF NOT EXISTS forever_armory_profiles_freshness
  ON marketlens_import.forever_armory_profiles (fetched_at DESC NULLS LAST);
