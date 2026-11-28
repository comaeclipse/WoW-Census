<?php
declare(strict_types=1);

/** Latest census, completed guild roster refresh, or character Armory read per edition. */
function homepage_update_times(): array {
  $rows = Db::uncached('SELECT d.source_game, MAX(s.t) AS last_scan
    FROM datasets d JOIN LATERAL (
      SELECT t FROM pop_samples WHERE game = d.game ORDER BY t DESC LIMIT 1
    ) s ON true GROUP BY d.source_game');
  $times = array_map('intval', array_intersect_key(array_column($rows, 'last_scan', 'source_game'), EDITIONS));
  $available = Db::uncached("SELECT
    to_regclass('guild_roster_snapshots') IS NOT NULL AS guilds,
    to_regclass('character_armory_profiles') IS NOT NULL AS armory,
    to_regclass('forever_armory_profiles') IS NOT NULL AS forever")[0];
  $merge = static function (array $rows) use (&$times): void {
    foreach ($rows as $row) {
      $source = $row['source_game'];
      if (!isset(EDITIONS[$source]) || $row['refreshed_at'] === null) continue;
      $times[$source] = max($times[$source] ?? 0, (int)$row['refreshed_at']);
    }
  };
  if ($available['guilds']) {
    $merge(Db::uncached('SELECT source_game, FLOOR(EXTRACT(EPOCH FROM MAX(stored_at)))::bigint AS refreshed_at
      FROM guild_roster_snapshots GROUP BY source_game'));
  }
  if ($available['armory']) {
    $sources = array_keys(EDITIONS);
    $values = implode(',', array_fill(0, count($sources), '(CAST(? AS text))'));
    $merge(Db::uncached("SELECT e.source_game, FLOOR(EXTRACT(EPOCH FROM p.fetched_at))::bigint AS refreshed_at
      FROM (VALUES $values) e(source_game) JOIN LATERAL (
        SELECT fetched_at FROM character_armory_profiles WHERE source_game=e.source_game
        ORDER BY fetched_at DESC NULLS LAST LIMIT 1
      ) p ON true", $sources));
  }
  if ($available['forever']) {
    $merge(Db::uncached("SELECT 'forever' AS source_game, FLOOR(EXTRACT(EPOCH FROM fetched_at))::bigint AS refreshed_at
      FROM forever_armory_profiles ORDER BY fetched_at DESC NULLS LAST LIMIT 1"));
  }
  return $times;
}
