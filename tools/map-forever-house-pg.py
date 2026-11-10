#!/usr/bin/env python3
"""Publish a Forever auction house that has just been verified.

    python -B tools/map-forever-house-pg.py --house us:114 --realm "CAMWOW US2 Realm 5 PvE-Horde" [--dry-run]

tools/import-forever-ah-csv-pg.py stores every house it was not told to map
under source_game 'forever-unmapped', game 'house:<region>:<id>-<faction>',
which no page reads. Once a house is verified (its rare listings match an
addon scan from that ruleset and faction), this replays each of its stored
snapshots, oldest first, into that market's existing key exactly as the CSV
importer would have with --map: snapshots, daily history and current items.
It then moves the import log rows to source 'forever' and deletes the
unpublished copies. One transaction; --dry-run rolls it back after the report.
Run it through tools/import-live.py (kind `map-house`) to do the same live.
"""

import argparse
import importlib.util
import json
import re
import sys
from collections import defaultdict
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("ah_csv", REPO / "tools" / "import-forever-ah-csv-pg.py")
ah = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ah)
pg, legacy = ah.pg, ah.legacy


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--house", required=True, metavar="REGION:ID", help="for example us:114")
    parser.add_argument("--realm", required=True, help="the market key without 'realm:', ending in its faction")
    parser.add_argument("--dsn", default=pg.DEFAULT_DSN)
    parser.add_argument("--schema", default="marketlens_import")
    parser.add_argument("--archive", help="ignored (accepted so tools/import-live.py can run this)")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    match = re.fullmatch(r"(us|eu):(\d+)", args.house)
    if not match:
        raise SystemExit("--house must look like us:114")
    region, house = match.group(1), int(match.group(2))
    faction = ah.HOUSE_FACTION.get(house)
    if not faction or not args.realm.endswith("-" + faction):
        raise SystemExit(f"house {house} is {faction}; --realm must end in -{faction}")
    game = f"house:{region}:{house}-{faction}"
    bucket = f"csv:{region}:house-{house}"

    conn = pg.psycopg2.connect(args.dsn)
    conn.autocommit = False
    db = pg.PgDb(conn)
    lock = conn.cursor()
    lock.execute("SELECT pg_advisory_lock(hashtext('marketlens-import'))")
    conn.commit()
    db.execute(f'SET search_path TO "{args.schema}"')
    if not db.execute("SELECT 1 FROM datasets WHERE game=? AND source_game=?", ("realm:" + args.realm, ah.SOURCE)).fetchone():
        raise SystemExit(f"realm:{args.realm} is not an existing {ah.SOURCE} market")

    snapshots = defaultdict(dict)
    for item, t, name, cat, src, crafter, qty, auctions, lo, med, mean, digest in db.execute(
            """SELECT item_id, captured_at, name, category, item_source, crafter, quantity, auctions,
                      lowest, median, weighted_average, source_sha256
               FROM local_auction_snapshots WHERE source_game=? AND game=?""", (ah.UNMAPPED, game)):
        snapshots[(t, digest)][str(item)] = {
            "n": "" if (name or "").startswith("item:") else name, "m": cat, "src": src, "cr": crafter,
            "s": [[t, qty, auctions, 0, lo, med, mean, 0]]}

    report = {"house": args.house, "from": game, "to": "realm:" + args.realm, "snapshots": [], "dryRun": args.dry_run}
    for (t, digest), items in sorted(snapshots.items()):
        body = {"realm": args.realm, "capabilities": {"sellers": False}, "items": items}
        details = legacy.import_market(db, body, digest, ah.SOURCE)
        db.execute("DELETE FROM local_imports WHERE source_sha256=? AND kind='market' AND bucket=? AND source_game=?",
                   (digest, bucket, ah.UNMAPPED))
        legacy.log_import(db, digest, "market", bucket, ah.SOURCE, details)
        report["snapshots"].append({"capturedAt": t, "sha256": digest, "items": len(items),
                                    "currentItemsUpdated": details["currentItemsUpdated"],
                                    "olderCurrentItemsKept": details["olderCurrentItemsKept"]})
    removed = db.execute("DELETE FROM local_auction_snapshots WHERE source_game=? AND game=?", (ah.UNMAPPED, game)).rowcount
    report["unpublishedRowsRemoved"] = removed
    print(json.dumps(report, indent=1))
    if args.dry_run or not snapshots:
        conn.rollback()
        print("DRY RUN: rolled back" if args.dry_run else f"Nothing stored for {game}")
        return
    for table in ("local_auction_snapshots", "history", "items", "datasets", "local_imports"):
        db.execute(f"ANALYZE {table}")
    conn.commit()
    print("CHANGED sources=" + ah.SOURCE)


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"MAPPING FAILED, nothing was written: {exc}", file=sys.stderr, flush=True)
        raise
