-- Adds realm-supply columns. Region rows leave these NULL; realm datasets
-- (game = "realm:<Realm-Faction>") populate them. Safe to run once.
ALTER TABLE items ADD COLUMN q INTEGER;     -- quantity listed on the realm
ALTER TABLE items ADD COLUMN sc INTEGER;    -- distinct sellers on the realm
ALTER TABLE history ADD COLUMN q INTEGER;   -- quantity at each snapshot
