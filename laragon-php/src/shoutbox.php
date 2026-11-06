<?php
declare(strict_types=1);

require_once __DIR__ . '/Wow2008.php';
require_once __DIR__ . '/shoutbox-db.php';
require_once __DIR__ . '/shoutbox-filter.php';
require_once __DIR__ . '/visitor-ip.php';

const SHOUT_COOLDOWN = 60;
const SHOUT_STRIKES = 3; // filtered shouts in a row that earn a timeout

/** Timeout length for a poster's next offense: 1, 3, 5, 10, then 15 minutes from then on. */
function shout_timeout_minutes(int $previous): int {
  return [1, 3, 5, 10, 15][min($previous, 4)];
}
session_start(['cookie_path' => '/shoutbox', 'cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'use_strict_mode' => true]);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header('Cloudflare-CDN-Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');

function shout_challenge(): string {
  $a = random_int(2, 9);
  $b = random_int(2, 9);
  $_SESSION['shout_challenge'] = ['answer' => $a + $b, 'issued' => time()];
  return "Human check: $a + $b = ?";
}

$form = ['name' => '', 'message' => ''];
$error = null;
$posted = isset($_GET['posted']);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$fresh = $method === 'POST';
if (!in_array($method, ['GET', 'POST'], true)) {
  header('Allow: GET, POST');
  http_response_code(405);
  exit;
}

try {
  $db = shout_db();
  if ($method === 'POST' && (string)($_POST['init'] ?? '') !== '1') {
    foreach ($form as $key => $_) $form[$key] = trim((string)($_POST[$key] ?? ''));
    $challenge = $_SESSION['shout_challenge'] ?? null;
    unset($_SESSION['shout_challenge']);
    $issued = (int)($challenge['issued'] ?? 0);
    if (!hash_equals((string)($_SESSION['shout_csrf'] ?? ''), (string)($_POST['csrf'] ?? '')) || !isset($_SESSION['shout_csrf'])) {
      $error = 'The form expired. Please reload and try again.';
    } elseif ((string)($_POST['website'] ?? '') !== '') {
      $error = 'Could not post that shout.';
    } elseif (!$challenge || time() - $issued > 3600 || time() - $issued < 2) {
      $error = 'The human check expired. Please try the new question.';
    } elseif (!hash_equals((string)$challenge['answer'], trim((string)($_POST['captcha'] ?? '')))) {
      $error = 'The math answer was incorrect. Try the new question.';
    } elseif ($form['name'] === '' || mb_strlen($form['name']) > 32 || preg_match('/[\x00-\x1f\x7f]/u', $form['name'])) {
      $error = 'Use a name of 1–32 characters.';
    } elseif ($form['message'] === '' || mb_strlen($form['message']) > 280 || preg_match('/[\x00-\x08\x0b-\x1f\x7f]/u', $form['message'])) {
      $error = 'Use a message of 1–280 characters.';
    } else {
      // Hash the visitor address so the public message table does not retain raw IPs.
      $poster = hash_hmac('sha256', shout_visitor_ip(), getenv('SHOUTBOX_HASH_KEY') ?: ML_CONFIG['db']['password']);
      $db->exec('BEGIN IMMEDIATE');
      try {
        $timeout = $db->prepare('SELECT until, timeouts FROM shoutbox_timeouts WHERE poster_hash = ?');
        $timeout->execute([$poster]);
        $timeout = $timeout->fetch(PDO::FETCH_ASSOC) ?: ['until' => 0, 'timeouts' => 0];
        $latest = $db->prepare('SELECT created_at FROM shoutbox_entries WHERE poster_hash = ? ORDER BY created_at DESC LIMIT 1');
        $latest->execute([$poster]);
        $last = $latest->fetchColumn();
        [$name, $nameHit] = shout_censor($form['name']);
        [$message, $messageHit] = shout_censor($form['message']);
        $censored = $nameHit || $messageHit;
        $banned = $db->prepare('SELECT 1 FROM shoutbox_bans WHERE poster_hash = ?');
        $banned->execute([$poster]);
        if ($banned->fetchColumn()) {
          $error = 'You are banned from the shoutbox.';
        } elseif ((int)$timeout['until'] > time()) {
          $error = 'You are timed out for ' . shout_duration((int)$timeout['until'] - time()) . ' more.';
        } elseif ($last !== false && time() - (int)$last < SHOUT_COOLDOWN) {
          $error = 'Please wait 60 seconds between shouts.';
        } else {
          if ($censored) {
            // This shout plus the poster's previous ones, all filtered, make a streak.
            $streak = $db->prepare('SELECT censored FROM shoutbox_entries WHERE poster_hash = ? ORDER BY created_at DESC LIMIT ?');
            $streak->execute([$poster, SHOUT_STRIKES - 1]);
            $flags = array_map('intval', $streak->fetchAll(PDO::FETCH_COLUMN));
            if (count($flags) === SHOUT_STRIKES - 1 && !in_array(0, $flags, true)) {
              $minutes = shout_timeout_minutes((int)$timeout['timeouts']);
              $db->prepare('INSERT INTO shoutbox_timeouts (poster_hash, until, timeouts) VALUES (?, ?, 1)
                ON CONFLICT (poster_hash) DO UPDATE SET until = excluded.until, timeouts = timeouts + 1')
                ->execute([$poster, time() + $minutes * 60]);
              $error = "Too many filtered words in a row. You are timed out for $minutes minutes.";
            }
          }
          if ($error === null) {
            $insert = $db->prepare('INSERT INTO shoutbox_entries (name, message, poster_hash, created_at, censored) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$name, $message, $poster, time(), (int)$censored]);
          }
        }
        $db->exec('COMMIT');
      } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
      }
      if ($error === null) {
        unset($_SESSION['shout_csrf']);
        header('Location: /shoutbox/?posted=' . bin2hex(random_bytes(4)), true, 303);
        exit;
      }
    }
  }
  $entries = $db->query('SELECT id, name, message, created_at FROM shoutbox_entries WHERE hidden_at IS NULL ORDER BY created_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
  // Filter again on display so shouts from before a term was added are covered too.
  foreach ($entries as &$entry) {
    $entry['name'] = shout_censor($entry['name'])[0];
    $entry['message'] = shout_censor($entry['message'])[0];
  }
  unset($entry);
  // The newest 50, listed oldest first so new shouts land at the bottom.
  $entries = array_reverse($entries);
} catch (Throwable $e) {
  error_log('shoutbox: ' . $e);
  http_response_code(503);
  $entries = [];
  $error = 'The shoutbox is temporarily unavailable. Please try again later.';
}

if ($error !== null && http_response_code() === 200) http_response_code(400);
// Only responses that carry the form issue a new token and question, so the
// shout list's auto-refresh does not invalidate a form someone is typing in.
$question = '';
if ($fresh) {
  $_SESSION['shout_csrf'] = bin2hex(random_bytes(24));
  $question = shout_challenge();
}
$head = '<title>Shoutbox – WoWCensus</title><meta name="robots" content="noindex,follow">';
$content = \Wow2008\render('pages/shoutbox.php', compact('entries', 'form', 'question', 'error', 'posted', 'fresh') + ['csrf' => $_SESSION['shout_csrf'] ?? '']);
echo with_analytics(\Wow2008\render('layout.php', ['head' => $head, 'content' => $content, 'wide' => true, 'source' => null, 'page' => 'shoutbox', 'route' => ['page' => 'shoutbox']]));
