#!/usr/bin/env python3
"""Write each WoW play session's data to its own Lua file whenever WoW saves.

    python -B tools/watch-savedvariables.py [--interval=10] [--settle=20]
    python -B tools/watch-savedvariables.py --import-pg [--check-icons]

WoW writes MarketLens.lua and NameplateInspect.lua, or the combined
WoWCensus.lua, on /reload and logout. This polls every client's files; once a
change has stopped changing for --settle seconds, tools/write-session-save.js
copies the realm buckets and inspects that are new since the last session file
into Documents/WoW Census Saves/wow-<edition>-<timestamp>.lua. Nothing is
written to PostgreSQL.

--import-pg runs tools/import-savedvariables-pg.py into local PostgreSQL
instead (the old behavior). With it, optional --check-icons renders local pages
and checks their icons for a later manual site release. Publishing to
wowcensus.io is a separate step (upload.md).
Output goes to the console and to MarketLensData/imports/watch.log. Stop it with Ctrl+C.
"""

import argparse
import os
import re
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
GAME_ROOT = Path(os.environ.get("MARKETLENS_GAME_ROOT", r"C:\Program Files (x86)\World of Warcraft"))
DATA_ROOT = Path(os.environ.get("MARKETLENS_DATA_ROOT", Path.home() / "Documents" / "MarketLensData"))
PHP = Path(os.environ.get("MARKETLENS_PHP", r"C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe"))
FLAVORS = ("_anniversary_", "_classic_", "_classic_beta_", "_classic_era_", "_forever_", "_retail_")
LOG = DATA_ROOT / "imports" / "watch.log"
CLIENT_NAMES = {"_anniversary_": "TBC Anniversary", "_classic_": "MoP Classic", "_classic_beta_": "Forever (Beta)", "_forever_": "Forever",
                "_classic_era_": "Classic Era / SoD / Hardcore", "_retail_": "Retail"}
# Edition part of the session file name: wow-<name>-<timestamp>.lua.
SESSION_NAMES = {"_anniversary_": "tbc", "_classic_": "mop", "_classic_beta_": "forever-beta", "_forever_": "forever",
                 "_classic_era_": "classic", "_retail_": "retail"}
SESSION_DIR = Path(os.environ.get("MARKETLENS_SESSION_DIR", Path.home() / "Documents" / "WoW Census Saves"))
EDITION_NAMES = {"classic-beta": "Forever (Beta)", "forever": "Forever", "classic-progression": "TBC Anniversary", "classic": "Classic Era",
                 "sod": "Season of Discovery", "hardcore": "Classic Hardcore", "mop-classic": "MoP Classic",
                 "retail": "Retail"}
# Windows toast through Windows PowerShell's own app ID, so nothing has to be installed.
TOAST = r"""
[Windows.UI.Notifications.ToastNotificationManager, Windows.UI.Notifications, ContentType = WindowsRuntime] > $null
$xml = [Windows.UI.Notifications.ToastNotificationManager]::GetTemplateContent([Windows.UI.Notifications.ToastTemplateType]::ToastText02)
$text = $xml.GetElementsByTagName('text')
$text.Item(0).AppendChild($xml.CreateTextNode($env:ML_TOAST_TITLE)) > $null
$text.Item(1).AppendChild($xml.CreateTextNode($env:ML_TOAST_BODY)) > $null
$toast = [Windows.UI.Notifications.ToastNotification]::new($xml)
[Windows.UI.Notifications.ToastNotificationManager]::CreateToastNotifier('{1AC14E77-02E7-4E5D-B744-2EB1AE5198B7}\WindowsPowerShell\v1.0\powershell.exe').Show($toast)
"""


def notify(title, body):
    """Show a Windows notification without waiting for it."""
    try:
        subprocess.Popen(["powershell", "-NoProfile", "-NonInteractive", "-Command", TOAST],
                         env={**os.environ, "ML_TOAST_TITLE": title, "ML_TOAST_BODY": body},
                         creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
                         stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except OSError as exc:
        log(f"notification failed: {exc}")


def client_name(path):
    flavor = next((part for part in Path(path).parts if part in CLIENT_NAMES), "")
    return CLIENT_NAMES.get(flavor, "WoW")


def log(message):
    line = f"{datetime.now():%Y-%m-%d %H:%M:%S} {message}"
    if sys.stdout:  # pythonw (the login task) has no console
        print(line, flush=True)
    LOG.parent.mkdir(parents=True, exist_ok=True)
    with LOG.open("a", encoding="utf-8") as stream:
        stream.write(line + "\n")


def clients():
    """(flavor, main save, companion inspect save or None) for every account."""
    found = []
    for flavor in FLAVORS:
        account_root = GAME_ROOT / flavor / "WTF" / "Account"
        if account_root.is_dir():
            for market in account_root.glob("*/SavedVariables/MarketLens.lua"):
                found.append((flavor, market, market.with_name("NameplateInspect.lua")))
            found += [(flavor, census, None) for census in account_root.glob("*/SavedVariables/WoWCensus.lua")]
    return found


def save_files():
    return [path for _, main, inspect in clients() for path in (main, inspect) if path]


def write_sessions(changed):
    """Write a session file for every client with a changed save; returns the files written."""
    written, failed = [], False
    for flavor, main, inspect in clients():
        if changed is not None and not ({main, inspect} & changed):
            continue
        command = ["node", REPO / "tools" / "write-session-save.js", f"--name={SESSION_NAMES[flavor]}",
                   f"--out={SESSION_DIR}", main] + ([inspect] if inspect else [])
        result = run("session", command)
        last = (result.stdout.strip().splitlines() or [""])[-1]
        if result.returncode:
            failed = True
        elif last.startswith("WROTE "):
            written.append(Path(last[6:]).name)
    return written, failed


def fingerprint():
    state = {}
    for path in save_files():
        try:
            stat = path.stat()
            state[path] = (stat.st_size, stat.st_mtime_ns)
        except FileNotFoundError:
            pass
    return state


def run(label, command):
    log(f"{label}: {' '.join(map(str, command))}")
    # No console windows popping up over the game for the importer or its node helpers.
    flags = getattr(subprocess, "CREATE_NO_WINDOW", 0)
    result = subprocess.run(list(map(str, command)), cwd=REPO, capture_output=True, text=True, encoding="utf-8",
                            errors="replace", creationflags=flags,
                            env={**os.environ, "PYTHONIOENCODING": "utf-8"})
    for line in (result.stdout + result.stderr).splitlines()[-12:]:
        log(f"  {line}")
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--interval", type=float, default=10, help="seconds between checks")
    parser.add_argument("--settle", type=float, default=20, help="seconds a change must stay still before importing")
    parser.add_argument("--import-pg", action="store_true",
                        help="import into local PostgreSQL instead of writing session files")
    parser.add_argument("--check-icons", action="store_true",
                        help="with --import-pg: render local pages and check icons for a future release")
    parser.add_argument("--import-only", "--no-static", action="store_true",
                        help="legacy alias; importing only is now the default")
    args = parser.parse_args()

    log(f"watching {len(save_files())} save files under {GAME_ROOT}")
    seen = fingerprint()
    # Start with a check, for anything saved while the watcher was not running.
    # That first check stays quiet unless it finds something.
    pending_since = time.monotonic() - args.settle
    pending_paths = None  # None: every client
    startup = True
    while True:
        try:
            current = fingerprint()
            if current != seen:
                paths = {p for p in set(current) | set(seen) if current.get(p) != seen.get(p)}
                changed = sorted({client_name(p) for p in paths})
                seen = current
                if pending_paths is not None:
                    pending_paths |= paths
                if pending_since is None:
                    pending_paths = paths
                    action = "importing" if args.import_pg else "saving the session"
                    notify("WoW save detected", f"{', '.join(changed)}: {action} once the game finishes writing.")
                pending_since = time.monotonic()
                log(f"save files changed ({', '.join(changed)}); waiting for the game to finish writing")
            elif pending_since is not None and time.monotonic() - pending_since >= args.settle:
                pending_since = None
                if not args.import_pg:
                    written, failed = write_sessions(pending_paths)
                    pending_paths = None
                    if failed:
                        log("session save failed; it will retry on the next save")
                        notify("WoW session save failed", "No session file was written. Details are in watch.log.")
                    elif written:
                        log(f"session files: {', '.join(written)}")
                        notify("WoW session saved", f"{', '.join(written)} in {SESSION_DIR}")
                    elif not startup:
                        log("nothing new")
                    startup = False
                    continue
                # python.exe, not pythonw.exe: the importer's output is how the watcher reads the result.
                python = Path(sys.executable).with_name("python.exe")
                result = run("import", [python if python.is_file() else sys.executable, "-B", REPO / "tools" / "import-savedvariables-pg.py"])
                if result.returncode:
                    log("import failed; nothing was written. It will retry on the next save.")
                    notify("WoW import failed", "Nothing was written to PostgreSQL. Details are in watch.log.")
                    continue
                match = re.search(r"^CHANGED sources=(.*)$", result.stdout, re.M)
                sources = [s for s in (match.group(1) if match else "").split(",") if s]
                names = ", ".join(EDITION_NAMES.get(s, s) for s in sources)
                if sources:
                    notify("Imported into PostgreSQL", f"New {names} data is in local PostgreSQL. The live site updates only when published.")
                elif not startup:
                    notify("WoW save checked", "Nothing new to import; that save was already in PostgreSQL.")
                startup = False
                if not sources:
                    log("nothing new")
                elif not args.check_icons or args.import_only:
                    log(f"imported: {', '.join(sources)}")
                else:
                    render = run("render", [PHP, REPO / "laragon-php" / "render-routes.php", f"--sources={','.join(sources)}"])
                    if render.returncode:
                        log("page render failed; PostgreSQL is still up to date, icons were not checked")
                        notify("Icon check failed", f"PostgreSQL has the new {names} data; rendering its pages failed. See watch.log.")
                        continue
                    # New talents can name icons the local mirror lacks, and newly seen items have no cached icon ("?").
                    mirror = run("icons", ["node", REPO / "tools" / "mirror-profile-icons.mjs"])
                    icons = run("icons", ["node", REPO / "tools" / "update-item-icons.mjs", f"--sources={','.join(sources)}"])
                    log("icons checked" if mirror.returncode == 0 and icons.returncode == 0 else "icon update failed; see above")
            time.sleep(args.interval)
        except KeyboardInterrupt:
            log("stopped")
            return


if __name__ == "__main__":
    main()
