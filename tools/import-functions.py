#!/usr/bin/env python3
"""Import functions shared by the PostgreSQL importers. Not a command.

tools/import-savedvariables-pg.py, tools/import-forever-ah-csv-pg.py and the
repair scripts load this file and call its functions with a database adapter
(PgDb in import-savedvariables-pg.py). The statements here use `?` placeholders,
INSERT OR IGNORE and two-argument MIN()/MAX(); the adapter's translate()
rewrites those for PostgreSQL.
"""

import hashlib
import json
import re
import subprocess
import unicodedata
from datetime import datetime, timezone
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
EXPORT_REALM = REPO / "tools" / "export-realm-from-savedvariables.js"
EXPORT_INSPECTS = REPO / "tools" / "export-local-inspects.mjs"
FLAVORS = {
    "_forever_": "forever",
    "_anniversary_": "tbc-anniversary",
    "_classic_": "mop-classic",
    "_classic_beta_": "classic-beta",
    "_classic_era_": "classic-era",
    "_retail_": "retail",
}
SOURCES = {
    "forever": "forever",
    "tbc-anniversary": "classic-progression",
    "mop-classic": "mop-classic",
    "classic-beta": "classic-beta",
    "classic-era": "classic",
    "sod": "sod",
    "hardcore": "hardcore",
    "retail": "retail",
}
# Old MarketLens buckets from the Classic Era installation carry no edition:
# these realms are Season of Discovery or Hardcore, not Classic Era.
ERA_SEASON_REALMS = {
    "Wild Growth": "sod", "Crusader Strike": "sod", "Lone Wolf": "sod", "Living Flame": "sod",
    "Doomhowl": "hardcore", "Soulseeker": "hardcore",
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
    # Saves key characters "<flavor>:<realm>:<name>"; WoWCensus puts the region
    # in front ("us:mop-classic:..."). The game key already holds the region, so
    # both become "<source>:<realm>:<name>". The realm slug drops hyphens: the
    # addon writes "ra-den" for its own realm but "raden" from "Name-Raden".
    parts = re.sub(r"^(?:us|eu|kr|tw|cn):", "", value).split(":", 2)
    if len(parts) < 3:
        return value
    return f"{source}:{parts[1].replace('-', '')}:{parts[2]}"


def source_for(flavor, bucket):
    # A release-client save can still carry retained beta realm buckets.
    # Route those by their recorded collection identity, not the install path.
    if flavor == "_forever_" and re.match(r"^(?:us|eu|kr|tw|cn):classic-beta:", bucket):
        return "classic-beta", SOURCES["classic-beta"]
    if flavor == "_forever_" and re.match(r"^(?:us|eu|kr|tw|cn):", bucket) and not re.match(
        r"^(?:us|eu|kr|tw|cn):forever:", bucket
    ):
        raise ValueError(f"Unexpected game identity in Forever save: {bucket}")
    # SoD and Era use the same installation, but not the same game data.
    if flavor == "_classic_era_":
        match = re.match(r"^(?:us|eu|kr|tw|cn):(sod|hardcore|classic-era):", bucket)
        if match:
            game = match.group(1)
            return game, SOURCES[game]
    realm_bucket = re.sub(r"^(?:us|eu|kr|tw|cn):[^:]+:", "", bucket)
    if flavor == "_classic_era_":
        game = ERA_SEASON_REALMS.get(re.sub(r"-(Alliance|Horde|Neutral)$", "", realm_bucket))
        if game:
            return game, SOURCES[game]
    normalized = FLAVORS[flavor]
    return normalized, SOURCES[normalized]


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
    snapshot_sql = """INSERT OR IGNORE INTO local_auction_snapshots VALUES
      (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"""
    snapshot_batch = []
    history_newer, history_older, item_rows = [], [], []
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
            snapshot_batch.append((
                source, game, item_id, t, name, category, item_source, crafter,
                *values[1:8], digest))
            if len(snapshot_batch) >= 2000:
                db.executemany(snapshot_sql, snapshot_batch)
                snapshot_batch.clear()
            exact += 1
            day = int(datetime.fromtimestamp(t, timezone.utc).strftime("%Y%m%d"))
            if day not in daily or t > daily[day][0]:
                daily[day] = (t, values)
        prior = existing.get(item_id)
        for day, (t, point) in daily.items():
            unit = int(point[5] or point[6] or 0)
            row = (game, item_id, day, int(point[1] or 0) * unit,
                   unit, int(point[1] or 0))
            (history_newer if not prior or t > unix(prior[0]) else history_older).append(row)
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
        # prior is (updated_at, q, pq): a one-point scan (every Forever AH CSV) compares with the row's q.
        pq = int(snapshots[-2][1] or 0) if len(snapshots) > 1 else (prior[1] if prior else None)
        sellers = int(last[3] or 0) if body.get("capabilities", {}).get("sellers") is not False else None
        top = int(last[7] or 0) if sellers is not None else None
        item_rows.append((
            game, item_id, name, slug(name), quantity * unit, unit, iso(t),
            quantity, pq, sellers, top, category, item_source, crafter))
        current += 1
    if snapshot_batch:
        db.executemany(snapshot_sql, snapshot_batch)
    # Batched rather than one statement per item, so a remote database (the live
    # import over an SSH tunnel) costs a few round trips, not thousands. Every
    # (game,id,ts) and (game,id) row is unique within one scan, so order is irrelevant.
    db.executemany("""INSERT INTO history (game,id,ts,mv,asp,q)
      VALUES (?,?,?,?,?,?) ON CONFLICT(game,id,ts) DO UPDATE SET
      mv=excluded.mv,asp=excluded.asp,q=excluded.q""", history_newer)
    db.executemany("""INSERT OR IGNORE INTO history (game,id,ts,mv,asp,q)
      VALUES (?,?,?,?,?,?)""", history_older)
    db.executemany("""INSERT INTO items
      (game,id,name,slug,mv,asp,updated_at,q,pq,sc,tc,cat,src,crafter)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ON CONFLICT(game,id) DO UPDATE SET
      name=excluded.name,slug=excluded.slug,mv=excluded.mv,asp=excluded.asp,
      updated_at=excluded.updated_at,q=excluded.q,pq=excluded.pq,
      sc=excluded.sc,tc=excluded.tc,cat=excluded.cat,src=excluded.src,
      crafter=excluded.crafter""", item_rows)
    stale_current_removed = 0
    if current:
        # A fresh AH import supersedes current rows left by older scans. Keep
        # their observations in history, but do not render them as live items.
        stale_current_removed = db.execute(
            "DELETE FROM items WHERE game=? AND updated_at<?", (game, iso(cutoff))
        ).rowcount
        old = db.execute("SELECT updated_at FROM datasets WHERE game=?", (game,)).fetchone()
        if not old or anchor > unix(old[0]):
            db.execute("""INSERT INTO datasets (game,source_game,updated_at) VALUES (?,?,?)
              ON CONFLICT(game) DO UPDATE SET source_game=excluded.source_game,
              updated_at=excluded.updated_at""", (game, source, iso(anchor)))
    return dict(game=game, scanAnchor=anchor, items=len(item_data), exactSnapshots=exact,
                historyCandidates=history, currentItemsUpdated=current,
                 olderCurrentItemsKept=skipped_older,
                 staleCurrentItemsRemoved=stale_current_removed)


def import_population(db, body, source):
    game = body.get("game") or "realm:" + body["realm"]
    details = {"game": game}
    samples = [(game, int(s["t"]), s.get("f") or None, int(s.get("o") or 0),
                int(s.get("tot") or s.get("o") or 0), s.get("flt") or "",
                encode(s.get("c") or {}), encode(s.get("r") or {}))
               for s in body.get("samples", []) if s.get("t")]
    db.executemany("""INSERT OR IGNORE INTO pop_samples
      (game,t,faction,observed,total,filter,classes,races) VALUES (?,?,?,?,?,?,?,?)""", samples)
    details["samples"] = len(samples)
    characters = []
    known = known_realms(db)
    for c in body.get("characters", []):
        if not c.get("k") or not c.get("fs") or not c.get("ls"):
            continue
        characters.append((game, source, character_key(c["k"], source),
                           c.get("fn") or c.get("n") or c["k"], c.get("n") or "",
                           canonical_realm(c.get("r") or "", known), c.get("g") or "", c.get("l") or 0,
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
      completed_at=NULLIF(MAX(COALESCE(population_sweeps.completed_at,0),COALESCE(excluded.completed_at,0)),0),
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
    seller_rows, listing_rows = [], []
    for seller in body.get("sellers", []):
        owner = seller.get("o")
        points = seller.get("L") or []
        if not owner or not points:
            continue
        first, last = int(seller.get("fs") or 0), int(seller.get("ls") or 0)
        latest = max(latest, last)
        qty = sum(int(p[1] or 0) for p in points)
        value = sum(int(p[1] or 0) * int(p[2] or 0) for p in points)
        seller_rows.append((game, owner, slug(owner), first, last, seller.get("sc") or 0,
                            len(points), qty, value, encode(seller.get("h") or [])))
        count += 1
        for item_id, amount, price in points:
            listing_rows.append((game, owner, item_id, amount, price, last or body.get("exportedAt") or 0))
            listings += 1
    # Batched: executemany runs the statements in order, so the upserts behave as
    # one statement per row did, but a remote database costs a few round trips.
    db.executemany("""INSERT INTO sellers
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
                   seller_rows)
    db.executemany("""INSERT INTO seller_listings (game,owner,id,q,l,last_seen)
          VALUES (?,?,?,?,?,?) ON CONFLICT(game,owner,id) DO UPDATE SET
          q=CASE WHEN excluded.last_seen>COALESCE(seller_listings.last_seen,0) THEN excluded.q ELSE seller_listings.q END,
          l=CASE WHEN excluded.last_seen>COALESCE(seller_listings.last_seen,0) THEN excluded.l ELSE seller_listings.l END,
          last_seen=MAX(COALESCE(seller_listings.last_seen,0),excluded.last_seen)""", listing_rows)
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


# Cross-realm "Name-Realm" strings drop the spaces from the realm ("OldBlanchy",
# "LeiShen", "Lightning'sBlade"). Store the spaced display name instead.
REALM_EXCEPTIONS = {"azjolnerub": "Azjol-Nerub"}


def realm_key(value):
    return re.sub(r"[\s'\-]", "", norm(value))


def known_realms(db):
    known = {}
    for table in ("characters", "character_inspects"):
        for (realm,) in db.execute(f"SELECT DISTINCT realm FROM {table} WHERE realm LIKE '% %'"):
            known.setdefault(realm_key(realm), realm)
    return known


def canonical_realm(realm, known):
    if not realm or " " in realm:
        return realm
    key = realm_key(realm)
    if key in known:
        return known[key]
    if key in REALM_EXCEPTIONS:
        return REALM_EXCEPTIONS[key]
    spaced = re.sub(r"(?<=[a-z])(?=[A-Z])|(?<=[A-Za-z])(?=\d)", " ", realm)
    if spaced != realm:
        known[key] = spaced
    return spaced


def repair_realm_names(db):
    """Rewrite stored space-less realm names to their spaced display form."""
    known = known_realms(db)
    changed = {}
    for table, column in (("characters", "realm"), ("character_inspects", "realm"),
                          ("character_inspects", "observer_realm")):
        for (realm,) in db.execute(f"SELECT DISTINCT {column} FROM {table} WHERE {column} NOT LIKE '% %'").fetchall():
            fixed = canonical_realm(realm, known)
            if fixed != realm:
                count = db.execute(f"UPDATE {table} SET {column}=? WHERE {column}=?", (fixed, realm)).rowcount
                changed[f"{table}.{column}: {realm} -> {fixed}"] = count
    # Guilds are stored as "Guild-Realm". Only touch suffixes that are known realms.
    for table in ("characters", "character_inspects"):
        for (guild,) in db.execute(f"SELECT DISTINCT guild FROM {table} WHERE guild LIKE '%-%'").fetchall():
            name, _, realm = guild.rpartition("-")
            fixed = known.get(realm_key(realm)) if " " not in realm else None
            if name and fixed and fixed != realm:
                count = db.execute(f"UPDATE {table} SET guild=? WHERE guild=?",
                                   (f"{name}-{fixed}", guild)).rowcount
                changed[f"{table}.guild suffix: {realm} -> {fixed}"] = \
                    changed.get(f"{table}.guild suffix: {realm} -> {fixed}", 0) + count
    return changed


def inspect_candidates(db, source, names=None):
    """Census characters by (normalized name, class). A database that sets
    narrow_reads (PostgreSQL, possibly over a tunnel) reads only the characters
    whose lower-cased name is one of the inspects', not the whole edition."""
    by_name_class = {}
    sql, params = "SELECT game,character_key,name,realm,race,class_file,last_seen FROM characters WHERE source_game=?", (source,)
    if names is not None and getattr(db, "narrow_reads", False):
        # A superset of what match_inspect() keeps, which compares norm() (casefold).
        keys = sorted({k for n in names for k in (n.lower(), norm(n))})
        sql, params = sql + " AND lower(name) = ANY(?)", (source, keys)
    for row in db.execute(sql, params):
        by_name_class.setdefault((norm(row[2]), row[5]), []).append(row)
    return by_name_class


def match_inspect(by_name_class, name, class_file, race, realm, faction, region):
    """(game, character_key) of the one census character an inspect is, else (None, "ambiguous"/"unmatched")."""
    candidates = [c for c in by_name_class.get((norm(name), class_file), [])
                  if (not region or region == "unknown" or c[0].startswith("realm:" + region + ":"))
                  if (not race or norm(c[4]) == norm(race))
                  and (not realm or realm_key(c[3]) == realm_key(realm))
                  and (not faction or c[0].endswith("-" + faction))]
    # One character has a row in each collection that saw it (an old collection
    # and its labelled twin, connected realms); link the latest of them.
    if len({c[1] for c in candidates}) == 1:
        return max(candidates, key=lambda c: c[6])[:2]
    return None, "ambiguous" if candidates else "unmatched"


def import_inspects(db, body, source):
    added = nodes = linked = unmatched = ambiguous = 0
    if not body["records"]:
        # Nothing to link or store: skip reading the edition's characters and inspects.
        return {"selected": body["selected"], "rejected": body["rejected"], "valid": 0, "talentNodes": 0,
                "linked": 0, "unmatched": 0, "ambiguous": 0}
    by_name_class = inspect_candidates(db, source, [p["normalized"]["name"] for p in body["records"]])
    known = known_realms(db)
    nodes_sql, nodes_params = "SELECT guid,captured_at,nodes_json FROM character_inspects WHERE source_game=?", (source,)
    if getattr(db, "narrow_reads", False):
        nodes_sql, nodes_params = nodes_sql + " AND guid = ANY(?)", (
            source, sorted({p["normalized"]["guid"] for p in body["records"]}))
    existing_nodes = {(guid, captured_at): nodes_json for guid, captured_at, nodes_json in db.execute(
        nodes_sql, nodes_params)}
    inspect_rows = []
    talent_sql = """INSERT OR IGNORE INTO inspect_talents
      (source_game,guid,captured_at,node_id,tree_id,spell_id,name,rank,max_rank,
       x,y,entry_id,definition_id,groups_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"""
    talent_batch = []
    for pair in body["records"]:
        raw, e = pair["raw"], pair["normalized"]
        region = raw.get("observerRegion")
        realm = canonical_realm(e.get("realm") or "", known) or None
        match_game, match_key = match_inspect(by_name_class, e["name"], e["class"], e.get("race"),
                                              realm, e.get("faction"), region)
        if match_game:
            status = "context-name" if e["schemaVersion"] >= 2 else "legacy-name"
            linked += 1
        else:
            status, match_key = match_key, None
            ambiguous += status == "ambiguous"
            unmatched += status == "unmatched"
        nodes_json = encode(e["list"])
        values = (source, e["guid"], e["time"], e["rawName"], e["name"],
                  realm, e.get("faction") or None,
                  canonical_realm(e.get("observerRealm") or "", known) or None, e.get("observerFaction") or None,
                  e.get("observerZone") or None, e["class"], e.get("race"),
                  e.get("level"), e.get("guild"), e.get("spec"), e.get("role"),
                  e.get("talents"), e["schemaVersion"], e.get("clientBuild"),
                  e.get("locale"), match_game, match_key, status, "NameplateInspect",
                  nodes_json, encode(raw), encode(e["gear"]) if e["gear"] else None)
        inspect_rows.append(values)
        added += 1
        nodes += len(e["list"])
        if existing_nodes.get((e["guid"], e["time"])) == nodes_json:
            continue
        for talent in e["list"]:
            talent_batch.append((source, e["guid"], e["time"], talent["node"], talent.get("tree"),
                                 talent.get("spell"), talent["name"], talent["rank"], talent["max"],
                                 talent.get("x"), talent.get("y"), talent.get("entry"),
                                 talent.get("definition"), encode(talent.get("groups") or [])))
    # Inspects first, in record order as before, then their talents.
    db.executemany("""INSERT INTO character_inspects
          (source_game,guid,captured_at,raw_name,name,realm,faction,observer_realm,
           observer_faction,observer_zone,class_file,race,level,guild,raw_spec_id,
           raw_role,import_string,schema_version,client_build,locale,game,
           character_key,match_status,collector,nodes_json,raw_json,gear_json)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
          ON CONFLICT(source_game,guid,captured_at) DO UPDATE SET
          raw_name=excluded.raw_name,name=excluded.name,game=excluded.game,
          character_key=excluded.character_key,match_status=excluded.match_status,
          gear_json=COALESCE(excluded.gear_json,character_inspects.gear_json)
          WHERE excluded.schema_version>=character_inspects.schema_version""", inspect_rows)
    db.executemany(talent_sql, talent_batch)
    return {"selected": body["selected"], "rejected": body["rejected"],
            "valid": added, "talentNodes": nodes, "linked": linked,
            "unmatched": unmatched, "ambiguous": ambiguous}
