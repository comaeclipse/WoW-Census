#!/usr/bin/env python3
"""Fill tools/item-icons/forever-media.json with Blizzard icon ids for items the Forever Armory shows.

The character pages use tools/item-icons/forever.json and classic.json (Wowhead icon names) first.
Items missing from both are looked up in Blizzard's item media API; the stored value is the icon's
file id, drawn from https://render.worldofwarcraft.com/forever-eu/icons/56/<id>.jpg.

    python tools/fetch-forever-item-media.py [--rate 5]

Requires BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET. Resumable: known ids are skipped.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import re
from pathlib import Path

import psycopg2

ROOT = Path(__file__).resolve().parent
OUT = ROOT / "item-icons" / "forever-media.json"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--rate", type=float, default=5.0)
    args = parser.parse_args()
    spec = importlib.util.spec_from_file_location("armory", ROOT / "sync-forever-armory.py")
    armory = importlib.util.module_from_spec(spec); spec.loader.exec_module(armory)

    known = {}
    for name in ("forever.json", "classic.json"):
        path = ROOT / "item-icons" / name
        if path.exists():
            known.update(json.loads(path.read_text(encoding="utf-8")))
    media = json.loads(OUT.read_text(encoding="utf-8")) if OUT.exists() else {}

    with psycopg2.connect(armory.DEFAULT_DSN) as conn, conn.cursor() as cur:
        cur.execute("""SELECT DISTINCT (i->'item'->>'id')::int FROM marketlens_import.forever_armory_snapshots s,
          jsonb_array_elements(s.equipment_json->'equipped_items') i WHERE i->'item'->>'id' IS NOT NULL""")
        ids = sorted(r[0] for r in cur.fetchall())
    todo = [i for i in ids if str(i) not in known and str(i) not in media]
    print(f"{len(ids)} items worn, {len(todo)} without an icon", flush=True)

    api = armory.Blizzard(args.rate)
    found = 0
    for n, item_id in enumerate(todo, 1):
        status, body, _ = api.get(f"https://eu.api.blizzard.com/data/wow/media/item/{item_id}"
                                  "?namespace=static-forever-eu&locale=en_US")
        url = next((a.get("value") for a in (body or {}).get("assets", []) if a.get("key") == "icon"), None)
        match = re.search(r"/icons/\d+/(\d+)\.jpg$", url or "")
        if match:
            media[str(item_id)] = match.group(1); found += 1
        if n % 100 == 0 or n == len(todo):
            OUT.write_text(json.dumps(dict(sorted(media.items(), key=lambda kv: int(kv[0]))), indent=1) + "\n", encoding="utf-8")
            print(f"{n}/{len(todo)} looked up, {found} found", flush=True)


if __name__ == "__main__":
    main()
