-- One-off repair (2026-11-11): Forever's current items never got a previous quantity (items.pq), because
-- import_market() copied the old row's pq instead of its q for one-point scans (every AH CSV), so the
-- "Change" columns on the Forever auction and profession pages stayed blank. Fills pq with the item's
-- quantity in the market's scan just before the current one; items absent from that scan stay null.
-- Run inside the import schema: psql ... -v ON_ERROR_STOP=1 -c "SET search_path=marketlens_import" -f this
WITH cur AS (
  SELECT i.game, i.id, extract(epoch FROM i.updated_at::timestamptz)::bigint AS t
  FROM items i JOIN datasets d USING (game)
  WHERE d.source_game = 'forever' AND i.pq IS NULL
), prev AS (
  SELECT c.game, c.id,
         (SELECT max(s.captured_at) FROM local_auction_snapshots s
           WHERE s.source_game = 'forever' AND s.game = c.game AND s.captured_at < c.t) AS pt
  FROM cur c
)
UPDATE items i SET pq = s.quantity
FROM prev p JOIN local_auction_snapshots s
  ON s.source_game = 'forever' AND s.game = p.game AND s.item_id = p.id AND s.captured_at = p.pt
WHERE i.game = p.game AND i.id = p.id;
