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

function bestiary_hardest_hit(array $m): ?array {
  $hardest = null;
  foreach ($m['abilities'] as $ability) {
    if ($ability[8] !== null && ($hardest === null || $ability[8] > $hardest[8])) $hardest = $ability;
  }
  return $hardest;
}

function bestiary_ability_label(array $ability): string {
  return $ability[0] > 0 ? bestiary_spell_link($ability[0], $ability[1]) : esc($ability[1]);
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

function forever_spell_path(int $id): string {
  return '/forever/spell/' . $id;
}

function bestiary_spell_icon(int $id): string {
  // Icon names from the Forever spell catalog, keyed by the combat log's spell ID.
  static $icons = [
    75 => 'ability_whirlwind',
    122 => 'spell_frost_frostnova',
    139 => 'spell_holy_renew',
    143 => 'spell_fire_flamebolt',
    145 => 'spell_fire_flamebolt',
    172 => 'spell_shadow_abominationexplosion',
    205 => 'spell_frost_frostbolt02',
    284 => 'ability_rogue_ambush',
    339 => 'spell_nature_stranglevines',
    589 => 'spell_shadow_shadowwordpain',
    591 => 'spell_holy_holysmite',
    594 => 'spell_shadow_shadowwordpain',
    639 => 'spell_holy_holybolt',
    647 => 'spell_holy_holybolt',
    678 => 'classicon_paladin',
    695 => 'spell_shadow_shadowbolt',
    707 => 'spell_fire_immolation',
    746 => 'spell_holy_heal',
    980 => 'spell_shadow_curseofsargeras',
    1715 => 'ability_shockwave',
    1757 => 'spell_shadow_ritualofsacrifice',
    2052 => 'spell_holy_lesserheal',
    2053 => 'spell_holy_lesserheal',
    2136 => 'spell_fire_fireball',
    2764 => 'ability_throw',
    5019 => 'ability_shootwand',
    5178 => 'spell_nature_abolishmagic',
    6343 => 'spell_nature_thunderclap',
    6546 => 'ability_gouge',
    6760 => 'ability_rogue_eviscerate',
    7268 => 'spell_nature_starfall',
    8092 => 'spell_shadow_unholyfrenzy',
    8924 => 'spell_nature_starfall',
    13549 => 'ability_hunter_quickshot',
    14281 => 'ability_impalingbolt',
    20280 => 'ability_thunderbolt',
    20467 => 'ability_warrior_innerrage',
    25740 => 'ability_thunderbolt',
    1261483 => 'ability_hunter_huntervswild',
    1311647 => 'spell_holy_retributionaura',
    1311650 => 'spell_holy_retributionaura',
    53 => 'ability_backstab',
    3391 => 'ability_ghoulfrenzy',
    6136 => 'spell_frost_frostarmor02',
    6268 => 'ability_warstomp',
    6660 => 'ability_marksmanship',
    7159 => 'ability_backstab',
    8014 => 'spell_holy_harmundeadaura',
    10277 => 'ability_throw',
    11918 => 'spell_nature_corrosivebreath',
    12024 => 'ability_ensnare',
    12544 => 'spell_frost_frostarmor02',
    13322 => 'spell_frost_frostbolt02',
    13563 => 'inv_egg_02',
    460939 => 'spell_arcane_teleportstormwind',
  ];
  return '<img class="bx-spell-icon" src="' . esc(forever_dungeon_icon($icons[$id] ?? null, 'large')) .
    '" width="20" height="20" alt="" loading="lazy">';
}

function bestiary_spell_link(int $id, string $name): string {
  return '<a class="bx-spell" href="' . esc(forever_spell_path($id)) . '">' . bestiary_spell_icon($id) . esc($name) . '</a>';
}

function bestiary_attack_cells(int $casts, int $hits, int $crits, int $misses, ?int $lo, ?int $hi, int $total, bool $isSpell): string {
  $attempts = $hits + $misses;
  return '<td class="r">' . ($hits ? bestiary_range([$lo, $hi]) : '<span class="dg-unk">No hits</span>') . '</td>' .
    '<td class="r">' . ($hits ? number_format($total / $hits, 1) : '') . '</td>' .
    '<td class="r hide-sm">' . js_locale($hits) . '</td>' .
    '<td class="r hide-sm">' . ($attempts ? round(100 * $misses / $attempts) . '%' : '') . '</td>' .
    '<td class="r hide-sm">' . ($hits ? round(100 * $crits / $hits) . '%' : '') . '</td>' .
    '<td class="r hide-sm">' . ($isSpell ? js_locale($casts) : '') . '</td>';
}

function bestiary_attack_table(string $label, string $rows): string {
  return '<table class="dg-list bx-t"><thead><tr><th scope="col">' . esc($label) . '</th><th class="r">Hit for</th><th class="r">Avg. damage per hit</th>' .
    '<th class="r hide-sm">Hits</th><th class="r hide-sm">Missed</th><th class="r hide-sm">Crit</th><th class="r hide-sm">Casts</th></tr></thead><tbody>' .
    $rows . '</tbody></table><p class="hint">"Missed" covers misses, dodges, parries, blocks and resists against players.</p>';
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
    'Damage includes absorbed damage. Health is the mob\'s full health at that level.';
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
    $atk .= '<tr><td>' . ($id > 0 ? bestiary_spell_link($id, $name) : esc($name)) .
      ($id && bestiary_school($school) ? ' <small class="bx-school">' . esc(bestiary_school($school)) . '</small>' : '') . '</td>' .
      bestiary_attack_cells($casts, $hits, $crits, $misses, $lo, $hi, $total, $id !== 0) . '</tr>';
  }
  $out .= '<section class="dg-boss"><h3>Attacks</h3>' . ($atk !== '' ?
    bestiary_attack_table('Ability', $atk) :
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
  $melee = bestiary_melee($m);
  $hardest = bestiary_hardest_hit($m);
  $combat = $melee ? '<tr><th>Average melee hit</th><td>' . number_format($melee[9] / $melee[4], 1) . ' (' . js_locale($melee[4]) . ' hits)</td></tr>' : '';
  if ($hardest) $combat .= '<tr><th>Hardest observed hit</th><td>' . js_locale($hardest[8]) . ' · ' . bestiary_ability_label($hardest) .
    ' (' . js_locale($hardest[4]) . ' hits)</td></tr>';
  return '<section class="dg-boss"><h3>Measured in combat logs</h3><table class="dg-facts"><tbody>' .
    '<tr><th>Level</th><td>' . bestiary_levels($m) . '</td></tr><tr><th>Health</th><td>' . bestiary_health($m) . '</td></tr>' . $combat . '</tbody></table></section>' .
    bestiary_sections($m, false) . '<p class="hint">' . bestiary_note() . '</p>';
}

const BESTIARY_STYLE = <<<'CSS'
.bx-t td,.bx-t th{font-size:12px;padding:4px 6px}
.bx-school{opacity:.65;font-size:10px;margin-left:4px}
.bx-spell{display:inline-flex;align-items:center;gap:5px;vertical-align:middle}
.bx-spell-icon{width:20px;height:20px;border-radius:3px;flex:none}
.bx-spell-title{display:flex;align-items:center;gap:8px}
.bx-spell-title .bx-spell-icon{width:32px;height:32px}
.bx-tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 10px;font-size:12px}
.bx-tools input{font:inherit;padding:4px 8px;border:1px solid rgba(255,255,255,.25);border-radius:3px;min-width:200px;max-width:100%;background:transparent;color:inherit}
.bx-stats{display:flex;flex-wrap:wrap;gap:18px;margin:0 0 12px;font-size:12px}
.bx-stats b{display:block;font-size:20px}
.bx-list td.nm a{font-weight:bold}
.bx-list td small{opacity:.65}
.bx-sort{font:inherit;color:inherit;background:none;border:0;padding:0;text-decoration:underline;cursor:pointer}
.w8-framed .bx-tools input{background:#fff8e6;color:#2a1e0c;border-color:rgba(60,40,10,.4)}
.w8-framed .bx-stats b{color:#5a2a00}
.bx-auto-shot{display:none}
CSS;

const FOREVER_AUTO_SHOT_STYLE = <<<'CSS'
.w8-framed .bx-auto-shot{display:block}
.bx-auto-summary{margin:12px 0;padding:clamp(12px,3vw,25px);border:1px solid #b28a51;background:radial-gradient(ellipse at 48% 40%,#fff7d6a8,transparent 75%),#f0d7a5}
.w8-framed .bx-auto-summary h2{margin:0;font-size:clamp(17px,3vw,25px)}
.bx-auto-scale{padding-top:58px;margin:0 5px}
.bx-auto-rail{position:relative;height:6px;background:linear-gradient(#987039,#f3d69a 38%,#a67736);border:1px solid #6b431e}
.bx-auto-rail::before,.bx-auto-rail::after{content:"";position:absolute;top:50%;width:4px;height:15px;transform:translateY(-50%);border:1px solid #6b431e;background:linear-gradient(90deg,#92612d,#f6da99,#a17636)}
.bx-auto-rail::before{left:-2px}.bx-auto-rail::after{right:-2px}
.bx-auto-marker{position:absolute;top:50%;width:13px;height:13px;border-radius:50%;border:1px solid #6b431e;background:radial-gradient(circle at 35% 30%,#ffe3a2,#9c6828 80%);transform:translate(-50%,-50%)}
.bx-auto-average{position:absolute;bottom:100%;left:clamp(67px,var(--average),100% - 67px);width:134px;transform:translateX(-50%);padding-bottom:8px;text-align:center;font:13px/1.1 Georgia,serif;color:#4b2b14}
.bx-auto-average strong{display:block;font-size:23px}
.bx-auto-ends{display:flex;justify-content:space-between;margin-top:8px;font-weight:bold;color:#542c16}
.bx-auto-sample{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));margin:14px 0 0;border-block:1px solid #a5733b70;text-align:center;color:#4b2b14}
.bx-auto-sample div{padding:8px 3px}.bx-auto-sample div+div{border-left:1px solid #a5733b70}
.bx-auto-sample dd{margin:3px 0 0;font:700 20px Georgia,serif}
CSS;

function forever_bestiary_index_body(): string {
  $mobs = forever_bestiary();
  $s = bestiary_logs_summary();
  if (!$mobs)
    return '<div class="panel census"><div class="ptitle">Bestiary of WoW: Forever</div><p class="hint">No combat logs have been imported yet.</p></div>';
  $rows = '';
  foreach ($mobs as $slug => $m) {
    $melee = bestiary_melee($m);
    $hardest = bestiary_hardest_hit($m);
    $spells = bestiary_spells($m);
    $where = array_key_first($m['places']) ?? '';
    $rows .= '<tr data-hit="' . ($hardest[8] ?? -1) . '" data-zones="' . esc(js_json(array_keys($m['places']))) . '" data-q="' .
      esc(strtolower($m['name'] . ' ' . implode(' ', array_keys($m['places'])) . ' ' . implode(' ', array_column($spells, 1)))) . '">' .
      '<td class="nm"><a href="' . esc(forever_npc_path($slug)) . '">' . esc($m['name']) . '</a>' . (isset(forever_npcs()[$slug]) ? ' <span class="dg-rare">BOSS</span>' : '') . '</td>' .
      '<td>' . bestiary_levels($m) . '</td><td class="r">' . bestiary_health($m) . '</td>' .
      '<td class="r">' . ($melee ? bestiary_range([$melee[7], $melee[8]]) : '') . '</td>' .
      '<td class="r">' . ($hardest ? js_locale($hardest[8]) . ' <small>(' . js_locale($hardest[4]) . ' hits)</small>' : '') . '</td>' .
      '<td class="hide-sm">' . implode(', ', array_map(fn($a) => bestiary_spell_link($a[0], $a[1]), $spells)) . '</td>' .
      '<td class="hide-sm">' . esc($where) . '</td></tr>';
  }
  $zones = count(array_unique(array_merge(...array_map(fn($m) => array_keys($m['places']), array_values($mobs)))));
  return '<div class="panel census"><div class="ptitle">Bestiary of WoW: Forever</div>' .
    '<p class="hint" style="margin-top:0">Every mob\'s level, health and how hard it hits, measured from players\' combat logs rather than copied from an old database. ' .
    'Forever changed a lot of numbers; these are the ones the game uses now.</p>' .
    '<div class="bx-stats"><span><b>' . js_locale(count($mobs)) . '</b>mobs measured</span><span><b>' . js_locale($zones) . '</b>zones</span>' .
    '<span><b>' . js_locale($s['logs']) . '</b>combat log' . ($s['logs'] === 1 ? '' : 's') . '</span></div>' .
    '<div class="bx-tools"><label for="bx-q"><b>Find</b></label><input id="bx-q" type="search" placeholder="Mob, zone or spell"><span id="bx-zone"></span></div>' .
    '<table class="dg-list bx-list bx-t"><thead><tr><th>Mob</th><th>Level</th><th class="r">Health</th><th class="r">Melee hit</th>' .
    '<th class="r" aria-sort="none"><button type="button" class="bx-sort">Hardest observed hit</button></th><th class="hide-sm">Spells</th><th class="hide-sm">Seen in</th></tr></thead><tbody>' .
    $rows . '</tbody></table><p class="dg-credit">' . bestiary_note() . '</p></div>' .
    '<script>(()=>{const button=document.querySelector(".bx-sort"),header=button.parentElement,body=button.closest("table").tBodies[0];' .
    'button.addEventListener("click",()=>{const asc=header.getAttribute("aria-sort")==="descending";header.setAttribute("aria-sort",asc?"ascending":"descending");' .
    'Array.from(body.rows).sort((a,b)=>a.dataset.hit<0?Number(b.dataset.hit>=0):b.dataset.hit<0?-1:(asc?1:-1)*(a.dataset.hit-b.dataset.hit)).forEach(row=>body.append(row));});' .
    'const zone=new URLSearchParams(location.search).get("zone"),input=document.getElementById("bx-q");' .
    'const search=()=>{const term=input.value.toLowerCase();for(const row of body.rows)row.hidden=!row.dataset.q.includes(term)||(zone!==null&&!JSON.parse(row.dataset.zones).includes(zone));};' .
    'input.addEventListener("input",search);if(zone){const label=document.getElementById("bx-zone"),clear=document.createElement("a");clear.href=location.pathname;clear.textContent="All zones";label.append(zone," · ",clear);search();}' .
    '})();</script>';
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

function bestiary_zone_links(): string {
  if (!bestiary_ready()) return '';
  $zones = Db::all("SELECT map_name, count(DISTINCT npc_id) AS mobs FROM combatlog_npc_places
    WHERE source_game = ? AND map_name IS NOT NULL GROUP BY map_name ORDER BY mobs DESC, map_name", [DUNGEON_SOURCE]);
  return implode(' · ', array_map(fn($z) => '<a href="' . esc(forever_npc_path() . '?zone=' . rawurlencode($z['map_name'])) . '">' .
    esc($z['map_name']) . ' (' . js_locale($z['mobs']) . ')</a>', $zones));
}

function forever_class_combat(string $classFile, string $path): string {
  if (!Db::first("SELECT to_regclass('combatlog_class_spells') IS NOT NULL AS ok")['ok']) return '';
  $mode = $_GET['combat'] ?? 'PvE';
  if (!in_array($mode, ['PvE', 'PvP', 'Healing'], true)) $mode = 'PvE';
  $level = filter_var($_GET['combat_level'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) ?: 0;
  $available = Db::all("SELECT DISTINCT level FROM combatlog_class_spells
    WHERE source_game = ? AND class_file = ? ORDER BY level", [DUNGEON_SOURCE, $classFile]);
  if (!$available) return '';
  $rows = Db::all("SELECT spell_id, name, periodic, sum(events) AS events, sum(amount) AS amount,
      sum(crits) AS crits, min(low) AS low, max(high) AS high, sum(overheal) AS overheal
    FROM combatlog_class_spells WHERE source_game = ? AND class_file = ? AND mode = ? AND (? = 0 OR level = ?)
    GROUP BY spell_id, name, periodic ORDER BY amount DESC, name, spell_id, periodic",
    [DUNGEON_SOURCE, $classFile, $mode, $level, $level]);
  $filters = '<form action="' . esc($path) . '#combat" method="get" class="cx-tools">' .
    '<label>Combat type <select name="combat" onchange="this.form.submit()">';
  foreach (['PvE', 'PvP', 'Healing'] as $option)
    $filters .= '<option' . ($option === $mode ? ' selected' : '') . '>' . $option . '</option>';
  $filters .= '</select></label>';
  $levels = array_filter(array_column($available, 'level'));
  if ($levels) {
    $filters .= '<label>Level <select name="combat_level" onchange="this.form.submit()"><option value="0">All levels</option>';
    foreach ($levels as $option)
      $filters .= '<option' . ($option === $level ? ' selected' : '') . '>' . $option . '</option>';
    $filters .= '</select></label>';
  }
  $filters .= '<noscript><button type="submit">Apply</button></noscript></form>';
  $healing = $mode === 'Healing';
  $coverage = Db::first("SELECT count(DISTINCT file_key) AS logs FROM combatlog_class_spells
    WHERE source_game = ? AND class_file = ? AND mode = ? AND (? = 0 OR level = ?)",
    [DUNGEON_SOURCE, $classFile, $mode, $level, $level]);
  $logs = (int)$coverage['logs'];
  $total = array_sum(array_column($rows, 'amount'));
  $what = $healing ? 'healing' : 'damage';
  $stats = '';
  foreach ($rows as $r) {
    $share = $total ? 100 * $r['amount'] / $total : 0;
    $name = $r['spell_id'] ? bestiary_spell_link($r['spell_id'], $r['name'])
      : '<span class="bx-spell"><img class="bx-spell-icon" src="' . esc(forever_dungeon_icon('ability_meleedamage', 'large')) .
        '" width="20" height="20" alt="" loading="lazy">' . esc($r['name']) . '</span>';
    $stats .= '<tr><td class="cx-name">' . $name . ($r['periodic'] ? ' <span class="cx-tag">over time</span>' : '') . '</td>' .
      '<td class="r">' . number_format($r['amount'] / $r['events'], 1) . '</td>' .
      '<td class="r cx-dim">' . bestiary_range([$r['low'], $r['high']]) . '</td>' .
      '<td class="r">' . js_locale($r['events']) . '</td>' .
      '<td class="r">' . round(100 * $r['crits'] / $r['events'], 1) . '%</td>' .
      '<td class="r">' . js_locale((int)$r['amount']) . '</td>' .
      ($healing ? '<td class="r cx-dim">' . js_locale((int)$r['overheal']) . '</td>' : '') .
      '<td class="cx-share"><span class="cx-bar"><i style="width:' . number_format(max($share, .5), 1, '.', '') . '%"></i></span>' .
      round($share, 1) . '%</td></tr>';
  }
  $summary = $total > 0
    ? '<p class="cx-sum"><b>' . js_locale((int)$total) . '</b> ' . $what . ' recorded in <b>' . js_locale($logs) . '</b> combat log' . ($logs === 1 ? '' : 's') .
      '. <b>' . esc($rows[0]['name']) . '</b> accounts for ' . round(100 * $rows[0]['amount'] / $total, 1) . '% of it.</p>'
    : '<p class="cx-sum">No ' . $what . ' recorded for these filters.</p>';
  $table = $stats === '' ? '' :
    '<div class="cx-wrap"><table class="dg-list rtable cx-t"><thead><tr><th>Ability</th>' .
    '<th class="r" title="Average per landed hit, heal or tick">Average</th><th class="r">Range</th>' .
    '<th class="r">' . ($healing ? 'Heals' : 'Hits') . '</th><th class="r">Crit</th>' .
    '<th class="r">' . ($healing ? 'Effective healing' : 'Damage') . '</th>' . ($healing ? '<th class="r">Overheal</th>' : '') .
    '<th>Share</th></tr></thead><tbody>' . $stats . '</tbody></table></div>';
  return '<style>' . FOREVER_CLASS_COMBAT_STYLE . '</style><div class="cx"><div class="cx-head">' . $summary . $filters . '</div>' . $table .
    '<p class="cx-note">Counts landed events only; periodic ticks are listed separately. Class is inferred from class-specific casts.</p></div>';
}

const FOREVER_CLASS_COMBAT_STYLE = <<<'CSS'
.cx-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px 16px;margin:0 0 10px}
.cx-sum{margin:0;font-size:12px}
.cx-tools{display:flex;gap:12px;align-items:center;margin:0;font-size:11px}
.cx-tools select{font:inherit;margin-left:4px}
.cx-wrap{overflow-x:auto}
.cx-t{width:100%;border-collapse:collapse}
.cx-t th,.cx-t td{padding:5px 8px;font-size:12px;white-space:nowrap;vertical-align:middle;text-align:left}
.cx-t th{cursor:default}
.cx-t tbody tr{cursor:default}
.cx-t .r{text-align:right;font-variant-numeric:tabular-nums}
.cx-t .cx-dim{opacity:.7}
.cx-t .bx-spell{display:inline-flex;align-items:center;gap:6px;vertical-align:middle}
.cx-t .bx-spell-icon{width:20px;height:20px;border-radius:3px;flex:none}
.cx-t a.bx-spell{text-decoration:none}
.cx-t a.bx-spell:hover{text-decoration:underline}
.cx-tag{font-size:10px;opacity:.65;margin-left:4px}
.cx-share{width:130px;font-variant-numeric:tabular-nums}
.cx-bar{display:inline-block;width:70px;height:8px;margin-right:6px;vertical-align:middle;background:rgba(128,128,128,.25);border-radius:2px;overflow:hidden}
.cx-bar i{display:block;height:100%;background:#b07a00}
.cx-note{margin:8px 2px 0;font-size:10px;opacity:.75}
CSS;

/** /forever/npc/ (slug null) or a mob's page; null when there is no such mob. */
function forever_bestiary_html(?string $slug, string $stylesheet): ?string {
  $m = $slug !== null ? (forever_bestiary()[$slug] ?? null) : null;
  if ($slug !== null && $m === null) return null;
  $label = seo_label(EDITIONS[DUNGEON_SOURCE]['label']);
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
  return bestiary_page($title, $desc, forever_npc_path($slug), $topic, $body, $stylesheet);
}

/** Totals and per-NPC observations for one recorded spell. */
function forever_spell_html(int $id, string $stylesheet): ?string {
  if ($id < 1 || $id > 2147483647) return null;
  $players = Db::first("SELECT to_regclass('combatlog_class_spells') IS NOT NULL AS ok")['ok']
    ? Db::all("SELECT class_file, mode, max(name) AS name, periodic, sum(events) AS events, sum(amount) AS amount,
        sum(crits) AS crits, min(low) AS low, max(high) AS high, sum(overheal) AS overheal, count(DISTINCT file_key) AS logs
      FROM combatlog_class_spells WHERE source_game = ? AND spell_id = ?
      GROUP BY class_file, mode, periodic ORDER BY mode, class_file, periodic", [DUNGEON_SOURCE, $id]) : [];
  $rows = bestiary_ready() ? Db::all("WITH observations AS (
      SELECT npc_id, max(ability) AS ability, bit_or(school) AS school, count(DISTINCT file_key) AS logs,
        sum(casts) AS casts, sum(hits) AS hits, sum(crits) AS crits, sum(misses) AS misses,
        min(min_dmg) AS lo, max(max_dmg) AS hi, sum(total_dmg)::bigint AS total
      FROM combatlog_npc_abilities WHERE source_game = ? AND spell_id = ?
      GROUP BY GROUPING SETS ((), (npc_id)) HAVING count(*) > 0
    )
    SELECT o.*, n.name, p.places, EXISTS (
      SELECT 1 FROM combatlog_npc_levels l WHERE l.source_game = ? AND l.npc_id = o.npc_id
    ) AS measured
    FROM observations o
    LEFT JOIN LATERAL (
      SELECT name FROM combatlog_npc_names
      WHERE source_game = ? AND npc_id = o.npc_id ORDER BY last_seen DESC, file_key LIMIT 1
    ) n ON true
    LEFT JOIN LATERAL (
      SELECT string_agg(DISTINCT map_name, ', ' ORDER BY map_name) AS places
      FROM combatlog_npc_places WHERE source_game = ? AND npc_id = o.npc_id
    ) p ON true
    ORDER BY o.npc_id IS NOT NULL, o.total DESC, o.casts DESC, n.name, o.npc_id",
    [DUNGEON_SOURCE, $id, DUNGEON_SOURCE, DUNGEON_SOURCE, DUNGEON_SOURCE]) : [];
  if (!$rows && !$players) return null;
  $summary = array_shift($rows);
  $name = $summary['ability'] ?? $players[0]['name'];
  $school = bestiary_school($summary['school'] ?? null);
  $cells = fn(array $r) => bestiary_attack_cells($r['casts'], $r['hits'], $r['crits'], $r['misses'], $r['lo'], $r['hi'], $r['total'], true);
  $casters = '';
  foreach ($rows as $r) {
    $npc = $r['name'] ?? ('NPC ' . $r['npc_id']);
    $slug = forever_npc_slug($npc);
    $npcLabel = $r['name'] !== null && $slug !== '' && ($r['measured'] || isset(forever_npcs()[$slug]))
      ? '<a href="' . esc(forever_npc_path($slug)) . '">' . esc($npc) . '</a>' : esc($npc);
    $places = $r['places'] !== null ? '<br><small class="dg-meta">NPC seen in ' . esc($r['places']) . '</small>' : '';
    $casters .= '<tr><td>' . $npcLabel . $places . '</td>' . $cells($r) . '</tr>';
  }
  $body = '<div class="panel census dg-npc"><div class="ptitle bx-spell-title">' .
    bestiary_spell_icon($id) . esc($name) . '</div>' .
    '<p class="dg-meta">Spell ID ' . $id . ($school !== '' ? ' · ' . esc($school) : '') . '</p>';
  if ($id === 75) {
    foreach ($players as $sample) {
      if ($sample['class_file'] !== 'HUNTER' || $sample['mode'] !== 'PvE' || $sample['periodic']) continue;
      $body .= forever_auto_shot_feature($sample);
      break;
    }
  }
  if ($summary) $body .= '<div class="bx-stats">' .
    ($summary['hits'] ? '<span><b>' . number_format($summary['total'] / $summary['hits'], 1) . '</b>Avg. damage per hit</span>' .
      '<span><b>' . bestiary_range([$summary['lo'], $summary['hi']]) . '</b>Damage range</span>' : '') .
    '<span><b>' . js_locale($summary['casts']) . '</b>Casts</span>' .
    '<span><b>' . js_locale($summary['hits']) . '</b>Hits</span>' .
    '<span><b>' . js_locale($summary['crits']) . '</b>Critical hits</span>' .
    '<span><b>' . js_locale($summary['misses']) . '</b>Missed or avoided</span>' .
    '<span><b>' . js_locale($summary['logs']) . '</b>Combat logs</span></div>' .
    '<section class="dg-boss"><h3>Observed damage by NPC</h3>' .
    bestiary_attack_table('NPC', '<tr><td><b>All NPCs</b></td>' . $cells($summary) . '</tr>' . $casters) . '</section>' .
    '<p class="dg-credit">Casts and damage come from NPC observations in WoW: Forever combat logs. Damage includes absorbed damage.</p>';
  if ($players) $body .= forever_player_spell_observations($players);
  $body .= '</div>';
  return bestiary_page($name . ' – WoW: Forever', 'Combat-log observations for ' . $name . ' in WoW: Forever.',
    forever_spell_path($id), $name, $body, $stylesheet, 'spells');
}

function forever_player_spell_observations(array $rows): string {
  $body = '';
  foreach ($rows as $r) {
    $class = $r['class_file'];
    $label = CLASS_META[$class][0] ?? $class;
    $classLink = '<a href="' . esc(class_route(DUNGEON_SOURCE, $class) . '#combat') . '">' . esc($label) . '</a>';
    $event = $r['periodic'] ? 'Tick' : ($r['mode'] === 'Healing' ? 'Heal' : 'Hit');
    $body .= '<tr><td>' . $classLink . '</td><td>' . esc($r['mode']) . '</td><td>' . $event . '</td>' .
      '<td class="r">' . number_format($r['amount'] / $r['events'], 1) . '</td>' .
      '<td class="r">' . bestiary_range([$r['low'], $r['high']]) . '</td>' .
      '<td class="r">' . js_locale($r['events']) . '</td>' .
      '<td class="r">' . round(100 * $r['crits'] / $r['events'], 1) . '%</td>' .
      '<td class="r">' . js_locale($r['logs']) . '</td>' .
      '<td class="r">' . ($r['mode'] === 'Healing' ? js_locale((int)$r['overheal']) : '') . '</td></tr>';
  }
  return '<section class="dg-boss"><h3>Player combat observations</h3><div style="overflow-x:auto">' .
    '<table class="dg-list bx-t"><thead><tr><th>Class</th><th>Combat type</th><th>Event</th><th class="r">Avg. per event</th>' .
    '<th class="r">Range</th><th class="r">Events</th><th class="r">Crit</th><th class="r">Combat logs</th><th class="r">Overheal</th>' .
    '</tr></thead><tbody>' . $body . '</tbody></table></div>' .
    '<p class="hint">Player figures cover landed damage and healing. Class is inferred from class-specific casts.</p></section>';
}

function forever_auto_shot_feature(array $sample): string {
  $average = $sample['amount'] / $sample['events'];
  $position = $sample['low'] < $sample['high'] ? 100 * ($average - $sample['low']) / ($sample['high'] - $sample['low']) : 50;
  $range = sprintf('Minimum %d, average %.1f, maximum %d damage per landed hit', $sample['low'], $average, $sample['high']);
  $crit = number_format(100 * $sample['crits'] / $sample['events'], 1) . '%';
  return '<section class="bx-auto-shot bx-auto-summary" aria-labelledby="bx-auto-range"><h2 id="bx-auto-range">Observed damage range</h2>' .
    '<p class="dg-meta">Hunter · PvE</p>' .
    '<div class="bx-auto-scale" role="img" aria-label="' . esc($range) . '"><div class="bx-auto-rail">' .
    '<span class="bx-auto-marker" style="left:' . number_format($position, 2, '.', '') . '%"></span>' .
    '<span class="bx-auto-average" style="--average:' . number_format($position, 2, '.', '') . '%"><strong>' .
    number_format($average, 1) . '</strong>Avg. damage per hit</span></div>' .
    '<div class="bx-auto-ends"><span>' . js_locale($sample['low']) . '</span><span>' . js_locale($sample['high']) . '</span></div></div>' .
    '<dl class="bx-auto-sample"><div><dt>Landed hits</dt><dd>' . js_locale($sample['events']) . '</dd></div>' .
    '<div><dt>Crit rate</dt><dd>' . $crit . '</dd></div>' .
    '<div><dt>Combat logs</dt><dd>' . js_locale($sample['logs']) . '</dd></div></dl></section>';
}

function bestiary_page(string $title, string $desc, string $path, string $topic, string $body, string $stylesheet, string $section = 'bestiary'): string {
  $navigation = edition_nav(DUNGEON_SOURCE, $section);
  $seo = seo_head($title . ' – WoWCensus', $desc, SITE_ORIGIN . $path);
  $heading = page_heading('WoWCensus', $navigation, $topic);
  $css = esc($stylesheet);
  $census_css = CENSUS_STYLESHEET;
  $style = DUNGEON_STYLE . BESTIARY_STYLE;
  if (str_contains($body, 'class="bx-auto-shot ')) $style .= FOREVER_AUTO_SHOT_STYLE;
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
