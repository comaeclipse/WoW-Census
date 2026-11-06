<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit(1);
// Our own /who racers, including characters seen only once (the board shows those once the API
// verifies them). Names merged from the external tracker are checked separately.
$racers = array_values(array_filter(race_to_60_model('forever', 2 * RACE_TO_60_POOL, true)['racers'], static fn($r) => empty($r['external'])));
echo json_encode(array_map(static fn($r) => [
  'key' => $r['key'], 'name' => $r['name'], 'region' => strtolower($r['region']),
  'class' => class_name($r['cf']), 'race' => $r['race'],
  'faction' => $r['faction'] === 'A' ? 'ALLIANCE' : 'HORDE',
], $racers), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
