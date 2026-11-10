<?php
// Blog comments: stored beside the shoutbox in its writable SQLite file, with a
// PHP-drawn image captcha (or a math question where GD is missing).
declare(strict_types=1);

require_once __DIR__ . '/shoutbox-db.php';
require_once __DIR__ . '/shoutbox-filter.php';
require_once __DIR__ . '/visitor-ip.php';

const BLOG_COMMENT_COOLDOWN = 60;
const BLOG_COMMENT_MAX = 1000;
const BLOG_CAPTCHA_CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I/L

function blog_comment_db(): PDO {
  $db = shout_db();
  $db->exec('CREATE TABLE IF NOT EXISTS blog_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    message TEXT NOT NULL,
    poster_hash TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    hidden_at INTEGER
  )');
  $db->exec('CREATE INDEX IF NOT EXISTS blog_comments_post ON blog_comments (post_id, created_at)');
  $db->exec('CREATE INDEX IF NOT EXISTS blog_comments_poster ON blog_comments (poster_hash, created_at DESC)');
  return $db;
}

function blog_session(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
  session_start(['cookie_path' => '/blog.php', 'cookie_httponly' => true, 'cookie_secure' => $https,
    'cookie_samesite' => 'Lax', 'use_strict_mode' => true]);
  // Re-send the cookie on every response: the origin's nginx page cache skips
  // responses that set a cookie, so the comment list and captcha are never stale.
  if (($_COOKIE[session_name()] ?? '') !== session_id()) return; // session_start() just sent it
  $params = session_get_cookie_params();
  setcookie(session_name(), session_id(), ['path' => $params['path'], 'secure' => $params['secure'],
    'httponly' => true, 'samesite' => 'Lax']);
}

function blog_captcha_has_image(): bool {
  return function_exists('imagecreatetruecolor') && function_exists('imagepng');
}

/** Issues a new challenge; returns the math question when there is no image captcha. */
function blog_captcha_issue(): ?string {
  if (blog_captcha_has_image()) {
    $code = '';
    for ($i = 0; $i < 5; $i++) $code .= BLOG_CAPTCHA_CHARS[random_int(0, strlen(BLOG_CAPTCHA_CHARS) - 1)];
    $_SESSION['blog_captcha'] = ['answer' => $code, 'issued' => time()];
    return null;
  }
  $a = random_int(2, 9);
  $b = random_int(2, 9);
  $_SESSION['blog_captcha'] = ['answer' => (string)($a + $b), 'issued' => time()];
  return "Human check: $a + $b = ?";
}

/** Streams the current challenge as a PNG. */
function blog_captcha_image(): void {
  $code = (string)($_SESSION['blog_captcha']['answer'] ?? '');
  header('Cache-Control: private, no-store');
  header('Cloudflare-CDN-Cache-Control: no-store');
  if ($code === '' || !blog_captcha_has_image()) {
    http_response_code(404);
    return;
  }
  // Draw small with the built-in font, then scale up so no TTF file is needed.
  $w = 90; $h = 26;
  $small = imagecreatetruecolor($w, $h);
  imagefill($small, 0, 0, imagecolorallocate($small, 235, 226, 204));
  for ($i = 0; $i < 6; $i++) {
    $c = imagecolorallocate($small, random_int(120, 200), random_int(110, 180), random_int(80, 150));
    imageline($small, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
  }
  for ($i = 0, $n = strlen($code); $i < $n; $i++) {
    $c = imagecolorallocate($small, random_int(20, 90), random_int(10, 60), random_int(0, 40));
    imagestring($small, 5, 8 + $i * 16 + random_int(-2, 2), random_int(2, 9), $code[$i], $c);
  }
  for ($i = 0; $i < 80; $i++) {
    imagesetpixel($small, random_int(0, $w - 1), random_int(0, $h - 1),
      imagecolorallocate($small, random_int(60, 160), random_int(50, 140), random_int(30, 110)));
  }
  $big = imagecreatetruecolor($w * 2, $h * 2);
  imagecopyresampled($big, $small, 0, 0, 0, 0, $w * 2, $h * 2, $w, $h);
  header('Content-Type: image/png');
  imagepng($big);
}

/**
 * Validates and stores a comment. Returns an error message, or null when it was saved.
 * @param array{name:string,message:string} $form
 */
function blog_comment_post(PDO $db, int $postId, array $form): ?string {
  $challenge = $_SESSION['blog_captcha'] ?? null;
  unset($_SESSION['blog_captcha']);
  $issued = (int)($challenge['issued'] ?? 0);
  if (!isset($_SESSION['blog_csrf']) || !hash_equals((string)$_SESSION['blog_csrf'], (string)($_POST['csrf'] ?? ''))) {
    return 'The form expired. Please try again.';
  }
  if ((string)($_POST['website'] ?? '') !== '') return 'Could not post that comment.';
  if (!$challenge || time() - $issued > 3600 || time() - $issued < 2) {
    return 'The human check expired. Please try the new one.';
  }
  if (!hash_equals(strtoupper((string)$challenge['answer']), strtoupper(trim((string)($_POST['captcha'] ?? ''))))) {
    return 'The human check answer was incorrect. Please try the new one.';
  }
  if ($form['name'] === '' || mb_strlen($form['name']) > 32 || preg_match('/[\x00-\x1f\x7f]/u', $form['name'])) {
    return 'Use a name of 1–32 characters.';
  }
  if ($form['message'] === '' || mb_strlen($form['message']) > BLOG_COMMENT_MAX || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $form['message'])) {
    return 'Use a comment of 1–' . BLOG_COMMENT_MAX . ' characters.';
  }
  // Hash the visitor address so the table does not retain raw IPs.
  $poster = hash_hmac('sha256', shout_visitor_ip(), getenv('SHOUTBOX_HASH_KEY') ?: ML_CONFIG['db']['password']);
  $db->exec('BEGIN IMMEDIATE');
  try {
    // Shoutbox bans apply to comments too.
    $banned = $db->prepare('SELECT 1 FROM shoutbox_bans WHERE poster_hash = ?');
    $banned->execute([$poster]);
    $latest = $db->prepare('SELECT created_at FROM blog_comments WHERE poster_hash = ? ORDER BY created_at DESC LIMIT 1');
    $latest->execute([$poster]);
    $last = $latest->fetchColumn();
    if ($banned->fetchColumn()) {
      $error = 'You are banned from commenting.';
    } elseif ($last !== false && time() - (int)$last < BLOG_COMMENT_COOLDOWN) {
      $error = 'Please wait 60 seconds between comments.';
    } else {
      $error = null;
      $db->prepare('INSERT INTO blog_comments (post_id, name, message, poster_hash, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$postId, shout_censor($form['name'])[0], shout_censor($form['message'])[0], $poster, time()]);
    }
    $db->exec('COMMIT');
  } catch (Throwable $e) {
    $db->exec('ROLLBACK');
    throw $e;
  }
  return $error;
}

/** @return list<array{id:int,name:string,message:string,created_at:int}> */
function blog_comments(PDO $db, int $postId): array {
  $q = $db->prepare('SELECT id, name, message, created_at FROM blog_comments WHERE post_id = ? AND hidden_at IS NULL ORDER BY created_at, id LIMIT 500');
  $q->execute([$postId]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
  // Filter again on display so comments from before a term was added are covered too.
  foreach ($rows as &$row) {
    $row['name'] = shout_censor($row['name'])[0];
    $row['message'] = shout_censor($row['message'])[0];
  }
  return $rows;
}
