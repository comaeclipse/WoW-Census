<?php
// Admin cache stats (/admin/?view=cache): every cache between a visitor and PostgreSQL,
// read on the server as it is now. Each section stands alone and says why when its
// source is missing or unreadable (a local Windows preview has no nginx, APCu or /proc).
//   nginx page cache   /var/cache/nginx/wowcensus (keys GET|host|uri|skin|accept|code|data)
//   rollouts           /var/log/wowcensus-data-refresh.log, /var/lib/wowcensus, /proc
//   APCu               Db.php's query cache (ml:q:<data version>:<sha256>, ml:qm:<sha256> metadata)
//   OPcache, realpath  this PHP-FPM master's code caches
//   PostgreSQL         census tables vs the newest import, buffer cache hit rates
//   access log         what reached the origin (Cloudflare misses and bypasses)
// Paths can be pointed elsewhere for testing: ML_ADMIN_NGINX_CACHE, ML_ADMIN_REFRESH_LOG,
// ML_ADMIN_ACCESS_LOG, ML_ADMIN_STATE_DIR.
declare(strict_types=1);

const AC_SCAN_MAX_FILES = 40000;
const AC_SCAN_BUDGET = 45.0;     // seconds (14k files took 12 s cold on the live box)
const AC_SCAN_TTL = 600;         // a page-cache scan is reused this long (APCu)
const AC_LOG_TAIL = 8 << 20;     // bytes of the access log read
const AC_REFRESH_TAIL = 384 << 10;

function ac_paths(): array {
  return [
    'cache' => getenv('ML_ADMIN_NGINX_CACHE') ?: '/var/cache/nginx/wowcensus',
    'refresh_log' => getenv('ML_ADMIN_REFRESH_LOG') ?: '/var/log/wowcensus-data-refresh.log',
    'access_log' => getenv('ML_ADMIN_ACCESS_LOG') ?: '/var/log/nginx/access.log',
    'state' => getenv('ML_ADMIN_STATE_DIR') ?: '/var/lib/wowcensus',
    'current' => '/srv/wowcensus/current',
    'nginx_code' => '/etc/nginx/conf.d/wowcensus-code-version.conf',
    'nginx_data' => '/etc/nginx/conf.d/wowcensus-data-version.conf',
  ];
}

function ac_apcu_on(): bool {
  return function_exists('apcu_enabled') && apcu_enabled();
}

function ac_whoami(): string {
  if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) return (string)(posix_getpwuid(posix_geteuid())['name'] ?? posix_geteuid());
  return get_current_user();
}

/** Reads at most $bytes from the end of a file, starting at a line. */
function ac_tail(string $file, int $bytes): ?string {
  $h = @fopen($file, 'rb');
  if (!$h) return null;
  $size = (int)(fstat($h)['size'] ?? 0);
  if ($size > $bytes) fseek($h, $size - $bytes);
  $data = (string)stream_get_contents($h);
  fclose($h);
  if ($size > $bytes && ($nl = strpos($data, "\n")) !== false) $data = substr($data, $nl + 1);
  return $data;
}

function ac_unavailable(string $path): string {
  if (!file_exists($path)) return "$path is not on this server.";
  return "$path is not readable by " . ac_whoami() . '.';
}

// ---------------------------------------------------------------- APCu

/** Drops query results keyed by an older data version: nothing reads them again. */
function ac_apcu_drop_old(): string {
  if (!ac_apcu_on()) return 'APCu is not enabled here.';
  $current = ac_apcu_current_version();
  if ($current === null) return 'The current data version is not known yet; load a page first.';
  $dropped = 0; $bytes = 0;
  foreach (new APCUIterator('/^ml:q:/', APC_ITER_KEY | APC_ITER_MEM_SIZE, 500, APC_LIST_ACTIVE) as $key => $e) {
    if (!preg_match('/^ml:q:(.+):[0-9a-f]{64}$/', $key, $m) || $m[1] === $current) continue;
    if (apcu_delete($key)) { $dropped++; $bytes += (int)$e['mem_size']; }
  }
  return 'Dropped ' . number_format($dropped) . ' query results from older data versions (' . ac_bytes($bytes) . ').';
}

function ac_apcu_current_version(): ?string {
  $v = apcu_fetch('ml:data-version', $ok);
  if ($ok) return (string)$v;
  if (getenv('ML_CENSUS_PUBLISHED') === '1') return Db::publishedVersion();
  return null;
}

function ac_apcu(): array {
  if (!function_exists('apcu_cache_info')) return ['error' => 'The APCu extension is not loaded in this PHP.'];
  if (!apcu_enabled()) return ['error' => 'APCu is loaded but disabled (apc.enabled / apc.enable_cli).'];
  $info = apcu_cache_info(true);
  $sma = apcu_sma_info(false);
  $total = (int)$sma['num_seg'] * (int)$sma['seg_size'];
  $free = (int)$sma['avail_mem'];
  $freeBlocks = 0; $smallFree = 0; $largestFree = 0;
  foreach ($sma['block_lists'] ?? [] as $blocks) foreach ($blocks as $b) {
    $freeBlocks++;
    $largestFree = max($largestFree, (int)$b['size']);
    if ($b['size'] < 5 << 20) $smallFree += (int)$b['size'];
  }
  $current = ac_apcu_current_version();
  $versions = []; $locks = []; $meta = []; $entries = []; $other = ['n' => 0, 'bytes' => 0, 'keys' => []];
  $flags = APC_ITER_KEY | APC_ITER_NUM_HITS | APC_ITER_MEM_SIZE | APC_ITER_CTIME | APC_ITER_ATIME | APC_ITER_TTL;
  foreach (new APCUIterator(null, $flags, 500, APC_LIST_ACTIVE) as $key => $e) {
    $key = (string)$key;
    if (preg_match('/^ml:q:(.+):([0-9a-f]{64})(:lock)?$/', $key, $m)) {
      if (!empty($m[3])) { $locks[] = ['version' => $m[1], 'sha' => $m[2], 'since' => (int)$e['creation_time']]; continue; }
      $v = &$versions[$m[1]];
      $v ??= ['n' => 0, 'bytes' => 0, 'hits' => 0, 'newest' => 0, 'oldest' => PHP_INT_MAX, 'unread' => 0];
      $v['n']++; $v['bytes'] += (int)$e['mem_size']; $v['hits'] += (int)$e['num_hits'];
      $v['newest'] = max($v['newest'], (int)$e['creation_time']); $v['oldest'] = min($v['oldest'], (int)$e['creation_time']);
      if ((int)$e['num_hits'] === 0) $v['unread']++;
      unset($v);
      $entries[] = ['version' => $m[1], 'sha' => $m[2], 'hits' => (int)$e['num_hits'], 'bytes' => (int)$e['mem_size'],
        'ctime' => (int)$e['creation_time'], 'atime' => (int)$e['access_time']];
    } elseif (str_starts_with($key, 'ml:qm:')) {
      $meta[substr($key, 6)] = true;
    } else {
      $other['n']++; $other['bytes'] += (int)$e['mem_size'];
      if (count($other['keys']) < 12) $other['keys'][] = ['key' => $key, 'bytes' => (int)$e['mem_size'], 'hits' => (int)$e['num_hits'], 'ttl' => (int)$e['ttl']];
    }
  }
  if ($current === null && $versions) {
    uasort($versions, fn($a, $b) => $b['newest'] <=> $a['newest']);
    $current = (string)array_key_first($versions);
  }
  uasort($versions, fn($a, $b) => $b['newest'] <=> $a['newest']);
  $live = array_values(array_filter($entries, fn($e) => $e['version'] === $current));
  $attach = function (array $list) {
    foreach ($list as &$e) $e['meta'] = apcu_fetch('ml:qm:' . $e['sha']) ?: null;
    return $list;
  };
  $top = fn(callable $cmp) => $attach(array_slice((function () use ($live, $cmp) { usort($live, $cmp); return $live; })(), 0, 15));
  // The slowest queries: from the metadata, which survives only while its entry does.
  $slow = [];
  foreach ($live as $e) if (isset($meta[$e['sha']]) && ($m = apcu_fetch('ml:qm:' . $e['sha']))) $slow[] = $e + ['meta' => $m];
  usort($slow, fn($a, $b) => $b['meta']['ms'] <=> $a['meta']['ms']);
  $saved = 0;
  foreach ($slow as $e) $saved += $e['meta']['ms'] * $e['hits'];
  $hits = (int)$info['num_hits']; $misses = (int)$info['num_misses'];
  return [
    'info' => $info, 'total' => $total, 'free' => $free, 'free_blocks' => $freeBlocks, 'largest_free' => $largestFree,
    'fragmentation' => $free > 0 ? $smallFree / $free : 0.0,
    'hit_rate' => $hits + $misses > 0 ? $hits / ($hits + $misses) : null,
    'current' => $current, 'versions' => $versions, 'locks' => $locks, 'other' => $other,
    'live' => count($live), 'live_bytes' => array_sum(array_column($live, 'bytes')),
    'live_hits' => array_sum(array_column($live, 'hits')),
    'with_meta' => count($slow), 'saved_ms' => $saved,
    'top_hits' => $top(fn($a, $b) => $b['hits'] <=> $a['hits']),
    'top_bytes' => $top(fn($a, $b) => $b['bytes'] <=> $a['bytes']),
    'slow' => array_slice($slow, 0, 15),
    'config' => ['shm_size' => ini_get('apc.shm_size'), 'ttl' => ini_get('apc.ttl'), 'gc_ttl' => ini_get('apc.gc_ttl'), 'entries_hint' => ini_get('apc.entries_hint')],
  ];
}

// ---------------------------------------------------------------- OPcache and realpath

function ac_opcache(): array {
  if (!function_exists('opcache_get_status')) return ['error' => 'OPcache is not loaded in this PHP.'];
  $status = @opcache_get_status(false);
  if (!is_array($status)) return ['error' => 'OPcache is off here, or opcache.restrict_api keeps this script out.'];
  $config = @opcache_get_configuration();
  return ['status' => $status, 'directives' => is_array($config) ? $config['directives'] : []];
}

function ac_realpath(): array {
  $paths = ac_paths();
  $cache = function_exists('realpath_cache_get') ? realpath_cache_get() : [];
  $entry = $cache[$paths['current']] ?? null;
  $link = @readlink($paths['current']);
  $code = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
  $stat = @stat($code);
  return [
    'size' => realpath_cache_size(), 'limit' => ini_get('realpath_cache_size'), 'ttl' => (int)ini_get('realpath_cache_ttl'),
    'entries' => count($cache), 'link' => $link === false ? null : $link, 'code' => $code,
    'code_since' => $stat ? max((int)$stat['ctime'], (int)$stat['mtime']) : null,
    'cached_target' => $entry['realpath'] ?? null, 'cached_expires' => isset($entry['expires']) ? (int)$entry['expires'] : null,
  ];
}

// ---------------------------------------------------------------- nginx page cache

function ac_edition_dirs(): array {
  $dirs = [];
  if (defined('EDITIONS')) foreach (EDITIONS as $e) if (($e['dir'] ?? '') !== '') $dirs[$e['dir']] = $e['label'];
  return $dirs;
}

/** One cache file: nginx's binary header (version 5, 64-bit), the KEY line, the saved response headers. */
function ac_nginx_entry(string $path, int $size, int $mtime): ?array {
  $h = @fopen($path, 'rb');
  if (!$h) return null;
  $head = (string)fread($h, 4096);
  fclose($h);
  $e = ['size' => $size, 'saved' => $mtime, 'expires' => null, 'key' => null, 'status' => 200, 'edge' => null, 'type' => null];
  if (strlen($head) >= 58) {
    $u = unpack('Pversion/Pvalid/Pupdating/Perror/Plm/Pdate/Vcrc/vvms/vhs/vbs', $head);
    $now = time();
    if ($u['version'] === 5 && $u['date'] > 1500000000 && $u['date'] <= $now + 86400 && $u['valid'] > 1500000000) {
      $e['saved'] = (int)$u['date'];
      $e['expires'] = (int)$u['valid'];
    }
  }
  if (($k = strpos($head, "\nKEY: ")) !== false && ($eol = strpos($head, "\n", $k + 6)) !== false) $e['key'] = substr($head, $k + 6, $eol - $k - 6);
  $headers = $k !== false ? substr($head, $k) : $head;
  if (($end = strpos($headers, "\r\n\r\n")) !== false) $headers = substr($headers, 0, $end);
  // The first header follows FastCGI's binary record header, not a line break.
  if (preg_match('/(?<![\w-])Status: (\d{3})/i', $headers, $m)) $e['status'] = (int)$m[1];
  if (preg_match('/(?<![\w-])Cloudflare-CDN-Cache-Control: ([^\r\n]+)/i', $headers, $m)) $e['edge'] = trim($m[1]);
  if (preg_match('/(?<![\w-])Content-Type: ([^\r\n;]+)/i', $headers, $m)) $e['type'] = strtolower(trim($m[1]));
  return $e;
}

/** The code and data parts nginx puts in cache keys now (its map ... { default "..."; } lines); null when unknown. */
function ac_nginx_key_part(string $file): ?string {
  $text = @file_get_contents($file);
  return $text !== false && preg_match('/^\s*map\s+\$host\s+\$wowcensus_\w+\s*\{\s*default\s+"([^"]*)"/m', $text, $m) ? $m[1] : null;
}

function ac_nginx_scan(): array {
  $dir = ac_paths()['cache'];
  if (!is_dir($dir) || !is_readable($dir)) return ['error' => ac_unavailable($dir)];
  $started = microtime(true);
  $entries = []; $unreadable = 0; $truncated = false; $files = 0;
  try {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
    foreach ($it as $file) {
      if (!$file->isFile()) continue;
      $name = $file->getFilename();
      if (str_contains($name, '.')) continue;  // nginx's temporary files while it saves
      if (++$files > AC_SCAN_MAX_FILES || microtime(true) - $started > AC_SCAN_BUDGET) { $truncated = true; break; }
      $e = ac_nginx_entry($file->getPathname(), (int)$file->getSize(), (int)$file->getMTime());
      if ($e === null) { $unreadable++; continue; }
      $e['file'] = $name;
      $entries[] = $e;
    }
  } catch (Throwable $ex) {
    return ['error' => 'Scanning ' . $dir . ' failed: ' . $ex->getMessage()];
  }
  $host = 'wowcensus.io';
  $dirs = ac_edition_dirs();
  // Entries saved under an older code or data key part are never read again (nginx asks for
  // the current parts only); they stay on disk until evicted or deleted.
  $liveCode = ac_nginx_key_part(ac_paths()['nginx_code']);
  $liveData = ac_nginx_key_part(ac_paths()['nginx_data']);
  foreach ($entries as &$e) {
    $f = $e['key'] === null ? [] : explode('|', $e['key']);
    $e['kind'] = $e['key'] === null ? 'no key' : (count($f) === 7 && $f[0] === 'GET' ? ($f[1] === $host ? 'current' : 'other host') : 'old format');
    $e['host'] = $f[1] ?? '';
    $e['uri'] = count($f) >= 3 ? $f[2] : (string)$e['key'];
    $e['skin'] = count($f) === 7 ? ($f[3] === '' ? 'wow2008' : $f[3]) : '';
    $e['accept'] = count($f) === 7 ? ($f[4] === '' ? 'html' : $f[4]) : '';
    $e['code'] = count($f) === 7 ? $f[5] : '';
    $e['data'] = count($f) === 7 ? $f[6] : '';
    if ($e['kind'] === 'current' && (($liveCode !== null && $e['code'] !== $liveCode) || ($liveData !== null && $e['data'] !== $liveData))) $e['kind'] = 'retired';
    $seg = explode('/', ltrim((string)parse_url($e['uri'], PHP_URL_PATH), '/'))[0] ?? '';
    $e['section'] = $e['uri'] === '/' ? 'Home' : ($dirs[$seg] ?? ($seg === 'blog' ? 'Blog' : 'Root pages'));
    if (preg_match('/max-age=(\d+)/', (string)$e['edge'], $m)) $e['edge_ttl'] = (int)$m[1];
    else $e['edge_ttl'] = $e['edge'] === null ? null : 0;
  }
  unset($e);
  return ['entries' => $entries, 'unreadable' => $unreadable, 'truncated' => $truncated,
    'scanned_at' => time(), 'scan_ms' => (int)round((microtime(true) - $started) * 1000), 'dir' => $dir,
    'disk_free' => @disk_free_space($dir) ?: null, 'disk_total' => @disk_total_space($dir) ?: null];
}

function ac_nginx(bool $rescan): array {
  if (ac_apcu_on() && !$rescan) {
    $scan = apcu_fetch('ml:admin:nginx-scan', $ok);
    if ($ok) return $scan + ['reused' => true];
  }
  $scan = ac_nginx_scan();
  if (ac_apcu_on() && !isset($scan['error'])) apcu_store('ml:admin:nginx-scan', $scan, AC_SCAN_TTL);
  return $scan + ['reused' => false];
}

/** Counts and sizes grouped by one field. */
function ac_group(array $entries, string|callable $by): array {
  $out = [];
  foreach ($entries as $e) {
    $k = is_callable($by) ? $by($e) : (string)($e[$by] ?? '');
    $out[$k] ??= ['n' => 0, 'bytes' => 0];
    $out[$k]['n']++;
    $out[$k]['bytes'] += $e['size'];
  }
  uasort($out, fn($a, $b) => $b['n'] <=> $a['n']);
  return $out;
}

// ---------------------------------------------------------------- rollouts and processes

function ac_processes(): array {
  if (!is_dir('/proc/self')) return ['error' => 'No /proc here.'];
  $watch = ['wowcensus-release-rollout', 'wowcensus-rollout', 'wowcensus-data-refresh', 'build-census-cache.php', 'warm-cache.sh', 'import-live', 'pg_dump', 'pg_restore'];
  $uptime = (float)explode(' ', (string)@file_get_contents('/proc/uptime'))[0];
  $out = [];
  foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $p) {
    $cmd = @file_get_contents("$p/cmdline");
    if (!$cmd) continue;
    $cmd = trim(str_replace("\0", ' ', $cmd));
    foreach ($watch as $w) {
      if (!str_contains($cmd, $w)) continue;
      $stat = (string)@file_get_contents("$p/stat");
      $fields = explode(' ', substr($stat, (int)strrpos($stat, ')') + 2));
      $start = isset($fields[19]) ? (int)$fields[19] / 100 : null;
      $out[] = ['pid' => (int)basename($p), 'what' => $w, 'cmd' => substr($cmd, 0, 200), 'elapsed' => $start !== null ? (int)($uptime - $start) : null];
      break;
    }
  }
  return ['list' => $out];
}

function ac_rollout(): array {
  $paths = ac_paths();
  $state = [];
  foreach (['data-version' => 'published', 'data-refresh.failed' => 'failed'] as $file => $name) {
    $f = $paths['state'] . '/' . $file;
    if (is_file($f)) $state[$name] = ['at' => (int)filemtime($f), 'value' => trim((string)@file_get_contents($f))];
  }
  $text = ac_tail($paths['refresh_log'], AC_REFRESH_TAIL);
  if ($text === null) return ['error' => ac_unavailable($paths['refresh_log']), 'state' => $state];
  $lines = array_values(array_filter(explode("\n", $text), fn($l) => trim($l) !== ''));
  $r = ['state' => $state, 'tail' => array_slice($lines, -30), 'last_start' => null, 'last_progress' => null, 'last_done' => null,
    'last_publish' => null, 'last_census' => null, 'last_stop' => null, 'failed_since_start' => 0, 'purge_failures' => 0, 'history' => []];
  foreach ($lines as $line) {
    if (!preg_match('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ) (.*)$/', $line, $m)) continue;
    $at = (int)strtotime($m[1]); $msg = $m[2];
    if (preg_match('/^rolling out (\d+) cached pages/', $msg, $x)) {
      $r['last_start'] = ['at' => $at, 'total' => (int)$x[1]]; $r['last_progress'] = null; $r['failed_since_start'] = 0;
    } elseif (preg_match('#^(\d+)/(\d+) pages \((\d+) ok, (\d+) failed, (\d+)s\)#', $msg, $x)) {
      $r['last_progress'] = ['at' => $at, 'n' => (int)$x[1], 'total' => (int)$x[2], 'ok' => (int)$x[3], 'failed' => (int)$x[4], 'secs' => (int)$x[5]];
    } elseif (preg_match('/^rollout done: (\d+) ok, (\d+) failed of (\d+)(?:, (\d+) purged at Cloudflare)?,? in (\d+)s/', $msg, $x)) {
      // Older rollout scripts log no purge count.
      $r['last_done'] = ['at' => $at, 'ok' => (int)$x[1], 'failed' => (int)$x[2], 'total' => (int)$x[3], 'purged' => $x[4] === '' ? null : (int)$x[4], 'secs' => (int)$x[5]];
      $r['history'][] = $r['last_done'];
    } elseif (str_starts_with($msg, 'failed: ')) {
      $r['failed_since_start']++;
    } elseif (str_starts_with($msg, 'cloudflare purge failed')) {
      $r['purge_failures']++;
    } elseif (str_starts_with($msg, 'stopping:') || str_starts_with($msg, 'rollout stopped early')) {
      $r['last_stop'] = ['at' => $at, 'msg' => $msg];
    } elseif (preg_match('/^published (\S+); rolling out/', $msg, $x)) {
      $r['last_publish'] = ['at' => $at, 'version' => $x[1]];
    } elseif (preg_match('/^census tables rebuilt in (\d+)s/', $msg, $x)) {
      $r['last_census'] = ['at' => $at, 'secs' => (int)$x[1]];
    }
  }
  $r['history'] = array_slice($r['history'], -8);
  return $r;
}

// ---------------------------------------------------------------- PostgreSQL

function ac_postgres(): array {
  try {
    $one = fn(string $sql) => Db::uncached($sql)[0] ?? [];
    $out = [];
    $out['census'] = $one("SELECT m.version, m.built_at::text AS built_at, (SELECT max(imported_at_utc) FROM local_imports) AS latest,
        (SELECT count(*) FROM local_imports WHERE imported_at_utc > m.version) AS pending FROM census_cache_meta m LIMIT 1");
    $out['tables'] = Db::uncached("SELECT c.relname AS name, pg_total_relation_size(c.oid) AS bytes, c.reltuples::bigint AS est_rows
        FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = current_schema() AND c.relkind = 'r' AND c.relname LIKE 'census%' ORDER BY 2 DESC");
    $out['db'] = $one("SELECT current_database() AS name, pg_database_size(current_database()) AS bytes, blks_hit, blks_read, xact_commit, xact_rollback,
        temp_files, temp_bytes, deadlocks, numbackends, stats_reset::text AS stats_reset FROM pg_stat_database WHERE datname = current_database()");
    $out['settings'] = Db::uncached("SELECT name, setting, unit FROM pg_settings WHERE name IN ('shared_buffers', 'effective_cache_size', 'work_mem', 'max_connections')");
    $out['io'] = Db::uncached("SELECT relname AS name, heap_blks_read, heap_blks_hit, COALESCE(idx_blks_read, 0) AS idx_blks_read, COALESCE(idx_blks_hit, 0) AS idx_blks_hit
        FROM pg_statio_user_tables WHERE schemaname = current_schema()
        ORDER BY heap_blks_read + COALESCE(idx_blks_read, 0) DESC LIMIT 10");
    $out['active'] = Db::uncached("SELECT pid, state, (now() - query_start)::text AS running, left(regexp_replace(query, '\\s+', ' ', 'g'), 160) AS query
        FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND state <> 'idle' ORDER BY query_start LIMIT 10");
    return $out;
  } catch (Throwable $e) {
    return ['error' => 'PostgreSQL stats failed: ' . $e->getMessage()];
  }
}

// ---------------------------------------------------------------- origin access log

function ac_access_log(): array {
  $file = ac_paths()['access_log'];
  $text = ac_tail($file, AC_LOG_TAIL);
  if ($text === null) return ['error' => ac_unavailable($file)];
  $re = '~^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+)[^"]*" (\d{3}) (\d+|-)(?: "[^"]*" "([^"]*)")?(.*)$~';
  $n = 0; $local = 0; $bots = 0; $first = null; $last = null; $bytes = 0;
  $codes = []; $paths = []; $hours = []; $cacheStatus = []; $withStatus = 0;
  foreach (explode("\n", $text) as $line) {
    if (!preg_match($re, $line, $m)) continue;
    $t = DateTime::createFromFormat('d/M/Y:H:i:s O', $m[2]);
    if (!$t) continue;
    $ts = $t->getTimestamp();
    $first ??= $ts; $last = $ts; $n++;
    if ($m[1] === '127.0.0.1' || $m[1] === '::1') $local++;
    if (preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit/i', $m[7] ?? '')) $bots++;
    $code = (int)$m[5];
    $codes[intdiv($code, 100) . 'xx'] = ($codes[intdiv($code, 100) . 'xx'] ?? 0) + 1;
    $bytes += $m[6] === '-' ? 0 : (int)$m[6];
    if ($m[1] !== '127.0.0.1' && $m[3] === 'GET') {
      $p = strtok($m[4], '?');
      $paths[$p] = ($paths[$p] ?? 0) + 1;
    }
    $hour = intdiv($ts, 3600) * 3600;
    $hours[$hour] = ($hours[$hour] ?? 0) + 1;
    if (preg_match('/(?:^|\s)(HIT|MISS|EXPIRED|STALE|UPDATING|REVALIDATED|BYPASS)(?:\s|$)/', $m[8] ?? '', $cs)) {
      $withStatus++;
      $cacheStatus[$cs[1]] = ($cacheStatus[$cs[1]] ?? 0) + 1;
    }
  }
  if ($n === 0) return ['error' => "No requests in the combined log format were found in $file."];
  arsort($paths); arsort($cacheStatus); ksort($codes); ksort($hours);
  return ['n' => $n, 'local' => $local, 'bots' => $bots, 'first' => $first, 'last' => $last, 'bytes' => $bytes, 'codes' => $codes,
    'paths' => array_slice($paths, 0, 15, true), 'hours' => array_slice($hours, -48, null, true),
    'cache_status' => $withStatus > $n / 2 ? $cacheStatus : null, 'read' => strlen($text)];
}

// ---------------------------------------------------------------- server

function ac_server(): array {
  $out = ['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'pid' => getmypid(), 'user' => ac_whoami(), 'memory_limit' => ini_get('memory_limit')];
  if (is_readable('/proc/loadavg')) $out['load'] = array_map('floatval', array_slice(explode(' ', (string)file_get_contents('/proc/loadavg')), 0, 3));
  if (is_readable('/proc/cpuinfo')) $out['cpus'] = substr_count((string)file_get_contents('/proc/cpuinfo'), "\nprocessor") + 1;
  if (is_readable('/proc/meminfo') && preg_match_all('/^(MemTotal|MemAvailable|SwapTotal|SwapFree):\s+(\d+)/m', (string)file_get_contents('/proc/meminfo'), $m))
    $out['mem'] = array_map(fn($kb) => (int)$kb * 1024, array_combine($m[1], $m[2]));
  $out['disk_free'] = @disk_free_space('/') ?: null;
  $out['disk_total'] = @disk_total_space('/') ?: null;
  $out['env'] = [];
  foreach (['ML_APCU_CACHE', 'ML_CENSUS_PUBLISHED', 'ML_EDGE_HTML_TTL', 'ML_NO_CENSUS_CACHE', 'ML_PROFILE'] as $k) {
    $v = getenv($k);
    $out['env'][$k] = $v === false ? null : $v;
  }
  return $out;
}

// ---------------------------------------------------------------- formatting

function ac_bytes(int|float|null $b): string {
  if ($b === null) return '—';
  $u = ['B', 'KB', 'MB', 'GB', 'TB'];
  $i = 0;
  while (abs($b) >= 1024 && $i < 4) { $b /= 1024; $i++; }
  return ($i === 0 ? (string)(int)$b : number_format($b, $b >= 100 ? 0 : 1)) . ' ' . $u[$i];
}

function ac_dur(int|float|null $s): string {
  if ($s === null) return '—';
  $s = (int)round($s);
  $neg = $s < 0; $s = abs($s);
  $t = match (true) {
    $s < 60 => $s . 's',
    $s < 3600 => intdiv($s, 60) . 'm' . ($s % 60 ? ' ' . $s % 60 . 's' : ''),
    $s < 86400 => intdiv($s, 3600) . 'h' . (intdiv($s % 3600, 60) ? ' ' . intdiv($s % 3600, 60) . 'm' : ''),
    $s < 86400 * 400 => intdiv($s, 86400) . 'd' . (intdiv($s % 86400, 3600) ? ' ' . intdiv($s % 86400, 3600) . 'h' : ''),
    default => number_format($s / (86400 * 365.25), 1) . 'y',
  };
  return $neg ? '-' . $t : $t;
}

function ac_ago(?int $at): string {
  if (!$at) return '—';
  return '<time datetime="' . cs_h(gmdate('c', $at)) . '" title="' . cs_h(gmdate('D M j, Y H:i:s', $at)) . ' UTC">' . cs_h(ac_dur(time() - $at)) . ' ago</time>';
}

function ac_pct(?float $f, int $dp = 1): string {
  return $f === null ? '—' : number_format($f * 100, $dp) . '%';
}

function ac_meter(float $used, float $total, string $tone = ''): string {
  $f = $total > 0 ? max(0.0, min(1.0, $used / $total)) : 0.0;
  $tone = $tone ?: ($f > .9 ? 'bad' : ($f > .75 ? 'warn' : 'ok'));
  return '<div class="meter ' . $tone . '" role="img" aria-label="' . cs_h(ac_pct($f)) . ' used"><span style="width:' . round($f * 100, 1) . '%"></span></div>';
}

function ac_stat(string $value, string $label, string $tone = ''): string {
  return '<div class="stat' . ($tone ? " $tone" : '') . '"><strong>' . $value . '</strong><small>' . cs_h($label) . '</small></div>';
}

function ac_kv(array $rows): string {
  $html = '<dl class="kv">';
  foreach ($rows as $k => $v) $html .= '<dt>' . cs_h($k) . '</dt><dd>' . $v . '</dd>';
  return $html . '</dl>';
}

function ac_error(array $part): string {
  return '<p class="unavailable">' . cs_h($part['error']) . '</p>';
}

/** A small table of grouped counts with a share bar. */
function ac_group_table(array $groups, string $label, int $total, int $limit = 12): string {
  $html = '<table class="compact"><thead><tr><th>' . cs_h($label) . '</th><th class="num">Pages</th><th class="num">Size</th><th>Share</th></tr></thead><tbody>';
  foreach (array_slice($groups, 0, $limit, true) as $k => $g)
    $html .= '<tr><td>' . cs_h($k === '' ? '(empty)' : $k) . '</td><td class="num">' . number_format($g['n']) . '</td><td class="num">' . ac_bytes($g['bytes']) . '</td><td class="bar">' . ac_meter($g['n'], $total, 'info') . '</td></tr>';
  if (count($groups) > $limit) $html .= '<tr><td colspan="4" class="muted">… ' . (count($groups) - $limit) . ' more</td></tr>';
  return $html . '</tbody></table>';
}

function ac_sql(?array $meta): string {
  if (!$meta) return '<span class="muted">(stored before metadata was recorded)</span>';
  return '<code class="sql" title="' . cs_h($meta['sql']) . '">' . cs_h(mb_strimwidth($meta['sql'], 0, 120, '…')) . '</code>';
}

// ---------------------------------------------------------------- page

function render_admin_cache(string $nav, string $csrf, string $flash): string {
  $rescan = isset($_GET['rescan']);
  $lookup = trim((string)($_GET['uri'] ?? ''));
  $now = time();
  $server = ac_server();
  $apcu = ac_apcu();
  $opcache = ac_opcache();
  $realpath = ac_realpath();
  $nginx = ac_nginx($rescan);
  $rollout = ac_rollout();
  $procs = ac_processes();
  $pg = ac_postgres();
  $access = ac_access_log();
  $edgeTtl = max(0, (int)(getenv('ML_EDGE_HTML_TTL') ?: 900));

  // Page cache summaries.
  $pc = null;
  if (!isset($nginx['error'])) {
    $entries = $nginx['entries'];
    $current = array_values(array_filter($entries, fn($e) => $e['kind'] === 'current'));
    $publishedAt = $rollout['state']['published']['at'] ?? null;
    $censusBuilt = isset($pg['census']['built_at']) ? strtotime((string)$pg['census']['built_at']) : null;
    $dataAt = $publishedAt ?: ($censusBuilt ?: null);
    $releaseAt = $realpath['code_since'];
    $ages = ['< 1 hour' => 0, '1–6 hours' => 0, '6–24 hours' => 0, '1–7 days' => 0, '> 7 days' => 0];
    $expiry = ['expired (next visit re-renders in background)' => 0, 'within 1 hour' => 0, 'within 24 hours' => 0, 'later than 24 hours' => 0, 'more than 30 days (old 10-year entries)' => 0, 'unknown' => 0];
    $beforeData = 0; $beforeRelease = 0; $edgeOld = 0;
    foreach ($current as $e) {
      $age = $now - $e['saved'];
      $ages[$age < 3600 ? '< 1 hour' : ($age < 21600 ? '1–6 hours' : ($age < 86400 ? '6–24 hours' : ($age < 604800 ? '1–7 days' : '> 7 days')))]++;
      $left = $e['expires'] === null ? null : $e['expires'] - $now;
      $expiry[$left === null ? 'unknown' : ($left <= 0 ? 'expired (next visit re-renders in background)' : ($left < 3600 ? 'within 1 hour' : ($left < 86400 ? 'within 24 hours' : ($left < 30 * 86400 ? 'later than 24 hours' : 'more than 30 days (old 10-year entries)'))))]++;
      if ($dataAt && $e['saved'] < $dataAt) $beforeData++;
      if ($releaseAt && $e['saved'] < $releaseAt) $beforeRelease++;
      if ($e['skin'] === 'wow2008' && $e['accept'] === 'html' && $e['edge_ttl'] !== null && $e['edge_ttl'] !== $edgeTtl) $edgeOld++;
    }
    $oldest = $current; usort($oldest, fn($a, $b) => $a['saved'] <=> $b['saved']);
    $largest = $current; usort($largest, fn($a, $b) => $b['size'] <=> $a['size']);
    $match = [];
    if ($lookup !== '') foreach ($entries as $e) if ($e['uri'] === $lookup || str_starts_with($e['uri'], $lookup . '?') || ($lookup !== '/' && str_contains($e['uri'], $lookup))) $match[] = $e;
    usort($match, fn($a, $b) => [$a['uri'], $a['skin'], $a['accept']] <=> [$b['uri'], $b['skin'], $b['accept']]);
    $pc = [
      'total' => count($entries), 'bytes' => array_sum(array_column($entries, 'size')), 'current' => count($current),
      'kinds' => ac_group($entries, 'kind'), 'sections' => ac_group($current, 'section'), 'skins' => ac_group($current, fn($e) => $e['skin'] . ' · ' . $e['accept']),
      'status' => ac_group($current, 'status'), 'edge' => ac_group($current, fn($e) => $e['edge'] ?? '(none saved)'),
      'codes' => ac_group($current, 'code'), 'datas' => ac_group($current, 'data'),
      'ages' => $ages, 'expiry' => $expiry, 'before_data' => $beforeData, 'data_at' => $dataAt, 'before_release' => $beforeRelease, 'release_at' => $releaseAt,
      'edge_old' => $edgeOld, 'oldest' => array_slice($oldest, 0, 12), 'largest' => array_slice($largest, 0, 12), 'match' => array_slice($match, 0, 200), 'match_n' => count($match),
    ];
  }

  // Rollout state.
  // One line per job: a shell script's subshells show up as more processes with the same name.
  $running = [];
  foreach ($procs['list'] ?? [] as $p) if (in_array($p['what'], ['wowcensus-rollout', 'wowcensus-release-rollout', 'wowcensus-data-refresh', 'build-census-cache.php'], true)
    && (!isset($running[$p['what']]) || $p['elapsed'] > $running[$p['what']]['elapsed'])) $running[$p['what']] = $p;
  $running = array_values($running);
  $rolloutRunning = (bool)array_filter($running, fn($p) => $p['what'] === 'wowcensus-rollout');
  $progress = $rollout['last_progress'] ?? null;
  $start = $rollout['last_start'] ?? null;
  $inFlight = $start && (!($rollout['last_done'] ?? null) || $rollout['last_done']['at'] < $start['at']) && (!($rollout['last_stop'] ?? null) || $rollout['last_stop']['at'] < $start['at']);

  // Headline numbers.
  $strip = '';
  if ($pc) $strip .= ac_stat(number_format($pc['current']), 'cached pages · ' . ac_bytes($pc['bytes']));
  if ($pc && $pc['data_at']) $strip .= ac_stat(number_format($pc['before_data']), 'rendered before the last data publish', $pc['before_data'] ? 'warn' : 'good');
  if (!isset($apcu['error'])) $strip .= ac_stat(ac_pct($apcu['hit_rate']), 'APCu hit rate · ' . ac_pct(($apcu['total'] - $apcu['free']) / max(1, $apcu['total']), 0) . ' full');
  if (!isset($opcache['error'])) $strip .= ac_stat(ac_pct(($opcache['status']['opcache_statistics']['opcache_hit_rate'] ?? 0) / 100, 2), 'OPcache hit rate');
  if (!isset($pg['error']) && ($pg['db']['blks_hit'] ?? 0) + ($pg['db']['blks_read'] ?? 0) > 0)
    $strip .= ac_stat(ac_pct($pg['db']['blks_hit'] / ($pg['db']['blks_hit'] + $pg['db']['blks_read']), 2), 'PostgreSQL buffer hits');
  if (!isset($pg['error']) && isset($pg['census']['pending'])) $strip .= ac_stat(number_format((int)$pg['census']['pending']), 'imports waiting to publish', $pg['census']['pending'] ? 'warn' : 'good');
  $strip .= ac_stat($rolloutRunning || $inFlight ? ($progress && $start && $progress['at'] >= $start['at'] ? number_format($progress['n']) . ' / ' . number_format($progress['total']) : 'running') : 'idle', 'rollout', $rolloutRunning ? 'info' : '');
  if (isset($server['load'])) $strip .= ac_stat(number_format($server['load'][0], 2), 'load (1 min)' . (isset($server['cpus']) ? ' · ' . $server['cpus'] . ' CPU' : ''), $server['load'][0] > 1.5 ? 'warn' : '');

  $self = '/admin/?view=cache';
  ob_start();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Cache stats · WoWCensus</title>
<style>
:root{color-scheme:dark;font-family:system-ui,sans-serif;background:#101720;color:#edf3fa}
*{box-sizing:border-box}body{max-width:1400px;margin:auto;padding:28px 24px 64px}
a{color:#a9d4ff}.back{font-size:.88rem;text-decoration:none}.eyebrow{margin:26px 0 5px;color:#8ea3b9;text-transform:uppercase;letter-spacing:.14em;font-size:.72rem;font-weight:700}
.view-nav{display:flex;gap:12px;flex-wrap:wrap;margin:12px 0 22px}.view-nav a{font-size:.85rem;text-decoration:none;padding:7px 12px;border:1px solid #344759;border-radius:7px}.view-nav a[aria-current=page]{background:#456e96;color:white}
.chrome{display:flex;justify-content:space-between;align-items:center;gap:12px}.chrome form{margin:0}.chrome button{font:inherit;font-size:.85rem;background:#1b2936;color:#b9cbdc;border:1px solid #344759;border-radius:7px;padding:6px 12px;cursor:pointer}.chrome button:hover{color:white;border-color:#456e96}
h1{font-size:clamp(1.8rem,4vw,2.8rem);margin:0 0 8px;letter-spacing:-.035em}h2{font-size:1.15rem;margin:0 0 4px}h3{font-size:.8rem;margin:18px 0 8px;color:#a8b9ca;text-transform:uppercase;letter-spacing:.08em}
.intro{color:#a8b9ca;line-height:1.5;margin:0 0 18px;max-width:900px}.muted{color:#8299ae}
.flash{background:#1d3a2c;border:1px solid #2f6b4c;color:#c9f2db;border-radius:9px;padding:10px 14px;margin:0 0 18px}
.strip{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;margin-bottom:22px}
.stat{background:#1a2734;border:1px solid #344759;border-radius:11px;padding:12px 14px}.stat strong{font-size:1.3rem;font-variant-numeric:tabular-nums;display:block}.stat small{display:block;color:#9db0c2;margin-top:3px;font-size:.78rem}
.stat.warn{border-color:#7a6326}.stat.warn strong{color:#f5d68b}.stat.good strong{color:#9fe3bd}.stat.info{border-color:#456e96}.stat.info strong{color:#a9d4ff}
.jump{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 20px;font-size:.82rem}.jump a{text-decoration:none;padding:4px 10px;border-radius:999px;background:#1b2936;border:1px solid #2b3b4b}
section.card{background:#16212c;border:1px solid #2e4153;border-radius:13px;padding:18px 20px;margin:0 0 18px}
.card-head{display:flex;justify-content:space-between;gap:12px;align-items:baseline;flex-wrap:wrap;margin-bottom:10px}.card-head p{margin:0;color:#94a9bd;font-size:.85rem}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px 26px}
.kv{display:grid;grid-template-columns:max-content 1fr;gap:5px 14px;margin:0;font-size:.86rem}.kv dt{color:#93a8bc}.kv dd{margin:0;font-variant-numeric:tabular-nums;word-break:break-word}
.meter{height:8px;background:#0f1822;border-radius:5px;overflow:hidden;min-width:80px}.meter span{display:block;height:100%;background:#4fae7f}.meter.warn span{background:#d1a840}.meter.bad span{background:#d0545c}.meter.info span{background:#4f86ba}
.mem{margin:4px 0 14px}.mem .meter{height:12px}.mem p{margin:5px 0 0;font-size:.82rem;color:#a8b9ca}
table{width:100%;border-collapse:collapse;font-size:.83rem}th,td{text-align:left;vertical-align:top;padding:6px 9px;border-top:1px solid #253545}th{font-size:.7rem;color:#8fa5ba;font-weight:600;border-top:0;text-transform:uppercase;letter-spacing:.04em}
td.num,th.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}td.bar{width:28%}td.uri{word-break:break-all;font-family:ui-monospace,monospace;font-size:.78rem}
code,.mono{font-family:ui-monospace,monospace;font-size:.78rem}code.sql{color:#c6d6e6}
.unavailable{color:#c0a882;background:#211d16;border:1px dashed #5a4a2d;border-radius:8px;padding:9px 12px;font-size:.85rem;margin:0}
.badge{display:inline-block;font-size:.68rem;font-weight:700;border-radius:5px;padding:1px 6px;vertical-align:1px}.badge.run{background:#1f3e5c;color:#a9d4ff}.badge.ok{background:#1d3a2c;color:#9fe3bd}.badge.warn{background:#4d4019;color:#f5dd9b}.badge.bad{background:#5c2328;color:#ffc3c7}
.log{background:#0d141b;border:1px solid #253545;border-radius:8px;padding:10px 12px;font-family:ui-monospace,monospace;font-size:.74rem;line-height:1.5;max-height:340px;overflow:auto;white-space:pre-wrap;word-break:break-all;color:#b8c8d8}
.bars{display:flex;align-items:flex-end;gap:2px;height:70px;margin:6px 0 2px}.bars span{flex:1;background:#4f86ba;border-radius:2px 2px 0 0;min-height:1px}.bars span:hover{background:#a9d4ff}
.axis{display:flex;justify-content:space-between;font-size:.7rem;color:#7f95a9}
form.inline{display:inline;margin:0}button,input{font:inherit;font-size:.82rem;background:#1b2936;color:#d4e0eb;border:1px solid #344759;border-radius:6px;padding:6px 11px}button{cursor:pointer}button:hover{border-color:#6f9cc6;color:white}
.lookup{display:flex;gap:8px;margin:0 0 12px}.lookup input{flex:1;max-width:420px}
.scroll{overflow-x:auto}
@media(max-width:800px){body{padding:18px 12px 48px}section.card{padding:14px}.grid{grid-template-columns:1fr}td.bar{display:none}}
</style></head><body>
<?= $nav ?>
<p class="eyebrow">Infrastructure</p><h1>Cache stats</h1>
<p class="intro">Every cache between a visitor and PostgreSQL, read on this server just now: Cloudflare's edge (as seen from the origin), nginx's page cache, the rollouts that re-render it, APCu's query results, PHP's code caches and PostgreSQL's census tables and buffers. Read only, except the one APCu clean-up button.</p>
<?php if ($flash !== ''): ?><p class="flash" role="status"><?= cs_h($flash) ?></p><?php endif; ?>
<div class="strip"><?= $strip ?></div>
<nav class="jump" aria-label="Sections"><a href="#pages">Page cache</a><a href="#rollout">Rollouts</a><a href="#edge">Cloudflare &amp; origin traffic</a><a href="#apcu">APCu queries</a><a href="#php">OPcache &amp; realpath</a><a href="#pg">PostgreSQL</a><a href="#server">Server</a></nav>

<section class="card" id="pages">
  <div class="card-head"><h2>nginx page cache</h2>
    <p><?php if (!isset($nginx['error'])): ?>Scanned <?= number_format(count($nginx['entries'])) ?> files in <?= number_format($nginx['scan_ms']) ?> ms, <?= ac_ago($nginx['scanned_at']) ?><?= $nginx['reused'] ? ' (reused)' : '' ?> · <a href="<?= cs_h($self . '&rescan=1' . ($lookup !== '' ? '&uri=' . rawurlencode($lookup) : '')) ?>">Rescan now</a><?php endif; ?></p></div>
<?php if (isset($nginx['error'])): ?><?= ac_error($nginx) ?>
<?php else: ?>
  <?php if ($nginx['truncated']): ?><p class="unavailable">The scan stopped early (<?= number_format(AC_SCAN_MAX_FILES) ?> files or <?= AC_SCAN_BUDGET ?> s); numbers cover what it read.</p><?php endif; ?>
  <div class="grid">
    <div>
      <?= ac_kv([
        'Entries' => number_format($pc['total']) . ' <span class="muted">(' . number_format($pc['current']) . ' current for wowcensus.io; retired ones are never served)</span>',
        'On disk' => ac_bytes($pc['bytes']) . ($nginx['disk_total'] ? ' <span class="muted">· disk ' . ac_bytes($nginx['disk_free']) . ' free of ' . ac_bytes($nginx['disk_total']) . '</span>' : ''),
        'Last data publish' => $pc['data_at'] ? ac_ago($pc['data_at']) . ' · <strong>' . number_format($pc['before_data']) . '</strong> pages rendered before it' : '<span class="muted">unknown</span>',
        'Release went live' => $pc['release_at'] ? ac_ago($pc['release_at']) . ' · <strong>' . number_format($pc['before_release']) . '</strong> pages rendered before it' : '<span class="muted">unknown</span>',
        'Edge TTL in saved pages' => $pc['edge_old'] ? '<span class="badge warn">' . number_format($pc['edge_old']) . '</span> default-skin pages still carry a TTL other than ' . number_format($edgeTtl) . ' s' : '<span class="badge ok">all match</span> ' . number_format($edgeTtl) . ' s',
        'Unreadable files' => number_format($nginx['unreadable']),
      ]) ?>
      <h3>Rendered (age)</h3>
      <table class="compact"><tbody><?php foreach ($pc['ages'] as $k => $n): ?><tr><td><?= cs_h($k) ?></td><td class="num"><?= number_format($n) ?></td><td class="bar"><?= ac_meter($n, max(1, $pc['current']), 'info') ?></td></tr><?php endforeach; ?></tbody></table>
      <h3>nginx expiry (fastcgi_cache_valid)</h3>
      <table class="compact"><tbody><?php foreach ($pc['expiry'] as $k => $n): if (!$n && $k === 'unknown') continue; ?><tr><td><?= cs_h($k) ?></td><td class="num"><?= number_format($n) ?></td><td class="bar"><?= ac_meter($n, max(1, $pc['current']), 'info') ?></td></tr><?php endforeach; ?></tbody></table>
    </div>
    <div>
      <h3 style="margin-top:0">By section</h3><?= ac_group_table($pc['sections'], 'Section', $pc['current']) ?>
      <h3>By skin · format</h3><?= ac_group_table($pc['skins'], 'Variant', $pc['current']) ?>
    </div>
    <div>
      <h3 style="margin-top:0">Key formats</h3><?= ac_group_table($pc['kinds'], 'Kind', $pc['total']) ?>
      <h3>HTTP status saved</h3><?= ac_group_table($pc['status'], 'Status', $pc['current']) ?>
      <h3>Cloudflare header saved</h3><?= ac_group_table($pc['edge'], 'Cloudflare-CDN-Cache-Control', $pc['current'], 6) ?>
      <h3>Code / data key parts</h3><?= ac_group_table($pc['codes'], 'Code key', $pc['current'], 4) ?><?= ac_group_table($pc['datas'], 'Data key', $pc['current'], 4) ?>
    </div>
  </div>
  <h3>Look up a page</h3>
  <form class="lookup" method="get" action="/admin/"><input type="hidden" name="view" value="cache"><input name="uri" value="<?= cs_h($lookup) ?>" placeholder="/forever/realms" aria-label="Path to look up"><button type="submit">Find cache entries</button></form>
  <?php if ($lookup !== ''): ?>
    <?php if (!$pc['match']): ?><p class="muted">No cache entries match <?= cs_h($lookup) ?> — its next visit renders it fresh.</p><?php else: ?>
    <div class="scroll"><table><thead><tr><th>URI</th><th>Skin</th><th>Format</th><th>Rendered</th><th>nginx expiry</th><th class="num">Size</th><th>Status</th><th>Edge header</th><th>File</th></tr></thead><tbody>
    <?php foreach ($pc['match'] as $e): ?><tr><td class="uri"><?= cs_h($e['uri']) ?><?= $e['kind'] !== 'current' ? ' <span class="badge warn">' . cs_h($e['kind']) . '</span>' : '' ?></td><td><?= cs_h($e['skin']) ?></td><td><?= cs_h($e['accept']) ?></td><td><?= ac_ago($e['saved']) ?></td><td><?= $e['expires'] === null ? '—' : ($e['expires'] <= $now ? '<span class="badge warn">expired</span>' : 'in ' . cs_h(ac_dur($e['expires'] - $now))) ?></td><td class="num"><?= ac_bytes($e['size']) ?></td><td><?= (int)$e['status'] ?></td><td class="mono"><?= cs_h((string)$e['edge']) ?></td><td class="mono"><?= cs_h(substr($e['file'], -8)) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php if ($pc['match_n'] > 200): ?><p class="muted">First 200 of <?= number_format($pc['match_n']) ?>.</p><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
  <div class="grid">
    <div><h3>Longest since re-render</h3><table><thead><tr><th>URI</th><th>Variant</th><th>Rendered</th></tr></thead><tbody>
      <?php foreach ($pc['oldest'] as $e): ?><tr><td class="uri"><a href="<?= cs_h($self . '&uri=' . rawurlencode($e['uri'])) ?>"><?= cs_h($e['uri']) ?></a></td><td><?= cs_h($e['skin'] . ' · ' . $e['accept']) ?></td><td><?= ac_ago($e['saved']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div><h3>Largest</h3><table><thead><tr><th>URI</th><th>Variant</th><th class="num">Size</th></tr></thead><tbody>
      <?php foreach ($pc['largest'] as $e): ?><tr><td class="uri"><a href="<?= cs_h($self . '&uri=' . rawurlencode($e['uri'])) ?>"><?= cs_h($e['uri']) ?></a></td><td><?= cs_h($e['skin'] . ' · ' . $e['accept']) ?></td><td class="num"><?= ac_bytes($e['size']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
<?php endif; ?>
</section>

<section class="card" id="rollout">
  <div class="card-head"><h2>Publishes and rollouts</h2><p>wowcensus-data-refresh → census tables → wowcensus-rollout re-renders every cached page in place</p></div>
  <div class="grid">
    <div>
      <?php
      $rows = [];
      if (isset($procs['error'])) $rows['Running jobs'] = '<span class="muted">' . cs_h($procs['error']) . '</span>';
      else $rows['Running jobs'] = $running ? implode('<br>', array_map(fn($p) => '<span class="badge run">' . cs_h($p['what']) . '</span> pid ' . $p['pid'] . ' · ' . cs_h(ac_dur($p['elapsed'])), $running)) : '<span class="badge ok">none</span>';
      if (isset($rollout['state']['published'])) $rows['Published data'] = '<span class="mono">' . cs_h($rollout['state']['published']['value']) . '</span> · ' . ac_ago($rollout['state']['published']['at']);
      if (isset($rollout['state']['failed'])) $rows['Last failure'] = '<span class="badge bad">failed</span> ' . ac_ago($rollout['state']['failed']['at']) . ' (retried after 30 min)';
      if (!isset($rollout['error'])) {
        if ($start) $rows['Last rollout started'] = ac_ago($start['at']) . ' · ' . number_format($start['total']) . ' pages';
        if ($inFlight && $progress && $progress['at'] >= $start['at']) {
          $rate = $progress['secs'] > 0 ? $progress['n'] / $progress['secs'] : 0;
          $rows['Progress'] = ac_meter($progress['n'], max(1, $progress['total']), 'info') . number_format($progress['n']) . ' / ' . number_format($progress['total'])
            . ' · ' . number_format($progress['failed']) . ' failed' . ($rate > 0 ? ' · ~' . cs_h(ac_dur(($progress['total'] - $progress['n']) / $rate)) . ' left at ' . number_format(1 / $rate, 1) . ' s/page' : '');
        }
        if ($inFlight && !$rolloutRunning && !isset($procs['error'])) $rows['Note'] = '<span class="badge warn">no rollout process</span> the log shows one started but not finished: it was stopped or killed.';
        if ($rollout['last_done'] ?? null) {
          $d = $rollout['last_done'];
          $rows['Last finished'] = ac_ago($d['at']) . ' · ' . number_format($d['ok']) . ' ok, ' . number_format($d['failed']) . ' failed of ' . number_format($d['total']) . ' in ' . cs_h(ac_dur($d['secs']));
          $rows['Cloudflare purges'] = ($d['purged'] === null ? 'not logged by this rollout script' : number_format($d['purged']) . ' URLs in the last rollout') . ($rollout['purge_failures'] ? ' · <span class="badge bad">' . $rollout['purge_failures'] . ' purge calls failed</span> in the log' : '');
        }
        if ($rollout['last_stop'] ?? null) $rows['Last early stop'] = ac_ago($rollout['last_stop']['at']) . ' · ' . cs_h($rollout['last_stop']['msg']);
        if ($rollout['last_census'] ?? null) $rows['Census rebuild'] = ac_ago($rollout['last_census']['at']) . ' · took ' . cs_h(ac_dur($rollout['last_census']['secs']));
      }
      echo ac_kv($rows);
      ?>
      <?php if (!empty($rollout['history'])): ?><h3>Recent rollouts</h3><table><thead><tr><th>Finished</th><th class="num">Pages</th><th class="num">Failed</th><th class="num">Purged</th><th class="num">Took</th><th class="num">s/page</th></tr></thead><tbody>
        <?php foreach (array_reverse($rollout['history']) as $d): ?><tr><td><?= ac_ago($d['at']) ?></td><td class="num"><?= number_format($d['total']) ?></td><td class="num"><?= number_format($d['failed']) ?></td><td class="num"><?= $d['purged'] === null ? '—' : number_format($d['purged']) ?></td><td class="num"><?= cs_h(ac_dur($d['secs'])) ?></td><td class="num"><?= $d['total'] ? number_format($d['secs'] / $d['total'], 2) : '—' ?></td></tr><?php endforeach; ?>
      </tbody></table><?php endif; ?>
    </div>
    <div><h3 style="margin-top:0">Refresh log (last lines)</h3>
      <?php if (isset($rollout['error'])): ?><?= ac_error($rollout) ?><?php else: ?><div class="log"><?= cs_h(implode("\n", $rollout['tail'])) ?></div><?php endif; ?>
    </div>
  </div>
</section>

<section class="card" id="edge">
  <div class="card-head"><h2>Cloudflare and origin traffic</h2><p>The origin only sees what the edge did not answer: misses, expiries, bypassed skins and per-visitor routes.</p></div>
  <div class="grid">
    <div><?= ac_kv([
      'Edge TTL (default skin HTML)' => number_format($edgeTtl) . ' s (' . cs_h(ac_dur($edgeTtl)) . ')' . ($edgeTtl > 900 ? ' · relies on rollout purges' : ''),
      'Other skins, Markdown' => 'never edge-cached (no-store)',
      'CSS' => 'browser 5 min · edge 1 h',
      'Other files' => 'browser 1 min · edge 5 min',
    ]) ?></div>
    <div>
    <?php if (isset($access['error'])): ?><?= ac_error($access) ?>
    <?php else: $span = max(1, $access['last'] - $access['first']); ?>
      <?= ac_kv([
        'Window read' => cs_h(ac_dur($span)) . ' (' . ac_bytes($access['read']) . ' of the log), up to ' . ac_ago($access['last']),
        'Origin requests' => number_format($access['n']) . ' · ' . number_format($access['n'] / ($span / 60), 1) . '/min',
        'From 127.0.0.1 (rollouts)' => number_format($access['local']) . ' (' . ac_pct($access['local'] / $access['n']) . ')',
        'Bots and crawlers' => number_format($access['bots']) . ' (' . ac_pct($access['bots'] / $access['n']) . ')',
        'Status classes' => implode(' · ', array_map(fn($k, $v) => cs_h($k) . ' ' . number_format($v), array_keys($access['codes']), $access['codes'])),
        'Bytes sent' => ac_bytes($access['bytes']),
        'nginx cache status' => $access['cache_status'] ? implode(' · ', array_map(fn($k, $v) => cs_h($k) . ' ' . number_format($v) . ' (' . ac_pct($v / array_sum($access['cache_status']), 0) . ')', array_keys($access['cache_status']), $access['cache_status']))
          : '<span class="muted">not in the log; add <code>$upstream_cache_status</code> to the log_format to see hits and misses</span>',
      ]) ?>
    <?php endif; ?>
    </div>
  </div>
  <?php if (!isset($access['error'])): $max = max($access['hours']); ?>
  <div class="grid">
    <div><h3>Origin requests per hour</h3>
      <div class="bars"><?php foreach ($access['hours'] as $h => $n): ?><span style="height:<?= round($n / max(1, $max) * 100, 1) ?>%" title="<?= cs_h(gmdate('M j H:00', $h)) ?> UTC: <?= number_format($n) ?>"></span><?php endforeach; ?></div>
      <div class="axis"><span><?= cs_h(gmdate('M j H:00', (int)array_key_first($access['hours']))) ?></span><span>peak <?= number_format($max) ?>/h</span><span><?= cs_h(gmdate('M j H:00', (int)array_key_last($access['hours']))) ?> UTC</span></div></div>
    <div><h3>Most requested at the origin (GET, not rollouts)</h3><table><tbody>
      <?php foreach ($access['paths'] as $p => $n): ?><tr><td class="uri"><a href="<?= cs_h($self . '&uri=' . rawurlencode((string)$p)) ?>"><?= cs_h((string)$p) ?></a></td><td class="num"><?= number_format($n) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
  <?php endif; ?>
</section>

<section class="card" id="apcu">
  <div class="card-head"><h2>APCu query cache</h2><p>Db.php keeps every query's rows here, keyed by the published data version, for 12 hours</p></div>
<?php if (isset($apcu['error'])): ?><?= ac_error($apcu) ?>
<?php else: $i = $apcu['info']; $used = $apcu['total'] - $apcu['free']; $old = array_filter($apcu['versions'], fn($k) => $k !== $apcu['current'], ARRAY_FILTER_USE_KEY); ?>
  <div class="mem"><?= ac_meter($used, $apcu['total']) ?><p><?= ac_bytes($used) ?> used of <?= ac_bytes($apcu['total']) ?> · <?= ac_bytes($apcu['free']) ?> free in <?= number_format($apcu['free_blocks']) ?> blocks (largest <?= ac_bytes($apcu['largest_free']) ?>) · fragmentation <?= ac_pct($apcu['fragmentation']) ?></p></div>
  <div class="grid">
    <div><?= ac_kv([
      'Hit rate' => ac_pct($apcu['hit_rate'], 2) . ' <span class="muted">(' . number_format((int)$i['num_hits']) . ' hits, ' . number_format((int)$i['num_misses']) . ' misses)</span>',
      'Entries' => number_format((int)$i['num_entries']) . ' · ' . number_format((int)$i['num_inserts']) . ' inserts since start',
      'Expunges' => (int)$i['expunges'] ? '<span class="badge warn">' . number_format((int)$i['expunges']) . '</span> the cache filled up and was emptied' : '<span class="badge ok">0</span>',
      'Up since' => ac_ago((int)$i['start_time']) . ' (PHP-FPM restart empties it)',
      'Settings' => 'shm ' . cs_h((string)$apcu['config']['shm_size']) . ' · ttl ' . cs_h((string)$apcu['config']['ttl']) . ' · gc_ttl ' . cs_h((string)$apcu['config']['gc_ttl']),
    ]) ?></div>
    <div><?= ac_kv([
      'Current data version' => $apcu['current'] !== null ? '<span class="mono">' . cs_h($apcu['current']) . '</span>' : '<span class="muted">unknown</span>',
      'Its query results' => number_format($apcu['live']) . ' · ' . ac_bytes($apcu['live_bytes']) . ' · read ' . number_format($apcu['live_hits']) . ' times',
      'Never read again' => number_format($apcu['versions'][$apcu['current']]['unread'] ?? 0) . ' of them (computed for one render only)',
      'Time saved (est.)' => $apcu['with_meta'] ? cs_h(ac_dur($apcu['saved_ms'] / 1000)) . ' of query time across ' . number_format($apcu['with_meta']) . ' timed entries' : '<span class="muted">no timed entries yet</span>',
      'Computing now' => $apcu['locks'] ? implode('<br>', array_map(fn($l) => '<span class="badge run">' . cs_h(substr($l['sha'], 0, 10)) . '</span> for ' . cs_h(ac_dur(time() - $l['since'])) . ' ' . ac_sql(apcu_fetch('ml:qm:' . $l['sha']) ?: null), $apcu['locks'])) : '<span class="badge ok">nothing</span>',
      'Other keys' => number_format($apcu['other']['n']) . ' · ' . ac_bytes($apcu['other']['bytes']),
    ]) ?></div>
  </div>
  <h3>By data version</h3>
  <table><thead><tr><th>Version</th><th class="num">Entries</th><th class="num">Size</th><th class="num">Reads</th><th>Newest</th><th></th></tr></thead><tbody>
    <?php foreach ($apcu['versions'] as $v => $g): ?><tr><td class="mono"><?= cs_h((string)$v) ?></td><td class="num"><?= number_format($g['n']) ?></td><td class="num"><?= ac_bytes($g['bytes']) ?></td><td class="num"><?= number_format($g['hits']) ?></td><td><?= ac_ago($g['newest']) ?></td><td><?= (string)$v === $apcu['current'] ? '<span class="badge ok">current</span>' : '<span class="badge warn">dead</span>' ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php if ($old): ?><form class="inline" method="post" action="/admin/?view=cache" data-confirm="Drop every query result from older data versions? Nothing reads them."><input type="hidden" name="csrf" value="<?= cs_h($csrf) ?>"><input type="hidden" name="action" value="cache-apcu-drop-old"><p><button type="submit">Free <?= ac_bytes(array_sum(array_column($old, 'bytes'))) ?>: drop <?= number_format(array_sum(array_column($old, 'n'))) ?> dead entries</button> <span class="muted">Only entries for older data versions; the current ones stay.</span></p></form><?php endif; ?>
  <div class="grid">
    <div class="scroll"><h3>Most read</h3><table><thead><tr><th>Query</th><th class="num">Reads</th><th class="num">Size</th><th class="num">Cost</th></tr></thead><tbody>
      <?php foreach ($apcu['top_hits'] as $e): ?><tr><td><?= ac_sql($e['meta']) ?></td><td class="num"><?= number_format($e['hits']) ?></td><td class="num"><?= ac_bytes($e['bytes']) ?></td><td class="num"><?= $e['meta'] ? number_format($e['meta']['ms']) . ' ms' : '—' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="scroll"><h3>Slowest to compute</h3><table><thead><tr><th>Query</th><th class="num">Cost</th><th class="num">Rows</th><th class="num">Reads</th></tr></thead><tbody>
      <?php if (!$apcu['slow']): ?><tr><td colspan="4" class="muted">Timings are recorded for queries cached from now on.</td></tr><?php endif; ?>
      <?php foreach ($apcu['slow'] as $e): ?><tr><td><?= ac_sql($e['meta']) ?></td><td class="num"><?= number_format($e['meta']['ms']) ?> ms</td><td class="num"><?= number_format($e['meta']['rows']) ?></td><td class="num"><?= number_format($e['hits']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="scroll"><h3>Largest</h3><table><thead><tr><th>Query</th><th class="num">Size</th><th class="num">Reads</th></tr></thead><tbody>
      <?php foreach ($apcu['top_bytes'] as $e): ?><tr><td><?= ac_sql($e['meta']) ?></td><td class="num"><?= ac_bytes($e['bytes']) ?></td><td class="num"><?= number_format($e['hits']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
<?php endif; ?>
</section>

<section class="card" id="php">
  <div class="card-head"><h2>OPcache and realpath cache</h2><p>Compiled PHP and resolved paths, per PHP-FPM master. After a release, pages render old code until the realpath entry for current expires.</p></div>
  <div class="grid">
    <div>
    <?php if (isset($opcache['error'])): ?><?= ac_error($opcache) ?>
    <?php else: $s = $opcache['status']; $m = $s['memory_usage']; $st = $s['opcache_statistics']; $d = $opcache['directives']; $ist = $s['interned_strings_usage'] ?? null; $mtotal = $m['used_memory'] + $m['free_memory'] + $m['wasted_memory']; ?>
      <div class="mem"><?= ac_meter($m['used_memory'] + $m['wasted_memory'], $mtotal) ?><p><?= ac_bytes($m['used_memory']) ?> used · <?= ac_bytes($m['wasted_memory']) ?> wasted (<?= number_format($m['current_wasted_percentage'], 1) ?>%) · <?= ac_bytes($m['free_memory']) ?> free</p></div>
      <?= ac_kv([
        'Hit rate' => number_format($st['opcache_hit_rate'], 3) . '% <span class="muted">(' . number_format($st['hits']) . ' hits, ' . number_format($st['misses']) . ' misses)</span>',
        'Scripts' => number_format($st['num_cached_scripts']) . ' cached · ' . number_format($st['num_cached_keys']) . ' of ' . number_format($st['max_cached_keys']) . ' keys',
        'Restarts' => 'out of memory ' . (int)$st['oom_restarts'] . ' · hash ' . (int)$st['hash_restarts'] . ' · manual ' . (int)$st['manual_restarts'],
        'Interned strings' => $ist ? ac_bytes($ist['used_memory']) . ' of ' . ac_bytes($ist['buffer_size']) : '—',
        'Up since' => ac_ago((int)$st['start_time']) . ($st['last_restart_time'] ? ' · last restart ' . ac_ago((int)$st['last_restart_time']) : ''),
        'Timestamps' => !empty($d['opcache.validate_timestamps']) ? 'checked every ' . (int)($d['opcache.revalidate_freq'] ?? 2) . ' s' : '<span class="badge warn">not checked</span> new code needs a PHP-FPM reload',
        'JIT' => !empty($s['jit']['on']) ? 'on · buffer ' . ac_bytes($s['jit']['buffer_size'] ?? 0) . ', ' . ac_bytes($s['jit']['buffer_free'] ?? 0) . ' free' : 'off',
      ]) ?>
    <?php endif; ?>
    </div>
    <div><?= ac_kv([
      'This code' => '<span class="mono">' . cs_h($realpath['code']) . '</span>',
      'current →' => $realpath['link'] !== null ? '<span class="mono">' . cs_h($realpath['link']) . '</span>' : '<span class="muted">no /srv/wowcensus/current here</span>',
      'Worker sees current as' => $realpath['cached_target'] !== null
        ? '<span class="mono">' . cs_h($realpath['cached_target']) . '</span> ' . ($realpath['link'] !== null && basename($realpath['cached_target']) !== basename($realpath['link']) ? '<span class="badge warn">stale</span>' : '<span class="badge ok">matches</span>') . ' · expires in ' . cs_h(ac_dur(($realpath['cached_expires'] ?? time()) - time()))
        : '<span class="muted">not in this worker\'s cache</span>',
      'Realpath cache' => ac_bytes($realpath['size']) . ' of ' . cs_h((string)$realpath['limit']) . ' · ' . number_format($realpath['entries']) . ' entries · ttl ' . $realpath['ttl'] . ' s',
    ]) ?></div>
  </div>
</section>

<section class="card" id="pg">
  <div class="card-head"><h2>PostgreSQL</h2><p>Census tables (pre-computed per publish) and the database's own buffer cache</p></div>
<?php if (isset($pg['error'])): ?><?= ac_error($pg) ?>
<?php else: $c = $pg['census']; $db = $pg['db']; $settings = array_column($pg['settings'], null, 'name'); ?>
  <div class="grid">
    <div><?= ac_kv([
      'Census tables built for' => $c ? '<span class="mono">' . cs_h((string)$c['version']) . '</span>' : '<span class="badge warn">no census tables</span>',
      'Built' => $c ? ac_ago(strtotime((string)$c['built_at']) ?: null) : '—',
      'Newest import' => '<span class="mono">' . cs_h((string)($c['latest'] ?? '—')) . '</span>',
      'Waiting to publish' => $c ? ((int)$c['pending'] ? '<span class="badge warn">' . number_format((int)$c['pending']) . ' imports</span> newer than the census tables' : '<span class="badge ok">up to date</span>') : '—',
      'Census tables' => number_format(count($pg['tables'])) . ' · ' . ac_bytes(array_sum(array_column($pg['tables'], 'bytes'))),
    ]) ?></div>
    <div><?= ac_kv([
      'Database' => cs_h((string)$db['name']) . ' · ' . ac_bytes((int)$db['bytes']),
      'Buffer hit rate' => ac_pct(($db['blks_hit'] + $db['blks_read']) ? $db['blks_hit'] / ($db['blks_hit'] + $db['blks_read']) : null, 2) . ' <span class="muted">(' . number_format((int)$db['blks_read']) . ' blocks read from disk)</span>',
      'Temp files' => number_format((int)$db['temp_files']) . ' · ' . ac_bytes((int)$db['temp_bytes']) . ' <span class="muted">(queries that outgrew work_mem)</span>',
      'Transactions' => number_format((int)$db['xact_commit']) . ' committed · ' . number_format((int)$db['xact_rollback']) . ' rolled back · ' . (int)$db['deadlocks'] . ' deadlocks',
      'Connections' => number_format((int)$db['numbackends']) . (isset($settings['max_connections']) ? ' of ' . cs_h($settings['max_connections']['setting']) : ''),
      'Memory settings' => implode(' · ', array_map(fn($k) => cs_h($k) . ' ' . (isset($settings[$k]) ? ac_bytes((int)$settings[$k]['setting'] * ($settings[$k]['unit'] === '8kB' ? 8192 : ($settings[$k]['unit'] === 'kB' ? 1024 : 1))) : '—'), ['shared_buffers', 'effective_cache_size', 'work_mem'])),
      'Stats since' => cs_h((string)($db['stats_reset'] ?? 'server start')),
    ]) ?></div>
  </div>
  <div class="grid">
    <div class="scroll"><h3>Census tables</h3><table><thead><tr><th>Table</th><th class="num">Rows (est.)</th><th class="num">Size</th></tr></thead><tbody>
      <?php foreach ($pg['tables'] as $t): ?><tr><td class="mono"><?= cs_h($t['name']) ?></td><td class="num"><?= number_format(max(0, (int)$t['est_rows'])) ?></td><td class="num"><?= ac_bytes((int)$t['bytes']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="scroll"><h3>Most disk reads</h3><table><thead><tr><th>Table</th><th class="num">Heap read</th><th class="num">Heap hit %</th><th class="num">Index read</th><th class="num">Index hit %</th></tr></thead><tbody>
      <?php foreach ($pg['io'] as $t): ?><tr><td class="mono"><?= cs_h($t['name']) ?></td><td class="num"><?= number_format((int)$t['heap_blks_read']) ?></td><td class="num"><?= ac_pct(($t['heap_blks_hit'] + $t['heap_blks_read']) ? $t['heap_blks_hit'] / ($t['heap_blks_hit'] + $t['heap_blks_read']) : null) ?></td><td class="num"><?= number_format((int)$t['idx_blks_read']) ?></td><td class="num"><?= ac_pct(($t['idx_blks_hit'] + $t['idx_blks_read']) ? $t['idx_blks_hit'] / ($t['idx_blks_hit'] + $t['idx_blks_read']) : null) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
  <?php if ($pg['active']): ?><h3>Running queries</h3><div class="scroll"><table><thead><tr><th>pid</th><th>State</th><th>Running</th><th>Query</th></tr></thead><tbody>
    <?php foreach ($pg['active'] as $a): ?><tr><td class="num"><?= (int)$a['pid'] ?></td><td><?= cs_h((string)$a['state']) ?></td><td class="mono"><?= cs_h(preg_replace('/\.\d+$/', '', (string)$a['running'])) ?></td><td><code class="sql"><?= cs_h((string)$a['query']) ?></code></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
<?php endif; ?>
</section>

<section class="card" id="server">
  <div class="card-head"><h2>Server and PHP</h2><p>This request's worker</p></div>
  <div class="grid">
    <div><?= ac_kv(array_filter([
      'Load' => isset($server['load']) ? implode(' · ', array_map(fn($l) => number_format($l, 2), $server['load'])) . (isset($server['cpus']) ? ' <span class="muted">(' . $server['cpus'] . ' CPU; rollouts pause above 1.5)</span>' : '') : null,
      'Memory' => isset($server['mem']['MemTotal']) ? ac_meter($server['mem']['MemTotal'] - $server['mem']['MemAvailable'], $server['mem']['MemTotal']) . ac_bytes($server['mem']['MemAvailable']) . ' available of ' . ac_bytes($server['mem']['MemTotal']) . (!empty($server['mem']['SwapTotal']) ? ' · swap ' . ac_bytes($server['mem']['SwapTotal'] - $server['mem']['SwapFree']) . ' used' : '') : null,
      'Disk /' => $server['disk_total'] ? ac_meter($server['disk_total'] - $server['disk_free'], $server['disk_total']) . ac_bytes($server['disk_free']) . ' free of ' . ac_bytes($server['disk_total']) : null,
      'PHP' => cs_h($server['php'] . ' · ' . $server['sapi'] . ' · pid ' . $server['pid'] . ' · user ' . $server['user'] . ' · memory_limit ' . $server['memory_limit']),
    ])) ?></div>
    <div><h3 style="margin-top:0">Cache switches in this pool</h3><?= ac_kv(array_map(fn($v) => $v === null ? '<span class="muted">unset</span>' : '<span class="mono">' . cs_h($v) . '</span>', $server['env'])) ?></div>
  </div>
</section>
<script>
document.querySelectorAll('.log').forEach(function (el) { el.scrollTop = el.scrollHeight; });
document.addEventListener('submit', function (ev) {
  var msg = ev.target.getAttribute('data-confirm');
  if (msg && !confirm(msg)) ev.preventDefault();
});
</script>
</body></html>
<?php
  return (string)ob_get_clean();
}
