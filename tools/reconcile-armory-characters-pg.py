#!/usr/bin/env python3
"""Apply newer Battle.net Armory guilds, levels and logins to the census rows of the same characters.

    python -B tools/reconcile-armory-characters-pg.py [--dsn "<dsn>"] [--source retail] [--full] [--dry-run]

Covers the editions tools/sync-character-armory.py enriches (Retail, MoP Classic, TBC Anniversary, Classic Era,
Season of Discovery, Hardcore); WoW: Forever has its own step, tools/reconcile-forever-characters-pg.py.

A census character is one name on one home realm in one region (characters_with_region), possibly with a row
in several collection buckets. When Blizzard's last login (character_armory_profiles.last_login_at) is newer
than every census sighting of that character, the Armory is the newest source and wins:

- level: the Armory level goes on every row;
- guild: a different Armory guild goes on every row, written the way the census already names that guild in
  the row's bucket ("Name" or "Name-Realm", possibly with a localized realm), so rosters don't split; a census
  guild with the same name before its "-Realm" is the same guild and is left alone; no Armory guild means the
  character left its guild;
- sighting: the newest row's last_seen becomes the login time, with a character_observations row, unless that
  would move a region-less row into another region (collection_region_at() looks at the time).

A lower Armory level means the name now belongs to a new character; those characters are skipped.
Race, class and faction are left alone: the census is composition by sighting. Characters the census has never
seen are not added. After the first full pass a run is incremental (profiles fetched since the last run;
armory_reconcile_state); --full checks everyone. One transaction; --dry-run reports and rolls back.
Afterwards the census cache needs rebuilding.
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
SOURCES = ("retail", "mop-classic", "classic-progression", "classic", "sod", "hardcore", "classic-beta")

SQL_PROFILES = """
CREATE TEMP TABLE arm_prof ON COMMIT DROP AS
SELECT DISTINCT ON (source_game, region, lower(realm_name), lower(name))
  source_game, region, lower(realm_name) lrealm, lower(name) lname, realm_name,
  NULLIF(TRIM(COALESCE(guild_name,'')),'') guild, profile_json->'guild'->'realm'->>'name' grealm,
  level, EXTRACT(EPOCH FROM last_login_at)::bigint t
FROM character_armory_profiles
WHERE last_login_at IS NOT NULL AND source_game = ANY(%(sources)s) AND (%(since)s::timestamptz IS NULL OR fetched_at > %(since)s)
ORDER BY source_game, region, lower(realm_name), lower(name), last_login_at DESC;
CREATE INDEX ON arm_prof(source_game, lname, lrealm);
ANALYZE arm_prof;

CREATE TEMP TABLE arm_ident ON COMMIT DROP AS
SELECT c.game, c.source_game, c.character_key, p.region, p.lrealm, p.lname, c.guild, c.level, c.last_seen
FROM characters c JOIN arm_prof p
  ON p.source_game=c.source_game AND p.lname=lower(c.name) AND p.lrealm=lower(c.realm)
 AND p.region=collection_region_at(c.game, c.last_seen)
WHERE c.source_game = ANY(%(sources)s);
CREATE INDEX ON arm_ident(source_game, region, lrealm, lname);
ANALYZE arm_ident;

-- Characters whose Armory login is newer than every census sighting.
-- A lower Armory level means the name now belongs to a new character: those are skipped (arm_skip).
CREATE TEMP TABLE arm_newer ON COMMIT DROP AS
SELECT p.*, i.seen, i.top_level FROM arm_prof p JOIN (
  SELECT source_game, region, lrealm, lname, max(last_seen) seen, max(level) top_level FROM arm_ident GROUP BY 1,2,3,4
) i USING (source_game, region, lrealm, lname)
WHERE p.t > i.seen;
CREATE TEMP TABLE arm_win ON COMMIT DROP AS
SELECT * FROM arm_newer WHERE level IS NULL OR top_level IS NULL OR level >= top_level;
CREATE INDEX ON arm_win(source_game, region, lrealm, lname);
ANALYZE arm_win;

-- How the census already names each Armory guild, per bucket. Guild names never contain "-": the census adds
-- "-Realm" for a guild of another realm, sometimes in the client's language ("-Ревущийфьорд" for Howling Fjord).
-- Prefer the spelling that names the Armory's guild realm, then the most common one.
CREATE TEMP TABLE arm_forms ON COMMIT DROP AS
SELECT DISTINCT ON (c.game, w.guild, w.grealm) c.game, w.guild, w.grealm, c.guild form
FROM (SELECT DISTINCT source_game, guild, grealm FROM arm_win WHERE guild IS NOT NULL) w
JOIN characters c ON c.source_game=w.source_game AND split_part(c.guild, '-', 1)=w.guild
GROUP BY c.game, w.guild, w.grealm, c.guild
ORDER BY c.game, w.guild, w.grealm,
  (c.guild IN (w.guild, w.guild || '-' || COALESCE(w.grealm,''), w.guild || '-' || replace(COALESCE(w.grealm,''), ' ', ''))) DESC,
  count(*) DESC, c.guild;
CREATE INDEX ON arm_forms(game, guild, grealm);
"""

SQL_APPLY = """
WITH target AS (
  SELECT i.game, i.source_game, i.character_key, i.guild old_guild, i.level old_level,
    CASE
      WHEN w.guild IS NULL THEN ''
      WHEN split_part(COALESCE(i.guild,''), '-', 1) = w.guild THEN i.guild
      ELSE COALESCE(f.form, CASE WHEN w.grealm IS NULL OR lower(w.grealm)=w.lrealm THEN w.guild ELSE w.guild || '-' || w.grealm END)
    END new_guild,
    COALESCE(w.level, i.level) new_level
  FROM arm_ident i JOIN arm_win w USING (source_game, region, lrealm, lname)
  LEFT JOIN arm_forms f ON f.game=i.game AND f.guild=w.guild AND f.grealm IS NOT DISTINCT FROM w.grealm
), changed AS (
  UPDATE characters c SET guild=t.new_guild, level=t.new_level
  FROM target t
  WHERE c.game=t.game AND c.source_game=t.source_game AND c.character_key=t.character_key
    AND (COALESCE(c.guild,'') IS DISTINCT FROM COALESCE(t.new_guild,'') OR c.level IS DISTINCT FROM t.new_level)
  RETURNING c.source_game, COALESCE(c.guild,'') IS DISTINCT FROM COALESCE(t.old_guild,'') guild_changed,
    c.level IS DISTINCT FROM t.old_level level_changed
) SELECT source_game, count(*), count(*) FILTER (WHERE guild_changed), count(*) FILTER (WHERE level_changed)
  FROM changed GROUP BY 1 ORDER BY 1;
"""

# The login is a sighting on the character's newest row, unless the new time would put the row in another region.
SQL_SIGHTINGS = """
CREATE TEMP TABLE arm_seen ON COMMIT DROP AS
SELECT DISTINCT ON (i.source_game, i.region, i.lrealm, i.lname) i.game, i.source_game, i.character_key, w.t
FROM arm_ident i JOIN arm_win w USING (source_game, region, lrealm, lname)
WHERE i.last_seen = w.seen AND collection_region_at(i.game, w.t) IS NOT DISTINCT FROM i.region
ORDER BY i.source_game, i.region, i.lrealm, i.lname, i.character_key;
UPDATE characters c SET last_seen=GREATEST(c.last_seen,s.t),
  seen_count=c.seen_count + CASE WHEN EXISTS (
    SELECT 1 FROM character_observations o
    WHERE o.game=s.game AND o.source_game=s.source_game AND o.character_key=s.character_key AND o.day=s.t/86400
  ) THEN 0 ELSE 1 END
FROM arm_seen s WHERE c.game=s.game AND c.source_game=s.source_game AND c.character_key=s.character_key;
INSERT INTO character_observations (game,source_game,character_key,day,sightings,first_seen,last_seen)
SELECT game,source_game,character_key,t/86400,1,t,t FROM arm_seen
ON CONFLICT (game,source_game,character_key,day) DO UPDATE SET
  sightings=GREATEST(character_observations.sightings, EXCLUDED.sightings),
  first_seen=LEAST(character_observations.first_seen, EXCLUDED.first_seen),
  last_seen=GREATEST(character_observations.last_seen, EXCLUDED.last_seen);
"""


def reconcile(conn, dry_run: bool = False, full: bool = False, sources: tuple[str, ...] = SOURCES) -> dict:
    started = time.time()
    cur = conn.cursor()
    cur.execute("SELECT pg_advisory_xact_lock(hashtext('marketlens-import'))")
    cur.execute("SET LOCAL search_path TO marketlens_import")
    cur.execute("SELECT to_regclass('marketlens_import.character_armory_profiles')")
    if cur.fetchone()[0] is None:
        return {"mode": "none", "reason": "no character_armory_profiles table"}
    cur.execute("""CREATE TABLE IF NOT EXISTS armory_reconcile_state (
      source_game text PRIMARY KEY, last_run_at timestamptz NOT NULL)""")
    cur.execute("SELECT now()")
    run_at = cur.fetchone()[0]
    cur.execute("SELECT source_game, last_run_at FROM armory_reconcile_state WHERE source_game = ANY(%s)", (list(sources),))
    last = dict(cur.fetchall())
    # One "since" for the run: the oldest of the sources' last runs (a source never run means a full pass).
    since = None if full or any(s not in last for s in sources) else min(last[s] for s in sources)
    mode = "full" if since is None else "incremental"
    cur.execute(SQL_PROFILES, {"sources": list(sources), "since": since})
    cur.execute(SQL_APPLY)
    applied = {s: {"rows": n, "guilds": g, "levels": l} for s, n, g, l in cur.fetchall()}
    cur.execute(SQL_SIGHTINGS)
    cur.execute("SELECT source_game, count(*) FROM arm_seen GROUP BY 1")
    seen = dict(cur.fetchall())
    cur.execute("SELECT source_game, count(*) FROM arm_prof GROUP BY 1")
    profiles = dict(cur.fetchall())
    cur.execute("SELECT source_game, count(*), count(*) FILTER (WHERE NOT (level IS NULL OR top_level IS NULL OR level >= top_level)) FROM arm_newer GROUP BY 1")
    rows = cur.fetchall()
    newer = {s: n for s, n, _ in rows}; skipped = {s: k for s, _, k in rows}
    per = {s: {"profiles": profiles.get(s, 0), "armoryNewer": newer.get(s, 0), "skippedNewCharacter": skipped.get(s, 0), **applied.get(s, {"rows": 0, "guilds": 0, "levels": 0}),
               "blizzardSightings": seen.get(s, 0)} for s in sources if profiles.get(s)}
    stats = {"mode": mode, "sources": per, "seconds": round(time.time() - started, 1)}
    if not dry_run:
        for s in sources:
            cur.execute("""INSERT INTO armory_reconcile_state (source_game, last_run_at) VALUES (%s, %s)
              ON CONFLICT (source_game) DO UPDATE SET last_run_at = EXCLUDED.last_run_at""", (s, run_at))
        # A local_imports row per changed edition lets the site's publish step see that its data changed.
        stamp = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        for s, v in per.items():
            if not (v["rows"] or v["blizzardSightings"]):
                continue
            sha = hashlib.sha256(f"reconcile-armory {s} {stamp} {v}".encode()).hexdigest()
            cur.execute("""INSERT INTO local_imports (source_sha256,kind,bucket,source_game,imported_at_utc,details_json)
                VALUES (%s,'reconcile_armory_characters',%s,%s,
                        to_char(clock_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),%s) ON CONFLICT DO NOTHING""",
                        (sha, s, s, psycopg2.extras.Json(v)))
    return stats


def main() -> None:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    ap = argparse.ArgumentParser()
    ap.add_argument("--dsn", default=DSN)
    ap.add_argument("--source", choices=SOURCES, action="append", help="limit to these editions (repeatable)")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--full", action="store_true", help="check every Armory profile, not only newly fetched ones")
    a = ap.parse_args()
    conn = psycopg2.connect(a.dsn)
    try:
        stats = reconcile(conn, a.dry_run, a.full, tuple(a.source or SOURCES))
        print(("DRY RUN " if a.dry_run else "") + "RECONCILED " + " ".join(f"{k}={v}" for k, v in stats.items()), flush=True)
        conn.rollback() if a.dry_run else conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


if __name__ == "__main__":
    main()
