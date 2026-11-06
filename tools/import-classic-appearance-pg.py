"""Import a Classic Appearance API pilot into local PostgreSQL.

The report is immutable by SHA-256. Reimporting it is idempotent. Skin choices
are separate rows so characters with multiple Skin Color options are preserved.
"""

import argparse
import hashlib
import json
import os
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

import psycopg2
from psycopg2.extras import execute_values


SCHEMA = Path(__file__).with_name("character-appearance-pg.sql")
DEFAULT_DSN = os.getenv("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")


def prepare(path, identity):
    content = path.read_bytes()
    digest = hashlib.sha256(content).hexdigest()
    report = json.loads(content)
    rows = report["characters"]
    expected = sum(part["requested"] for part in report["summary"].values())
    if not rows or len(rows) != expected:
        raise ValueError(f"report has {len(rows)} rows but summary requests {expected}")
    sampled_at = datetime.fromisoformat(report["sampled_at_utc"])
    if sampled_at.tzinfo is None:
        raise ValueError("sampled_at_utc must include a timezone")
    observations, choices, seen = [], [], set()
    for row in rows:
        edition = row["edition"]
        natural = (edition, row["name"].casefold(), row["realm"].casefold())
        keys = identity.get(natural, set())
        if len(keys) != 1:
            raise ValueError(f"identity missing or ambiguous: {natural}")
        key = next(iter(keys))
        identity_key = (edition, key)
        if identity_key in seen:
            raise ValueError(f"repeated character identity: {identity_key}")
        seen.add(identity_key)
        skin = row.get("skin_choices", [])
        if row["status"] != "ok" and skin:
            raise ValueError(f"failed request contains skin choices: {natural}")
        if len({choice["option_id"] for choice in skin}) != len(skin):
            raise ValueError(f"repeated skin option: {natural}")
        observations.append((digest, edition, key, row["name"], row["realm"],
                             row.get("race"), row.get("level"), row["status"],
                             row.get("fetched_at"), row.get("customization_count")))
        for choice in skin:
            if choice["option_id"] is None or choice["choice_id"] is None:
                raise ValueError(f"incomplete skin choice: {natural}")
            choices.append((digest, edition, key, choice["option_id"],
                            choice["choice_id"], choice.get("display_order")))
    return digest, sampled_at, observations, choices


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("report", type=Path)
    parser.add_argument("--dsn", default=DEFAULT_DSN)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    conn = psycopg2.connect(args.dsn)
    try:
        identity = defaultdict(set)
        with conn.cursor() as cur:
            cur.execute("SELECT source_game, name, realm, character_key FROM marketlens_import.characters")
            for edition, name, realm, key in cur:
                identity[(edition, name.casefold(), realm.casefold())].add(key)
        digest, sampled_at, observations, choices = prepare(args.report, identity)
        print(f"Prepared {len(observations)} observations and {len(choices)} skin choices; "
              f"status={dict(Counter(row[7] for row in observations))}")
        if args.dry_run:
            return
        with conn:
            with conn.cursor() as cur:
                cur.execute(SCHEMA.read_text(encoding="utf-8"))
                cur.execute("""INSERT INTO marketlens_import.appearance_sample_runs
                    (run_id, report_name, report_sha256, report_file_mtime,
                     requested_count, sampled_at)
                    VALUES (%s,%s,%s,%s,%s,%s) ON CONFLICT (run_id) DO NOTHING""",
                    (digest, args.report.name, digest,
                     datetime.fromtimestamp(args.report.stat().st_mtime, timezone.utc),
                     len(observations), sampled_at))
                execute_values(cur, """INSERT INTO marketlens_import.character_appearance_observations
                    (run_id,source_game,character_key,name,realm,race_at_query,
                     level_at_query,fetch_status,fetched_at,customization_count)
                    VALUES %s ON CONFLICT (run_id,source_game,character_key) DO NOTHING""",
                    observations, page_size=500)
                if choices:
                    execute_values(cur, """INSERT INTO marketlens_import.character_appearance_skin_choices
                        (run_id,source_game,character_key,option_id,choice_id,display_order)
                        VALUES %s ON CONFLICT (run_id,source_game,character_key,option_id)
                        DO NOTHING""", choices, page_size=500)
                cur.execute("""SELECT COUNT(*) FROM marketlens_import.character_appearance_observations
                    WHERE run_id=%s""", (digest,))
                actual_observations = cur.fetchone()[0]
                cur.execute("""SELECT COUNT(*) FROM marketlens_import.character_appearance_skin_choices
                    WHERE run_id=%s""", (digest,))
                actual_choices = cur.fetchone()[0]
                if (actual_observations, actual_choices) != (len(observations), len(choices)):
                    raise ValueError(f"stored {(actual_observations, actual_choices)}, "
                                     f"expected {(len(observations), len(choices))}")
                cur.execute("ANALYZE marketlens_import.character_appearance_observations")
                cur.execute("ANALYZE marketlens_import.character_appearance_skin_choices")
        print(f"Verified run {digest[:12]}: {actual_observations} observations, "
              f"{actual_choices} skin choices")
    finally:
        conn.close()


if __name__ == "__main__":
    main()
