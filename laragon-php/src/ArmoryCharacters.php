<?php
// Cross-edition character pages at /<edition>/character/<stable numeric id>/.
// Discovery comes from WoWCensus. Blizzard profile data only enriches an already-known character.

declare(strict_types=1);

const ARMORY_SOURCES = ['retail', 'mop-classic', 'classic-progression', 'classic', 'sod', 'hardcore', 'classic-beta'];

function armory_character_id(string $source, string $region, string $realm, string $name): string {
  return guild_hash_id($source, strtolower("$region\n$realm"), mb_strtolower($name));
}

function armory_character_path(string $source, string $id): string {
  return edition_path($source) . 'character/' . $id . '/';
}

function armory_item_path(string $source, int|string $id): string {
  return edition_path($source) . 'item/' . $id;
}

/** Item tooltip fields shared by the cross-edition Armory item pages. */
function armory_item_tip(array $it): array {
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
  foreach ($it['stats'] ?? [] as $st) if (!empty($st['display']['display_string'])) $tip['stats'][] = [wow_text($st['display']['display_string']), !empty($st['is_equip_bonus'])];
  foreach ($it['spells'] ?? [] as $sp) if (!empty($sp['description'])) $tip['spells'][] = wow_text($sp['description']);
  return $tip;
}

/** All newest Armory equipment snapshots for one edition, indexed for item statistics. */
function armory_wear_index(string $source): array {
  static $memo = [];
  if (isset($memo[$source])) return $memo[$source];
  try {
    $ver = (string)(Db::uncached('SELECT max(fetched_at) v FROM character_armory_profiles WHERE source_game=?', [$source])[0]['v'] ?? '');
  } catch (PDOException $e) {
    if ($e->getCode() === '42P01') return $memo[$source] = [];
    throw $e;
  }
  $key = 'ml:armory-wear:' . $source . ':' . $ver;
  $apcu = getenv('ML_APCU_CACHE') === '1' && function_exists('apcu_enabled') && apcu_enabled();
  if ($apcu) { $hit = apcu_fetch($key, $ok); if ($ok) return $memo[$source] = $hit; }
  $rows = Db::uncached("WITH latest AS (SELECT DISTINCT ON (region, character_id) region, character_id, equipment
        FROM character_armory_slim WHERE source_game=? AND equipment IS NOT NULL ORDER BY region, character_id, snapshot_at DESC)
      SELECT l.region, l.character_id ch, p.class_id, p.race_name, p.faction, p.level, p.equipped_item_level ilvl,
        (e->'item'->>'id')::int item, e->'slot'->>'type' slot
      FROM latest l JOIN character_armory_profiles p ON p.source_game=? AND p.region=l.region AND p.character_id=l.character_id
      CROSS JOIN LATERAL jsonb_array_elements(l.equipment->'equipped_items') e
      ORDER BY l.region, l.character_id", [$source, $source]);
  $index = ['_chars' => 0, '_class' => []];
  $pairOf = ['FINGER_1' => 'FINGER_2', 'FINGER_2' => 'FINGER_1', 'TRINKET_1' => 'TRINKET_2', 'TRINKET_2' => 'TRINKET_1'];
  $flush = function (array $ch) use (&$index, $pairOf) {
    if (!$ch) return;
    $p = $ch['p']; $index['_chars']++;
    $index['_class'][$p['faction']][(int)$p['class_id']] = ($index['_class'][$p['faction']][(int)$p['class_id']] ?? 0) + 1;
    $seen = [];
    foreach ($ch['slots'] as $slot => $item) {
      $e = &$index[$item]; $e ??= [0, [], [], [], [], [], [], [], []];
      $e[7][$slot] = ($e[7][$slot] ?? 0) + 1;
      if (isset($pairOf[$slot]) && ($other = $ch['slots'][$pairOf[$slot]] ?? null) !== null && $other !== $item) $e[8][$other] = ($e[8][$other] ?? 0) + 1;
      if (isset($seen[$item])) { unset($e); continue; }
      $seen[$item] = true; $e[0]++;
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
  return $memo[$source] = $index;
}

function armory_item_wearers(string $source, int $id, ?int $itemLevel): ?array {
  $index = armory_wear_index($source); $e = $index[$id] ?? null;
  if ($e === null) return null;
  [$n, $classes, $races, $factions, $regions, $levels, $ilvls, $slots, $pairs] = $e;
  arsort($classes); arsort($races); arsort($factions); arsort($regions); arsort($levels); arsort($pairs);
  $pool = [];
  foreach (array_keys($factions) as $f) foreach ($index['_class'][$f] ?? [] as $cid => $c) $pool[$cid] = ($pool[$cid] ?? 0) + $c;
  $classRows = [];
  $classFiles = FOREVER_CLASS_FILES + [6 => 'DEATHKNIGHT', 10 => 'MONK', 12 => 'DEMONHUNTER', 13 => 'EVOKER'];
  foreach ($classes as $cid => $c) {
    $cf = $classFiles[$cid] ?? null;
    $classRows[] = ['name' => CLASS_META[$cf][0] ?? ('Class ' . $cid), 'file' => strtolower((string)$cf), 'color' => '#' . (CLASS_META[$cf][1] ?? 'c8b898'),
      'n' => $c, 'of' => !empty($pool[$cid]) ? round(100 * $c / $pool[$cid]) : null, 'pool' => $pool[$cid] ?? 0];
  }
  $top = null;
  foreach ($classRows as $c) if ($c['of'] !== null && $c['n'] >= max(5, $n * .05) && (!$top || $c['of'] > $top['of'])) $top = $c;
  $hist = null;
  if ($ilvls) {
    $lo = min(array_keys($ilvls)); $hi = max(array_keys($ilvls)); $w = max(1, (int)ceil(($hi - $lo + 1) / 10)); $buckets = [];
    for ($b = $lo; $b <= $hi; $b += $w) $buckets[$b] = 0;
    $sum = 0;
    foreach ($ilvls as $lv => $c) { $buckets[$lo + intdiv($lv - $lo, $w) * $w] += $c; $sum += $lv * $c; }
    $hist = ['w' => $w, 'buckets' => $buckets, 'min' => $lo, 'max' => $hi, 'avg' => round($sum / array_sum($ilvls)), 'own' => $itemLevel,
      'above' => $itemLevel !== null ? array_sum(array_filter($ilvls, fn($lv) => $lv > $itemLevel, ARRAY_FILTER_USE_KEY)) : null];
  }
  $names = []; $pairs = array_slice($pairs, 0, 8, true);
  if ($pairs) {
    $marks = implode(',', array_fill(0, count($pairs), '?'));
    foreach (Db::all("SELECT DISTINCT ON (item_id) item_id,item_json->>'name' n,item_json->'quality'->>'type' q FROM character_armory_items WHERE source_game=? AND item_id IN ($marks) ORDER BY item_id,snapshot_at DESC", [$source, ...array_keys($pairs)]) as $r)
      $names[(int)$r['item_id']] = [$r['n'], FOREVER_QUALITY[$r['q'] ?? ''] ?? 1];
  }
  $regionNames = ['eu' => 'Europe', 'us' => 'Americas', 'kr' => 'Korea', 'tw' => 'Taiwan']; $poolChars = 0;
  foreach (array_keys($factions) as $f) $poolChars += array_sum($index['_class'][$f] ?? []);
  return ['players' => $n, 'chars' => $index['_chars'], 'pool' => $poolChars, 'classes' => $classRows, 'top' => $top,
    'races' => array_map(null, array_keys($races), array_values($races)), 'factions' => $factions,
    'regions' => array_map(fn($r, $c) => [$regionNames[$r] ?? strtoupper((string)$r), $c], array_keys($regions), array_values($regions)),
    'levels' => array_slice($levels, 0, 3, true), 'hist' => $hist,
    'slots' => array_filter(['Top' => ($slots['TRINKET_1'] ?? 0) + ($slots['FINGER_1'] ?? 0), 'Bottom' => ($slots['TRINKET_2'] ?? 0) + ($slots['FINGER_2'] ?? 0)])
      ?: array_filter(['Main hand' => $slots['MAIN_HAND'] ?? 0, 'Off hand' => $slots['OFF_HAND'] ?? 0]),
    'pairs' => array_map(fn($pid, $c) => ['id' => $pid, 'name' => $names[$pid][0] ?? ('Item ' . $pid), 'quality' => $names[$pid][1] ?? 1, 'n' => $c], array_keys($pairs), array_values($pairs))];
}

/** A locally stored item observed on an enriched character in this edition. */
function armory_item_model(string $source, int $id): ?array {
  if (!in_array($source, ARMORY_SOURCES, true) || $id < 1) return null;
  try {
    $row = Db::first('SELECT item_json FROM character_armory_items WHERE source_game=? AND item_id=? ORDER BY snapshot_at DESC LIMIT 1', [$source, $id]);
    $market = Db::all('SELECT game,captured_at,name,category,crafter,quantity,lowest,median,weighted_average
      FROM local_auction_snapshots WHERE source_game=? AND item_id=? ORDER BY captured_at,game', [$source, $id]);
  } catch (PDOException $e) {
    if ($e->getCode() === '42P01') return null;
    throw $e;
  }
  $wearers = edition_item_wearers($source, $id);
  if (!$row && !$market && !$wearers) return null;
  $it = $row ? forever_json($row['item_json']) : [];
  $tip = $row ? armory_item_tip($it) : null;
  $branch = EDITIONS[$source]['branch'] ?: 'retail';
  $icons = (array)item_cache('item-icons', $source) + (array)item_cache('item-icons', $branch) + (array)item_cache('item-icons', 'classic');
  $quality = FOREVER_QUALITY[$it['quality']['type'] ?? ''] ?? 1;
  $latest = $market ? end($market) : null;
  $name = $it['name'] ?? (($latest['name'] ?? '') !== '' && !preg_match('/^item:\d+$/', (string)$latest['name']) ? $latest['name'] : null)
    ?? ($wearers['name'] ?? null)
    ?? (item_cache('item-names', $branch)[$id] ?? ('Item ' . $id));
  $scans = []; $seen = [];
  foreach ($market as $m) {
    $game = preg_replace('/^realm:(?:(?:us|eu|kr|tw|cn):[a-z-]+:)?/', '', (string)$m['game']);
    $key = $game . '|' . $m['captured_at'];
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $faction = preg_match('/-(Alliance|Horde)$/', $game, $match) ? $match[1] : 'Neutral';
    $scans[] = [preg_replace('/-(Alliance|Horde)$/', '', $game), $faction, (int)$m['captured_at'], (int)$m['quantity'],
      (int)$m['lowest'], (int)$m['median'], (int)$m['weighted_average']];
  }
  return ['source' => $source, 'id' => $id, 'name' => $name, 'icon' => $icons[$id] ?? $icons[(string)$id] ?? ($wearers['icon'] ?? null),
    'cat' => $it['item_subclass']['name'] ?? ($it['inventory_type']['name'] ?? ($latest['category'] ?? ($wearers['slot'] ?? 'Item'))), 'crafter' => $latest['crafter'] ?? null,
    'roles' => edition_profession_roles($source, ['name' => $name, 'cat' => $latest['category'] ?? null], $latest['crafter'] ?? null),
    'taught' => null, 'scans' => $scans, 'wearers' => $wearers, 'quality' => $wearers['quality'] ?? $quality, 'localOnly' => !$market,
    'tip' => $tip, 'armoryWearers' => $row ? armory_item_wearers($source, $id, $tip['ilvl']) : null, 'armoryOnly' => !$market && $row,
    'loot' => item_loot_sources($source, $id),
    'vendor' => item_vendor_vs_ah(($it['sell_price']['value'] ?? 0) > 0 ? (int)$it['sell_price']['value'] : null, item_vendor_log($source, $id), $scans, null)];
}

function armory_item_html(string $source, array $item): string {
  $label = seo_label(EDITIONS[$source]['label']);
  $title = $item['name'] . ' – ' . $label . ($item['scans'] ? ' Auction Price History' : ' Item');
  $canonical = SITE_ORIGIN . armory_item_path($source, $item['id']);
  $icon = $item['icon'] ?: 'inv_misc_questionmark';
  $m = forever_item_markets($item['scans']); $newest = [];
  foreach ($m['log'] as $row) if (!isset($newest[$row[0]]) || $row[1] > $newest[$row[0]][1]) $newest[$row[0]] = $row;
  usort($newest, fn($a, $b) => $b[2] <=> $a[2]); $rows = '';
  foreach ($newest as [$key, $t, $qty, $lo, $med]) $rows .= '<tr><td class="l">' . esc(gmdate('Y-m-d H:i', $t)) . '</td><td class="l">' . esc($m['markets'][$key][0]) . '</td><td class="r">' . js_locale($qty) . '</td><td class="r">' . esc(money($lo)) . '</td><td class="r">' . esc(money($med)) . '</td></tr>';
  $nav = edition_nav($source, 'auctionhouse.html'); $heading = page_heading('WoWCensus', $nav, $title); $css = esc(edition_path($source) . stylesheet()['name']);
  $desc = $item['scans'] ? $label . ' auction house prices and current listed supply for ' . $item['name'] . ' from in-game scans.' : $label . ' item profile for ' . $item['name'] . ', observed on an enriched character.';
  $wowhead = 'https://www.wowhead.com/' . (EDITIONS[$source]['branch'] !== '' ? EDITIONS[$source]['branch'] . '/' : '') . 'item=' . $item['id'];
  $market = $rows !== '' ? '<div class="panel census mkt"><div class="ptitle">Current listings</div><table class="mkttable"><thead><tr><th class="l">Scanned (UTC)</th><th class="l">Market</th><th class="r">Qty</th><th class="r">Lowest</th><th class="r">Median</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '<div class="panel"><p class="hint">No auction listing has been observed for this item yet.</p></div>';
  return localize_content('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' . seo_head($title . ' – WoWCensus', $desc, $canonical) . '<link rel="stylesheet" href="' . $css . '"></head><body><div class="wrap"><header class="ihead"><div class="page-heading">' . $heading . $nav['games'] . '</div>' . $nav['breadcrumbs'] . '</header><main><div class="panel census"><div class="ptitle"><img src="/assets/images/wow/icons/large/' . esc($icon) . '.jpg" width="40" height="40" alt=""> ' . esc($item['name']) . '</div><p class="hint">' . esc($item['cat'] ?: 'Item') . ' · Item ID ' . $item['id'] . ' · <a href="' . esc($wowhead) . '" target="_blank" rel="noopener">View on Wowhead</a></p></div>' . $market . '</main></div></body></html>\n');
}

/** Profile ids for names on one census realm. Ambiguous cross-region matches are left unlinked. */
function armory_character_ids(string $source, string $realm, array $names, ?string $region = null, ?string $guild = null): array {
  if (!in_array($source, ARMORY_SOURCES, true)) return [];
  $names = array_values(array_unique(array_filter(array_map(fn($n) => mb_strtolower((string)$n), $names))));
  if (!$names) return [];
  $marks = implode(',', array_fill(0, count($names), '?'));
  try {
    $regionSql = $region !== null ? ' AND region=?' : '';
    // A guild on connected realms has members homed on each of them: their Armory guild names it.
    $placeSql = $guild !== null ? '(lower(realm_name)=lower(?) OR guild_name=?)' : 'lower(realm_name)=lower(?)';
    $args = [$source, $realm, ...($guild !== null ? [$guild] : []), ...($region !== null ? [$region] : []), ...$names];
    $rows = Db::all("SELECT lower(name) n,public_id FROM character_armory_profiles
      WHERE source_game=? AND $placeSql$regionSql AND lower(name) IN ($marks)", $args);
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
    'achievementPoints' => (int)($p['achievement_points'] ?? 0), 'achievements' => (int)(($a = $j($s['achievements_json']))['total_quantity'] ?? count($a['achievements'] ?? [])),
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
