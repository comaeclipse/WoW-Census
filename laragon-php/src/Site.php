<?php
// Port of tools/build-static-site.mjs: assemble each published file on request.

declare(strict_types=1);

const PAGE_LABELS = ['index' => 'Census', 'combos' => 'Race + Class', 'auctionhouse' => 'Auction House', 'guilds' => 'Guilds', 'geography' => 'Geography', 'talents' => 'Talents', 'gear' => 'Gear'];

function resource(string $path): string {
  static $cache = [];
  return $cache[$path] ??= file_get_contents(resource_path($path));
}

// The bundled stylesheet and its content-hashed name (tools/layout-id.js).
function stylesheet(): array {
  $css = str_replace("\r\n", "\n", resource('public/style.css'));
  // Content-hashed: Cloudflare edge-caches CSS far past its max-age, so only a
  // new URL reliably delivers a changed stylesheet.
  return ['css' => $css, 'name' => 'style.' . substr(hash('sha256', $css), 0, 12) . '.css'];
}

function census_css(): string { return resource('public/census.css'); }
function census_css_version(): string { return substr(hash('sha256', census_css()), 0, 12); }

function localize_content(string $content): string {
  $content = str_replace('https://wow.zamimg.com/', '/assets/', $content);
  return preg_replace('/<script\s+src="\/assets\/js\/tooltips\.js"[^>]*><\/script>/', '', $content);
}

// Self-hosted Umami analytics (s.wowcensus.io, on Vercel), added to every HTML page as it is written or served.
// data-domains keeps local previews (127.0.0.1:8081) out of the counts.
const ANALYTICS_TAG = '<script defer src="https://s.wowcensus.io/script.js" data-website-id="15776ba3-1f50-4da9-98d7-59b2fc4066f9" data-domains="wowcensus.io"></script>';

// Temporary notice shown under the header of each edition's census page. Set to null to hide it.
// Give each new notice a new id: dismissals are remembered per id in the visitor's browser.
const SITE_NOTICE = [
  'id' => '2026-10-contributors',
  'icon' => '/notice-discord.png',
  'html' => 'Looking for contributors! Join our Discord for more info: <a href="https://discord.gg/Dzw8H3QZX" target="_blank" rel="noopener">discord.gg/Dzw8H3QZX <span class="blink">&#9656;</span></a>',
];

function site_notice_html(): string {
  $n = SITE_NOTICE;
  if (!$n) return '';
  $id = esc($n['id']);
  $icon = !empty($n['icon']) ? '<img class="notice-icon" src="' . esc($n['icon']) . '" alt="" width="29" height="22">' : '';
  return <<<HTML
<aside class="site-notice" data-notice-id="$id" aria-label="Site notice">
    <p class="notice-text">$icon{$n['html']}</p>
    <button class="notice-close" type="button" aria-label="Dismiss notice">&times;</button>
  </aside>
  <script>(function(){var n=document.currentScript.previousElementSibling,k="notice-dismissed:"+n.dataset.noticeId;try{if(localStorage.getItem(k))n.hidden=true}catch(e){}n.querySelector(".notice-close").addEventListener("click",function(){n.hidden=true;try{localStorage.setItem(k,"1")}catch(e){}})})();</script>
HTML;
}

function with_analytics(string $html): string {
  $at = stripos($html, '</head>');
  if ($at === false || str_contains($html, 's.wowcensus.io/script.js')) return $html;
  return substr_replace($html, ANALYTICS_TAG, $at, 0);
}

function markdown_path(string $route): string { return str_ends_with($route, '/') ? $route . 'index.md' : $route . '.md'; }

function with_markdown_alternate(string $html, string $route): string {
  $at = strpos($html, '</head>');
  if ($at === false) throw new RuntimeException("No </head> to announce the Markdown copy of $route");
  return substr_replace($html, '<link rel="alternate" type="text/markdown" href="' . markdown_path($route) . '"></head>', $at, 7);
}

function generated_at(): string {
  return gmdate('Y-m-d\TH:i:s') . '.000Z';
}

function edition_page_html(string $source, string $page): string {
  ['css' => $css, 'name' => $cssName] = stylesheet();
  $html = render_edition_page($source, $page, $cssName, generated_at());
  $html = str_replace('href="/census.css"', 'href="/census.css?v=' . census_css_version() . '"', $html);
  if ($page === 'index') {
    $link = '<link rel="stylesheet" href="' . $cssName . '">';
    if (!str_contains($html, $link)) throw new RuntimeException("Landing page stylesheet missing: $source");
    $at = strpos($html, $link);
    $html = substr_replace($html, '<style>' . $css . '</style>', $at, strlen($link));
  }
  return localize_content(with_markdown_alternate($html, page_path($source, $page)));
}

function race_page_html(string $source, string $race): string {
  $route = race_profiles()[$source][$race]['path'];
  $html = render_race_profile(load_inspects($source), $source, $race, stylesheet()['name'], SITE_ORIGIN . $route);
  return localize_content(with_markdown_alternate($html, $route));
}

function class_page_html(string $source, string $classFile): string {
  $route = class_route($source, $classFile);
  $html = render_class_profile(load_inspects($source), $source, $classFile, stylesheet()['name'], SITE_ORIGIN . $route);
  return localize_content(with_markdown_alternate($html, $route));
}

function census_json(string $source): string {
  $census = load_census($source);
  $obj = fn(array $a) => $a ? $a : new stdClass();
  $payload = [
    'groups' => array_map(fn($g) => ['faction' => $g['faction'], 'races' => $obj($g['races']),
      'raceWindows' => ['all' => $obj($g['raceWindows']['all']), 'week' => $obj($g['raceWindows']['week']), 'day' => $obj($g['raceWindows']['day'])],
      'classes' => $obj($g['classes']), 'combos' => $obj($g['combos']), 'characters' => $g['characters'], 'samples' => $g['samples'],
      'observed' => $g['observed'], 'games' => $g['games']], $census['groups']),
    'units' => array_map(function ($u) use ($obj) {
      $out = [];
      foreach ($u as $k => $v) {
        if ($k === 'guilds' || $k === 'zones') continue;
        if (in_array($k, ['races', 'classes', 'combos'], true)) $v = $obj($v);
        if ($k === 'raceWindows') $v = ['all' => $obj($v['all']), 'week' => $obj($v['week']), 'day' => $obj($v['day'])];
        $out[$k] = $v;
      }
      return $out;
    }, $census['units']),
    'realms' => $census['realms'],
    'lastT' => $census['lastT'],
    'zoneWindowDays' => $census['zoneWindowDays'],
  ];
  return js_json($payload) . "\n";
}

function sitemap_xml(): string {
  $routes = [];
  foreach (EDITIONS as $source => $_) foreach ([...PAGES, 'realms'] as $page) $routes[] = page_path($source, $page);
  $routes = array_merge($routes, race_routes(), class_routes());
  return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
    '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" .
    implode("\n", array_map(fn($r) => '  <url><loc>' . SITE_ORIGIN . $r . '</loc></url>', $routes)) .
    "\n</urlset>\n";
}

function robots_txt(): string {
  return "User-agent: *\nContent-Signal: ai-train=no, search=yes, ai-input=yes\nAllow: /\n\nSitemap: " . SITE_ORIGIN . "/sitemap.xml\n";
}

function llms_txt(): string {
  $sections = [];
  foreach (EDITIONS as $source => $edition) {
    $link = fn($label, $route) => '- [' . $label . '](' . SITE_ORIGIN . markdown_path($route) . ')';
    $classes = array_map(fn($cf) => $link(CLASS_META[$cf][0] ?? $cf, class_route($source, $cf)), class_profiles()[$source]['classes'] ?? []);
    $races = [];
    foreach (race_profiles()[$source] ?? [] as $race => $p) $races[] = $link($p['label'] ?? $race, $p['path']);
    $sections = array_merge($sections, ['## ' . $edition['label'], ''], array_map(fn($page) => $link(PAGE_LABELS[$page], page_path($source, $page)), PAGES), [''],
      $classes ? array_merge(['### Class talents', ''], $classes, ['']) : [],
      $races ? array_merge(['### Race talents', ''], $races, ['']) : []);
  }
  return "# WoWCensus\n\nStatic population, auction, guild, geography, talent, and gear snapshots.\n" .
    "Data is collected in game and frozen when these files are built.\n\n" .
    "Each link is the Markdown copy of a page; drop the .md (or index.md) for the HTML page.\n\n" .
    implode("\n", $sections) . "\n";
}

function ai_catalog_json(): string {
  $entries = [];
  foreach (EDITIONS as $source => $edition)
    $entries[] = [
      'identifier' => "urn:air:wowcensus.io:census:$source",
      'displayName' => $edition['label'] . ' census data',
      'type' => 'application/json',
      'url' => SITE_ORIGIN . edition_path($source) . 'census.json',
      'description' => 'Observed population from sampled in-game /who results.',
      'tags' => ['world-of-warcraft', 'census', 'population'],
    ];
  return js_json(['specVersion' => '1.0', 'host' => ['displayName' => 'wowcensus', 'documentationUrl' => SITE_ORIGIN . '/llms.txt'], 'entries' => $entries], '  ') . "\n";
}

function not_found_html(): string {
  return localize_content('<!doctype html><html lang="en"><head><meta charset="utf-8">' .
    '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Not found</title>' .
    '<meta name="robots" content="noindex"><link rel="stylesheet" href="/' . stylesheet()['name'] . '"></head>' .
    '<body><main class="wrap"><h1 class="iname">Not found</h1><p><a href="/">WoWCensus home</a></p></main></body></html>' . "\n");
}

function headers_file(): string {
  return "/style.*.css\n  Cache-Control: public, max-age=31536000, immutable\n\n" .
    "/:edition/style.*.css\n  Cache-Control: public, max-age=31536000, immutable\n\n" .
    "/census.css\n  Cache-Control: public, max-age=300\n\n" .
    "/fonts/*\n  Cache-Control: public, max-age=31536000, immutable\n\n" .
    "/data/*\n  Cache-Control: public, max-age=31536000, immutable\n\n" .
    "/:edition/data/*\n  Cache-Control: public, max-age=31536000, immutable\n\n" .
    "/census.json\n  Cache-Control: public, max-age=300\n\n" .
    "/:edition/census.json\n  Cache-Control: public, max-age=300\n\n" .
    "/*.md\n  Content-Type: text/markdown; charset=utf-8\n\n" .
    "/*\n  X-Content-Type-Options: nosniff\n";
}
