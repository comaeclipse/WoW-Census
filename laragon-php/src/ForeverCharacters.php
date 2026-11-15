<?php
// WoW: Forever character pages: /forever/character/<12-digit id>/ (the other editions use the same form).
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

function forever_character_path(string $id): string {
  return '/forever/character/' . $id . '/';
}

/**
 * The stored profile row and its newest snapshot, or null (unknown character, or no Armory tables here).
 * public_id is the realm-free page id of /<edition>/character/<id>/: guild_hash_id('forever', region,
 * lowercase full name), stored by tools/sync-forever-armory.py.
 */
function forever_character_rows(string $id): ?array {
  try {
    $p = Db::first('SELECT * FROM forever_armory_profiles WHERE public_id=? ORDER BY fetched_at DESC LIMIT 1', [$id]);
    if (!$p) return null;
    $s = Db::first('SELECT * FROM forever_armory_snapshots WHERE region=? AND character_id=? ORDER BY snapshot_at DESC LIMIT 1',
      [$p['region'], $p['character_id']]);
  } catch (PDOException $e) {
    if (in_array($e->getCode(), ['42P01', '42703'], true)) return null;
    throw $e;
  }
  return $s ? ['profile' => $p, 'snapshot' => $s] : null;
}

/** The page id of an old /forever/characters/<region>/<slug>/ link, for its redirect. */
function forever_character_id_for_slug(string $region, string $slug): ?string {
  try {
    $r = Db::first('SELECT public_id FROM forever_armory_profiles WHERE region=? AND lower(slug)=lower(?) ORDER BY fetched_at DESC LIMIT 1', [$region, $slug]);
  } catch (PDOException $e) {
    if (in_array($e->getCode(), ['42P01', '42703'], true)) return null;
    throw $e;
  }
  return $r['public_id'] ?? null;
}

/** Page ids of the characters with an Armory page, keyed by lowercase full name, for linking rosters. */
function forever_character_ids(string $region, array $names): array {
  $names = array_values(array_unique(array_filter(array_map(fn($n) => mb_strtolower((string)$n), $names))));
  if (!$names) return [];
  $out = [];
  try {
    foreach (array_chunk($names, 500) as $chunk) {
      $marks = implode(',', array_fill(0, count($chunk), '?'));
      foreach (Db::all("SELECT lower(full_name) n, public_id FROM forever_armory_profiles
          WHERE region=? AND public_id IS NOT NULL AND lower(full_name) IN ($marks)", [$region, ...$chunk]) as $r)
        $out[$r['n']] = $r['public_id'];
    }
  } catch (PDOException $e) {
    if (!in_array($e->getCode(), ['42P01', '42703'], true)) throw $e;
  }
  return $out;
}

/** Any edition's character page model by page id: WoW: Forever's own, or the other editions' (ArmoryCharacters.php). */
function edition_character_model(string $source, string $id): ?array {
  return $source === 'forever' ? forever_character_model($id) : armory_character_model($source, $id);
}

function edition_character_html(array $c): string {
  return ($c['source'] ?? 'forever') === 'forever' ? forever_character_html($c) : armory_character_html($c);
}

function forever_json(?string $raw): array {
  if ($raw === null || $raw === '') return [];
  $v = json_decode($raw, true);
  return is_array($v) ? $v : [];
}

/** The page model for one character, or null. */
/** The newest Armory copy of an item seen equipped on a Forever character (tools/sync-forever-armory.py), or null. */
function forever_armory_item(int $id): ?array {
  try {
    $row = Db::first('SELECT item_json FROM forever_armory_items WHERE item_id=?', [$id]);
  } catch (PDOException $e) {
    if ($e->getCode() === '42P01') return null;
    throw $e;
  }
  if (!$row) return null;
  $it = forever_json($row['item_json']);
  $media = (array)item_cache('item-icons', 'forever-media');
  $sub = $it['item_subclass']['name'] ?? '';
  $tip = ['ilvl' => ($it['level']['value'] ?? 0) > 1 ? (int)$it['level']['value'] : null,
    'binding' => $it['binding']['name'] ?? null, 'bop' => ($it['binding']['type'] ?? '') === 'ON_ACQUIRE',
    'unique' => $it['unique_equipped'] ?? null, 'slot' => $it['inventory_type']['name'] ?? null,
    'type' => $sub !== '' && empty($it['is_subclass_hidden']) && $sub !== 'Miscellaneous' ? $sub : null,
    'armor' => $it['armor']['display']['display_string'] ?? null,
    'weapon' => isset($it['weapon']['damage']['display_string']) ? [$it['weapon']['damage']['display_string'], $it['weapon']['attack_speed']['display_string'] ?? '', $it['weapon']['dps']['display_string'] ?? ''] : null,
    'stats' => [], 'spells' => [], 'requires' => $it['requirements']['level']['display_string'] ?? null,
    'classes' => $it['requirements']['playable_classes']['display_string'] ?? null,
    'sell' => ($it['sell_price']['value'] ?? 0) > 0 ? (int)$it['sell_price']['value'] : null];
  foreach ($it['stats'] ?? [] as $st) if (!empty($st['display']['display_string'])) $tip['stats'][] = [$st['display']['display_string'], !empty($st['is_equip_bonus'])];
  foreach ($it['spells'] ?? [] as $sp) if (!empty($sp['description'])) $tip['spells'][] = $sp['description'];
  return ['name' => $it['name'] ?? ('Item ' . $id), 'quality' => FOREVER_QUALITY[$it['quality']['type'] ?? ''] ?? 1,
    'cat' => $it['inventory_type']['name'] ?? ($it['item_subclass']['name'] ?? null), 'tip' => $tip,
    'iconUrl' => isset($media[(string)$id]) ? 'https://render.worldofwarcraft.com/forever-eu/icons/56/' . $media[(string)$id] . '.jpg' : null];
}

/**
 * Who wears each item on the Armory characters' newest snapshots, built in one pass and cached in APCu until a new
 * profile is fetched: '_chars' => n, '_class' => [faction => [class_id => n]], and per item id
 * [characters, classes, races, factions, regions, levels, item levels, slots, paired item ids (rings and trinkets)].
 */
function forever_armory_wear_index(): array {
  static $index = null;
  if ($index !== null) return $index;
  try {
    $ver = (string)(Db::uncached('SELECT max(fetched_at) v FROM forever_armory_profiles')[0]['v'] ?? '');
  } catch (PDOException $e) {
    if ($e->getCode() === '42P01') return $index = [];
    throw $e;
  }
  $key = 'ml:forever-armory-wear:' . $ver;
  $apcu = getenv('ML_APCU_CACHE') === '1' && function_exists('apcu_enabled') && apcu_enabled();
  if ($apcu) { $hit = apcu_fetch($key, $ok); if ($ok) return $index = $hit; }
  $rows = Db::uncached("WITH latest AS (SELECT DISTINCT ON (region, character_id) region, character_id, equipment_json
        FROM forever_armory_snapshots WHERE equipment_json IS NOT NULL ORDER BY region, character_id, snapshot_at DESC)
      SELECT l.region, l.character_id ch, p.class_id, p.race_name, p.faction, p.level, p.equipped_item_level ilvl,
        (e->'item'->>'id')::int item, e->'slot'->>'type' slot
      FROM latest l JOIN forever_armory_profiles p USING (region, character_id)
      CROSS JOIN LATERAL jsonb_array_elements(l.equipment_json->'equipped_items') e
      ORDER BY l.region, l.character_id");
  $index = ['_chars' => 0, '_class' => []];
  $pairOf = ['FINGER_1' => 'FINGER_2', 'FINGER_2' => 'FINGER_1', 'TRINKET_1' => 'TRINKET_2', 'TRINKET_2' => 'TRINKET_1'];
  $flush = function (array $ch) use (&$index, $pairOf) {
    if (!$ch) return;
    $p = $ch['p'];
    $index['_chars']++;
    $index['_class'][$p['faction']][(int)$p['class_id']] = ($index['_class'][$p['faction']][(int)$p['class_id']] ?? 0) + 1;
    $seen = [];
    foreach ($ch['slots'] as $slot => $item) {
      $e = &$index[$item];
      $e ??= [0, [], [], [], [], [], [], [], []];
      $e[7][$slot] = ($e[7][$slot] ?? 0) + 1;
      if (isset($pairOf[$slot]) && ($other = $ch['slots'][$pairOf[$slot]] ?? null) !== null && $other !== $item) $e[8][$other] = ($e[8][$other] ?? 0) + 1;
      if (isset($seen[$item])) { unset($e); continue; }
      $seen[$item] = true;
      $e[0]++;
      foreach ([1 => (int)$p['class_id'], 2 => $p['race_name'], 3 => $p['faction'], 4 => $p['region'], 5 => (int)$p['level'], 6 => $p['ilvl'] === null ? null : (int)$p['ilvl']] as $k => $v)
        if ($v !== null && $v !== '') $e[$k][$v] = ($e[$k][$v] ?? 0) + 1;
      unset($e);
    }
  };
  $cur = null; $ch = [];
  foreach ($rows as $r) {
    $id = $r['region'] . ':' . $r['ch'];
    if ($id !== $cur) { $flush($ch); $cur = $id; $ch = ['p' => $r, 'slots' => []]; }
    if ($r['item']) $ch['slots'][$r['slot']] = (int)$r['item'];
  }
  $flush($ch);
  if ($apcu) apcu_store($key, $index, 24 * 3600);
  return $index;
}

/** One item's Armory wearers for its page (forever_armory_wear_index()), or null when no Armory character wears it. */
function forever_armory_wearers(int $id, ?int $itemLevel): ?array {
  $index = forever_armory_wear_index();
  $e = $index[$id] ?? null;
  if ($e === null) return null;
  [$n, $classes, $races, $factions, $regions, $levels, $ilvls, $slots, $pairs] = $e;
  arsort($classes); arsort($races); arsort($factions); arsort($regions); arsort($levels); arsort($pairs);
  // "Of class" compares with every Armory character of that class in the factions that wear it.
  $pool = [];
  foreach (array_keys($factions) as $f) foreach ($index['_class'][$f] ?? [] as $cid => $c) $pool[$cid] = ($pool[$cid] ?? 0) + $c;
  $classRows = [];
  foreach ($classes as $cid => $c) {
    $cf = FOREVER_CLASS_FILES[$cid] ?? null;
    $classRows[] = ['name' => CLASS_META[$cf][0] ?? ('Class ' . $cid), 'file' => strtolower((string)$cf), 'color' => '#' . (CLASS_META[$cf][1] ?? 'c8b898'),
      'n' => $c, 'of' => !empty($pool[$cid]) ? round(100 * $c / $pool[$cid]) : null, 'pool' => $pool[$cid] ?? 0];
  }
  $top = null;
  foreach ($classRows as $c) if ($c['of'] !== null && $c['n'] >= max(5, $n * .05) && (!$top || $c['of'] > $top['of'])) $top = $c;
  // Item-level histogram: up to 10 buckets over the wearers' equipped item levels.
  $hist = null;
  if ($ilvls) {
    $lo = min(array_keys($ilvls)); $hi = max(array_keys($ilvls));
    $w = max(1, (int)ceil(($hi - $lo + 1) / 10));
    $buckets = [];
    for ($b = $lo; $b <= $hi; $b += $w) $buckets[$b] = 0;
    $sum = 0;
    foreach ($ilvls as $lv => $c) { $buckets[$lo + intdiv($lv - $lo, $w) * $w] += $c; $sum += $lv * $c; }
    $hist = ['w' => $w, 'buckets' => $buckets, 'min' => $lo, 'max' => $hi, 'avg' => round($sum / array_sum($ilvls)), 'own' => $itemLevel,
      'above' => $itemLevel !== null ? array_sum(array_filter($ilvls, fn($lv) => $lv > $itemLevel, ARRAY_FILTER_USE_KEY)) : null];
  }
  $names = [];
  $pairs = array_slice($pairs, 0, 8, true);
  if ($pairs) {
    $marks = implode(',', array_fill(0, count($pairs), '?'));
    foreach (Db::all("SELECT item_id, item_json->>'name' n, item_json->'quality'->>'type' q FROM forever_armory_items WHERE item_id IN ($marks)", array_keys($pairs)) as $r)
      $names[(int)$r['item_id']] = [$r['n'], FOREVER_QUALITY[$r['q'] ?? ''] ?? 1];
  }
  $regionNames = ['eu' => 'Europe', 'us' => 'Americas', 'kr' => 'Korea', 'tw' => 'Taiwan'];
  $poolChars = 0;
  foreach (array_keys($factions) as $f) $poolChars += array_sum($index['_class'][$f] ?? []);
  return ['players' => $n, 'chars' => $index['_chars'], 'pool' => $poolChars, 'classes' => $classRows, 'top' => $top,
    'races' => array_map(null, array_keys($races), array_values($races)), 'factions' => $factions,
    'regions' => array_map(fn($r, $c) => [$regionNames[$r] ?? strtoupper((string)$r), $c], array_keys($regions), array_values($regions)),
    'levels' => array_slice($levels, 0, 3, true), 'hist' => $hist,
    'slots' => array_filter(['Top' => ($slots['TRINKET_1'] ?? 0) + ($slots['FINGER_1'] ?? 0), 'Bottom' => ($slots['TRINKET_2'] ?? 0) + ($slots['FINGER_2'] ?? 0)])
      ?: array_filter(['Main hand' => $slots['MAIN_HAND'] ?? 0, 'Off hand' => $slots['OFF_HAND'] ?? 0]),
    'pairs' => array_map(fn($id, $c) => ['id' => $id, 'name' => $names[$id][0] ?? ('Item ' . $id), 'quality' => $names[$id][1] ?? 1, 'n' => $c], array_keys($pairs), array_values($pairs))];
}

function forever_character_model(string $id): ?array {
  if (!preg_match('/^\d{12}$/', $id)) return null;
  $rows = forever_character_rows($id);
  if (!$rows) return null;
  ['profile' => $p, 'snapshot' => $s] = $rows;
  $region = $p['region'];
  $profile = forever_json($s['profile_json']);
  $cf = FOREVER_CLASS_FILES[(int)$p['class_id']] ?? null;

  $icons = (array)item_cache('item-icons', 'forever') + (array)item_cache('item-icons', 'classic');
  // Blizzard icon file ids for items Wowhead's lists lack (tools/fetch-forever-item-media.py).
  $media = (array)item_cache('item-icons', 'forever-media');
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
      'icon' => $icon = $icons[$id] ?? $icons[(string)$id] ?? null, 'lines' => $lines,
      'iconUrl' => $icon !== null ? '/assets/images/wow/icons/large/' . $icon . '.jpg'
        : (isset($media[(string)$id]) ? 'https://render.worldofwarcraft.com/forever-eu/icons/56/' . $media[(string)$id] . '.jpg' : null),
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
    'source' => 'forever', 'id' => $p['public_id'], 'region' => $region, 'slug' => $p['slug'], 'name' => $p['full_name'], 'first' => $p['name'], 'level' => $p['level'] !== null ? (int)$p['level'] : null,
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
    seo_head($title . ' – WoWCensus', forever_character_description($c), SITE_ORIGIN . forever_character_path($c['id'])) .
    '<link rel="stylesheet" href="/' . stylesheet()['name'] . '"></head><body><main class="wrap">' .
    '<h1 class="iname">' . esc($c['name']) . '</h1><p>' . esc(forever_character_description($c)) . '</p>' .
    '<h2>Gear</h2><table><thead><tr><th>Slot</th><th>Item</th><th>Item level</th></tr></thead><tbody>' . $gear . '</tbody></table>' .
    '<h2>Talents ' . esc($c['build']) . '</h2><ul>' . $trees . '</ul>' .
    '<h2>Professions</h2><ul>' . $profs . '</ul>' .
    '<h2>PvP</h2><p>' . esc(number_format($c['pvp']['hks'])) . ' honorable kills</p>' .
    '</main></body></html>' . "\n");
}
