"""Cache Blizzard's WoW: Forever Armory for already-known census characters.

This is enrichment, never census ingestion. It discovers no population on its
own and writes only ``forever_armory_*`` provenance tables. Profiles are public,
level 10+, and reflect the last logout rather than an in-game sighting.

Examples:
  python tools/sync-forever-armory.py --limit 100
  python tools/sync-forever-armory.py --region us --name "Baldur Asmonson"
  python tools/sync-forever-armory.py --limit 500 --include guilds

Requires BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET. The default source is
the API-verified character table, then recent canonical census characters.
Runs are resumable: successful snapshots are refreshed only after --max-age,
and misses back off for --miss-age. Endpoint failures are recorded separately.
"""
from __future__ import annotations

import argparse
import base64
import concurrent.futures
import json
import os
import random
import re
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone

import psycopg2
from psycopg2.extras import Json, execute_values

DEFAULT_DSN = os.getenv("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")
REGIONS = ("us", "eu", "kr", "tw")
ENDPOINTS = ("specializations", "equipment", "statistics", "professions", "achievements",
             "reputations", "pvp-summary", "appearance", "character-media")
REGION_RE = re.compile(r"^CAMWOW (US|EU|KR|TW)", re.I)


def slugify(name: str) -> str:
    return "-".join(name.casefold().split())


def region_from_realm(realm: str | None) -> str | None:
    match = REGION_RE.match(realm or "")
    return match.group(1).lower() if match else None


def api_url(region: str, slug: str, endpoint: str = "") -> str:
    suffix = f"/{endpoint}" if endpoint else ""
    quoted = urllib.parse.quote(slug, safe="-")
    return (f"https://{region}.api.blizzard.com/profile/wow/character/{quoted}{suffix}"
            f"?namespace=profile-forever-{region}&locale=en_US")


def guild_url(region: str, slug: str) -> str:
    quoted = urllib.parse.quote(slug, safe="-")
    return (f"https://{region}.api.blizzard.com/data/wow/guild/{quoted}/roster"
            f"?namespace=profile-forever-{region}&locale=en_US")


def schema(cur) -> None:
    cur.execute("CREATE SCHEMA IF NOT EXISTS marketlens_import")
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.forever_armory_candidates (
      region text NOT NULL CHECK (region IN ('us','eu','kr','tw')), name text NOT NULL,
      slug text NOT NULL, discovery_source text NOT NULL, source_identity text,
      first_queued_at timestamptz NOT NULL DEFAULT now(), last_attempt_at timestamptz,
      next_attempt_at timestamptz, status text NOT NULL DEFAULT 'queued',
      attempts integer NOT NULL DEFAULT 0, last_http_status integer, last_error text,
      PRIMARY KEY(region,name))""")
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.forever_armory_runs (
      run_id bigserial PRIMARY KEY, started_at timestamptz NOT NULL DEFAULT now(),
      finished_at timestamptz, requested integer NOT NULL, fetched integer NOT NULL DEFAULT 0,
      not_found integer NOT NULL DEFAULT 0, failed integer NOT NULL DEFAULT 0,
      endpoint_failures integer NOT NULL DEFAULT 0, options jsonb NOT NULL)""")
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.forever_armory_state (
      key text PRIMARY KEY, value text NOT NULL, updated_at timestamptz NOT NULL DEFAULT now())""")
    # Upgrade an already-seeded database without replaying millions of verified rows.
    cur.execute("""DO $$ BEGIN IF to_regclass('marketlens_import.external_verified_characters') IS NOT NULL
      AND EXISTS (SELECT 1 FROM marketlens_import.forever_armory_candidates LIMIT 1) THEN
      EXECUTE 'INSERT INTO marketlens_import.forever_armory_state(key,value)
        SELECT ''external_verified_through'',max(verified_at)::text
        FROM marketlens_import.external_verified_characters HAVING max(verified_at) IS NOT NULL
        ON CONFLICT(key) DO NOTHING'; END IF; END $$""")
    cur.execute("""INSERT INTO marketlens_import.forever_armory_state(key,value)
      SELECT 'canonical_last_seen',max(last_seen)::text FROM marketlens_import.characters
      WHERE source_game='forever' AND EXISTS
        (SELECT 1 FROM marketlens_import.forever_armory_candidates LIMIT 1)
      HAVING max(last_seen) IS NOT NULL ON CONFLICT(key) DO NOTHING""")
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.forever_armory_profiles (
      region text NOT NULL, character_id bigint NOT NULL, name text NOT NULL, surname text,
      full_name text NOT NULL, slug text NOT NULL, ruleset_id integer, ruleset_name text,
      level integer, class_id integer, class_name text, race_id integer, race_name text,
      faction text, gender text, guild_id bigint, guild_name text, guild_slug text,
      average_item_level integer, equipped_item_level integer, achievement_points integer,
      last_login_at timestamptz, fetched_at timestamptz NOT NULL, run_id bigint NOT NULL,
      profile_json jsonb NOT NULL,
      PRIMARY KEY(region,character_id))""")
    cur.execute("""CREATE UNIQUE INDEX IF NOT EXISTS forever_armory_profiles_name
      ON marketlens_import.forever_armory_profiles(region,lower(full_name))""")
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.forever_armory_snapshots (
      region text NOT NULL, character_id bigint NOT NULL, snapshot_at timestamptz NOT NULL,
      last_login_at timestamptz, run_id bigint NOT NULL,
      profile_json jsonb NOT NULL, specializations_json jsonb, equipment_json jsonb,
      statistics_json jsonb, professions_json jsonb, achievements_json jsonb,
      reputations_json jsonb, pvp_summary_json jsonb, appearance_json jsonb,
      character_media_json jsonb, endpoint_status_json jsonb NOT NULL,
      PRIMARY KEY(region,character_id,snapshot_at))""")
    cur.execute("""CREATE INDEX IF NOT EXISTS forever_armory_snapshots_login
      ON marketlens_import.forever_armory_snapshots(region,character_id,last_login_at DESC)""")
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.forever_armory_talents (
      region text NOT NULL, character_id bigint NOT NULL, snapshot_at timestamptz NOT NULL,
      group_index integer NOT NULL, specialization_index integer NOT NULL,
      is_active boolean NOT NULL, specialization_id integer, specialization_name text,
      spent_points integer, talent_index integer NOT NULL, talent_id integer, talent_name text,
      talent_rank integer, spell_id integer, talent_json jsonb NOT NULL,
      PRIMARY KEY(region,character_id,snapshot_at,group_index,specialization_index,talent_index))""")
    cur.execute("""ALTER TABLE marketlens_import.forever_armory_talents
      ADD COLUMN IF NOT EXISTS talent_id integer, ADD COLUMN IF NOT EXISTS talent_name text,
      ADD COLUMN IF NOT EXISTS talent_rank integer, ADD COLUMN IF NOT EXISTS spell_id integer""")
    cur.execute("""UPDATE marketlens_import.forever_armory_talents SET
      talent_id=NULLIF(talent_json#>>'{talent,id}','')::integer,
      talent_name=talent_json#>>'{talent,name}',
      talent_rank=NULLIF(talent_json->>'talent_rank','')::integer,
      spell_id=NULLIF(talent_json#>>'{spell_tooltip,spell,id}','')::integer
      WHERE talent_id IS NULL AND talent_json ? 'talent'""")
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.forever_armory_guild_rosters (
      region text NOT NULL, guild_id bigint NOT NULL, guild_slug text NOT NULL,
      fetched_at timestamptz NOT NULL, run_id bigint NOT NULL, roster_json jsonb NOT NULL,
      PRIMARY KEY(region,guild_id,fetched_at))""")
    cur.execute("""CREATE OR REPLACE VIEW marketlens_import.forever_armory_current_builds AS
      SELECT p.region,p.character_id,p.full_name,p.ruleset_name,p.class_name,p.level,
        p.last_login_at,p.fetched_at,t.group_index,t.specialization_index,t.is_active,
        t.specialization_id,t.specialization_name,t.spent_points,
        jsonb_agg(jsonb_build_object('id',t.talent_id,'name',t.talent_name,'rank',t.talent_rank,
          'spell_id',t.spell_id) ORDER BY t.talent_index)
          FILTER (WHERE t.talent_id IS NOT NULL) AS talents
      FROM marketlens_import.forever_armory_profiles p
      JOIN marketlens_import.forever_armory_talents t ON t.region=p.region
        AND t.character_id=p.character_id AND t.snapshot_at=(
          SELECT max(s.snapshot_at) FROM marketlens_import.forever_armory_snapshots s
          WHERE s.region=p.region AND s.character_id=p.character_id)
      GROUP BY p.region,p.character_id,p.full_name,p.ruleset_name,p.class_name,p.level,
        p.last_login_at,p.fetched_at,t.group_index,t.specialization_index,t.is_active,
        t.specialization_id,t.specialization_name,t.spent_points""")


class Blizzard:
    def __init__(self, rate: float):
        cid, secret = os.getenv("BLIZZARD_CLIENT_ID"), os.getenv("BLIZZARD_CLIENT_SECRET")
        if not cid or not secret:
            raise SystemExit("set BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET")
        basic = base64.b64encode(f"{cid}:{secret}".encode()).decode()
        req = urllib.request.Request("https://us.battle.net/oauth/token", data=b"grant_type=client_credentials",
                                     headers={"Authorization": "Basic " + basic})
        with urllib.request.urlopen(req, timeout=25) as response:
            self.token = json.load(response)["access_token"]
        self.gap, self.next, self.lock = 1 / rate, 0.0, threading.Lock()

    def get(self, url: str) -> tuple[int, dict | None, str | None]:
        for attempt in range(4):
            with self.lock:
                now = time.monotonic(); due = max(now, self.next); self.next = due + self.gap
            time.sleep(max(0, due - now))
            try:
                req = urllib.request.Request(url, headers={"Authorization": "Bearer " + self.token})
                with urllib.request.urlopen(req, timeout=30) as response:
                    return response.status, json.load(response), None
            except urllib.error.HTTPError as error:
                if error.code == 404:
                    return 404, None, None
                if error.code in (429, 500, 502, 503, 504) and attempt < 3:
                    time.sleep(min(2 ** (attempt + 1), 12)); continue
                return error.code, None, f"HTTP {error.code}"
            except (urllib.error.URLError, TimeoutError, ValueError) as error:
                if attempt < 3:
                    time.sleep(min(2 ** (attempt + 1), 12)); continue
                return 0, None, type(error).__name__
        return 0, None, "retry exhausted"


def queue_known(cur, recent_days: int) -> int:
    cur.execute("SELECT to_regclass('marketlens_import.external_verified_characters')")
    has_verified = cur.fetchone()[0] is not None
    cur.execute("SELECT value::timestamptz FROM marketlens_import.forever_armory_state WHERE key='external_verified_through'")
    row = cur.fetchone(); cutoff = row[0] if row else datetime.fromtimestamp(0, timezone.utc)
    if has_verified:
        cur.execute("""INSERT INTO marketlens_import.forever_armory_candidates
      (region,name,slug,discovery_source,source_identity)
      SELECT region,name,lower(replace(name,' ','-')),'blizzard-verified-foreverpulse',forever_pulse_id::text
      FROM marketlens_import.external_verified_characters WHERE verified_at>%s
      ON CONFLICT(region,name) DO NOTHING
      RETURNING 1""", (cutoff,))
        count = cur.rowcount
        cur.execute("""INSERT INTO marketlens_import.forever_armory_state(key,value)
      SELECT 'external_verified_through',max(verified_at)::text FROM marketlens_import.external_verified_characters
      HAVING max(verified_at) IS NOT NULL ON CONFLICT(key) DO UPDATE SET
      value=excluded.value,updated_at=now()""")
    else:
        count = 0
    cur.execute("SELECT value::bigint FROM marketlens_import.forever_armory_state WHERE key='canonical_last_seen'")
    state = cur.fetchone(); seen_cutoff = state[0] if state else 0
    cur.execute("""SELECT name,realm,character_key FROM marketlens_import.characters
      WHERE source_game='forever' AND level>=10 AND last_seen>%s
        AND last_seen>=extract(epoch from now()-%s::interval)
      ORDER BY last_seen DESC""", (seen_cutoff, f"{recent_days} days"))
    candidates = {}
    for name, realm, identity in cur.fetchall():
        region = region_from_realm(realm)
        if region and name and len(name.split()) >= 2:
            candidates.setdefault((region, name.casefold()),
                                  (region, name, slugify(name), "wowcensus", identity))
    values = list(candidates.values())
    if values:
        execute_values(cur, """INSERT INTO marketlens_import.forever_armory_candidates
          (region,name,slug,discovery_source,source_identity) VALUES %s
          ON CONFLICT(region,name) DO UPDATE SET slug=excluded.slug""", values, page_size=1000)
    cur.execute("""INSERT INTO marketlens_import.forever_armory_state(key,value)
      SELECT 'canonical_last_seen',max(last_seen)::text FROM marketlens_import.characters
      WHERE source_game='forever' HAVING max(last_seen) IS NOT NULL ON CONFLICT(key) DO UPDATE SET
      value=excluded.value,updated_at=now()""")
    return count + len(values)


def specialization_rows(body: dict | None):
    rows = []
    for gi, group in enumerate((body or {}).get("specialization_groups", [])):
        for si, spec in enumerate(group.get("specializations", [])):
            info = spec.get("specialization") or {}
            talents = spec.get("talents") or []
            if not talents:
                talents = [None]
            for ti, talent in enumerate(talents):
                talent = talent or {}
                talent_info, spell = talent.get("talent") or {}, (talent.get("spell_tooltip") or {}).get("spell") or {}
                rows.append((gi, si, bool(group.get("is_active")), info.get("id"),
                             spec.get("specialization_name") or info.get("name"), spec.get("spent_points"),
                             ti, talent_info.get("id"), talent_info.get("name"), talent.get("talent_rank"),
                             spell.get("id"), talent))
    return rows


def fetch_character(api: Blizzard, candidate: tuple, include: set[str]) -> dict:
    region, name, slug = candidate
    status, profile, error = api.get(api_url(region, slug))
    result = {"region": region, "name": name, "slug": slug, "status": status,
              "profile": profile, "error": error, "endpoints": {}, "endpoint_status": {}}
    if status != 200:
        return result
    for endpoint in ENDPOINTS:
        if "all" not in include and endpoint not in include:
            continue
        code, body, err = api.get(api_url(region, slug, endpoint))
        key = endpoint.replace("-", "_")
        result["endpoint_status"][endpoint] = {"status": code, "error": err}
        if code == 200:
            result["endpoints"][key] = body
    return result


def dt_ms(value):
    return datetime.fromtimestamp(value / 1000, timezone.utc) if value else None


def store_result(cur, run_id: int, result: dict, miss_age: int) -> tuple[str, int]:
    now = datetime.now(timezone.utc); region, name, slug = result["region"], result["name"], result["slug"]
    if result["status"] != 200:
        state = "not_found" if result["status"] == 404 else "failed"
        cur.execute("""UPDATE marketlens_import.forever_armory_candidates SET
          status=%s,attempts=attempts+1,last_attempt_at=now(),next_attempt_at=now()+%s::interval,
          last_http_status=%s,last_error=%s WHERE region=%s AND name=%s""",
                    (state, f"{miss_age if state == 'not_found' else 1} days", result["status"],
                     result["error"], region, name))
        return state, 0
    p, endpoints = result["profile"], result["endpoints"]
    character_id = int(p["id"]); full = " ".join(str(p.get("name_search") or f"{p.get('name','')} {p.get('surname','')}").split())
    ruleset, cls, race, guild = p.get("ruleset") or {}, p.get("character_class") or {}, p.get("race") or {}, p.get("guild") or {}
    last_login = dt_ms(p.get("last_login_timestamp")); snapshot_at = now
    cur.execute("""INSERT INTO marketlens_import.forever_armory_profiles
      (region,character_id,name,surname,full_name,slug,ruleset_id,ruleset_name,level,class_id,class_name,
       race_id,race_name,faction,gender,guild_id,guild_name,guild_slug,average_item_level,equipped_item_level,
       achievement_points,last_login_at,fetched_at,run_id,profile_json)
      VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
      ON CONFLICT(region,character_id) DO UPDATE SET name=excluded.name,surname=excluded.surname,
       full_name=excluded.full_name,slug=excluded.slug,ruleset_id=excluded.ruleset_id,ruleset_name=excluded.ruleset_name,
       level=excluded.level,class_id=excluded.class_id,class_name=excluded.class_name,race_id=excluded.race_id,
       race_name=excluded.race_name,faction=excluded.faction,gender=excluded.gender,guild_id=excluded.guild_id,
       guild_name=excluded.guild_name,guild_slug=excluded.guild_slug,average_item_level=excluded.average_item_level,
       equipped_item_level=excluded.equipped_item_level,achievement_points=excluded.achievement_points,
       last_login_at=excluded.last_login_at,fetched_at=excluded.fetched_at,run_id=excluded.run_id,
       profile_json=excluded.profile_json""",
      (region, character_id, p.get("name"), p.get("surname"), full, p.get("slug") or slug,
       ruleset.get("id"), ruleset.get("name"), p.get("level"), cls.get("id"), cls.get("name"),
       race.get("id"), race.get("name"), (p.get("faction") or {}).get("name"), (p.get("gender") or {}).get("name"),
       guild.get("id"), guild.get("name"), guild.get("slug"), p.get("average_item_level"),
       p.get("equipped_item_level"), p.get("achievement_points"), last_login, now, run_id, Json(p)))
    values = {key: Json(endpoints[key]) if key in endpoints else None for key in
              ("specializations", "equipment", "statistics", "professions", "achievements", "reputations",
               "pvp_summary", "appearance", "character_media")}
    cur.execute("""INSERT INTO marketlens_import.forever_armory_snapshots
      (region,character_id,snapshot_at,last_login_at,run_id,profile_json,specializations_json,equipment_json,
       statistics_json,professions_json,achievements_json,reputations_json,pvp_summary_json,appearance_json,
       character_media_json,endpoint_status_json) VALUES
      (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
      (region, character_id, snapshot_at, last_login, run_id, Json(p), values["specializations"], values["equipment"],
       values["statistics"], values["professions"], values["achievements"], values["reputations"],
       values["pvp_summary"], values["appearance"], values["character_media"], Json(result["endpoint_status"])))
    talents = specialization_rows(endpoints.get("specializations"))
    if talents:
        execute_values(cur, """INSERT INTO marketlens_import.forever_armory_talents
          (region,character_id,snapshot_at,group_index,specialization_index,is_active,specialization_id,
           specialization_name,spent_points,talent_index,talent_id,talent_name,talent_rank,spell_id,talent_json) VALUES %s""",
          [(region, character_id, snapshot_at, *row[:-1], Json(row[-1])) for row in talents])
    cur.execute("""UPDATE marketlens_import.forever_armory_candidates SET status='ok',attempts=attempts+1,
      last_attempt_at=now(),next_attempt_at=NULL,last_http_status=200,last_error=NULL,slug=%s
      WHERE region=%s AND name=%s""", (p.get("slug") or slug, region, name))
    failures = sum(1 for x in result["endpoint_status"].values() if x["status"] != 200)
    return "ok", failures


def store_guild(cur, api: Blizzard, run_id: int, region: str, guild_id: int, slug: str) -> tuple[bool, int]:
    status, body, _ = api.get(guild_url(region, slug))
    if status != 200:
        return False, status
    now = datetime.now(timezone.utc)
    cur.execute("""INSERT INTO marketlens_import.forever_armory_guild_rosters
      (region,guild_id,guild_slug,fetched_at,run_id,roster_json) VALUES(%s,%s,%s,%s,%s,%s)""",
                (region, guild_id, slug, now, run_id, Json(body)))
    members = []
    for entry in body.get("members", []):
        character = entry.get("character") or {}
        name = " ".join(str(character.get("name_search") or
                            f"{character.get('name','')} {character.get('surname','')}").split())
        member_slug = character.get("slug") or slugify(name)
        if name and len(name.split()) >= 2:
            members.append((region, name, member_slug, "blizzard-guild-roster", f"{guild_id}:{entry.get('rank')}"))
    if members:
        execute_values(cur, """INSERT INTO marketlens_import.forever_armory_candidates
          (region,name,slug,discovery_source,source_identity) VALUES %s ON CONFLICT(region,name) DO NOTHING""",
                       members, page_size=1000)
    return True, len(members)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dsn", default=DEFAULT_DSN)
    parser.add_argument("--limit", type=int, default=100)
    parser.add_argument("--region", choices=REGIONS)
    parser.add_argument("--name", help="exact two-part name; requires --region")
    parser.add_argument("--include", nargs="+", default=["all"], choices=("all", "guilds", *ENDPOINTS))
    parser.add_argument("--rate", type=float, default=8.0, help="all HTTP requests per second")
    parser.add_argument("--workers", type=int, default=6)
    parser.add_argument("--max-age", type=int, default=24, help="hours before refreshing a success")
    parser.add_argument("--miss-age", type=int, default=7, help="days before retrying a 404")
    parser.add_argument("--recent-days", type=int, default=14, help="canonical characters to queue")
    parser.add_argument("--seed", type=int, default=1)
    args = parser.parse_args()
    if args.name and not args.region:
        parser.error("--name requires --region")
    conn = psycopg2.connect(args.dsn)
    with conn, conn.cursor() as cur:
        cur.execute("SELECT pg_advisory_xact_lock(hashtext('marketlens-forever-armory-schema'))")
        schema(cur)
        if args.name:
            cur.execute("""INSERT INTO marketlens_import.forever_armory_candidates
              (region,name,slug,discovery_source) VALUES (%s,%s,%s,'explicit')
              ON CONFLICT(region,name) DO UPDATE SET slug=excluded.slug,discovery_source='explicit',next_attempt_at=NULL""",
                        (args.region, args.name, slugify(args.name)))
        else:
            queue_known(cur, args.recent_days)
    with conn.cursor() as cur:
        def due(region, limit):
            where = ["(c.next_attempt_at IS NULL OR c.next_attempt_at<=now())", "c.region=%s"]
            values = [region]
            if args.name: where.append("lower(c.name)=lower(%s)"); values.append(args.name)
            values.extend([args.max_age, limit])
            cur.execute(f"""SELECT c.region,c.name,c.slug FROM marketlens_import.forever_armory_candidates c
              LEFT JOIN marketlens_import.forever_armory_profiles p
                ON p.region=c.region AND lower(p.full_name)=lower(c.name)
              WHERE {' AND '.join(where)} AND (p.fetched_at IS NULL OR p.fetched_at<now()-(%s||' hours')::interval)
              ORDER BY c.status='queued' DESC,c.last_attempt_at NULLS FIRST,c.first_queued_at LIMIT %s""", values)
            return cur.fetchall()
        if args.region:
            candidates = due(args.region, args.limit)
        else:
            share = max(1, (args.limit + len(REGIONS) - 1) // len(REGIONS))
            candidates = [row for region in REGIONS for row in due(region, share)][:args.limit]
        random.Random(args.seed).shuffle(candidates)
        cur.execute("INSERT INTO marketlens_import.forever_armory_runs(requested,options) VALUES(%s,%s) RETURNING run_id",
                    (len(candidates), Json(vars(args))))
        run_id = cur.fetchone()[0]
    conn.commit()
    api = Blizzard(args.rate); stats = {"ok": 0, "not_found": 0, "failed": 0, "endpoint_failures": 0}
    include = set(args.include)
    guilds = set()
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
        futures = [pool.submit(fetch_character, api, row, include) for row in candidates]
        for future in concurrent.futures.as_completed(futures):
            result = future.result()
            try:
                with conn, conn.cursor() as cur:
                    state, endpoint_failures = store_result(cur, run_id, result, args.miss_age)
                stats[state] += 1; stats["endpoint_failures"] += endpoint_failures
                profile = result.get("profile") or {}; guild = profile.get("guild") or {}
                if state == "ok" and guild.get("id") and guild.get("slug"):
                    guilds.add((result["region"], int(guild["id"]), guild["slug"]))
                print(f"{result['region']} {result['name']}: {state}", flush=True)
            except Exception:
                conn.rollback()
                raise
    if "guilds" in include:
        for region, guild_id, slug in sorted(guilds):
            with conn, conn.cursor() as cur:
                ok, members = store_guild(cur, api, run_id, region, guild_id, slug)
            print(f"{region} guild {slug}: {'ok '+str(members)+' members' if ok else 'failed '+str(members)}", flush=True)
    with conn, conn.cursor() as cur:
        cur.execute("""UPDATE marketlens_import.forever_armory_runs SET finished_at=now(),fetched=%s,
          not_found=%s,failed=%s,endpoint_failures=%s WHERE run_id=%s""",
                    (stats["ok"], stats["not_found"], stats["failed"], stats["endpoint_failures"], run_id))
    conn.close()
    print(json.dumps({"run_id": run_id, "requested": len(candidates), **stats}))


if __name__ == "__main__":
    main()
