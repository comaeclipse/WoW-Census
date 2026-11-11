<?php
// Contact form at /contact. index.php hands the request here; nginx refuses
// every other *.php path. The server cannot reach SMTP (DigitalOcean blocks
// it), so mail goes out through Resend's HTTPS API.
//
// Settings come from the PHP-FPM pool (env[...] in pool.d/wowcensus.conf):
//   RESEND_API_KEY  key from resend.com
//   CONTACT_TO      where messages are delivered
//   CONTACT_FROM    sender on a domain verified in Resend
//                   (default: onboarding@resend.dev, which only delivers to
//                   the address the Resend account was opened with)
//
// Spam checks: a math question kept in the session, a hidden field people
// leave empty, and a minimum of three seconds between showing and sending.

declare(strict_types=1);

const CONTACT_MIN_SECONDS = 3;
const CONTACT_MAX_AGE = 3600;

function contact_new_challenge(): string {
  $a = random_int(2, 9);
  $b = random_int(2, 9);
  $_SESSION['contact'] = ['answer' => $a + $b, 'issued' => time()];
  return "What is $a + $b?";
}

function contact_send(string $name, string $email, string $message): bool {
  $key = getenv('RESEND_API_KEY') ?: '';
  $to = getenv('CONTACT_TO') ?: '';
  if ($key === '' || $to === '') {
    error_log('contact: RESEND_API_KEY or CONTACT_TO is not set');
    return false;
  }
  $payload = json_encode([
    'from' => getenv('CONTACT_FROM') ?: 'WoWCensus contact <onboarding@resend.dev>',
    'to' => [$to],
    'reply_to' => $email,
    'subject' => 'WoWCensus contact: ' . $name,
    'text' => "From: $name <$email>\n\n$message\n",
  ], JSON_THROW_ON_ERROR);
  $context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Authorization: Bearer $key\r\nContent-Type: application/json\r\n",
    'content' => $payload,
    'timeout' => 10,
    'ignore_errors' => true,
  ]]);
  $response = @file_get_contents('https://api.resend.com/emails', false, $context);
  $status = isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) ? (int)$m[1] : 0;
  if ($status < 200 || $status >= 300) {
    error_log("contact: Resend returned $status: " . substr((string)$response, 0, 300));
    return false;
  }
  return true;
}

function contact_page(array $form, string $question, ?string $error, bool $sent): string {
  $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $body = '<h1 class="iname">Contact</h1>';
  if ($sent) {
    $body .= '<p>Thanks, your message was sent.</p><p><a href="/">WoWCensus home</a></p>';
  } else {
    if ($error !== null) $body .= '<p class="contact-error" role="alert">' . $h($error) . '</p>';
    $body .= '<form class="contact" method="post" action="/contact">' .
      '<p><label>Name<br><input name="name" maxlength="100" required value="' . $h($form['name']) . '"></label></p>' .
      '<p><label>Email<br><input name="email" type="email" maxlength="254" required value="' . $h($form['email']) . '"></label></p>' .
      '<p><label>Message<br><textarea name="message" rows="8" maxlength="5000" required>' . $h($form['message']) . '</textarea></label></p>' .
      // Hidden from people; bots that fill every field give themselves away.
      '<p class="contact-hp" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></p>' .
      '<p><label>' . $h($question) . '<br><input name="captcha" inputmode="numeric" maxlength="3" required autocomplete="off"></label></p>' .
      '<p><button type="submit">Send</button></p></form>';
  }
  return localize_content('<!doctype html><html lang="en"><head><meta charset="utf-8">' .
    '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Contact – WoWCensus</title>' .
    '<meta name="robots" content="noindex"><link rel="stylesheet" href="/' . stylesheet()['name'] . '">' .
    '<style>.contact-hp{position:absolute;left:-9999px}.contact input,.contact textarea{width:100%;max-width:520px;box-sizing:border-box}' .
    '.contact-error{color:#c0392b;font-weight:bold}</style></head>' .
    // div.wrap, like the site's pages, so the 2008 skin can frame it.
    '<body><div class="wrap"><main>' . $body . '</main></div></body></html>' . "\n");
}

// The cookie is scoped to /contact so other pages never carry it.
session_start(['cookie_path' => '/contact', 'cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'use_strict_mode' => true]);
header('Cache-Control: private, no-store');
header('Cloudflare-CDN-Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');

$form = ['name' => '', 'email' => '', 'message' => ''];
$error = null;
$sent = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  foreach ($form as $field => $_) $form[$field] = trim((string)($_POST[$field] ?? ''));
  $challenge = $_SESSION['contact'] ?? null;
  unset($_SESSION['contact']);  // one guess per question
  $age = $challenge ? time() - $challenge['issued'] : PHP_INT_MAX;

  if ((string)($_POST['website'] ?? '') !== '' || $age < CONTACT_MIN_SECONDS) {
    $sent = true;  // looks like a bot: pretend it worked, send nothing
  } else if (!$challenge || $age > CONTACT_MAX_AGE) {
    $error = 'The form expired. Please answer the new question.';
  } else if (trim((string)($_POST['captcha'] ?? '')) !== (string)$challenge['answer']) {
    $error = 'That answer was wrong. Please try the new question.';
  } else if ($form['name'] === '' || mb_strlen($form['name']) > 100 || preg_match('/[\r\n]/', $form['name'])) {
    $error = 'Please enter your name.';
  } else if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 254) {
    $error = 'Please enter a valid email address.';
  } else if ($form['message'] === '' || mb_strlen($form['message']) > 5000) {
    $error = 'Please write a message (up to 5,000 characters).';
  } else if (contact_send($form['name'], $form['email'], $form['message'])) {
    $sent = true;
  } else {
    $error = 'Your message could not be sent right now. Please try again later.';
  }
}

if (!$sent) http_response_code($error === null ? 200 : 400);
echo with_skin(with_analytics(contact_page($form, $sent ? '' : contact_new_challenge(), $error, $sent)));
