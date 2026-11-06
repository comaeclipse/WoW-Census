<?php
// Render the published routes of some editions into a working folder, for tools
// that read rendered pages (tools/mirror-profile-icons.mjs, tools/update-item-icons.mjs).
// Nothing here is published: the site itself renders every page on request.
//
//   php laragon-php/render-routes.php [--out=<dir>] [--sources=classic,sod]
//
// The folder is emptied first, so it holds only the editions asked for. The
// design and class-profile tests read a full run (no --sources).
// Default: Documents/MarketLensData/rendered-pages.

declare(strict_types=1);
ini_set('memory_limit', '2G');
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/Router.php';

$opts = getopt('', ['out:', 'sources:']);
$home = getenv('USERPROFILE') ?: getenv('HOME');
$out = rtrim(str_replace('\\', '/', $opts['out'] ?? "$home/Documents/MarketLensData/rendered-pages"), '/');
$sources = isset($opts['sources']) ? array_values(array_filter(explode(',', $opts['sources']))) : array_keys(EDITIONS);
foreach ($sources as $s) if (!isset(EDITIONS[$s])) { fwrite(STDERR, "Unknown edition key: $s\n"); exit(2); }

if (is_dir($out)) {
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
$started = microtime(true);
$written = 0;
// A run for every edition also writes the shared files (sitemap, census.css, ...).
foreach (published_routes($sources, !isset($opts['sources'])) as $path) {
  $result = route_request($path);
  if ($result === null) throw new RuntimeException("No route for $path");
  $file = $out . $path;
  if (!is_dir(dirname($file))) mkdir(dirname($file), 0777, true);
  file_put_contents($file, $result[1]);
  $written++;
}
printf("Rendered %d routes for %s in %.1fs -> %s\n", $written, implode(',', $sources), microtime(true) - $started, $out);
