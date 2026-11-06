BEGIN;
SELECT pg_advisory_xact_lock(hashtext('marketlens-import'));
CREATE TABLE IF NOT EXISTS marketlens_import.external_who_canonical_imports (
  source_sha256 text PRIMARY KEY REFERENCES marketlens_import.external_who_sources(sha256),
  game text NOT NULL, characters integer NOT NULL, sightings integer NOT NULL,
  time_basis text NOT NULL, applied_at timestamptz NOT NULL DEFAULT now()
);
SET LOCAL search_path TO marketlens_import;

CREATE TEMP TABLE fp_chars ON COMMIT DROP AS
WITH ranked AS (
  SELECT s.name, s.class, s.race, s.level, s.guild, s.player_zone, s.batch_id,
    lower(s.name) name_key,
    EXTRACT(EPOCH FROM b.first_observed_at)::bigint batch_start,
    EXTRACT(EPOCH FROM b.last_observed_at)::bigint batch_end,
    min(EXTRACT(EPOCH FROM b.first_observed_at)::bigint) OVER (PARTITION BY lower(s.name)) first_bound,
    max(EXTRACT(EPOCH FROM b.last_observed_at)::bigint) OVER (PARTITION BY lower(s.name)) last_bound,
    count(*) OVER (PARTITION BY lower(s.name)) batch_count,
    row_number() OVER (PARTITION BY lower(s.name) ORDER BY b.last_observed_at DESC, s.batch_id DESC) rn
  FROM external_who_sightings s JOIN external_who_batches b
    ON b.scope_id=s.scope_id AND b.batch_id=s.batch_id
  WHERE s.source_sha256='b8febc1f1b07e66c68283fab23edd56a3b3d8c2e056a51b84cb12ecc698903c9'
    AND s.scope_id='6798-PVP-Alliance'
) SELECT * FROM ranked WHERE rn=1;

DO $$ BEGIN
  IF (SELECT count(*) FROM fp_chars) <> 18441 OR
     (SELECT count(*) FROM external_who_sightings WHERE source_sha256='b8febc1f1b07e66c68283fab23edd56a3b3d8c2e056a51b84cb12ecc698903c9') <> 19879 THEN
    RAISE EXCEPTION 'ForeverPulse source counts changed';
  END IF;
END $$;

INSERT INTO census_collection_realms
  (game,source_game,region,region_id,realm_id,realm_name,realm_normalized,faction)
SELECT 'realm:us:forever:CAMWOW US1 Realm 2 PvP-Alliance','forever','us',1,6798,
  'CAMWOW US1 Realm 2 PvP','CAMWOWUS1Realm2PvP','Alliance'
WHERE NOT EXISTS (SELECT 1 FROM external_who_canonical_imports WHERE source_sha256='b8febc1f1b07e66c68283fab23edd56a3b3d8c2e056a51b84cb12ecc698903c9')
ON CONFLICT (game) DO NOTHING;

INSERT INTO characters
  (game,source_game,character_key,full_name,name,realm,guild,level,race,class,class_file,zone,first_seen,last_seen,seen_count)
SELECT 'realm:us:forever:CAMWOW US1 Realm 2 PvP-Alliance','forever',
  'forever:camwowus1realm2pvp:'||name_key,
  name||'-CAMWOW US1 Realm 2 PvP',name,'CAMWOW US1 Realm 2 PvP',guild,level,race,class,upper(class),player_zone,
  first_bound,last_bound,batch_count
FROM fp_chars
WHERE NOT EXISTS (SELECT 1 FROM external_who_canonical_imports WHERE source_sha256='b8febc1f1b07e66c68283fab23edd56a3b3d8c2e056a51b84cb12ecc698903c9')
ON CONFLICT (game,source_game,character_key) DO UPDATE SET
  first_seen=LEAST(characters.first_seen,EXCLUDED.first_seen),
  last_seen=GREATEST(characters.last_seen,EXCLUDED.last_seen),
  seen_count=GREATEST(characters.seen_count,EXCLUDED.seen_count),
  guild=CASE WHEN characters.guild='' AND EXCLUDED.guild<>'' THEN EXCLUDED.guild ELSE characters.guild END;

INSERT INTO character_observations
  (game,source_game,character_key,day,sightings,first_seen,last_seen)
SELECT 'realm:us:forever:CAMWOW US1 Realm 2 PvP-Alliance','forever',
  'forever:camwowus1realm2pvp:'||lower(s.name),
  floor(EXTRACT(EPOCH FROM b.last_observed_at)/86400)::bigint,
  count(*)::bigint,
  min(EXTRACT(EPOCH FROM b.first_observed_at))::bigint,
  max(EXTRACT(EPOCH FROM b.last_observed_at))::bigint
FROM external_who_sightings s JOIN external_who_batches b
  ON b.scope_id=s.scope_id AND b.batch_id=s.batch_id
WHERE s.source_sha256='b8febc1f1b07e66c68283fab23edd56a3b3d8c2e056a51b84cb12ecc698903c9'
  AND NOT EXISTS (SELECT 1 FROM external_who_canonical_imports WHERE source_sha256='b8febc1f1b07e66c68283fab23edd56a3b3d8c2e056a51b84cb12ecc698903c9')
GROUP BY lower(s.name),floor(EXTRACT(EPOCH FROM b.last_observed_at)/86400)::bigint
ON CONFLICT (game,source_game,character_key,day) DO UPDATE SET
  sightings=GREATEST(character_observations.sightings,EXCLUDED.sightings),
  first_seen=LEAST(character_observations.first_seen,EXCLUDED.first_seen),
  last_seen=GREATEST(character_observations.last_seen,EXCLUDED.last_seen);

INSERT INTO datasets (game,source_game,updated_at)
SELECT 'realm:us:forever:CAMWOW US1 Realm 2 PvP-Alliance','forever',
  to_char(max(b.last_observed_at AT TIME ZONE 'UTC'),'YYYY-MM-DD"T"HH24:MI:SS"Z"')
FROM external_who_batches b WHERE b.scope_id='6798-PVP-Alliance'
  AND NOT EXISTS (SELECT 1 FROM external_who_canonical_imports WHERE source_sha256='b8febc1f1b07e66c68283fab23edd56a3b3d8c2e056a51b84cb12ecc698903c9')
HAVING count(*)>0
ON CONFLICT (game) DO UPDATE SET updated_at=GREATEST(datasets.updated_at,EXCLUDED.updated_at);

INSERT INTO local_imports (source_sha256,kind,bucket,source_game,imported_at_utc,details_json)
SELECT 'b8febc1f1b07e66c68283fab23edd56a3b3d8c2e056a51b84cb12ecc698903c9',
  'external_who_characters','us:forever:CAMWOW US1 Realm 2 PvP-Alliance','forever',
  to_char(clock_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
  '{"source":"ForeverPulse 4.6.0","names":18441,"rows":19879,"timeBasis":"batch interval"}'
WHERE NOT EXISTS (SELECT 1 FROM external_who_canonical_imports WHERE source_sha256='b8febc1f1b07e66c68283fab23edd56a3b3d8c2e056a51b84cb12ecc698903c9')
ON CONFLICT DO NOTHING;

INSERT INTO external_who_canonical_imports (source_sha256,game,characters,sightings,time_basis)
VALUES ('b8febc1f1b07e66c68283fab23edd56a3b3d8c2e056a51b84cb12ecc698903c9',
  'realm:us:forever:CAMWOW US1 Realm 2 PvP-Alliance',18441,19879,'batch start/end bounds')
ON CONFLICT (source_sha256) DO NOTHING;
COMMIT;
