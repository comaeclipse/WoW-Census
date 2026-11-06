#!/usr/bin/env python3
"""Rewrite malformed character keys in PostgreSQL to "<source>:<realm>:<name>".

    python -B tools/repair-character-keys-pg.py            # dry run: counts, then roll back
    python -B tools/repair-character-keys-pg.py --apply

Two importer bugs, fixed in tools/build-local-database.py character_key():
- WoWCensus keys start with the region ("us:mop-classic:galakras:name"). The
  importer replaced only the first segment, storing
  "mop-classic:mop-classic:galakras:name" beside the older
  "mop-classic:galakras:name" for the same player.
- The addon wrote "ra-den" for the player's own realm but "raden" from
  cross-realm "Name-Raden" names. Realm slugs now drop hyphens.

Rows that end up with the same key are merged the way the importer merges a
re-imported character. Every changed row is first copied, as JSON, into
character_key_repair_backup. Runs under the importer's lock, in one transaction.
"""

import argparse
import os

import psycopg2

DEFAULT_DSN = os.environ.get("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")

CANON = """CREATE FUNCTION pg_temp.canon_key(k text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE array_length(p, 1)
    WHEN 4 THEN p[1] || ':' || replace(p[3], '-', '') || ':' || p[4]
    WHEN 3 THEN p[1] || ':' || replace(p[2], '-', '') || ':' || p[3]
    ELSE k END
  FROM (SELECT string_to_array(k, ':') p) s $$"""

# table -> (primary key without character_key, merge on conflict)
MERGED = {
    "characters": ("game, source_game", """
      full_name=CASE WHEN excluded.last_seen>=t.last_seen THEN excluded.full_name ELSE t.full_name END,
      name=CASE WHEN excluded.last_seen>=t.last_seen THEN excluded.name ELSE t.name END,
      realm=CASE WHEN excluded.last_seen>=t.last_seen THEN excluded.realm ELSE t.realm END,
      guild=CASE WHEN excluded.last_seen>=t.last_seen THEN excluded.guild ELSE t.guild END,
      level=CASE WHEN excluded.last_seen>=t.last_seen THEN excluded.level ELSE t.level END,
      race=CASE WHEN excluded.last_seen>=t.last_seen THEN excluded.race ELSE t.race END,
      class=CASE WHEN excluded.last_seen>=t.last_seen THEN excluded.class ELSE t.class END,
      class_file=CASE WHEN excluded.last_seen>=t.last_seen THEN excluded.class_file ELSE t.class_file END,
      zone=CASE WHEN excluded.last_seen>=t.last_seen THEN excluded.zone ELSE t.zone END,
      first_seen=LEAST(t.first_seen,excluded.first_seen),
      last_seen=GREATEST(t.last_seen,excluded.last_seen),
      seen_count=GREATEST(t.seen_count,excluded.seen_count)"""),
    "character_observations": ("game, source_game, day", """
      sightings=GREATEST(t.sightings,excluded.sightings),
      first_seen=LEAST(t.first_seen,excluded.first_seen),
      last_seen=GREATEST(t.last_seen,excluded.last_seen)"""),
    # The same sighting twice in one sweep: keep the first.
    "character_location_observations": ("game, source_game, sweep_id", None),
}
# Keys copied from characters; nothing to merge.
UPDATED = ("character_inspects", "character_appearance_observations", "character_appearance_skin_choices")
APPEARANCE_FK = "character_appearance_skin_cho_run_id_source_game_character_fkey"


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
    cur.execute(CANON)
    cur.execute("""CREATE TABLE IF NOT EXISTS character_key_repair_backup (
      table_name TEXT NOT NULL, repaired_at TIMESTAMPTZ NOT NULL DEFAULT now(), row_json JSONB NOT NULL)""")
    bad = "pg_temp.canon_key(character_key) <> character_key"
    for table in (*MERGED, *UPDATED):
        cur.execute(f"""INSERT INTO character_key_repair_backup (table_name, row_json)
          SELECT %s, to_jsonb(t) FROM {table} t WHERE {bad}""", (table,))

    for table, (pk, merge) in MERGED.items():
        cur.execute(f"CREATE TEMP TABLE moved (LIKE {table}) ON COMMIT DROP")
        cur.execute(f"WITH d AS (DELETE FROM {table} WHERE {bad} RETURNING *) INSERT INTO moved SELECT * FROM d")
        moved = cur.rowcount
        cur.execute("UPDATE moved SET character_key = pg_temp.canon_key(character_key)")
        order = "observed_at" if table == "character_location_observations" else "last_seen DESC"
        if table == "characters":
            # Several old keys can land on one new key: fold them before the upsert.
            cur.execute("""UPDATE moved m SET first_seen = a.fs, seen_count = a.sc FROM (
              SELECT game, source_game, character_key, MIN(first_seen) fs, MAX(seen_count) sc
              FROM moved GROUP BY 1, 2, 3) a
              WHERE (m.game, m.source_game, m.character_key) = (a.game, a.source_game, a.character_key)""")
        cur.execute(f"""SELECT COUNT(*) FROM (SELECT DISTINCT {pk}, character_key FROM moved) m
          JOIN {table} USING ({pk}, character_key)""")
        existing = cur.fetchone()[0]
        cur.execute(f"""INSERT INTO {table} AS t
          SELECT DISTINCT ON ({pk}, character_key) * FROM moved ORDER BY {pk}, character_key, {order}
          ON CONFLICT ({pk}, character_key) DO {'UPDATE SET ' + merge if merge else 'NOTHING'}""")
        print(f"{table}: {moved} rows rekeyed, {moved - cur.rowcount} folded together, "
              f"{existing} merged into an existing row", flush=True)
        cur.execute("DROP TABLE moved")

    cur.execute(f"""SELECT COUNT(*) FROM (SELECT 1 FROM character_appearance_observations
      GROUP BY run_id, source_game, pg_temp.canon_key(character_key) HAVING COUNT(*) > 1) x""")
    if cur.fetchone()[0]:
        raise SystemExit("character_appearance_observations would get duplicate keys; nothing was written")
    cur.execute("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = %s", (APPEARANCE_FK,))
    fk = cur.fetchone()[0]
    cur.execute(f"ALTER TABLE character_appearance_skin_choices DROP CONSTRAINT {APPEARANCE_FK}")
    for table in UPDATED:
        cur.execute(f"UPDATE {table} SET character_key = pg_temp.canon_key(character_key) WHERE {bad}")
        print(f"{table}: {cur.rowcount} rows rekeyed", flush=True)
    cur.execute(f"ALTER TABLE character_appearance_skin_choices ADD CONSTRAINT {APPEARANCE_FK} {fk}")

    for table in (*MERGED, *UPDATED):
        cur.execute(f"SELECT COUNT(*) FROM {table} WHERE {bad}")
        assert cur.fetchone()[0] == 0, table
    if args.apply:
        conn.commit()
        print("committed", flush=True)
    else:
        conn.rollback()
        print("dry run: rolled back (use --apply to commit)", flush=True)
    conn.close()


if __name__ == "__main__":
    main()
