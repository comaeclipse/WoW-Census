#!/usr/bin/env python3
"""Capture remote D1 and installed SavedVariables, merge SQLite, build static Pages.

Default: reuse the newest verified local D1 archive and capture SavedVariables
Fresh historical export: python tools/refresh-local-static.py --refresh-d1
Rebuild an existing archive: python tools/refresh-local-static.py --from-snapshot PATH
Fresh SavedVariables, unchanged D1: python tools/refresh-local-static.py --d1-from PATH
Only one edition changed: add --source=classic-beta (comma-separate several)
Output defaults to C:/Users/<user>/Documents/MarketLensData/<UTC timestamp>/
"""

import argparse
import hashlib
import json
import os
import shutil
import sqlite3
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
GAME_ROOT = Path(os.environ.get("MARKETLENS_GAME_ROOT", r"C:\Program Files (x86)\World of Warcraft"))
DATA_ROOT = Path(os.environ.get("MARKETLENS_DATA_ROOT", Path.home() / "Documents" / "MarketLensData"))
FLAVORS = ("_anniversary_", "_classic_", "_classic_beta_", "_classic_era_", "_retail_")


def run(*args, cwd=REPO, capture=False):
    print("Running:", " ".join(map(str, args)), flush=True)
    return subprocess.run(list(map(str, args)), cwd=cwd, check=True,
                          text=True, capture_output=capture)


def digest(file):
    h = hashlib.sha256()
    with file.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            h.update(block)
    return h.hexdigest().upper()


def verified_d1_archive(root):
    base = root / "d1"
    database = base / "marketlens.sqlite"
    record = base / "verification.json"
    if not database.is_file() or not record.is_file():
        return None
    try:
        verification = json.loads(record.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return None
    if verification.get("integrityCheck") != "ok" or digest(database) != verification.get("sqliteSha256"):
        return None
    return verification


def restore_sql(source, target):
    db = sqlite3.connect(target)
    db.execute("PRAGMA journal_mode=DELETE")
    try:
        chunk = []
        size = 0
        with source.open("r", encoding="utf-8-sig") as stream:
            for line in stream:
                chunk.append(line)
                size += len(line)
                if size >= 8 * 1024 * 1024 and sqlite3.complete_statement("".join(chunk)):
                    db.executescript("BEGIN;\n" + "".join(chunk) + "\nCOMMIT;")
                    chunk, size = [], 0
            if chunk:
                script = "".join(chunk)
                if not sqlite3.complete_statement(script):
                    raise RuntimeError("D1 export ends in an incomplete SQL statement")
                db.executescript("BEGIN;\n" + script + "\nCOMMIT;")
        integrity = db.execute("PRAGMA integrity_check").fetchone()[0]
        if integrity != "ok":
            raise RuntimeError(f"Restored D1 integrity check: {integrity}")
        tables = {name: db.execute(f'SELECT COUNT(*) FROM "{name}"').fetchone()[0]
                  for (name,) in db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")}
    finally:
        db.close()
    return {"capturedAtUtc": datetime.now(timezone.utc).isoformat(),
            "sourceSqlBytes": source.stat().st_size, "sourceSqlSha256": digest(source),
            "sqliteBytes": target.stat().st_size, "sqliteSha256": digest(target),
            "integrityCheck": integrity, "tables": tables}


def capture_savedvariables(root):
    manifest = []
    realms = []
    for flavor in FLAVORS:
        account_root = GAME_ROOT / flavor / "WTF" / "Account"
        if not account_root.is_dir():
            continue
        for source in sorted(account_root.glob("*/SavedVariables/MarketLens.lua")):
            inspect = source.with_name("NameplateInspect.lua")
            if not inspect.is_file():
                raise RuntimeError(f"Missing companion SavedVariables: {inspect}")
            for file in (source, inspect):
                relative = file.relative_to(GAME_ROOT)
                target = root / "savedvariables" / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                before = file.stat()
                shutil.copy2(file, target)
                after = file.stat()
                if (before.st_size, before.st_mtime_ns) != (after.st_size, after.st_mtime_ns) or digest(file) != digest(target):
                    raise RuntimeError(f"SavedVariables changed while copying: {file}; reload or exit the game and retry")
                manifest.append({"relativePath": str(relative), "bytes": target.stat().st_size,
                                 "sourceLastWriteUtc": datetime.fromtimestamp(file.stat().st_mtime, timezone.utc).isoformat(),
                                 "sha256": digest(target)})
            listing = run("node", REPO / "tools" / "export-realm-from-savedvariables.js",
                          root / "savedvariables" / source.relative_to(GAME_ROOT), "--list-realms",
                          capture=True)
            realms.append({"flavor": flavor, "file": str(source.relative_to(GAME_ROOT)),
                           "realms": json.loads(listing.stdout)})
    if not manifest or not realms:
        raise RuntimeError(f"No MarketLens SavedVariables found in {GAME_ROOT}")
    (root / "savedvariables-manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    (root / "savedvariables-realms.json").write_text(json.dumps(realms, indent=2) + "\n", encoding="utf-8")
    return len(manifest), sum(len(group["realms"]) for group in realms)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--from-snapshot", type=Path, help="reuse a captured archive; do not contact D1 or game folders")
    parser.add_argument("--d1-from", type=Path,
                        help="reuse this archive's verified D1 SQLite instead of exporting D1; capture fresh SavedVariables")
    parser.add_argument("--refresh-d1", action="store_true",
                        help="explicitly export historical D1 again instead of reusing the newest local archive")
    parser.add_argument("--source", help="re-render only these editions, comma-separated (e.g. classic-beta); "
                        "other editions' static files are left as they are")
    parser.add_argument("--out", type=Path, default=REPO / "static", help="static output directory")
    args = parser.parse_args()
    if args.refresh_d1 and (args.from_snapshot or args.d1_from):
        parser.error("--refresh-d1 cannot be combined with --from-snapshot or --d1-from")
    if args.from_snapshot and args.d1_from:
        parser.error("--from-snapshot and --d1-from are mutually exclusive")
    if not args.from_snapshot and not args.d1_from and not args.refresh_d1:
        for candidate in sorted(DATA_ROOT.glob("*/"), reverse=True):
            if verified_d1_archive(candidate):
                args.d1_from = candidate
                break
        if not args.d1_from:
            raise RuntimeError("No verified local D1 archive found. Pass --d1-from PATH or explicitly --refresh-d1")
    if args.from_snapshot:
        root = args.from_snapshot.resolve()
        for name in ("d1/marketlens.sqlite", "savedvariables-manifest.json", "savedvariables-realms.json"):
            if not (root / name).is_file():
                raise RuntimeError(f"Snapshot is missing {name}: {root}")
    else:
        root = DATA_ROOT / datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S")
        root.mkdir(parents=True, exist_ok=False)
        (root / "d1").mkdir()
    if args.d1_from:
        base = args.d1_from.resolve() / "d1"
        for name in ("marketlens.sqlite", "verification.json"):
            if not (base / name).is_file():
                raise RuntimeError(f"D1 archive is missing {name}: {base}")
        verification = verified_d1_archive(args.d1_from.resolve())
        if verification is None:
            raise RuntimeError(f"D1 archive does not match its verification record: {base}")
        shutil.copy2(base / "marketlens.sqlite", root / "d1" / "marketlens.sqlite")
        verification["reusedFrom"] = str(base)
        (root / "d1" / "verification.json").write_text(json.dumps(verification, indent=2) + "\n", encoding="utf-8")
        print(f"Reused D1 SQLite captured {verification['capturedAtUtc']} from {base}", flush=True)
    elif not args.from_snapshot:
        sql = root / "d1" / "marketlens-remote.sql"
        # Wrangler prints a short-lived signed download URL during export.
        # Keep it out of terminal logs while still surfacing a useful failure.
        try:
            run("npx.cmd" if os.name == "nt" else "npx", "wrangler", "d1", "export", "marketlens",
                "--remote", "--skip-confirmation", f"--output={sql}",
                f"--config={REPO / 'tools' / 'd1-archive.wrangler.toml'}", capture=True)
        except subprocess.CalledProcessError as exc:
            if "Authentication error" in (exc.stderr or ""):
                raise RuntimeError("Cloudflare authorization expired. Run `npx wrangler login` from the repository root and retry.") from exc
            raise RuntimeError(f"D1 export failed (exit {exc.returncode}); inspect Wrangler logs locally.") from exc
        print(f"Downloaded D1 SQL: {sql.stat().st_size:,} bytes", flush=True)
        verification = restore_sql(sql, root / "d1" / "marketlens.sqlite")
        (root / "d1" / "verification.json").write_text(json.dumps(verification, indent=2) + "\n", encoding="utf-8")
    if not args.from_snapshot:
        files, buckets = capture_savedvariables(root)
        print(f"Captured {len(verification['tables'])} D1 tables, {files} SavedVariables files, {buckets} realm buckets", flush=True)
    run(sys.executable, REPO / "tools" / "build-local-database.py", root)
    report = json.loads((root / "phase-two-report.json").read_text(encoding="utf-8"))
    if report.get("errors") or report.get("integrityCheck") != "ok":
        raise RuntimeError("Local SQLite merge did not pass verification")
    run("node", REPO / "tools" / "build-static-site.mjs", f"--db={root / 'marketlens-unified.sqlite'}",
        f"--out={args.out.resolve()}", *([f"--source={args.source}"] if args.source else []))
    print(f"READY snapshot={root} static={args.out.resolve()}")


if __name__ == "__main__":
    main()
