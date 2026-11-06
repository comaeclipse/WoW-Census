<?php
// Port of site/src/profile-games.mjs and class-profile.mjs.

declare(strict_types=1);

const PROFILE_GAMES = [
  'forever' => ['dir' => 'forever', 'home' => '/forever/', 'nav' => 'Forever', 'gameLabel' => 'WoW: Forever'],
  'classic-beta' => ['dir' => 'forever-beta', 'home' => '/forever-beta/', 'nav' => 'Forever (Beta)', 'gameLabel' => 'WoW: Forever (Beta)'],
  'classic-progression' => ['dir' => 'tbc', 'home' => '/tbc/', 'nav' => 'TBC Anniversary', 'gameLabel' => 'TBC Anniversary'],
  'classic' => ['dir' => 'classic', 'home' => '/classic/', 'nav' => 'Classic Era', 'gameLabel' => 'Classic Era'],
  'sod' => ['dir' => 'sod', 'home' => '/sod/', 'nav' => 'SoD', 'gameLabel' => 'Season of Discovery'],
  'hardcore' => ['dir' => 'hardcore', 'home' => '/hardcore/', 'nav' => 'Hardcore', 'gameLabel' => 'Classic Hardcore'],
  'mop-classic' => ['dir' => 'mop', 'home' => '/mop/', 'nav' => 'MoP Classic', 'gameLabel' => 'Mists of Pandaria Classic'],
  'retail' => ['dir' => 'retail', 'home' => '/retail/', 'nav' => 'Retail', 'gameLabel' => 'Retail'],
];
const PAGE_NAV = [['Census', ''], ['Realms', 'realms'], ['Auction House', 'auctionhouse'], ['Guilds', 'guilds'], ['Geography', 'geography'], ['Talents', 'talents'], ['Gear', 'gear']];

function profile_page_nav(string $source): array {
  return array_map(fn($p) => [$p[0], PROFILE_GAMES[$source]['home'] . $p[1]], PAGE_NAV);
}
function talents_path(string $source): string { return PROFILE_GAMES[$source]['home'] . 'talents'; }
function profile_game_nav(callable $counterpart): array {
  $out = [];
  foreach (PROFILE_GAMES as $source => $g) $out[] = [$g['nav'], $counterpart($source) ?: talents_path($source)];
  return $out;
}
function root_prefix(string $route): string {
  return str_repeat('../', count(array_filter(explode('/', $route), fn($s) => $s !== '')));
}

const PROFILE_CLASSES = ['PALADIN', 'WARRIOR', 'DRUID', 'HUNTER', 'SHAMAN', 'PRIEST', 'ROGUE', 'MAGE', 'WARLOCK'];
const MODERN_PROFILE_CLASSES = [...PROFILE_CLASSES, 'DEATHKNIGHT', 'MONK'];

function class_profiles(): array {
  static $p = null;
  if ($p) return $p;
  $p = [];
  foreach (PROFILE_GAMES as $source => $game)
    $p[$source] = $game + ['classes' => $source === 'retail' ? [...MODERN_PROFILE_CLASSES, 'DEMONHUNTER', 'EVOKER']
      : ($source === 'mop-classic' ? MODERN_PROFILE_CLASSES : PROFILE_CLASSES)];
  return $p;
}

const CLASS_TREES = [
  'PALADIN' => [['Holy', 'spell_holy_holybolt', '#eeb138'], ['Protection', 'spell_holy_devotionaura', '#4c9ddb'], ['Retribution', 'spell_holy_auraoflight', '#a96be0']],
  'DRUID' => [['Balance', 'spell_nature_starfall', '#7d6fe0'], ['Feral Combat', 'ability_racial_bearform', '#e88a3a'], ['Restoration', 'spell_nature_healingtouch', '#5fbf5f']],
  'HUNTER' => [['Beast Mastery', 'ability_hunter_beasttaming', '#b6c95e'], ['Marksmanship', 'ability_marksmanship', '#e0524a'], ['Survival', 'ability_hunter_swiftstrike', '#8fbf4a']],
  'SHAMAN' => [['Elemental', 'spell_nature_lightning', '#e0763a'], ['Enhancement', 'spell_nature_lightningshield', '#4c9ddb'], ['Restoration', 'spell_nature_magicimmunity', '#5fbf8f']],
  'PRIEST' => [['Discipline', 'spell_holy_wordfortitude', '#d8d2c0'], ['Holy', 'spell_holy_guardianspirit', '#eeb138'], ['Shadow', 'spell_shadow_shadowwordpain', '#8a63c9']],
  'ROGUE' => [['Assassination', 'ability_rogue_eviscerate', '#5fbf5f'], ['Combat', 'ability_backstab', '#e0524a'], ['Subtlety', 'ability_stealth', '#8a63c9']],
  'MAGE' => [['Arcane', 'spell_holy_magicalsentry', '#b06be0'], ['Fire', 'spell_fire_firebolt02', '#e0763a'], ['Frost', 'spell_frost_frostbolt02', '#5fb0f0']],
  'WARLOCK' => [['Affliction', 'spell_shadow_deathcoil', '#84d84e'], ['Demonology', 'spell_shadow_metamorphosis', '#8a63c9'], ['Destruction', 'spell_shadow_rainoffire', '#e0763a']],
  'WARRIOR' => [['Arms', 'ability_warrior_savageblow', '#e0524a'], ['Fury', 'ability_warrior_innerrage', '#eeac38'], ['Protection', 'inv_shield_06', '#539ad4']],
  'DEATHKNIGHT' => [['Blood', 'classicon_deathknight', '#bd3949'], ['Frost', 'classicon_deathknight', '#5fb0f0'], ['Unholy', 'classicon_deathknight', '#84d84e']],
  'MONK' => [['Brewmaster', 'classicon_monk', '#eeb138'], ['Windwalker', 'classicon_monk', '#5fbf5f'], ['Mistweaver', 'classicon_monk', '#5fb0f0']],
  'DEMONHUNTER' => [['Havoc', 'classicon_demonhunter', '#a96be0'], ['Vengeance', 'classicon_demonhunter', '#e0524a']],
  'EVOKER' => [['Devastation', 'classicon_evoker', '#e0763a'], ['Preservation', 'classicon_evoker', '#5fbf5f'], ['Augmentation', 'classicon_evoker', '#a96be0']],
];

function modern_trees(string $cf): ?array {
  if ($cf === 'DRUID') return ['Balance', 'Feral', 'Guardian', 'Restoration'];
  if ($cf === 'ROGUE') return ['Assassination', 'Outlaw', 'Subtlety'];
  return isset(CLASS_TREES[$cf]) ? array_map(fn($t) => $t[0], CLASS_TREES[$cf]) : null;
}

function spec_icons(): array {
  $o = [];
  foreach (CLASS_TREES as $cf => $trees) foreach ($trees as [$t, $icon]) $o[$t . ':' . $cf] = $icon;
  return $o + ['Feral:DRUID' => 'ability_druid_catform', 'Guardian:DRUID' => 'ability_racial_bearform', 'Outlaw:ROGUE' => 'ability_rogue_waylay'];
}

function spec_colors(): array {
  $o = [];
  foreach (CLASS_TREES as $cf => $trees) foreach ($trees as [$t, , $c]) $o[$t . ':' . $cf] = $c;
  return $o;
}

function class_slug(string $cf): string { return preg_replace('/\s+/', '-', js_lower(CLASS_META[$cf][0] ?? $cf)); }
function class_route(string $source, string $cf): string { return '/' . class_profiles()[$source]['dir'] . '/' . class_slug($cf) . '/'; }
function class_routes(): array {
  $out = [];
  foreach (class_profiles() as $source => $p) foreach ($p['classes'] as $cf) $out[] = class_route($source, $cf);
  return $out;
}

function median(array $values): int|float|null {
  $a = array_values(array_filter($values, fn($v) => is_int($v) || (is_float($v) && is_finite($v))));
  sort($a);
  return $a ? $a[(int)floor((count($a) - 1) / 2)] : null;
}

function pct_round(int|float $a, int|float $b): int|float { return $b ? js_round(100 * $a / $b) : 0; }

function class_profile(array $inspects, string $classFile, string $source): array {
  $modern = $source === 'retail' || $source === 'mop-classic';
  $rows = array_values(array_filter($inspects['records'], fn($r) => $r['classFile'] === $classFile &&
    ($modern ? ($r['classification'] ?? '') !== '' && $r['classification'] !== 'Unknown build' : count($r['talents']) > 0)));
  $known = $modern ? ($source === 'mop-classic' && $classFile === 'ROGUE' ? ['Assassination', 'Combat', 'Subtlety'] : modern_trees($classFile))
    : (isset(CLASS_TREES[$classFile]) ? array_map(fn($t) => $t[0], CLASS_TREES[$classFile]) : null);
  if (!$known) {
    $set = [];
    foreach ($rows as $r) foreach ($r['talents'] as $t) if (($t['branch'] ?? '') !== '') $set[$t['branch']] = true;
    $known = array_map('strval', array_keys($set)); sort($known, SORT_STRING);
  }
  $nodes = [];
  foreach ($rows as $r) foreach ($r['talents'] as $t) {
    $tree = $modern ? $r['classification'] : $t['branch'];
    $key = $modern ? $tree . ':' . js_string($t['node']) : 'n' . js_string($t['node']);
    $nodes[$key] ??= ['node' => $t['node'], 'name' => $t['name'], 'max' => $t['max'], 'icon' => ($t['icon'] ?? null) ?: 'inv_misc_questionmark', 'tree' => $tree];
  }
  $trees = [];
  foreach ($known as $tree) {
    $members = array_values(array_filter($rows, fn($r) => $r['classification'] === $tree));
    $picks = [];
    foreach ($members as $r) {
      $unique = [];
      foreach ($r['talents'] as $t) $unique[js_string($t['node'])] = $t;
      foreach ($unique as $t) {
        if (!$modern && $t['branch'] !== $tree) continue;
        $k = js_string($t['node']);
        $picks[$k] ??= ['n' => 0, 'ranks' => 0];
        $picks[$k]['n']++; $picks[$k]['ranks'] += $t['rank'] ?: 0;
      }
    }
    $talents = [];
    foreach ($nodes as $t) {
      if ($t['tree'] !== $tree) continue;
      $p = $picks[js_string($t['node'])] ?? ['n' => 0, 'ranks' => 0];
      $talents[] = ['name' => $t['name'], 'icon' => $t['icon'], 'max' => $t['max'], 'n' => $p['n'], 'pct' => pct_round($p['n'], count($members)),
        'avgRank' => $p['n'] ? js_num(js_fixed($p['ranks'] / $p['n'], 1)) : 0];
    }
    $talents = js_sort($talents, fn($a, $b) => js_or($b['n'] - $a['n'], fn() => js_cmp($a['name'], $b['name'])));
    $trees[] = ['tree' => $tree, 'n' => count($members), 'medLv' => median(array_map(fn($r) => $r['level'], $members)), 'talents' => $talents];
  }
  $hybrid = count(array_filter($rows, fn($r) => str_contains($r['classification'] ?? '', '/')));
  $unknown = count(array_filter($rows, fn($r) => !in_array($r['classification'], $known, true) && !str_contains($r['classification'] ?? '', '/')));
  return ['n' => count($rows), 'trees' => $trees, 'hybrid' => $hybrid, 'unknown' => $unknown];
}

// The race template owns the profile pages' look; reuse its stylesheet.
function profile_style(string $prefix): string {
  $template = race_template();
  if (!preg_match('/<style>([\s\S]*?)<\/style>/', $template, $m)) throw new RuntimeException('Race template style missing');
  return str_replace('../static/fonts/', $prefix . 'fonts/', $m[1]);
}

function race_template(): string {
  static $t = null;
  return $t ??= file_get_contents(resource_path('src/race-template.html'));
}

function esc_profile(mixed $s): string { return esc_markup($s); }

// $D is class_profile() for this class, as class_profiles_model() publishes it.
function render_class_profile(array $D, string $source, string $classFile, string $stylesheet, string $canonical): string {
  $P = class_profiles()[$source];
  $route = class_route($source, $classFile);
  $prefix = root_prefix($route);
  $icon = fn($n) => $prefix . 'assets/images/wow/icons/large/' . $n . '.jpg';
  $treeColor = fn($i) => CLASS_TREES[$classFile][$i][2] ?? (['var(--gold)', 'var(--blue)', 'var(--green)'][$i] ?? 'var(--gold)');
  $className = CLASS_META[$classFile][0] ?? $classFile;
  $plural = $className . 's';
  $title = "{$P['gameLabel']} $className Talents – WoWCensus";
  $ranked = js_sort($D['trees'], fn($a, $b) => $b['n'] - $a['n']);
  $named = fn($t) => '<strong>' . esc_profile($t['tree']) . '</strong> (' . pct_round($t['n'], $D['n']) . '%)';
  $modern = $source === 'retail' || $source === 'mop-classic';
  if (!$D['n']) $intro = 'No ' . esc_profile($className) . ' inspections with ' . ($modern ? 'a recognized specialization' : 'readable talents') . ' are available in this snapshot.';
  else {
    $others = implode(' and ', array_map($named, array_values(array_filter(array_slice($ranked, 1), fn($t) => $t['n']))));
    $intro = 'Among ' . esc_profile($plural) . " we've inspected, " . $named($ranked[0]) . ' is the most common build, followed by ' . ($others !== '' ? $others : 'no other single-tree build') . '.';
  }
  $seg = '';
  foreach ($D['trees'] as $i => $t)
    if ($t['n']) $seg .= '<i style="background:' . $treeColor($i) . ';flex:' . $t['n'] . '" title="' . esc_profile($t['tree']) . ' ' . $t['n'] . '"></i>';
  if ($D['hybrid']) $seg .= '<i class="th" style="flex:' . $D['hybrid'] . '" title="Hybrid ' . $D['hybrid'] . '"></i>';
  if ($D['unknown']) $seg .= '<i style="background:var(--muted);flex:' . $D['unknown'] . '" title="Unknown ' . $D['unknown'] . '"></i>';
  $key = '';
  foreach ($D['trees'] as $i => $t)
    $key .= '<span style="--c:' . $treeColor($i) . '"><b>' . esc_profile($t['tree']) . '</b> ' . $t['n'] . ' · ' . pct_round($t['n'], $D['n']) . '%</span>';
  if ($D['hybrid']) $key .= '<span class="kh"><b>Hybrid</b> ' . $D['hybrid'] . ' · ' . pct_round($D['hybrid'], $D['n']) . '%</span>';
  if ($D['unknown']) $key .= '<span style="--c:var(--muted)"><b>Unknown</b> ' . $D['unknown'] . ' · ' . pct_round($D['unknown'], $D['n']) . '%</span>';
  $columns = '';
  foreach ($D['trees'] as $i => $t) {
    $rows = '';
    foreach ($t['talents'] as $k => $x)
      $rows .= '<li' . ($x['n'] ? '' : ' class="none"') . '><span class="rank">' . ($k + 1) . '</span><img src="' . esc_profile($icon($x['icon'])) . '" alt="" width="30" height="30" loading="lazy">' .
        '<span class="tt"><b>' . esc_profile($x['name']) . '</b></span>' .
        '<span class="pick"><b>' . js_string($x['pct']) . '%</b><div class="mini"><i style="width:' . js_string($x['pct']) . '%"></i></div></span></li>';
    $body = !$t['n'] ? '<p class="colnote">No ' . esc_profile($t['tree']) . ' ' . esc_profile($plural) . ' in this sample.</p>'
      : (!$t['talents'] ? '<p class="colnote">No readable talent selections for this spec.</p>' : '<ol class="ranks">' . $rows . '</ol>');
    $anchor = preg_replace('/^-|-$/', '', preg_replace('/[^a-z0-9]+/', '-', js_lower((string)$t['tree'])));
    $columns .= '<article class="card speccol" id="' . esc_profile($anchor) . '" data-tree="' . esc_profile($t['tree']) . '" style="--c:' . $treeColor($i) . '">' .
      '<div class="card-h"><img src="' . esc_profile($icon(CLASS_TREES[$classFile][$i][1] ?? 'classicon_' . js_lower($classFile))) . '" alt="" width="40" height="40"><h4>' . esc_profile($t['tree']) . '</h4>' .
      '<div class="cn" title="' . $t['n'] . ' players"><b>' . $t['n'] . '</b>' . pct_round($t['n'], $D['n']) . '%</div></div>' .
      '<div class="card-b">' . $body . '</div>' .
      '<div class="card-f">' . ($t['n'] ? 'Median level ' . js_string($t['medLv']) : 'No players classified') . '</div></article>';
  }
  $classChips = count($P['classes']) > 1 ? '<div class="chips class-chip-links" aria-label="Class">' . implode('', array_map(fn($cf) =>
    '<a class="chip" href="' . class_route($source, $cf) . '"' . ($cf === $classFile ? ' aria-current="page"' : '') . '>' .
    '<img src="' . esc_profile($icon('classicon_' . js_lower($cf))) . '" alt="" width="20" height="20"><span>' . esc_profile(CLASS_META[$cf][0] ?? $cf) . '</span></a>', $P['classes'])) . '</div>' : '';
  $fonts = implode("\n", array_map(fn($f) => '<link rel="preload" href="' . $prefix . 'fonts/' . $f . '.woff2" as="font" type="font/woff2" crossorigin>',
    ['press-start-2p-latin', 'vt323-latin', 'cinzel-latin-800-normal', 'friz-quadrata-regular']));
  $gameNav = implode('', array_map(fn($e) => '<a class="game"' . ($e[0] === $P['nav'] ? ' aria-current="true"' : '') . ' href="' . $e[1] . '">' . $e[0] . '</a>',
    profile_game_nav(fn($game) => in_array($classFile, class_profiles()[$game]['classes'], true) ? class_route($game, $classFile) : null)));
  $pageNav = implode('', array_map(fn($e) => '<a class="game"' . ($e[0] === 'Talents' ? ' aria-current="true"' : '') . ' href="' . $e[1] . '">' . $e[0] . '</a>', profile_page_nav($source)));
  $eTitle = esc_profile($title); $eClass = esc_profile($className); $eGame = esc_profile($P['gameLabel']); $eCanon = esc_profile($canonical);
  $eSheet = esc_profile($stylesheet); $style = profile_style($prefix) . js_template_const('class-profile.mjs', 'CLASS_STYLE');
  $eNav = esc_profile($P['nav']); $talents = talents_path($source); $portrait = esc_profile($icon('classicon_' . js_lower($classFile)));
  $bigN = js_locale($D['n']); $ePlural = esc_profile($plural); $with = $modern ? 'known specs' : 'talents';
  return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>$eTitle</title>
<meta name="description" content="$eClass talents in $eGame: which talents players pick in each spec, ranked by how often they appear in nearby-player inspections."><link rel="canonical" href="$eCanon"><meta property="og:type" content="website"><meta property="og:site_name" content="WoWCensus"><meta property="og:title" content="$eTitle"><meta property="og:url" content="$eCanon"><link rel="icon" href="{$prefix}favicon.ico">
$fonts
<link rel="stylesheet" href="$prefix$eSheet">
<style>$style</style></head>
<body><div class="wrap">
<header class="ihead">
  <div class="page-heading">
    <h1 class="iname">WoWCensus<span class="itopic">$eGame $eClass Talents</span></h1>
    <div class="site-nav-stack">
      <nav class="games game-nav" aria-label="Game edition">$gameNav</nav>
      <nav class="games page-nav" aria-label="Site pages">$pageNav</nav>
    </div>
  </div>
  <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">WoWCensus</a><span aria-hidden="true">/</span><a href="{$P['home']}">$eNav</a><span aria-hidden="true">/</span><a href="$talents">Talents</a><span aria-hidden="true">/</span><span aria-current="page">$eClass</span></nav>
</header>
$classChips
<section class="hero slab class-profile-summary">
  <img class="portrait" src="$portrait" alt="" width="96" height="96">
  <div>
    <h2>$eClass <span>talents</span></h2>
    <p>$intro</p>
  </div>
  <div class="bignum"><b>$bigN</b><small>$ePlural with $with</small></div>
</section>
<section class="panel split">
  <div class="ptitle">Spec split</div>
  <div class="specbar">$seg</div><div class="speckey">$key</div>
</section>
<div class="speccols">$columns</div>
</div></body></html>
HTML;
}
