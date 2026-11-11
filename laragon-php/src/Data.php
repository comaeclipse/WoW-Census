<?php
// Census and inspect data for the pages (once a port of the retired Node generator's queries
// and the read side of site/src/inspects.mjs).
// The pages depend on groups arriving in binary key order, so grouped queries
// order explicitly with COLLATE "C"; name matching ignores ASCII case (ILIKE).

declare(strict_types=1);

const REGION = 'us';
const GAMES = [
  'forever' => 'WoW: Forever',
  'classic-progression' => 'TBC Anniversary',
  'classic' => 'Classic Era',
  'retail' => 'Retail',
  'classic-beta' => 'WoW: Forever (Beta)',
  'mop-classic' => 'Mists of Pandaria Classic',
  'sod' => 'Season of Discovery',
  'hardcore' => 'Classic Hardcore',
];
const ITEM_COLUMNS = ['id', 'name', 'slug', 'mv', 'asp', 'q', 'pq', 'sc', 'tc', 'cat', 'src', 'crafter'];
const ZONE_WINDOW_DAYS = 30;

// Old MarketLens collections ("realm:Thunderstrike-Horde") carry no region, but they
// hold the same realms as the region-labelled WoWCensus ones
// ("realm:eu:tbc-anniversary:Thunderstrike-Horde") and share many of their characters.
// The census pages count an old collection as the labelled collection of its region,
// each character there once. Its region is the one all its rows have
// (tools/collection-region-provenance.sql), or for rows of no region the one their
// characters' home realms point to (scan_region_map()). For a realm name in one region
// only, it is also the one region its scans or labelled twins show; a collection of no
// characters takes its realm's other collections' region. Old collections
// of names in two regions without that stay apart. old game => labelled game.
function collection_aliases(): array {
  static $aliases = null;
  if ($aliases !== null) return $aliases;
  $aliases = [];
  // build-census-cache.php stores this function's answer for the current import.
  if (census_cache_fresh()) {
    foreach (Db::all('SELECT old_game, alias FROM census_aliases ORDER BY seq') as $r) $aliases[$r['old_game']] = $r['alias'];
    return $aliases;
  }
  $rows = Db::all("WITH old AS (
      SELECT DISTINCT game, source_game, regexp_replace(substr(game, 7), '-(Alliance|Horde|Neutral)$', '') realm FROM datasets
      WHERE game LIKE 'realm:%' AND game !~ '^realm:(us|eu|kr|tw|cn):'),
    labelled AS (
      SELECT DISTINCT source_game, split_part(game, ':', 2) region, split_part(game, ':', 3) flavor,
        regexp_replace(regexp_replace(game, '^realm:[a-z]{2}:[^:]+:', ''), '-(Alliance|Horde|Neutral)$', '') realm
      FROM datasets WHERE game ~ '^realm:(us|eu|kr|tw|cn):[^:]+:'),
    flavors AS (SELECT source_game, MIN(flavor) flavor FROM labelled GROUP BY source_game HAVING COUNT(DISTINCT flavor) = 1),
    rows AS (SELECT o.game, o.source_game, o.realm, c.collection_region region
      FROM old o JOIN characters_with_region c ON c.game = o.game),
    -- Group the ~500k character rows once. Looking them up per collection
    -- rescanned all of them twice for each one (10 s instead of under 2 s).
    -- MATERIALIZED keeps PostgreSQL from folding these back into per-row lookups.
    by_game AS MATERIALIZED (SELECT game, string_agg(DISTINCT COALESCE(region, '?'), ',') regions FROM rows GROUP BY game),
    by_realm AS MATERIALIZED (SELECT source_game, realm, string_agg(DISTINCT region, ',') regions FROM rows GROUP BY source_game, realm)
    SELECT o.game, o.source_game, o.realm, f.flavor,
      (SELECT g.regions FROM by_game g WHERE g.game = o.game) game_regions,
      (SELECT b.regions FROM by_realm b WHERE b.source_game = o.source_game AND b.realm = o.realm) realm_regions,
      (SELECT string_agg(DISTINCT l.region, ',') FROM labelled l WHERE l.source_game = o.source_game AND l.realm = o.realm) twin_regions
    FROM old o JOIN flavors f ON f.source_game = o.source_game
    ORDER BY o.game COLLATE \"C\"");
  foreach ($rows as $r) {
    $own = array_filter(explode(',', (string)$r['game_regions']));
    $known = array_values(array_diff($own, ['?']));
    // Rows of no region: their characters' home realms can tell (Retail, MoP).
    $homes = in_array('?', $own, true) && count($known) <= 1 && ($groups = connected_realm_groups($r['source_game']))
      ? scan_region_map($r['source_game'], $groups)[$r['game']] ?? null : null;
    if (count($own) === 1 && $own[0] !== '?') $region = $own[0];
    elseif ($homes && (!$known || $known[0] === $homes)) $region = $homes;
    // A collection with no characters (Retail's cross-faction auction scans) goes
    // with the region of the realm's other old collections.
    elseif (!$own && count($sibling = array_filter(explode(',', (string)$r['realm_regions']))) === 1) $region = reset($sibling);
    elseif (!realm_region_shown($r['source_game'], $r['realm'])) {
      $seen = array_unique(array_filter(array_merge(explode(',', (string)$r['realm_regions']), explode(',', (string)$r['twin_regions']))));
      $region = count($seen) === 1 ? reset($seen) : null;
    } else $region = null;
    if ($region) $aliases[$r['game']] = "realm:$region:$r[flavor]:" . substr($r['game'], 6);
  }
  return $aliases;
}

function canonical_game(string $game): string { return collection_aliases()[$game] ?? $game; }

/** SQL for the canonical collection of a game column. */
function canonical_game_sql(string $column): string {
  $cases = '';
  foreach (collection_aliases() as $from => $to)
    $cases .= " WHEN '" . str_replace("'", "''", $from) . "' THEN '" . str_replace("'", "''", $to) . "'";
  return "CASE $column$cases ELSE $column END";
}

/**
 * Pre-computed census sets (laragon-php/build-census-cache.php): census_characters,
 * census_latest, census_sightings and census_seen hold exactly what the *_live_sql()
 * fragments below compute on every page, built once per import. Used only while
 * census_cache_meta.version names the latest import; otherwise (no tables, data
 * imported since, ML_NO_CENSUS_CACHE=1) pages run the live SQL, unchanged.
 *
 * With ML_CENSUS_PUBLISHED=1 (production) the tables are used whatever their
 * version: an upload changes nothing on the site until wowcensus-data-refresh has
 * rebuilt them, so pages never fall back to the slow live SQL in between.
 */
function census_cache_fresh(): bool {
  static $fresh = null;
  if ($fresh !== null) return $fresh;
  if (getenv('ML_NO_CENSUS_CACHE') === '1') return $fresh = false;
  if (getenv('ML_CENSUS_PUBLISHED') === '1') return $fresh = Db::publishedVersion() !== null;
  try {
    $row = Db::uncached("SELECT (SELECT version FROM census_cache_meta) = (SELECT max(imported_at_utc) FROM local_imports) fresh")[0] ?? [];
    return $fresh = !empty($row['fresh']);
  } catch (Throwable $e) {
    return $fresh = false;  // No tables yet.
  }
}

/**
 * characters, one row per character and canonical collection (per character on
 * connected MoP realms), from its latest sighting: filter on rn = 1. Adds cgame.
 */
function census_characters_sql(string $sourceGame): string {
  if (census_cache_fresh())
    return "(SELECT * FROM census_characters WHERE source_game = '" . str_replace("'", "''", $sourceGame) . "')";
  return census_characters_live_sql($sourceGame);
}

/** The newest sighting per canonical collection. */
function census_latest_sql(): string {
  if (census_cache_fresh()) return '(SELECT game, latest FROM census_latest)';
  return '(SELECT ' . canonical_game_sql('game') . ' game, MAX(last_seen) latest FROM characters GROUP BY 1)';
}

/** Editions whose census counts every character ever seen, not only the last CENSUS_WINDOW_DAYS. */
const CENSUS_ALL_OBSERVED = ['classic-beta', 'forever'];

/**
 * load_census()'s four aggregates over an edition's characters, for every edition
 * at once: table name => SELECT. build-census-cache.php stores each as a table, so a
 * page reads a few thousand rows instead of grouping every character (1.2 million
 * on WoW: Forever). $chars and $latest are the census_characters and census_latest
 * tables of the same build. The datasets join keeps out the other editions'
 * collections that connected MoP rows carry, as the live queries do.
 */
function census_rollup_selects(string $chars, string $latest): array {
  $from = "FROM $chars c JOIN datasets d ON d.game = c.game AND d.source_game = c.source_game";
  $all = "'" . implode("','", CENSUS_ALL_OBSERVED) . "'";
  $censusWindow = CENSUS_WINDOW_DAYS * 86400;
  $zoneWindow = ZONE_WINDOW_DAYS * 86400;
  $zone = "COALESCE(NULLIF(TRIM(c.zone), ''), 'Unknown')";
  return [
    'census_combos' =>
      "SELECT c.source_game, c.cgame, c.race, c.class_file cf, COUNT(*)::int n
       $from LEFT JOIN $latest m ON m.game = c.cgame
       WHERE c.rn = 1 AND (c.source_game IN ($all) OR c.last_seen >= m.latest - $censusWindow)
       GROUP BY c.source_game, c.cgame, c.race, c.class_file",
    'census_race_windows' =>
      "SELECT c.source_game, c.cgame, c.race, COUNT(*)::int all_count,
         SUM(CASE WHEN c.last_seen >= m.latest - 604800 THEN 1 ELSE 0 END)::int week_count,
         SUM(CASE WHEN c.last_seen >= m.latest - 86400 THEN 1 ELSE 0 END)::int day_count
       $from JOIN $latest m ON m.game = c.cgame
       WHERE c.rn = 1 AND c.race IS NOT NULL
       GROUP BY c.source_game, c.cgame, c.race",
    'census_guild_counts' =>
      "SELECT c.source_game, c.cgame, c.guild, COUNT(*)::int members
       $from
       WHERE c.rn = 1 AND TRIM(COALESCE(c.guild, '')) <> ''
       GROUP BY c.source_game, c.cgame, c.guild",
    'census_zones' =>
      "SELECT c.source_game, c.cgame, $zone zone, COUNT(*)::int characters,
         CAST(ROUND(AVG(c.level)) AS INT) avg_level, MAX(c.level) max_level
       $from JOIN $latest m ON m.game = c.cgame
       WHERE c.rn = 1 AND c.last_seen >= m.latest - $zoneWindow
       GROUP BY c.source_game, c.cgame, $zone",
  ];
}

/**
 * One edition's rows of a census_rollup_selects() table while the census tables
 * are in use, else (or when the tables were built before the rollups existed)
 * the same rows from $live.
 */
function census_rollup(string $sql, string $sourceGame, callable $live): array {
  if (census_cache_fresh()) {
    try {
      return Db::all($sql, [$sourceGame]);
    } catch (PDOException $e) {
      if ($e->getCode() !== '42P01') throw $e;  // undefined_table: older census build
    }
  }
  return $live();
}

function census_characters_live_sql(string $sourceGame): string {
  $cg = canonical_game_sql('c0.game');
  $partition = $sourceGame === 'mop-classic' ? 'c0.character_key' : "$cg, c0.character_key";
  return "(SELECT c0.*, $cg cgame, ROW_NUMBER() OVER (PARTITION BY $partition ORDER BY c0.last_seen DESC) rn
     FROM characters c0 WHERE c0.source_game = '" . str_replace("'", "''", $sourceGame) . "')";
}
const INSPECT_WINDOW_DAYS = 14;

function api_items(string $game): array {
  if (!str_starts_with($game, 'realm:'))
    return ['game' => $game, 'sourceGame' => $game, 'region' => REGION, 'count' => 0, 'updatedAt' => null, 'days' => 0, 'columns' => ITEM_COLUMNS, 'items' => []];
  // Equal quantities stay in insertion order. items.seq keeps that order: it
  // was numbered when the data first moved to PostgreSQL, and new rows
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
  $allObserved = in_array($sourceGame, CENSUS_ALL_OBSERVED, true);
  $connected = $sourceGame === 'mop-classic';
  $og = canonical_game_sql('o.game');
  $partition = $connected ? 'o.character_key' : "$og, o.character_key";
  $chars = census_characters_sql($sourceGame);
  $latestByGame = census_latest_sql();
  $censusWindow = CENSUS_WINDOW_DAYS * 86400;
  // The census-sweep sightings, ranked per character (both queries below).
  $cached = census_cache_fresh();
  $sightings = "SELECT game, race, cf, t, rn FROM census_sightings WHERE source_game = ?";
  $censusRows = $allObserved ? [] : Db::all(
    "WITH obs AS (" . ($cached ? $sightings : "
       SELECT $og game, o.race race, o.class_file cf, o.observed_at t,
         ROW_NUMBER() OVER (PARTITION BY $partition ORDER BY o.observed_at DESC) rn
       FROM character_location_observations o
       JOIN population_sweeps s
         ON s.game = o.game AND s.source_game = o.source_game AND s.sweep_id = o.sweep_id
       JOIN population_sweep_queries q
         ON q.game = o.game AND q.source_game = o.source_game AND q.sweep_id = o.sweep_id
        AND q.query_index = o.query_index
       JOIN datasets d ON d.game = o.game
       WHERE d.source_game = ? AND s.label ILIKE 'census %' AND COALESCE(q.filter, '') NOT ILIKE '%z-%'
     ") . "), latest AS MATERIALIZED (SELECT game, MAX(t) t FROM obs GROUP BY game)
     SELECT obs.game game, obs.race race, obs.cf cf, COUNT(*) n
     FROM obs JOIN latest l ON l.game = obs.game
     WHERE obs.rn = 1 AND obs.t >= l.t - ?
     GROUP BY obs.game, obs.race, obs.cf
     ORDER BY obs.game COLLATE \"C\", obs.race COLLATE \"C\" NULLS FIRST, obs.cf COLLATE \"C\" NULLS FIRST", [$sourceGame, $censusWindow]);
  $censused = [];
  foreach ($censusRows as $r) $censused[$r['game']] = true;
  // The four aggregates over the edition's characters come from the census build's
  // rollup tables (census_rollup_selects()); the live SQL beside each is the fallback.
  $sightingRows = array_values(array_filter(census_rollup(
    'SELECT cgame game, race, cf, n FROM census_combos WHERE source_game = ?
     ORDER BY cgame COLLATE "C", race COLLATE "C" NULLS FIRST, cf COLLATE "C" NULLS FIRST', $sourceGame, fn() => Db::all(
    "SELECT c.cgame game, c.race race, c.class_file cf, COUNT(*) n
     FROM $chars c JOIN datasets d ON d.game = c.game
     " . ($allObserved ? '' : "JOIN $latestByGame m ON m.game = c.cgame") . "
     WHERE d.source_game = ? AND c.rn = 1 " . ($allObserved ? '' : 'AND c.last_seen >= m.latest - ?') . "
     GROUP BY c.cgame, c.race, c.class_file
     ORDER BY c.cgame COLLATE \"C\", c.race COLLATE \"C\" NULLS FIRST, c.class_file COLLATE \"C\" NULLS FIRST",
    $allObserved ? [$sourceGame] : [$sourceGame, $censusWindow])), fn($r) => !isset($censused[$r['game']])));
  $rows = array_merge($censusRows, $sightingRows);
  $raceCensusRows = $allObserved ? [] : Db::all(
    "WITH obs AS (" . ($cached ? $sightings : "
       SELECT $og game, o.race, o.observed_at t,
         ROW_NUMBER() OVER (PARTITION BY $partition ORDER BY o.observed_at DESC) rn
       FROM character_location_observations o
       JOIN population_sweeps s ON s.game=o.game AND s.source_game=o.source_game AND s.sweep_id=o.sweep_id
       JOIN population_sweep_queries q ON q.game=o.game AND q.source_game=o.source_game AND q.sweep_id=o.sweep_id AND q.query_index=o.query_index
       JOIN datasets d ON d.game=o.game
       WHERE d.source_game=? AND s.label ILIKE 'census %' AND COALESCE(q.filter,'') NOT ILIKE '%z-%'
     ") . "), latest AS MATERIALIZED (SELECT game, MAX(t) t FROM obs GROUP BY game)
     SELECT o.game, o.race, COUNT(*) all_count,
       SUM(CASE WHEN o.t>=l.t-604800 THEN 1 ELSE 0 END) week_count,
       SUM(CASE WHEN o.t>=l.t-86400 THEN 1 ELSE 0 END) day_count
     FROM obs o JOIN latest l ON l.game=o.game
     WHERE o.rn=1 AND o.race IS NOT NULL
     GROUP BY o.game,o.race
     ORDER BY o.game COLLATE \"C\", o.race COLLATE \"C\"", [$sourceGame]);
  $raceSightingRows = array_values(array_filter(census_rollup(
    'SELECT cgame game, race, all_count, week_count, day_count FROM census_race_windows WHERE source_game = ?
     ORDER BY cgame COLLATE "C", race COLLATE "C"', $sourceGame, fn() => Db::all(
    "SELECT c.cgame game, c.race, COUNT(*) all_count,
       SUM(CASE WHEN c.last_seen>=m.latest-604800 THEN 1 ELSE 0 END) week_count,
       SUM(CASE WHEN c.last_seen>=m.latest-86400 THEN 1 ELSE 0 END) day_count
     FROM $chars c JOIN datasets d ON d.game=c.game
     JOIN $latestByGame m ON m.game=c.cgame
     WHERE d.source_game=? AND c.rn=1 AND c.race IS NOT NULL
     GROUP BY c.cgame,c.race
     ORDER BY c.cgame COLLATE \"C\", c.race COLLATE \"C\"", [$sourceGame])), fn($r) => !isset($censused[$r['game']])));
  $raceWindowRows = array_merge($raceCensusRows, $raceSightingRows);
  $scans = Db::all(
    'SELECT ' . canonical_game_sql('p.game') . ' game, COUNT(*) samples, SUM(p.observed) observed, MAX(p.t) "lastT"
     FROM pop_samples p JOIN datasets d ON d.game = p.game
     WHERE d.source_game = ? GROUP BY ' . canonical_game_sql('p.game') . ' ORDER BY ' . canonical_game_sql('p.game') . ' COLLATE "C"', [$sourceGame]);
  $guilds = census_rollup(
    'SELECT cgame game, guild, members FROM census_guild_counts WHERE source_game = ?
     ORDER BY cgame COLLATE "C", guild COLLATE "C"', $sourceGame, fn() => Db::all(
    "SELECT c.cgame game, c.guild guild, COUNT(*) members
     FROM $chars c
     JOIN datasets d ON d.game = c.game
     WHERE d.source_game = ? AND c.rn=1 AND TRIM(COALESCE(c.guild, '')) <> ''
     GROUP BY c.cgame, c.guild
     ORDER BY c.cgame COLLATE \"C\", c.guild COLLATE \"C\"", [$sourceGame]));
  $zones = census_rollup(
    'SELECT cgame game, zone, characters, avg_level "avgLevel", max_level "maxLevel" FROM census_zones WHERE source_game = ?
     ORDER BY cgame COLLATE "C", zone COLLATE "C"', $sourceGame, fn() => Db::all(
    "SELECT c.cgame game, COALESCE(NULLIF(TRIM(c.zone), ''), 'Unknown') zone,
       COUNT(*) characters, CAST(ROUND(AVG(c.level)) AS INT) \"avgLevel\", MAX(c.level) \"maxLevel\"
     FROM $chars c JOIN datasets d ON d.game = c.game
     JOIN $latestByGame m ON m.game = c.cgame
     WHERE d.source_game = ? AND c.rn=1 AND c.last_seen >= m.latest - ?
     GROUP BY c.cgame, COALESCE(NULLIF(TRIM(c.zone), ''), 'Unknown')
     ORDER BY c.cgame COLLATE \"C\", COALESCE(NULLIF(TRIM(c.zone), ''), 'Unknown') COLLATE \"C\"", [$sourceGame, ZONE_WINDOW_DAYS * 86400]));

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

// Distinct players inspected in the inspect window, without load_inspects' record cap.
// Null when published query data predates this count, so the page just omits it.
function recent_inspect_count(string $source): ?int {
  try {
    $row = Db::first('SELECT COUNT(DISTINCT guid) n FROM character_inspects WHERE source_game=?
      AND captured_at>=(SELECT MAX(captured_at) FROM character_inspects WHERE source_game=?)-?',
      [$source, $source, INSPECT_WINDOW_DAYS * 86400]);
  } catch (RuntimeException $e) {
    return null;
  }
  return $row['n'] ?? null;
}

/** Every sighting day of every character of one edition (three ? = the edition). */
function seen_sightings_live_sql(): string {
  [$og, $cg] = [canonical_game_sql('o.game'), canonical_game_sql('c.game')];
  return "SELECT $og g, o.character_key k, o.observed_at / 86400 dy FROM character_location_observations o
       JOIN datasets d ON d.game = o.game WHERE d.source_game = ?
     UNION SELECT $cg, c.character_key, c.first_seen / 86400 FROM characters c JOIN datasets d ON d.game = c.game WHERE d.source_game = ?
     UNION SELECT $cg, c.character_key, c.last_seen / 86400 FROM characters c JOIN datasets d ON d.game = c.game WHERE d.source_game = ?";
}

/** Census-sweep sightings of one edition, ranked per character (one ? = the edition). */
function census_sightings_live_sql(string $sourceGame): string {
  $og = canonical_game_sql('o.game');
  $partition = $sourceGame === 'mop-classic' ? 'o.character_key' : "$og, o.character_key";
  return "SELECT $og game, o.race race, o.class_file cf, o.observed_at t,
         ROW_NUMBER() OVER (PARTITION BY $partition ORDER BY o.observed_at DESC) rn
       FROM character_location_observations o
       JOIN population_sweeps s
         ON s.game = o.game AND s.source_game = o.source_game AND s.sweep_id = o.sweep_id
       JOIN population_sweep_queries q
         ON q.game = o.game AND q.source_game = o.source_game AND q.sweep_id = o.sweep_id
        AND q.query_index = o.query_index
       JOIN datasets d ON d.game = o.game
       WHERE d.source_game = ? AND s.label ILIKE 'census %' AND COALESCE(q.filter, '') NOT ILIKE '%z-%'";
}

// Distinct characters seen in the last 7 and 30 days and overall, each as a
// running total by UTC day (the banner's "seen recently" chart), per-day distinct
// counts for the last 14 days ('daily', the front page's trend), and with the
// count for the same span just before it, for a change figure. A sighting is a
// /who observation or a character's first or last sighting. Days count back
// from the newest sighting, not from today. Null when published query data
// predates this, so the banner just omits it.
function seen_activity(string $source): ?array {
  try {
    if (census_cache_fresh()) {
      // build-census-cache.php ran seen_activity_rows() for this import.
      $row = Db::first('SELECT firsts, spans FROM census_seen_rows WHERE source_game = ?', [$source]);
      $firsts = $row ? json_decode($row['firsts'], true, 512, JSON_THROW_ON_ERROR) : [];
      $spans = $row ? json_decode($row['spans'], true, 512, JSON_THROW_ON_ERROR) : null;
    } else {
      [$firsts, $spans] = seen_activity_rows(seen_sightings_live_sql(), [$source, $source, $source]);
    }
  } catch (RuntimeException $e) {
    return null;
  }
  if (!$firsts || !$spans) return null;
  $new = [];
  foreach ($firsts as $r) $new[$r['span']][$r['dy']] = $r['n'];
  $first = (int)array_key_first($new['all']);
  $last = (int)array_key_last($new['all']);
  $span = function (string $key, int $from, string $n, string $p) use ($new, $last, $spans): array {
    $total = 0;
    $running = [];
    foreach (range($from, $last) as $d) $running[] = $total += $new[$key][$d] ?? 0;
    return ['n' => $spans[$n], 'prev' => $spans[$p], 'firstDay' => $from, 'running' => $running];
  };
  // Distinct characters seen on each of the last 14 days (the front page's trend line);
  // null when the cache predates the 'day' span.
  $daily = isset($new['day']) ? array_map(fn($d) => $new['day'][$d] ?? 0, range($last - 13, $last)) : null;
  return [
    'lastDay' => $last,
    'daily' => $daily,
    'all' => $span('all', $first, 'nall', 'pall'),
    '30' => $span('30', max($first, $last - 29), 'n30', 'p30'),
    '7' => $span('7', max($first, $last - 6), 'n7', 'p7'),
  ];
}

/**
 * seen_activity()'s two query results over a sightings query (columns g, k, dy):
 * [new characters per day per span, distinct-character counts per span].
 */
function seen_activity_rows(string $sightings, array $params): array {
    // New characters per day within each span: a character counts on its first sighting day in the span.
    $firsts = Db::all("WITH s AS ($sightings), l AS (SELECT MAX(dy) dy FROM s),
      f AS (SELECT MIN(s.dy) a, MIN(s.dy) FILTER (WHERE s.dy > l.dy - 30) m30, MIN(s.dy) FILTER (WHERE s.dy > l.dy - 7) m7
        FROM s, l GROUP BY s.g, s.k, l.dy)
      SELECT 'all' span, a dy, COUNT(*) n FROM f GROUP BY a
      UNION ALL SELECT '30', m30, COUNT(*) FROM f WHERE m30 IS NOT NULL GROUP BY m30
      UNION ALL SELECT '7', m7, COUNT(*) FROM f WHERE m7 IS NOT NULL GROUP BY m7
      UNION ALL SELECT 'day', s.dy, COUNT(DISTINCT (s.g, s.k)) FROM s, l WHERE s.dy > l.dy - 14 GROUP BY s.dy
      ORDER BY 1, 2", $params);
    $spans = Db::first("WITH s AS ($sightings), l AS (SELECT MAX(dy) dy FROM s)
      SELECT COUNT(DISTINCT (s.g, s.k)) FILTER (WHERE s.dy > l.dy - 7) n7,
        COUNT(DISTINCT (s.g, s.k)) FILTER (WHERE s.dy > l.dy - 14 AND s.dy <= l.dy - 7) p7,
        COUNT(DISTINCT (s.g, s.k)) FILTER (WHERE s.dy > l.dy - 30) n30,
        COUNT(DISTINCT (s.g, s.k)) FILTER (WHERE s.dy > l.dy - 60 AND s.dy <= l.dy - 30) p30,
        COUNT(DISTINCT (s.g, s.k)) nall,
        COUNT(DISTINCT (s.g, s.k)) FILTER (WHERE s.dy <= l.dy - 30) pall
      FROM s, l", $params);
    return [$firsts, $spans];
}

function load_inspects(string $source): array {
  static $cache = [];
  if (isset($cache[$source])) return $cache[$source];
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
  return $cache[$source] = $model;
}
