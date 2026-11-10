#!/usr/bin/env python3
"""Run an importer against local PostgreSQL and then the live wowcensus.io database.

    python -B tools/import-live.py csv FILE.csv [FILE.csv ...] --map "us:111=CAMWOW US2 Realm 5 PvE-Alliance" [--dry-run]
    python -B tools/import-live.py saves --game-root <stage>\\game [--allow-beta-import]

`csv` runs tools/import-forever-ah-csv-pg.py and `saves` runs
tools/import-savedvariables-pg.py; every argument after the kind is passed to
that importer unchanged. The importer runs locally first (local PostgreSQL stays
the full copy and the source of truth). Only if that succeeds does it run again,
on this PC, against the live database through an SSH tunnel: the Lua/CSV
parsing stays here and only SQL crosses the wire. No dump, upload or restore.

Both importers skip any file whose SHA-256 is already in local_source_files and
write in one transaction, so a run is safe to repeat and a failure writes
nothing. Files already imported locally are still imported live if live lacks
them.

The live side uses exactly one SSH connection: it forwards a local port to the
server's PostgreSQL, reads the database password from /etc/wowcensus/pg-password
(kept in memory, passed to the importer as PGPASSWORD, never printed), and stays
open until the import ends. The live run archives into
MarketLensData/imports-live/<UTC time>/: its import-report.json (with timings) and
a copy of each file it imported, so a live-only run can be replayed locally later
(--target local --game-root pointing at that folder, or the CSVs in it).

New data goes live on the site through the hourly wowcensus-data-refresh cron
(census rebuild, then the page rollout). --publish-now starts that refresh with
FORCE=1 over the same connection when the live import changed something.

Options for this wrapper (everything else goes to the importer):
  --target both|local|live   default both
  --host root@162.243.9.180  the production server
  --publish-now              start FORCE=1 wowcensus-data-refresh after a live change
"""

import argparse
import os
import socket
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
IMPORTERS = {"csv": REPO / "tools" / "import-forever-ah-csv-pg.py",
             "saves": REPO / "tools" / "import-savedvariables-pg.py"}
LIVE_DB = "dbname=marketlens user=wowcensus"
LIVE_ARCHIVE = Path(os.environ.get("MARKETLENS_DATA_ROOT", Path.home() / "Documents" / "MarketLensData")) / "imports-live"
LIVE_PG_PORT = 5432
# Runs on the server: print the password and a marker, then wait for one line on
# stdin ("publish" or "done"), so the tunnel lives exactly as long as the import.
REMOTE = ("printf '%s\\nREADY\\n' \"$(cat /etc/wowcensus/pg-password)\" && read cmd; "
          "if [ \"$cmd\" = publish ]; then FORCE=1 nohup /usr/local/bin/wowcensus-data-refresh "
          ">> /var/log/wowcensus-data-refresh.log 2>&1 < /dev/null & echo PUBLISH STARTED; fi")


def run_importer(kind, args, env=None):
    """Run the importer, streaming its output; return (exit code, last line)."""
    proc = subprocess.Popen([sys.executable, "-B", str(IMPORTERS[kind]), *args], cwd=REPO, env=env,
                            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, encoding="utf-8",
                            errors="backslashreplace")
    last = ""
    for line in proc.stdout:
        sys.stdout.write(line)
        if line.strip():
            last = line.strip()
    return proc.wait(), last


def free_port():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter, add_help=True)
    parser.add_argument("kind", choices=sorted(IMPORTERS))
    parser.add_argument("--target", choices=("both", "local", "live"), default="both")
    parser.add_argument("--host", default="root@162.243.9.180")
    parser.add_argument("--publish-now", action="store_true")
    args, rest = parser.parse_known_args()
    if any(a == "--dsn" or a.startswith("--dsn=") or a == "--archive" or a.startswith("--archive=") for a in rest):
        raise SystemExit("--dsn and --archive are set by this wrapper; leave them out")
    if args.kind == "saves" and "--dry-run" in rest:
        raise SystemExit("import-savedvariables-pg.py has no --dry-run")
    dry = "--dry-run" in rest

    if args.target in ("both", "local"):
        print("=== local PostgreSQL", flush=True)
        code, _ = run_importer(args.kind, rest)
        if code:
            raise SystemExit(f"local import failed (exit {code}); live not touched")
    if args.target == "local":
        return

    print(f"=== live PostgreSQL via {args.host}", flush=True)
    port = free_port()
    ssh = subprocess.Popen(["ssh", "-o", "BatchMode=yes", "-o", "ExitOnForwardFailure=yes",
                            "-o", "ServerAliveInterval=30", "-L", f"127.0.0.1:{port}:127.0.0.1:{LIVE_PG_PORT}",
                            args.host, REMOTE],
                           stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True, encoding="utf-8")
    # Kept, not deleted: the live run's import report (with timings) and a copy of
    # every file it imported, so a live-only import can be replayed locally later.
    archive = LIVE_ARCHIVE
    archive.mkdir(parents=True, exist_ok=True)
    try:
        password = ssh.stdout.readline().strip()
        if ssh.stdout.readline().strip() != "READY" or not password:
            raise SystemExit("could not open the SSH tunnel to the live database (not retrying)")
        env = {**os.environ, "PGPASSWORD": password, "PGCONNECT_TIMEOUT": "15"}
        dsn = f"host=127.0.0.1 port={port} {LIVE_DB}"
        code, last = run_importer(args.kind, [*rest, f"--dsn={dsn}", f"--archive={archive}"], env)
        # Any committed run ends with a CHANGED line (unmapped AH houses print it empty but
        # still add local_imports rows, which is what the refresh watches).
        # The save importer prints an empty CHANGED line when nothing was new.
        changed = code == 0 and not dry and last.startswith("CHANGED") and (
            args.kind == "csv" or last != "CHANGED sources=")
        publish = changed and args.publish_now
        ssh.stdin.write("publish\n" if publish else "done\n")
        ssh.stdin.flush()
        tail = ssh.communicate(timeout=60)[0].strip()
        if tail:
            print(tail)
        if code:
            raise SystemExit(f"live import failed (exit {code}); nothing was written there")
        if changed and not publish:
            print("live: the hourly wowcensus-data-refresh publishes this (census rebuild, then page rollout)")
    finally:
        if ssh.poll() is None:
            ssh.kill()


if __name__ == "__main__":
    main()
