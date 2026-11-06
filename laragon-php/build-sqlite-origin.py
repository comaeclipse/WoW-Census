import json
import sqlite3
import sys
from pathlib import Path

capture = Path(sys.argv[1])
output = Path(sys.argv[2])
if output.exists():
    raise SystemExit(f"Refusing to overwrite {output}")
records = sorted(capture.glob("*.json"))
models = sorted((capture / "models").glob("inspects-*.json"))
# Pages that read only their share of the inspection and auction data, published on their own.
PAGE_MODELS = ("inspect-summary", "talents", "gear", "class-profiles", "race-profiles", "auctionhouse")
page_models = {kind: sorted((capture / "models").glob(kind + "-*.json")) for kind in PAGE_MODELS}
# One roster per realm guild, for the /guilds/<id>/ pages.
guild_models = sorted((capture / "models").glob("guild-*.json"))
if not records or len(models) != 8 or any(len(paths) != 8 for paths in page_models.values()):
    raise SystemExit(f"Incomplete capture: {len(records)} queries, {len(models)} inspection models, "
                     + ", ".join(f"{len(paths)} {kind} models" for kind, paths in page_models.items()))
if not guild_models:
    raise SystemExit("Incomplete capture: no guild roster models")
# Collection status for /admin/, written by the root route group's capture.
activity_model = capture / "models" / "admin-collection-activity.json"
if not activity_model.is_file():
    raise SystemExit("Incomplete capture: no admin-collection-activity model")

db = sqlite3.connect(output)
try:
    db.execute("CREATE TABLE query_results (key TEXT PRIMARY KEY, rows TEXT NOT NULL)")
    db.execute("CREATE TABLE published_models (key TEXT PRIMARY KEY, data TEXT NOT NULL)")
    queries = 0
    for path in records:
        record = json.loads(path.read_text(encoding="utf-8"))
        if record["key"] != path.stem or not isinstance(record["rows"], list):
            raise ValueError(f"Invalid record: {path}")
        sql = record["sql"].strip()
        if "character_inspects" in sql or sql == "SELECT COUNT(*) n FROM characters WHERE source_game=?":
            continue
        # Every guilded character of an edition: published per guild as roster models instead.
        if sql.startswith("/* guild rosters */"):
            continue
        db.execute("INSERT INTO query_results VALUES (?, ?)", (record["key"], json.dumps(record["rows"], ensure_ascii=False, separators=(",", ":"))))
        queries += 1
    for path in models:
        model = json.loads(path.read_text(encoding="utf-8"))
        if "records" not in model or "coverage" not in model:
            raise ValueError(f"Invalid model: {path}")
        key = "inspects:" + path.stem.removeprefix("inspects-")
        db.execute("INSERT INTO published_models VALUES (?, ?)", (key, json.dumps(model, ensure_ascii=False, separators=(",", ":"))))
    for kind, paths in page_models.items():
        for path in paths:
            model = json.loads(path.read_text(encoding="utf-8"))
            if not isinstance(model, (dict, list)):
                raise ValueError(f"Invalid {kind} model: {path}")
            key = kind + ":" + path.stem.removeprefix(kind + "-")
            db.execute("INSERT INTO published_models VALUES (?, ?)", (key, json.dumps(model, ensure_ascii=False, separators=(",", ":"))))
    for path in guild_models:
        model = json.loads(path.read_text(encoding="utf-8"))
        if not isinstance(model, dict) or "members" not in model:
            raise ValueError(f"Invalid guild roster model: {path}")
        db.execute("INSERT INTO published_models VALUES (?, ?)", ("guild:" + path.stem.removeprefix("guild-"), json.dumps(model, ensure_ascii=False, separators=(",", ":"))))
    activity = json.loads(activity_model.read_text(encoding="utf-8"))
    if not isinstance(activity, dict) or not isinstance(activity.get("rows"), list):
        raise ValueError(f"Invalid collection activity model: {activity_model}")
    db.execute("INSERT INTO published_models VALUES (?, ?)", ("admin:collection-activity", json.dumps(activity, ensure_ascii=False, separators=(",", ":"))))
    db.commit()
    db.execute("PRAGMA optimize")
    integrity = db.execute("PRAGMA integrity_check").fetchone()[0]
    if integrity != "ok":
        raise ValueError(integrity)
    print(json.dumps({"queries": queries, "models": len(models) + sum(map(len, page_models.values())), "guild_models": len(guild_models), "bytes": output.stat().st_size, "integrity": integrity}))
finally:
    db.close()
