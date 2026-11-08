<?php
// PostgreSQL access. Rows come back with JavaScript-like types, the way
// node:sqlite returned them to the original generator.

declare(strict_types=1);

final class Db {
  private static ?PDO $pdo = null;

  public static function pdo(): PDO {
    if (!self::$pdo) {
      if (getenv('ML_QUERY_CACHE') === '1' && ($snapshot = getenv('ML_SQLITE_PATH'))) {
        self::$pdo = new PDO('sqlite:file:' . $snapshot . '?mode=ro', null, null, [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return self::$pdo;
      }
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
    if (getenv('ML_QUERY_CACHE') === '1') {
      $st = self::pdo()->prepare('SELECT rows FROM query_results WHERE key = ?');
      $st->execute([$key]);
      $value = $st->fetchColumn();
      if ($value === false) throw new RuntimeException('Missing published page data for query ' . $key);
      return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
    if (self::apcuEnabled()) return self::cached($key, fn() => self::run($sql, $params, $key));
    return self::run($sql, $params, $key);
  }

  /** Like all(), but never from APCu: for checks that must see the database as it is now. */
  public static function uncached(string $sql, array $params = []): array {
    if (getenv('ML_QUERY_CACHE') === '1') return self::all($sql, $params);
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

  private static function dataVersion(): string {
    $version = apcu_fetch('ml:data-version', $ok);
    if ($ok) return $version;
    $published = getenv('ML_CENSUS_PUBLISHED') === '1' ? self::publishedVersion() : null;
    $row = $published === null ? self::run('SELECT max(imported_at_utc) v FROM local_imports', [], null)[0] ?? [] : ['v' => $published];
    $version = (string)($row['v'] ?? '0');
    apcu_store('ml:data-version', $version, 60);
    return $version;
  }

  private static function cached(string $key, callable $compute): array {
    $entry = 'ml:q:' . self::dataVersion() . ':' . $key;
    $rows = apcu_fetch($entry, $ok);
    if ($ok) return $rows;
    $lock = $entry . ':lock';
    if (!apcu_add($lock, 1, 300)) {
      // Another worker is running this query: wait for its result.
      for ($waited = 0; $waited < 290; $waited += 0.25) {
        usleep(250000);
        $rows = apcu_fetch($entry, $ok);
        if ($ok) return $rows;
        if (!apcu_exists($lock)) break;
      }
    }
    try {
      $rows = $compute();
      apcu_store($entry, $rows, 12 * 3600);  // Too large for the cache: still returned.
      return $rows;
    } finally {
      apcu_delete($lock);
    }
  }

  private static function run(string $sql, array $params, ?string $key): array {
    $started = microtime(true);
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
          // SUM over bigint and AVG are numeric; SQLite gave integers when exact.
          case 'numeric': $row[$k] = preg_match('/^-?\d+$/', (string)$v) ? (int)$v : (float)$v; break;
        }
      }
      $rows[] = $row;
    }
    if (getenv('ML_PROFILE')) self::$profile[] = [round(microtime(true) - $started, 3), preg_replace('/\s+/', ' ', substr($sql, 0, 160)), $params];
    if ($key !== null && ($dir = getenv('ML_CAPTURE_DIR'))) {
      $path = $dir . '/' . $key . '.json';
      if (!is_file($path)) file_put_contents($path, json_encode(['key' => $key, 'sql' => $sql, 'params' => array_values($params), 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }
    return $rows;
  }

  public static function first(string $sql, array $params = []): ?array {
    return self::all($sql, $params)[0] ?? null;
  }

  public static function model(string $key): array {
    $st = self::pdo()->prepare('SELECT data FROM published_models WHERE key = ?');
    $st->execute([$key]);
    $value = $st->fetchColumn();
    if ($value === false) throw new RuntimeException('Missing published model ' . $key);
    return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
  }
}
