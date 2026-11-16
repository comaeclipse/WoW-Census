<?php
// WoW: Forever professions: which auction-house items belong to each trade
// (the materials it uses or gathers, the goods it crafts, the recipes that
// teach it) and what is listed for them right now.
//
// Crafted goods come from the scans' own crafter field. Materials are picked by
// auction category plus a few named reagents. Recipes are sorted by their name
// prefix (Plans:, Formula:, ...); "Pattern:" and "Recipe:" are shared by two
// trades each, so those go to the trade that crafts the taught item, or by its
// wording when the item itself has not been seen.

declare(strict_types=1);

const PROFESSION_SOURCE = 'forever';

const PROFESSIONS = [
  'alchemy' => ['name' => 'Alchemy', 'kind' => 'primary', 'role' => 'Crafting', 'icon' => 'trade_alchemy', 'crafter' => 'Alchemy',
    'blurb' => 'Brews potions, elixirs and transmutes from herbs gathered in the wild.',
    'cats' => ['Herbs'], 'names' => '/^(Empty|Leaded|Crystal|Imbued) Vial$/'],
  'blacksmithing' => ['name' => 'Blacksmithing', 'kind' => 'primary', 'role' => 'Crafting', 'icon' => 'inv_hammer_20', 'crafter' => 'Blacksmithing',
    'blurb' => 'Hammers bars and stone into mail, plate, weapons and sharpening stones.',
    'cats' => ['Ore & Bars'], 'names' => '/(Flux|^Elemental (Earth|Fire))$/'],
  'enchanting' => ['name' => 'Enchanting', 'kind' => 'primary', 'role' => 'Crafting', 'icon' => 'inv_enchant_dustarcane', 'crafter' => 'Enchanting',
    'blurb' => 'Disenchants magic gear into dust and essences, then binds them onto armor and weapons.',
    'cats' => ['Enchanting Mats'], 'names' => '/^(Simple Wood|Star Wood)$/'],
  'engineering' => ['name' => 'Engineering', 'kind' => 'primary', 'role' => 'Crafting', 'icon' => 'trade_engineering', 'crafter' => 'Engineering',
    'blurb' => 'Builds bombs, scopes, gadgets and goggles out of metal parts and blasting powder.',
    'cats' => ['Engineering Supplies', 'Ore & Bars'], 'names' => null],
  'leatherworking' => ['name' => 'Leatherworking', 'kind' => 'primary', 'role' => 'Crafting', 'icon' => 'inv_misc_armorkit_17', 'crafter' => 'Leatherworking',
    'blurb' => 'Cures hides into leather and mail armor, armor kits and bags.',
    'cats' => ['Leather & Hides'], 'names' => '/(Thread|Dye|^Salt)$/'],
  'tailoring' => ['name' => 'Tailoring', 'kind' => 'primary', 'role' => 'Crafting', 'icon' => 'inv_fabric_silk_02', 'crafter' => 'Tailoring',
    'blurb' => 'Weaves cloth dropped by humanoids into robes, cloth armor, shirts and bags.',
    'cats' => ['Cloth'], 'names' => '/(Dye|^Bleach)$/'],
  'herbalism' => ['name' => 'Herbalism', 'kind' => 'primary', 'role' => 'Gathering', 'icon' => 'inv_misc_flower_02', 'crafter' => null,
    'blurb' => 'Picks herbs from the world for alchemists and scribes.',
    'cats' => ['Herbs'], 'names' => null],
  'mining' => ['name' => 'Mining', 'kind' => 'primary', 'role' => 'Gathering', 'icon' => 'inv_pick_02', 'crafter' => 'Mining',
    'blurb' => 'Digs ore and gems from mineral veins and smelts ore into bars.',
    'cats' => ['Ore & Bars'], 'names' => '/^(Aquamarine|Jade|Malachite|Moss Agate|Tigerseye|Shadowgem|Lesser Moonstone|Citrine|Star Ruby|Azerothian Diamond|Blue Sapphire|Large Opal|Huge Emerald|Arcane Crystal|Elemental Earth)$/'],
  'skinning' => ['name' => 'Skinning', 'kind' => 'primary', 'role' => 'Gathering', 'icon' => 'inv_misc_pelt_wolf_01', 'crafter' => null,
    'blurb' => 'Strips leather, hides and scales from slain beasts.',
    'cats' => ['Leather & Hides'], 'names' => null],
  'cooking' => ['name' => 'Cooking', 'kind' => 'secondary', 'role' => 'Secondary', 'icon' => 'inv_misc_food_14', 'crafter' => 'Cooking',
    'blurb' => 'Turns meat, fish and spices into food that restores health and grants buffs.',
    'cats' => ['Cooking Ingredients'], 'names' => '/(Spices|^Simple Flour|^Small Egg)$/'],
  'first-aid' => ['name' => 'First Aid', 'kind' => 'secondary', 'role' => 'Secondary', 'icon' => 'spell_holy_sealofsacrifice', 'crafter' => 'First Aid',
    'blurb' => 'Sews cloth into bandages and makes anti-venoms.',
    'cats' => [], 'names' => '/^(Linen Cloth|Wool Cloth|Silk Cloth|Mageweave Cloth|Runecloth|Felcloth|Small Venom Sac|Large Venom Sac)$/'],
  'fishing' => ['name' => 'Fishing', 'kind' => 'secondary', 'role' => 'Secondary', 'icon' => 'trade_fishing', 'crafter' => null,
    'blurb' => 'Reels in fish for cooks and alchemists, plus the odd clam and trophy catch.',
    'cats' => [], 'names' => '/^(Raw .+|Oily Blackmouth|Firefin Snapper|Stonescale Eel|Whimsyfin|Deviate Fish|Sickly Looking Fish|\d+ Pound .+)$/'],
];

// Retail trades use the same market/census presentation, but not the retired
// First Aid trade or Forever's classic-only material rules.
const RETAIL_PROFESSIONS = [
  'alchemy' => ['name' => 'Alchemy', 'kind' => 'primary', 'role' => 'Crafting', 'icon' => 'trade_alchemy', 'crafter' => 'Alchemy', 'blurb' => 'Creates potions, phials, cauldrons and transmuted materials.', 'cats' => ['Herbs'], 'names' => '/(Vial|Potion|Phial|Cauldron)/i'],
  'blacksmithing' => ['name' => 'Blacksmithing', 'kind' => 'primary', 'role' => 'Crafting', 'icon' => 'inv_hammer_20', 'crafter' => 'Blacksmithing', 'blurb' => 'Forges plate armor, weapons, tools and alloys.', 'cats' => ['Ore & Bars', 'Metal & Stone'], 'names' => '/(Flux|Alloy|Whetstone)/i'],
  'enchanting' => ['name' => 'Enchanting', 'kind' => 'primary', 'role' => 'Crafting', 'icon' => 'inv_enchant_dustarcane', 'crafter' => 'Enchanting', 'blurb' => 'Turns magical materials into equipment enchantments and finishing reagents.', 'cats' => ['Enchanting Mats', 'Enchanting'], 'names' => '/(Dust|Shard|Crystal|Enchant)/i'],
  'engineering' => ['name' => 'Engineering', 'kind' => 'primary', 'role' => 'Crafting', 'icon' => 'trade_engineering', 'crafter' => 'Engineering', 'blurb' => 'Builds devices, scopes, explosives, toys and profession equipment.', 'cats' => ['Engineering Supplies'], 'names' => '/(Gear|Bolt|Widget|Blasting|Scope)/i'],
  'herbalism' => ['name' => 'Herbalism', 'kind' => 'primary', 'role' => 'Gathering', 'icon' => 'inv_misc_flower_02', 'crafter' => null, 'blurb' => 'Gathers herbs used by alchemists and scribes.', 'cats' => ['Herbs'], 'names' => null],
  'inscription' => ['name' => 'Inscription', 'kind' => 'primary', 'role' => 'Crafting', 'icon' => '10prof_table_inscription01', 'crafter' => 'Inscription', 'blurb' => 'Mills herbs and creates inks, missives, contracts and glyphs.', 'cats' => ['Inscription'], 'names' => '/(Pigment|Ink|Missive|Glyph|Contract)/i'],
  'jewelcrafting' => ['name' => 'Jewelcrafting', 'kind' => 'primary', 'role' => 'Crafting', 'icon' => 'inv_misc_gem_01', 'crafter' => 'Jewelcrafting', 'blurb' => 'Cuts gems and crafts rings, necklaces and profession accessories.', 'cats' => ['Gems'], 'names' => '/(Gem|Setting|Clasp)/i'],
  'leatherworking' => ['name' => 'Leatherworking', 'kind' => 'primary', 'role' => 'Crafting', 'icon' => 'inv_misc_armorkit_17', 'crafter' => 'Leatherworking', 'blurb' => 'Crafts leather and mail armor, armor kits and profession equipment.', 'cats' => ['Leather & Hides'], 'names' => '/(Hide|Leather|Scale|Armor Kit)/i'],
  'mining' => ['name' => 'Mining', 'kind' => 'primary', 'role' => 'Gathering', 'icon' => 'inv_pick_02', 'crafter' => 'Mining', 'blurb' => 'Gathers ore, stone and gems from mineral deposits.', 'cats' => ['Ore & Bars', 'Metal & Stone'], 'names' => '/(Ore|Stone)/i'],
  'skinning' => ['name' => 'Skinning', 'kind' => 'primary', 'role' => 'Gathering', 'icon' => 'inv_misc_pelt_wolf_01', 'crafter' => null, 'blurb' => 'Collects leather, hides and scales from beasts.', 'cats' => ['Leather & Hides'], 'names' => '/(Hide|Leather|Scale)/i'],
  'tailoring' => ['name' => 'Tailoring', 'kind' => 'primary', 'role' => 'Crafting', 'icon' => 'inv_fabric_silk_02', 'crafter' => 'Tailoring', 'blurb' => 'Sews cloth armor, bags, bolts and profession equipment.', 'cats' => ['Cloth'], 'names' => '/(Cloth|Bolt|Thread)/i'],
  'cooking' => ['name' => 'Cooking', 'kind' => 'secondary', 'role' => 'Secondary', 'icon' => 'inv_misc_food_14', 'crafter' => 'Cooking', 'blurb' => 'Prepares food and feasts from gathered ingredients.', 'cats' => ['Cooking Ingredients'], 'names' => '/(Meat|Fish|Spice|Feast)/i'],
  'fishing' => ['name' => 'Fishing', 'kind' => 'secondary', 'role' => 'Secondary', 'icon' => 'trade_fishing', 'crafter' => null, 'blurb' => 'Catches fish and other aquatic materials.', 'cats' => ['Fish'], 'names' => '/(Fish|Minnow|Eel)/i'],
];

function profession_catalog(string $source): array {
  return $source === 'retail' ? RETAIL_PROFESSIONS : PROFESSIONS;
}

// Recipe name prefixes that belong to one trade.
const PROFESSION_RECIPE_PREFIX = [
  'Plans' => 'blacksmithing', 'Formula' => 'enchanting', 'Schematic' => 'engineering', 'Manual' => 'first-aid',
  'Expert Cookbook' => 'cooking', 'Expert First Aid' => 'first-aid', 'Expert Fishing' => 'fishing',
];

/** Live profession census from every character's newest stored Forever Armory snapshot. */
function armory_profession_census(): array {
  return published_page_model('forever-armory-professions', function () {
    $fallback = fn() => json_decode(file_get_contents(__DIR__ . '/forever-profession-census.json'), true, 512, JSON_THROW_ON_ERROR);
    try {
      $profiles = Db::all("WITH latest AS (
          SELECT DISTINCT ON (region,character_id) region,character_id,snapshot_at,professions_json
          FROM forever_armory_snapshots WHERE professions_json IS NOT NULL ORDER BY region,character_id,snapshot_at DESC)
        SELECT p.region,p.character_id,p.ruleset_name,p.class_name,p.level,l.snapshot_at
        FROM latest l JOIN forever_armory_profiles p USING(region,character_id)");
      $trades = Db::all("WITH latest AS (
          SELECT DISTINCT ON (region,character_id) region,character_id,snapshot_at,professions_json
          FROM forever_armory_snapshots WHERE professions_json IS NOT NULL ORDER BY region,character_id,snapshot_at DESC), expanded AS (
          SELECT l.region,l.character_id,x.kind,x.prof
          FROM latest l CROSS JOIN LATERAL (
            SELECT 'primary' kind,j prof FROM jsonb_array_elements(COALESCE(l.professions_json->'primaries','[]'::jsonb)) j
            UNION ALL SELECT 'secondary',j FROM jsonb_array_elements(COALESCE(l.professions_json->'secondaries','[]'::jsonb)) j) x)
        SELECT region,character_id,kind,
          COALESCE(prof->'profession'->>'name',prof->'tiers'->0->'tier'->>'name') name,
          COALESCE((prof->'tiers'->0->>'skill_points')::int,0) skill,
          COALESCE((prof->'tiers'->0->>'max_skill_points')::int,0) max_skill,
          jsonb_array_length(COALESCE(prof->'tiers'->0->'known_recipes','[]'::jsonb)) recipes
        FROM expanded WHERE COALESCE(prof->'profession'->>'name',prof->'tiers'->0->'tier'->>'name') IS NOT NULL");
    } catch (PDOException $e) {
      if (in_array($e->getCode(), ['42P01', '42703'], true)) return $fallback();
      throw $e;
    }
    if (!$profiles) return $fallback();
    $meta = []; $regions = []; $rulesets = []; $classTotals = []; $levelTotals = []; $from = null; $to = null;
    foreach ($profiles as $p) {
      $key = $p['region'] . ':' . $p['character_id']; $meta[$key] = $p;
      $regions[strtoupper($p['region'])] = ($regions[strtoupper($p['region'])] ?? 0) + 1;
      $rulesets[$p['ruleset_name'] ?: 'Unknown'] = ($rulesets[$p['ruleset_name'] ?: 'Unknown'] ?? 0) + 1;
      $classTotals[$p['class_name']] = ($classTotals[$p['class_name']] ?? 0) + 1;
      $level = (int)$p['level']; $band = $level < 20 ? '10-19' : ($level < 30 ? '20-29' : ($level < 40 ? '30-39' : ($level < 50 ? '40-49' : '50-60')));
      $levelTotals[$band] = ($levelTotals[$band] ?? 0) + 1;
      $t = strtotime((string)$p['snapshot_at']); $from = $from === null ? $t : min($from, $t); $to = $to === null ? $t : max($to, $t);
    }
    $byChar = []; $stats = ['primary' => [], 'secondary' => []]; $classTrades = []; $levelSkills = [];
    foreach ($trades as $t) {
      $key = $t['region'] . ':' . $t['character_id']; if (!isset($meta[$key])) continue;
      $kind = $t['kind']; $name = $t['name']; $skill = (int)$t['skill']; $max = (int)$t['max_skill'];
      $byChar[$key][$kind][] = $name;
      $stats[$kind][$name] ??= ['skills' => [], 'recipes' => 0, 'maxed' => 0, 'top' => 0];
      $stats[$kind][$name]['skills'][] = $skill; $stats[$kind][$name]['recipes'] += (int)$t['recipes'];
      $stats[$kind][$name]['top'] = max($stats[$kind][$name]['top'], $skill);
      if ($max > 0 && $skill >= $max) $stats[$kind][$name]['maxed']++;
      if ($kind === 'primary') {
        $class = $meta[$key]['class_name']; $classTrades[$class][$name] = ($classTrades[$class][$name] ?? 0) + 1;
        $level = (int)$meta[$key]['level']; $band = $level < 20 ? '10-19' : ($level < 30 ? '20-29' : ($level < 40 ? '30-39' : ($level < 50 ? '40-49' : '50-60')));
        $levelSkills[$band][] = $skill;
      }
    }
    $n = count($profiles); $primaryCounts = ['0' => 0, '1' => 0, '2' => 0]; $combos = [];
    foreach (array_keys($meta) as $key) {
      $p = array_values(array_unique($byChar[$key]['primary'] ?? [])); sort($p, SORT_STRING);
      $primaryCounts[(string)min(2, count($p))]++;
      if ($p) { $combo = implode(' + ', $p); $combos[$combo] = ($combos[$combo] ?? 0) + 1; }
    }
    arsort($combos);
    $summaries = function (array $rows) use ($n): array {
      $out = [];
      foreach ($rows as $name => $s) {
        sort($s['skills']); $count = count($s['skills']);
        $out[] = ['name' => $name, 'count' => $count, 'pct' => round(100 * $count / $n, 1),
          'avg' => round(array_sum($s['skills']) / max(1, $count), 1), 'median' => $s['skills'][(int)floor(($count - 1) / 2)] ?? 0,
          'top' => $s['top'], 'recipes' => round($s['recipes'] / max(1, $count), 1), 'maxed' => round(100 * $s['maxed'] / max(1, $count), 1), 'brackets' => []];
      }
      usort($out, fn($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));
      return $out;
    };
    $classes = [];
    foreach ($classTotals as $class => $total) {
      $top = [];
      foreach ($classTrades[$class] ?? [] as $name => $count) $top[] = [$name, round(100 * $count / $total, 1)];
      usort($top, fn($a, $b) => $b[1] <=> $a[1]); $classes[] = ['class' => $class, 'count' => $total, 'top' => $top];
    }
    usort($classes, fn($a, $b) => $b['count'] <=> $a['count']);
    $levels = [];
    foreach (['10-19','20-29','30-39','40-49','50-60'] as $band) if (isset($levelTotals[$band]))
      $levels[] = [$band, $levelTotals[$band], round(array_sum($levelSkills[$band] ?? [0]) / max(1, count($levelSkills[$band] ?? [])), 1)];
    $comboRows = [];
    foreach (array_slice($combos, 0, 10, true) as $name => $count) $comboRows[] = [$name, $count, round(100 * $count / $n, 1)];
    return ['source' => 'forever_armory_snapshots', 'sampled' => $n, 'profiles' => $n, 'notFound' => 0,
      'fetchedFrom' => gmdate('c', $from), 'fetchedTo' => gmdate('c', $to), 'regions' => $regions, 'rulesets' => $rulesets,
      'primaryCounts' => $primaryCounts, 'primaries' => $summaries($stats['primary']), 'secondaries' => $summaries($stats['secondary']),
      'combos' => $comboRows, 'classes' => $classes, 'levels' => $levels,
      'learned' => count($trades), 'recipes' => array_sum(array_map(fn($x) => $x['recipes'], [...$stats['primary'], ...$stats['secondary']])),
      'capped' => array_sum(array_map(fn($x) => $x['maxed'], [...$stats['primary'], ...$stats['secondary']]))];
  });
}

/** Retail profession census from each character's newest stored Armory snapshot. */
function retail_armory_profession_census(): array {
  return published_page_model('retail-armory-professions', function () {
    try {
      $rows = Db::all("WITH latest AS (
          SELECT DISTINCT ON (region,character_id) region,character_id,snapshot_at,professions_json
          FROM character_armory_snapshots WHERE source_game='retail' AND professions_json IS NOT NULL
          ORDER BY region,character_id,snapshot_at DESC)
        SELECT p.region,p.character_id,p.class_name,p.level,l.snapshot_at,l.professions_json
        FROM latest l JOIN character_armory_profiles p
          ON p.source_game='retail' AND p.region=l.region AND p.character_id=l.character_id");
    } catch (PDOException $e) {
      if (in_array($e->getCode(), ['42P01', '42703'], true)) $rows = [];
      else throw $e;
    }
    $n = count($rows); $from = null; $to = null; $regions = []; $classTotals = [];
    $primaryCounts = ['0' => 0, '1' => 0, '2' => 0]; $stats = ['primary' => [], 'secondary' => []];
    $combos = []; $classTrades = []; $learned = 0; $recipes = 0; $capped = 0;
    foreach ($rows as $r) {
      $regions[strtoupper((string)$r['region'])] = ($regions[strtoupper((string)$r['region'])] ?? 0) + 1;
      $class = (string)$r['class_name']; $classTotals[$class] = ($classTotals[$class] ?? 0) + 1;
      $t = strtotime((string)$r['snapshot_at']); $from = $from === null ? $t : min($from, $t); $to = $to === null ? $t : max($to, $t);
      $json = forever_json($r['professions_json']); $primaryNames = [];
      foreach (['primaries' => 'primary', 'secondaries' => 'secondary'] as $key => $kind) foreach ($json[$key] ?? [] as $prof) {
        $name = trim((string)($prof['profession']['name'] ?? ($prof['tiers'][0]['tier']['name'] ?? '')));
        if ($name === '') continue;
        $tiers = $prof['tiers'] ?? []; $skill = 0; $max = 0; $known = 0;
        foreach ($tiers as $tier) { $skill = max($skill, (int)($tier['skill_points'] ?? 0)); $max = max($max, (int)($tier['max_skill_points'] ?? 0)); $known += count($tier['known_recipes'] ?? []); }
        $stats[$kind][$name] ??= ['skills' => [], 'recipes' => 0, 'maxed' => 0, 'top' => 0];
        $stats[$kind][$name]['skills'][] = $skill; $stats[$kind][$name]['recipes'] += $known; $stats[$kind][$name]['top'] = max($stats[$kind][$name]['top'], $skill);
        if ($max > 0 && $skill >= $max) { $stats[$kind][$name]['maxed']++; $capped++; }
        $learned++; $recipes += $known;
        if ($kind === 'primary') { $primaryNames[] = $name; $classTrades[$class][$name] = ($classTrades[$class][$name] ?? 0) + 1; }
      }
      $primaryNames = array_values(array_unique($primaryNames)); sort($primaryNames, SORT_STRING);
      $primaryCounts[(string)min(2, count($primaryNames))]++;
      if ($primaryNames) { $combo = implode(' + ', $primaryNames); $combos[$combo] = ($combos[$combo] ?? 0) + 1; }
    }
    $summaries = function (array $group) use ($n): array {
      $out = [];
      foreach ($group as $name => $s) { sort($s['skills']); $count = count($s['skills']); $out[] = ['name' => $name, 'count' => $count,
        'pct' => $n ? round(100 * $count / $n, 1) : 0, 'avg' => round(array_sum($s['skills']) / max(1, $count), 1),
        'median' => $s['skills'][(int)floor(($count - 1) / 2)] ?? 0, 'top' => $s['top'], 'recipes' => round($s['recipes'] / max(1, $count), 1),
        'maxed' => round(100 * $s['maxed'] / max(1, $count), 1), 'brackets' => []]; }
      usort($out, fn($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name'])); return $out;
    };
    arsort($combos); $comboRows = [];
    foreach (array_slice($combos, 0, 10, true) as $name => $count) $comboRows[] = [$name, $count, $n ? round(100 * $count / $n, 1) : 0];
    $classes = [];
    foreach ($classTotals as $class => $total) { $top = []; foreach ($classTrades[$class] ?? [] as $name => $count) $top[] = [$name, round(100 * $count / $total, 1)];
      usort($top, fn($a, $b) => $b[1] <=> $a[1]); $classes[] = ['class' => $class, 'count' => $total, 'top' => $top]; }
    usort($classes, fn($a, $b) => $b['count'] <=> $a['count']);
    return ['source' => 'character_armory_snapshots', 'sampled' => $n, 'profiles' => $n, 'notFound' => 0,
      'fetchedFrom' => $from ? gmdate('c', $from) : null, 'fetchedTo' => $to ? gmdate('c', $to) : null, 'regions' => $regions, 'rulesets' => [],
      'primaryCounts' => $primaryCounts, 'primaries' => $summaries($stats['primary']), 'secondaries' => $summaries($stats['secondary']),
      'combos' => $comboRows, 'classes' => $classes, 'levels' => [], 'learned' => $learned, 'recipes' => $recipes, 'capped' => $capped];
  });
}

/** The trade a recipe teaches, or null. $craftedBy maps a crafted item's name to its trade slug. */
function profession_for_recipe(string $name, array $craftedBy): ?string {
  foreach (PROFESSION_RECIPE_PREFIX as $prefix => $slug) if (str_starts_with($name, $prefix)) return $slug;
  if (!preg_match('/^(Pattern|Recipe): (.+)$/', $name, $m)) return null;
  [$prefix, $taught] = [$m[1], $m[2]];
  $pair = $prefix === 'Pattern' ? ['tailoring', 'leatherworking'] : ['alchemy', 'cooking'];
  if (in_array($craftedBy[$taught] ?? null, $pair, true)) return $craftedBy[$taught];
  if ($prefix === 'Pattern')
    return preg_match('/Leather|Scorpid|Whelp|Suede|Hide|Armor Kit|Mail|Kilt|Barbaric|Voodoo|Overalls|Deviate|Chimeric|Dusky|Raptor|Harness|Murloc|Bandit|Living|Wildthorn|Swift Boots|Herbalist|Pilferer|Heavy Earthen|Shadowskin|Guardian|Gem-studded|Hillman|Moonglow|Ball|Quiver|Ammo Pouch/', $taught)
      ? 'leatherworking' : 'tailoring';
  return preg_match('/Potion|Elixir|Flask|Transmute|Philosopher|Gift of Arthas|Draught|Rocket Fuel|Oil\b|Elemental/', $taught) ? 'alchemy' : 'cooking';
}

/** Which lists of which trades an item appears in: [[slug, 'mats'|'crafted'|'recipes'], ...]. */
function profession_roles(array $it, ?string $crafter, array $craftedBy): array {
  $out = [];
  $name = (string)$it['name'];
  if (($it['cat'] ?? '') === 'Recipes') {
    if (($slug = profession_for_recipe($name, $craftedBy)) !== null) $out[] = [$slug, 'recipes'];
    return $out;
  }
  foreach (PROFESSIONS as $slug => $p) {
    if ($crafter !== null && $p['crafter'] === $crafter) $out[] = [$slug, 'crafted'];
    elseif (in_array($it['cat'] ?? '', $p['cats'], true) || ($p['names'] !== null && preg_match($p['names'], $name))) $out[] = [$slug, 'mats'];
  }
  return $out;
}

/** Source-aware profession membership for AH and item pages. */
function edition_profession_roles(string $source, array $it, ?string $crafter = null, array $craftedBy = []): array {
  if ($source === PROFESSION_SOURCE) return profession_roles($it, $crafter, $craftedBy);
  $out = []; $name = (string)($it['name'] ?? ''); $cat = (string)($it['cat'] ?? '');
  foreach (profession_catalog($source) as $slug => $p) {
    $role = null;
    if ($crafter !== null && strcasecmp((string)$p['crafter'], $crafter) === 0) $role = 'crafted';
    elseif (in_array($cat, $p['cats'], true) || ($p['names'] !== null && preg_match($p['names'], $name)))
      $role = $p['role'] === 'Gathering' ? 'mats' : (preg_match('/^(Recipe|Pattern|Plans|Formula|Schematic):/i', $name) ? 'recipes' : 'mats');
    if ($role !== null) $out[] = [$slug, $role];
  }
  return $out;
}

/**
 * Every view's listings per trade: views[] = {realm, faction, scanned, professions: {slug: {mats, crafted, recipes, units, value, items}}}.
 * Rows are [name, id, icon, qty, unit copper, listed value copper, change].
 */
function professions_page_model(string $source = PROFESSION_SOURCE): array {
  return published_page_model('professions:' . $source, function () use ($source) {
    $catalog = profession_catalog($source);
    $model = model_auctionhouse($source);
    // The crafter field rides on the realm snapshots; merge_items() leaves it out.
    $crafter = [];
    foreach (edition_snaps($source) as $snap) {
      $iId = array_search('id', $snap['columns'], true); $iCr = array_search('crafter', $snap['columns'], true);
      if ($iCr === false) continue;
      foreach ($snap['items'] as $row) if (($row[$iCr] ?? null) !== null && $row[$iCr] !== '') $crafter[$row[$iId]] = $row[$iCr];
    }
    $slugOf = [];
    foreach ($catalog as $slug => $p) if ($p['crafter'] !== null) $slugOf[$p['crafter']] = $slug;
    $widest = ['items' => []];
    foreach ($model['views'] as $v) if (count($v['snapshot']['items']) > count($widest['items'])) $widest = $v['snapshot'];
    $craftedBy = [];
    foreach ($widest['items'] as $it) if (isset($crafter[$it['id']], $slugOf[$crafter[$it['id']]])) $craftedBy[(string)$it['name']] = $slugOf[$crafter[$it['id']]];
    $joke = joke_price_threshold($widest['items']);
    $branch = EDITIONS[$source]['branch'];
    $icons = (array)item_cache('item-icons', $branch) + (array)item_cache('item-icons', 'classic');

    $views = [];
    foreach ($model['views'] as $v) {
      $lists = [];
      foreach ($catalog as $slug => $_) $lists[$slug] = ['mats' => [], 'crafted' => [], 'recipes' => []];
      foreach ($v['snapshot']['items'] as $it) {
        if (($it['q'] ?: 0) <= 0 || ($it['asp'] ?: 0) > $joke || preg_match('/^item:\d+$/', (string)$it['name'])) continue;
        $roles = edition_profession_roles($source, $it, $crafter[$it['id']] ?? null, $craftedBy);
        if (!$roles) continue;
        $change = $it['pq'] === null ? null : ($it['pq'] === 0 ? 'NEW' : (int)js_round(($it['q'] - $it['pq']) / $it['pq'] * 100));
        $row = [(string)$it['name'], $it['id'], $icons[$it['id']] ?? null, (int)$it['q'], (int)js_round($it['asp'] ?: 0), (int)($it['mv'] ?: 0), $change];
        foreach ($roles as [$slug, $role]) $lists[$slug][$role][] = $row;
      }
      $profs = [];
      foreach ($lists as $slug => $roles) {
        $units = 0; $value = 0; $items = 0;
        foreach ($roles as $role => $rows) {
          usort($rows, fn($a, $b) => $b[3] <=> $a[3] ?: strcmp($a[0], $b[0]));
          foreach ($rows as $r) { $units += $r[3]; $value += $r[5]; }
          $items += count($rows);
          $roles[$role] = array_slice($rows, 0, 60);
          $roles[$role . 'Count'] = count($rows);
        }
        $profs[$slug] = $roles + ['units' => $units, 'value' => $value, 'items' => $items];
      }
      $s = $v['snapshot'];
      $views[] = ['realm' => $v['realm'], 'faction' => $v['faction'], 'scanned' => $s['updatedAt'] ? substr((string)$s['updatedAt'], 0, 10) : null,
        'empty' => !$s['items'], 'professions' => $profs];
    }
    return ['source' => $source, 'views' => $views, 'dims' => $model['dims'],
      'armory' => $source === 'retail' ? retail_armory_profession_census() : armory_profession_census()];
  });
}

function profession_path(?string $slug = null, string $source = PROFESSION_SOURCE): string {
  return edition_path($source) . 'professions' . ($slug !== null ? '/' . $slug : '');
}

/**
 * The plain page every skin starts from (the 2008 skin rebuilds it from the model).
 * $slug null is the list of trades.
 */
function professions_html(?string $slug, string $stylesheet, string $source = PROFESSION_SOURCE): string {
  $model = professions_page_model($source); $catalog = profession_catalog($source);
  $all = $model['views'][0] ?? ['professions' => []];
  $label = EDITIONS[$source]['label'];
  $seoLabel = seo_label($label);
  $navigation = edition_nav($source, 'professions');
  if ($slug === null) {
    $title = $seoLabel . ' Professions';
    $desc = $seoLabel . ' professions: auction house supply and asking prices for each trade\'s materials, crafted goods and recipes.';
    $pc = $model['armory'];
    $pop = '<div class="panel census"><div class="ptitle">Armory profession popularity</div><p class="hint" style="margin-top:0">Newest last-logout Armory profile for ' . js_locale($pc['profiles']) . ' sampled characters through ' . esc(substr($pc['fetchedTo'], 0, 10)) . '. This is profile coverage, not a population census.</p><table class="mkttable"><thead><tr><th class="l">Primary profession</th><th class="r">Characters</th><th class="r">Share</th><th class="r">Average skill</th></tr></thead><tbody>';
    foreach ($pc['primaries'] as $p) $pop .= '<tr><td class="l">' . esc($p['name']) . '</td><td class="r">' . js_locale($p['count']) . '</td><td class="r">' . esc($p['pct']) . '%</td><td class="r">' . esc($p['avg']) . '</td></tr>';
    $pop .= '</tbody></table></div>';
    $body = $pop . '<div class="panel census"><div class="ptitle">Profession markets</div><p class="hint" style="margin-top:0">Pick a trade to see what its materials, crafted goods and recipes list for on the auction house.</p><table class="mkttable"><thead><tr><th class="l">Profession</th><th class="r">Items listed</th><th class="r">Units</th><th class="r">Listed value</th></tr></thead><tbody>';
    foreach ($catalog as $s => $p) {
      $d = $all['professions'][$s] ?? ['items' => 0, 'units' => 0, 'value' => 0];
      $body .= '<tr><td class="l"><a href="' . esc(profession_path($s, $source)) . '">' . esc($p['name']) . '</a> <span class="mu">' . esc($p['role']) . '</span></td><td class="r">' . js_locale($d['items']) . '</td><td class="r">' . js_locale($d['units']) . '</td><td class="r">' . esc(big_gold($d['value'])) . '</td></tr>';
    }
    $body .= '</tbody></table></div>';
  } else {
    $p = $catalog[$slug];
    $d = $all['professions'][$slug];
    $title = $seoLabel . ' ' . $p['name'] . ' Auction Prices';
    $desc = $seoLabel . ' ' . $p['name'] . ': auction house supply and asking prices for its materials, crafted goods and recipes.';
    $branch = EDITIONS[$source]['branch'];
    $row = fn($r) => ['id' => $r[1], 'name' => $r[0], 'q' => $r[3], 'asp' => $r[4], 'mv' => $r[5]];
    $cols = [
      ['label' => 'Item', 'left' => true, 'cell' => fn($it) => '<a href="' . esc(armory_item_path($source, $it['id'])) . '">' . esc(item_label($it)) . '</a>'],
      ['label' => 'Qty', 'cell' => fn($it) => js_locale($it['q'])],
      ['label' => 'Unit', 'cell' => fn($it) => esc(money($it['asp']))],
    ];
    $body = '<p class="hint"><a href="' . esc(profession_path(null, $source)) . '">&larr; All professions</a> &middot; ' . esc($p['blurb']) . '</p>';
    foreach (['mats' => $p['role'] === 'Gathering' ? 'Gathered goods' : 'Materials', 'crafted' => 'Crafted goods', 'recipes' => 'Recipes'] as $role => $heading)
      if ($d[$role]) $body .= top_table($heading, js_locale($d[$role . 'Count']) . ' items listed, by quantity.', array_map($row, $d[$role]), $branch, $cols);
  }
  $canonical = SITE_ORIGIN . profession_path($slug, $source);
  $seo = seo_head($title . ' – WoWCensus', $desc, $canonical);
  $heading = page_heading('WoWCensus', $navigation, $title);
  $style = js_template_const('market.mjs', 'MARKET_STYLE');
  $css = esc($stylesheet);
  $census_css = CENSUS_STYLESHEET;
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

// ---------------------------------------------------------------- item pages

function forever_item_path(int|string $id): string { return edition_path(PROFESSION_SOURCE) . 'item/' . $id; }

/**
 * Who wears each item, from the inspected gear behind the Gear page:
 * id => [name, quality, icon, players, enchanted, slots {slot: n}, classes {classFile: n}, races, factions, levels [min, max, sum, n]],
 * plus '_slots' => {slot: players with that slot filled}. Cached in APCu per data version when available.
 */
function forever_wear_index(): array {
  static $index = null;
  if ($index !== null) return $index;
  $key = 'ml:forever-wear:' . (Db::publishedVersion() ?? 'live');
  $apcu = getenv('ML_APCU_CACHE') === '1' && function_exists('apcu_enabled') && apcu_enabled();
  if ($apcu) { $hit = apcu_fetch($key, $ok); if ($ok) return $index = $hit; }
  $packed = gear_page_model(PROFESSION_SOURCE)['packed'];
  $index = ['_slots' => [], '_players' => 0];
  foreach (unpack_gear_records($packed) as $r) {
    $index['_players']++;
    $seen = []; $filled = [];
    foreach ($r['gear'] as $w) {
      [$slot, $i, $ench] = $w;
      $filled[$slot] = true;
      if (isset($seen[$i])) continue;
      $seen[$i] = true;
      [$id, $name, $quality, $icon] = $packed['items'][$i];
      $e = &$index[$id];
      $e ??= [(string)$name, $quality < 0 ? 1 : $quality, $icon ?: null, 0, 0, [], [], [], [], [null, null, 0, 0]];
      $e[3]++;
      if ($ench === 1) $e[4]++;
      $e[5][$slot] = ($e[5][$slot] ?? 0) + 1;
      foreach ([6 => 'classFile', 7 => 'race', 8 => 'faction'] as $k => $f) if (($r[$f] ?? null) !== null) $e[$k][$r[$f]] = ($e[$k][$r[$f]] ?? 0) + 1;
      if (($lv = $r['level'] ?? null) !== null) { $e[9][0] = min($e[9][0] ?? $lv, $lv); $e[9][1] = max($e[9][1] ?? $lv, $lv); $e[9][2] += $lv; $e[9][3]++; }
      unset($e);
    }
    foreach ($filled as $slot => $_) $index['_slots'][$slot] = ($index['_slots'][$slot] ?? 0) + 1;
  }
  if ($apcu) apcu_store($key, $index, 12 * 3600);
  return $index;
}

/**
 * One item's wearers for its page, or null when no inspected character wore it. Reads the row
 * build-census-cache.php precomputed into census_item_wear (one indexed lookup); only while those
 * tables are missing or behind does it fall back to scanning every inspection.
 */
function forever_item_wearers(int $id): ?array {
  if (census_cache_fresh()) {
    try {
      $row = Db::first('SELECT data FROM census_item_wear WHERE item_id = ?', [$id]);
      return $row ? json_decode((string)$row['data'], true, 512, JSON_THROW_ON_ERROR) : null;
    } catch (PDOException) {
      // Published before census_item_wear existed: build it live until the next publish.
    }
  }
  return forever_item_wearers_live($id);
}

/** forever_item_wearers() from the inspections themselves (about a second for Forever's 10,000). */
function forever_item_wearers_live(int $id): ?array {
  $index = forever_wear_index();
  $e = $index[$id] ?? null;
  if ($e === null) return null;
  [$name, $quality, $icon, $players, $ench, $slots, $classes, $races, $factions, $lv] = $e;
  arsort($classes); arsort($races); arsort($factions); arsort($slots);
  $slotNames = [1 => 'Head', 2 => 'Neck', 3 => 'Shoulder', 4 => 'Shirt', 5 => 'Chest', 6 => 'Waist', 7 => 'Legs', 8 => 'Feet', 9 => 'Wrist', 10 => 'Hands',
    11 => 'Ring', 12 => 'Ring', 13 => 'Trinket', 14 => 'Trinket', 15 => 'Back', 16 => 'Main Hand', 17 => 'Off Hand', 18 => 'Ranged', 19 => 'Tabard'];
  $slot = array_key_first($slots);
  // Share of inspected characters with that slot filled who wore this item there.
  $filled = $index['_slots'][$slot] ?? 0;
  if (in_array($slot, [11, 12, 13, 14], true)) $filled = max($index['_slots'][$slot] ?? 0, $index['_slots'][$slot === 11 || $slot === 12 ? 23 - $slot : 27 - $slot] ?? 0);
  return ['name' => $name, 'quality' => $quality, 'icon' => $icon, 'players' => $players, 'inspected' => $index['_players'],
    'enchanted' => $players ? round(100 * $ench / $players) : 0, 'slot' => $slotNames[$slot] ?? 'Gear', 'slotShare' => $filled ? round(100 * $slots[$slot] / $filled, 1) : null,
    'classes' => array_map(fn($cf, $n) => [CLASS_META[$cf][0] ?? $cf, '#' . (CLASS_META[$cf][1] ?? 'c8b898'), $n], array_keys($classes), $classes),
    'races' => array_map(null, array_keys($races), array_values($races)), 'factions' => $factions,
    'levels' => $lv[3] ? ['min' => $lv[0], 'max' => $lv[1], 'avg' => round($lv[2] / $lv[3], 1)] : null];
}

/**
 * One WoW: Forever item's auction history, or null when no Forever scan has seen it.
 * scans[] = [realm key, faction, captured_at unix, qty, lowest cu, median cu, average cu], oldest first.
 */
function forever_item_model(int $id): ?array {
  // The plain page and the 2008 skin both ask for it in one request.
  static $memo = [];
  if (array_key_exists($id, $memo)) return $memo[$id];
  return $memo[$id] = forever_item_model_build($id);
}

function forever_item_model_build(int $id): ?array {
  $rows = Db::all("SELECT game, captured_at, name, category, crafter, quantity, lowest, median, weighted_average
    FROM local_auction_snapshots WHERE source_game=? AND item_id=? ORDER BY captured_at, game", [PROFESSION_SOURCE, $id]);
  // Gear seen on inspected characters has a page even if it was never listed.
  $wearers = forever_item_wearers($id);
  // Gear only seen on Armory characters still gets a page.
  $armory = forever_armory_item($id);
  $sales = forever_item_sales($id);
  $loot = item_loot_sources(PROFESSION_SOURCE, $id);
  $vendorLog = item_vendor_log(PROFESSION_SOURCE, $id);
  if (!$rows && !$wearers && !$armory && !($sales['count'] ?? 0) && !$loot && !$vendorLog) return null;
  // Armory characters' gear: who wears it, when the inspections behind the Gear page don't.
  $armoryWearers = !$wearers && $armory ? forever_armory_wearers($id, $armory['tip']['ilvl'] ?? null) : null;
  $name = $armory['name'] ?? null; $cat = null; $crafter = null; $scans = []; $seen = [];
  foreach ($rows as $r) {
    if (($r['name'] ?? '') !== '' && !preg_match('/^item:\d+$/', (string)$r['name'])) $name = (string)$r['name'];
    if (($r['category'] ?? '') !== '') $cat = $r['category'];
    if (($r['crafter'] ?? '') !== '') $crafter = $r['crafter'];
    // An old collection's scans count as its labelled twin's: keep each capture once. Forever's twins
    // differ only by the "us:forever:" label, so stripping it matches canonical_game() without
    // building the alias map (a census-wide query whenever the census tables are behind).
    $game = preg_replace('/^realm:(?:(?:us|eu|kr|tw|cn):[a-z-]+:)?/', '', $r['game']);
    if (isset($seen[$game . '|' . $r['captured_at']])) continue;
    $seen[$game . '|' . $r['captured_at']] = true;
    $faction = preg_match('/-(Alliance|Horde)$/', $game, $m) ? $m[1] : 'Neutral';
    $scans[] = [preg_replace('/-(Alliance|Horde)$/', '', $game), $faction, (int)$r['captured_at'], (int)$r['quantity'],
      (int)$r['lowest'], (int)$r['median'], (int)$r['weighted_average']];
  }
  $branch = EDITIONS[PROFESSION_SOURCE]['branch'];
  $name ??= ($wearers['name'] ?? null) ?: ((item_cache('item-names', $branch)[$id] ?? null) ?: ((item_cache('item-names', 'classic')[$id] ?? null) ?: 'Item ' . $id));
  $icon = ((array)item_cache('item-icons', $branch) + (array)item_cache('item-icons', 'classic'))[$id] ?? ($wearers['icon'] ?? null);
  if ($cat === null && $wearers) $cat = $wearers['slot'];
  if ($cat === null && $armory) $cat = $armory['cat'];

  // The trades it belongs to, and for a recipe the item it teaches.
  $taught = null; $craftedBy = [];
  if ($cat === 'Recipes' && preg_match('/^(?:Pattern|Recipe|Plans|Formula|Schematic|Manual): (.+)$/', $name, $m)) {
    $hit = Db::first('SELECT item_id, crafter FROM local_auction_snapshots WHERE source_game=? AND name=? ORDER BY captured_at DESC LIMIT 1', [PROFESSION_SOURCE, $m[1]]);
    if ($hit) {
      $taught = ['id' => (int)$hit['item_id'], 'name' => $m[1]];
      foreach (PROFESSIONS as $slug => $p) if ($p['crafter'] !== null && $p['crafter'] === $hit['crafter']) $craftedBy[$m[1]] = $slug;
    }
  }
  $roles = profession_roles(['name' => $name, 'cat' => $cat], $crafter, $craftedBy);
  return ['id' => $id, 'name' => $name, 'icon' => $icon, 'cat' => $cat, 'crafter' => $crafter, 'roles' => $roles, 'taught' => $taught, 'scans' => $scans,
    'wearers' => $wearers, 'quality' => $wearers['quality'] ?? ($armory['quality'] ?? null), 'iconUrl' => $icon === null ? ($armory['iconUrl'] ?? null) : null,
    'armoryOnly' => !$rows && !$wearers && $armory !== null, 'tip' => $armory['tip'] ?? null, 'armoryWearers' => $armoryWearers,
    'sales' => ($sales['count'] ?? 0) ? $sales : null, 'loot' => $loot,
    'vendor' => item_vendor_vs_ah($armory['tip']['sell'] ?? null, $vendorLog, $scans, ($sales['count'] ?? 0) ? $sales : null)];
}

/**
 * Completed auction sales of one item, newest first, from players' Journalator / TSM ledgers
 * (tools/import-journalator-pg.py). Nobody is named: not the seller who uploaded, not the buyer.
 * Returns null when the ledger tables aren't in this database yet.
 * rows[] = [sold unix, market label, faction, quantity, unit price cu, seconds to sell or null]
 */
function forever_item_sales(int $id, int $limit = 25): ?array {
  static $have = null;
  $have ??= (bool)(Db::first("SELECT to_regclass('addon_auction_sales') IS NOT NULL AS ok")['ok'] ?? false);
  if (!$have) return null;
  $rows = Db::all('SELECT region, realm, faction, quantity, unit_price, sold_at, seconds_to_sell
    FROM addon_auction_sales WHERE source_game=? AND item_id=? ORDER BY sold_at DESC LIMIT ' . (int)$limit, [PROFESSION_SOURCE, $id]);
  if (!$rows) return ['rows' => [], 'count' => 0];
  $stats = Db::first('SELECT count(*) AS n, percentile_cont(0.5) WITHIN GROUP (ORDER BY unit_price) AS med,
      percentile_cont(0.5) WITHIN GROUP (ORDER BY seconds_to_sell) AS secs, count(seconds_to_sell) AS timed, max(sold_at) AS last
    FROM addon_auction_sales WHERE source_game=? AND item_id=?', [PROFESSION_SOURCE, $id]);
  $out = [];
  foreach ($rows as $r) {
    $faction = in_array($r['faction'], ['Alliance', 'Horde'], true) ? $r['faction'] : 'Neutral';
    $group = forever_realm_group((string)$r['realm']);
    $label = ($group['label'] ?? strtoupper((string)$r['region']) . ' ' . realm_name((string)$r['realm'])) . ($faction === 'Neutral' ? '' : ' · ' . $faction);
    $out[] = [(int)$r['sold_at'], $label, $faction, (int)$r['quantity'], (int)$r['unit_price'],
      $r['seconds_to_sell'] === null ? null : (int)$r['seconds_to_sell']];
  }
  return ['rows' => $out, 'count' => (int)$stats['n'], 'median' => (int)round((float)$stats['med']),
    'medianSecs' => $stats['secs'] === null ? null : (int)round((float)$stats['secs']), 'timed' => (int)$stats['timed'], 'last' => (int)$stats['last']];
}

/**
 * An item's scans as players see the market: Forever's collector realms are hidden servers behind a region and
 * ruleset, so scans group into markets like "US PvE · Alliance". Each scanned day gets one point per market: every
 * server's latest scan up to that day (carried forward), quantities summed, lowest the minimum and the median
 * weighted by quantity.
 * Returns markets (key => [label, faction]), points [key, day noon unix, qty, lowest, median] and log [key, unix, qty, lowest, median].
 */
function forever_item_markets(array $scans): array {
  $markets = []; $byMarket = []; $log = [];
  foreach ($scans as [$realm, $faction, $t, $qty, $lo, $med]) {
    $group = forever_realm_group($realm) ?? ['key' => $realm, 'label' => realm_name($realm)];
    $key = $group['key'] . '|' . $faction;
    $markets[$key] = [$group['label'] . ($faction === 'Neutral' ? '' : ' · ' . $faction), $faction];
    $byMarket[$key][] = [$realm, $t, $qty, $lo, $med];
    $log[] = [$key, $t, $qty, $lo, $med];
  }
  $points = [];
  foreach ($byMarket as $key => $rows) {
    $days = array_values(array_unique(array_map(fn($r) => gmdate('Y-m-d', $r[1]), $rows)));
    sort($days);
    foreach ($days as $day) {
      $end = strtotime($day . ' 23:59:59 UTC');
      $latest = [];
      foreach ($rows as $r) if ($r[1] <= $end && (!isset($latest[$r[0]]) || $r[1] > $latest[$r[0]][1])) $latest[$r[0]] = $r;
      $qty = array_sum(array_column($latest, 2));
      $lo = min(array_column($latest, 3));
      usort($latest, fn($a, $b) => $a[4] <=> $b[4]);
      $med = end($latest)[4]; $acc = 0;
      foreach ($latest as $r) if (($acc += $r[2]) * 2 >= $qty) { $med = $r[4]; break; }
      $points[] = [$key, strtotime($day . ' 12:00:00 UTC'), $qty, $lo, $med];
    }
  }
  return ['markets' => $markets, 'points' => $points, 'log' => $log];
}

/** forever_item_markets() without the per-scan log: a scan count and each market's newest scan time. */
function forever_item_market_summary(array $m): array {
  $last = [];
  foreach ($m['log'] as [$key, $t]) $last[$key] = max($last[$key] ?? 0, $t);
  return ['markets' => $m['markets'], 'points' => $m['points'], 'scans' => count($m['log']), 'lastScan' => $last];
}

/** The plain page every skin starts from; the 2008 skin rebuilds it from the model. */
function forever_item_html(array $item, string $stylesheet): string {
  $label = seo_label(EDITIONS[PROFESSION_SOURCE]['label']);
  $navigation = edition_nav(PROFESSION_SOURCE, 'auctionhouse.html');
  $title = $item['name'] . ' – ' . $label . ($item['scans'] ? ' Auction Price History' : ' Item');
  $desc = $item['scans']
    ? $label . ' auction house prices for ' . $item['name'] . ': current listings per region and ruleset, price and supply history from in-game scans' . ($item['wearers'] ? ', and who wears it.' : '.')
    : $label . ' ' . $item['name'] . ': which classes, races and levels wear it, from inspected characters.';
  $seo = seo_head($title . ' – WoWCensus', $desc, SITE_ORIGIN . forever_item_path($item['id']));
  $m = forever_item_markets($item['scans']);
  $rows = '';
  // Newest scan per market only; the full scan log was too long to be useful.
  $newest = [];
  foreach ($m['log'] as $row) if (!isset($newest[$row[0]]) || $row[1] > $newest[$row[0]][1]) $newest[$row[0]] = $row;
  usort($newest, fn($a, $b) => $b[2] <=> $a[2]);
  foreach ($newest as [$key, $t, $qty, $lo, $med])
    $rows .= '<tr><td class="l">' . esc(gmdate('Y-m-d H:i', $t)) . '</td><td class="l">' . esc($m['markets'][$key][0]) . '</td><td class="r">' . js_locale($qty) . '</td><td class="r">' . esc(money($lo)) . '</td><td class="r">' . esc(money($med)) . '</td></tr>';
  $heading = page_heading('WoWCensus', $navigation, $item['name']);
  $style = js_template_const('market.mjs', 'MARKET_STYLE');
  $css = esc($stylesheet);
  $census_css = CENSUS_STYLESHEET;
  $name = esc($item['name']);
  $wowhead = esc('https://www.wowhead.com/' . EDITIONS[PROFESSION_SOURCE]['branch'] . '/item=' . $item['id']);
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
  <div class="panel census mkt"><div class="ptitle">$name &mdash; current listings</div>
  <p class="hint" style="margin-top:0"><a href="$wowhead" target="_blank" rel="noopener">View on Wowhead</a></p>
  <table class="mkttable"><thead><tr><th class="l">Scanned (UTC)</th><th class="l">Realm</th><th class="r">Qty</th><th class="r">Lowest</th><th class="r">Median</th></tr></thead><tbody>$rows</tbody></table></div>
</div>
</body></html>
HTML;
}
