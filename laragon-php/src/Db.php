<?php
// PostgreSQL access. Rows come back with JavaScript-like types, the way
// node:sqlite returned them to the original generator.

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
    if (getenv('ML_QUERY_CACHE') === '1') {
      $st = self::pdo()->prepare('SELECT rows FROM query_results WHERE key = ?');
      $st->execute([$key]);
      $value = $st->fetchColumn();
      if ($value === false) throw new RuntimeException('Missing published page data for query ' . $key);
      return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
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
    if ($dir = getenv('ML_CAPTURE_DIR')) {
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
