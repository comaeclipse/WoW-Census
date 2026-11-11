#!/usr/bin/env python3
"""Import WoW SavedVariables straight into the PostgreSQL database.

    python -B tools/import-savedvariables-pg.py
    python -B tools/import-savedvariables-pg.py --game-root=<dir> --schema=<schema>

PostgreSQL is the source of truth: each run adds only what is new. A save file
whose SHA-256 was already imported is skipped, and changed files go through the
import functions in tools/import-functions.py. Every changed file is archived
under MarketLensData/imports/<UTC time>/; local_source_files records its
hash, path and size (the contents live only in that archive).

The whole run is one transaction, so the PHP site switches from the old data
to the new in one step. The last line is
`CHANGED sources=<edition keys>` (empty when nothing was new).
"""

import argparse
import hashlib
import importlib.util
import json
import os
import re
import shutil
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import psycopg2
import psycopg2.extras

# The watcher captures stdout through a pipe. On Windows that pipe can default
# to cp1252 and fail when a realm name contains Chinese or other Unicode text.
if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="backslashreplace")
if sys.stderr and hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", errors="backslashreplace")

REPO = Path(__file__).resolve().parent.parent
GAME_ROOT = Path(os.environ.get("MARKETLENS_GAME_ROOT", r"C:\Program Files (x86)\World of Warcraft"))
DATA_ROOT = Path(os.environ.get("MARKETLENS_DATA_ROOT", Path.home() / "Documents" / "MarketLensData"))
DEFAULT_DSN = os.environ.get("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")
FLAVORS = ("_anniversary_", "_classic_", "_classic_beta_", "_classic_era_", "_forever_", "_retail_")
FOREVER_LAUNCH_UTC = datetime(2026, 11, 4, 23, 0, tzinfo=timezone.utc)

spec = importlib.util.spec_from_file_location("import_functions", REPO / "tools" / "import-functions.py")
legacy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(legacy)


def translate(sql):
    """Rewrite the import functions' statements (tools/import-functions.py) for PostgreSQL."""
    sql = sql.replace("%", "%%").replace("?", "%s")
    if re.search(r"INSERT\s+OR\s+IGNORE\s+INTO", sql):
        sql = re.sub(r"INSERT\s+OR\s+IGNORE\s+INTO", "INSERT INTO", sql).rstrip() + " ON CONFLICT DO NOTHING"
    # Two-argument MIN()/MAX() are PostgreSQL's LEAST()/GREATEST().
    out, i = [], 0
    for m in re.finditer(r"\b(MIN|MAX)\(", sql):
        start = m.end()
        depth, j, commas = 1, start, 0
        while depth:
            ch = sql[j]
            depth += ch == "("
            depth -= ch == ")"
            commas += ch == "," and depth == 1
            j += 1
        if commas:
            out.append(sql[i:m.start()] + ("LEAST(" if m.group(1) == "MIN" else "GREATEST("))
            i = start
    out.append(sql[i:])
    return "".join(out)


class PgDb:
    """The connection interface the import functions use (execute, executemany, fetch)."""

    # The import functions read only the rows a file can touch (see
    # inspect_candidates) instead of whole tables.
    narrow_reads = True

    def __init__(self, conn):
        self.conn = conn
        self.cache = {}

    def sql(self, statement):
        if statement not in self.cache:
            self.cache[statement] = translate(statement)
        return self.cache[statement]

    def execute(self, statement, params=()):
        cur = self.conn.cursor()
        cur.execute(self.sql(statement), tuple(params))
        return cur

    def executemany(self, statement, rows):
        rows = list(rows)
        cur = self.conn.cursor()
        if rows:
            psycopg2.extras.execute_batch(cur, self.sql(statement), rows, page_size=500)
        return cur

    # The legacy code commits per unit with `with db:`; this run is one transaction.
    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False


def sha256_bytes(data):
    return hashlib.sha256(data).hexdigest()


def stable_read(path):
    """Read a save file WoW is not writing at the same moment."""
    for _ in range(5):
        before = path.stat()
        data = path.read_bytes()
        after = path.stat()
        if (before.st_size, before.st_mtime_ns) == (after.st_size, after.st_mtime_ns) == (len(data), after.st_mtime_ns):
            return data, after
    raise RuntimeError(f"{path} kept changing while it was read; wait for the game to finish saving")


def run_node(*args):
    result = subprocess.run(["node", *map(str, args)], cwd=REPO, capture_output=True, check=False)
    if result.returncode:
        raise RuntimeError(result.stderr.decode("utf-8", errors="replace")[-1200:])
    return json.loads(result.stdout)


REGIONS = ("us", "eu", "kr", "tw", "cn")
# Every table the import functions write (ANALYZEd after a changed import).
IMPORT_TABLES = ("census_collection_realms", "character_inspects", "character_location_observations",
                 "character_observations", "characters", "collection_key_regions", "datasets", "history",
                 "inspect_talents", "items", "local_auction_snapshots", "local_imports", "local_source_files",
                 "pop_samples", "population_sweep_queries", "population_sweeps", "seller_listings", "sellers")


def population_times(body):
    """Every collection time in an exported population bucket (samples, sightings, sweeps)."""
    times = [int(s["t"]) for s in body.get("samples") or [] if s.get("t")]
    times += [int(t) for o in body.get("observations") or [] for t in o[3:5] if t]
    times += [int(s["startedAt"]) for s in body.get("sweeps") or [] if s.get("startedAt")]
    return times


def relabel_live_forever_inspects(db):
    """Move inspects the release client recorded as beta after launch to Forever.

    WoWCensus 0.1.7 tags the live release "classic-beta". The beta client is not
    imported after launch, so a post-launch beta inspect came from the release.
    Rows that already exist under Forever are left where they are.
    """
    launch = int(FOREVER_LAUNCH_UTC.timestamp())
    moving = """FROM character_inspects b WHERE b.source_game = 'classic-beta' AND b.captured_at >= %s
      AND NOT EXISTS (SELECT 1 FROM character_inspects f WHERE f.source_game = 'forever'
                      AND f.guid = b.guid AND f.captured_at = b.captured_at)"""
    if not db.execute("SELECT count(*) " + moving.replace("%s", "?"), (launch,)).fetchone()[0]:
        return 0
    fk = db.execute("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'inspect_talents_inspect_fk'").fetchone()
    if fk:
        db.execute("ALTER TABLE inspect_talents DROP CONSTRAINT inspect_talents_inspect_fk")
    db.execute("""UPDATE inspect_talents t SET source_game = 'forever'
      WHERE t.source_game = 'classic-beta' AND t.captured_at >= ? AND EXISTS (
        SELECT 1 """ + moving.replace("%s", "?") + " AND b.guid = t.guid AND b.captured_at = t.captured_at)",
      (launch, launch))
    moved = db.execute("""UPDATE character_inspects c SET source_game = 'forever'
      WHERE c.source_game = 'classic-beta' AND c.captured_at >= ? AND NOT EXISTS (
        SELECT 1 FROM character_inspects f WHERE f.source_game = 'forever'
        AND f.guid = c.guid AND f.captured_at = c.captured_at)""", (launch,)).rowcount
    if fk:
        db.execute("ALTER TABLE inspect_talents ADD CONSTRAINT inspect_talents_inspect_fk " + fk[0])
    return moved


def record_key_region(db, game, source, identity):
    """Store the region a MarketLens bucket recorded for its region-less key.

    Returns a warning when the key has now been seen from two regions; such
    keys are left out of collection_key_region() and stay unresolved."""
    identity = identity or {}
    region = identity.get("region")
    if region not in REGIONS or not identity.get("since"):
        return None
    conflicts = sorted(r for r in (identity.get("conflicts") or {}) if r in REGIONS and r != region)
    row = db.execute("SELECT region, conflicts FROM collection_key_regions WHERE game=? AND source_game=?",
                     (game, source)).fetchone()
    if row and row[0] != region:
        conflicts = sorted(set(conflicts) | {region})
        region = row[0]
    note = ",".join(conflicts) or None
    db.execute("""INSERT INTO collection_key_regions
      (game,source_game,region,since,region_id,realm_id,conflicts) VALUES (?,?,?,?,?,?,?)
      ON CONFLICT (game,source_game) DO UPDATE SET since=LEAST(collection_key_regions.since,excluded.since),
      region_id=excluded.region_id, realm_id=excluded.realm_id,
      conflicts=COALESCE(excluded.conflicts, collection_key_regions.conflicts), updated_at=now()""",
               (game, source, region, int(identity["since"]), identity.get("regionID"), identity.get("realmID"), note))
    if note:
        return f"{source} {game} was collected in {region.upper()} and also {note.upper()}; its rows stay unresolved until it is split"
    return None


def region_route(db, bucket, source, path, flavor):
    """The region-qualified bucket for a region-less MarketLens bucket recorded in a
    region other than the one its key is already pinned to; None otherwise."""
    if re.match(r"^(us|eu|kr|tw|cn):", bucket):
        return None
    row = db.execute("SELECT region FROM collection_key_regions WHERE game=? AND source_game=?",
                     ("realm:" + bucket, source)).fetchone()
    if not row:
        return None
    try:
        body = run_node(legacy.EXPORT_REALM, path, f"--realm={bucket}", "--population", f"--flavor={flavor}")
    except RuntimeError:
        return None
    identity = body.get("identity") or {}
    region, game = identity.get("region"), identity.get("game")
    if region not in REGIONS or region == row[0] or not game:
        return None
    # The qualified key uses WoWCensus's game names (classic-era, tbc-anniversary, ...).
    # MarketLens records Season of Discovery and Hardcore realms as "classic-era";
    # those take the game the importer routed the bucket to (sod, hardcore).
    if game == "classic-era" and source in ("sod", "hardcore"):
        game = source
    expected = {"classic": "classic-era", "classic-progression": "tbc-anniversary"}.get(source, source)
    if game != expected:
        raise RuntimeError(f"Refusing {bucket}: recorded game {identity.get('game')} conflicts with import source {source}")
    return f"{region}:{game}:{bucket}"


def write_key_region_function(db):
    """Regenerate collection_key_region() as a constant CASE from collection_key_regions."""
    groups = {}
    for game, region, since in db.execute(
            "SELECT game, min(region), min(since) FROM collection_key_regions GROUP BY game "
            "HAVING count(DISTINCT region) = 1 AND bool_and(conflicts IS NULL)").fetchall():
        groups.setdefault((region, since), []).append(game)
    cur = db.conn.cursor()
    whens = " ".join(cur.mogrify("WHEN collection_game = ANY(%s) AND observed_at >= %s THEN %s",
                                 (games, since, region)).decode()
                     for (region, since), games in sorted(groups.items()))
    body = f"SELECT CASE {whens} ELSE NULL END" if whens else "SELECT NULL::text"
    cur.execute(f"""CREATE OR REPLACE FUNCTION marketlens_import.collection_key_region(
      collection_game text, observed_at bigint) RETURNS text LANGUAGE sql IMMUTABLE AS $fn$ {body} $fn$""")


def main():
    started = time.perf_counter()
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--game-root", type=Path, default=GAME_ROOT, help="folder holding _classic_era_ and the other clients")
    parser.add_argument("--dsn", default=DEFAULT_DSN)
    parser.add_argument("--schema", default="marketlens_import")
    parser.add_argument("--archive", type=Path, default=DATA_ROOT / "imports", help="where changed save files are copied")
    parser.add_argument("--allow-beta-import", action="store_true", help="explicitly import additional beta saves after the Forever launch cutoff")
    args = parser.parse_args()

    conn = psycopg2.connect(args.dsn)
    conn.autocommit = False
    db = PgDb(conn)
    # One import at a time (a manual run and the watcher can otherwise overlap).
    lock = conn.cursor()
    lock.execute("SELECT pg_advisory_lock(hashtext('marketlens-import'))")
    conn.commit()
    db.execute(f'SET search_path TO "{args.schema}"')
    if args.schema == "marketlens_import":
        # Applied every run so edits to the region rules take effect without a
        # separate step; collection_key_region() is regenerated after the import.
        conn.cursor().execute((REPO / "tools" / "collection-region-provenance.sql").read_text(encoding="utf-8"))
    db.execute("""CREATE TABLE IF NOT EXISTS census_collection_realms (
      game TEXT PRIMARY KEY, source_game TEXT NOT NULL, region TEXT NOT NULL,
      region_id INTEGER, realm_id INTEGER, realm_name TEXT NOT NULL,
      realm_normalized TEXT, faction TEXT,
      UNIQUE (source_game, region, realm_id, faction)
    )""")
    # Keep the recorded beta/live game identity authoritative even if a future
    # importer accidentally labels a release save by its installation folder.
    source_tables = ("datasets", "characters", "character_observations",
                     "population_sweeps", "population_sweep_queries",
                     "character_location_observations", "census_collection_realms")
    for table in source_tables:
        constraint = f"{table}_forever_identity_check"
        db.execute(f"""DO $$ BEGIN
          IF NOT EXISTS (SELECT 1 FROM pg_constraint
                         WHERE conname = '{constraint}'
                           AND conrelid = '{args.schema}.{table}'::regclass) THEN
            ALTER TABLE {table} ADD CONSTRAINT {constraint}
            CHECK (CASE WHEN game ~ '^realm:(us|eu|kr|tw|cn):(classic-beta|forever):'
                        THEN source_game = split_part(game, ':', 3) ELSE true END);
          END IF;
        END $$""")
    db.execute("""DO $$ BEGIN
      IF NOT EXISTS (SELECT 1 FROM pg_constraint
                     WHERE conname = 'local_imports_forever_identity_check'
                       AND conrelid = 'local_imports'::regclass) THEN
        ALTER TABLE local_imports ADD CONSTRAINT local_imports_forever_identity_check
        CHECK (CASE WHEN bucket ~ '^(us|eu|kr|tw|cn):(classic-beta|forever):'
                    THEN source_game = split_part(bucket, ':', 2) ELSE true END);
      END IF;
    END $$""")
    # Only the hash is ever read back: the save itself is archived on disk below.
    db.execute("ALTER TABLE local_source_files ALTER COLUMN content DROP NOT NULL")
    imported = {row[0] for row in db.execute("SELECT sha256 FROM local_source_files")}
    run_dir = args.archive / datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S")
    report = {"startedAtUtc": datetime.now(timezone.utc).isoformat(), "schema": args.schema,
              "files": [], "buckets": [], "inspections": [], "errors": [],
              "timingSeconds": {"export": 0, "market": 0, "population": 0,
                                "sellers": 0, "inspections": 0, "realmRepair": 0, "analyze": 0}}
    changed_sources = set()

    # 1. Read every client's pair of save files; archive and record the new ones.
    clients = []
    recorded_files = set()
    for flavor in FLAVORS:
        if flavor == "_forever_" and datetime.now(timezone.utc) < FOREVER_LAUNCH_UTC:
            continue
        if flavor == "_classic_beta_" and datetime.now(timezone.utc) >= FOREVER_LAUNCH_UTC and not args.allow_beta_import:
            continue
        account_root = args.game_root / flavor / "WTF" / "Account"
        if not account_root.is_dir():
            continue
        save_pairs = [(market, market.with_name("NameplateInspect.lua"))
                      for market in account_root.glob("*/SavedVariables/MarketLens.lua")]
        save_pairs += [(census, census) for census in account_root.glob("*/SavedVariables/WoWCensus.lua")]
        for market, inspect in sorted(save_pairs):
            client = {"flavor": flavor}
            for kind, path in (("market", market), ("inspect", inspect)):
                if not path.is_file():
                    raise RuntimeError(f"Missing companion SavedVariables: {path}")
                data, stat = stable_read(path)
                digest = sha256_bytes(data)
                relative = path.relative_to(args.game_root)
                copy = run_dir / relative
                if digest not in imported:
                    copy.parent.mkdir(parents=True, exist_ok=True)
                    copy.write_bytes(data)
                    db.execute("INSERT INTO local_source_files VALUES (?,?,?,?,NULL) ON CONFLICT DO NOTHING", (
                        digest, relative.as_posix(),
                        datetime.fromtimestamp(stat.st_mtime, timezone.utc).isoformat(),
                        len(data)))
                client[kind] = {"path": copy, "sha256": digest, "new": digest not in imported, "relative": relative.as_posix()}
                if relative not in recorded_files:
                    report["files"].append({"file": relative.as_posix(), "sha256": digest, "new": digest not in imported})
                    recorded_files.add(relative)
            clients.append(client)
    if not clients:
        raise SystemExit(f"No MarketLens or WoWCensus SavedVariables found in {args.game_root}")

    # 2. Realm buckets from each new MarketLens.lua (the functions in import-functions.py).
    for client in clients:
        market = client["market"]
        if not market["new"]:
            continue
        for bucket in run_node(legacy.EXPORT_REALM, market["path"], "--list-realms"):
            flavor, source = legacy.source_for(client["flavor"], bucket)
            # The key stored in PostgreSQL. It differs from the save's bucket only
            # when a live Forever bucket was recorded as beta (see relabel below).
            # The import log names a relabelled bucket "eu:forever:X [recorded classic-beta]",
            # so an upgraded save holding both "eu:classic-beta:X" and "eu:forever:X"
            # imports both into eu:forever:X instead of skipping the second as done.
            db_bucket = log_bucket = bucket
            relabel_log = re.sub(r"^(us|eu|kr|tw|cn):classic-beta:", r"\1:forever:", bucket) + " [recorded classic-beta]"
            result = {"flavor": flavor, "source": source, "bucket": bucket}
            combined = market["relative"].endswith("/WoWCensus.lua")
            # MarketLens names buckets without a region ("Wild Growth-Alliance"), so the
            # same name from US and EU would share one key. Once a key is pinned to one
            # region, a bucket recorded in another region goes to the region-qualified
            # key WoWCensus uses ("eu:sod:Wild Growth-Alliance") instead of merging.
            routed = None if combined else region_route(db, bucket, source, market["path"], flavor)
            if routed:
                db_bucket = log_bucket = routed
                result.update(bucket=routed, recordedBucket=bucket)
                print(f"{flavor:18} {bucket}: key is pinned to another region; importing as {routed}", flush=True)
            for kind, extra, importer in (
                ("market", [], lambda body: legacy.import_market(db, body, market["sha256"], source)),
                ("population", ["--population", f"--flavor={flavor}"], lambda body: legacy.import_population(db, body, source)),
                ("sellers", ["--sellers"], lambda body: legacy.import_sellers(db, body, source)),
            ):
                if legacy.already(db, market["sha256"], kind, routed or bucket) or (
                        client["flavor"] == "_forever_" and legacy.already(db, market["sha256"], kind, relabel_log)):
                    result[kind] = "already imported"
                    continue
                try:
                    phase_started = time.perf_counter()
                    body = run_node(legacy.EXPORT_REALM, market["path"], f"--realm={bucket}", *extra)
                    report["timingSeconds"]["export"] += time.perf_counter() - phase_started
                except RuntimeError as exc:
                    report["timingSeconds"]["export"] += time.perf_counter() - phase_started
                    if "zero items" in str(exc) or "no seller data" in str(exc) or "zero data" in str(exc):
                        result[kind] = "no captured data"
                        continue
                    raise
                if kind == "population":
                    identity = body.get("identity") or {}
                    recorded_game = identity.get("game")
                    if client["flavor"] == "_forever_" and recorded_game not in ("classic-beta", "forever"):
                        raise RuntimeError(f"Refusing {bucket}: Forever save has no valid beta/live game identity")
                    if recorded_game in ("classic-beta", "forever") and source != recorded_game:
                        raise RuntimeError(
                            f"Refusing {bucket}: recorded game {recorded_game} conflicts with import source {source}"
                        )
                    match = re.match(r"^(us|eu|kr|tw|cn):([^:]+):", bucket)
                    if match and (identity.get("region") != match.group(1)
                                  or recorded_game != match.group(2)):
                        raise RuntimeError(f"Refusing {bucket}: collection identity does not match its bucket")
                    # WoWCensus 0.1.7 records the live Forever release as "classic-beta"
                    # (same build range as the beta). A release-client bucket whose data is
                    # all from after launch is live Forever; one that is all older stays beta.
                    if client["flavor"] == "_forever_" and recorded_game == "classic-beta" and match:
                        times = population_times(body)
                        launch = FOREVER_LAUNCH_UTC.timestamp()
                        if times and min(times) >= launch:
                            source = "forever"
                            db_bucket = re.sub(r"^(us|eu|kr|tw|cn):classic-beta:", r"\1:forever:", bucket)
                            log_bucket = relabel_log
                            result.update(source=source, bucket=db_bucket, recordedBucket=bucket)
                            print(f"{flavor:18} {bucket}: live Forever data recorded as beta; importing as {db_bucket}", flush=True)
                        elif times and max(times) >= launch:
                            raise RuntimeError(f"Refusing {bucket}: beta-labelled data spans the Forever launch; split it by hand")
                # Companion copies are named MarketLens.lua, so `combined` is false.
                # A relabelled live Forever bucket still has to be stored under the
                # new game key; this block compares the save's original bucket.
                if routed:
                    body["realm"] = routed
                    if kind == "population":
                        identity = body.get("identity") or {}
                        body["game"] = "realm:" + routed
                        db.execute("""INSERT INTO census_collection_realms
                          (game,source_game,region,region_id,realm_id,realm_name,realm_normalized,faction)
                          VALUES (?,?,?,?,?,?,?,?) ON CONFLICT (game) DO UPDATE SET
                          region_id=excluded.region_id,realm_id=excluded.realm_id""",
                          (body["game"], source, identity["region"], identity.get("regionID"), identity.get("realmID"),
                           identity.get("realmName"), None, identity.get("faction")))
                elif kind == "population" and (combined or db_bucket != bucket):
                    identity = body.get("identity") or {}
                    region = identity.get("region")
                    if region not in ("us", "eu", "kr", "tw", "cn"):
                        result[kind] = "skipped: collection region is unknown"
                        continue
                    # GetCurrentRegion() IDs; 2 can be Korea or Taiwan.
                    reported = {1: "us", 3: "eu", 4: "tw", 5: "cn"}.get(identity.get("regionID"))
                    if identity.get("game") == "retail" and reported and reported != region:
                        note = f"{source} {bucket} is labelled {region.upper()} but the client reported {reported.upper()} (region ID {identity['regionID']})"
                        report["errors"].append(note)
                        print(f"WARNING {note}", flush=True)
                    if identity.get("game") not in ("retail", "classic-era", "sod", "hardcore", "mop-classic", "tbc-anniversary", "classic-beta", "forever") or not identity.get("realmName"):
                        raise RuntimeError(f"Invalid WoWCensus collection identity in {bucket}")
                    expected = f'{region}:{identity["game"]}:{identity["realmName"]}-{identity.get("faction") or "Neutral"}'
                    if bucket != expected:
                        raise RuntimeError(f"WoWCensus bucket does not match collection identity: {bucket}")
                    body["game"] = "realm:" + db_bucket
                    db.execute("""INSERT INTO census_collection_realms
                      (game,source_game,region,region_id,realm_id,realm_name,realm_normalized,faction)
                      VALUES (?,?,?,?,?,?,?,?) ON CONFLICT (game) DO UPDATE SET
                      region_id=excluded.region_id,realm_id=excluded.realm_id,
                      realm_normalized=excluded.realm_normalized""",
                      (body["game"], source, region, identity.get("regionID"), identity.get("realmID"),
                       identity["realmName"], identity.get("realmNormalized"), identity.get("faction")))
                phase_started = time.perf_counter()
                details = importer(body)
                report["timingSeconds"][kind] += time.perf_counter() - phase_started
                if not combined and not routed and details.get("game"):
                    note = record_key_region(db, details["game"], source, body.get("identity"))
                    if note:
                        report["errors"].append(note)
                        print(f"WARNING {note}", flush=True)
                legacy.log_import(db, market["sha256"], kind, log_bucket, source, details)
                result[kind] = details
                changed_sources.add(source)
            report["buckets"].append(result)
            print(f"{flavor:18} {db_bucket}: imported", flush=True)

    # 3. Inspections. They link to retained characters, so they are redone when
    #    either of the client's files changed: the log key covers both hashes.
    for client in clients:
        if client["market"]["relative"].endswith("/WoWCensus.lua"):
            # Standalone WoWCensus saves have no NameplateInspectDB companion.
            continue
        if not client["market"]["new"] and not client["inspect"]["new"]:
            continue
        key = sha256_bytes((client["market"]["sha256"] + client["inspect"]["sha256"]).encode())
        if client["flavor"] == "_classic_era_":
            sources = ["classic", "sod", "hardcore"]
        elif client["flavor"] == "_forever_":
            # The release save may retain beta inspections alongside live ones.
            sources = ["classic-beta", "forever"]
        else:
            sources = [legacy.SOURCES[legacy.FLAVORS[client["flavor"]]]]
        path = client["inspect"]["path"] if client["inspect"]["new"] else client["market"]["path"].with_name("NameplateInspect.lua")
        if not path.is_file():
            # Only MarketLens.lua changed; archive the unchanged companion beside it for the exporter.
            data, _ = stable_read(args.game_root / client["inspect"]["relative"])
            if sha256_bytes(data) != client["inspect"]["sha256"]:
                raise RuntimeError(f"{client['inspect']['relative']} changed during the import; run it again")
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
        for source in sources:
            if legacy.already(db, key, "inspections", source):
                continue
            phase_started = time.perf_counter()
            body = run_node(legacy.EXPORT_INSPECTS, path, source)
            report["timingSeconds"]["export"] += time.perf_counter() - phase_started
            phase_started = time.perf_counter()
            details = legacy.import_inspects(db, body, source)
            report["timingSeconds"]["inspections"] += time.perf_counter() - phase_started
            legacy.log_import(db, key, "inspections", source, source, details)
            report["inspections"].append({"source": source, **details})
            changed_sources.add(source)
            print(f"inspections {source}: {details}", flush=True)
    if any(client["flavor"] == "_forever_" for client in clients):
        moved = relabel_live_forever_inspects(db)
        if moved:
            report["foreverInspectsRelabelled"] = moved
            changed_sources.add("forever")
            print(f"inspections forever: {moved} post-launch inspects recorded as beta moved to Forever", flush=True)

    if changed_sources:
        phase_started = time.perf_counter()
        report["realmNamesRepaired"] = legacy.repair_realm_names(db)
        report["timingSeconds"]["realmRepair"] += time.perf_counter() - phase_started
        for change, count in report["realmNamesRepaired"].items():
            print(f"realm repaired {change} ({count} rows)", flush=True)
    if args.schema == "marketlens_import":
        write_key_region_function(db)
    conn.commit()
    if args.schema == "marketlens_import":
        # Never fails the import: the data is already in; the check says which realms to fix.
        check = subprocess.run([sys.executable, "-B", str(REPO / "tools" / "check-collection-regions.py"), f"--dsn={args.dsn}"],
                               capture_output=True, text=True, encoding="utf-8")
        print(check.stdout.rstrip() or check.stderr.rstrip(), flush=True)
    if changed_sources:
        conn.autocommit = True
        phase_started = time.perf_counter()
        # Only the tables an import writes; a whole-database ANALYZE also rescans the
        # big census_* cache tables, which took most of a live import's time.
        for table in IMPORT_TABLES:
            db.execute(f"ANALYZE {table}")
        report["timingSeconds"]["analyze"] += time.perf_counter() - phase_started
        # Rebuild the pre-computed census tables for this import. Until it succeeds the
        # pages run the live census SQL (census_cache_fresh()), so a failure only costs speed.
        # The PHP reads laragon-php/src/config.php's database, so only for that one.
        if args.dsn == DEFAULT_DSN and args.schema == "marketlens_import":
            phase_started = time.perf_counter()
            php = os.environ.get("MARKETLENS_PHP", r"C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe")
            build = subprocess.run([php, "-d", "memory_limit=1G", str(REPO / "laragon-php" / "build-census-cache.php")],
                                   capture_output=True, text=True, encoding="utf-8")
            report["timingSeconds"]["censusCache"] = time.perf_counter() - phase_started
            if build.returncode == 0:
                print(build.stdout.strip().splitlines()[-1], flush=True)
            else:
                note = "census cache not rebuilt; pages use the live census SQL: " + (build.stderr or build.stdout).strip()[-500:]
                report["errors"].append(note)
                print(f"WARNING {note}", flush=True)
        report["timingSeconds"]["total"] = time.perf_counter() - started
        report["timingSeconds"] = {key: round(value, 2) for key, value in report["timingSeconds"].items()}
        run_dir.mkdir(parents=True, exist_ok=True)
        report["changedSources"] = sorted(changed_sources)
        (run_dir / "import-report.json").write_text(json.dumps(report, ensure_ascii=False, indent=2, default=str) + "\n", encoding="utf-8")
        print(f"report={run_dir / 'import-report.json'}", flush=True)
    conn.close()
    print("CHANGED sources=" + ",".join(sorted(changed_sources)), flush=True)


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"IMPORT FAILED, nothing was written: {exc}", file=sys.stderr, flush=True)
        raise SystemExit(1)
