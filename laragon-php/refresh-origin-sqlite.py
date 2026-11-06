#!/usr/bin/env python3
"""Refresh some editions' data in an existing compact origin SQLite snapshot.

    python -B laragon-php/refresh-origin-sqlite.py <base.sqlite> <capture-dir> <output.sqlite> --editions=forever,classic-beta

Capture queries for every route group into a *fresh* directory first, with full
page models only for the editions being refreshed (refresh-origin.py does this).
Collection aliases and region fixes can change SQL keys used by other editions,
so every captured query is upserted. The base must be the snapshot currently
deployed on the origin; the deploy wrapper verifies its hash.

For each refreshed edition the seven page models and every guild roster are
replaced; an edition's old rosters are removed first, so disbanded guilds go.
Other editions' models are copied unchanged. The admin collection-activity
model is always replaced. Old query keys stay until the next full rebuild.
"""

import argparse
import json
import os
import sqlite3
from pathlib import Path

# Edition (source key) -> capture route group (its top-level directory).
EDITION_GROUPS = {
    "forever": "forever", "classic-beta": "forever-beta", "classic-progression": "tbc",
    "classic": "classic", "sod": "sod", "hardcore": "hardcore", "mop-classic": "mop", "retail": "retail",
}
# Friendlier names on the command line.
ALIASES = {"forever-beta": "classic-beta", "tbc": "classic-progression", "era": "classic",
           "classic-era": "classic", "mop": "mop-classic"}
GROUPS = ("_root", ".well-known", "classic", "forever", "forever-beta", "hardcore", "mop", "retail", "sod", "tbc")
PAGE_MODELS = ("inspects", "inspect-summary", "talents", "gear", "class-profiles", "race-profiles", "auctionhouse")


def parse_editions(text):
    editions = []
    for name in (part.strip() for part in text.split(",")):
        if not name:
            continue
        edition = ALIASES.get(name, name)
        if edition not in EDITION_GROUPS:
            raise ValueError(f"Unknown edition {name!r}; use one of {', '.join(sorted(EDITION_GROUPS))}")
        if edition not in editions:
            editions.append(edition)
    if not editions:
        raise ValueError("No editions given")
    return editions


def captured_query(path):
    record = json.loads(path.read_text(encoding="utf-8"))
    if record.get("key") != path.stem or not isinstance(record.get("rows"), list):
        raise ValueError(f"Invalid query capture: {path}")
    sql = record["sql"].strip()
    # Same exclusions as build-sqlite-origin.py.
    if "character_inspects" in sql or sql == "SELECT COUNT(*) n FROM characters WHERE source_game=?":
        return None
    if sql.startswith("/* guild rosters */"):
        return None
    return record["key"], json.dumps(record["rows"], ensure_ascii=False, separators=(",", ":"))


def model_json(path, kind):
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, (dict, list)):
        raise ValueError(f"Invalid model: {path}")
    if kind == "inspects" and ("records" not in value or "coverage" not in value):
        raise ValueError(f"Invalid inspection model: {path}")
    if kind == "guild" and (not isinstance(value, dict) or "members" not in value):
        raise ValueError(f"Invalid guild roster: {path}")
    if kind == "admin" and (not isinstance(value, dict) or not isinstance(value.get("rows"), list)):
        raise ValueError(f"Invalid collection activity model: {path}")
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))


def guild_glob(edition):
    # "guild:classic-<id>" must not match "guild:classic-beta-<id>": ids are digits.
    return f"guild:{edition}-[0-9]*"


def refresh(base, capture, output, editions):
    for group in GROUPS:
        manifest = capture / "manifests" / f"{group}.json"
        if not manifest.is_file():
            raise ValueError(f"Missing completed capture group: {group}")
        record = json.loads(manifest.read_text(encoding="utf-8"))
        if record.get("group") != group or int(record.get("rendered", 0)) < 1:
            raise ValueError(f"Invalid capture group: {group}")
        home = next((e for e, g in EDITION_GROUPS.items() if g == group), None)
        if home in editions and record.get("queriesOnly"):
            raise ValueError(f"Capture group {group} has queries only, but {home} is being refreshed")
    queries = sorted(capture.glob("*.json"))
    models = capture / "models"
    fixed = {"admin:collection-activity": (models / "admin-collection-activity.json", "admin")}
    guilds = {}
    for edition in editions:
        fixed.update({f"{kind}:{edition}": (models / f"{kind}-{edition}.json", kind) for kind in PAGE_MODELS})
        # glob("guild-classic-*") would also match classic-beta and classic-progression.
        guilds[edition] = sorted(p for p in models.glob(f"guild-{edition}-*.json")
                                 if p.stem.removeprefix(f"guild-{edition}-").isdigit())
    missing = [str(path) for path, _ in fixed.values() if not path.is_file()]
    if not queries or missing:
        raise ValueError("Incomplete capture: " + ("no queries" if not queries else "missing " + ", ".join(missing)))
    if output.exists():
        raise FileExistsError(f"Refusing to overwrite {output}")
    output.parent.mkdir(parents=True, exist_ok=True)
    temp = output.with_name(output.name + ".building")
    if temp.exists():
        raise FileExistsError(f"Unfinished build exists: {temp}")
    source = sqlite3.connect(f"file:{base.as_posix()}?mode=ro", uri=True)
    target = sqlite3.connect(temp)
    try:
        source.backup(target)
        source.close()
        tables = {row[0] for row in target.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        if not {"query_results", "published_models"} <= tables:
            raise ValueError("Base is not a compact origin snapshot")
        before_queries = target.execute("SELECT COUNT(*) FROM query_results").fetchone()[0]
        before_models = target.execute("SELECT COUNT(*) FROM published_models").fetchone()[0]
        base_query_keys = {row[0] for row in target.execute("SELECT key FROM query_results")}
        refreshed_guild = " OR ".join("key GLOB ?" for _ in editions)
        globs = [guild_glob(e) for e in editions]
        base_kept_models = {row[0] for row in target.execute(
            f"SELECT key FROM published_models WHERE NOT ({refreshed_guild})", globs)}
        target.execute("BEGIN IMMEDIATE")
        changed_queries = 0
        for path in queries:
            item = captured_query(path)
            if item is None:
                continue
            target.execute("INSERT INTO query_results(key,rows) VALUES (?,?) "
                           "ON CONFLICT(key) DO UPDATE SET rows=excluded.rows", item)
            changed_queries += 1
        for key, (path, kind) in fixed.items():
            target.execute("INSERT INTO published_models(key,data) VALUES (?,?) "
                           "ON CONFLICT(key) DO UPDATE SET data=excluded.data", (key, model_json(path, kind)))
        guild_counts = {}
        for edition in editions:
            removed = target.execute("DELETE FROM published_models WHERE key GLOB ?", (guild_glob(edition),)).rowcount
            for path in guilds[edition]:
                target.execute("INSERT INTO published_models(key,data) VALUES (?,?)",
                               ("guild:" + path.stem.removeprefix("guild-"), model_json(path, "guild")))
            guild_counts[edition] = {"removed": removed, "inserted": len(guilds[edition])}
        target.commit()
        after_queries = target.execute("SELECT COUNT(*) FROM query_results").fetchone()[0]
        after_models = target.execute("SELECT COUNT(*) FROM published_models").fetchone()[0]
        if base_query_keys - {row[0] for row in target.execute("SELECT key FROM query_results")}:
            raise ValueError("Base query keys were lost")
        if base_kept_models - {row[0] for row in target.execute("SELECT key FROM published_models")}:
            raise ValueError("Models of editions not being refreshed were lost")
        integrity = target.execute("PRAGMA integrity_check").fetchone()[0]
        if integrity != "ok":
            raise ValueError(f"SQLite integrity: {integrity}")
        target.close()
        os.replace(temp, output)
        return {"editions": editions, "base_queries": before_queries, "queries": after_queries,
                "refreshed_queries": changed_queries, "base_models": before_models, "models": after_models,
                "guilds": guild_counts, "bytes": output.stat().st_size, "integrity": integrity}
    except BaseException:
        target.close()
        source.close()
        temp.unlink(missing_ok=True)
        raise


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("base", type=Path)
    parser.add_argument("capture", type=Path)
    parser.add_argument("output", type=Path)
    parser.add_argument("--editions", required=True, help="comma-separated, e.g. forever,classic-beta or tbc,mop")
    args = parser.parse_args()
    print(json.dumps(refresh(args.base, args.capture, args.output, parse_editions(args.editions))))
