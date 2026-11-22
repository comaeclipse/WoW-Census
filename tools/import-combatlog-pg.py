"""Import what WoW: Forever combat logs measured about mobs into PostgreSQL, for the bestiary pages.

    python -B tools/import-combatlog-pg.py [LOG ...] [--dry-run]

With no LOG, every WoWCombatLog*.txt in the Forever client's Logs folder is read. The log must be
written with Advanced Combat Logging on: that is where a unit's level, health and armor come from.

By default only creatures are kept. A combat log also records every player the client could see nearby
(their casts, buffs and fights); none of that is stored, and no player name or GUID reaches the
database. Every log also yields anonymous class/spell totals (combatlog_class_spells, see
tools/combatlog-player-stats.py: class is inferred from class-exclusive casts, which works on
companion uploads too); --class-stats rebuilds only those totals. Player names and GUIDs are never stored.

A log grows while the game runs, so each file is keyed by a hash of its first line plus its
file name; importing it again replaces that file's rows instead of adding to them.

Tables (schema marketlens_import, created on first run):
  combatlog_files          one row per log: first/last event time, events, mobs seen
  combatlog_npc_levels     per log, mob and level: sightings, health range, armor
  combatlog_npc_abilities  per log, mob and ability (spell id 0 = melee): casts, hits on players,
                           crits, misses, min/max/total damage
  combatlog_npc_places     per log, mob and map: sightings, map name
  combatlog_npc_names      per log and mob: name, deaths, first/last seen
"""

import argparse
import csv
import datetime as dt
import hashlib
import importlib.util
import json
import sys
from collections import defaultdict
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
SOURCE_GAME = "forever"
# Site game keys a log can belong to. Every client writes combat log version 22 with the same 19-field
# advanced block (checked on Forever 1.60.1 and Retail 12.1.0); each line's block must name one of the
# line's two units, so a client with a different layout yields nothing instead of wrong numbers.
GAMES = ("forever", "classic-beta", "retail", "classic-progression", "mop-classic", "classic")
LOGS = Path(r"C:\Program Files (x86)\World of Warcraft\_forever_\Logs")


def _load(name, file):
    spec = importlib.util.spec_from_file_location(name, REPO / "tools" / file)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


pg = _load("import_pg", "import-savedvariables-pg.py")

# Advanced info block: GUID, owner, curHP, maxHP, AP, SP, armor, 4 more, curPower, maxPower, cost,
# x, y, uiMapID, facing, level (19 fields). It starts after the spell id/name/school for SPELL_ and
# RANGE_ events and straight after the two units for SWING_ events; the suffix follows it.
ADV_LEN = 19
DAMAGE = {"SPELL_DAMAGE", "SPELL_PERIODIC_DAMAGE", "RANGE_DAMAGE", "SWING_DAMAGE", "DAMAGE_SHIELD", "SPELL_BUILDING_DAMAGE"}
MISSED = {"SPELL_MISSED", "SPELL_PERIODIC_MISSED", "RANGE_MISSED", "SWING_MISSED", "DAMAGE_SHIELD_MISSED"}
CASTS = {"SPELL_CAST_SUCCESS"}


def schema(cur):
    s = "marketlens_import"
    cur.execute(f"""CREATE TABLE IF NOT EXISTS {s}.combatlog_files (
        file_key text PRIMARY KEY, source_game text NOT NULL, first_event timestamptz, last_event timestamptz,
        events integer NOT NULL, npcs integer NOT NULL, imported_at timestamptz NOT NULL DEFAULT now())""")
    cur.execute(f"""CREATE TABLE IF NOT EXISTS {s}.combatlog_npc_names (
        file_key text NOT NULL REFERENCES {s}.combatlog_files(file_key) ON DELETE CASCADE,
        source_game text NOT NULL, npc_id integer NOT NULL, name text NOT NULL, deaths integer NOT NULL,
        first_seen timestamptz NOT NULL, last_seen timestamptz NOT NULL, PRIMARY KEY (file_key, npc_id))""")
    cur.execute(f"""CREATE TABLE IF NOT EXISTS {s}.combatlog_npc_levels (
        file_key text NOT NULL REFERENCES {s}.combatlog_files(file_key) ON DELETE CASCADE,
        source_game text NOT NULL, npc_id integer NOT NULL, level smallint NOT NULL, sightings integer NOT NULL,
        min_hp integer NOT NULL, max_hp integer NOT NULL, armor integer, PRIMARY KEY (file_key, npc_id, level))""")
    cur.execute(f"""CREATE TABLE IF NOT EXISTS {s}.combatlog_npc_abilities (
        file_key text NOT NULL REFERENCES {s}.combatlog_files(file_key) ON DELETE CASCADE,
        source_game text NOT NULL, npc_id integer NOT NULL, spell_id integer NOT NULL, ability text NOT NULL,
        school integer, casts integer NOT NULL, hits integer NOT NULL, crits integer NOT NULL, misses integer NOT NULL,
        min_dmg integer, max_dmg integer, total_dmg bigint NOT NULL, PRIMARY KEY (file_key, npc_id, spell_id))""")
    cur.execute(f"""CREATE TABLE IF NOT EXISTS {s}.combatlog_npc_places (
        file_key text NOT NULL REFERENCES {s}.combatlog_files(file_key) ON DELETE CASCADE,
        source_game text NOT NULL, npc_id integer NOT NULL, ui_map_id integer NOT NULL, map_name text,
        sightings integer NOT NULL, PRIMARY KEY (file_key, npc_id, ui_map_id))""")
    for t in ("names", "levels", "abilities", "places"):
        cur.execute(f"CREATE INDEX IF NOT EXISTS combatlog_npc_{t}_npc ON {s}.combatlog_npc_{t} (source_game, npc_id)")


CONTROL_PLAYER = 0x100


def npc_id(guid, flags="0x0"):
    """Creature-0-6823-0-24349-474-000181C935 -> 474; None for players, pets, totems, guardians and
    anything else a player controls (unit flag COMBATLOG_OBJECT_CONTROL_PLAYER)."""
    if not guid.startswith("Creature-"):
        return None
    try:
        if int(flags, 16) & CONTROL_PLAYER:
            return None
    except ValueError:
        pass
    parts = guid.split("-")
    return int(parts[5]) if len(parts) > 6 and parts[5].isdigit() else None


def to_int(v):
    try:
        return int(v)
    except (TypeError, ValueError):
        return None


def parse_time(stamp):
    """'11/21/2026 10:39:37.968-6' -> aware datetime."""
    date, clock = stamp.split(" ")
    cut = max(clock.rfind("-"), clock.rfind("+"))
    hours = 0.0
    if cut > 0:
        clock, hours = clock[:cut], float(clock[cut:])
    tz = dt.timezone(dt.timedelta(hours=hours))
    return dt.datetime.strptime(f"{date} {clock}", "%m/%d/%Y %H:%M:%S.%f").replace(tzinfo=tz)


def read_log(path):
    names = {}          # npc -> [name, deaths, first, last]
    levels = defaultdict(lambda: [0, None, None, None])   # (npc, level) -> [sightings, min_hp, max_hp, armor]
    abilities = defaultdict(lambda: [None, None, 0, 0, 0, 0, None, None, 0])  # (npc, spell) -> name, school, casts, hits, crits, misses, min, max, total
    places = defaultdict(int)  # (npc, map) -> sightings
    map_names = {}
    first = last = None
    events = 0
    header_ok = False
    with open(path, encoding="utf-8", errors="replace") as fh:
        for line in fh:
            stamp, sep, rest = line.rstrip("\n").partition("  ")
            if not sep:
                continue
            try:
                f = next(csv.reader([rest]))
            except (csv.Error, StopIteration):
                continue
            ev = f[0]
            if ev == "COMBAT_LOG_VERSION":
                header_ok = header_ok or (len(f) > 3 and f[2] == "ADVANCED_LOG_ENABLED" and f[3] == "1")
                continue
            if ev == "MAP_CHANGE" and len(f) > 2 and to_int(f[1]):
                map_names[int(f[1])] = f[2]
                continue
            if len(f) < 9:
                continue
            try:
                t = parse_time(stamp)
            except ValueError:
                continue
            events += 1
            first = first or t
            last = t
            src, dst = npc_id(f[1], f[3]), npc_id(f[5], f[7])
            for n, name in ((src, f[2]), (dst, f[6])):
                if n is not None and name and name != "nil":
                    rec = names.setdefault(n, [name, 0, t, t])
                    rec[0], rec[3] = name, t
            if ev == "UNIT_DIED" and dst is not None:
                names[dst][1] += 1
                continue

            swing = ev.startswith("SWING_")
            adv = 9 if swing else 12
            # A creature with an owner is a player's totem or guardian, not a mob.
            if (len(f) >= adv + ADV_LEN and f[adv].startswith("Creature-") and f[adv] in (f[1], f[5])
                    and f[adv + 1] == "0000000000000000"):
                who = npc_id(f[adv])
                if who is not None and who not in names:
                    who = None  # only units already seen as NPC-controlled
                level, cur_hp, max_hp, armor, ui_map = (to_int(f[adv + 18]), to_int(f[adv + 2]), to_int(f[adv + 3]),
                                                        to_int(f[adv + 6]), to_int(f[adv + 16]))
                if who is not None and level and 0 < level <= 100 and max_hp and max_hp > 0 and cur_hp is not None:
                    row = levels[(who, level)]
                    row[0] += 1
                    row[1] = max_hp if row[1] is None else min(row[1], max_hp)
                    row[2] = max_hp if row[2] is None else max(row[2], max_hp)
                    if armor is not None and armor >= 0:
                        row[3] = armor if row[3] is None else max(row[3], armor)
                    if ui_map:
                        places[(who, ui_map)] += 1

            if src is None:
                continue
            spell = 0 if swing else to_int(f[9])
            if spell is None:
                continue
            ability = "Melee" if swing else f[10]
            school = 1 if swing else to_int(f[11]) if not f[11].startswith("0x") else int(f[11], 16)
            if ev in CASTS:
                a = abilities[(src, spell)]
                a[0], a[1] = ability, school
                a[2] += 1
            elif ev in DAMAGE and f[5].startswith("Player-"):
                amt_at = adv + ADV_LEN
                amount = to_int(f[amt_at]) if len(f) > amt_at else None
                if amount is None:
                    continue
                absorbed = to_int(f[amt_at + 6]) if len(f) > amt_at + 6 else 0
                hit = amount + (absorbed or 0)
                a = abilities[(src, spell)]
                a[0], a[1] = ability, school
                a[3] += 1
                a[4] += 1 if len(f) > amt_at + 7 and f[amt_at + 7] == "1" else 0
                a[6] = hit if a[6] is None else min(a[6], hit)
                a[7] = hit if a[7] is None else max(a[7], hit)
                a[8] += hit
            elif ev in MISSED and f[5].startswith("Player-"):
                a = abilities[(src, spell)]
                a[0], a[1] = ability, school
                a[5] += 1
    return {"header_ok": header_ok, "names": names, "levels": levels, "abilities": abilities, "places": places,
            "map_names": map_names, "first": first, "last": last, "events": events}


def file_key(path):
    with open(path, "rb") as fh:
        head = fh.readline()
    return hashlib.sha256(head + b"\x1f" + path.name.encode()).hexdigest()


def store(cur, key, d):
    s = "marketlens_import"
    cur.execute(f"DELETE FROM {s}.combatlog_files WHERE file_key = %s", (key,))
    cur.execute(f"""INSERT INTO {s}.combatlog_files (file_key, source_game, first_event, last_event, events, npcs)
        VALUES (%s,%s,%s,%s,%s,%s)""", (key, SOURCE_GAME, d["first"], d["last"], d["events"], len(d["names"])))
    batch = pg.psycopg2.extras.execute_batch
    batch(cur, f"""INSERT INTO {s}.combatlog_npc_names (file_key, source_game, npc_id, name, deaths, first_seen, last_seen)
        VALUES (%s,%s,%s,%s,%s,%s,%s)""", [(key, SOURCE_GAME, n, *v) for n, v in d["names"].items()])
    batch(cur, f"""INSERT INTO {s}.combatlog_npc_levels (file_key, source_game, npc_id, level, sightings, min_hp, max_hp, armor)
        VALUES (%s,%s,%s,%s,%s,%s,%s,%s)""", [(key, SOURCE_GAME, n, lv, *v) for (n, lv), v in d["levels"].items()])
    batch(cur, f"""INSERT INTO {s}.combatlog_npc_abilities (file_key, source_game, npc_id, spell_id, ability, school, casts, hits,
        crits, misses, min_dmg, max_dmg, total_dmg) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
          [(key, SOURCE_GAME, n, sp, *v) for (n, sp), v in d["abilities"].items() if v[0]])
    batch(cur, f"""INSERT INTO {s}.combatlog_npc_places (file_key, source_game, npc_id, ui_map_id, map_name, sightings)
        VALUES (%s,%s,%s,%s,%s,%s)""", [(key, SOURCE_GAME, n, m, d["map_names"].get(m), c) for (n, m), c in d["places"].items()])


def main():
    global SOURCE_GAME
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("logs", nargs="*", type=Path)
    ap.add_argument("--dsn", default=pg.DEFAULT_DSN)
    ap.add_argument("--archive", help="ignored (accepted so tools/import-live.py can run this)")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--game", default=SOURCE_GAME, choices=GAMES,
                    help="site game key the logs belong to (default forever)")
    ap.add_argument("--class-stats", action="store_true", help="rebuild only the class/spell totals of the given logs")
    args = ap.parse_args()
    SOURCE_GAME = args.game
    if args.class_stats and not args.logs:
        ap.error("--class-stats requires explicit log paths")
    player_stats = _load("combatlog_player_stats", "combatlog-player-stats.py")
    class_only = args.class_stats
    logs = args.logs or sorted(LOGS.glob("WoWCombatLog*.txt"))

    conn = pg.psycopg2.connect(args.dsn)
    conn.autocommit = False
    cur = conn.cursor()
    cur.execute("SELECT pg_advisory_lock(hashtext(%s))",
                ("marketlens-combat-class" if class_only else "marketlens-import",))
    conn.commit()
    if not class_only:
        schema(cur)
    known = player_stats.known_spells(cur, SOURCE_GAME)
    report = {"files": [], "dryRun": args.dry_run}
    for path in logs:
        d = read_log(path)
        entry = {"file": path.name, "events": d["events"], "mobs": len(d["names"]),
                 "mobsWithLevels": len({n for n, _ in d["levels"]}), "advanced": d["header_ok"]}
        if not d["header_ok"]:
            entry["skipped"] = "Advanced Combat Logging was off"
        else:
            if not class_only and d["levels"]:
                store(cur, file_key(path), d)
            elif not class_only:
                entry["mobData"] = "none"
            totals = player_stats.aggregate(path, npc_id, to_int, ADV_LEN, known)
            player_stats.store(cur, file_key(path), SOURCE_GAME, totals)
            entry["classSpellRows"] = len(totals)
            entry["classEvents"] = {c: n for c, n in sorted(
                {c: sum(v[0] for k, v in totals.items() if k[0] == c) for c in {k[0] for k in totals}}.items())}
            if not d["levels"] and not totals:
                entry["skipped"] = "no mob or class data"
        report["files"].append(entry)
    if not class_only:
        cur.execute("SELECT count(DISTINCT npc_id) FROM marketlens_import.combatlog_npc_names")
        mobs = cur.fetchone()[0]
        cur.execute("SELECT count(*) FROM marketlens_import.combatlog_files")
        report.update({"logsTotal": cur.fetchone()[0], "mobsTotal": mobs})
    print(json.dumps(report, indent=1))
    if args.dry_run:
        conn.rollback()
        print("DRY RUN: rolled back")
        return
    conn.commit()
    print("CHANGED sources=combatlog" if any("skipped" not in f for f in report["files"]) else "Nothing new")


if __name__ == "__main__":
    sys.exit(main())
