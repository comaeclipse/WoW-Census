<?php
declare(strict_types=1);

// Every edition with stored Armory profiles feeds them, with the in-game inspects, into the talent,
// gear, class and race pages; Forever's Armory records carry their equipment.
require dirname(__DIR__) . '/laragon-php/src/bootstrap.php';

function all_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}

$out = [];
foreach (['forever', 'retail', 'mop-classic', 'classic-progression', 'classic', 'sod', 'hardcore'] as $source) {
  $armory = $source === 'forever' ? forever_armory_talent_records() : character_armory_records($source);
  $samples = load_talent_samples($source);
  $out[$source] = ['armory' => count($armory), 'armoryUsed' => $samples['coverage']['armoryUsed'] ?? 0,
    'inspectUsed' => $samples['coverage']['inspectUsed'] ?? 0];
  if (!$armory) continue;
  $talented = array_filter($armory, fn($r) => count($r['talents'] ?? []) > 0);
  all_check(count($talented) > 0, "$source: Armory talents were not normalized");
  all_check(count(array_filter($armory, fn($r) => count($r['gear'] ?? []) > 0)) > 0, "$source: Armory equipment was not normalized");
  $known = array_filter($talented, fn($r) => $r['classification'] !== 'Unknown build');
  all_check(count($known) >= 0.9 * count($talented), "$source: most Armory builds should classify");
  all_check(($samples['coverage']['armoryUsed'] ?? 0) > 0, "$source: aggregate model has no Armory records");
  $gear = gear_page_model($source);
  all_check(($gear['sources']['armory'] ?? 0) > 0, "$source: gear model has no Armory records");
  all_check(($gear['sources']['inspect'] ?? 0) > 0 || !load_inspects($source)['records'], "$source: gear model dropped the inspects");
}

echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
