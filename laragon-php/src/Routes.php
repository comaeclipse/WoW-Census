<?php
// The published route set: every page, Markdown copy, data file and stylesheet
// the site serves, as request paths. capture-page-data.php renders these to
// collect the origin's query results, and render-routes.php writes them out for
// tools that read rendered pages (the icon mirrors).

declare(strict_types=1);

/**
 * Request paths ("/tbc/census.html"). $sources limits the per-edition routes;
 * the shared routes (sitemap, robots, census.css, ...) come with $shared.
 * @return list<string>
 */
function published_routes(?array $sources = null, bool $shared = true): array {
  $routes = [];
  $cssName = stylesheet()['name'];
  foreach ($sources ?? array_keys(EDITIONS) as $source) {
    $dir = edition_path($source);
    foreach (PAGES as $page) { $routes[] = "{$dir}$page.html"; $routes[] = "{$dir}$page.md"; }
    $routes[] = "{$dir}realms.html";
    // Filter data files are content-hashed, so their names come from the data.
    foreach (['talents', 'gear'] as $page)
      if ($data = render_edition_data($source, $page)) $routes[] = $dir . $data['file'];
    foreach (race_profiles()[$source] ?? [] as $p) { $routes[] = $p['path'] . 'index.html'; $routes[] = $p['path'] . 'index.md'; }
    foreach (class_profiles()[$source]['classes'] ?? [] as $cf) {
      $routes[] = class_route($source, $cf) . 'index.html';
      $routes[] = class_route($source, $cf) . 'index.md';
    }
    $routes[] = "{$dir}census.json";
    $routes[] = "{$dir}$cssName";
    // Retail skin colours: the index, one page per race, and each race's swatch fragment.
    if ($source === 'retail')
      foreach (array_merge([null], array_keys(retail_skin_color_races())) as $slug) {
        $base = '/retail/skin-colors/' . ($slug === null ? '' : "$slug/");
        $routes[] = "{$base}index.html";
        $routes[] = "{$base}detail.html";
      }
    if ($source === 'forever')
      foreach (array_merge([null], array_keys(forever_skin_color_races())) as $slug) {
        $base = '/forever/skin-colors/' . ($slug === null ? '' : "$slug/");
        $routes[] = "{$base}index.html";
        $routes[] = "{$base}detail.html";
      }
  }
  if ($shared) array_push($routes, '/census.css', '/sitemap.xml', '/robots.txt', '/llms.txt', '/.well-known/ai-catalog.json');
  return $routes;
}
