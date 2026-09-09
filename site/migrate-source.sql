-- Adds the item source axis (crafted/gathered/...) and producing profession to
-- the items table. Safe to run once on an existing database before deploying the
-- source-aware worker.
ALTER TABLE items ADD COLUMN src TEXT;
ALTER TABLE items ADD COLUMN crafter TEXT;
