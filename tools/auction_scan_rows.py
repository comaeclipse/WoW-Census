"""Per-row bulk AH scans: PostgreSQL storage and the scan-to-scan liquidity diff.

The addon keeps the last few bulk scans (legacy getAll, modern replicate) of each
realm-faction as individual rows: item, stack, buyout and time-left bucket, with
no seller names (MarketLens/Analytics/ScanRows.lua). tools/export-scan-rows.js
reads them out of MarketLens.lua; tools/import-savedvariables-pg.py stores them
with import_scans(); tools/ah-liquidity-diff.py compares two of them with diff().
"""

import bisect
from collections import defaultdict

import psycopg2.extras

SCHEMA_SQL = """
CREATE TABLE IF NOT EXISTS auction_row_scans (
  source_game TEXT NOT NULL, bucket TEXT NOT NULL, scanned_at BIGINT NOT NULL,
  mode TEXT NOT NULL, row_count INTEGER NOT NULL, item_count INTEGER NOT NULL,
  source_sha256 TEXT NOT NULL,
  PRIMARY KEY (source_game, bucket, scanned_at)
);
CREATE TABLE IF NOT EXISTS auction_scan_rows (
  source_game TEXT NOT NULL, bucket TEXT NOT NULL, scanned_at BIGINT NOT NULL,
  item_id INTEGER NOT NULL, stack INTEGER NOT NULL, buyout BIGINT NOT NULL,
  time_left SMALLINT NOT NULL, n INTEGER NOT NULL,
  PRIMARY KEY (source_game, bucket, scanned_at, item_id, stack, buyout, time_left),
  FOREIGN KEY (source_game, bucket, scanned_at)
    REFERENCES auction_row_scans ON DELETE CASCADE
);
"""

# Time-left buckets as (shortest, longest) hours left. Bucket 0 is unknown.
LEGACY_BUCKETS = {1: (0.0, 0.5), 2: (0.5, 2.0), 3: (2.0, 8.0), 4: (8.0, 24.0)}
MODERN_BUCKETS = {1: (0.0, 0.5), 2: (0.5, 2.0), 3: (2.0, 12.0), 4: (12.0, 48.0)}
# Slack on the bucket edges: the server rounds, and the dump arrives a little
# after the moment the scan time records.
TOLERANCE_HOURS = 0.25


def buckets_for(mode):
    return MODERN_BUCKETS if mode == "replicate" else LEGACY_BUCKETS


def ensure_schema(conn):
    conn.cursor().execute(SCHEMA_SQL)


def import_scans(conn, source, bucket, scans, digest):
    """Store new scans of one realm-faction. Returns how many scans were new."""
    ensure_schema(conn)
    cur = conn.cursor()
    added = 0
    for scan in scans:
        items = scan.get("items") or {}
        rows = [(source, bucket, int(scan["t"]), int(item_id), int(stack), int(buyout), int(left), int(n))
                for item_id, tuples in items.items() for stack, buyout, left, n in tuples]
        if not rows:
            continue
        cur.execute("""INSERT INTO auction_row_scans VALUES (%s,%s,%s,%s,%s,%s,%s)
                       ON CONFLICT DO NOTHING RETURNING scanned_at""",
                    (source, bucket, int(scan["t"]), scan.get("mode") or "getAll",
                     sum(r[7] for r in rows), len(items), digest))
        if cur.fetchone() is None:
            continue  # this scan came in with an earlier save
        psycopg2.extras.execute_values(cur, "INSERT INTO auction_scan_rows VALUES %s", rows, page_size=5000)
        added += 1
    return added


def list_scans(conn, bucket=None, source=None):
    cur = conn.cursor()
    cur.execute("""SELECT source_game, bucket, scanned_at, mode, row_count, item_count
                   FROM auction_row_scans
                   WHERE (%s IS NULL OR bucket = %s) AND (%s IS NULL OR source_game = %s)
                   ORDER BY source_game, bucket, scanned_at""", (bucket, bucket, source, source))
    return [dict(zip(("source", "bucket", "t", "mode", "rows", "items"), r)) for r in cur.fetchall()]


def load_scan(conn, source, bucket, scanned_at):
    cur = conn.cursor()
    cur.execute("SELECT mode FROM auction_row_scans WHERE source_game=%s AND bucket=%s AND scanned_at=%s",
                (source, bucket, scanned_at))
    mode = cur.fetchone()[0]
    cur.execute("""SELECT item_id, stack, buyout, time_left, n FROM auction_scan_rows
                   WHERE source_game=%s AND bucket=%s AND scanned_at=%s""", (source, bucket, scanned_at))
    items = defaultdict(list)
    for item_id, stack, buyout, left, n in cur.fetchall():
        items[item_id].append((stack, buyout, left, n))
    return {"t": scanned_at, "mode": mode, "items": dict(items)}


def weighted_median(pairs):
    """Median of (value, weight) pairs."""
    pairs = sorted(pairs)
    total = sum(w for _, w in pairs)
    if not total:
        return None
    seen = 0
    for value, weight in pairs:
        seen += weight
        if seen * 2 >= total:
            return value
    return pairs[-1][0]


def _match(a_counts, b_counts, gap, spans):
    """Pair one (item, stack, buyout)'s A rows with B rows, by time-left bucket.

    A row can survive into B in the same bucket or a lower one, if B's bucket
    still fits what was left at A minus the gap. Returns (matched A counts,
    matched B counts), each {bucket: n}."""
    a_left, b_left = dict(a_counts), dict(b_counts)
    a_used, b_used = defaultdict(int), defaultdict(int)
    for ka in sorted(a_left, reverse=True):
        lo_a, hi_a = spans.get(ka, (0.0, float("inf")))
        for kb in sorted(b_left, reverse=True):
            if not a_left[ka]:
                break
            if not b_left[kb]:
                continue
            if ka and kb:
                lo_b, hi_b = spans[kb]
                if kb > ka or lo_b > hi_a - gap + TOLERANCE_HOURS or hi_b < lo_a - gap - TOLERANCE_HOURS:
                    continue
            n = min(a_left[ka], b_left[kb])
            a_left[ka] -= n
            b_left[kb] -= n
            a_used[ka] += n
            b_used[kb] += n
    return a_used, b_used


def diff(scan_a, scan_b):
    """Estimate per-item sales between two scans of the same realm-faction.

    Returns (summary, [per-item dict]). Units are item counts, prices copper
    per unit."""
    gap = (scan_b["t"] - scan_a["t"]) / 3600.0
    if gap <= 0:
        raise ValueError("scan B must be later than scan A")
    spans = buckets_for(scan_a.get("mode"))
    report = []
    totals = defaultdict(float)
    for item_id in sorted(set(scan_a["items"]) | set(scan_b["items"])):
        a_keys, b_keys = defaultdict(lambda: defaultdict(int)), defaultdict(lambda: defaultdict(int))
        for stack, buyout, left, n in scan_a["items"].get(item_id, ()):
            a_keys[(stack, buyout)][left] += n
        for stack, buyout, left, n in scan_b["items"].get(item_id, ()):
            b_keys[(stack, buyout)][left] += n

        removed_early = []   # (stack, buyout, n) gone too soon to have expired
        maybe_expired = 0    # units that may simply have run out
        new_rows = []        # (stack, buyout, n) only in B
        survivors = []       # unit prices of A rows still listed at B
        supply = 0
        for key in set(a_keys) | set(b_keys):
            stack, buyout = key
            a_counts, b_counts = a_keys.get(key, {}), b_keys.get(key, {})
            a_used, b_used = _match(a_counts, b_counts, gap, spans)
            for left, n in a_counts.items():
                supply += stack * n
                gone = n - a_used[left]
                if a_used[left]:
                    survivors.append(buyout / stack)
                if not gone:
                    continue
                if left and spans[left][0] >= gap:
                    removed_early.append((stack, buyout, gone))
                else:
                    maybe_expired += stack * gone
            fresh = sum(b_counts.values()) - sum(b_used.values())
            if fresh:
                new_rows.append((stack, buyout, fresh))

        # A removed row with a new, cheaper row of the same stack in B looks like
        # a cancel-and-relist (undercut). Each new row explains one removed row.
        cheaper = defaultdict(list)
        for stack, buyout, n in new_rows:
            cheaper[stack].extend([buyout] * n)
        for prices in cheaper.values():
            prices.sort()
        relist = 0
        sold = []            # (unit price, units)
        for stack, buyout, n in sorted(removed_early, key=lambda r: -r[1] / r[0]):
            prices = cheaper.get(stack, [])
            for _ in range(n):
                i = bisect.bisect_left(prices, buyout)
                if i:
                    prices.pop(i - 1)
                    relist += stack
                else:
                    sold.append((buyout / stack, stack))

        # Buyers take the cheap end first, so a sold row priced above an A row
        # that is still listed is weaker evidence: it counts only toward the
        # point estimate, not the low end.
        floor = min(survivors) if survivors else float("inf")
        sold_units = sum(u for _, u in sold)
        low = sum(u for p, u in sold if p <= floor)
        high = sold_units + relist
        new_units = sum(s * n for s, _, n in new_rows)
        if not (supply or new_units):
            continue
        row = {
            "item_id": item_id,
            "supply_a": supply,
            "removed_early": sum(s * n for s, _, n in removed_early),
            "maybe_expired": maybe_expired,
            "relist": relist,
            "sold": sold_units,
            "sold_low": low,
            "sold_high": high,
            "sold_per_hour": sold_units / gap,
            "sold_share": sold_units / supply if supply else None,
            "sold_median": weighted_median(sold),
            "new_auctions": sum(n for _, _, n in new_rows),
            "new_units": new_units,
        }
        report.append(row)
        for k in ("supply_a", "removed_early", "maybe_expired", "relist", "sold", "sold_low", "sold_high", "new_units"):
            totals[k] += row[k]
    summary = {"gap_hours": gap, "mode": scan_a.get("mode"), "items": len(report), **totals}
    return summary, report
