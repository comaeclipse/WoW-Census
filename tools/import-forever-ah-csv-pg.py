#!/usr/bin/env python3
"""Import WoW: Forever auction-house summary CSVs into PostgreSQL.

    python -B tools/import-forever-ah-csv-pg.py FILE.csv [FILE.csv ...] [--map "us:ID=REALM-FACTION"] [--dry-run]

Each CSV is one snapshot of several auction houses, with one row per house,
item and listing type: snapshot_time_central, house_id, item_id, item_name,
listing_type, listings, total_quantity and min/median/mean unit buyout in gold.

Forever's auction houses are ruleset economies (one Alliance, Horde and Neutral
house per ruleset), and the API does not say which ruleset a house id serves.
A verified house (VERIFIED_HOUSES below, or one passed with --map after
checking it by matching rare listings against an in-game addon scan;
tools/map-forever-house-pg.py then publishes its earlier snapshots) is imported into that market's
existing key exactly like an addon scan: snapshots, daily history and current
items. Every other house is kept as raw snapshots under source_game
'forever-unmapped' and game 'house:<region>:<id>-<faction>'. No page reads
those, so nothing about an unmapped house is published until it is mapped.

A file whose SHA-256 is already in local_source_files is skipped. New files
are archived under MarketLensData/imports/<UTC time>/forever-ah-csv/. The
whole run is one transaction; --dry-run rolls it back after the report.
"""

import argparse
import csv
import importlib.util
import json
import re
import shutil
import sys
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

REPO = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("import_pg", REPO / "tools" / "import-savedvariables-pg.py")
pg = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pg)
legacy = pg.legacy

SOURCE = "forever"
UNMAPPED = "forever-unmapped"
CENTRAL = ZoneInfo("America/Chicago")
# House id groups seen in the Forever auction API: Alliance / Horde / Neutral per economy.
HOUSE_FACTION = {**{h: "Alliance" for h in (110, 111, 112, 119, 123)},
                 **{h: "Horde" for h in (113, 114, 115, 120, 124)},
                 **{h: "Neutral" for h in (116, 117, 118, 121, 125)}}
# Houses checked against an addon scan from that ruleset and faction (exact rare
# listings: 1-2 units, >= 1g). Mapped by default; --map adds or overrides.
#   us:111  2026-11-10: 42 exact matches vs CAMWOW US2 Realm 5 PvE-Alliance; others <= 3
#   us:114  2026-11-10: 294 of 502 vs CAMWOW US2 Realm 5 PvE-Horde (21:23 UTC); others <= 5
VERIFIED_HOUSES = {("us", 111): "CAMWOW US2 Realm 5 PvE-Alliance",
                   ("us", 114): "CAMWOW US2 Realm 5 PvE-Horde"}
COLUMNS = ["snapshot_time_central", "house_id", "item_id", "item_name", "listing_type", "listings",
           "total_quantity", "min_unit_buyout_gold", "median_unit_buyout_gold", "mean_unit_buyout_gold"]


def copper(gold):
    return int(round(float(gold) * 10000)) if gold not in ("", None) else 0


def read_csv(path, region):
    """Rows summed per house and item: {house: {item: [name, qty, listings, lowest, median, mean]}}, unix time."""
    with open(path, encoding="utf-8", newline="") as f:
        reader = csv.DictReader(f)
        if reader.fieldnames != COLUMNS:
            raise RuntimeError(f"{path.name}: unexpected columns {reader.fieldnames}")
        rows = list(reader)
    times = {r["snapshot_time_central"] for r in rows}
    if len(times) != 1:
        raise RuntimeError(f"{path.name}: expected one snapshot time, found {sorted(times)}")
    local = datetime.strptime(times.pop(), "%Y-%m-%d %H:%M").replace(tzinfo=CENTRAL)
    t = int(local.timestamp())
    parts = defaultdict(lambda: defaultdict(list))
    for r in rows:
        house, item = int(r["house_id"]), int(r["item_id"])
        if house not in HOUSE_FACTION:
            raise RuntimeError(f"{path.name}: house {house} has no known faction")
        qty = int(r["total_quantity"])
        if item <= 0 or qty <= 0:
            continue
        parts[house][item].append((r["item_name"].strip(), qty, int(r["listings"] or 0), copper(r["min_unit_buyout_gold"]),
                                   copper(r["median_unit_buyout_gold"]), copper(r["mean_unit_buyout_gold"])))
    houses = {}
    for house, items in parts.items():
        out = {}
        for item, rs in items.items():
            qty = sum(r[1] for r in rs)
            # Median across rows, weighted by quantity; mean weighted the same way.
            acc, median = 0, max(r[4] for r in rs)
            for r in sorted(rs, key=lambda r: r[4]):
                acc += r[1]
                if acc * 2 >= qty:
                    median = r[4]
                    break
            mean = round(sum(r[5] * r[1] for r in rs) / qty)
            name = next((r[0] for r in rs if r[0]), "")
            out[item] = [name, qty, sum(r[2] for r in rs), min(r[3] for r in rs), median, mean]
        houses[house] = out
    return houses, t, local


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("files", nargs="+", type=Path)
    parser.add_argument("--map", action="append", default=[], metavar="REGION:HOUSE=REALM-FACTION",
                        help="a checked house: its scans go into that market's existing key")
    parser.add_argument("--region", help="us or eu (default: taken from the file name)")
    parser.add_argument("--dsn", default=pg.DEFAULT_DSN)
    parser.add_argument("--schema", default="marketlens_import")
    parser.add_argument("--archive", type=Path, default=pg.DATA_ROOT / "imports")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    mapping = dict(VERIFIED_HOUSES)
    for m in args.map:
        match = re.fullmatch(r"(us|eu):(\d+)=(.+-(Alliance|Horde|Neutral))", m)
        if not match:
            raise SystemExit(f"Bad --map {m!r}; expected us:111=CAMWOW US2 Realm 5 PvE-Alliance")
        house = int(match.group(2))
        if HOUSE_FACTION.get(house) != match.group(4):
            raise SystemExit(f"--map {m!r}: house {house} is {HOUSE_FACTION.get(house)}, not {match.group(4)}")
        mapping[(match.group(1), house)] = match.group(3)

    conn = pg.psycopg2.connect(args.dsn)
    conn.autocommit = False
    db = pg.PgDb(conn)
    lock = conn.cursor()
    lock.execute("SELECT pg_advisory_lock(hashtext('marketlens-import'))")
    conn.commit()
    db.execute(f'SET search_path TO "{args.schema}"')
    imported = {row[0] for row in db.execute("SELECT sha256 FROM local_source_files")}
    for realm in set(mapping.values()):
        if not db.execute("SELECT 1 FROM datasets WHERE game=? AND source_game=?", ("realm:" + realm, SOURCE)).fetchone():
            raise SystemExit(f"--map target realm:{realm} is not an existing {SOURCE} market")

    # Category, source and crafter per item from its newest scan: the CSV has none and
    # import_market() would otherwise blank them on the current items.
    meta = {r[0]: r[1:] for r in db.execute("""SELECT DISTINCT ON (item_id) item_id, category, item_source, crafter
      FROM local_auction_snapshots WHERE source_game=? AND category IS NOT NULL
      ORDER BY item_id, captured_at DESC""", (SOURCE,))}
    names = dict(db.execute("SELECT DISTINCT ON (item_id) item_id, name FROM local_auction_snapshots WHERE source_game=? AND name NOT LIKE 'item:%' ORDER BY item_id, captured_at DESC", (SOURCE,)))

    run_dir = args.archive / datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S") / "forever-ah-csv"
    report = {"files": [], "dryRun": args.dry_run}
    loaded = []
    for path in args.files:
        data = path.read_bytes()
        digest = pg.sha256_bytes(data)
        entry = {"file": path.name, "sha256": digest, "bytes": len(data)}
        report["files"].append(entry)
        if digest in imported:
            entry["result"] = "already imported"
            continue
        region = args.region or (re.search(r"forever_(us|eu)_", path.name) or [None, None])[1]
        if region not in ("us", "eu"):
            raise SystemExit(f"{path.name}: region not in the file name; pass --region")
        houses, t, local = read_csv(path, region)
        entry.update(region=region, snapshotCentral=local.isoformat(), snapshotUtc=datetime.fromtimestamp(t, timezone.utc).isoformat(),
                     houses={})
        loaded.append((t, path, data, digest, region, houses, entry))

    for t, path, data, digest, region, houses, entry in sorted(loaded, key=lambda x: x[0]):
        for house, items in sorted(houses.items()):
            faction = HOUSE_FACTION[house]
            realm = mapping.get((region, house))
            summary = {"faction": faction, "items": len(items), "units": sum(v[1] for v in items.values())}
            if realm:
                body = {"realm": realm, "capabilities": {"sellers": False}, "items": {}}
                for item, (name, qty, listings, lo, med, mean) in items.items():
                    cat, src, crafter = meta.get(item, (None, None, None))
                    body["items"][str(item)] = {"n": name or names.get(item) or "", "m": cat, "src": src, "cr": crafter,
                                                "s": [[t, qty, listings, 0, lo, med, mean, 0]]}
                details = legacy.import_market(db, body, digest, SOURCE)
                bucket = f"csv:{region}:house-{house}"
                legacy.log_import(db, digest, "market", bucket, SOURCE, details)
                summary.update(mappedTo="realm:" + realm, import_market=details)
            else:
                game = f"house:{region}:{house}-{faction}"
                db.executemany("""INSERT OR IGNORE INTO local_auction_snapshots VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""", [
                    (UNMAPPED, game, item, t, name or names.get(item) or f"item:{item}", *meta.get(item, (None, None, None)),
                     qty, listings, 0, lo, med, mean, 0, digest)
                    for item, (name, qty, listings, lo, med, mean) in items.items()])
                legacy.log_import(db, digest, "market", f"csv:{region}:house-{house}", UNMAPPED, {"game": game, "items": len(items)})
                summary.update(storedAs=game, published=False)
            entry["houses"][house] = summary
        db.execute("INSERT INTO local_source_files VALUES (?,?,?,?,NULL) ON CONFLICT DO NOTHING", (
            digest, "forever-ah-csv/" + path.name,
            datetime.fromtimestamp(path.stat().st_mtime, timezone.utc).isoformat(), len(data)))
        entry["result"] = "imported"

    print(json.dumps(report, indent=1, default=str))
    if args.dry_run or not loaded:
        conn.rollback()
        print("DRY RUN: rolled back" if args.dry_run else "Nothing new")
        return
    for t, path, *_ in loaded:
        run_dir.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, run_dir / path.name)
    db.execute("ANALYZE local_auction_snapshots")
    db.execute("ANALYZE items")
    conn.commit()
    print(f"archived to {run_dir}")
    print("CHANGED sources=" + (SOURCE if mapping else ""))


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"IMPORT FAILED, nothing was written: {exc}", file=sys.stderr, flush=True)
        raise
