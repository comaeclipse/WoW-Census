<?php
// Cross-edition character pages at /<edition>/character/<stable numeric id>/.
// Discovery comes from WoWCensus. Blizzard profile data only enriches an already-known character.

declare(strict_types=1);

const ARMORY_SOURCES = ['retail', 'mop-classic', 'classic-progression', 'classic', 'sod', 'hardcore'];

function armory_character_id(string $source, string $region, string $realm, string $name): string {
  return guild_hash_id($source, strtolower("$region\n$realm"), mb_strtolower($name));
}

function armory_character_path(string $source, string $id): string {
  return edition_path($source) . 'character/' . $id . '/';
}

function armory_item_path(string $source, int|string $id): string {
  return edition_path($source) . 'item/' . $id;
}

/** A locally stored item observed on an enriched character in this edition. */
function armory_item_model(string $source, int $id): ?array {
  if (!in_array($source, ARMORY_SOURCES, true) || $id < 1) return null;
  try {
    $row = Db::first('SELECT item_json FROM character_armory_items WHERE source_game=? AND item_id=? ORDER BY snapshot_at DESC LIMIT 1', [$source, $id]);
  } catch (PDOException $e) {
    if ($e->getCode() === '42P01') return null;
    throw $e;
  }
  if (!$row) return null;
  $it = forever_json($row['item_json']);
  $branch = EDITIONS[$source]['branch'] ?: 'retail';
  $icons = (array)item_cache('item-icons', $source) + (array)item_cache('item-icons', $branch) + (array)item_cache('item-icons', 'classic');
  $quality = FOREVER_QUALITY[$it['quality']['type'] ?? ''] ?? 1;
  $name = $it['name'] ?? ('Item ' . $id);
  return ['source' => $source, 'id' => $id, 'name' => $name, 'icon' => $icons[$id] ?? $icons[(string)$id] ?? null,
    'cat' => $it['item_subclass']['name'] ?? ($it['inventory_type']['name'] ?? 'Item'), 'crafter' => null,
    'roles' => [], 'taught' => null, 'scans' => [], 'wearers' => null, 'quality' => $quality, 'localOnly' => true];
}

function armory_item_html(string $source, array $item): string {
  $label = seo_label(EDITIONS[$source]['label']);
  $title = $item['name'] . ' – ' . $label . ' Item';
  $canonical = SITE_ORIGIN . armory_item_path($source, $item['id']);
  $icon = $item['icon'] ?: 'inv_misc_questionmark';
  return localize_content('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
    seo_head($title . ' – WoWCensus', $label . ' item profile for ' . $item['name'] . ', observed on an enriched character.', $canonical) .
    '<link rel="stylesheet" href="/' . stylesheet()['name'] . '"></head><body><main class="wrap"><h1 class="iname">' . esc($item['name']) .
    '</h1><p><img src="/assets/images/wow/icons/large/' . esc($icon) . '.jpg" width="56" height="56" alt=""> Item ID ' . $item['id'] .
    '</p></main></body></html>\n');
}

/** Profile ids for names on one census realm. Ambiguous cross-region matches are left unlinked. */
function armory_character_ids(string $source, string $realm, array $names, ?string $region = null): array {
  if (!in_array($source, ARMORY_SOURCES, true)) return [];
  $names = array_values(array_unique(array_filter(array_map(fn($n) => mb_strtolower((string)$n), $names))));
  if (!$names) return [];
  $marks = implode(',', array_fill(0, count($names), '?'));
  try {
    $regionSql = $region !== null ? ' AND region=?' : '';
    $args = [$source, $realm, ...($region !== null ? [$region] : []), ...$names];
    $rows = Db::all("SELECT lower(name) n,public_id FROM character_armory_profiles
      WHERE source_game=? AND lower(realm_name)=lower(?)$regionSql AND lower(name) IN ($marks)", $args);
  } catch (PDOException $e) {
    if ($e->getCode() === '42P01') return [];
    throw $e;
  }
  $out = []; $ambiguous = [];
  foreach ($rows as $r) {
    if (isset($out[$r['n']])) $ambiguous[$r['n']] = true;
    else $out[$r['n']] = $r['public_id'];
  }
  foreach ($ambiguous as $name => $_) unset($out[$name]);
  return $out;
}

function armory_character_rows(string $source, string $id): ?array {
  if (!in_array($source, ARMORY_SOURCES, true) || !preg_match('/^\d{12}$/', $id)) return null;
  try {
    $p = Db::first('SELECT * FROM character_armory_profiles WHERE source_game=? AND public_id=? ORDER BY fetched_at DESC LIMIT 1', [$source, $id]);
    if (!$p) return null;
    $s = Db::first('SELECT * FROM character_armory_snapshots WHERE source_game=? AND region=? AND character_id=? ORDER BY snapshot_at DESC LIMIT 1',
      [$source, $p['region'], $p['character_id']]);
  } catch (PDOException $e) {
    if ($e->getCode() === '42P01') return null;
    throw $e;
  }
  return $s ? ['profile' => $p, 'snapshot' => $s] : null;
}

function armory_character_model(string $source, string $id): ?array {
  $rows = armory_character_rows($source, $id);
  if (!$rows) return null;
  ['profile' => $p, 'snapshot' => $s] = $rows;
  $j = fn(?string $v): array => forever_json($v);
  $edition = EDITIONS[$source];
  $classFiles = FOREVER_CLASS_FILES + [6 => 'DEATHKNIGHT', 10 => 'MONK', 12 => 'DEMONHUNTER', 13 => 'EVOKER'];
  $quality = FOREVER_QUALITY;
  $icons = (array)item_cache('item-icons', $source) + (array)item_cache('item-icons', $edition['branch'] ?: 'retail');

  $gear = [];
  foreach ($j($s['equipment_json'])['equipped_items'] ?? [] as $it) {
    $itemId = (int)($it['item']['id'] ?? 0);
    $lines = [];
    if (!empty($it['armor']['display']['display_string'])) $lines[] = $it['armor']['display']['display_string'];
    foreach ($it['stats'] ?? [] as $st) if (!empty($st['display']['display_string'])) $lines[] = $st['display']['display_string'];
    foreach ($it['spells'] ?? [] as $sp) if (!empty($sp['description'])) $lines[] = $sp['description'];
    $icon = $icons[$itemId] ?? $icons[(string)$itemId] ?? null;
    $gear[] = ['slot' => $it['slot']['name'] ?? '', 'id' => $itemId, 'name' => $it['name'] ?? "Item $itemId",
      'quality' => $quality[$it['quality']['type'] ?? ''] ?? 1, 'ilvl' => isset($it['level']['value']) ? (int)$it['level']['value'] : null,
      'type' => trim(($it['inventory_type']['name'] ?? '') . (!empty($it['item_subclass']['name']) ? ' · ' . $it['item_subclass']['name'] : '')),
      'lines' => $lines, 'iconUrl' => $icon ? '/assets/images/wow/icons/large/' . $icon . '.jpg' : null,
      'enchants' => array_values(array_filter(array_map(fn($e) => $e['display_string'] ?? null, $it['enchantments'] ?? []))),
      'url' => armory_item_path($source, $itemId)];
  }

  $groups = $j($s['specializations_json'])['specialization_groups'] ?? [];
  $active = null;
  foreach ($groups as $g) if (!empty($g['is_active'])) $active = $g;
  $active ??= $groups[0] ?? null;
  $trees = [];
  foreach ($active['specializations'] ?? [] as $spec) {
    $talents = [];
    foreach ($spec['talents'] ?? [] as $t) $talents[] = ['name' => $t['talent']['name'] ?? ($t['spell_tooltip']['spell']['name'] ?? ''),
      'rank' => (int)($t['talent_rank'] ?? 0), 'desc' => $t['spell_tooltip']['description'] ?? ''];
    $trees[] = ['name' => $spec['specialization_name'] ?? ($spec['specialization']['name'] ?? ''),
      'points' => (int)($spec['spent_points'] ?? 0), 'talents' => $talents];
  }
  $mainTree = $trees ? array_reduce($trees, fn($a, $b) => $a === null || $b['points'] > $a['points'] ? $b : $a) : null;

  $profs = [];
  $prof = $j($s['professions_json']);
  foreach (['primaries' => 'Primary', 'secondaries' => 'Secondary'] as $key => $kind) foreach ($prof[$key] ?? [] as $pr) {
    $tier = $pr['tiers'][0] ?? [];
    $profs[] = ['name' => $pr['profession']['name'] ?? ($tier['tier']['name'] ?? ''), 'kind' => $kind,
      'skill' => (int)($tier['skill_points'] ?? 0), 'max' => (int)($tier['max_skill_points'] ?? 0), 'recipes' => count($tier['known_recipes'] ?? [])];
  }
  $rawPvp = $j($s['pvp_summary_json']);
  $pvp = ['rank' => (int)($rawPvp['pvp_rank'] ?? 0), 'hks' => (int)($rawPvp['honorable_kills'] ?? 0), 'maps' => []];
  foreach ($rawPvp['pvp_map_statistics'] ?? [] as $m) $pvp['maps'][] = ['name' => $m['world_map']['name'] ?? '',
    'played' => (int)($m['match_statistics']['played'] ?? 0), 'won' => (int)($m['match_statistics']['won'] ?? 0), 'lost' => (int)($m['match_statistics']['lost'] ?? 0)];

  $st = $j($s['statistics_json']);
  $eff = fn($k) => isset($st[$k]['effective']) ? (int)$st[$k]['effective'] : null;
  $pct = fn($k) => isset($st[$k]['value']) ? round((float)$st[$k]['value'], 2) . '%' : null;
  $stats = ['Base' => ['Health' => $st['health'] ?? null, ($st['power_type']['name'] ?? 'Power') => $st['power'] ?? null,
    'Strength' => $eff('strength'), 'Agility' => $eff('agility'), 'Stamina' => $eff('stamina'), 'Intellect' => $eff('intellect'), 'Armor' => $eff('armor')],
    'Combat' => ['Attack power' => $st['attack_power'] ?? null, 'Melee crit' => $pct('melee_crit'), 'Ranged crit' => $pct('ranged_crit'),
      'Spell power' => $st['spell_power'] ?? null, 'Spell crit' => $pct('spell_crit'), 'Dodge' => $pct('dodge'), 'Parry' => $pct('parry')]];
  $stats = array_filter(array_map(fn($g) => array_filter($g, fn($v) => $v !== null && $v !== 0 && $v !== '0%'), $stats));

  $reps = [];
  foreach ($j($s['reputations_json'])['reputations'] ?? [] as $r) $reps[] = ['name' => $r['faction']['name'] ?? '',
    'standing' => $r['standing']['name'] ?? '', 'tier' => (int)($r['standing']['tier'] ?? 0), 'value' => (int)($r['standing']['value'] ?? 0),
    'max' => (int)($r['standing']['max'] ?? 0), 'raw' => (int)($r['standing']['raw'] ?? 0)];
  usort($reps, fn($a, $b) => $b['raw'] <=> $a['raw']);
  $media = [];
  foreach ($j($s['character_media_json'])['assets'] ?? [] as $a) $media[$a['key']] = $a['value'];
  $profile = $j($s['profile_json']);

  return ['source' => $source, 'edition' => $edition['label'], 'id' => $id, 'region' => $p['region'], 'realm' => $p['realm_name'],
    'slug' => $p['name_slug'], 'name' => $p['name'], 'first' => $p['name'], 'level' => (int)$p['level'], 'race' => $p['race_name'],
    'class' => $p['class_name'], 'cf' => $classFiles[(int)$p['class_id']] ?? null, 'faction' => $p['faction'], 'gender' => $p['gender'],
    'guild' => $p['guild_name'], 'guildUrl' => null, 'ruleset' => $p['realm_name'], 'ilvl' => $p['equipped_item_level'] !== null ? (int)$p['equipped_item_level'] : null,
    'achievementPoints' => (int)($p['achievement_points'] ?? 0), 'achievements' => count($j($s['achievements_json'])['achievements'] ?? []),
    'lastLogin' => $p['last_login_at'] ? strtotime($p['last_login_at']) : null, 'fetched' => strtotime($s['snapshot_at']),
    'title' => $profile['active_title']['display_string'] ?? null, 'render' => $media['main-raw'] ?? null, 'avatar' => $media['avatar'] ?? null,
    'gear' => $gear, 'trees' => $trees, 'build' => implode(' / ', array_column($trees, 'points')), 'mainTree' => $mainTree['name'] ?? null,
    'professions' => $profs, 'pvp' => $pvp, 'stats' => $stats, 'reputations' => $reps, 'mounts' => [], 'titles' => [], 'pets' => []];
}

function armory_character_description(array $c): string {
  $what = trim('Level ' . $c['level'] . ' ' . $c['race'] . ' ' . $c['class']);
  return $c['name'] . ' is a ' . $what . ' on ' . $c['edition'] . '. Gear, talents and profile details from the Battle.net Armory.';
}

function armory_character_html(array $c): string {
  $title = $c['name'] . ' – Level ' . $c['level'] . ' ' . $c['race'] . ' ' . $c['class'] . ' – ' . $c['edition'];
  return localize_content('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
    seo_head($title . ' – WoWCensus', armory_character_description($c), SITE_ORIGIN . armory_character_path($c['source'], $c['id'])) .
    '<link rel="stylesheet" href="/' . stylesheet()['name'] . '"></head><body><main class="wrap"><h1 class="iname">' . esc($c['name']) .
    '</h1><p>' . esc(armory_character_description($c)) . '</p></main></body></html>\n');
}
