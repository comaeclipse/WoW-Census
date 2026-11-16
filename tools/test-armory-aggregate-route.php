<?php
declare(strict_types=1);

require dirname(__DIR__) . '/laragon-php/src/bootstrap.php';
require dirname(__DIR__) . '/laragon-php/src/Router.php';

$path = $argv[1] ?? '';
if (!in_array($path, ['/forever/talents', '/forever/paladin/', '/forever/human/', '/forever/professions/',
  '/retail/talents', '/retail/paladin/', '/retail/human/', '/retail/gear'], true))
  throw new InvalidArgumentException('Expected one Armory-backed aggregate route');
$result = route_request($path);
if ($result === null || !str_starts_with($result[0], 'text/html') || strlen($result[1]) <= 1000)
  throw new RuntimeException("$path did not render a complete HTML page");
echo json_encode(['path' => $path, 'bytes' => strlen($result[1]),
  'peakMiB' => round(memory_get_peak_usage(true) / 1048576, 1)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
