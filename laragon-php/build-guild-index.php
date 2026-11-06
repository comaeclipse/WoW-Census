<?php
// Backfill the guild lookup on an existing, fresh PostgreSQL census cache.
// Future cache rebuilds create this table in build-census-cache.php.
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
if (!census_cache_fresh()) { fwrite(STDERR, "Census cache is stale; rebuild it first\n"); exit(2); }
$pdo = Db::pdo();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') { fwrite(STDERR, "PostgreSQL only\n"); exit(2); }
$pdo->beginTransaction();
$pdo->exec("SELECT pg_advisory_xact_lock(hashtext('marketlens-census-cache'))");
$pdo->exec('DROP TABLE IF EXISTS census_guilds_next');
$pdo->exec('CREATE TABLE census_guilds_next (source_game text NOT NULL, id text NOT NULL, cgame text NOT NULL, guild text NOT NULL)');
$insert = $pdo->prepare('INSERT INTO census_guilds_next VALUES (?, ?, ?, ?)');
$rows = $pdo->query("SELECT DISTINCT source_game, cgame, guild FROM census_characters WHERE rn=1 AND TRIM(COALESCE(guild, '')) <> ''");
$n = 0;
while ($g = $rows->fetch(PDO::FETCH_ASSOC)) {
  $insert->execute([$g['source_game'], guild_id($g['source_game'], $g['cgame'], $g['guild']), $g['cgame'], $g['guild']]);
  $n++;
}
$pdo->exec('CREATE UNIQUE INDEX census_guilds_next_i0 ON census_guilds_next (source_game, id)');
$pdo->exec('DROP TABLE IF EXISTS census_guilds');
$pdo->exec('ALTER TABLE census_guilds_next RENAME TO census_guilds');
$pdo->exec('ALTER INDEX census_guilds_next_i0 RENAME TO census_guilds_i0');
$pdo->commit();
$pdo->exec('ANALYZE census_guilds');
printf("Indexed %s guilds\n", number_format($n));
