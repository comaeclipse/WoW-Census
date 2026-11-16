<?php
declare(strict_types=1);

require dirname(__DIR__) . '/laragon-php/src/bootstrap.php';

function retail_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}

$armory = character_armory_records('retail');
retail_check(count($armory) > 0, 'Retail Armory normalization returned no records');
retail_check(count(array_filter($armory, fn($r) => count($r['talents'] ?? []) > 0)) > 0, 'Retail Armory talents were not normalized');
retail_check(count(array_filter($armory, fn($r) => count($r['gear'] ?? []) > 0)) > 0, 'Retail Armory equipment was not normalized');

$samples = load_talent_samples('retail');
retail_check(($samples['coverage']['armoryUsed'] ?? 0) > 0, 'Retail aggregate model has no Armory records');
$gear = gear_page_model('retail');
retail_check(($gear['sources']['armory'] ?? 0) > 0, 'Retail gear model has no Armory records');

echo json_encode(['armory' => count($armory), 'combined' => $samples['coverage']['combined'],
  'armoryUsed' => $samples['coverage']['armoryUsed'], 'gearRows' => count($gear['packed']['rows'])], JSON_UNESCAPED_SLASHES) . PHP_EOL;
