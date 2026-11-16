#!/usr/bin/env python3
"""Hash, validate, deduplicate, stage, and import supplied WoWCensus saves.

Examples:
  python -B tools/ingest-saves.py save1.lua save2.lua
  python -B tools/ingest-saves.py save*.lua --target local
  python -B tools/ingest-saves.py save*.lua --validate-only

The local source ledger is checked before staging. Files are copied into an
isolated WoW-style tree and then passed to import-live.py, which imports local
PostgreSQL first and live PostgreSQL second. The original files are never
modified or executed.
"""

import argparse
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

import psycopg2


REPO = Path(__file__).resolve().parent.parent
DEFAULT_DSN = os.environ.get(
    "MARKETLENS_PG_DSN",
    "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz",
)
DEFAULT_DATA_ROOT = Path(os.environ.get(
    "MARKETLENS_DATA_ROOT", Path.home() / "Documents" / "MarketLensData"
))

# Filename family -> staging flavor and permitted bucket game identities.
FAMILIES = (
    (re.compile(r"^wowcensus-mop-classic-", re.I), "_classic_", {"mop-classic"}),
    (re.compile(r"^wowcensus-tbc-anniversary-", re.I), "_anniversary_", {"tbc-anniversary"}),
    (re.compile(r"^wowcensus-classic-era-sod-hardcore-", re.I), "_classic_era_",
     {"classic-era", "sod", "hardcore"}),
    (re.compile(r"^(?:wowcensus-)?retail-", re.I), "_retail_", {"retail"}),
    (re.compile(r"^(?:wowcensus|wow)-forever-", re.I), "_forever_", {"forever", "classic-beta"}),
)
REALM_RE = re.compile(r"^(?P<region>[a-z]+):(?P<game>[^:]+):(?P<realm>.+)-(?P<faction>Alliance|Horde)$")
# Older addon versions wrote region-less buckets ("Chants éternels-Horde"); the
# importer routes those by the save's flavor, so only the shape is checked here.
LEGACY_REALM_RE = re.compile(r"^(?P<realm>[^:]+)-(?P<faction>Alliance|Horde)$")


def sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def family(path):
    for pattern, flavor, games in FAMILIES:
        if pattern.search(path.name):
            return flavor, games
    raise ValueError(f"unrecognized save filename family: {path.name}")


def list_realms(path):
    result = subprocess.run(
        ["node", str(REPO / "tools" / "export-realm-from-savedvariables.js"),
         str(path), "--list-realms"],
        cwd=REPO, capture_output=True, text=True, encoding="utf-8", errors="replace",
    )
    if result.returncode:
        raise ValueError((result.stderr or result.stdout).strip())
    try:
        realms = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise ValueError(f"realm listing was not valid JSON: {exc}") from exc
    if not isinstance(realms, list) or not all(isinstance(realm, str) for realm in realms):
        raise ValueError("realm listing was not an array of strings")
    return realms


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("files", nargs="+", type=Path)
    parser.add_argument("--dsn", default=DEFAULT_DSN, help="local ledger DSN")
    parser.add_argument("--target", choices=("both", "local", "live"), default="both")
    parser.add_argument("--publish-now", action=argparse.BooleanOptionalAction, default=True)
    parser.add_argument("--validate-only", action="store_true")
    parser.add_argument("--stage-root", type=Path)
    args = parser.parse_args()

    paths = [path.expanduser().resolve(strict=True) for path in args.files]
    if len(set(paths)) != len(paths):
        raise SystemExit("the same input path was supplied more than once")

    rows = []
    failures = []
    seen_hashes = {}
    for path in paths:
        try:
            flavor, games = family(path)
            digest = sha256(path)
            realms = list_realms(path)
            if not realms:
                raise ValueError("save contains no realm buckets")
            identities = []
            for realm in realms:
                match = REALM_RE.fullmatch(realm)
                legacy = None if match else LEGACY_REALM_RE.fullmatch(realm)
                if not match and not legacy:
                    raise ValueError(f"invalid realm bucket identity: {realm}")
                if legacy:
                    identities.append({"region": None, "game": None, **legacy.groupdict()})
                    continue
                identity = match.groupdict()
                if identity["game"] not in games:
                    raise ValueError(
                        f"bucket game {identity['game']!r} does not match filename family"
                    )
                identities.append(identity)
            if digest in seen_hashes:
                raise ValueError(f"byte-identical to another input: {seen_hashes[digest]}")
            seen_hashes[digest] = str(path)
            rows.append({"path": path, "name": path.name, "bytes": path.stat().st_size,
                         "sha256": digest, "flavor": flavor, "realms": realms,
                         "identities": identities})
        except Exception as exc:
            failures.append(f"{path}: {exc}")

    if failures:
        raise SystemExit("validation failed:\n  " + "\n  ".join(failures))

    with psycopg2.connect(args.dsn) as conn, conn.cursor() as cur:
        cur.execute(
            "SELECT sha256, relative_path FROM marketlens_import.local_source_files "
            "WHERE sha256 = ANY(%s)", ([row["sha256"] for row in rows],),
        )
        known = dict(cur.fetchall())
    new_rows = [row for row in rows if row["sha256"] not in known]

    for row in rows:
        status = f"known ({known[row['sha256']]})" if row["sha256"] in known else "new"
        print(f"{status:5} {row['sha256']} {row['bytes']:>9} {row['name']}")
        for realm in row["realms"]:
            print(f"      realm={realm} flavor={row['flavor']}")
    print(f"SUMMARY supplied={len(rows)} new={len(new_rows)} known={len(rows) - len(new_rows)}")

    if args.validate_only or not new_rows:
        return
    if args.target == "live" and new_rows:
        print("NOTE target=live: local ledger was used for preflight; live importer dedupes independently")

    timestamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    stage = (args.stage_root or DEFAULT_DATA_ROOT / f"session-import-{timestamp}").resolve()
    game_root = stage / "game"
    stage.mkdir(parents=True, exist_ok=False)
    manifest = []
    for index, row in enumerate(new_rows, 1):
        account = f"{index:03d}-{row['sha256'][:12]}"
        target_dir = game_root / row["flavor"] / "WTF" / "Account" / account / "SavedVariables"
        target_dir.mkdir(parents=True)
        target = target_dir / "WoWCensus.lua"
        shutil.copy2(row["path"], target)
        if sha256(target) != row["sha256"]:
            raise SystemExit(f"staged hash mismatch: {target}")
        manifest.append({key: row[key] for key in ("name", "bytes", "sha256", "flavor", "realms")})
        manifest[-1]["source"] = str(row["path"])
        manifest[-1]["staged"] = str(target)
    (stage / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    print(f"STAGED files={len(new_rows)} root={stage}")

    command = [sys.executable, "-B", str(REPO / "tools" / "import-live.py"), "saves",
               "--target", args.target, "--game-root", str(game_root)]
    if args.publish_now:
        command.append("--publish-now")
    result = subprocess.run(command, cwd=REPO)
    if result.returncode:
        raise SystemExit(result.returncode)
    print(f"COMPLETE manifest={stage / 'manifest.json'}")


if __name__ == "__main__":
    main()
