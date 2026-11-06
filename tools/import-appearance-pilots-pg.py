"""Import local Blizzard Retail appearance pilot reports into local PostgreSQL.

Usage: python tools/import-appearance-pilots-pg.py REPORT.json [REPORT.json ...]
Reports are immutable by SHA-256; rerunning the same report is idempotent.
"""

import argparse
import hashlib
import json
import os
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

import psycopg2
from psycopg2.extras import execute_values

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_DSN = os.getenv("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")
SCHEMA = ROOT / "tools/character-appearance-pg.sql"
SWATCHES = ROOT / "mockups/retail-appearance-choice-swatches.json"


def prepare_report(path, identity, swatches, swatch_source, swatch_date):
    content = path.read_bytes()
    digest = hashlib.sha256(content).hexdigest()
    report = json.loads(content)
    rows = report["characters"]
    if not rows or len(rows) != report["summary"]["requested"]:
        raise ValueError(f"{path.name}: invalid requested count")
    output = []
    keys = set()
    for row in rows:
        natural = (row["name"].casefold(), row["realm"].casefold())
        character_keys = identity.get(natural)
        if not character_keys or len(character_keys) != 1:
            raise ValueError(f"{path.name}: character identity missing or ambiguous: {natural}")
        character_key = next(iter(character_keys))
        if character_key in keys:
            raise ValueError(f"{path.name}: repeated character identity: {character_key}")
        keys.add(character_key)
        choice = row.get("skin_choice_id")
        swatch = swatches.get(str(choice)) if choice is not None else None
        if choice is not None and (not swatch or swatch["option_id"] != row.get("skin_option_id")):
            raise ValueError(f"{path.name}: unmapped or mismatched choice {choice}")
        output.append((digest, "retail", character_key, row["name"], row["realm"],
                       row.get("race"), row.get("level"), row["status"], row.get("fetched_at"),
                       row.get("customization_count"), row.get("skin_option_id"),
                       choice, row.get("skin_display_order"), swatch["hex"] if swatch else None))
    file_mtime = datetime.fromtimestamp(path.stat().st_mtime, timezone.utc)
    return (digest, path.name, digest, file_mtime, swatch_source, swatch_date, len(rows)), output


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("reports", nargs="+", type=Path)
    parser.add_argument("--dsn", default=DEFAULT_DSN)
    args = parser.parse_args()
    swatch_document = json.loads(SWATCHES.read_text(encoding="utf-8"))
    swatches = swatch_document["choices"]
    conn = psycopg2.connect(args.dsn)
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT name, realm, character_key FROM marketlens_import.characters WHERE source_game='retail'")
            identity = defaultdict(set)
            for name, realm, key in cur:
                identity[(name.casefold(), realm.casefold())].add(key)
        prepared = [prepare_report(path, identity, swatches,
                                   swatch_document["source"], swatch_document["retrieved"])
                    for path in args.reports]
        with conn:
            with conn.cursor() as cur:
                cur.execute(SCHEMA.read_text(encoding="utf-8"))
                for run, rows in prepared:
                    cur.execute("""INSERT INTO marketlens_import.appearance_sample_runs
                        (run_id, report_name, report_sha256, report_file_mtime,
                         swatch_source_url, swatch_retrieved_on, requested_count)
                        VALUES (%s,%s,%s,%s,%s,%s,%s) ON CONFLICT (run_id) DO NOTHING""", run)
                    execute_values(cur, """INSERT INTO marketlens_import.character_appearance_observations
                        (run_id,source_game,character_key,name,realm,race_at_query,level_at_query,
                         fetch_status,fetched_at,customization_count,skin_option_id,
                         skin_choice_id,skin_display_order,swatch_hex) VALUES %s
                        ON CONFLICT (run_id,source_game,character_key) DO NOTHING""", rows,
                        page_size=500)
                    cur.execute("""INSERT INTO marketlens_import.character_appearance_skin_choices
                        (run_id, source_game, character_key, option_id, choice_id,
                         display_order, swatch_hex)
                        SELECT run_id, source_game, character_key, skin_option_id,
                               skin_choice_id, skin_display_order, swatch_hex
                        FROM marketlens_import.character_appearance_observations
                        WHERE run_id=%s AND skin_option_id IS NOT NULL
                          AND skin_choice_id IS NOT NULL
                        ON CONFLICT (run_id, source_game, character_key, option_id) DO NOTHING""",
                        (run[0],))
                    cur.execute("SELECT COUNT(*) FROM marketlens_import.character_appearance_observations WHERE run_id=%s", (run[0],))
                    count = cur.fetchone()[0]
                    if count != len(rows):
                        raise ValueError(f"{run[1]}: imported {count} of {len(rows)} rows")
                    print(f"{run[1]}: {count} observations verified")
                cur.execute("ANALYZE marketlens_import.character_appearance_observations")
                cur.execute("""SELECT COUNT(*), COUNT(DISTINCT character_key),
                    COUNT(*) FILTER (WHERE skin_choice_id IS NOT NULL),
                    COUNT(DISTINCT race_at_query) FILTER (WHERE skin_choice_id IS NOT NULL)
                    FROM marketlens_import.character_appearance_observations""")
                total, unique, with_skin, races = cur.fetchone()
                print(f"PostgreSQL: {total} observations, {unique} characters, {with_skin} skin choices, {races} races")
    finally:
        conn.close()


if __name__ == "__main__":
    main()
