#!/usr/bin/env python3
"""Build a local-only recording and publication history report.

Usage: python -B tools/build-data-history.py [--s3]
The output lives outside static/ so it cannot be included in an S3 deploy.
"""
import argparse
import html
import json
import os
import sqlite3
import subprocess
from datetime import datetime, timezone
from pathlib import Path

DATA_ROOT = Path(os.environ.get("MARKETLENS_DATA_ROOT", Path.home() / "Documents" / "MarketLensData"))
EDITION = {"classic-beta": ("WoW Forever", "index.html"),
           "classic-progression": ("TBC Anniversary", "tbc/index.html"),
           "classic": ("Classic Era", "classic/index.html"),
           "sod": ("Season of Discovery", "sod/index.html"),
           "mop-classic": ("MoP Classic", "mop/index.html"),
           "retail": ("Retail", "retail/index.html")}


def utc(value):
    if not value:
        return "—"
    try:
        if isinstance(value, (float, int)):
            date = datetime.fromtimestamp(value, timezone.utc)
        else:
            date = datetime.fromisoformat(value.replace("Z", "+00:00"))
        return date.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    except (ValueError, OverflowError):
        return "—"


def snapshot_time(folder):
    try:
        return datetime.strptime(folder.name[:15], "%Y%m%d-%H%M%S").replace(tzinfo=timezone.utc)
    except ValueError:
        return None


def datasets(dbfile):
    with sqlite3.connect(f"file:{dbfile.as_posix()}?mode=ro", uri=True) as db:
        return {game: (source, updated) for game, source, updated in db.execute(
            "SELECT game, source_game, updated_at FROM datasets WHERE game LIKE 'realm:%'")}


def auction_times(dbfile):
    with sqlite3.connect(f"file:{dbfile.as_posix()}?mode=ro", uri=True) as db:
        return dict(db.execute("SELECT game, MAX(captured_at) FROM local_auction_snapshots GROUP BY game"))


def esc(value):
    return html.escape(str(value), quote=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--data-root", type=Path, default=DATA_ROOT)
    parser.add_argument("--s3", action="store_true", help="read current S3 object upload times")
    parser.add_argument("--bucket", default="wowcensus.io")
    parser.add_argument("--profile", default="wowcensus")
    args = parser.parse_args()
    root = args.data_root.resolve()
    snapshots = [(folder, snapshot_time(folder)) for folder in root.iterdir() if folder.is_dir()]
    snapshots = [(folder, time) for folder, time in snapshots
                 if time and (folder / "marketlens-unified.sqlite").is_file()]
    snapshots.sort(key=lambda pair: pair[1])
    if not snapshots:
        parser.error(f"No unified SQLite snapshots in {root}")
    latest, latest_time = snapshots[-1]
    current = datasets(latest / "marketlens-unified.sqlite")
    auction = auction_times(latest / "marketlens-unified.sqlite")
    first_seen = {}
    last_seen = {}
    changes = []
    prior = {}
    for folder, time in snapshots:
        values = datasets(folder / "marketlens-unified.sqlite")
        for game, (_, updated) in values.items():
            first_seen.setdefault(game, time)
            last_seen[game] = time
            if prior and updated and (game not in prior or prior[game][1] != updated):
                changes.append((game, updated, time))
        prior = values

    uploads = {}
    s3_note = "S3 was not queried. Run with --s3 to include current page object times."
    if args.s3:
        result = subprocess.run(["aws", "--profile", args.profile, "s3api", "list-objects-v2",
                                 "--bucket", args.bucket, "--output", "json"],
                                capture_output=True, text=True)
        if result.returncode:
            s3_note = "S3 query failed; upload times are unavailable in this report."
        else:
            uploads = {row["Key"]: row.get("LastModified") for row in
                       json.loads(result.stdout).get("Contents", [])}
            s3_note = ("S3 times are the LastModified value of each edition's current census page. "
                       "They show that object's latest upload, not when each realm's data was uploaded. "
                       "Bucket versioning is disabled, so earlier uploads cannot be reconstructed from S3.")

    rows = []
    for game, (source, recorded) in sorted(current.items(), key=lambda item: (item[1][1] or "", item[0]), reverse=True):
        label, key = EDITION.get(source, (source, ""))
        rows.append("<tr><td>" + esc(label) + "</td><th scope='row'>" + esc(game.removeprefix("realm:")) +
                    "</th><td>" + esc(utc(recorded)) + "</td><td>" + esc(utc(auction.get(game))) +
                    "</td><td>" + esc(utc(first_seen.get(game).timestamp() if game in first_seen else None)) +
                    "</td><td>" + esc(utc(last_seen.get(game).timestamp() if game in last_seen else None)) +
                    "</td><td>" + esc(utc(uploads.get(key))) + "</td></tr>")
    events = "".join("<tr><td>" + esc(utc(imported.timestamp())) + "</td><td>" +
                     esc(game.removeprefix("realm:")) + "</td><td>" + esc(utc(recorded)) + "</td></tr>"
                     for game, recorded, imported in sorted(changes, key=lambda row: row[2], reverse=True)[:250])
    output = root / "history" / "index.html"
    output.parent.mkdir(parents=True, exist_ok=True)
    page = f"""<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Local data history · WoWCensus</title><meta name="robots" content="noindex,nofollow">
<style>
@font-face{{font-family:VT323;src:url('../../Projects/MarketLens/site/public/fonts/vt323-latin.woff2') format('woff2')}}
:root{{--bg:#0d0f17;--panel:#161a28;--line:#2b3145;--ink:#eae7d6;--muted:#8b90a0;--gold:#ffce43;--blue:#5fb0f0}}
*{{box-sizing:border-box}}body{{margin:0;background:var(--bg);color:var(--ink);font:20px/1.25 VT323,Consolas,monospace}}
main{{max-width:1160px;margin:auto;padding:30px 18px 70px}}h1{{color:var(--gold);font-size:42px;margin:0 0 8px}}h2{{color:var(--gold);font-size:28px;margin:34px 0 10px}}p{{max-width:1000px;margin:8px 0 16px}}.muted{{color:var(--muted)}}
.stats{{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:24px 0}}.stat{{background:var(--panel);border:2px solid var(--line);box-shadow:5px 5px #000;padding:15px}}.stat strong{{display:block;color:var(--gold);font-size:32px}}
.tablewrap{{overflow-x:auto;border:2px solid var(--line);box-shadow:5px 5px #000}}table{{border-collapse:collapse;width:100%;min-width:830px}}th,td{{padding:9px 12px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}}thead th{{color:var(--gold);background:#1e2334;position:sticky;top:0}}tbody tr:nth-child(odd){{background:var(--panel)}}tbody tr:hover{{background:#1e2334}}tbody th{{font-weight:normal;color:var(--blue)}}
thead button{{font:inherit;color:inherit;background:none;border:0;padding:0;cursor:pointer;text-align:left;white-space:nowrap}}thead button:hover{{color:var(--ink)}}thead button:focus-visible{{outline:2px solid var(--blue);outline-offset:3px}}thead button::after{{content:' ↕';color:var(--muted)}}thead th[aria-sort='ascending'] button::after{{content:' ↑';color:var(--gold)}}thead th[aria-sort='descending'] button::after{{content:' ↓';color:var(--gold)}}
@media(max-width:650px){{main{{padding:18px 12px 50px}}h1{{font-size:32px}}.stats{{grid-template-columns:1fr}}}}
</style><main><header><div class="muted">WOWCENSUS / LOCAL REPORT</div><h1>Data history</h1>
<p>Recorded in game, found in a local SQLite snapshot, and last uploaded as an edition page are separate events. All times are UTC.</p></header>
<section class="stats"><div class="stat"><strong>{len(current)}</strong>realm datasets</div><div class="stat"><strong>{len(snapshots)}</strong>local snapshots</div><div class="stat"><strong>{len(changes)}</strong>observed dataset changes</div></section>
<p class="muted">Latest local snapshot: {esc(utc(latest_time.timestamp()))}. {esc(s3_note)}</p>
<h2>Current datasets</h2><p class="muted">Select a column heading to sort. Select it again to reverse the order.</p><div class="tablewrap"><table id="datasets"><thead><tr><th scope="col"><button type="button">Edition</button></th><th scope="col"><button type="button">Realm and faction</button></th><th scope="col" aria-sort="descending"><button type="button">Dataset updated</button></th><th scope="col"><button type="button">AH scanned</button></th><th scope="col"><button type="button">First local snapshot</button></th><th scope="col"><button type="button">Latest local snapshot</button></th><th scope="col"><button type="button">Edition page uploaded</button></th></tr></thead><tbody>{''.join(rows)}</tbody></table></div>
<p class="muted">“Dataset updated” is the SQLite updated_at value; “AH scanned” is the latest auction capture. “First local snapshot” means first among retained snapshots, not first ever. An edition page upload may include several realms. “—” means unavailable.</p>
<h2>Changes seen in retained snapshots</h2><div class="tablewrap"><table><thead><tr><th>Local snapshot</th><th>Realm and faction</th><th>New recorded time</th></tr></thead><tbody>{events}</tbody></table></div>
<p class="muted">Only the latest 250 observed changes are shown. The first retained snapshot establishes a baseline, not a newly recorded event. Intermediate game recordings overwritten before a snapshot cannot be recovered here. Built locally from {esc(latest.name)}.</p></main>
<script>
(() => {{
  const table = document.getElementById('datasets');
  const headers = [...table.tHead.rows[0].cells];
  let column = 2, direction = -1;
  headers.forEach((header, index) => header.querySelector('button').addEventListener('click', () => {{
    direction = column === index ? -direction : (index < 2 ? 1 : -1);
    column = index;
    headers.forEach(cell => cell.removeAttribute('aria-sort'));
    header.setAttribute('aria-sort', direction === 1 ? 'ascending' : 'descending');
    const rows = [...table.tBodies[0].rows];
    rows.sort((a, b) => {{
      const left = a.cells[index].textContent.trim();
      const right = b.cells[index].textContent.trim();
      if (left === '—') return right === '—' ? 0 : 1;
      if (right === '—') return -1;
      return direction * left.localeCompare(right, undefined, {{numeric: true, sensitivity: 'base'}});
    }});
    table.tBodies[0].append(...rows);
  }}));
}})();
</script></html>"""
    output.write_text(page, encoding="utf-8")
    print(f"Wrote {output} ({len(current)} datasets, {len(snapshots)} snapshots, {len(changes)} observed changes)")


if __name__ == "__main__":
    main()
