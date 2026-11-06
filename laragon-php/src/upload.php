<?php
// Private Lua file exchange. Files live outside the document root.
declare(strict_types=1);

const UPLOAD_MAX_BYTES = 25 * 1024 * 1024;
const UPLOAD_IDLE = 43200;

header('Cache-Control: private, no-store');
header('Cloudflare-CDN-Cache-Control: no-store');
header('X-Accel-Expires: 0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

function up_h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function up_page(string $body): void {
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Lua files · WoWCensus</title><style>'
    . ':root{color-scheme:dark;font:16px system-ui,sans-serif;background:#101720;color:#edf3fa}*{box-sizing:border-box}body{max-width:820px;margin:40px auto;padding:0 18px}h1{margin:0 0 8px}p{color:#b9c8d6}a{color:#91c9ff}form{margin:0}input,button{font:inherit}input[type=password]{padding:10px;background:#192632;color:white;border:1px solid #52677a;border-radius:7px;width:100%;max-width:340px}button,.button{background:#456e96;color:white;border:0;border-radius:7px;padding:9px 15px;cursor:pointer}button:hover,.button:hover{background:#5d87ad}.top{display:flex;justify-content:space-between;gap:16px;align-items:center}.drop{display:block;margin:28px 0;padding:46px 20px;text-align:center;border:2px dashed #6685a1;border-radius:14px;background:#192632;cursor:pointer}.drop.drag{background:#24435c;border-color:#a7d6ff}.drop input{position:absolute;width:1px;height:1px;opacity:0}.drop strong{display:block;font-size:1.2rem}.drop small{display:block;margin-top:7px;color:#b9c8d6}.list{list-style:none;padding:0;margin:18px 0}.list li{display:flex;align-items:center;gap:14px;padding:13px 0;border-bottom:1px solid #344759}.file{flex:1;min-width:0;overflow-wrap:anywhere}.meta{display:block;color:#aabac9;font-size:.8rem;margin-top:4px}.delete{background:#7f3640;min-width:38px}.delete:hover{background:#aa4753}.notice{padding:11px 14px;border-radius:8px;background:#264830;color:#d7f5da}.error{background:#5a2730;color:#ffe2e4}.progress{min-height:24px}</style></head><body>' . $body . '</body></html>';
}
function up_csrf(): string {
  if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
  return $_SESSION['csrf'];
}
function up_csrf_ok(): bool { return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? '')); }
function up_store(): string { return (string)(getenv('LUA_UPLOAD_DIR') ?: dirname(__DIR__, 2) . '/lua-uploads'); }
function up_name(string $name): bool {
  return strlen($name) <= 180 && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._ -]*\.lua\z/iD', $name) === 1 && !str_contains($name, '..');
}
function up_files(string $dir): array {
  if (!is_dir($dir)) return [];
  $files = [];
  foreach (new DirectoryIterator($dir) as $item) {
    if (!$item->isFile() || $item->isLink() || !up_name($item->getFilename())) continue;
    $files[] = ['name' => $item->getFilename(), 'size' => $item->getSize(), 'time' => $item->getMTime(), 'md5' => md5_file($item->getPathname())];
  }
  usort($files, fn($a, $b) => $b['time'] <=> $a['time'] ?: strcmp($a['name'], $b['name']));
  return $files;
}

$hash = (string)getenv('UPLOAD_PASSWORD_HASH');
if ($hash === '' && ($file = getenv('UPLOAD_PASSWORD_HASH_FILE')) && is_readable($file)) $hash = trim((string)file_get_contents($file));
if ($hash === '') $hash = (string)getenv('ADMIN_PASSWORD_HASH');
if ($hash === '' && ($file = getenv('ADMIN_PASSWORD_HASH_FILE')) && is_readable($file)) $hash = trim((string)file_get_contents($file));
if ($hash === '' || (password_get_info($hash)['algo'] ?? null) === null) { http_response_code(404); exit('Not found'); }
if ($path === '/upload/') { header('Location: /upload', true, 301); exit; }
$https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || str_contains((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"https"');
session_name('wowcensus_upload');
session_start(['cookie_path' => '/upload', 'cookie_httponly' => true, 'cookie_secure' => $https, 'cookie_samesite' => 'Strict', 'use_strict_mode' => true, 'gc_maxlifetime' => UPLOAD_IDLE]);
$authed = ($_SESSION['upload_auth'] ?? false) === true && time() - (int)($_SESSION['seen'] ?? 0) < UPLOAD_IDLE;
$error = '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST' && !up_csrf_ok()) { http_response_code(403); $error = 'Session expired. Reload and try again.'; }
elseif ($method === 'POST' && ($_POST['action'] ?? '') === 'login' && !$authed) {
  // Share the admin portal's per-address login limit.
  require_once __DIR__ . '/visitor-ip.php';
  $ip = shout_visitor_ip();
  $limitFile = sys_get_temp_dir() . '/wowcensus-upload-login-' . hash('sha256', $ip);
  $attempts = is_file($limitFile) ? (int)file_get_contents($limitFile) : 0;
  if ($attempts >= 8 && time() - filemtime($limitFile) < 900) { http_response_code(429); $error = 'Too many attempts. Try again later.'; }
  elseif (password_verify((string)($_POST['password'] ?? ''), $hash)) {
    session_regenerate_id(true);
    $_SESSION = ['upload_auth' => true, 'seen' => time(), 'csrf' => bin2hex(random_bytes(24))];
    @unlink($limitFile);
    header('Location: /upload', true, 303); exit;
  } else { file_put_contents($limitFile, (string)($attempts + 1), LOCK_EX); http_response_code(401); $error = 'Wrong password.'; }
}
if (!$authed) {
  up_page('<h1>Lua file exchange</h1><p>Sign in to upload or retrieve your files.</p>' . ($error ? '<p class="notice error">' . up_h($error) . '</p>' : '') . '<form method="post" action="/upload"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="' . up_csrf() . '"><p><label for="password">Password</label></p><input id="password" type="password" name="password" autocomplete="current-password" required><p><button>Sign in</button></p></form>');
  exit;
}
$_SESSION['seen'] = time();
$dir = up_store();
if ($method === 'POST' && !$error) {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'logout') { $_SESSION = []; session_regenerate_id(true); header('Location: /upload', true, 303); exit; }
  if ($action === 'delete') {
    $name = (string)($_POST['name'] ?? '');
    if (!up_name($name) || !is_file($dir . '/' . $name) || is_link($dir . '/' . $name)) { http_response_code(404); $error = 'File not found.'; }
    elseif (!unlink($dir . '/' . $name)) { http_response_code(500); $error = 'Could not delete the file.'; }
    else { header('Location: /upload?deleted=1', true, 303); exit; }
  } elseif ($action === 'upload') {
    $uploads = $_FILES['files'] ?? null;
    if (!$uploads || !is_array($uploads['name'] ?? null)) $error = 'Choose one or more .lua files.';
    else {
      if (!is_dir($dir) && !mkdir($dir, 0700, true)) $error = 'Storage is unavailable.';
      foreach (($uploads['name'] ?? []) as $i => $name) {
        if ($error) break;
        $temp = (string)($uploads['tmp_name'][$i] ?? '');
        $size = (int)($uploads['size'][$i] ?? 0);
        if (($uploads['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !up_name((string)$name) || $size < 1 || $size > UPLOAD_MAX_BYTES || !is_uploaded_file($temp)) { $error = 'Only nonempty .lua files up to 25 MB are accepted.'; break; }
        $target = $dir . '/' . $name;
        if (file_exists($target)) {
          $stem = substr((string)$name, 0, -4);
          $suffix = gmdate('Ymd-His');
          for ($copy = 1; file_exists($target); $copy++) {
            $candidate = substr($stem, 0, 150) . '-' . $suffix . ($copy > 1 ? '-' . $copy : '') . '.lua';
            $target = $dir . '/' . $candidate;
          }
        }
        if (!move_uploaded_file($temp, $target)) { $error = 'Could not save ' . $name . '.'; break; }
        chmod($target, 0600);
      }
      if (!$error) { header('Location: /upload?uploaded=1', true, 303); exit; }
    }
  }
}
if ($method === 'GET' && isset($_GET['download'])) {
  $name = (string)$_GET['download'];
  $file = $dir . '/' . $name;
  if (!up_name($name) || !is_file($file) || is_link($file)) { http_response_code(404); exit('File not found'); }
  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="' . $name . '"');
  header('Content-Length: ' . filesize($file));
  readfile($file); exit;
}
$body = '<div class="top"><div><h1>Lua file exchange</h1><p>Move SavedVariables between your computers. Files stay here until you delete them.</p></div><form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="' . up_csrf() . '"><button>Sign out</button></form></div>';
if ($error) $body .= '<p class="notice error" role="alert">' . up_h($error) . '</p>';
elseif (isset($_GET['uploaded'])) $body .= '<p class="notice">Upload complete.</p>';
elseif (isset($_GET['deleted'])) $body .= '<p class="notice">File deleted.</p>';
$body .= '<form id="upload-form" method="post" enctype="multipart/form-data" action="/upload"><input type="hidden" name="action" value="upload"><input type="hidden" name="csrf" value="' . up_csrf() . '"><label class="drop" id="drop"><strong>Drop .lua files here or click to choose</strong><small>Up to 25 MB per file</small><input id="files" type="file" name="files[]" accept=".lua" multiple required></label><button type="submit">Upload files</button><p class="progress" id="progress" role="status"></p></form><h2>Files</h2><ul class="list">';
$files = up_files($dir);
if (!$files) $body .= '<li>No files uploaded yet.</li>';
foreach ($files as $file) {
  $name = up_h($file['name']);
  $body .= '<li><div class="file"><a href="/upload?download=' . rawurlencode($file['name']) . '">' . $name . '</a><span class="meta">' . number_format($file['size'] / 1024, 1) . ' KB · MD5 ' . $file['md5'] . ' · ' . gmdate('Y-m-d H:i', $file['time']) . ' UTC</span></div><form method="post" action="/upload" onsubmit="return confirm(\'Delete this file?\')"><input type="hidden" name="action" value="delete"><input type="hidden" name="csrf" value="' . up_csrf() . '"><input type="hidden" name="name" value="' . $name . '"><button class="delete" aria-label="Delete ' . $name . '" title="Delete">X</button></form></li>';
}
$body .= '</ul><script>const drop=document.getElementById("drop"),input=document.getElementById("files"),status=document.getElementById("progress");for(const event of ["dragenter","dragover"]){drop.addEventListener(event,e=>{e.preventDefault();drop.classList.add("drag")})}for(const event of ["dragleave","drop"]){drop.addEventListener(event,e=>{e.preventDefault();drop.classList.remove("drag")})}drop.addEventListener("drop",e=>{input.files=e.dataTransfer.files;status.textContent=input.files.length+" file(s) selected"});input.addEventListener("change",()=>status.textContent=input.files.length+" file(s) selected");document.getElementById("upload-form").addEventListener("submit",()=>status.textContent="Uploading…");</script>';
up_page($body);
