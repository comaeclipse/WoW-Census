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

Tables (schema marketlens_import, created on first run):
  dungeon_run_kills  one row per boss kill: dungeon, boss (journal name), npc_name, killed_at, map_id
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


def to_int(v):
    return v if isinstance(v, int) and not isinstance(v, bool) else None


def as_list(v):
    if isinstance(v, list):
        return v
    if isinstance(v, dict):
        return [v[k] for k in sorted(v, key=lambda k: (not str(k).isdigit(), int(k) if str(k).isdigit() else 0, str(k)))]
    return []


def kills_from(uploads):
    """{kill_key: (dungeon, boss, npc_name, t, map_id, {item_id: [name, quality, qty]})} across every upload."""
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
                                    kill["t"], to_int(run.get("map")), items)
    return out


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("uploads", nargs="?", type=Path, default=dungeons.UPLOADS)
    ap.add_argument("--dsn", default=pg.DEFAULT_DSN)
    ap.add_argument("--archive", help="ignored (accepted so tools/import-live.py can run this)")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    kills = kills_from(args.uploads)
    conn = pg.psycopg2.connect(args.dsn)
    conn.autocommit = False
    cur = conn.cursor()
    cur.execute("SELECT pg_advisory_lock(hashtext('marketlens-import'))")
    conn.commit()
    schema(cur)
    new_kills = new_items = 0
    for key, (dungeon, boss, npc, t, map_id, items) in sorted(kills.items(), key=lambda kv: kv[1][3]):
        cur.execute("""INSERT INTO marketlens_import.dungeon_run_kills (kill_key, source_game, dungeon, boss, npc_name, killed_at, map_id)
            VALUES (%s,%s,%s,%s,%s,%s,%s) ON CONFLICT (kill_key) DO NOTHING""", (key, SOURCE_GAME, dungeon, boss, npc, t, map_id))
        new_kills += cur.rowcount
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
    report = {"killsInUploads": len(kills), "newKills": new_kills, "newOrGrownDrops": new_items,
              "killsTotal": total, "bossesTotal": bosses, "dropsTotal": cur.fetchone()[0], "dryRun": args.dry_run}
    print(json.dumps(report, indent=1))
    if args.dry_run:
        conn.rollback()
        print("DRY RUN: rolled back")
        return
    conn.commit()
    print("CHANGED sources=dungeon-runs" if new_kills or new_items else "Nothing new")


if __name__ == "__main__":
    sys.exit(main())
