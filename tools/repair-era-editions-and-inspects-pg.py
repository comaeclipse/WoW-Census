#!/usr/bin/env python3
"""Repair two importer bugs in PostgreSQL, fixed in tools/build-local-database.py.

    python -B tools/repair-era-editions-and-inspects-pg.py            # dry run: counts, then roll back
    python -B tools/repair-era-editions-and-inspects-pg.py --apply

- source_for() filed old Classic Era buckets of Hardcore and Season of Discovery
  realms other than Doomhowl and Wild Growth ("Soulseeker-Alliance",
  "Crusader Strike-Alliance") as Classic Era. Their rows move to the realm's
  edition: source_game and the character_key prefix change.
- import_inspects() called an inspect ambiguous when its one character had rows
  in several collections. Every unlinked inspect is matched again.

Every changed row is first copied, as JSON, into data_repair_backup. Runs under
the importer's lock, in one transaction.
"""

import argparse
import importlib.util
import os
from pathlib import Path

import psycopg2

DEFAULT_DSN = os.environ.get("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")
REPO = Path(__file__).resolve().parent.parent

spec = importlib.util.spec_from_file_location("build_local_database", REPO / "tools" / "build-local-database.py")
legacy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(legacy)

# Tables keyed by (game, source_game, ...); the ones with character_key are rekeyed too.
EDITION_TABLES = ("characters", "character_observations", "character_location_observations",
                  "population_sweeps", "population_sweep_queries", "datasets")


class Db:
    """The importer's db.execute() with ? placeholders, on a psycopg2 cursor."""

    def __init__(self, cur):
        self.cur = cur

    def execute(self, sql, params=()):
        self.cur.execute(sql.replace("%", "%%").replace("?", "%s"), params)
        return self.cur


def backup(cur, repair, table, where, params):
    cur.execute(f"""INSERT INTO data_repair_backup (repair, table_name, row_json)
      SELECT %s, %s, to_jsonb(t) FROM {table} t WHERE {where}""", (repair, table, *params))


def move_editions(cur):
    era = legacy.SOURCES["classic-era"]
    for realm, edition in legacy.ERA_SEASON_REALMS.items():
        source = legacy.SOURCES[edition]
        games = [f"realm:{realm}-{faction}" for faction in ("Alliance", "Horde", "Neutral")]
        for table in EDITION_TABLES:
            where = "game = ANY(%s) AND source_game = %s"
            backup(cur, "era-edition", table, where, (games, era))
            keyed = table.startswith("character")
            cur.execute(f"""UPDATE {table} SET source_game = %s
              {", character_key = %s || substr(character_key, length(%s) + 1)" if keyed else ""}
              WHERE {where}""", (source, *((source, era) if keyed else ()), games, era))
            if cur.rowcount:
                print(f"{table}: {cur.rowcount} rows of {realm} moved from {era} to {source}", flush=True)
        buckets = [g[len("realm:"):] for g in games]
        where = "bucket = ANY(%s) AND source_game = %s"
        backup(cur, "era-edition", "local_imports", where, (buckets, era))
        cur.execute(f"UPDATE local_imports SET source_game = %s WHERE {where}", (source, buckets, era))
        if cur.rowcount:
            print(f"local_imports: {cur.rowcount} rows of {realm} moved from {era} to {source}", flush=True)


def relink_inspects(cur):
    db = Db(cur)
    known = legacy.known_realms(db)
    cur.execute("SELECT DISTINCT source_game FROM character_inspects WHERE character_key IS NULL ORDER BY 1")
    for (source,) in cur.fetchall():
        by_name_class = legacy.inspect_candidates(db, source)
        cur.execute("""SELECT guid, captured_at, name, class_file, race, realm, faction, schema_version,
            raw_json::jsonb->>'observerRegion', match_status
          FROM character_inspects WHERE source_game = %s AND character_key IS NULL""", (source,))
        rows = cur.fetchall()
        updates = []
        counts = {}
        for guid, captured_at, name, class_file, race, realm, faction, schema, region, before in rows:
            realm = legacy.canonical_realm(realm or "", known) or None
            game, key = legacy.match_inspect(by_name_class, name, class_file, race, realm, faction, region)
            if game:
                status = "context-name" if schema >= 2 else "legacy-name"
            else:
                status, key = key, None
            counts[(before, status)] = counts.get((before, status), 0) + 1
            if status != before:
                updates.append((game, key, status, source, guid, captured_at))
        if updates:
            cur.execute("""CREATE TEMP TABLE relinked (game TEXT, character_key TEXT, match_status TEXT,
              source_game TEXT, guid TEXT, captured_at BIGINT) ON COMMIT DROP""")
            cur.executemany("INSERT INTO relinked VALUES (%s,%s,%s,%s,%s,%s)", updates)
            cur.execute("""INSERT INTO data_repair_backup (repair, table_name, row_json)
              SELECT 'inspect-link', 'character_inspects', jsonb_build_object('source_game', i.source_game,
                'guid', i.guid, 'captured_at', i.captured_at, 'game', i.game,
                'character_key', i.character_key, 'match_status', i.match_status)
              FROM character_inspects i JOIN relinked r USING (source_game, guid, captured_at)""")
            cur.execute("""UPDATE character_inspects i SET game = r.game, character_key = r.character_key,
              match_status = r.match_status FROM relinked r
              WHERE (i.source_game, i.guid, i.captured_at) = (r.source_game, r.guid, r.captured_at)""")
            cur.execute("DROP TABLE relinked")
        summary = ", ".join(f"{b} -> {a}: {n}" for (b, a), n in sorted(counts.items()))
        print(f"inspections {source}: {summary}", flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--dsn", default=DEFAULT_DSN)
    parser.add_argument("--schema", default="marketlens_import")
    parser.add_argument("--apply", action="store_true", help="commit (default: report and roll back)")
    args = parser.parse_args()

    conn = psycopg2.connect(args.dsn)
    cur = conn.cursor()
    cur.execute("SELECT pg_advisory_lock(hashtext('marketlens-import'))")
    cur.execute(f'SET search_path TO "{args.schema}"')
    cur.execute("""CREATE TABLE IF NOT EXISTS data_repair_backup (
      repair TEXT NOT NULL, table_name TEXT NOT NULL,
      repaired_at TIMESTAMPTZ NOT NULL DEFAULT now(), row_json JSONB NOT NULL)""")
    move_editions(cur)
    relink_inspects(cur)
    if args.apply:
        conn.commit()
        print("committed", flush=True)
    else:
        conn.rollback()
        print("dry run: rolled back (use --apply to commit)", flush=True)
    conn.close()


if __name__ == "__main__":
    main()
