#!/usr/bin/env python3
"""Refresh Blizzard-backed auction markets using their API snapshot times."""
import argparse
import hashlib
import json
import os
import runpy
import shutil
from collections import defaultdict
from email.utils import parsedate_to_datetime
from itertools import islice
from pathlib import Path
from urllib.error import HTTPError

import psycopg2
from psycopg2.extras import execute_values

TOOLS = Path(__file__).resolve().parent
API = runpy.run_path(str(TOOLS / "import-retail-ah-api-pg.py"))
PG = runpy.run_path(str(TOOLS / "import-savedvariables-pg.py"))
HOUSES = runpy.run_path(str(TOOLS / "forever-auction-houses-pg.py"))
PAGES = {"forever": "/forever/auctionhouse", "retail": "/retail/auctionhouse",
         "mop-classic": "/mop/auctionhouse"}
REGIONS = ("us", "eu", "kr", "tw")


def fetch(token, region, namespace, path):
    return API["request"](
        f"https://{region}.api.blizzard.com/data/wow/{path}?namespace={namespace}-{region}&locale=en_US",
        token=token)


def timestamp(headers):
    value = next((value for key, value in headers.items() if key.lower() == "last-modified"), None)
    return int(parsedate_to_datetime(value).timestamp()) if value else None


def markets(token, source, region, catalog):
    namespace = "dynamic" if source == "retail" else "dynamic-classic"
    if source == "retail":
        yield 0, ["Region-wide commodities"], "auctions/commodities"
        if region in catalog:
            for group in catalog[region]:
                market_id = int(group["id"])
                yield market_id, [r["name"] for r in group["realms"]], f"connected-realm/{market_id}/auctions"
            return
    raw, _ = fetch(token, region, namespace, "connected-realm/index")
    for entry in json.loads(raw)["connected_realms"]:
        market_id = int(entry["href"].split("?")[0].rsplit("/", 1)[-1])
        group, _ = fetch(token, region, namespace, f"connected-realm/{market_id}")
        yield market_id, [r["name"] for r in json.loads(group)["realms"]], f"connected-realm/{market_id}/auctions"


def forever_markets(token, region):
    for ruleset in range(1, 6):
        try:
            raw, _ = fetch(token, region, "dynamic-forever", f"ruleset/{ruleset}/auctions/")
        except HTTPError as error:
            if error.code in (403, 404):
                continue
            raise
        houses = json.loads(raw).get("auctions", [])
        if houses:
            for house in houses:
                if house["id"] in HOUSES["HOUSE_FACTION"]:
                    yield house["id"], f"ruleset/{ruleset}/auctions/{house['id']}"
            return


def store_forever(conn, region, house, responses, mapping, names):
    prices = defaultdict(list)
    times = []
    digest = hashlib.sha256()
    for raw, headers in responses:
        digest.update(hashlib.sha256(raw).digest())
        rows = json.loads(raw).get("auctions", [])
        if rows:
            captured = timestamp(headers)
            if captured is None:
                return 0
            times.append(captured)
        for row in rows:
            item = row["item"]["id"]
            quantity = row["quantity"]
            if quantity > 0 and item > 0:
                price = row.get("unit_price") or row.get("buyout", 0) // quantity
                if price:
                    prices[item].append((price, quantity))
    if not prices:
        return 0
    captured = min(times)
    digest = digest.hexdigest()
    items = {}
    for item, listings in prices.items():
        quantity = sum(q for _, q in listings)
        items[item] = [captured, quantity, len(listings), 0, min(p for p, _ in listings),
                       API["weighted_median"](listings), sum(p * q for p, q in listings) // quantity, 0]

    bucket = f"api:{region}:house-{house}"
    realm = mapping.get((region, house))
    faction = HOUSES["HOUSE_FACTION"][house]
    game = f"realm:{realm}" if realm else f"house:{region}:{house}-{faction}"
    source = "forever" if realm else "forever-unmapped"
    db = PG["PgDb"](conn)
    with conn, conn.cursor() as cur:
        cur.execute("SET search_path TO marketlens_import, public")
        cur.execute("SELECT pg_advisory_xact_lock(hashtext('marketlens-import'))")
        cur.execute("SELECT 1 FROM local_imports WHERE source_sha256=%s AND kind=%s AND bucket=%s",
                    (digest, "blizzard-forever-auctions", bucket))
        if cur.fetchone():
            return 0
        if realm:
            cur.execute("SELECT updated_at FROM datasets WHERE game=%s AND source_game='forever'", (game,))
            existing = cur.fetchone()
            if not existing:
                raise ValueError(f"Verified Forever market is missing its dataset: {game}")
            legacy = PG["legacy"]
            if existing[0] and legacy.unix(existing[0]) >= captured:
                return 0
            cur.execute("SELECT id,cat,src,crafter FROM items WHERE game=%s", (game,))
            metadata = {item: (category, label, crafter) for item, category, label, crafter in cur}
            body = {"realm": realm, "capabilities": {"sellers": False}, "items": {}}
            for item, point in items.items():
                category, label, crafter = metadata.get(item, (None, None, None))
                body["items"][item] = {"n": names.get(item), "m": category,
                                       "src": label, "cr": crafter, "s": [point]}
            details = legacy.import_market(db, body, digest, source)
        else:
            execute_values(cur, """INSERT INTO local_auction_snapshots
                (source_game,game,item_id,captured_at,name,category,item_source,crafter,quantity,auctions,
                 sellers,lowest,median,weighted_average,top_seller_concentration,source_sha256)
                VALUES %s ON CONFLICT DO NOTHING""",
                [(source, game, item, captured, names.get(item, f"item:{item}"), None, None, None,
                  *point[1:], digest) for item, point in items.items()])
            details = {"game": game, "items": len(items)}
        PG["legacy"].log_import(db, digest, "blizzard-forever-auctions", bucket, source, details)
    return len(items)


def queue_page(conn, source):
    with conn, conn.cursor() as cur:
        cur.execute("""INSERT INTO marketlens_import.hourly_armory_cache(path) VALUES (%s)
            ON CONFLICT (path) DO UPDATE SET queued_at=excluded.queued_at""", (PAGES[source],))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dsn", default=API["DEFAULT_DSN"])
    parser.add_argument("--source", choices=PAGES, action="append")
    parser.add_argument("--region", choices=REGIONS, action="append")
    parser.add_argument("--limit", type=int, help="Maximum markets per edition and region for a smoke run")
    parser.add_argument("--market", type=int, action="append", help="Retry named market IDs for one edition and region")
    parser.add_argument("--publish", action="store_true", help="Rebuild and purge auction pages on this origin")
    parser.add_argument("--cache-only", action="store_true", help="Rebuild pages without fetching auctions")
    parser.add_argument("--storage-path", type=Path)
    args = parser.parse_args()
    if args.limit is not None and args.limit < 1:
        parser.error("--limit must be positive")
    if args.market and (len(args.source or []) != 1 or len(args.region or []) != 1):
        parser.error("--market requires one --source and one --region")
    if args.storage_path and shutil.disk_usage(args.storage_path).free < 12 * 1024**3:
        raise SystemExit("Auction refresh deferred: PostgreSQL volume has less than 12 GiB free")
    sources = args.source or PAGES
    publish = args.publish or args.cache_only
    conn = psycopg2.connect(args.dsn, application_name="wowcensus-daily-auctions")
    with conn, conn.cursor() as cur:
        cur.execute("SELECT pg_try_advisory_lock(hashtext('wowcensus-daily-auctions'))")
        if not cur.fetchone()[0]:
            raise SystemExit("A daily auction refresh is already running")
        mapping = HOUSES["verified_markets"](cur, "marketlens_import")
        if publish:
            cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.hourly_armory_cache
                (path text PRIMARY KEY, queued_at timestamptz NOT NULL DEFAULT now())""")
    failures = 0
    if args.cache_only:
        for source in sources:
            queue_page(conn, source)
    else:
        failures = refresh_markets(conn, args, sources, mapping)
    if publish:
        armory = runpy.run_path(str(TOOLS / "hourly-armory-refresh.py"))
        armory["refresh_cache"](conn, [PAGES[source] for source in sources], page_timeout=180)
    conn.close()
    if failures:
        raise SystemExit(f"{failures} auction refresh errors")


def refresh_markets(conn, args, sources, mapping):
    client_id, secret = API["credentials"]()
    raw, _ = API["request"]("https://oauth.battle.net/token", data=b"grant_type=client_credentials",
                             basic=f"{client_id}:{secret}")
    token = json.loads(raw)["access_token"]
    catalog_path = Path(os.getenv("WOWCENSUS_RETAIL_REALMS", API["CATALOG"]))
    catalog = json.loads(catalog_path.read_text(encoding="utf-8"))
    failures = 0
    for source in sources:
        with conn, conn.cursor() as cur:
            cur.execute("SET search_path TO marketlens_import, public")
            names = API["item_names"](cur, source)
        updated = 0
        for region in args.region or REGIONS:
            jobs = (forever_markets(token, region) if source == "forever"
                    else markets(token, source, region, catalog))
            if args.market:
                jobs = (job for job in jobs if job[0] in args.market)
            market_id = None
            try:
                for job in islice(jobs, args.limit):
                    market_id = job[0]
                    try:
                        if source == "forever":
                            path = job[1]
                            responses = [fetch(token, region, "dynamic-forever", path + suffix)
                                         for suffix in ("", "/commodities")]
                            count = store_forever(conn, region, market_id, responses, mapping, names)
                        else:
                            _, members, path = job
                            namespace = "dynamic" if source == "retail" else "dynamic-classic"
                            raw, headers = fetch(token, region, namespace, path)
                            captured = timestamp(headers)
                            if captured is None:
                                continue
                            kind = ("blizzard-retail-commodities" if market_id == 0 else
                                    "blizzard-retail-auctions" if source == "retail" else "blizzard-mop-auctions")
                            count = API["store_snapshot"](
                                conn, raw, captured, game=f"realm:{region}:{source}:{members[0]}",
                                source=source, region=region, market_id=market_id, members=members,
                                names=names, kind=kind, skip_current=True)
                        if count:
                            if args.publish and not updated:
                                queue_page(conn, source)
                            updated += 1
                    except HTTPError as error:
                        if error.code not in (403, 404):
                            failures += 1
                        print(f"{source} {region} market {market_id}: HTTP {error.code}", flush=True)
                        if error.code in (401, 429):
                            break
                    except (OSError, ValueError, KeyError) as error:
                        failures += 1
                        print(f"{source} {region} market {market_id}: {error}", flush=True)
            except (OSError, ValueError, KeyError) as error:
                failures += 1
                print(f"{source} {region} market {market_id}: {error}", flush=True)
        print(f"{source}: {updated} markets updated", flush=True)
        if args.publish and updated:
            queue_page(conn, source)
    return failures


if __name__ == "__main__":
    main()
