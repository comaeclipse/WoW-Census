<?php
// Prints every item id seen in an edition's Auction House scans, one per line, so
// tools/update-item-icons.mjs --ids-from=<file> can cache icons for all of them,
// not only the items a rendered page happens to show today.
// Usage: php laragon-php/auction-item-ids.php <source> > ids.txt
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
$source = $argv[1] ?? '';
if (!isset(EDITIONS[$source])) { fwrite(STDERR, "usage: php auction-item-ids.php <source>\n"); exit(2); }
foreach (Db::all('SELECT DISTINCT item_id FROM local_auction_snapshots WHERE source_game=? ORDER BY 1', [$source]) as $row)
  echo (int)$row['item_id'], "\n";
