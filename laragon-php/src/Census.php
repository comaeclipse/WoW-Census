<?php
// Port of site/src/census.mjs: shared page helpers and the census pages.

declare(strict_types=1);

const CENSUS_WINDOW_DAYS = 14;

const CLASS_META = [
  'WARRIOR' => ['Warrior', 'C79C6E'], 'PALADIN' => ['Paladin', 'F58CBA'],
  'HUNTER' => ['Hunter', 'ABD473'], 'ROGUE' => ['Rogue', 'FFF569'],
  'PRIEST' => ['Priest', 'FFFFFF'], 'SHAMAN' => ['Shaman', '0070DE'],
  'MAGE' => ['Mage', '69CCF0'], 'WARLOCK' => ['Warlock', '9482C9'],
  'DRUID' => ['Druid', 'FF7D0A'], 'DEATHKNIGHT' => ['Death Knight', 'C41F3B'],
  'MONK' => ['Monk', '00FF96'], 'DEMONHUNTER' => ['Demon Hunter', 'A330C9'],
  'EVOKER' => ['Evoker', '33937F'],
];

const FACTION_COLOR = ['Alliance' => '#3f83f8', 'Horde' => '#d9363e', 'Unknown' => '#8b90a0'];

const ICON_ROOT = 'https://wow.zamimg.com/images/wow/icons/large/';
const RACE_ICON = [
  'Human' => 'achievement_character_human_male', 'Dwarf' => 'achievement_character_dwarf_male',
  'Gnome' => 'achievement_character_gnome_male', 'Night Elf' => 'achievement_character_nightelf_male',
  'Orc' => 'achievement_character_orc_male', 'Tauren' => 'achievement_character_tauren_male',
  'Troll' => 'achievement_character_troll_male', 'Undead' => 'achievement_character_undead_male',
  'Scourge' => 'achievement_character_undead_male',
  'Blood Elf' => 'achievement_character_bloodelf_male', 'Draenei' => 'achievement_character_draenei_male',
  'Goblin' => 'race_goblin_male', 'Pandaren' => 'race_pandaren_male', 'Worgen' => 'race_worgen_male',
  'Nightborne' => 'achievement_alliedrace_nightborne',
  'Highmountain Tauren' => 'achievement_alliedrace_highmountaintauren',
  'Void Elf' => 'achievement_alliedrace_voidelf',
  'Lightforged Draenei' => 'achievement_alliedrace_lightforgeddraenei',
  'Dark Iron Dwarf' => 'achievement_alliedrace_darkirondwarf',
  "Mag'har Orc" => 'achievement_alliedrace_magharorc',
  'Kul Tiran' => 'achievement_alliedrace_kultiranhuman',
  'Zandalari Troll' => 'achievement_alliedrace_zandalaritroll',
  'Mechagnome' => 'achievement_alliedrace_mechagnome', 'Vulpera' => 'achievement_alliedrace_vulpera',
  'Dracthyr' => 'race_dracthyr_male', 'Earthen' => 'race_earthendwarf_male', 'Haranir' => 'race_harronir_male',
  'High Order Skyborne' => 'achievement_character_bloodelf_male',
  'Windshaper Skyborne' => 'achievement_character_bloodelf_male',
];

const CENSUS_STYLESHEET = '<link rel="stylesheet" href="/census.css">';

// String(value) the way the renderers coerce their inputs.
function js_string(mixed $s): string {
  if (is_int($s) || is_float($s)) return js_str($s);
  if ($s === null) return 'null';
  if ($s === true) return 'true';
  if ($s === false) return 'false';
  return (string)$s;
}

function esc(mixed $s): string {
  return strtr(js_string($s), ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;']);
}

// The browser-side scripts and inline styles live in the generator's own
// modules; read them from there so both renderers ship identical code.
function js_source(string $module): string {
  static $cache = [];
  return $cache[$module] ??= str_replace("\r\n", "\n", file_get_contents(resource_path('src/' . $module)));
}

// Function.prototype.toString of a top-level function in a module.
function js_function_source(string $module, string $name): string {
  $src = js_source($module);
  if (!preg_match('/^(?:export )?(function ' . $name . '\(.*?\n\})/ms', $src, $m)) throw new RuntimeException("function $name");
  return $m[1];
}

// A module's `const NAME = \`...\`;` template literal, interpolations resolved.
function js_template_const(string $module, string $name): string {
  static $cache = [];
  $key = "$module:$name";
  if (isset($cache[$key])) return $cache[$key];
  if (!preg_match('/const ' . $name . ' = `(.*?)`;/s', js_source($module), $m)) throw new RuntimeException("const $name");
  return $cache[$key] = preg_replace_callback('/\$\{(\w+)\}/', function ($ref) use ($module) {
    $id = $ref[1];
    $shared = ['SELECT_SCRIPT' => 'census.mjs', 'FILTER_LOADER' => 'census.mjs'];
    if (isset($shared[$id])) return js_template_const($shared[$id], $id);
    return js_function_source($module, $id);
  }, $m[1]);
}

function filter_loader(): string { return js_template_const('census.mjs', 'FILTER_LOADER'); }
function select_script(): string { return js_template_const('census.mjs', 'SELECT_SCRIPT'); }
function toggle_script(): string { return js_template_const('census.mjs', 'TOGGLE_SCRIPT'); }

// A filter page's records file: { json, file } with a content-hashed name.
function filter_data(string $page, mixed $packed): array {
  $json = js_json($packed);
  return ['json' => $json, 'file' => "data/$page." . substr(hash('sha256', $json), 0, 12) . '.json'];
}

function seo_label(string $gameLabel): string {
  return preg_match('/^WoW\b/i', $gameLabel) ? $gameLabel : 'WoW ' . $gameLabel;
}

function seo_head(string $title, string $description, ?string $canonical): string {
  return '<title>' . esc($title) . "</title>\n" .
    '<meta name="description" content="' . esc($description) . '">' .
    ($canonical ? "\n" . '<link rel="canonical" href="' . esc($canonical) . '">' .
      "\n" . '<meta property="og:type" content="website">' . "\n" . '<meta property="og:site_name" content="WoWCensus">' .
      "\n" . '<meta property="og:title" content="' . esc($title) . '">' . "\n" . '<meta property="og:description" content="' . esc($description) . '">' .
      "\n" . '<meta property="og:url" content="' . esc($canonical) . '">' : '');
}

function page_heading(string $heading, array $navigation, ?string $topic): string {
  return '<h1 class="iname">' . esc($heading) .
    (($navigation['games'] ?? '') !== '' && $topic ? '<span class="itopic">' . esc($topic) . '</span>' : '') . '</h1>';
}

/**
 * Realm names that exist in more than one region, as "<flavor or edition key>|<name>"
 * keys: names the site has collections for in two regions, and Retail names that
 * Blizzard's connected-realm lists hold in both US and EU.
 */
function shared_realm_names(): array {
  static $shared = null;
  if ($shared !== null) return $shared;
  $regions = [];
  foreach (Db::all("SELECT DISTINCT game, source_game FROM datasets WHERE game ~ '^realm:(us|eu|kr|tw|cn):'") as $r)
    if (preg_match('/^realm:([a-z]{2}):([^:]+):(.+?)(?:-(?:Alliance|Horde|Neutral))?$/', $r['game'], $m)) {
      $regions["$m[2]|$m[3]"][$m[1]] = true;
      $regions["$r[source_game]|$m[3]"][$m[1]] = true;
    }
  $retail = json_decode(file_get_contents(__DIR__ . '/retail-connected-realms.json'), true);
  foreach (['us', 'eu'] as $region)
    foreach ($retail[$region] ?? [] as $group) foreach ($group['realms'] as $realm) $regions['retail|' . $realm['name']][$region] = true;
  $shared = array_map(fn() => true, array_filter($regions, fn($r) => count($r) > 1));
  return $shared;
}

/** Whether a realm name needs its region to tell it apart ("Wild Growth (US)"). */
function realm_region_shown(string $flavor, string $name): bool {
  return isset(shared_realm_names()["$flavor|" . preg_replace('/-(Alliance|Horde|Neutral)$/', '', $name)]);
}

// The region is shown only for realm names that exist in more than one region,
// or always with $withRegion.
function realm_name(mixed $name, bool $withRegion = false): string {
  $s = preg_replace('/^realm:/', '', $name === null ? '' : js_string($name));
  if (preg_match('/^(us|eu|kr|tw|cn):([a-z0-9-]+):(.+)$/i', $s, $m))
    return $m[3] . ($withRegion || realm_region_shown($m[2], $m[3]) ? ' (' . strtoupper($m[1]) . ')' : '');
  if (!preg_match('/^Classic Beta\s+/i', $s)) return $s;
  $short = trim(preg_replace('/\s+\d+(?=-|$)/', '', preg_replace('/^Classic Beta\s+/i', '', $s), 1));
  return $short !== '' ? $short : $s;
}

function faction_color(?string $f): string { return FACTION_COLOR[$f] ?? FACTION_COLOR['Unknown']; }
function icon_url(string $name): string { return ICON_ROOT . $name . '.jpg'; }
function class_icon(?string $token): string { return icon_url('classicon_' . js_lower($token ?: 'warrior')); }
function race_icon(string $race): string { return icon_url(RACE_ICON[$race] ?? 'inv_misc_questionmark'); }
function class_name(string $token): string { return CLASS_META[$token][0] ?? $token; }

function sorted_entries(array $dist): array {
  return js_sort(js_entries($dist), fn($a, $b) => js_or($b[1] - $a[1], fn() => js_cmp((string)$a[0], (string)$b[0])));
}

function combo_cards(array $groups, array $opts): string {
  $blocks = [];
  foreach ($groups as $g) {
    $cards = '';
    foreach (array_slice(sorted_entries($g['combos'] ?? []), 0, 3) as [$key, $count]) {
      [$race, $token] = array_pad(explode("\t", (string)$key), 2, null);
      $className = $token !== null && isset(CLASS_META[$token]) ? CLASS_META[$token][0] : js_string($token);
      $share = $g['characters'] ? js_fixed($count / $g['characters'] * 100, 1) : '0.0';
      $raceImage = '<img src="' . esc(race_icon($race)) . '" alt="' . esc($race) . ' icon" loading="lazy">';
      $classImage = '<img src="' . esc(class_icon($token)) . '" alt="' . esc($className) . ' icon" loading="lazy">';
      $racePath = $opts['raceProfiles'][$race]['path'] ?? null;
      $classPath = in_array($token, $opts['classProfiles']['classes'] ?? [], true) && isset($opts['classRoute']) ? ($opts['classRoute'])($token) : null;
      $cards .= '<article class="combocard">' .
        '<div class="comboicons">' . ($racePath ? '<a href="' . esc($racePath) . '" aria-label="' . esc($race) . ' race page">' . $raceImage . '</a>' : $raceImage) .
        ($classPath ? '<a href="' . esc($classPath) . '" aria-label="' . esc($className) . ' class page">' . $classImage . '</a>' : $classImage) . '</div>' .
        '<div><div class="comboname">' . esc($race) . ' ' . esc($className) . '</div>' .
        '<div class="combostat">' . js_locale($count) . ' characters &middot; ' . $share . '% of ' . esc($g['faction']) . '</div></div>' .
        '</article>';
    }
    if ($cards !== '') $blocks[] = '<section class="comboblock" style="--fac:' . faction_color($g['faction']) . '">' .
      '<div class="combofaction">' . esc($g['faction']) . '</div>' . $cards . '</section>';
  }
  $blocks = implode('', $blocks);
  $more = !empty($opts['comboHref'])
    ? '<a class="combo-more" href="' . esc($opts['comboHref']) . '">Explore race and class combinations</a>'
    : '';
  // A side column (role split, compact race panel) stacks the faction blocks on the left.
  if (!empty($opts['side'])) $blocks = '<div class="combocol">' . $blocks . '</div><div class="sidecol">' . $opts['side'] . '</div>';
  return $blocks !== '' ? '<section class="combos"><div class="ptitle combo-title">Most popular race + class' . $more . '</div><div class="combogrid">' . $blocks . '</div></section>' : '';
}

const HEALER_TREES = ['Holy', 'Discipline', 'Restoration'];

// Tank/healer/damage counts for the inspected characters, or null when no tank
// or healer is identified. Spec-based clients report each spec's role; for
// talent-tree clients the main tree decides it, except Feral Combat, which
// bears and cats share, so those builds are left out.
function role_split(array $inspects): ?array {
  $counts = ['TANK' => 0, 'HEALER' => 0, 'DAMAGER' => 0];
  $unassigned = 0;
  foreach ($inspects['records'] as $r) {
    if (($r['classificationMethod'] ?? null) === 'inspected-specialization-id') $role = $r['rawRole'] ?? null;
    elseif (($tree = $r['primaryTree'] ?? null) === null) $role = null;
    elseif ($tree === 'Feral Combat') { $unassigned++; continue; }
    else $role = $tree === 'Protection' ? 'TANK' : (in_array($tree, HEALER_TREES, true) ? 'HEALER' : 'DAMAGER');
    if (isset($counts[$role ?? ''])) $counts[$role]++;
  }
  return $counts['TANK'] || $counts['HEALER'] ? ['counts' => $counts, 'feral' => $unassigned] : null;
}

function role_split_html(array $split): string {
  $counts = $split['counts'];
  $total = array_sum($counts);
  $note = $split['feral'] ? ' (' . js_locale($split['feral']) . ' Feral druids not counted: talents cannot tell tanks from cats)' : '';
  $roles = [['TANK', 'tank', 'Tank'], ['HEALER', 'heal', 'Healer'], ['DAMAGER', 'dps', 'Damage']];
  $tiles = ''; $stack = ''; $label = [];
  foreach ($roles as [$role, $key, $name]) {
    $pct = js_fixed($counts[$role] / $total * 100, 0);
    $tiles .= '<div class="roletile ' . $key . '" title="' . js_locale($counts[$role]) . ' of ' . js_locale($total) . ' inspected characters' . $note . '">' .
      '<i class="roleicon ' . $key . '" aria-hidden="true"></i><div><b>' . $pct . '%</b><span>' . $name . '</span></div></div>';
    $stack .= '<i class="' . $key . '" style="flex:' . $counts[$role] . '"></i>';
    $label[] = $pct . '% ' . strtolower($name);
  }
  return '<section class="roleblock" aria-label="Role split"><div class="rolehead"><div class="combofaction">Role split</div></div>' .
    '<div class="roletiles">' . $tiles . '</div><div class="rolestack" role="img" aria-label="' . implode(', ', $label) . '">' . $stack . '</div></section>';
}

// Population by race, compacted to fit the side column: one list per faction.
function race_compact_html(array $groups, bool $hasRaceWindows): string {
  $grid = function (string $window) use ($groups): string {
    $dist = fn($g) => $g['raceWindows'][$window] ?? $g['races'];
    $max = 0;
    foreach ($groups as $g) foreach ($dist($g) as $n) $max = max($max, $n);
    $cols = '';
    foreach ($groups as $g) {
      $color = faction_color($g['faction']);
      $rows = '';
      foreach (sorted_entries($dist($g)) as [$race, $n]) {
        if ($n <= 0) continue;
        $name = preg_match('/^(High Order|Windshaper) Skyborne$/i', (string)$race) ? 'Skyborne' : (string)$race;
        $rows .= '<div class="rrow"><div class="rlab"><span>' . esc($name) . '</span><b>' . js_locale($n) . '</b></div>' .
          '<div class="rbar"><i style="width:' . js_fixed($n / $max * 100, 2) . '%;background:' . $color . '"></i></div></div>';
      }
      $cols .= '<div class="rcol"><div class="combofaction" style="--fac:' . $color . '">' . esc($g['faction']) . '</div>' . $rows . '</div>';
    }
    return $cols;
  };
  $windows = $hasRaceWindows ? ['all', 'week', 'day'] : ['all'];
  $bars = '';
  foreach ($windows as $window)
    $bars .= '<div class="race-bars rgrid" data-window="' . $window . '"' . ($window === 'all' ? '' : ' hidden') . '>' . $grid($window) . '</div>';
  return '<section class="raceblock race-panel" aria-label="Population by race"><div class="rolehead racehead"><div class="combofaction">Population by race</div>' .
    ($hasRaceWindows ? '<div class="race-toggle" role="group" aria-label="Population by race time range"><button type="button" data-window="all" aria-pressed="true">All</button><button type="button" data-window="week" aria-pressed="false">7 days</button><button type="button" data-window="day" aria-pressed="false">24 hours</button></div>' : '') .
    '</div>' . $bars . '</section>';
}

function combo_label(mixed $key): array {
  [$race, $token] = array_pad(explode("\t", js_string($key ?? '')), 2, null);
  return ['race' => $race, 'token' => $token, 'className' => $token !== null && isset(CLASS_META[$token]) ? CLASS_META[$token][0] : $token];
}

function combo_analysis(array $groups, ?string $flavorNote): string {
  $leaders = [];
  foreach ($groups as $g) {
    $top = sorted_entries($g['combos'] ?? [])[0] ?? null;
    if (!$top) continue;
    $combo = combo_label($top[0]);
    $share = $g['characters'] ? js_fixed($top[1] / $g['characters'] * 100, 1) : '0.0';
    $leaders[] = $combo['race'] . ' ' . js_string($combo['className']) . ' leads the observed ' . $g['faction'] .
      ' sample (' . $share . '% of its characters)';
  }
  return ($flavorNote ? $flavorNote . ' ' : '') . ($leaders ? implode('; ', $leaders) . '.' : '');
}

function combo_breakdown(array $groups, ?array $raceProfiles): string {
  $raceProfiles ??= [];
  $out = '';
  foreach ($groups as $g) {
    $rows = '';
    foreach (sorted_entries($g['combos'] ?? []) as [$key, $count]) {
      $combo = combo_label($key);
      $share = $g['characters'] ? js_fixed($count / $g['characters'] * 100, 1) : '0.0';
      $racePath = $raceProfiles[$combo['race']]['path'] ?? null;
      $raceName = $racePath ? '<a href="' . esc($racePath) . '">' . esc($combo['race']) . '</a>' : esc($combo['race']);
      $rows .= '<tr><td class="combo-name"><span class="combo-name-content"><img src="' . esc(race_icon($combo['race'])) . '" alt="" loading="lazy">' .
        '<img src="' . esc(class_icon($combo['token'])) . '" alt="" loading="lazy"><span class="combo-name-text">' . $raceName . ' ' . esc($combo['className']) .
        '</span></span></td><td>' . js_locale($count) . '</td><td>' . $share . '%</td></tr>';
    }
    $out .= '<section class="panel combo-panel"><div class="ptitle">' . esc($g['faction']) . ' race + class</div>' .
      '<p class="hint" style="margin-top:0">' . js_locale($g['characters']) . ' observed characters, most common first.</p>' .
      '<div class="tablewrap"><table class="combo-table"><thead><tr><th class="l">Combination</th><th>Characters</th><th>Share</th></tr></thead><tbody>' .
      ($rows !== '' ? $rows : '<tr><td colspan="3" class="l">No race + class data yet.</td></tr>') .
      '</tbody></table></div></section>';
  }
  return $out;
}

function bar_row(string $label, int|float $n, int|float $max, string $color, ?string $text): string {
  $pct = $max > 0 ? max($n / $max * 100, 0.6) : 0;
  return '<div class="bar">' .
    '<div class="bl">' . esc($label) . '</div>' .
    '<div class="bt"><i style="width:' . js_fixed($pct, 2) . '%;background:' . $color . '"></i></div>' .
    '<div class="bn">' . esc($text ?? js_locale($n)) . '</div></div>';
}

function bar_chart(array $entries, int|float $max, callable $colorFor, ?callable $labelFor = null, ?callable $valueFor = null): string {
  $out = '';
  foreach ($entries as [$k, $n])
    $out .= bar_row($labelFor ? $labelFor($k) : (string)$k, $n, $max, $colorFor($k), $valueFor ? $valueFor($n, $k) : null);
  return $out;
}

function chip_row(string $dim, array $options, string $active): string {
  if (count($options) < 2) return '';
  $chip = fn($o) => '<button class="chip" type="button" data-key="' . esc($o['key']) . '" aria-pressed="' .
    ($o['key'] === $active ? 'true' : 'false') . '">' . esc($o['label']) . '</button>';
  $all = null;
  foreach ($options as $o) if ($o['key'] === 'all') { $all = $o; break; }
  if ($dim !== 'realm' || !$all) {
    return '<div class="chips vchips" data-dim="' . esc($dim) . '">' . implode('', array_map($chip, $options)) . '</div>';
  }
  $rest = array_values(array_filter($options, fn($o) => $o !== $all));
  $picked = null;
  foreach ($rest as $o) if ($o['key'] === $active) { $picked = $o; break; }
  $id = 'vsel-' . esc($dim);
  $check = '<svg class="vsel-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
  return '<div class="chips vchips vsel-row" data-dim="' . esc($dim) . '">' . $chip($all) .
    '<div class="vsel"><button class="vsel-trigger" type="button" aria-haspopup="listbox" aria-expanded="false" aria-controls="' .
    $id . '" aria-pressed="' . ($picked ? 'true' : 'false') . '"' . ($picked ? '' : ' data-placeholder') . '>' .
    '<span class="vsel-value">' . esc($picked ? $picked['label'] : 'Select a realm') . '</span>' .
    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>' .
    '<div class="vsel-pop" id="' . $id . '" role="listbox" tabindex="-1" aria-label="Realm" hidden>' .
    '<div class="vsel-label" role="presentation">Realms</div>' .
    implode('', array_map(fn($o) => '<div class="vsel-item" role="option" data-key="' . esc($o['key']) . '" aria-selected="' .
      ($o['key'] === $active ? 'true' : 'false') . '"><span>' . esc($o['label']) . '</span>' . $check . '</div>', $rest)) .
    '</div></div></div>';
}

function notes_html(array $notes): string {
  $out = '';
  foreach (array_values($notes) as $i => $note)
    $out .= '<div class="itag" style="' . ($i ? 'margin-top:4px;' : 'margin-top:10px;') . 'line-height:1.6">' . esc($note) . '</div>';
  return $out;
}

function generated_note_html(?string $note): string {
  return $note ? '<div class="itag" style="margin-top:16px;line-height:1.6">' . esc($note) . '</div>' : '';
}

function render_census_view(?array $census, array $opts): string {
  $groups = $census['groups'] ?? [];
  $total = 0; $samples = 0;
  foreach ($groups as $g) { $total += $g['characters']; $samples += $g['samples']; }
  if (!$total && !$samples) {
    return '<div class="panel"><p class="hint">No ' . esc($opts['gameLabel'] ?? 'WoW Forever') . ' population data is in the current snapshot yet. ' .
      'In game, open the Population tab and press Scan Population (or /ml who), then /reload and rebuild the local site snapshot.</p></div>';
  }
  $raceMarkup = function (string $window) use ($groups): string {
    $dist = fn($g) => $g['raceWindows'][$window] ?? $g['races'];
    $max = 0;
    foreach ($groups as $g) foreach ($dist($g) as $n) $max = max($max, $n);
    $out = '';
    foreach ($groups as $g) {
      $entries = array_values(array_filter(sorted_entries($dist($g)), fn($e) => $e[1] > 0));
      $out .= bar_chart($entries, $max, fn() => faction_color($g['faction']),
        fn($k) => js_upper(preg_match('/^(High Order|Windshaper) Skyborne$/i', (string)$k) ? 'Skyborne' : (string)$k));
    }
    return $out !== '' ? $out : '<p class="hint">No race observations in this window.</p>';
  };
  $hasRaceWindows = false;
  foreach ($groups as $g) if (isset($g['raceWindows'])) $hasRaceWindows = true;
  $legend = '';
  foreach ($groups as $g)
    $legend .= '<span class="key"><i style="background:' . faction_color($g['faction']) . '"></i>' . esc($g['faction']) . '</span>';
  $bars = '';
  if ($hasRaceWindows) {
    foreach (['all', 'week', 'day'] as $window)
      $bars .= '<div class="race-bars" data-window="' . $window . '"' . ($window === 'all' ? '' : ' hidden') . '>' . $raceMarkup($window) . '</div>';
  } else $bars = $raceMarkup('all');
  $racePanel = '<div class="panel census race-panel" style="margin-bottom:22px"><div class="race-head"><div><div class="ptitle">Population by race</div>' .
    '<p class="hint" style="margin-top:0">Grouped by faction, most populous first. Windows end at each realm\'s latest scan.</p></div>' .
    ($hasRaceWindows ? '<div class="race-toggle" role="group" aria-label="Population by race time range"><button type="button" data-window="all" aria-pressed="true">All</button><button type="button" data-window="week" aria-pressed="false">7 days</button><button type="button" data-window="day" aria-pressed="false">24 hours</button></div>' : '') . '</div>' .
    '<div class="legend">' . $legend . '</div>' . $bars . '</div>';

  $classPanels = '';
  foreach ($groups as $g) {
    $entries = sorted_entries($g['classes']);
    $max = $entries ? $entries[0][1] : 0;
    $classPanels .= '<div class="panel census"><div class="ptitle">Class distribution &mdash; ' . esc($g['faction']) . '</div>' .
      '<p class="hint" style="margin-top:0">' . js_locale($g['characters']) . ' characters shown</p>' .
      ($entries
        ? bar_chart($entries, $max,
            fn($k) => '#' . (CLASS_META[$k][1] ?? '8b90a0'),
            fn($k) => js_upper(CLASS_META[$k][0] ?? (string)$k))
        : '<p class="hint">No class data yet.</p>') . '</div>';
  }

  $links = '';
  if (!empty($opts['realmHref']))
    foreach ($groups as $g) foreach ($g['games'] as $game)
      $links .= '<a class="game" href="' . esc(($opts['realmHref'])($game)) . '">' . esc(realm_name($game)) . '</a>';
  if (!empty($opts['roleSplit'])) {
    $opts['side'] = role_split_html($opts['roleSplit']) . race_compact_html($groups, $hasRaceWindows);
    $racePanel = '';
  }
  return combo_cards($groups, $opts) . $racePanel . '<div class="chartgrid">' . $classPanels . '</div>' .
    ($links !== '' ? '<div class="ptitle" style="margin:26px 0 12px">Per-realm detail</div><nav class="games">' . $links . '</nav>' : '');
}

// 980 -> "980", 1386 -> "1.4k", 46210 -> "46k".
function compact_count(int $n): string {
  if ($n < 1000) return (string)$n;
  $k = $n / 1000;
  return ($k < 10 ? rtrim(rtrim(number_format($k, 1), '0'), '.') : (string)round($k)) . 'k';
}

function render_combo_breakdown_html(?array $census, array $opts): string {
  $groups = $census['groups'] ?? [];
  $navigation = $opts['nav'];
  $gameLabel = $opts['gameLabel'] ?? 'WoW Forever';
  $heading = ($navigation['games'] ?? '') !== '' ? 'WoWCensus' : $gameLabel . ' — Race + Class';
  $seoGameLabel = seo_label($gameLabel);
  $topic = $seoGameLabel . ' Race & Class Breakdown';
  $notes = notes_html($opts['notes'] ?? []);
  $generatedNote = generated_note_html($opts['generatedNote'] ?? null);
  $analysis = $opts['analysisText'] ?? combo_analysis($groups, $opts['flavorNote'] ?? null);
  $paragraphs = implode('', array_map(fn($p) => '<p class="hint combo-analysis" style="margin:0 0 12px">' . esc($p) . '</p>',
    preg_split('/\n\s*\n/', $analysis)));
  $inspected = $opts['recentInspects'] ?? null;
  $bignum = $inspected ? '<div class="combo-bignum"><b>' . compact_count($inspected) . '</b><small>players inspected recently</small></div>' : '';
  $seo = seo_head($topic . ' – WoWCensus', $seoGameLabel . ' race and class combinations: how often each race plays each class and which pairings are most and least common in /who results.', $opts['canonical'] ?? null);
  $stylesheet = esc($opts['stylesheet'] ?? 'style.css');
  $headingHtml = page_heading($heading, $navigation, $topic);
  $games = $navigation['games'] ?? '';
  $crumbs = ($navigation['breadcrumbs'] ?? '') !== '' ? $navigation['breadcrumbs'] : $notes;
  $pages = $navigation['pages'] ?? '';
  $breakdown = combo_breakdown($groups, $opts['raceProfiles'] ?? null);
  $census_css = CENSUS_STYLESHEET;
  return <<<HTML
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="google-site-verification" content="N0-ZNJyDo16jefYUGxfcAda_mKf7S2oATfRGWETdsHs">
$seo
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96">
<link rel="icon" href="/favicon-96.png" type="image/png" sizes="96x96">
<link rel="preload" href="/fonts/press-start-2p-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/vt323-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/cinzel-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="$stylesheet">$census_css
</head><body><div class="crt" aria-hidden="true"></div><div class="wrap">
<header class="ihead"><div class="page-heading">$headingHtml$games</div>$crumbs</header>
<div class="page-controls">$pages</div>
<section class="panel" style="margin-bottom:22px">$bignum<div class="ptitle">What this sample shows</div>$paragraphs</section>
<div class="combo-breakdown">$breakdown</div>$generatedNote
</div></body></html>
HTML;
}

function render_census_html(array $views, array $opts): string {
  $list = array_values(array_filter($views));
  $active = $list ? $list[0]['key'] : '';
  $chips = chip_row('realm', array_map(fn($v) => ['key' => $v['key'], 'label' => $v['label']], $list), $active);
  $panes = '';
  foreach ($list as $v)
    $panes .= '<div class="vpane" data-realm="' . esc($v['key']) . '"' . ($v['key'] === $active ? '' : ' hidden') . '>' .
      render_census_view($v['census'], $opts) . '</div>';
  $navigation = $opts['nav'];
  $notes = notes_html($opts['notes'] ?? []);
  $generatedNote = generated_note_html($opts['generatedNote'] ?? null);
  $gameLabel = $opts['gameLabel'] ?? 'WoW Forever';
  $heading = ($navigation['games'] ?? '') !== '' ? 'WoWCensus' : ($gameLabel === 'WoW Forever' ? 'WoW Forever Beta' : $gameLabel . ' — Census');
  $seoGameLabel = seo_label($gameLabel);
  $topic = $seoGameLabel . ' Population Census';
  $description = $opts['description'] ?? ($seoGameLabel . ' population census: realm population, faction balance and the race and class mix of characters seen in sampled /who results.');
  $seo = seo_head($opts['title'] ?? ($topic . ' – WoWCensus'), $description, $opts['canonical'] ?? null);
  $stylesheet = esc($opts['stylesheet'] ?? '/style.css');
  $bodyClass = !empty($opts['mobileHome']) ? ' class="census-home"' : '';
  $headingHtml = page_heading($heading, $navigation, $opts['headingTopic'] ?? $topic);
  $games = $navigation['games'] ?? '';
  $crumbs = ($navigation['breadcrumbs'] ?? '') !== '' ? $navigation['breadcrumbs'] : $notes;
  $pages = $navigation['pages'] ?? '';
  $coverage = $opts['inspectCoverage'] ?? '';
  $toggle = $chips !== '' ? toggle_script() : '';
  $census_css = CENSUS_STYLESHEET;
  $notice = !empty($opts['mobileHome']) ? site_notice_html() : '';
  return <<<HTML
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="google-site-verification" content="N0-ZNJyDo16jefYUGxfcAda_mKf7S2oATfRGWETdsHs">
$seo
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96">
<link rel="icon" href="/favicon-96.png" type="image/png" sizes="96x96">
<link rel="preload" href="/fonts/press-start-2p-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/vt323-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/cinzel-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="$stylesheet">
$census_css
</head><body$bodyClass>
<div class="crt" aria-hidden="true"></div>
<div class="wrap">
  <header class="ihead">
    <div class="page-heading">$headingHtml$games</div>
    $crumbs
  </header>
  $notice
  <main>
  <div class="page-controls">$pages$chips</div>
  $panes
  $coverage
  $generatedNote
  </main>
</div>
$toggle
<script>(function(){
  var toggles=[].slice.call(document.querySelectorAll('.race-toggle'));
  function apply(window){toggles.forEach(function(toggle){var panel=toggle.closest('.race-panel');toggle.querySelectorAll('button').forEach(function(b){b.setAttribute('aria-pressed',String(b.dataset.window===window))});panel.querySelectorAll('.race-bars').forEach(function(bars){bars.hidden=bars.dataset.window!==window})})}
  function selected(){var value=new URLSearchParams(location.search).get('race');return value==='week'||value==='day'?value:'all'}
  toggles.forEach(function(toggle){toggle.addEventListener('click',function(event){var button=event.target.closest('button[data-window]');if(!button)return;apply(button.dataset.window);var url=new URL(location.href);if(button.dataset.window==='all')url.searchParams.delete('race');else url.searchParams.set('race',button.dataset.window);history.pushState(null,'',url.pathname+url.search+url.hash)})});
  window.addEventListener('popstate',function(){apply(selected())});apply(selected());
})();</script>
</body></html>
HTML;
}
