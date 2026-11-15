<?php
declare(strict_types=1);

require dirname(__DIR__) . '/laragon-php/src/bootstrap.php';
require dirname(__DIR__) . '/laragon-php/src/Router.php';

function check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}

$samples = load_talent_samples('forever');
check(($samples['coverage']['armoryUsed'] ?? 0) > 0, 'combined talent model has no Armory records');
check(($samples['coverage']['inspectUsed'] ?? 0) > 0, 'combined talent model has no inspection records');
check(count($samples['records']) === ($samples['coverage']['combined'] ?? -1), 'combined talent coverage mismatch');
check(($samples['coverage']['combined'] ?? 0) < ($samples['coverage']['inspected'] ?? 0) + ($samples['coverage']['armory'] ?? 0),
  'Forever inspect and Armory records were not deduplicated');

$paladin = class_profiles_model('forever')['PALADIN'];
check(($paladin['sources']['armory'] ?? 0) > 0, 'Paladin page has no Armory builds');
$human = race_profiles_model('forever')['Human'];
check(($human['sources']['armory'] ?? 0) > 0, 'Human page has no Armory builds');

$professions = armory_profession_census();
check(($professions['profiles'] ?? 0) > 0, 'profession census has no profiles');
check(count($professions['primaries'] ?? []) >= 8, 'profession census is missing primary trades');

echo json_encode([
  'combined' => $samples['coverage']['combined'],
  'inspectUsed' => $samples['coverage']['inspectUsed'],
  'armoryUsed' => $samples['coverage']['armoryUsed'],
  'paladinArmory' => $paladin['sources']['armory'],
  'humanArmory' => $human['sources']['armory'],
  'professionProfiles' => $professions['profiles'],
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
