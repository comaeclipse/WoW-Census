<?php
// Render the published route set against the full local database and capture
// only the SQL result rows that those pages need on the compact origin.
declare(strict_types=1);

if ($argc < 2 || $argc > 4 || ($argc === 4 && $argv[3] !== '--queries-only')) {
  fwrite(STDERR, "Usage: php capture-page-data.php <capture-dir> [top-level-dir] [--queries-only]\n");
  exit(2);
}
$capture = $argv[1];
$topLevel = $argv[2] ?? '';
$queriesOnly = ($argv[3] ?? '') === '--queries-only';
if (!is_dir($capture) && !mkdir($capture, 0700, true)) throw new RuntimeException('Cannot create capture directory');
putenv('ML_CAPTURE_DIR=' . realpath($capture));
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/Router.php';

// The group's routes (src/Routes.php): files at the root for '', else those under
// <top-level-dir>/. Forever release and beta archives have separate directories.
$home = source_for_path($topLevel === '' ? '/' : '/' . $topLevel . '/');
$routeSources = $home ? [$home] : ($topLevel === 'data' ? ['forever', 'classic-beta'] : []);
$routes = array_values(array_filter(published_routes($routeSources), fn($path) => $topLevel === ''
  ? !str_contains(substr($path, 1), '/') : str_starts_with($path, '/' . $topLevel . '/')));
if ($topLevel === '') $routes[] = '/';
if (!$routes) throw new RuntimeException("No published routes under '$topLevel'");

$ok = 0;
$skipped = 0;
$errors = [];
foreach ($routes as $path) {
  $_SERVER['REQUEST_URI'] = $path;
  $_GET = [];
  try {
    if (route_request($path) === null) { $skipped++; fwrite(STDERR, "Skipped $path\n"); continue; }
    $ok++;
  } catch (Throwable $e) {
    $errors[] = $path . ': ' . $e->getMessage();
  }
  if (($ok + count($errors)) % 25 === 0) fwrite(STDERR, "Rendered $ok routes; errors " . count($errors) . "\n");
}
// Publish every page model of the edition this group is home to, even ones no
// route above asked for, so a snapshot always holds the full set.
$home = source_for_path($topLevel === '' ? '/' : '/' . $topLevel . '/');
if ($home) {
  try {
    // Only the 2008 skin (census banners, front page) asks for seen_activity(); the routes above render without a skin.
    seen_activity($home);
    if (!$queriesOnly) {
      inspect_summary($home); talent_page_model($home); gear_page_model($home);
      class_profiles_model($home); race_profiles_model($home); auctionhouse_page_model($home);
      // Guild roster pages are not in the static route set; publish every roster.
      guild_rosters($home);
    }
  } catch (Throwable $e) {
    $errors[] = "page models for $home: " . $e->getMessage();
  }
}
// The admin portal's collection status, published once with the root group.
if ($topLevel === '') {
  require_once __DIR__ . '/src/CollectionStats.php';
  try { collection_activity_model(); }
  catch (Throwable $e) { $errors[] = 'collection activity: ' . $e->getMessage(); }
}
echo "Rendered: $ok; skipped: $skipped; errors: " . count($errors) . "\n";
foreach ($errors as $error) fwrite(STDERR, $error . "\n");
if (!$errors) {
  if (!is_dir($capture . '/manifests')) mkdir($capture . '/manifests', 0700, true);
  $group = $topLevel === '' ? '_root' : $topLevel;
  file_put_contents($capture . '/manifests/' . $group . '.json', json_encode([
    'group' => $group, 'queriesOnly' => $queriesOnly, 'rendered' => $ok,
  ], JSON_THROW_ON_ERROR));
}
exit($errors ? 1 : 0);
