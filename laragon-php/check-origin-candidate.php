<?php
// Smoke-check a candidate SQLite against the PHP release currently on the droplet.
declare(strict_types=1);

$base = '/srv/wowcensus/current';
require $base . '/src/bootstrap.php';
require $base . '/src/Router.php';
require $base . '/src/Wow2008.php';

if (isset($argv[1])) {
  $part = $argv[1] === '_root' ? '' : $argv[1];
  $home = source_for_path($part === '' ? '/' : '/' . $part . '/');
  $sources = $home ? [$home] : ($part === 'data' ? ['forever', 'classic-beta'] : []);
  $routes = array_values(array_filter(published_routes($sources), fn($path) => $part === ''
    ? !str_contains(substr($path, 1), '/') : str_starts_with($path, '/' . $part . '/')));
  if ($part === '') $routes[] = '/';
  if (!$routes) throw new RuntimeException("No published routes under $part");
  foreach ($routes as $path) {
    $_SERVER['REQUEST_URI'] = $path;
    if (route_request($path) === null) throw new RuntimeException("Missing route: $path");
  }
  echo $argv[1], ' routes=', count($routes), PHP_EOL;
  exit;
}

foreach (['/', '/forever/', '/forever/realms', '/forever/guilds', '/classic/realms', '/retail/realms'] as $path) {
  $_SERVER['REQUEST_URI'] = $path;
  $route = route_request($path);
  if (!$route || strlen($route[1]) < 1000) throw new RuntimeException("Missing or short route: $path");
  $skin = Wow2008\render_page($route[1], $path);
  if (strlen($skin) < 1000) throw new RuntimeException("Short 2008 page: $path");
  echo $path, ' default=', strlen($route[1]), ' wow2008=', strlen($skin), PHP_EOL;
}
