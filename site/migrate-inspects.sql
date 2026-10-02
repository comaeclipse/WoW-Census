-- Nearby inspected builds are separate from /who population observations.
CREATE TABLE IF NOT EXISTS character_inspects (
 source_game TEXT NOT NULL, guid TEXT NOT NULL, captured_at INTEGER NOT NULL,
 raw_name TEXT NOT NULL, name TEXT NOT NULL, realm TEXT, faction TEXT,
 observer_realm TEXT, observer_faction TEXT, observer_zone TEXT,
 class_file TEXT NOT NULL, race TEXT, level INTEGER, guild TEXT,
 raw_spec_id INTEGER, raw_role TEXT, import_string TEXT,
 schema_version INTEGER NOT NULL, client_build TEXT, locale TEXT,
 game TEXT, character_key TEXT, match_status TEXT NOT NULL,
 collector TEXT NOT NULL, nodes_json TEXT NOT NULL, raw_json TEXT NOT NULL,
 PRIMARY KEY(source_game,guid,captured_at)
);
CREATE INDEX IF NOT EXISTS idx_inspects_latest ON character_inspects(source_game,guid,captured_at DESC);
CREATE INDEX IF NOT EXISTS idx_inspects_time ON character_inspects(source_game,captured_at DESC);
CREATE INDEX IF NOT EXISTS idx_inspects_character ON character_inspects(game,character_key);
CREATE TABLE IF NOT EXISTS inspect_talents (
 source_game TEXT NOT NULL, guid TEXT NOT NULL, captured_at INTEGER NOT NULL,
 node_id INTEGER NOT NULL, tree_id INTEGER, spell_id INTEGER,
 name TEXT NOT NULL, rank INTEGER NOT NULL, max_rank INTEGER NOT NULL,
 x REAL, y REAL, entry_id INTEGER, definition_id INTEGER, groups_json TEXT,
 PRIMARY KEY(source_game,guid,captured_at,node_id),
 FOREIGN KEY(source_game,guid,captured_at) REFERENCES character_inspects(source_game,guid,captured_at)
);
CREATE TABLE IF NOT EXISTS talent_catalog (
 source_game TEXT NOT NULL, catalog_version TEXT NOT NULL, node_id INTEGER NOT NULL,
 class_file TEXT NOT NULL, branch TEXT NOT NULL, name TEXT NOT NULL,
 spell_id INTEGER, max_rank INTEGER, icon TEXT, definition_id INTEGER,
 source_url TEXT NOT NULL, retrieved_at TEXT NOT NULL,
 PRIMARY KEY(source_game,catalog_version,node_id)
);
