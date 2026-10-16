<?php
// Front controller: every page is rendered from PostgreSQL on request.
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Router.php';

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
header('X-Content-Type-Options: nosniff');
try {
  $result = route_request($path);
} catch (Throwable $e) {
  http_response_code(500);
  header('Cache-Control: no-store');
  header('Content-Type: text/plain; charset=utf-8');
  echo "Page temporarily unavailable\n";
  error_log((string)$e);
  exit;
}
if ($result === null) {
  http_response_code(404);
  header('Cache-Control: no-store');
  header('Content-Type: text/html; charset=utf-8');
  echo with_analytics(not_found_html());
  exit;
}
[$type, $body] = $result;
header('Content-Type: ' . $type);
if (str_starts_with($type, 'text/css')) {
  header('Cache-Control: public, max-age=300');
  header('Cloudflare-CDN-Cache-Control: public, max-age=3600');
} else {
  header('Cache-Control: public, max-age=60');
  header('Cloudflare-CDN-Cache-Control: public, max-age=300');
}
echo str_starts_with($type, 'text/html') ? with_analytics($body) : $body;
