<?php
// Map a request path to the file the static build would have published there.
// Returns [content-type, body] or null for a 404.

declare(strict_types=1);

function route_request(string $path): ?array {
  $html = 'text/html; charset=utf-8';
  $md = 'text/markdown; charset=utf-8';
  $json = 'application/json';
  ['name' => $cssName, 'css' => $css] = stylesheet();

  switch ($path) {
    case '/sitemap.xml': return ['application/xml', sitemap_xml()];
    case '/robots.txt': return ['text/plain; charset=utf-8', robots_txt()];
    case '/llms.txt': return ['text/plain; charset=utf-8', llms_txt()];
    case '/.well-known/ai-catalog.json': return [$json, ai_catalog_json()];
    case '/404.html': return [$html, not_found_html()];
    case '/_headers': return ['application/octet-stream', headers_file()];
    case '/census.css': return ['text/css', localize_content(census_css())];
    case '/notice-discord.png': return ['image/png', resource('public/notice-discord.png')];
    // The 2008 skin shows its front page at "/" and Forever's census here.
    case '/forever/': return [$html, edition_page_html('forever', 'index')];
  }

  if (preg_match('#^/retail/skin-colors(?:/([a-z0-9-]+))?/(index|detail)\.html$#', $path, $m)) {
    if (($m[1] ?? '') !== '' && !isset(retail_skin_color_races()[$m[1]])) return null;
    return [$html, localize_content(retail_skin_colors_page($cssName, $m[1] ?? null, $m[2] === 'detail'))];
  }
  if (preg_match('#^/retail/skin-colors(?:/([a-z0-9-]+))?/?$#', $path, $m)) {
    if (($m[1] ?? '') !== '' && !isset(retail_skin_color_races()[$m[1]])) return null;
    return [$html, localize_content(retail_skin_colors_page($cssName, $m[1] ?? null))];
  }
  if (preg_match('#^/forever/skin-colors(?:/([a-z0-9-]+))?/(index|detail)\.html$#', $path, $m)) {
    if (($m[1] ?? '') !== '' && !isset(forever_skin_color_races()[$m[1]])) return null;
    return [$html, localize_content(forever_skin_colors_page($cssName, $m[1] ?? null, $m[2] === 'detail'))];
  }
  if (preg_match('#^/forever/skin-colors(?:/([a-z0-9-]+))?/?$#', $path, $m)) {
    if (($m[1] ?? '') !== '' && !isset(forever_skin_color_races()[$m[1]])) return null;
    return [$html, localize_content(forever_skin_colors_page($cssName, $m[1] ?? null))];
  }

  // WoW: Forever item pages: /forever/item/765 (+ trailing slash).
  if (preg_match('#^/forever/item/(\d{1,7})/?$#', $path, $m)) {
    $item = forever_item_model((int)$m[1]);
    return $item ? [$html, localize_content(forever_item_html($item, edition_path(PROFESSION_SOURCE) . $cssName))] : null;
  }
  // WoW: Forever professions: /forever/professions and /forever/professions/tailoring (+ trailing slash or index.html).
  if (preg_match('#^/forever/professions(?:/([a-z-]+))?/?(?:index\.html)?$#', $path, $m)) {
    $slug = ($m[1] ?? '') !== '' ? $m[1] : null;
    if ($slug !== null && !isset(PROFESSIONS[$slug])) return null;
    return [$html, localize_content(professions_html($slug, edition_path(PROFESSION_SOURCE) . $cssName))];
  }


  // Realm explorer: /realms, /classic/realms (+ .html). It has no Markdown copy.
  if (preg_match('#^/(?:([a-z-]+)/)?realms(\.html)?$#', $path, $m)) {
    $source = source_for_path(($m[1] ?? '') !== '' ? '/' . $m[1] . '/' : '/');
    return $source ? [$html, realms_page_html($source)] : null;
  }

  // One realm's page: /retail/realms/us/stormrage (+ trailing slash). Like the explorer, no Markdown copy.
  if (preg_match('#^/(?:([a-z-]+)/)?realms/([a-z]+)/([^/]+)/?$#', $path, $m)) {
    $source = source_for_path(($m[1] ?? '') !== '' ? '/' . $m[1] . '/' : '/');
    $realm = $source ? find_explorer_realm(realm_explorer_data($source)['realms'], $m[2], $m[3]) : null;
    return $realm ? [$html, realms_page_html($source, $realm)] : null;
  }

  // Guild rosters:/guilds/<id>/, /classic/guilds/<id>/. Served by the origin only; the static site has none.
  if (preg_match('#^/(?:([a-z-]+)/)?guilds/(\d{12})/(?:index\.html)?$#', $path, $m)) {
    $source = source_for_path(($m[1] ?? '') !== '' ? '/' . $m[1] . '/' : '/');
    $roster = $source ? guild_roster($source, $m[2]) : null;
    return $roster ? [$html, guild_page_html($source, $roster)] : null;
  }

  // One realm x faction pane of a guilds or geography page, fetched when a visitor picks it.
  // Not text/html, so no skin wraps it; the skin's variant is in the query (view=aero).
  if (preg_match('#^/(?:([a-z-]+)/)?(guilds|geography)\.pane$#', $path, $m)) {
    $source = source_for_path(($m[1] ?? '') !== '' ? '/' . $m[1] . '/' : '/');
    $pane = $source ? edition_pane_html($source, $m[2], (string)($_GET['realm'] ?? ''), (string)($_GET['faction'] ?? ''), (string)($_GET['view'] ?? '')) : null;
    return $pane === null ? null : ['text/x-wowcensus-pane; charset=utf-8', $pane];
  }

  // Edition pages: /, /combos, /classic/, /classic/combos (+ .html and .md copies).
  if (preg_match('#^/(?:([a-z-]+)/)?(?:(index|combos|auctionhouse|guilds|geography|talents|gear)(\.html|\.md)?)?$#', $path, $m)) {
    $source = source_for_path(($m[1] ?? '') !== '' ? '/' . $m[1] . '/' : '/');
    $page = ($m[2] ?? '') !== '' ? $m[2] : 'index';
    $ext = $m[3] ?? '';
    // The directory index is served as "/" and "/index.html"; a bare "/index" was never published.
    if ($source && !($page === 'index' && ($m[2] ?? '') !== '' && $ext === '')) {
      if ($ext === '.md') return [$md, page_markdown($source, $page)];
      if ($page === 'index' && ($m[2] ?? '') === '' && !str_ends_with($path, '/')) return null;
      return [$html, edition_page_html($source, $page)];
    }
  }

  // Per-edition files.
  if (preg_match('#^/(?:([a-z-]+)/)?(census\.json|style\.[0-9a-f]{12}\.css|data/(talents|gear)\.[0-9a-f]{12}\.json)$#', $path, $m)) {
    $source = source_for_path(($m[1] ?? '') !== '' ? '/' . $m[1] . '/' : '/');
    if (!$source) return null;
    if ($m[2] === 'census.json') return [$json, census_json($source)];
    if (str_starts_with($m[2], 'style.')) return $m[2] === $cssName ? ['text/css', localize_content($css)] : null;
    $data = render_edition_data($source, $m[3]);
    return $data && $data['file'] === $m[2] ? [$json, $data['json'] . "\n"] : null;
  }

  // Race and class talent pages: /classic/human/ (+ index.html, index.md).
  if (preg_match('#^/([a-z-]+)/([a-z0-9-]+)/(index\.html|index\.md)?$#', $path, $m)) {
    $route = '/' . $m[1] . '/' . $m[2] . '/';
    foreach (race_profiles() as $source => $races)
      foreach ($races as $race => $p)
        if ($p['path'] === $route) return ($m[3] ?? '') === 'index.md' ? [$md, profile_markdown(race_page_html($source, $race), $route)] : [$html, race_page_html($source, $race)];
    foreach (class_profiles() as $source => $p)
      foreach ($p['classes'] as $cf)
        if (class_route($source, $cf) === $route) return ($m[3] ?? '') === 'index.md' ? [$md, profile_markdown(class_page_html($source, $cf), $route)] : [$html, class_page_html($source, $cf)];
  }
  return null;
}
