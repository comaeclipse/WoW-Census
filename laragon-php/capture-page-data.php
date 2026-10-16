<?php
// Render the published route set against the full local database and capture
// only the SQL result rows that those pages need on the compact origin.
declare(strict_types=1);

if ($argc < 3 || $argc > 4) {
  fwrite(STDERR, "Usage: php capture-page-data.php <static-dir> <capture-dir> [top-level-dir]\n");
  exit(2);
}
$static = realpath($argv[1]);
if ($static === false) throw new RuntimeException('Static directory not found');
$capture = $argv[2];
$topLevel = $argv[3] ?? '';
if (!is_dir($capture) && !mkdir($capture, 0700, true)) throw new RuntimeException('Cannot create capture directory');
putenv('ML_CAPTURE_DIR=' . realpath($capture));
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/Router.php';

$ok = 0;
$skipped = 0;
$errors = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($static, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
  if (!$file->isFile()) continue;
  $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($static) + 1));
  if (($topLevel === '' && str_contains($relative, '/')) || ($topLevel !== '' && !str_starts_with($relative, $topLevel . '/'))) continue;
  if (!preg_match('/\.(?:html|md|json|css|xml|txt)$/', $relative)) continue;
  $path = '/' . $relative;
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
echo "Rendered: $ok; skipped: $skipped; errors: " . count($errors) . "\n";
foreach ($errors as $error) fwrite(STDERR, $error . "\n");
exit($errors ? 1 : 0);
