<?php
// Local Laragon settings. Environment variables override each value.

declare(strict_types=1);

define('ML_CONFIG', [
  'db' => [
    'host' => getenv('ML_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('ML_DB_PORT') ?: '5433',
    'name' => getenv('ML_DB_NAME') ?: 'marketlens_local',
    'user' => getenv('ML_DB_USER') ?: 'skarz',
    'password' => getenv('ML_DB_PASSWORD') ?: '',
    'schema' => getenv('ML_DB_SCHEMA') ?: 'marketlens_import',
  ],
  // Copied in by deploy.ps1: style.css, census.css, race-template.html, the
  // talent catalogs and the item name/icon caches.
  'resources' => getenv('ML_RESOURCES') ?: dirname(__DIR__) . '/resources',
]);

// A generator resource: from the deployed resources/ folder, or straight from
// the repository when running from laragon-php/ (the static generator does).
function resource_path(string $relative): string {
  $deployed = ML_CONFIG['resources'] . '/' . $relative;
  if (is_file($deployed)) return $deployed;
  $repo = dirname(__DIR__, 2);
  $mapped = preg_replace(['#^src/#', '#^public/#', '#^(item-names|item-icons)/#'], ['site/src/', 'site/public/', 'tools/$1/'], $relative);
  if (is_file("$repo/$mapped")) return "$repo/$mapped";
  throw new RuntimeException("Missing resource: $relative");
}
