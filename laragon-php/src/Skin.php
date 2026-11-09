<?php
declare(strict_types=1);

function skin_switch(string $skin): string {
  $back = $_SERVER['REQUEST_URI'] ?? '/';
  if (!str_starts_with($back, '/') || str_starts_with($back, '//')) $back = '/';
  $back = rawurlencode($back);
  $current = $skin === 'current' ? ' aria-current="true"' : '';
  $cobalt = $skin === 'cobalt' ? ' aria-current="true"' : '';
  return '<nav class="skin-switch" aria-label="Site appearance"><a href="/skin?choice=cobalt&amp;return=' . $back . '"' . $cobalt . '>Retail</a><a href="/skin?choice=wow2008&amp;return=' . $back . '">2008</a><a href="/skin?choice=current&amp;return=' . $back . '"' . $current . '>Pixel</a></nav>';
}

function skin_switch_dropdown(): string {
  $back = $_SERVER['REQUEST_URI'] ?? '/';
  if (!str_starts_with($back, '/') || str_starts_with($back, '//')) $back = '/';
  $back = rawurlencode($back);
  return '<details class="skin-dropdown"><summary>Skin: Pixel <span aria-hidden="true">▾</span></summary>' .
    '<nav class="skin-dropdown-menu" aria-label="Choose site appearance">' .
    '<a href="/skin?choice=cobalt&amp;return=' . $back . '">Retail</a>' .
    '<a href="/skin?choice=wow2008&amp;return=' . $back . '">2008</a>' .
    '<a aria-current="true" href="/skin?choice=current&amp;return=' . $back . '">Pixel</a></nav></details>';
}

function skin_section(string $html, string $class): string {
  if (preg_match('/<nav class="[^"]*\b' . preg_quote($class, '/') . '\b[^"]*"[^>]*>.*?<\/nav>/s', $html, $m)) return $m[0];
  return '';
}

function skin_wrap_close(string $html): ?int {
  if (!preg_match('/<(div|main) class="wrap cobalt-wrap[^"]*"/', $html, $start, PREG_OFFSET_CAPTURE)) return null;
  $masked = preg_replace_callback('/<script\b[^>]*>.*?<\/script>/is', static fn($m) => str_repeat(' ', strlen($m[0])), $html);
  $bodyEnd = stripos($masked, '</body>');
  $close = strripos(substr($masked, 0, $bodyEnd === false ? strlen($masked) : $bodyEnd), '</' . $start[1][0] . '>');
  return $close !== false && $close > $start[0][1] ? $close : null;
}

function skin_metrics(string $path): string {
  $result = route_request(rtrim($path, '/') . '/census.json');
  $data = $result && str_contains($result[0], 'json') ? json_decode($result[1], true) : null;
  if (!is_array($data)) return '';
  $alliance = 0;
  $horde = 0;
  foreach ($data['groups'] ?? [] as $group) {
    if (($group['faction'] ?? '') === 'Alliance') $alliance = (int)($group['characters'] ?? 0);
    if (($group['faction'] ?? '') === 'Horde') $horde = (int)($group['characters'] ?? 0);
  }
  $cards = [
    ['Alliance observed', number_format($alliance), 'all realms in sample'],
    ['Horde observed', number_format($horde), 'all realms in sample'],
    ['Realm buckets', number_format(count($data['units'] ?? [])), 'home-realm views'],
    ['Latest observation', !empty($data['lastT']) ? gmdate('n/j/Y', (int)$data['lastT']) : 'Unknown', 'snapshot timestamp'],
  ];
  $html = '<div class="census-metrics cobalt-metrics">';
  foreach ($cards as [$label, $value, $note]) $html .= '<div class="metric cobalt-metric"><span>' . htmlspecialchars($label) . '</span><strong>' . htmlspecialchars($value) . '</strong><small>' . htmlspecialchars($note) . '</small></div>';
  return $html . '</div>';
}

function render_skin(string $html, string $skin): string {
  if ($skin === 'wow2008') {
    require_once __DIR__ . '/Wow2008.php';
    return Wow2008\render_page($html, parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
  }
  if (!preg_match('/<header class="ihead"[^>]*>.*?<\/header>/s', $html, $match)) return $html;
  $header = $match[0];
  $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
  $segments = array_values(array_filter(explode('/', trim($requestPath, '/'))));
  $pageKey = count($segments) === 0 ? 'census' : (count($segments) === 1 && in_array($segments[0], ['forever','tbc','classic','sod','hardcore','mop','retail'], true) ? 'census' : end($segments));
  if (str_starts_with($requestPath, '/retail/skin-colors') || str_starts_with($requestPath, '/forever/skin-colors')) $pageKey = 'skin-colors';
  elseif ($requestPath === '/faq.php') $pageKey = 'faq';
  elseif (!in_array($pageKey, ['census','realms','auctionhouse','guilds','geography','talents','gear','combos'], true)) $pageKey = 'profile';
  $html = preg_replace('/<html\b/', '<html data-skin="' . $skin . '" data-cobalt-page="' . htmlspecialchars($pageKey, ENT_QUOTES) . '"', $html, 1);
  if ($skin === 'current') {
    $pageNav = skin_section($header, 'page-nav');
    $newHeader = $pageNav !== '' ? str_replace($pageNav, $pageNav . skin_switch_dropdown(), $header)
      : substr_replace($header, skin_switch_dropdown(), strrpos($header, '</header>'), 0);
    $html = str_replace($header, $newHeader, $html);
    return str_replace('</head>', '<link rel="stylesheet" href="/skin-switch.css?v=5"></head>', $html);
  }

  // Keep the original body bytes intact: several page scripts construct HTML at run time.
  $headEnd = stripos($html, '</head>');
  $head = substr($html, 0, $headEnd);
  $rest = substr($html, $headEnd);
  // The skin-colors page carries its whole layout in its own <style>; other pages' inline styles give way to the theme.
  if ($pageKey !== 'skin-colors') $head = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $head);
  $head = preg_replace('/<link\b[^>]*rel="(?:stylesheet|preload)"[^>]*>/i', '', $head);
  $styles = '';
  foreach (['theme.css', 'census.css', 'detail.css', 'modern-php.css'] as $file) $styles .= '<link rel="stylesheet" href="/modern/' . $file . '?v=4">';
  $head .= $styles . '<link rel="stylesheet" href="/modern-bridge.css?v=5"><link rel="stylesheet" href="/modern-page-layouts.css?v=10">';
  if ($pageKey === 'geography') $head .= '<link rel="stylesheet" href="/geo-aero.css?v=1">';
  $html = $head . $rest;

  preg_match('/<span class="itopic">(.*?)<\/span>/s', $header, $topicMatch);
  $topic = html_entity_decode(trim(strip_tags($topicMatch[1] ?? 'WoWCensus')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $editionNav = skin_section($header, 'game-nav');
  $pageNav = skin_section($header, 'page-nav');
  preg_match('/<a\b[^>]*aria-current[^>]*>(.*?)<\/a>/s', $editionNav, $editionMatch);
  preg_match('/<span\b[^>]*aria-current[^>]*>(.*?)<\/span>/s', skin_section($header, 'breadcrumbs'), $pageMatch);
  $edition = html_entity_decode(trim(strip_tags($editionMatch[1] ?? 'WoW Forever')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $page = html_entity_decode(trim(strip_tags($pageMatch[1] ?? 'Census')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  if ($pageKey === 'faq') $edition = 'WoWCensus';
  $description = match ($pageKey) {
    'gear' => 'Explore equipment slots and the items seen most often on inspected characters.',
    'talents' => 'Explore classified builds and selected talents among nearby inspected characters.',
    'realms' => 'Compare observed characters across realms, faction balance, and recent scans.',
    'auctionhouse' => 'Review listed items, supply, and asking prices from the latest auction scans.',
    'guilds' => 'Explore guild sizes among characters in the published census sample.',
    'geography' => 'Explore where characters were seen in recent population samples.',
    'combos' => 'Compare race and class pairings among observed characters.',
    'skin-colors' => 'Compare the skin-color choices seen in sampled Retail character profiles, race by race.',
    'faq' => 'Answers to common questions about WoWCensus: what it is, where the numbers come from and how to get involved.',
    'profile' => 'Explore the published character and inspection sample for this profile.',
    default => 'Explore the published WoWCensus snapshot. Counts describe observed characters, not unique players or a live online population.',
  };
  if ($pageKey === 'profile' && preg_match('/<section class="hero slab class-profile-summary">(.*?)<\/section>/s', $html, $summary)) {
    if (preg_match('/<p>(.*?)<\/p>/s', $summary[1], $summaryText))
      $description = trim(html_entity_decode(strip_tags($summaryText[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (preg_match('/<div class="bignum"><b>(.*?)<\/b><small>(.*?)<\/small><\/div>/s', $summary[1], $sample))
      $description .= ' ' . trim(strip_tags($sample[1])) . ' ' . trim(strip_tags($sample[2])) . '.';
    $html = str_replace($summary[0], '', $html);
  }
  $top = '<header class="top cobalt-top"><div class="wrap top-inner cobalt-top-inner"><a class="brand cobalt-brand" href="/">WoW<span>Census</span></a>' . str_replace('class="games page-nav"', 'class="cobalt-page-nav"', $pageNav) . skin_switch($skin) . '</div></header>';
  $artFile = '/cobalt-character.webp';
  if ($pageKey === 'profile') {
    if (preg_match('/\bPaladin Talents\b/i', $topic)) $artFile = '/cobalt-paladin-v2.webp';
    elseif (preg_match('/\bWarrior Talents\b/i', $topic)) $artFile = '/cobalt-warrior.webp';
  }
  $heroArt = $pageKey === 'gear' ? '' : '<img class="census-hero-art" src="' . $artFile . '" alt="" width="350" height="400">';
  $hero = '<section class="census-hero cobalt-hero"><div class="census-hero-copy"><p class="eyebrow cobalt-eyebrow">' . htmlspecialchars($edition . ' · ' . $page) . '</p><h1>' . htmlspecialchars(preg_replace('/^WoW\s+/', '', $topic)) . '</h1><p class="cobalt-intro">' . htmlspecialchars($description) . '</p></div>' . $heroArt . '</section>';
  if ($pageKey === 'geography') $hero = '<section class="geo-aero-hero"><div class="geo-aero-hero-copy"><span class="geo-aero-kicker">✦ &nbsp; WORLD AT A GLANCE <i></i> ' . htmlspecialchars(strtoupper($edition)) . '</span>'
    . '<h1>Where Azeroth <em>gathers.</em></h1><p>Explore the latest known locations of observed characters across realms and factions.</p>'
    . '<div class="geo-aero-actions"><a href="#geo-location-controls">Explore locations <span>↗</span></a></div></div>'
    . '<div class="geo-aero-orbit" aria-hidden="true"><div>✦</div><span></span><span></span><span></span></div></section>';
  $editionNav = str_replace('class="games game-nav"', 'class="cobalt-edition-nav"', $editionNav);
  $html = preg_replace('/<(div|main) class="wrap( [^"]*)?"/', $top . '<$1 class="wrap cobalt-wrap$2"', $html, 1);
  $html = str_replace($header, '<div class="cobalt-shell census-wrap detail-wrap">' . $editionNav . $hero . '<div class="cobalt-content census-main detail-main">', $html);
  if (preg_match('/<body[^>]*class="[^"]*\bcensus-home\b/', $html)) {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $metrics = skin_metrics($path);
    if ($metrics !== '') $html = preg_replace('/(?=<div class="(?:vpane|panel)\b)/', $metrics, $html, 1);
  }
  $close = skin_wrap_close($html);
  if ($close === null) throw new RuntimeException('Could not close Cobalt page wrapper');
  return substr_replace($html, '</div></div>', $close, 0);
}
