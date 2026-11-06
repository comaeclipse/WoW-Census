#!/usr/bin/env python3
"""Import verified ForeverPulse manual /who batches as isolated observations.

Channel rosters, scan cells, and talent data are intentionally outside this
import. Batch/name keys deduplicate overlapping copies of the same capture.
"""
import csv
import hashlib
import json
import os
import re
import shutil
from datetime import datetime, timezone
from pathlib import Path

import psycopg2
from psycopg2.extras import execute_values

SOURCE = Path(r"C:\Users\skarz\Desktop\ForeverPulse.lua")
OUTPUT = Path(r"C:\Users\skarz\Documents\Codex\2026-11-05\de-2\outputs")
DATA = Path.home() / "Documents" / "MarketLensData"
DSN = os.environ.get("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")
SCOPE = "6798-PVP-Alliance"


def main():
    raw = SOURCE.read_bytes()
    digest = hashlib.sha256(raw).hexdigest()
    summary = json.loads((OUTPUT / "foreverpulse-cleaning-summary.json").read_text(encoding="utf-8"))
    if summary["source_sha256"] != digest:
        raise RuntimeError("Source changed since the cleaned export; regenerate it first")
    scope = summary["scope"]
    if (scope["realm_id"], scope["realm_name"], scope["faction"], scope["region"], scope["environment"]) != (
        6798, "CAMWOW US1 Realm 2 PvP", "Alliance", "1", "live"
    ):
        raise RuntimeError("Unexpected scope")

    rows = []
    batches = {}
    seen = set()
    with (OUTPUT / "foreverpulse-who-batches.csv").open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            if row["scope_id"] != SCOPE or not re.fullmatch(r"[0-9a-f-]{36}", row["batch_id"]):
                raise RuntimeError("Invalid scope or batch ID")
            key = (SCOPE, row["batch_id"], row["name"])
            if key in seen:
                raise RuntimeError(f"Duplicate row inside cleaned export: {key}")
            seen.add(key)
            if not row["name"] or not row["class"] or not row["race"] or not row["level"].isdigit():
                raise RuntimeError("Missing character fields")
            level = int(row["level"])
            if not 1 <= level <= 90:
                raise RuntimeError("Invalid level")
            start = datetime.fromisoformat(row["batch_start_utc"].replace("Z", "+00:00"))
            end = datetime.fromisoformat(row["batch_end_utc"].replace("Z", "+00:00"))
            if start.tzinfo is None or end.tzinfo is None or start > end:
                raise RuntimeError("Invalid batch time")
            metadata = (start, end, row["batch_close_reason"])
            if row["batch_id"] in batches and batches[row["batch_id"]] != metadata:
                raise RuntimeError("Conflicting batch metadata")
            batches[row["batch_id"]] = metadata
            rows.append((SCOPE, row["batch_id"], row["name"], row["class"], row["race"], level,
                         row["guild"], row["player_zone"], digest))
    if len(rows) != summary["who_rows"] or len(batches) != summary["who_batches"]:
        raise RuntimeError("Cleaned export counts differ from the source analysis")

    archive = DATA / "imports" / f"foreverpulse-{digest[:12]}"
    archive.mkdir(parents=True, exist_ok=True)
    archived = archive / "ForeverPulse.lua"
    if archived.exists() and hashlib.sha256(archived.read_bytes()).hexdigest() != digest:
        raise RuntimeError("Archive path contains different content")
    if not archived.exists():
        shutil.copyfile(SOURCE, archived)
    if hashlib.sha256(archived.read_bytes()).hexdigest() != digest:
        raise RuntimeError("Archive verification failed")

    conn = psycopg2.connect(DSN)
    try:
        with conn:
            with conn.cursor() as cur:
                cur.execute("SELECT pg_advisory_xact_lock(hashtext('marketlens-external-who-import'))")
                cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.external_who_sources (
                    sha256 text PRIMARY KEY, addon text NOT NULL, addon_version text NOT NULL,
                    scope_id text NOT NULL, realm_id integer NOT NULL, realm_name text NOT NULL,
                    region text NOT NULL, faction text NOT NULL, row_count integer NOT NULL,
                    batch_count integer NOT NULL, imported_at timestamptz NOT NULL DEFAULT now()
                )""")
                cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.external_who_batches (
                    scope_id text NOT NULL, batch_id text NOT NULL,
                    first_observed_at timestamptz NOT NULL, last_observed_at timestamptz NOT NULL,
                    close_reason text NOT NULL, source_sha256 text NOT NULL REFERENCES marketlens_import.external_who_sources(sha256),
                    PRIMARY KEY (scope_id,batch_id)
                )""")
                cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.external_who_sightings (
                    scope_id text NOT NULL, batch_id text NOT NULL, name text NOT NULL,
                    class text NOT NULL, race text NOT NULL, level integer NOT NULL,
                    guild text NOT NULL, player_zone text NOT NULL,
                    source_sha256 text NOT NULL REFERENCES marketlens_import.external_who_sources(sha256),
                    PRIMARY KEY (scope_id,batch_id,name),
                    FOREIGN KEY (scope_id,batch_id) REFERENCES marketlens_import.external_who_batches(scope_id,batch_id)
                )""")
                cur.execute("SELECT row_count,batch_count FROM marketlens_import.external_who_sources WHERE sha256=%s", (digest,))
                existing = cur.fetchone()
                if existing:
                    if existing != (len(rows), len(batches)):
                        raise RuntimeError("Previously imported source has different counts")
                    print(f"ALREADY IMPORTED sha256={digest} rows={existing[0]} batches={existing[1]}")
                    return
                # A repeated batch ID from a different source must contain the
                # same metadata and character attributes. Never silently replace.
                cur.execute("SELECT scope_id,batch_id,first_observed_at,last_observed_at,close_reason FROM marketlens_import.external_who_batches WHERE scope_id=%s", (SCOPE,))
                old_batches = {(scope_id, batch_id): (first, last, reason) for scope_id,batch_id,first,last,reason in cur.fetchall()}
                for batch_id, metadata in batches.items():
                    old = old_batches.get((SCOPE, batch_id))
                    if old is not None and old != metadata:
                        raise RuntimeError(f"Conflicting existing batch {batch_id}")
                cur.execute("SELECT scope_id,batch_id,name,class,race,level,guild,player_zone FROM marketlens_import.external_who_sightings WHERE scope_id=%s", (SCOPE,))
                old_rows = {(r[0],r[1],r[2]): r[3:] for r in cur.fetchall()}
                for row in rows:
                    old = old_rows.get(row[:3])
                    if old is not None and old != row[3:8]:
                        raise RuntimeError(f"Conflicting existing sighting {row[:3]}")
                cur.execute("""INSERT INTO marketlens_import.external_who_sources
                    (sha256,addon,addon_version,scope_id,realm_id,realm_name,region,faction,row_count,batch_count)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
                    (digest, "ForeverPulse", "4.6.0", SCOPE, 6798, scope["realm_name"], "us", "Alliance", len(rows), len(batches)))
                batch_values = [(SCOPE, batch_id, *metadata, digest) for batch_id, metadata in batches.items()]
                execute_values(cur, """INSERT INTO marketlens_import.external_who_batches
                    (scope_id,batch_id,first_observed_at,last_observed_at,close_reason,source_sha256)
                    VALUES %s ON CONFLICT DO NOTHING""", batch_values)
                execute_values(cur, """INSERT INTO marketlens_import.external_who_sightings
                    (scope_id,batch_id,name,class,race,level,guild,player_zone,source_sha256)
                    VALUES %s ON CONFLICT DO NOTHING""", rows, page_size=1000)
                cur.execute("""INSERT INTO marketlens_import.local_source_files
                    (sha256,relative_path,source_modified_utc,bytes,content) VALUES (%s,%s,%s,%s,NULL)
                    ON CONFLICT (sha256) DO NOTHING""",
                    (digest, f"external/foreverpulse-{digest[:12]}/ForeverPulse.lua",
                     datetime.fromtimestamp(SOURCE.stat().st_mtime,timezone.utc).isoformat(), len(raw)))
        print(f"IMPORTED sha256={digest} rows={len(rows)} batches={len(batches)} archive={archived}")
    finally:
        conn.close()


if __name__ == "__main__":
    main()
