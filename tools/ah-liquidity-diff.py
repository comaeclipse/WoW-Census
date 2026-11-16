#!/usr/bin/env python3
"""Estimate AH liquidity by diffing two bulk scans of one realm-faction.

    python -B tools/ah-liquidity-diff.py --list
    python -B tools/ah-liquidity-diff.py --realm "Dreamscythe-Horde"
    python -B tools/ah-liquidity-diff.py --realm "Dreamscythe-Horde" --a 1792000000 --b 1792012000
    python -B tools/ah-liquidity-diff.py --save <MarketLens.lua> --realm "Dreamscythe-Horde"

Rows are matched as a multiset on (item, stack, buyout); a match may keep its
time-left bucket or step down. An A row with no match was removed early (sold or
cancelled) when its bucket at A could not have run out before B, and may have
expired otherwise; possible expiries never count as sales. A removed row with a
new, cheaper B row of the same stack counts as a cancel-and-relist. The rest is
"likely sold": the low end keeps only rows priced at or below the cheapest A
row still listed at B, the high end adds the relists back in.

Without --a/--b it compares the newest two scans. Scans are read from
PostgreSQL (stored by tools/import-savedvariables-pg.py), or straight from a
save file with --save. Nothing here is published to the site.
"""

import argparse
import csv
import importlib.util
import json
import os
import subprocess
import sys
from datetime import datetime
from pathlib import Path

import psycopg2

if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="backslashreplace")

REPO = Path(__file__).resolve().parent.parent
DEFAULT_DSN = os.environ.get("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")
spec = importlib.util.spec_from_file_location("auction_scan_rows", REPO / "tools" / "auction_scan_rows.py")
rows_mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(rows_mod)


def stamp(t):
    return datetime.fromtimestamp(t).strftime("%Y-%m-%d %H:%M")


def money(copper):
    if copper is None:
        return "-"
    copper = int(round(copper))
    g, s, c = copper // 10000, copper // 100 % 100, copper % 100
    return f"{g}g{s:02d}s" if g else f"{s}s{c:02d}c" if s else f"{c}c"


def item_names(conn, ids):
    if not conn or not ids:
        return {}
    try:
        cur = conn.cursor()
        cur.execute("SELECT id, name FROM items WHERE id = ANY(%s) AND name NOT LIKE 'item:%%'", (list(ids),))
        return dict(cur.fetchall())
    except psycopg2.Error:
        conn.rollback()
        return {}


def scans_from_save(path, realm):
    out = subprocess.run(["node", str(REPO / "tools" / "export-scan-rows.js"), str(path), f"--realm={realm}"],
                         capture_output=True, check=True)
    scans = json.loads(out.stdout).get(realm) or []
    for scan in scans:
        scan["items"] = {int(k): [tuple(r) for r in v] for k, v in scan["items"].items()}
    return scans


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--dsn", default=DEFAULT_DSN)
    parser.add_argument("--schema", default="marketlens_import")
    parser.add_argument("--list", action="store_true", help="list the stored scans and exit")
    parser.add_argument("--realm", help='realm-faction bucket, e.g. "Dreamscythe-Horde"')
    parser.add_argument("--source", help="edition key, when the same bucket exists in two editions")
    parser.add_argument("--save", type=Path, help="read scans from this MarketLens.lua instead of PostgreSQL")
    parser.add_argument("--a", type=int, help="scan A time (unix); default: second-newest")
    parser.add_argument("--b", type=int, help="scan B time (unix); default: the scan after A")
    parser.add_argument("--limit", type=int, default=40, help="items to print (by likely-sold units)")
    parser.add_argument("--min-sold", type=float, default=1, help="hide items with fewer likely-sold units")
    parser.add_argument("--csv", type=Path, help="write every item's row to this CSV file")
    parser.add_argument("--json", action="store_true", help="print JSON instead of a table")
    args = parser.parse_args()

    conn = None
    try:
        conn = psycopg2.connect(args.dsn)
        conn.cursor().execute(f'SET search_path TO "{args.schema}"')
    except psycopg2.Error as exc:
        if not args.save:
            raise SystemExit(f"cannot reach PostgreSQL: {exc}")
        conn = None

    if args.save:
        if not args.realm:
            raise SystemExit("--save needs --realm")
        scans = scans_from_save(args.save, args.realm)
        meta = [{"source": "save", "bucket": args.realm, "t": s["t"], "mode": s["mode"], "rows": s["rows"]} for s in scans]
    else:
        rows_mod.ensure_schema(conn)
        meta = rows_mod.list_scans(conn, args.realm, args.source)
    if args.list or not args.realm:
        for s in meta:
            print(f'{s["source"]:20} {s["bucket"]:30} {s["t"]}  {stamp(s["t"])}  {s["mode"]:9} {s["rows"]:>7} rows')
        if not meta:
            print("no row scans stored yet")
        return
    if len({s["source"] for s in meta}) > 1:
        raise SystemExit("this bucket exists in several editions; pass --source")
    times = [s["t"] for s in meta]
    if len(times) < 2:
        raise SystemExit(f"{args.realm} has {len(times)} row scan(s); two are needed")
    t_a = args.a or (times[times.index(args.b) - 1] if args.b in times else times[-2])
    if t_a not in times:
        raise SystemExit(f"no scan at {t_a}")
    t_b = args.b or (times[times.index(t_a) + 1] if times.index(t_a) + 1 < len(times) else None)
    if t_b not in times or t_b <= t_a:
        raise SystemExit("scan B must be a stored scan later than A")

    if args.save:
        by_t = {s["t"]: s for s in scans}
        scan_a, scan_b = by_t[t_a], by_t[t_b]
    else:
        source = meta[0]["source"]
        scan_a = rows_mod.load_scan(conn, source, args.realm, t_a)
        scan_b = rows_mod.load_scan(conn, source, args.realm, t_b)
    if scan_a["mode"] != scan_b["mode"]:
        print(f'warning: scan A is {scan_a["mode"]}, scan B is {scan_b["mode"]}', file=sys.stderr)

    summary, report = rows_mod.diff(scan_a, scan_b)
    names = item_names(conn, {r["item_id"] for r in report})
    for r in report:
        r["name"] = names.get(r["item_id"]) or f'item:{r["item_id"]}'
    report.sort(key=lambda r: (-r["sold"], -r["supply_a"]))

    if args.csv:
        with args.csv.open("w", newline="", encoding="utf-8") as fh:
            writer = csv.DictWriter(fh, fieldnames=list(report[0]) if report else ["item_id"])
            writer.writeheader()
            writer.writerows(report)
    if args.json:
        print(json.dumps({"realm": args.realm, "a": t_a, "b": t_b, "summary": summary, "items": report}, indent=1))
        return

    gap = summary["gap_hours"]
    print(f"{args.realm}: {stamp(t_a)} -> {stamp(t_b)} ({gap:.1f} h, {summary['mode']})")
    if gap > 8:
        print("note: the gap is longer than the Long bucket, so most vanished rows may have expired")
    print(f"A supply {summary.get('supply_a', 0):.0f} units; gone early {summary.get('removed_early', 0):.0f}, "
          f"maybe expired {summary.get('maybe_expired', 0):.0f}, relisted {summary.get('relist', 0):.0f}; "
          f"likely sold {summary.get('sold', 0):.0f} ({summary.get('sold_low', 0):.0f}-{summary.get('sold_high', 0):.0f}); "
          f"new {summary.get('new_units', 0):.0f} units")
    print()
    print(f"{'item':38} {'sold':>6} {'range':>11} {'/h':>6} {'median':>10} {'of A':>6} {'supply':>7} {'new':>6} {'expired?':>8}")
    shown = 0
    for r in report:
        if r["sold"] < args.min_sold or shown >= args.limit:
            continue
        shown += 1
        share = f'{r["sold_share"] * 100:.0f}%' if r["sold_share"] is not None else "-"
        print(f'{r["name"][:38]:38} {r["sold"]:>6} {str(r["sold_low"]) + "-" + str(r["sold_high"]):>11} '
              f'{r["sold_per_hour"]:>6.1f} {money(r["sold_median"]):>10} {share:>6} {r["supply_a"]:>7} '
              f'{r["new_units"]:>6} {r["maybe_expired"]:>8}')


if __name__ == "__main__":
    main()
