#!/usr/bin/env python3
"""Import Blizzard-verified Forever characters (from the Forever Pulse directory) as isolated rows.

    python -B tools/import-foreverpulse-verified-pg.py <verified.jsonl> --dry-run
    python -B tools/import-foreverpulse-verified-pg.py <verified.jsonl>

Each input row was found by name on the Blizzard Forever profile API in its region, matched on
full name, race, class and faction, and its ruleset (from the profile, or for the first batch from
its guild) matched the realm type Forever Pulse saw it on. Guild may be null.
Name, region, ruleset, faction, race, class, level and guild come from Blizzard; the realm id is
Forever Pulse's observation (Blizzard exposes no Forever realm).

Like import-foreverpulse-who-pg.py, this never touches the addon's own `characters` table. Rows go
to marketlens_import.external_verified_characters keyed by (region, name). A file whose SHA-256 was
already imported is skipped. A row for a name that now belongs to a different Blizzard character is
an error unless --replace-reused-names is given. Newer verifications of the same character update it.
"""
import argparse
import hashlib
import json
import os
import shutil
from datetime import datetime, timezone
from pathlib import Path

DATA = Path.home() / "Documents" / "MarketLensData"
DSN = os.environ.get("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")
REGIONS = {"US": "us", "EU": "eu"}
RULESETS = {"Normal", "PvP", "Roleplay", "Roleplay PvP"}
FACTIONS = {"Alliance", "Horde"}
CLASSES = {"Warrior", "Paladin", "Hunter", "Rogue", "Priest", "Shaman", "Mage", "Warlock", "Druid"}
RACES = {"Human", "Dwarf", "Night Elf", "Gnome", "Orc", "Undead", "Tauren", "Troll"}  # plus any "... Skyborne"
FIELDS = ("name", "region", "ruleset", "faction", "race", "class", "level", "guild", "guild_id",
          "blizzard_character_id", "last_login_utc", "realm_id_observed", "forever_pulse_id", "verified_at_utc")


def utc(ms_or_s):
    if ms_or_s is None:
        return None
    v = int(ms_or_s)
    return datetime.fromtimestamp(v / 1000 if v > 10**11 else v, timezone.utc)


def load(path):
    rows, names, ids = [], set(), set()
    for n, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        if not line.strip():
            continue
        r = json.loads(line)
        missing = [f for f in FIELDS if f not in r]
        if missing:
            raise RuntimeError(f"line {n}: missing {missing}")
        region = REGIONS.get(r["region"])
        if not region:
            raise RuntimeError(f"line {n}: bad region {r['region']!r}")
        if r["ruleset"] not in RULESETS or r["faction"] not in FACTIONS or r["class"] not in CLASSES:
            raise RuntimeError(f"line {n}: bad ruleset/faction/class {r['ruleset']!r} {r['faction']!r} {r['class']!r}")
        if r["race"] not in RACES and not r["race"].endswith("Skyborne"):
            raise RuntimeError(f"line {n}: unknown race {r['race']!r}")
        if not isinstance(r["level"], int) or not 1 <= r["level"] <= 90:
            raise RuntimeError(f"line {n}: bad level {r['level']!r}")
        name = " ".join(str(r["name"]).split())
        if not name:
            raise RuntimeError(f"line {n}: missing name")
        # Guild is optional: the ruleset comes from the character profile itself.
        if (r["guild"] is None) != (r["guild_id"] is None) or (r["guild"] is not None and (
                not str(r["guild"]).strip() or not isinstance(r["guild_id"], int))):
            raise RuntimeError(f"line {n}: guild and guild_id must both be set or both be null")
        key = (region, name.casefold())
        if key in names:
            raise RuntimeError(f"line {n}: duplicate name {key}")
        # Names are unique per region; Blizzard character ids are not (two different characters
        # in one region can share an id, presumably one per hidden server), so ids are not checked.
        names.add(key)
        rows.append((region, name, r["ruleset"], r["faction"], r["race"], r["class"], r["level"],
                     r["guild"], r["guild_id"], r["blizzard_character_id"], utc(r["last_login_utc"]),
                     int(r["realm_id_observed"]), r["forever_pulse_id"], utc(r["verified_at_utc"])))
    if not rows:
        raise RuntimeError("no rows")
    return rows


def summary(rows):
    by = {}
    for r in rows:
        by[(r[0], r[2], r[3])] = by.get((r[0], r[2], r[3]), 0) + 1
    return "\n".join(f"  {region.upper()} {ruleset:13} {faction:8} {n}" for (region, ruleset, faction), n in sorted(by.items()))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("source", type=Path)
    ap.add_argument("--dsn", default=DSN)
    ap.add_argument("--dry-run", action="store_true", help="validate and summarize only; no archive, no database")
    ap.add_argument("--replace-reused-names", action="store_true")
    a = ap.parse_args()

    raw = a.source.read_bytes()
    digest = hashlib.sha256(raw).hexdigest()
    rows = load(a.source)
    verified = [r[13] for r in rows]
    print(f"VALID rows={len(rows)} sha256={digest} verified {min(verified):%Y-%m-%d %H:%M} .. {max(verified):%Y-%m-%d %H:%M} UTC")
    print(summary(rows))
    if a.dry_run:
        return

    archive = DATA / "imports" / f"foreverpulse-verified-{digest[:12]}"
    archive.mkdir(parents=True, exist_ok=True)
    archived = archive / a.source.name
    if archived.exists() and hashlib.sha256(archived.read_bytes()).hexdigest() != digest:
        raise RuntimeError("Archive path contains different content")
    if not archived.exists():
        shutil.copyfile(a.source, archived)

    import psycopg2
    from psycopg2.extras import execute_values

    conn = psycopg2.connect(a.dsn)
    try:
        with conn, conn.cursor() as cur:
            cur.execute("SELECT pg_advisory_xact_lock(hashtext('marketlens-external-verified-import'))")
            cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.external_verified_sources (
                sha256 text PRIMARY KEY, source text NOT NULL, method text NOT NULL,
                row_count integer NOT NULL, verified_from timestamptz NOT NULL, verified_to timestamptz NOT NULL,
                imported_at timestamptz NOT NULL DEFAULT now())""")
            cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.external_verified_characters (
                region text NOT NULL CHECK (region IN ('us','eu')),
                name text NOT NULL,
                ruleset text NOT NULL, faction text NOT NULL, race text NOT NULL, class text NOT NULL,
                level integer NOT NULL, guild text, guild_id bigint,
                blizzard_character_id bigint NOT NULL,
                last_login_at timestamptz, realm_id_observed integer NOT NULL, forever_pulse_id uuid NOT NULL,
                verified_at timestamptz NOT NULL,
                source_sha256 text NOT NULL REFERENCES marketlens_import.external_verified_sources(sha256),
                PRIMARY KEY (region, name))""")
            # Tables created before guildless rows were allowed had these NOT NULL.
            cur.execute("""ALTER TABLE marketlens_import.external_verified_characters
                ALTER COLUMN guild DROP NOT NULL, ALTER COLUMN guild_id DROP NOT NULL""")
            # Blizzard ids repeat within a region, so this index is for lookups only (it was unique once).
            cur.execute("""SELECT indisunique FROM pg_index WHERE indexrelid =
                to_regclass('marketlens_import.external_verified_characters_blizzard_id')""")
            if (cur.fetchone() or (False,))[0]:
                cur.execute("DROP INDEX marketlens_import.external_verified_characters_blizzard_id")
            cur.execute("""CREATE INDEX IF NOT EXISTS external_verified_characters_blizzard_id
                ON marketlens_import.external_verified_characters (region, blizzard_character_id)""")
            cur.execute("SELECT row_count FROM marketlens_import.external_verified_sources WHERE sha256=%s", (digest,))
            if cur.fetchone():
                print(f"ALREADY IMPORTED sha256={digest}")
                return
            cur.execute("SELECT region, lower(name), blizzard_character_id FROM marketlens_import.external_verified_characters")
            existing = {(r, n): cid for r, n, cid in cur.fetchall()}
            reused = [r for r in rows if existing.get((r[0], r[1].casefold())) not in (None, r[9])]
            if reused and not a.replace_reused_names:
                raise RuntimeError(f"{len(reused)} names now belong to a different Blizzard character, e.g. {reused[0][:2]}; "
                                   "rerun with --replace-reused-names to take the newer character")
            if reused:
                execute_values(cur, """DELETE FROM marketlens_import.external_verified_characters c USING (VALUES %s) v(region,name)
                    WHERE c.region=v.region AND lower(c.name)=lower(v.name)""", [(r[0], r[1]) for r in reused])
            cur.execute("""INSERT INTO marketlens_import.external_verified_sources
                (sha256,source,method,row_count,verified_from,verified_to) VALUES (%s,%s,%s,%s,%s,%s)""",
                (digest, "forever-pulse.com character directory",
                 "blizzard profile-forever name+race+class+faction match; ruleset = observed realm type",
                 len(rows), min(verified), max(verified)))
            execute_values(cur, """INSERT INTO marketlens_import.external_verified_characters
                (region,name,ruleset,faction,race,class,level,guild,guild_id,blizzard_character_id,
                 last_login_at,realm_id_observed,forever_pulse_id,verified_at,source_sha256) VALUES %s
                ON CONFLICT (region,name) DO UPDATE SET
                  ruleset=excluded.ruleset, faction=excluded.faction, race=excluded.race, class=excluded.class,
                  level=excluded.level, guild=excluded.guild, guild_id=excluded.guild_id,
                  last_login_at=excluded.last_login_at, realm_id_observed=excluded.realm_id_observed,
                  forever_pulse_id=excluded.forever_pulse_id, verified_at=excluded.verified_at,
                  source_sha256=excluded.source_sha256
                WHERE excluded.verified_at > marketlens_import.external_verified_characters.verified_at""",
                [r + (digest,) for r in rows], page_size=1000)
            cur.execute("""INSERT INTO marketlens_import.local_source_files
                (sha256,relative_path,source_modified_utc,bytes,content) VALUES (%s,%s,%s,%s,NULL)
                ON CONFLICT (sha256) DO NOTHING""",
                (digest, f"external/foreverpulse-verified-{digest[:12]}/{a.source.name}",
                 datetime.fromtimestamp(a.source.stat().st_mtime, timezone.utc).isoformat(), len(raw)))
        print(f"IMPORTED sha256={digest} rows={len(rows)} archive={archived}")
    finally:
        conn.close()


if __name__ == "__main__":
    main()
