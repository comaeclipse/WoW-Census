<?php
// Port of site/src/guilds.mjs and site/src/geography.mjs.

declare(strict_types=1);

function guild_scope_label(array $g, array $snapshot): string {
  $manyRealms = count($snapshot['realms'] ?? []) > 1;
  return implode(' · ', array_filter([$manyRealms ? realm_name($g['realm']) : '', $g['faction']], fn($x) => $x !== '' && $x !== null));
}

function render_guild_view(array $snapshot): string {
  $guilds = $snapshot['guilds'] ?? [];
  $top = array_slice($guilds, 0, 100);
  $surveyed = $snapshot['surveyedCharacters'] ?: 0;
  $guilded = $snapshot['guildedCharacters'] ?: 0;
  $realms = $snapshot['realms'] ?? [];
  $lastT = $snapshot['lastT'] ?: 0;
  $rows = '';
  foreach ($top as $i => $g)
    $rows .= '<tr>' .
      '<td class="rank">' . ($i + 1) . '</td>' .
      '<td>&lt;' . esc(guild_display_name($g['name'])) . '&gt;</td>' .
      '<td class="scope">' . esc(guild_scope_label($g, $snapshot)) . '</td>' .
      '<td class="members">' . js_locale($g['members']) . '</td></tr>';
  $realmText = implode(' · ', array_map('realm_name', $realms));
  $tiles =
    '<div class="tile"><div class="k">Guilds found</div><div class="v">' . js_locale(count($guilds)) .
      '</div><div class="s">distinct realm guilds</div></div>' .
    '<div class="tile"><div class="k">Guilded characters</div><div class="v">' . js_locale($guilded) .
      '</div><div class="s">latest known guild</div></div>' .
    '<div class="tile"><div class="k">Surveyed</div><div class="v">' . js_locale($surveyed) .
      '</div><div class="s">unique characters</div></div>' .
    '<div class="tile"><div class="k">Updated</div><div class="v">' .
      ($lastT ? substr(js_iso($lastT), 0, 10) : '&mdash;') .
      '</div><div class="s">' . esc($realmText !== '' ? $realmText : 'no realm') . '</div></div>';
  $body = $rows !== ''
    ? '<div class="panel census"><div class="ptitle">Most popular guilds</div>' .
      '<p class="hint" style="margin-top:0">Ranked by unique surveyed characters whose latest recorded guild matches this name.</p>' .
      '<table class="guildtable"><thead><tr><th class="rank">#</th><th>Guild</th><th class="scope">Realm · faction</th>' .
      '<th class="members">Characters</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
    : '<div class="panel"><p class="hint">No guilded characters were found for this selection yet.</p></div>';
  return '<section class="tiles">' . $tiles . '</section>' . $body;
}

// From this many realm x faction panes on, only the selected one is in the page; the others
// are empty and load from $paneUrl when picked (retail's ~140 panes made 2-3 MB pages).
const LAZY_PANES_FROM = 12;

function view_panes(array $views, string $activeRealm, string $activeFaction, callable $render, ?string $paneUrl = null): string {
  $lazy = $paneUrl !== null && count($views) >= LAZY_PANES_FROM;
  $out = '';
  foreach ($views as $v) {
    $on = $v['realm'] === $activeRealm && $v['faction'] === $activeFaction;
    $attrs = 'class="vpane" data-realm="' . esc($v['realm']) . '" data-faction="' . esc($v['faction']) . '"' . ($on ? '' : ' hidden');
    if ($lazy && !$on) {
      $src = $paneUrl . (str_contains($paneUrl, '?') ? '&' : '?') . 'realm=' . rawurlencode($v['realm']) . '&faction=' . rawurlencode($v['faction']);
      $out .= '<div ' . $attrs . ' data-pane="' . esc($src) . '"></div>';
    } else {
      $out .= '<div ' . $attrs . '>' . $render($v['snapshot']) . '</div>';
    }
  }
  return $out;
}

/** Where this page's lazy panes load from: /retail/geography -> /retail/geography.pane; null outside a request. */
function pane_url(string $page, string $query = ''): ?string {
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
  if (!is_string($path) || !preg_match('#^(/(?:[a-z-]+/)?)' . $page . '(?:\.html)?$#', $path, $m)) return null;
  return $m[1] . $page . '.pane' . ($query !== '' ? '?' . $query : '');
}

function render_guild_html(array $views, array $dims, array $opts): string {
  $list = array_values(array_filter($views));
  $realms = $dims['realm'] ?? []; $factions = $dims['faction'] ?? [];
  $activeRealm = $realms ? $realms[0]['key'] : 'all';
  $activeFaction = $factions ? $factions[0]['key'] : 'all';
  $chips = chip_row('realm', $realms, $activeRealm) . chip_row('faction', $factions, $activeFaction);
  $panes = view_panes($list, $activeRealm, $activeFaction, 'render_guild_view', pane_url('guilds'));
  $gameLabel = $opts['gameLabel'] ?? 'WoW Forever';
  $navigation = $opts['nav'];
  $heading = ($navigation['games'] ?? '') !== '' ? 'WoWCensus' : $gameLabel . ' — Guilds';
  $notes = notes_html($opts['notes'] ?? []);
  $generatedNote = generated_note_html($opts['generatedNote'] ?? null);
  $seoGameLabel = seo_label($gameLabel);
  $topic = $seoGameLabel . ' Guilds by Realm';
  $seo = seo_head($topic . ' – WoWCensus', $seoGameLabel . ' guild activity by realm and faction: the guilds with the most characters seen and how large they are, from sampled /who results.', $opts['canonical'] ?? null);
  $stylesheet = esc($opts['stylesheet'] ?? 'style.css');
  $census_css = CENSUS_STYLESHEET;
  $style = js_template_const('guilds.mjs', 'GUILD_STYLE');
  $headingHtml = page_heading($heading, $navigation, $topic);
  $games = $navigation['games'] ?? '';
  $crumbs = ($navigation['breadcrumbs'] ?? '') !== '' ? $navigation['breadcrumbs'] : $notes;
  $pages = $navigation['pages'] ?? '';
  $toggle = $chips !== '' ? toggle_script() : '';
  return <<<HTML
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="google-site-verification" content="N0-ZNJyDo16jefYUGxfcAda_mKf7S2oATfRGWETdsHs">
$seo
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96">
<link rel="icon" href="/favicon-96.png" type="image/png" sizes="96x96">
<link rel="preload" href="/fonts/press-start-2p-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/vt323-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/cinzel-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="$stylesheet">
$census_css<style>$style</style>
</head><body><div class="crt" aria-hidden="true"></div><div class="wrap">
  <header class="ihead"><div class="page-heading">$headingHtml$games</div>
    $crumbs
  </header>
  <div class="page-controls">$pages$chips</div>$panes
  $generatedNote
</div>$toggle</body></html>
HTML;
}

const CAPITALS = ['Darnassus', 'Dornogal', 'Exodar', 'Ironforge', 'Orgrimmar', 'Shattrath City',
  'Silvermoon City', 'Stormwind City', 'The Exodar', 'Thunder Bluff', 'Undercity'];
const PLAY_STYLES = ['End game', 'Leveling', 'Capital', 'Starting'];

function activity_hint(array $z, int|float $observedMax): string {
  if (in_array($z['name'], CAPITALS, true)) return 'Capital';
  if (($z['avgLevel'] ?: 0) <= 10) return 'Starting';
  if ($observedMax > 0 && ($z['avgLevel'] ?: 0) >= $observedMax * .88) return 'End game';
  return 'Leveling';
}

function render_geography_aero_view(array $snapshot): string {
  $zones = array_values(array_filter($snapshot['zones'] ?? [], fn($z) => $z['name'] !== 'Unknown'));
  $total = (int)(($snapshot['characters'] ?? 0) ?: array_sum(array_column($zones, 'characters')));
  $max = $zones ? max(1, (int)$zones[0]['characters']) : 1;
  $observedMax = 0;
  foreach ($zones as $zone) $observedMax = max($observedMax, $zone['maxLevel'] ?: 0);
  $window = !empty($snapshot['windowDays']) ? 'Last ' . (int)$snapshot['windowDays'] . ' days' : 'All observations';
  $updated = !empty($snapshot['lastT']) ? gmdate('M j, Y', (int)$snapshot['lastT']) : 'Unknown';
  $html = '<div class="geo-aero">';
  $html .= '<div class="geo-aero-metrics">'
    . '<div><span>CHARACTERS LOCATED</span><strong>' . js_locale($total) . '</strong><small>Unique, latest known zone</small></div>'
    . '<div><span>LOCATIONS OBSERVED</span><strong>' . js_locale(count($zones)) . '</strong><small>' . esc($window) . '</small></div>'
    . '<div><span>LATEST OBSERVATION</span><strong class="geo-aero-metric-name">' . esc($updated) . '</strong><small>Population sample</small></div></div>';
  if (!$zones) return $html . '<p class="geo-aero-note">No location data is available for this selection yet.</p></div>';
  $html .= '<section class="geo-aero-section" aria-label="Most observed locations"><div class="geo-aero-heading"><div><span>THE WORLD\'S PULSE</span><h2>Most observed locations</h2></div><small>Top three in this selection</small></div><div class="geo-aero-featured">';
  foreach (array_slice($zones, 0, 3) as $index => $zone) {
    $type = activity_hint($zone, $observedMax);
    $html .= '<article class="geo-aero-feature geo-aero-feature-' . ($index + 1) . '"><div class="geo-aero-feature-top"><span>0' . ($index + 1) . '</span><span>' . esc($type) . '</span></div>'
      . '<div class="geo-aero-feature-bottom"><span class="geo-aero-ornament" aria-hidden="true">' . ['✦','◇','✧'][$index] . '</span><h3>' . esc($zone['name']) . '</h3><p><strong>' . js_locale($zone['characters']) . '</strong> characters last seen here <span>·</span> avg. level ' . ($zone['avgLevel'] ?: '&mdash;') . '</p></div></article>';
  }
  $html .= '</div></section><section class="geo-aero-section geo-aero-explorer" aria-label="Location rankings"><div class="geo-aero-heading"><div><span>EXPLORE THE MAP</span><h2>Location rankings</h2></div><small>' . js_locale(count($zones)) . ' places in this selection</small></div>';
  $html .= '<div class="geo-aero-filter" role="group" aria-label="Filter locations by type">';
  foreach (['All locations','Capital','End game','Leveling','Starting'] as $index => $type)
    $html .= '<button type="button" data-geo-filter="' . esc($type) . '" aria-pressed="' . ($index === 0 ? 'true' : 'false') . '">' . esc($type) . '</button>';
  $html .= '</div><div class="geo-aero-list-head"><span>LOCATION</span><span>CHARACTERS</span><span>AVG. LEVEL</span></div><div class="geo-aero-list">';
  foreach (array_slice($zones, 0, 50) as $index => $zone) {
    $type = activity_hint($zone, $observedMax);
    $width = js_fixed($zone['characters'] / $max * 100, 1);
    $html .= '<div class="geo-aero-row" data-geo-type="' . esc($type) . '"><div class="geo-aero-row-title"><span class="geo-aero-row-index">' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) . '</span><div><strong>' . esc($zone['name']) . '</strong><small>' . esc($type) . '</small></div></div>'
      . '<div class="geo-aero-row-measure"><span class="geo-aero-row-track"><i style="width:' . $width . '%"></i></span><strong>' . js_locale($zone['characters']) . '</strong></div><span class="geo-aero-row-level">' . ($zone['avgLevel'] ?: '&mdash;') . '</span></div>';
  }
  $html .= '</div><p class="geo-aero-note"><span aria-hidden="true">ⓘ</span> Each character appears once in its latest known location within this sample window. These counts are not simultaneous players or a live population.</p></section></div>';
  return $html;
}

function render_geography_view(array $snapshot): string {
  $win = !empty($snapshot['windowDays']) ? 'last ' . $snapshot['windowDays'] . ' days' : 'all time';
  $zones = array_values(array_filter($snapshot['zones'] ?? [], fn($z) => $z['name'] !== 'Unknown'));
  $zoneSum = array_sum(array_map(fn($z) => $z['characters'], $zones));
  $total = ($snapshot['characters'] ?? 0) ?: $zoneSum;
  $max = $zones ? $zones[0]['characters'] : 0;
  $observedMax = 0;
  foreach ($zones as $z) $observedMax = max($observedMax, $z['maxLevel'] ?: 0);
  $top5 = array_sum(array_map(fn($z) => $z['characters'], array_slice($zones, 0, 5)));
  $top5Pct = $total ? js_round($top5 / $total * 100) : 0;
  $capitalCount = array_sum(array_map(fn($z) => $z['characters'], array_filter($zones, fn($z) => in_array($z['name'], CAPITALS, true))));
  $capitalPct = $total ? js_round($capitalCount / $total * 100) : 0;
  $updated = !empty($snapshot['lastT']) ? substr(js_iso($snapshot['lastT']), 0, 10) : '&mdash;';
  $tiles = '<section class="tiles">' .
    '<div class="tile"><div class="k">Characters</div><div class="v">' . js_locale($total) . '</div><div class="s">unique, ' . $win . '</div></div>' .
    '<div class="tile"><div class="k">Locations</div><div class="v">' . js_locale(count($zones)) . '</div><div class="s">with recent sightings</div></div>' .
    '<div class="tile"><div class="k">Top 5 share</div><div class="v">' . $top5Pct . '%</div><div class="s">location concentration</div></div>' .
    '<div class="tile"><div class="k">Updated</div><div class="v">' . $updated . '</div><div class="s">last population sample</div></div></section>';
  if (!$zones) return $tiles . '<div class="panel"><p class="hint">No location data is available for this selection yet.</p></div>';
  $renderBars = fn(array $list) => implode('', array_map(fn($z) => '<div class="geobar"><div class="name">' . esc(js_upper($z['name'])) .
    '</div><div class="track"><i style="width:' . js_fixed($max ? max(.8, $z['characters'] / $max * 100) : 0, 1) .
    '%"></i></div><div class="value">' . js_locale($z['characters']) . '</div></div>', $list));
  $visibleZones = array_slice($zones, 0, 50);
  $bars = $renderBars(array_slice($visibleZones, 0, 15));
  $moreBars = count($visibleZones) > 15
    ? '<details class="geomore"><summary><span class="more-closed">&#8595; See more</span><span class="more-open">&#8593; See less</span></summary>' .
      '<div class="morebars">' . $renderBars(array_slice($visibleZones, 15)) . '</div></details>'
    : '';
  $rows = implode('', array_map(fn($z) => '<tr><td>' . esc($z['name']) . '</td><td>' . js_locale($z['characters']) .
    '</td><td>' . ($total ? js_fixed($z['characters'] / $total * 100, 1) : '0.0') . '%</td><td>' .
    esc(activity_hint($z, $observedMax)) . ' · avg ' . ($z['avgLevel'] ? js_string($z['avgLevel']) : '&mdash;') . '</td></tr>', array_slice($zones, 0, 50)));
  $styleCounts = array_fill_keys(PLAY_STYLES, 0);
  foreach ($zones as $z) $styleCounts[activity_hint($z, $observedMax)] += $z['characters'];
  $located = array_sum(array_map(fn($z) => $z['characters'], $zones));
  $styleMax = max(1, ...array_values($styleCounts));
  $styleBars = implode('', array_map(fn($k) => '<div class="geobar"><div class="name">' . esc(js_upper($k)) .
    '</div><div class="track"><i style="width:' . js_fixed($styleCounts[$k] / $styleMax * 100, 1) .
    '%"></i></div><div class="value">' . js_str($located ? js_round($styleCounts[$k] / $located * 100) : 0) .
    '%</div></div>', PLAY_STYLES));
  $top = $zones[0];
  $signals = '<div class="panel"><div class="ptitle">Play-style mix</div>' .
    '<p class="hint" style="margin-top:0">Recent characters grouped by the kind of location they were last seen in.</p>' .
    '<div class="styles">' . $styleBars . '</div>' .
    '<div class="ptitle" style="margin-top:18px">How to read this</div>' .
    '<div class="signal"><b>Strongest cluster</b><span>' . esc($top['name']) . ' contains ' .
      ($total ? js_fixed($top['characters'] / $total * 100, 1) : '0.0') . '% of characters in this selection.</span></div>' .
    '<div class="signal"><b>Top-five concentration</b><span>' . $top5Pct .
      '% indicates how much the footprint is concentrated in its five leading locations.</span></div>' .
    '<div class="signal"><b>Capital footprint</b><span>' . $capitalPct .
      '% were last recorded in recognized capitals.</span></div>' .
    '<div class="signal"><b>Level context</b><span>Average level adds context for each location.</span></div></div>';
  return $tiles . '<div class="geogrid"><div><div class="panel census" style="margin-bottom:18px"><div class="ptitle">Where players are &middot; ' . $win . '</div>' .
    $bars . $moreBars . '</div>' .
    '<div class="panel census"><div class="ptitle">Location detail</div><table class="geotable"><thead><tr><th>Location</th><th>Characters</th><th>Share</th><th>Activity</th></tr></thead><tbody>' .
    $rows . '</tbody></table></div></div>' . $signals . '</div>';
}

function render_geography_html(array $views, array $dims, array $opts): string {
  $realms = $dims['realm'] ?? []; $factions = $dims['faction'] ?? [];
  $activeRealm = $realms ? $realms[0]['key'] : 'all';
  $activeFaction = $factions ? $factions[0]['key'] : 'all';
  $chips = chip_row('realm', $realms, $activeRealm) . chip_row('faction', $factions, $activeFaction);
  $renderer = ($_COOKIE['wowcensus_skin'] ?? '') === 'cobalt' ? 'render_geography_aero_view' : 'render_geography_view';
  $panes = view_panes($views, $activeRealm, $activeFaction, $renderer,
    pane_url('geography', $renderer === 'render_geography_aero_view' ? 'view=aero' : ''));
  $gameLabel = $opts['gameLabel'] ?? 'WoW Forever';
  $navigation = $opts['nav'];
  $heading = ($navigation['games'] ?? '') !== '' ? 'WoWCensus' : $gameLabel . ' — Geography';
  $notes = notes_html($opts['notes'] ?? []);
  $generatedNote = generated_note_html($opts['generatedNote'] ?? null);
  $seoGameLabel = seo_label($gameLabel);
  $topic = $seoGameLabel . ' Population by Zone';
  $seo = seo_head($topic . ' – WoWCensus', $seoGameLabel . ' player activity by zone, realm and faction: where characters were last seen, from latest-known locations gathered by /who scans.', $opts['canonical'] ?? null);
  $stylesheet = esc($opts['stylesheet'] ?? 'style.css');
  $census_css = CENSUS_STYLESHEET;
  $style = js_template_const('geography.mjs', 'GEO_STYLE');
  $headingHtml = page_heading($heading, $navigation, $topic);
  $games = $navigation['games'] ?? '';
  $crumbs = ($navigation['breadcrumbs'] ?? '') !== '' ? $navigation['breadcrumbs'] : $notes;
  $pages = $navigation['pages'] ?? '';
  $toggle = $chips !== '' ? toggle_script() : '';
  if ($renderer === 'render_geography_aero_view') $toggle .= '<script src="/geo-aero.js?v=1" defer></script>';
  return <<<HTML
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="google-site-verification" content="N0-ZNJyDo16jefYUGxfcAda_mKf7S2oATfRGWETdsHs">
$seo
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96">
<link rel="icon" href="/favicon-96.png" type="image/png" sizes="96x96">
<link rel="preload" href="/fonts/press-start-2p-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/vt323-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/cinzel-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="$stylesheet">$census_css<style>$style</style>
</head><body><div class="crt" aria-hidden="true"></div><div class="wrap">
<header class="ihead"><div class="page-heading">$headingHtml$games</div>
$crumbs</header>
<div class="page-controls" id="geo-location-controls">$pages$chips</div>$panes
$generatedNote
</div>$toggle</body></html>
HTML;
}
