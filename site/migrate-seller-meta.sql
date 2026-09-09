-- Adds latest seller scan/sample provenance to realm datasets.
-- Safe to run once on an existing database before deploying seller telemetry.
ALTER TABLE datasets ADD COLUMN seller_updated_at TEXT;
ALTER TABLE datasets ADD COLUMN seller_meta TEXT;
