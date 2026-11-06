<?php
// Assemble each published file on request (once a port of the retired static generator).

declare(strict_types=1);
require_once __DIR__ . '/Skin.php';

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
  'id' => '2026-10-home-rotation',
];

function site_notice_html(): string {
  $n = SITE_NOTICE;
  if (!$n) return '';
  $id = esc($n['id']);
  $kofiIcon = '<svg class="notice-pixel-icon" viewBox="0 0 32 24" aria-hidden="true" shape-rendering="crispEdges"><path fill="#67c9ed" d="M2 3h23v3H2zM0 6h28v12H0zM3 18h22v3H3zM7 21h15v2H7z"/><path fill="#f4f5f7" d="M4 5h19v12H4zM7 18h14v2H7z"/><path fill="#67c9ed" d="M28 8h3v8h-3zM25 16h5v3h-5z"/><path fill="#ee5a73" d="M10 8h3v2h-3zM16 8h3v2h-3zM9 10h11v3H9zM11 13h7v2h-7zM13 15h3v2h-3z"/></svg>';
  $coffeeIcon = '<svg class="notice-pixel-icon" viewBox="0 0 32 24" aria-hidden="true" shape-rendering="crispEdges"><path fill="#ffdd00" d="M2 0h26v3H2zM0 3h30v18H0zM3 21h25v3H3z"/><path fill="#151414" d="M8 5h3v4H8zM14 4h3v5h-3zM5 10h20v3H5zM5 13h3v6H5zM20 13h3v6h-3zM8 19h15v2H8zM25 13h4v3h-4zM23 16h4v3h-4z"/><path fill="#fff" d="M8 13h12v5H8z"/></svg>';
  return <<<HTML
<script>document.documentElement.dataset.noticeChoice=String(Math.floor(Math.random()*3))</script>
<aside class="site-notice" data-notice-id="$id" aria-label="Site notice">
    <p class="notice-text" data-notice-option="0"><img class="notice-icon" src="/notice-discord.png" alt="" width="29" height="22">Looking for contributors! Join our Discord for more info: <a href="https://discord.gg/Dzw8H3QZX" target="_blank" rel="noopener">discord.gg/Dzw8H3QZX <span class="blink">&#9656;</span></a></p>
    <p class="notice-text" data-notice-option="1">$kofiIcon Like this project? Help support us on <a href="https://ko-fi.com/wowcensus" target="_blank" rel="noopener noreferrer">Ko-fi <span class="blink">&#9656;</span></a></p>
    <p class="notice-text" data-notice-option="2">$coffeeIcon Like this project? Help support us on <a href="https://buymeacoffee.com/wowcensus" target="_blank" rel="noopener noreferrer">Buy Me a Coffee <span class="blink">&#9656;</span></a></p>
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

// Public names of the skins, used as the Umami tag so pageviews can be filtered by skin.
// Nginx caches pages per skin, so the tag always matches the page it is in.
const SKIN_NAMES = ['wow2008' => '2008', 'cobalt' => 'retail', 'current' => 'pixel'];

function with_skin(string $html): string {
  $skin = $_COOKIE['wowcensus_skin'] ?? '';
  $skin = isset(SKIN_NAMES[$skin]) ? $skin : 'wow2008';
  return str_replace(' data-website-id=', ' data-tag="' . SKIN_NAMES[$skin] . '" data-website-id=', render_skin($html, $skin));
}

// True when an Accept header ranks Markdown above HTML (text/markdown or text/*; */* alone stays HTML).
function prefers_markdown(string $accept): bool {
  $q = ['md' => 0.0, 'html' => 0.0];
  foreach (explode(',', strtolower($accept)) as $part) {
    $bits = array_map('trim', explode(';', $part));
    $weight = 1.0;
    foreach (array_slice($bits, 1) as $param) if (preg_match('/^q=([0-9.]+)$/', $param, $m)) $weight = (float)$m[1];
    if ($bits[0] === 'text/markdown' || $bits[0] === 'text/*') $q['md'] = max($q['md'], $weight);
    if ($bits[0] === 'text/html' || $bits[0] === 'application/xhtml+xml') $q['html'] = max($q['html'], $weight);
  }
  return $q['md'] > 0 && $q['md'] > $q['html'];
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
  $html = render_race_profile(race_profiles_model($source)[$race], $source, $race, stylesheet()['name'], SITE_ORIGIN . $route);
  return localize_content(with_markdown_alternate($html, $route));
}

function class_page_html(string $source, string $classFile): string {
  $route = class_route($source, $classFile);
  $html = render_class_profile(class_profiles_model($source)[$classFile], $source, $classFile, stylesheet()['name'], SITE_ORIGIN . $route);
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
    '<meta name="viewport" content="width=device-width,initial-scale=1"><title>404 — MRGLGLGL! – WoWCensus</title>' .
    '<meta name="robots" content="noindex"><link rel="stylesheet" href="/' . stylesheet()['name'] . '">' .
    '<style>.lost{display:flex;align-items:center;justify-content:center;gap:28px;flex-wrap:wrap;min-height:70vh;padding:24px}' .
    '.lost img{width:300px;height:300px;flex:none}.lost-text{max-width:360px}.lost-text h1{margin:0 0 12px}' .
    '.lost-text p{margin:0 0 12px;line-height:1.5}.lost-btn{display:inline-block;margin-top:6px;padding:8px 18px;border-radius:4px;' .
    'background:#c8902a;color:#1a0d00;font-weight:700;text-decoration:none}.lost-btn:hover{background:#e0a838}</style></head>' .
    '<body><main class="wrap lost"><img src="/murloc-404.webp" width="300" height="300" alt="A confused Murloc holding a torn map marked 404">' .
    '<div class="lost-text"><h1 class="iname">404 — MRGLGLGL!</h1><p><b>A wild Murloc has eaten this page.</b></p>' .
    '<p>We tried asking for it back, but negotiations broke down into gurgling.</p>' .
    '<a class="lost-btn" href="/">Respawn at Homepage</a></div></main></body></html>' . "\n");
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
