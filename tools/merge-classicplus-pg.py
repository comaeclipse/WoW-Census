#!/usr/bin/env python3
"""Merge imported ClassicPlus characters into the Forever census.

    python -B tools/merge-classicplus-pg.py --dry-run
    python -B tools/merge-classicplus-pg.py [--dsn "<dsn>"] [--region kr]
    python -B tools/import-live.py --target live classicplus-merge   # same merge on wowcensus.io

Reads marketlens_import.external_classicplus_characters (tools/import-classicplus-pg.py) and writes the
canonical rows the census pages read, the same way tools/merge-foreverpulse-verified-pg.py does:

- Every row's date is the day its file was imported (external_classicplus_sources.imported_at), not
  the packed ClassicPlus day: first_seen = last_seen = imported_at, seen_count 1, and one
  character_observations row on that day.
- Race and class are stored localised by the client (enUS, esMX, koKR); they are mapped to the
  census's English names. An unknown value stops the merge instead of being guessed.
- Game keys are realm:<region>:forever:<Realm>-<Faction> with the realm name as ClassicPlus recorded
  it. A realm id already in census_collection_realms keeps that name; a new one is added.
- A character whose character_key already exists under any Forever game is not inserted again. In the
  same game, only an empty guild is filled.
- Each source file merges once (external_classicplus_canonical_imports). One transaction; --dry-run
  rolls back. Afterwards rebuild the census cache (laragon-php/build-census-cache.php).
"""
import argparse
import json
import os
import sys

import psycopg2
from psycopg2.extras import execute_values

DSN = os.environ.get("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")
REGION_ID = {"us": 1, "kr": 2, "eu": 3}

RACES = {
    "Human": "Human", "Dwarf": "Dwarf", "Night Elf": "Night Elf", "Gnome": "Gnome",
    "High Order Skyborne": "High Order Skyborne", "Windshaper Skyborne": "Windshaper Skyborne",
    "Orc": "Orc", "Undead": "Undead", "Tauren": "Tauren", "Troll": "Troll",
    # esMX
    "Humano": "Human", "Enano": "Dwarf", "Elfo de la noche": "Night Elf", "Gnomo": "Gnome",
    "Cielonato de la Suma Orden": "High Order Skyborne",
    # koKR
    "인간": "Human", "드워프": "Dwarf", "나이트 엘프": "Night Elf", "노움": "Gnome",
    "고등 마법원 스카이본": "High Order Skyborne",
}
CLASSES = {
    **{c: c for c in ("Warrior", "Paladin", "Hunter", "Rogue", "Priest", "Shaman", "Mage", "Warlock", "Druid")},
    # esMX
    "Guerrero": "Warrior", "Paladín": "Paladin", "Cazador": "Hunter", "Pícaro": "Rogue",
    "Sacerdote": "Priest", "Chamán": "Shaman", "Mago": "Mage", "Brujo": "Warlock", "Druida": "Druid",
    # koKR
    "전사": "Warrior", "성기사": "Paladin", "사냥꾼": "Hunter", "도적": "Rogue", "사제": "Priest",
    "주술사": "Shaman", "마법사": "Mage", "흑마법사": "Warlock", "드루이드": "Druid",
}


def main():
    sys.stdout.reconfigure(errors="backslashreplace")
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--dsn", default=DSN)
    ap.add_argument("--region", choices=sorted(REGION_ID), help="merge only this region's sources")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--archive", help=argparse.SUPPRESS)  # set by tools/import-live.py; nothing to archive here
    a = ap.parse_args()
    conn = psycopg2.connect(a.dsn)
    try:
        cur = conn.cursor()
        cur.execute("SELECT pg_advisory_xact_lock(hashtext('marketlens-import'))")
        cur.execute("SET LOCAL search_path TO marketlens_import")
        cur.execute("""CREATE TABLE IF NOT EXISTS external_classicplus_canonical_imports (
            source_sha256 text PRIMARY KEY REFERENCES external_classicplus_sources(sha256),
            canonical_at timestamptz NOT NULL, merged integer NOT NULL, skipped_existing integer NOT NULL,
            applied_at timestamptz NOT NULL DEFAULT now())""")
        cur.execute("""SELECT s.sha256, s.region, s.realm_id, s.realm_name, s.faction,
                EXTRACT(EPOCH FROM s.imported_at)::bigint
            FROM external_classicplus_sources s
            WHERE NOT EXISTS (SELECT 1 FROM external_classicplus_canonical_imports m WHERE m.source_sha256=s.sha256)
              AND (%s IS NULL OR s.region = %s)
            ORDER BY s.imported_at, s.sha256""", (a.region, a.region))
        sources = cur.fetchall()
        if not sources:
            print("NOTHING TO MERGE")
            conn.rollback()
            return

        cur.execute("""SELECT DISTINCT ON (region, realm_id) region, realm_id, realm_name, realm_normalized
            FROM census_collection_realms WHERE source_game='forever' ORDER BY region, realm_id, game""")
        realms = {(r, rid): (name, norm) for r, rid, name, norm in cur.fetchall()}
        cur.execute("SELECT game FROM census_collection_realms WHERE source_game='forever'")
        known_games = {g for (g,) in cur.fetchall()}
        cur.execute("SELECT character_key, game, guild FROM characters WHERE source_game='forever'")
        existing = {}
        for key, game, guild in cur.fetchall():
            existing.setdefault(key, []).append((game, guild))

        new_realms, chars, obs, guild_fills, per_game, stats = {}, [], [], [], {}, {}
        for sha, region, realm_id, recorded_name, faction, seen in sources:
            realm_name, realm_norm = realms.get((region, realm_id)) or (recorded_name, recorded_name.replace(" ", ""))
            realms[(region, realm_id)] = (realm_name, realm_norm)
            game = f"realm:{region}:forever:{realm_name}-{faction}"
            if game not in known_games:
                new_realms[game] = (game, "forever", region, REGION_ID[region], realm_id, realm_name, realm_norm, faction)
            cur.execute("""SELECT name, race_raw, class_raw, level, guild FROM external_classicplus_characters
                WHERE source_sha256=%s ORDER BY name""", (sha,))
            rows = cur.fetchall()
            unknown = sorted({r[1] for r in rows if r[1] not in RACES} | {r[2] for r in rows if r[2] not in CLASSES})
            if unknown:
                raise SystemExit(f"{sha[:12]}: no English name for {unknown}; add them to RACES/CLASSES")
            s = stats[sha] = {"game": game, "canonicalAt": seen, "merged": 0, "skippedExisting": 0, "guildFills": 0}
            for name, race_raw, class_raw, level, guild in rows:
                key = f"forever:{realm_norm.lower()}:{name.lower()}"
                if key in existing:
                    for g, old_guild in existing[key]:
                        if g == game and not old_guild and guild:
                            guild_fills.append((guild, game, key))
                            s["guildFills"] += 1
                    s["skippedExisting"] += 1
                    continue
                cls = CLASSES[class_raw]
                chars.append((game, "forever", key, f"{name}-{realm_name}", name, realm_name, guild, level,
                              RACES[race_raw], cls, cls.upper(), "", seen, seen, 1))
                obs.append((game, "forever", key, seen // 86400, 1, seen, seen))
                per_game[game] = max(per_game.get(game, 0), seen)
                existing[key] = [(game, guild)]
                s["merged"] += 1

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
            bucket = s["game"].removeprefix("realm:")
            cur.execute("""INSERT INTO local_imports (source_sha256,kind,bucket,source_game,imported_at_utc,details_json)
                VALUES (%s,'external_classicplus_characters',%s,'forever',
                        to_char(clock_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),%s)
                ON CONFLICT DO NOTHING""",
                (sha, bucket, json.dumps({"source": "ClassicPlus addon save, dated by import day", **s},
                                         ensure_ascii=False)))
            cur.execute("""INSERT INTO external_classicplus_canonical_imports
                (source_sha256,canonical_at,merged,skipped_existing) VALUES (%s,to_timestamp(%s),%s,%s)""",
                (sha, s["canonicalAt"], s["merged"], s["skippedExisting"]))

        print(f"{'DRY RUN ' if a.dry_run else ''}MERGED characters={len(chars)} guild_fills={len(guild_fills)} "
              f"new_realm_rows={len(new_realms)} games={len(per_game)}")
        for sha, s in stats.items():
            print(f"  {sha[:12]} {s['game']}: merged={s['merged']} skipped_existing={s['skippedExisting']} "
                  f"guild_fills={s['guildFills']} day={s['canonicalAt'] // 86400}")
        if a.dry_run:
            conn.rollback()
        else:
            conn.commit()
            print("CHANGED sources=forever" if chars or guild_fills else "CHANGED sources=", flush=True)
    except BaseException:
        conn.rollback()
        raise
    finally:
        conn.close()


if __name__ == "__main__":
    main()
