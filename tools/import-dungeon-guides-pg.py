#!/usr/bin/env python3
"""Load the Forever Journal dungeon overviews, artwork and boss portraits into PostgreSQL for the dungeon pages.

    python -B tools/import-dungeon-guides-pg.py [--export DIR] [--dry-run]
    python -B tools/import-live.py dungeon-guides      # local, then live

Input: an export folder (default dungeon-journal-export/) with dungeons.json (one entry per
dungeon: name, level_range, source_url on foreverjournal.pro, overview paragraphs and the saved
artwork files) plus cards/ and detail-artwork/. The dungeon is matched to forever_dungeons by the
last part of its source URL, which is the site's slug. boss-icons/boss-icons.json lists each dungeon's
boss portraits (dungeon name, boss name, saved file, image URL); a boss is matched by name within its
dungeon.

Artwork is written as WebP into laragon-php/public/wow2008/dungeons/<slug>-card.webp and
<slug>-detail.webp (cards at most 640 px wide), and boss portraits into .../wow2008/portraits/<image name>.webp
(128 px), so they ship with the next code release.

Tables (schema marketlens_import, created on first run, replaced as a whole in one transaction;
a run whose rows match what is stored writes nothing):
  forever_dungeon_guides            one row per dungeon: source_url, card_art, detail_art (paths under /wow2008/)
  forever_dungeon_guide_paragraphs  the overview, one row per paragraph
  forever_dungeon_boss_portraits    one row per boss with a portrait: dungeon_slug, boss_name, portrait (path under /wow2008/)
"""

import argparse
import json
import os
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
DEFAULT_DSN = os.environ.get("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")
S = "marketlens_import."
EXPORT = REPO / "dungeon-journal-export"
ART_DIR = REPO / "laragon-php" / "public" / "wow2008" / "dungeons"
PORTRAIT_DIR = REPO / "laragon-php" / "public" / "wow2008" / "portraits"
PORTRAIT_SIZE = 128
CARD_WIDTH = 640

SCHEMA = [
    f"""CREATE TABLE IF NOT EXISTS {S}forever_dungeon_guides (
        dungeon_slug text PRIMARY KEY, source_url text NOT NULL, card_art text, detail_art text)""",
    f"""CREATE TABLE IF NOT EXISTS {S}forever_dungeon_guide_paragraphs (
        dungeon_slug text NOT NULL, para_order integer NOT NULL, body text NOT NULL,
        PRIMARY KEY (dungeon_slug, para_order))""",
    f"""CREATE TABLE IF NOT EXISTS {S}forever_dungeon_boss_portraits (
        dungeon_slug text NOT NULL, boss_name text NOT NULL, portrait text NOT NULL,
        PRIMARY KEY (dungeon_slug, boss_name))""",
]


def write_art(src, dest, max_width, alpha=False):
    """Save src as WebP at dest (shrunk to max_width); return the path under /wow2008/."""
    from PIL import Image
    with Image.open(src) as im:
        im = im.convert("RGBA" if alpha else "RGB")
        if max_width and im.width > max_width:
            im = im.resize((max_width, round(im.height * max_width / im.width)), Image.LANCZOS)
        dest.parent.mkdir(parents=True, exist_ok=True)
        tmp = dest.with_suffix(".tmp")
        im.save(tmp, "WEBP", quality=82, method=6)
    if dest.exists() and dest.read_bytes() == tmp.read_bytes():
        tmp.unlink()
    else:
        os.replace(tmp, dest)
    return dest.parent.name + "/" + dest.name


def read_export(folder, slugs):
    guides, paragraphs, skipped = [], [], []
    for d in json.loads((folder / "dungeons.json").read_text(encoding="utf-8")):
        slug = d["source_url"].rstrip("/").rsplit("/", 1)[-1]
        if slug not in slugs:
            skipped.append(f"{d['name']} ({slug})")
            continue
        art = d.get("artwork") or {}
        card = art.get("cards", {}).get("file")
        detail = art.get("detail-artwork", {}).get("file")
        guides.append((slug, d["source_url"],
                       write_art(folder / card, ART_DIR / f"{slug}-card.webp", CARD_WIDTH) if card else None,
                       write_art(folder / detail, ART_DIR / f"{slug}-detail.webp", None) if detail else None))
        for i, p in enumerate(x.strip() for x in d.get("overview") or []):
            if p:
                paragraphs.append((slug, i, p))
    return sorted(guides), sorted(paragraphs), skipped


def read_portraits(folder, bosses):
    """[(dungeon_slug, boss_name, portrait)] for every journal boss with a saved portrait; plus unmatched names."""
    index = folder / "boss-icons" / "boss-icons.json"
    if not index.exists():
        return [], []
    rows, unmatched = [], []
    for d in json.loads(index.read_text(encoding="utf-8")):
        for b in d["bosses"]:
            key = (d["dungeon"], b["name"])
            if not b.get("file") or not b.get("image_url"):
                continue
            if key not in bosses:
                unmatched.append(f"{b['name']} ({d['dungeon']})")
                continue
            stem = b["image_url"].rstrip("/").rsplit("/", 1)[-1].rsplit(".", 1)[0]
            rows.append((bosses[key], b["name"],
                         write_art(folder / "boss-icons" / b["file"], PORTRAIT_DIR / f"{stem}.webp", PORTRAIT_SIZE, alpha=True)))
    return sorted(set(rows)), unmatched


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--export", type=Path, default=EXPORT)
    ap.add_argument("--dsn", default=DEFAULT_DSN)
    ap.add_argument("--archive", help="ignored (accepted so tools/import-live.py can run this)")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    import psycopg2
    from psycopg2.extras import execute_values
    conn = psycopg2.connect(args.dsn)
    try:
        with conn, conn.cursor() as cur:
            cur.execute(f"SELECT slug FROM {S}forever_dungeons")
            slugs = {r[0] for r in cur.fetchall()}
            if not slugs:
                raise SystemExit("forever_dungeons is empty: run build-forever-dungeons.py first")
            guides, paragraphs, skipped = read_export(args.export, slugs)
            cur.execute(f"SELECT d.name, b.name, d.slug FROM {S}forever_dungeon_bosses b JOIN {S}forever_dungeons d ON d.slug = b.dungeon_slug")
            portraits, unmatched = read_portraits(args.export, {(r[0], r[1]): r[2] for r in cur.fetchall()})
            for ddl in SCHEMA:
                cur.execute(ddl)
            cur.execute(f"SELECT dungeon_slug, source_url, card_art, detail_art FROM {S}forever_dungeon_guides ORDER BY 1")
            old_g = cur.fetchall()
            cur.execute(f"SELECT dungeon_slug, para_order, body FROM {S}forever_dungeon_guide_paragraphs ORDER BY 1, 2")
            old_p = cur.fetchall()
            cur.execute(f"SELECT dungeon_slug, boss_name, portrait FROM {S}forever_dungeon_boss_portraits ORDER BY 1, 2, 3")
            old_b = cur.fetchall()
            changed = old_g != guides or old_p != paragraphs or old_b != portraits
            if changed and not args.dry_run:
                cur.execute(f"DELETE FROM {S}forever_dungeon_guide_paragraphs")
                cur.execute(f"DELETE FROM {S}forever_dungeon_guides")
                execute_values(cur, f"INSERT INTO {S}forever_dungeon_guides VALUES %s", guides)
                execute_values(cur, f"INSERT INTO {S}forever_dungeon_guide_paragraphs VALUES %s", paragraphs)
                cur.execute(f"DELETE FROM {S}forever_dungeon_boss_portraits")
                if portraits:
                    execute_values(cur, f"INSERT INTO {S}forever_dungeon_boss_portraits VALUES %s", portraits)
    finally:
        conn.close()
    print(f"guides={len(guides)} paragraphs={len(paragraphs)} cards={sum(1 for g in guides if g[2])} "
          f"details={sum(1 for g in guides if g[3])} portraits={len(portraits)} dryRun={args.dry_run}")
    for s in skipped:
        print(f"skipped (no such dungeon): {s}")
    for s in unmatched:
        print(f"skipped portrait (no such boss): {s}")
    if args.dry_run:
        print("Would change" if changed else "Nothing new")
    else:
        print("CHANGED sources=dungeon-guides" if changed else "Nothing new")


if __name__ == "__main__":
    sys.exit(main())
