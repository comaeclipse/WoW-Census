#!/usr/bin/env python3
"""Import WoWCensus Companion uploads into PostgreSQL.

    python -B tools/pull-companion-uploads.py run                  # pull, import local + live, mark imported
    python -B tools/pull-companion-uploads.py run --target local   # pull and import into local PostgreSQL only
    python -B tools/pull-companion-uploads.py pull                 # only download and stage
    python -B tools/pull-companion-uploads.py ack <stage dir>      # mark a staged batch imported (census or add-on stage)
    python -B tools/pull-companion-uploads.py pull-addons          # download other add-ons' uploads; ack the stage once used

Players' uploads wait on api.wowcensus.io (the upload Worker). `pull` asks the
Worker for every receipt still waiting, downloads each bundle, checks it
(ZIP SHA-256, only manifest.json + WoWCensus.lua, size and inflation limits,
Lua SHA-256) and writes the Lua into a WoW-style tree:

    MarketLensData/companion-uploads/<UTC time>/game/_<edition>_/WTF/Account/<receipt>/SavedVariables/WoWCensus.lua

Since companion 0.1.12 most uploads are sessions: only what is new since the
last confirmed upload, in the save's own layout, so the importer reads them like
any save. `pull` only asks for WoWCensus uploads. Uploads from other add-ons
(Auctionator, Journalator, TradeSkillMaster, Dungeon Journal) are not imported
here; `pull-addons` downloads them to

    MarketLensData/companion-uploads/addons-<UTC time>/<edition>/<addon>/<receipt>.lua (+ .json manifest)

and leaves them queued on the Worker. Once their data is used (ledgers through
import-live.py journalator, Dungeon Journal saves through
tools/build-forever-dungeons.py and import-live.py dungeon-runs), `ack <that folder>` marks them imported; each
manifest carries its receipt id.

`run` then hands that tree to tools/import-live.py saves (the same importer as
local saves: SHA-256 dedup, one transaction, local first, then live). When a
staged save carries ledger data (the add-on's own auctionLog since WoWCensus
0.1.16 / companion 0.1.16, or shared Journalator/TSM/Auctionator rows in
market), the same tree then goes through import-live.py journalator. Only after
every live import succeeds does it tell the Worker each receipt is imported so the
players' apps show "On wowcensus.io". A bundle that fails the checks is marked
failed right away. The Worker's INGEST_TOKEN is read from WOWCENSUS_INGEST_TOKEN
or the secrets file.
"""

import argparse
import hashlib
import io
import json
import os
import subprocess
import sys
import urllib.error
import urllib.request
import zipfile
from datetime import datetime, timezone
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
DATA_ROOT = Path(os.environ.get("MARKETLENS_DATA_ROOT", Path.home() / "Documents" / "MarketLensData"))
API = os.environ.get("WOWCENSUS_API", "https://api.wowcensus.io")
SECRETS = Path(os.environ.get("WOWCENSUS_SECRETS", Path.home() / "Documents" / "Projects" / "wowcensus-secrets" / "worker-secrets.env"))
SUPPORTED = {"anniversary", "classic", "classic_era", "forever", "retail"}
ADDON_FILES = {"auctionator": "Auctionator.lua", "journalator": "Journalator.lua",
               "tsm": "TradeSkillMaster.lua", "dungeonjournal": "ForeverDungeonJournal.lua"}
MAX_ZIP = 64 << 20
MAX_LUA = 256 << 20
MAX_RATIO = 60
MAX_MANIFEST = 64 << 10


def token():
    if os.environ.get("WOWCENSUS_INGEST_TOKEN"):
        return os.environ["WOWCENSUS_INGEST_TOKEN"]
    for line in SECRETS.read_text(encoding="utf-8").splitlines():
        if line.startswith("INGEST_TOKEN="):
            return line.split("=", 1)[1].strip()
    raise SystemExit(f"INGEST_TOKEN not found in {SECRETS}")


def call(method, path, body=None, raw=False):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(API + path, data=data, method=method, headers={
        "Authorization": "Bearer " + token(),
        "Content-Type": "application/json",
        "User-Agent": "wowcensus-importer",
    })
    with urllib.request.urlopen(req, timeout=300) as resp:
        payload = resp.read(MAX_ZIP + 1)
    if raw:
        return payload
    return json.loads(payload)


def ack(receipt_id, status, detail=None):
    call("POST", "/v1/ingest/ack", {"receiptId": receipt_id, "status": status, "detail": detail})


class BadBundle(ValueError):
    """An upload that can never be imported; the caller marks it failed and moves on."""


def read_member(zf, name, limit, ratio=None):
    info = zf.getinfo(name)
    if info.file_size > limit or (ratio and info.file_size > max(info.compress_size, 1) * ratio):
        raise BadBundle(f"{name} too large or compressed suspiciously")
    with zf.open(info) as fh:
        data = fh.read(limit + 1)
    if len(data) > limit or len(data) != info.file_size:
        raise BadBundle(f"{name} size does not match its header")
    return data


def check_manifest(manifest, rec):
    if not isinstance(manifest, dict):
        raise BadBundle("manifest is not a JSON object")
    for key, types in (("sha256", str), ("edition", str), ("kind", (str, type(None))),
                       ("mode", str), ("rows", (int, type(None)))):
        if key in manifest and (not isinstance(manifest[key], types) or isinstance(manifest[key], bool)):
            raise BadBundle(f"manifest field {key} has the wrong type")
    if manifest.get("sha256") != rec["sha256"] or manifest.get("edition") != rec["edition"]:
        raise BadBundle("manifest does not match the receipt")
    if (manifest.get("kind") or "wowcensus") != (rec.get("kind") or "wowcensus") or manifest.get("mode", "full") not in ("full", "session"):
        raise BadBundle("manifest kind or mode does not match the receipt")


def check_bundle(rec, blob, lua_name="WoWCensus.lua"):
    """Validate a downloaded bundle. Every problem with its contents raises BadBundle."""
    if len(blob) > MAX_ZIP:
        raise BadBundle("bundle too large")
    if hashlib.sha256(blob).hexdigest() != rec["zip_sha256"]:
        raise BadBundle("bundle SHA-256 does not match the receipt")
    try:
        with zipfile.ZipFile(io.BytesIO(blob)) as zf:
            names = sorted(zf.namelist())
            if names != sorted([lua_name, "manifest.json"]):
                raise BadBundle(f"unexpected bundle contents: {names[:5]}")
            manifest_bytes = read_member(zf, "manifest.json", MAX_MANIFEST)
            lua = read_member(zf, lua_name, MAX_LUA, MAX_RATIO)
        manifest = json.loads(manifest_bytes)
    except BadBundle:
        raise
    except (zipfile.BadZipFile, zipfile.LargeZipFile, json.JSONDecodeError, UnicodeDecodeError,
            NotImplementedError, RuntimeError, EOFError, OSError, ValueError, KeyError, RecursionError) as exc:
        raise BadBundle(f"unreadable bundle: {type(exc).__name__}: {exc}") from exc
    if hashlib.sha256(lua).hexdigest() != rec["sha256"]:
        raise BadBundle("Lua SHA-256 does not match the receipt")
    check_manifest(manifest, rec)
    if lua_name == "WoWCensus.lua" and not lua.lstrip().startswith(b"WoWCensusDB"):
        raise BadBundle("not a WoWCensus SavedVariables file")
    return lua, manifest


def pull(limit):
    pending = [r for r in call("GET", f"/v1/ingest/pending?kind=wowcensus&limit={limit}")["receipts"]
               if (r.get("kind") or "wowcensus") == "wowcensus"]
    if not pending:
        print("No uploads waiting.", flush=True)
        return None
    stage = DATA_ROOT / "companion-uploads" / datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S")
    staged = []
    for rec in pending:
        rid = rec["id"]
        if rec["edition"] not in SUPPORTED:
            ack(rid, "failed", f"game version {rec['edition']} is not imported")
            print(f"{rid} {rec['edition']}: unsupported game version, marked failed", flush=True)
            continue
        blob = call("GET", f"/v1/ingest/object/{rid}", raw=True)
        try:
            lua, manifest = check_bundle(rec, blob)
        except BadBundle as exc:
            ack(rid, "failed", str(exc)[:300])
            print(f"{rid} {rec['edition']}: rejected ({exc}), marked failed", flush=True)
            continue
        path = stage / "game" / f"_{rec['edition']}_" / "WTF" / "Account" / rid / "SavedVariables" / "WoWCensus.lua"
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(lua)
        mode = manifest.get("mode", "full")
        staged.append({"receiptId": rid, "edition": rec["edition"], "sha256": rec["sha256"], "mode": mode,
                       "rows": manifest.get("rows"), "client": rec.get("client_id"), "bytes": len(lua),
                       "uploaded": rec["created_at"]})
        print(f"{rid} {rec['edition']:12} {mode:7} {len(lua) / 1048576:6.2f} MB staged", flush=True)
    if not staged:
        return None
    (stage / "receipts.json").write_text(json.dumps(staged, indent=2), encoding="utf-8")
    clients = len({s["client"] for s in staged if s["client"]})
    print(f"stage={stage}  files={len(staged)}  clients={clients}", flush=True)
    return stage


def pull_addons(limit):
    pending = [r for r in call("GET", f"/v1/ingest/pending?kind=addon&limit={limit}")["receipts"]
               if r.get("kind") == "addon"]
    if not pending:
        print("No add-on uploads waiting.", flush=True)
        return None
    stage = DATA_ROOT / "companion-uploads" / ("addons-" + datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S"))
    for rec in pending:
        rid, addon = rec["id"], rec.get("addon")
        if addon not in ADDON_FILES:
            print(f"{rid}: unknown add-on {addon!r}, skipped", flush=True)
            continue
        blob = call("GET", f"/v1/ingest/object/{rid}", raw=True)
        try:
            lua, manifest = check_bundle(rec, blob, ADDON_FILES[addon])
        except BadBundle as exc:
            print(f"{rid} {addon}: rejected ({exc}), left queued", flush=True)
            continue
        out = stage / rec["edition"] / addon
        out.mkdir(parents=True, exist_ok=True)
        (out / f"{rid}.lua").write_bytes(lua)
        (out / f"{rid}.json").write_text(json.dumps({**manifest, "receiptId": rid, "uploaded": rec["created_at"]}, indent=2),
                                         encoding="utf-8")
        print(f"{rid} {rec['edition']:12} {addon:15} {manifest.get('mode', 'full'):7} {len(lua) / 1048576:6.2f} MB", flush=True)
    print(f"stage={stage} (left queued on the Worker)", flush=True)
    return stage


def ledger_saves(stage):
    """Staged WoWCensus.lua files with rows for tools/import-journalator-pg.py."""
    return [p for p in sorted((stage / "game").rglob("WoWCensus.lua"))
            if any(k in p.read_bytes() for k in (b'["auctionLog"]', b'["market"]'))]


def ack_stage(stage):
    if (stage / "receipts.json").exists():
        ids = [s["receiptId"] for s in json.loads((stage / "receipts.json").read_text(encoding="utf-8"))]
    else:
        # An add-on stage (pull-addons): each upload's manifest carries its receipt id.
        ids = [json.loads(m.read_text(encoding="utf-8"))["receiptId"] for m in sorted(stage.glob("*/*/*.json"))]
        if not ids:
            raise SystemExit(f"{stage}: no receipts.json and no add-on manifests")
    for rid in ids:
        ack(rid, "imported")
    print(f"Marked {len(ids)} upload(s) imported.", flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="cmd", required=True)
    p_run = sub.add_parser("run")
    p_run.add_argument("--target", choices=("both", "local"), default="both")
    p_run.add_argument("--limit", type=int, default=100)
    p_run.add_argument("--publish-now", action="store_true")
    p_pull = sub.add_parser("pull")
    p_pull.add_argument("--limit", type=int, default=100)
    p_ack = sub.add_parser("ack")
    p_ack.add_argument("stage", type=Path)
    p_addons = sub.add_parser("pull-addons")
    p_addons.add_argument("--limit", type=int, default=200)
    args = parser.parse_args()

    try:
        if args.cmd == "ack":
            ack_stage(args.stage)
            return
        if args.cmd == "pull-addons":
            pull_addons(args.limit)
            return
        stage = pull(args.limit)
        if args.cmd == "pull" or stage is None:
            return
        cmd = [sys.executable, "-B", str(REPO / "tools" / "import-live.py"), "saves",
               "--game-root", str(stage / "game"), "--target", args.target]
        if args.publish_now:
            cmd.append("--publish-now")
        print("running: " + " ".join(cmd[2:]), flush=True)
        result = subprocess.run(cmd)
        if result.returncode != 0:
            raise SystemExit(f"Import failed; nothing marked imported. Retry with: python -B tools/pull-companion-uploads.py run")
        ledgers = ledger_saves(stage)
        if ledgers:
            cmd = [sys.executable, "-B", str(REPO / "tools" / "import-live.py"), "journalator",
                   str(stage / "game"), "--target", args.target]
            if args.publish_now:
                cmd.append("--publish-now")
            print(f"{len(ledgers)} upload(s) carry auction or ledger rows; running: " + " ".join(cmd[2:]), flush=True)
            if subprocess.run(cmd).returncode != 0:
                raise SystemExit("Ledger import failed; the census rows are in, nothing marked imported. "
                                 "Retry with: python -B tools/pull-companion-uploads.py run")
        if args.target == "both":
            ack_stage(stage)
        else:
            print(f"Local import done. After the live import, mark them imported with:\n"
                  f"  python -B tools/pull-companion-uploads.py ack \"{stage}\"", flush=True)
    except urllib.error.HTTPError as exc:
        raise SystemExit(f"Upload API returned {exc.code}: {exc.read()[:300].decode(errors='replace')}")


if __name__ == "__main__":
    main()
