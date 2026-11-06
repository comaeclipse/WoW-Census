#!/usr/bin/env python3
"""Capture, build, and optionally deploy a per-edition origin SQLite refresh.

    python -B laragon-php/refresh-origin.py --editions=forever,classic-beta [--deploy]

Every route group is captured for its queries (shared SQL keys can change for
any edition); only the listed editions also capture their page models and guild
rosters, and only those are replaced in the deployed snapshot. Use the full
rebuild in README.md for code or query changes.

First use: --base <local copy of the droplet's current.sqlite>. After a
successful --deploy, the local base path is remembered for the next refresh.
The local base SHA-256 must match the live droplet before capture and before
activation. This deliberately refuses to publish from a stale local base.
"""

import argparse
import hashlib
import importlib.util
import json
import os
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
DATA = Path.home() / "Documents" / "MarketLensData"
BASE_POINTER = DATA / "current-origin-snapshot.txt"
# The origin's SSH address stays out of the public repo: set WOWCENSUS_ORIGIN_SSH
# or put it (e.g. root@<ip>) in MarketLensData/origin-ssh.txt.
ORIGIN_FILE = DATA / "origin-ssh.txt"
REMOTE = os.environ.get("WOWCENSUS_ORIGIN_SSH") or (ORIGIN_FILE.read_text(encoding="utf-8").strip() if ORIGIN_FILE.exists() else "")
REMOTE_CURRENT = "/srv/wowcensus/data/current.sqlite"

spec = importlib.util.spec_from_file_location("refresh_origin_sqlite", Path(__file__).with_name("refresh-origin-sqlite.py"))
merge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(merge)


def require_remote():
    if not REMOTE:
        sys.exit(f"Set WOWCENSUS_ORIGIN_SSH or write the origin's ssh address to {ORIGIN_FILE}")


def run(args, **kwargs):
    print("$", " ".join(map(str, args)), flush=True)
    return subprocess.run(list(map(str, args)), check=True, **kwargs)


def sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(4 * 1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def live_hash():
    result = run(["ssh", "-o", "BatchMode=yes", REMOTE, f"sha256sum {REMOTE_CURRENT}"], capture_output=True, text=True)
    digest = result.stdout.split()[0]
    if not re.fullmatch(r"[0-9a-f]{64}", digest):
        raise RuntimeError("Could not read droplet snapshot hash")
    return digest


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--editions", required=True,
                        help="comma-separated: " + ", ".join(sorted(merge.EDITION_GROUPS))
                        + " (also tbc, mop, era, forever-beta)")
    parser.add_argument("--base", type=Path, help="local copy of the droplet's active compact SQLite")
    parser.add_argument("--deploy", action="store_true", help="upload, activate, clear origin cache, and verify HTTP")
    parser.add_argument("--php", type=Path, default=Path(r"C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe"))
    parser.add_argument("--data-root", type=Path, default=DATA)
    args = parser.parse_args(argv)
    require_remote()
    try:
        editions = merge.parse_editions(args.editions)
    except ValueError as exc:
        parser.error(str(exc))
    base = args.base or (Path(BASE_POINTER.read_text(encoding="utf-8").strip()) if BASE_POINTER.exists() else None)
    if base is None or not base.is_file():
        parser.error("Provide --base with a local copy of the active droplet SQLite")
    if not args.php.is_file():
        parser.error(f"PHP executable is missing: {args.php}")
    base_digest = sha256(base)
    if base_digest != live_hash():
        raise RuntimeError("Local base snapshot differs from droplet current.sqlite; refresh --base first")

    label = "-".join(merge.EDITION_GROUPS[e] for e in editions)
    tag = datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S") + "-" + label
    capture = args.data_root / f"compact-origin-delta-{tag}"
    candidate = args.data_root / f"compact-origin-delta-{tag}.sqlite"
    if capture.exists() or candidate.exists():
        raise FileExistsError("Capture or output already exists; rerun with a new tag")
    full_groups = {merge.EDITION_GROUPS[e] for e in editions}
    # The root group's home edition is Forever: it writes Forever's models too.
    if "forever" in editions:
        full_groups.add("")
    for part in ("", ".well-known", "classic", "forever", "forever-beta", "hardcore", "mop", "retail", "sod", "tbc"):
        command = [args.php, "-d", "memory_limit=2G", REPO / "laragon-php" / "capture-page-data.php", capture, part]
        if part not in full_groups:
            command.append("--queries-only")
        run(command)
    built = merge.refresh(base, capture, candidate, editions)
    print(json.dumps(built), flush=True)
    if built["integrity"] != "ok":
        raise RuntimeError("Candidate failed integrity check")
    candidate_digest = sha256(candidate)
    print(f"candidate={candidate} sha256={candidate_digest}", flush=True)
    if not args.deploy:
        print("Candidate built locally. Run again with --deploy to capture current data and publish.", flush=True)
        return

    if base_digest != live_hash():
        raise RuntimeError("Droplet snapshot changed during capture; refusing deployment")
    remote_file = f"/srv/wowcensus/data/marketlens-{tag}.sqlite"
    run(["scp", candidate, f"{REMOTE}:{remote_file}"])
    remote_check = (
        "set -e; "
        f"chown root:www-data {remote_file}; chmod 640 {remote_file}; "
        f"test \"$(sha256sum {remote_file} | cut -d' ' -f1)\" = {candidate_digest}; "
        f"ML_QUERY_CACHE=1 ML_SQLITE_PATH={remote_file} "
        f"php -d memory_limit=512M /tmp/marketlens-check-{tag}.php"
    )
    run(["scp", REPO / "laragon-php" / "check-origin-candidate.php", f"{REMOTE}:/tmp/marketlens-check-{tag}.php"])
    run(["ssh", "-o", "BatchMode=yes", REMOTE, remote_check])
    groups = " ".join(merge.GROUPS)
    run(["ssh", "-o", "BatchMode=yes", REMOTE,
         f"set -e; for g in {groups}; do ML_QUERY_CACHE=1 ML_SQLITE_PATH={remote_file} "
         f"php -d memory_limit=512M /tmp/marketlens-check-{tag}.php $g; done"])
    if base_digest != live_hash():
        raise RuntimeError("Droplet snapshot changed before activation; candidate remains inactive")
    activate = (
        "set -e; "
        f"ln -s {remote_file} /srv/wowcensus/data/current.next.sqlite; "
        f"mv -Tf /srv/wowcensus/data/current.next.sqlite {REMOTE_CURRENT}; "
        "systemctl restart php8.3-fpm; "
        "test \"$(readlink -f /var/cache/nginx/wowcensus)\" = /var/cache/nginx/wowcensus; "
        "find /var/cache/nginx/wowcensus -type f -delete; "
        f"rm -f /tmp/marketlens-check-{tag}.php; "
        "systemctl is-active php8.3-fpm"
    )
    run(["ssh", "-o", "BatchMode=yes", REMOTE, activate])
    if live_hash() != candidate_digest:
        raise RuntimeError("Droplet pointer did not activate the candidate")
    for edition in editions:
        url = f"https://wowcensus.io/{merge.EDITION_GROUPS[edition]}/realms"
        response = run(["curl.exe", "-fsSI", url], capture_output=True, text=True)
        if "200" not in response.stdout.splitlines()[0]:
            raise RuntimeError(f"{url} did not return HTTP 200")
    BASE_POINTER.parent.mkdir(parents=True, exist_ok=True)
    BASE_POINTER.write_text(str(candidate) + "\n", encoding="utf-8")
    print(f"DEPLOYED {remote_file}; realms pages HTTP 200 for {', '.join(editions)}", flush=True)


if __name__ == "__main__":
    main()
