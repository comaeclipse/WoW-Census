<?php
// The shoutbox's writable SQLite store, shared by the public page and the admin moderation view.
declare(strict_types=1);

function shout_db(): PDO {
  // A small SQLite file of its own, apart from the PostgreSQL census data.
  $path = getenv('SHOUTBOX_DB_PATH') ?: sys_get_temp_dir() . '/marketlens-shoutbox.sqlite';
  $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
  $db->exec('PRAGMA busy_timeout = 5000');
  $db->exec('CREATE TABLE IF NOT EXISTS shoutbox_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    message TEXT NOT NULL,
    poster_hash TEXT NOT NULL,
    created_at INTEGER NOT NULL
  )');
  $db->exec('CREATE INDEX IF NOT EXISTS shoutbox_entries_poster ON shoutbox_entries (poster_hash, created_at DESC)');
  $columns = $db->query('PRAGMA table_info(shoutbox_entries)')->fetchAll(PDO::FETCH_COLUMN, 1);
  // censored: the filter matched when posted. hidden_at: a moderator removed it from the public log.
  foreach (['censored' => 'INTEGER NOT NULL DEFAULT 0', 'hidden_at' => 'INTEGER'] as $column => $type) {
    if (in_array($column, $columns, true)) continue;
    try {
      $db->exec("ALTER TABLE shoutbox_entries ADD COLUMN $column $type");
    } catch (PDOException $e) {
      // Another request added it first.
    }
  }
  $db->exec('CREATE TABLE IF NOT EXISTS shoutbox_timeouts (
    poster_hash TEXT PRIMARY KEY,
    until INTEGER NOT NULL,
    timeouts INTEGER NOT NULL
  )');
  $db->exec('CREATE TABLE IF NOT EXISTS shoutbox_bans (
    poster_hash TEXT PRIMARY KEY,
    reason TEXT NOT NULL DEFAULT \'\',
    created_at INTEGER NOT NULL
  )');
  return $db;
}

/** "45 minutes", "3 hours", "2 days": the larger unit once it is a whole one or more. */
function shout_duration(int $seconds): string {
  $minutes = max(1, (int)ceil($seconds / 60));
  if ($minutes < 120) return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
  $hours = (int)ceil($minutes / 60);
  if ($hours < 48) return "$hours hours";
  return (int)ceil($hours / 24) . ' days';
}
