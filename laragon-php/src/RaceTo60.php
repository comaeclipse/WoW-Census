<?php
// Race to 60: the highest-level characters /who has seen in a fresh edition (WoW: Forever),
// ranked by level, then by when they were first seen at that level.

declare(strict_types=1);

const RACE_TO_60_SOURCES = ['forever'];
const RACE_TO_60_ROWS = 500;
// Characters taken from each candidate list (by level, by leveling pace) for the board and the API checks.
const RACE_TO_60_POOL = 1500;

/**
 * The leaderboard model: ranked racers plus a few totals.
 * Each racer: name, level, class file, race, faction (A/H), guild, guild page id, realm group label, region,
 * realm type, when first seen at this level, and last seen (Unix seconds).
 * $candidates keeps characters seen only once even when the API has not verified them: the API
 * checker's list (race60-api-candidates.php).
 */
function race_to_60_model(string $source, int $limit = RACE_TO_60_ROWS, bool $candidates = false): array {
  // Latest row per character, from two lists: the highest levels we saw, and the fastest pace
  // (level per hour since launch when last seen). A racer we saw once at level 12 two hours after
  // launch is far ahead of their last sighting now, and only the pace list finds them.
  $rows = Db::all(
    "WITH c AS (
       SELECT DISTINCT ON (character_key) character_key, game, name, realm, guild, level, race, class_file, last_seen, seen_count,
         CASE WHEN game LIKE '%-Alliance' THEN 'A' WHEN game LIKE '%-Horde' THEN 'H' END faction
       FROM characters
       WHERE source_game = ? AND level IS NOT NULL AND class_file IS NOT NULL
       ORDER BY character_key, last_seen DESC
     ),
     launch AS (SELECT MIN(first_seen) t FROM characters WHERE source_game = ?),
     top AS (
       (SELECT * FROM c WHERE faction IS NOT NULL ORDER BY level DESC, last_seen LIMIT ?)
       UNION
       (SELECT c.* FROM c, launch WHERE c.faction IS NOT NULL
         ORDER BY c.level / GREATEST((c.last_seen - launch.t) / 3600.0, 1) DESC, c.level DESC LIMIT ?)
     ),
     reached AS (
       SELECT o.character_key, MIN(o.observed_at) t
       FROM character_location_observations o JOIN top ON top.character_key = o.character_key AND top.level = o.level
       WHERE o.source_game = ?
       GROUP BY 1
     )
     SELECT top.*, COALESCE(reached.t, top.last_seen) reached_at
     FROM top LEFT JOIN reached ON reached.character_key = top.character_key",
    [$source, $source, RACE_TO_60_POOL, RACE_TO_60_POOL, $source]);

  $checks = race_to_60_api_checks();

  $racers = [];
  foreach ($rows as $r) {
    $group = forever_realm_group((string)$r['realm']);
    $type = $group ? explode(':', $group['key'])[1] : 'Other';
    $region = preg_match('/^CAMWOW\s+(US|EU)\d/i', (string)$r['realm'], $m) ? strtoupper($m[1]) : '';
    $racers[] = [
      'key' => (string)$r['character_key'],
      'name' => (string)$r['name'],
      'level' => (int)$r['level'],
      'cf' => (string)$r['class_file'],
      'race' => (string)($r['race'] ?? ''),
      'faction' => (string)$r['faction'],
      'guild' => (string)($r['guild'] ?? ''),
      // The guild's roster page id ('' without a guild); see guild_id().
      'guildId' => trim((string)($r['guild'] ?? '')) !== '' ? guild_id($source, canonical_game((string)$r['game']), (string)$r['guild']) : '',
      'realm' => $group['label'] ?? preg_replace('/^CAMWOW\s+/i', '', (string)$r['realm']),
      'region' => $region,
      'type' => $type,
      'reached' => (int)$r['reached_at'],
      'seen' => (int)$r['last_seen'],
      'whoLevel' => (int)$r['level'],
      'seenCount' => (int)$r['seen_count'],
    ];
    $racer = &$racers[array_key_last($racers)];
    $check = $checks[$racer['key']] ?? null;
    $racer['api'] = null;
    if ($check && ($check['region'] ?? '') === strtolower($region)
        && ($check['name'] ?? '') === $racer['name']) {
      $racer['api'] = $check;
      if (($check['status'] ?? '') === 'ok' && (int)($check['checked_at'] ?? 0) >= $racer['seen'] && isset($check['level'])
          && $check['level'] >= $racer['level']) {
        if ($check['level'] > $racer['level']) $racer['reached'] = null;
        $racer['level'] = (int)$check['level'];
        $racer['levelSource'] = 'api';
      }
    }
    $racer['levelSource'] ??= 'who';
    unset($racer);
  }
  // A character seen only once shows only when the Blizzard API confirmed it: one sighting far above
  // everyone else (a GM or a misread) would otherwise sit on top of the board.
  if (!$candidates)
    $racers = array_values(array_filter($racers, fn($r) => $r['seenCount'] >= 2 || ($r['api']['status'] ?? '') === 'ok'));
  // Add verified characters from the external tracker that our /who list does not have.
  // When we have the character too, a newer and higher verified level from that check updates our row.
  $onBoard = [];
  foreach ($racers as $i => $r) $onBoard[strtolower($r['region']) . "\t" . mb_strtolower($r['name'])] = $i;
  foreach (race_to_60_external_racers() as $r) {
    $i = $onBoard[strtolower($r['region']) . "\t" . mb_strtolower($r['name'])] ?? null;
    if ($i === null) { $racers[] = $r; continue; }
    $ours = &$racers[$i];
    if ($r['level'] > $ours['level'] && $r['seen'] >= $ours['seen'] && $r['cf'] === $ours['cf'] && $r['faction'] === $ours['faction'])
      $ours = ['level' => $r['level'], 'api' => $r['api'], 'levelSource' => 'api', 'reached' => null] + $ours;
    unset($ours);
  }
  // An API-only level has no reached-at time. Known /who times win ties.
  usort($racers, fn($a, $b) => [$b['level'], $a['reached'] ?? PHP_INT_MAX, $a['name']] <=> [$a['level'], $b['reached'] ?? PHP_INT_MAX, $b['name']]);
  // One character can be recorded under two collector realms that the page shows as the same group
  // ("US PvE"). Keep its best-ranked row.
  $seenRacer = [];
  $racers = array_values(array_filter($racers, function ($r) use (&$seenRacer) {
    $id = implode("\t", [$r['name'], $r['realm'], $r['faction'], $r['race'], $r['cf']]);
    if (isset($seenRacer[$id])) return false;
    return $seenRacer[$id] = true;
  }));
  $racers = array_slice($racers, 0, $limit);

  $totals = Db::first(
    "SELECT COUNT(DISTINCT character_key) n, MAX(last_seen) latest,
       COUNT(DISTINCT character_key) FILTER (WHERE level >= 60) at60
     FROM characters WHERE source_game = ?", [$source]) ?? [];
  $apiAt60 = 0;
  foreach ($racers as $r) if ($r['whoLevel'] < 60 && $r['level'] >= 60) $apiAt60++;
  $classes = [];
  foreach (array_slice($racers, 0, 100) as $r) $classes[$r['cf']] = ($classes[$r['cf']] ?? 0) + 1;
  arsort($classes);
  return [
    'racers' => $racers,
    'tracked' => (int)($totals['n'] ?? 0),
    'latest' => (int)($totals['latest'] ?? 0),
    'at60' => (int)($totals['at60'] ?? 0) + $apiAt60,
    'top' => $racers[0] ?? null,
    'leadingClass' => $classes ? array_key_first($classes) : null,
    'leadingClassCount' => $classes ? reset($classes) : 0,
    'topCount' => min(100, count($racers)),
  ];
}

/** The API snapshot written by race60-api-check.py; [] when missing or invalid (fail closed). */
function race_to_60_api_snapshot(): array {
  static $data = null;
  if ($data !== null) return $data;
  $path = getenv('ML_RACE60_API_SNAPSHOT') ?: '/srv/wowcensus/data/race60-api.json';
  $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
  if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['checks'] ?? null)) $data = [];
  return $data;
}

/** Last successful API check for each census identity. Invalid snapshots fail closed. */
function race_to_60_api_checks(): array {
  return race_to_60_api_snapshot()['checks'] ?? [];
}

/**
 * Racers our /who samples have not seen, from another tracker's names, kept only when the
 * Blizzard API returned a matching Forever profile (status ok).
 */
function race_to_60_external_racers(): array {
  $racers = [];
  foreach (race_to_60_api_snapshot()['external'] ?? [] as $key => $c) {
    if (($c['status'] ?? '') !== 'ok' || !is_int($c['level'] ?? null)) continue;
    $faction = match ($c['api_faction'] ?? '') { 'ALLIANCE' => 'A', 'HORDE' => 'H', default => null };
    $cf = strtoupper(str_replace(' ', '', (string)($c['api_class'] ?? '')));
    if (!$faction || !isset(CLASS_META[$cf])) continue;
    $region = strtoupper((string)($c['region'] ?? ''));
    // The profile's realm names the group ("CAMWOW US2 Realm 5 PvE"); the tracker's ruleset is the fallback.
    $group = forever_realm_group((string)($c['api_realm'] ?? ''));
    $type = $group ? explode(':', $group['key'])[1] : (($c['ruleset'] ?? '') ?: 'Other');
    $racers[] = [
      'key' => (string)$key,
      'name' => (string)($c['display_name'] ?? $c['name'] ?? ''),
      'level' => $c['level'],
      'cf' => $cf,
      'race' => (string)($c['api_race'] ?? ''),
      'faction' => $faction,
      'guild' => (string)($c['api_guild'] ?? ''),
      'guildId' => '',
      'realm' => $group['label'] ?? trim($region . ' ' . $type),
      'region' => $region,
      'type' => $type,
      'reached' => null,
      'seen' => (int)($c['checked_at'] ?? 0),
      'whoLevel' => 0,
      'api' => $c,
      'levelSource' => 'api',
      'external' => true,
    ];
  }
  return $racers;
}

/** Plain HTML for skins without their own design; the 2008 skin rebuilds the page from the model. */
function race_to_60_html(string $source): string {
  $model = race_to_60_model($source);
  $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $label = EDITIONS[$source]['label'];
  $rows = '';
  foreach (array_slice($model['racers'], 0, 100) as $i => $r)
    $rows .= '<tr><td>' . ($i + 1) . '</td><td>' . $h($r['name']) . '</td><td>' . $r['level'] . '</td><td>' . $h(class_name($r['cf'])) . '</td><td>' . $h($r['race'])
      . '</td><td>' . ($r['faction'] === 'A' ? 'Alliance' : 'Horde') . '</td><td>' . $h($r['guild']) . '</td><td>' . $h($r['realm']) . '</td><td>'
      . ($r['reached'] === null ? 'Unknown (API level)' : gmdate('Y-m-d H:i', $r['reached']) . 'Z') . '</td><td>'
      . $h($r['api']['status'] ?? 'Pending') . (isset($r['api']['checked_at']) ? ' · ' . gmdate('Y-m-d H:i', $r['api']['checked_at']) . 'Z' : '') . '</td><td>'
      . gmdate('Y-m-d H:i', $r['seen']) . 'Z</td></tr>';
  return localize_content('<!doctype html><html lang="en"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Race to 60 – ' . $h($label) . ' – WoWCensus</title>'
    . '<meta name="description" content="' . $h($label) . ' race to 60: the highest-level characters seen in game, with class, race, faction, guild and realm.">'
    . '<link rel="canonical" href="' . SITE_ORIGIN . edition_path($source) . 'race-to-60">'
    . '<link rel="stylesheet" href="/' . stylesheet()['name'] . '"></head><body><main class="wrap"><h1 class="iname">Race to 60 – ' . $h($label) . '</h1>'
    . '<p>Current Blizzard profile levels can change ranks. Unknown API level times follow known /who times in a tie.</p>'
    . '<table><thead><tr><th>#</th><th>Player</th><th>Level</th><th>Class</th><th>Race</th><th>Faction</th><th>Guild</th><th>Realm</th><th>Reached level</th><th>API check</th><th>Last seen</th></tr></thead><tbody>'
    . $rows . '</tbody></table></main></body></html>' . "\n");
}
