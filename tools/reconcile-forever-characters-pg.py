#!/usr/bin/env python3
"""Apply the newest known guild and level to every census row of a WoW: Forever character.

    python -B tools/reconcile-forever-characters-pg.py [--dsn "<dsn>"] [--dry-run]

A Forever character is one name per region; the census keeps one row per hidden collector realm it
was seen on, and each row keeps the guild and level of its own last sighting. This step takes the
newest observation from every reliable source and applies it to all of the character's rows:

- census sightings (WoWCensus addon, ClassicPlus and merged Forever Pulse rows): characters.last_seen
- Forever Pulse's Blizzard-verified rows: external_verified_characters.last_login_at
- Blizzard's profile API (tools/sync-forever-armory.py): forever_armory_profiles.last_login_at

The newest one wins (ties go to the census). A Blizzard login newer than every census sighting also
counts as a sighting: the character's newest row gets that last_seen and a character_observations row.
Characters the census has never seen are not added (Blizzard does not say which hidden realm they are on).

The save importer, the Forever Pulse merge and the Armory sync run this after they write. After the first
full pass a run is incremental (characters whose rows disagree, plus Forever Pulse and Armory rows added
since the last run; forever_reconcile_state); --full checks everyone. One
transaction; --dry-run reports and rolls back. Afterwards the census cache needs rebuilding.
"""
from __future__ import annotations

import argparse
import hashlib
import os
import sys
import time

import psycopg2
import psycopg2.extras

DSN = os.environ.get("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")

# Which characters to look at. A full pass takes every one (minutes); an incremental pass takes the
# characters whose census rows disagree, plus those with Forever Pulse or Armory rows added since the last pass.
SQL_CANDIDATES_FULL = """
CREATE TEMP TABLE forever_cand ON COMMIT DROP AS
SELECT DISTINCT lower(substr(realm,8,2)) region, lower(name) lname FROM characters
WHERE source_game='forever' AND realm ~ '^CAMWOW (US|EU|KR)';
"""
SQL_CANDIDATES_SINCE = """
CREATE TEMP TABLE forever_cand ON COMMIT DROP AS
SELECT lower(substr(realm,8,2)) region, lower(name) lname FROM characters
WHERE source_game='forever' AND realm ~ '^CAMWOW (US|EU|KR)'
GROUP BY 1, 2 HAVING count(*) > 1 AND (min(COALESCE(guild,'')) <> max(COALESCE(guild,'')) OR min(level) <> max(level))
UNION SELECT region, lower(name) FROM external_verified_characters WHERE verified_at > %(since)s
UNION SELECT region, lower(full_name) FROM forever_armory_profiles WHERE fetched_at > %(since)s;
"""

SQL_NEWEST = """
CREATE INDEX ON forever_cand(region, lname);
ANALYZE forever_cand;
CREATE TEMP TABLE forever_ident ON COMMIT DROP AS
SELECT c.game, c.character_key, k.region, k.lname, c.guild, c.level, c.last_seen
FROM characters c JOIN forever_cand k ON k.region=lower(substr(c.realm,8,2)) AND k.lname=lower(c.name)
WHERE c.source_game='forever' AND c.realm ~ '^CAMWOW (US|EU|KR)';
CREATE INDEX ON forever_ident(region, lname);
ANALYZE forever_ident;

CREATE TEMP TABLE forever_newest ON COMMIT DROP AS
SELECT DISTINCT ON (region, lname) region, lname, guild, level, t, src FROM (
  SELECT region, lname, COALESCE(guild,'') guild, level, last_seen t, 0 pri, 'census' src FROM forever_ident
  UNION ALL
  SELECT v.region, lower(v.name), COALESCE(v.guild,''), v.level, EXTRACT(EPOCH FROM v.last_login_at)::bigint, 1, 'foreverpulse'
    FROM external_verified_characters v
    WHERE v.last_login_at IS NOT NULL AND EXISTS (SELECT 1 FROM forever_ident i WHERE i.region=v.region AND i.lname=lower(v.name))
  UNION ALL
  SELECT p.region, lower(p.full_name), COALESCE(p.guild_name,''), p.level, EXTRACT(EPOCH FROM p.last_login_at)::bigint, 2, 'armory'
    FROM forever_armory_profiles p
    WHERE p.last_login_at IS NOT NULL AND EXISTS (SELECT 1 FROM forever_ident i WHERE i.region=p.region AND i.lname=lower(p.full_name))
) o ORDER BY region, lname, t DESC, pri;
CREATE INDEX ON forever_newest(region, lname);
"""

SQL_APPLY = """
WITH changed AS (
  UPDATE characters c SET guild=n.guild, level=COALESCE(n.level, c.level)
  FROM forever_ident i JOIN forever_newest n ON n.region=i.region AND n.lname=i.lname
  WHERE c.source_game='forever' AND c.game=i.game AND c.character_key=i.character_key
    AND (c.guild IS DISTINCT FROM n.guild OR (n.level IS NOT NULL AND c.level IS DISTINCT FROM n.level))
  RETURNING c.guild IS DISTINCT FROM i.guild guild_changed, c.level IS DISTINCT FROM i.level level_changed
) SELECT count(*), count(*) FILTER (WHERE guild_changed), count(*) FILTER (WHERE level_changed) FROM changed;
"""

# A Blizzard login newer than every census sighting is a sighting on the character's newest row.
SQL_SIGHTINGS = """
CREATE TEMP TABLE forever_seen ON COMMIT DROP AS
SELECT DISTINCT ON (i.region, i.lname) i.game, i.character_key, n.t
FROM forever_ident i JOIN forever_newest n ON n.region=i.region AND n.lname=i.lname
WHERE n.src <> 'census' AND n.t > (SELECT max(j.last_seen) FROM forever_ident j WHERE j.region=i.region AND j.lname=i.lname)
ORDER BY i.region, i.lname, i.last_seen DESC;
UPDATE characters c SET last_seen=s.t, seen_count=c.seen_count+1
FROM forever_seen s WHERE c.source_game='forever' AND c.game=s.game AND c.character_key=s.character_key;
INSERT INTO character_observations (game,source_game,character_key,day,sightings,first_seen,last_seen)
SELECT game,'forever',character_key,t/86400,1,t,t FROM forever_seen
ON CONFLICT (game,source_game,character_key,day) DO UPDATE SET sightings=character_observations.sightings+1,
  last_seen=GREATEST(character_observations.last_seen, EXCLUDED.last_seen);
"""


def reconcile(conn, dry_run: bool = False, full: bool = False) -> dict:
    started = time.time()
    cur = conn.cursor()
    cur.execute("SELECT pg_advisory_xact_lock(hashtext('marketlens-import'))")
    cur.execute("SET LOCAL search_path TO marketlens_import")
    for table in ("external_verified_characters", "forever_armory_profiles"):
        cur.execute("SELECT to_regclass(%s)", (f"marketlens_import.{table}",))
        if cur.fetchone()[0] is None:
            cur.execute(f"CREATE TEMP VIEW {table} AS SELECT NULL::text region, NULL::text name, NULL::text full_name, "
                        "NULL::text guild, NULL::text guild_name, NULL::integer level, NULL::timestamptz last_login_at, "
                        "NULL::timestamptz verified_at, NULL::timestamptz fetched_at WHERE false")
    cur.execute("""CREATE TABLE IF NOT EXISTS forever_reconcile_state (
      id boolean PRIMARY KEY DEFAULT true CHECK (id), last_run_at timestamptz NOT NULL)""")
    cur.execute("SELECT last_run_at, now() FROM forever_reconcile_state")
    row = cur.fetchone()
    cur.execute("SELECT now()")
    run_at = cur.fetchone()[0]
    mode = "full" if full or row is None else "incremental"
    if mode == "full":
        cur.execute(SQL_CANDIDATES_FULL)
    else:
        cur.execute(SQL_CANDIDATES_SINCE, {"since": row[0]})
    cur.execute(SQL_NEWEST)
    cur.execute(SQL_APPLY)
    rows, guilds, levels = cur.fetchone()
    cur.execute(SQL_SIGHTINGS)
    cur.execute("SELECT count(*) FROM forever_seen")
    sightings = cur.fetchone()[0]
    cur.execute("SELECT src, count(*) FROM forever_newest GROUP BY src")
    newest = dict(cur.fetchall())
    stats = {"mode": mode, "rowsUpdated": rows, "guildChanges": guilds, "levelChanges": levels, "blizzardSightings": sightings,
             "newestFrom": newest, "seconds": round(time.time() - started, 1)}
    if not dry_run:
        cur.execute("""INSERT INTO forever_reconcile_state (id, last_run_at) VALUES (true, %s)
          ON CONFLICT (id) DO UPDATE SET last_run_at = EXCLUDED.last_run_at""", (run_at,))
    if (rows or sightings) and not dry_run:
        # A local_imports row lets the site's publish step see that Forever data changed.
        stamp = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        sha = hashlib.sha256(f"reconcile-forever {stamp} {stats}".encode()).hexdigest()
        cur.execute("""INSERT INTO local_imports (source_sha256,kind,bucket,source_game,imported_at_utc,details_json)
            VALUES (%s,'reconcile_forever_characters','forever','forever',
                    to_char(clock_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),%s) ON CONFLICT DO NOTHING""",
                    (sha, psycopg2.extras.Json(stats)))
    return stats


def main() -> None:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    ap = argparse.ArgumentParser()
    ap.add_argument("--dsn", default=DSN)
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--full", action="store_true", help="check every character, not only changed ones")
    a = ap.parse_args()
    conn = psycopg2.connect(a.dsn)
    try:
        stats = reconcile(conn, a.dry_run, a.full)
        print(("DRY RUN " if a.dry_run else "") + "RECONCILED " + " ".join(f"{k}={v}" for k, v in stats.items()), flush=True)
        conn.rollback() if a.dry_run else conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


if __name__ == "__main__":
    main()
