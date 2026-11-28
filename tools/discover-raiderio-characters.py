#!/usr/bin/env python3
"""Discover missing Retail characters from Raider.IO Mythic+ rosters."""
import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import runpy
import time
import urllib.error
import urllib.parse
import urllib.request

import psycopg2
from psycopg2.extras import Json

ARMORY = runpy.run_path(str(Path(__file__).with_name("hourly-armory-refresh.py")))
API = "https://raider.io/api/v1/mythic-plus/"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class RaiderIO:
    def __init__(self, key):
        self.key = key
        self.opener = urllib.request.build_opener(NoRedirect())

    def get(self, path, **params):
        url = API + path + "?" + urllib.parse.urlencode({**params, "access_key": self.key})
        request = urllib.request.Request(url, headers={"User-Agent": "WoWCensus/1.0 character discovery",
                                                        "Accept": "application/json"})
        for attempt in range(3):
            try:
                with self.opener.open(request, timeout=20) as response:
                    result = json.load(response)
                time.sleep(0.25)
                return result
            except urllib.error.HTTPError as error:
                status = error.code
                wait = error.headers.get("Retry-After", "")
                delay = int(wait) if wait.isdecimal() else 2 ** attempt
                error.close()
                if status not in (429, 500, 502, 503, 504) or attempt == 2 or delay > 120:
                    raise RuntimeError(f"Raider.IO {path}: HTTP {status}") from None
                time.sleep(delay)
            except (OSError, ValueError):
                if attempt == 2:
                    raise RuntimeError(f"Raider.IO {path}: request or JSON error") from None
                time.sleep(2 ** attempt)


SEED = """
WITH incoming AS (
    SELECT DISTINCT ON (region,lower(realm),lower(name)) region,realm,lower(realm) realm_key,
        name,lower(name) name_key,realm_slug,realm_id,raiderio_id
    FROM jsonb_to_recordset(%s::jsonb) AS x(region text,realm text,name text,
        realm_slug text,realm_id bigint,raiderio_id bigint)
    ORDER BY region,lower(realm),lower(name),raiderio_id
), known AS MATERIALIZED (
    SELECT collection_region AS region,lower(name) AS name_key,
           regexp_replace(replace(lower(realm),chr(39),''),'[[:space:]]+','-','g') AS realm_slug
    FROM marketlens_import.characters_with_region WHERE source_game='retail'
), inserted AS (
    INSERT INTO marketlens_import.hourly_armory_queue(source,region,realm,name,realm_key,name_key)
    SELECT 'retail',i.region,i.realm,i.name,i.realm_key,i.name_key FROM incoming i
    WHERE NOT EXISTS (SELECT 1 FROM known k WHERE (k.realm_slug,k.name_key)=(i.realm_slug,i.name_key)
        AND (k.region IS NULL OR k.region=i.region))
    AND NOT EXISTS (SELECT 1 FROM marketlens_import.character_armory_profiles p
        WHERE p.source_game='retail' AND p.region=i.region AND lower(p.name)=i.name_key
        AND (p.realm_id=i.realm_id OR p.realm_slug=i.realm_slug))
    AND NOT EXISTS (SELECT 1 FROM marketlens_import.character_armory_misses m
        WHERE m.source_game='retail' AND (m.region,lower(m.realm),lower(m.name))=(i.region,i.realm_key,i.name_key))
    ON CONFLICT DO NOTHING RETURNING region,realm_key,name_key
)
INSERT INTO marketlens_import.raiderio_character_discoveries(region,realm_key,name_key,raiderio_id,season)
SELECT j.region,j.realm_key,j.name_key,i.raiderio_id,%s
FROM inserted j JOIN incoming i USING (region,realm_key,name_key)
ON CONFLICT DO NOTHING
"""


def roster_members(rankings, region):
    for ranking in rankings:
        for member in ranking["run"]["roster"]:
            character = member.get("character") or {}
            realm = character.get("realm") or {}
            name = (character.get("name") or "").strip()
            if (member.get("isBanned") or not name or (character.get("region") or {}).get("slug") != region
                    or not realm.get("name") or not realm.get("slug") or not realm.get("wowRealmId")
                    or not character.get("id")):
                continue
            yield dict(region=region, realm=realm["name"], realm_slug=realm["slug"],
                       realm_id=realm["wowRealmId"], name=name, raiderio_id=character["id"])


def active_season(seasons, region):
    now = datetime.now(timezone.utc)
    return next((season["slug"] for season in seasons if season["is_main_season"]
                 and datetime.fromisoformat(season["starts"][region]) <= now
                 < datetime.fromisoformat(season["ends"][region])), None)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--pages", type=int, default=4, help="Leaderboard pages per region per run")
    parser.add_argument("--expansion-id", type=int, default=11)
    args = parser.parse_args()
    if not 1 <= args.pages <= 10:
        parser.error("--pages must be between 1 and 10")
    key = os.getenv("RAIDERIO_API_KEY")
    if not key:
        raise SystemExit("Set RAIDERIO_API_KEY")
    api = RaiderIO(key)
    conn = psycopg2.connect(ARMORY["STANDARD"]["DSN"], application_name="wowcensus-raiderio")
    with conn, conn.cursor() as cur:
        cur.execute("SELECT pg_try_advisory_lock(hashtext('wowcensus-raiderio'))")
        if not cur.fetchone()[0]:
            return
        cur.execute(ARMORY["SCHEMA"])
        cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.raiderio_discovery_cursor (
            region text PRIMARY KEY, season text NOT NULL, next_page integer NOT NULL)""")
    seasons = api.get("static-data", expansion_id=args.expansion_id)["seasons"]
    for region in ARMORY["REGIONS"]:
        season = active_season(seasons, region)
        if not season:
            raise SystemExit(f"Raider.IO did not list an active season for {region}")
        with conn, conn.cursor() as cur:
            cur.execute("""SELECT next_page FROM marketlens_import.raiderio_discovery_cursor
                WHERE region=%s AND season=%s""", (region, season))
            saved = cur.fetchone()
        page = saved[0] if saved else 0
        candidates = {}
        for _ in range(args.pages):
            rankings = api.get("runs", season=season, region=region, dungeon="all",
                               affixes="all", page=page)["rankings"]
            for member in roster_members(rankings, region):
                candidates[(member["realm_slug"], member["name"].lower())] = member
            page = (page + 1) % 50 if rankings else 0
            if not rankings:
                break
        with conn, conn.cursor() as cur:
            cur.execute(SEED, (Json(list(candidates.values())), season))
            added = cur.rowcount
            cur.execute("""INSERT INTO marketlens_import.raiderio_discovery_cursor VALUES (%s,%s,%s)
                ON CONFLICT (region) DO UPDATE SET season=excluded.season,next_page=excluded.next_page""",
                (region, season, page))
        print(f"{region} {season}: {len(candidates)} distinct roster identities, {added} new queued", flush=True)
    conn.close()


if __name__ == "__main__":
    main()
