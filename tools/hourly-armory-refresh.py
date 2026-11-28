#!/usr/bin/env python3
"""Refresh a rotating batch of Blizzard Armory profiles across WoWCensus editions."""
import argparse
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import json
import os
import runpy
import shutil
import ssl
import threading
import time
import urllib.error
import urllib.request

import psycopg2
from psycopg2.extras import Json, execute_values

TOOLS = Path(__file__).resolve().parent
STANDARD = runpy.run_path(str(TOOLS / "sync-character-armory.py"))
FOREVER = runpy.run_path(str(TOOLS / "sync-forever-armory.py"))
SOURCES = (*STANDARD["SOURCES"], "forever")
REGIONS = ("us", "eu", "kr", "tw")
ENDPOINTS = ("equipment", "specializations", "character-media")

SCHEMA = """
CREATE TABLE IF NOT EXISTS marketlens_import.hourly_armory_queue (
    source text NOT NULL, region text NOT NULL, realm text NOT NULL, name text NOT NULL,
    realm_key text NOT NULL, name_key text NOT NULL,
    due_at timestamptz NOT NULL DEFAULT '-infinity',
    last_status integer,
    PRIMARY KEY (source, region, realm_key, name_key)
);
CREATE INDEX IF NOT EXISTS hourly_armory_queue_due
    ON marketlens_import.hourly_armory_queue (source, region, due_at, realm_key, name_key);
CREATE TABLE IF NOT EXISTS marketlens_import.hourly_armory_seed (
    source text PRIMARY KEY, seeded_at timestamptz NOT NULL
);
ALTER TABLE marketlens_import.hourly_armory_seed
    ADD COLUMN IF NOT EXISTS inventory_version integer NOT NULL DEFAULT 1;
CREATE TABLE IF NOT EXISTS marketlens_import.hourly_armory_cache (
    path text PRIMARY KEY, queued_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS marketlens_import.raiderio_character_discoveries (
    region text NOT NULL, realm_key text NOT NULL, name_key text NOT NULL,
    raiderio_id bigint NOT NULL, season text NOT NULL, discovered_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (region, realm_key, name_key)
);
CREATE TABLE IF NOT EXISTS marketlens_import.blizzard_character_discoveries (
    source text NOT NULL, region text NOT NULL, realm_key text NOT NULL, name_key text NOT NULL,
    character_id bigint, feed text NOT NULL, evidence text NOT NULL,
    discovered_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (source, region, realm_key, name_key)
);
"""

STANDARD_INVENTORY = """
SELECT collection_region, realm, name, '-infinity'::timestamptz
FROM marketlens_import.characters_with_region
WHERE source_game=%s AND collection_region IN ('us','eu','kr','tw')
  AND realm<>'' AND name<>''
UNION ALL
SELECT region, realm_name, name, fetched_at + interval '7 days'
FROM marketlens_import.character_armory_profiles WHERE source_game=%s
UNION ALL
SELECT region, realm, name, checked_at + interval '7 days'
FROM marketlens_import.character_armory_misses WHERE source_game=%s
"""

FOREVER_INVENTORY = """
SELECT COALESCE(lower(substring(realm from '^CAMWOW (US|EU|KR|TW)')), collection_region),
       '', name, '-infinity'::timestamptz
FROM marketlens_import.characters_with_region
WHERE source_game=%s AND name LIKE '%% %%'
UNION ALL
SELECT region, '', name, '-infinity'::timestamptz FROM marketlens_import.external_verified_characters
UNION ALL
SELECT region, '', full_name, fetched_at + interval '7 days' FROM marketlens_import.forever_armory_profiles
UNION ALL
SELECT region, '', name, COALESCE(next_attempt_at, '-infinity') FROM marketlens_import.forever_armory_candidates
"""

SELECT_DUE = """
WITH scopes AS (
    SELECT source, region FROM unnest(%s::text[]) AS s(source)
    CROSS JOIN unnest(%s::text[]) AS r(region)
), by_region AS (
    SELECT q.*, first_attempt, row_number() OVER (PARTITION BY q.source,q.region,first_attempt
        ORDER BY q.due_at,q.realm_key,q.name_key) AS region_rank
    FROM scopes s CROSS JOIN (VALUES (true),(false)) AS lane(first_attempt)
    CROSS JOIN LATERAL (
        SELECT * FROM marketlens_import.hourly_armory_queue q
        WHERE (q.source,q.region)=(s.source,s.region)
          AND q.due_at >= CASE WHEN first_attempt THEN '-infinity'::timestamptz ELSE to_timestamp(0) END
          AND q.due_at <= CASE WHEN first_attempt THEN '-infinity'::timestamptz ELSE now() END
        ORDER BY q.due_at,q.realm_key,q.name_key LIMIT %s
    ) q
), by_lane AS (
    SELECT *, row_number() OVER (PARTITION BY source,first_attempt ORDER BY region_rank,due_at,region)
        AS lane_rank FROM by_region
), by_source AS (
    SELECT *, row_number() OVER (PARTITION BY source ORDER BY
        CASE WHEN first_attempt THEN lane_rank + (lane_rank - 1)/3 ELSE lane_rank*4 END)
        AS edition_rank FROM by_lane
)
SELECT source,region,realm,name,realm_key,name_key
FROM by_source ORDER BY edition_rank,due_at,source LIMIT %s
"""

SELECT_SHUFFLED = """
WITH candidates AS (
    SELECT *, random() AS shuffle FROM marketlens_import.hourly_armory_queue TABLESAMPLE SYSTEM (0.12)
    WHERE source=ANY(%s) AND region=ANY(%s) AND due_at<=now()
), ranked AS (
    SELECT *, row_number() OVER (PARTITION BY source,region
        ORDER BY (due_at='-infinity') DESC,shuffle) AS region_rank
    FROM candidates
), mixed AS (
    SELECT *, row_number() OVER (PARTITION BY source ORDER BY region_rank,shuffle) AS edition_rank
    FROM ranked
)
SELECT source,region,realm,name,realm_key,name_key
FROM mixed ORDER BY (edition_rank<=5) DESC, (due_at='-infinity') DESC, shuffle LIMIT %s
"""


class WorkerClient:
    def __init__(self, rate):
        self.origin = os.environ["ARMORY_WORKER_URL"].rstrip("/")
        self.key = os.environ["ARMORY_WORKER_KEY"]
        self.gap = 1 / rate
        self.lock = threading.Lock()
        self.direct = STANDARD["Blizzard"](rate)
        self.next_at = 0

    def get(self, url):
        if not url.startswith(("https://us.api.blizzard.com/", "https://eu.api.blizzard.com/")):
            return self.direct.get(url)
        for attempt in range(3):
            with self.lock:
                now = time.monotonic()
                due = max(now, self.next_at)
                self.next_at = due + self.gap
            time.sleep(max(0, due - now))
            try:
                req = urllib.request.Request(
                    self.origin + "/internal/profile", data=json.dumps({"url": url}).encode(),
                    headers={"Authorization": "Bearer " + self.key, "Content-Type": "application/json",
                             "User-Agent": "WoWCensusArmory/1.0"},
                )
                with urllib.request.urlopen(req, timeout=35) as response:
                    result = json.load(response)
                status = result["status"]
                if status not in (429, 500, 502, 503, 504):
                    return status, result.get("body")
            except (OSError, ValueError, KeyError):
                pass
            time.sleep(2 ** attempt)
        return 599, None

    def report(self, results):
        request = urllib.request.Request(
            self.origin + "/internal/armory-runs", data=json.dumps(results).encode(),
            headers={"Authorization": "Bearer " + self.key, "Content-Type": "application/json",
                     "User-Agent": "WoWCensusArmory/1.0"},
        )
        with urllib.request.urlopen(request, timeout=20):
            pass


def seed(conn):
    for source in SOURCES:
        with conn, conn.cursor() as cur:
            cur.execute("""SELECT 1 FROM marketlens_import.hourly_armory_seed
                WHERE source=%s AND inventory_version=2 AND seeded_at>now()-interval '1 day'""", (source,))
            if cur.fetchone():
                continue
            inventory = FOREVER_INVENTORY if source == "forever" else STANDARD_INVENTORY
            args = (source,) if source == "forever" else (source, source, source)
            cur.execute(f"""
                INSERT INTO marketlens_import.hourly_armory_queue
                    (source,region,realm,name,realm_key,name_key,due_at)
                SELECT DISTINCT ON (region,lower(realm),lower(name))
                    %s,region,realm,name,lower(realm),lower(name),due_at
                FROM ({inventory}) inventory(region,realm,name,due_at)
                WHERE region IN ('us','eu','kr','tw') AND name<>''
                ORDER BY region,lower(realm),lower(name),due_at DESC,realm,name
                ON CONFLICT DO NOTHING
            """, (source, *args))
            print(f"Inventory {source}: added {cur.rowcount}", flush=True)
            cur.execute("""INSERT INTO marketlens_import.hourly_armory_seed(source,seeded_at,inventory_version)
                VALUES (%s,now(),2) ON CONFLICT (source) DO UPDATE
                SET seeded_at=excluded.seeded_at,inventory_version=excluded.inventory_version""", (source,))


class ForeverClient:
    def __init__(self, api):
        self.api = api

    def get(self, url):
        status, body = self.api.get(url)
        return status, body, None if status == 200 else f"HTTP {status}"


def fetch(api, candidate):
    source, region, realm, name, _, _ = candidate
    if source == "forever":
        result = FOREVER["fetch_character"](
            ForeverClient(api), (region, name, FOREVER["slugify"](name)), set(ENDPOINTS)
        )
        profile = result["profile"] or {}
        actual_name = " ".join((profile.get("name_search") or
                               f"{profile.get('name', '')} {profile.get('surname', '')}").split())
        identity_matches = actual_name.lower() == name.lower()
    else:
        slugify = STANDARD["slugify"]
        # The request slug retains letters such as sharp S that casefold expands.
        row = dict(source=source, region=region, realm=realm, name=name,
                   realm_slug=slugify(realm),
                   name_slug="-".join(name.lower().replace("'", "").split()))
        result = STANDARD["fetch_one"](api, row, ENDPOINTS)
        profile = result["profile"] or {}
        actual_realm = profile.get("realm") or {}
        identity_matches = ((profile.get("name") or "").lower() == name.lower()
                            and actual_realm.get("slug") == row["realm_slug"])
    if result["status"] == 200 and (not profile.get("id") or not identity_matches):
        result["status"] = 409
    return result


def save(cur, candidate, result, forever_run, queue_cache):
    source, region, realm, name, realm_key, name_key = candidate
    status = result["status"]
    if status == 200 and source == "forever":
        FOREVER["store_result"](cur, forever_run, result, 7)
        cur.execute("""SELECT public_id FROM marketlens_import.forever_armory_profiles
            WHERE region=%s AND character_id=%s""", (region, result["profile"]["id"]))
        edition, page = source, cur.fetchone()[0]
    elif status == 200:
        STANDARD["save"](cur, result, preserve_missing=True)
        edition = STANDARD["SOURCES"][source][0]
        page = STANDARD["public_id"](source, region, realm, name)
    if status == 200 and queue_cache:
        cur.execute("""INSERT INTO marketlens_import.hourly_armory_cache(path) VALUES (%s)
            ON CONFLICT (path) DO UPDATE SET queued_at=excluded.queued_at""",
                    (f"/{edition}/character/{page}/",))
    due = "7 days" if status in (200, 403, 404, 409) else "1 hour"
    cur.execute("""UPDATE marketlens_import.hourly_armory_queue
        SET due_at=now()+%s::interval,last_status=%s
        WHERE (source,region,realm_key,name_key)=(%s,%s,%s,%s)""",
                (due, status, source, region, realm_key, name_key))


def refresh_cache(conn, only_paths=None, page_timeout=10):
    host = "wowcensus.io"
    zone = os.environ["CF_ZONE_ID"]
    token = os.environ["CF_API_TOKEN"]
    context = ssl._create_unverified_context()
    with conn, conn.cursor() as cur:
        cur.execute("""SELECT path, queued_at FROM marketlens_import.hourly_armory_cache
            WHERE CASE WHEN %s::text[] IS NULL THEN path LIKE '%%/character/%%'
                       ELSE path = ANY(%s::text[]) END
            ORDER BY queued_at LIMIT 1200""", (only_paths, only_paths))
        paths = dict(cur.fetchall())
    refreshed = []
    deadline = time.monotonic() + 600
    for path in paths:
        if time.monotonic() >= deadline:
            break
        request = urllib.request.Request(f"https://127.0.0.1{path}", headers={
            "Host": host, "X-WoWCensus-Refresh": "1", "Accept": "text/html",
        })
        try:
            with urllib.request.urlopen(request, context=context, timeout=page_timeout) as response:
                if response.status == 200 and f"https://{host}{path}".encode() in response.read():
                    refreshed.append(path)
        except OSError as exc:
            print(f"Origin refresh deferred for {path}: {exc}", flush=True)
    purged = 0
    for offset in range(0, len(refreshed), 25):
        batch = refreshed[offset:offset + 25]
        request = urllib.request.Request(f"https://api.cloudflare.com/client/v4/zones/{zone}/purge_cache",
            headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
            data=json.dumps({"files": [f"https://{host}{path}" for path in batch]}).encode())
        try:
            with urllib.request.urlopen(request, timeout=25) as response:
                if not json.load(response).get("success"):
                    raise ValueError("Cloudflare rejected cache purge")
        except (OSError, ValueError) as exc:
            print(f"Cache purge deferred: {exc}", flush=True)
            break
        with conn, conn.cursor() as cur:
            execute_values(cur, """DELETE FROM marketlens_import.hourly_armory_cache AS pending
                USING (VALUES %s) AS refreshed(path, queued_at)
                WHERE pending.path=refreshed.path AND pending.queued_at=refreshed.queued_at""",
                [(path, paths[path]) for path in batch])
        purged += len(batch)
    print(f"Cache: {purged} refreshed and purged, {len(paths) - purged} deferred", flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dsn", default=STANDARD["DSN"])
    parser.add_argument("--limit", type=int, default=1000)
    parser.add_argument("--rate", type=float, default=8)
    parser.add_argument("--workers", type=int, default=10)
    parser.add_argument("--refresh-cache", action="store_true")
    parser.add_argument("--storage-path", type=Path)
    parser.add_argument("--min-free-gib", type=int, default=12)
    args = parser.parse_args()
    if not 1 <= args.limit <= 1000 or not (args.rate > 0 and args.workers > 0):
        parser.error("use a limit of 1–1000 and positive rate and worker counts")
    if args.storage_path and shutil.disk_usage(args.storage_path).free < args.min_free_gib * 1024**3:
        raise SystemExit(f"Armory refresh deferred: less than {args.min_free_gib} GiB free at {args.storage_path}")

    conn = psycopg2.connect(args.dsn, application_name="wowcensus-hourly-armory")
    with conn, conn.cursor() as cur:
        cur.execute("SELECT pg_try_advisory_lock(hashtext('wowcensus-hourly-armory'))")
        if not cur.fetchone()[0]:
            print("An hourly Armory job already holds the lock", flush=True)
            return
        cur.execute(SCHEMA)
    seed(conn)
    with conn, conn.cursor() as cur:
        # Reserve an indexed slice so rare queue entries advance even when
        # their heap pages do not appear in a random sample.
        reserve = max(1, args.limit // 10)
        cur.execute("""WITH discovered AS (
            SELECT 'retail' AS source,region,realm_key,name_key,'Raider.IO' AS feed,discovered_at
              FROM marketlens_import.raiderio_character_discoveries
            UNION ALL SELECT source,region,realm_key,name_key,feed,discovered_at
              FROM marketlens_import.blizzard_character_discoveries
        ), pending AS (
            SELECT q.source,q.region,q.realm,q.name,q.realm_key,q.name_key,d.feed,d.discovered_at,
                row_number() OVER(PARTITION BY q.source,q.region,d.feed
                  ORDER BY d.discovered_at,q.realm_key,q.name_key) AS rank
            FROM discovered d
            JOIN marketlens_import.hourly_armory_queue q
              USING(source,region,realm_key,name_key)
            WHERE q.last_status IS NULL AND q.due_at<=now()
        )
        SELECT source,region,realm,name,realm_key,name_key,feed FROM pending
        ORDER BY rank,discovered_at,source,region,realm_key,name_key LIMIT %s""", (reserve,))
        discoveries = {row[:6]: row[6] for row in cur.fetchall()}
        cur.execute(SELECT_DUE, (list(SOURCES), list(REGIONS), reserve, reserve))
        candidates = list(discoveries)
        candidates.extend(row for row in cur if row not in discoveries)
        selected = set(candidates)
        cur.execute(SELECT_SHUFFLED, (list(SOURCES), list(REGIONS), args.limit))
        candidates.extend(row for row in cur if row not in selected)
        candidates = candidates[:args.limit]
        if len(candidates) < args.limit:
            cur.execute(SELECT_DUE, (list(SOURCES), list(REGIONS), args.limit, args.limit))
            selected = set(candidates)
            candidates.extend(row for row in cur if row not in selected)
            candidates = candidates[:args.limit]
    print(f"Selected {len(candidates)}: {dict(Counter(row[0] for row in candidates))}", flush=True)
    forever_count = sum(row[0] == "forever" for row in candidates)
    counts = Counter()
    forever_counts = Counter()
    endpoint_failures = 0

    if candidates:
        api = WorkerClient(args.rate) if os.getenv("ARMORY_WORKER_URL") else STANDARD["Blizzard"](args.rate)
        report = []
        forever_run = None
        if forever_count:
            with conn, conn.cursor() as cur:
                cur.execute("""INSERT INTO marketlens_import.forever_armory_runs(requested,options)
                    VALUES (%s,%s) RETURNING run_id""", (forever_count, Json({"source": "hourly"})))
                forever_run = cur.fetchone()[0]
        with ThreadPoolExecutor(max_workers=args.workers) as pool:
            for offset in range(0, len(candidates), 25):
                batch = candidates[offset:offset + 25]
                results = list(pool.map(lambda row: fetch(api, row), batch))
                with conn, conn.cursor() as cur:
                    for candidate, result in zip(batch, results):
                        save(cur, candidate, result, forever_run, args.refresh_cache)
                        status = result["status"]
                        counts[status] += 1
                        ruleset = ((result.get("profile") or {}).get("ruleset") or {}).get("name")
                        report.append({"edition": candidate[0], "region": candidate[1],
                                       "realm": candidate[2], "ruleset": ruleset, "name": candidate[3],
                                       "status": status, "endpoints": result.get("endpoint_status", {}),
                                       "discovery": discoveries.get(candidate)})
                        if candidate[0] == "forever":
                            forever_counts[status] += 1
                            endpoint_failures += sum(v["status"] != 200 for v in result["endpoint_status"].values())
                if sum(counts.values()) % 250 == 0:
                    print(f"Progress: {dict(counts)}", flush=True)
        if forever_run:
            with conn, conn.cursor() as cur:
                cur.execute("""UPDATE marketlens_import.forever_armory_runs SET finished_at=now(),
                    fetched=%s,not_found=%s,failed=%s,endpoint_failures=%s WHERE run_id=%s""",
                    (forever_counts[200], forever_counts[404],
                     forever_count - forever_counts[200] - forever_counts[404], endpoint_failures, forever_run))
    print(f"Completed: {dict(counts)}", flush=True)
    if args.refresh_cache:
        refresh_cache(conn)
    if candidates and isinstance(api, WorkerClient):
        try:
            api.report(report)
        except (OSError, ValueError) as error:
            print(f"Dashboard report deferred: {error}", flush=True)
    conn.close()


if __name__ == "__main__":
    main()
