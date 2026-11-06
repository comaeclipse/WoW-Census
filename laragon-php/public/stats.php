<?php
// LOCAL ONLY. Collection activity from local PostgreSQL. Never publish to S3.
declare(strict_types=1);

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
  http_response_code(403);
  exit;
}

require dirname(__DIR__) . '/src/config.php';
require dirname(__DIR__) . '/src/Db.php';
require dirname(__DIR__) . '/src/CollectionStats.php';
require_once dirname(__DIR__) . '/src/CollectionWhoHeatmapPage.php';

const STATS_TTL = 600;

function snapshot(): array {
  $cache = sys_get_temp_dir() . '/marketlens-collection-status-v11.json';
  $lock = fopen($cache . '.lock', 'c');
  if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock status cache');
  try {
    if (is_file($cache) && time() - filemtime($cache) < STATS_TTL) {
      $saved = json_decode((string)file_get_contents($cache), true);
      if (is_array($saved)) return [$saved, 'HIT'];
    }
    $saved = collection_activity_query();
    $saved['who_hours'] = who_heatmap_query();
    $tmp = $cache . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, json_encode($saved, JSON_THROW_ON_ERROR)) === false ||
        !rename($tmp, $cache)) throw new RuntimeException('Cannot write status cache');
    return [$saved, 'MISS'];
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try { [$snapshot, $cacheState] = snapshot(); }
catch (Throwable $e) {
  http_response_code(500);
  error_log((string)$e);
  exit('Collection status temporarily unavailable');
}
header('X-MarketLens-Stats-Cache: ' . $cacheState);

$region = (string)($_GET['region'] ?? 'both');
$view = (string)($_GET['view'] ?? 'history');
$chrome = '<a class="back" href="/">← MarketLens home</a>';
if ($view === 'who-hours') {
  echo render_collection_heatmap_page($snapshot, $region, (string)($_GET['time'] ?? 'local'), '/stats.php', $chrome, 'Refreshes every 10 minutes.');
} else {
  $nav = $chrome . '<nav class="view-nav" aria-label="Activity view">'
    . '<a href="/stats.php?region=' . rawurlencode($region) . '" aria-current="page">Daily history</a>'
    . '<a href="/stats.php?view=who-hours&amp;region=' . rawurlencode($region) . '">/who time heatmap</a></nav>';
  echo render_collection_stats($snapshot, $region, '/stats.php', $nav, 'Refreshes every 10 minutes.');
}
