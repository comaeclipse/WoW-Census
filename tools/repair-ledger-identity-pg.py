"""Re-key stored ledger events after a change to IDENTITY_DROP, and fold the copies it now matches.

    python -B tools/repair-ledger-identity-pg.py [--dry-run]
    python -B tools/import-live.py repair-ledger-identity      # local, then live

tools/import-journalator-pg.py keys every event by a hash of its row. Some fields appear in one copy
of an event and not another (IDENTITY_DROP: a training row's trainer specialty), so the same event was
stored twice. The importer now leaves those fields out of the key. This recomputes the key of every
stored event in the affected sections the same way. Copies of one event become one row: the copy with
the most fields is kept, as many times as any single copy was stored (identical events in the same
second stay apart). One transaction; --dry-run rolls it back after the report.
"""

import argparse
import importlib.util
import json
import sys
from collections import defaultdict
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("journalator_pg", REPO / "tools" / "import-journalator-pg.py")
j = importlib.util.module_from_spec(spec)
spec.loader.exec_module(j)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--dsn", default=j.pg.DEFAULT_DSN)
    ap.add_argument("--archive", help="ignored (accepted so tools/import-live.py can run this)")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    conn = j.pg.psycopg2.connect(args.dsn)
    conn.autocommit = False
    cur = conn.cursor()
    cur.execute("SELECT pg_advisory_lock(hashtext('marketlens-import'))")
    conn.commit()
    cur.execute("""SELECT column_name FROM information_schema.columns
        WHERE table_schema='marketlens_import' AND table_name='addon_ledger_events' ORDER BY ordinal_position""")
    cols = [r[0] for r in cur.fetchall()]
    report = {"dryRun": args.dry_run, "sections": {}}
    changed = False
    for section in sorted(j.IDENTITY_DROP):
        cur.execute(f"SELECT {', '.join(cols)} FROM marketlens_import.addon_ledger_events WHERE section=%s", (section,))
        rows = [dict(zip(cols, r)) for r in cur.fetchall()]
        groups = defaultdict(lambda: defaultdict(list))
        for r in rows:
            row = r["row"]
            ident = (r["edition"], r["region"], row.get("realm"), row.get("char"), section, row.get("t"),
                     json.dumps(j.identity(section, row), sort_keys=True))
            groups[ident][json.dumps(j.canon(row), sort_keys=True)].append(r)
        keep, rekeyed = [], 0
        for ident, variants in groups.items():
            n = max(len(v) for v in variants.values())
            best = max(variants.values(), key=lambda v: (len(json.dumps(v[0]["row"])), len(v)))
            for occ, r in enumerate(sorted(best, key=lambda r: r["event_key"])[:n], 1):
                key = j.event_key(r["edition"], r["region"], section, r["row"], occ)
                rekeyed += key != r["event_key"]
                keep.append({**r, "event_key": key})
        removed = len(rows) - len(keep)
        report["sections"][section] = {"rows": len(rows), "events": len(groups), "kept": len(keep),
                                       "removed": removed, "rekeyed": rekeyed}
        if not removed and not rekeyed:
            continue
        changed = True
        cur.execute("DELETE FROM marketlens_import.addon_ledger_events WHERE section=%s", (section,))
        for r in keep:
            vals = [json.dumps(r[c], ensure_ascii=False) if c == "row" else r[c] for c in cols]
            cur.execute(f"INSERT INTO marketlens_import.addon_ledger_events ({', '.join(cols)}) VALUES ({', '.join(['%s'] * len(cols))})", vals)
    print(json.dumps(report, indent=1))
    if args.dry_run:
        conn.rollback()
        print("DRY RUN: rolled back")
        return
    conn.commit()
    print("CHANGED sources=addon-ledger" if changed else "Nothing new")


if __name__ == "__main__":
    sys.exit(main())
