<?php
// Realm explorer (/<edition>/realms): every scanned realm of an edition with its
// characters, faction balance, class and race mix and top race + class pairs.
// Built from the mockup in mockups/realms-src/. The page ships its data as JSON
// and realms/realms.js draws the table and the selected realm's detail.

declare(strict_types=1);

// SoD realms appear among the Classic Era client's census units.
const SOD_REALMS = ['Crusader Strike', 'Living Flame', 'Lone Wolf', 'Wild Growth', 'Chaos Bolt', 'Lava Lash'];

// Legacy Retail scan buckets did not record a region. Verified on 2026-10-19
// with five recent character profile lookups per bucket against Blizzard's
// regional APIs (four for Chants eternels). Use these only when the stored
// collection region is unknown; explicit SavedVariables provenance wins.
const RETAIL_VERIFIED_COLLECTION_REGIONS = [
  'realm:Draenor-Horde' => 'eu',
  'realm:Draenor-Alliance' => 'eu',
  'realm:Illidan-Alliance' => 'us',
  'realm:Lordaeron-Alliance' => 'eu',
  'realm:Kilrogg-Horde' => 'eu',
  'realm:Chants éternels-Horde' => 'eu',
];

// MoP Classic connected realms, by region, first realm first (it names the group).
// Members share one population, so the realm explorer shows each group as one row
// with its home realms as a breakdown. Confirmed from /who scans on 2026-10-27: a
// scan from any member returns characters from every member. Bloodsail Buccaneers
// returned only its own characters. Retail groups come from Blizzard's API instead
// (retail-connected-realms.json, from tools/update-retail-connected-realms.py).
const MOP_CONNECTED_REALMS = [
  'us' => [
    ['Pagle', 'Galakras', 'Nazgrim', 'Lei Shen', 'Ra-den', 'Immerseus'],
    ['Bloodsail Buccaneers'],
  ],
  'eu' => [
    ["Shek'zeer", 'Mirage Raceway', 'Hoptallus', 'Garalon', 'Norushen'],
  ],
];

function realm_slug(string $name): string {
  return strtolower(preg_replace('/[^A-Za-z0-9()]/', '', $name));
}

/** Matches one realm across spellings: "Ra-den"/"Raden", "Temple noir"/"Templenoir", Cyrillic names. */
function realm_match_slug(string $name): string {
  return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $name));
}

/** Short public label and realm type for Forever's collector names. */
function forever_realm_display(string $name): array {
  if (!preg_match('/^CAMWOW\s+(US|EU)(\d+)\s+Realm\s+(\d+)\s+(RP PvP|PvP|PvE|RP)$/i', $name, $m))
    return ['displayName' => $name, 'realmType' => 'Other', 'cluster' => ''];
  $types = ['rp pvp' => 'RP-PvP', 'pvp' => 'PvP', 'pve' => 'PvE', 'rp' => 'RP'];
  return ['displayName' => 'Realm ' . $m[3], 'realmType' => $types[strtolower($m[4])],
    'cluster' => strtoupper($m[1]) . $m[2]];
}

/** A Forever realm key's region/type group, as the realms page names it ("us:PvE", "US PvE"); null for other names. */
function forever_realm_group(string $realm): ?array {
  if (!preg_match('/CAMWOW\s+(US|EU)\d+\s+Realm\s+\d+\s+(RP PvP|PvP|PvE|RP)(?:-(?:Alliance|Horde))?$/i', $realm, $m)) return null;
  $type = forever_realm_display('CAMWOW ' . $m[1] . '1 Realm 1 ' . $m[2])['realmType'];
  return ['key' => strtolower($m[1]) . ':' . $type, 'label' => strtoupper($m[1]) . ' ' . $type];
}

/** Collapse Forever's invisible collector realms into player-facing region/type buckets. */
function forever_realm_groups(array $realms): array {
  $groups = [];
  foreach ($realms as $realm) {
    $meta = forever_realm_display($realm['name']);
    $region = $realm['region'];
    if (!$region && preg_match('/^CAMWOW\s+(US|EU)\d+/i', $realm['name'], $m)) $region = strtolower($m[1]);
    $type = $meta['realmType'];
    $key = ($region ?? 'unknown') . ':' . $type;
    $group = &$groups[$key];
    $group ??= ['key' => $key, 'name' => strtoupper($region ?? 'Unknown') . ' ' . $type,
      'displayName' => strtoupper($region ?? 'Unknown') . ' ' . $type, 'realmType' => $type,
      'region' => $region, 'regionShown' => false, 'aliases' => [], 'factions' => []];
    $group['aliases'][] = $realm['key'];
    foreach ($realm['factions'] as $fac => $f) {
      $to = &$group['factions'][$fac];
      $to ??= ['races' => [], 'week' => 0, 'day' => 0, 'classes' => [], 'combos' => [], 'chars' => 0];
      foreach (['chars', 'week', 'day'] as $field) $to[$field] += $f[$field];
      foreach (['races', 'classes', 'combos'] as $field)
        foreach ($f[$field] as $name => $n) $to[$field][$name] = ($to[$field][$name] ?? 0) + $n;
      unset($to);
    }
    unset($group);
  }
  foreach ($groups as &$group) {
    $group['aliases'] = array_values(array_unique($group['aliases']));
    foreach ($group['factions'] as &$f)
      foreach (['races', 'classes', 'combos'] as $field) { arsort($f[$field]); $f[$field] = (object)$f[$field]; }
    unset($f);
  }
  unset($group);
  $list = array_values($groups);
  $regions = ['us' => 0, 'eu' => 1]; $types = ['PvE' => 0, 'PvP' => 1, 'RP' => 2, 'RP-PvP' => 3];
  usort($list, fn($a, $b) => (($regions[$a['region'] ?? ''] ?? 9) <=> ($regions[$b['region'] ?? ''] ?? 9))
    ?: (($types[$a['realmType']] ?? 9) <=> ($types[$b['realmType']] ?? 9)));
  return $list;
}

/**
 * Connected realms of an edition, or null when its realms are not connected:
 * region => list of groups, each a list of members ['name' => display name, 'alt' => other spellings].
 */
function connected_realm_groups(string $source): ?array {
  static $retail = null;
  if ($source === 'mop-classic')
    return array_map(fn($groups) => array_map(fn($g) => array_map(fn($n) => ['name' => $n, 'alt' => []], $g), $groups), MOP_CONNECTED_REALMS);
  if ($source !== 'retail') return null;
  if ($retail === null) {
    $data = json_decode(file_get_contents(__DIR__ . '/retail-connected-realms.json'), true, 512, JSON_THROW_ON_ERROR);
    $retail = [];
    foreach (['us', 'eu'] as $region)
      foreach ($data[$region] as $g) $retail[$region][] = array_map(fn($r) => ['name' => $r['name'], 'alt' => $r['alt'] ?? []], $g['realms']);
  }
  return $retail;
}

/** region => match slug => the members of that realm's group. */
function connected_realm_index(array $groups): array {
  $index = [];
  foreach ($groups as $region => $list) foreach ($list as $members)
    foreach ($members as $m) foreach ([$m['name'], ...$m['alt']] as $n) $index[$region][realm_match_slug($n)] = $members;
  return $index;
}

// Faction by race, for scans whose dataset name carries no faction (some retail
// realms). Pandaren, Dracthyr and the other neutral races stay Unknown there.
const RACE_FACTION = [
  'Human' => 'Alliance', 'Dwarf' => 'Alliance', 'Night Elf' => 'Alliance', 'Gnome' => 'Alliance', 'Draenei' => 'Alliance',
  'Worgen' => 'Alliance', 'Void Elf' => 'Alliance', 'Lightforged Draenei' => 'Alliance', 'Dark Iron Dwarf' => 'Alliance',
  'Kul Tiran' => 'Alliance', 'Mechagnome' => 'Alliance', 'High Order Skyborne' => 'Alliance',
  'Orc' => 'Horde', 'Undead' => 'Horde', 'Tauren' => 'Horde', 'Troll' => 'Horde', 'Blood Elf' => 'Horde', 'Goblin' => 'Horde',
  'Nightborne' => 'Horde', 'Highmountain Tauren' => 'Horde', "Mag'har Orc" => 'Horde', 'Zandalari Troll' => 'Horde', 'Vulpera' => 'Horde',
  'Windshaper Skyborne' => 'Horde',
];

// Groups characters by their own home realm (the realm in their name), not by the
// realm the scan ran on, so connected realms each get their own row. Each character
// counts once, from its latest sighting within the census window of its scan.
function realm_explorer_data(string $source): array {
  $census = load_census($source);
  $allObserved = in_array($source, ['classic-beta', 'forever'], true);
  $regionFallback = 'NULL';
  if ($source === 'retail') {
    $cases = [];
    foreach (RETAIL_VERIFIED_COLLECTION_REGIONS as $game => $region)
      $cases[] = "WHEN '" . str_replace("'", "''", $game) . "' THEN '" . $region . "'";
    $regionFallback = 'CASE c.game ' . implode(' ', $cases) . ' END';
  }
  $groups = connected_realm_groups($source);
  if ($groups) {
    // A home realm whose name exists in one region only also names the region
    // of a scan that recorded none.
    $regionsOf = [];
    foreach (connected_realm_index($groups) as $region => $slugs) foreach ($slugs as $slug => $_) $regionsOf[$slug][$region] = true;
    $cases = [];
    foreach ($regionsOf as $slug => $regions)
      if (count($regions) === 1) $cases[] = "WHEN '" . str_replace("'", "''", (string)$slug) . "' THEN '" . array_key_first($regions) . "'";
    $byHome = "CASE lower(split_part(c.character_key, ':', 2)) " . implode(' ', $cases) . ' END';
    $byScan = scan_regions_from_homes($source, $groups);
    $regionFallback = 'COALESCE(' . ($regionFallback === 'NULL' ? '' : "$regionFallback, ") . "$byHome, $byScan)";
  }
  $rows = Db::all(
    "WITH m AS (SELECT game, MAX(last_seen) latest FROM characters GROUP BY game),
     base AS (
       SELECT c.*, COALESCE(c.collection_region, $regionFallback) resolved_region
       FROM characters_with_region c
     ),
     c AS (
       SELECT c.game, c.realm, c.resolved_region, c.race, c.class_file cf, c.last_seen t, m.latest,
         ROW_NUMBER() OVER (PARTITION BY c.resolved_region, c.character_key ORDER BY c.last_seen DESC) rn
       FROM base c JOIN datasets d ON d.game = c.game JOIN m ON m.game = c.game
       WHERE d.source_game = ? AND COALESCE(c.realm, '') <> ''" . ($allObserved ? '' : ' AND c.last_seen >= m.latest - ?') . "
     )
     SELECT game, realm, resolved_region collection_region, race, cf, COUNT(*) n,
       SUM(CASE WHEN t >= latest - 604800 THEN 1 ELSE 0 END) week_count,
       SUM(CASE WHEN t >= latest - 86400 THEN 1 ELSE 0 END) day_count
     FROM c WHERE rn = 1
     GROUP BY game, realm, resolved_region, race, cf",
    $allObserved ? [$source] : [$source, CENSUS_WINDOW_DAYS * 86400]);
  $realms = []; $spellings = [];
  foreach ($rows as $r) {
    if ($source === 'classic' && in_array($r['realm'], SOD_REALMS, true)) continue;
    // Forever realms are all "Classic Beta <type>" ("Classic Beta PvP 2"); show them as PvE / PvP like the census chips.
    $name = in_array($source, ['classic-beta', 'forever'], true) ? realm_name($r['realm']) : $r['realm'];
    // One realm can be spelled two ways ("Ra-den" and "Raden"); group them together.
    // Rows of no region in an old collection take the region of its labelled twin (collection_aliases()).
    $region = $r['collection_region'] ?? (isset(collection_aliases()[$r['game']]) ? explode(':', collection_aliases()[$r['game']])[1] : null);
    $key = ($region ?? 'unknown') . ':' . realm_slug($name);
    $spellings[$key][$name] = ($spellings[$key][$name] ?? 0) + $r['n'];
    $fac = str_starts_with($r['game'], 'realm:') ? split_realm(substr($r['game'], 6))['fac'] : '';
    if ($fac !== 'Alliance' && $fac !== 'Horde') $fac = RACE_FACTION[$r['race']] ?? 'Unknown';
    $realms[$key] ??= ['name' => $name, 'region' => $region, 'factions' => []];
    $f = &$realms[$key]['factions'][$fac];
    $f ??= ['races' => [], 'week' => 0, 'day' => 0, 'classes' => [], 'combos' => [], 'chars' => 0];
    $f['chars'] += $r['n']; $f['week'] += $r['week_count']; $f['day'] += $r['day_count'];
    $race = (string)($r['race'] ?? ''); $cf = (string)($r['cf'] ?? '');
    if ($race !== '') $f['races'][$race] = ($f['races'][$race] ?? 0) + $r['n'];
    if ($cf !== '') $f['classes'][$cf] = ($f['classes'][$cf] ?? 0) + $r['n'];
    if ($race !== '' && $cf !== '') $f['combos']["$race\t$cf"] = ($f['combos']["$race\t$cf"] ?? 0) + $r['n'];
    unset($f);
  }
  // Rows of no region join the one region a realm of that name was seen in, unless
  // the name is in two regions.
  $regionsBySlug = [];
  foreach ($realms as $key => $realm) if ($realm['region'] !== null) $regionsBySlug[substr($key, strpos($key, ':') + 1)][$key] = true;
  foreach ($realms as $key => $realm) {
    $slug = substr($key, strpos($key, ':') + 1);
    if ($realm['region'] !== null || count($regionsBySlug[$slug] ?? []) !== 1 || realm_region_shown($source, $realm['name'])) continue;
    $into = array_key_first($regionsBySlug[$slug]);
    foreach ($realm['factions'] as $fac => $f) {
      $to = &$realms[$into]['factions'][$fac];
      $to ??= ['races' => [], 'week' => 0, 'day' => 0, 'classes' => [], 'combos' => [], 'chars' => 0];
      foreach (['chars', 'week', 'day'] as $k) $to[$k] += $f[$k];
      foreach (['races', 'classes', 'combos'] as $k) foreach ($f[$k] as $name => $n) $to[$k][$name] = ($to[$k][$name] ?? 0) + $n;
      unset($to);
    }
    foreach ($spellings[$key] as $name => $n) $spellings[$into][$name] = ($spellings[$into][$name] ?? 0) + $n;
    unset($realms[$key]);
  }
  foreach ($realms as $key => &$realm) {
    // Keep the punctuated spelling ("Ra-den") when a realm has two.
    uksort($spellings[$key], fn($a, $b) => strlen((string)$b) <=> strlen((string)$a));
    $realm['name'] = (string)array_key_first($spellings[$key]);
    $realm['key'] = ($realm['region'] ?? 'unknown') . ':' . $realm['name'];
  }
  unset($realm);
  if ($groups) $realms = connected_groups($realms, $groups, $source === 'retail');
  foreach ($realms as &$realm) {
    // Labels show the region only when one of the names is in two regions.
    $realm['regionShown'] = (bool)array_filter($realm['members'] ?? [$realm['name']], fn($n) => realm_region_shown($source, (string)$n));
    $order = ['Alliance' => 1, 'Horde' => 2];
    uksort($realm['factions'], fn($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));
    foreach ($realm['factions'] as &$f) foreach (['races', 'classes', 'combos'] as $k) { arsort($f[$k]); $f[$k] = (object)$f[$k]; }
    unset($f);
  }
  unset($realm);
  $total = fn($r) => array_sum(array_map(fn($f) => $f['chars'], $r['factions']));
  $list = array_values($realms);
  usort($list, fn($a, $b) => $total($b) <=> $total($a) ?: strcmp($a['name'], $b['name']));
  if ($source === 'forever') $list = forever_realm_groups($list);
  return ['realms' => $list, 'lastT' => $census['lastT']];
}

/**
 * SQL CASE on c.game giving a region to scans that recorded none, when their realm
 * name exists in both regions ("Illidan-Horde"). The region whose connected group
 * around the scanned realm accounts for most of the scan's home realms wins; on a
 * tie, the smaller group (a US Illidan scan sees only Illidan, an EU one also sees
 * Arathi, Naxxramas and Temple noir). Still tied: no region.
 */
function scan_regions_from_homes(string $source, array $groups): string {
  $cases = [];
  foreach (scan_region_map($source, $groups) as $game => $region)
    $cases[] = "WHEN '" . str_replace("'", "''", $game) . "' THEN '" . $region . "'";
  return $cases ? 'CASE c.game ' . implode(' ', $cases) . ' END' : 'NULL';
}

/** The region scan_regions_from_homes() gives each game with rows of no region. */
function scan_region_map(string $source, array $groups): array {
  static $cache = [];
  if (isset($cache[$source])) return $cache[$source];
  $index = connected_realm_index($groups);
  $homes = [];
  foreach (Db::all("SELECT game, lower(split_part(character_key, ':', 2)) home, COUNT(*) n FROM characters_with_region
      WHERE source_game = ? AND collection_region IS NULL GROUP BY 1, 2", [$source]) as $r)
    $homes[$r['game']][(string)$r['home']] = $r['n'];
  $map = [];
  foreach ($homes as $game => $counts) {
    $scanned = realm_match_slug(split_realm(preg_replace('/^realm:(?:[a-z]{2}:[^:]+:)?/', '', $game))['name']);
    $best = [];
    foreach ($index as $region => $slugs) {
      if (!isset($slugs[$scanned])) continue;
      $members = array_map(fn($m) => realm_match_slug($m['name']), $slugs[$scanned]);
      $explained = array_sum(array_intersect_key($counts, array_flip($members)));
      $best[] = [$explained, -count($members), $region];
    }
    rsort($best);
    if ($best && $best[0][0] > 0 && (count($best) === 1 || array_slice($best[0], 0, 2) !== array_slice($best[1], 0, 2)))
      $map[$game] = $best[0][2];
  }
  return $cache[$source] = $map;
}

/**
 * Fold home realms into their connected group. A group is shaped like a realm, plus
 * `homes` (each home realm's characters by faction), `members` (every realm in the
 * group) and `aliases` (realm keys of its members, so their ?realm= links open it).
 * A MoP group is named after its first realm; a Retail group after the home realm
 * with the most characters seen ("Eonar group"). Realms outside the map, or with no
 * known region, stay rows of their own.
 */
function connected_groups(array $realms, array $groups, bool $nameByLargest): array {
  $index = connected_realm_index($groups);
  $out = [];
  foreach ($realms as $realm) {
    $region = $realm['region'];
    $members = $index[$region ?? ''][realm_match_slug($realm['name'])] ?? [['name' => $realm['name'], 'alt' => []]];
    $id = ($region ?? 'unknown') . ':' . $members[0]['name'];
    $g = &$out[$id];
    $g ??= ['name' => $members[0]['name'], 'region' => $region, 'key' => $id, 'factions' => [], 'homes' => [],
      'members' => array_column($members, 'name'),
      'aliases' => array_map(fn($m) => ($region ?? 'unknown') . ':' . $m['name'], $members)];
    $home = ['name' => $realm['name'], 'chars' => 0, 'A' => 0, 'H' => 0];
    foreach ($realm['factions'] as $fac => $f) {
      $home['chars'] += $f['chars'];
      if ($fac === 'Alliance') $home['A'] += $f['chars'];
      if ($fac === 'Horde') $home['H'] += $f['chars'];
      $to = &$g['factions'][$fac];
      $to ??= ['races' => [], 'week' => 0, 'day' => 0, 'classes' => [], 'combos' => [], 'chars' => 0];
      foreach (['chars', 'week', 'day'] as $k) $to[$k] += $f[$k];
      foreach (['races', 'classes', 'combos'] as $k) foreach ($f[$k] as $name => $n) $to[$k][$name] = ($to[$k][$name] ?? 0) + $n;
      unset($to);
    }
    $g['homes'][] = $home;
    $g['aliases'][] = $realm['key'];
    unset($g);
  }
  $named = [];
  foreach ($out as $g) {
    usort($g['homes'], fn($a, $b) => $b['chars'] <=> $a['chars']);
    if ($nameByLargest) {
      // Name the group after its biggest home realm, in the map's spelling.
      $top = realm_match_slug($g['homes'][0]['name']);
      foreach ($g['members'] as $m) if (realm_match_slug($m) === $top) $g['name'] = $m;
      $g['key'] = ($g['region'] ?? 'unknown') . ':' . $g['name'];
    }
    if (count($g['members']) > 1) $g['name'] .= ' group';
    $g['aliases'] = array_values(array_unique(array_diff($g['aliases'], [$g['key']])));
    $named[$g['key']] = $g;
  }
  return $named;
}

function render_realms_html(string $source, string $stylesheet, string $generatedAt): string {
  $edition = EDITIONS[$source];
  ['realms' => $realms, 'lastT' => $lastT] = realm_explorer_data($source);
  $scanned = $lastT ? gmdate('M j, Y', $lastT) : 'not yet';
  $races = []; $classes = [];
  foreach ($realms as $r) foreach ($r['factions'] as $f) {
    foreach ($f['races'] as $race => $_) $races[$race] = true;
    foreach ($f['classes'] as $cf => $_) $classes[$cf] = true;
  }
  $raceIcons = ['?' => icon_url('inv_misc_questionmark')];
  foreach (array_keys($races) as $race) $raceIcons[$race] = race_icon((string)$race);
  $classIcons = []; $classMeta = []; $classPaths = [];
  $profiles = class_profiles()[$source]['classes'] ?? [];
  foreach (array_keys($classes) as $cf) {
    $classIcons[$cf] = class_icon((string)$cf);
    $classMeta[$cf] = CLASS_META[$cf] ?? [ucfirst(strtolower((string)$cf)), '8B90A0'];
    if (in_array($cf, $profiles, true)) $classPaths[$cf] = class_route($source, (string)$cf);
  }
  $racePaths = [];
  foreach (race_profiles()[$source] ?? [] as $race => $p) $racePaths[$race] = $p['path'];

  $navigation = edition_nav($source, 'realms.html');
  $label = $edition['label'];
  $topic = seo_label($label) . ' Realm Explorer';
  $seo = seo_head($topic . ' – WoWCensus', seo_label($label) . ' realms compared: characters seen, faction balance, race and class mix and top race and class pairs per realm, from /who scans.',
    SITE_ORIGIN . page_path($source, 'realms'));
  $json = fn($v) => str_replace('</', '<\/', js_json($v));
  $script = strtr(file_get_contents(__DIR__ . '/realms/realms.js'), [
    '__DATA__' => $json($realms), '__EDITION__' => $json($source), '__RACE_ICONS__' => $json((object)$raceIcons), '__CLASS_ICONS__' => $json((object)$classIcons),
    '__CLASSES__' => $json((object)$classMeta), '__RACE_PATHS__' => $json((object)$racePaths), '__CLASS_PATHS__' => $json((object)$classPaths),
    '__COMBOS_HREF__' => $json(page_path($source, 'combos')), '__SCANNED__' => $scanned,
  ]);
  $style = file_get_contents(__DIR__ . '/realms/realms.css');
  $heading = page_heading('WoWCensus', $navigation, $topic) . $navigation['games'];
  $crumbs = $navigation['breadcrumbs'];
  $nav = esc($edition['nav']);
  $sheet = esc($stylesheet);
  $census = CENSUS_STYLESHEET;
  $generated = generated_note_html('Page generated: ' . utc_timestamp($generatedAt));
  $hint = $source === 'forever'
    ? 'Forever has no player-visible realms. Collector buckets are combined by region and realm type. Counts are characters seen by scans, not players online.'
    : (connected_realm_groups($source)
    ? 'Connected realms share one population, so each connected group is one row' . ($source === 'retail' ? ', named after its realm with the most characters seen' : '') . '. Each character counts once. Realms with fewer than 20 characters seen are listed but not ranked.'
    : "Grouped by each character's home realm, so players seen through a connected realm count on their own realm. Each character counts once. Realms with fewer than 20 characters seen are listed but not ranked.");
  $empty = $realms ? '' : '<div class="panel"><p class="hint">No ' . esc($label) . ' realms have been scanned yet.</p></div>';
  $main = $realms ? <<<HTML
    <div class="tiles" id="tiles"></div>

    <div class="realm-controls">
      <label class="search"><span>FIND</span><input id="q" type="search" placeholder="Realm name" autocomplete="off" aria-label="Find a realm"></label>
      <div class="chips" role="group" aria-label="Faction" id="fchips">
        <button class="chip" type="button" data-f="all" aria-pressed="true">Both factions</button>
        <button class="chip" type="button" data-f="Alliance" aria-pressed="false">Alliance</button>
        <button class="chip" type="button" data-f="Horde" aria-pressed="false">Horde</button>
      </div>
    </div>

    <div class="panelgrid realm-grid">
      <div class="panelcol">
        <div class="tablewrap">
          <table class="realm-table">
            <thead><tr>
              <th class="l" data-sort="name">Realm <span class="ar off">▼</span></th>
              <th data-sort="chars">Characters <span class="ar">▼</span></th>
              <th class="l" data-sort="ally">Balance <span class="ar off">▼</span></th>
              <th data-sort="week">7 days <span class="ar off">▼</span></th>
              <th class="l" data-sort="top">Top class <span class="ar off">▼</span></th>
            </tr></thead>
            <tbody id="rows"></tbody>
          </table>
          <div class="foot">Characters seen by scans, not players online · Scanned $scanned</div>
        </div>
        <p class="hint">$hint</p>
        <section class="combos realm-combos" id="combos"></section>
      </div>
      <div class="panelcol realm-detail" id="detail"></div>
    </div>
HTML : $empty;
  $scriptTag = $realms ? "<script>\n$script</script>" : '';
  return <<<HTML
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
$seo
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96">
<link rel="icon" href="/favicon-96.png" type="image/png" sizes="96x96">
<link rel="preload" href="/fonts/press-start-2p-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/vt323-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/cinzel-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="$sheet">
$census
<style>
$style</style>
</head><body><div class="crt" aria-hidden="true"></div>
<div class="wrap">
  <header class="ihead">
    <div class="page-heading">$heading</div>
    <div class="mobile-edition">$nav <span>/</span> Realms</div>
    $crumbs
  </header>
  <main>
$main
  $generated
  </main>
</div>
$scriptTag
</body></html>
HTML;
}

function realms_page_html(string $source): string {
  $html = render_realms_html($source, stylesheet()['name'], generated_at());
  $html = str_replace('href="/census.css"', 'href="/census.css?v=' . census_css_version() . '"', $html);
  return localize_content($html);
}
