#!/usr/bin/env python3
"""Rotate Forever profession snapshots and rebuild the profession pages."""
import argparse
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import json
import os
import re
import runpy
import shutil
import subprocess

import psycopg2
from psycopg2.extras import Json, execute_values

ARMORY = runpy.run_path(str(Path(__file__).with_name("hourly-armory-refresh.py")))
FOREVER = ARMORY["FOREVER"]
CATALOG = Path(__file__).resolve().parents[1] / "laragon-php" / "src" / "Professions.php"

SELECT_DUE = """
WITH sampled AS (
    SELECT region,character_id,max(snapshot_at) AS latest
    FROM marketlens_import.forever_armory_snapshots
    WHERE professions_json IS NOT NULL GROUP BY region,character_id
), eligible AS (
    SELECT p.region,p.full_name,p.slug,p.character_id,s.latest,p.fetched_at
    FROM marketlens_import.forever_armory_profiles p
    LEFT JOIN sampled s USING (region,character_id)
    LEFT JOIN marketlens_import.forever_profession_checks c USING (region,character_id)
    WHERE p.level>=10 AND p.slug<>'' AND (s.latest IS NULL OR s.latest<=now()-interval '23 hours')
      AND (c.checked_at IS NULL OR c.checked_at<=now()-CASE WHEN c.status=404
          THEN interval '7 days' ELSE interval '23 hours' END)
), lanes AS (
    SELECT *,row_number() OVER (PARTITION BY (latest IS NULL)
        ORDER BY latest NULLS LAST,fetched_at DESC,region,character_id) AS lane_rank
    FROM eligible
)
SELECT region,full_name,slug,character_id FROM lanes
ORDER BY CASE WHEN latest IS NULL THEN lane_rank*5 ELSE lane_rank+(lane_rank-1)/4 END,
         latest NULLS LAST,region,character_id LIMIT %s
"""


def pull(api, candidate):
    region, name, slug, character_id = candidate
    result = FOREVER["fetch_character"](api, (region, name, slug), {"professions"})
    if result["status"] != 200:
        return result["status"], None
    if result["profile"].get("id") != character_id:
        return 409, None
    status = result["endpoint_status"].get("professions", {}).get("status", 599)
    return status, result if status == 200 else None


def profession_pages():
    catalog = os.getenv("WOWCENSUS_PROFESSION_CATALOG", str(CATALOG))
    output = subprocess.check_output([
        "php", "-r", "require $argv[1]; echo json_encode(array_keys(profession_catalog('forever')));",
        "--", catalog,
    ], text=True, timeout=20)
    slugs = json.loads(output)
    if not slugs or any(not re.fullmatch(r"[a-z]+(?:-[a-z]+)*", slug) for slug in slugs):
        raise ValueError("Invalid or empty Forever profession catalog")
    return ["/forever/professions", *(f"/forever/professions/{slug}" for slug in slugs)]


def rebuild_pages(conn, pages):
    with conn, conn.cursor() as cur:
        execute_values(cur, """INSERT INTO marketlens_import.hourly_armory_cache(path) VALUES %s
            ON CONFLICT (path) DO UPDATE SET queued_at=excluded.queued_at""", [(p,) for p in pages])
    ARMORY["refresh_cache"](conn, pages, page_timeout=180)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--limit", type=int, default=1000)
    parser.add_argument("--storage-path", type=Path)
    parser.add_argument("--cache-only", action="store_true", help="Rebuild the pages without pulling profiles")
    args = parser.parse_args()
    if not 1 <= args.limit <= 1000:
        parser.error("--limit must be between 1 and 1000")
    if args.storage_path and shutil.disk_usage(args.storage_path).free < 12 * 1024**3:
        raise SystemExit("Professions refresh deferred: less than 12 GiB free")
    pages = profession_pages()
    conn = psycopg2.connect(ARMORY["STANDARD"]["DSN"], application_name="wowcensus-daily-professions")
    with conn, conn.cursor() as cur:
        cur.execute("SELECT pg_try_advisory_lock(hashtext('wowcensus-daily-professions'))")
        if not cur.fetchone()[0]:
            return
    if args.cache_only:
        rebuild_pages(conn, pages)
        conn.close()
        return
    with conn, conn.cursor() as cur:
        cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.forever_profession_checks (
            region text NOT NULL, character_id bigint NOT NULL, checked_at timestamptz NOT NULL,
            status integer NOT NULL, PRIMARY KEY(region,character_id))""")
        cur.execute(SELECT_DUE, (args.limit,))
        rows = cur.fetchall()
        if rows:
            cur.execute("""INSERT INTO marketlens_import.forever_armory_runs(requested,options)
                VALUES (%s,%s) RETURNING run_id""", (len(rows), Json({"source": "daily-professions"})))
            run_id = cur.fetchone()[0]
    if not rows:
        rebuild_pages(conn, pages)
        conn.close()
        return
    api = (ARMORY["ForeverClient"](ARMORY["WorkerClient"](6)) if os.getenv("ARMORY_WORKER_URL")
           else FOREVER["Blizzard"](6))
    counts = Counter()
    print(f"Selected {len(rows)} Forever profiles", flush=True)
    with ThreadPoolExecutor(max_workers=8) as pool:
        for offset in range(0, len(rows), 25):
            batch = rows[offset:offset + 25]
            pulled = list(pool.map(lambda row: pull(api, row), batch))
            with conn, conn.cursor() as cur:
                for row, (status, result) in zip(batch, pulled):
                    if result:
                        FOREVER["store_result"](cur, run_id, result, 7)
                    counts[status] += 1
                execute_values(cur, """INSERT INTO marketlens_import.forever_profession_checks
                    (region,character_id,status,checked_at) VALUES %s
                    ON CONFLICT (region,character_id) DO UPDATE
                    SET status=excluded.status,checked_at=excluded.checked_at""",
                    [(row[0], row[3], status) for row, (status, _) in zip(batch, pulled)],
                    template="(%s,%s,%s,now())")
    with conn, conn.cursor() as cur:
        cur.execute("""UPDATE marketlens_import.forever_armory_runs SET
            finished_at=now(),fetched=%s,not_found=%s,failed=%s WHERE run_id=%s""",
            (counts[200], counts[404], len(rows) - counts[200] - counts[404], run_id))
    print(f"Profession endpoint results: {dict(counts)}", flush=True)
    rebuild_pages(conn, pages)
    conn.close()
    if not counts[200]:
        raise SystemExit("No profession snapshots refreshed")


if __name__ == "__main__":
    main()
