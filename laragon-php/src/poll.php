<?php
// /poll: the front page's community poll. GET returns the tally as JSON; POST (option=<key>) records a vote,
// one per visitor address, and returns the tally. The front page is cached, so its widget reads everything here.
declare(strict_types=1);

require_once __DIR__ . '/visitor-ip.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('Cloudflare-CDN-Cache-Control: no-store');

function poll_db(): PDO {
  // A small SQLite file beside the shoutbox's store, apart from the PostgreSQL census data.
  $shoutbox = getenv('SHOUTBOX_DB_PATH');
  $path = getenv('POLL_DB_PATH') ?: ($shoutbox ? dirname($shoutbox) . '/poll.sqlite' : sys_get_temp_dir() . '/marketlens-poll.sqlite');
  $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
  $db->exec('PRAGMA busy_timeout = 5000');
  $db->exec('CREATE TABLE IF NOT EXISTS poll_votes (
    poll_id TEXT NOT NULL,
    option TEXT NOT NULL,
    voter_hash TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    PRIMARY KEY (poll_id, voter_hash)
  )');
  return $db;
}

function poll_reply(int $status, array $body): void {
  http_response_code($status);
  echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$poll = require __DIR__ . '/poll-question.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
  header('Allow: GET, POST');
  poll_reply(405, ['error' => 'Method not allowed']);
}
// Hash the visitor address so the vote table does not retain raw IPs.
$voter = hash_hmac('sha256', shout_visitor_ip(), getenv('SHOUTBOX_HASH_KEY') ?: ML_CONFIG['db']['password']);

try {
  $db = poll_db();
  if ($method === 'POST') {
    // Votes come from the site's own pages only.
    $origin = parse_url((string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
    $host = parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    if (!$origin || !$host || strcasecmp($origin, $host) !== 0) poll_reply(403, ['error' => 'Votes must come from this site']);
    $option = (string)($_POST['option'] ?? '');
    if (!isset($poll['options'][$option])) poll_reply(400, ['error' => 'Pick one of the answers']);
    // A repeat vote from the same visitor is ignored; the first one stands.
    $db->prepare('INSERT OR IGNORE INTO poll_votes (poll_id, option, voter_hash, created_at) VALUES (?, ?, ?, ?)')
      ->execute([$poll['id'], $option, $voter, time()]);
  }
  $counts = $db->prepare('SELECT option, COUNT(*) FROM poll_votes WHERE poll_id = ? GROUP BY option');
  $counts->execute([$poll['id']]);
  $counts = $counts->fetchAll(PDO::FETCH_KEY_PAIR);
  $mine = $db->prepare('SELECT option FROM poll_votes WHERE poll_id = ? AND voter_hash = ?');
  $mine->execute([$poll['id'], $voter]);
} catch (Throwable $e) {
  error_log('poll: ' . $e);
  poll_reply(503, ['error' => 'The poll is unavailable right now']);
}
$options = [];
foreach ($poll['options'] as $key => $label) $options[] = ['key' => $key, 'label' => $label, 'votes' => (int)($counts[$key] ?? 0)];
poll_reply(200, ['id' => $poll['id'], 'question' => $poll['question'], 'options' => $options,
  'total' => array_sum(array_column($options, 'votes')), 'voted' => $mine->fetchColumn() ?: null]);
