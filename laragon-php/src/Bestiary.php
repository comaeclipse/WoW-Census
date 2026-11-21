<?php
declare(strict_types=1);

/**
 * WoW: Forever bestiary: /forever/npc/ and a page per mob at /forever/npc/<slug>/.
 *
 * Every number is measured from combat logs (tools/import-combatlog-pg.py): a mob's level, health
 * and armor from Advanced Combat Logging, the hits it landed on players, and where it was seen.
 * Players in the logs are never stored. Dungeon bosses keep their journal page at the same address
 * and get the measured numbers as an extra section.
 */

function bestiary_ready(): bool {
  static $ok = null;
  return $ok ??= (bool)(Db::first("SELECT to_regclass('combatlog_npc_levels') IS NOT NULL AS ok")['ok'] ?? false);
}

/**
 * Measured mobs, slug => ['name', 'ids', 'logs', 'deaths', 'levels' => [level => [sightings, min_hp, max_hp, armor]],
 * 'abilities' => [[spell_id, ability, school, casts, hits, crits, misses, min, max, total]], 'places' => [name => sightings]].
 * Mobs that share a name share a page. Only mobs with a measured level are listed.
 */
function forever_bestiary(): array {
  static $by = null;
  if ($by !== null) return $by;
  $by = [];
  if (!bestiary_ready()) return $by;
  $g = DUNGEON_SOURCE;
  $mobs = [];
  foreach (Db::all("SELECT DISTINCT ON (npc_id) npc_id, name FROM combatlog_npc_names WHERE source_game = ? ORDER BY npc_id, last_seen DESC", [$g]) as $r)
    $mobs[(int)$r['npc_id']] = ['name' => (string)$r['name']];
  foreach (Db::all("SELECT npc_id, count(DISTINCT file_key) AS logs, sum(deaths) AS deaths FROM combatlog_npc_names WHERE source_game = ? GROUP BY 1", [$g]) as $r)
    if (isset($mobs[(int)$r['npc_id']])) $mobs[(int)$r['npc_id']] += ['logs' => (int)$r['logs'], 'deaths' => (int)$r['deaths']];
  $levels = [];
  foreach (Db::all("SELECT npc_id, level, sum(sightings) AS n, min(min_hp) AS lo, max(max_hp) AS hi, max(armor) AS armor
      FROM combatlog_npc_levels WHERE source_game = ? GROUP BY 1, 2 ORDER BY 1, 2", [$g]) as $r)
    $levels[(int)$r['npc_id']][(int)$r['level']] = [(int)$r['n'], (int)$r['lo'], (int)$r['hi'], $r['armor'] !== null ? (int)$r['armor'] : null];
  $abilities = [];
  foreach (Db::all("SELECT npc_id, spell_id, max(ability) AS ability, max(school) AS school, sum(casts) AS casts, sum(hits) AS hits,
      sum(crits) AS crits, sum(misses) AS misses, min(min_dmg) AS lo, max(max_dmg) AS hi, sum(total_dmg) AS total
      FROM combatlog_npc_abilities WHERE source_game = ? GROUP BY 1, 2", [$g]) as $r)
    $abilities[(int)$r['npc_id']][] = [(int)$r['spell_id'], (string)$r['ability'], $r['school'] !== null ? (int)$r['school'] : null, (int)$r['casts'],
      (int)$r['hits'], (int)$r['crits'], (int)$r['misses'], $r['lo'] !== null ? (int)$r['lo'] : null, $r['hi'] !== null ? (int)$r['hi'] : null, (int)$r['total']];
  $places = [];
  foreach (Db::all("SELECT npc_id, coalesce(max(map_name), 'Map ' || ui_map_id) AS place, sum(sightings) AS n
      FROM combatlog_npc_places WHERE source_game = ? GROUP BY npc_id, ui_map_id", [$g]) as $r)
    $places[(int)$r['npc_id']][(string)$r['place']] = ($places[(int)$r['npc_id']][(string)$r['place']] ?? 0) + (int)$r['n'];

  foreach ($mobs as $id => $m) {
    if (!isset($levels[$id])) continue;
    $slug = forever_npc_slug($m['name']);
    if ($slug === '') continue;
    $e = &$by[$slug];
    $e ??= ['name' => $m['name'], 'ids' => [], 'logs' => 0, 'deaths' => 0, 'levels' => [], 'abilities' => [], 'places' => []];
    $e['ids'][] = $id;
    $e['logs'] = max($e['logs'], $m['logs'] ?? 0);
    $e['deaths'] += $m['deaths'] ?? 0;
    foreach ($levels[$id] as $lv => $x) {
      $y = $e['levels'][$lv] ?? null;
      $e['levels'][$lv] = $y ? [$y[0] + $x[0], min($y[1], $x[1]), max($y[2], $x[2]), max($y[3] ?? 0, $x[3] ?? 0) ?: null] : $x;
    }
    foreach ($abilities[$id] ?? [] as $a) {
      // Only misses (an immune target, a debuff that didn't land) say nothing about the ability.
      if (!$a[3] && !$a[4]) continue;
      $k = $a[0] . '|' . $a[1];
      $y = $e['abilities'][$k] ?? null;
      $e['abilities'][$k] = $y ? [$a[0], $a[1], $a[2], $y[3] + $a[3], $y[4] + $a[4], $y[5] + $a[5], $y[6] + $a[6],
        min($y[7] ?? PHP_INT_MAX, $a[7] ?? PHP_INT_MAX) === PHP_INT_MAX ? null : min($y[7] ?? PHP_INT_MAX, $a[7] ?? PHP_INT_MAX),
        max($y[8] ?? 0, $a[8] ?? 0) ?: null, $y[9] + $a[9]] : $a;
    }
    foreach ($places[$id] ?? [] as $p => $n) $e['places'][$p] = ($e['places'][$p] ?? 0) + $n;
    unset($e);
  }
  foreach ($by as &$e) {
    ksort($e['levels']);
    arsort($e['places']);
    // Melee first, then by damage dealt, then by casts.
    usort($e['abilities'], fn($a, $b) => [$a[0] !== 0, -$a[9], -$a[3]] <=> [$b[0] !== 0, -$b[9], -$b[3]]);
  }
  unset($e);
  uasort($by, fn($a, $b) => [array_key_first($a['levels']), $a['name']] <=> [array_key_first($b['levels']), $b['name']]);
  return $by;
}

function bestiary_range(array $values): string {
  $values = array_values(array_filter($values, fn($v) => $v !== null));
  if (!$values) return '';
  $lo = min($values);
  $hi = max($values);
  return $lo === $hi ? js_locale($lo) : js_locale($lo) . '–' . js_locale($hi);
}

function bestiary_levels(array $m): string { return bestiary_range(array_keys($m['levels'])); }

function bestiary_health(array $m): string {
  return bestiary_range(array_merge(array_column($m['levels'], 1), array_column($m['levels'], 2)));
}

function bestiary_melee(array $m): ?array {
  foreach ($m['abilities'] as $a) if ($a[0] === 0 && $a[4] > 0) return $a;
  return null;
}

/** Abilities other than melee: anything it cast, or that hit a player. */
function bestiary_spells(array $m): array {
  return array_values(array_filter($m['abilities'], fn($a) => $a[0] !== 0));
}

const BESTIARY_SCHOOLS = [1 => 'Physical', 2 => 'Holy', 4 => 'Fire', 8 => 'Nature', 16 => 'Frost', 32 => 'Shadow', 64 => 'Arcane'];

function bestiary_school(?int $school): string {
  if (!$school) return '';
  if (isset(BESTIARY_SCHOOLS[$school])) return BESTIARY_SCHOOLS[$school];
  $parts = [];
  foreach (BESTIARY_SCHOOLS as $bit => $name) if ($school & $bit) $parts[] = $name;
  return implode('/', $parts);
}

function bestiary_logs_summary(): array {
  static $s = null;
  if ($s !== null) return $s;
  if (!bestiary_ready()) return $s = ['logs' => 0, 'first' => null, 'last' => null];
  $r = Db::first("SELECT count(*) AS logs, min(first_event) AS first, max(last_event) AS last FROM combatlog_files WHERE source_game = ?", [DUNGEON_SOURCE]) ?? [];
  return $s = ['logs' => (int)($r['logs'] ?? 0), 'first' => $r['first'] ?? null, 'last' => $r['last'] ?? null];
}

function bestiary_note(): string {
  $s = bestiary_logs_summary();
  return 'Measured from ' . js_locale($s['logs']) . ' combat log' . ($s['logs'] === 1 ? '' : 's') . ' recorded with Advanced Combat Logging. ' .
    'Damage is what players actually took, after their armor and resistances; health is the mob\'s full health at that level. ' .
    'Only mobs are recorded: players who appear in a log are never stored.';
}

/** The measured numbers for one mob: health by level, its attacks, where it was seen and loot players logged. */
function bestiary_sections(array $m, bool $withLoot = true): string {
  $lvRows = '';
  foreach ($m['levels'] as $lv => [$n, $lo, $hi, $armor])
    $lvRows .= '<tr><td>' . $lv . '</td><td class="r">' . bestiary_range([$lo, $hi]) . '</td><td class="r">' . ($armor !== null ? js_locale($armor) : '') . '</td><td class="r">' . js_locale($n) . '</td></tr>';
  $out = '<section class="dg-boss"><h3>Health by level</h3><table class="dg-list bx-t"><thead><tr><th>Level</th><th class="r">Health</th><th class="r">Armor</th><th class="r">Times measured</th></tr></thead><tbody>' .
    $lvRows . '</tbody></table></section>';

  $atk = '';
  foreach ($m['abilities'] as [$id, $name, $school, $casts, $hits, $crits, $misses, $lo, $hi, $total]) {
    $swings = $hits + $misses;
    $atk .= '<tr><td>' . esc($name) . ($id && bestiary_school($school) ? ' <small class="bx-school">' . esc(bestiary_school($school)) . '</small>' : '') . '</td>' .
      '<td class="r">' . ($hits ? bestiary_range([$lo, $hi]) : '<span class="dg-unk">No hits</span>') . '</td>' .
      '<td class="r">' . ($hits ? js_locale((int)round($total / $hits)) : '') . '</td>' .
      '<td class="r hide-sm">' . js_locale($hits) . '</td>' .
      '<td class="r hide-sm">' . ($swings ? round(100 * $misses / $swings) . '%' : '') . '</td>' .
      '<td class="r hide-sm">' . ($hits ? round(100 * $crits / $hits) . '%' : '') . '</td>' .
      '<td class="r hide-sm">' . ($id ? js_locale($casts) : '') . '</td></tr>';
  }
  $out .= '<section class="dg-boss"><h3>Attacks</h3>' . ($atk !== '' ?
    '<table class="dg-list bx-t"><thead><tr><th>Ability</th><th class="r">Hit for</th><th class="r">Average</th><th class="r hide-sm">Hits</th><th class="r hide-sm">Missed</th><th class="r hide-sm">Crit</th><th class="r hide-sm">Casts</th></tr></thead><tbody>' .
    $atk . '</tbody></table><p class="hint">"Missed" covers misses, dodges, parries, blocks and resists against players.</p>' :
    '<p class="hint">No attacks on players logged yet.</p>') . '</section>';

  if ($withLoot) {
    $windows = 0;
    $items = [];
    foreach (forever_dungeon_observed($m['ids']) as $o) {
      $windows += $o['windows'];
      foreach ($o['items'] as $item => $n) $items[$item] = ($items[$item] ?? 0) + $n;
    }
    if ($items) {
      arsort($items);
      $rows = '';
      foreach ($items as $item => $n) {
        $name = forever_dungeon_item_name($item);
        // The name caches miss most open-world drops; the item's own page knows them from AH scans and the Armory.
        if ($name === 'Item ' . $item) $name = (forever_item_model($item)['name'] ?? null) ?: $name;
        $rows .= '<tr><td>' . forever_dungeon_item_link(['id' => $item, 'name' => $name, 'quality' => 1]) . '</td><td class="o">' . $n . ' of ' . $windows . ' kills</td></tr>';
      }
      $out .= '<section class="dg-boss"><h3>Loot players saw</h3><table class="dg-loot"><tbody>' . $rows . '</tbody></table><p class="hint">From players\' Journalator loot logs.</p></section>';
    }
  }
  return $out;
}

function bestiary_facts(array $m): string {
  $facts = [
    ['Level', bestiary_levels($m)],
    ['Health', bestiary_health($m)],
    ['Seen in', $m['places'] ? esc(implode(', ', array_keys($m['places']))) : '<span class="dg-unk">Unknown</span>'],
    ['NPC ID', esc(implode(', ', $m['ids']))],
    ['Deaths logged', js_locale($m['deaths'])],
    ['Combat logs', js_locale($m['logs'])],
  ];
  return '<table class="dg-facts"><tbody>' . implode('', array_map(fn($f) => '<tr><th>' . $f[0] . '</th><td>' . $f[1] . '</td></tr>', $facts)) . '</tbody></table>';
}

/** A dungeon boss's journal page also shows what the logs measured, when its name was logged. */
function bestiary_boss_section(string $slug): string {
  $m = forever_bestiary()[$slug] ?? null;
  if ($m === null) return '';
  return '<section class="dg-boss"><h3>Measured in combat logs</h3><table class="dg-facts"><tbody>' .
    '<tr><th>Level</th><td>' . bestiary_levels($m) . '</td></tr><tr><th>Health</th><td>' . bestiary_health($m) . '</td></tr></tbody></table></section>' .
    bestiary_sections($m, false) . '<p class="hint">' . bestiary_note() . '</p>';
}

const BESTIARY_STYLE = <<<'CSS'
.bx-t td,.bx-t th{font-size:12px;padding:4px 6px}
.bx-school{opacity:.65;font-size:10px;margin-left:4px}
.bx-tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 10px;font-size:12px}
.bx-tools input{font:inherit;padding:4px 8px;border:1px solid rgba(255,255,255,.25);border-radius:3px;min-width:200px;max-width:100%;background:transparent;color:inherit}
.bx-stats{display:flex;flex-wrap:wrap;gap:18px;margin:0 0 12px;font-size:12px}
.bx-stats b{display:block;font-size:20px}
.bx-list td.nm a{font-weight:bold}
.bx-list td small{opacity:.65}
.w8-framed .bx-tools input{background:#fff8e6;color:#2a1e0c;border-color:rgba(60,40,10,.4)}
.w8-framed .bx-stats b{color:#5a2a00}
CSS;

function forever_bestiary_index_body(): string {
  $mobs = forever_bestiary();
  $s = bestiary_logs_summary();
  if (!$mobs)
    return '<div class="panel census"><div class="ptitle">Bestiary of WoW: Forever</div><p class="hint">No combat logs have been imported yet.</p></div>';
  $rows = '';
  foreach ($mobs as $slug => $m) {
    $melee = bestiary_melee($m);
    $spells = array_map(fn($a) => $a[1], bestiary_spells($m));
    $where = array_key_first($m['places']) ?? '';
    $rows .= '<tr data-q="' . esc(strtolower($m['name'] . ' ' . implode(' ', array_keys($m['places'])) . ' ' . implode(' ', $spells))) . '">' .
      '<td class="nm"><a href="' . esc(forever_npc_path($slug)) . '">' . esc($m['name']) . '</a>' . (isset(forever_npcs()[$slug]) ? ' <span class="dg-rare">BOSS</span>' : '') . '</td>' .
      '<td>' . bestiary_levels($m) . '</td><td class="r">' . bestiary_health($m) . '</td>' .
      '<td class="r">' . ($melee ? bestiary_range([$melee[7], $melee[8]]) : '') . '</td>' .
      '<td class="hide-sm">' . esc(implode(', ', $spells)) . '</td>' .
      '<td class="hide-sm">' . esc($where) . '</td></tr>';
  }
  $zones = count(array_unique(array_merge(...array_map(fn($m) => array_keys($m['places']), array_values($mobs)))));
  return '<div class="panel census"><div class="ptitle">Bestiary of WoW: Forever</div>' .
    '<p class="hint" style="margin-top:0">Every mob\'s level, health and how hard it hits, measured from players\' combat logs rather than copied from an old database. ' .
    'Forever changed a lot of numbers; these are the ones the game uses now.</p>' .
    '<div class="bx-stats"><span><b>' . js_locale(count($mobs)) . '</b>mobs measured</span><span><b>' . js_locale($zones) . '</b>zones</span>' .
    '<span><b>' . js_locale($s['logs']) . '</b>combat log' . ($s['logs'] === 1 ? '' : 's') . '</span></div>' .
    '<div class="bx-tools"><label for="bx-q"><b>Find</b></label><input id="bx-q" type="search" placeholder="Mob, zone or spell" oninput="var q=this.value.toLowerCase();document.querySelectorAll(\'.bx-list tbody tr\').forEach(function(r){r.style.display=r.dataset.q.indexOf(q)<0?\'none\':\'\'})"></div>' .
    '<table class="dg-list bx-list bx-t"><thead><tr><th>Mob</th><th>Level</th><th class="r">Health</th><th class="r">Melee hit</th><th class="hide-sm">Spells</th><th class="hide-sm">Seen in</th></tr></thead><tbody>' .
    $rows . '</tbody></table><p class="dg-credit">' . bestiary_note() . '</p></div>';
}

function forever_bestiary_mob_body(string $slug, array $m): string {
  $sub = array_filter(['Level ' . bestiary_levels($m), array_key_first($m['places'])]);
  return '<div class="panel census dg-npc">' .
    '<div class="dg-head"><div><div class="ptitle" style="margin:0">' . esc($m['name']) . '</div><p class="dg-meta">' . esc(implode(' · ', $sub)) . '</p></div></div>' .
    '<div class="dg-npc-grid"><div>' . bestiary_sections($m) . '</div><aside>' .
    '<section class="dg-boss"><h3>Details</h3>' . bestiary_facts($m) . '</section></aside></div>' .
    '<nav class="dg-npc-nav"><span></span><a href="' . esc(forever_npc_path()) . '">All mobs in the bestiary</a><span></span></nav>' .
    '<p class="dg-credit">' . bestiary_note() . '</p></div>';
}

/** /forever/npc/ (slug null) or a mob's page; null when there is no such mob. */
function forever_bestiary_html(?string $slug, string $stylesheet): ?string {
  $m = $slug !== null ? (forever_bestiary()[$slug] ?? null) : null;
  if ($slug !== null && $m === null) return null;
  $label = seo_label(EDITIONS[DUNGEON_SOURCE]['label']);
  $navigation = edition_nav(DUNGEON_SOURCE, 'bestiary');
  if ($m === null) {
    $title = 'Bestiary – ' . $label;
    $desc = 'Level, health and damage of ' . count(forever_bestiary()) . ' mobs in ' . $label . ', measured from players\' combat logs.';
    $body = forever_bestiary_index_body();
    $topic = 'Bestiary';
  } else {
    $melee = bestiary_melee($m);
    $title = $m['name'] . ' – Bestiary – ' . $label;
    $desc = $m['name'] . ' in ' . $label . ': level ' . bestiary_levels($m) . ', ' . bestiary_health($m) . ' health' .
      ($melee ? ', hits for ' . bestiary_range([$melee[7], $melee[8]]) : '') . ($m['places'] ? ', seen in ' . implode(', ', array_keys($m['places'])) : '') . '. Measured from combat logs.';
    $body = forever_bestiary_mob_body($slug, $m);
    $topic = $m['name'];
  }
  $seo = seo_head($title . ' – WoWCensus', $desc, SITE_ORIGIN . forever_npc_path($slug));
  $heading = page_heading('WoWCensus', $navigation, $topic);
  $css = esc($stylesheet);
  $census_css = CENSUS_STYLESHEET;
  $style = DUNGEON_STYLE . BESTIARY_STYLE;
  return <<<HTML
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
$seo
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96">
<link rel="stylesheet" href="$css">
$census_css<style>
$style
</style>
</head><body>
<div class="crt" aria-hidden="true"></div>
<div class="wrap">
  <header class="ihead">
    <div class="page-heading">$heading{$navigation['games']}</div>
    {$navigation['breadcrumbs']}
  </header>
  <div class="page-controls"></div>
  $body
</div>
</body></html>
HTML;
}
