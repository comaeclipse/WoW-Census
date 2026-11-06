<?php
// Port of site/src/market.mjs: the auction-house overview.

declare(strict_types=1);

function money(int|float|null $cop): string {
  $cop = js_round($cop ?: 0);
  $g = (int)floor($cop / 10000); $s = (int)floor(fmod($cop, 10000) / 100); $c = fmod($cop, 100);
  $c = js_num(js_str($c));
  if ($g > 0) return js_locale($g) . 'g ' . $s . 's';
  if ($s > 0) return $s . 's ' . js_str($c) . 'c';
  return js_str($c) . 'c';
}

function big_gold(int|float|null $cop): string {
  $g = floor(($cop ?: 0) / 10000);
  if ($g >= 1e6) return js_fixed($g / 1e6, 1) . 'M g';
  if ($g >= 1e3) return js_str(js_round($g / 1e3)) . 'k g';
  return js_locale(js_num(js_str($g))) . ' g';
}

function item_label(array $it): string {
  return preg_match('/^item:(\d+)$/', (string)($it['name'] ?? ''), $m) ? 'Item ' . $m[1] : js_string($it['name']);
}

function item_link(array $it, string $branch): string {
  $href = 'https://www.wowhead.com/' . ($branch !== '' ? $branch . '/' : '') . 'item=' . js_string($it['id']);
  return '<a href="' . esc($href) . '" target="_blank" rel="noopener">' . esc(item_label($it)) . '</a>';
}

function qty_change(array $it): string {
  if ($it['pq'] === null) return '<span class="mu">&mdash;</span>';
  if ($it['pq'] === 0) return ($it['q'] ?: 0) > 0 ? '<span class="gr">NEW</span>' : '<span class="mu">0%</span>';
  $pct = js_round((($it['q'] ?: 0) - $it['pq']) / $it['pq'] * 100);
  $cls = $pct > 0 ? 'gr' : ($pct < 0 ? 'rd' : 'mu');
  return '<span class="' . $cls . '">' . ($pct > 0 ? '+' : '') . js_locale($pct) . '%</span>';
}

function top_table(string $title, string $hint, array $items, string $branch, array $cols): string {
  $head = implode('', array_map(fn($c) => '<th class="' . (!empty($c['left']) ? 'l' : 'r') . '">' . esc($c['label']) . '</th>', $cols));
  $body = '';
  foreach ($items as $it) {
    $body .= '<tr>';
    foreach ($cols as $c) $body .= '<td class="' . (!empty($c['left']) ? 'l' : 'r') . '">' . ($c['cell'])($it, $branch) . '</td>';
    $body .= '</tr>';
  }
  return '<div class="panel census mkt"><div class="ptitle">' . esc($title) . '</div>' .
    '<p class="hint" style="margin-top:0">' . esc($hint) . '</p>' .
    '<table class="mkttable"><thead><tr>' . $head . '</tr></thead><tbody>' .
    ($body !== '' ? $body : '<tr><td class="l mu" colspan="' . count($cols) . '" style="padding:16px">No data.</td></tr>') .
    '</tbody></table></div>';
}

const JOKE_FLOOR = 500 * 10000;
function joke_price_threshold(array $items): int|float {
  $prices = [];
  foreach ($items as $it) if (($it['q'] ?: 0) > 0 && ($it['asp'] ?: 0) > 0) $prices[] = $it['asp'];
  if (!$prices) return INF;
  sort($prices);
  $p99 = $prices[(int)floor(0.99 * (count($prices) - 1))];
  return max(50 * $p99, JOKE_FLOOR);
}

function sort_desc_by(array $items, string $field): array {
  return js_sort($items, fn($a, $b) => ($b[$field] ?: 0) - ($a[$field] ?: 0));
}

// What one auction view shows: totals, the leading markets and the few items
// its tables list. The compact origin publishes these rather than every item
// of every view, which took more memory than the origin droplet has.
function market_view_summary(?array $snapshot, int|float $threshold): array {
  $all = $snapshot['items'] ?? [];
  $aggregateOnly = !empty($snapshot['aggregateOnly']);
  $joke = $aggregateOnly ? [] : sort_desc_by(array_values(array_filter($all, fn($it) => ($it['asp'] ?: 0) > $threshold)), 'asp');
  $items = $joke ? array_values(array_filter($all, fn($it) => ($it['asp'] ?: 0) <= $threshold)) : $all;

  $totalValue = 0; $totalQty = 0;
  foreach ($items as $it) { $totalValue += $it['mv'] ?: 0; $totalQty += $it['q'] ?: 0; }

  $cats = [];
  foreach ($items as $it) {
    $k = $it['cat'] ?: 'Uncategorized';
    $cats[$k] = ($cats[$k] ?? 0) + ($aggregateOnly ? ($it['q'] ?: 0) : ($it['mv'] ?: 0));
  }

  $breakouts = [];
  foreach ($items as $it)
    if (($it['q'] ?: 0) >= 500 && ($it['pq'] ?: 0) >= 100) {
      $it['pct'] = js_round(($it['q'] - $it['pq']) / $it['pq'] * 100);
      if ($it['pct'] > 0) $breakouts[] = $it;
    }

  return [
    'realms' => $snapshot['realms'] ?? [], 'branch' => $snapshot['branch'] ?? '', 'updatedAt' => $snapshot['updatedAt'] ?? null,
    'aggregateOnly' => $aggregateOnly, 'itemCount' => count($items), 'totalValue' => $totalValue, 'totalQty' => $totalQty,
    'byQty' => array_slice(sort_desc_by($items, 'q'), 0, 15),
    'byPrice' => array_slice(sort_desc_by(array_values(array_filter($items, fn($it) => ($it['q'] ?: 0) > 0)), 'asp'), 0, 15),
    'byValue' => array_slice(sort_desc_by($items, 'mv'), 0, 15),
    'cats' => array_slice(sorted_entries($cats), 0, 14),
    'breakouts' => array_slice(js_sort($breakouts, fn($a, $b) => js_or($b['pct'] - $a['pct'], fn() => $b['q'] - $a['q'])), 0, 2),
    'joke' => array_slice($joke, 0, 10),
    // INF, when nothing is a joke listing, has no JSON form; it is shown only beside jokes.
    'jokeThreshold' => $joke ? $threshold : null,
  ];
}

// Each view's summary, with joke listings judged by the widest view's prices.
function market_view_summaries(array $views): array {
  $widest = ['items' => []];
  foreach ($views as $v) if (count($v['snapshot']['items'] ?? []) > count($widest['items'] ?? [])) $widest = $v['snapshot'];
  $threshold = joke_price_threshold($widest['items']);
  return array_map(fn($v) => ['realm' => $v['realm'], 'faction' => $v['faction'], 'summary' => market_view_summary($v['snapshot'], $threshold)], $views);
}

function render_market_view(array $summary, string $analysisLabel = ''): string {
  $aggregateOnly = $summary['aggregateOnly'];
  $joke = $summary['joke'];
  $realms = $summary['realms'];
  $branch = $summary['branch'];
  $updatedAt = $summary['updatedAt'];
  $totalValue = $summary['totalValue']; $totalQty = $summary['totalQty'];
  $byQty = $summary['byQty']; $byPrice = $summary['byPrice']; $byValue = $summary['byValue'];
  $catEntries = $summary['cats'];
  $catMax = $catEntries ? $catEntries[0][1] : 0;
  $catPanel = '<div class="panel census" style="margin-bottom:22px">' .
    '<div class="ptitle">' . ($aggregateOnly
      ? 'Where supply sits &mdash; listed quantity by market'
      : 'Where the gold sits &mdash; listed value by market') . '</div>' .
    '<p class="hint" style="margin-top:0">' . ($aggregateOnly
      ? 'Total units currently listed, summed per market.'
      : 'Quantity listed &times; unit price, summed per market.') . '</p>' .
    ($catEntries
      ? bar_chart($catEntries, $catMax, fn() => 'var(--gold)', fn($k) => js_upper((string)$k),
          fn($v) => $aggregateOnly ? js_locale($v) . ' units' : big_gold($v))
      : '<p class="hint">No market data yet.</p>') . '</div>';

  $tiles =
    '<div class="tile"><div class="k">Items listed</div><div class="v">' . js_locale($summary['itemCount']) .
      '</div><div class="s">distinct items seen</div></div>' .
    ($aggregateOnly
      ? '<div class="tile"><div class="k">Price coverage</div><div class="v">MINIMUM</div><div class="s">aggregate browse summaries</div></div>'
      : '<div class="tile"><div class="k">Listed value</div><div class="v">' . esc(big_gold($totalValue)) .
        '</div><div class="s">asking prices, not sales</div></div>') .
    '<div class="tile"><div class="k">Quantity</div><div class="v">' . js_locale($totalQty) .
      '</div><div class="s">units on the AH</div></div>' .
    '<div class="tile"><div class="k">Scanned</div><div class="v">' .
      ($updatedAt ? esc(substr((string)$updatedAt, 0, 10)) : '&mdash;') .
      '</div></div>';

  $supplyBreakouts = $summary['breakouts'];

  $qtyCell = fn($it) => js_locale($it['q'] ?: 0);
  $linkCell = fn($it, $b) => item_link($it, $b);
  $qtyTable = top_table('Most listed items', 'By quantity sitting on the auction house.', $byQty, $branch, [
    ['label' => 'Item', 'left' => true, 'cell' => $linkCell],
    ['label' => 'Qty', 'cell' => $qtyCell],
    ['label' => 'Change', 'cell' => fn($it) => qty_change($it)],
    ['label' => $aggregateOnly ? 'Min unit' : 'Unit', 'cell' => fn($it) => esc(money($it['asp']))],
  ]);
  $priceTable = top_table($aggregateOnly ? 'Highest minimum prices' : 'Most expensive items',
    $aggregateOnly ? "Highest minimum asking price in Retail's aggregate summary." : 'Highest unit asking price among items currently listed.', $byPrice, $branch, [
    ['label' => 'Item', 'left' => true, 'cell' => $linkCell],
    ['label' => $aggregateOnly ? 'Min unit' : 'Unit', 'cell' => fn($it) => esc(money($it['asp']))],
    ['label' => 'Qty', 'cell' => $qtyCell],
    ['label' => 'Change', 'cell' => fn($it) => qty_change($it)],
  ]);
  $valueTable = top_table('Deepest markets', 'Single items holding the most listed value.', $byValue, $branch, [
    ['label' => 'Item', 'left' => true, 'cell' => $linkCell],
    ['label' => 'Listed value', 'cell' => fn($it) => esc(big_gold($it['mv']))],
    ['label' => 'Qty', 'cell' => $qtyCell],
    ['label' => 'Change', 'cell' => fn($it) => qty_change($it)],
  ]);

  $realmText = implode(' · ', array_map('realm_name', $realms));
  $body = $summary['itemCount']
    ? '<div class="mktgrid">' . $qtyTable . $priceTable . '</div>' . $catPanel . ($aggregateOnly ? '' : $valueTable)
    : '<div class="panel"><p class="hint">No auction scan is in the current snapshot for ' .
      esc($realmText !== '' ? $realmText : 'this faction') .
      ' yet. Run /ml scan at the auction house, /reload, then rebuild the local site snapshot.</p></div>';

  $jokePanel = $joke
    ? top_table('Excluded as joke listings',
        'Priced above ' . big_gold($summary['jokeThreshold']) . ' a unit — left out of the totals and lists above.',
        $joke, $branch, [
          ['label' => 'Item', 'left' => true, 'cell' => $linkCell],
          ['label' => 'Unit', 'cell' => fn($it) => esc(money($it['asp']))],
          ['label' => 'Qty', 'cell' => $qtyCell],
          ['label' => 'Change', 'cell' => fn($it) => qty_change($it)],
        ])
    : '';

  $marketAnalysis = '';
  if ($analysisLabel !== '' && $summary['itemCount']) {
    $sectors = implode(', ', array_map(fn($e) => js_lower((string)$e[0]) . ' at ' . big_gold($e[1]), array_slice($catEntries, 0, 3)));
    $marketAnalysis = '<section class="panel market-analysis" aria-label="' . esc($analysisLabel) . ' market update">' .
      '<div class="ptitle">' . esc($analysisLabel) . ' market update</div>' .
      '<p>Scanned ' . esc($updatedAt ? substr((string)$updatedAt, 0, 10) : 'date unavailable') .
      ': The auction house held <strong>' . js_locale($totalQty) .
      ' units across ' . js_locale($summary['itemCount']) . ' items</strong> with <strong>' .
      esc(big_gold($totalValue)) . '</strong> in listed asking value. Leading sectors: ' .
      esc($sectors) .
      '. ' . ($supplyBreakouts ? 'Supply breakouts: ' .
        esc(implode(', ', array_map(fn($it) => js_string($it['name']) . ' (+' . js_locale($it['pct']) . '%)', $supplyBreakouts))) . '. ' : '') .
      esc(($byQty[0]['name'] ?? '') ?: 'The leading item') . ' topped listed quantity at ' .
      js_locale(($byQty[0]['q'] ?? 0) ?: 0) . ' units. ' .
      'These are changes in listed supply, not trading volume or confirmed sales.</p></section>';
  }
  return ($marketAnalysis !== '' ? $marketAnalysis : '<section class="tiles">' . $tiles . '</section>') . $body .
    ($jokePanel !== '' ? '<div style="margin-top:22px">' . $jokePanel . '</div>' : '');
}

// $views come from market_view_summaries().
function render_market_html(array $views, array $dims, array $opts): string {
  $list = array_values(array_filter($views));
  $realmOpts = $dims['realm'] ?? [];
  $factionOpts = $dims['faction'] ?? [];
  $activeRealm = $realmOpts ? $realmOpts[0]['key'] : 'all';
  $activeFaction = $factionOpts ? $factionOpts[0]['key'] : 'all';
  $chips = chip_row('realm', $realmOpts, $activeRealm) . chip_row('faction', $factionOpts, $activeFaction);
  $sections = '';
  foreach ($list as $v) {
    $on = $v['realm'] === $activeRealm && $v['faction'] === $activeFaction;
    $sections .= '<div class="vpane" data-realm="' . esc($v['realm']) . '" data-faction="' . esc($v['faction']) . '"' .
      ($on ? '' : ' hidden') . '>' . render_market_view($v['summary'],
        !empty($opts['tbcAnalysis']) ? ($v['realm'] === 'all' ? 'TBC' : $v['realm']) .
          ($v['faction'] === 'all' ? ' combined' : ' ' . $v['faction']) : '') . '</div>';
  }
  $script = $chips !== '' ? toggle_script() : '';
  $gameLabel = $opts['gameLabel'] ?? 'WoW Forever';
  $navigation = $opts['nav'];
  $heading = ($navigation['games'] ?? '') !== '' ? 'WoWCensus' : $gameLabel . ' — Auction House';
  $notes = notes_html($opts['notes'] ?? []);
  $generatedNote = generated_note_html($opts['generatedNote'] ?? null);
  $seoGameLabel = seo_label($gameLabel);
  $topic = $seoGameLabel . ' Auction House Prices';
  $seo = seo_head($topic . ' – WoWCensus', $seoGameLabel . ' auction house prices: item supply, asking prices, total listed value and recent market changes per realm from regular scans.', $opts['canonical'] ?? null);
  $friz = !empty($opts['tbcAnalysis']) ? '<link rel="preload" href="/fonts/friz-quadrata-regular.woff2" as="font" type="font/woff2" crossorigin>' : '';
  $stylesheet = esc($opts['stylesheet'] ?? 'style.css');
  $census_css = CENSUS_STYLESHEET;
  $style = js_template_const('market.mjs', 'MARKET_STYLE');
  $headingHtml = page_heading($heading, $navigation, $topic);
  $games = $navigation['games'] ?? '';
  $crumbs = ($navigation['breadcrumbs'] ?? '') !== '' ? $navigation['breadcrumbs'] : $notes;
  $pages = $navigation['pages'] ?? '';
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
$friz
<link rel="stylesheet" href="$stylesheet">
$census_css<style>
$style
</style>
</head><body>
<div class="crt" aria-hidden="true"></div>
<div class="wrap">
  <header class="ihead">
    <div class="page-heading">$headingHtml$games</div>
    $crumbs
  </header>
  <div class="page-controls">$pages$chips</div>
  $sections
  $generatedNote
</div>
$script
</body></html>
HTML;
}
