#!/usr/bin/env python3
"""Import Journalator / TSM / Auctionator ledgers into PostgreSQL.

    python -B tools/import-journalator-pg.py PATH [PATH ...] [--region us] [--dry-run]

PATH can be:
  * a Journalator.lua SavedVariables file (the add-on's own save; its logs are an
    encoded blob that tools/journalator_decode.py unpacks as data),
  * a WoWCensus.lua save written with "Collect other addon data" on (WoWCensusDB.market:
    Journalator sections, TSM sales/buys/expired/cancelled, Auctionator posting history),
  * a folder, searched for both (companion upload stages included; a sibling
    <name>.json manifest supplies the edition).

The edition comes from the install path (_forever_, _anniversary_, ...), the upload
manifest, or WoWCensusDB.market.game. The region comes from WoWCensusDB.market.region,
--region, or the realm's known region in census_collection_realms; a file whose region
cannot be decided is refused.

Raw Journalator rows get the same cleanup as WoWCensus's own copy (Market.lua): other
players' names (buyer, trade partner, mail sender/recipient, crafting notes) are dropped,
item links become itemString + itemID, source{character,realm,faction} is flattened and
`time` becomes `t`. Both routes therefore yield identical rows, so a sale seen in a raw
Journalator file and again in a WoWCensus copy is stored once. Identical events in the
same second (13 Coarse Thread vendored at once) are kept apart by an occurrence number.

Tables (schema marketlens_import, created on first run):
  addon_ledger_sources  one row per imported file (sha256), like local_source_files
  addon_ledger_events   every event: edition, region, realm, faction, character, section,
                        t, item_id, item_name, quantity, money, row (jsonb, names removed)
  addon_auction_sales   derived: each completed AH sale (Journalator Invoices as seller,
                        TSM Sales), its unit price, and when we have the matching posting,
                        posted_at and seconds_to_sell. Rebuilt for every touched character.

One transaction; --dry-run rolls it back after the report. A file whose SHA-256 is
already in addon_ledger_sources is skipped.
"""

import argparse
import hashlib
import importlib.util
import json
import re
import subprocess
import sys
from collections import defaultdict
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent


def _load(name, file):
    spec = importlib.util.spec_from_file_location(name, REPO / "tools" / file)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


pg = _load("import_pg", "import-savedvariables-pg.py")
jdec = _load("journalator_decode", "journalator_decode.py")
legacy = pg.legacy

# Journalator / companion-upload / install-path edition names -> site edition.
EDITIONS = {
    "_forever_": "forever", "_anniversary_": "tbc-anniversary", "_classic_": "mop-classic",
    "_classic_era_": "classic-era", "_retail_": "retail",
    "forever": "forever", "anniversary": "tbc-anniversary", "tbc-anniversary": "tbc-anniversary",
    "classic": "mop-classic", "mop-classic": "mop-classic", "classic_era": "classic-era",
    "classic-era": "classic-era", "sod": "sod", "hardcore": "hardcore", "retail": "retail",
}
REGIONS = ("us", "eu", "kr", "tw", "cn")
# Fields WoWCensus's Market.lua never copies: other players' names and free text.
PRIVATE = {"playerName", "player", "recipient", "sender", "subject", "text",
           "crafterName", "crafterNote", "customerNote", "otherPlayer"}
MAX_DEPTH = 6
SALE_WINDOW = 48 * 3600 + 3600   # longest auction plus slack for clock skew


def clean_item(link):
    m = re.search(r"(item:[^|]*)", link or "")
    if not m:
        return None, None
    s = re.sub(r"Player-\d+-[0-9A-Fa-f]+", "", m.group(1)).rstrip(":")
    n = re.match(r"^item:(\d+)", s)
    return s, int(n.group(1)) if n else None


def copy_value(v, depth=0):
    """Python twin of Market.lua copyValue(): names out, links -> itemString/itemID."""
    if isinstance(v, list):
        return [copy_value(x, depth + 1) for x in v] if depth <= MAX_DEPTH else None
    if not isinstance(v, dict):
        return v
    if depth > MAX_DEPTH:
        return None
    out = {}
    for k, x in v.items():
        if k in PRIVATE:
            continue
        if isinstance(k, str) and isinstance(x, str) and k.endswith("Link"):
            s, i = clean_item(x)
            out[k[:-4] + "String"], out[k[:-4] + "ID"] = s, i
        elif k == "guid" and isinstance(x, str) and x.startswith("Player-"):
            continue
        else:
            out[k] = copy_value(x, depth + 1)
    return out


def canon(v):
    """Stable form for hashing: no None, no empty containers, lists kept in order."""
    if isinstance(v, dict):
        out = {str(k): canon(x) for k, x in v.items()}
        return {k: x for k, x in sorted(out.items()) if x not in (None, {}, [])}
    if isinstance(v, list):
        return [canon(x) for x in v]
    if isinstance(v, float) and v.is_integer():
        return int(v)
    return v


def journalator_row(raw):
    """A raw Journalator log entry in WoWCensus's copied shape."""
    row = copy_value(raw)
    src = raw.get("source") if isinstance(raw.get("source"), dict) else {}
    row.pop("source", None)
    row["char"], row["realm"], row["faction"] = src.get("character"), src.get("realm"), src.get("faction")
    row["t"] = raw.get("time")
    row.pop("time", None)
    return row


def to_int(v):
    try:
        return int(v) if v is not None and v != "" else None
    except (TypeError, ValueError):
        try:
            return int(float(v))
        except (TypeError, ValueError):
            return None


def measures(section, row):
    """(item_id, item_name, quantity, money) for the common columns."""
    item = to_int(row.get("itemID"))
    name = row.get("itemName")
    qty = to_int(row.get("count") if row.get("count") is not None else row.get("qty"))
    if section == "Invoices":
        money = to_int(row.get("value"))
    elif section == "Posting":
        money = to_int(row.get("buyout"))
    elif section == "Vendoring":
        unit = to_int(row.get("unitPrice"))
        money = unit * (qty or 1) if unit is not None else None
    elif section == "Questing":
        money = to_int(row.get("rewardMoney"))
    elif section == "Trades":
        money = (to_int(row.get("moneyIn")) or 0) - (to_int(row.get("moneyOut")) or 0)
    elif section.startswith("TSM:"):
        price = to_int(row.get("price"))
        money = price * (qty or 1) if price is not None else None
    elif section == "Auctionator:Posting":
        price = to_int(row.get("price"))
        money = price * (qty or 1) if price is not None else None
    else:
        money = to_int(row.get("money") if row.get("money") is not None else row.get("value"))
    return item, name, qty, money


def lua_tables(path):
    """WoWCensusDB as JSON via the repository's SavedVariables reader (data only)."""
    js = ("const {readSavedVariables}=require(process.argv[1]);"
          "const v=readSavedVariables(process.argv[2]);"
          "process.stdout.write(JSON.stringify({market:v.market||null,realms:Object.keys(v.realms||{})}));")
    out = subprocess.run(["node", "-e", js, str(REPO / "tools" / "savedvariables.js"), str(path)],
                         capture_output=True, check=True, timeout=600)
    return json.loads(out.stdout)


def edition_from_path(path):
    for part in Path(path).parts:
        if part in EDITIONS and part.startswith("_"):
            return EDITIONS[part]
    manifest = Path(path).with_suffix(".json")
    if manifest.exists():
        try:
            e = json.loads(manifest.read_text(encoding="utf-8")).get("edition")
            if e in EDITIONS:
                return EDITIONS[e]
        except (OSError, ValueError):
            pass
    return None


def rows_from_file(path):
    """(kind, edition or None, region or None, [(section, row)]) for one file."""
    head = Path(path).read_bytes()[:4096].lstrip()
    if head.startswith(b"JOURNALATOR_") or b"\nJOURNALATOR_" in Path(path).read_bytes()[:200000]:
        rows = []
        for store in jdec.read_archive(path).values():
            logs = store["logs"]
            if not isinstance(logs, dict):
                continue
            for section, entries in logs.items():
                if isinstance(entries, list):
                    rows += [(section, journalator_row(e)) for e in entries if isinstance(e, dict)]
        return "journalator", edition_from_path(path), None, rows
    if head.startswith(b"WoWCensusDB"):
        data = lua_tables(path)
        m = data.get("market") or {}
        rows = []
        for section, entries in (m.get("journalator") or {}).items():
            vals = entries.values() if isinstance(entries, dict) else entries
            rows += [(section, dict(r)) for r in vals if isinstance(r, dict)]
        tsm = m.get("tsm") or {}
        for r in (tsm.values() if isinstance(tsm, dict) else tsm):
            if isinstance(r, dict) and r.get("kind"):
                r = dict(r)
                # TSM realm keys carry the faction ("Whitemane - Alliance"); keep the realm name.
                realm = str(r.get("realm") or "")
                fm = re.match(r"^(.*?)\s*-\s*(Alliance|Horde|Neutral)$", realm)
                if fm:
                    r["realm"] = fm.group(1)
                    r.setdefault("faction", fm.group(2))
                r.pop("player", None)
                rows.append(("TSM:" + r.pop("kind"), r))
        auc = m.get("auctionator") or {}
        for r in (auc.values() if isinstance(auc, dict) else auc):
            if isinstance(r, dict):
                r = dict(r)
                r["itemID"] = to_int(r.get("key")) if str(r.get("key", "")).isdigit() else None
                rows.append(("Auctionator:Posting", r))
        edition = EDITIONS.get(m.get("game")) or edition_from_path(path)
        region = m.get("region") if m.get("region") in REGIONS else None
        return "wowcensus", edition, region, rows
    return None, None, None, []


def schema(cur):
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.addon_ledger_sources (
        sha256 text PRIMARY KEY, kind text NOT NULL, path text NOT NULL, edition text, region text,
        rows integer NOT NULL, new_rows integer NOT NULL, imported_at timestamptz NOT NULL DEFAULT now())""")
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.addon_ledger_events (
        event_key text PRIMARY KEY,
        edition text NOT NULL, source_game text NOT NULL, region text NOT NULL,
        realm text, faction text, character text,
        section text NOT NULL, t bigint NOT NULL,
        item_id integer, item_name text, quantity integer, money bigint,
        row jsonb NOT NULL, source_sha256 text NOT NULL,
        imported_at timestamptz NOT NULL DEFAULT now())""")
    cur.execute("""CREATE INDEX IF NOT EXISTS addon_ledger_events_item
        ON marketlens_import.addon_ledger_events (source_game, item_id, t DESC) WHERE item_id IS NOT NULL""")
    cur.execute("""CREATE INDEX IF NOT EXISTS addon_ledger_events_section
        ON marketlens_import.addon_ledger_events (source_game, section, t DESC)""")
    cur.execute("""CREATE INDEX IF NOT EXISTS addon_ledger_events_who
        ON marketlens_import.addon_ledger_events (edition, region, realm, character)""")
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.addon_auction_sales (
        event_key text PRIMARY KEY REFERENCES marketlens_import.addon_ledger_events(event_key) ON DELETE CASCADE,
        edition text NOT NULL, source_game text NOT NULL, region text NOT NULL,
        realm text, faction text, item_id integer, item_name text,
        quantity integer NOT NULL, total bigint NOT NULL, unit_price bigint NOT NULL,
        sold_at bigint NOT NULL, posted_at bigint, seconds_to_sell integer, origin text NOT NULL)""")
    cur.execute("""CREATE INDEX IF NOT EXISTS addon_auction_sales_item
        ON marketlens_import.addon_auction_sales (source_game, item_id, sold_at DESC)""")
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.addon_loot_sources (
        event_key text PRIMARY KEY REFERENCES marketlens_import.addon_ledger_events(event_key) ON DELETE CASCADE,
        edition text NOT NULL, source_game text NOT NULL, region text NOT NULL,
        kind text NOT NULL, source_id bigint NOT NULL, source_name text, t bigint NOT NULL, money bigint)""")
    cur.execute("""CREATE INDEX IF NOT EXISTS addon_loot_sources_source
        ON marketlens_import.addon_loot_sources (source_game, kind, source_id)""")
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.addon_loot_items (
        event_key text NOT NULL REFERENCES marketlens_import.addon_loot_sources(event_key) ON DELETE CASCADE,
        source_game text NOT NULL, item_id integer NOT NULL, quantity integer NOT NULL,
        PRIMARY KEY (event_key, item_id))""")
    cur.execute("""CREATE INDEX IF NOT EXISTS addon_loot_items_item
        ON marketlens_import.addon_loot_items (source_game, item_id)""")
    cur.execute("""CREATE INDEX IF NOT EXISTS addon_auction_sales_realm
        ON marketlens_import.addon_auction_sales (source_game, region, realm, faction, sold_at DESC)""")


def market_regions(cur, source_game, cache):
    """{lower realm: {regions}} from the site's market keys; an unprefixed key is US."""
    if ("markets", source_game) not in cache:
        cur.execute("SELECT DISTINCT game FROM marketlens_import.datasets WHERE source_game=%s", (source_game,))
        out = defaultdict(set)
        for (game,) in cur.fetchall():
            m = re.match(r"^realm:(?:(us|eu|kr|tw|cn):[^:]+:)?(.+)-(?:Alliance|Horde|Neutral)$", game)
            if m:
                out[m.group(2).lower()].add(m.group(1) or "us")
        cache[("markets", source_game)] = out
    return cache[("markets", source_game)]


def region_for(cur, edition, realm, cache):
    """The realm's region, or None when it can't be told apart (same name in two regions)."""
    key = (edition, realm)
    if key in cache:
        return cache[key]
    name = realm or ""
    m = re.match(r"^CAMWOW\s+(US|EU|KR|TW)\d", name, re.I)
    found = {m.group(1).lower()} if m else set()
    if edition == "retail":
        # Blizzard's own realm list, not collected rows: retail collection regions have known mislabels.
        if "retail-list" not in cache:
            data = json.loads((REPO / "laragon-php" / "src" / "retail-connected-realms.json").read_text(encoding="utf-8"))
            names = defaultdict(set)
            for reg in ("us", "eu"):
                for cr in data.get(reg, []):
                    for r in cr.get("realms", []):
                        names[r["name"].lower()].add(reg)
            cache["retail-list"] = names
        found = set(cache["retail-list"].get(name.lower(), ()))
        if not found and re.search(r"[가-힣]", name):
            found = {"kr"}
        elif not found and re.search(r"[一-鿿]", name):
            found = {"tw"}
        cache[key] = found.pop() if len(found) == 1 else None
        return cache[key]
    if not found:
        cur.execute("""SELECT DISTINCT region FROM marketlens_import.census_collection_realms
            WHERE source_game=%s AND lower(realm_name)=lower(%s)""", (legacy.SOURCES[edition], name))
        found = {r[0] for r in cur.fetchall()}
    if not found:
        found = set(market_regions(cur, legacy.SOURCES[edition], cache).get(name.lower(), ()))
    if not found and re.search(r"[가-힣]", name):
        found = {"kr"}
    elif not found and re.search(r"[一-鿿]", name):
        found = {"tw"}
    cache[key] = found.pop() if len(found) == 1 else None
    return cache[key]


def character_region(cur, edition, char, realm, cache):
    """The census's own sighting of this character on this realm, if it is in one region only."""
    if not char or not realm:
        return None
    key = ("char", edition, char, realm)
    if key not in cache:
        cur.execute("""SELECT DISTINCT collection_region FROM marketlens_import.characters_with_region
            WHERE source_game=%s AND name=%s AND realm=%s AND collection_region IS NOT NULL""",
                    (legacy.SOURCES[edition], char, realm))
        found = {r[0] for r in cur.fetchall()}
        cache[key] = found.pop() if len(found) == 1 else None
    return cache[key]


def account_region(cur, edition, path, rows, cache):
    """Region for realm names that exist in two regions (Illidan, Stormrage): the one every
    unambiguous realm of the same account points to, from the file's own rows and the account's
    WTF realm folders. None unless the vote is unanimous. CJK realms (TW/KR) don't vote: one
    Battle.net account can hold US and TW characters."""
    names = {r.get("realm") for _, r in rows if r.get("realm")}
    parts = Path(path).resolve().parts
    if "Account" in parts and parts[-2:-1] == ("SavedVariables",):
        account_dir = Path(*parts[:-2])
        names |= {d.name for d in account_dir.iterdir() if d.is_dir() and d.name not in ("SavedVariables",)}
    votes = set()
    for name in names:
        if re.search(r"[가-힣一-鿿]", name):
            continue
        reg = region_for(cur, edition, name, cache)
        if reg:
            votes.add(reg)
    return votes.pop() if len(votes) == 1 else None


def source_game_for(edition, realm):
    if edition == "classic-era":
        g = legacy.ERA_SEASON_REALMS.get(realm or "")
        if g:
            return g
    return legacy.SOURCES[edition]


def event_key(edition, region, section, row, occ):
    body = json.dumps(canon(row), sort_keys=True, ensure_ascii=False, separators=(",", ":"))
    ident = "|".join(map(str, (edition, region, row.get("realm"), row.get("char"), section, row.get("t"), occ, body)))
    return hashlib.sha256(ident.encode("utf-8")).hexdigest()


def fill_item_ids(cur):
    """Journalator sale rows often carry only the item name: borrow the id from another ledger
    row of the same name and game (a posting, a failure), then from the game's auction scans."""
    cur.execute("""UPDATE marketlens_import.addon_ledger_events e SET item_id = s.item_id
        FROM (SELECT DISTINCT ON (source_game, item_name) source_game, item_name, item_id
              FROM marketlens_import.addon_ledger_events
              WHERE item_id IS NOT NULL AND item_name IS NOT NULL
              ORDER BY source_game, item_name, t DESC) s
        WHERE e.item_id IS NULL AND e.item_name IS NOT NULL
          AND e.source_game = s.source_game AND e.item_name = s.item_name""")
    borrowed = cur.rowcount
    cur.execute("""UPDATE marketlens_import.addon_ledger_events e SET item_id = s.item_id
        FROM (SELECT source_game, name, min(item_id) AS item_id
              FROM marketlens_import.local_auction_snapshots
              WHERE source_game IN (SELECT DISTINCT source_game FROM marketlens_import.addon_ledger_events
                                    WHERE item_id IS NULL AND item_name IS NOT NULL)
              GROUP BY 1, 2 HAVING count(DISTINCT item_id) = 1) s
        WHERE e.item_id IS NULL AND e.item_name IS NOT NULL
          AND e.source_game = s.source_game AND e.item_name = s.name""")
    return borrowed + cur.rowcount


def rebuild_sales(cur, groups):
    """Recompute addon_auction_sales for each (edition, region, realm, character) touched."""
    made = 0
    for edition, region, realm, char in groups:
        cur.execute("""DELETE FROM marketlens_import.addon_auction_sales s USING marketlens_import.addon_ledger_events e
            WHERE s.event_key=e.event_key AND e.edition=%s AND e.region=%s AND e.realm IS NOT DISTINCT FROM %s
              AND e.character IS NOT DISTINCT FROM %s""", (edition, region, realm, char))
        cur.execute("""SELECT event_key, source_game, faction, section, t, item_id, item_name, quantity, money, row
            FROM marketlens_import.addon_ledger_events
            WHERE edition=%s AND region=%s AND realm IS NOT DISTINCT FROM %s AND character IS NOT DISTINCT FROM %s
              AND section IN ('Invoices','Posting','TSM:sale') ORDER BY t""", (edition, region, realm, char))
        rows = cur.fetchall()
        posts = defaultdict(list)       # (name, qty) -> [[t, buyout, bid, used]]; sale rows may lack an item id
        for key, sg, fac, section, t, item, name, qty, money, row in rows:
            if section == "Posting":
                posts[(name, qty)].append([t, money, to_int(row.get("bid")), False])
        for key, sg, fac, section, t, item, name, qty, money, row in rows:
            if section == "Posting" or not qty or money is None or money <= 0:
                continue
            if section == "Invoices" and row.get("invoiceType") != "seller":
                continue
            posted = None
            if section == "Invoices":
                # Oldest unused posting of the same stack that this sale could have come from:
                # sold at buyout, or won with a bid between the posted bid and buyout.
                for p in posts.get((name, qty), []):
                    if p[3] or p[0] > t or t - p[0] > SALE_WINDOW:
                        continue
                    if p[1] == money or (p[2] is not None and p[2] <= money and (p[1] is None or money <= p[1])):
                        p[3], posted = True, p[0]
                        break
            cur.execute("""INSERT INTO marketlens_import.addon_auction_sales VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
                        (key, edition, sg, region, realm, fac, item, name, qty, money, round(money / qty), t,
                         posted, (t - posted) if posted is not None else None,
                         "journalator" if section == "Invoices" else "tsm"))
            made += 1
    return made


def rebuild_loot(cur):
    """Recompute addon_loot_sources / addon_loot_items from the whole ledger.

    One source row per loot window (a corpse or world object, keyed by its NPC or object id) and per
    quest turn-in, each with the items it gave. Windows that gave only coin still count: they are the
    kills an item did not drop from."""
    cur.execute("DELETE FROM marketlens_import.addon_loot_sources")
    cur.execute(r"""INSERT INTO marketlens_import.addon_loot_sources
        SELECT event_key, edition, source_game, region, kind, src::bigint, name, t, money FROM (
          SELECT event_key, edition, source_game, region, row->>'type' AS kind,
                 coalesce(row->>'npcID', row->>'objectID') AS src, row->>'name' AS name, t, (CASE WHEN row->>'money' ~ '^-?[0-9]{1,15}$' THEN (row->>'money')::bigint END) AS money
            FROM marketlens_import.addon_ledger_events
           WHERE section='LootContainers' AND row->>'type' IN ('npc','world')
          UNION ALL
          SELECT event_key, edition, source_game, region, 'quest', row->>'questID', row->>'questName', t, (CASE WHEN row->>'rewardMoney' ~ '^-?[0-9]{1,15}$' THEN (row->>'rewardMoney')::bigint END)
            FROM marketlens_import.addon_ledger_events
           WHERE section='Questing' AND row->>'state'='turned in') s
        WHERE src ~ '^[0-9]{1,12}$' AND src::bigint > 0""")
    sources = cur.rowcount
    cur.execute(r"""INSERT INTO marketlens_import.addon_loot_items
        SELECT s.event_key, s.source_game, (it->>'itemID')::int, sum(greatest(coalesce((CASE WHEN it->>'quantity' ~ '^-?[0-9]{1,15}$' THEN (it->>'quantity')::bigint END), 1), 1))
          FROM marketlens_import.addon_loot_sources s
          JOIN marketlens_import.addon_ledger_events e ON e.event_key=s.event_key
          CROSS JOIN LATERAL (SELECT e.row->CASE WHEN s.kind='quest' THEN 'rewardItems' ELSE 'items' END AS x) l
          CROSS JOIN LATERAL (  -- SavedVariables lists arrive as JSON arrays or as {"1": ..., "2": ...}
            SELECT value FROM jsonb_array_elements(CASE WHEN jsonb_typeof(l.x)='array' THEN l.x ELSE '[]'::jsonb END)
            UNION ALL SELECT value FROM jsonb_each(CASE WHEN jsonb_typeof(l.x)='object' THEN l.x ELSE '{}'::jsonb END)) v(it)
         WHERE it->>'itemID' ~ '^[0-9]{1,9}$'
         GROUP BY 1, 2, 3""")
    return sources, cur.rowcount


def find_files(paths):
    out = []
    for p in map(Path, paths):
        if p.is_dir():
            out += sorted(x for x in p.rglob("*.lua") if x.name in ("Journalator.lua", "WoWCensus.lua")
                          or x.parent.name in ("journalator", "tsm", "auctionator"))
        else:
            out.append(p)
    return out


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("paths", nargs="+")
    ap.add_argument("--region", choices=REGIONS, help="region for every row whose realm region cannot be worked out (the last resort)")
    ap.add_argument("--edition", help="edition when the path can't say (forever, tbc-anniversary, ...)")
    ap.add_argument("--realm-region", action="append", default=[], metavar="REALM=REGION",
                    help="region for a realm name that exists in two regions, e.g. Illidan=us (repeatable)")
    ap.add_argument("--dsn", default=pg.DEFAULT_DSN)
    ap.add_argument("--archive", help="ignored (accepted so tools/import-live.py can run this)")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()
    if args.edition and args.edition not in EDITIONS:
        raise SystemExit(f"--edition {args.edition!r}: expected one of {sorted(set(EDITIONS.values()))}")
    overrides = {}
    for item in args.realm_region:
        realm, _, reg = item.rpartition("=")
        if not realm or reg not in REGIONS:
            raise SystemExit(f"--realm-region {item!r}: expected REALM=us|eu|kr|tw|cn")
        overrides[realm.strip().lower()] = reg

    conn = pg.psycopg2.connect(args.dsn)
    conn.autocommit = False
    cur = conn.cursor()
    cur.execute("SELECT pg_advisory_lock(hashtext('marketlens-import'))")
    conn.commit()
    schema(cur)
    cur.execute("SELECT sha256 FROM marketlens_import.addon_ledger_sources")
    seen_files = {r[0] for r in cur.fetchall()}

    report, groups, cache = {"files": [], "dryRun": args.dry_run}, set(), {}
    for path in find_files(args.paths):
        data = path.read_bytes()
        digest = hashlib.sha256(data).hexdigest()
        entry = {"file": str(path), "sha256": digest}
        report["files"].append(entry)
        if digest in seen_files:
            entry["result"] = "already imported"
            continue
        seen_files.add(digest)
        kind, edition, region, rows = rows_from_file(path)
        edition = EDITIONS.get(args.edition) if args.edition else edition
        entry.update(kind=kind, edition=edition, rows=len(rows))
        if kind is None:
            entry["result"] = "skipped: not a Journalator or WoWCensus save"
            continue
        if not edition:
            raise SystemExit(f"{path}: edition unknown; pass --edition")
        occ, new, per, problems = defaultdict(int), 0, defaultdict(int), defaultdict(int)
        fallback = region or account_region(cur, edition, path, rows, cache) or args.region
        if not region and fallback:
            entry["ambiguousRealmsRegion"] = fallback
        for section, row in rows:
            t = to_int(row.get("t"))
            if t is None:
                continue
            row["t"] = t
            realm = row.get("realm")
            reg = (region or overrides.get((realm or "").lower()) or region_for(cur, edition, realm, cache)
                   or character_region(cur, edition, row.get("char"), realm, cache) or fallback)
            if reg not in REGIONS:
                problems[str(realm)] += 1
                continue
            base = (edition, reg, realm, row.get("char"), section, t, json.dumps(canon(row), sort_keys=True))
            occ[base] += 1
            key = event_key(edition, reg, section, row, occ[base])
            item, name, qty, money = measures(section, row)
            cur.execute("""INSERT INTO marketlens_import.addon_ledger_events
                (event_key, edition, source_game, region, realm, faction, character, section, t,
                 item_id, item_name, quantity, money, row, source_sha256)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) ON CONFLICT (event_key) DO NOTHING""",
                        (key, edition, source_game_for(edition, realm), reg, realm, row.get("faction"), row.get("char"),
                         section, t, item, name, qty, money, json.dumps(row, ensure_ascii=False), digest))
            if cur.rowcount:
                new += 1
                per[section] += 1
                groups.add((edition, reg, realm, row.get("char")))
        if problems:
            # Kept out rather than guessed, and the file stays unrecorded so a rerun with
            # --region picks those rows up (rows already stored dedupe by event key).
            entry.update(result="partial", newRows=new, skippedRegionUnknown=dict(problems),
                         newBySection=dict(sorted(per.items())))
            seen_files.discard(digest)
            continue
        cur.execute("INSERT INTO marketlens_import.addon_ledger_sources (sha256, kind, path, edition, region, rows, new_rows) VALUES (%s,%s,%s,%s,%s,%s,%s)",
                    (digest, kind, str(path), edition, region or args.region, len(rows), new))
        entry.update(result="imported", newRows=new, newBySection=dict(sorted(per.items())))

    report["itemIdsFilled"] = fill_item_ids(cur)
    report["salesRebuilt"] = rebuild_sales(cur, groups)
    report["lootSources"], report["lootItems"] = rebuild_loot(cur)
    cur.execute("""SELECT count(*), count(seconds_to_sell) FROM marketlens_import.addon_auction_sales""")
    total, timed = cur.fetchone()
    report["salesTotal"], report["salesWithTimeToSell"] = total, timed
    print(json.dumps(report, indent=1, ensure_ascii=False))
    if args.dry_run:
        conn.rollback()
        print("DRY RUN: rolled back")
        return
    conn.commit()
    print("CHANGED sources=addon-ledger" if groups else "Nothing new")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"IMPORT FAILED, nothing was written: {exc}", file=sys.stderr, flush=True)
        raise
