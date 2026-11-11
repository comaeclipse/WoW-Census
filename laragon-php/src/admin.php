<?php
// Password-protected admin portal: the collection status page, read live from
// PostgreSQL. The password_hash() output comes from
// ADMIN_PASSWORD_HASH or the file ADMIN_PASSWORD_HASH_FILE names; without
// either the portal does not exist.
declare(strict_types=1);

require_once __DIR__ . '/CollectionStats.php';
require_once __DIR__ . '/CollectionWhoHeatmapPage.php';
require_once __DIR__ . '/AdminShoutbox.php';
require_once __DIR__ . '/AdminCache.php';
require_once __DIR__ . '/visitor-ip.php';

const ADMIN_IDLE = 43200;       // a session ends after 12 idle hours
const ADMIN_MAX_FAILURES = 8;   // failed logins per address per window
const ADMIN_FAILURE_WINDOW = 900;

// Never cached: X-Accel-Expires stops nginx's fastcgi_cache, which ignores Cache-Control.
header('Cache-Control: private, no-store');
header('Cloudflare-CDN-Cache-Control: no-store');
header('X-Accel-Expires: 0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

// PHP-FPM reads an env[] value starting with "$" as another variable's name,
// and bcrypt hashes start with "$2y$", so the pool names a file instead.
$hash = (string)getenv('ADMIN_PASSWORD_HASH');
if ($hash === '' && ($file = getenv('ADMIN_PASSWORD_HASH_FILE')) && is_readable($file)) $hash = trim((string)file_get_contents($file));
if ($hash === '' || (password_get_info($hash)['algo'] ?? null) === null) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  exit("Not found\n");
}
if ($path === '/admin') {
  header('Location: /admin/', true, 301);
  exit;
}

// Behind Cloudflare the visitor's scheme is in CF-Visitor, whatever reaches the origin.
$https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
  || str_contains((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"https"');
session_name('wowcensus_admin');
session_start(['cookie_path' => '/admin/', 'cookie_httponly' => true, 'cookie_secure' => $https,
  'cookie_samesite' => 'Strict', 'use_strict_mode' => true, 'gc_maxlifetime' => ADMIN_IDLE]);

/** Failed logins per visitor address, in a small shared file. */
function admin_failures(string $ip, bool $record): int {
  $file = sys_get_temp_dir() . '/wowcensus-admin-failures.json';
  $handle = fopen($file, 'c+');
  if (!$handle || !flock($handle, LOCK_EX)) return PHP_INT_MAX;  // fail closed
  try {
    $now = time();
    $all = json_decode((string)stream_get_contents($handle), true);
    $all = is_array($all) ? $all : [];
    foreach ($all as $key => $times) $all[$key] = array_values(array_filter((array)$times, fn($t) => $now - (int)$t < ADMIN_FAILURE_WINDOW));
    if ($record) $all[$ip][] = $now;
    $all = array_filter($all);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($all));
    return count($all[$ip] ?? []);
  } finally {
    flock($handle, LOCK_UN);
    fclose($handle);
  }
}

function admin_page(string $title, string $body): string {
  return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<meta name="robots" content="noindex,nofollow"><title>' . cs_h($title) . '</title><style>'
    . ':root{color-scheme:dark;font-family:system-ui,sans-serif;background:#101720;color:#edf3fa}*{box-sizing:border-box}'
    . 'body{min-height:100vh;margin:0;display:grid;place-items:center;padding:16px}'
    . 'form{width:min(340px,100%);background:#192632;border:1px solid #344759;border-radius:12px;padding:26px 24px}'
    . 'h1{font-size:1.3rem;margin:0 0 18px;letter-spacing:-.02em}label{display:block;font-size:.8rem;color:#a8b9ca;margin-bottom:6px}'
    . 'input{width:100%;font:inherit;padding:9px 11px;border-radius:7px;border:1px solid #344759;background:#101720;color:inherit}'
    . 'input:focus{outline:2px solid #456e96;outline-offset:1px}button{margin-top:16px;width:100%;font:inherit;font-weight:700;padding:9px;border:0;border-radius:7px;background:#456e96;color:white;cursor:pointer}'
    . '.error{color:#ff9a9e;font-size:.85rem;margin:0 0 14px}</style></head><body>' . $body . '</body></html>';
}

function admin_csrf(): string {
  if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
  return $_SESSION['csrf'];
}

function admin_csrf_ok(): bool {
  return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''));
}

header('Content-Type: text/html; charset=utf-8');
$authed = ($_SESSION['admin'] ?? false) === true && time() - (int)($_SESSION['seen'] ?? 0) < ADMIN_IDLE;
if (!$authed && isset($_SESSION['admin'])) {
  $_SESSION = [];
  session_regenerate_id(true);
}
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  if (!admin_csrf_ok()) {
    $error = 'Your session expired. Try again.';
  } elseif ($action === 'logout') {
    $_SESSION = [];
    session_regenerate_id(true);
    header('Location: /admin/', true, 303);
    exit;
  } elseif ($action === 'login') {
    $ip = shout_visitor_ip();
    if (admin_failures($ip, false) >= ADMIN_MAX_FAILURES) {
      http_response_code(429);
      $error = 'Too many attempts. Try again in a few minutes.';
    } elseif (password_verify((string)($_POST['password'] ?? ''), $hash)) {
      session_regenerate_id(true);
      $_SESSION = ['admin' => true, 'seen' => time(), 'csrf' => bin2hex(random_bytes(24))];
      header('Location: /admin/', true, 303);
      exit;
    } else {
      admin_failures($ip, true);
      usleep(random_int(400000, 900000));
      http_response_code(401);
      $error = 'Wrong password.';
    }
  }
}

if (!$authed) {
  echo admin_page('Admin sign in · WoWCensus',
    '<form method="post" action="/admin/"><h1>WoWCensus admin</h1>'
    . ($error ? '<p class="error" role="alert">' . cs_h($error) . '</p>' : '')
    . '<input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="' . cs_h(admin_csrf()) . '">'
    . '<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required autofocus>'
    . '<button type="submit">Sign in</button></form>');
  exit;
}

$_SESSION['seen'] = time();
$chrome = '<div class="chrome"><a class="back" href="/">← WoWCensus</a>'
  . '<form method="post" action="/admin/"><input type="hidden" name="action" value="logout">'
  . '<input type="hidden" name="csrf" value="' . cs_h(admin_csrf()) . '"><button type="submit">Sign out</button></form></div>';
$shoutLink = '<a href="/admin/?view=shoutbox">Shoutbox</a><a href="/admin/?view=cache">Cache stats</a>';

// Shoutbox moderation: POST, act, then redirect back (post/redirect/get) with a one-time message.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('/^(shout|poster)-/', (string)($_POST['action'] ?? ''))) {
  $return = (string)($_POST['return'] ?? '');
  if (!preg_match('~^/admin/\?view=shoutbox(&[^\s#]*)?$~', $return)) $return = '/admin/?view=shoutbox';
  try {
    $_SESSION['flash'] = admin_csrf_ok()
      ? admin_shout_action(shout_db(), (string)$_POST['action'], $_POST)
      : 'Your session expired. Try again.';
  } catch (Throwable $e) {
    error_log('admin shoutbox: ' . $e);
    $_SESSION['flash'] = 'That did not work: the shoutbox database is unavailable.';
  }
  header('Location: ' . $return, true, 303);
  exit;
}
// Cache stats: one clean-up action, then back to the page (post/redirect/get).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with((string)($_POST['action'] ?? ''), 'cache-')) {
  $_SESSION['flash'] = !admin_csrf_ok() ? 'Your session expired. Try again.'
    : ($_POST['action'] === 'cache-apcu-drop-old' ? ac_apcu_drop_old() : 'Unknown action.');
  header('Location: /admin/?view=cache', true, 303);
  exit;
}
if (($_GET['view'] ?? '') === 'cache') {
  $flash = (string)($_SESSION['flash'] ?? '');
  unset($_SESSION['flash']);
  session_write_close();  // the page-cache scan can take seconds; do not hold the session lock
  set_time_limit(120);
  $nav = $chrome . '<nav class="view-nav" aria-label="Admin view">'
    . '<a href="/admin/">Daily history</a><a href="/admin/?view=who-hours">/who time heatmap</a>'
    . '<a href="/admin/?view=shoutbox">Shoutbox</a><a href="/admin/?view=cache" aria-current="page">Cache stats</a></nav>';
  echo render_admin_cache($nav, admin_csrf(), $flash);
  exit;
}
if (($_GET['view'] ?? '') === 'shoutbox') {
  $flash = (string)($_SESSION['flash'] ?? '');
  unset($_SESSION['flash']);
  $nav = $chrome . '<nav class="view-nav" aria-label="Admin view">'
    . '<a href="/admin/">Daily history</a><a href="/admin/?view=who-hours">/who time heatmap</a>'
    . '<a href="/admin/?view=shoutbox" aria-current="page">Shoutbox</a><a href="/admin/?view=cache">Cache stats</a></nav>';
  try {
    echo render_admin_shoutbox(shout_db(), $nav, admin_csrf(), $flash);
  } catch (Throwable $e) {
    http_response_code(503);
    error_log('admin shoutbox: ' . $e);
    echo 'The shoutbox database is unavailable.';
  }
  exit;
}

// The activity model takes ~9 s to build and does not need to be live. The last
// build is kept in a file (not APCu, which clears everything when it fills up);
// every load is served from it, and one older than ADMIN_MODEL_FRESH is rebuilt
// after the page has been sent. ?refresh=1 rebuilds it now.
const ADMIN_MODEL_FRESH = 900;

function admin_model_file(): string {
  return sys_get_temp_dir() . '/wowcensus-admin-model.ser';
}

function admin_model_read(): ?array {
  $raw = @file_get_contents(admin_model_file());
  $model = $raw === false ? false : @unserialize($raw, ['allowed_classes' => false]);
  return is_array($model) && isset($model['generated_at'], $model['rows']) ? $model : null;
}

function admin_build_model(): array {
  $model = collection_activity_model();
  $file = admin_model_file();
  $tmp = $file . '.' . getmypid() . '.tmp';
  if (file_put_contents($tmp, serialize($model)) === false || !rename($tmp, $file)) {
    @unlink($tmp);
    error_log('admin: could not write ' . $file);
  }
  return $model;
}

$started = microtime(true);
$refresh = isset($_GET['refresh']);
$snapshot = $refresh ? null : admin_model_read();
$source = 'file';
if ($snapshot === null) {
  session_write_close();  // building the model can take seconds; do not hold the session lock
  $source = 'PostgreSQL';
  try {
    $snapshot = admin_build_model();
  } catch (Throwable $e) {
    http_response_code(500);
    error_log((string)$e);
    exit('Collection status could not be read from the database.');
  }
}
if ($refresh) {
  $q = $_GET;
  unset($q['refresh']);
  header('Location: /admin/' . ($q ? '?' . http_build_query($q) : ''), true, 303);
  exit;
}
$loadMs = (int)round((microtime(true) - $started) * 1000);
header('Server-Timing: model;desc="' . $source . '";dur=' . $loadMs);
$age = max(0, time() - (int)$snapshot['generated_at']);
$note = ($age < 60 ? 'Read from PostgreSQL just now' : 'Cached ' . intdiv($age, 60) . ' min ago')
  . ' (data loaded from ' . $source . ' in ' . number_format($loadMs) . ' ms)'
  . ($age >= ADMIN_MODEL_FRESH ? '; refreshing in the background, reload in ~10 s.' : '.');
$refreshQuery = $_GET;
$refreshQuery['refresh'] = '1';
$shoutLink .= '<a href="/admin/?' . cs_h(http_build_query($refreshQuery)) . '">Refresh data</a>';
$region = (string)($_GET['region'] ?? 'both');
if (($_GET['view'] ?? '') === 'who-hours') {
  echo render_collection_heatmap_page($snapshot, $region, (string)($_GET['time'] ?? 'local'), '/admin/', $chrome, $note, $shoutLink);
} else {
  $nav = $chrome . '<nav class="view-nav" aria-label="Activity view">'
    . '<a href="/admin/?region=' . rawurlencode($region) . '" aria-current="page">Daily history</a>'
    . '<a href="/admin/?view=who-hours&amp;region=' . rawurlencode($region) . '">/who time heatmap</a>' . $shoutLink . '</nav>';
  echo render_collection_stats($snapshot, $region, '/admin/', $nav, $note);
}

// A stale model: finish the response, then rebuild it once (the lock stops parallel rebuilds).
if ($age >= ADMIN_MODEL_FRESH && ($lock = fopen(admin_model_file() . '.lock', 'c')) && flock($lock, LOCK_EX | LOCK_NB)) {
  session_write_close();
  if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
  try {
    if ((int)(admin_model_read()['generated_at'] ?? 0) <= (int)$snapshot['generated_at']) admin_build_model();
  } catch (Throwable $e) {
    error_log('admin background refresh: ' . $e);
  }
  flock($lock, LOCK_UN);
}
