<?php
// PostgreSQL access. Rows come back with JavaScript-like types (integers and
// floats, not strings), which the page code and its embedded scripts expect.

declare(strict_types=1);

final class Db {
  private static ?PDO $pdo = null;

  public static function pdo(): PDO {
    if (!self::$pdo) {
      $cfg = ML_CONFIG['db'];
      self::$pdo = new PDO("pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']}",
        $cfg['user'], $cfg['password'], [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
          PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
      self::$pdo->exec('SET search_path TO ' . $cfg['schema']);
    }
    return self::$pdo;
  }

  /** Query timings, collected when ML_PROFILE is set. */
  public static array $profile = [];

  /** @return list<array<string, mixed>> */
  public static function all(string $sql, array $params = []): array {
    $key = hash('sha256', json_encode([$sql, array_values($params)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    if (self::apcuEnabled()) return self::cached($key, fn() => self::run($sql, $params, $key));
    return self::run($sql, $params, $key);
  }

  /** Like all(), but never from APCu: for checks that must see the database as it is now. */
  public static function uncached(string $sql, array $params = []): array {
    return self::run($sql, $params, hash('sha256', json_encode([$sql, array_values($params)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
  }

  /**
   * Shared query cache for a server rendering straight from PostgreSQL
   * (ML_APCU_CACHE=1 and the APCu extension). Many pages run the same heavy
   * queries, so one render's results serve every page and every PHP worker
   * until the data changes: entries are keyed by the latest import, and a
   * PHP-FPM restart empties the cache. While one worker computes a query,
   * others needing it wait for that result instead of running it again.
   */
  private static function apcuEnabled(): bool {
    static $on = null;
    return $on ??= getenv('ML_APCU_CACHE') === '1' && function_exists('apcu_enabled') && apcu_enabled();
  }

  /**
   * The import the census tables were built for (census_cache_meta.version), or
   * null without them. With ML_CENSUS_PUBLISHED=1 this, not the newest import,
   * is the data version the site shows.
   */
  public static function publishedVersion(): ?string {
    static $version = false;
    if ($version !== false) return $version;
    try {
      $row = self::run('SELECT version FROM census_cache_meta LIMIT 1', [], null)[0] ?? null;
      return $version = $row ? (string)$row['version'] : null;
    } catch (Throwable $e) {
      return $version = null;
    }
  }

  /** Longest a worker waits for another worker's cache fill before running the query itself. */
  private const LOCK_WAIT_SECONDS = 20;
  /** How long the previous data version's results stay as a fallback after the version changes. */
  private const STALE_KEEP_SECONDS = 900;

  private static function dataVersion(): string {
    $version = apcu_fetch('ml:data-version', $ok);
    if ($ok) return $version;
    $published = getenv('ML_CENSUS_PUBLISHED') === '1' ? self::publishedVersion() : null;
    $row = $published === null ? self::run('SELECT max(imported_at_utc) v FROM local_imports', [], null)[0] ?? [] : ['v' => $published];
    $version = (string)($row['v'] ?? '0');
    apcu_store('ml:data-version', $version, 60);
    self::sweepVersions($version);
    return $version;
  }

  /**
   * When the data version changes, the previous version's results stay for
   * STALE_KEEP_SECONDS so a worker can serve them while the new ones are
   * computed; anything older is dropped at once instead of waiting out its
   * 12-hour TTL. Runs at most once a minute, in one worker.
   */
  private static function sweepVersions(string $current): void {
    if (!apcu_add('ml:sweep-lock', 1, 55)) return;
    $state = apcu_fetch('ml:versions', $ok);
    if (!$ok || !is_array($state)) $state = ['current' => $current, 'previous' => null, 'changed' => 0];
    if ($state['current'] !== $current) {
      $state = ['current' => $current, 'previous' => $state['current'], 'changed' => time()];
    } elseif ($state['previous'] !== null && time() - $state['changed'] > self::STALE_KEEP_SECONDS) {
      $state['previous'] = null;
    }
    apcu_store('ml:versions', $state);
    self::dropVersionsExcept(array_values(array_filter([$state['current'], $state['previous']], fn($v) => $v !== null)));
  }

  /** @return array{0: int, 1: int} entries dropped and their bytes */
  public static function dropVersionsExcept(array $keep): array {
    $dropped = 0; $bytes = 0;
    foreach (new APCUIterator('/^ml:q:/', APC_ITER_KEY | APC_ITER_MEM_SIZE, 500, APC_LIST_ACTIVE) as $key => $e) {
      if (!preg_match('/^ml:q:(.+):[0-9a-f]{64}(:lock)?$/', (string)$key, $m) || in_array($m[1], $keep, true)) continue;
      if (apcu_delete($key)) { $dropped++; $bytes += (int)$e['mem_size']; }
    }
    return [$dropped, $bytes];
  }

  /** The previous data version while its results are kept as a fallback, else null. */
  private static function staleVersion(string $current): ?string {
    $state = apcu_fetch('ml:versions', $ok);
    return $ok && is_array($state) && $state['current'] === $current ? $state['previous'] : null;
  }

  /**
   * Old rows are fine for a visitor's one-off page, which is then kept out of
   * the page cache, but never for a rollout's pre-render (X-WoWCensus-Refresh
   * from 127.0.0.1), whose HTML is cached until the next rollout, and never once
   * output has started, since the page could no longer be marked uncacheable.
   */
  private static function mayServeStale(): bool {
    if (PHP_SAPI === 'cli' || headers_sent()) return false;
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    return !($local && ($_SERVER['HTTP_X_WOWCENSUS_REFRESH'] ?? '') === '1');
  }

  /** Counters for the admin cache page: ml:stat:<name>, since the last PHP-FPM restart. */
  private static function stat(string $name, int $by = 1): void {
    apcu_add('ml:stat:' . $name, 0);
    apcu_inc('ml:stat:' . $name, $by);
  }

  private static function cached(string $key, callable $compute): array {
    $version = self::dataVersion();
    $entry = 'ml:q:' . $version . ':' . $key;
    $rows = apcu_fetch($entry, $ok);
    if ($ok) return $rows;
    $lock = $entry . ':lock';
    $owned = apcu_add($lock, 1, 300);
    if (!$owned) {
      // Another worker is running this query: serve the previous version's
      // result if it is still kept, else wait a bounded time for the new one.
      $stale = self::staleVersion($version);
      if ($stale !== null && self::mayServeStale()) {
        $rows = apcu_fetch('ml:q:' . $stale . ':' . $key, $ok);
        if ($ok) {
          // A page built from old rows must not enter nginx's page cache (or Cloudflare's).
          header('Cache-Control: private, no-store');
          header('X-Accel-Expires: 0');
          self::stat('stale-served');
          return $rows;
        }
      }
      $start = microtime(true);
      $outcome = 'timeout';
      while (microtime(true) - $start < self::LOCK_WAIT_SECONDS) {
        usleep(250000);
        $rows = apcu_fetch($entry, $ok);
        if ($ok) { $outcome = 'filled'; break; }
        if (!apcu_exists($lock)) { $outcome = 'released'; break; }
      }
      $ms = (int)round((microtime(true) - $start) * 1000);
      self::stat('wait-n'); self::stat('wait-ms', $ms); self::stat('wait-' . $outcome);
      $max = apcu_fetch('ml:stat:wait-max-ms', $ok);
      if (!$ok || $ms > $max) apcu_store('ml:stat:wait-max-ms', $ms);
      if ($outcome === 'filled') return $rows;
      // The filling worker gave up or could not store its result: take the lock if it is free.
      $owned = apcu_add($lock, 1, 300);
    }
    try {
      $started = microtime(true);
      $rows = $compute();
      $ms = (int)round((microtime(true) - $started) * 1000);
      $bytes = strlen(serialize($rows));
      $limit = (int)(getenv('ML_APCU_MAX_BYTES') ?: 0);
      $stored = false;
      if ($limit > 0 && $bytes > $limit) self::stat('skip-large');
      elseif (!($stored = apcu_store($entry, $rows, 12 * 3600))) self::stat('store-failed');
      // What the entry holds and what it cost, for the admin cache page (src/AdminCache.php).
      apcu_store('ml:qm:' . $key, ['sql' => self::$lastSql, 'ms' => $ms, 'rows' => count($rows), 'bytes' => $bytes,
        'stored' => $stored, 'at' => time()], 12 * 3600);
      return $rows;
    } finally {
      if ($owned) apcu_delete($lock);
    }
  }

  /** The start of the last query run, kept with its APCu entry. */
  private static string $lastSql = '';

  private static function run(string $sql, array $params, ?string $key): array {
    $started = microtime(true);
    self::$lastSql = substr(preg_replace('/\s+/', ' ', trim($sql)), 0, 400);
    $st = self::pdo()->prepare($sql);
    foreach (array_values($params) as $i => $v)
      $st->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : (is_null($v) ? PDO::PARAM_NULL : PDO::PARAM_STR));
    $st->execute();
    $types = [];
    for ($i = 0; $i < $st->columnCount(); $i++) {
      $meta = $st->getColumnMeta($i);
      $types[$meta['name']] = $meta['native_type'] ?? '';
    }
    $rows = [];
    while (($row = $st->fetch()) !== false) {
      foreach ($row as $k => $v) {
        if ($v === null) continue;
        switch ($types[$k]) {
          case 'int2': case 'int4': case 'int8': $row[$k] = (int)$v; break;
          case 'float4': case 'float8': $row[$k] = (float)$v; break;
          // SUM over bigint and AVG are numeric: an integer when exact, else a float.
          case 'numeric': $row[$k] = preg_match('/^-?\d+$/', (string)$v) ? (int)$v : (float)$v; break;
        }
      }
      $rows[] = $row;
    }
    if (getenv('ML_PROFILE')) self::$profile[] = [round(microtime(true) - $started, 3), preg_replace('/\s+/', ' ', substr($sql, 0, 160)), $params];
    return $rows;
  }

  public static function first(string $sql, array $params = []): ?array {
    return self::all($sql, $params)[0] ?? null;
  }
}
