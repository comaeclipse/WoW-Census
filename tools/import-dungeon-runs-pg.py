"""Import the boss kills and drops in players' Dungeon Journal run logs into PostgreSQL.

    python -B tools/import-dungeon-runs-pg.py [UPLOADS DIR] [--dry-run]
    python -B tools/import-live.py dungeon-runs            # local, then live

The Dungeon Journal add-on keeps a log of each dungeon run (characterData.runLog.runs): every kill,
and on a boss kill (its "b", the journal's boss name) the items that boss dropped. Companion uploads
of the add-on (pull-companion-uploads.py pull-addons) carry that log, so the dungeon pages can show
what players actually saw drop next to the add-on's loot list.

Every upload of an install repeats its runs, so a kill is keyed by a hash of the install, the
character, the run's start and the kill's time and boss. Character names go into the hash only;
nothing in the tables names a player. A rerun adds only kills not seen before.

Each run also gets a summary row (dungeon_runs): start, last boss kill, whether the log closed it as
completed, the uploading character's deaths and releases (corpse runs), boss and trash kills, coin
looted. Several players of one group can upload the same run, so runs of a dungeon whose kills of
the same boss lie within DUPLICATE_SECONDS of each other share a group_key; the site counts one run
per group.

Tables (schema marketlens_import, created on first run):
  dungeon_runs       one row per run: dungeon, map_id, started_at, final_kill, last_seen, completed,
                     deaths, releases, boss_kills, trash_kills, money, group_key
  dungeon_run_kills  one row per boss kill: dungeon, boss (journal name), npc_name, killed_at, map_id, run_key
  dungeon_run_loot   the items seen on that kill: item_id, item_name, quality, quantity
"""

import argparse
import hashlib
import importlib.util
import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
SOURCE_GAME = "forever"


def _load(name, file):
    spec = importlib.util.spec_from_file_location(name, REPO / "tools" / file)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


pg = _load("import_pg", "import-savedvariables-pg.py")
dungeons = _load("build_dungeons", "build-forever-dungeons.py")
combatlog = _load("import_combatlog", "import-combatlog-pg.py")
DUPLICATE_SECONDS = 30

LINK = re.compile(r"\|c(?:nIQ(\d)|ff[0-9a-fA-F]{6}).*?\|h\[(.*?)\]\|h")


def schema(cur):
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.dungeon_run_kills (
        kill_key text PRIMARY KEY, source_game text NOT NULL, dungeon text NOT NULL, boss text NOT NULL,
        npc_name text, killed_at bigint NOT NULL, map_id integer)""")
    cur.execute("""CREATE INDEX IF NOT EXISTS dungeon_run_kills_boss
        ON marketlens_import.dungeon_run_kills (source_game, dungeon, boss)""")
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.dungeon_run_loot (
        kill_key text NOT NULL REFERENCES marketlens_import.dungeon_run_kills(kill_key) ON DELETE CASCADE,
        source_game text NOT NULL, item_id integer NOT NULL, item_name text, quality smallint,
        quantity integer NOT NULL, PRIMARY KEY (kill_key, item_id))""")
    cur.execute("""CREATE INDEX IF NOT EXISTS dungeon_run_loot_item
        ON marketlens_import.dungeon_run_loot (source_game, item_id)""")
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.dungeon_runs (
        run_key text PRIMARY KEY, source_game text NOT NULL, dungeon text NOT NULL, map_id integer,
        started_at bigint NOT NULL, final_kill bigint, last_seen bigint, completed boolean NOT NULL,
        deaths integer NOT NULL, releases integer NOT NULL, boss_kills integer NOT NULL, trash_kills integer NOT NULL,
        money integer, group_key text NOT NULL)""")
    cur.execute("""CREATE INDEX IF NOT EXISTS dungeon_runs_dungeon ON marketlens_import.dungeon_runs (source_game, dungeon)""")
    cur.execute("ALTER TABLE marketlens_import.dungeon_run_kills ADD COLUMN IF NOT EXISTS run_key text")


def to_int(v):
    return v if isinstance(v, int) and not isinstance(v, bool) else None


def as_list(v):
    if isinstance(v, list):
        return v
    if isinstance(v, dict):
        return [v[k] for k in sorted(v, key=lambda k: (not str(k).isdigit(), int(k) if str(k).isdigit() else 0, str(k)))]
    return []


def run_summary(run):
    """The summary of one run log entry, or None when it has no usable start."""
    start = to_int(run.get("created")) or to_int(run.get("firstKill"))
    if start is None:
        return None
    kills = [k for k in as_list(run.get("kills")) if isinstance(k, dict) and to_int(k.get("t")) is not None]
    bosses = [k for k in kills if isinstance(k.get("b"), str)]
    trash = [k for k in kills if not isinstance(k.get("b"), str) and isinstance(k.get("m"), str)]
    exits = [e for e in as_list(run.get("exits")) if isinstance(e, dict) and e.get("why") == "released"]
    return {"dungeon": run["dungeon"], "map": to_int(run.get("map")), "start": start,
            "final": to_int(run.get("finalKill")) or (max(k["t"] for k in bosses) if bosses else None),
            "last": to_int(run.get("lastSeen")), "completed": run.get("completed") is True,
            "deaths": to_int(run.get("deaths")) or 0, "releases": len(exits), "bosses": len(bosses),
            "trash": len(trash), "money": to_int(run.get("money")),
            "boss_times": {(k["b"]): k["t"] for k in bosses}}


def group_runs(runs):
    """{run_key: group_key}: runs of one dungeon that killed a boss within DUPLICATE_SECONDS of each other
    are one group run seen by several uploaders."""
    parent = {k: k for k in runs}

    def find(k):
        while parent[k] != k:
            parent[k] = parent[parent[k]]
            k = parent[k]
        return k
    keys = sorted(runs)
    for i, a in enumerate(keys):
        for b in keys[i + 1:]:
            ra, rb = runs[a], runs[b]
            if ra["dungeon"] != rb["dungeon"]:
                continue
            if any(abs(t - rb["boss_times"][boss]) <= DUPLICATE_SECONDS
                   for boss, t in ra["boss_times"].items() if boss in rb["boss_times"]):
                parent[find(b)] = find(a)
    return {k: find(k) for k in runs}


def kills_from(uploads, runs_out=None):
    """{kill_key: (dungeon, boss, npc_name, t, map_id, {item_id: [name, quality, qty]}, run_key)} across every
    upload; runs_out, when given, collects {run_key: run_summary()} (the longest-lived copy of each run)."""
    out = {}
    for manifest in sorted(uploads.glob("addons-*/forever/dungeonjournal/*.json")):
        try:
            m = json.loads(manifest.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            continue
        lua = manifest.with_suffix(".lua")
        if not lua.exists():
            continue
        install = m.get("accountId") or m.get("sourceId") or ""
        for target, value in dungeons.assignments(lua):
            if target != "DungeonJournal" or not isinstance(value, dict) or "character" not in value:
                continue
            who = f"{value.get('character')}|{value.get('realm')}"
            log = (value.get("characterData") or {}).get("runLog") or {}
            for run in as_list(log.get("runs") if isinstance(log, dict) else None):
                if not isinstance(run, dict) or not isinstance(run.get("dungeon"), str):
                    continue
                run_key = hashlib.sha256("\x1f".join(map(str, (install, who, run.get("created")))).encode()).hexdigest()
                if runs_out is not None:
                    summary = run_summary(run)
                    prev_run = runs_out.get(run_key)
                    if summary and (prev_run is None or (summary["last"] or 0, summary["bosses"] + summary["trash"]) >=
                                    (prev_run["last"] or 0, prev_run["bosses"] + prev_run["trash"])):
                        runs_out[run_key] = summary
                for kill in as_list(run.get("kills")):
                    if not isinstance(kill, dict) or not isinstance(kill.get("b"), str) or to_int(kill.get("t")) is None:
                        continue
                    key = hashlib.sha256("\x1f".join(map(str, (install, who, run.get("created"), kill["t"], kill["b"]))).encode()).hexdigest()
                    items = {}
                    for it in as_list(kill.get("loot")):
                        if not isinstance(it, dict) or to_int(it.get("id")) is None or it["id"] <= 0:
                            continue
                        lm = LINK.search(it.get("link") or "")
                        row = items.setdefault(it["id"], [lm.group(2) if lm else None, int(lm.group(1)) if lm and lm.group(1) else None, 0])
                        row[2] += max(1, to_int(it.get("count")) or 1)
                    prev = out.get(key)
                    if prev is None or sum(x[2] for x in items.values()) > sum(x[2] for x in prev[5].values()):
                        out[key] = (run["dungeon"], kill["b"], kill.get("m") if isinstance(kill.get("m"), str) else None,
                                    kill["t"], to_int(run.get("map")), items, run_key)
    return out


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("uploads", nargs="?", type=Path, default=dungeons.UPLOADS)
    ap.add_argument("--dsn", default=pg.DEFAULT_DSN)
    ap.add_argument("--archive", help="ignored (accepted so tools/import-live.py can run this)")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    runs = {}
    kills = kills_from(args.uploads, runs)
    groups = group_runs(runs)
    conn = pg.psycopg2.connect(args.dsn)
    conn.autocommit = False
    cur = conn.cursor()
    cur.execute("SELECT pg_advisory_lock(hashtext('marketlens-import'))")
    conn.commit()
    schema(cur)
    new_kills = new_items = new_runs = changed_runs = 0
    for run_key, r in sorted(runs.items(), key=lambda kv: kv[1]["start"]):
        row = (run_key, SOURCE_GAME, r["dungeon"], r["map"], r["start"], r["final"], r["last"], r["completed"], r["deaths"],
               r["releases"], r["bosses"], r["trash"], r["money"], groups[run_key])
        cur.execute("""INSERT INTO marketlens_import.dungeon_runs (run_key, source_game, dungeon, map_id, started_at, final_kill,
            last_seen, completed, deaths, releases, boss_kills, trash_kills, money, group_key)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            ON CONFLICT (run_key) DO UPDATE SET map_id = EXCLUDED.map_id, final_kill = EXCLUDED.final_kill,
              last_seen = EXCLUDED.last_seen, completed = EXCLUDED.completed, deaths = EXCLUDED.deaths,
              releases = EXCLUDED.releases, boss_kills = EXCLUDED.boss_kills, trash_kills = EXCLUDED.trash_kills,
              money = EXCLUDED.money, group_key = EXCLUDED.group_key
            WHERE (dungeon_runs.map_id, dungeon_runs.final_kill, dungeon_runs.last_seen, dungeon_runs.completed,
              dungeon_runs.deaths, dungeon_runs.releases, dungeon_runs.boss_kills, dungeon_runs.trash_kills,
              dungeon_runs.money, dungeon_runs.group_key) IS DISTINCT FROM
              (EXCLUDED.map_id, EXCLUDED.final_kill, EXCLUDED.last_seen, EXCLUDED.completed, EXCLUDED.deaths,
              EXCLUDED.releases, EXCLUDED.boss_kills, EXCLUDED.trash_kills, EXCLUDED.money, EXCLUDED.group_key)
            RETURNING (xmax = 0)""", row)
        hit = cur.fetchone()
        if hit is not None:
            new_runs += 1 if hit[0] else 0
            changed_runs += 0 if hit[0] else 1
    for key, (dungeon, boss, npc, t, map_id, items, run_key) in sorted(kills.items(), key=lambda kv: kv[1][3]):
        cur.execute("""INSERT INTO marketlens_import.dungeon_run_kills (kill_key, source_game, dungeon, boss, npc_name, killed_at, map_id, run_key)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s) ON CONFLICT (kill_key) DO UPDATE SET run_key = EXCLUDED.run_key
            WHERE dungeon_run_kills.run_key IS DISTINCT FROM EXCLUDED.run_key RETURNING (xmax = 0)""",
                    (key, SOURCE_GAME, dungeon, boss, npc, t, map_id, run_key))
        hit = cur.fetchone()
        new_kills += 1 if hit is not None and hit[0] else 0
        for item_id, (name, quality, qty) in items.items():
            cur.execute("""INSERT INTO marketlens_import.dungeon_run_loot (kill_key, source_game, item_id, item_name, quality, quantity)
                VALUES (%s,%s,%s,%s,%s,%s)
                ON CONFLICT (kill_key, item_id) DO UPDATE SET quantity = greatest(dungeon_run_loot.quantity, EXCLUDED.quantity),
                  item_name = coalesce(EXCLUDED.item_name, dungeon_run_loot.item_name), quality = coalesce(EXCLUDED.quality, dungeon_run_loot.quality)
                WHERE dungeon_run_loot.quantity < EXCLUDED.quantity OR dungeon_run_loot.item_name IS NULL""",
                        (key, SOURCE_GAME, item_id, name, quality, qty))
            new_items += cur.rowcount
    cur.execute("SELECT count(*), count(DISTINCT (dungeon, boss)) FROM marketlens_import.dungeon_run_kills")
    total, bosses = cur.fetchone()
    cur.execute("SELECT count(*) FROM marketlens_import.dungeon_run_loot")
    drops = cur.fetchone()[0]
    cur.execute("SELECT count(*), count(DISTINCT group_key) FROM marketlens_import.dungeon_runs")
    runs_total, groups_total = cur.fetchone()
    report = {"killsInUploads": len(kills), "newKills": new_kills, "newOrGrownDrops": new_items,
              "killsTotal": total, "bossesTotal": bosses, "dropsTotal": drops,
              "runsInUploads": len(runs), "newRuns": new_runs, "changedRuns": changed_runs,
              "runsTotal": runs_total, "groupRunsTotal": groups_total,
              "dungeonPlaceRows": combatlog.resolve_instance_places(cur), "dryRun": args.dry_run}
    print(json.dumps(report, indent=1))
    if args.dry_run:
        conn.rollback()
        print("DRY RUN: rolled back")
        return
    conn.commit()
    print("CHANGED sources=dungeon-runs" if new_kills or new_items or new_runs or changed_runs else "Nothing new")


if __name__ == "__main__":
    sys.exit(main())
