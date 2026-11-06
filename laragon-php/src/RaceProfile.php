<?php
// Port of site/src/race-profile.mjs. The Node build runs race-template.html's
// script in a DOM emulator (linkedom) and serializes the result. This builds
// the same document directly from the template text, following linkedom's
// serialization: text escapes only & < >, attribute values only ", and an
// attribute added to an existing element is written first.

declare(strict_types=1);

function race_profiles(): array {
  static $p = null;
  if ($p) return $p;
  $Human = ['plural' => 'Humans', 'faction' => 'Alliance']; $Dwarf = ['plural' => 'Dwarves', 'faction' => 'Alliance'];
  $Gnome = ['plural' => 'Gnomes', 'faction' => 'Alliance']; $NightElf = ['plural' => 'Night Elves', 'icon' => 'achievement_character_nightelf_male', 'faction' => 'Alliance'];
  $Orc = ['plural' => 'Orcs', 'faction' => 'Horde']; $Undead = ['plural' => 'Undead', 'faction' => 'Horde'];
  $Tauren = ['plural' => 'Tauren', 'faction' => 'Horde']; $Troll = ['plural' => 'Trolls', 'faction' => 'Horde'];
  $Draenei = ['plural' => 'Draenei', 'faction' => 'Alliance']; $BloodElf = ['plural' => 'Blood Elves', 'faction' => 'Horde'];
  $Worgen = ['plural' => 'Worgen', 'faction' => 'Alliance']; $Goblin = ['plural' => 'Goblins', 'faction' => 'Horde'];
  $Pandaren = ['plural' => 'Pandaren', 'faction' => 'Both'];
  $classicSet = ['Human' => $Human, 'Dwarf' => $Dwarf, 'Gnome' => $Gnome, 'Night Elf' => $NightElf, 'Orc' => $Orc, 'Undead' => $Undead, 'Tauren' => $Tauren, 'Troll' => $Troll];
  $races = [
    'classic-beta' => ['Human' => $Human, 'Dwarf' => $Dwarf, 'Gnome' => $Gnome,
      'High Order Skyborne' => ['label' => 'Alliance Skyborne', 'slug' => 'skyborne', 'plural' => 'Skyborne', 'icon' => 'achievement_character_bloodelf_male', 'faction' => 'Alliance'],
      'Night Elf' => $NightElf, 'Orc' => $Orc, 'Undead' => $Undead, 'Tauren' => $Tauren,
      'Windshaper Skyborne' => ['label' => 'Horde Skyborne', 'plural' => 'Skyborne', 'icon' => 'achievement_character_bloodelf_male', 'faction' => 'Horde'],
      'Troll' => $Troll],
    'classic-progression' => ['Human' => $Human, 'Dwarf' => $Dwarf, 'Gnome' => $Gnome, 'Night Elf' => $NightElf, 'Draenei' => $Draenei, 'Orc' => $Orc, 'Undead' => $Undead, 'Tauren' => $Tauren, 'Troll' => $Troll, 'Blood Elf' => $BloodElf],
    'classic' => $classicSet, 'sod' => $classicSet, 'hardcore' => $classicSet,
    'mop-classic' => ['Human' => $Human, 'Dwarf' => $Dwarf, 'Gnome' => $Gnome, 'Night Elf' => $NightElf, 'Draenei' => $Draenei, 'Worgen' => $Worgen,
      'Pandaren' => $Pandaren, 'Orc' => $Orc, 'Undead' => $Undead, 'Tauren' => $Tauren, 'Troll' => $Troll, 'Blood Elf' => $BloodElf, 'Goblin' => $Goblin],
    'retail' => ['Human' => $Human, 'Dwarf' => $Dwarf, 'Gnome' => $Gnome, 'Night Elf' => $NightElf, 'Draenei' => $Draenei, 'Worgen' => $Worgen,
      'Void Elf' => ['plural' => 'Void Elves', 'faction' => 'Alliance'], 'Lightforged Draenei' => ['plural' => 'Lightforged Draenei', 'faction' => 'Alliance'],
      'Dark Iron Dwarf' => ['plural' => 'Dark Iron Dwarves', 'faction' => 'Alliance'], 'Kul Tiran' => ['plural' => 'Kul Tirans', 'faction' => 'Alliance'],
      'Mechagnome' => ['plural' => 'Mechagnomes', 'faction' => 'Alliance'],
      'Pandaren' => $Pandaren, 'Dracthyr' => ['plural' => 'Dracthyr', 'faction' => 'Both'], 'Earthen' => ['plural' => 'Earthen', 'faction' => 'Both'],
      'Haranir' => ['plural' => 'Haranir', 'faction' => 'Both'],
      'Orc' => $Orc, 'Undead' => $Undead, 'Tauren' => $Tauren, 'Troll' => $Troll, 'Blood Elf' => $BloodElf, 'Goblin' => $Goblin,
      'Nightborne' => ['plural' => 'Nightborne', 'faction' => 'Horde'], 'Highmountain Tauren' => ['plural' => 'Highmountain Tauren', 'faction' => 'Horde'],
      "Mag'har Orc" => ['slug' => 'maghar-orc', 'plural' => "Mag'har Orcs", 'faction' => 'Horde'],
      'Zandalari Troll' => ['plural' => 'Zandalari Trolls', 'faction' => 'Horde'], 'Vulpera' => ['plural' => 'Vulpera', 'faction' => 'Horde']],
  ];
  $races['forever'] = $races['classic-beta'];
  $p = [];
  foreach ($races as $source => $list)
    foreach ($list as $race => $profile)
      $p[$source][$race] = $profile + ['path' => '/' . PROFILE_GAMES[$source]['dir'] . '/' .
        ($profile['slug'] ?? preg_replace('/\s+/', '-', js_lower($profile['label'] ?? $race))) . '/'];
  return $p;
}

function race_routes(): array {
  $out = [];
  foreach (race_profiles() as $races) foreach ($races as $p) $out[] = $p['path'];
  return $out;
}

const MODERN_GAMES = ['retail', 'mop-classic'];
const TANK_SPECS = ['Protection', 'Blood', 'Guardian', 'Brewmaster', 'Vengeance'];

function race_profile_data(array $inspects, string $race, string $source): array {
  $modern = in_array($source, MODERN_GAMES, true);
  $rows = array_values(array_filter($inspects['records'], fn($r) => $r['race'] === $race &&
    ($modern ? ($r['classification'] ?? '') !== '' && $r['classification'] !== 'Unknown build' : count($r['talents']) > 0)));
  $classes = [];
  $classOrder = [];
  foreach ($rows as $r) $classOrder[$r['classFile']] = true;
  foreach (array_keys($classOrder) as $cf) {
    $members = array_values(array_filter($rows, fn($r) => $r['classFile'] === $cf));
    $spec = []; $tm = []; $tree = [];
    foreach ($members as $r) {
      $name = ($r['classification'] ?? '') !== '' ? $r['classification'] : 'Unknown build';
      $spec[$name] = ($spec[$name] ?? 0) + 1;
      if (!$modern) foreach ($r['orderedPoints'] ?? [] as $p) $tree[$p['tree']] = ($tree[$p['tree']] ?? 0) + $p['points'];
      $unique = [];
      foreach ($r['talents'] as $t) $unique['n' . js_string($t['node'])] = $t;
      foreach ($unique as $t) {
        if ($modern && preg_match('/^node \d+$/', (string)$t['name'])) continue;
        $key = 'n' . js_string($t['node']);
        $tm[$key] ??= ['name' => $t['name'], 'n' => 0, 'ranks' => 0, 'max' => $t['max'], 'icon' => ($t['icon'] ?? null) ?: 'inv_misc_questionmark',
          'tree' => ($t['branch'] ?? '') !== '' ? $t['branch'] : 'Unmapped', 'specs' => []];
        $tm[$key]['n']++;
        $tm[$key]['ranks'] += $t['rank'] ?: 0;
        if ($modern) $tm[$key]['specs'][$name] = ($tm[$key]['specs'][$name] ?? 0) + 1;
      }
    }
    $tal = array_slice(js_sort(array_values($tm), fn($a, $b) => js_or($b['n'] - $a['n'], fn() => js_cmp($a['name'], $b['name']))), 0, 6);
    $treeEntries = js_sort(js_entries($tree), fn($a, $b) => $b[1] - $a[1]);
    $trees = [];
    foreach ($treeEntries as [$k, $n]) $trees[$k] = js_num(js_fixed($n / count($members), 1));
    $classes[$cf] = ['n' => count($members), 'spec' => $spec,
      'tal' => array_map(fn($t) => ['name' => $t['name'], 'pct' => pct_round($t['n'], count($members)), 'avgRank' => js_num(js_fixed($t['ranks'] / $t['n'], 1)),
        'max' => $t['max'], 'icon' => $t['icon'],
        'tree' => $modern ? (count($t['specs']) > 1 ? 'Shared' : (string)js_keys($t['specs'])[0]) : $t['tree']], $tal),
      'trees' => $trees,
      'medLv' => median(array_map(fn($r) => $r['level'], $members))];
  }
  return ['n' => count($rows), 'classes' => $classes, 'medLv' => median(array_map(fn($r) => $r['level'], $rows))];
}

// linkedom's serialization of text and attribute values.
function le_text(mixed $s): string { return strtr(js_string($s), ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']); }
function le_attr(mixed $s): string { return str_replace('"', '&quot;', js_string($s)); }

// $D is race_profile_data() for this race, as race_profiles_model() publishes it.
function render_race_profile(array $D, string $game, string $race, string $stylesheet, string $canonical): string {
  $G = PROFILE_GAMES[$game];
  $PROFILES = race_profiles()[$game];
  $modern = in_array($game, MODERN_GAMES, true);
  $profile = $PROFILES[$race];
  $plural = $profile['plural']; $path = $profile['path']; $label = $profile['label'] ?? $race;
  $icon = $profile['icon'] ?? (RACE_ICON[$race] ?? null);
  $prefix = root_prefix($path);
  $CLASS = [];
  foreach (CLASS_META as $cf => [$n, $c]) $CLASS[$cf] = [$n, '#' . $c];
  $SPECICON = spec_icons(); $SPECCOLOR = spec_colors();
  $ICON = fn($n) => $prefix . 'assets/images/wow/icons/large/' . js_string($n) . '.jpg';
  $specColor = fn($s, $cf) => ($SPECCOLOR[$s . ':' . $cf] ?? null) ?: $CLASS[$cf][1];
  $pct = fn($a, $b) => js_round(100 * $a / $b);

  $src = strtr(race_template(), ['{{GAME}}' => $G['gameLabel'], '{{RACES}}' => $plural]);
  $src = str_replace('{{RACE_SLUG}}', preg_replace('/\s+/', '', js_lower($label)), $src);
  $src = str_replace('{{RACE}}', $label, $src);
  $src = str_replace(['../static/fonts/', '../static/assets/'], [$prefix . 'fonts/', $prefix . 'assets/'], $src);
  $src = str_replace("\r\n", "\n", $src);

  // Head: the emulator's own doctype, boolean attributes, new title, stylesheet and meta.
  [$head, $body] = explode('</head>', $src, 2);
  $head = str_replace('<!doctype html>', '<!DOCTYPE html>', $head);
  $head = str_replace(' crossorigin>', ' crossorigin="">', $head);
  // The title element's text is written raw.
  $title = $G['gameLabel'] . ' ' . $label . ' Specs & Talents – WoWCensus';
  $head = preg_replace_callback('/<title>[\s\S]*?<\/title>/', fn() => '<title>' . $title . '</title>', $head, 1);
  $head = str_replace('<style>', '<link rel="stylesheet" href="' . le_attr($prefix . $stylesheet) . '"><style>', $head);
  $head .= '<meta name="description" content="' . le_attr($label . ' class builds in ' . $G['gameLabel'] . ': which classes ' . $plural . ' play, their most popular specs and most-picked talents, from player inspections.') . '">' .
    '<link rel="canonical" href="' . le_attr($canonical) . '"><meta property="og:type" content="website"><meta property="og:site_name" content="WoWCensus">' .
    '<meta property="og:title" content="' . le_attr($G['gameLabel'] . ' ' . $label . ' Specs & Talents – WoWCensus') . '"><meta property="og:url" content="' . le_attr($canonical) . '">' .
    '<link rel="icon" href="' . le_attr($prefix . 'favicon.ico') . '">';

  // Navigation from the game config.
  $links = function (array $items, string $current): string {
    $out = '';
    foreach ($items as [$text, $href])
      $out .= '<a ' . ($text === $current ? 'aria-current="true" ' : '') . 'href="' . le_attr($href) . '" class="game">' . le_text($text) . '</a>';
    return $out;
  };
  $gameNav = $links(profile_game_nav(fn($g) => race_profiles()[$g][$race]['path'] ?? null), $G['nav']);
  $pageNav = $links(profile_page_nav($game), 'Talents');
  $body = preg_replace('/(<nav class="games game-nav" aria-label="Game edition">)[\s\S]*?(<\/nav>)/', '${1}' .str_replace(['\\', '$'], ['\\\\', '\\$'], $gameNav) . '$2', $body, 1);
  $body = preg_replace('/(<nav class="games page-nav" aria-label="Site pages">)[\s\S]*?(<\/nav>)/', '${1}' .str_replace(['\\', '$'], ['\\\\', '\\$'], $pageNav) . '$2', $body, 1);
  $crumbHrefs = ['/', $G['home'], talents_path($game)];
  $n = 0;
  $body = preg_replace_callback('/(<nav class="breadcrumbs"[\s\S]*?<\/nav>)/', function ($m) use (&$n, $crumbHrefs, $G) {
    return preg_replace_callback('/<a href="#">([^<]*)<\/a>/', function ($a) use (&$n, $crumbHrefs, $G) {
      $i = $n++;
      $text = $i === 1 ? le_text($G['nav']) : $a[1];
      return '<a href="' . le_attr($crumbHrefs[$i]) . '">' . $text . '</a>';
    }, $m[1]);
  }, $body, 1);

  // Race chips and the faction picker.
  $currentFaction = $profile['faction'] === 'Both' ? 'Alliance' : $profile['faction'];
  $chips = '';
  foreach ($PROFILES as $key => $p) {
    $attrs = ($key === $race ? 'aria-current="page" ' : '') .
      ($p['faction'] !== 'Both' && $p['faction'] !== $currentFaction ? 'hidden ' : '') .
      'data-faction="' . le_attr($p['faction']) . '" href="' . le_attr($p['path']) . '" class="chip"';
    $chips .= '<a ' . $attrs . '>' . le_text($p['label'] ?? $key) . '</a>';
  }
  $body = preg_replace('/(<div class="chips" aria-label="Race">)[\s\S]*?(<\/div>)/', '${1}' .str_replace(['\\', '$'], ['\\\\', '\\$'], $chips) . '$2', $body, 1);
  $body = preg_replace_callback('/<option value="(Alliance|Horde)">/', fn($m) => $m[1] === $currentFaction ? '<option selected value="' . $m[1] . '">' : $m[0], $body);

  // Hero and tiles.
  if ($icon) $body = preg_replace('/(<img class="portrait" src=")[^"]*/', '${1}' .str_replace(['\\', '$'], ['\\\\', '\\$'], le_attr($prefix . 'assets/images/wow/icons/large/' . $icon . '.jpg')), $body, 1);
  $order = array_keys($D['classes']);
  $order = array_map('strval', js_sort(js_keys($D['classes']), fn($a, $b) => $D['classes'][$b]['n'] - $D['classes'][$a]['n']));
  $top = $order[0] ?? null;
  $topN = $top !== null ? $D['classes'][$top]['n'] : 0;
  $specs = [];
  foreach ($order as $cf)
    foreach (js_entries($D['classes'][$cf]['spec']) as [$s, $cnt])
      if ($s !== 'Unknown build' && !str_contains($s, '/')) $specs[] = ['cf' => $cf, 's' => (string)$s, 'n' => $cnt];
  $specs = js_sort($specs, fn($a, $b) => $b['n'] - $a['n']);
  $tank = js_sort(array_values(array_filter($specs, fn($x) => $modern ? in_array($x['s'], TANK_SPECS, true) : $x['s'] === 'Protection')), fn($a, $b) => $b['n'] - $a['n']);
  $first = $specs[0] ?? null;
  $tankName = fn($x) => ($x['s'] === 'Protection' ? 'Prot ' : $x['s'] . ' ') . (CLASS_META[$x['cf']][0] ?? $x['cf']);
  if ($D['n']) {
    $intro = le_text('Among ' . $plural . " we've inspected, ") . '<strong>' . le_text(CLASS_META[$top][0] ?? $top) . '</strong>' . le_text(' is the most common class');
    $intro .= $first ? le_text(', with ') . '<strong>' . le_text($first['s']) . '</strong>' . le_text(' leading among identified builds.')
      : le_text('. No identified builds are available in this sample.');
  } else $intro = le_text('No ' . $label . ' inspections with readable talents are available in this snapshot.');
  $body = preg_replace('/(<section class="hero slab">[\s\S]*?<p>)[\s\S]*?(<\/p>)/', '${1}' .str_replace(['\\', '$'], ['\\\\', '\\$'], $intro) . '$2', $body, 1);
  $body = preg_replace('/(<div class="bignum"><b>)[^<]*(<\/b><small>)[^<]*(<\/small>)/',
    '${1}' .js_locale($D['n']) . '${2}' . str_replace(['\\', '$'], ['\\\\', '\\$'], le_text($plural . ($modern ? ' with a known spec' : ' with talents'))) . '$3', $body, 1);
  if ($modern) $body = preg_replace('/<section class="panel">\s*<div class="ptitle">Hybrids &amp; unknowns<\/div>[\s\S]*?<\/section>/', '', $body, 1);

  $tileIndex = 0;
  $body = preg_replace_callback('/<div class="tile"( style="([^"]*)")?><div class="k">([^<]*)<\/div><div class="v">([^<]*)<\/div>(?:<div class="s">([^<]*)<\/div>)?<\/div>/',
    function ($m) use (&$tileIndex, $top, $topN, $D, $first, $tank, $modern, $tankName, $pct, $SPECCOLOR) {
      $i = $tileIndex++;
      $style = $m[1] !== '' ? $m[2] : null; $k = $m[3]; $v = $m[4]; $s = $m[5] ?? null;
      if ($i === 0 && $top !== null) {
        $style = '--c:#' . (CLASS_META[$top][1] ?? 'ffce43'); $v = le_text(CLASS_META[$top][0] ?? $top);
        $s = le_text($topN . ' of ' . $D['n'] . ' · ' . pct_round($topN, $D['n']) . '%');
      } elseif ($i === 1 && $first) {
        $style = '--c:' . (($SPECCOLOR[$first['s'] . ':' . $first['cf']] ?? null) ?: '#' . (CLASS_META[$first['cf']][1] ?? 'ffce43'));
        $v = le_text($first['s']); $s = le_text($first['n'] . ' players · ' . pct_round($first['n'], $D['n']) . '%');
      } elseif ($i === 2) {
        $v = le_text($tank ? $tankName($tank[0]) : 'None classified');
        $s = le_text($tank ? $tank[0]['n'] . ' inspected' . (isset($tank[1]) ? ' · ahead of ' . $tank[1]['n'] . ' ' . ($modern ? $tankName($tank[1]) : (CLASS_META[$tank[1]['cf']][0] ?? $tank[1]['cf'])) : '')
          : ($modern ? 'No tank spec observed' : 'No Protection build observed'));
      } elseif ($i === 3) {
        $v = le_text($D['medLv'] ?? '—');
      }
      return '<div class="tile"' . ($style !== null ? ' style="' . le_attr($style) . '"' : '') . '><div class="k">' . $k . '</div><div class="v">' . $v . '</div>' .
        ($s !== null ? '<div class="s">' . $s . '</div>' : '') . '</div>';
    }, $body);

  // The template script's rendering.
  $row = function (array $r, int $i, int|float $max, bool $hy) use ($CLASS, $specColor, $SPECICON, $ICON, $pct, $D): string {
    $cn = $CLASS[$r['cf']][0];
    $col = $hy ? $CLASS[$r['cf']][1] : $specColor($r['s'], $r['cf']);
    $icon = $hy ? 'classicon_' . js_lower($r['cf']) : (($SPECICON[$r['s'] . ':' . $r['cf']] ?? null) ?: ($SPECICON[$r['s']] ?? 'undefined'));
    return '<li style="--c:' . le_attr($col) . '"><span class="rank">' . ($hy ? '~' : $i + 1) . '</span><img src="' . le_attr($ICON($icon)) . '" alt="" width="34" height="34">' . "\n" .
      '    <span class="sname">' . le_text($hy ? $cn : $r['s']) . '<small>' . le_text($hy ? $r['s'] : $cn) . '</small></span>' . "\n" .
      '    <span class="track"><i style="width:' . js_fixed(100 * $r['n'] / $max, 1) . '%"></i></span>' . "\n" .
      '    <span class="num"><b>' . $r['n'] . '</b><span>' . js_str($pct($r['n'], $D['n'])) . '%</span></span></li>';
  };
  $hybrids = [];
  foreach ($order as $cf) {
    $h = 0;
    foreach (js_entries($D['classes'][$cf]['spec']) as [$s, $cnt]) if (str_contains((string)$s, '/') || $s === 'Unknown build') $h += $cnt;
    if ($h) $hybrids[] = ['cf' => $cf, 's' => 'Hybrid / unknown', 'n' => $h];
  }
  $boardTop = ($specs[0]['n'] ?? 0) ?: 1;
  $board = '';
  foreach (array_slice($specs, 0, 12) as $i => $r) $board .= $row($r, $i, $boardTop, false);
  $hyRows = '';
  foreach (js_sort($hybrids, fn($a, $b) => $b['n'] - $a['n']) as $i => $r) $hyRows .= $row($r, $i, $boardTop, true);
  $strip = ''; $stripKey = '';
  foreach ($order as $cf) {
    $strip .= '<i style="width:' . js_str(100 * $D['classes'][$cf]['n'] / $D['n']) . '%;background:' . $CLASS[$cf][1] . '" title="' . le_attr($CLASS[$cf][0]) . '"></i>';
    $stripKey .= '<span style="--c:' . $CLASS[$cf][1] . '"><b>' . le_text($CLASS[$cf][0]) . '</b> ' . js_str($pct($D['classes'][$cf]['n'], $D['n'])) . '%</span>';
  }
  $cards = '';
  foreach ($order as $cf) {
    $c = $D['classes'][$cf]; [$cn, $col] = $CLASS[$cf];
    $trees = js_entries($c['trees']);
    $ti = [];
    foreach ($trees as $i => [$t]) $ti[$t] = $i;
    $hy = 0; $pure = [];
    foreach (js_entries($c['spec']) as [$s, $cnt]) {
      $s = (string)$s;
      if (!str_contains($s, '/') && $s !== 'Unknown build') $pure[] = [$s, $cnt]; else $hy += $cnt;
    }
    $pure = js_sort($pure, fn($a, $b) => js_or(($ti[$a[0]] ?? 99) - ($ti[$b[0]] ?? 99), fn() => $b[1] - $a[1]));
    $seg = ''; $key = '';
    foreach ($pure as [$s, $cnt]) {
      $seg .= '<i style="--k:' . le_attr($specColor($s, $cf)) . ';width:' . js_str(100 * $cnt / $c['n']) . '%" title="' . le_attr($s . ' ' . $cnt) . '"></i>';
      $key .= '<span style="--k:' . le_attr($specColor($s, $cf)) . '"><b>' . le_text($s) . '</b> ' . js_str($pct($cnt, $c['n'])) . '%</span>';
    }
    if ($hy) {
      $seg .= '<i class="th" style="width:' . js_str(100 * $hy / $c['n']) . '%" title="Hybrid ' . $hy . '"></i>';
      $key .= '<span class="kh"><b>Hybrid</b> ' . js_str($pct($hy, $c['n'])) . '%</span>';
    }
    $tr = '';
    foreach ($trees as [$t, $p])
      $tr .= '<div class="tree"><div class="tn">' . le_text($t) . '</div><div class="tv" style="color:' . le_attr($specColor((string)$t, $cf)) . '">' . js_string($p) . '</div></div>';
    $tal = '';
    foreach ($c['tal'] as $t) {
      $on = js_round($t['avgRank']);
      $pips = '';
      for ($k = 0; $k < $t['max']; $k++) $pips .= $k < $on ? '<i class="on"></i>' : '<i></i>';
      $tal .= '<li><img src="' . le_attr($ICON($t['icon'])) . '" alt="" width="34" height="34"><span class="tt"><b>' . le_text($t['name']) . '</b>' . "\n" .
        '      <span class="meta"><span class="pips" title="avg rank ' . le_attr(js_string($t['avgRank']) . ' of ' . js_string($t['max'])) . '">' . $pips . '</span>' . le_text($t['tree']) . '</span></span>' . "\n" .
        '      <span class="pick"><b>' . js_string($t['pct']) . '%</b><div class="mini"><i style="width:' . js_string($t['pct']) . '%"></i></div></span></li>';
    }
    $cards .= '<article class="card" data-class="' . le_attr($cf) . '" style="--c:' . le_attr($col) . '">' . "\n" .
      '    <div class="card-h"><img src="' . $prefix . 'assets/images/wow/icons/large/classicon_' . js_lower($cf) . '.jpg" alt="" width="40" height="40"><h4>' . le_text($cn) . '</h4>' . "\n" .
      '      <div class="cn"><b>' . $c['n'] . '</b>inspected · ' . js_str($pct($c['n'], $D['n'])) . '%</div></div>' . "\n" .
      '    <div class="card-b">' . "\n" .
      '      <p class="lbl">Spec split</p><div class="specbar">' . $seg . '</div><div class="speckey">' . $key . '</div>' . "\n" .
      '      ' . ($trees ? '<p class="lbl">Avg points per tree</p><div class="trees">' . $tr . '</div>' : '') . "\n" .
      '      <p class="lbl">Most-picked talents</p><ul class="tal">' . $tal . '</ul>' . "\n" .
      '    </div>' . "\n" .
      '    <div class="card-f">Median level ' . le_text(js_string($c['medLv'])) . '</div>' . "\n" .
      '  </article>';
  }
  $classChips = '<button aria-pressed="true" class="chip" data-c="">All</button>';
  foreach ($order as $cf) $classChips .= '<button aria-pressed="false" class="chip" data-c="' . le_attr($cf) . '">' . le_text($CLASS[$cf][0]) . '</button>';
  $fill = function (string $body, string $id, string $html): string {
    return preg_replace('/(id="' . $id . '"[^>]*>)(<\/(?:ol|div)>)/', '${1}' .str_replace(['\\', '$'], ['\\\\', '\\$'], $html) . '$2', $body, 1);
  };
  $body = $fill($body, 'board', $board);
  $body = $fill($body, 'hybrids', $hyRows);
  $body = $fill($body, 'strip', $strip);
  $body = $fill($body, 'stripkey', $stripKey);
  $body = $fill($body, 'classchips', $classChips);
  $body = $fill($body, 'cards', $cards);

  // The template script is removed; the page's own small script goes at the end of the body.
  $body = preg_replace('/<script>[\s\S]*?<\/script>/', '', $body, 1);
  $script = "<script>(()=>{const chips=document.getElementById('classchips'),cards=[...document.querySelectorAll('.card')];function apply(s){const v=cards.some(c=>c.dataset.class===s)?s:'';chips.querySelectorAll('button').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.c===v)));cards.forEach(c=>c.hidden=!!v&&c.dataset.class!==v)}chips.addEventListener('click',e=>{const b=e.target.closest('button[data-c]');if(!b)return;const u=new URL(location.href);b.dataset.c?u.searchParams.set('class',b.dataset.c):u.searchParams.delete('class');history.pushState(null,'',u.pathname+u.search+u.hash);apply(b.dataset.c)});addEventListener('popstate',()=>apply(new URL(location.href).searchParams.get('class')||''));apply(new URL(location.href).searchParams.get('class')||'')})();(()=>{const select=document.querySelector('.faction-picker select'),links=[...document.querySelectorAll('.race-filter .chips a')];select.addEventListener('change',()=>links.forEach(link=>link.hidden=link.dataset.faction!=='Both'&&link.dataset.faction!==select.value))})();</script>";
  // Text after the template's </body> moves inside the body, before the new script.
  $at = strrpos($body, '</body>');
  $body = substr($body, 0, $at) . $script . substr($body, $at);
  return $head . '</head>' . $body;
}
