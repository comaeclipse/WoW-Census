#!/usr/bin/env python3
"""Import Taiwan (TW) WoW Forever character export CSV into PostgreSQL.

Usage:
    python tools/import-tw-csv-pg.py <tw_names.csv> [--dry-run] [--dsn DSN]

Deduplicates by (region, slug) and (region, lower(name)).
Stores full sighting attributes (itemLevel, honorableKills, first_seen, last_seen)
in marketlens_import.external_tw_characters, archives source to MarketLensData,
records in local_source_files / local_imports, and queues characters into
marketlens_import.forever_armory_candidates for Blizzard Armory ruleset resolution.
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import json
import os
import shutil
import sys
from datetime import datetime, timezone
from pathlib import Path

import psycopg2
from psycopg2.extras import execute_values

DEFAULT_DSN = os.getenv("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")
DATA_DIR = Path(os.getenv("MARKETLENS_DATA_ROOT", Path.home() / "Documents" / "MarketLensData"))


def parse_timestamp(ts_str: str | None) -> datetime | None:
    if not ts_str or not ts_str.strip():
        return None
    s = ts_str.strip()
    try:
        # e.g. "2026-11-16 03:48"
        if len(s) == 16:
            dt = datetime.strptime(s, "%Y-%m-%d %H:%M")
        elif len(s) == 19:
            dt = datetime.strptime(s, "%Y-%m-%d %H:%M:%S")
        else:
            dt = datetime.fromisoformat(s)
        return dt.replace(tzinfo=timezone.utc)
    except Exception:
        return None


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("file", type=Path, help="Path to tw_names.csv")
    parser.add_argument("--dry-run", action="store_true", help="Report without committing")
    parser.add_argument("--dsn", default=DEFAULT_DSN, help="PostgreSQL DSN")
    parser.add_argument("--no-candidates", action="store_true", help="Do not queue to forever_armory_candidates")
    args = parser.parse_args()

    csv_path = args.file.resolve()
    if not csv_path.exists():
        sys.exit(f"Error: file not found: {csv_path}")

    raw_bytes = csv_path.read_bytes()
    sha256 = hashlib.sha256(raw_bytes).hexdigest()
    mtime_utc = datetime.fromtimestamp(csv_path.stat().st_mtime, timezone.utc).isoformat()
    size_bytes = len(raw_bytes)

    print(f"Source file: {csv_path.name}")
    print(f"  SHA-256: {sha256}")
    print(f"  Size: {size_bytes:,} bytes")
    print(f"  Modified: {mtime_utc}")

    # Read and validate rows
    rows = []
    with open(csv_path, "r", encoding="utf-8-sig") as f:
        reader = csv.DictReader(f)
        required_cols = {"slug", "name", "level", "race", "class", "faction", "guild", "itemLevel", "honorableKills", "first_seen", "last_seen"}
        missing_cols = required_cols - set(reader.fieldnames or [])
        if missing_cols:
            sys.exit(f"Error: missing required columns in CSV: {missing_cols}")

        for line_num, r in enumerate(reader, start=2):
            slug = (r.get("slug") or "").strip()
            name = (r.get("name") or "").strip()
            if not slug or not name:
                continue

            level = int(r["level"]) if r.get("level") and r["level"].strip() else None
            race = (r.get("race") or "").strip() or None
            char_class = (r.get("class") or "").strip() or None
            faction = (r.get("faction") or "").strip() or None
            guild = (r.get("guild") or "").strip() or None
            item_level = int(r["itemLevel"]) if r.get("itemLevel") and r["itemLevel"].strip() else None
            honorable_kills = int(r["honorableKills"]) if r.get("honorableKills") and r["honorableKills"].strip() else None
            first_seen = parse_timestamp(r.get("first_seen"))
            last_seen = parse_timestamp(r.get("last_seen"))

            rows.append({
                "region": "tw",
                "slug": slug,
                "name": name,
                "level": level,
                "race": race,
                "class": char_class,
                "faction": faction,
                "guild": guild,
                "item_level": item_level,
                "honorable_kills": honorable_kills,
                "first_seen": first_seen,
                "last_seen": last_seen,
            })

    print(f"Read {len(rows):,} valid character rows from CSV")

    # In-memory deduplication by slug
    deduped = {}
    for r in rows:
        key = ("tw", r["slug"])
        if key in deduped:
            existing = deduped[key]
            # Merge
            if r["level"] is not None:
                existing["level"] = max(existing["level"] or 0, r["level"])
            if r["item_level"] is not None:
                existing["item_level"] = max(existing["item_level"] or 0, r["item_level"])
            if r["honorable_kills"] is not None:
                existing["honorable_kills"] = max(existing["honorable_kills"] or 0, r["honorable_kills"])
            if r["race"]:
                existing["race"] = r["race"]
            if r["class"]:
                existing["class"] = r["class"]
            if r["faction"]:
                existing["faction"] = r["faction"]
            if r["guild"]:
                existing["guild"] = r["guild"]
            if r["first_seen"]:
                existing["first_seen"] = min(existing["first_seen"], r["first_seen"]) if existing["first_seen"] else r["first_seen"]
            if r["last_seen"]:
                existing["last_seen"] = max(existing["last_seen"], r["last_seen"]) if existing["last_seen"] else r["last_seen"]
        else:
            deduped[key] = r

    unique_characters = list(deduped.values())
    print(f"Unique characters to process: {len(unique_characters):,}")

    # Database ingestion
    conn = psycopg2.connect(args.dsn)
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT pg_advisory_xact_lock(hashtext('marketlens-import'))")
            cur.execute("SET LOCAL search_path TO marketlens_import")

            # 1. Archive file & record in local_source_files
            archive_dir = DATA_DIR / "imports" / "20261116-tw-names"
            archive_path = archive_dir / "tw_names.csv"
            rel_path = f"imports/20261116-tw-names/tw_names.csv"

            if not args.dry_run:
                archive_dir.mkdir(parents=True, exist_ok=True)
                if not archive_path.exists() or archive_path.stat().st_size != size_bytes:
                    shutil.copy2(csv_path, archive_path)

            cur.execute("""
                INSERT INTO marketlens_import.local_source_files (sha256, relative_path, source_modified_utc, bytes)
                VALUES (%s, %s, %s, %s)
                ON CONFLICT (sha256) DO NOTHING
            """, (sha256, rel_path, mtime_utc, size_bytes))

            # 2. Ensure table marketlens_import.external_tw_characters exists
            cur.execute("""
                CREATE TABLE IF NOT EXISTS marketlens_import.external_tw_characters (
                    region text NOT NULL DEFAULT 'tw',
                    slug text NOT NULL,
                    name text NOT NULL,
                    level integer,
                    race text,
                    class text,
                    faction text,
                    guild text,
                    item_level integer,
                    honorable_kills integer,
                    first_seen timestamptz,
                    last_seen timestamptz,
                    source_sha256 text NOT NULL REFERENCES marketlens_import.local_source_files(sha256),
                    imported_at timestamptz NOT NULL DEFAULT now(),
                    PRIMARY KEY (region, slug)
                );
                CREATE UNIQUE INDEX IF NOT EXISTS external_tw_characters_name_idx
                    ON marketlens_import.external_tw_characters(region, lower(name));
                CREATE INDEX IF NOT EXISTS external_tw_characters_guild_idx
                    ON marketlens_import.external_tw_characters(region, guild);
                CREATE INDEX IF NOT EXISTS external_tw_characters_level_idx
                    ON marketlens_import.external_tw_characters(region, level DESC);
            """)

            # 3. Insert or update external_tw_characters
            char_tuples = [
                (
                    c["region"], c["slug"], c["name"], c["level"], c["race"], c["class"],
                    c["faction"], c["guild"], c["item_level"], c["honorable_kills"],
                    c["first_seen"], c["last_seen"], sha256
                )
                for c in unique_characters
            ]

            upsert_sql = """
                INSERT INTO marketlens_import.external_tw_characters
                  (region, slug, name, level, race, class, faction, guild, item_level, honorable_kills, first_seen, last_seen, source_sha256)
                VALUES %s
                ON CONFLICT (region, slug) DO UPDATE SET
                  level = GREATEST(external_tw_characters.level, EXCLUDED.level),
                  race = COALESCE(NULLIF(EXCLUDED.race, ''), external_tw_characters.race),
                  class = COALESCE(NULLIF(EXCLUDED.class, ''), external_tw_characters.class),
                  faction = COALESCE(NULLIF(EXCLUDED.faction, ''), external_tw_characters.faction),
                  guild = COALESCE(NULLIF(EXCLUDED.guild, ''), external_tw_characters.guild),
                  item_level = GREATEST(external_tw_characters.item_level, EXCLUDED.item_level),
                  honorable_kills = GREATEST(external_tw_characters.honorable_kills, EXCLUDED.honorable_kills),
                  first_seen = LEAST(external_tw_characters.first_seen, EXCLUDED.first_seen),
                  last_seen = GREATEST(external_tw_characters.last_seen, EXCLUDED.last_seen),
                  source_sha256 = EXCLUDED.source_sha256,
                  imported_at = now()
            """
            execute_values(cur, upsert_sql, char_tuples, page_size=1000)
            print(f"Upserted {len(char_tuples):,} characters into external_tw_characters")

            # 4. Queue into forever_armory_candidates if requested
            candidates_queued = 0
            if not args.no_candidates:
                candidate_tuples = [
                    (c["region"], c["name"], c["slug"], "tw_names_csv")
                    for c in unique_characters
                ]
                cand_sql = """
                    INSERT INTO marketlens_import.forever_armory_candidates
                      (region, name, slug, discovery_source)
                    VALUES %s
                    ON CONFLICT (region, name) DO NOTHING
                    RETURNING 1
                """
                execute_values(cur, cand_sql, candidate_tuples, page_size=1000)
                cur.execute("SELECT count(*) FROM marketlens_import.forever_armory_candidates WHERE region='tw'")
                tw_cand_total = cur.fetchone()[0]
                print(f"Queued into forever_armory_candidates (total TW candidates now: {tw_cand_total:,})")

            # 5. Record in local_imports
            details = json.dumps({
                "source_file": csv_path.name,
                "rows_read": len(rows),
                "unique_characters": len(unique_characters),
                "has_item_level": sum(1 for c in unique_characters if c["item_level"] is not None),
                "has_honorable_kills": sum(1 for c in unique_characters if c["honorable_kills"] is not None),
            })
            cur.execute("""
                INSERT INTO marketlens_import.local_imports
                  (source_sha256, kind, bucket, source_game, imported_at_utc, details_json)
                VALUES (%s, 'external_tw_characters', 'tw:forever:leaderboard', 'forever',
                        to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"'), %s)
                ON CONFLICT (source_sha256, kind, bucket) DO UPDATE SET
                  imported_at_utc = EXCLUDED.imported_at_utc,
                  details_json = EXCLUDED.details_json
            """, (sha256, details))

        if args.dry_run:
            conn.rollback()
            print("\nDRY RUN: rolled back changes. Database remains untouched.")
        else:
            conn.commit()
            print("\nSUCCESS: Changes committed to local PostgreSQL.")

    except Exception as e:
        conn.rollback()
        raise e
    finally:
        conn.close()


if __name__ == "__main__":
    main()
