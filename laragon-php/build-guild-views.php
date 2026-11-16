<?php
// Pre-compute the guilds page: every realm x faction view of every edition, as
// build_guilds_geography() builds it live, trimmed to what the pages show
// (trim_guild_view()). The live build merges and sorts every guild (~400k rows on
// WoW: Forever) and counts merged guilds' members with a ~12 s query; pages read
// one stored view instead (guild_views_precomputed()).
//
// build-census-cache.php runs this right after it publishes the census tables, so
// the views are built from those tables and tagged with their version. A view of
// another version is ignored and the page builds live.
//
//   php laragon-php/build-guild-views.php
declare(strict_types=1);

putenv('ML_NO_GUILD_VIEWS=1');  // Build live, never from the views being replaced.
require __DIR__ . '/src/bootstrap.php';

$pdo = Db::pdo();
$started = microtime(true);
$version = Db::publishedVersion();
if ($version === null || !census_cache_fresh()) { fwrite(STDERR, "census tables missing or stale: run build-census-cache.php\n"); exit(2); }

$pdo->exec('DROP TABLE IF EXISTS census_guild_views_next');
$pdo->exec('CREATE TABLE census_guild_views_next (version text NOT NULL, source_game text NOT NULL, realm text NOT NULL,
  faction text NOT NULL, data text NOT NULL)');
$insert = $pdo->prepare('INSERT INTO census_guild_views_next VALUES (?, ?, ?, ?, ?)');
$rows = 0;
foreach (array_keys(GAMES) as $source) {
  $t = microtime(true);
  ['dims' => $dims] = edition_dims($source);
  foreach ($dims['realm'] as $r) foreach ($dims['faction'] as $f) {
    foreach (build_guilds_geography($source, 'guilds', [$r['key'], $f['key']])['views'] as $view) {
      $insert->execute([$version, $source, $view['realm'], $view['faction'],
        json_encode(trim_guild_view($view['snapshot']), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)]);
      $rows++;
    }
  }
  printf("%-20s %4d views %6.1fs\n", $source, count($dims['realm']) * count($dims['faction']), microtime(true) - $t);
}
$pdo->beginTransaction();
$pdo->exec("SELECT pg_advisory_xact_lock(hashtext('marketlens-census-cache'))");
$pdo->exec('CREATE INDEX census_guild_views_next_i0 ON census_guild_views_next (source_game, realm, faction)');
$pdo->exec('DROP TABLE IF EXISTS census_guild_views');
$pdo->exec('ALTER TABLE census_guild_views_next RENAME TO census_guild_views');
$pdo->exec('ALTER INDEX census_guild_views_next_i0 RENAME TO census_guild_views_i0');
$pdo->commit();
printf("census_guild_views %d views for %s in %.1fs\n", $rows, $version, microtime(true) - $started);
