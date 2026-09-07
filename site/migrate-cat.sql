-- Adds the addon-computed market column. Region rows (TSM feeds) leave it NULL;
-- uploaded realm datasets populate it from rec.class.market. Safe to run once.
ALTER TABLE items ADD COLUMN cat TEXT;
