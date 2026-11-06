<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit(1);
$model = race_to_60_model('forever');
$racers = $model['racers'];
$apiLevels = 0;
$unknownTimes = 0;
foreach ($racers as $i => $r) {
  if ($r['levelSource'] === 'api') $apiLevels++;
  if ($r['reached'] === null) $unknownTimes++;
  if ($i && $racers[$i - 1]['level'] < $r['level']) throw new RuntimeException('Levels out of order');
  if ($r['reached'] === null && $r['levelSource'] !== 'api') throw new RuntimeException('Unknown time without API increase');
}
$html = race_to_60_html('forever');
if (!str_contains($html, 'API check')) throw new RuntimeException('Plain page omits API status');
echo json_encode(['racers' => count($racers), 'api_levels' => $apiLevels,
  'unknown_times' => $unknownTimes, 'top_level' => $model['top']['level'] ?? null]), "\n";
