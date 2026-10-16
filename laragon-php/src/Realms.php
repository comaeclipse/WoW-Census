<?php
// Realm explorer (/<edition>/realms): every scanned realm of an edition with its
// characters, faction balance, class and race mix and top race + class pairs.
// Built from the mockup in mockups/realms-src/. The page ships its data as JSON
// and realms/realms.js draws the table and the selected realm's detail.

declare(strict_types=1);

// SoD realms appear among the Classic Era client's census units.
const SOD_REALMS = ['Crusader Strike', 'Living Flame', 'Lone Wolf', 'Wild Growth', 'Chaos Bolt', 'Lava Lash'];

// Faction by race, for scans whose dataset name carries no faction (some retail
// realms). Pandaren, Dracthyr and the other neutral races stay Unknown there.
const RACE_FACTION = [
  'Human' => 'Alliance', 'Dwarf' => 'Alliance', 'Night Elf' => 'Alliance', 'Gnome' => 'Alliance', 'Draenei' => 'Alliance',
  'Worgen' => 'Alliance', 'Void Elf' => 'Alliance', 'Lightforged Draenei' => 'Alliance', 'Dark Iron Dwarf' => 'Alliance',
  'Kul Tiran' => 'Alliance', 'Mechagnome' => 'Alliance',
  'Orc' => 'Horde', 'Undead' => 'Horde', 'Tauren' => 'Horde', 'Troll' => 'Horde', 'Blood Elf' => 'Horde', 'Goblin' => 'Horde',
  'Nightborne' => 'Horde', 'Highmountain Tauren' => 'Horde', "Mag'har Orc" => 'Horde', 'Zandalari Troll' => 'Horde', 'Vulpera' => 'Horde',
];

// Groups characters by their own home realm (the realm in their name), not by the
// realm the scan ran on, so connected realms each get their own row. Each character
// counts once, from its latest sighting within the census window of its scan.
function realm_explorer_data(string $source): array {
  $census = load_census($source);
  $allObserved = $source === 'classic-beta';
  $rows = Db::all(
    "WITH m AS (SELECT game, MAX(last_seen) latest FROM characters GROUP BY game),
     c AS (
       SELECT c.game, c.realm, c.race, c.class_file cf, c.last_seen t, m.latest,
         ROW_NUMBER() OVER (PARTITION BY c.character_key ORDER BY c.last_seen DESC) rn
       FROM characters c JOIN datasets d ON d.game = c.game JOIN m ON m.game = c.game
       WHERE d.source_game = ? AND COALESCE(c.realm, '') <> ''" . ($allObserved ? '' : ' AND c.last_seen >= m.latest - ?') . "
     )
     SELECT game, realm, race, cf, COUNT(*) n,
       SUM(CASE WHEN t >= latest - 604800 THEN 1 ELSE 0 END) week_count,
       SUM(CASE WHEN t >= latest - 86400 THEN 1 ELSE 0 END) day_count
     FROM c WHERE rn = 1
     GROUP BY game, realm, race, cf",
    $allObserved ? [$source] : [$source, CENSUS_WINDOW_DAYS * 86400]);
  $realms = []; $spellings = [];
  foreach ($rows as $r) {
    if ($source === 'classic' && in_array($r['realm'], SOD_REALMS, true)) continue;
    // Forever realms are all "Classic Beta <type>" ("Classic Beta PvP 2"); show them as PvE / PvP like the census chips.
    $name = $source === 'classic-beta' ? realm_name($r['realm']) : $r['realm'];
    // One realm can be spelled two ways ("Ra-den" and "Raden"); group them together.
    $key = strtolower(preg_replace('/[^A-Za-z0-9()]/', '', $name));
    $spellings[$key][$name] = ($spellings[$key][$name] ?? 0) + $r['n'];
    $fac = str_starts_with($r['game'], 'realm:') ? split_realm(substr($r['game'], 6))['fac'] : '';
    if ($fac !== 'Alliance' && $fac !== 'Horde') $fac = RACE_FACTION[$r['race']] ?? 'Unknown';
    $realms[$key] ??= ['name' => $name, 'factions' => []];
    $f = &$realms[$key]['factions'][$fac];
    $f ??= ['races' => [], 'week' => 0, 'day' => 0, 'classes' => [], 'combos' => [], 'chars' => 0];
    $f['chars'] += $r['n']; $f['week'] += $r['week_count']; $f['day'] += $r['day_count'];
    $race = (string)($r['race'] ?? ''); $cf = (string)($r['cf'] ?? '');
    if ($race !== '') $f['races'][$race] = ($f['races'][$race] ?? 0) + $r['n'];
    if ($cf !== '') $f['classes'][$cf] = ($f['classes'][$cf] ?? 0) + $r['n'];
    if ($race !== '' && $cf !== '') $f['combos']["$race\t$cf"] = ($f['combos']["$race\t$cf"] ?? 0) + $r['n'];
    unset($f);
  }
  foreach ($realms as $key => &$realm) {
    // Keep the punctuated spelling ("Ra-den") when a realm has two.
    uksort($spellings[$key], fn($a, $b) => strlen((string)$b) <=> strlen((string)$a));
    $realm['name'] = (string)array_key_first($spellings[$key]);
    $order = ['Alliance' => 1, 'Horde' => 2];
    uksort($realm['factions'], fn($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));
    foreach ($realm['factions'] as &$f) foreach (['races', 'classes', 'combos'] as $k) { arsort($f[$k]); $f[$k] = (object)$f[$k]; }
    unset($f);
  }
  unset($realm);
  $total = fn($r) => array_sum(array_map(fn($f) => $f['chars'], $r['factions']));
  $list = array_values($realms);
  usort($list, fn($a, $b) => $total($b) <=> $total($a) ?: strcmp($a['name'], $b['name']));
  return ['realms' => $list, 'lastT' => $census['lastT']];
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
  $seo = seo_head($topic . ' – WoWCensus', seo_label($label) . ' realms compared: characters seen, faction balance, class and race mix, and the most popular race and class pairs on each realm, from in-game /who scans.',
    SITE_ORIGIN . page_path($source, 'realms'));
  $json = fn($v) => str_replace('</', '<\/', js_json($v));
  $script = strtr(file_get_contents(__DIR__ . '/realms/realms.js'), [
    '__DATA__' => $json($realms), '__RACE_ICONS__' => $json((object)$raceIcons), '__CLASS_ICONS__' => $json((object)$classIcons),
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
        <p class="hint">Grouped by each character's home realm, so players seen through a connected realm count on their own realm. Each character counts once. Realms with fewer than 20 characters seen are listed but not ranked.</p>
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
