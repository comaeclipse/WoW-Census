<?php
declare(strict_types=1);

/**
 * WoW: Forever dungeon pages: /forever/dungeons/ and /forever/dungeons/<slug>/.
 *
 * Bosses, levels and loot come from the Dungeon Journal add-on (by Exehn), read as data by
 * tools/build-forever-dungeons.py into the forever_dungeon* tables, together with how many players'
 * uploaded journals record each boss as killed. What players saw a boss drop is added at render
 * time from two logs: Journalator's loot windows (addon_loot_sources, kind npc) and the add-on's own
 * run logs (dungeon_run_kills / dungeon_run_loot, from tools/import-dungeon-runs-pg.py).
 */

const DUNGEON_SOURCE = 'forever';
const DUNGEON_QUALITY = [0 => 'q0', 1 => 'q1', 2 => 'q2', 3 => 'q3', 4 => 'q4', 5 => 'q5'];

/**
 * The journal as one array: ['source' => [addon, author, version], 'uploaders', 'dungeons' => [...]], each
 * dungeon with its bosses (and their loot), trash loot and quest details, in the journal's order.
 * Empty (no dungeons) until tools/build-forever-dungeons.py has created the tables.
 */
function forever_dungeon_data(): array {
  static $data = null;
  if ($data !== null) return $data;
  $journal = (bool)(Db::first("SELECT to_regclass('forever_dungeon_journal') IS NOT NULL AS ok")['ok'] ?? false)
    ? Db::first('SELECT addon, author, version, uploaders FROM forever_dungeon_journal WHERE id = 1') : null;
  if (!$journal) return $data = ['source' => ['addon' => 'Dungeon Journal', 'author' => 'Exehn', 'version' => null], 'uploaders' => 0, 'dungeons' => []];
  $loot = static function (array $r): array {
    $it = ['id' => (int)$r['item_id'], 'name' => $r['item_name'], 'slot' => $r['slot'], 'quality' => $r['quality'] !== null ? (int)$r['quality'] : null];
    if ($r['drop_from'] !== null) $it['from'] = $r['drop_from'];
    return $it;
  };
  $bossLoot = $trash = $rewards = $questBosses = [];
  foreach (Db::all('SELECT dungeon_slug, boss_order, item_id, item_name, slot, quality, drop_from
      FROM forever_dungeon_boss_loot ORDER BY dungeon_slug, boss_order, loot_order') as $r)
    $bossLoot[$r['dungeon_slug']][(int)$r['boss_order']][] = $loot($r);
  foreach (Db::all('SELECT dungeon_slug, item_id, item_name, slot, quality, drop_from
      FROM forever_dungeon_trash_loot ORDER BY dungeon_slug, loot_order') as $r)
    $trash[$r['dungeon_slug']][] = $loot($r);
  foreach (Db::all('SELECT dungeon_slug, quest_order, item_id, item_name, quality
      FROM forever_dungeon_quest_rewards ORDER BY dungeon_slug, quest_order, reward_order') as $r)
    $rewards[$r['dungeon_slug']][(int)$r['quest_order']][] = ['id' => (int)$r['item_id'], 'name' => $r['item_name'], 'quality' => $r['quality'] !== null ? (int)$r['quality'] : null];
  foreach (Db::all('SELECT dungeon_slug, quest_order, boss_name FROM forever_dungeon_quest_bosses
      ORDER BY dungeon_slug, quest_order, boss_order') as $r)
    $questBosses[$r['dungeon_slug']][(int)$r['quest_order']][] = $r['boss_name'];
  $portraits = [];
  if ((bool)(Db::first("SELECT to_regclass('forever_dungeon_boss_portraits') IS NOT NULL AS ok")['ok'] ?? false))
    foreach (Db::all('SELECT dungeon_slug, boss_name, portrait FROM forever_dungeon_boss_portraits') as $r)
      $portraits[$r['dungeon_slug']][$r['boss_name']] = $r['portrait'];
  $bosses = $quests = [];
  foreach (Db::all('SELECT dungeon_slug, boss_order, npc_id, name, level, rare, description, killed_by
      FROM forever_dungeon_bosses ORDER BY dungeon_slug, boss_order') as $r)
    $bosses[$r['dungeon_slug']][] = ['name' => $r['name'], 'npcID' => $r['npc_id'] !== null ? (int)$r['npc_id'] : null,
      'level' => $r['level'], 'rare' => (bool)$r['rare'], 'description' => $r['description'],
      'loot' => $bossLoot[$r['dungeon_slug']][(int)$r['boss_order']] ?? [], 'killedBy' => (int)$r['killed_by'],
      'portrait' => $portraits[$r['dungeon_slug']][$r['name']] ?? null];
  foreach (Db::all('SELECT dungeon_slug, quest_order, quest_id, name, objective, pickup, turnin, faction, requires_level, reward_choice
      FROM forever_dungeon_quests ORDER BY dungeon_slug, quest_order') as $r)
    $quests[$r['dungeon_slug']][] = ['name' => $r['name'], 'id' => $r['quest_id'] !== null ? (int)$r['quest_id'] : null,
      'objective' => $r['objective'], 'pickup' => $r['pickup'], 'turnin' => $r['turnin'], 'faction' => $r['faction'],
      'requires' => $r['requires_level'] !== null ? (int)$r['requires_level'] : null,
      'rewards' => $rewards[$r['dungeon_slug']][(int)$r['quest_order']] ?? [], 'rewardChoice' => (bool)$r['reward_choice'],
      'bosses' => $questBosses[$r['dungeon_slug']][(int)$r['quest_order']] ?? []];
  $guides = [];
  if ((bool)(Db::first("SELECT to_regclass('forever_dungeon_guides') IS NOT NULL AS ok")['ok'] ?? false)) {
    foreach (Db::all('SELECT dungeon_slug, source_url, card_art, detail_art FROM forever_dungeon_guides') as $r)
      $guides[$r['dungeon_slug']] = ['source' => $r['source_url'], 'card' => $r['card_art'], 'detail' => $r['detail_art'], 'overview' => []];
    foreach (Db::all('SELECT dungeon_slug, body FROM forever_dungeon_guide_paragraphs ORDER BY dungeon_slug, para_order') as $r)
      if (isset($guides[$r['dungeon_slug']])) $guides[$r['dungeon_slug']]['overview'][] = $r['body'];
  }
  $dungeons = [];
  foreach (Db::all('SELECT slug, name, sort_order, level_range, location, description, icon, is_new, loot_incomplete
      FROM forever_dungeons ORDER BY sort_order, slug') as $r) {
    $q = $quests[$r['slug']] ?? [];
    $dungeons[] = ['name' => $r['name'], 'slug' => $r['slug'], 'order' => (int)$r['sort_order'], 'level' => $r['level_range'],
      'location' => $r['location'], 'description' => $r['description'], 'icon' => $r['icon'], 'new' => (bool)$r['is_new'],
      'lootIncomplete' => (bool)$r['loot_incomplete'], 'quests' => count($q), 'questDetails' => $q,
      'bosses' => $bosses[$r['slug']] ?? [], 'trash' => $trash[$r['slug']] ?? [], 'guide' => $guides[$r['slug']] ?? null];
  }
  return $data = ['source' => ['addon' => $journal['addon'], 'author' => $journal['author'], 'version' => $journal['version']],
    'uploaders' => (int)$journal['uploaders'], 'dungeons' => $dungeons];
}

/** @return array<string, array> slug => dungeon, in the journal's order (by level). */
function forever_dungeons(): array {
  static $by = null;
  if ($by !== null) return $by;
  $by = [];
  foreach (forever_dungeon_data()['dungeons'] as $d) $by[$d['slug']] = $d;
  return $by;
}

function forever_dungeon_path(?string $slug = null): string {
  return edition_path(DUNGEON_SOURCE) . 'dungeons/' . ($slug !== null ? $slug . '/' : '');
}

/** Bosses (and trash) that drop item $id: [['dungeon', 'slug', 'boss' (null for trash), 'from']]. */
function forever_dungeon_item_sources(int $id): array {
  static $index = null;
  if ($index === null) {
    $index = [];
    foreach (forever_dungeons() as $d) {
      foreach ($d['bosses'] as $b)
        foreach ($b['loot'] as $it) $index[$it['id']][] = ['dungeon' => $d['name'], 'slug' => $d['slug'], 'boss' => $b['name'], 'name' => $it['name'], 'quality' => $it['quality'], 'slot' => $it['slot']];
      foreach ($d['trash'] as $it) $index[$it['id']][] = ['dungeon' => $d['name'], 'slug' => $d['slug'], 'boss' => null, 'from' => $it['from'] ?? null, 'name' => $it['name'], 'quality' => $it['quality'], 'slot' => $it['slot']];
      // Quest rewards get item pages too, so the dungeon page can link them.
      foreach ($d['questDetails'] ?? [] as $q)
        foreach ($q['rewards'] as $it) $index[$it['id']][] = ['dungeon' => $d['name'], 'slug' => $d['slug'], 'boss' => null, 'quest' => $q['name'], 'name' => $it['name'], 'quality' => $it['quality'], 'slot' => null];
    }
  }
  return $index[$id] ?? [];
}

/**
 * What players' Journalator logs saw each NPC drop: npcID => ['windows' => times looted,
 * 'items' => [item id => drops]]. Empty when the loot tables are missing or nothing is logged.
 */
function forever_dungeon_observed(array $npcIds): array {
  $npcIds = array_values(array_filter($npcIds));
  if (!$npcIds || !loot_tables_ready()) return [];
  $in = implode(',', array_fill(0, count($npcIds), '?'));
  $ids = array_map('strval', $npcIds);
  $out = [];
  foreach (Db::all("SELECT source_id, count(*) AS windows FROM addon_loot_sources
      WHERE source_game = ? AND kind = 'npc' AND source_id IN ($in) GROUP BY 1", array_merge([DUNGEON_SOURCE], $ids)) as $r)
    $out[(int)$r['source_id']] = ['windows' => (int)$r['windows'], 'items' => []];
  if (!$out) return [];
  foreach (Db::all("SELECT s.source_id, i.item_id, count(*) AS drops FROM addon_loot_sources s
      JOIN addon_loot_items i ON i.event_key = s.event_key
      WHERE s.source_game = ? AND s.kind = 'npc' AND s.source_id IN ($in) GROUP BY 1, 2", array_merge([DUNGEON_SOURCE], $ids)) as $r)
    $out[(int)$r['source_id']]['items'][(int)$r['item_id']] = (int)$r['drops'];
  return $out;
}

/**
 * What the Dungeon Journal run logs players uploaded saw each boss of $dungeon drop:
 * boss name => ['kills' => kills logged, 'items' => [item id => ['drops', 'name', 'quality']]].
 */
function forever_dungeon_runs(string $dungeon): array {
  static $ready = null, $cache = [];
  $ready ??= (bool)(Db::first("SELECT to_regclass('dungeon_run_loot') IS NOT NULL AS ok")['ok'] ?? false);
  if (!$ready) return [];
  if (isset($cache[$dungeon])) return $cache[$dungeon];
  $out = [];
  foreach (Db::all("SELECT boss, count(*) AS kills FROM dungeon_run_kills WHERE source_game = ? AND dungeon = ? GROUP BY 1",
      [DUNGEON_SOURCE, $dungeon]) as $r)
    $out[(string)$r['boss']] = ['kills' => (int)$r['kills'], 'items' => []];
  if ($out) foreach (Db::all("SELECT k.boss, l.item_id, count(*) AS drops, max(l.item_name) AS name, max(l.quality) AS quality
      FROM dungeon_run_loot l JOIN dungeon_run_kills k ON k.kill_key = l.kill_key
      WHERE k.source_game = ? AND k.dungeon = ? GROUP BY 1, 2", [DUNGEON_SOURCE, $dungeon]) as $r)
    $out[(string)$r['boss']]['items'][(int)$r['item_id']] = ['drops' => (int)$r['drops'], 'name' => $r['name'], 'quality' => $r['quality'] !== null ? (int)$r['quality'] : null];
  return $cache[$dungeon] = $out;
}

function forever_dungeon_median(array $values): ?float {
  $values = array_values(array_filter($values, fn($v) => $v !== null));
  if (!$values) return null;
  sort($values);
  $n = count($values);
  return $n % 2 ? (float)$values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
}

/**
 * How Dungeon Journal runs of $dungeon went, one run per group (several uploaders of one group share a
 * group_key): ['runs', 'completed', 'clear' => median seconds to the last boss of completed runs,
 * 'deaths' / 'releases' => per uploader per run, 'trash' => median trash kills,
 * 'bosses' => [boss => ['at' => median seconds from the start, 'runs']]] in the order they fall; null without runs.
 */
function forever_dungeon_run_profile(string $dungeon): ?array {
  static $ready = null;
  $ready ??= (bool)(Db::first("SELECT to_regclass('dungeon_runs') IS NOT NULL AS ok")['ok'] ?? false);
  if (!$ready) return null;
  $runs = Db::all("SELECT DISTINCT ON (group_key) run_key, started_at, final_kill, completed, deaths, releases, trash_kills
      FROM dungeon_runs WHERE source_game = ? AND dungeon = ? AND boss_kills > 0
      ORDER BY group_key, boss_kills + trash_kills DESC, last_seen DESC NULLS LAST", [DUNGEON_SOURCE, $dungeon]);
  if (!$runs) return null;
  $start = [];
  foreach ($runs as $r) $start[(string)$r['run_key']] = (int)$r['started_at'];
  $marks = implode(',', array_fill(0, count($start), '?'));
  $at = [];
  foreach (Db::all("SELECT run_key, boss, min(killed_at) AS t FROM dungeon_run_kills WHERE run_key IN ($marks) GROUP BY 1, 2", array_keys($start)) as $k)
    $at[(string)$k['boss']][] = (int)$k['t'] - $start[(string)$k['run_key']];
  $bosses = [];
  foreach ($at as $boss => $times) $bosses[$boss] = ['at' => forever_dungeon_median($times), 'runs' => count($times)];
  uasort($bosses, fn($a, $b) => $a['at'] <=> $b['at']);
  $done = array_filter($runs, fn($r) => $r['completed'] && $r['final_kill'] !== null);
  return [
    'runs' => count($runs), 'completed' => count($done),
    'clear' => forever_dungeon_median(array_map(fn($r) => (int)$r['final_kill'] - (int)$r['started_at'], $done)),
    'deaths' => array_sum(array_column($runs, 'deaths')) / count($runs),
    'releases' => array_sum(array_column($runs, 'releases')) / count($runs),
    'trash' => forever_dungeon_median(array_map(fn($r) => (int)$r['trash_kills'], $runs)),
    'bosses' => $bosses,
  ];
}

function forever_dungeon_minutes(float $seconds): string {
  $m = (int)round($seconds / 60);
  return $m >= 60 ? intdiv($m, 60) . ' h ' . ($m % 60) . ' min' : $m . ' min';
}

function forever_dungeon_run_profile_html(?array $p): string {
  if (!$p) return '';
  $n = $p['runs'];
  $facts = [
    [$p['clear'] !== null ? forever_dungeon_minutes($p['clear']) : '-', 'Typical clear' . ($p['completed'] ? ' (' . $p['completed'] . ' finished)' : '')],
    [number_format($p['deaths'], 1), 'Deaths per player per run'],
    [number_format($p['releases'], 1), 'Corpse runs per player per run'],
    [$p['trash'] !== null ? js_locale((int)round($p['trash'])) : '-', 'Trash kills per run'],
  ];
  $factHtml = '<div class="dg-facts2">' . implode('', array_map(fn($f) => '<div><b>' . esc($f[0]) . '</b><span>' . esc($f[1]) . '</span></div>', $facts)) . '</div>';
  $legs = [];
  $prev = 0.0;
  foreach ($p['bosses'] as $boss => $b) {
    $legs[] = ['boss' => $boss, 'at' => $b['at'], 'leg' => max(0.0, $b['at'] - $prev), 'runs' => $b['runs']];
    $prev = $b['at'];
  }
  $longest = $legs ? max(array_column($legs, 'leg')) : 0;
  $rows = '';
  foreach ($legs as $i => $l) {
    $slow = count($legs) > 1 && $l['leg'] === $longest && $longest > 0;
    $rows .= '<li' . ($slow ? ' class="slow"' : '') . '><span class="n">' . esc((string)$l['boss']) . '</span><span class="bar"><i style="width:' .
      ($longest > 0 ? max(2, (int)round(100 * $l['leg'] / $longest)) : 2) . '%"></i></span><span class="t">+' . esc(forever_dungeon_minutes($l['leg'])) .
      '<small> · at ' . esc(forever_dungeon_minutes($l['at'])) . ($l['runs'] < $n ? ' · ' . $l['runs'] . ' of ' . $n . ' runs' : '') . '</small></span></li>';
  }
  return '<section class="dg-boss dg-runs"><h3>How runs go <small>from ' . js_locale($n) . ' run' . ($n === 1 ? '' : 's') .
    ' players logged with the Dungeon Journal add-on' . ($n < 3 ? ' · too few to call a trend yet' : '') . '</small></h3>' . $factHtml .
    ($rows !== '' ? '<p class="hint" style="margin:0 0 4px">Time from the first pull to each boss (median); the longest bar is the slowest stretch.</p><ol class="dg-split">' . $rows . '</ol>' : '') .
    '</section>';
}

/**
 * Mobs combat logs saw inside $dungeon (bestiary entries placed there by their instance map id),
 * ranked by the damage they did per kill: [slug => bestiary entry + 'perKill', 'damage'].
 */
function forever_dungeon_mobs(string $dungeon): array {
  if (!function_exists('forever_bestiary')) return [];
  $out = [];
  foreach (forever_bestiary() as $slug => $m) {
    if (!isset($m['places'][$dungeon])) continue;
    $damage = array_sum(array_map(fn($a) => $a[9], $m['abilities']));
    if ($damage <= 0) continue;  // critters and objects
    $out[$slug] = $m + ['damage' => $damage, 'perKill' => $m['deaths'] > 0 ? $damage / $m['deaths'] : null, 'boss' => isset(forever_npcs()[$slug])];
  }
  // Trash first (which packs hurt), then the bosses; each by damage per kill.
  uasort($out, fn($a, $b) => [$a['boss'], -($a['perKill'] ?? -1), -$a['damage']] <=> [$b['boss'], -($b['perKill'] ?? -1), -$b['damage']]);
  return $out;
}

/**
 * What each mob's spells did in players' combat logs (combatlog_npc_mechanics), npc id => [spell id => totals]
 * with 'ability', 'starts', 'interrupted', 'summons', 'summon', 'gap' (average seconds between one mob's casts),
 * 'debuffs', 'stack', 'debuffSec' (average time on a player), 'volleys', 'volleyHits'.
 */
function forever_dungeon_mechanics(array $npcIds): array {
  static $ready = null;
  $ready ??= (bool)(Db::first("SELECT to_regclass('combatlog_npc_mechanics') IS NOT NULL AS ok")['ok'] ?? false);
  $npcIds = array_values(array_unique(array_map('intval', $npcIds)));
  if (!$ready || !$npcIds) return [];
  $marks = implode(',', array_fill(0, count($npcIds), '?'));
  $out = [];
  foreach (Db::all("SELECT npc_id, spell_id, max(ability) AS ability, sum(starts) AS starts, sum(interrupted) AS interrupted,
      sum(summons) AS summons, max(summon_name) AS summon, sum(gap_sum_ms) AS gap_ms, sum(gap_n) AS gaps, sum(debuffs) AS debuffs,
      max(debuff_max_stack) AS stack, sum(debuff_ms_sum) AS debuff_ms, sum(debuff_n) AS debuff_n, sum(volleys) AS volleys, sum(volley_hits) AS volley_hits
      FROM combatlog_npc_mechanics WHERE source_game = ? AND npc_id IN ($marks) GROUP BY 1, 2", array_merge([DUNGEON_SOURCE], $npcIds)) as $r)
    $out[(int)$r['npc_id']][(int)$r['spell_id']] = [
      'ability' => (string)$r['ability'], 'starts' => (int)$r['starts'], 'interrupted' => (int)$r['interrupted'],
      'summons' => (int)$r['summons'], 'summon' => $r['summon'], 'gap' => (int)$r['gaps'] > 0 ? (int)$r['gap_ms'] / (int)$r['gaps'] / 1000 : null,
      'debuffs' => (int)$r['debuffs'], 'stack' => (int)$r['stack'], 'debuffSec' => (int)$r['debuff_n'] > 0 ? (int)$r['debuff_ms'] / (int)$r['debuff_n'] / 1000 : null,
      'volleys' => (int)$r['volleys'], 'volleyHits' => (int)$r['volley_hits'],
    ];
  return $out;
}

function forever_dungeon_duration(float $sec): string {
  if ($sec >= 90) return round($sec / 60) . ' min';
  return (int)round($sec) . ' s';
}

/** One spell's measured behaviour as [tags, plain-language notes]; empty for spells with nothing to say. */
function forever_dungeon_spell_notes(array $x): array {
  $tags = $notes = [];
  if ($x['debuffs'] > 0) {
    $notes[] = 'leaves a debuff' . ($x['stack'] > 1 ? ' that stacks to ' . $x['stack'] : '') .
      ($x['debuffSec'] !== null && $x['debuffSec'] >= 10 ? ', about ' . forever_dungeon_duration($x['debuffSec']) . ' on the player' : '');
    if ($x['stack'] > 1) $tags[] = 'Stacks';
    if (($x['debuffSec'] ?? 0) >= 120) $tags[] = 'Long debuff';
  }
  if ($x['volleys'] >= 2 && $x['volleyHits'] / $x['volleys'] >= 1.5) {
    $notes[] = 'hits ' . number_format($x['volleyHits'] / $x['volleys'], 1) . ' players per cast on average';
    $tags[] = 'Hits several';
  }
  if ($x['summons'] > 0) {
    $notes[] = 'summons ' . ($x['summon'] ?: 'a helper');
    $tags[] = 'Summons';
  }
  if ($x['starts'] > 0) {
    $notes[] = 'has a cast time' . ($x['interrupted'] > 0 ? ', players interrupted ' . $x['interrupted'] . ' of ' . $x['starts'] : '');
    if ($x['interrupted'] > 0) $tags[] = 'Interruptible';
  }
  if ($x['gap'] !== null && $x['gap'] >= 2) $notes[] = ($notes ? 'about' : 'used about') . ' every ' . forever_dungeon_duration($x['gap']) . ' while fighting';
  return [$tags, $notes];
}

/** "What the mobs do": each measured mob's spells with what they did to players, trash first. */
function forever_dungeon_mechanics_html(string $dungeon): string {
  $mobs = forever_dungeon_mobs($dungeon);
  if (!$mobs) return '';
  $mech = forever_dungeon_mechanics(array_merge(...array_map(fn($m) => $m['ids'], array_values($mobs))));
  $cards = '';
  foreach ($mobs as $slug => $m) {
    // Keyed by name: one spell can have several ids (ranks, versions); the first with something to say wins.
    $spells = [];
    foreach ($m['ids'] as $id) foreach ($mech[$id] ?? [] as $sp => $x) {
      if ($x['ability'] === 'Dazed' || isset($spells[$x['ability']])) continue;  // every melee mob dazes from behind
      [$tags, $notes] = forever_dungeon_spell_notes($x);
      if ($notes) $spells[$x['ability']] = [$sp, $tags, $notes];
    }
    foreach ($m['abilities'] as $a) if ($a[0] !== 0 && !isset($spells[$a[1]]) && $a[3] > 0 && $a[1] !== 'Dazed')
      $spells[$a[1]] = [$a[0], [], [js_locale($a[3]) . ' cast' . ($a[3] === 1 ? '' : 's') . ' logged']];
    if (!$spells) continue;
    $rows = '';
    foreach ($spells as $name => [$sp, $tags, $notes])
      $rows .= '<li><span class="sp">' . bestiary_spell_link((int)$sp, $name) . '</span>' .
        implode('', array_map(fn($t) => '<span class="dg-mtag">' . esc($t) . '</span>', $tags)) .
        '<span class="nt">' . esc(ucfirst(implode('; ', $notes))) . '</span></li>';
    $cards .= '<div class="dg-mech-mob"><h4><a href="' . esc(forever_npc_path($slug)) . '">' . esc($m['name']) . '</a>' .
      ($m['boss'] ? ' <span class="dg-rare">BOSS</span>' : '') . ' <small>Lv ' . esc(bestiary_levels($m)) . '</small></h4><ul>' . $rows . '</ul></div>';
  }
  if ($cards === '') return '';
  return '<section class="dg-boss dg-mech"><h3>What the mobs do <small>measured from players\' combat logs</small></h3>' .
    '<div class="dg-mech-grid">' . $cards . '</div>' .
    '<p class="hint">Debuff times are how long a debuff stayed on a player; "players per cast" counts players hit within the same instant. Interrupts are the ones players landed in these logs.</p></section>';
}

function forever_dungeon_mobs_html(string $dungeon): string {
  $mobs = forever_dungeon_mobs($dungeon);
  if (!$mobs) return '';
  $total = array_sum(array_column($mobs, 'damage')) ?: 1;
  $rows = '';
  foreach ($mobs as $slug => $m) {
    $hardest = bestiary_hardest_hit($m);
    $top = null;
    foreach ($m['abilities'] as $a) if ($top === null || $a[9] > $top[9]) $top = $a;
    $rows .= '<tr><td><a href="' . esc(forever_npc_path($slug)) . '">' . esc($m['name']) . '</a>' . ($m['boss'] ? ' <span class="dg-rare">BOSS</span>' : '') . '</td>' .
      '<td>' . bestiary_levels($m) . '</td><td class="r">' . js_locale($m['deaths']) . '</td>' .
      '<td class="r"><b>' . ($m['perKill'] !== null ? js_locale((int)round($m['perKill'])) : '-') . '</b></td>' .
      '<td class="r hide-sm">' . js_locale((int)round(100 * $m['damage'] / $total)) . '%</td>' .
      '<td class="hide-sm">' . ($top && $top[9] > 0 ? bestiary_ability_label($top) . ' <small>(' . js_locale($top[9]) . ')</small>' : '') . '</td>' .
      '<td class="r hide-sm">' . ($hardest ? js_locale($hardest[8]) : '') . '</td></tr>';
  }
  return '<section class="dg-boss"><h3>Mobs in this dungeon <small>measured from players\' combat logs · trash, then bosses, each ranked by damage dealt per kill</small></h3>' .
    '<table class="dg-list"><thead><tr><th>Mob</th><th>Level</th><th class="r">Killed</th><th class="r">Damage per kill</th>' .
    '<th class="r hide-sm">Share of damage</th><th class="hide-sm">Does most damage with</th><th class="r hide-sm">Hardest hit</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
    '<p class="hint">Damage players took from each mob, after their armor and resistances. <a href="' . esc(forever_npc_path() . '?zone=' . rawurlencode($dungeon)) .
    '">All of them in the bestiary</a>.</p></section>';
}

function forever_dungeon_item_name(int $id): string {
  return (item_cache('item-names', EDITIONS[DUNGEON_SOURCE]['branch'])[$id] ?? null) ?: ((item_cache('item-names', 'classic')[$id] ?? null) ?: 'Item ' . $id);
}

/**
 * A boss's loot rows: the journal's list, each with how often players saw it drop, then drops players
 * saw that the journal does not list. $obs is Journalator's (windows, items => drops), $runs the
 * run logs' (kills, items => [drops, name, quality]).
 */
function forever_dungeon_loot_rows(array $b, ?array $obs, ?array $runs): string {
  $seen = function (int $id) use ($obs, $runs): string {
    $out = [];
    if ($runs && isset($runs['items'][$id])) $out[] = $runs['items'][$id]['drops'] . ' of ' . $runs['kills'] . ' run' . ($runs['kills'] === 1 ? '' : 's');
    if ($obs && isset($obs['items'][$id])) $out[] = $obs['items'][$id] . ' of ' . $obs['windows'] . ' kills';
    return implode(' · ', $out);
  };
  $rows = '';
  $listed = [];
  foreach ($b['loot'] as $it) {
    $listed[$it['id']] = true;
    $rows .= '<tr><td>' . forever_dungeon_item_link($it) . '</td><td class="s">' . esc($it['slot']) . '</td><td class="o">' . esc($seen((int)$it['id'])) . '</td></tr>';
  }
  $extra = [];
  foreach ($runs['items'] ?? [] as $id => $x) if (!isset($listed[$id])) $extra[$id] = ['name' => $x['name'], 'quality' => $x['quality']];
  foreach ($obs['items'] ?? [] as $id => $n) if (!isset($listed[$id])) $extra[$id] ??= ['name' => null, 'quality' => null];
  foreach ($extra as $id => $x)
    $rows .= '<tr class="dg-extra"><td>' . forever_dungeon_item_link(['id' => $id, 'name' => $x['name'] ?: forever_dungeon_item_name($id), 'quality' => $x['quality'] ?? 1]) .
      '</td><td class="s">Not in the journal\'s list</td><td class="o">' . esc($seen($id)) . '</td></tr>';
  return $rows;
}

function forever_dungeon_icon(?string $icon, string $size = 'small'): string {
  return '/assets/images/wow/icons/' . $size . '/' . ($icon ?: 'inv_misc_questionmark') . '.jpg';
}

function forever_dungeon_item_icon(int $id, string $size = 'small'): string {
  static $icons = null;
  $icons ??= (array)item_cache('item-icons', EDITIONS[DUNGEON_SOURCE]['branch']) + (array)item_cache('item-icons', 'classic');
  return forever_dungeon_icon($icons[$id] ?? null, $size);
}

function forever_dungeon_seen_note(): string {
  return 'Counts on the right are drops players saw: "of N runs" from Dungeon Journal run logs, "of N kills" from Journalator loot logs.';
}

function forever_dungeon_credit(): string {
  $s = forever_dungeon_data()['source'];
  return 'Bosses, levels, quests and loot lists come from the <b>' . esc($s['addon']) . '</b> add-on by ' . esc($s['author']) .
    ($s['version'] ? ' (version ' . esc($s['version']) . ')' : '') . '. Kill counts and run logs come from players\' uploaded journals; observed drops also come from players\' Journalator logs.';
}

/** URL of a boss's Forever Journal portrait, or null. */
function forever_boss_portrait(array $b): ?string {
  return !empty($b['portrait']) ? '/wow2008/' . $b['portrait'] : null;
}

/** URL of a dungeon's Forever Journal artwork ('card' or 'detail', falling back to the card), or null. */
function forever_dungeon_art(array $d, string $kind = 'card'): ?string {
  $g = $d['guide'] ?? null;
  $file = $g ? ($kind === 'detail' ? ($g['detail'] ?: $g['card']) : $g['card']) : null;
  return $file ? '/wow2008/' . $file : null;
}

/** The dungeon's overview from Forever Journal (its artwork is the page banner); '' when there is none. */
function forever_dungeon_overview_html(array $d): string {
  $g = $d['guide'] ?? null;
  if (!$g || !$g['overview']) return '';
  return '<section class="dg-boss dg-overview">' .
    '<div class="dg-ov-text"><h3>Overview</h3>' . implode('', array_map(fn($p) => '<p>' . esc($p) . '</p>', $g['overview'])) .
    '<p class="dg-ov-src">Overview and artwork: <a href="' . esc($g['source']) . '" target="_blank" rel="noopener">Forever Journal</a></p></div></section>';
}

const DUNGEON_STYLE = <<<'CSS'
.dg-quests article{padding:8px 0;border-bottom:1px solid rgba(128,128,128,.25)}
.dg-quests article:last-child{border-bottom:0}
.dg-quests h4,.dg-quests p{margin:0 0 4px}
.dg-quests .dg-it{display:inline-flex;margin-right:8px}
.dg-list{width:100%;border-collapse:collapse}
.dg-list th,.dg-list td{padding:6px 8px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;vertical-align:middle}
.dg-list td.r,.dg-list th.r{text-align:right}
.dg-name{display:flex;align-items:center;gap:8px}
.dg-name img{width:24px;height:24px;border:1px solid rgba(0,0,0,.6);flex:none}
.dg-new{display:inline-block;font-size:10px;font-weight:bold;letter-spacing:.05em;padding:1px 5px;border-radius:3px;background:#2a64c0;color:#fff;vertical-align:middle;margin-left:6px}
.dg-head{display:flex;gap:14px;align-items:flex-start;margin-bottom:10px}
.dg-head img{width:56px;height:56px;border:1px solid rgba(0,0,0,.6);flex:none}
.dg-npc-heading{align-items:center}
.dg-npc-heading .iname{margin:0}
.dg-meta{margin:2px 0;opacity:.85}
.dg-boss{margin:14px 0;padding:10px 12px;border:1px solid rgba(255,255,255,.1);border-radius:4px}
.dg-boss h3{margin:0 0 4px;font-size:16px}
.dg-boss .dg-sub{font-size:12px;opacity:.75;margin-bottom:6px}
.dg-kill{display:inline-block;font-size:11px;padding:1px 6px;border-radius:3px;background:rgba(30,255,0,.12);color:#7fdc6a;margin-left:8px}
.dg-loot{width:100%;border-collapse:collapse;margin-top:4px}
.dg-loot td{padding:3px 6px;border-top:1px solid rgba(255,255,255,.05);vertical-align:middle}
.dg-loot td.s{opacity:.7;font-size:12px}
.dg-loot td.o{text-align:right;font-size:12px;opacity:.85;white-space:nowrap}
.dg-loot tr.dg-extra td.s{font-style:italic;opacity:.85}
.dg-it{display:inline-flex;align-items:center;gap:6px;text-decoration:none}
.dg-it img{width:18px;height:18px;border:1px solid rgba(0,0,0,.6)}
.dg-it.q0{color:#9d9d9d}.dg-it.q1{color:#fff}.dg-it.q2{color:#1eff00}.dg-it.q3{color:#3d8bff}.dg-it.q4{color:#b579ff}.dg-it.q5{color:#ff8c32}
.dg-credit{font-size:12px;opacity:.75;margin-top:14px}
.dg-pick{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 12px;font-size:12px}
.dg-pick label{font-weight:bold}
.dg-pick select{font:inherit;padding:3px 6px;border:1px solid rgba(255,255,255,.25);border-radius:3px;max-width:100%}
.dg-boss h3 a.dg-npc{color:inherit;text-decoration:none}
.dg-boss h3 a.dg-npc:hover{text-decoration:underline}
.dg-rare{display:inline-block;font-size:10px;font-weight:bold;letter-spacing:.05em;padding:1px 5px;border-radius:3px;background:#8a6a10;color:#fff;vertical-align:middle;margin-left:6px}
.dg-cols{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;align-items:start}
.dg-cols>div>.dg-boss:first-child{margin-top:0}
.dg-npc-grid{display:grid;grid-template-columns:minmax(0,1fr) 240px;gap:14px;align-items:start}
.dg-npc-grid .dg-boss{margin:10px 0 0}
.dg-facts{width:100%;border-collapse:collapse;font-size:12px}
.dg-facts th,.dg-facts td{padding:3px 4px;text-align:left;vertical-align:top;border-top:1px solid rgba(255,255,255,.05)}
.dg-facts th{font-weight:bold;opacity:.75;white-space:nowrap;width:1%;padding-right:10px}
.dg-unk{opacity:.6;font-style:italic}
.dg-roster{list-style:none;margin:0;padding:0;font-size:12px}
.dg-roster li{display:flex;align-items:baseline;gap:6px;padding:3px 0;border-top:1px solid rgba(255,255,255,.05)}
.dg-roster li:first-child{border-top:0}
.dg-roster .n{flex:none;width:16px;text-align:center;font-size:10px;font-weight:bold;opacity:.7}
.dg-roster small{margin-left:auto;opacity:.65;font-size:10px}
.dg-roster li[aria-current] b{font-weight:bold}
.dg-npc-nav{display:flex;justify-content:space-between;gap:10px;margin:14px 0 0;font-size:12px}
.dg-npc-nav a:nth-child(2){text-align:center}
.dg-npc-nav a:last-child{text-align:right}
@media (max-width:640px){.dg-list .hide-sm{display:none}.dg-loot td.s{display:none}}
@media (max-width:760px){.dg-npc-grid,.dg-cols{grid-template-columns:minmax(0,1fr)}.dg-npc-nav{flex-wrap:wrap}}
.w8-framed .dg-facts{font-size:11px}
.w8-framed .dg-facts th,.w8-framed .dg-facts td,.w8-framed .dg-roster li{border-color:rgba(60,40,10,.15);text-align:left;font-size:11px}
.w8-framed .dg-facts th{background:none;border-bottom:0;color:#5a2a00}
.w8-framed .dg-rare{background:#8a5a00}
.w8-framed .dg-boss h3 a.dg-npc{color:#5a2a00}
.w8-framed .dg-roster li[aria-current]{background:rgba(255,215,90,.25)}
.w8-framed .dg-list th,.w8-framed .dg-list td,.w8-framed .dg-loot td{border-color:rgba(60,40,10,.15)}
.w8-framed .dg-boss{border-color:rgba(60,40,10,.25);background:rgba(255,250,235,.45)}
.w8-framed .dg-pick label{color:#5a2a00}
.w8-framed .dg-pick select{background:#fff8e6;color:#2a1e0c;border-color:rgba(60,40,10,.4)}
.w8-framed .dg-kill{background:#2f6b1f;color:#fff}
.census-banner.has-intro .t{right:22px}
.census-banner .t .intro{margin:6px 0 0;max-width:600px;font:12px/1.45 Tahoma,Arial,sans-serif;color:#eadfc4;text-shadow:0 1px 2px #000}
.census-banner .banner-tools{position:absolute;right:12px;top:10px;z-index:1;padding:4px 8px;background:rgba(0,0,0,.65);border:1px solid #6a5022}
.w8-framed .banner-tools .dg-pick{margin:0;font-size:11px;gap:6px}
.w8-framed .banner-tools .dg-pick label{color:#f0c860}
.w8-framed .banner-tools .dg-pick a{color:#f0c860}
.w8-framed .banner-tools .dg-pick select{padding:1px 4px;font-size:11px}
@media (max-width:600px){
  .census-banner.has-intro{height:auto;min-height:150px;display:flex;flex-direction:column}
  .census-banner.has-intro .t{position:relative;left:auto;right:auto;bottom:auto;padding:12px 14px 14px}
  .census-banner .banner-tools{position:relative;top:auto;right:auto;order:-1;margin:10px 10px 0}
  .w8-framed .banner-tools .dg-pick{flex-wrap:nowrap}
  .w8-framed .banner-tools .dg-pick select{flex:1;min-width:0}
  .census-banner.has-intro h1{font-size:24px}
}
.w8-framed .dg-it.q0{color:#6b6b6b}.w8-framed .dg-it.q1{color:#2a1e0c}.w8-framed .dg-it.q2{color:#1a7a00}
.w8-framed .dg-it.q3{color:#0a4fb0}.w8-framed .dg-it.q4{color:#7a2fb8}.w8-framed .dg-it.q5{color:#b55a00}
.dg-boss h3 small{font-size:11px;font-weight:normal;opacity:.7;margin-left:6px;font-variant:normal;letter-spacing:0}
.dg-facts2{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));margin:0 0 12px;border:1px solid rgba(255,255,255,.12)}
.dg-facts2 div{padding:8px 10px;border-left:1px solid rgba(255,255,255,.12)}
.dg-facts2 div:first-child{border-left:0}
.dg-facts2 b{display:block;font:bold 19px Georgia,serif}
.dg-facts2 span{font-size:11px;opacity:.75}
.dg-route-box{margin-top:0;min-width:0}
.dg-overview{margin-top:0}
.dg-ov-text h3{margin-top:0}
.dg-ov-text p{margin:0 0 8px;line-height:1.5}
.dg-ov-text p.dg-ov-src{margin:0;font-size:11px;opacity:.75}
.dg-page{min-width:0;max-width:100%}
.dg-route{display:grid;grid-template-columns:repeat(auto-fill,minmax(78px,1fr));row-gap:12px;list-style:none;margin:0;padding:4px 0 2px;overflow:hidden}
.dg-route li{min-width:0;position:relative;text-align:center}
.dg-route li+li:before{content:"";position:absolute;top:20px;right:50%;width:100%;height:3px;background:#b89552}
.dg-route a{position:relative;z-index:1;display:block;padding:0 2px;color:inherit;text-decoration:none}
.dg-route .dot{display:flex;align-items:center;justify-content:center;width:40px;height:40px;margin:0 auto;border-radius:50%;border:2px solid #8a6a32;background:#2a1e0c;color:#ffd75a;font:bold 15px Georgia,serif}
.dg-route a.rare .dot{border-style:dashed}
.dg-route .dot.pic{position:relative;width:46px;height:46px;margin-top:-3px;overflow:visible;background:#100b05}
.dg-route .dot.pic img{width:100%;height:100%;border-radius:50%;object-fit:cover}
.dg-route .dot.pic i{position:absolute;right:-6px;bottom:-4px;min-width:16px;height:16px;padding:0 2px;box-sizing:border-box;border-radius:8px;background:#2a1e0c;border:1px solid #8a6a32;color:#ffd75a;font:bold 10px/14px Georgia,serif;font-style:normal;text-align:center}
.dg-route a.on .dot.pic i{background:#7a1e10;border-color:#ffd75a}
.dg-bh{align-items:center}
.dg-bh-n{flex:1;min-width:0}
.dg-portrait{flex:none;width:64px;height:64px;border-radius:50%;border:2px solid #8a6a32;background:#100b05;box-shadow:0 2px 5px rgba(0,0,0,.5)}
.dg-npc-pic{text-align:center}
.dg-npc-pic img{display:block;width:96px;height:96px;margin:0 auto;border-radius:50%;border:3px solid #8a6a32;background:#100b05;box-shadow:0 3px 8px rgba(0,0,0,.5)}
.dg-route a.on .dot{background:#7a1e10;border-color:#ffd75a;box-shadow:0 0 0 3px rgba(255,215,90,.35)}
.dg-route .n{display:block;margin-top:5px;font-size:10.5px;line-height:1.2;font-weight:bold}
.dg-route .l{display:block;font-size:10px;opacity:.7}
.dg-route a:hover .n{text-decoration:underline}
.dg-main{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:14px;align-items:start}
.dg-main.solo{grid-template-columns:minmax(0,1fr)}
.dg-main>.dg-boss,.dg-cards>.dg-boss:first-child{margin-top:0}
.dg-js .dg-card{display:none;margin:0}
.dg-js .dg-card.on{display:block}
.dg-bh{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;padding-bottom:8px;margin-bottom:6px;border-bottom:1px solid rgba(255,255,255,.12)}
.dg-bh h3{font-size:19px}
.dg-bh .dg-sub{margin:0}
.dg-bh-r{text-align:right}
.dg-bh-r .dg-kill{margin:0}
.dg-kill.none{background:rgba(128,128,128,.25);color:inherit;opacity:.8}
.dg-step{display:none;gap:4px;justify-content:flex-end;margin-top:6px}
.dg-js .dg-step{display:flex}
.dg-step button,.dg-qf button{font:11px Verdana,Tahoma,sans-serif;padding:3px 8px;cursor:pointer;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.06);color:inherit}
.dg-desc{margin:0 0 6px}
.dg-loot2 td{padding:5px 4px}
.dg-big{display:flex;align-items:center;gap:8px;text-decoration:none}
.dg-big img{width:30px;height:30px;border:1px solid rgba(0,0,0,.7);border-radius:3px;flex:none}
.dg-big b{display:block}
.dg-big small{display:block;font-size:10.5px;opacity:.75;color:#ccc}
.dg-big:hover b{text-decoration:underline}
.dg-big.q0{color:#9d9d9d}.dg-big.q1{color:#fff}.dg-big.q2{color:#1eff00}.dg-big.q3{color:#3d8bff}.dg-big.q4{color:#b579ff}.dg-big.q5{color:#ff8c32}
.dg-loot2 td.o{width:130px;text-align:right;font-size:10.5px}
.dg-bar{display:block;height:7px;margin-top:3px;position:relative;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2)}
.dg-bar i{position:absolute;left:0;top:0;bottom:0;background:#b8402a}
.dg-none{font-style:italic;opacity:.6}
.dg-recipes{margin-top:8px;border:1px dashed rgba(255,255,255,.25)}
.dg-recipes summary{display:flex;align-items:center;flex-wrap:wrap;gap:6px;padding:7px 9px;cursor:pointer;list-style:none;font-size:12px}
.dg-recipes summary::-webkit-details-marker{display:none}
.dg-recipes summary img{width:22px;height:22px;border:1px solid rgba(0,0,0,.7);border-radius:3px}
.dg-pchip{font-size:10.5px;padding:1px 6px;border-radius:8px;border:1px solid rgba(255,255,255,.25);opacity:.85}
.dg-more{margin-left:auto;font-size:11px;opacity:.7}
.dg-more:after{content:" \25BE"}
.dg-recipes[open] .dg-more:after{content:" \25B4"}
.dg-recipes[open] .dg-more{font-size:0}.dg-recipes[open] .dg-more:before{content:"hide";font-size:11px}
.dg-prof{padding:4px 10px 0;font-size:10.5px;font-weight:bold;opacity:.75;text-transform:uppercase;letter-spacing:.04em}
.dg-recipes ul{margin:0;list-style:none;padding:2px 10px 8px 14px;columns:2;column-gap:18px;font-size:11.5px}
.dg-recipes li{margin:2px 0;break-inside:avoid}
.dg-recipes li small{opacity:.7;font-size:10px}
.dg-qcol{margin-top:0}
.dg-qf{display:flex;gap:4px;margin:0 0 6px}
.dg-qf button.on{background:#3a2406;color:#ffd75a;border-color:#3a2406}
.dg-q{border-bottom:1px dotted rgba(255,255,255,.18)}
.dg-q:last-child{border-bottom:0}
.dg-q summary{display:flex;flex-wrap:wrap;align-items:center;gap:4px 7px;padding:6px 0;cursor:pointer;list-style:none}
.dg-q summary::-webkit-details-marker{display:none}
.dg-q .qn{flex:1;font-weight:bold}
.dg-q summary:hover .qn{text-decoration:underline}
.dg-q .ql{font-size:10.5px;opacity:.75}
.dg-q .rw{flex-basis:100%;display:flex;align-items:center;gap:3px;padding-left:16px}
.dg-q .rw img{width:20px;height:20px;border:1px solid rgba(0,0,0,.7);border-radius:2px}
.dg-fac{width:9px;height:9px;border-radius:50%;flex:none;background:#8a7a5a}
.dg-fac.horde{background:#b3261c}.dg-fac.alliance{background:#2a5fbf}
.dg-btag{margin-left:4px;font-size:10px;padding:1px 5px;border-radius:2px;background:#7a1e10;color:#fff}
.dg-qb{padding:0 0 8px 16px;font-size:11.5px}
.dg-qb p{margin:0 0 3px}
.dg-qb .dg-it{margin-right:6px}
.dg-split{list-style:none;margin:0;padding:0}
.dg-split li{display:grid;grid-template-columns:minmax(120px,190px) minmax(0,1fr) minmax(150px,auto);align-items:center;gap:8px;padding:3px 0;font-size:12px}
.dg-split .n{font-weight:bold}
.dg-split .bar{display:block;height:10px;background:rgba(255,255,255,.08);border-radius:2px}
.dg-split .bar i{display:block;height:100%;background:#c8a040;border-radius:2px}
.dg-split li.slow .bar i{background:#c0392b}
.dg-split li.slow .n:after{content:" slowest stretch";font-size:10px;font-weight:normal;color:#e07060;margin-left:4px}
.dg-split .t small{opacity:.7}
@media (max-width:640px){.dg-split li{grid-template-columns:minmax(0,1fr) auto}.dg-split .bar{grid-column:1/-1;grid-row:2}}
.w8-framed .dg-split .bar{background:#e2cb98}
.w8-framed .dg-split .bar i{background:#7a5a20}
.w8-framed .dg-split li.slow .bar i{background:#7a1e10}
.w8-framed .dg-split li.slow .n:after{color:#7a1e10}
.dg-mech-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:8px}
.dg-mech-mob{padding:6px 9px;border:1px solid rgba(255,255,255,.12);border-radius:3px}
.dg-mech-mob h4{margin:0 0 4px;font-size:13px}
.dg-mech-mob h4 small{font-weight:normal;opacity:.7;font-size:10.5px}
.dg-mech-mob ul{margin:0;padding:0;list-style:none}
.dg-mech-mob li{margin:4px 0;font-size:11.5px;line-height:1.4}
.dg-mech-mob .sp{font-weight:bold;margin-right:4px}
.dg-mech-mob .nt{display:block;opacity:.85}
.dg-mtag{display:inline-block;margin-right:3px;padding:0 5px;font-size:9.5px;border-radius:2px;background:#5a2a10;color:#ffd75a;text-transform:uppercase;letter-spacing:.03em}
@media (max-width:640px){.dg-mech-grid{grid-template-columns:minmax(0,1fr)}}
.w8-framed .dg-mech-mob{border-color:#c9a86a;background:rgba(243,226,184,.5)}
.w8-framed .dg-mech-mob .nt{color:#4a3618;opacity:1}
.w8-framed .dg-mtag{background:#7a1e10;color:#fff}
.dg-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px}
.dg-tile{display:flex;align-items:center;gap:8px;padding:6px;border:1px solid rgba(255,255,255,.15);text-decoration:none}
.dg-tile img{width:36px;height:36px;border:1px solid rgba(0,0,0,.7);border-radius:3px;flex:none}
.dg-tile b{display:block;font-size:11.5px}
.dg-tile small{display:block;font-size:10.5px;opacity:.75;color:#ccc}
.dg-tile:hover b,.dg-chip:hover{text-decoration:underline}
.dg-chips{display:flex;flex-wrap:wrap;gap:6px}
.dg-chip{display:inline-flex;align-items:center;gap:5px;padding:3px 7px 3px 3px;font-size:11.5px;font-weight:bold;border:1px solid rgba(255,255,255,.15);text-decoration:none}
.dg-chip img{width:22px;height:22px;border:1px solid rgba(0,0,0,.7);border-radius:2px}
.dg-tile.q2,.dg-chip.q2{color:#1eff00}.dg-tile.q3,.dg-chip.q3{color:#3d8bff}.dg-tile.q4,.dg-chip.q4{color:#b579ff}.dg-tile.q1,.dg-chip.q1{color:#fff}.dg-tile.q0,.dg-chip.q0{color:#9d9d9d}
@media (max-width:760px){.dg-main{grid-template-columns:minmax(0,1fr)}.dg-recipes ul{columns:1}.dg-loot2 td.o{width:96px}}
.w8-framed .dg-facts2{background:#ead4a2;border-color:#c9a86a}
.w8-framed .dg-facts2 div{border-color:#c9a86a}
.w8-framed .dg-facts2 b{color:#5a2a00}
.w8-framed .dg-facts2 span{color:#6b5532;opacity:1}
.w8-framed .dg-route .n{color:#3a2406}
.w8-framed .dg-ov-text p{color:#2a1e0c}
.w8-framed .dg-ov-text p.dg-ov-src{color:#6b5532;opacity:1}
.w8-framed .dg-bh{border-color:rgba(60,40,10,.25)}
.w8-framed .dg-bh h3 a.dg-npc{font-variant:small-caps}
.w8-framed .dg-kill.none{background:#8a7a5a;color:#fff;opacity:1}
.w8-framed .dg-step button,.w8-framed .dg-qf button{background:#ead4a2;border-color:#a88850;color:#2a1e0c}
.w8-framed .dg-qf button.on{background:#3a2406;color:#ffd75a;border-color:#3a2406}
.w8-framed .dg-big small,.w8-framed .dg-tile small{color:#6b5532;opacity:1}
.w8-framed .dg-big.q0,.w8-framed .dg-tile.q0,.w8-framed .dg-chip.q0{color:#6b6b6b}.w8-framed .dg-big.q1,.w8-framed .dg-tile.q1,.w8-framed .dg-chip.q1{color:#2a1e0c}
.w8-framed .dg-big.q2,.w8-framed .dg-tile.q2,.w8-framed .dg-chip.q2{color:#1a7a00}.w8-framed .dg-big.q3,.w8-framed .dg-tile.q3,.w8-framed .dg-chip.q3{color:#0a4fb0}
.w8-framed .dg-big.q4,.w8-framed .dg-tile.q4,.w8-framed .dg-chip.q4{color:#7a2fb8}.w8-framed .dg-big.q5{color:#b55a00}
.w8-framed .dg-loot2 td.o{color:#6b5532}
.w8-framed .dg-bar{background:#e2cb98;border-color:#b89552}
.w8-framed .dg-bar i{background:#7a1e10}
.w8-framed .dg-recipes{border-color:#b89552;background:rgba(243,226,184,.6)}
.w8-framed .dg-pchip{background:#ead4a2;border-color:#c9a86a;color:#6b5532;opacity:1}
.w8-framed .dg-prof{color:#5a2a00;opacity:1}
.w8-framed .dg-q{border-color:#d1b57c}
.w8-framed .dg-q .qn{color:#5a2a00}
.w8-framed .dg-q .ql{color:#6b5532;opacity:1}
.w8-framed .dg-qb{color:#4a3618}
.w8-framed .dg-qb b{color:#2a1e0c}
.w8-framed .dg-tile,.w8-framed .dg-chip{background:#f3e2b8;border-color:#c9a86a}
CSS;

function forever_npc_slug(string $name): string {
  return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(str_replace("'", '', $name))), '-');
}

function forever_npc_path(?string $slug = null): string {
  return edition_path(DUNGEON_SOURCE) . 'npc/' . ($slug !== null ? $slug . '/' : '');
}

/**
 * Every boss and rare spawn in the journal, slug => ['boss', 'dungeon', 'n' (1-based place in the dungeon)].
 * Boss names are unique across the journal, so the name is the page's address.
 */
function forever_npcs(): array {
  static $by = null;
  if ($by !== null) return $by;
  $by = [];
  foreach (forever_dungeons() as $d)
    foreach ($d['bosses'] as $i => $b) $by[forever_npc_slug($b['name'])] ??= ['boss' => $b, 'dungeon' => $d, 'n' => $i + 1];
  return $by;
}

/** NPC id => slug, for linking Journalator loot sources to their boss page. */
function forever_npc_slug_by_id(int $npcId): ?string {
  static $ids = null;
  if ($ids === null) {
    $ids = [];
    foreach (forever_npcs() as $slug => $n) if ($n['boss']['npcID']) $ids[(int)$n['boss']['npcID']] = $slug;
  }
  return $ids[$npcId] ?? null;
}

function forever_npc_subtitle(array $n): string {
  $boss = $n['boss'];
  return implode(' · ', array_filter([
    $boss['level'] ? 'Level ' . $boss['level'] : null,
    $boss['rare'] ? 'Rare spawn' : 'Boss',
    $n['dungeon']['name'],
  ]));
}

function forever_npc_body(array $n): string {
  $b = $n['boss'];
  $d = $n['dungeon'];
  $obs = $b['npcID'] ? (forever_dungeon_observed([$b['npcID']])[$b['npcID']] ?? null) : null;
  $runs = forever_dungeon_runs($d['name'])[$b['name']] ?? null;
  $loot = forever_dungeon_loot_rows($b, $obs, $runs);
  $quests = array_filter($d['questDetails'] ?? [], fn($quest) => in_array($b['name'], $quest['bosses'], true));
  $encounter = $d['slug'] === 'hall-of-thanes' && $b['npcID'] === 261306
    ? '<section class="dg-boss"><h3>Encounter</h3><p>Faldrim is the first boss in the Hall of Thanes, a royal dwarven burial chamber beneath Ironforge. In Anvilmar’s Rest, the Ghostly Attendant asks adventurers to put the restless thane’s spirit to rest.</p>' .
      '<p class="hint"><a href="https://worldofwarcraft.blizzard.com/en-us/news/24301455/">Blizzard dungeon overview</a> · <a href="https://www.wowhead.com/forever/guide/hall-of-thanes-dungeon-overview-location-rewards">Hall of Thanes map and guide</a></p></section>'
    : '';

  $facts = [
    ['Dungeon', '<a href="' . esc(forever_dungeon_path($d['slug'])) . '">' . esc($d['name']) . '</a>' . ($d['new'] ? ' <span class="dg-new">NEW</span>' : '')],
    ['Location', esc($d['location'])],
    ['Level', $b['level'] !== '' && $b['level'] !== null ? esc($b['level']) : '<span class="dg-unk">Unknown</span>'],
    ['Type', $b['rare'] ? 'Rare spawn' : 'Boss ' . count(array_filter(array_slice($d['bosses'], 0, $n['n']), fn($x) => !$x['rare'])) . ' of ' . count(array_filter($d['bosses'], fn($x) => !$x['rare']))],
    ['NPC ID', $b['npcID'] ? esc((string)$b['npcID']) : '<span class="dg-unk">Not recorded</span>'],
    ['Kills on record', $b['killedBy'] ? js_locale($b['killedBy']) . ' uploader' . ($b['killedBy'] === 1 ? '' : 's') : '<span class="dg-unk">None yet</span>'],
  ];
  if ($runs) $facts[] = ['Kills in run logs', js_locale($runs['kills'])];
  if ($obs) $facts[] = ['Looted in logs', js_locale($obs['windows']) . ' time' . ($obs['windows'] === 1 ? '' : 's')];
  $factRows = implode('', array_map(fn($f) => '<tr><th>' . $f[0] . '</th><td>' . $f[1] . '</td></tr>', $facts));

  $others = '';
  $k = 0;
  foreach ($d['bosses'] as $x) {
    $slug = forever_npc_slug($x['name']);
    $others .= '<li' . ($x['name'] === $b['name'] ? ' aria-current="page"' : '') . '><span class="n" title="' . ($x['rare'] ? 'Rare spawn' : 'Boss') . '">' . ($x['rare'] ? 'R' : ++$k) . '</span>' .
      ($x['name'] === $b['name'] ? '<b>' . esc($x['name']) . '</b>' : '<a href="' . esc(forever_npc_path($slug)) . '">' . esc($x['name']) . '</a>') .
      ($x['level'] ? '<small>' . esc($x['level']) . '</small>' : '') . '</li>';
  }
  $prev = $d['bosses'][$n['n'] - 2] ?? null;
  $next = $d['bosses'][$n['n']] ?? null;
  $nav = '<nav class="dg-npc-nav">' .
    ($prev ? '<a href="' . esc(forever_npc_path(forever_npc_slug($prev['name']))) . '">&laquo; ' . esc($prev['name']) . '</a>' : '<span></span>') .
    '<a href="' . esc(forever_dungeon_path($d['slug'])) . '">All of ' . esc($d['name']) . '</a>' .
    ($next ? '<a href="' . esc(forever_npc_path(forever_npc_slug($next['name']))) . '">' . esc($next['name']) . ' &raquo;</a>' : '<span></span>') . '</nav>';

  return '<div class="panel census dg-npc">' .
    ($b['description'] !== '' ? '<p style="margin:4px 0 0">' . esc($b['description']) . '</p>' : '') .
    '<div class="dg-npc-grid"><div>' .
    $encounter .
    '<section class="dg-boss"><h3>Loot</h3>' .
    ($loot !== '' ? '<table class="dg-loot"><tbody>' . $loot . '</tbody></table>' : '<p class="hint">No loot listed yet.</p>') .
    ($obs || $runs ? '<p class="hint">' . forever_dungeon_seen_note() . '</p>' : '') . '</section>' .
    bestiary_boss_section(forever_npc_slug($b['name'])) .
    '</div><aside>' .
    (($pic = forever_boss_portrait($b)) ? '<section class="dg-boss dg-npc-pic"><img src="' . esc($pic) . '" alt="' . esc($b['name']) . '" width="96" height="96"></section>' : '') .
    '<section class="dg-boss"><h3>Details</h3><table class="dg-facts"><tbody>' . $factRows . '</tbody></table></section>' .
    '<section class="dg-boss"><h3>In ' . esc($d['name']) . '</h3><ol class="dg-roster">' . $others . '</ol></section>' .
    '</aside></div>' . $nav .
    '<p class="dg-credit">' . forever_dungeon_credit() . '</p></div>' .
    forever_dungeon_quests_html($quests, true);
}

/** The plain NPC page (the default skins); the 2008 skin frames its body. */
function forever_npc_html(string $slug, string $stylesheet): ?string {
  $n = forever_npcs()[$slug] ?? null;
  if ($n === null) return null;
  $b = $n['boss'];
  $d = $n['dungeon'];
  $label = seo_label(EDITIONS[DUNGEON_SOURCE]['label']);
  $navigation = edition_nav(DUNGEON_SOURCE, 'dungeons');
  $title = $b['name'] . ' – ' . $d['name'] . ' – ' . $label;
  $desc = $b['name'] . ($b['rare'] ? ', a rare spawn' : '') . ' in ' . $d['name'] . ' (' . $label . ')' . ($b['level'] ? ', level ' . $b['level'] : '') .
    ': ' . count($b['loot']) . ' listed drops' . ($b['loot'] ? ', including ' . implode(', ', array_map(fn($i) => $i['name'], array_slice($b['loot'], 0, 3))) : '') . '.';
  $seo = seo_head($title . ' – WoWCensus', $desc, SITE_ORIGIN . forever_npc_path($slug));
  $heading = '<div class="dg-head dg-npc-heading"><img src="' . esc(forever_dungeon_icon($d['icon'], 'large')) .
    '" alt=""><div><h1 class="iname">' . esc($b['name']) . '</h1><p class="dg-meta">' .
    esc(forever_npc_subtitle($n)) . '</p></div></div>';
  $css = esc($stylesheet);
  $census_css = CENSUS_STYLESHEET;
  $style = DUNGEON_STYLE . BESTIARY_STYLE;
  $body = forever_npc_body($n);
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

function forever_dungeon_item_link(array $it, ?string $url = null): string {
  return '<a class="dg-it ' . (DUNGEON_QUALITY[$it['quality']] ?? 'q1') . '" href="' . esc($url ?? forever_item_path($it['id'])) . '"><img src="' .
    esc(forever_dungeon_item_icon((int)$it['id'])) . '" alt="" loading="lazy">' . esc($it['name']) . '</a>';
}

function forever_dungeon_quests_html(array $quests, bool $onBoss = false): string {
  if (!$quests) return '';
  $html = $onBoss
    ? '<section class="panel census dg-quests"><h2 class="ptitle">Related Quests</h2>'
    : '<section class="dg-boss dg-quests"><h3>Quests</h3>';
  foreach ($quests as $quest) {
    $name = esc($quest['name']);
    if ($quest['id']) $name = '<a href="https://www.wowhead.com/forever/quest=' . (int)$quest['id'] . '">' . $name . '</a>';
    $meta = array_filter([$quest['faction'] ?? null, $quest['requires'] ? 'Requires level ' . $quest['requires'] : null]);
    $html .= '<article><h4>' . $name . '</h4>';
    if ($meta) $html .= '<p class="dg-sub">' . esc(implode(' · ', $meta)) . '</p>';
    if ($quest['objective'] !== '') $html .= '<p>' . esc($quest['objective']) . '</p>';
    foreach (['pickup' => 'Pick up', 'turnin' => 'Turn in'] as $key => $label) {
      if (isset($quest[$key])) $html .= '<p><b>' . $label . '</b> ' . esc($quest[$key]) . '</p>';
    }
    if (!$onBoss && $quest['bosses']) {
      $links = array_map(fn($boss) => '<a href="' . esc(forever_npc_path(forever_npc_slug($boss))) . '">' . esc($boss) . '</a>', $quest['bosses']);
      $html .= '<p><b>Related bosses</b> ' . implode(', ', $links) . '</p>';
    }
    if ($quest['rewards']) {
      $html .= '<p><b>' . ($quest['rewardChoice'] ? 'Choose a reward' : 'Rewards') . '</b> ' .
        implode(' ', array_map(fn($item) => forever_dungeon_item_link($item), $quest['rewards'])) . '</p>';
    }
    $html .= '</article>';
  }
  return $html . '</section>';
}

function forever_dungeon_meta(array $d): string {
  return implode(' · ', array_filter([$d['level'] ? 'Level ' . $d['level'] : null, $d['location'] ?: null, count($d['bosses']) . ' bosses']));
}

function forever_dungeons_body(?array $d): string {
  $data = forever_dungeon_data();
  $dungeons = forever_dungeons();
  if ($d === null) {
    $rows = '';
    foreach ($dungeons as $x) {
      $items = array_sum(array_map(fn($b) => count($b['loot']), $x['bosses'])) + count($x['trash']);
      $rows .= '<tr><td><a class="dg-name" href="' . esc(forever_dungeon_path($x['slug'])) . '"><img src="' . esc(forever_dungeon_icon($x['icon'])) . '" alt=""><span>' .
        esc($x['name']) . ($x['new'] ? '<span class="dg-new">NEW</span>' : '') . '</span></a></td><td>' . esc($x['level']) . '</td><td class="hide-sm">' .
        esc($x['location']) . '</td><td class="r">' . count($x['bosses']) . '</td><td class="r hide-sm">' . js_locale($items) . '</td></tr>';
    }
    $new = count(array_filter($dungeons, fn($x) => $x['new']));
    // The 2008 skin swaps the marked block for forever_dungeons_w8_index().
    return '<!--dg-index--><div class="panel census"><div class="ptitle">Dungeons of WoW: Forever</div>' .
      '<p class="hint" style="margin-top:0">' . count($dungeons) . ' dungeons, ' . $new . ' of them new in Forever. Pick one for its bosses and what they drop.</p>' .
      '<table class="dg-list"><thead><tr><th>Dungeon</th><th>Level</th><th class="hide-sm">Location</th><th class="r">Bosses</th><th class="r hide-sm">Loot</th></tr></thead><tbody>' .
      $rows . '</tbody></table><p class="dg-credit">' . forever_dungeon_credit() . '</p></div><!--/dg-index-->';
  }

  $observed = forever_dungeon_observed(array_map(fn($b) => $b['npcID'], $d['bosses']));
  $runLog = forever_dungeon_runs($d['name']);
  $anySeen = false;
  $pick = '<nav class="dg-pick" aria-label="Dungeons"><label for="dg-jump">Dungeon</label>' .
    '<select id="dg-jump" onchange="if(this.value)location.href=this.value">';
  foreach ($dungeons as $x) $pick .= '<option value="' . esc(forever_dungeon_path($x['slug'])) . '"' . ($x['slug'] === $d['slug'] ? ' selected' : '') . '>' . esc($x['name']) . '</option>';
  $pick .= '</select><a href="' . esc(forever_dungeon_path()) . '">All dungeons</a></nav>';

  $route = $cards = '';
  $best = [];
  $runs = 0;
  $count = count($d['bosses']);
  foreach ($d['bosses'] as $i => $b) {
    $obs = $b['npcID'] ? ($observed[$b['npcID']] ?? null) : null;
    $bossRuns = $runLog[$b['name']] ?? null;
    $anySeen = $anySeen || $obs || $bossRuns;
    $runs = max($runs, $bossRuns['kills'] ?? 0);
    $anchor = 'boss-' . forever_npc_slug($b['name']);
    $pic = forever_boss_portrait($b);
    $route .= '<li><a href="#' . esc($anchor) . '" data-i="' . $i . '"' . ($b['rare'] ? ' class="rare"' : '') . '><span class="dot' . ($pic ? ' pic' : '') . '">' .
      ($pic ? '<img src="' . esc($pic) . '" alt="" loading="lazy"><i>' . ($b['rare'] ? '&#9733;' : $i + 1) . '</i>' : ($b['rare'] ? '&#9733;' : $i + 1)) .
      '</span><span class="n">' . esc($b['name']) . '</span>' . ($b['level'] ? '<span class="l">Lv ' . esc($b['level']) . '</span>' : '') . '</a></li>';
    [$rows, $recipes, $seen] = forever_dungeon_boss_loot($b, $obs, $bossRuns);
    foreach ($seen as $s) $best[] = $s + ['boss' => $b['name']];
    $sub = array_filter([$b['level'] ? 'Level ' . $b['level'] : null, $b['rare'] ? 'Rare spawn' : null, $b['npcID'] ? 'NPC ' . $b['npcID'] : null]);
    $kill = $b['killedBy'] ? '<span class="dg-kill">Killed by ' . js_locale($b['killedBy']) . ' uploader' . ($b['killedBy'] === 1 ? '' : 's') . '</span>'
      : '<span class="dg-kill none">No kills logged</span>';
    $cards .= '<section class="dg-boss dg-card" id="' . esc($anchor) . '" data-i="' . $i . '"><header class="dg-bh">' .
      ($pic ? '<img class="dg-portrait" src="' . esc($pic) . '" alt="' . esc($b['name']) . '" width="64" height="64" loading="lazy">' : '') . '<div class="dg-bh-n"><h3><a class="dg-npc" href="' .
      esc(forever_npc_path(forever_npc_slug($b['name']))) . '">' . esc($b['name']) . '</a></h3>' .
      ($sub ? '<div class="dg-sub">' . esc(implode(' · ', $sub)) . '</div>' : '') . '</div><div class="dg-bh-r">' . $kill .
      ($count > 1 ? '<span class="dg-step"><button type="button" data-step="-1">&lsaquo; Prev</button><button type="button" data-step="1">Next &rsaquo;</button></span>' : '') .
      '</div></header>' .
      ($b['description'] !== '' ? '<p class="dg-desc">' . esc($b['description']) . '</p>' : '') .
      ($rows !== '' ? '<table class="dg-loot dg-loot2"><tbody>' . $rows . '</tbody></table>' : ($recipes === '' ? '<p class="hint">No loot listed yet.</p>' : '')) .
      $recipes . '</section>';
  }

  $quests = $d['questDetails'] ?? [];
  $factions = array_count_values(array_map(fn($q) => $q['faction'] ?? 'Both', $quests));
  $questPanel = forever_dungeon_quest_list($quests);

  $rare = count(array_filter($d['bosses'], fn($b) => $b['rare']));
  $facts = [[$d['level'] ?: '-', 'Level range'], [(string)$count, 'Boss' . ($count === 1 ? '' : 'es') . ($rare ? ' (' . $rare . ' rare)' : '')]];
  if ($quests) {
    $split = [];
    foreach (['Horde' => 'Horde', 'Alliance' => 'Alliance', 'Both' => 'both'] as $f => $label) if (isset($factions[$f])) $split[] = $factions[$f] . ' ' . $label;
    $facts[] = [(string)count($quests), 'Quests · ' . implode(' · ', $split)];
  }
  $facts[] = [js_locale(forever_dungeon_loot_count($d)), 'Items in the loot tables'];
  $profile = forever_dungeon_run_profile($d['name']);
  if ($profile) $runs = max($runs, $profile['runs']);
  $facts[] = [$runs ? js_locale($runs) : '-', 'Runs logged by players'];
  $factHtml = '<div class="dg-facts2">' . implode('', array_map(fn($f) => '<div><b>' . esc($f[0]) . '</b><span>' . esc($f[1]) . '</span></div>', $facts)) . '</div>';

  usort($best, fn($a, $b) => [$b['quality'] >= 3, $b['rate'], $b['drops']] <=> [$a['quality'] >= 3, $a['rate'], $a['drops']]);
  $bestHtml = '';
  $shown = [];
  foreach ($best as $x) {
    if ($x['quality'] < 2 || isset($shown[$x['id']]) || count($shown) >= 8) continue;
    $shown[$x['id']] = true;
    $bestHtml .= '<a class="dg-tile ' . (DUNGEON_QUALITY[$x['quality']] ?? 'q1') . '" href="' . esc(forever_item_path($x['id'])) . '"><img src="' .
      esc(forever_dungeon_item_icon($x['id'], 'large')) . '" alt="" loading="lazy"><span><b>' . esc($x['name']) . '</b><small>' . esc($x['boss']) . ' · ' . esc($x['seen']) . '</small></span></a>';
  }

  $trash = '';
  foreach ($d['trash'] as $it)
    $trash .= '<a class="dg-chip ' . (DUNGEON_QUALITY[$it['quality']] ?? 'q1') . '" href="' . esc(forever_item_path($it['id'])) . '" title="' .
      esc(implode(' · ', array_filter([$it['slot'], $it['from'] ?? null]))) . '"><img src="' .
      esc(forever_dungeon_item_icon((int)$it['id'])) . '" alt="" loading="lazy">' . esc($it['name']) . '</a>';

  // The 2008 skin moves the marked picker and heading into its banner.
  return '<div class="panel census dg-page"><!--dg-pick-->' . $pick . '<!--/dg-pick--><!--dg-head-->' .
    '<div class="dg-head"><img src="' . esc(forever_dungeon_icon($d['icon'], 'large')) . '" alt=""><div><div class="ptitle" style="margin:0">' . esc($d['name']) .
    ($d['new'] ? '<span class="dg-new">NEW IN FOREVER</span>' : '') . '</div><p class="dg-meta">' . esc(forever_dungeon_meta($d)) . '</p>' .
    ($d['description'] !== '' ? '<p style="margin:4px 0 0">' . esc($d['description']) . '</p>' : '') . '</div></div><!--/dg-head-->' .
    $factHtml .
    ($d['lootIncomplete'] ? '<p class="hint">The loot list for this dungeon is still being filled in.</p>' : '') .
    forever_dungeon_overview_html($d) .
    ($count ? '<section class="dg-boss dg-route-box"><h3>Boss route <small>in the journal\'s order · pick a boss to see its loot</small></h3><ol class="dg-route">' . $route . '</ol></section>' : '') .
    forever_dungeon_run_profile_html($profile) .
    '<div class="dg-main' . ($questPanel === '' ? ' solo' : '') . '"><div class="dg-cards">' . $cards . '</div>' . $questPanel . '</div>' .
    forever_dungeon_mobs_html($d['name']) .
    forever_dungeon_mechanics_html($d['name']) .
    ($bestHtml !== '' ? '<section class="dg-boss"><h3>Best of the dungeon <small>the drops players saw most often</small></h3><div class="dg-tiles">' . $bestHtml . '</div></section>' : '') .
    ($trash !== '' ? '<section class="dg-boss"><h3>Trash drops <small>from any mob in the dungeon · hover for the slot</small></h3><div class="dg-chips">' . $trash . '</div></section>' : '') .
    ($anySeen ? '<p class="hint">' . forever_dungeon_seen_note() . '</p>' : '') . '<p class="dg-credit"><a href="' . esc(forever_dungeon_path()) . '">All dungeons</a> · ' . forever_dungeon_credit() . '</p></div>' .
    DUNGEON_PAGE_SCRIPT;
}

/**
 * One boss's loot for its card: [table rows, folded recipe list, items players saw drop].
 * Recipes ("Recipe, <profession>") fold into one list grouped by profession; each seen item is
 * ['id', 'name', 'quality', 'rate', 'drops', 'seen'] for "Best of the dungeon".
 */
function forever_dungeon_boss_loot(array $b, ?array $obs, ?array $runs): array {
  $seen = function (int $id) use ($obs, $runs): array {
    $text = [];
    $rate = null;
    $drops = 0;
    if ($runs && isset($runs['items'][$id])) {
      $drops = $runs['items'][$id]['drops'];
      $text[] = $drops . ' of ' . $runs['kills'] . ' run' . ($runs['kills'] === 1 ? '' : 's');
      $rate = $drops / max(1, $runs['kills']);
    }
    if ($obs && isset($obs['items'][$id])) {
      $text[] = $obs['items'][$id] . ' of ' . $obs['windows'] . ' kill' . ($obs['windows'] === 1 ? '' : 's');
      $rate ??= $obs['items'][$id] / max(1, $obs['windows']);
      $drops = max($drops, $obs['items'][$id]);
    }
    return [implode(' · ', $text), $rate, $drops];
  };
  $found = [];
  $row = function (array $it, string $slot, bool $extra) use ($seen, &$found): string {
    [$text, $rate, $drops] = $seen((int)$it['id']);
    if ($rate !== null) $found[] = ['id' => (int)$it['id'], 'name' => $it['name'], 'quality' => (int)$it['quality'], 'rate' => $rate, 'drops' => $drops, 'seen' => $text];
    $q = DUNGEON_QUALITY[$it['quality']] ?? 'q1';
    return '<tr' . ($extra ? ' class="dg-extra"' : '') . '><td><a class="dg-big ' . $q . '" href="' . esc(forever_item_path($it['id'])) . '"><img src="' .
      esc(forever_dungeon_item_icon((int)$it['id'], 'large')) . '" alt="" loading="lazy"><span><b>' . esc($it['name']) . '</b><small>' . esc($slot) . '</small></span></a></td>' .
      '<td class="o">' . ($rate !== null ? esc($text) . '<span class="dg-bar"><i style="width:' . max(3, (int)round(100 * min(1, $rate))) . '%"></i></span>'
        : '<span class="dg-none">not seen yet</span>') . '</td></tr>';
  };
  $rows = '';
  $recipes = [];
  $listed = [];
  foreach ($b['loot'] as $it) {
    $listed[$it['id']] = true;
    if (preg_match('/^Recipe,\s*(.+)$/', $it['slot'], $m)) { $recipes[$m[1]][] = $it; continue; }
    $rows .= $row($it, $it['slot'], false);
  }
  $extra = [];
  foreach ($runs['items'] ?? [] as $id => $x) if (!isset($listed[$id])) $extra[$id] = ['name' => $x['name'], 'quality' => $x['quality']];
  foreach ($obs['items'] ?? [] as $id => $n) if (!isset($listed[$id])) $extra[$id] ??= ['name' => null, 'quality' => null];
  foreach ($extra as $id => $x)
    $rows .= $row(['id' => $id, 'name' => $x['name'] ?: forever_dungeon_item_name($id), 'quality' => $x['quality'] ?? 1], 'Also seen here · not in the journal\'s list', true);
  $fold = '';
  if ($recipes) {
    ksort($recipes);
    $n = array_sum(array_map('count', $recipes));
    $lists = '';
    foreach ($recipes as $prof => $list) {
      $lists .= '<div class="dg-prof">' . esc($prof) . '</div><ul>';
      foreach ($list as $it) {
        [$text] = $seen((int)$it['id']);
        $lists .= '<li>' . forever_dungeon_item_link($it) . ($text !== '' ? ' <small>' . esc($text) . '</small>' : '') . '</li>';
      }
      $lists .= '</ul>';
    }
    $fold = '<details class="dg-recipes"><summary><img src="' . esc(forever_dungeon_icon('inv_scroll_03')) . '" alt=""><b>' . $n . ' recipe' . ($n === 1 ? '' : 's') . '</b>' .
      implode('', array_map(fn($p) => '<span class="dg-pchip">' . esc($p) . ' ' . count($recipes[$p]) . '</span>', array_keys($recipes))) .
      '<span class="dg-more">show</span></summary>' . $lists . '</details>';
  }
  return [$rows, $fold, $found];
}

/** The quest column: one compact line per quest (faction, name, level, reward icons) that opens to its details. */
function forever_dungeon_quest_list(array $quests): string {
  if (!$quests) return '';
  $factions = array_unique(array_map(fn($q) => $q['faction'] ?? 'Both', $quests));
  $filter = count(array_intersect($factions, ['Horde', 'Alliance'])) === 2
    ? '<div class="dg-qf" role="group" aria-label="Faction"><button type="button" class="on" data-f="">All</button><button type="button" data-f="Horde">Horde</button><button type="button" data-f="Alliance">Alliance</button></div>' : '';
  $html = '';
  foreach ($quests as $quest) {
    $faction = $quest['faction'] ?? 'Both';
    $icons = implode('', array_map(fn($it) => '<img src="' . esc(forever_dungeon_item_icon((int)$it['id'])) . '" alt="" title="' . esc($it['name']) . '" loading="lazy">', $quest['rewards']));
    $body = '';
    if ($quest['objective'] !== '') $body .= '<p>' . esc($quest['objective']) . '</p>';
    foreach (['pickup' => 'Pick up', 'turnin' => 'Turn in'] as $key => $label)
      if (isset($quest[$key])) $body .= '<p><b>' . $label . '</b> ' . esc($quest[$key]) . '</p>';
    if ($quest['bosses'])
      $body .= '<p><b>Bosses</b> ' . implode(', ', array_map(fn($boss) => '<a href="#boss-' . esc(forever_npc_slug($boss)) . '" data-boss>' . esc($boss) . '</a>', $quest['bosses'])) . '</p>';
    if ($quest['rewards'])
      $body .= '<p><b>' . ($quest['rewardChoice'] ? 'Choose one' : 'Rewards') . '</b> ' .
        implode(' ', array_map(fn($item) => forever_dungeon_item_link($item), $quest['rewards'])) . '</p>';
    if ($quest['id']) $body .= '<p><a href="https://www.wowhead.com/forever/quest=' . (int)$quest['id'] . '">Quest on Wowhead</a></p>';
    $html .= '<details class="dg-q" data-f="' . esc($faction) . '"><summary><span class="dg-fac ' . strtolower(esc($faction)) . '" title="' . esc($faction) . '"></span>' .
      '<span class="qn">' . esc($quest['name']) . '</span><span class="ql">' . ($faction === 'Both' ? 'Both · ' : '') . ($quest['requires'] ? 'Lv ' . (int)$quest['requires'] . '+' : '') . '</span>' .
      ($icons !== '' || $quest['bosses'] ? '<span class="rw">' . $icons . ($quest['bosses'] ? '<span class="dg-btag">' . esc($quest['bosses'][0]) . '</span>' : '') . '</span>' : '') .
      '</summary><div class="dg-qb">' . $body . '</div></details>';
  }
  return '<section class="dg-boss dg-qcol"><h3>Quests <small>' . count($quests) . ' · open one for its details</small></h3>' . $filter . $html . '</section>';
}

const DUNGEON_PAGE_SCRIPT = <<<'HTML'
<script>
(function(){
  var page=document.querySelector('.dg-page');if(!page)return;
  var cards=[].slice.call(page.querySelectorAll('.dg-card')),stops=[].slice.call(page.querySelectorAll('.dg-route a'));
  if(!cards.length)return;
  page.classList.add('dg-js');
  var cur=0;
  function show(i,scroll,user){
    cur=(i+cards.length)%cards.length;
    cards.forEach(function(c,k){c.classList.toggle('on',k===cur)});
    stops.forEach(function(s,k){s.classList.toggle('on',k===cur);if(k===cur)s.setAttribute('aria-current','true');else s.removeAttribute('aria-current')});
    if(user&&history.replaceState)history.replaceState(null,'','#'+cards[cur].id);
    if(scroll)cards[cur].scrollIntoView({block:'nearest',behavior:'smooth'});
  }
  function byId(id){for(var k=0;k<cards.length;k++)if(cards[k].id===id)return k;return -1}
  page.addEventListener('click',function(e){
    var a=e.target.closest('.dg-route a,a[data-boss]');
    if(a){var k=byId(a.getAttribute('href').slice(1));if(k>-1){e.preventDefault();show(k,a.hasAttribute('data-boss'),true);}return}
    var b=e.target.closest('.dg-step button');if(b){show(cur+(+b.getAttribute('data-step')),false,true);return}
    var f=e.target.closest('.dg-qf button');
    if(f){var v=f.getAttribute('data-f');
      [].forEach.call(page.querySelectorAll('.dg-qf button'),function(x){x.classList.toggle('on',x===f)});
      [].forEach.call(page.querySelectorAll('.dg-q'),function(q){var g=q.getAttribute('data-f');q.hidden=!!v&&g!==v&&g!=='Both'});}
  });
  var start=byId(decodeURIComponent(location.hash.slice(1)));
  if(start<0)start=0;
  show(start,false);
})();
</script>
HTML;

const DUNGEON_TIERS = [
  ['novice', 'I', 'Novice Delves', 'Your first dungeons: the caverns, mines and ruins below the capitals.', 0, 20],
  ['journeyman', 'II', 'Journeyman Halls', 'Keeps, flooded ruins and the first wings of the Scarlet Monastery.', 20, 35],
  ['veteran', 'III', 'Veteran Expeditions', 'Lost cities, desert temples and the deep places of the world.', 35, 50],
  ['endgame', 'IV', 'Endgame Strongholds', 'Blackrock Mountain, Dire Maul, Stratholme and Scholomance.', 50, 999],
];
const DUNGEON_GLOW = [
  'hall-of-thanes' => '#e0a040', 'ruins-of-lordaeron' => '#7fbf3a', 'excavation-site-wetlands' => '#3ac0b0',
  'city-of-dalaran' => '#b060ff', 'the-drowned-city' => '#3a90d0', 'kroldok-stronghold' => '#e05a20',
  'alcaz-prison' => '#8aa0c0', 'blackmaw-hold' => '#c03030', 'shapers-terrace' => '#50d070',
];

function forever_dungeon_levels(array $d): array {
  $p = preg_split('/[-–]/u', (string)$d['level']);
  return [(int)$p[0], (int)end($p)];
}

function forever_dungeon_loot_count(array $d): int {
  return array_sum(array_map(fn($b) => count($b['loot']), $d['bosses'])) + count($d['trash']);
}

/** The dungeon index as the 2008 skin draws it: stats, search, new-in-Forever cards, level tiers and a side rail. */
function forever_dungeons_w8_index(): string {
  $data = forever_dungeon_data();
  $dungeons = array_values(forever_dungeons());
  $new = array_values(array_filter($dungeons, fn($d) => $d['new']));
  $bosses = array_sum(array_map(fn($d) => count($d['bosses']), $dungeons));
  $loot = array_sum(array_map('forever_dungeon_loot_count', $dungeons));
  $tierOf = function (array $d): array {
    $lo = forever_dungeon_levels($d)[0];
    foreach (DUNGEON_TIERS as $t) if ($lo >= $t[4] && $lo < $t[5]) return $t;
    return DUNGEON_TIERS[count(DUNGEON_TIERS) - 1];
  };

  $cards = '';
  foreach ($new as $d)
    $cards .= '<a class="dgx-card' . (($art = forever_dungeon_art($d)) ? ' art' : '') . '" href="' . esc(forever_dungeon_path($d['slug'])) . '" style="--glow:' . (DUNGEON_GLOW[$d['slug']] ?? '#c88f10') . '">' .
      ($art ? '<img class="dgx-card-art" src="' . esc($art) . '" alt="">' : '') .
      '<span class="dgx-card-top"><span>5 player · ' . count($d['bosses']) . ' bosses</span><span class="dgx-badge">New</span></span>' .
      '<img src="' . esc(forever_dungeon_icon($d['icon'], 'large')) . '" alt="" loading="lazy">' .
      '<span class="dgx-card-nm">' . esc($d['name']) . '</span><span class="dgx-card-sub">' . esc($d['location']) . ' · Levels ' . esc($d['level']) . '</span></a>';

  $groups = '';
  foreach (DUNGEON_TIERS as [$key, $num, $name, $blurb]) {
    $members = array_values(array_filter($dungeons, fn($d) => $tierOf($d)[0] === $key));
    if (!$members) continue;
    $range = min(array_map(fn($d) => forever_dungeon_levels($d)[0], $members)) . '–' . max(array_map(fn($d) => forever_dungeon_levels($d)[1], $members));
    $rows = '';
    foreach ($members as $d) {
      $kills = array_sum(array_map(fn($b) => (int)$b['killedBy'], $d['bosses']));
      $names = array_map(fn($b) => $b['name'], $d['bosses']);
      $shown = implode(', ', array_map(fn($x) => '<a href="' . esc(forever_npc_path(forever_npc_slug($x))) . '">' . esc($x) . '</a>', array_slice($names, 0, 3))) .
        (count($names) > 3 ? ' +' . (count($names) - 3) . ' more' : '');
      $search = mb_strtolower(implode(' ', array_merge([$d['name'], $d['location']], $names)));
      $href = esc(forever_dungeon_path($d['slug']));
      $status = $d['lootIncomplete'] ? ['filling', 'Loot list being filled in'] : ['complete', 'Bosses and loot listed'];
      $rows .= '<li class="dgx-row" data-tier="' . $key . '" data-new="' . (int)$d['new'] . '" data-kills="' . $kills . '" data-q="' . esc($search) . '">' .
        '<a class="dgx-ico" href="' . $href . '"><img src="' . esc(forever_dungeon_icon($d['icon'])) . '" alt="" loading="lazy"></a>' .
        '<div class="dgx-nm"><a href="' . $href . '">' . esc($d['name']) . '</a>' . ($d['new'] ? '<span class="dgx-new">New</span>' : '') .
        '<small>' . esc($d['location']) . '</small><small class="dgx-bs">' . $shown . '</small></div>' .
        '<div class="dgx-lv">' . esc($d['level']) . '</div>' .
        '<div class="dgx-n" title="Bosses"><b>' . count($d['bosses']) . '</b></div>' .
        '<div class="dgx-n dgx-loot" title="Loot items"><b>' . forever_dungeon_loot_count($d) . '</b></div>' .
        '<div class="dgx-st"><i class="dot ' . $status[0] . '" title="' . $status[1] . '"></i>' .
        ($kills ? '<span class="dgx-k" title="Boss kills recorded in uploaded journals">' . $kills . '</span>' : '') . '</div></li>';
    }
    $groups .= '<div class="dgx-grp" data-tier="' . $key . '"><div class="dgx-grp-hd"><span class="dgx-seal t-' . $key . '">' . $num . '</span>' .
      '<div><h3>' . esc($name) . '</h3><p>' . esc($blurb) . '</p></div><div class="dgx-grp-meta"><b>Levels ' . $range . '</b>' . count($members) . ' dungeons</div></div>' .
      '<div class="dgx-cols"><span>Dungeon</span><span>Level</span><span>Bosses</span><span class="dgx-loot">Loot</span><span></span></div>' .
      '<ul class="dgx-list">' . $rows . '</ul></div>';
  }

  $tierOptions = implode('', array_map(fn($t) => '<option value="' . $t[0] . '">' . esc($t[2]) . '</option>', DUNGEON_TIERS));
  $biggest = $dungeons;
  usort($biggest, fn($a, $b) => forever_dungeon_loot_count($b) <=> forever_dungeon_loot_count($a));
  $biggestLi = implode('', array_map(fn($d) => '<li><a href="' . esc(forever_dungeon_path($d['slug'])) . '">' . esc($d['name']) . '</a><span class="ct">' .
    forever_dungeon_loot_count($d) . '</span></li>', array_slice($biggest, 0, 5)));
  $s = $data['source'];

  return '<div class="dgx-stats"><div><b>' . count($dungeons) . '</b><span>Dungeons</span></div><div><b>' . count($new) . '</b><span>New in Forever</span></div>' .
    '<div><b>' . js_locale($bosses) . '</b><span>Bosses</span></div><div><b>' . js_locale($loot) . '</b><span>Loot items</span></div></div>' .
    '<div class="dgx-wrap"><div class="dgx-body">' .
    '<form class="section dgx-find" onsubmit="return false"><div class="post-hd"><span class="ico g"></span><h2>Find a Dungeon</h2><span class="sub">Bosses, levels and loot for every instance</span></div>' .
    '<div class="section-bd dgx-find-bd"><label><span>Search</span><input type="search" id="dgx-q" placeholder="Dungeon, boss or zone..."></label>' .
    '<label><span>Level</span><select id="dgx-tier"><option value="">All levels</option>' . $tierOptions . '</select></label>' .
    '<label><span>Show</span><select id="dgx-show"><option value="">All dungeons</option><option value="new">New in Forever</option><option value="kills">With recorded kills</option></select></label>' .
    '<button class="btn-red" type="reset" id="dgx-reset">Clear</button></div></form>' .
    '<div class="section" id="new"><div class="post-hd"><span class="ico p"></span><h2>New in Forever</h2><span class="sub">' . count($new) . ' dungeons you won\'t find in Classic</span></div>' .
    '<div class="section-bd"><div class="dgx-cards">' . $cards . '</div></div></div>' .
    '<div class="section"><div class="post-hd"><span class="ico r"></span><h2>Browse by Level</h2><span class="sub" id="dgx-count">' . count($dungeons) . ' dungeons</span></div>' .
    '<div class="section-bd">' . $groups . '<p class="dgx-empty" id="dgx-empty" hidden>No dungeon matches that search.</p></div></div>' .
    '</div><aside class="dgx-rail">' .
    '<div class="box dgx-know"><h4><i></i>Know Something?</h4><p>Found a drop the journal doesn\'t list? Upload your journal and Journalator log with the WoWCensus companion.</p>' .
    '<a class="btn-red" href="https://discord.gg/kSUGgYDd8V" target="_blank" rel="noopener">Tell us on Discord</a></div>' .
    '<div class="box"><h4><i></i>Biggest Loot Tables</h4><ul class="ql dgx-ql">' . $biggestLi . '</ul></div>' .
    '<div class="box"><h4><i></i>Guide Legend</h4><ul class="ql dgx-legend">' .
    '<li><i class="dot complete"></i><div><b>Complete</b><small>Bosses and loot listed</small></div></li>' .
    '<li><i class="dot filling"></i><div><b>Being filled in</b><small>Some loot still missing</small></div></li>' .
    '<li><span class="dgx-k">5</span><div><b>Kills on record</b><small>From uploaded journals</small></div></li></ul></div>' .
    '<div class="box dgx-know"><h4><i></i>Sources</h4><p>Bosses, levels and loot come from the <b>' . esc($s['addon']) . '</b> add-on by ' . esc($s['author']) .
    ($s['version'] ? ' (version ' . esc($s['version']) . ')' : '') . '. Kill counts come from players\' uploaded journals.</p></div>' .
    '</aside></div>' . DUNGEON_W8_INDEX_SCRIPT;
}

const DUNGEON_W8_INDEX_SCRIPT = <<<'HTML'
<script>
(function(){
  var q=document.getElementById('dgx-q'),tier=document.getElementById('dgx-tier'),show=document.getElementById('dgx-show');
  if(!q)return;
  var rows=[].slice.call(document.querySelectorAll('.dgx-row')),grps=[].slice.call(document.querySelectorAll('.dgx-grp'));
  function apply(){
    var s=q.value.trim().toLowerCase(),t=tier.value,v=show.value,n=0;
    rows.forEach(function(r){
      var ok=(!s||r.getAttribute('data-q').indexOf(s)>-1)&&(!t||r.getAttribute('data-tier')===t)&&(v!=='new'||r.getAttribute('data-new')==='1')&&(v!=='kills'||+r.getAttribute('data-kills')>0);
      r.hidden=!ok;if(ok)n++;
    });
    grps.forEach(function(g){g.hidden=!g.querySelector('.dgx-row:not([hidden])')});
    document.getElementById('dgx-count').textContent=n+' dungeon'+(n===1?'':'s');
    document.getElementById('dgx-empty').hidden=n>0;
  }
  q.addEventListener('input',apply);tier.addEventListener('change',apply);show.addEventListener('change',apply);
  document.getElementById('dgx-reset').addEventListener('click',function(){setTimeout(apply,0)});
})();
</script>
HTML;

const DUNGEON_W8_INDEX_STYLE = <<<'CSS'
.census-banner .banner-overlay{position:absolute;right:12px;bottom:10px;z-index:1}
.dgx-stats{display:flex;width:max-content;background:rgba(0,0,0,.72);border:1px solid #6a5022;box-shadow:inset 0 0 0 1px rgba(255,215,90,.08)}
.dgx-stats div{padding:6px 12px 5px;text-align:center;border-left:1px solid #3a2a12}
.dgx-stats div:first-child{border-left:0}
.dgx-stats b{display:block;font:900 22px/1 'Cinzel',serif;color:var(--gold-lt);text-shadow:0 0 6px rgba(255,170,0,.45)}
.dgx-stats span{display:block;margin-top:3px;font:700 8px Tahoma,Arial,sans-serif;letter-spacing:1px;text-transform:uppercase;color:#cbb88a}
.dgx-wrap{display:grid;grid-template-columns:minmax(0,1fr) 210px;gap:14px;align-items:start}
.dgx-rail .box:first-child{margin-top:0}
.dgx-find-bd{display:grid;grid-template-columns:1.4fr 1fr 1fr auto;gap:10px;align-items:end}
.dgx-find label span{display:block;font:700 9px Tahoma,Arial,sans-serif;letter-spacing:1px;text-transform:uppercase;color:#6a4a20;margin-bottom:3px}
.dgx-find input,.dgx-find select{width:100%;box-sizing:border-box;height:24px;padding:2px 6px;font:11px Tahoma,Arial,sans-serif;color:#2a1600;background:linear-gradient(#fffaf0,#f0e2c0);border:1px solid #9c7c42;box-shadow:inset 0 1px 2px rgba(60,30,0,.35);border-radius:2px}
.w8-framed .dgx-find .btn-red{height:24px}
.dgx-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.dgx-card{position:relative;display:flex;flex-direction:column;justify-content:flex-end;min-height:118px;padding:10px;text-decoration:none;overflow:hidden;border:2px solid #1a1208;outline:1px solid #b08a40;background:radial-gradient(circle at 72% 30%,color-mix(in srgb,var(--glow) 55%,transparent),transparent 58%),linear-gradient(160deg,#20180e,#0c0906 70%);box-shadow:inset 0 0 24px rgba(0,0,0,.8),0 2px 4px rgba(60,30,0,.5)}
.dgx-card:hover{outline-color:#ffd75a;filter:brightness(1.15)}
.dgx-card.art{background:#0c0906}
.dgx-card img.dgx-card-art{position:absolute;inset:0;right:auto;top:0;width:100%;height:100%;object-fit:cover;border:0;box-shadow:none}
.dgx-card.art:after{content:"";position:absolute;inset:0;background:linear-gradient(rgba(0,0,0,.55),rgba(0,0,0,0) 30%,rgba(0,0,0,.15) 50%,rgba(0,0,0,.85))}
.dgx-card.art>span{position:relative;z-index:1}
.dgx-card.art>.dgx-card-top{position:absolute}
.dgx-card.art>img:not(.dgx-card-art){z-index:1}
.dgx-card img:not(.dgx-card-art){position:absolute;right:12px;top:26px;width:44px;height:44px;border:2px ridge #856636;box-shadow:0 0 14px color-mix(in srgb,var(--glow) 70%,transparent),0 2px 4px #000}
.dgx-card-top{position:absolute;left:8px;right:8px;top:7px;display:flex;justify-content:space-between;align-items:center;font:700 8px Tahoma,Arial,sans-serif;letter-spacing:1px;text-transform:uppercase;color:#cbb88a;white-space:nowrap}
.dgx-badge{background:#8a1a08;color:#ffe8c0;border:1px solid #c04020;padding:1px 5px}
.dgx-card-nm{font:700 14px/1.15 'Cinzel',serif;color:#f6e3ae;text-shadow:0 2px 3px #000;padding-right:50px}
.dgx-card-sub{font-size:9px;color:#cbb88a;margin-top:3px}
.dgx-grp{margin:0 0 14px;border:1px solid #9c7c42;background:rgba(201,160,80,.18)}
.dgx-grp:last-of-type{margin-bottom:0}
.dgx-grp-hd{display:flex;align-items:center;gap:10px;padding:8px 10px;background:linear-gradient(rgba(201,160,80,.45),rgba(201,160,80,.2));border-bottom:1px solid #9c7c42}
.w8-framed .dgx-grp-hd h3{margin:0;font:700 15px 'Cinzel',serif;color:#5a2a00}
.dgx-grp-hd p{margin:1px 0 0;font-size:10px;color:#6a4a20}
.dgx-grp-meta{margin-left:auto;text-align:right;font-size:9px;color:#6a4a20;white-space:nowrap}
.dgx-grp-meta b{display:block;font-size:11px;color:#3a1a00}
.dgx-seal{flex:none;width:34px;height:34px;border-radius:50%;display:grid;place-items:center;font:900 13px 'Cinzel',serif;color:#fff3c8;text-shadow:0 1px 2px #000;border:2px solid #2a1600;box-shadow:0 0 0 1px #c9a050,inset 0 2px 4px rgba(255,255,255,.35),0 2px 3px rgba(0,0,0,.5)}
.t-novice{background:radial-gradient(circle at 40% 35%,#9ad07a,#3a6a1a 60%,#132a06)}
.t-journeyman{background:radial-gradient(circle at 40% 35%,#8ab8ff,#2a5aa0 60%,#0a1a38)}
.t-veteran{background:radial-gradient(circle at 40% 35%,#e0a0ff,#7a2fb8 60%,#240838)}
.t-endgame{background:radial-gradient(circle at 40% 35%,#ffc070,#b55a00 60%,#3a1600)}
.dgx-cols,.dgx-row{display:grid;grid-template-columns:30px minmax(0,1fr) 54px 40px 40px 42px;gap:8px;align-items:center}
.dgx-cols{padding:4px 10px;font:700 8px Tahoma,Arial,sans-serif;letter-spacing:1px;text-transform:uppercase;color:#6a4a20;border-bottom:1px solid rgba(156,124,66,.5)}
.dgx-cols span:first-child{grid-column:1/3}
.dgx-cols span:nth-child(n+2){text-align:center}
.dgx-list{list-style:none;margin:0;padding:0}
.dgx-row{padding:5px 10px;border-bottom:1px solid rgba(156,124,66,.35)}
.dgx-row:nth-child(even){background:rgba(255,250,235,.35)}
.dgx-row:last-child{border-bottom:0}
.dgx-row:hover{background:rgba(255,215,90,.22)}
.dgx-ico img{display:block;width:26px;height:26px;border:2px ridge #856636;background:#100d09;box-shadow:0 1px 2px #000}
.w8-framed .dgx-nm a{font:700 11px Tahoma,Arial,sans-serif;color:#7a2a00;text-decoration:none}
.w8-framed .dgx-nm a:hover{text-decoration:underline;color:#a03a00}
.dgx-nm small{display:block;font-size:9px;color:#6a4a20;line-height:1.3}
.dgx-nm small.dgx-bs{color:#8a6a3a;font-style:italic;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.w8-framed .dgx-nm small.dgx-bs a{font:italic 9px Tahoma,Arial,sans-serif;color:#8a5a2a}
.w8-framed .dgx-nm small.dgx-bs a:hover{color:#a03a00}
.dgx-new{display:inline-block;margin-left:5px;padding:0 4px;font:700 8px/12px Tahoma,Arial,sans-serif;letter-spacing:.5px;text-transform:uppercase;color:#fff;background:linear-gradient(#3a7ad0,#1a4a90);border:1px solid #0a2a5a;vertical-align:1px}
.dgx-lv{text-align:center;font-size:10px;color:#3a1a00;font-variant-numeric:tabular-nums}
.dgx-n{text-align:center}
.dgx-n b{display:inline-block;min-width:22px;padding:1px 4px;font:700 10px Tahoma,Arial,sans-serif;color:#f2d483;background:linear-gradient(#4a3418,#2a1c0c);border:1px solid #1a1006;box-shadow:inset 0 1px 0 rgba(255,215,90,.2)}
.dgx-loot b{color:#e8d8b0;background:linear-gradient(#5a4a30,#3a2e1a)}
.dgx-st{display:flex;align-items:center;justify-content:flex-end;gap:5px}
.dgx-wrap .dot{display:inline-block;width:10px;height:10px;border-radius:50%;border:1px solid #1a1006;flex:none}
.dgx-wrap .dot.complete{background:radial-gradient(circle at 40% 35%,#c8ffa0,#3a9a10 55%,#0a2a00)}
.dgx-wrap .dot.filling{background:radial-gradient(circle at 40% 35%,#fff0a0,#c8900a 55%,#3a2400)}
.dgx-k{display:inline-block;min-width:12px;padding:0 3px;font:700 9px/13px Tahoma,Arial,sans-serif;text-align:center;color:#fff;background:#2f6b1f;border:1px solid #143a0a}
.dgx-empty{text-align:center;color:#6a4a20;font-style:italic;margin:10px 0 0}
.dgx-ql li{justify-content:space-between}
.dgx-ql a{flex:1;min-width:0}
.dgx-ql .ct{font:700 9px Tahoma,Arial,sans-serif;color:#f2d483;background:#3a2810;border:1px solid #1a1006;padding:0 4px;min-width:14px;text-align:center}
.dgx-legend li{gap:8px}
.dgx-legend b{display:block;font-size:10px;color:#3a1a00}
.dgx-legend small{display:block;font-size:9px;color:#6a4a20}
.dgx-know p{margin:4px 8px 6px;font-size:10px;line-height:1.4;color:#e8d8b0}
.w8-framed .dgx-know .btn-red{display:block;margin:0 8px;text-align:center;text-decoration:none;color:#ffe8c0}
@media (max-width:900px){.dgx-wrap{grid-template-columns:minmax(0,1fr)}}
@media (max-width:760px){
  .census-banner:has(.banner-overlay){height:206px}
  .census-banner:has(.banner-overlay) .t{bottom:70px}
  .census-banner .banner-overlay{left:8px;right:8px;bottom:8px}
  .dgx-stats{width:auto}
  .dgx-stats div{flex:1;padding:5px 4px}
  .dgx-stats b{font-size:17px}
  .dgx-find-bd{grid-template-columns:1fr 1fr}
  .dgx-find-bd label:first-child{grid-column:1/-1}
  .dgx-cards{grid-template-columns:repeat(2,minmax(0,1fr))}
  .dgx-cols,.dgx-row{grid-template-columns:28px minmax(0,1fr) 46px 34px 30px}
  .dgx-loot{display:none}
  .dgx-grp-hd p{display:none}
}
@media (max-width:440px){.dgx-cards{grid-template-columns:1fr}}
CSS;

/** The plain page (the default skins); the 2008 skin frames its body. */
function forever_dungeons_html(?string $slug, string $stylesheet): ?string {
  $d = $slug !== null ? (forever_dungeons()[$slug] ?? null) : null;
  if ($slug !== null && $d === null) return null;
  $label = seo_label(EDITIONS[DUNGEON_SOURCE]['label']);
  $navigation = edition_nav(DUNGEON_SOURCE, 'dungeons');
  if ($d === null) {
    $title = $label . ' Dungeons';
    $desc = $label . ' dungeons: every boss, its level and its loot, including the dungeons new in Forever.';
  } else {
    $title = $d['name'] . ' – ' . $label . ' Dungeon';
    $desc = $d['name'] . ($d['level'] ? ' (level ' . $d['level'] . ')' : '') . ' in ' . $label . ': ' . count($d['bosses']) . ' bosses, their levels and what they drop.';
  }
  $seo = seo_head($title . ' – WoWCensus', $desc, SITE_ORIGIN . forever_dungeon_path($slug));
  $heading = page_heading('WoWCensus', $navigation, $d['name'] ?? 'Dungeons');
  $css = esc($stylesheet);
  $census_css = CENSUS_STYLESHEET;
  $style = DUNGEON_STYLE;
  $body = forever_dungeons_body($d);
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
