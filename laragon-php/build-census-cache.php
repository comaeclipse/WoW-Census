<?php
// Pre-compute the census sets the pages otherwise rebuild on every request
// (see census_cache_fresh() in src/Data.php):
//
//   census_characters  census_characters_live_sql() for every edition: one row per
//                      character and collection, with its canonical collection
//                      (cgame) and latest-sighting rank (rn)
//   census_latest      newest sighting per canonical collection
//   census_sightings   census-sweep sightings, ranked per character
//   census_seen        every sighting day of every character (seen_activity())
//   census_seen_rows   seen_activity()'s two results per edition, from census_seen
//   census_aliases     collection_aliases()'s answer
//   census_guilds      public guild id to canonical collection and guild name
//
// Each table is built from the same SQL the pages run live, in one snapshot of
// the database, then swapped in together with census_cache_meta.version = the
// latest import. Pages use the tables only while that version is current, so a
// later import falls back to the live SQL until this runs again. Run it after
// every import (tools/import-savedvariables-pg.py does) and after any repair
// that changes characters or sightings without an import.
//
//   php laragon-php/build-census-cache.php
declare(strict_types=1);

putenv('ML_NO_CENSUS_CACHE=1');  // Build from the live SQL, never from an older copy.
require __DIR__ . '/src/bootstrap.php';

$pdo = Db::pdo();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') { fwrite(STDERR, "PostgreSQL only\n"); exit(2); }
$started = microtime(true);
$pdo->exec("SET maintenance_work_mem = '256MB'");
$pdo->beginTransaction();
// One snapshot: every table and the version describe the same data.
$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$pdo->exec("SELECT pg_advisory_xact_lock(hashtext('marketlens-census-cache'))");
$version = $pdo->query('SELECT max(imported_at_utc) FROM local_imports')->fetchColumn();
if ($version === false || $version === null) { fwrite(STDERR, "No imports recorded\n"); exit(2); }

$quote = fn(string $s): string => "'" . str_replace("'", "''", $s) . "'";
$sources = array_keys(GAMES);
$build = function (string $table, string $select, string $indexes) use ($pdo): void {
  $t = microtime(true);
  $pdo->exec("DROP TABLE IF EXISTS {$table}_next");
  $pdo->exec("CREATE TABLE {$table}_next AS $select");
  foreach (array_filter(explode(';', $indexes)) as $i => $columns)
    $pdo->exec("CREATE INDEX {$table}_next_i$i ON {$table}_next ($columns)");
  $n = $pdo->query("SELECT count(*) FROM {$table}_next")->fetchColumn();
  printf("%-18s %9s rows %6.1fs\n", $table, number_format((int)$n), microtime(true) - $t);
};

$build('census_characters', implode("\nUNION ALL\n", array_map(
    fn($s) => 'SELECT * FROM ' . census_characters_live_sql($s) . ' x', $sources)),
  'source_game, rn; source_game, cgame');
$t = microtime(true);
$pdo->exec('DROP TABLE IF EXISTS census_guilds_next');
$pdo->exec('CREATE TABLE census_guilds_next (source_game text NOT NULL, id text NOT NULL, cgame text NOT NULL, guild text NOT NULL)');
$insertGuild = $pdo->prepare('INSERT INTO census_guilds_next VALUES (?, ?, ?, ?)');
$guildRows = $pdo->query("SELECT DISTINCT source_game, cgame, guild FROM census_characters_next WHERE rn=1 AND TRIM(COALESCE(guild, '')) <> ''");
$guildCount = 0;
while ($guild = $guildRows->fetch(PDO::FETCH_ASSOC)) {
  $insertGuild->execute([$guild['source_game'], guild_id($guild['source_game'], $guild['cgame'], $guild['guild']), $guild['cgame'], $guild['guild']]);
  $guildCount++;
}
$pdo->exec('CREATE UNIQUE INDEX census_guilds_next_i0 ON census_guilds_next (source_game, id)');
printf("%-18s %9s rows %6.1fs\n", 'census_guilds', number_format($guildCount), microtime(true) - $t);
$build('census_latest', 'SELECT ' . canonical_game_sql('game') . ' game, MAX(last_seen) latest FROM characters GROUP BY 1',
  'game');
$build('census_sightings', implode("\nUNION ALL\n", array_map(
    fn($s) => "SELECT {$quote($s)}::text source_game, x.* FROM (" . str_replace('d.source_game = ?', "d.source_game = {$quote($s)}",
      census_sightings_live_sql($s)) . ') x', $sources)),
  'source_game, game');
$build('census_seen', implode("\nUNION ALL\n", array_map(
    fn($s) => "SELECT {$quote($s)}::text source_game, x.* FROM (" . str_replace('d.source_game = ?', "d.source_game = {$quote($s)}",
      seen_sightings_live_sql()) . ') x', $sources)),
  'source_game');

// seen_activity()'s two small results per edition, from the census_seen just built.
$t = microtime(true);
$pdo->exec('DROP TABLE IF EXISTS census_seen_rows_next');
$pdo->exec('CREATE TABLE census_seen_rows_next (source_game text NOT NULL, firsts text NOT NULL, spans text NOT NULL)');
$st = $pdo->prepare('INSERT INTO census_seen_rows_next VALUES (?, ?, ?)');
foreach ($sources as $s) {
  [$firsts, $spans] = seen_activity_rows('SELECT g, k, dy FROM census_seen_next WHERE source_game = ?', [$s]);
  $st->execute([$s, json_encode($firsts, JSON_THROW_ON_ERROR), json_encode($spans, JSON_THROW_ON_ERROR)]);
}
$pdo->exec('CREATE INDEX census_seen_rows_next_i0 ON census_seen_rows_next (source_game)');
printf("%-18s %9s rows %6.1fs\n", 'census_seen_rows', count($sources), microtime(true) - $t);

// collection_aliases()'s answer, in its own order (canonical_game_sql() builds its CASE from it).
$t = microtime(true);
$pdo->exec('DROP TABLE IF EXISTS census_aliases_next');
// Plain index, no constraints: their names would survive the rename and collide next build.
$pdo->exec('CREATE TABLE census_aliases_next (seq int NOT NULL, old_game text NOT NULL, alias text NOT NULL)');
$st = $pdo->prepare('INSERT INTO census_aliases_next VALUES (?, ?, ?)');
$seq = 0;
foreach (collection_aliases() as $old => $alias) $st->execute([++$seq, $old, $alias]);
$pdo->exec('CREATE INDEX census_aliases_next_i0 ON census_aliases_next (seq)');
printf("%-18s %9s rows %6.1fs\n", 'census_aliases', number_format($seq), microtime(true) - $t);

foreach (['census_characters', 'census_guilds', 'census_latest', 'census_sightings', 'census_seen', 'census_seen_rows', 'census_aliases'] as $table) {
  $pdo->exec("DROP TABLE IF EXISTS $table");
  $pdo->exec("ALTER TABLE {$table}_next RENAME TO $table");
  foreach ($pdo->query("SELECT indexname FROM pg_indexes WHERE tablename = " . $quote($table) . " AND indexname LIKE " . $quote("{$table}_next_i%"))->fetchAll(PDO::FETCH_COLUMN) as $index)
    $pdo->exec("ALTER INDEX $index RENAME TO " . str_replace('_next_i', '_i', $index));
}
$pdo->exec('CREATE TABLE IF NOT EXISTS census_cache_meta (version text NOT NULL, built_at timestamptz NOT NULL)');
$pdo->exec('DELETE FROM census_cache_meta');
$st = $pdo->prepare('INSERT INTO census_cache_meta (version, built_at) VALUES (?, now())');
$st->execute([$version]);
$pdo->commit();
foreach (['census_characters', 'census_latest', 'census_sightings', 'census_seen'] as $table) $pdo->exec("ANALYZE $table");
printf("census cache built for import %s in %.1fs\n", $version, microtime(true) - $started);
