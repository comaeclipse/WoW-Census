<?php
// WoW: Forever character pages: /forever/characters/<region>/<slug>/.
//
// Each page shows one character's Battle.net Armory snapshot (gear, talents, professions,
// PvP, stats, reputations) as stored by tools/sync-forever-armory.py in forever_armory_*.
// The Armory reflects the character's last logout, not an in-game sighting, and is never
// counted in the census.

declare(strict_types=1);

const FOREVER_CHARACTER_REGIONS = ['us', 'eu', 'kr'];
const FOREVER_CLASS_FILES = [1 => 'WARRIOR', 2 => 'PALADIN', 3 => 'HUNTER', 4 => 'ROGUE', 5 => 'PRIEST', 7 => 'SHAMAN', 8 => 'MAGE', 9 => 'WARLOCK', 11 => 'DRUID'];
const FOREVER_RULESET_TYPES = ['Normal' => 'PvE', 'PvP' => 'PvP', 'Roleplay' => 'RP', 'Roleplay PvP' => 'RP-PvP'];
const FOREVER_GEAR_SLOTS = ['HEAD', 'NECK', 'SHOULDER', 'BACK', 'CHEST', 'SHIRT', 'TABARD', 'WRIST', 'HANDS', 'WAIST', 'LEGS', 'FEET',
  'FINGER_1', 'FINGER_2', 'TRINKET_1', 'TRINKET_2', 'MAIN_HAND', 'OFF_HAND', 'RANGED'];
const FOREVER_QUALITY = ['POOR' => 0, 'COMMON' => 1, 'UNCOMMON' => 2, 'RARE' => 3, 'EPIC' => 4, 'LEGENDARY' => 5, 'ARTIFACT' => 6, 'HEIRLOOM' => 7];

function forever_character_path(string $region, string $slug): string {
  return '/forever/characters/' . $region . '/' . rawurlencode($slug) . '/';
}

/** The stored profile row and its newest snapshot, or null (unknown character, or no Armory tables here). */
function forever_character_rows(string $region, string $slug): ?array {
  try {
    $p = Db::first('SELECT * FROM forever_armory_profiles WHERE region=? AND lower(slug)=lower(?) ORDER BY fetched_at DESC LIMIT 1', [$region, $slug]);
    if (!$p) return null;
    $s = Db::first('SELECT * FROM forever_armory_snapshots WHERE region=? AND character_id=? ORDER BY snapshot_at DESC LIMIT 1',
      [$region, $p['character_id']]);
  } catch (PDOException $e) {
    if ($e->getCode() === '42P01') return null;
    throw $e;
  }
  return $s ? ['profile' => $p, 'snapshot' => $s] : null;
}

/** Characters with an Armory page, keyed "region\tlowercase full name" => slug, for linking rosters. */
function forever_character_slugs(string $region, array $names): array {
  $names = array_values(array_unique(array_filter(array_map(fn($n) => mb_strtolower((string)$n), $names))));
  if (!$names) return [];
  $out = [];
  try {
    foreach (array_chunk($names, 500) as $chunk) {
      $marks = implode(',', array_fill(0, count($chunk), '?'));
      foreach (Db::all("SELECT lower(full_name) n, slug FROM forever_armory_profiles WHERE region=? AND lower(full_name) IN ($marks)", [$region, ...$chunk]) as $r)
        $out[$r['n']] = $r['slug'];
    }
  } catch (PDOException $e) {
    if ($e->getCode() !== '42P01') throw $e;
  }
  return $out;
}

function forever_json(?string $raw): array {
  if ($raw === null || $raw === '') return [];
  $v = json_decode($raw, true);
  return is_array($v) ? $v : [];
}

/** The page model for one character, or null. */
function forever_character_model(string $region, string $slug): ?array {
  if (!in_array($region, FOREVER_CHARACTER_REGIONS, true)) return null;
  $rows = forever_character_rows($region, $slug);
  if (!$rows) return null;
  ['profile' => $p, 'snapshot' => $s] = $rows;
  $profile = forever_json($s['profile_json']);
  $cf = FOREVER_CLASS_FILES[(int)$p['class_id']] ?? null;

  $icons = (array)item_cache('item-icons', 'forever') + (array)item_cache('item-icons', 'classic');
  $gear = [];
  foreach (forever_json($s['equipment_json'])['equipped_items'] ?? [] as $it) {
    $id = (int)($it['item']['id'] ?? 0);
    $lines = [];
    if (!empty($it['armor']['display']['display_string'])) $lines[] = $it['armor']['display']['display_string'];
    foreach ($it['stats'] ?? [] as $st) if (!empty($st['display']['display_string'])) $lines[] = $st['display']['display_string'];
    foreach ($it['spells'] ?? [] as $sp) if (!empty($sp['description'])) $lines[] = $sp['description'];
    $gear[$it['slot']['type'] ?? ''] = [
      'slot' => $it['slot']['name'] ?? '', 'id' => $id, 'name' => $it['name'] ?? ('Item ' . $id),
      'quality' => FOREVER_QUALITY[$it['quality']['type'] ?? ''] ?? 1, 'ilvl' => ($it['level']['value'] ?? 0) > 1 ? (int)$it['level']['value'] : null,
      'type' => trim(($it['inventory_type']['name'] ?? '') . (isset($it['item_subclass']['name']) && $it['item_subclass']['name'] !== 'Miscellaneous' ? ' · ' . $it['item_subclass']['name'] : '')),
      'icon' => $icons[$id] ?? $icons[(string)$id] ?? null, 'lines' => $lines,
      'enchants' => array_values(array_filter(array_map(fn($e) => $e['display_string'] ?? null, $it['enchantments'] ?? []))),
    ];
  }
  $ordered = [];
  foreach (FOREVER_GEAR_SLOTS as $slot) if (isset($gear[$slot])) $ordered[] = $gear[$slot];

  // Talents: the active specialization group's three trees.
  $groups = forever_json($s['specializations_json'])['specialization_groups'] ?? [];
  $active = null;
  foreach ($groups as $g) if (!empty($g['is_active'])) $active = $g;
  $active ??= $groups[0] ?? null;
  $trees = [];
  foreach ($active['specializations'] ?? [] as $spec) {
    $talents = [];
    foreach ($spec['talents'] ?? [] as $t)
      $talents[] = ['name' => $t['talent']['name'] ?? ($t['spell_tooltip']['spell']['name'] ?? ''), 'rank' => (int)($t['talent_rank'] ?? 0),
        'desc' => $t['spell_tooltip']['description'] ?? ''];
    $trees[] = ['name' => $spec['specialization_name'] ?? ($spec['specialization']['name'] ?? ''), 'points' => (int)($spec['spent_points'] ?? 0), 'talents' => $talents];
  }
  $mainTree = $trees ? array_reduce($trees, fn($a, $b) => $a === null || $b['points'] > $a['points'] ? $b : $a) : null;

  $prof = forever_json($s['professions_json']);
  $profs = [];
  foreach (['primaries' => 'Primary', 'secondaries' => 'Secondary'] as $key => $kind)
    foreach ($prof[$key] ?? [] as $pr) {
      $tier = $pr['tiers'][0] ?? [];
      $profs[] = ['name' => $pr['profession']['name'] ?? ($tier['tier']['name'] ?? ''), 'kind' => $kind,
        'skill' => (int)($tier['skill_points'] ?? 0), 'max' => (int)($tier['max_skill_points'] ?? 0),
        'recipes' => count($tier['known_recipes'] ?? [])];
    }

  $pvpRaw = forever_json($s['pvp_summary_json']);
  $pvp = ['rank' => (int)($pvpRaw['pvp_rank'] ?? 0), 'hks' => (int)($pvpRaw['honorable_kills'] ?? 0), 'maps' => []];
  foreach ($pvpRaw['pvp_map_statistics'] ?? [] as $m)
    $pvp['maps'][] = ['name' => $m['world_map']['name'] ?? '', 'played' => (int)($m['match_statistics']['played'] ?? 0),
      'won' => (int)($m['match_statistics']['won'] ?? 0), 'lost' => (int)($m['match_statistics']['lost'] ?? 0)];

  $st = forever_json($s['statistics_json']);
  $eff = fn($k) => isset($st[$k]['effective']) ? (int)$st[$k]['effective'] : null;
  $pctOf = fn($k) => isset($st[$k]['value']) ? round((float)$st[$k]['value'], 2) : null;
  $stats = [
    'Base' => array_filter(['Health' => isset($st['health']) ? (int)$st['health'] : null,
      ($st['power_type']['name'] ?? 'Power') => isset($st['power']) ? (int)$st['power'] : null,
      'Strength' => $eff('strength'), 'Agility' => $eff('agility'), 'Stamina' => $eff('stamina'),
      'Intellect' => $eff('intellect'), 'Spirit' => $eff('spirit'), 'Armor' => $eff('armor')], fn($v) => $v !== null),
    'Melee & Ranged' => array_filter(['Attack power' => isset($st['attack_power']) ? (int)$st['attack_power'] : null,
      'Melee crit' => ($v = $pctOf('melee_crit')) !== null ? $v . '%' : null, 'Ranged crit' => ($v = $pctOf('ranged_crit')) !== null ? $v . '%' : null,
      'Dodge' => ($v = $pctOf('dodge')) !== null ? $v . '%' : null, 'Parry' => ($v = $pctOf('parry')) !== null ? $v . '%' : null,
      'Block' => ($v = $pctOf('block')) !== null ? $v . '%' : null], fn($v) => $v !== null),
    'Spell' => array_filter(['Spell power' => isset($st['spell_power']) ? (int)$st['spell_power'] : null,
      'Healing' => isset($st['spell_healing']) ? (int)$st['spell_healing'] : null,
      'Spell crit' => ($v = $pctOf('spell_crit')) !== null ? $v . '%' : null,
      'Mana regen' => isset($st['mana_regen']) ? round((float)$st['mana_regen'], 1) : null], fn($v) => $v !== null),
    'Resistances' => array_filter(['Fire' => $eff('fire_resistance'), 'Nature' => $eff('nature_resistance'), 'Frost' => $eff('frost_resistance'),
      'Shadow' => $eff('shadow_resistance'), 'Arcane' => $eff('arcane_resistance')], fn($v) => $v !== null),
  ];

  $reps = [];
  foreach (forever_json($s['reputations_json'])['reputations'] ?? [] as $r)
    $reps[] = ['name' => $r['faction']['name'] ?? '', 'standing' => $r['standing']['name'] ?? '', 'tier' => (int)($r['standing']['tier'] ?? 0),
      'value' => (int)($r['standing']['value'] ?? 0), 'max' => (int)($r['standing']['max'] ?? 0), 'raw' => (int)($r['standing']['raw'] ?? 0)];
  usort($reps, fn($a, $b) => $b['raw'] <=> $a['raw'] ?: strcmp($a['name'], $b['name']));

  $media = [];
  foreach (forever_json($s['character_media_json'])['assets'] ?? [] as $a) $media[$a['key']] = $a['value'];
  $mounts = array_values(array_filter(array_map(fn($m) => $m['mount']['name'] ?? null, forever_json($s['mounts_json'])['mounts'] ?? [])));
  $titles = array_values(array_filter(array_map(fn($t) => $t['name'] ?? null, forever_json($s['titles_json'])['titles'] ?? [])));
  $pets = array_map(fn($pt) => ['name' => $pt['name'] ?? '', 'level' => $pt['level'] ?? null, 'creature' => $pt['creature']['name'] ?? '',
    'active' => !empty($pt['is_active'])], forever_json($s['hunter_pets_json'])['hunter_pets'] ?? []);

  // The census guild page, when the census has seen this guild in the same region, realm type and faction.
  $guildUrl = null;
  if ($p['guild_name'] && ($type = FOREVER_RULESET_TYPES[$p['ruleset_name'] ?? ''] ?? null) && in_array($p['faction'], ['Alliance', 'Horde'], true)) {
    $gid = guild_hash_id('forever', "forever:$region:$type:{$p['faction']}", $p['guild_name']);
    try {
      if (Db::first('SELECT 1 x FROM census_guilds WHERE source_game=? AND id=? LIMIT 1', ['forever', $gid])) $guildUrl = guild_path('forever', $gid);
    } catch (PDOException $e) {
      if (!in_array($e->getCode(), ['42P01', '42703'], true)) throw $e;
    }
  }

  $ilvls = array_filter(array_map(fn($g) => $g['ilvl'], array_filter($ordered, fn($g) => !in_array($g['slot'], ['Shirt', 'Tabard'], true))));
  return [
    'region' => $region, 'slug' => $p['slug'], 'name' => $p['full_name'], 'first' => $p['name'], 'level' => $p['level'] !== null ? (int)$p['level'] : null,
    'race' => $p['race_name'], 'class' => $p['class_name'], 'cf' => $cf, 'faction' => $p['faction'], 'gender' => $p['gender'],
    'guild' => $p['guild_name'], 'guildUrl' => $guildUrl, 'ruleset' => FOREVER_RULESET_TYPES[$p['ruleset_name'] ?? ''] ?? $p['ruleset_name'],
    'ilvl' => $p['equipped_item_level'] !== null ? (int)$p['equipped_item_level'] : ($ilvls ? (int)round(array_sum($ilvls) / count($ilvls)) : null),
    'achievementPoints' => (int)($p['achievement_points'] ?? 0), 'achievements' => count(forever_json($s['achievements_json'])['achievements'] ?? []),
    'lastLogin' => $p['last_login_at'] ? strtotime((string)$p['last_login_at']) : null, 'fetched' => strtotime((string)$s['snapshot_at']),
    'title' => $profile['active_title']['display_string'] ?? null,
    'render' => $media['main-raw'] ?? null, 'avatar' => $media['avatar'] ?? null,
    'gear' => $ordered, 'trees' => $trees, 'build' => implode(' / ', array_map(fn($t) => $t['points'], $trees)), 'mainTree' => $mainTree['name'] ?? null,
    'professions' => $profs, 'pvp' => $pvp, 'stats' => array_filter(array_map(fn($g) => array_filter($g, fn($v) => $v !== 0 && $v !== 0.0 && $v !== '0%'), $stats)), 'reputations' => $reps,
    'mounts' => $mounts, 'titles' => $titles, 'pets' => $pets,
  ];
}

function forever_character_description(array $c): string {
  $bits = array_filter([$c['level'] ? 'Level ' . $c['level'] : null, $c['race'], $c['class']]);
  $d = $c['name'] . ' is a ' . implode(' ', $bits) . ' on WoW: Forever (' . strtoupper($c['region']) . ' ' . $c['ruleset'] . ')';
  if ($c['guild']) $d .= ' in <' . $c['guild'] . '>';
  return $d . '. Gear, talents, professions and PvP from the Battle.net Armory.';
}

/** The plain page (the current and other skins). The 2008 skin draws its own. */
function forever_character_html(array $c): string {
  $title = $c['name'] . ' – ' . implode(' ', array_filter([$c['level'] ? 'Level ' . $c['level'] : null, $c['race'], $c['class']])) . ' – WoW: Forever';
  $gear = '';
  foreach ($c['gear'] as $g)
    $gear .= '<tr><td>' . esc($g['slot']) . '</td><td><a href="' . esc(forever_item_path($g['id'])) . '">' . esc($g['name']) . '</a>' .
      ($g['enchants'] ? '<br><small>' . esc(implode('; ', $g['enchants'])) . '</small>' : '') . '</td><td>' . esc($g['ilvl'] ?? '') . '</td></tr>';
  $trees = '';
  foreach ($c['trees'] as $t)
    $trees .= '<li>' . esc($t['name']) . ': ' . esc($t['points']) . ' points' . ($t['talents'] ? ' (' . esc(implode(', ', array_map(fn($x) => $x['name'] . ' ' . $x['rank'], $t['talents']))) . ')' : '') . '</li>';
  $profs = '';
  foreach ($c['professions'] as $p) $profs .= '<li>' . esc($p['name']) . ' ' . esc($p['skill']) . '/' . esc($p['max']) . '</li>';
  return localize_content('<!doctype html><html lang="en"><head><meta charset="utf-8">' .
    '<meta name="viewport" content="width=device-width,initial-scale=1">' .
    seo_head($title . ' – WoWCensus', forever_character_description($c), SITE_ORIGIN . forever_character_path($c['region'], $c['slug'])) .
    '<link rel="stylesheet" href="/' . stylesheet()['name'] . '"></head><body><main class="wrap">' .
    '<h1 class="iname">' . esc($c['name']) . '</h1><p>' . esc(forever_character_description($c)) . '</p>' .
    '<h2>Gear</h2><table><thead><tr><th>Slot</th><th>Item</th><th>Item level</th></tr></thead><tbody>' . $gear . '</tbody></table>' .
    '<h2>Talents ' . esc($c['build']) . '</h2><ul>' . $trees . '</ul>' .
    '<h2>Professions</h2><ul>' . $profs . '</ul>' .
    '<h2>PvP</h2><p>' . esc(number_format($c['pvp']['hks'])) . ' honorable kills</p>' .
    '</main></body></html>' . "\n");
}
