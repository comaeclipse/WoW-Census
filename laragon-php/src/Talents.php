<?php
// Port of site/src/talents.mjs and site/src/gear.mjs. The markup functions
// mirror the browser copies the pages embed, which re-render on each filter.

declare(strict_types=1);

const JS_CLASS_NAMES = ['WARRIOR' => 'Warrior', 'PALADIN' => 'Paladin', 'HUNTER' => 'Hunter', 'ROGUE' => 'Rogue', 'PRIEST' => 'Priest', 'SHAMAN' => 'Shaman', 'MAGE' => 'Mage', 'WARLOCK' => 'Warlock', 'DRUID' => 'Druid', 'MONK' => 'Monk', 'DEATHKNIGHT' => 'Death Knight', 'DEMONHUNTER' => 'Demon Hunter', 'EVOKER' => 'Evoker'];

function inspect_time(int|float|null $t): string {
  return $t ? str_replace('T', ' ', substr(js_iso($t), 0, 16)) . 'Z' : 'Unavailable';
}

// The markup functions' own escape, which also covers apostrophes.
function esc_markup(mixed $s): string {
  return strtr($s === null ? '' : js_string($s), ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']);
}

function cls_name(mixed $c): string { return JS_CLASS_NAMES[$c] ?? js_string($c); }

function render_inspect_coverage(array $data, string $href): string {
  $c = $data['coverage'];
  return '<section class="panel" style="margin-top:20px"><div class="ptitle">Inspected talent builds</div>
    <p>' . js_locale($c['inspected']) . ' nearby players inspected · ' . js_locale($c['withSelectedTalents']) . ' with selected talents · ' . js_locale($c['linked']) . ' linked to retained census characters</p>
    <p class="hint">' . js_locale($c['unmatched']) . ' unlinked inspections. Nearby inspect samples have their own coverage and do not measure population-wide specialization shares.</p>
    <p class="hint">Latest inspection: ' . esc(inspect_time($data['lastT'])) . '</p><a class="game" href="' . esc($href) . '">Explore talents</a></section>';
}

const TALENT_PACKED_FIELDS = ['classFile', 'race', 'realm', 'faction', 'classification'];

function pack_ref(array &$values, array &$index, string $f, mixed $v): int {
  if ($v === null || $v === '') return -1;
  $key = (is_string($v) ? 's' : 'n') . js_string($v);
  if (!isset($index[$f][$key])) { $index[$f][$key] = count($values[$f]); $values[$f][] = $v; }
  return $index[$f][$key];
}

function pack_talent_records(array $records): array {
  $values = []; $index = []; $names = []; $nameIndex = [];
  foreach (TALENT_PACKED_FIELDS as $f) { $values[$f] = []; $index[$f] = []; }
  $rows = [];
  foreach ($records as $r) {
    $row = [];
    foreach (TALENT_PACKED_FIELDS as $f) $row[] = pack_ref($values, $index, $f, $r[$f] ?? null);
    $row[] = $r['level'] ?? -1;
    $row[] = !empty($r['hybrid']) ? 1 : 0;
    $refs = [];
    foreach ($r['talents'] as $t) {
      $k = $r['classFile'] . "\t" . js_string($t['node']);
      if (!isset($nameIndex[$k])) { $nameIndex[$k] = count($names); $names[] = $t['name']; }
      $refs[] = $nameIndex[$k];
    }
    $row[] = $refs;
    $rows[] = $row;
  }
  return ['fields' => TALENT_PACKED_FIELDS, 'values' => $values, 'names' => $names, 'rows' => $rows];
}

// Records keep their talents as indexes into $packed['names'], one per class
// and node, rather than expanding each selection into its own array.
function talent_rows(array $packed): array {
  $n = count($packed['fields']);
  $out = [];
  foreach ($packed['rows'] as $row) {
    $r = [];
    foreach ($packed['fields'] as $i => $f) $r[$f] = $row[$i] < 0 ? null : $packed['values'][$f][$row[$i]];
    $r['level'] = $row[$n] < 0 ? null : $row[$n];
    $r['hybrid'] = $row[$n + 1] === 1;
    $r['talents'] = $row[$n + 2];
    $out[] = $r;
  }
  return $out;
}

// Distinct non-empty values of one record field, as the filter selects list them.
function filter_values(array $records, string $key): array {
  $seen = []; $values = [];
  foreach ($records as $r) {
    $v = $r[$key] ?? null;
    if ($v === null || $v === '') continue;
    $k = (is_string($v) ? 's' : 'n') . js_string($v);
    if (!isset($seen[$k])) { $seen[$k] = true; $values[] = $v; }
  }
  return js_sort($values, fn($a, $b) => $key === 'level' ? $a - $b : js_cmp(js_string($a), js_string($b)));
}

function filter_selects(array $records): string {
  $fields = [['classFile', 'Class'], ['race', 'Race'], ['realm', 'Realm'], ['faction', 'Faction'], ['level', 'Level']];
  $out = '';
  foreach ($fields as [$key, $label]) {
    $options = '';
    foreach (filter_values($records, $key) as $v)
      $options .= '<option value="' . esc_markup($v) . '">' . esc_markup($key === 'classFile' ? cls_name($v) : ($key === 'realm' ? realm_name($v) : $v)) . '</option>';
    $out .= '<label>' . esc_markup($label) . '<select class="vsel-native" data-field="' . $key . '" autocomplete="off"><option value="">All</option>' . $options . '</select></label>';
  }
  return $out;
}

// $records come from talent_rows(); $names is the packed talent names.
function talent_markup(string $part, array $records, array $names = [], array $classPaths = []): string {
  if ($part === 'filters') return filter_selects($records);
  $rows = $records;
  $withNodes = array_values(array_filter($rows, fn($r) => count($r['talents']) > 0));
  $classified = array_values(array_filter($rows, fn($r) => ($r['classification'] ?? '') !== '' && $r['classification'] !== 'Unknown build'));
  $talents = []; $denominators = [];
  foreach ($withNodes as $r) $denominators[$r['classFile']] = ($denominators[$r['classFile']] ?? 0) + 1;
  foreach ($withNodes as $r)
    foreach ($r['talents'] as $t) {
      $talents[$t] ??= ['name' => $names[$t], 'classFile' => $r['classFile'], 'players' => 0];
      $talents[$t]['players']++;
    }
  $iconRoot = 'https://wow.zamimg.com/images/wow/icons/large/';
  $factions = [];
  foreach ($rows as $r) if (($r['faction'] ?? '') !== '') $factions[$r['faction']] = true;
  $factions = array_map('strval', array_keys($factions));
  sort($factions, SORT_STRING);
  $distribution = '';
  foreach ($factions as $faction) {
    $members = array_values(array_filter($classified, fn($r) => $r['faction'] === $faction));
    $combos = [];
    foreach ($members as $r) {
      $key = $r['classFile'] . "\t" . $r['classification'];
      $combos[$key] ??= ['n' => 0, 'hybrid' => false];
      $combos[$key]['n']++;
      $combos[$key]['hybrid'] = $combos[$key]['hybrid'] || !empty($r['hybrid']);
    }
    $entries = [];
    foreach ($combos as $key => $n) $entries[] = [(string)$key, $n];
    $entries = js_sort($entries, fn($a, $b) => js_or($b[1]['n'] - $a[1]['n'], fn() => js_cmp($a[0], $b[0])));
    $body = '';
    foreach ($entries as [$key, $n]) {
      [$c, $tree] = array_pad(explode("\t", $key), 2, '');
      $classTotal = count(array_filter($members, fn($r) => $r['classFile'] === $c));
      $share = js_fixed(100 * $n['n'] / $classTotal, 1);
      $label = '<img src="' . $iconRoot . 'classicon_' . esc_markup(js_lower($c)) . '.jpg" alt="" loading="lazy">' . esc_markup(cls_name($c)) . ' · ' . esc_markup($tree) . ($n['hybrid'] ? ' Hybrid' : '');
      $anchor = preg_replace('/^-|-$/', '', preg_replace('/[^a-z0-9]+/', '-', js_lower($tree)));
      $body .= '<tr><td class="combo-name">' . (!empty($classPaths[$c]) ? '<a href="' . esc_markup($classPaths[$c] . '#' . $anchor) . '">' . $label . '</a>' : $label) .
        '</td><td>' . js_locale($n['n']) . '</td><td>' . $share . '%</td></tr>';
    }
    $distribution .= '<section class="panel combo-panel"><div class="ptitle">' . esc_markup($faction) . ' class + build</div>' .
      ($members ? '<div class="tablewrap"><table class="combo-table talent-combo-table"><thead><tr><th class="l">Combination</th><th>Players</th><th>%</th></tr></thead><tbody>' . $body . '</tbody></table></div>' : '') . '</section>';
  }
  $popular = '';
  $list = js_sort(array_values($talents), fn($a, $b) => js_or($b['players'] / $denominators[$b['classFile']] - $a['players'] / $denominators[$a['classFile']],
    fn() => js_or($b['players'] - $a['players'], fn() => js_cmp(js_string($a['name']), js_string($b['name'])))));
  foreach ($list as $t) {
    $d = $denominators[$t['classFile']];
    $pct = js_fixed(100 * $t['players'] / $d, 1);
    $popular .= '<tr><td class="l">' . esc_markup(cls_name($t['classFile'])) . '</td><td class="l">' . esc_markup($t['name']) . '</td><td>' . $t['players'] . ' / ' . $d . ' (' . $pct . '%)</td></tr>';
  }
  if (!$rows) return '<section class="panel"><p class="hint talent-empty">No inspections available for these filters.</p></section>';
  return '<div class="combo-breakdown">' . $distribution . '</div>' . ($withNodes
    ? '<section class="panel talent-section"><div class="ptitle">Selected talent popularity</div><p class="hint" style="margin-top:0">Shares use players with readable nodes in each filtered class. ' .
      (count($rows) - count($withNodes)) . ' inspected player(s) without readable talent selections excluded.</p><div class="tablewrap"><table class="talent-popularity-table"><thead><tr><th class="l">Class</th><th class="l">Talent</th><th>Players selecting</th></tr></thead><tbody>' . $popular . '</tbody></table></div></section>'
    : '');
}

// $data is inspect_summary(); $packed is talent_page_model().
function render_talents_html(array $data, array $packed, array $opts): string {
  $nav = $opts['nav'] ?? [];
  $seoGameLabel = seo_label($opts['gameLabel'] ?? 'WoW Forever');
  $topic = $seoGameLabel . ' Talent Builds & Popular Specs';
  $dataFile = filter_data('talents', $packed)['file'];
  $records = talent_rows($packed);
  $classPaths = [];
  foreach ($opts['classTiles'] ?? [] as $p) if (!empty($p['path'])) $classPaths[$p['classFile']] = $p['path'];
  $seo = seo_head($topic . ' – WoWCensus', $seoGameLabel . ' talent builds from inspected players: the most common builds per class, tree point totals and how often each talent is picked.', $opts['canonical'] ?? null);
  $stylesheet = esc($opts['stylesheet'] ?? '/style.css');
  $census_css = CENSUS_STYLESHEET;
  $headingHtml = page_heading('WoWCensus', $nav, $topic);
  $games = $nav['games'] ?? '';
  $crumbs = $nav['breadcrumbs'] ?? '';
  $pages = $nav['pages'] ?? '';
  $tiles = '';
  $anyFilterTile = false;
  foreach ($opts['classTiles'] ?? [] as $p) {
    if (empty($p['path'])) $anyFilterTile = true;
    $tiles .= (!empty($p['path'])
      ? '<a class="chip" href="' . esc($p['path']) . '">'
      : '<button class="chip" type="button" data-class="' . esc($p['classFile']) . '" aria-pressed="false" aria-label="Filter ' . esc($p['label']) . ' talents">') .
      "\n      " . '<img src="https://wow.zamimg.com/images/wow/icons/large/' . esc($p['icon']) . '.jpg" alt="" width="20" height="20"><span>' . esc($p['label']) . '</span>' . (!empty($p['path']) ? '</a>' : '</button>');
  }
  $filters = talent_markup('filters', $records);
  $summary = $anyFilterTile ? '<p class="hint talent-summary" style="margin:12px 0 0">' . js_locale($data['coverage']['inspected']) . ' nearby players inspected in the latest ' . $data['windowDays'] . ' days; ' .
    js_locale($data['coverage']['withSelectedTalents']) . ' have readable talent selections. Build shares use only classified inspections and do not describe the full character population. Latest inspection: ' .
    esc(inspect_time($data['lastT'])) . '.' . ($data['truncated'] ? ' Results are limited to the newest 10,000 inspected players.' : '') . '</p>' : '';
  $dataSrc = esc($dataFile);
  $classPathsAttr = esc(js_json((object)$classPaths));
  $content = talent_markup('content', $records, $packed['names'], $classPaths);
  $generated = !empty($opts['generatedNote']) ? '<div class="itag" style="margin-top:16px;line-height:1.6">' . esc($opts['generatedNote']) . '</div>' : '';
  $script = js_template_const('talents.mjs', 'TALENT_SCRIPT');
  $select = select_script();
  return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    $seo
    <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96"><link rel="icon" href="/favicon-96.png" type="image/png" sizes="96x96">
    <link rel="preload" href="/fonts/press-start-2p-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/fonts/vt323-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/fonts/cinzel-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/fonts/friz-quadrata-regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="$stylesheet">
    $census_css
    <style>
    .talent-summary{font-family:"Friz Quadrata Web","Friz Quadrata","Friz Quadrata Std","Fritz Quadrata","Cinzel",Georgia,serif;font-size:16px;font-weight:400;letter-spacing:normal;line-height:1.66;color:#ccc;text-align:left}
    .talent-filters{display:flex;flex-wrap:wrap;gap:14px;margin-top:14px}.talent-filters label{display:grid;gap:5px;font-size:17px;color:var(--muted)}
    .talent-filters select{color:var(--ink);background:var(--panel2);border:2px solid var(--line);font-family:var(--term);font-size:18px;padding:6px 10px;max-width:230px}
    .talent-section{margin-top:22px}.talent-empty{margin:0}
    .talent-combo-table{min-width:0}.talent-combo-table th:first-child,.talent-combo-table td:first-child{width:72%}
    .talent-combo-table th:nth-child(2),.talent-combo-table td:nth-child(2){width:12%}
    .talent-combo-table th:nth-child(3),.talent-combo-table td:nth-child(3){width:16%}
    .talent-combo-table th:not(:first-child),.talent-combo-table td:not(:first-child){padding-left:6px;padding-right:6px}
    .talent-combo-table .combo-name{display:table-cell;text-align:left;white-space:normal;overflow:visible;text-overflow:clip;line-height:1.4}
    .talent-combo-table .combo-name img{vertical-align:middle;margin-right:7px;flex:none}
    .talent-combo-table .combo-name a{color:inherit;text-decoration:none}.talent-combo-table .combo-name a:hover,.talent-combo-table .combo-name a:focus-visible{text-decoration:underline}
    .talent-popularity-table{table-layout:auto}.talent-popularity-table td:nth-child(2){white-space:normal;text-overflow:clip}
    @media(max-width:1050px){.combo-breakdown{grid-template-columns:1fr}.combo-panel{margin-bottom:22px}.combo-panel:last-child{margin-bottom:0}}
    @media(max-width:600px){.talent-filters label{flex:1 1 40%}.talent-filters select{max-width:100%;width:100%}.talent-filters .vsel-trigger{width:100%}
      .talent-combo-table th:first-child,.talent-combo-table td:first-child{width:50%}
      .talent-combo-table th:nth-child(2),.talent-combo-table td:nth-child(2){width:26%}
      .talent-combo-table th:nth-child(3),.talent-combo-table td:nth-child(3){width:24%}}
    </style></head><body><div class="crt" aria-hidden="true"></div><div class="wrap">
    <header class="ihead"><div class="page-heading">$headingHtml$games</div>$crumbs</header>
    <div class="page-controls">$pages</div>
    <section class="panel" style="margin-bottom:22px">
    <div class="chips class-chip-links talent-class-chips" aria-label="Class">$tiles</div>
    <div class="talent-filters" id="talent-filters">$filters</div>
    $summary</section>
    <div id="talent-content" aria-live="polite" data-src="$dataSrc" data-class-paths="$classPathsAttr">$content</div>
    $generated</div>
    <script>$script</script>$select</body></html>
HTML;
}

const GEAR_PACKED_FIELDS = ['classFile', 'race', 'realm', 'faction'];

function pack_gear_records(array $records): array {
  $values = []; $index = []; $items = []; $itemIndex = []; $runes = []; $runeIndex = [];
  foreach (GEAR_PACKED_FIELDS as $f) { $values[$f] = []; $index[$f] = []; }
  $rows = [];
  foreach ($records as $r) {
    if (empty($r['gear'])) continue;
    $row = [];
    foreach (GEAR_PACKED_FIELDS as $f) $row[] = pack_ref($values, $index, $f, $r[$f] ?? null);
    $row[] = $r['level'] ?? -1;
    $worn = [];
    foreach ($r['gear'] as $g) {
      $key = js_string($g['id']);
      if (!isset($itemIndex[$key])) { $itemIndex[$key] = count($items); $items[] = [$g['id'], $g['name'], $g['quality'] ?? -1, ($g['icon'] ?? '') ?: '']; }
      $w = [$g['slot'], $itemIndex[$key], !empty($g['enchant']) ? 1 : 0];
      // SoD rune: a fourth entry indexing `runes`, only on items that have one.
      if (($g['rune'] ?? '') !== '') {
        if (!isset($runeIndex[$g['rune']])) { $runeIndex[$g['rune']] = count($runes); $runes[] = $g['rune']; }
        $w[] = $runeIndex[$g['rune']];
      }
      $worn[] = $w;
    }
    $row[] = $worn;
    $rows[] = $row;
  }
  $packed = ['fields' => GEAR_PACKED_FIELDS, 'values' => $values, 'items' => $items, 'rows' => $rows];
  if ($runes) $packed['runes'] = $runes;
  return $packed;
}

// Records keep their worn items packed as [slot, item index, enchanted, rune
// index?]: expanding every item of every record costs more memory than the
// origin has.
function unpack_gear_records(array $packed): array {
  $n = count($packed['fields']);
  $out = [];
  foreach ($packed['rows'] as $row) {
    $r = [];
    foreach ($packed['fields'] as $i => $f) $r[$f] = $row[$i] < 0 ? null : $packed['values'][$f][$row[$i]];
    $r['level'] = $row[$n] < 0 ? null : $row[$n];
    $r['gear'] = $row[$n + 1];
    $out[] = $r;
  }
  return $out;
}

function pack_gear_page(array $data, string $branch): array {
  $packed = pack_gear_records($data['records']);
  $packed['wowhead'] = 'https://www.wowhead.com/' . ($branch !== '' ? $branch . '/' : '') . 'item=';
  return $packed;
}

function gear_markup(string $part, array $records, array $packed, string $selected = '', bool $cobalt = false): string {
  if ($part === 'filters') return filter_selects($records);
  $wowhead = $packed['wowhead'];
  if (!$records) return $part === 'top' ? '<p class="hint">No inspected gear for these filters.</p>' : '<section class="panel"><p class="hint" style="margin:0">No inspected gear for these filters.</p></section>';
  $groups = [['Head', [1]], ['Neck', [2]], ['Shoulder', [3]], ['Back', [15]], ['Chest', [5]], ['Wrist', [9]], ['Hands', [10]], ['Waist', [6]], ['Legs', [7]], ['Feet', [8]],
    ['Rings', [11, 12]], ['Trinkets', [13, 14]], ['Main hand', [16]], ['Off hand', [17]], ['Ranged', [18]], ['Shirt', [4]], ['Tabard', [19]]];
  if ($cobalt && $part === 'top') $groups = array_merge(array_slice($groups, 0, 12), array_slice($groups, 14), array_slice($groups, 12, 2));
  $total = count($records);
  $tiles = '';
  foreach ($groups as [$label, $slots]) {
    if ($part === 'content' && $selected !== '' && $selected !== $label) continue;
    $items = []; $runes = []; $iconRoot = 'https://wow.zamimg.com/images/wow/icons/small/'; $filled = 0; $enchanted = 0; $runed = 0;
    foreach ($records as $r) {
      $worn = array_values(array_filter($r['gear'], fn($w) => in_array($w[0], $slots, true)));
      if (!$worn) continue;
      $filled++;
      foreach ($worn as $w) if ($w[2] === 1) { $enchanted++; break; }
      $seen = [];
      foreach ($worn as $w) {
        // Packing gave each item id one index.
        if (isset($seen[$w[1]])) continue;
        $seen[$w[1]] = true;
        $it = $packed['items'][$w[1]];
        $items[$w[1]] ??= ['id' => $it[0], 'name' => $it[1], 'quality' => $it[2] < 0 ? null : $it[2], 'icon' => $it[3] ?: null, 'players' => 0];
        $items[$w[1]]['players']++;
      }
      // Season of Discovery: each player counts once per rune; paired rings pool.
      $wornRunes = array_values(array_unique(array_filter(array_map(fn($w) => isset($w[3]) ? $packed['runes'][$w[3]] : null, $worn), fn($n) => ($n ?? '') !== '')));
      if ($wornRunes) $runed++;
      foreach ($wornRunes as $name) $runes[$name] = ($runes[$name] ?? 0) + 1;
    }
    if (!$filled) continue;
    $top = array_slice(js_sort(array_values($items), fn($a, $b) => js_or($b['players'] - $a['players'], fn() => js_cmp(js_string($a['name']), js_string($b['name'])))), 0, 10);
    if ($part === 'top') {
      $it = $top[0];
      $slotHref = $cobalt ? '?' . http_build_query(array_merge($_GET, ['slot' => $label])) : $wowhead . js_string($it['id']);
      $external = $cobalt ? '' : ' target="_blank" rel="noopener"';
      $tiles .= '<a class="gear-top-item" href="' . esc_markup($slotHref) . '"' . $external . '><span class="gear-top-slot">' . esc_markup($label) .
        '</span><img src="https://wow.zamimg.com/images/wow/icons/large/' . esc_markup($it['icon'] ?: 'inv_misc_questionmark') . '.jpg" alt="" width="40" height="40"><span class="gear-top-name iq' .
        js_string($it['quality'] ?? 1) . '">' . esc_markup($it['name']) . '</span><span class="gear-top-count">' . js_locale($it['players']) . ' players</span></a>';
      continue;
    }
    $body = '';
    foreach ($top as $it)
      $body .= '<tr><td class="l"><a class="iq' . js_string($it['quality'] ?? 1) . '" href="' . esc_markup($wowhead . js_string($it['id'])) . '" target="_blank" rel="noopener"><img src="' . $iconRoot .
        esc_markup($it['icon'] ?: 'inv_misc_questionmark') . '.jpg" alt="" width="20" height="20" loading="lazy"><span>' . esc_markup($it['name']) . '</span></a></td><td>' .
        js_locale($it['players']) . '</td><td>' . js_fixed(100 * $it['players'] / $filled, 1) . '%</td></tr>';
    // Shares are of players with a rune recorded here: older inspects carry none.
    $runeTable = '';
    if ($runed) {
      $pairs = [];
      foreach ($runes as $name => $n) $pairs[] = [(string)$name, $n];
      $runeRows = '';
      foreach (array_slice(js_sort($pairs, fn($a, $b) => js_or($b[1] - $a[1], fn() => js_cmp($a[0], $b[0]))), 0, 10) as [$name, $n])
        $runeRows .= '<tr><td class="l gear-rune">' . esc_markup($name) . '</td><td>' . js_locale($n) . '</td><td>' . js_fixed(100 * $n / $runed, 1) . '%</td></tr>';
      $runeTable = '<p class="hint gear-rune-hint">Runes · ' . js_locale($runed) . ' player' . ($runed === 1 ? '' : 's') . ' with a rune recorded</p><div class="tablewrap"><table class="gear-table"><thead><tr><th class="l">Rune</th><th>Players</th><th>%</th></tr></thead><tbody>' . $runeRows . '</tbody></table></div>';
    }
    $tiles .= '<section class="panel gear-slot"><div class="ptitle">' . esc_markup($label) . '</div><p class="hint">' . js_locale($filled) . ' of ' . js_locale($total) .
      ' players with this slot filled · ' . js_fixed(100 * $enchanted / $filled, 0) . '% enchanted · ' . js_locale(count($items)) . ' distinct item' . (count($items) === 1 ? '' : 's') .
      '</p><div class="tablewrap"><table class="gear-table"><thead><tr><th class="l">Item</th><th>Players</th><th>%</th></tr></thead><tbody>' . $body . '</tbody></table></div>' . $runeTable . '</section>';
  }
  return '<div class="' . ($part === 'top' ? 'gear-top-grid' : 'gear-grid') . '">' . $tiles . '</div>';
}

// $model is gear_page_model(): the packed gear records and lastT.
function render_gear_html(array $model, array $opts): string {
  $nav = $opts['nav'] ?? [];
  $seoGameLabel = seo_label($opts['gameLabel'] ?? 'WoW Forever');
  $topic = $seoGameLabel . ' Gear: Most Equipped Items';
  $packed = $model['packed'];
  $dataFile = filter_data('gear', $packed)['file'];
  $records = unpack_gear_records($packed);
  $withGear = count($records);
  $summary = $withGear
    ? 'Latest inspection: ' . esc(inspect_time($model['lastT']))
    : 'No inspected gear for ' . esc($opts['gameLabel'] ?? 'this edition') . ' yet. Gear is collected during the same inspection as talents and will appear in a future snapshot when available.';
  $seo = seo_head($topic . ' – WoWCensus', $seoGameLabel . ' gear seen on inspected players: the most equipped items in every slot for each class and spec, and how often they are enchanted.', $opts['canonical'] ?? null);
  $stylesheet = esc($opts['stylesheet'] ?? '/style.css');
  $census_css = CENSUS_STYLESHEET;
  $headingHtml = page_heading('WoWCensus', $nav, $topic);
  $games = $nav['games'] ?? '';
  $crumbs = $nav['breadcrumbs'] ?? '';
  $pages = $nav['pages'] ?? '';
  $cobalt = ($_COOKIE['wowcensus_skin'] ?? '') === 'cobalt';
  if ($cobalt && $withGear) {
    $allRecords = $records;
    foreach (['classFile', 'race', 'realm', 'faction', 'level'] as $field) {
      $value = $_GET[$field] ?? '';
      if (!is_scalar($value) || (string)$value === '') continue;
      $records = array_values(array_filter($records, static fn($r) => (string)($r[$field] ?? '') === (string)$value));
    }
    $selected = $_GET['slot'] ?? 'Head';
    $labels = ['Head','Neck','Shoulder','Back','Chest','Wrist','Hands','Waist','Legs','Feet','Rings','Trinkets','Main hand','Off hand','Ranged','Shirt','Tabard'];
    if (!is_string($selected) || !in_array($selected, $labels, true)) $selected = 'Head';
    $filters = filter_selects($allRecords);
    $panel = '<section class="panel gear-top-panel"><div class="ptitle">Character equipment <small>Most equipped items in the inspection sample</small></div><p class="gear-context">Each slot shows its most observed item across inspected characters. This is an aggregate view, not one character\'s equipment.</p><div id="gear-top">' . gear_markup('top', $records, $packed, '', true) . '</div><form class="gear-filters" id="gear-filters" method="get">' . $filters . '<input type="hidden" name="slot" value="' . esc_markup($selected) . '"><button type="submit">Apply filters</button></form><p class="hint gear-snapshot">' . $summary . ' · ' . js_locale(count($records)) . ' matching inspected characters</p></section>';
    $content = '<div id="gear-content" class="gear-detail"><div class="gear-detail-heading"><h2>' . esc_markup($selected) . ' items</h2><p>Select another slot on the equipment sheet to see its leading items.</p></div>' . gear_markup('content', $records, $packed, $selected, true) . '</div>';
    $generated = !empty($opts['generatedNote']) ? '<div class="itag" style="margin-top:16px;line-height:1.6">' . esc($opts['generatedNote']) . '</div>' : '';
    $scripts = '<script>document.querySelectorAll("#gear-filters select").forEach(s=>{const v=new URLSearchParams(location.search).get(s.dataset.field);if(v!==null)s.value=v;});</script>';
  } else {
  $panel = $withGear
    ? '<section class="panel gear-top-panel"><div class="ptitle">Most equipped by slot</div><div id="gear-top">' . gear_markup('top', $records, $packed) . '</div><div class="gear-filters" id="gear-filters">' . gear_markup('filters', $records, $packed) . '</div><p class="hint" style="margin:14px 0 0">' . $summary . '</p></section>'
    : '<section class="panel" style="margin-bottom:22px"><p class="hint gear-summary" style="margin:0">' . $summary . '</p></section>';
  $content = $withGear ? '<div id="gear-content" aria-live="polite" data-src="' . esc($dataFile) . '">' . gear_markup('content', $records, $packed) . '</div>' : '';
  $generated = !empty($opts['generatedNote']) ? '<div class="itag" style="margin-top:16px;line-height:1.6">' . esc($opts['generatedNote']) . '</div>' : '';
  $scripts = $withGear ? '<script>' . js_template_const('gear.mjs', 'GEAR_SCRIPT') . '</script>' . select_script() . "\n" .
    '    <script>const whTooltips={colorLinks:false,iconizeLinks:false,renameLinks:false};</script><script src="https://wow.zamimg.com/js/tooltips.js" async></script>' : '';
  }
  return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    $seo
    <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96"><link rel="icon" href="/favicon-96.png" type="image/png" sizes="96x96">
    <link rel="preload" href="/fonts/press-start-2p-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/fonts/vt323-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/fonts/cinzel-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/fonts/friz-quadrata-regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="$stylesheet">
    $census_css
    <style>
    .gear-summary{font-family:"Friz Quadrata Web","Friz Quadrata","Friz Quadrata Std","Fritz Quadrata","Cinzel",Georgia,serif;font-size:16px;font-weight:400;letter-spacing:normal;line-height:1.66;color:#ccc;text-align:left}
    .gear-filters{display:flex;flex-wrap:wrap;gap:14px;margin-top:14px}.gear-filters label{display:grid;gap:5px;font-size:17px;color:var(--muted)}
    .gear-filters select{color:var(--ink);background:var(--panel2);border:2px solid var(--line);font-family:var(--term);font-size:18px;padding:6px 10px;max-width:230px}
    .gear-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px}
    .gear-top-panel{margin-bottom:22px}.gear-top-grid{display:grid;grid-template-columns:repeat(9,minmax(0,1fr));gap:8px}
    .gear-top-item{min-width:0;min-height:144px;display:flex;flex-direction:column;align-items:center;text-align:center;padding:10px 5px;background:var(--panel2);border:2px solid var(--line);box-shadow:3px 3px 0 var(--shadow);text-decoration:none}
    .gear-top-item:hover,.gear-top-item:focus-visible{border-color:var(--gold)}
    .gear-top-slot{font-family:var(--pixel);font-size:8px;line-height:1.5;letter-spacing:1px;text-transform:uppercase;color:var(--muted);min-height:25px}
    .gear-top-item img{border:2px solid #000;box-shadow:0 0 0 1px var(--line);margin:3px 0 6px}
    .gear-top-name{font-family:var(--term);font-size:18px;line-height:1.05;overflow-wrap:anywhere}
    .gear-top-count{font-family:var(--term);font-size:15px;color:var(--muted);margin-top:auto;padding-top:5px}
    .gear-slot .hint{margin:0 0 8px}.gear-slot .gear-rune-hint{margin:16px 0 8px}.gear-table td.gear-rune{color:#1eff00}
    .gear-table{table-layout:fixed;min-width:0}.gear-table th:first-child,.gear-table td:first-child{width:54%}
    .gear-table th:not(:first-child),.gear-table td:not(:first-child){padding-left:6px;padding-right:6px}
    .gear-table th:nth-child(2),.gear-table td:nth-child(2){width:26%}.gear-table th:nth-child(3),.gear-table td:nth-child(3){width:20%}
    .gear-table td:first-child{white-space:normal;line-height:1.35}
    .gear-table a{text-decoration:none;display:inline-flex;align-items:center;gap:8px}.gear-table a:hover span{text-decoration:underline}
    .gear-table img{width:20px;height:20px;flex:none;border:1px solid #000;box-shadow:0 0 0 1px var(--line)}
    .wowhead-tooltip table{width:auto;min-width:0;table-layout:auto}
    .wowhead-tooltip td,.wowhead-tooltip th{white-space:normal;overflow:visible;text-overflow:clip;border-bottom:0;font-variant-numeric:normal}
    .wowhead-tooltip tr:hover{background:none}
    .iq0{color:#9d9d9d}.iq1{color:#fff}.iq2{color:#1eff00}.iq3{color:#0070dd}.iq4{color:#a335ee}.iq5{color:#ff8000}.iq6,.iq7{color:#e6cc80}
    @media(max-width:1050px){.gear-grid{grid-template-columns:1fr}}
    @media(max-width:900px){.gear-top-grid{grid-template-columns:repeat(5,minmax(0,1fr))}}
    @media(max-width:600px){.gear-filters label{flex:1 1 40%}.gear-filters select{max-width:100%;width:100%}.gear-filters .vsel-trigger{width:100%}
      .gear-top-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.gear-top-item{min-height:132px}
      .gear-table th:first-child,.gear-table td:first-child{width:46%}.gear-table th:nth-child(2),.gear-table td:nth-child(2){width:31%}.gear-table th:nth-child(3),.gear-table td:nth-child(3){width:23%}}
    </style></head><body><div class="crt" aria-hidden="true"></div><div class="wrap">
    <header class="ihead"><div class="page-heading">$headingHtml$games</div>$crumbs</header>
    <div class="page-controls">$pages</div>
    $panel
    $content
    $generated</div>
    $scripts</body></html>
HTML;
}
