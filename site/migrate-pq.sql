-- Adds the previous-scan quantity column that backs the realm table's "% qty
-- change since last scan". Populated by /admin/import-realm from each item's
-- second-to-last snapshot; NULL means the item has no earlier snapshot (re-upload
-- a realm after applying this to fill it in). Safe to run once.
ALTER TABLE items ADD COLUMN pq INTEGER;    -- quantity in the scan before the latest (NULL when unknown)
