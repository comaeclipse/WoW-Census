#!/usr/bin/env python3
"""Enrich already-known non-Forever WoWCensus characters with Blizzard profiles.

Public pages use a stable WoWCensus id and never expose the realm in their URL.
This discovers no characters: candidates always come from characters_with_region.
"""
from __future__ import annotations

import argparse
import base64
import concurrent.futures
import hashlib
import json
import os
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone

import psycopg2
from psycopg2.extras import Json

DSN = os.getenv("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")
SOURCES = {
    "retail": ("retail", "profile-{region}"),
    "mop-classic": ("mop", "profile-classic-{region}"),
    "classic-progression": ("tbc", "profile-classicann-{region}"),
    "classic": ("classic", "profile-classic1x-{region}"),
    "sod": ("sod", "profile-classic1x-{region}"),
    "hardcore": ("hardcore", "profile-classic1x-{region}"),
}
ENDPOINTS = ("specializations", "equipment", "statistics", "professions", "achievements",
             "reputations", "pvp-summary", "appearance", "character-media")


def slugify(value: str) -> str:
    return "-".join(value.casefold().replace("'", "").split())


def public_id(source: str, region: str, realm: str, name: str) -> str:
    scope = f"{region}\n{realm}".lower()
    raw = hashlib.sha256(f"{source}\n{scope}\n{name.casefold()}".encode()).hexdigest()[:13]
    return str(100_000_000_000 + int(raw, 16) % 900_000_000_000)


def api_url(source: str, region: str, realm_slug: str, name_slug: str, endpoint: str = "") -> str:
    namespace = SOURCES[source][1].format(region=region)
    suffix = f"/{endpoint}" if endpoint else ""
    return (f"https://{region}.api.blizzard.com/profile/wow/character/"
            f"{urllib.parse.quote(realm_slug)}/{urllib.parse.quote(name_slug)}{suffix}"
            f"?namespace={namespace}&locale=en_US")


class Blizzard:
    def __init__(self, rate: float):
        cid, secret = os.getenv("BLIZZARD_CLIENT_ID"), os.getenv("BLIZZARD_CLIENT_SECRET")
        if not cid or not secret:
            raise SystemExit("set BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET")
        basic = base64.b64encode(f"{cid}:{secret}".encode()).decode()
        req = urllib.request.Request("https://us.battle.net/oauth/token", data=b"grant_type=client_credentials",
                                     headers={"Authorization": "Basic " + basic})
        self.token = json.load(urllib.request.urlopen(req, timeout=25))["access_token"]
        self.gap, self.next_at, self.lock = 1 / rate, 0.0, threading.Lock()

    def get(self, url: str) -> tuple[int, dict | None]:
        for attempt in range(5):
            with self.lock:
                now = time.monotonic(); due = max(now, self.next_at); self.next_at = due + self.gap
            time.sleep(max(0, due - now))
            try:
                req = urllib.request.Request(url, headers={"Authorization": "Bearer " + self.token})
                with urllib.request.urlopen(req, timeout=30) as response:
                    return response.status, json.load(response)
            except urllib.error.HTTPError as error:
                if error.code == 429 or error.code >= 500:
                    time.sleep(min(16, 2 ** attempt)); continue
                return error.code, None
            except (TimeoutError, urllib.error.URLError):
                time.sleep(min(16, 2 ** attempt))
        return 599, None


def schema(cur) -> None:
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.character_armory_profiles (
      source_game text NOT NULL, public_id char(12) NOT NULL, region text NOT NULL,
      character_id bigint NOT NULL, realm_id bigint, realm_name text NOT NULL, realm_slug text NOT NULL,
      name text NOT NULL, name_slug text NOT NULL, level integer, class_id integer, class_name text,
      race_id integer, race_name text, faction text, gender text, guild_name text,
      average_item_level integer, equipped_item_level integer, achievement_points integer,
      last_login_at timestamptz, fetched_at timestamptz NOT NULL, profile_json jsonb NOT NULL,
      PRIMARY KEY(source_game,region,character_id), UNIQUE(source_game,public_id))""")
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.character_armory_snapshots (
      source_game text NOT NULL, region text NOT NULL, character_id bigint NOT NULL,
      snapshot_at timestamptz NOT NULL, profile_json jsonb NOT NULL,
      specializations_json jsonb, equipment_json jsonb, statistics_json jsonb,
      professions_json jsonb, achievements_json jsonb, reputations_json jsonb,
      pvp_summary_json jsonb, appearance_json jsonb, character_media_json jsonb,
      endpoint_status_json jsonb NOT NULL,
      PRIMARY KEY(source_game,region,character_id,snapshot_at))""")
    cur.execute("CREATE INDEX IF NOT EXISTS character_armory_profiles_name ON marketlens_import.character_armory_profiles(source_game,region,lower(name))")


def candidates(cur, source: str, region: str | None, limit: int, min_level: int, name: str | None) -> list[dict]:
    where = ["c.source_game=%s", "c.collection_region IN ('us','eu','kr','tw')", "c.level >= %s"]
    args: list[object] = [source, min_level]
    if region: where.append("c.collection_region=%s"); args.append(region)
    if name: where.append("lower(c.name)=lower(%s)"); args.append(name)
    args.append(limit)
    cur.execute(f"""SELECT DISTINCT ON (c.collection_region,lower(c.realm),lower(c.name))
      c.collection_region region,c.realm,c.name,c.level,c.last_seen
      FROM marketlens_import.characters_with_region c
      WHERE {' AND '.join(where)}
      ORDER BY c.collection_region,lower(c.realm),lower(c.name),c.last_seen DESC LIMIT %s""", args)
    rows = []
    for region_value, realm, char_name, level, last_seen in cur.fetchall():
        rows.append({"source": source, "region": region_value, "realm": realm, "realm_slug": slugify(realm),
                     "name": char_name, "name_slug": slugify(char_name), "level": level, "last_seen": last_seen})
    return rows


def fetch_one(api: Blizzard, row: dict) -> dict:
    status, profile = api.get(api_url(row["source"], row["region"], row["realm_slug"], row["name_slug"]))
    out = dict(row, status=status, profile=profile, endpoints={}, endpoint_status={})
    if status != 200 or not profile:
        return out
    for endpoint in ENDPOINTS:
        code, body = api.get(api_url(row["source"], row["region"], row["realm_slug"], row["name_slug"], endpoint))
        out["endpoint_status"][endpoint] = code
        if code == 200: out["endpoints"][endpoint] = body
    return out


def save(cur, row: dict) -> None:
    p = row["profile"]; now = datetime.now(timezone.utc); source = row["source"]; region = row["region"]
    realm = p.get("realm", {}); guild = p.get("guild", {})
    pid = public_id(source, region, row["realm"], row["name"])
    cur.execute("""INSERT INTO marketlens_import.character_armory_profiles
      (source_game,public_id,region,character_id,realm_id,realm_name,realm_slug,name,name_slug,level,
       class_id,class_name,race_id,race_name,faction,gender,guild_name,average_item_level,
       equipped_item_level,achievement_points,last_login_at,fetched_at,profile_json)
      VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,to_timestamp(%s/1000.0),%s,%s)
      ON CONFLICT(source_game,region,character_id) DO UPDATE SET public_id=excluded.public_id,
       realm_id=excluded.realm_id,realm_name=excluded.realm_name,realm_slug=excluded.realm_slug,
       name=excluded.name,name_slug=excluded.name_slug,level=excluded.level,class_id=excluded.class_id,
       class_name=excluded.class_name,race_id=excluded.race_id,race_name=excluded.race_name,
       faction=excluded.faction,gender=excluded.gender,guild_name=excluded.guild_name,
       average_item_level=excluded.average_item_level,equipped_item_level=excluded.equipped_item_level,
       achievement_points=excluded.achievement_points,last_login_at=excluded.last_login_at,
       fetched_at=excluded.fetched_at,profile_json=excluded.profile_json""",
      (source,pid,region,p["id"],realm.get("id"),realm.get("name",row["realm"]),realm.get("slug",row["realm_slug"]),
       p.get("name",row["name"]),row["name_slug"],p.get("level"),p.get("character_class",{}).get("id"),
       p.get("character_class",{}).get("name"),p.get("race",{}).get("id"),p.get("race",{}).get("name"),
       p.get("faction",{}).get("name"),p.get("gender",{}).get("name"),guild.get("name"),p.get("average_item_level"),
       p.get("equipped_item_level"),p.get("achievement_points"),p.get("last_login_timestamp"),now,Json(p)))
    e = row["endpoints"]
    cur.execute("""INSERT INTO marketlens_import.character_armory_snapshots
      (source_game,region,character_id,snapshot_at,profile_json,specializations_json,equipment_json,
       statistics_json,professions_json,achievements_json,reputations_json,pvp_summary_json,
       appearance_json,character_media_json,endpoint_status_json)
      VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
      (source,region,p["id"],now,Json(p),*[Json(e[x]) if x in e else None for x in ENDPOINTS],Json(row["endpoint_status"])))


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--source", required=True, choices=SOURCES)
    ap.add_argument("--region", choices=("us","eu","kr","tw"))
    ap.add_argument("--limit", type=int, default=100)
    ap.add_argument("--min-level", type=int, default=10)
    ap.add_argument("--name")
    ap.add_argument("--rate", type=float, default=5.0)
    ap.add_argument("--workers", type=int, default=4)
    args = ap.parse_args()
    conn = psycopg2.connect(DSN); conn.autocommit = False
    with conn:
        with conn.cursor() as cur: schema(cur); rows = candidates(cur,args.source,args.region,args.limit,args.min_level,args.name)
    api = Blizzard(args.rate); results = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
        for result in pool.map(lambda r: fetch_one(api,r), rows):
            results.append(result); print(result["status"],result["region"],result["realm"],result["name"],flush=True)
    with conn:
        with conn.cursor() as cur:
            for result in results:
                if result["status"] == 200: save(cur,result)
    conn.close()
    print(json.dumps({"source":args.source,"requested":len(rows),"saved":sum(r["status"]==200 for r in results)}))


if __name__ == "__main__": main()
