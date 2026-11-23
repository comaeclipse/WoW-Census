#!/usr/bin/env python3
"""Archive and import ClassicPlus 2.13.0 saves into separate Forever provenance tables.

    python -B tools/import-classicplus-pg.py FILE.lua [FILE.lua ...]

The packed character date has day precision; it is not an exact /who sighting.
These rows do not change the canonical WoWCensus census or its cache.
"""
import argparse
import hashlib
import json
import os
import subprocess
from datetime import datetime, timezone
from pathlib import Path

import psycopg2
from psycopg2.extras import execute_values

REPO = Path(__file__).resolve().parent.parent
DATA = Path(os.environ.get("MARKETLENS_DATA_ROOT", Path.home() / "Documents" / "MarketLensData"))
DSN = os.environ.get("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("files", type=Path, nargs="+")
    ap.add_argument("--dsn", default=DSN)
    ap.add_argument("--archive", type=Path, default=DATA / "imports-classicplus")
    args = ap.parse_args()
    files = []
    for path in args.files:
        raw = path.read_bytes()
        digest = hashlib.sha256(raw).hexdigest()
        result = subprocess.run(["node", str(REPO / "tools" / "export-classicplus-savedvariables.js"), str(path)],
                                capture_output=True, text=True, encoding="utf-8", check=True)
        files.append((path, raw, digest, json.loads(result.stdout)))  # one body per realm and faction
    if len({x[2] for x in files}) != len(files):
        raise SystemExit("duplicate file content in this batch")

    archive = args.archive / datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S")
    conn = psycopg2.connect(args.dsn)
    imported = []
    try:
        with conn, conn.cursor() as cur:
            cur.execute("SELECT pg_advisory_xact_lock(hashtext('marketlens-classicplus-import'))")
            cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.external_classicplus_sources (
              sha256 text PRIMARY KEY REFERENCES marketlens_import.local_source_files(sha256),
              version text NOT NULL, data_format text NOT NULL, saved_at timestamptz NOT NULL,
              locale text, region text NOT NULL CHECK (region IN ('us','eu','kr')),
              realm_id integer NOT NULL, realm_name text NOT NULL, ruleset text NOT NULL,
              faction text NOT NULL CHECK (faction IN ('Alliance','Horde')),
              character_rows integer NOT NULL, first_day date NOT NULL, last_day date NOT NULL,
              scan_passes integer NOT NULL, scan_times integer NOT NULL,
              archive_path text NOT NULL, imported_at timestamptz NOT NULL DEFAULT now())""")
            cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.external_classicplus_characters (
              source_sha256 text NOT NULL REFERENCES marketlens_import.external_classicplus_sources(sha256),
              name text NOT NULL, race_raw text NOT NULL, class_raw text NOT NULL,
              level integer NOT NULL, guild text NOT NULL, last_seen_day date NOT NULL,
              source_character_id bigint NOT NULL, seen_count integer NOT NULL, surname text NOT NULL,
              PRIMARY KEY (source_sha256,name))""")
            for path, raw, digest, bodies in files:
                cur.execute("SELECT 1 FROM marketlens_import.local_source_files WHERE sha256=%s", (digest,))
                if cur.fetchone():
                    print(f"ALREADY IMPORTED {path.name} sha256={digest}", flush=True)
                    continue
                target = archive / path.name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(raw)
                if hashlib.sha256(target.read_bytes()).hexdigest() != digest:
                    raise RuntimeError(f"archive hash mismatch: {target}")
                modified = datetime.fromtimestamp(path.stat().st_mtime, timezone.utc).isoformat()
                cur.execute("""INSERT INTO marketlens_import.local_source_files
                  (sha256,relative_path,source_modified_utc,bytes,content) VALUES (%s,%s,%s,%s,NULL)""",
                            (digest, f"external/classicplus/{path.name}", modified, len(raw)))
                multiple_realms = len({body["realmId"] for body in bodies}) > 1
                for body in bodies:
                    import_faction(cur, path, raw, digest, body, target, modified, len(bodies) > 1, multiple_realms)
                    imported.append(body)
        print(f"IMPORTED sources={len(imported)} characters={sum(len(b['rows']) for b in imported)}", flush=True)
        print("CHANGED sources=", flush=True)
    finally:
        conn.close()


def import_faction(cur, path, raw, file_digest, body, target, modified, split, multiple_realms):
    digest = file_digest
    if split:
        # Keep existing source IDs for single-realm saves.
        suffix = body["faction"]
        if multiple_realms:
            suffix = f"{body['region']}:{body['realmId']}:{suffix}"
        digest = hashlib.sha256(f"{file_digest}:{suffix}".encode()).hexdigest()
        cur.execute("""INSERT INTO marketlens_import.local_source_files
          (sha256,relative_path,source_modified_utc,bytes,content) VALUES (%s,%s,%s,%s,NULL)""",
                    (digest, f"external/classicplus/{path.name}#{suffix}", modified, len(raw)))
    cur.execute("""INSERT INTO marketlens_import.external_classicplus_sources
      (sha256,version,data_format,saved_at,locale,region,realm_id,realm_name,ruleset,faction,
       character_rows,first_day,last_day,scan_passes,scan_times,archive_path)
      VALUES (%s,%s,%s,to_timestamp(%s),%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
                (digest, body["version"], body["dataFormat"], body["savedAt"], body["locale"],
                 body["region"], body["realmId"], body["realmName"], body["ruleset"],
                 body["faction"], len(body["rows"]), body["firstDay"], body["lastDay"],
                 body["scanPasses"], body["scanTimes"], str(target)))
    execute_values(cur, """INSERT INTO marketlens_import.external_classicplus_characters
      (source_sha256,name,race_raw,class_raw,level,guild,last_seen_day,source_character_id,seen_count,surname)
      VALUES %s""", [(digest, *row) for row in body["rows"]], page_size=1000)
    print(f"IMPORTED {path.name} {body['region']}:{body['realmName']}:{body['faction']} "
          f"characters={len(body['rows'])} days={body['firstDay']}..{body['lastDay']} source={digest[:12]}",
          flush=True)


if __name__ == "__main__":
    main()
