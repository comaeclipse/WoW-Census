#!/usr/bin/env python3
"""Import a Blizzard Retail connected-realm auction snapshot into PostgreSQL.

Examples:
    python -B tools/import-retail-ah-api-pg.py --region us --realm Illidan
    python -B tools/import-retail-ah-api-pg.py --region eu --connected-realm 1403

The Game Data API is cross-faction. Non-commodity auctions are scoped to a
connected realm; region-wide commodities are deliberately not mixed into these
rows. Credentials come from BLIZZARD_CLIENT_ID/SECRET or the repository .env.
"""

import argparse
import base64
import hashlib
import json
import os
import re
import time
import urllib.parse
import urllib.request
import urllib.error
from collections import defaultdict
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
from pathlib import Path

import psycopg2
import psycopg2.extras

REPO = Path(__file__).resolve().parent.parent
CATALOG = REPO / "laragon-php" / "src" / "retail-connected-realms.json"
DEFAULT_DSN = os.getenv("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")


def credentials():
    values = {}
    path = REPO / ".env"
    if path.is_file():
        for line in path.read_text(encoding="utf-8").splitlines():
            key, sep, value = line.partition("=")
            if sep:
                values[key.strip()] = value.strip().strip('"')
    client_id = os.getenv("BLIZZARD_CLIENT_ID") or values.get("BLIZZARD_CLIENT_ID")
    secret = os.getenv("BLIZZARD_CLIENT_SECRET") or values.get("BLIZZARD_CLIENT_SECRET")
    if not client_id or not secret:
        raise SystemExit("set BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET")
    return client_id, secret


def request(url, *, token=None, data=None, basic=None):
    for attempt in range(5):
        req = urllib.request.Request(url, data=data)
        if token:
            req.add_header("Authorization", "Bearer " + token)
        if basic:
            req.add_header("Authorization", "Basic " + base64.b64encode(basic.encode()).decode())
        try:
            with urllib.request.urlopen(req, timeout=120) as response:
                return response.read(), dict(response.headers)
        except urllib.error.HTTPError as error:
            if error.code != 429 or attempt == 4:
                raise
            time.sleep(int(error.headers.get("Retry-After") or 2 ** (attempt + 1)))


def realm_group(region, realm=None, connected_id=None):
    catalog = json.loads(CATALOG.read_text(encoding="utf-8"))
    groups = catalog.get(region, [])
    if connected_id is not None:
        return next((g for g in groups if int(g["id"]) == connected_id), None)
    wanted = re.sub(r"[^a-z0-9]", "", realm.lower())
    for group in groups:
        for member in group["realms"]:
            names = [member["name"], *(member.get("alt") or [])]
            if any(re.sub(r"[^a-z0-9]", "", name.lower()) == wanted for name in names):
                return group
    return None


def weighted_median(prices):
    total = sum(q for _, q in prices)
    at = 0
    for price, quantity in sorted(prices):
        at += quantity
        if at * 2 >= total:
            return price
    return 0


def slug(name):
    return re.sub(r"-+", "-", re.sub(r"[^a-z0-9]+", "-", name.lower())).strip("-")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--region", choices=("us", "eu", "kr", "tw"), required=True)
    target = parser.add_mutually_exclusive_group(required=True)
    target.add_argument("--realm")
    target.add_argument("--connected-realm", type=int)
    parser.add_argument("--dsn", default=DEFAULT_DSN)
    args = parser.parse_args()

    group = realm_group(args.region, args.realm, args.connected_realm)
    if not group:
        raise SystemExit("connected realm was not found in laragon-php/src/retail-connected-realms.json")
    connected_id = int(group["id"])
    members = [r["name"] for r in group["realms"]]
    anchor_name = members[0]
    game = f"realm:{args.region}:retail:{anchor_name}"

    client_id, secret = credentials()
    raw_token, _ = request("https://oauth.battle.net/token", data=b"grant_type=client_credentials",
                           basic=f"{client_id}:{secret}")
    token = json.loads(raw_token)["access_token"]
    query = urllib.parse.urlencode({"namespace": f"dynamic-{args.region}", "locale": "en_US"})
    raw, headers = request(
        f"https://{args.region}.api.blizzard.com/data/wow/connected-realm/{connected_id}/auctions?{query}",
        token=token)
    body = json.loads(raw)
    captured = int(parsedate_to_datetime(headers["Last-Modified"]).timestamp()) if headers.get("Last-Modified") \
        else int(datetime.now(timezone.utc).timestamp())
    digest = hashlib.sha256(raw).hexdigest()

    grouped = defaultdict(lambda: {"q": 0, "n": 0, "value": 0, "prices": []})
    excluded_battle_pets = 0
    for auction in body.get("auctions", []):
        # Every caged pet has base item 82800; pet_species_id is the identity.
        # The site's current item key is a numeric item ID, so combining species
        # would publish a fictitious Pet Cage market under whichever pet name was
        # cached most recently. Leave them out until variant keys are supported.
        if (auction.get("item") or {}).get("pet_species_id"):
            excluded_battle_pets += 1
            continue
        item_id = int((auction.get("item") or {}).get("id") or 0)
        quantity = int(auction.get("quantity") or 0)
        if not item_id or not quantity:
            continue
        total = int(auction.get("buyout") or auction.get("bid") or 0)
        unit = total // quantity if total else 0
        row = grouped[item_id]
        row["q"] += quantity
        row["n"] += 1
        row["value"] += total
        if unit:
            row["prices"].append((unit, quantity))

    conn = psycopg2.connect(args.dsn)
    try:
        with conn, conn.cursor() as cur:
            cur.execute("SET search_path TO marketlens_import, public")
            # Makes a retry of the same API snapshot repairable if aggregation
            # logic changes; other snapshots and import sources are untouched.
            cur.execute("DELETE FROM local_auction_snapshots WHERE game=%s AND captured_at=%s AND source_sha256=%s",
                        (game, captured, digest))
            cur.execute("SELECT item_id,name FROM local_auction_snapshots WHERE source_game='retail' AND name NOT LIKE 'item:%%' ORDER BY captured_at DESC")
            names = {}
            for item_id, name in cur.fetchall():
                names.setdefault(int(item_id), name)
            cur.execute("SELECT id,name FROM items WHERE name NOT LIKE 'item:%%'")
            for item_id, name in cur.fetchall():
                names.setdefault(int(item_id), name)
            cur.execute("SELECT id,q,updated_at FROM items WHERE game=%s", (game,))
            prior = {int(i): (int(q or 0), updated) for i, q, updated in cur.fetchall()}
            stamp = datetime.fromtimestamp(captured, timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
            snapshot_rows, item_rows, history_rows = [], [], []
            for item_id, data in grouped.items():
                quantity = data["q"]
                average = data["value"] // quantity if quantity else 0
                lowest = min((p for p, _ in data["prices"]), default=0)
                median = weighted_median(data["prices"])
                name = names.get(item_id, f"item:{item_id}")
                snapshot_rows.append(("retail", game, item_id, captured, name, None, "Blizzard Game Data API", None,
                                      quantity, data["n"], None, lowest, median, average, None, digest))
                previous = prior.get(item_id, (None, None))[0]
                item_rows.append((game, item_id, name, slug(name), data["value"], average, stamp,
                                  quantity, previous, None, None, None, "Blizzard Game Data API", None))
                history_rows.append((game, item_id, captured // 86400, data["value"], average, quantity))
            psycopg2.extras.execute_batch(cur, """INSERT INTO local_auction_snapshots
              (source_game,game,item_id,captured_at,name,category,item_source,crafter,quantity,auctions,sellers,lowest,median,weighted_average,top_seller_concentration,source_sha256)
              VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) ON CONFLICT DO NOTHING""", snapshot_rows, page_size=1000)
            psycopg2.extras.execute_batch(cur, """INSERT INTO history (game,id,ts,mv,asp,q) VALUES (%s,%s,%s,%s,%s,%s)
              ON CONFLICT(game,id,ts) DO UPDATE SET mv=excluded.mv,asp=excluded.asp,q=excluded.q""", history_rows, page_size=1000)
            psycopg2.extras.execute_batch(cur, """INSERT INTO items
              (game,id,name,slug,mv,asp,updated_at,q,pq,sc,tc,cat,src,crafter)
              VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
              ON CONFLICT(game,id) DO UPDATE SET name=excluded.name,slug=excluded.slug,mv=excluded.mv,asp=excluded.asp,
              updated_at=excluded.updated_at,q=excluded.q,pq=excluded.pq,sc=excluded.sc,tc=excluded.tc,cat=excluded.cat,src=excluded.src,crafter=excluded.crafter""",
              item_rows, page_size=1000)
            cur.execute("DELETE FROM items WHERE game=%s AND updated_at<>%s", (game, stamp))
            cur.execute("""INSERT INTO datasets(game,source_game,updated_at) VALUES (%s,'retail',%s)
              ON CONFLICT(game) DO UPDATE SET source_game='retail',updated_at=excluded.updated_at""", (game, stamp))
            details = json.dumps({"region": args.region, "connectedRealmId": connected_id, "members": members,
                                  "records": len(body.get("auctions", [])), "items": len(grouped),
                                  "excludedBattlePetAuctions": excluded_battle_pets})
            cur.execute("""INSERT INTO local_imports(source_sha256,kind,bucket,source_game,imported_at_utc,details_json)
              VALUES (%s,'blizzard-retail-auctions',%s,'retail',%s,%s) ON CONFLICT(source_sha256,kind,bucket)
              DO UPDATE SET imported_at_utc=excluded.imported_at_utc,details_json=excluded.details_json""",
                        (digest, f"{args.region}:{connected_id}", stamp, details))
            cur.execute("ANALYZE local_auction_snapshots")
            cur.execute("ANALYZE items")
        print(f"imported {len(body.get('auctions', [])):,} auctions as {len(grouped):,} items")
        print(f"connected realm {connected_id}: {', '.join(members)}")
        print(f"excluded {excluded_battle_pets:,} battle-pet auctions (species variants need a separate key)")
        print(f"game {game} captured {stamp} sha256 {digest}")
    finally:
        conn.close()


if __name__ == "__main__":
    main()
