#!/usr/bin/env python3
"""Copy the per-row bulk AH scans from every MarketLens.lua into PostgreSQL.

    python -B tools/import-scan-rows-pg.py
    python -B tools/import-scan-rows-pg.py --save <MarketLens.lua> --flavor _anniversary_

Each save repeats the last few scans of every realm-faction, so scans already
stored (same edition, realm-faction and scan time) are skipped. Feeds
tools/ah-liquidity-diff.py; nothing here reaches the site. The edition comes
from the client folder, with SoD and Hardcore split out of _classic_era_ the
same way as tools/build-local-database.py.
"""

import argparse
import hashlib
import importlib.util
import json
import os
import subprocess
import sys
from pathlib import Path

import psycopg2

if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="backslashreplace")

REPO = Path(__file__).resolve().parent.parent
GAME_ROOT = Path(os.environ.get("MARKETLENS_GAME_ROOT", r"C:\Program Files (x86)\World of Warcraft"))
DEFAULT_DSN = os.environ.get("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")
EXPORT_SCAN_ROWS = REPO / "tools" / "export-scan-rows.js"


def load(name, file):
    spec = importlib.util.spec_from_file_location(name, REPO / "tools" / file)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


legacy = load("build_local_database", "build-local-database.py")
scan_rows = load("auction_scan_rows", "auction_scan_rows.py")


def import_save(conn, path, flavor):
    data = path.read_bytes()
    digest = hashlib.sha256(data).hexdigest()
    out = subprocess.run(["node", str(EXPORT_SCAN_ROWS), str(path)], capture_output=True, check=False)
    if out.returncode:
        raise RuntimeError(out.stderr.decode("utf-8", errors="replace")[-1200:])
    total = 0
    for bucket, scans in json.loads(out.stdout).items():
        _, source = legacy.source_for(flavor, bucket)
        added = scan_rows.import_scans(conn, source, bucket, scans, digest)
        total += added
        print(f"{source:20} {bucket:30} {len(scans)} in save, {added} new", flush=True)
    return total


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--game-root", type=Path, default=GAME_ROOT)
    parser.add_argument("--save", type=Path, help="import one MarketLens.lua instead of every client's")
    parser.add_argument("--flavor", choices=sorted(legacy.FLAVORS), help="client folder of --save")
    parser.add_argument("--dsn", default=DEFAULT_DSN)
    parser.add_argument("--schema", default="marketlens_import")
    args = parser.parse_args()

    if args.save:
        if not args.flavor:
            raise SystemExit("--save needs --flavor")
        saves = [(args.save, args.flavor)]
    else:
        saves = [(path, flavor) for flavor in sorted(legacy.FLAVORS)
                 for path in sorted((args.game_root / flavor / "WTF" / "Account").glob("*/SavedVariables/MarketLens.lua"))]

    conn = psycopg2.connect(args.dsn)
    conn.cursor().execute(f'SET search_path TO "{args.schema}"')
    total = sum(import_save(conn, path, flavor) for path, flavor in saves)
    conn.commit()
    conn.close()
    print(f"row scans added: {total}")


if __name__ == "__main__":
    main()
