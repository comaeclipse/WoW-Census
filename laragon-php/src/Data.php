<?php
// Port of tools/local-site-data.mjs and the read side of site/src/inspects.mjs.
// Each query mirrors the SQLite original. SQLite's LIKE ignores ASCII case
// (ILIKE here), and its GROUP BY returns groups in binary key order, which the
// pages depend on, so those queries order explicitly with COLLATE "C".

declare(strict_types=1);

const REGION = 'us';
const GAMES = [
  'classic-progression' => 'TBC Anniversary',
  'classic' => 'Classic Era',
  'retail' => 'Retail',
  'classic-beta' => 'Forever (Beta)',
  'mop-classic' => 'Mists of Pandaria Classic',
  'sod' => 'Season of Discovery',
  'hardcore' => 'Classic Hardcore',
];
const ITEM_COLUMNS = ['id', 'name', 'slug', 'mv', 'asp', 'q', 'pq', 'sc', 'tc', 'cat', 'src', 'crafter'];
const ZONE_WINDOW_DAYS = 30;
const INSPECT_WINDOW_DAYS = 14;

function api_items(string $game): array {
  if (!str_starts_with($game, 'realm:'))
    return ['game' => $game, 'sourceGame' => $game, 'region' => REGION, 'count' => 0, 'updatedAt' => null, 'days' => 0, 'columns' => ITEM_COLUMNS, 'items' => []];
  // SQLite returned equal quantities in rowid (insertion) order. items.seq keeps
  // that order: it was numbered in SQLite's order on migration, and new rows
  // take the next number while updated rows keep theirs.
  $results = Db::all('SELECT id,name,slug,mv,asp,q,pq,sc,tc,cat,src,crafter FROM items WHERE game=? ORDER BY q DESC NULLS LAST, seq', [$game]);
  $rows = array_map(fn($r) => [$r['id'], $r['name'], $r['slug'], $r['mv'], $r['asp'], $r['q'], $r['pq'], $r['sc'], $r['tc'], $r['cat'], $r['src'], $r['crafter']], $results);
  $meta = Db::first('SELECT MAX(updated_at) u FROM items WHERE game=?', [$game]);
  $dataset = Db::first('SELECT source_game FROM datasets WHERE game=?', [$game]);
  $hd = Db::first('SELECT COUNT(DISTINCT ts) d FROM history WHERE game=?', [$game]);
  return ['game' => $game, 'sourceGame' => ($dataset['source_game'] ?? null) ?: $game, 'region' => REGION, 'count' => count($rows),
    'updatedAt' => $meta['u'] ?? null, 'days' => ($hd['d'] ?? 0) ?: 0, 'columns' => ITEM_COLUMNS, 'items' => $rows];
}

function api_games(): array {
  static $games = null;
  if ($games !== null) return $games;
  $itemRows = Db::all('SELECT game, COUNT(*) c, MAX(updated_at) u FROM items GROUP BY game ORDER BY game COLLATE "C"');
  $popRows = Db::all('SELECT game, COUNT(*) c FROM pop_samples GROUP BY game ORDER BY game COLLATE "C"');
  $source = [];
  foreach (Db::all('SELECT game, source_game FROM datasets') as $r) $source[$r['game']] = $r['source_game'];
  $map = [];
  foreach ($itemRows as $r) $map[$r['game']] = ['game' => $r['game'], 'count' => $r['c'], 'updatedAt' => $r['u'], 'hasItems' => true, 'hasPop' => false];
  foreach ($popRows as $r) {
    if (isset($map[$r['game']])) $map[$r['game']]['hasPop'] = true;
    else $map[$r['game']] = ['game' => $r['game'], 'count' => 0, 'updatedAt' => null, 'hasItems' => false, 'hasPop' => true];
  }
  $games = [];
  foreach ($map as $e) {
    $realm = str_starts_with($e['game'], 'realm:');
    $games[] = ['game' => $e['game'], 'count' => $e['count'], 'updatedAt' => $e['updatedAt'], 'realm' => $realm,
      'hasItems' => $e['hasItems'], 'hasPop' => $e['hasPop'],
      'sourceGame' => $realm ? ($source[$e['game']] ?? null) : $e['game'],
      'label' => $realm ? realm_name($e['game']) : (GAMES[$e['game']] ?? $e['game'])];
  }
  return $games;
}

function split_realm(string $label): array {
  $cut = strrpos($label, '-');
  return $cut !== false && $cut > 0 ? ['name' => substr($label, 0, $cut), 'fac' => substr($label, $cut + 1)] : ['name' => $label, 'fac' => ''];
}

function merge_census_units(array $units): array {
  $byFaction = [];
  foreach ($units as $u) {
    $f = $u['faction'];
    $byFaction[$f] ??= ['faction' => $f, 'races' => [], 'raceWindows' => ['all' => [], 'week' => [], 'day' => []], 'classes' => [], 'combos' => [],
      'characters' => 0, 'samples' => 0, 'observed' => 0, 'games' => []];
    $g = &$byFaction[$f];
    $g['characters'] += $u['characters'];
    $g['samples'] += $u['samples'];
    $g['observed'] += $u['observed'];
    if (!in_array($u['game'], $g['games'], true)) $g['games'][] = $u['game'];
    foreach (js_keys($u['races']) as $k) $g['races'][$k] = ($g['races'][$k] ?? 0) + $u['races'][$k];
    foreach (['all', 'week', 'day'] as $window)
      foreach (js_keys($u['raceWindows'][$window] ?? []) as $k)
        $g['raceWindows'][$window][$k] = ($g['raceWindows'][$window][$k] ?? 0) + $u['raceWindows'][$window][$k];
    foreach (js_keys($u['classes']) as $k) $g['classes'][$k] = ($g['classes'][$k] ?? 0) + $u['classes'][$k];
    foreach (js_keys($u['combos'] ?? []) as $k) $g['combos'][$k] = ($g['combos'][$k] ?? 0) + $u['combos'][$k];
    unset($g);
  }
  $order = ['Alliance', 'Horde'];
  $rank = fn($f) => (($i = array_search($f, $order, true)) === false ? 0 : $i + 1) ?: 99;
  return js_sort(array_values($byFaction), fn($a, $b) => js_or($rank($a['faction']) - $rank($b['faction']), fn() => $b['characters'] - $a['characters']));
}

function load_census(string $sourceGame): array {
  static $cache = [];
  if (isset($cache[$sourceGame])) return $cache[$sourceGame];
  $allObserved = $sourceGame === 'classic-beta';
  $connected = $sourceGame === 'mop-classic';
  $partition = $connected ? 'o.character_key' : 'o.game, o.character_key';
  $censusWindow = CENSUS_WINDOW_DAYS * 86400;
  $censusRows = $allObserved ? [] : Db::all(
    "WITH obs AS (
       SELECT o.game game, o.race race, o.class_file cf, o.observed_at t,
         ROW_NUMBER() OVER (PARTITION BY $partition ORDER BY o.observed_at DESC) rn
       FROM character_location_observations o
       JOIN population_sweeps s
         ON s.game = o.game AND s.source_game = o.source_game AND s.sweep_id = o.sweep_id
       JOIN population_sweep_queries q
         ON q.game = o.game AND q.source_game = o.source_game AND q.sweep_id = o.sweep_id
        AND q.query_index = o.query_index
       JOIN datasets d ON d.game = o.game
       WHERE d.source_game = ? AND s.label ILIKE 'census %' AND COALESCE(q.filter, '') NOT ILIKE '%z-%'
     ), latest AS MATERIALIZED (SELECT game, MAX(t) t FROM obs GROUP BY game)
     SELECT obs.game game, obs.race race, obs.cf cf, COUNT(*) n
     FROM obs JOIN latest l ON l.game = obs.game
     WHERE obs.rn = 1 AND obs.t >= l.t - ?
     GROUP BY obs.game, obs.race, obs.cf
     ORDER BY obs.game COLLATE \"C\", obs.race COLLATE \"C\" NULLS FIRST, obs.cf COLLATE \"C\" NULLS FIRST", [$sourceGame, $censusWindow]);
  $censused = [];
  foreach ($censusRows as $r) $censused[$r['game']] = true;
  $sightingRows = array_values(array_filter(Db::all(
    "SELECT c.game game, c.race race, c.class_file cf, COUNT(*) n
     FROM characters c JOIN datasets d ON d.game = c.game
     " . ($allObserved ? '' : 'JOIN (SELECT game, MAX(last_seen) latest FROM characters GROUP BY game) m ON m.game = c.game') . "
     WHERE d.source_game = ? " . ($allObserved ? '' : 'AND c.last_seen >= m.latest - ?') . "
     GROUP BY c.game, c.race, c.class_file
     ORDER BY c.game COLLATE \"C\", c.race COLLATE \"C\" NULLS FIRST, c.class_file COLLATE \"C\" NULLS FIRST",
    $allObserved ? [$sourceGame] : [$sourceGame, $censusWindow]), fn($r) => !isset($censused[$r['game']])));
  $rows = array_merge($censusRows, $sightingRows);
  $raceCensusRows = $allObserved ? [] : Db::all(
    "WITH obs AS (
       SELECT o.game, o.race, o.observed_at t,
         ROW_NUMBER() OVER (PARTITION BY $partition ORDER BY o.observed_at DESC) rn
       FROM character_location_observations o
       JOIN population_sweeps s ON s.game=o.game AND s.source_game=o.source_game AND s.sweep_id=o.sweep_id
       JOIN population_sweep_queries q ON q.game=o.game AND q.source_game=o.source_game AND q.sweep_id=o.sweep_id AND q.query_index=o.query_index
       JOIN datasets d ON d.game=o.game
       WHERE d.source_game=? AND s.label ILIKE 'census %' AND COALESCE(q.filter,'') NOT ILIKE '%z-%'
     ), latest AS MATERIALIZED (SELECT game, MAX(t) t FROM obs GROUP BY game)
     SELECT o.game, o.race, COUNT(*) all_count,
       SUM(CASE WHEN o.t>=l.t-604800 THEN 1 ELSE 0 END) week_count,
       SUM(CASE WHEN o.t>=l.t-86400 THEN 1 ELSE 0 END) day_count
     FROM obs o JOIN latest l ON l.game=o.game
     WHERE o.rn=1 AND o.race IS NOT NULL
     GROUP BY o.game,o.race
     ORDER BY o.game COLLATE \"C\", o.race COLLATE \"C\"", [$sourceGame]);
  $raceSightingRows = array_values(array_filter(Db::all(
    "SELECT c.game, c.race, COUNT(*) all_count,
       SUM(CASE WHEN c.last_seen>=m.latest-604800 THEN 1 ELSE 0 END) week_count,
       SUM(CASE WHEN c.last_seen>=m.latest-86400 THEN 1 ELSE 0 END) day_count
     FROM characters c JOIN datasets d ON d.game=c.game
     JOIN (SELECT game,MAX(last_seen) latest FROM characters GROUP BY game) m ON m.game=c.game
     WHERE d.source_game=? AND c.race IS NOT NULL
     GROUP BY c.game,c.race
     ORDER BY c.game COLLATE \"C\", c.race COLLATE \"C\"", [$sourceGame]), fn($r) => !isset($censused[$r['game']])));
  $raceWindowRows = array_merge($raceCensusRows, $raceSightingRows);
  $scans = Db::all(
    'SELECT p.game game, COUNT(*) samples, SUM(p.observed) observed, MAX(p.t) "lastT"
     FROM pop_samples p JOIN datasets d ON d.game = p.game
     WHERE d.source_game = ? GROUP BY p.game ORDER BY p.game COLLATE "C"', [$sourceGame]);
  $charSource = $connected ? "(SELECT *, ROW_NUMBER() OVER (PARTITION BY character_key ORDER BY last_seen DESC) rn FROM characters WHERE source_game='mop-classic')" : 'characters';
  $guilds = Db::all(
    "SELECT c.game game, c.guild guild, COUNT(*) members
     FROM $charSource c
     JOIN datasets d ON d.game = c.game
     WHERE d.source_game = ? " . ($connected ? 'AND c.rn=1' : '') . " AND TRIM(COALESCE(c.guild, '')) <> ''
     GROUP BY c.game, c.guild
     ORDER BY c.game COLLATE \"C\", c.guild COLLATE \"C\"", [$sourceGame]);
  $zones = Db::all(
    "SELECT c.game game, COALESCE(NULLIF(TRIM(c.zone), ''), 'Unknown') zone,
       COUNT(*) characters, CAST(ROUND(AVG(c.level)) AS INT) \"avgLevel\", MAX(c.level) \"maxLevel\"
     FROM $charSource c JOIN datasets d ON d.game = c.game
     JOIN (SELECT game, MAX(last_seen) latest FROM characters GROUP BY game) m ON m.game = c.game
     WHERE d.source_game = ? " . ($connected ? 'AND c.rn=1' : '') . " AND c.last_seen >= m.latest - ?
     GROUP BY c.game, COALESCE(NULLIF(TRIM(c.zone), ''), 'Unknown')
     ORDER BY c.game COLLATE \"C\", COALESCE(NULLIF(TRIM(c.zone), ''), 'Unknown') COLLATE \"C\"", [$sourceGame, ZONE_WINDOW_DAYS * 86400]);

  $units = [];
  $realms = [];
  $lastT = 0;
  $unit = function (string $game) use (&$units, &$realms): string {
    if (!isset($units[$game])) {
      $label = str_starts_with($game, 'realm:') ? substr($game, 6) : $game;
      ['name' => $name, 'fac' => $fac] = split_realm($label);
      $realms[$name] = true;
      $units[$game] = ['game' => $game, 'realm' => $name, 'faction' => $fac !== '' ? $fac : 'Unknown',
        'races' => [], 'raceWindows' => ['all' => [], 'week' => [], 'day' => []], 'classes' => [], 'combos' => [], 'guilds' => [], 'zones' => [], 'zoneCharacters' => 0,
        'characters' => 0, 'samples' => 0, 'observed' => 0];
    }
    return $game;
  };
  foreach ($rows as $r) {
    $u = &$units[$unit($r['game'])];
    $u['basis'] = isset($censused[$r['game']]) ? 'census' : 'sightings';
    $u['characters'] += $r['n'];
    if ($r['race'] !== null && $r['race'] !== '') $u['races'][$r['race']] = ($u['races'][$r['race']] ?? 0) + $r['n'];
    if ($r['cf'] !== null && $r['cf'] !== '') $u['classes'][$r['cf']] = ($u['classes'][$r['cf']] ?? 0) + $r['n'];
    if ($r['race'] !== null && $r['race'] !== '' && $r['cf'] !== null && $r['cf'] !== '') {
      $key = $r['race'] . "\t" . $r['cf'];
      $u['combos'][$key] = ($u['combos'][$key] ?? 0) + $r['n'];
    }
    unset($u);
  }
  foreach ($raceWindowRows as $r) {
    $u = &$units[$unit($r['game'])];
    $u['raceWindows']['all'][$r['race']] = $r['all_count'] ?: 0;
    $u['raceWindows']['week'][$r['race']] = $r['week_count'] ?: 0;
    $u['raceWindows']['day'][$r['race']] = $r['day_count'] ?: 0;
    unset($u);
  }
  foreach ($scans as $sc) {
    $u = &$units[$unit($sc['game'])];
    $u['samples'] += $sc['samples'] ?: 0;
    $u['observed'] += $sc['observed'] ?: 0;
    if (($sc['lastT'] ?: 0) > $lastT) $lastT = $sc['lastT'];
    unset($u);
  }
  foreach ($guilds as $g) $units[$unit($g['game'])]['guilds'][] = ['name' => $g['guild'], 'members' => $g['members'] ?: 0];
  foreach ($zones as $z) {
    $game = $unit($z['game']);
    $units[$game]['zoneCharacters'] += $z['characters'] ?: 0;
    $units[$game]['zones'][] = ['name' => $z['zone'], 'characters' => $z['characters'] ?: 0,
      'avgLevel' => $z['avgLevel'] ?: 0, 'maxLevel' => $z['maxLevel'] ?: 0];
  }
  foreach ($units as &$u) {
    $u['guilds'] = js_sort($u['guilds'], fn($a, $b) => js_or($b['members'] - $a['members'], fn() => js_cmp($a['name'], $b['name'])));
    $u['zones'] = js_sort($u['zones'], fn($a, $b) => js_or($b['characters'] - $a['characters'], fn() => js_cmp($a['name'], $b['name'])));
  }
  unset($u);
  $realmList = array_map('strval', array_keys($realms));
  sort($realmList, SORT_STRING);
  return $cache[$sourceGame] = [
    'groups' => merge_census_units(array_values($units)),
    'units' => array_values($units),
    'realms' => $realmList,
    'lastT' => $lastT,
    'zoneWindowDays' => ZONE_WINDOW_DAYS,
  ];
}

// Season of Discovery rune on one inspected item. NameplateInspect keeps the
// item's green tooltip lines ("green"): the enchant and the rune, since the
// rune is not in the item link. Enchant text carries numbers ("+7 Agility",
// "130 Armor"); a rune is a bare ability name ("Divine Storm"), listed after
// the enchant. Weapons, shirts and tabards take no runes, so their lines are
// always enchants ("Grand Crusader").
function gear_rune(array $g): ?string {
  if (in_array($g['slot'] ?? null, [4, 16, 17, 18, 19], true)) return null;
  $rune = null;
  foreach ($g['green'] ?? [] as $line) {
    $line = trim((string)$line);
    if ($line === '' || preg_match('/[\d+<>]/', $line) || str_starts_with($line, 'Set:')) continue;
    $rune = mb_substr($line, 0, 60);
  }
  return $rune;
}

function load_inspects(string $source): array {
  static $cache = [];
  if (isset($cache[$source])) return $cache[$source];
  if (getenv('ML_QUERY_CACHE') === '1') return $cache[$source] = Db::model('inspects:' . $source);
  $latest = Db::first('SELECT MAX(captured_at) latest FROM character_inspects WHERE source_game=?', [$source]);
  $end = $latest['latest'] ?? 0;
  $end = $end ?: 0;
  $rows = Db::all('SELECT i.name, i.class_file, i.race, i.level, i.guild, i.captured_at, i.realm, i.faction, i.match_status,
      i.game, i.character_key, i.import_string, i.raw_spec_id, i.raw_role, i.gear_json, i.nodes_json
    FROM character_inspects i
    JOIN (SELECT guid,MAX(captured_at) t FROM character_inspects WHERE source_game=? GROUP BY guid) m
      ON m.guid=i.guid AND m.t=i.captured_at
    WHERE i.source_game=? AND i.captured_at>=? ORDER BY i.captured_at DESC,i.guid COLLATE "C" LIMIT 10001',
    [$source, $source, $end - INSPECT_WINDOW_DAYS * 86400]);
  $truncated = count($rows) > 10000;
  $records = [];
  foreach (array_slice($rows, 0, 10000) as $r) {
    $e = ['class' => $r['class_file'], 'spec' => $r['raw_spec_id'], 'list' => json_decode($r['nodes_json'], true)];
    $build = describe_build($e, $source);
    $game = $r['game'];
    $realm = ($r['realm'] ?? '') !== '' ? $r['realm']
      : (($game !== null && ($x = preg_replace('/-(Alliance|Horde)$/', '', preg_replace('/^realm:/', '', $game))) !== '') ? $x : null);
    $faction = ($r['faction'] ?? '') !== '' ? $r['faction']
      : (preg_match('/-(Alliance|Horde)$/', $game ?? '', $m) ? $m[1] : null);
    $gear = [];
    if (($r['gear_json'] ?? '') !== '')
      foreach (json_decode($r['gear_json'], true) as $g)
        $gear[] = ['slot' => $g['slot'] ?? null, 'id' => $g['id'] ?? null, 'name' => $g['name'] ?? null, 'quality' => $g['quality'] ?? null, 'enchant' => $g['enchant'] ?? null,
          'rune' => $source === 'sod' ? gear_rune($g) : null];
    $records[] = ['name' => $r['name'], 'classFile' => $r['class_file'], 'race' => $r['race'], 'level' => $r['level'],
      'guild' => ($r['guild'] ?? '') !== '' ? $r['guild'] : null, 'time' => $r['captured_at'],
      'realm' => $realm, 'faction' => $faction,
      'matchStatus' => $r['match_status'], 'game' => $game, 'characterKey' => $r['character_key'],
      'importString' => $r['import_string'], 'rawSpecID' => $r['raw_spec_id'], 'rawRole' => $r['raw_role'],
      'gear' => $gear] + $build;
  }
  $retained = Db::first('SELECT COUNT(*) n FROM characters WHERE source_game=?', [$source])['n'] ?? 0;
  $linkedKeys = [];
  foreach ($records as $r) if (($r['game'] ?? '') !== '') $linkedKeys[$r['game'] . ':' . js_string($r['characterKey'])] = true;
  $history = Db::first('SELECT COUNT(*) n FROM character_inspects WHERE source_game=?', [$source])['n'] ?? 0;
  $count = fn(callable $f) => count(array_filter($records, $f));
  $model = ['source' => $source, 'lastT' => $end, 'windowDays' => INSPECT_WINDOW_DAYS, 'truncated' => $truncated, 'records' => $records,
    'coverage' => ['inspected' => count($records), 'withSelectedTalents' => $count(fn($r) => count($r['talents']) > 0),
      'withGear' => $count(fn($r) => count($r['gear']) > 0), 'linked' => count($linkedKeys), 'unmatched' => $count(fn($r) => ($r['game'] ?? '') === ''),
      'ambiguous' => $count(fn($r) => $r['matchStatus'] === 'ambiguous'), 'retainedCensusCharacters' => $retained ?: 0, 'history' => $history ?: 0]];
  if ($dir = getenv('ML_CAPTURE_DIR')) {
    $keep = array_fill_keys(['classFile', 'race', 'realm', 'faction', 'classification', 'level', 'hybrid', 'orderedPoints', 'talents', 'gear'], true);
    $published = $model;
    $published['records'] = array_map(fn($r) => array_intersect_key($r, $keep), $records);
    if (!is_dir($dir . '/models')) mkdir($dir . '/models', 0700, true);
    file_put_contents($dir . '/models/inspects-' . $source . '.json', json_encode($published, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
  }
  return $cache[$source] = $model;
}
