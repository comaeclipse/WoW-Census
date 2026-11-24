<?php
declare(strict_types=1);

/**
 * WoW: Forever combat logs: the overview at /forever/combat-logs/ and one view per class at
 * /forever/combat-logs/<class>/ (the same page, opened on that class).
 *
 * Totals across every imported combat log (tools/import-combatlog-pg.py): player class spell totals
 * (combatlog_class_spells), mobs measured, kills, mob attacks and where they were logged. Uploaders
 * and the players in the logs are never stored, so nothing here names a character.
 */

function forever_combat_logs_path(?string $class = null): string {
  return edition_path(DUNGEON_SOURCE) . 'combat-logs/' . ($class !== null ? $class . '/' : '');
}

/** Class views, slug => [label, class file], in the class pages' order. */
function forever_combat_logs_classes(): array {
  static $out = null;
  if ($out !== null) return $out;
  $out = [];
  foreach (class_profiles()[DUNGEON_SOURCE]['classes'] ?? [] as $cf) $out[class_slug($cf)] = [CLASS_META[$cf][0] ?? $cf, $cf];
  return $out;
}

/** Everything the overview shows, or null before any log is imported. */
function forever_combat_logs_model(): ?array {
  static $model = false;
  if ($model !== false) return $model;
  if (!bestiary_ready()) return $model = null;
  $g = DUNGEON_SOURCE;
  $files = Db::all("SELECT first_event, last_event, events, npcs FROM combatlog_files WHERE source_game = ? ORDER BY first_event", [$g]);
  if (!$files) return $model = null;

  $spells = [];
  if (Db::first("SELECT to_regclass('combatlog_class_spells') IS NOT NULL AS ok")['ok'])
    foreach (Db::all("SELECT class_file, mode, spell_id, name, sum(events) AS events, sum(amount) AS amount, sum(crits) AS crits,
        min(low) AS low, max(high) AS high FROM combatlog_class_spells WHERE source_game = ?
        GROUP BY class_file, mode, spell_id, name ORDER BY sum(amount) DESC, name", [$g]) as $r)
      $spells[] = ['c' => $r['class_file'], 'm' => $r['mode'], 'id' => (int)$r['spell_id'], 'n' => $r['name'], 'ev' => (int)$r['events'],
        'amt' => (int)$r['amount'], 'cr' => (int)$r['crits'], 'lo' => (int)$r['low'], 'hi' => (int)$r['high'],
        'ic' => forever_dungeon_icon(bestiary_spell_icon_name((int)$r['spell_id']), 'large')];

  $mobPath = function (string $name): ?string {
    $slug = forever_npc_slug($name);
    return isset(forever_npcs()[$slug]) || isset(forever_bestiary()[$slug]) ? forever_npc_path($slug) : null;
  };
  $kills = array_map(fn($r) => ['n' => $r['name'], 'd' => (int)$r['deaths'], 'lv' => $r['level'] !== null ? (int)$r['level'] : null, 'url' => $mobPath($r['name'])],
    Db::all("SELECT max(n.name) AS name, sum(n.deaths) AS deaths,
        (SELECT max(l.level) FROM combatlog_npc_levels l WHERE l.source_game = n.source_game AND l.npc_id = n.npc_id) AS level
      FROM combatlog_npc_names n WHERE n.source_game = ? GROUP BY n.source_game, n.npc_id HAVING sum(n.deaths) > 0
      ORDER BY sum(n.deaths) DESC, max(n.name) LIMIT 10", [$g]));
  $hits = array_map(fn($r) => ['mob' => $r['mob'], 'ab' => $r['ability'], 'id' => (int)$r['spell_id'], 'sc' => bestiary_school($r['school'] !== null ? (int)$r['school'] : null),
      'h' => (int)$r['hits'], 'mx' => (int)$r['hi'], 'ic' => forever_dungeon_icon(bestiary_spell_icon_name((int)$r['spell_id']), 'large'), 'url' => $mobPath($r['mob'])],
    Db::all("SELECT n.name AS mob, max(a.ability) AS ability, a.spell_id, bit_or(a.school) AS school, sum(a.hits) AS hits, max(a.max_dmg) AS hi
      FROM combatlog_npc_abilities a
      JOIN (SELECT DISTINCT ON (npc_id) npc_id, name FROM combatlog_npc_names WHERE source_game = ? ORDER BY npc_id, last_seen DESC) n USING (npc_id)
      WHERE a.source_game = ? AND a.hits > 0 GROUP BY n.name, a.spell_id ORDER BY max(a.max_dmg) DESC NULLS LAST, n.name LIMIT 10", [$g, $g]));
  $zones = array_map(fn($r) => ['n' => $r['map_name'], 'npcs' => (int)$r['npcs'], 's' => (int)$r['sightings'], 'url' => forever_npc_path() . '?zone=' . rawurlencode($r['map_name'])],
    Db::all("SELECT map_name, count(DISTINCT npc_id) AS npcs, sum(sightings) AS sightings FROM combatlog_npc_places
      WHERE source_game = ? AND map_name IS NOT NULL GROUP BY map_name ORDER BY sum(sightings) DESC, map_name", [$g]));
  $levels = array_map(fn($r) => ['lv' => (int)$r['level'], 'n' => (int)$r['n']],
    Db::all("SELECT level, count(DISTINCT npc_id) AS n FROM combatlog_npc_levels WHERE source_game = ? GROUP BY level ORDER BY level", [$g]));
  $t = Db::first("SELECT (SELECT count(DISTINCT npc_id) FROM combatlog_npc_names WHERE source_game = ?) AS npcs,
      (SELECT coalesce(sum(deaths), 0) FROM combatlog_npc_names WHERE source_game = ?) AS kills,
      (SELECT count(DISTINCT spell_id) FROM combatlog_npc_abilities WHERE source_game = ? AND spell_id <> 0) AS npc_spells,
      (SELECT count(DISTINCT npc_id) FROM combatlog_npc_levels WHERE source_game = ? AND max_hp > 0) AS with_hp,
      (SELECT count(DISTINCT npc_id) FROM combatlog_npc_levels WHERE source_game = ? AND armor IS NOT NULL) AS with_armor,
      (SELECT count(DISTINCT (npc_id, level)) FROM combatlog_npc_levels WHERE source_game = ?) AS mob_levels,
      (SELECT count(DISTINCT npc_id) FROM combatlog_npc_abilities WHERE source_game = ? AND hits > 0) AS attackers,
      (SELECT coalesce(sum(hits), 0) FROM combatlog_npc_abilities WHERE source_game = ?) AS mob_hits,
      (SELECT coalesce(sum(total_dmg), 0) FROM combatlog_npc_abilities WHERE source_game = ?) AS mob_damage", [$g, $g, $g, $g, $g, $g, $g, $g, $g]) ?? [];

  return $model = [
    'files' => array_map(fn($f) => ['first' => $f['first_event'], 'last' => $f['last_event'], 'events' => (int)$f['events'], 'npcs' => (int)$f['npcs']], $files),
    'totals' => ['npcs' => (int)($t['npcs'] ?? 0), 'kills' => (int)($t['kills'] ?? 0), 'npcSpells' => (int)($t['npc_spells'] ?? 0),
      'withHp' => (int)($t['with_hp'] ?? 0), 'withArmor' => (int)($t['with_armor'] ?? 0), 'mobLevels' => (int)($t['mob_levels'] ?? 0),
      'attackers' => (int)($t['attackers'] ?? 0), 'mobHits' => (int)($t['mob_hits'] ?? 0), 'mobDamage' => (int)($t['mob_damage'] ?? 0),
      'classSpells' => count(array_unique(array_filter(array_column($spells, 'id')))), 'events' => array_sum(array_column($files, 'events'))],
    'spells' => $spells, 'zones' => $zones, 'kills' => $kills, 'hits' => $hits, 'levels' => $levels,
  ];
}

/** Search-engine description, shared by both skins. */
function forever_combat_logs_description(?array $m): string {
  $label = seo_label(EDITIONS[DUNGEON_SOURCE]['label']);
  if (!$m) return "Player combat logs from $label: class abilities, mob kills and the hardest hits.";
  return 'What ' . js_locale(count($m['files'])) . " uploaded combat logs from $label show: damage and healing by class and ability, " .
    js_locale($m['totals']['npcs']) . ' mobs measured, ' . js_locale($m['totals']['kills']) . ' kills and the hardest-hitting mob attacks.';
}

/** The plain page (other skins and the base the 2008 skin rebuilds): a summary and damage by class. */
function forever_combat_logs_html(?string $class, string $stylesheet): ?string {
  if ($class !== null && !isset(forever_combat_logs_classes()[$class])) return null;
  $m = forever_combat_logs_model();
  $label = seo_label(EDITIONS[DUNGEON_SOURCE]['label']);
  if (!$m) {
    $body = '<div class="panel census"><div class="ptitle">Combat Logs</div><p class="hint">No combat logs have been imported yet.</p></div>';
  } else {
    $byClass = [];
    foreach ($m['spells'] as $s) if ($s['m'] === 'PvE') $byClass[$s['c']] = ($byClass[$s['c']] ?? 0) + $s['amt'];
    arsort($byClass);
    $total = array_sum($byClass);
    $rows = '';
    foreach ($byClass as $cf => $amt)
      $rows .= '<tr><td>' . esc(CLASS_META[$cf][0] ?? $cf) . '</td><td class="r">' . js_locale($amt) . '</td><td class="r">' .
        ($total ? number_format(100 * $amt / $total, 1) : '0') . '%</td></tr>';
    $body = '<div class="panel census"><div class="ptitle">Combat Logs</div><p class="hint" style="margin-top:0">' .
      esc(forever_combat_logs_description($m)) . '</p>' .
      '<table class="dg-list bx-t"><thead><tr><th>Class</th><th class="r">Damage</th><th class="r">Share</th></tr></thead><tbody>' . $rows .
      '</tbody></table><p class="dg-credit">' . bestiary_note() . ' See the <a href="' . esc(forever_npc_path()) . '">bestiary</a> for every mob.</p></div>';
  }
  if ($class === null)
    return bestiary_page("Combat Logs – $label", forever_combat_logs_description($m), forever_combat_logs_path(), 'Combat Logs', $body, $stylesheet, 'combat-logs');
  [$name] = forever_combat_logs_classes()[$class];
  return bestiary_page("$name Combat Logs – $label", "$name damage, healing and abilities in $label, from players' uploaded combat logs.",
    forever_combat_logs_path($class), "$name Combat Logs", $body, $stylesheet, 'combat-logs');
}
