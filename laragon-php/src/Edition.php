<?php
// Port of site/src/edition.mjs: editions, navigation, data shaping per page,
// and the page dispatcher. Models are built on demand from PostgreSQL.

declare(strict_types=1);

const SITE_ORIGIN = 'https://wowcensus.io';
const PAGES = ['index', 'combos', 'auctionhouse', 'guilds', 'geography', 'talents', 'gear'];

const EDITIONS = [
  'classic-beta' => ['dir' => '', 'label' => 'WoW Forever', 'nav' => 'Forever', 'flavor' => 'classic-beta', 'branch' => 'forever', 'title' => 'WoW Forever & Classic+ Population Census – WoWCensus', 'description' => 'WoWCensus - World of Warcraft: Forever and Classic+ population tracker. Realm population, faction balance, and race/class breakdowns from in-game /who scans.', 'comboBlurb' => 'Forever Beta is still evolving, so this snapshot is most useful for reading the current visible community rather than a settled long-term meta.'],
  'classic-progression' => ['dir' => 'tbc', 'label' => 'TBC Anniversary', 'nav' => 'TBC Anniversary', 'flavor' => 'tbc-anniversary', 'branch' => 'tbc', 'comboBlurb' => 'TBC Anniversary’s smaller era roster makes race and class choices a direct view of the currently visible progression community.'],
  'classic' => ['dir' => 'classic', 'label' => 'Classic Era', 'nav' => 'Classic Era', 'flavor' => 'classic-era', 'branch' => 'classic', 'comboBlurb' => 'Classic Era keeps the original-era roster, so the mix reflects the characters currently active in its long-lived realms rather than modern class availability.'],
  'sod' => ['dir' => 'sod', 'label' => 'Season of Discovery', 'nav' => 'SoD', 'flavor' => 'sod', 'branch' => 'classic', 'comboBlurb' => 'Season of Discovery class balance and player activity can shift sharply between phases, so treat the mix as a current activity signal rather than a durable meta ranking.'],
  'hardcore' => ['dir' => 'hardcore', 'label' => 'Classic Hardcore', 'nav' => 'Hardcore', 'flavor' => 'hardcore', 'branch' => 'classic', 'comboBlurb' => "Hardcore's one-life rules shape who appears in each sample. These are observed characters from in-game scans, not a count of active accounts."],
  'mop-classic' => ['dir' => 'mop', 'label' => 'Mists of Pandaria Classic', 'nav' => 'MoP Classic', 'flavor' => 'mop-classic', 'branch' => 'mop-classic', 'connectedMarket' => true, 'comboAnalysisText' => "Human and Blood Elf Paladins remain the most common race/class combinations, while Hunters lead overall across both factions. Compared with TBC Anniversary, MoP's population is spread more evenly across character choices, with Monks adding variety without overtaking the established favorites.\n\nActivity spans both endgame and leveling content: Siege of Orgrimmar leads individual locations, but leveling areas collectively account for slightly more recent character sightings than endgame areas."],
  'retail' => ['dir' => 'retail', 'label' => 'Retail', 'nav' => 'Retail', 'flavor' => 'retail', 'branch' => '', 'factionlessMarket' => true, 'marketMetric' => 'quantity', 'comboBlurb' => 'Retail’s broad modern roster and cross-faction play make this a view of the current population mix.'],
];

function edition_path(string $source): string {
  $dir = EDITIONS[$source]['dir'];
  return $dir !== '' ? '/' . $dir . '/' : '/';
}

function source_for_path(string $dir): ?string {
  foreach (EDITIONS as $source => $_) if (edition_path($source) === $dir) return $source;
  return null;
}

function page_path(string $source, string $page): string {
  $root = edition_path($source);
  return $page === 'index' ? $root : $root . $page;
}

function utc_timestamp(mixed $value): string {
  if (!$value) return 'unavailable';
  if (is_int($value) || is_float($value)) return str_replace('T', ' ', substr(js_iso($value), 0, 16)) . 'Z';
  try { $date = new DateTimeImmutable((string)$value); } catch (Exception) { return 'unavailable'; }
  return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i') . 'Z';
}

function edition_nav(string $source, string $current): array {
  $edition = EDITIONS[$source];
  $currentPage = preg_replace('/\.html$/', '', $current);
  $pageLabels = ['index' => 'Census', 'combos' => 'Race + Class', 'realms' => 'Realms', 'auctionhouse' => 'Auction House', 'guilds' => 'Guilds', 'geography' => 'Geography', 'talents' => 'Talents', 'gear' => 'Gear'];
  $item = fn(string $href, string $label, ?bool $active = null) =>
    '<a class="game"' . (($active ?? ($href === $currentPage)) ? ' aria-current="true"' : '') . ' href="' . $href . '">' . $label . '</a>';
  $games = '<nav class="games game-nav" aria-label="Game edition">';
  foreach (EDITIONS as $s => $e) $games .= $item(page_path($s, $currentPage), $e['nav'], $s === $source);
  $games .= '</nav>';
  $pages = '<nav class="games page-nav" aria-label="Site pages">' .
    $item(page_path($source, 'index'), 'Census', $currentPage === 'index' || $currentPage === 'combos') .
    $item(page_path($source, 'realms'), 'Realms', $currentPage === 'realms') .
    $item(page_path($source, 'auctionhouse'), 'Auction House', $currentPage === 'auctionhouse') .
    $item(page_path($source, 'guilds'), 'Guilds', $currentPage === 'guilds') .
    $item(page_path($source, 'geography'), 'Geography', $currentPage === 'geography') .
    $item(page_path($source, 'talents'), 'Talents', $currentPage === 'talents') .
    $item(page_path($source, 'gear'), 'Gear', $currentPage === 'gear') . '</nav>';
  return [
    'games' => '<div class="site-nav-stack">' . $games . $pages . '</div>',
    'pages' => '',
    'breadcrumbs' => '<nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">WoWCensus</a><span aria-hidden="true">/</span><a href="' .
      page_path($source, 'index') . '">' . $edition['nav'] . '</a><span aria-hidden="true">/</span><span aria-current="page">' .
      $pageLabels[$currentPage] . '</span></nav>',
  ];
}

// Chip labels for realms: drop the shared "Classic Beta" prefix only while the
// shortened labels stay distinct.
function realm_labels(array $names): array {
  $short = fn($n) => ($s = trim(preg_replace('/^Classic Beta\s+/i', '', $n))) !== '' ? $s : $n;
  $distinct = count(array_unique(array_map('realm_name', $names))) === count($names);
  $out = [];
  foreach ($names as $n) $out[$n] = $distinct ? realm_name($n) : $short($n);
  return $out;
}

// Merge realm item snapshots into one market view.
function merge_items(array $perRealm): array {
  $byId = [];
  foreach ($perRealm as $snap) {
    $columns = $snap['columns'];
    $col = fn($name) => ($i = array_search($name, $columns, true)) === false ? -1 : $i;
    [$iId, $iName, $iMv, $iAsp, $iQ, $iPq, $iCat] = array_map($col, ['id', 'name', 'mv', 'asp', 'q', 'pq', 'cat']);
    foreach ($snap['items'] as $row) {
      $id = $row[$iId];
      $cur = $byId[$id] ?? ['id' => $id, 'name' => $row[$iName], 'cat' => $row[$iCat], 'q' => 0, 'pq' => 0,
        'hasPreviousQty' => true, 'mv' => 0, 'asp' => 0];
      $cur['q'] += $row[$iQ] ?: 0;
      if ($iPq < 0 || $row[$iPq] === null) $cur['hasPreviousQty'] = false;
      else $cur['pq'] += $row[$iPq] ?: 0;
      $cur['mv'] += $row[$iMv] ?: 0;
      if (preg_match('/^item:\d+$/', (string)$cur['name']) && !preg_match('/^item:\d+$/', (string)$row[$iName])) $cur['name'] = $row[$iName];
      if (!$cur['cat']) $cur['cat'] = $row[$iCat];
      $byId[$id] = $cur;
    }
  }
  $out = [];
  foreach ($byId as $it) {
    $it['asp'] = $it['q'] > 0 ? js_round($it['mv'] / $it['q']) : 0;
    if (!$it['hasPreviousQty']) $it['pq'] = null;
    unset($it['hasPreviousQty']);
    $out[] = $it;
  }
  return $out;
}

function edition_snaps(string $source): array {
  static $cache = [];
  if (isset($cache[$source])) return $cache[$source];
  $snaps = [];
  foreach (api_games() as $g) {
    if ($g['sourceGame'] !== $source || !$g['realm'] || !$g['hasItems']) continue;
    $snap = api_items($g['game']);
    $realm = preg_replace('/^realm:/', '', $g['game']);
    $snaps[] = ['realm' => $realm, 'faction' => preg_match('/-(Alliance|Horde)$/', $realm, $m) ? $m[1] : 'Neutral',
      'items' => $snap['items'] ?? [], 'columns' => $snap['columns'], 'updatedAt' => $snap['updatedAt']];
  }
  return $cache[$source] = $snaps;
}

function edition_dims(string $source): array {
  $edition = EDITIONS[$source];
  $census = load_census($source);
  $betaRealms = [];
  foreach (api_games() as $g)
    if ($g['sourceGame'] === $source && $g['realm']) $betaRealms[] = preg_replace('/^realm:/', '', $g['game']);
  $factions = [];
  foreach ($census['units'] as $u) if ($u['faction'] !== '' && $u['faction'] !== 'Unknown') $factions[$u['faction']] = true;
  $factions = array_map('strval', array_keys($factions)); sort($factions, SORT_STRING);
  $realmNames = [];
  foreach ($betaRealms as $r) $realmNames[preg_replace('/-(Alliance|Horde)$/', '', $r)] = true;
  $realmNames = array_map('strval', array_keys($realmNames)); sort($realmNames, SORT_STRING);
  $labels = realm_labels($realmNames);
  $connected = !empty($edition['connectedMarket']);
  $realmDim = [['key' => 'all', 'label' => !empty($edition['factionlessMarket']) ? 'Latest regional scan' : ($connected ? 'Connected group' : 'All realms')]];
  foreach ($realmNames as $r) $realmDim[] = ['key' => $r, 'label' => $labels[$r] . ($connected ? ' scan' : '')];
  $factionDim = [['key' => 'all', 'label' => 'Both']];
  foreach ($factions as $f) $factionDim[] = ['key' => $f, 'label' => $f];
  return ['dims' => ['realm' => $realmDim, 'faction' => $factionDim], 'betaRealms' => $betaRealms];
}

function model_auctionhouse(string $source): array {
  $edition = EDITIONS[$source];
  ['dims' => $dims, 'betaRealms' => $betaRealms] = edition_dims($source);
  $snaps = edition_snaps($source);
  $newest = function (array $captures): array {
    $latest = $captures[0];
    foreach (array_slice($captures, 1) as $snap)
      if (strcmp((string)($snap['updatedAt'] ?? ''), (string)($latest['updatedAt'] ?? '')) > 0) $latest = $snap;
    return $latest;
  };
  $snapshotOf = function (array $from, array $fallbackRealms) use ($edition, $newest): array {
    if (!empty($edition['factionlessMarket']) && count($from) > 1) $selected = [$newest($from)];
    elseif (!empty($edition['connectedMarket']) && count($from) > 1) {
      $selected = [];
      foreach (array_unique(array_map(fn($s) => $s['faction'], $from)) as $faction)
        $selected[] = $newest(array_values(array_filter($from, fn($s) => $s['faction'] === $faction)));
    } else $selected = $from;
    $updated = array_values(array_filter(array_map(fn($s) => $s['updatedAt'], $selected)));
    sort($updated, SORT_STRING);
    return [
      'items' => merge_items($selected),
      'realms' => $selected ? array_map(fn($s) => $s['realm'], $selected) : $fallbackRealms,
      'updatedAt' => $updated ? end($updated) : null,
      'branch' => $edition['branch'], 'uploadFlavor' => $edition['flavor'],
      'aggregateOnly' => ($edition['marketMetric'] ?? null) === 'quantity',
    ];
  };
  $marketDims = !empty($edition['factionlessMarket'])
    ? ['realm' => $dims['realm'], 'faction' => [['key' => 'all', 'label' => 'Cross-faction']]]
    : (!empty($edition['connectedMarket'])
      ? ['realm' => array_merge([['key' => $dims['realm'][0]['key'], 'label' => 'Latest connected scan']], array_slice($dims['realm'], 1)), 'faction' => $dims['faction']]
      : $dims);
  $names = item_cache('item-names', $edition['branch']);
  $savedNames = [];
  // SQLite's bare `name` under GROUP BY item_id came from the first row in its
  // index order (game, newest capture first).
  foreach (Db::all("SELECT DISTINCT ON (item_id) item_id, name FROM local_auction_snapshots
      WHERE source_game=? AND name!='' AND name NOT ILIKE 'item:%' ORDER BY item_id, game COLLATE \"C\", captured_at DESC", [$source]) as $row)
    $savedNames[$row['item_id']] = $row['name'];
  $views = [];
  foreach ($marketDims['realm'] as $r) {
    foreach ($marketDims['faction'] as $f) {
      $from = array_values(array_filter($snaps, fn($s) =>
        ($r['key'] === 'all' || preg_replace('/-(Alliance|Horde)$/', '', $s['realm']) === $r['key']) &&
        ($f['key'] === 'all' || $s['faction'] === $f['key'])));
      $fallback = array_values(array_filter($betaRealms, fn($name) =>
        ($r['key'] === 'all' || preg_replace('/-(Alliance|Horde)$/', '', $name) === $r['key']) &&
        ($f['key'] === 'all' || str_ends_with($name, '-' . $f['key']))));
      $snapshot = $snapshotOf($from, $fallback);
      foreach ($snapshot['items'] as &$item)
        if (preg_match('/^item:\d+$/', (string)$item['name'])) $item['name'] = ($names[$item['id']] ?? null) ?: (($savedNames[$item['id']] ?? null) ?: $item['name']);
      unset($item);
      $views[] = ['realm' => $r['key'], 'faction' => $f['key'], 'snapshot' => $snapshot];
    }
  }
  return ['views' => $views, 'dims' => $marketDims];
}

function item_cache(string $kind, string $branch): array {
  // A branch without a cache file has nothing to fill in.
  try { $file = resource_path("$kind/" . ($branch !== '' ? $branch : 'retail') . '.json'); }
  catch (RuntimeException) { return []; }
  return json_decode(file_get_contents($file), true);
}

function model_guilds_geography(string $source, string $page): array {
  $census = load_census($source);
  ['dims' => $dims] = edition_dims($source);
  $views = [];
  foreach ($dims['realm'] as $r) {
    foreach ($dims['faction'] as $f) {
      $from = array_values(array_filter($census['units'], fn($u) =>
        ($r['key'] === 'all' || $u['realm'] === $r['key']) && ($f['key'] === 'all' || $u['faction'] === $f['key'])));
      $realms = array_values(array_unique(array_map(fn($u) => $u['realm'], $from)));
      if ($page === 'guilds') {
        $guilds = [];
        foreach ($from as $u) foreach ($u['guilds'] as $g)
          $guilds[] = ['name' => $g['name'], 'members' => $g['members'], 'realm' => $u['realm'], 'faction' => $u['faction']];
        $guilds = js_sort($guilds, fn($a, $b) => js_or($b['members'] - $a['members'], fn() => js_cmp($a['name'], $b['name'])));
        $views[] = ['realm' => $r['key'], 'faction' => $f['key'], 'snapshot' => [
          'guilds' => $guilds,
          'guildedCharacters' => array_sum(array_map(fn($g) => $g['members'], $guilds)),
          'surveyedCharacters' => array_sum(array_map(fn($u) => $u['characters'] ?: 0, $from)),
          'realms' => $realms,
          'lastT' => $census['lastT'] ?: 0,
        ]];
      } else {
        $byZone = [];
        foreach ($from as $u) foreach ($u['zones'] as $z) {
          $cur = $byZone[$z['name']] ?? ['name' => $z['name'], 'characters' => 0, 'levelSum' => 0, 'maxLevel' => 0];
          $cur['characters'] += $z['characters'] ?: 0;
          $cur['levelSum'] += ($z['avgLevel'] ?: 0) * ($z['characters'] ?: 0);
          $cur['maxLevel'] = max($cur['maxLevel'], $z['maxLevel'] ?: 0);
          $byZone[$z['name']] = $cur;
        }
        $zones = js_sort(array_map(fn($z) => ['name' => (string)$z['name'], 'characters' => $z['characters'],
          'avgLevel' => $z['characters'] ? js_round($z['levelSum'] / $z['characters']) : 0, 'maxLevel' => $z['maxLevel']], array_values($byZone)),
          fn($a, $b) => js_or($b['characters'] - $a['characters'], fn() => js_cmp($a['name'], $b['name'])));
        $views[] = ['realm' => $r['key'], 'faction' => $f['key'], 'snapshot' => [
          'zones' => $zones,
          'characters' => array_sum(array_map(fn($u) => $u['zoneCharacters'] ?: 0, $from)),
          'realms' => $realms, 'lastT' => $census['lastT'] ?: 0,
          'windowDays' => $census['zoneWindowDays'] ?: 0,
        ]];
      }
    }
  }
  return ['views' => $views, 'dims' => $dims, 'lastT' => $census['lastT']];
}

function census_views(string $source): array {
  $edition = EDITIONS[$source];
  $census = load_census($source);
  $units = $census['units'];
  $connected = !empty($edition['connectedMarket']);
  $realms = array_values(array_unique(array_map(fn($u) => $u['realm'], $units)));
  sort($realms, SORT_STRING);
  $view = function (string $key, string $label, array $units) use ($census): array {
    $realms = array_values(array_unique(array_map(fn($u) => $u['realm'], $units)));
    sort($realms, SORT_STRING);
    return ['key' => $key, 'label' => $label, 'census' => ['groups' => merge_census_units($units), 'realms' => $realms, 'lastT' => $census['lastT']]];
  };
  if (!$units) return [['key' => 'all', 'label' => $connected ? 'Connected group' : 'All realms', 'census' => $census]];
  $labels = realm_labels($realms);
  $views = [$view('all', $connected ? 'Connected group' : 'All realms', $units)];
  foreach ($realms as $r)
    $views[] = $view($r, $labels[$r] . ($connected ? ' scan' : ''), array_values(array_filter($units, fn($u) => $u['realm'] === $r)));
  return $views;
}

// Inspect records with gear icons from the local cache, as the static build applied them.
function inspects_with_icons(string $source): array {
  $inspects = load_inspects($source);
  $icons = item_cache('item-icons', EDITIONS[$source]['branch']);
  foreach ($inspects['records'] as &$record)
    foreach ($record['gear'] as &$item)
      if (empty($item['icon'])) $item['icon'] = ($icons[$item['id']] ?? null) ?: null;
  unset($record, $item);
  return $inspects;
}

// The filter data file a page fetches, or null.
function render_edition_data(string $source, string $page): ?array {
  if ($page === 'talents') return talent_data(load_inspects($source));
  if ($page === 'gear') {
    $inspects = inspects_with_icons($source);
    foreach ($inspects['records'] as $r) if ($r['gear']) return gear_data($inspects, EDITIONS[$source]['branch']);
  }
  return null;
}

function render_edition_page(string $source, string $page, string $stylesheet, string $generatedAt): string {
  $edition = EDITIONS[$source];
  $generatedNote = 'Page generated: ' . utc_timestamp($generatedAt);
  $navigation = edition_nav($source, $page . '.html');
  if (!empty($edition['connectedMarket'])) $navigation['breadcrumbs'] .=
    '<p class="hint">Galakras and Nazgrim tabs show scan origins within the Pagle connected group. Shared character identities count once; the auction overview uses the newest scan per faction.</p>';
  $common = ['stylesheet' => $stylesheet, 'nav' => $navigation, 'generatedNote' => $generatedNote, 'gameLabel' => $edition['label'],
    'canonical' => SITE_ORIGIN . page_path($source, $page)];
  switch ($page) {
    case 'index':
      $census = load_census($source);
      $inspects = load_inspects($source);
      return render_census_html(census_views($source), $common + [
        'mobileHome' => true,
        'comboHref' => page_path($source, 'combos'),
        'raceProfiles' => race_profiles()[$source] ?? null,
        'classProfiles' => class_profiles()[$source] ?? null,
        'classRoute' => fn($classFile) => class_route($source, $classFile),
        'roleSplit' => in_array($source, ['retail', 'classic-progression', 'mop-classic', 'classic'], true) ? role_split($inspects) : null,
        'inspectCoverage' => render_inspect_coverage(['coverage' => $inspects['coverage'], 'lastT' => $inspects['lastT']], page_path($source, 'talents')),
        'notes' => ['Latest census observation: ' . utc_timestamp($census['lastT'])],
        'uploadFlavor' => $edition['flavor'],
        'title' => $edition['title'] ?? null, 'description' => $edition['description'] ?? null,
        'headingTopic' => $source === 'mop-classic' ? 'MoP Classic' : null,
      ]);
    case 'combos':
      $census = load_census($source);
      return render_combo_breakdown_html(census_views($source)[0]['census'], $common + [
        'notes' => ['Latest census observation: ' . utc_timestamp($census['lastT'])], 'flavorNote' => $edition['comboBlurb'] ?? null,
        'analysisText' => $edition['comboAnalysisText'] ?? null, 'raceProfiles' => race_profiles()[$source] ?? null,
      ]);
    case 'auctionhouse':
      $model = model_auctionhouse($source);
      $first = $model['views'][0] ?? null;
      return render_market_html($model['views'], $model['dims'], $common + [
        'notes' => ['Latest auction scan: ' . utc_timestamp($first ? $first['snapshot']['updatedAt'] : null)],
        'tbcAnalysis' => $source === 'classic-progression',
      ]);
    case 'guilds':
      $model = model_guilds_geography($source, 'guilds');
      return render_guild_html($model['views'], $model['dims'], $common + ['notes' => ['Latest census observation: ' . utc_timestamp($model['lastT'])]]);
    case 'geography':
      $model = model_guilds_geography($source, 'geography');
      return render_geography_html($model['views'], $model['dims'], $common + ['notes' => ['Latest location observation: ' . utc_timestamp($model['lastT'])]]);
    case 'talents':
      $inspects = load_inspects($source);
      $observed = [];
      foreach ($inspects['records'] as $record) $observed[$record['classFile']] = true;
      $profileClasses = class_profiles()[$source]['classes'] ?? null;
      $classicOrder = class_profiles()['classic-beta']['classes'];
      $classes = $profileClasses ?? array_values(array_filter(
        array_merge($classicOrder, array_values(array_filter(array_keys(CLASS_META), fn($cf) => !in_array($cf, $classicOrder, true)))),
        fn($cf) => isset($observed[$cf])));
      return render_talents_html($inspects, $common + [
        'classTiles' => array_map(fn($cf) => ['classFile' => $cf, 'label' => CLASS_META[$cf][0] ?? $cf,
          'path' => $profileClasses ? class_route($source, $cf) : null,
          'color' => '#' . (CLASS_META[$cf][1] ?? 'ffce43'), 'icon' => 'classicon_' . js_lower($cf)], $classes)]);
    case 'gear':
      return render_gear_html(inspects_with_icons($source), $common + ['branch' => $edition['branch']]);
  }
  throw new RuntimeException('unknown page: ' . $page);
}
