#!/usr/bin/env python3
"""Queue uncatalogued MoP Classic and Forever characters from Blizzard feeds."""
import argparse
from collections import Counter, defaultdict
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import runpy
import urllib.parse

import psycopg2
from psycopg2.extras import Json, execute_values

ARMORY = runpy.run_path(str(Path(__file__).with_name("hourly-armory-refresh.py")))
REGIONS = ARMORY["REGIONS"]
SOURCES = ("mop-classic", "forever")

GUILD_SEEDS = """
WITH observed AS (
    SELECT marketlens_import.collection_region_at(cgame,0) region,cgame,guild,members
    FROM marketlens_import.census_guild_counts WHERE source_game=%s AND guild<>''
)
SELECT region,cgame,guild,members FROM observed WHERE region=ANY(%s)
"""

QUEUE = """
WITH incoming AS MATERIALIZED (
  SELECT DISTINCT ON (region,lower(realm),lower(name)) region,realm,realm_slug,
      lower(realm) realm_key,name,lower(name) name_key,character_id,feed,evidence
  FROM jsonb_to_recordset(%s::jsonb) AS b(region text,realm text,realm_slug text,name text,
      character_id bigint,feed text,evidence text)
  ORDER BY region,lower(realm),lower(name),feed DESC,evidence
), scope AS (SELECT %s::text source),
names AS MATERIALIZED (SELECT DISTINCT name_key FROM incoming),
known_census AS MATERIALIZED (
  SELECT CASE WHEN source='forever' THEN coalesce(lower(substring(c.realm from '^CAMWOW (US|EU|KR|TW)')),c.collection_region)
           ELSE c.collection_region END AS region,
         CASE WHEN source='forever' THEN '' ELSE regexp_replace(replace(lower(c.realm),chr(39),''),'[[:space:]]+','-','g') END AS realm_slug,
         n.name_key
  FROM marketlens_import.characters_with_region c JOIN names n ON lower(c.name)=n.name_key
  CROSS JOIN scope WHERE c.source_game=source
), known_other AS MATERIALIZED (
  SELECT region,realm_slug,lower(name) name_key FROM marketlens_import.character_armory_profiles p,scope WHERE p.source_game=source
  UNION ALL SELECT region,'',lower(full_name) FROM marketlens_import.forever_armory_profiles,scope WHERE source='forever'
  UNION ALL SELECT region,'',lower(name) FROM marketlens_import.external_verified_characters,scope WHERE source='forever'
  UNION ALL SELECT region,'',lower(name) FROM marketlens_import.forever_armory_candidates,scope WHERE source='forever'
  UNION ALL SELECT m.region,regexp_replace(replace(lower(m.realm),chr(39),''),'[[:space:]]+','-','g'),lower(m.name)
    FROM marketlens_import.character_armory_misses m,scope WHERE m.source_game=source
  UNION ALL SELECT r.region,'',lower(coalesce(a->'character'->>'name_search',concat_ws(' ',a->'character'->>'name',a->'character'->>'surname')))
    FROM marketlens_import.forever_armory_guild_rosters r
    CROSS JOIN LATERAL jsonb_array_elements(r.roster_json->'members') a CROSS JOIN scope WHERE source='forever'
  UNION ALL SELECT lower(r.roster::jsonb->>'region'),
    coalesce(a->'character'->'realm'->>'slug',
      regexp_replace(replace(lower(coalesce(a->>'home_realm',r.roster::jsonb->>'realm')),chr(39),''),'[[:space:]]+','-','g')),
    lower(coalesce(a->>'name',a->'character'->>'name'))
    FROM marketlens_import.guild_roster_snapshots r
    CROSS JOIN LATERAL jsonb_array_elements(r.roster::jsonb->'members') a CROSS JOIN scope WHERE r.source_game=source
), known AS MATERIALIZED (
  SELECT DISTINCT region,realm_slug,name_key FROM (
    SELECT * FROM known_census UNION ALL SELECT o.* FROM known_other o JOIN names USING(name_key)
  ) x
), inserted AS (
  INSERT INTO marketlens_import.hourly_armory_queue(source,region,realm,name,realm_key,name_key)
  SELECT s.source,i.region,i.realm,i.name,i.realm_key,i.name_key FROM incoming i CROSS JOIN scope s
  LEFT JOIN known k ON (k.region,k.realm_slug,k.name_key)=(i.region,i.realm_slug,i.name_key)
  LEFT JOIN known u ON u.region IS NULL AND (u.realm_slug,u.name_key)=(i.realm_slug,i.name_key)
  LEFT JOIN marketlens_import.character_armory_profiles p
    ON (p.source_game,p.region,p.character_id)=(s.source,i.region,i.character_id)
  WHERE k.name_key IS NULL AND u.name_key IS NULL AND p.character_id IS NULL
  ON CONFLICT DO NOTHING RETURNING source,region,realm_key,name_key
)
INSERT INTO marketlens_import.blizzard_character_discoveries(source,region,realm_key,name_key,character_id,feed,evidence)
SELECT j.source,j.region,j.realm_key,j.name_key,i.character_id,i.feed,i.evidence
FROM inserted j JOIN incoming i USING(region,realm_key,name_key)
ON CONFLICT DO NOTHING RETURNING region,feed
"""


def endpoint(region, path, namespace):
    return f"https://{region}.api.blizzard.com/data/wow/{path}?namespace={namespace}-{region}&locale=en_US"


def region_catalog(api, region):
    status, body = api.get(endpoint(region, "realm/index", "dynamic-classic"))
    if status != 200:
        raise ValueError(f"MoP {region} realm index HTTP {status}")
    return {realm["slug"]: realm["name"] for realm in body["realms"]}


def character_row(source, region, character, catalog, feed, evidence):
    if source == "forever":
        name = " ".join((character.get("name_search") or
                        f"{character.get('name', '')} {character.get('surname', '')}").split())
        if len(name.split()) < 2:
            return None
        slug, realm = "", ""
    else:
        name = (character.get("name") or "").strip()
        slug = (character.get("realm") or {}).get("slug")
        realm = catalog.get(slug)
        if not name or not realm:
            return None
    return dict(region=region, realm=realm, realm_slug=slug, name=name,
                character_id=character.get("id"), feed=feed, evidence=evidence)


def pvp_jobs(api, region):
    status, body = api.get(endpoint(region, "pvp-season/index", "dynamic-classic"))
    if status != 200:
        raise ValueError(f"MoP {region} PvP season index HTTP {status}")
    season = int(body["current_season"]["id"])
    prefix = f"pvp-season/{season}/pvp-leaderboard"
    status, body = api.get(endpoint(region, prefix + "/index", "dynamic-classic"))
    if status != 200:
        raise ValueError(f"MoP {region} PvP bracket index HTTP {status}")
    for bracket in body["leaderboards"]:
        name = bracket["name"]
        if not name or not all(c.isascii() and (c.isalnum() or c == "-") for c in name):
            raise ValueError(f"Unexpected bracket name in {region}")
        yield region, f"{season}:{name}", endpoint(region, prefix + "/" + name, "dynamic-classic")


def guild_jobs(conn, source, catalogs, limit):
    # Current Armory guild slugs are preferred. The census extends coverage to
    # guilds that have yet to appear on any successfully fetched Armory profile.
    seeds = {}
    slugify = ARMORY["STANDARD"]["slugify"]
    with conn, conn.cursor() as cur:
        cur.execute(GUILD_SEEDS, (source, list(REGIONS)))
        for region, scope, name, size in cur:
            realm = ""
            if source == "mop-classic":
                base = scope.removeprefix("realm:")
                if base.startswith(region + ":"):
                    base = base[len(region) + 1:].removeprefix("mop-classic:")
                realm = slugify(base.removesuffix("-Alliance").removesuffix("-Horde").removesuffix("-Neutral"))
                if realm not in catalogs.get(region, {}):
                    continue
            key = region, realm, slugify(name)
            seeds[key] = (False, max(size, seeds.get(key, (False, 0))[1]))
        if source == "forever":
            cur.execute("""SELECT region,'',guild_slug,count(*),NULL::text FROM marketlens_import.forever_armory_profiles
                WHERE guild_slug<>'' AND region=ANY(%s) GROUP BY 1,2,3""", (list(REGIONS),))
        else:
            cur.execute("""SELECT region,coalesce(profile_json->'guild'->'realm'->>'slug',realm_slug),
                guild_name,count(*),profile_json->'guild'->'key'->>'href'
                FROM marketlens_import.character_armory_profiles
                WHERE source_game='mop-classic' AND guild_name<>'' AND region=ANY(%s) GROUP BY 1,2,3,5""", (list(REGIONS),))
        for region, realm, guild, size, href in cur:
            slug = guild if source == "forever" else slugify(guild)
            parts = urllib.parse.urlsplit(href or "").path.strip("/").split("/")
            if len(parts) == 5 and parts[:3] == ["data", "wow", "guild"]:
                realm, slug = map(urllib.parse.unquote, parts[3:])
            key = region, realm, slug
            seeds[key] = (True, max(size, seeds.get(key, (False, 0))[1]))
        cur.execute("""SELECT region,realm,slug FROM marketlens_import.blizzard_discovery_guild_checks
            WHERE source=%s AND checked_at>now()-interval '7 days'""", (source,))
        recent = set(cur.fetchall())
        cur.execute("""SELECT region,realm,slug,checked_at FROM marketlens_import.blizzard_discovery_guild_checks
            WHERE source=%s""", (source,))
        last = {row[:3]: row[3] for row in cur}
    groups = defaultdict(list)
    for key, (verified, size) in seeds.items():
        if key not in recent:
            groups[key[0]].append((key, verified, size))
    for region, entries in groups.items():
        entries.sort(key=lambda entry: (entry[0] in last, not entry[1], last.get(entry[0]), -entry[2], entry[0]))
        for (r, realm, slug), _, _ in entries[:limit]:
            if source == "forever":
                url = ARMORY["FOREVER"]["guild_url"](r, slug)
            else:
                path = f"guild/{urllib.parse.quote(realm,safe='')}/{urllib.parse.quote(slug,safe='')}/roster"
                url = endpoint(r, path, "profile-classic")
            yield (r, realm, slug), url


def ingest(conn, source, rows, checks=()):
    with conn, conn.cursor() as cur:
        cur.execute("SET LOCAL enable_nestloop=off")
        cur.execute("SET LOCAL statement_timeout='4min'")
        cur.execute(QUEUE, (Json(rows), source))
        added = Counter(cur.fetchall())
        if checks:
            execute_values(cur, """INSERT INTO marketlens_import.blizzard_discovery_guild_checks
                (source,region,realm,slug,status,checked_at) VALUES %s
                ON CONFLICT (source,region,realm,slug) DO UPDATE SET
                    status=excluded.status,checked_at=excluded.checked_at""",
                checks, template="(%s,%s,%s,%s,%s,now())")
    print(f"{source}: {len(rows)} supplied identities; {sum(added.values())} new queued; {dict(added)}", flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--guilds-per-region", type=int, default=50)
    args = parser.parse_args()
    if not 1 <= args.guilds_per_region <= 100:
        parser.error("--guilds-per-region must be between 1 and 100")
    api = ARMORY["STANDARD"]["Blizzard"](6)
    conn = psycopg2.connect(ARMORY["STANDARD"]["DSN"], application_name="wowcensus-blizzard-discovery")
    with conn, conn.cursor() as cur:
        cur.execute("SELECT pg_try_advisory_lock(hashtext('wowcensus-blizzard-discovery'))")
        if not cur.fetchone()[0]:
            return
        cur.execute(ARMORY["SCHEMA"])
        cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.blizzard_discovery_guild_checks (
            source text NOT NULL,region text NOT NULL,realm text NOT NULL,slug text NOT NULL,
            status integer NOT NULL,checked_at timestamptz NOT NULL,
            PRIMARY KEY(source,region,realm,slug))""")
    catalogs = {}
    failures = 0
    for region in REGIONS:
        try:
            catalogs[region] = region_catalog(api, region)
        except ValueError as error:
            print(error, flush=True)
            failures += 1
    with ThreadPoolExecutor(max_workers=8) as pool:
        jobs = []
        for region in catalogs:
            try:
                jobs.extend(pvp_jobs(api, region))
            except ValueError as error:
                print(error, flush=True)
                failures += 1
        rows = []
        for (region, label, _), (status, body) in zip(jobs, pool.map(lambda job: api.get(job[2]), jobs)):
            if status != 200:
                print(f"MoP {region} PvP {label}: HTTP {status}", flush=True)
                failures += 1
                continue
            entries = body.get("entries", [])
            print(f"MoP {region} PvP {label}: {len(entries)} entries", flush=True)
            for entry in entries:
                row = character_row("mop-classic", region, entry["character"], catalogs[region], "Blizzard PvP", label)
                if row:
                    rows.append(row)
        ingest(conn, "mop-classic", rows)
        for source in SOURCES:
            jobs = list(guild_jobs(conn, source, catalogs, args.guilds_per_region))
            roster_rows, checks, outcomes = [], [], Counter()
            for ((region, realm, slug), _), (status, body) in zip(jobs, pool.map(lambda job: api.get(job[1]), jobs)):
                outcomes[status] += 1
                if status in (200, 403, 404):
                    checks.append((source, region, realm, slug, status))
                if status != 200:
                    continue
                for member in body.get("members", []):
                    row = character_row(source, region, member["character"], catalogs.get(region, {}),
                                        "Blizzard guild roster", f"{realm}:{slug}" if realm else slug)
                    if row:
                        roster_rows.append(row)
            print(f"{source} guild responses: {dict(outcomes)}", flush=True)
            failures += sum(n for status, n in outcomes.items() if status not in (200, 403, 404))
            ingest(conn, source, roster_rows, checks)
    conn.close()
    if failures:
        raise SystemExit(f"{failures} Blizzard requests need retry")


if __name__ == "__main__":
    main()
