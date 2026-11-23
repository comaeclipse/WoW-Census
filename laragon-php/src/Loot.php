<?php
declare(strict_types=1);

/**
 * Where an item comes from and what a vendor pays for it, from players' Journalator logs
 * (tools/import-journalator-pg.py builds addon_loot_sources / addon_loot_items from the ledger).
 *
 * A loot window is one corpse or world object a player looted, coin-only ones included, so
 * "dropped in X of N" is a real observed rate. Quest rows count turn-ins and the reward received.
 * Nobody is named.
 */

const LOOT_SMALL_SAMPLE = 10;

function loot_tables_ready(): bool {
  static $ok = null;
  return $ok ??= (bool)(Db::first("SELECT to_regclass('addon_loot_items') IS NOT NULL AS ok")['ok'] ?? false);
}

/**
 * rows[] = ['kind' => npc|world|quest, 'id', 'name', 'windows' => times looted / turned in,
 *           'drops' => windows that gave this item, 'qty' => units received, 'rate' => 0..1, 'last' => unix]
 * Most reliable first: sources with more drops, then higher rates. Null when nothing is logged.
 */
function item_loot_sources(string $sourceGame, int $id): ?array {
  if (!loot_tables_ready()) return null;
  $rows = Db::all("WITH hit AS (
        SELECT s.kind, s.source_id, count(*) AS drops, sum(i.quantity) AS qty, max(s.t) AS last
          FROM addon_loot_items i JOIN addon_loot_sources s ON s.event_key = i.event_key
         WHERE i.source_game = ? AND i.item_id = ?
         GROUP BY 1, 2)
      SELECT h.kind, h.source_id, h.drops, h.qty, h.last, w.windows, w.name
        FROM hit h
        JOIN LATERAL (SELECT count(*) AS windows,
                             (array_agg(source_name ORDER BY t DESC) FILTER (WHERE source_name NOT IN ('', 'Unknown')))[1] AS name
                        FROM addon_loot_sources
                       WHERE source_game = ? AND kind = h.kind AND source_id = h.source_id) w ON true",
    [$sourceGame, $id, $sourceGame]);
  if (!$rows) return null;
  $out = [];
  foreach ($rows as $r) {
    $windows = max(1, (int)$r['windows']);
    $out[] = ['kind' => (string)$r['kind'], 'id' => (int)$r['source_id'],
      'name' => (string)($r['name'] ?? '') !== '' ? (string)$r['name'] : (['npc' => 'Mob', 'world' => 'Object', 'quest' => 'Quest'][$r['kind']] ?? ucfirst((string)$r['kind'])) . ' #' . $r['source_id'],
      'windows' => $windows, 'drops' => (int)$r['drops'], 'qty' => (int)$r['qty'],
      'rate' => min(1.0, (int)$r['drops'] / $windows), 'last' => (int)$r['last']];
  }
  usort($out, fn($a, $b) => [$b['drops'], $b['rate'], $b['windows']] <=> [$a['drops'], $a['rate'], $a['windows']]);
  return ['rows' => $out, 'windows' => array_sum(array_column($out, 'windows')), 'drops' => array_sum(array_column($out, 'drops'))];
}

/**
 * Vendor prices players were paid (sell) and charged (purchase), from Journalator's Vendoring log.
 * The most common price wins: one unit price per item, but a rare odd row shouldn't.
 * Returns ['sell' => cu|null, 'sells' => rows, 'buy' => cu|null, 'buys' => rows] or null.
 */
function item_vendor_log(string $sourceGame, int $id): ?array {
  if (!loot_tables_ready()) return null;
  $rows = Db::all("SELECT row->>'vendorType' AS kind, (row->>'unitPrice')::bigint AS unit, count(*) AS n
      FROM addon_ledger_events
     WHERE source_game = ? AND section = 'Vendoring' AND item_id = ? AND row->>'unitPrice' ~ '^[0-9]{1,12}$'
     GROUP BY 1, 2 ORDER BY n DESC, unit DESC", [$sourceGame, $id]);
  if (!$rows) return null;
  $out = ['sell' => null, 'sells' => 0, 'buy' => null, 'buys' => 0];
  foreach ($rows as $r) {
    $k = $r['kind'] === 'purchase' ? 'buy' : ($r['kind'] === 'sell' ? 'sell' : null);
    if ($k === null) continue;
    $out[$k . 's'] += (int)$r['n'];
    if ($out[$k] === null && (int)$r['unit'] > 0) $out[$k] = (int)$r['unit'];
  }
  return $out['sells'] || $out['buys'] ? $out : null;
}

/**
 * Vendor it or auction it? Compares the vendor price with what the auction house would pay after its
 * 5% cut: the typical completed sale when players' logs have some, else the median asking price
 * across each market's latest scan. Deposits aren't counted.
 *
 * $scans are forever_item_model scans ([realm, faction, t, qty, lowest, median, avg]); $sales its sales block.
 */
function item_vendor_vs_ah(?int $armorySell, ?array $log, array $scans, ?array $sales): ?array {
  $sell = $armorySell ?: ($log['sell'] ?? null);
  $buy = $log['buy'] ?? null;
  if (!$sell && !$buy) return null;
  $latest = [];
  foreach ($scans as [$realm, $faction, $t, $qty, $lo, $med]) {
    $k = $realm . '|' . $faction;
    if ($med > 0 && (!isset($latest[$k]) || $t > $latest[$k][0])) $latest[$k] = [$t, $med, $lo];
  }
  $ah = null;
  if ($sales && ($sales['count'] ?? 0) >= 3 && $sales['median'] > 0) {
    $ah = ['price' => (int)$sales['median'], 'basis' => 'sales', 'n' => (int)$sales['count'], 'when' => (int)$sales['last']];
  } elseif ($latest) {
    $meds = array_column($latest, 1); sort($meds);
    $n = count($meds);
    $mid = $n % 2 ? $meds[intdiv($n, 2)] : intdiv($meds[$n / 2 - 1] + $meds[$n / 2], 2);
    $ah = ['price' => (int)$mid, 'basis' => 'listings', 'n' => $n, 'when' => max(array_column($latest, 0))];
  }
  $verdict = null;
  if ($sell && $ah) {
    $net = (int)floor($ah['price'] * 0.95);
    $verdict = ['net' => $net, 'gain' => $net - $sell, 'pick' => $net > $sell ? 'auction' : 'vendor'];
  }
  return ['sell' => $sell, 'sellSource' => $armorySell ? 'armory' : 'log', 'sells' => $log['sells'] ?? 0,
    'buy' => $buy, 'buys' => $log['buys'] ?? 0, 'ah' => $ah, 'verdict' => $verdict];
}
