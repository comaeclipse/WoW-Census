-- User-confirmed provenance: all observations before 2026-10-17 00:00
-- America/Chicago (2026-10-17 05:00 UTC) were collected on US realms.
-- Newer legacy keys have no region identity. The collection date alone cannot
-- establish EU provenance: US Dreamscythe scans continued after the cutoff.
-- Views preserve the raw imported rows and leave ambiguous regions unknown.
--
-- Since 2026-11-02 MarketLens records the region each bucket was collected in.
-- The importer stores it in collection_key_regions and regenerates
-- collection_key_region() from that table after every run, so the lookup
-- stays a constant CASE (a table lookup per row made the views ~9x slower).
-- The hand-written lists below cover only rows collected before that.

CREATE TABLE IF NOT EXISTS marketlens_import.collection_key_regions (
  game TEXT NOT NULL, source_game TEXT NOT NULL, region TEXT NOT NULL,
  since BIGINT NOT NULL, region_id INTEGER, realm_id INTEGER,
  conflicts TEXT, updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (game, source_game)
);

-- Placeholder until the importer regenerates it; this file may be re-applied.
CREATE OR REPLACE FUNCTION marketlens_import.collection_key_region(
  collection_game text, observed_at bigint
) RETURNS text LANGUAGE sql IMMUTABLE AS $$ SELECT NULL::text $$;

CREATE OR REPLACE FUNCTION marketlens_import.collection_region_at(
  collection_game text, observed_at bigint
) RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE
    WHEN collection_game ~ '^realm:(us|eu|kr|tw|cn):'
      THEN split_part(collection_game, ':', 2)
    WHEN marketlens_import.collection_key_region(collection_game, observed_at) IS NOT NULL
      THEN marketlens_import.collection_key_region(collection_game, observed_at)
    -- User-confirmed US reporting convention includes Oceanic Frostmourne and Remulos.
    WHEN collection_game IN ('realm:Classic Beta PvP 2-Alliance', 'realm:Classic Beta PvP 2-Horde',
                             'realm:Westfall-Alliance', 'realm:Westfall-Horde',
                             'realm:Frostmourne-Alliance', 'realm:Frostmourne-Horde',
                             'realm:Zul''jin-Alliance', 'realm:Zul''jin-Horde',
                             'realm:Rattlegore-Alliance', 'realm:Rattlegore-Horde',
                             'realm:Nightslayer-Alliance', 'realm:Hellscream-Alliance', 'realm:Hellscream-Horde',
                             'realm:Dalaran-Alliance', 'realm:Dalaran-Horde',
                             'realm:Wildhammer-Alliance', 'realm:Wildhammer-Horde',
                             'realm:Nesingwary-Alliance', 'realm:Nesingwary-Horde',
                             'realm:Mal''Ganis-Alliance', 'realm:Remulos-Alliance', 'realm:Remulos-Horde',
                             -- Retail legacy keys whose observed home realms resolve to US.
                             'realm:Illidan-Horde', 'realm:Fizzcrank-Horde',
                             'realm:Tichondrius-Horde', 'realm:Drenden-Alliance',
                             'realm:Quel''Thalas-Horde', 'realm:Sargeras-Alliance', 'realm:Sargeras-Horde',
                             'realm:Dreamscythe-Alliance', 'realm:Dreamscythe-Horde',
                             'realm:Mankrik-Alliance', 'realm:Mankrik-Horde',
                             'realm:Doomhowl-Alliance', 'realm:Doomhowl-Horde',
                             'realm:Pagle-Alliance', 'realm:Pagle-Horde',
                             'realm:Illidan-Alliance',
                             'realm:Wild Growth-Alliance', 'realm:Wild Growth-Horde',
                             -- Retail 2026-10-31: cross-realm guilds are US (Zul'jin, Emerald Dream,
                             -- Tichondrius); Ragnaros is the Latin American realm (Quel'Thalas, Spanish guilds).
                             'realm:Thrall-Alliance', 'realm:Thrall-Horde', 'realm:Mal''Ganis-Horde',
                             'realm:Ragnaros-Alliance', 'realm:Ragnaros-Horde',
                             -- Retail 2026-11-02: Area 52 (Stormrage, Zul'jin guilds); Spinebreaker
                             -- is the US realm connected to Wildhammer (Wildhammer, Hellscream guilds).
                             'realm:Area 52-Alliance', 'realm:Area 52-Horde',
                             'realm:Spinebreaker-Alliance', 'realm:Spinebreaker-Horde',
                             'realm:Gorefiend-Alliance', 'realm:Gorefiend-Horde',
                             -- Not the German realm the eu:retail:Tichondrius keys name: these
                             -- guilds are Area 52, Stormrage, Illidan.
                             'realm:Tichondrius-Alliance',
                             -- 2026-11-02, from check-collection-regions.py. Retail: Stormrage, Skullcrusher
                             -- and Moon Guard characters are from US connected realms (Area 52, Eonar, Velen);
                             -- the Illidan AH key's scans fall inside the US Illidan-Horde sweeps.
                             'realm:Illidan', 'realm:Moon Guard-Alliance',
                             'realm:Stormrage-Alliance', 'realm:Stormrage-Horde',
                             'realm:Skullcrusher-Alliance', 'realm:Skullcrusher-Horde',
                             -- Classic Era Ashkandi (Mankrik, Westfall, Pagle guilds); MoP Classic
                             -- Immerseus and Nazgrim (Pagle, Galakras, Lei Shen guilds).
                             'realm:Ashkandi-Alliance', 'realm:Ashkandi-Horde',
                             'realm:Immerseus-Alliance', 'realm:Immerseus-Horde',
                             'realm:Nazgrim-Alliance', 'realm:Nazgrim-Horde',
                             -- CAMWOW realm labels identify their collection region.
                             'realm:CAMWOW US2 Realm 7 PvE-Horde',
                             'realm:CAMWOW US2 Realm 11 RP-Horde')
      THEN 'us'
    WHEN collection_game IN ('realm:Draenor-Alliance', 'realm:Draenor-Horde',
                             'realm:Lordaeron-Alliance', 'realm:Kilrogg-Horde',
                             'realm:Chants éternels-Horde',
                             -- TBC Anniversary Thunderstrike is EU only; 8,040 of these
                             -- characters were also seen by the EU-labelled WoWCensus scans.
                             'realm:Thunderstrike-Alliance', 'realm:Thunderstrike-Horde',
                             -- Retail 2026-10-31: cross-realm guilds are EU (Silvermoon, Draenor, Doomhammer).
                             'realm:Kazzak-Alliance', 'realm:Kazzak-Horde',
                             'realm:Twisting Nether-Alliance', 'realm:Twisting Nether-Horde',
                             -- 2026-11-02: Classic Era Nethergarde Keep (Pyrewood Village, Mirage Raceway
                             -- guilds); EU-only TBC Anniversary Spineshatter and Hardcore Soulseeker.
                             'realm:Nethergarde Keep-Alliance', 'realm:Nethergarde Keep-Horde',
                             'realm:Spineshatter-Horde',
                             'realm:Soulseeker-Alliance', 'realm:Soulseeker-Horde',
                             'realm:CAMWOW EU4 Realm 3 PvE-Horde')
      THEN 'eu'
    WHEN collection_game = 'realm:語風-Horde' THEN 'tw'
    WHEN observed_at > 0 AND observed_at < 1792213200 THEN 'us'
    ELSE NULL
  END
$$;

CREATE OR REPLACE VIEW marketlens_import.pop_samples_with_region AS
  SELECT s.*, marketlens_import.collection_region_at(s.game, s.t) AS collection_region
  FROM marketlens_import.pop_samples s;

CREATE OR REPLACE VIEW marketlens_import.population_sweeps_with_region AS
  SELECT s.*, marketlens_import.collection_region_at(
    s.game, GREATEST(s.started_at, COALESCE(s.completed_at, s.started_at))) AS collection_region
  FROM marketlens_import.population_sweeps s;

CREATE OR REPLACE VIEW marketlens_import.population_sweep_queries_with_region AS
  SELECT q.*, marketlens_import.collection_region_at(q.game, q.observed_at) AS collection_region
  FROM marketlens_import.population_sweep_queries q;

CREATE OR REPLACE VIEW marketlens_import.character_location_observations_with_region AS
  SELECT o.*, marketlens_import.collection_region_at(o.game, o.observed_at) AS collection_region
  FROM marketlens_import.character_location_observations o;

CREATE OR REPLACE VIEW marketlens_import.character_observations_with_region AS
  -- Some historical imports have last_seen=0 despite a valid first_seen.
  SELECT o.*, marketlens_import.collection_region_at(
    o.game, GREATEST(o.first_seen, o.last_seen)) AS collection_region
  FROM marketlens_import.character_observations o;

-- Attribute each aggregate to its latest sighting. Timestamped observations
-- retain their own collection region on either side of the cutoff.
CREATE OR REPLACE VIEW marketlens_import.characters_with_region AS
  SELECT c.*, marketlens_import.collection_region_at(c.game, c.last_seen) AS collection_region
  FROM marketlens_import.characters c;

CREATE OR REPLACE VIEW marketlens_import.character_inspects_with_region AS
  SELECT i.*, CASE
    WHEN i.raw_json::jsonb ->> 'observerRegion' IN ('us', 'eu', 'kr', 'tw', 'cn')
      THEN i.raw_json::jsonb ->> 'observerRegion'
    ELSE marketlens_import.collection_region_at(i.game, i.captured_at)
  END AS collection_region
  FROM marketlens_import.character_inspects i;

CREATE OR REPLACE VIEW marketlens_import.auction_snapshots_with_region AS
  SELECT a.*, marketlens_import.collection_region_at(a.game, a.captured_at) AS collection_region
  FROM marketlens_import.local_auction_snapshots a;

CREATE OR REPLACE VIEW marketlens_import.auction_history_with_region AS
  SELECT h.*, marketlens_import.collection_region_at(h.game, h.ts) AS collection_region
  FROM marketlens_import.history h;

CREATE OR REPLACE VIEW marketlens_import.seller_listings_with_region AS
  SELECT l.*, marketlens_import.collection_region_at(l.game, l.last_seen) AS collection_region
  FROM marketlens_import.seller_listings l;

CREATE OR REPLACE VIEW marketlens_import.sellers_with_region AS
  SELECT s.*, marketlens_import.collection_region_at(s.game, s.last_seen) AS collection_region
  FROM marketlens_import.sellers s;
