#!/usr/bin/env python3
"""Merge an archived D1 snapshot and WoW SavedVariables into local SQLite.

Usage: python tools/build-local-database.py <phase-one snapshot directory>
The original D1 SQLite file and Lua copies are never modified. Reruns skip
already imported source hashes and preserve older observations.
"""

import hashlib
import json
import re
import shutil
import sqlite3
import subprocess
import sys
import unicodedata
from datetime import datetime, timezone
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
EXPORT_REALM = REPO / "tools" / "export-realm-from-savedvariables.js"
EXPORT_INSPECTS = REPO / "tools" / "export-local-inspects.mjs"
FLAVORS = {
    "_anniversary_": "tbc-anniversary",
    "_classic_": "mop-classic",
    "_classic_beta_": "classic-beta",
    "_classic_era_": "classic-era",
    "_retail_": "retail",
}
SOURCES = {
    "tbc-anniversary": "classic-progression",
    "mop-classic": "mop-classic",
    "classic-beta": "classic-beta",
    "classic-era": "classic",
    "sod": "sod",
    "retail": "retail",
}


def encode(value):
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))


def run_node(script, *args):
    result = subprocess.run(["node", str(script), *map(str, args)], cwd=REPO,
                            capture_output=True, check=False)
    if result.returncode:
        raise RuntimeError(result.stderr.decode("utf-8", errors="replace")[-1200:])
    return json.loads(result.stdout)


def sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def iso(t):
    return datetime.fromtimestamp(t, timezone.utc).isoformat().replace("+00:00", "Z")


def unix(value):
    if not value:
        return 0
    try:
        return int(datetime.fromisoformat(value.replace("Z", "+00:00")).timestamp())
    except ValueError:
        return 0


def slug(value):
    return re.sub(r"(^-|-$)", "", re.sub(r"[^a-z0-9]+", "-", value.lower()))


def character_key(value, source):
    return source + value[value.index(":"):] if ":" in value else value


def source_for(flavor, bucket):
    # SoD and Era use the same installation, but not the same game data.
    if flavor == "_classic_era_" and bucket.startswith("Wild Growth-"):
        return "sod", "sod"
    normalized = FLAVORS[flavor]
    return normalized, SOURCES[normalized]


def setup(db):
    db.executescript("""
    CREATE TABLE IF NOT EXISTS local_source_files (
      sha256 TEXT PRIMARY KEY, relative_path TEXT NOT NULL,
      source_modified_utc TEXT NOT NULL, bytes INTEGER NOT NULL,
      content BLOB NOT NULL
    );
    CREATE TABLE IF NOT EXISTS local_imports (
      source_sha256 TEXT NOT NULL, kind TEXT NOT NULL, bucket TEXT NOT NULL,
      source_game TEXT NOT NULL, imported_at_utc TEXT NOT NULL,
      details_json TEXT NOT NULL,
      PRIMARY KEY (source_sha256, kind, bucket)
    );
    CREATE TABLE IF NOT EXISTS local_auction_snapshots (
      source_game TEXT NOT NULL, game TEXT NOT NULL, item_id INTEGER NOT NULL,
      captured_at INTEGER NOT NULL, name TEXT, category TEXT, item_source TEXT,
      crafter TEXT, quantity INTEGER, auctions INTEGER, sellers INTEGER,
      lowest INTEGER, median INTEGER, weighted_average INTEGER,
      top_seller_concentration INTEGER, source_sha256 TEXT NOT NULL,
      PRIMARY KEY (source_game, game, item_id, captured_at)
    );
    CREATE INDEX IF NOT EXISTS idx_local_auction_game_time
      ON local_auction_snapshots(source_game, game, captured_at DESC);
    """)


def record_source(db, root, path, manifest):
    relative = path.relative_to(root / "savedvariables").as_posix()
    expected = manifest[relative.replace("/", "\\")]
    digest = sha256(path)
    if digest != expected["sha256"].lower() or path.stat().st_size != expected["bytes"]:
        raise RuntimeError(f"SavedVariables copy changed: {relative}")
    if not db.execute("SELECT 1 FROM local_source_files WHERE sha256=?", (digest,)).fetchone():
        db.execute("INSERT INTO local_source_files VALUES (?,?,?,?,?)", (
            digest, relative, expected["sourceLastWriteUtc"],
            expected["bytes"], sqlite3.Binary(path.read_bytes())))
    return digest


def already(db, digest, kind, bucket):
    return db.execute("SELECT 1 FROM local_imports WHERE source_sha256=? AND kind=? AND bucket=?",
                      (digest, kind, bucket)).fetchone() is not None


def log_import(db, digest, kind, bucket, source, details):
    db.execute("INSERT INTO local_imports VALUES (?,?,?,?,?,?)", (
        digest, kind, bucket, source, iso(datetime.now(timezone.utc).timestamp()),
        encode(details)))


def import_market(db, body, digest, source):
    realm = body["realm"]
    game = "realm:" + (re.sub(r"-(Alliance|Horde|Neutral)$", "", realm)
                       if source == "retail" else realm)
    item_data = body.get("items", {})
    latest_times = sorted(int(rec["s"][-1][0]) for rec in item_data.values()
                          if rec.get("s") and rec["s"][-1][0])
    anchor = latest_times[len(latest_times) // 2] if latest_times else 0
    cutoff = anchor - 48 * 3600
    reference = dict(db.execute("SELECT id,name FROM items WHERE game=?", (source,)))
    existing = {row[0]: row[1:] for row in db.execute(
        "SELECT id,updated_at,q,pq FROM items WHERE game=?", (game,))}
    exact = 0
    history = 0
    current = 0
    skipped_older = 0
    for raw_id, rec in item_data.items():
        item_id = int(raw_id)
        snapshots = rec.get("s") or []
        if not item_id or not snapshots:
            continue
        name = (rec.get("n") or "").strip() or reference.get(item_id) or f"item:{item_id}"
        category = rec.get("m") or None
        item_source = rec.get("src") or None
        crafter = rec.get("cr") or None
        daily = {}
        for point in snapshots:
            t = int(point[0] or 0)
            if t <= 0:
                continue
            values = list(point) + [0] * (8 - len(point))
            db.execute("""INSERT OR IGNORE INTO local_auction_snapshots VALUES
              (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""", (
                source, game, item_id, t, name, category, item_source, crafter,
                *values[1:8], digest))
            exact += 1
            day = int(datetime.fromtimestamp(t, timezone.utc).strftime("%Y%m%d"))
            if day not in daily or t > daily[day][0]:
                daily[day] = (t, values)
        prior = existing.get(item_id)
        for day, (t, point) in daily.items():
            unit = int(point[5] or point[6] or 0)
            row = (game, item_id, day, int(point[1] or 0) * unit,
                   unit, int(point[1] or 0))
            if not prior or t > unix(prior[0]):
                db.execute("""INSERT INTO history (game,id,ts,mv,asp,q)
                  VALUES (?,?,?,?,?,?) ON CONFLICT(game,id,ts) DO UPDATE SET
                  mv=excluded.mv,asp=excluded.asp,q=excluded.q""", row)
            else:
                db.execute("""INSERT OR IGNORE INTO history (game,id,ts,mv,asp,q)
                  VALUES (?,?,?,?,?,?)""", row)
            history += 1
        last = list(snapshots[-1]) + [0] * (8 - len(snapshots[-1]))
        t = int(last[0] or 0)
        if t < cutoff:
            continue
        if prior and t <= unix(prior[0]):
            skipped_older += 1
            continue
        unit = int(last[5] or last[6] or 0)
        quantity = int(last[1] or 0)
        pq = int(snapshots[-2][1] or 0) if len(snapshots) > 1 else (prior[2] if prior else None)
        sellers = int(last[3] or 0) if body.get("capabilities", {}).get("sellers") is not False else None
        top = int(last[7] or 0) if sellers is not None else None
        db.execute("""INSERT INTO items
          (game,id,name,slug,mv,asp,updated_at,q,pq,sc,tc,cat,src,crafter)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
          ON CONFLICT(game,id) DO UPDATE SET
          name=excluded.name,slug=excluded.slug,mv=excluded.mv,asp=excluded.asp,
          updated_at=excluded.updated_at,q=excluded.q,pq=excluded.pq,
          sc=excluded.sc,tc=excluded.tc,cat=excluded.cat,src=excluded.src,
          crafter=excluded.crafter""", (
            game, item_id, name, slug(name), quantity * unit, unit, iso(t),
            quantity, pq, sellers, top, category, item_source, crafter))
        current += 1
    if current:
        old = db.execute("SELECT updated_at FROM datasets WHERE game=?", (game,)).fetchone()
        if not old or anchor > unix(old[0]):
            db.execute("""INSERT INTO datasets (game,source_game,updated_at) VALUES (?,?,?)
              ON CONFLICT(game) DO UPDATE SET source_game=excluded.source_game,
              updated_at=excluded.updated_at""", (game, source, iso(anchor)))
    return dict(game=game, scanAnchor=anchor, items=len(item_data), exactSnapshots=exact,
                historyCandidates=history, currentItemsUpdated=current,
                olderCurrentItemsKept=skipped_older)


def import_population(db, body, source):
    game = "realm:" + body["realm"]
    details = {"game": game}
    samples = [(game, int(s["t"]), s.get("f") or None, int(s.get("o") or 0),
                int(s.get("tot") or s.get("o") or 0), s.get("flt") or "",
                encode(s.get("c") or {}), encode(s.get("r") or {}))
               for s in body.get("samples", []) if s.get("t")]
    db.executemany("""INSERT OR IGNORE INTO pop_samples
      (game,t,faction,observed,total,filter,classes,races) VALUES (?,?,?,?,?,?,?,?)""", samples)
    details["samples"] = len(samples)
    characters = []
    for c in body.get("characters", []):
        if not c.get("k") or not c.get("fs") or not c.get("ls"):
            continue
        characters.append((game, source, character_key(c["k"], source),
                           c.get("fn") or c.get("n") or c["k"], c.get("n") or "",
                           c.get("r") or "", c.get("g") or "", c.get("l") or 0,
                           c.get("race") or "", c.get("class") or "", c.get("cf") or "",
                           c.get("z") or "", c["fs"], c["ls"], c.get("sc") or 0))
    db.executemany("""INSERT INTO characters
      (game,source_game,character_key,full_name,name,realm,guild,level,race,class,
       class_file,zone,first_seen,last_seen,seen_count)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ON CONFLICT(game,source_game,character_key) DO UPDATE SET
      full_name=CASE WHEN excluded.last_seen>=characters.last_seen THEN excluded.full_name ELSE characters.full_name END,
      name=CASE WHEN excluded.last_seen>=characters.last_seen THEN excluded.name ELSE characters.name END,
      realm=CASE WHEN excluded.last_seen>=characters.last_seen THEN excluded.realm ELSE characters.realm END,
      guild=CASE WHEN excluded.last_seen>=characters.last_seen THEN excluded.guild ELSE characters.guild END,
      level=CASE WHEN excluded.last_seen>=characters.last_seen THEN excluded.level ELSE characters.level END,
      race=CASE WHEN excluded.last_seen>=characters.last_seen THEN excluded.race ELSE characters.race END,
      class=CASE WHEN excluded.last_seen>=characters.last_seen THEN excluded.class ELSE characters.class END,
      class_file=CASE WHEN excluded.last_seen>=characters.last_seen THEN excluded.class_file ELSE characters.class_file END,
      zone=CASE WHEN excluded.last_seen>=characters.last_seen THEN excluded.zone ELSE characters.zone END,
      first_seen=MIN(characters.first_seen,excluded.first_seen),
      last_seen=MAX(characters.last_seen,excluded.last_seen),
      seen_count=MAX(characters.seen_count,excluded.seen_count)""", characters)
    details["characters"] = len(characters)
    observations = [(game, source, character_key(o[0], source), o[1], o[2] or 0,
                     o[3] or 0, o[4] or 0) for o in body.get("observations", [])
                    if len(o) >= 5 and o[0] and o[1]]
    db.executemany("""INSERT INTO character_observations VALUES (?,?,?,?,?,?,?)
      ON CONFLICT(game,source_game,character_key,day) DO UPDATE SET
      sightings=MAX(character_observations.sightings,excluded.sightings),
      first_seen=MIN(character_observations.first_seen,excluded.first_seen),
      last_seen=MAX(character_observations.last_seen,excluded.last_seen)""", observations)
    details["observations"] = len(observations)
    sweeps = [(game, source, str(s["id"]), s["startedAt"], s.get("completedAt") or None,
               s["status"], s.get("label") or "", s.get("faction") or "",
               s.get("queryCount") or 0, s.get("characterCount") or 0,
               s.get("cappedCount") or 0) for s in body.get("sweeps", [])
              if s.get("id") and s.get("startedAt") and s.get("status") in
              ("active", "complete", "partial")]
    db.executemany("""INSERT INTO population_sweeps VALUES (?,?,?,?,?,?,?,?,?,?,?)
      ON CONFLICT(game,source_game,sweep_id) DO UPDATE SET
      started_at=MIN(population_sweeps.started_at,excluded.started_at),
      completed_at=MAX(COALESCE(population_sweeps.completed_at,0),COALESCE(excluded.completed_at,0)),
      status=CASE WHEN population_sweeps.status IN ('complete','partial') THEN population_sweeps.status ELSE excluded.status END,
      label=excluded.label,faction=excluded.faction,
      query_count=MAX(population_sweeps.query_count,excluded.query_count),
      character_count=MAX(population_sweeps.character_count,excluded.character_count),
      capped_count=MAX(population_sweeps.capped_count,excluded.capped_count)""", sweeps)
    details["sweeps"] = len(sweeps)
    queries = [(game, source, str(q[0]), q[1], q[2], q[3] or "", q[4] or 0,
                q[5] or q[4] or 0, int(q[6] is True))
               for q in body.get("sweepQueries", []) if len(q) >= 7 and q[0] and q[1] and q[2]]
    db.executemany("""INSERT OR IGNORE INTO population_sweep_queries
      VALUES (?,?,?,?,?,?,?,?,?)""", queries)
    details["sweepQueries"] = len(queries)
    locations = [(game, source, str(o[0]), character_key(o[1], source), o[2] or 0,
                  o[3], o[4] or "", o[5] or 0, o[6] or "", o[7] or "")
                 for o in body.get("locationObservations", [])
                 if len(o) >= 8 and o[0] and o[1] and o[3]]
    db.executemany("""INSERT OR IGNORE INTO character_location_observations
      VALUES (?,?,?,?,?,?,?,?,?,?)""", locations)
    details["locationObservations"] = len(locations)
    latest = max((c["ls"] for c in body.get("characters", []) if c.get("ls")), default=0)
    previous = db.execute("SELECT updated_at FROM datasets WHERE game=?", (game,)).fetchone()
    if (latest and (not previous or latest > unix(previous[0]))) or not previous:
        db.execute("""INSERT INTO datasets (game,source_game,updated_at) VALUES (?,?,?)
          ON CONFLICT(game) DO UPDATE SET source_game=excluded.source_game,
          updated_at=excluded.updated_at""", (game, source, iso(latest) if latest else None))
    return details


def import_sellers(db, body, source):
    realm = body["realm"]
    game = "realm:" + (re.sub(r"-(Alliance|Horde|Neutral)$", "", realm)
                       if source == "retail" else realm)
    count = listings = 0
    latest = 0
    for seller in body.get("sellers", []):
        owner = seller.get("o")
        points = seller.get("L") or []
        if not owner or not points:
            continue
        first, last = int(seller.get("fs") or 0), int(seller.get("ls") or 0)
        latest = max(latest, last)
        qty = sum(int(p[1] or 0) for p in points)
        value = sum(int(p[1] or 0) * int(p[2] or 0) for p in points)
        db.execute("""INSERT INTO sellers
          (game,owner,slug,first_seen,last_seen,seen_count,items,qty,value,hist)
          VALUES (?,?,?,?,?,?,?,?,?,?)
          ON CONFLICT(game,owner) DO UPDATE SET
          first_seen=MIN(sellers.first_seen,excluded.first_seen),
          last_seen=MAX(sellers.last_seen,excluded.last_seen),
          seen_count=MAX(sellers.seen_count,excluded.seen_count),
          items=CASE WHEN excluded.last_seen>sellers.last_seen THEN excluded.items ELSE sellers.items END,
          qty=CASE WHEN excluded.last_seen>sellers.last_seen THEN excluded.qty ELSE sellers.qty END,
          value=CASE WHEN excluded.last_seen>sellers.last_seen THEN excluded.value ELSE sellers.value END,
          hist=CASE WHEN excluded.last_seen>sellers.last_seen THEN excluded.hist ELSE sellers.hist END""",
                   (game, owner, slug(owner), first, last, seller.get("sc") or 0,
                    len(points), qty, value, encode(seller.get("h") or [])))
        count += 1
        for item_id, amount, price in points:
            db.execute("""INSERT INTO seller_listings (game,owner,id,q,l,last_seen)
              VALUES (?,?,?,?,?,?) ON CONFLICT(game,owner,id) DO UPDATE SET
              q=CASE WHEN excluded.last_seen>COALESCE(seller_listings.last_seen,0) THEN excluded.q ELSE seller_listings.q END,
              l=CASE WHEN excluded.last_seen>COALESCE(seller_listings.last_seen,0) THEN excluded.l ELSE seller_listings.l END,
              last_seen=MAX(COALESCE(seller_listings.last_seen,0),excluded.last_seen)""",
                       (game, owner, item_id, amount, price, last or body.get("exportedAt") or 0))
            listings += 1
    previous = db.execute("SELECT seller_updated_at FROM datasets WHERE game=?", (game,)).fetchone()
    if count and (not previous or latest > unix(previous[0])):
        db.execute("""INSERT INTO datasets (game,source_game,seller_updated_at,seller_meta)
          VALUES (?,?,?,?) ON CONFLICT(game) DO UPDATE SET
          source_game=excluded.source_game,seller_updated_at=excluded.seller_updated_at,
          seller_meta=excluded.seller_meta""",
                   (game, source, iso(latest), encode(body.get("meta") or {})))
    return {"game": game, "sellers": count, "listings": listings, "latestSellerSeen": latest}


def norm(value):
    return " ".join(unicodedata.normalize("NFC", str(value or "")).casefold().split())


def import_inspects(db, body, source):
    by_name_class = {}
    for row in db.execute("SELECT game,character_key,name,realm,race,class_file FROM characters WHERE source_game=?", (source,)):
        by_name_class.setdefault((norm(row[2]), row[5]), []).append(row)
    added = nodes = linked = unmatched = ambiguous = 0
    for pair in body["records"]:
        raw, e = pair["raw"], pair["normalized"]
        candidates = [c for c in by_name_class.get((norm(e["name"]), e["class"]), [])
                      if (not e.get("race") or norm(c[4]) == norm(e["race"]))
                      and (not e.get("realm") or norm(c[3]) == norm(e["realm"]))
                      and (not e.get("faction") or c[0].endswith("-" + e["faction"]))]
        if len(candidates) == 1:
            match_game, match_key = candidates[0][:2]
            status = "context-name" if e["schemaVersion"] >= 2 else "legacy-name"
            linked += 1
        else:
            match_game = match_key = None
            status = "ambiguous" if candidates else "unmatched"
            ambiguous += status == "ambiguous"
            unmatched += status == "unmatched"
        values = (source, e["guid"], e["time"], e["rawName"], e["name"],
                  e.get("realm") or None, e.get("faction") or None,
                  e.get("observerRealm") or None, e.get("observerFaction") or None,
                  e.get("observerZone") or None, e["class"], e.get("race"),
                  e.get("level"), e.get("guild"), e.get("spec"), e.get("role"),
                  e.get("talents"), e["schemaVersion"], e.get("clientBuild"),
                  e.get("locale"), match_game, match_key, status, "NameplateInspect",
                  encode(e["list"]), encode(raw), encode(e["gear"]) if e["gear"] else None)
        db.execute("""INSERT INTO character_inspects
          (source_game,guid,captured_at,raw_name,name,realm,faction,observer_realm,
           observer_faction,observer_zone,class_file,race,level,guild,raw_spec_id,
           raw_role,import_string,schema_version,client_build,locale,game,
           character_key,match_status,collector,nodes_json,raw_json,gear_json)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
          ON CONFLICT(source_game,guid,captured_at) DO UPDATE SET
          raw_name=excluded.raw_name,name=excluded.name,game=excluded.game,
          character_key=excluded.character_key,match_status=excluded.match_status,
          gear_json=COALESCE(excluded.gear_json,character_inspects.gear_json)
          WHERE excluded.schema_version>=character_inspects.schema_version""", values)
        added += 1
        for talent in e["list"]:
            db.execute("""INSERT OR IGNORE INTO inspect_talents
              (source_game,guid,captured_at,node_id,tree_id,spell_id,name,rank,max_rank,
               x,y,entry_id,definition_id,groups_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                       (source, e["guid"], e["time"], talent["node"], talent.get("tree"),
                        talent.get("spell"), talent["name"], talent["rank"], talent["max"],
                        talent.get("x"), talent.get("y"), talent.get("entry"),
                        talent.get("definition"), encode(talent.get("groups") or [])))
            nodes += 1
    return {"selected": body["selected"], "rejected": body["rejected"],
            "valid": added, "talentNodes": nodes, "linked": linked,
            "unmatched": unmatched, "ambiguous": ambiguous}


def main():
    if len(sys.argv) != 2:
        raise SystemExit(__doc__)
    root = Path(sys.argv[1]).resolve()
    base = root / "d1" / "marketlens.sqlite"
    target = root / "marketlens-unified.sqlite"
    if not base.is_file():
        raise SystemExit(f"Missing verified D1 SQLite snapshot: {base}")
    if not target.exists():
        shutil.copy2(base, target)
    db = sqlite3.connect(target)
    db.execute("PRAGMA journal_mode=WAL")
    setup(db)
    files = json.loads((root / "savedvariables-manifest.json").read_text(encoding="utf-8-sig"))
    manifest = {item["relativePath"]: item for item in files}
    realm_inventory = json.loads((root / "savedvariables-realms.json").read_text(encoding="utf-8-sig"))
    report = {"database": str(target), "sources": [], "buckets": [], "inspections": [],
              "errors": []}
    for item in files:
        path = root / "savedvariables" / item["relativePath"]
        with db:
            digest = record_source(db, root, path, manifest)
        report["sources"].append({"file": item["relativePath"], "sha256": digest})
    for entry in realm_inventory:
        path = root / "savedvariables" / entry["file"]
        digest = sha256(path)
        for bucket in entry["realms"]:
            flavor, source = source_for(entry["flavor"], bucket)
            result = {"flavor": flavor, "source": source, "bucket": bucket}
            for kind, extra, importer in (
                ("market", [], lambda body: import_market(db, body, digest, source)),
                ("population", ["--population", f"--flavor={flavor}"],
                 lambda body: import_population(db, body, source)),
                ("sellers", ["--sellers"], lambda body: import_sellers(db, body, source)),
            ):
                if already(db, digest, kind, bucket):
                    result[kind] = "already imported"
                    continue
                try:
                    body = run_node(EXPORT_REALM, path, f"--realm={bucket}", *extra)
                    with db:
                        details = importer(body)
                        log_import(db, digest, kind, bucket, source, details)
                    result[kind] = details
                except RuntimeError as exc:
                    # The source helper uses nonzero exit for empty AH/seller/pop data.
                    if "zero items" in str(exc) or "no seller data" in str(exc) or "zero data" in str(exc):
                        result[kind] = "no captured data"
                    else:
                        report["errors"].append({"bucket": bucket, "kind": kind, "error": str(exc)})
                        result[kind] = "ERROR"
                except Exception as exc:
                    report["errors"].append({"bucket": bucket, "kind": kind, "error": str(exc)})
                    result[kind] = "ERROR"
            report["buckets"].append(result)
            print(f"{flavor:18} {bucket}: market={result['market'] != 'ERROR'} "
                  f"population={result['population'] != 'ERROR'} sellers={result['sellers'] != 'ERROR'}",
                  flush=True)
    for entry in realm_inventory:
        flavor = entry["flavor"]
        sources = ["classic", "sod"] if flavor == "_classic_era_" else [SOURCES[FLAVORS[flavor]]]
        path = (root / "savedvariables" / entry["file"]).with_name("NameplateInspect.lua")
        digest = sha256(path)
        for source in sources:
            if already(db, digest, "inspections", source):
                continue
            try:
                body = run_node(EXPORT_INSPECTS, path, source)
                with db:
                    details = import_inspects(db, body, source)
                    log_import(db, digest, "inspections", source, source, details)
                report["inspections"].append({"source": source, **details})
                print(f"inspections {source}: {details}", flush=True)
            except Exception as exc:
                report["errors"].append({"source": source, "kind": "inspections", "error": str(exc)})
    report["integrityCheck"] = db.execute("PRAGMA integrity_check").fetchone()[0]
    report["tables"] = {name: db.execute(f'SELECT COUNT(*) FROM "{name}"').fetchone()[0]
                        for (name,) in db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")}
    db.execute("PRAGMA wal_checkpoint(TRUNCATE)")
    db.close()
    (root / "phase-two-report.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"integrity={report['integrityCheck']} errors={len(report['errors'])} db={target}")
    if report["errors"] or report["integrityCheck"] != "ok":
        raise SystemExit(1)


if __name__ == "__main__":
    main()
