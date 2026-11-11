<?php
// Discord interactions endpoint for /submit <file>: players send a WoW Census or
// MarketLens SavedVariables file (or a .zip of them; each .lua inside is
// unzipped, hashed and stored on its own), which is stored as
//   <original name>-<discord username>-<UTC timestamp>.lua
// in DISCORD_SUBMIT_DIR with its MD5 recorded in submissions.jsonl.
//
// Pool settings (pool.d/wowcensus-live.conf):
//   env[DISCORD_PUBLIC_KEY] = <application public key, hex>
//   env[DISCORD_SUBMIT_DIR] = /srv/wowcensus/discord-submissions
//   env[DISCORD_SUBMIT_EMAIL_TO] = optional; else CONTACT_TO gets each new file, zipped
// Stored files are listed (download, delete) on /upload.
// The file is never executed or parsed here; it only has to look like a save.
declare(strict_types=1);

const DS_MAX_BYTES = 25 * 1024 * 1024;           // the attachment as sent
const DS_MAX_UNZIPPED = 100 * 1024 * 1024;       // one .lua inside a zip
const DS_MAX_UNZIPPED_TOTAL = 200 * 1024 * 1024; // everything taken from one zip
const DS_ZIP_MAX_ENTRIES = 1000;                 // entries looked at in one zip
const DS_PER_HOUR = 10;
const DS_VARIABLES = ['WoWCensusDB', 'MarketLensDB'];

header('Cache-Control: private, no-store');
header('Cloudflare-CDN-Cache-Control: no-store');
header('X-Accel-Expires: 0');

function ds_json(array $data, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode($data, JSON_UNESCAPED_SLASHES);
}
function ds_store(): string { return rtrim((string)(getenv('DISCORD_SUBMIT_DIR') ?: dirname(__DIR__, 2) . '/discord-submissions'), '/\\'); }

// The pieces of a stored name. Discord usernames are already [a-z0-9._], 2-32 characters.
function ds_clean(string $value, int $max): string {
  $value = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $value), '._-');
  return substr($value, 0, $max);
}
function ds_target_name(string $original, string $username, int $time): string {
  $stem = ds_clean(preg_replace('/\.lua\z/i', '', $original) ?? '', 80) ?: 'save';
  $user = ds_clean($username, 32) ?: 'unknown';
  return $stem . '-' . $user . '-' . gmdate('Ymd-His', $time) . '.lua';
}

// One submissions.jsonl line per stored file.
function ds_index(string $dir): array {
  $file = $dir . '/submissions.jsonl';
  if (!is_file($file)) return [];
  $rows = [];
  foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $row = json_decode($line, true);
    if (is_array($row)) $rows[] = $row;
  }
  return $rows;
}

function ds_looks_like_save(string $body): bool {
  if ($body === '' || str_contains($body, "\0")) return false;
  $head = substr($body, 0, 65536);
  foreach (DS_VARIABLES as $name) if (preg_match('/^\s*' . $name . '\s*=\s*\{/m', $head)) return true;
  return false;
}

function ds_download(string $url): ?string {
  $host = strtolower((string)parse_url($url, PHP_URL_HOST));
  if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array($host, ['cdn.discordapp.com', 'media.discordapp.net'], true)) return null;
  $ch = curl_init($url);
  $body = '';
  curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body): int {
      if (strlen($body) + strlen($chunk) > DS_MAX_BYTES) return 0; // aborts the transfer
      $body .= $chunk;
      return strlen($chunk);
    },
  ]);
  $ok = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
  curl_close($ch);
  return $ok ? $body : null;
}

// Replaces the deferred "thinking..." reply. Interaction tokens are valid for 15 minutes.
function ds_reply(string $appId, string $token, string $message): void {
  $ch = curl_init('https://discord.com/api/v10/webhooks/' . rawurlencode($appId) . '/' . rawurlencode($token) . '/messages/@original');
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'PATCH',
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: DiscordBot (https://wowcensus.io, 1.0)'],
    CURLOPT_POSTFIELDS => json_encode(['content' => $message, 'allowed_mentions' => ['parse' => []]]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
  ]);
  if (curl_exec($ch) === false || curl_getinfo($ch, CURLINFO_RESPONSE_CODE) >= 300) error_log('discord: reply failed: ' . curl_error($ch));
  curl_close($ch);
}

// Reads a file once: its MD5, or null when it is not a save (empty, binary, or no
// WoWCensusDB/MarketLensDB line in the first 64 KB).
function ds_scan(string $path): ?string {
  $in = fopen($path, 'rb');
  if (!$in) return null;
  $hash = hash_init('md5');
  $head = (string)fread($in, 65536);
  $ok = ds_looks_like_save($head);
  hash_update($hash, $head);
  while ($ok && !feof($in)) {
    $chunk = (string)fread($in, 1 << 20);
    if (str_contains($chunk, "\0")) $ok = false;
    hash_update($hash, $chunk);
  }
  fclose($in);
  return $ok ? hash_final($hash) : null;
}

// Copies at most $limit bytes from a stream into $path; false when it is longer.
function ds_copy_capped($in, string $path, int $limit): bool {
  $out = fopen($path, 'wb');
  $bytes = 0;
  while (!feof($in)) {
    $chunk = (string)fread($in, 1 << 20);
    $bytes += strlen($chunk);
    if ($bytes > $limit) { fclose($out); return false; }
    fwrite($out, $chunk);
  }
  fclose($out);
  return true;
}

// The .lua files inside a zip, extracted to temporary files next to the store:
// [[entry name, temp path], ...] plus one note per entry that was skipped.
// Entry sizes in the zip header can lie, so the copy itself enforces the limits.
function ds_unzip(string $zipPath, string $dir, array &$notes): array {
  if (!class_exists('ZipArchive')) { $notes[] = 'Zip files cannot be read right now; send the .lua file itself.'; return []; }
  $zip = new ZipArchive();
  if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) { $notes[] = 'That zip file could not be opened.'; return []; }
  $files = [];
  $total = 0;
  for ($i = 0; $i < min($zip->numFiles, DS_ZIP_MAX_ENTRIES); $i++) {
    $stat = $zip->statIndex($i);
    $name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
    $base = basename($name);
    if (str_ends_with($name, '/') || str_contains($name, '__MACOSX/') || str_starts_with($base, '.') || !preg_match('/\.lua\z/i', $base)) continue;
    if (count($files) >= DS_PER_HOUR) { $notes[] = "Skipped `$base`: too many files in one zip."; continue; }
    if (($stat['encryption_method'] ?? 0) !== 0) { $notes[] = "Skipped `$base`: password protected."; continue; }
    $in = $zip->getStreamIndex($i);
    if (!$in) { $notes[] = "Skipped `$base`: could not be unzipped."; continue; }
    $tmp = tempnam($dir, '.incoming-');
    $ok = ds_copy_capped($in, $tmp, min(DS_MAX_UNZIPPED, DS_MAX_UNZIPPED_TOTAL - $total));
    fclose($in);
    if (!$ok) { unlink($tmp); $notes[] = "Skipped `$base`: over " . (DS_MAX_UNZIPPED >> 20) . ' MB unzipped.'; continue; }
    $total += filesize($tmp);
    $files[] = [$base, $tmp];
  }
  $zip->close();
  return $files;
}

// Moves one checked temp file into the store under its final name and indexes it.
function ds_keep(string $dir, string $tmp, string $md5, string $name, array $row, array &$kept): string {
  $lock = fopen($dir . '/.lock', 'c');
  flock($lock, LOCK_EX);
  try {
    foreach (ds_index($dir) as $old) if (($old['md5'] ?? '') === $md5) return "Already have `$name` (MD5 `$md5`).";
    $now = time();
    $target = ds_target_name($name, $row['username'], $now);
    for ($n = 2; file_exists("$dir/$target"); $n++) $target = substr(ds_target_name($name, $row['username'], $now), 0, -4) . "-$n.lua";
    $bytes = filesize($tmp);
    if (!rename($tmp, "$dir/$target")) return "Could not save `$name`. Try again later.";
    chmod("$dir/$target", 0640);
    $row = ['time' => $now, 'file' => $target, 'md5' => $md5, 'bytes' => $bytes, 'original' => $name] + $row;
    file_put_contents($dir . '/submissions.jsonl', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    $kept[] = $row;
    return "Received `$name` (" . number_format($bytes / 1024, 1) . " KB, MD5 `$md5`).";
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

// Emails the files one /submit stored, zipped together, through Resend. Settings
// are the contact form's (RESEND_API_KEY, CONTACT_FROM), sent to
// DISCORD_SUBMIT_EMAIL_TO or else CONTACT_TO. A failure is only logged: the
// files are already stored and listed on /upload.
function ds_email_payload(string $dir, array $kept, string $from, string $to): ?array {
  if (!$kept) return null;
  $first = $kept[0];
  $lines = [];
  foreach ($kept as $row) {
    $lines[] = $row['file'] . "\n  MD5 " . $row['md5'] . ', ' . number_format($row['bytes'] / 1024, 1) . ' KB, sent as ' . $row['original']
      . (isset($row['archive']) ? ' in ' . $row['archive'] : '');
  }
  $who = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$first['username']) ?: 'unknown';
  $text = "From Discord user $who (id " . $first['user_id'] . ')' . ($first['guild_id'] !== '' ? ' in server ' . $first['guild_id'] : ' by DM') . ":\n\n"
    . implode("\n\n", $lines) . "\n\nAlso on https://wowcensus.io/upload\n";
  $payload = [
    'from' => $from, 'to' => [$to],
    'subject' => 'WoWCensus submit: ' . $first['original'] . (count($kept) > 1 ? ' +' . (count($kept) - 1) . ' more' : '') . " from $who",
    'text' => $text,
  ];
  // Saves are repetitive text and zip to about a tenth; leave the attachment off
  // when even the zip would push the email past Resend's 40 MB (base64 adds a third).
  if (class_exists('ZipArchive')) {
    $zipPath = tempnam($dir, '.email-');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::OVERWRITE) === true) {
      foreach ($kept as $row) $zip->addFile($dir . '/' . $row['file'], $row['file']);
      $zip->close();
      if (filesize($zipPath) <= 28 * 1024 * 1024) {
        $payload['attachments'] = [['filename' => substr($first['file'], 0, -4) . '.zip', 'content' => base64_encode((string)file_get_contents($zipPath))]];
      } else $payload['text'] .= "\n(Too large to attach; download it from /upload.)\n";
    }
    @unlink($zipPath);
  } else $payload['text'] .= "\n(Not attached: PHP's zip extension is missing.)\n";
  return $payload;
}

function ds_email(string $dir, array $kept): void {
  $key = getenv('RESEND_API_KEY') ?: '';
  $to = getenv('DISCORD_SUBMIT_EMAIL_TO') ?: getenv('CONTACT_TO') ?: '';
  if ($key === '' || $to === '' || !$kept) return;
  $payload = ds_email_payload($dir, $kept, getenv('CONTACT_FROM') ?: 'WoWCensus <onboarding@resend.dev>', $to);
  $ch = curl_init('https://api.resend.com/emails');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $key", 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
  ]);
  $response = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  if ($status < 200 || $status >= 300) error_log("discord: Resend returned $status: " . substr((string)$response, 0, 300) . ' ' . curl_error($ch));
  curl_close($ch);
}

// Stores the attachment (a .lua, or the .lua files in a .zip) and returns the message for the player.
// $kept receives the index row of each file stored.
function ds_submit(array $interaction, ?callable $download = null, array &$kept = []): string {
  $download ??= 'ds_download';
  $user = $interaction['member']['user'] ?? $interaction['user'] ?? [];
  $userId = (string)($user['id'] ?? '');
  $username = (string)($user['username'] ?? '');
  $optionId = '';
  foreach ($interaction['data']['options'] ?? [] as $option) if (($option['name'] ?? '') === 'file') $optionId = (string)($option['value'] ?? '');
  $attachment = $interaction['data']['resolved']['attachments'][$optionId] ?? null;
  if (!is_array($attachment)) return 'Attach your save file to /submit.';
  $original = (string)($attachment['filename'] ?? '');
  $isZip = (bool)preg_match('/\.zip\z/i', $original);
  if (!$isZip && !preg_match('/\.lua\z/i', $original)) return 'Send a .lua file or a .zip of them. Your save is in WTF\\Account\\<ACCOUNT>\\SavedVariables\\WoWCensus.lua.';
  if ((int)($attachment['size'] ?? 0) > DS_MAX_BYTES) return 'That file is over 25 MB.';

  $dir = ds_store();
  if (!is_dir($dir) && !mkdir($dir, 0770, true)) { error_log("discord: cannot create $dir"); return 'Storage is unavailable right now. Try again later.'; }
  $allowance = DS_PER_HOUR - count(array_filter(ds_index($dir), fn($r) => ($r['user_id'] ?? '') === $userId && ($r['time'] ?? 0) > time() - 3600));
  if ($allowance <= 0) return 'You have sent ' . DS_PER_HOUR . ' files in the last hour. Try again later.';
  $body = $download((string)($attachment['url'] ?? ''));
  if ($body === null) return 'Could not download the file from Discord. Try again.';
  $incoming = tempnam($dir, '.incoming-');
  file_put_contents($incoming, $body);
  unset($body);

  $notes = [];
  $files = $isZip ? ds_unzip($incoming, $dir, $notes) : [[$original, $incoming]];
  if ($isZip && !$files && !$notes) $notes[] = 'No .lua files in that zip.';
  $row = ['user_id' => $userId, 'username' => $username, 'guild_id' => (string)($interaction['guild_id'] ?? '')] + ($isZip ? ['archive' => $original] : []);
  $stored = 0;
  try {
    foreach ($files as [$name, $tmp]) {
      $md5 = ds_scan($tmp);
      if ($md5 === null) { $notes[] = "`$name` does not look like a WoW Census save (no WoWCensusDB or MarketLensDB in it)."; continue; }
      if ($stored >= $allowance) { $notes[] = "Skipped `$name`: hourly limit of " . DS_PER_HOUR . ' files reached.'; continue; }
      $line = ds_keep($dir, $tmp, $md5, $name, $row, $kept);
      if (str_starts_with($line, 'Received')) $stored++;
      $notes[] = $line;
    }
  } finally {
    foreach ($files as [, $tmp]) if (is_file($tmp)) unlink($tmp);
    if (is_file($incoming)) unlink($incoming);
  }
  if ($stored) $notes[] = 'Thanks for contributing to WoWCensus!';
  $message = implode("\n", $notes);
  return strlen($message) > 1900 ? substr($message, 0, 1900) . "\n…" : $message;
}

if (defined('DS_LIBRARY_ONLY')) return; // tests load the functions only
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); exit; }
$publicKey = (string)getenv('DISCORD_PUBLIC_KEY');
if (!preg_match('/\A[0-9a-f]{64}\z/i', $publicKey)) { error_log('discord: DISCORD_PUBLIC_KEY is not set'); http_response_code(404); exit; }

$raw = (string)file_get_contents('php://input');
$signature = (string)($_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? '');
$timestamp = (string)($_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? '');
$valid = preg_match('/\A[0-9a-f]{128}\z/i', $signature) && $timestamp !== ''
  && sodium_crypto_sign_verify_detached(sodium_hex2bin($signature), $timestamp . $raw, sodium_hex2bin($publicKey));
if (!$valid) { http_response_code(401); exit('invalid request signature'); }

$interaction = json_decode($raw, true);
if (!is_array($interaction)) { http_response_code(400); exit; }
$type = (int)($interaction['type'] ?? 0);
if ($type === 1) { ds_json(['type' => 1]); exit; } // PING
if ($type !== 2 || ($interaction['data']['name'] ?? '') !== 'submit') { ds_json(['type' => 4, 'data' => ['content' => 'Unknown command.', 'flags' => 64]]); exit; }

// Discord wants an answer within 3 seconds: defer (ephemeral), finish the response, then do the work.
ds_json(['type' => 5, 'data' => ['flags' => 64]]);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
ignore_user_abort(true);
set_time_limit(120);
$kept = [];
try { $message = ds_submit($interaction, null, $kept); }
catch (Throwable $e) { error_log('discord: ' . $e); $message = 'Something went wrong storing your file. Try again later.'; }
ds_reply((string)($interaction['application_id'] ?? ''), (string)($interaction['token'] ?? ''), $message);
// After the reply, so the player never waits on the email.
try { ds_email(ds_store(), $kept); } catch (Throwable $e) { error_log('discord: email: ' . $e); }
