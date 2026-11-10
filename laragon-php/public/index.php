<?php
// Front controller: every page is rendered from PostgreSQL on request.
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Router.php';

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
header('X-Content-Type-Options: nosniff');
if ($path === '/skin') {
  $choice = $_GET['choice'] ?? '';
  $back = $_GET['return'] ?? '/';
  if (!in_array($choice, ['current', 'cobalt', 'wow2008'], true) || !is_string($back) || !str_starts_with($back, '/') || str_starts_with($back, '//') || str_contains($back, "\r") || str_contains($back, "\n")) {
    http_response_code(400);
    exit;
  }
  setcookie('wowcensus_skin', $choice, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
  header('Cache-Control: private, no-store');
  header('Location: ' . $back, true, 303);
  exit;
}
// Race to 60 was retired on 2026-11-09; its old URLs go to WoW: Forever's census.
if (preg_match('#^/[a-z-]+/race-to-60(?:\.html)?$#', $path)) {
  header('Location: /forever/', true, 301);
  exit;
}
if ($path === '/contact') {
  require dirname(__DIR__) . '/src/contact.php';
  exit;
}
if ($path === '/admin' || $path === '/admin/') {
  require dirname(__DIR__) . '/src/admin.php';
  exit;
}
if ($path === '/upload' || $path === '/upload/') {
  require dirname(__DIR__) . '/src/upload.php';
  exit;
}
if ($path === '/poll') {
  require dirname(__DIR__) . '/src/poll.php';
  exit;
}
if ($path === '/shoutbox.php' || $path === '/shoutbox' || $path === '/shoutbox/') {
  require dirname(__DIR__) . '/src/shoutbox.php';
  exit;
}
// Markdown for agents: a page with a Markdown copy answers Accept: text/markdown with it.
// Pages without one (realms, rosters, files) fall through to their usual response.
$negotiable = !preg_match('#\.[a-z0-9]+$#', $path) || str_ends_with($path, '.html');
$negotiated = false;
try {
  $result = null;
  if ($negotiable && prefers_markdown($_SERVER['HTTP_ACCEPT'] ?? '')) {
    $mdPath = str_ends_with($path, '/') ? $path . 'index.md' : (str_ends_with($path, '.html') ? substr($path, 0, -5) . '.md' : $path . '.md');
    $result = route_request($mdPath);
    $negotiated = $result !== null && str_starts_with($result[0], 'text/markdown');
    if (!$negotiated) $result = null;
  }
  $result ??= route_request($path);
} catch (Throwable $e) {
  http_response_code(500);
  header('Cache-Control: private, no-store');
  header('Content-Type: text/plain; charset=utf-8');
  echo "Page temporarily unavailable\n";
  error_log((string)$e);
  exit;
}
if ($result === null) {
  http_response_code(404);
  header('Cache-Control: no-store');
  header('Content-Type: text/html; charset=utf-8');
  echo with_skin(with_analytics(not_found_html()));
  exit;
}
[$type, $body] = $result;
header('Content-Type: ' . $type);
if ($negotiable) header('Vary: Accept');
// Announce the Markdown copy in a header too: the 2008 skin rebuilds <head> and drops the <link>.
if (str_starts_with($type, 'text/html') && preg_match('#<link rel="alternate" type="text/markdown" href="([^"]+)">#', $body, $alt))
  header('Link: <' . $alt[1] . '>; rel="alternate"; type="text/markdown"');
if ($negotiated) {
  // Cloudflare's cache ignores Vary: Accept, so the negotiated copy must never be cached at the edge
  // or browsers asking for this URL would get Markdown.
  header('Cache-Control: private, no-store');
  header('Cloudflare-CDN-Cache-Control: no-store');
} else if (str_starts_with($type, 'text/css')) {
  header('Cache-Control: public, max-age=300');
  header('Cloudflare-CDN-Cache-Control: public, max-age=3600');
} else if (str_starts_with($type, 'text/html')) {
  // Browsers always ask again, so a skin switch shows at once. Cloudflare may keep the
  // default (2008) skin for ML_EDGE_HTML_TTL seconds (15 minutes unless the pool sets it):
  // its Cache Rule bypasses the edge for visitors whose wowcensus_skin cookie picks another
  // skin and for Accept headers that may want Markdown. Raise the TTL only where
  // wowcensus-rollout purges re-rendered pages from Cloudflare, or changes show late.
  $skinCookie = $_COOKIE['wowcensus_skin'] ?? '';
  $defaultSkin = !isset(SKIN_NAMES[$skinCookie]) || $skinCookie === 'wow2008';
  $edgeTtl = max(0, (int) (getenv('ML_EDGE_HTML_TTL') ?: 900));
  header('Cache-Control: private, no-store');
  header('Cloudflare-CDN-Cache-Control: ' . ($defaultSkin && $edgeTtl > 0 ? "public, max-age=$edgeTtl" : 'no-store'));
} else {
  header('Cache-Control: public, max-age=60');
  header('Cloudflare-CDN-Cache-Control: public, max-age=300');
}
echo str_starts_with($type, 'text/html') ? with_skin(with_analytics($body)) : $body;
