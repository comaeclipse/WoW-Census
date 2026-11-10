<?php
// The "2008" skin (the default): the 2008 game-portal mockup's templates
// (src/wow2008/, from Mockups/wow-2008), filled with the site's own data.
// Served unless a visitor picks Retail or Pixel. The templates keep the mockup's
// markup and scripts; each page adapter below builds the data they draw from.

declare(strict_types=1);

namespace Wow2008;

const TEMPLATE_DIR = __DIR__ . '/wow2008';
// Bump when public/wow2008/ changes, so browsers fetch the new files.
const ASSET_VERSION = '60';

// Home page "Support WoWCensus" box: this month's donations against the monthly server bill, in US dollars.
const SUPPORT_RAISED = 0;
const SUPPORT_GOAL = 20;

// ---------------------------------------------------------------- helpers (the mockup's bootstrap)

function e(mixed $value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL of a theme asset; the mockup's css/ and js/ folders are flattened into /wow2008/. */
function asset(string $path): string {
  return '/wow2008/' . basename($path) . '?v=' . ASSET_VERSION;
}

/** Render a template with the given variables. */
function render(string $template, array $vars = []): string {
  $file = TEMPLATE_DIR . '/' . $template;
  if (!is_file($file)) throw new \RuntimeException("WoW 2008 template not found: $template");
  extract($vars, EXTR_SKIP);
  ob_start();
  try {
    include $file;
  } catch (\Throwable $t) {
    ob_end_clean();
    throw $t;
  }
  return ob_get_clean();
}

function component(string $name, array $props = []): string {
  return rtrim(render("components/$name.php", $props), "\n");
}

function component_start(string $name, array $props = []): void {
  $GLOBALS['w8_components'][] = [$name, $props];
  ob_start();
}

function component_end(): void {
  [$name, $props] = array_pop($GLOBALS['w8_components']);
  $props['slot'] = rtrim(ob_get_clean(), "\n");
  echo component($name, $props), "\n";
}

function start_section(string $name): void {
  $GLOBALS['w8_section_stack'][] = $name;
  ob_start();
}

function end_section(): void {
  $name = array_pop($GLOBALS['w8_section_stack']);
  $GLOBALS['w8_sections'][$name] = ($GLOBALS['w8_sections'][$name] ?? '') . ob_get_clean();
}

function section(string $name): string {
  return $GLOBALS['w8_sections'][$name] ?? '';
}

/** JSON for a page script's data, safe inside <script>. */
function js(mixed $value): string {
  return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
}

function fmt(int|float $n): string { return number_format($n); }

// ---------------------------------------------------------------- editions and links

/** The current edition (a source key such as 'classic-progression'), or null. */
function current_source(): ?string { return $GLOBALS['w8_source'] ?? null; }

/** Sidebar icon letter and banner subtitle per edition. */
const EDITION_ART = [
  'forever' => ['F', 'WOW FOREVER'],
  'classic-beta' => ['B', 'WOW FOREVER BETA'],
  'classic-progression' => ['T', 'THE BURNING CRUSADE'],
  'classic' => ['C', 'CLASSIC ERA'],
  'sod' => ['S', 'SEASON OF DISCOVERY'],
  'hardcore' => ['H', 'CLASSIC HARDCORE'],
  'mop-classic' => ['M', 'MISTS OF PANDARIA'],
  'retail' => ['R', 'RETAIL'],
];

function edition_name(string $source): string { return \EDITIONS[$source]['nav'] ?? \EDITIONS[$source]['label']; }
function edition_subtitle(string $source): string { return EDITION_ART[$source][1] ?? strtoupper(\EDITIONS[$source]['label']); }

/** The race or class profile pages of an edition, in the site's order: [slug => [label, path]]. */
function race_pages(string $source): array {
  $out = [];
  foreach (\race_profiles()[$source] ?? [] as $race => $p) $out[profile_slug($p['path'])] = [(string)($p['label'] ?? $race), $p['path'], (string)$race];
  return $out;
}

function class_pages(string $source): array {
  $out = [];
  foreach (\class_profiles()[$source]['classes'] ?? [] as $cf) {
    $path = \class_route($source, $cf);
    $out[profile_slug($path)] = [\CLASS_META[$cf][0] ?? $cf, $path, $cf];
  }
  return $out;
}

function profile_slug(string $path): string { return basename(rtrim($path, '/')); }

/**
 * Link to a census page, in the mockup's url($page, $edition) form.
 *   url()                 home
 *   url('realms')         realms page of the current edition
 *   url('gear', 'retail') gear page of another edition
 */
function url(?string $page = null, ?string $source = null): string {
  if ($page === null && $source === null) return '/';
  $source ??= current_source() ?? array_key_first(\EDITIONS);
  return match ($page) {
    // Forever's census was the site root; the root is now the front page.
    null, 'census' => \edition_path($source),
    'realms' => \edition_path($source) . 'realms',
    'auction-house' => \page_path($source, 'auctionhouse'),
    'races' => (array_values(race_pages($source))[0][1] ?? \edition_path($source)),
    'classes' => (array_values(class_pages($source))[0][1] ?? \edition_path($source)),
    default => \page_path($source, $page),
  };
}

/** Sidebar entries for one edition: [label, href, sub, page key]. */
function edition_links(string $source): array {
  $links = [['Census', url('census', $source), false, 'census'], ['Race + Class', url('combos', $source), true, 'combos'],
    ['Realms', url('realms', $source), false, 'realms'], ['Auction House', url('auction-house', $source), false, 'auction-house'],
    ...($source === \PROFESSION_SOURCE ? [['Professions', \profession_path(), true, 'professions']] : []),
    ['Guilds', url('guilds', $source), false, 'guilds'], ['Geography', url('geography', $source), false, 'geography'],
    ['Talents', url('talents', $source), false, 'talents']];
  if (race_pages($source)) $links[] = ['Races', url('races', $source), true, 'races'];
  if (class_pages($source)) $links[] = ['Classes', url('classes', $source), true, 'classes'];
  $links[] = ['Gear', url('gear', $source), false, 'gear'];
  if (in_array($source, ['retail', 'forever'], true)) $links[] = ['Skin Colors', '/' . $source . '/skin-colors/', false, 'skin-colors'];
  return $links;
}

// ---------------------------------------------------------------- page shell

/** The tags the rendered page carried for search engines, sharing and analytics. */
function head_tags(string $html): string {
  $head = substr($html, 0, stripos($html, '</head>') ?: 0);
  $keep = [];
  if (preg_match('/<title>.*?<\/title>/s', $head, $m)) $keep[] = $m[0];
  foreach ([
    '/<meta\s+name="(?:description|robots|google-site-verification)"[^>]*>/i',
    '/<meta\s+property="og:[^"]*"[^>]*>/i',
    '/<link\s+rel="(?:canonical|alternate)"[^>]*>/i',
    '/<script\b[^>]*data-website-id[^>]*><\/script>/i',
  ] as $pattern)
    if (preg_match_all($pattern, $head, $m)) array_push($keep, ...$m[0]);
  return implode("\n", $keep);
}

/** Links that switch the site's appearance, for the top bar. */
function skin_links(): string {
  $back = $_SERVER['REQUEST_URI'] ?? '/';
  if (!str_starts_with($back, '/') || str_starts_with($back, '//')) $back = '/';
  $back = rawurlencode($back);
  $out = '';
  foreach (['cobalt' => 'Retail', 'wow2008' => '2008', 'current' => 'Pixel'] as $choice => $label)
    $out .= '<a href="/skin?choice=' . $choice . '&amp;return=' . $back . '"' . ($choice === 'wow2008' ? ' aria-current="true"' : '') . '>' . $label . '</a>';
  return '<span class="w8-skins" aria-label="Site appearance">Skin:' . $out . '</span>';
}

/** Render a page template inside the layout. */
function page(string $template, array $vars, string $html, ?string $page): string {
  $GLOBALS['w8_sections'] = [];
  $content = render("pages/$template.php", $vars + ['page' => $page]);
  return render('layout.php', ['head' => head_tags($html), 'content' => $content, 'wide' => true, 'source' => current_source(),
    'page' => $page, 'route' => $vars['route'] ?? ['edition' => current_source(), 'page' => $page]]);
}

// ---------------------------------------------------------------- routing

/**
 * The 2008 version of a page the site rendered as $html for $path. Pages the
 * mockup has a design for get it; any other page keeps its content inside the
 * 2008 frame.
 */
function render_page(string $html, string $path): string {
  $GLOBALS['w8_source'] = null;
  if (http_response_code() === 404) return page('404', ['requested' => $path], $html, null);
  // The portal's front page; the Forever census it used to show lives at /forever/.
  if ($path === '/' || $path === '/index.html') return home_page();
  if ($path === '/forever/') {
    $GLOBALS['w8_source'] = 'forever';
    return census_page('forever', $html);
  }
  if (preg_match('#^/(?:([a-z-]+)/)?(?:(index|combos|auctionhouse|guilds|geography|talents|gear)(?:\.html)?)?$#', $path, $m)
      && ($source = \source_for_path(($m[1] ?? '') !== '' ? '/' . $m[1] . '/' : '/'))) {
    $GLOBALS['w8_source'] = $source;
    $kind = ($m[2] ?? '') !== '' ? $m[2] : 'index';
    return match ($kind) {
      'index' => census_page($source, $html),
      'talents' => talents_page($source, $html),
      'gear' => gear_page($source, $html),
      'guilds' => guilds_page($source, $html),
      'geography' => geography_page($source, $html),
      'auctionhouse' => auction_page($source, $html),
      default => framed_page($source, $html, $kind),
    };
  }
  if (preg_match('#^/(?:([a-z-]+)/)?guilds/(\d{12})/(?:index\.html)?$#', $path, $m)
      && ($source = \source_for_path(($m[1] ?? '') !== '' ? '/' . $m[1] . '/' : '/')) && ($roster = \guild_roster($source, $m[2]))) {
    $GLOBALS['w8_source'] = $source;
    return guild_page($source, $roster, $html);
  }
  if (preg_match('#^/(?:([a-z-]+)/)?realms(?:\.html)?$#', $path, $m)
      && ($source = \source_for_path(($m[1] ?? '') !== '' ? '/' . $m[1] . '/' : '/'))) {
    $GLOBALS['w8_source'] = $source;
    return realms_page($source, $html);
  }
  foreach (\EDITIONS as $source => $_) {
    foreach (race_pages($source) as $slug => [$label, $route]) if ($route === $path) { $GLOBALS['w8_source'] = $source; return races_page($source, $html, $slug); }
    foreach (class_pages($source) as $slug => [$label, $route]) if ($route === $path) { $GLOBALS['w8_source'] = $source; return classes_page($source, $html, $slug); }
  }
  if (preg_match('#^/forever/item/(\d{1,7})/?$#', $path, $m) && ($item = \forever_item_model((int)$m[1]))) {
    $GLOBALS['w8_source'] = \PROFESSION_SOURCE;
    return item_page($item, $html);
  }
  if (preg_match('#^/forever/professions(?:/([a-z-]+))?/?(?:index\.html)?$#', $path, $m)) {
    $GLOBALS['w8_source'] = \PROFESSION_SOURCE;
    return ($m[1] ?? '') !== '' ? profession_page($m[1], $html) : professions_page($html);
  }
  if (preg_match('#^/(retail|forever)/skin-colors(?:/([a-z0-9-]+))?/?(index\.html)?$#', $path, $m)) { $GLOBALS['w8_source'] = $m[1]; return skin_colors_page($html, $m[2] ?? null, $m[1]); }
  // The current skin's race switcher fetches these fragments; they stay as they are.
  if (str_starts_with($path, '/retail/skin-colors') || str_starts_with($path, '/forever/skin-colors')) return $html;
  // Blog posts have long titles: the banner and breadcrumb just say "Blog"; the post shows its own title.
  if ($path === '/blog.php') return framed_page(null, $html, 'blog', 'census', 'Blog', 'WOWCENSUS · NEWS & ANALYSIS');
  return framed_page(null, $html, null, $path === '/contact' ? 'contact' : 'census');
}

// ---------------------------------------------------------------- pages

/** A page without a 2008 design of its own: its content, inside the 2008 frame. */
function framed_page(?string $source, string $html, ?string $page, string $art = 'census', ?string $bannerTitle = null, ?string $subtitle = null): string {
  $body = '';
  $start = strpos($html, '<div class="wrap">');
  if ($start !== false) {
    $body = substr($html, $start);
    $body = preg_replace('/<header class="ihead".*?<\/header>/s', '', $body, 1);
    $body = preg_replace('/<div class="page-controls">\s*<nav class="[^"]*page-nav.*?<\/nav>/s', '<div class="page-controls">', $body, 1);
    $end = strripos($body, '</body>');
    if ($end !== false) $body = substr($body, 0, $end);
  }
  // The combo table is shared with the other skins. Shorten its visible text
  // inside this frame while keeping linked race paths and data keys intact.
  if (in_array($source, ['classic-beta', 'forever'], true) && $page === 'combos')
    $body = preg_replace_callback('/>([^<]+)</', static fn($m) => '>' . str_replace(
      ['High Order Skyborne', 'Windshaper Skyborne'], 'Skyborne', $m[1]) . '<', $body);
  preg_match('/<title>(.*?)<\/title>/s', $html, $t);
  $title = trim(preg_replace('/\s+[–-]\s+WoWCensus$/u', '', html_entity_decode($t[1] ?? 'WoWCensus', ENT_QUOTES | ENT_HTML5, 'UTF-8')));
  // The banner and breadcrumb already name the edition.
  if ($source !== null) $title = preg_replace('/^' . preg_quote(\seo_label(\EDITIONS[$source]['label']), '/') . '\s+/u', '', $title);
  if ($bannerTitle !== null) $title = $bannerTitle;
  $styles = '';
  if (preg_match_all('/<style\b[^>]*>.*?<\/style>/is', substr($html, 0, stripos($html, '</head>') ?: 0), $m)) $styles = implode("\n", $m[0]);
  return page('framed', ['title' => $title, 'body' => $body, 'styles' => $styles, 'source' => $source, 'art' => $art, 'subtitle' => $subtitle], $html, $page);
}

function census_page(string $source, string $html): string {
  $view = \census_views($source)[0]['census'];
  $faction = fn(string $f) => array_values(array_filter($view['groups'], fn($g) => $g['faction'] === $f))[0] ?? null;
  $data = ['top' => [], 'race' => [], 'cls' => []];
  $totals = [];
  foreach (['A' => 'Alliance', 'H' => 'Horde'] as $k => $name) {
    $g = $faction($name);
    $totals[$k] = $g['characters'] ?? 0;
    $combos = $g['combos'] ?? [];
    arsort($combos);
    $data['top'][$k] = [];
    foreach (array_slice($combos, 0, 3, true) as $combo => $n) {
      [$race, $cf] = explode("\t", (string)$combo);
      $data['top'][$k][] = [$race . ' ' . (\CLASS_META[$cf][0] ?? $cf), $n, $totals[$k] ? round(100 * $n / $totals[$k], 1) : 0];
    }
    $races = $g['races'] ?? [];
    arsort($races);
    $data['race'][$k] = array_map(fn($r, $n) => [(string)$r, $n], array_keys($races), $races);
    $classes = [];
    foreach ($g['classes'] ?? [] as $cf => $n) $classes[\CLASS_META[$cf][0] ?? $cf] = $n;
    $data['cls'][$k] = (object)$classes;
  }
  $summary = \inspect_summary($source);
  $split = in_array($source, ['retail', 'classic-progression', 'mop-classic', 'classic'], true) ? $summary['roleSplit'] : null;
  if ($split) {
    $total = array_sum($split['counts']);
    $data['roles'] = [['Tank', round(100 * $split['counts']['TANK'] / $total), '#4a7ac8'],
      ['Healer', round(100 * $split['counts']['HEALER'] / $total), '#3aa020'], ['Damage', round(100 * $split['counts']['DAMAGER'] / $total), '#b02a1a']];
    $data['roleNote'] = \js_locale($total) . ' inspected characters' . ($split['feral'] ? ' · ' . \js_locale($split['feral']) . ' Feral druids not counted' : '');
  }
  $census = \load_census($source);
  return page('census', [
    'data' => $data, 'coverage' => $summary['coverage'], 'inspectedAt' => \inspect_time($summary['lastT']),
    'stamp' => 'Page generated: ' . \utc_timestamp(\generated_at()), 'observedAt' => \utc_timestamp($census['lastT']),
    'subtitle' => edition_subtitle($source) . ' · POPULATION SURVEY', 'seen' => \seen_activity($source),
  ], $html, 'census');
}

/** Each edition's gem color on the front page. */
const EDITION_COLORS = [
  'forever' => '#007ea6', 'classic-beta' => '#4b8a9e', 'classic-progression' => '#7db642', 'classic' => '#f7b804', 'sod' => '#00b1e1',
  'hardcore' => '#ff4c02', 'mop-classic' => '#4abe7f', 'retail' => '#715ce8',
];
const HOME_NAMES = ['forever' => 'WoW: Forever', 'classic-beta' => 'WoW: Forever (Beta)', 'sod' => 'Season of Discovery'];

/** The front page: news, per-edition activity and the shoutbox, from data the census pages already use. */
function home_page(): string {
  $editions = [];
  foreach (\EDITIONS as $source => $_) {
    $seen = \seen_activity($source);
    if (!$seen) continue;
    $census = \load_census($source);
    // A week-over-week change only when the data covers both weeks (as components/seen.php).
    $r = $seen['7'];
    $delta = $r['prev'] && $seen['all']['firstDay'] <= $seen['lastDay'] - 13 ? 100 * ($r['n'] - $r['prev']) / $r['prev'] : null;
    $editions[] = ['source' => $source, 'name' => HOME_NAMES[$source] ?? edition_name($source), 'href' => url('census', $source),
      'color' => EDITION_COLORS[$source] ?? '#c9a050', 'n7' => $r['n'], 'prev7' => $r['prev'], 'delta' => $delta,
      'total' => $seen['all']['n'], 'realms' => count($census['realms']), 'lastT' => (int)$census['lastT'],
      'week' => $r['running'], 'lastDay' => (int)$seen['lastDay']];
  }
  usort($editions, fn($a, $b) => $b['n7'] <=> $a['n7']);

  $posts = require __DIR__ . '/blog-posts.php';
  uksort($posts, fn($a, $b) => strcmp($posts[$b]['date'], $posts[$a]['date']) ?: ((int)$b <=> (int)$a));
  $news = [];
  foreach (array_slice($posts, 0, 3, true) as $id => $p) {
    $text = trim(preg_replace('/\s+/u', ' ', $p['body']));
    if (mb_strlen($text) > 380) $text = rtrim(mb_substr($text, 0, mb_strrpos(mb_substr($text, 0, 380), ' ')), ' ,.;:') . '…';
    $news[] = ['id' => $id, 'title' => $p['title'], 'date' => $p['date'], 'excerpt' => $text];
  }

  // "Seen last 7 days" counts only editions with data in the newest week: an edition whose collection
  // stopped (the Forever beta) would otherwise add its final week. No overall week-over-week change: the
  // editions scanned in both weeks get uneven effort, so the sum reflects scanning, not players.
  $latestDay = $editions ? max(array_column($editions, 'lastDay')) : 0;
  $current = array_filter($editions, fn($e) => $e['lastDay'] >= $latestDay - 6);
  $newest = $editions ? array_reduce($editions, fn($m, $e) => $m === null || $e['lastT'] > $m['lastT'] ? $e : $m) : null;
  $totals = [
    'tracked' => array_sum(array_column($editions, 'total')), 'seen7' => array_sum(array_column($current, 'n7')),
    'seen7Editions' => count($current), 'change' => null, 'realms' => array_sum(array_column($editions, 'realms')),
    'newestT' => $newest['lastT'] ?? 0, 'newestName' => $newest['name'] ?? '',
  ];

  $head = '<title>WoWCensus – World of Warcraft Population Census</title>' .
    '<meta name="description" content="WoWCensus tracks characters seen in game across WoW Forever, TBC Anniversary, Classic Era, Season of Discovery, Hardcore, MoP Classic and Retail: population, faction balance, race and class, auction prices and more.">' .
    '<link rel="canonical" href="' . e(\SITE_ORIGIN . '/') . '">' . \ANALYTICS_TAG;
  $GLOBALS['w8_sections'] = [];
  $content = render('pages/home.php', ['editions' => $editions, 'news' => $news, 'totals' => $totals,
    'poll' => require __DIR__ . '/poll-question.php']);
  return render('layout.php', ['head' => $head, 'content' => $content, 'wide' => false, 'source' => null,
    'page' => 'home', 'route' => ['page' => 'home']]);
}

// Approximate positions on the Classic and TBC continent art (1002 x 668 source coordinates).
// K = Kalimdor, E = Eastern Kingdoms, O = Outland.
const MAP_POSITIONS = [
  "Shattrath City" => ['O', 472, 472],
  "Black Temple" => ['O', 704, 536],
  "Stormwind City" => ['E', 475, 478],
  "Orgrimmar" => ['K', 618, 309],
  "Hellfire Peninsula" => ['O', 582, 348],
  "Terokkar Forest" => ['O', 483, 484],
  "Nagrand" => ['O', 314, 454],
  "Zangarmarsh" => ['O', 350, 324],
  "Hyjal Summit" => ['K', 519, 220],
  "The Barrens" => ['K', 495, 374],
  "Shadowmoon Valley" => ['O', 670, 535],
  "Netherstorm" => ['O', 588, 151],
  "Stranglethorn Vale" => ['E', 485, 589],
  "Tanaris" => ['K', 549, 551],
  "Ironforge" => ['E', 475, 394],
  "Scarlet Monastery" => ['E', 495, 217],
  "Blade's Edge Mountains" => ['O', 383, 204],
  "Elwynn Forest" => ['E', 493, 483],
  "Durotar" => ['K', 605, 324],
  "Eversong Woods" => ['E', 564, 111],
  "Ghostlands" => ['E', 560, 160],
  "Hillsbrad Foothills" => ['E', 441, 290],
  "Dustwallow Marsh" => ['K', 562, 455],
  "Ashenvale" => ['K', 492, 286],
  "Wetlands" => ['E', 504, 352],
  "Karazhan" => ['E', 552, 520],
  "Tirisfal Glades" => ['E', 480, 228],
  "Serpentshrine Cavern" => ['O', 356, 324],
  "Thunder Bluff" => ['K', 432, 391],
  "Dun Morogh" => ['E', 467, 399],
  "Undercity" => ['E', 480, 233],
  "Teldrassil" => ['K', 422, 66],
  "Duskwood" => ['E', 501, 512],
  "Darkshore" => ['K', 428, 214],
  "The Steamvault" => ['O', 358, 320],
  "Redridge Mountains" => ['E', 533, 484],
  "Westfall" => ['E', 438, 496],
  "Thousand Needles" => ['K', 482, 481],
  "Arathi Highlands" => ['E', 512, 307],
  "Hellfire Ramparts" => ['O', 602, 352],
  "Azuremyst Isle" => ['K', 321, 158],
  "Eye of the Storm" => ['O', 602, 139],
  "Un'Goro Crater" => ['K', 472, 548],
  "Feralas" => ['K', 400, 483],
  "Stonetalon Mountains" => ['K', 405, 334],
  "Western Plaguelands" => ['E', 478, 259],
  "Felwood" => ['K', 444, 251],
  "Loch Modan" => ['E', 535, 396],
  "Mana-Tombs" => ['O', 473, 487],
  "Silvermoon City" => ['E', 566, 112],
];

// SoD's endgame and discovery locations, placed on the Classic-era maps.
const SOD_MAP_POSITIONS = [
  "Scarlet Enclave" => ['E', 566, 254],
  "Eastern Plaguelands" => ['E', 560, 260],
  "Naxxramas" => ['E', 580, 255],
  "Ahn'Qiraj" => ['K', 415, 590],
  "Mulgore" => ['K', 431, 405],
  "The Hinterlands" => ['E', 545, 289],
  "Searing Gorge" => ['E', 500, 425],
  "Stratholme" => ['E', 554, 245],
  "Winterspring" => ['K', 551, 244],
  "Silverpine Forest" => ['E', 430, 253],
  "Silithus" => ['K', 400, 570],
  "Karazhan Crypts" => ['E', 551, 521],
  "Darnassus" => ['K', 421, 68],
  "Zul'Farrak" => ['K', 553, 541],
  "Desolace" => ['K', 400, 417],
  "Blackrock Depths" => ['E', 504, 444],
  "Blackrock Spire" => ['E', 504, 438],
  "Burning Steppes" => ['E', 509, 450],
  "Scholomance" => ['E', 466, 267],
  "Azshara" => ['K', 618, 270],
  "Blackwing Lair" => ['E', 506, 438],
];

// Additional locations prominent in the Hardcore population sample.
const HARDCORE_MAP_POSITIONS = [
  "Wailing Caverns" => ['K', 482, 370],
  "Badlands" => ['E', 552, 414],
  "The Deadmines" => ['E', 438, 500],
  "Dire Maul" => ['K', 402, 489],
  "Alterac Mountains" => ['E', 471, 282],
  "Blasted Lands" => ['E', 548, 553],
  "Ragefire Chasm" => ['K', 613, 314],
];

// Forever beta combines the old-world geography with several custom regions.
// Only locations identifiable on the Classic continent art are pinned.
const FOREVER_MAP_POSITIONS = [
  "Coldridge Valley" => ['E', 464, 411],
  "The Crossroads" => ['K', 494, 365],
  "Deathknell" => ['E', 469, 224],
  "Northshire Valley" => ['E', 484, 475],
  "Valley of Trials" => ['K', 605, 341],
  "Sentinel Hill" => ['E', 439, 501],
  "Goldshire" => ['E', 494, 491],
  "Shadowfang Keep" => ['E', 426, 257],
  "Blackfathom Deeps" => ['K', 446, 283],
  "Shadowglen" => ['K', 409, 67],
  "The Hall of Thanes" => ['E', 476, 392],
  "Lakeshire" => ['E', 534, 489],
  "Ratchet" => ['K', 550, 395],
  "Ruins of Lordaeron" => ['E', 480, 235],
  "Auberdine" => ['K', 426, 214],
  "Darkshire" => ['E', 501, 511],
  "Tarren Mill" => ['E', 443, 285],
  "The Sepulcher" => ['E', 427, 262],
  "Menethil Harbor" => ['E', 500, 353],
  "Brill" => ['E', 485, 228],
];

// MoP's busiest places across the five continent views. Two separate-world
// destinations (Darkmoon Island and Deepholm) remain in the location table.
const MOP_MAP_POSITIONS = [
  "Siege of Orgrimmar" => ['K', 618, 309],
  "Valley of the Four Winds" => ['P', 519, 435],
  "Shrine of Seven Stars" => ['P', 496, 342],
  "Timeless Isle" => ['P', 898, 444],
  "The Jade Forest" => ['P', 689, 310],
  "Shrine of Two Moons" => ['P', 465, 338],
  "Kun-Lai Summit" => ['P', 449, 222],
  "Northern Barrens" => ['K', 495, 363],
  "Borean Tundra" => ['N', 298, 423],
  "Townlong Steppes" => ['P', 319, 305],
  "Mount Hyjal" => ['K', 519, 220],
  "Northern Stranglethorn" => ['E', 485, 559],
  "Dalaran" => ['N', 491, 331],
  "Vale of Eternal Blossoms" => ['P', 505, 349],
  "Dread Wastes" => ['P', 327, 468],
  "Krasarang Wilds" => ['P', 518, 550],
  "The Cape of Stranglethorn" => ['E', 486, 613],
  "Dragonblight" => ['N', 527, 388],
  "Temple of the Jade Serpent" => ['P', 699, 358],
  "Plaguelands: The Scarlet Enclave" => ['E', 566, 254],
  "Shado-Pan Monastery" => ['P', 393, 225],
  "Siege of Niuzao Temple" => ['P', 293, 289],
  "Isle of Thunder" => ['P', 220, 106],
];

// Retail's current regions; mapped instances use their entrance region.
// Other interiors stay in the location list rather than receiving a guessed point.
const RETAIL_MAP_POSITIONS = [
  "Silvermoon City" => ['M', 305, 267],
  "Eversong Woods" => ['M', 233, 388],
  "Zul'Aman" => ['M', 453, 526],
  "The Coiled Isle" => ['M', 812, 349],
  "The Venomous Abyss" => ['M', 816, 310],
  "Vaults of Atal'Utek" => ['M', 857, 355],
  "Altar of Fangs" => ['M', 777, 381],
  "Harandar" => ['M', 807, 141],
  "Voidstorm" => ['M', 528, 158],
  "Isle of Quel'Danas" => ['M', 269, 123],
  "Murder Row" => ['M', 332, 279],
  "Den of Nalorakk" => ['M', 419, 556],
  "Maisara Caverns" => ['M', 482, 548],
  "Ohn'ahran Plains" => ['D', 356, 386],
  "The Waking Shores" => ['D', 510, 233],
  "Ruby Life Pools" => ['D', 537, 280],
  "The Azure Span" => ['D', 564, 526],
  "Valdrakken" => ['D', 643, 444],
  "Thaldraszus" => ['D', 645, 367],
  "The Forbidden Reach" => ['D', 660, 74],
  "Dornogal" => ['H', 661, 126],
  "Isle of Dorn" => ['H', 747, 186],
  "The Ringing Deeps" => ['H', 535, 355],
  "Hallowfall" => ['H', 357, 357],
  "Azj-Kahet" => ['H', 452, 513],
];

// Retain the original schematic positions for other editions.
const SCHEMATIC_POSITIONS = [
  "Shattrath City" => [706, 282],
  "Black Temple" => [836, 318],
  "Stormwind City" => [404, 292],
  "Orgrimmar" => [232, 152],
  "Hellfire Peninsula" => [770, 238],
  "Terokkar Forest" => [738, 306],
  "Nagrand" => [654, 300],
  "Zangarmarsh" => [666, 222],
  "Hyjal Summit" => [244, 352],
  "The Barrens" => [194, 228],
  "Shadowmoon Valley" => [800, 330],
  "Netherstorm" => [756, 106],
  "Stranglethorn Vale" => [428, 392],
  "Tanaris" => [212, 372],
  "Ironforge" => [446, 222],
  "Scarlet Monastery" => [374, 58],
  "Blade's Edge Mountains" => [686, 140],
  "Elwynn Forest" => [432, 304],
  "Durotar" => [246, 186],
  "Eversong Woods" => [506, 44],
  "Ghostlands" => [510, 78],
  "Hillsbrad Foothills" => [418, 132],
  "Dustwallow Marsh" => [232, 292],
  "Ashenvale" => [152, 148],
  "Wetlands" => [458, 182],
  "Karazhan" => [466, 346],
  "Tirisfal Glades" => [392, 74],
  "Serpentshrine Cavern" => [680, 204],
  "Thunder Bluff" => [134, 234],
  "Dun Morogh" => [424, 232],
  "Undercity" => [408, 86],
  "Teldrassil" => [108, 44],
  "Duskwood" => [440, 336],
  "Darkshore" => [140, 96],
  "The Steamvault" => [650, 238],
  "Redridge Mountains" => [468, 298],
  "Westfall" => [398, 322],
  "Thousand Needles" => [180, 320],
  "Arathi Highlands" => [470, 142],
  "Hellfire Ramparts" => [784, 222],
  "Azuremyst Isle" => [58, 112],
  "Eye of the Storm" => [778, 90],
  "Un'Goro Crater" => [164, 372],
  "Feralas" => [108, 310],
  "Stonetalon Mountains" => [108, 182],
  "Western Plaguelands" => [430, 100],
  "Felwood" => [176, 112],
  "Loch Modan" => [482, 226],
  "Mana-Tombs" => [724, 326],
  "Silvermoon City" => [524, 32],
];

// ---------------------------------------------------------------- page adapters
// Each builds the data its mockup template's script draws from, in the shape the
// mockup's sample data had.

/** Class display name for a class file ('DEATHKNIGHT' -> 'Death Knight'). */
function class_name(string $cf): string { return \CLASS_META[$cf][0] ?? $cf; }

function faction_key(?string $faction): string { return match ($faction) { 'Alliance' => 'A', 'Horde' => 'H', default => 'N' }; }

function common_vars(string $source): array {
  return ['subtitle' => edition_subtitle($source), 'generated' => \utc_timestamp(\generated_at()), 'source' => $source];
}

function talents_page(string $source, string $html): string {
  $packed = \talent_page_model($source);
  $summary = \inspect_summary($source);
  $talentIcons = [];
  foreach (\class_profiles_model($source) as $classFile => $profile)
    foreach ($profile['trees'] ?? [] as $tree)
      foreach ($tree['talents'] ?? [] as $talent)
        if (!empty($talent['icon'])) $talentIcons[class_name((string)$classFile)][$talent['name']] = $talent['icon'];
  $builds = ['A' => [], 'H' => []]; $totals = []; $picks = [];
  foreach (\talent_rows($packed) as $r) {
    $cls = class_name((string)$r['classFile']);
    if ($r['talents']) {
      $totals[$cls] = ($totals[$cls] ?? 0) + 1;
      foreach ($r['talents'] as $ref) {
        $name = $packed['names'][$ref];
        $picks[$ref] = [$cls, $name, ($picks[$ref][2] ?? 0) + 1, $talentIcons[$cls][$name] ?? null];
      }
    }
    $k = faction_key($r['faction']);
    if ($k === 'N' || ($r['classification'] ?? '') === '' || $r['classification'] === 'Unknown build') continue;
    $spec = $r['classification'] . ($r['hybrid'] ? ' Hybrid' : '');
    $builds[$k]["$cls|$spec"] = ($builds[$k]["$cls|$spec"] ?? 0) + 1;
  }
  // Class profiles omit unclassified builds and branches outside known specs.
  // This compact snapshot fills those gaps without loading full inspections
  // into the memory-limited web worker.
  if (array_filter($picks, fn($pick) => !$pick[3])) {
    $fallback = json_decode(file_get_contents(TEMPLATE_DIR . '/talent-icon-fallback.json'), true)[$source] ?? [];
    foreach ($picks as &$pick) if (!$pick[3]) $pick[3] = $fallback[$pick[0]][$pick[1]] ?? null;
    unset($pick);
  }
  foreach ($builds as $k => $counts) {
    arsort($counts);
    $builds[$k] = array_map(fn($key, $n) => [...explode('|', (string)$key, 2), $n], array_keys($counts), $counts);
  }
  // Class order as the site's talents page lists them.
  $order = \class_profiles()[$source]['classes'] ?? array_keys(\CLASS_META);
  $classes = array_values(array_filter(array_map('Wow2008\class_name', $order), fn($c) => isset($totals[$c])));
  foreach (array_keys($totals) as $c) if (!in_array($c, $classes, true)) $classes[] = $c;
  return page('talents', common_vars($source) + ['data' => ['builds' => $builds, 'totals' => (object)$totals, 'talents' => array_values($picks)],
    'classes' => $classes, 'inspectedAt' => \inspect_time($summary['lastT'])], $html, 'talents');
}

function gear_page(string $source, string $html): string {
  $model = \gear_page_model($source);
  $packed = $model['packed'];
  $records = \unpack_gear_records($packed);
  $groups = [['Head', [1]], ['Neck', [2]], ['Shoulder', [3]], ['Back', [15]], ['Chest', [5]], ['Wrist', [9]], ['Hands', [10]], ['Waist', [6]], ['Legs', [7]], ['Feet', [8]],
    ['Rings', [11, 12]], ['Trinkets', [13, 14]], ['Main Hand', [16]], ['Off Hand', [17]], ['Ranged', [18]], ['Shirt', [4]], ['Tabard', [19]]];
  $slots = [];
  foreach ($groups as [$label, $ids]) {
    $filled = 0; $enchanted = 0; $items = [];
    foreach ($records as $r) {
      $worn = array_values(array_filter($r['gear'], fn($w) => in_array($w[0], $ids, true)));
      if (!$worn) continue;
      $filled++;
      foreach ($worn as $w) if ($w[2] === 1) { $enchanted++; break; }
      $seen = [];
      foreach ($worn as $w) if (!isset($seen[$w[1]])) { $seen[$w[1]] = true; $items[$w[1]] = ($items[$w[1]] ?? 0) + 1; }
    }
    if (!$filled) continue;
    uksort($items, fn($a, $b) => $items[$b] <=> $items[$a] ?: strcmp((string)$packed['items'][$a][1], (string)$packed['items'][$b][1]));
    $top = [];
    foreach (array_slice($items, 0, 10, true) as $i => $n) {
      [$id, $name, $quality, $icon] = $packed['items'][$i];
      $top[] = [(string)$name, $quality < 0 ? 1 : $quality, $id, $n, round(100 * $n / $filled, 1), $icon ?: null];
    }
    $slots[] = ['slot' => $label, 'filled' => $filled, 'of' => count($records), 'ench' => (int)round(100 * $enchanted / $filled), 'distinct' => count($items), 'items' => $top];
  }
  return page('gear', common_vars($source) + ['data' => ['latest' => \inspect_time($model['lastT']), 'generated' => \utc_timestamp(\generated_at()), 'slots' => $slots,
    'wowhead' => $packed['wowhead']]], $html, 'gear');
}

/** Short realm labels for a list of the site's realm keys. */
function realm_label_map(array $realms): array {
  $labels = \realm_labels($realms);
  return array_map(fn($r) => preg_replace('/^(.*)\s+\((EU|US|KR|TW|CN)\)$/', '$1 ($2)', $labels[$r] ?? \realm_name($r)), array_combine($realms, $realms));
}

const REALM_PALETTE = ['#c9a050', '#8a5ac8', '#3aa060', '#4a8ad0', '#d0603a', '#3ab0b0', '#b04a8a', '#8aa030', '#a07050', '#6a6ad0'];

function guilds_page(string $source, string $html): string {
  $snap = \model_guilds_geography($source, 'guilds')['views'][0]['snapshot'];
  // Forever's collector realm numbers are meaningless to players: group them by
  // region and type, the way the realms page does ("US PvE").
  $groupLabels = [];
  $guilds = array_map(function ($g) use ($source, &$groupLabels) {
    if ($source === 'forever' && ($group = \forever_realm_group($g['realm']))) {
      $groupLabels[$group['key']] = $group['label'];
      $g['realm'] = $group['key'];
    }
    return $g;
  }, $snap['guilds']);
  // The overall top 100, plus each realm and faction's own top 25 so small realms still have a list.
  $top = []; $perRealm = [];
  foreach (array_values(array_filter($guilds, fn($g) => in_array($g['faction'], ['Alliance', 'Horde'], true))) as $i => $g) {
    $n = $perRealm[$g['realm'] . '|' . $g['faction']] = ($perRealm[$g['realm'] . '|' . $g['faction']] ?? 0) + 1;
    if ($i < 100 || $n <= 25) $top[] = $g + ['rank' => $i + 1];
  }
  $realms = array_values(array_unique(array_merge(array_map(fn($g) => $g['realm'], array_filter($top, fn($g) => $g['rank'] <= 100)),
    array_map(fn($g) => $g['realm'], $top))));
  $labels = $groupLabels + realm_label_map(array_values(array_diff($realms, array_keys($groupLabels))));
  $keys = array_combine($realms, array_map(fn($i) => 'r' . $i, array_keys($realms)));
  $raw = implode("\n", array_map(fn($g) => str_replace(['|', "\n"], ['¦', ' '], \guild_display_name($g['name'])) . '|' . $keys[$g['realm']] . '|' . faction_key($g['faction']) . '|' . $g['members']
    . '|' . \guild_id($source, $g['game'], $g['name']) . '|' . $g['rank'], $top));
  $realmNames = []; $realmColors = [];
  foreach ($realms as $i => $r) { $realmNames[$keys[$r]] = $labels[$r]; $realmColors[$keys[$r]] = REALM_PALETTE[$i % count(REALM_PALETTE)]; }
  $guilded = $snap['guildedCharacters']; $surveyed = $snap['surveyedCharacters'];
  return page('guilds', common_vars($source) + ['raw' => $raw, 'guildBase' => url('guilds', $source) . '/', 'realmNames' => (object)$realmNames, 'realmColors' => (object)$realmColors,
    'guildCount' => count($snap['guilds']), 'guilded' => $guilded, 'surveyed' => $surveyed, 'inGuild' => $surveyed ? round(100 * $guilded / $surveyed, 1) : 0,
    'updated' => !empty($snap['lastT']) ? substr(\js_iso($snap['lastT']), 0, 10) : '—', 'realmList' => array_values($labels)], $html, 'guilds');
}

function guild_page(string $source, array $g, string $html): string {
  $realm = \guild_realm_label($g['realm']);
  $classes = []; $races = []; $levels = []; $classColor = [];
  foreach ($g['members'] as [, $level, $race, $cf]) {
    if ($cf !== null) { $classes[class_name($cf)] = ($classes[class_name($cf)] ?? 0) + 1; $classColor[class_name($cf)] = '#' . (\CLASS_META[$cf][1] ?? 'C0C0C0'); }
    if ($race !== null && $race !== '') $races[$race] = ($races[$race] ?? 0) + 1;
    if ($level) $levels[] = (int)$level;
  }
  arsort($classes); arsort($races);
  // Ten-level bands, top band first, with the highest level seen on its own.
  $max = $levels ? max($levels) : 0; $bands = [];
  foreach ($levels as $l) {
    $band = $l === $max ? (string)$max : (string)(intdiv($l - 1, 10) * 10 + 1) . '–' . min($max - 1, intdiv($l - 1, 10) * 10 + 10);
    $bands[$band] = ($bands[$band] ?? 0) + 1;
  }
  uksort($bands, fn($a, $b) => (int)$b <=> (int)$a);
  $members = array_map(fn($m) => [$m[0], $m[1], $m[2], $m[3] !== null ? class_name($m[3]) : null, $m[4], $m[5] ? gmdate('Y-m-d', (int)$m[5]) : null], $g['members']);
  $specs = array_map(fn($s) => [class_name($s[0]), $s[1], $s[2]], $g['specs']);
  foreach ($g['specs'] as $s) $classColor[class_name($s[0])] = '#' . (\CLASS_META[$s[0]][1] ?? 'C0C0C0');
  return page('guild', common_vars($source) + ['name' => \guild_display_name($g['name']), 'realm' => $realm, 'faction' => $g['faction'], 'members' => $members,
    'classes' => (object)$classes, 'races' => (object)$races, 'bands' => (object)$bands, 'specs' => $specs, 'classColor' => (object)$classColor,
    'inspected' => array_sum(array_column($g['specs'], 2)), 'avgLevel' => $levels ? round(array_sum($levels) / count($levels), 1) : 0, 'maxLevel' => $max,
    'firstSeen' => $g['firstT'] ? gmdate('M j, Y', (int)$g['firstT']) : '—', 'lastSeen' => $g['lastT'] ? gmdate('M j, Y', (int)$g['lastT']) : '—',
    'allianceRaces' => array_keys(array_filter(\RACE_FACTION, fn($f) => $f === 'Alliance'))], $html, 'guilds');
}

function geography_page(string $source, string $html): string {
  $snap = \model_guilds_geography($source, 'geography')['views'][0]['snapshot'];
  $zones = array_values(array_filter($snap['zones'] ?? [], fn($z) => $z['name'] !== 'Unknown'));
  $total = ($snap['characters'] ?? 0) ?: array_sum(array_column($zones, 'characters'));
  $observedMax = 0;
  foreach ($zones as $z) $observedMax = max($observedMax, $z['maxLevel'] ?: 0);
  $type = fn($z) => ['End game' => 'End', 'Leveling' => 'Leveling', 'Capital' => 'Capital', 'Starting' => 'Starting'][\activity_hint($z, $observedMax)];
  $continentMap = in_array($source, ['classic-beta', 'forever', 'classic', 'classic-progression', 'sod', 'hardcore', 'mop-classic', 'retail'], true);
  $positions = match ($source) {
    'sod' => array_replace(MAP_POSITIONS, SOD_MAP_POSITIONS),
    'hardcore' => array_replace(MAP_POSITIONS, SOD_MAP_POSITIONS, HARDCORE_MAP_POSITIONS),
    'classic-beta' => array_replace(MAP_POSITIONS, SOD_MAP_POSITIONS, HARDCORE_MAP_POSITIONS, FOREVER_MAP_POSITIONS),
    'forever' => array_replace(MAP_POSITIONS, SOD_MAP_POSITIONS, HARDCORE_MAP_POSITIONS, FOREVER_MAP_POSITIONS),
    'mop-classic' => array_replace(MAP_POSITIONS, SOD_MAP_POSITIONS, HARDCORE_MAP_POSITIONS, MOP_MAP_POSITIONS),
    'retail' => array_replace(MAP_POSITIONS, SOD_MAP_POSITIONS, HARDCORE_MAP_POSITIONS, MOP_MAP_POSITIONS, RETAIL_MAP_POSITIONS),
    'classic', 'classic-progression' => MAP_POSITIONS,
    default => SCHEMATIC_POSITIONS,
  };
  $locs = []; $mix = ['End' => 0, 'Leveling' => 0, 'Capital' => 0, 'Starting' => 0]; $placed = 0; $top50 = 0;
  foreach ($zones as $i => $z) {
    $t = $type($z);
    $mix[$t] += $z['characters'];
    // Only the top 50 go on the map, and only where the map has a place for them.
    $pos = $i < 50 ? ($positions[$z['name']] ?? null) : null;
    if (in_array($source, ['classic-beta', 'forever', 'classic', 'sod', 'hardcore'], true) && ($pos[0] ?? null) === 'O') $pos = null;
    if ($i < 50) $top50 += $z['characters'];
    if ($pos) $placed += $z['characters'];
    $locs[] = [$z['name'], $z['characters'], $t, (int)round($z['avgLevel'] ?: 0), $continentMap ? ($pos[0] ?? null) : null, $continentMap ? ($pos[1] ?? null) : ($pos[0] ?? null), $continentMap ? ($pos[2] ?? null) : ($pos[1] ?? null)];
  }
  $located = array_sum($mix);
  arsort($mix);
  $mixRows = [];
  foreach ($mix as $k => $n) if ($n) $mixRows[] = [$k, $located ? (int)round(100 * $n / $located) : 0];
  $top5 = array_sum(array_map(fn($z) => $z['characters'], array_slice($zones, 0, 5)));
  return page('geography', common_vars($source) + ['data' => ['total' => $total, 'locs' => $locs, 'mix' => $mixRows],
    'total' => $total, 'count' => count($zones), 'top5' => $total ? (int)round(100 * $top5 / $total) : 0,
    'capital' => $located ? (int)round(100 * ($mix['Capital'] ?? 0) / $located) : 0, 'top' => $zones[0] ?? null,
    'window' => !empty($snap['windowDays']) ? 'last ' . $snap['windowDays'] . ' days' : 'all time',
    'showMap' => $top50 && $placed / $top50 >= .5, 'tbcMap' => $source === 'classic-progression', 'classicMap' => in_array($source, ['classic-beta', 'forever', 'classic', 'sod', 'hardcore'], true), 'mopMap' => $source === 'mop-classic', 'retailMap' => $source === 'retail', 'foreverMap' => in_array($source, ['classic-beta', 'forever'], true),
    'updated' => !empty($snap['lastT']) ? substr(\js_iso($snap['lastT']), 0, 10) : '—', 'mixSum' => array_sum(array_column($mixRows, 1))], $html, 'geography');
}

function realms_page(string $source, string $html): string {
  $data = \realm_explorer_data($source);
  $realms = [];
  $topList = function (array $f): array {
    $out = [];
    foreach (array_slice((array)$f['combos'], 0, 3, true) as $combo => $n) {
      [$race, $cf] = explode("\t", (string)$combo);
      $out[] = [$race . ' ' . class_name($cf), $n, $f['chars'] ? round(100 * $n / $f['chars'], 1) : 0];
    }
    return $out;
  };
  $seenAll = 0; $weekAll = 0; $enough = 0;
  foreach ($data['realms'] as $r) {
    $seen = 0; $week = 0; $day = 0; $classes = []; $races = [];
    foreach ($r['factions'] as $f) {
      $seen += $f['chars']; $week += $f['week']; $day += $f['day'];
      foreach ((array)$f['classes'] as $cf => $n) $classes[class_name((string)$cf)] = ($classes[class_name((string)$cf)] ?? 0) + $n;
      foreach ((array)$f['races'] as $race => $n) $races[(string)$race] = ($races[(string)$race] ?? 0) + $n;
    }
    arsort($classes); arsort($races);
    $pct = fn(array $m) => (object)array_map(fn($n) => $seen ? round(100 * $n / $seen, 1) : 0, $m);
    $a = $r['factions']['Alliance'] ?? null; $h = $r['factions']['Horde'] ?? null;
    $factionClasses = function (?array $f): ?object {
      if (!$f) return null;
      $classes = [];
      foreach ((array)$f['classes'] as $cf => $n) $classes[class_name((string)$cf)] = $f['chars'] ? round(100 * $n / $f['chars'], 1) : 0;
      return (object)$classes;
    };
    $realms[] = ['id' => $r['key'], 'name' => $r['name'], 'displayName' => $r['displayName'] ?? $r['name'], 'realmType' => $r['realmType'] ?? null,
      'groupRegion' => $r['region'] ? strtoupper((string)$r['region']) : null,
      'region' => $r['region'] && $r['regionShown'] ? strtoupper((string)$r['region']) : null, 'known' => (bool)$r['region'], 'pvp' => false,
      'seen' => $seen, 'd7' => $week, 'd24' => $day, 'A' => $a['chars'] ?? 0, 'H' => $h ? $h['chars'] : null,
      'top' => (string)array_key_first($classes), 'topA' => $a ? $topList($a) : [], 'topH' => $h ? $topList($h) : null,
      'cls' => $pct($classes), 'clsA' => $factionClasses($a), 'clsH' => $factionClasses($h), 'race' => $pct($races),
      'homes' => $r['homes'] ?? null, 'members' => $r['members'] ?? null, 'aliases' => $r['aliases'] ?? []];
    $seenAll += $seen; $weekAll += $week; if ($seen >= 20) $enough++;
  }
  $busiest = $source === 'forever' ? array_reduce($realms, fn($best, $r) => !$best || $r['seen'] > $best['seen'] ? $r : $best) : ($realms[0] ?? null);
  return page('realms', common_vars($source) + ['data' => $realms, 'allianceRaces' => array_keys(array_filter(\RACE_FACTION, fn($f) => $f === 'Alliance')),
    'connected' => in_array($source, ['mop-classic', 'retail'], true), 'mixed' => $source === 'retail', 'groupForever' => $source === 'forever',
    'count' => count($realms), 'enough' => $enough, 'seen' => $seenAll, 'week' => $weekAll,
    'busiest' => $busiest ? ($source === 'forever'
      ? $busiest['displayName']
      : $busiest['name'] . ($busiest['region'] ? ' (' . $busiest['region'] . ')' : '')) : '—',
    'busiestPct' => $busiest && $seenAll ? round(100 * $busiest['seen'] / $seenAll, 1) : 0,
    'scanned' => !empty($data['lastT']) ? gmdate('M j, Y', (int)$data['lastT']) : '—'], $html, 'realms');
}

function auction_page(string $source, string $html): string {
  $model = \auctionhouse_page_model($source);
  $realmLabels = realm_label_map(array_values(array_filter(array_column($model['dims']['realm'], 'key'), fn($k) => $k !== 'all')));
  $branch = \EDITIONS[$source]['branch'];
  $icons = (array)\item_cache('item-icons', $branch);
  // Forever reuses Classic items: Classic's cache fills ids the Forever one has not reached yet.
  if ($branch === 'forever') $icons += (array)\item_cache('item-icons', 'classic');
  $item = function (array $it) use ($icons): array {
    $change = null;
    if ($it['pq'] !== null) $change = $it['pq'] === 0 ? (($it['q'] ?: 0) > 0 ? 'NEW' : 0) : (int)\js_round((($it['q'] ?: 0) - $it['pq']) / $it['pq'] * 100);
    return ['name' => \item_label($it), 'id' => $it['id'], 'icon' => ($it['icon'] ?? null) ?: ($icons[$it['id']] ?? null), 'qty' => $it['q'] ?: 0, 'unit' => (int)\js_round($it['asp'] ?: 0),
      'value' => (int)floor(($it['mv'] ?: 0) / 10000), 'change' => $change];
  };
  $views = [];
  foreach ($model['views'] as $v) {
    $s = $v['summary'];
    if (!$s['itemCount']) { $views[] = ['realm' => $v['realm'], 'faction' => $v['faction'], 'empty' => true]; continue; }
    $views[] = ['realm' => $v['realm'], 'faction' => $v['faction'], 'scanned' => $s['updatedAt'] ? substr((string)$s['updatedAt'], 0, 10) : '—',
      'units' => $s['totalQty'], 'items' => $s['itemCount'], 'value' => (int)floor($s['totalValue'] / 10000), 'aggregate' => $s['aggregateOnly'],
      'breakouts' => array_map(fn($it) => [\item_label($it), $it['pct']], $s['breakouts']),
      'listed' => array_map($item, $s['byQty']), 'expensive' => array_map($item, $s['byPrice']), 'deepest' => array_map($item, $s['byValue']),
      'markets' => array_map(fn($e) => [\js_upper((string)$e[0]), $s['aggregateOnly'] ? $e[1] : (int)floor($e[1] / 10000)], $s['cats']),
      'excluded' => array_map($item, $s['joke']), 'excludeAbove' => $s['jokeThreshold'] !== null ? (int)floor($s['jokeThreshold'] / 10000) : null];
  }
  $realms = array_map(fn($r) => [$r['key'], $r['key'] === 'all' ? $r['label'] : ($realmLabels[$r['key']] ?? $r['label'])], $model['dims']['realm']);
  $factions = array_map(fn($f) => [$f['key'], $f['key'] === 'all' ? ($f['label'] === 'Cross-faction' ? 'Cross-faction' : 'Both') : $f['label']], $model['dims']['faction']);
  return page('auction-house', common_vars($source) + ['data' => ['generated' => \utc_timestamp(\generated_at()), 'views' => $views],
    'realms' => $realms, 'factions' => $factions, 'wowhead' => 'https://www.wowhead.com/' . ($branch !== '' ? $branch . '/' : '') . 'item=',
    // Forever items have pages of their own; other editions still link to Wowhead.
    'itemBase' => $source === \PROFESSION_SOURCE ? \forever_item_path('') : null], $html, 'auction-house');
}

/** Realm and faction filter rows for the professions pages, as on the auction house. */
function profession_filters(array $dims): array {
  $realmLabels = realm_label_map(array_values(array_filter(array_column($dims['realm'], 'key'), fn($k) => $k !== 'all')));
  return [
    array_map(fn($r) => [$r['key'], $r['key'] === 'all' ? $r['label'] : ($realmLabels[$r['key']] ?? $r['label'])], $dims['realm']),
    array_map(fn($f) => [$f['key'], $f['key'] === 'all' ? 'Both' : $f['label']], $dims['faction']),
  ];
}

function profession_cards(): array {
  $cards = [];
  foreach (\PROFESSIONS as $slug => $p) $cards[$slug] = ['name' => $p['name'], 'role' => $p['role'], 'kind' => $p['kind'], 'icon' => $p['icon'], 'blurb' => $p['blurb'], 'url' => \profession_path($slug)];
  return $cards;
}

function professions_page(string $html): string {
  $model = \professions_page_model();
  [$realms, $factions] = profession_filters($model['dims']);
  $views = array_map(fn($v) => ['realm' => $v['realm'], 'faction' => $v['faction'], 'scanned' => $v['scanned'], 'empty' => $v['empty'],
    'p' => array_map(fn($d) => [$d['items'], $d['units'], (int)floor($d['value'] / 10000), $d['matsCount'], $d['craftedCount'], $d['recipesCount']], $v['professions'])], $model['views']);
  return page('professions', common_vars(\PROFESSION_SOURCE) + ['cards' => profession_cards(), 'data' => ['generated' => \utc_timestamp(\generated_at()), 'views' => $views],
    'realms' => $realms, 'factions' => $factions], $html, 'professions');
}

function profession_page(string $slug, string $html): string {
  if (!isset(\PROFESSIONS[$slug])) return page('404', ['requested' => \profession_path($slug)], $html, null);
  $model = \professions_page_model();
  [$realms, $factions] = profession_filters($model['dims']);
  $views = array_map(fn($v) => ['realm' => $v['realm'], 'faction' => $v['faction'], 'scanned' => $v['scanned'], 'empty' => $v['empty'] || !$v['professions'][$slug]['items'],
    'p' => $v['professions'][$slug]], $model['views']);
  $branch = \EDITIONS[\PROFESSION_SOURCE]['branch'];
  return page('profession', common_vars(\PROFESSION_SOURCE) + ['slug' => $slug, 'prof' => \PROFESSIONS[$slug], 'cards' => profession_cards(),
    'data' => ['generated' => \utc_timestamp(\generated_at()), 'views' => $views], 'realms' => $realms, 'factions' => $factions,
    'wowhead' => 'https://www.wowhead.com/' . ($branch !== '' ? $branch . '/' : '') . 'item='], $html, 'professions');
}

function item_page(array $item, string $html): string {
  $keys = array_values(array_unique(array_map(fn($s) => $s[0], $item['scans'])));
  $labels = realm_label_map($keys);
  $realms = [];
  foreach ($keys as $k) $realms[$k] = $labels[$k] ?? \realm_name($k);
  $gathering = ['herbalism', 'mining', 'skinning', 'fishing'];
  $roleLabel = ['mats' => 'Material', 'crafted' => 'Crafted', 'recipes' => 'Recipe'];
  $trades = array_map(fn($r) => ['name' => \PROFESSIONS[$r[0]]['name'], 'icon' => \PROFESSIONS[$r[0]]['icon'], 'url' => \profession_path($r[0]),
    'role' => $r[1] === 'mats' && in_array($r[0], $gathering, true) ? 'Gathered' : $roleLabel[$r[1]]], $item['roles']);
  $branch = \EDITIONS[\PROFESSION_SOURCE]['branch'];
  return page('item', common_vars(\PROFESSION_SOURCE) + ['item' => $item, 'trades' => $trades,
    'data' => ['generated' => \utc_timestamp(\generated_at()), 'realms' => $realms, 'scans' => $item['scans']],
    'wowhead' => 'https://www.wowhead.com/' . ($branch !== '' ? $branch . '/' : '') . 'item=' . $item['id']], $html, 'auction-house');
}

/** Talent points available at the edition's level cap, for the "avg points per tree" bars. */
function tree_points(string $source): int { return $source === 'classic-progression' ? 61 : 51; }
function level_cap(string $source): int { return $source === 'classic-progression' ? 70 : 60; }

function races_page(string $source, string $html, string $slug): string {
  $model = \race_profiles_model($source);
  $pages = race_pages($source);
  $rd = [];
  foreach ($pages as $s => [$label, $path, $race]) {
    $d = $model[$race] ?? null;
    if (!$d) continue;
    $sample = $d['n'];
    $profile = \race_profiles()[$source][$race];
    $specs = []; $hybrids = []; $mix = []; $classes = [];
    foreach ($d['classes'] as $cf => $c) {
      $cls = class_name((string)$cf);
      $mix[] = [$cls, $sample ? (int)round(100 * $c['n'] / $sample) : 0];
      $hyb = 0; $split = [];
      foreach ($c['spec'] as $spec => $n) {
        $spec = (string)$spec;
        if (str_contains($spec, '/') || $spec === 'Unknown build') { $hyb += $n; continue; }
        $specs[] = [$spec, $cls, $n, $sample ? (int)round(100 * $n / $sample) : 0];
        $split[] = [$spec, $c['n'] ? (int)round(100 * $n / $c['n']) : 0];
      }
      if ($hyb) { $hybrids[] = [$cls, $hyb, $sample ? (int)round(100 * $hyb / $sample) : 0]; $split[] = ['Hybrid', $c['n'] ? (int)round(100 * $hyb / $c['n']) : 0]; }
      $classes[] = ['cls' => $cls, 'n' => $c['n'], 'pct' => $sample ? (int)round(100 * $c['n'] / $sample) : 0, 'split' => $split,
        'trees' => array_map(fn($t, $v) => [(string)$t, $v], array_keys((array)$c['trees']), array_values((array)$c['trees'])),
        'talents' => array_map(fn($t) => [$t['name'], $t['tree'], $t['pct'], $t['avgRank'], $t['max'], $t['icon'] ?? null], $c['tal']), 'median' => $c['medLv']];
    }
    usort($specs, fn($a, $b) => $b[2] <=> $a[2] ?: strcmp($a[0] . $a[1], $b[0] . $b[1]));
    usort($hybrids, fn($a, $b) => $b[1] <=> $a[1]);
    usort($mix, fn($a, $b) => $b[1] <=> $a[1]);
    usort($classes, fn($a, $b) => $b['n'] <=> $a['n']);
    $tiles = [];
    if ($classes) $tiles['Top class'] = [$classes[0]['cls'], \js_locale($classes[0]['n']) . ' of ' . \js_locale($sample) . ' · ' . $classes[0]['pct'] . '%'];
    if ($specs) $tiles['Top spec'] = [$specs[0][0], \js_locale($specs[0][2]) . ' players · ' . $specs[0][3] . '%'];
    foreach ($specs as $sp) if (in_array($sp[0], \TANK_SPECS, true)) { $tiles['Top tank spec'] = [($sp[0] === 'Protection' ? 'Prot' : $sp[0]) . ' ' . $sp[1], \js_locale($sp[2]) . ' inspected']; break; }
    if ($d['medLv'] !== null) $tiles['Median level'] = [(string)$d['medLv'], null];
    $displayName = in_array($race, ['High Order Skyborne', 'Windshaper Skyborne'], true) ? 'Skyborne' : $label;
    $rd[$s] = ['slug' => $s, 'name' => $displayName, 'plural' => $profile['plural'] ?? $label . 's', 'faction' => faction_key($profile['faction'] ?? null),
      'icon' => \RACE_ICON[$race] ?? null,
      'sample' => $sample, 'tiles' => (object)$tiles, 'specs' => array_slice($specs, 0, 12), 'hybrids' => $hybrids, 'classMix' => $mix, 'classes' => $classes,
      'path' => $path];
  }
  $route = ['edition' => $source, 'page' => 'races', 'base' => dirname(array_values($pages)[0][1]) . '/', 'initial' => $slug, 'queryUrls' => false];
  return page('races', common_vars($source) + ['data' => ['races' => $rd, 'order' => array_keys($rd), 'treePoints' => tree_points($source), 'levelCap' => level_cap($source)],
    'route' => $route], $html, 'races');
}

/** Treemap cells for swatch rows, as [hex, count, x%, y%, w%, h%] on a 2:1 box. */
function skin_cells(array $rows): array {
  $boxes = \skin_treemap(array_map(fn($r) => (int)$r['n'], $rows), 200, 100);
  return array_map(fn($r, $b) => [$r['swatch_hex'], (int)$r['n'], round($b[0] / 2, 3), round($b[1], 3), round($b[2] / 2, 3), round($b[3], 3)], $rows, $boxes);
}

function skin_colors_page(string $html, ?string $slug, string $source = 'retail'): string {
  $prefix = $source === 'forever' ? 'forever' : 'retail';
  $races = $prefix === 'forever' ? \forever_skin_color_races() : \retail_skin_color_races();
  $factionKey = ['Alliance' => 'A', 'Horde' => 'H', 'Either faction' => 'N'];
  $rd = [];
  foreach (($prefix === 'forever' ? \forever_skin_color_factions() : \retail_skin_color_factions()) as $faction => $members) foreach ($members as $race) {
    $sample = $prefix === 'forever' ? \forever_skin_race_sample($race) : \retail_skin_race_sample($race);
    $bodies = $prefix === 'forever' ? \forever_skin_body_rows($race, $sample['validRows']) : \retail_skin_body_rows($race, $sample['validRows']);
    $rd[\skin_color_slug($race)] = ['name' => $race, 'faction' => $factionKey[$faction], 'icon' => \RACE_ICON[$race] ?? null,
      'queried' => $sample['queried'], 'matched' => array_sum(array_column($sample['rows'], 'n')), 'cells' => skin_cells($sample['rows']),
      'bodies' => $bodies === null ? null : array_map(fn($body, $rows) => [$body, skin_cells($rows)], array_keys($bodies), $bodies),
      'note' => $prefix === 'forever' ? \forever_skin_form_note($race) : \retail_skin_form_note($race)];
  }
  $spectrum = $prefix === 'forever' ? \forever_skin_spectrum_data() : \retail_skin_spectrum_data();
  $rows = [];
  foreach ($spectrum['samples'] as $race => $points) {
    usort($points, fn($a, $b) => $b['n'] <=> $a['n']);
    $rows[] = [\skin_color_slug($race), round($spectrum['medians'][$race], 2), array_map(fn($p) => [$p['hex'], round($p['lightness'], 2), $p['n']], $points)];
  }
  $route = ['edition' => $source, 'page' => 'skin-colors', 'base' => '/' . $source . '/skin-colors/', 'initial' => $slug !== null && isset($races[$slug]) ? $slug : array_key_first($races)];
  $sampleInfo = $source === 'forever' ? \forever_skin_data() : null;
  return page('skin-colors', common_vars($source) + ['data' => ['races' => $rd, 'spectrum' => $rows, 'maxShare' => $spectrum['maxShare']], 'route' => $route, 'sampleInfo' => $sampleInfo], $html, 'skin-colors');
}

function classes_page(string $source, string $html, string $slug): string {
  $model = \class_profiles_model($source);
  $cd = [];
  foreach (class_pages($source) as $s => [$label, $path, $cf]) {
    $c = $model[$cf] ?? null;
    if (!$c) continue;
    $n = $c['n'];
    $split = array_map(fn($t) => [$t['tree'], $t['n'], $n ? (int)round(100 * $t['n'] / $n) : 0], $c['trees']);
    if ($c['hybrid']) $split[] = ['Hybrid', $c['hybrid'], $n ? (int)round(100 * $c['hybrid'] / $n) : 0];
    $specs = array_map(fn($t) => ['tree' => $t['tree'], 'icon' => \spec_icons()[$t['tree'] . ':' . $cf] ?? ('classicon_' . \js_lower($cf)), 'n' => $t['n'], 'pct' => $n ? (int)round(100 * $t['n'] / $n) : 0,
      'talents' => array_map(fn($x) => [$x['name'], $x['pct'], $x['icon'] ?? null], array_slice(array_values(array_filter($t['talents'], fn($x) => $x['n'] > 0)), 0, 25)),
      'median' => $t['medLv']], $c['trees']);
    $cd[$s] = ['slug' => $s, 'name' => $label, 'sample' => $n, 'split' => $split, 'specs' => $specs, 'path' => $path];
  }
  $pages = class_pages($source);
  $route = ['edition' => $source, 'page' => 'classes', 'base' => dirname(array_values($pages)[0][1]) . '/', 'initial' => $slug, 'queryUrls' => false];
  return page('classes', common_vars($source) + ['data' => ['classes' => $cd, 'order' => array_keys($cd)], 'route' => $route], $html, 'classes');
}
