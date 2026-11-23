#!/usr/bin/env python3
"""Run an importer against local PostgreSQL and then the live wowcensus.io database.

    python -B tools/import-live.py csv FILE.csv [FILE.csv ...] --map "us:111=CAMWOW US2 Realm 5 PvE-Alliance" [--dry-run]
    python -B tools/import-live.py saves --game-root <stage>\\game [--allow-beta-import]

`csv` runs tools/import-forever-ah-csv-pg.py, `saves` runs
tools/import-savedvariables-pg.py and `map-house` runs
tools/map-forever-house-pg.py (publish a verified Forever auction house), `journalator` runs
tools/import-journalator-pg.py (Journalator/TSM/Auctionator ledgers), `dungeon-runs` runs
tools/import-dungeon-runs-pg.py (boss kills and drops from Dungeon Journal run logs), `forever-dungeons` runs
tools/build-forever-dungeons.py (the dungeon/boss/loot/quest tables from the Dungeon Journal add-on), `dungeon-guides`
runs tools/import-dungeon-guides-pg.py (Forever Journal overviews and artwork), `combatlog` runs
tools/import-combatlog-pg.py (Forever bestiary from advanced combat logs); every argument after the kind is passed to
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
  --skip-icons               don't fill and ship item icons after the live import

After every successful live import, the wrapper reads the item ids in live's AH
scans through the tunnel and runs tools/update-item-icons.mjs for them. Any
tools/item-icons/<stem>.json that differs from live's copy (by SHA-256) goes over the same SSH connection into the
live release's resources/item-icons/, and the server re-renders that edition's AH
page and purges it from Cloudflare. Missing icon images need no upload: nginx
redirects them to wow.zamimg.com. Commit the updated JSON so the next release keeps it.
"""

import argparse
import os
import socket
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
IMPORTERS = {"csv": REPO / "tools" / "import-forever-ah-csv-pg.py",
             "classicplus": REPO / "tools" / "import-classicplus-pg.py",
             "classicplus-merge": REPO / "tools" / "merge-classicplus-pg.py",
             "saves": REPO / "tools" / "import-savedvariables-pg.py",
             "map-house": REPO / "tools" / "map-forever-house-pg.py",
             "journalator": REPO / "tools" / "import-journalator-pg.py",
             "dungeon-runs": REPO / "tools" / "import-dungeon-runs-pg.py",
             "forever-dungeons": REPO / "tools" / "build-forever-dungeons.py",
             "dungeon-guides": REPO / "tools" / "import-dungeon-guides-pg.py",
             "combatlog": REPO / "tools" / "import-combatlog-pg.py",
             "repair-ledger-identity": REPO / "tools" / "repair-ledger-identity-pg.py"}
LIVE_DB = "dbname=marketlens user=wowcensus"
LIVE_ARCHIVE = Path(os.environ.get("MARKETLENS_DATA_ROOT", Path.home() / "Documents" / "MarketLensData")) / "imports-live"
LIVE_PG_PORT = 5432
# Runs on the server: print the password and a marker, then read commands on stdin
# until "publish" or "done", so the tunnel lives exactly as long as the import.
#   hashes: print "HASH <stem> <sha256>" for each live item-icons file, then "HASHES END"
#   icons <stem>  + one base64 line: replace the live release's resources/item-icons/<stem>.json
#   refresh <uri>: re-render that page in nginx's cache and purge it from Cloudflare
# Python's text pipes on Windows send "\r\n": every read strips the "\r".
REMOTE = r"""printf '%s\nREADY\n' "$(cat /etc/wowcensus/pg-password)" &&
cr="$(printf '\r')"
while read -r cmd arg; do
  cmd="${cmd%"$cr"}"; arg="${arg%"$cr"}"
  case "$cmd" in
    hashes)
      for s in forever classic tbc mop-classic retail; do
        echo "HASH $s $(sha256sum "$(readlink -f /srv/wowcensus/current)/resources/item-icons/$s.json" 2>/dev/null | cut -c1-64)"
      done; echo "HASHES END";;
    icons) read -r data; data="${data%"$cr"}"
      case "$arg" in forever|classic|tbc|mop-classic|retail) ;; *) echo "ICONS $arg REJECTED"; continue;; esac
      f="$(readlink -f /srv/wowcensus/current)/resources/item-icons/$arg.json"
      if printf '%s' "$data" | base64 -d > "$f.new" && php -r 'is_array(json_decode(file_get_contents($argv[1]), true)) or exit(1);' "$f.new"
      then chmod 644 "$f.new"; mv "$f.new" "$f"; echo "ICONS $arg UPDATED"
      else rm -f "$f.new"; echo "ICONS $arg FAILED"; fi;;
    refresh)
      case "$arg" in /*auctionhouse|/*auctionhouse.html) ;; *) echo "REFRESH $arg REJECTED"; continue;; esac
      curl -sk -o /dev/null -w "REFRESH $arg %{http_code}\n" -H "Host: wowcensus.io" -H "X-WoWCensus-Refresh: 1" "https://127.0.0.1$arg"
      (. /etc/wowcensus/cloudflare.env && curl -s -o /dev/null -w "PURGE $arg %{http_code}\n" -X POST "https://api.cloudflare.com/client/v4/zones/$CF_ZONE_ID/purge_cache" -H "Authorization: Bearer $CF_API_TOKEN" -H "Content-Type: application/json" --data "{\"files\":[\"https://wowcensus.io$arg\"]}");;
    *) break;;
  esac
done
if [ "$cmd" = publish ]; then FORCE=1 nohup /usr/local/bin/wowcensus-data-refresh >> /var/log/wowcensus-data-refresh.log 2>&1 < /dev/null & echo PUBLISH STARTED; fi"""
ICON_TOOL = REPO / "tools" / "update-item-icons.mjs"
# Edition -> tools/item-icons/<stem>.json (update-item-icons.mjs EDITIONS) and its AH page.
ICON_STEM = {"forever": "forever", "classic-beta": "forever", "classic-progression": "tbc", "classic": "classic",
             "sod": "classic", "hardcore": "classic", "mop-classic": "mop-classic", "retail": "retail"}
AH_PAGE = {"forever": "/forever/auctionhouse", "classic-beta": "/forever-beta/auctionhouse",
           "classic-progression": "/tbc/auctionhouse", "classic": "/classic/auctionhouse", "sod": "/sod/auctionhouse",
           "hardcore": "/hardcore/auctionhouse", "mop-classic": "/mop/auctionhouse", "retail": "/retail/auctionhouse"}


def fill_icons(dsn, password, ssh):
    """Cache icons for every item in the live AH scans; return the remote commands that ship them.

    Before this, icons came from one-off fills of tools/item-icons/*.json, and prod's copy only
    changed on a code release, so every item first seen in a live import showed "?" on the site.
    A file is shipped whenever it differs from live's copy, so a fill made elsewhere (the local
    watcher, a manual run) also reaches live on the next import.
    """
    import base64
    import hashlib
    import tempfile
    import psycopg2
    with psycopg2.connect(dsn, password=password, connect_timeout=15) as conn, conn.cursor() as cur:
        cur.execute("SELECT source_game, array_agg(item_id ORDER BY item_id) FROM "
                    "(SELECT DISTINCT source_game, item_id FROM marketlens_import.local_auction_snapshots) s GROUP BY 1")
        scanned = {source: ids for source, ids in cur.fetchall() if source in ICON_STEM}
    files = {stem: REPO / "tools" / "item-icons" / f"{stem}.json" for stem in set(ICON_STEM.values())}
    for source, ids in sorted(scanned.items()):
        with tempfile.NamedTemporaryFile("w", suffix=".txt", delete=False) as tmp:
            tmp.write("\n".join(map(str, ids)))
        try:
            out = subprocess.run(["node", str(ICON_TOOL), f"--sources={source}", f"--ids-from={tmp.name}"], cwd=REPO,
                                 capture_output=True, text=True, encoding="utf-8", errors="backslashreplace")
        finally:
            os.unlink(tmp.name)
        for line in (out.stdout + out.stderr).splitlines():
            if line.strip():
                print(f"icons {source}: {line.strip()}")
        if out.returncode:
            print(f"icons {source}: update-item-icons.mjs failed (exit {out.returncode})")
    ssh.stdin.write("hashes\n")
    ssh.stdin.flush()
    live = {}
    while (line := ssh.stdout.readline().strip()) != "HASHES END":
        if not line:
            raise RuntimeError("the server did not answer the icon hash check")
        parts = line.split()
        if parts[0] == "HASH":
            live[parts[1]] = parts[2] if len(parts) > 2 else ""
    commands = []
    for stem, f in sorted(files.items()):
        if f.exists() and hashlib.sha256(f.read_bytes()).hexdigest() != live.get(stem):
            commands.append(f"icons {stem}\n{base64.b64encode(f.read_bytes()).decode()}\n")
            for source in sorted(s for s in scanned if ICON_STEM[s] == stem):
                commands += [f"refresh {AH_PAGE[source]}\n", f"refresh {AH_PAGE[source]}.html\n"]
    return commands


def run_importer(kind, args, env=None):
    """Run the importer, streaming its output; return (exit code, last line)."""
    # The child must write UTF-8 (Windows pipes default to cp1252, which cannot encode
    # Korean realm names and garbles accented guilds); our own console may not take UTF-8.
    env = {**(env or os.environ), "PYTHONIOENCODING": "utf-8"}
    sys.stdout.reconfigure(errors="backslashreplace")
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
    parser.add_argument("--skip-icons", action="store_true")
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
            args.kind not in ("saves", "classicplus") or last != "CHANGED sources=")
        publish = changed and args.publish_now
        # Icons for new items, shipped over this connection. The import is already
        # committed, so a failure here only warns.
        if code == 0 and not dry and not args.skip_icons:
            try:
                commands = fill_icons(dsn, password, ssh)
                for command in commands:
                    ssh.stdin.write(command)
                if not commands:
                    print("icons: live already has every scanned item's icon")
            except Exception as e:
                print(f"icons: skipped ({type(e).__name__}: {e})")
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
