#!/usr/bin/env python3
"""Merge Blizzard-verified Forever characters into the Forever census.

    python -B tools/merge-foreverpulse-verified-pg.py --dsn "<dsn>" --dry-run
    python -B tools/merge-foreverpulse-verified-pg.py --dsn "<dsn>"

Reads marketlens_import.external_verified_characters (tools/import-foreverpulse-verified-pg.py) and
writes canonical rows the census pages read, the way tools/merge-foreverpulse-who-pg.sql did for /who
batches:

- Only characters whose realm id has a known name in census_collection_realms are merged. The name is
  never guessed; the rest stay in the external table until their realm is named.
- Game keys use the current importer's form, realm:<region>:forever:<Realm>-<Faction>. A missing
  faction row for a named realm is added to census_collection_realms.
- A character whose character_key already exists under any Forever game is not inserted again (the
  census has legacy realm:<Realm>-<Faction> keys too). In the same game, only an empty guild is filled.
- A character Blizzard shows without a guild is stored with guild '' like the addon's own rows.
- first_seen = last_seen = Blizzard last login (verification time if missing), seen_count 1, and one
  character_observations row on that day.
- Each source file merges once (external_verified_canonical_imports). One transaction; --dry-run rolls back.
Afterwards rebuild the census cache (laragon-php/build-census-cache.php).
"""
import argparse
import os

import psycopg2
from psycopg2.extras import execute_values

DSN = os.environ.get("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")
REGION_ID = {"us": 1, "eu": 3}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dsn", default=DSN)
    ap.add_argument("--dry-run", action="store_true")
    a = ap.parse_args()
    conn = psycopg2.connect(a.dsn)
    try:
        cur = conn.cursor()
        cur.execute("SELECT pg_advisory_xact_lock(hashtext('marketlens-import'))")
        cur.execute("SET LOCAL search_path TO marketlens_import")
        cur.execute("""CREATE TABLE IF NOT EXISTS external_verified_canonical_imports (
            source_sha256 text PRIMARY KEY REFERENCES external_verified_sources(sha256),
            merged integer NOT NULL, skipped_existing integer NOT NULL, skipped_unnamed_realm integer NOT NULL,
            applied_at timestamptz NOT NULL DEFAULT now())""")
        cur.execute("""SELECT s.sha256 FROM external_verified_sources s
            WHERE NOT EXISTS (SELECT 1 FROM external_verified_canonical_imports m WHERE m.source_sha256=s.sha256)""")
        pending = [r[0] for r in cur.fetchall()]
        if not pending:
            print("NOTHING TO MERGE")
            conn.rollback()
            return

        cur.execute("""SELECT DISTINCT ON (region, realm_id) region, realm_id, realm_name, realm_normalized
            FROM census_collection_realms WHERE source_game='forever' ORDER BY region, realm_id, game""")
        realms = {(r, rid): (name, norm) for r, rid, name, norm in cur.fetchall()}
        cur.execute("SELECT game, faction FROM census_collection_realms WHERE source_game='forever'")
        known_games = {g for g, _ in cur.fetchall()}
        cur.execute("SELECT character_key, game, guild FROM characters WHERE source_game='forever'")
        existing = {}
        for key, game, guild in cur.fetchall():
            existing.setdefault(key, []).append((game, guild))

        cur.execute("""SELECT region, name, faction, race, class, level, guild, realm_id_observed,
                EXTRACT(EPOCH FROM COALESCE(last_login_at, verified_at))::bigint, source_sha256
            FROM external_verified_characters WHERE source_sha256 = ANY(%s)""", (pending,))
        rows = cur.fetchall()

        new_realms, chars, obs, guild_fills, per_game = {}, [], [], [], {}
        stats = {s: {"merged": 0, "skipped_existing": 0, "skipped_unnamed_realm": 0} for s in pending}
        for region, name, faction, race, cls, level, guild, realm_id, seen, sha in rows:
            realm = realms.get((region, realm_id))
            if not realm:
                stats[sha]["skipped_unnamed_realm"] += 1
                continue
            realm_name, realm_norm = realm
            guild = guild or ""  # the census stores "no guild" as an empty string
            game = f"realm:{region}:forever:{realm_name}-{faction}"
            key = f"forever:{realm_norm.lower()}:{name.lower()}"
            if key in existing:
                for g, old_guild in existing[key]:
                    if g == game and not old_guild and guild:
                        guild_fills.append((guild, game, key))
                stats[sha]["skipped_existing"] += 1
                continue
            if game not in known_games:
                new_realms[game] = (game, "forever", region, REGION_ID[region], realm_id, realm_name, realm_norm, faction)
            chars.append((game, "forever", key, f"{name}-{realm_name}", name, realm_name, guild, level,
                          race, cls, cls.upper(), "", seen, seen, 1))
            obs.append((game, "forever", key, seen // 86400, 1, seen, seen))
            per_game[game] = max(per_game.get(game, 0), seen)
            existing[key] = [(game, guild)]
            stats[sha]["merged"] += 1

        if new_realms:
            execute_values(cur, """INSERT INTO census_collection_realms
                (game,source_game,region,region_id,realm_id,realm_name,realm_normalized,faction) VALUES %s
                ON CONFLICT (game) DO NOTHING""", list(new_realms.values()))
        if chars:
            execute_values(cur, """INSERT INTO characters
                (game,source_game,character_key,full_name,name,realm,guild,level,race,class,class_file,zone,
                 first_seen,last_seen,seen_count) VALUES %s ON CONFLICT (game,source_game,character_key) DO NOTHING""",
                chars, page_size=1000)
            execute_values(cur, """INSERT INTO character_observations
                (game,source_game,character_key,day,sightings,first_seen,last_seen) VALUES %s
                ON CONFLICT (game,source_game,character_key,day) DO NOTHING""", obs, page_size=1000)
        for guild, game, key in guild_fills:
            cur.execute("""UPDATE characters SET guild=%s WHERE source_game='forever' AND game=%s
                AND character_key=%s AND guild=''""", (guild, game, key))
        for game, latest in per_game.items():
            cur.execute("""INSERT INTO datasets (game,source_game,updated_at)
                VALUES (%s,'forever',to_char(to_timestamp(%s) AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"'))
                ON CONFLICT (game) DO UPDATE SET updated_at=GREATEST(datasets.updated_at,EXCLUDED.updated_at)""",
                (game, latest))
        for sha, s in stats.items():
            cur.execute("""INSERT INTO local_imports (source_sha256,kind,bucket,source_game,imported_at_utc,details_json)
                VALUES (%s,'external_verified_characters','forever','forever',
                        to_char(clock_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),%s)
                ON CONFLICT DO NOTHING""",
                (sha, '{"source":"forever-pulse.com directory, Blizzard-verified","merged":%d,"skippedExisting":%d,'
                      '"skippedUnnamedRealm":%d}' % (s["merged"], s["skipped_existing"], s["skipped_unnamed_realm"])))
            cur.execute("""INSERT INTO external_verified_canonical_imports
                (source_sha256,merged,skipped_existing,skipped_unnamed_realm) VALUES (%s,%s,%s,%s)""",
                (sha, s["merged"], s["skipped_existing"], s["skipped_unnamed_realm"]))

        print(f"{'DRY RUN ' if a.dry_run else ''}MERGED characters={len(chars)} guild_fills={len(guild_fills)} "
              f"new_realm_rows={len(new_realms)} games={len(per_game)}")
        for sha, s in stats.items():
            print(f"  {sha[:12]} {s}")
        for game in sorted(per_game):
            print(f"  {game}: {sum(1 for c in chars if c[0] == game)}")
        if a.dry_run:
            conn.rollback()
        else:
            conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


if __name__ == "__main__":
    main()
