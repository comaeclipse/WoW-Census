"""Fill marketlens_import.combatlog_spell_icons for combat-log spells the site has no icon for.

    python -B tools/update-spell-icons.py [--dsn DSN] [--game forever] [--recheck]

Spell pages, the bestiary and class combat tables draw each spell's icon from this table. Icons come
from Wowhead's tooltip API for the edition's branch; a spell Wowhead does not know is stored with a
NULL icon so it is not asked again (--recheck asks again). import-combatlog-pg.py runs this after
each import. New icons are mirrored into assets/wow.zamimg.com like item icons.
"""
import argparse
import importlib.util
import json
import re
import sys
import time
import urllib.request
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
BRANCH = {"forever": "forever", "classic-beta": "forever", "classic": "classic", "classic-progression": "tbc",
          "mop-classic": "mop-classic", "retail": ""}
HEADERS = {"User-Agent": "wowcensus-icons/1.0"}


def _pg():
    spec = importlib.util.spec_from_file_location("import_pg", REPO / "tools" / "import-savedvariables-pg.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def fetch(url):
    with urllib.request.urlopen(urllib.request.Request(url, headers=HEADERS), timeout=20) as res:
        return res.read()


def icon_for(spell_id, branch):
    try:
        icon = json.loads(fetch(f"https://nether.wowhead.com/{branch + '/' if branch else ''}tooltip/spell/{spell_id}")).get("icon")
    except Exception as e:
        print(f"spell {spell_id}: {e}", file=sys.stderr)
        return None, False
    return (icon.lower(), True) if isinstance(icon, str) and re.fullmatch(r"[a-z0-9_-]+", icon, re.I) else (None, True)


def mirror(icon):
    n = 0
    for size in ("small", "large"):
        out = REPO / "assets" / "wow.zamimg.com" / "images" / "wow" / "icons" / size / f"{icon}.jpg"
        if out.exists():
            continue
        try:
            data = fetch(f"https://wow.zamimg.com/images/wow/icons/{size}/{icon}.jpg")
        except Exception as e:
            print(f"icon {size}/{icon}: {e}", file=sys.stderr)
            continue
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_bytes(data)
        n += 1
    return n


def update(cur, game, recheck=False):
    """Look up missing icons for one game; returns (added, unknown)."""
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.combatlog_spell_icons (
        source_game text NOT NULL, spell_id integer NOT NULL, icon text, checked_at timestamptz NOT NULL DEFAULT now(),
        PRIMARY KEY (source_game, spell_id))""")
    sources = ["SELECT spell_id FROM marketlens_import.combatlog_npc_abilities WHERE source_game = %(g)s"]
    cur.execute("SELECT to_regclass('marketlens_import.combatlog_class_spells') IS NOT NULL")
    if cur.fetchone()[0]:
        sources.append("SELECT spell_id FROM marketlens_import.combatlog_class_spells WHERE source_game = %(g)s")
    cur.execute(f"""SELECT DISTINCT s.spell_id FROM ({' UNION '.join(sources)}) s
        LEFT JOIN marketlens_import.combatlog_spell_icons i ON i.source_game = %(g)s AND i.spell_id = s.spell_id
        WHERE s.spell_id > 0 AND (i.spell_id IS NULL OR (%(r)s AND i.icon IS NULL)) ORDER BY 1""", {"g": game, "r": recheck})
    added = unknown = 0
    for (spell_id,) in cur.fetchall():
        icon, answered = icon_for(spell_id, BRANCH.get(game, "classic"))
        if not answered:
            continue
        cur.execute("""INSERT INTO marketlens_import.combatlog_spell_icons (source_game, spell_id, icon) VALUES (%s,%s,%s)
            ON CONFLICT (source_game, spell_id) DO UPDATE SET icon = EXCLUDED.icon, checked_at = now()""", (game, spell_id, icon))
        if icon:
            added += 1
            mirror(icon)
        else:
            unknown += 1
        time.sleep(0.2)
    return added, unknown


def main():
    pg = _pg()
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--dsn", default=pg.DEFAULT_DSN)
    ap.add_argument("--game", default="forever", choices=sorted(BRANCH))
    ap.add_argument("--recheck", action="store_true", help="ask Wowhead again for spells stored without an icon")
    args = ap.parse_args()
    conn = pg.psycopg2.connect(args.dsn)
    with conn, conn.cursor() as cur:
        added, unknown = update(cur, args.game, args.recheck)
    print(f"ADDED icons={added} unknown={unknown}")


if __name__ == "__main__":
    main()
