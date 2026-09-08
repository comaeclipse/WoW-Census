-- Adds the top-seller concentration column. Region rows leave it NULL; realm
-- datasets populate it (0-100 = the largest single seller's share of an item's
-- listed quantity). Safe to run once.
ALTER TABLE items ADD COLUMN tc INTEGER;    -- top-seller concentration 0-100 (NULL when sellers unknown)
