#!/usr/bin/env python3
"""WoW: Forever auction-house catalog in PostgreSQL.

    python -B tools/forever-auction-houses-pg.py [--show]

Two tables in marketlens_import:

forever_auction_houses: one row per API house id (110-125, no 122). The
API returns the same 15 houses under every region and API ruleset, so a
house id is an economy, not a realm. Five economies of Alliance / Horde /
Blackwater (Neutral). `client_ruleset` is the ruleset value the client's
AuctionHouseRedirect table points at the economy's Alliance house
(2->110, 3->111, 4->119, 5->112; from client-data research). `ruleset` is
the label we believe the economy serves and `confidence` says how sure:
  verified  rare listings matched an addon scan of that ruleset and faction
  inferred  follows from the client table and which houses have listings,
            but no addon scan has confirmed it yet
  unknown   nothing ties it to a ruleset

forever_auction_house_markets: one row per (region, house) that is cleared
for publication, pointing at the site market key (datasets.game without
'realm:'). tools/import-forever-ah-csv-pg.py imports only these houses into
'forever'; every other house stays in 'forever-unmapped', which no page
reads. tools/map-forever-house-pg.py adds a row here when it publishes a
newly verified house.

Running this file creates the tables if needed and upserts the catalog
(the economy rows always; a market row only if absent). The importer and
map-house call ensure() themselves, so the live database gets the tables on
its next import.
"""

import argparse
import importlib.util
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent

# house_id: (economy, faction, client_ruleset, ruleset, confidence, evidence)
# Economy 2 (111/114) was verified 2026-11-10; it is client value 3, which the
# client's ruleset picker lists second (PvP, Normal, Roleplay, Hardcore). Read
# in that order, 2=PvP, 3=Normal, 4=Roleplay, 5=Hardcore. US has four live
# rulesets (PvP, Normal, RP, RP-PvP) and its auction CSVs carry exactly four
# economies: 110, 111, 119 and 123 (and their Horde houses), never 112/115.
# That fits 112/115/118 being the unlaunched Hardcore economy and 123/124/125
# (outside the client table) being RP-PvP. All of this is inferred until an
# addon scan from that ruleset matches.
_INFERRED = "client AuctionHouseRedirect value {v} read in picker order; listings present in US CSVs"
HOUSES = {
    110: (1, "Alliance", 2, "PvP", "inferred", _INFERRED.format(v=2)),
    113: (1, "Horde", None, "PvP", "inferred", "Horde house of economy 1; listings present in US CSVs"),
    116: (1, "Neutral", None, "PvP", "inferred", "Blackwater house of economy 1"),
    111: (2, "Alliance", 3, "Normal", "verified",
          "2026-11-10: 42 exact rare listings vs addon scan of CAMWOW US2 Realm 5 PvE-Alliance; other houses <= 3. "
          "2026-11-15 EU: 227 of 395 vs CAMWOW EU4 Realm 7 PvE-Alliance; other houses <= 4"),
    114: (2, "Horde", None, "Normal", "verified",
          "2026-11-10: 294 of 502 exact rare listings vs addon scan of CAMWOW US2 Realm 5 PvE-Horde; other houses <= 5. "
          "2026-11-15 EU: 303 of 436 vs CAMWOW EU4 Realm 7 PvE-Horde; other houses <= 3"),
    117: (2, "Neutral", None, "Normal", "inferred", "Blackwater house of the verified Normal economy"),
    112: (3, "Alliance", 5, "Hardcore", "inferred",
          "client AuctionHouseRedirect value 5 read in picker order; no listings in any US CSV (ruleset not launched)"),
    115: (3, "Horde", None, "Hardcore", "inferred", "Horde house of economy 3; no listings in any US CSV"),
    118: (3, "Neutral", None, "Hardcore", "inferred", "Blackwater house of economy 3"),
    119: (4, "Alliance", 4, "Roleplay", "inferred", _INFERRED.format(v=4)),
    120: (4, "Horde", None, "Roleplay", "inferred", "Horde house of economy 4; listings present in US CSVs"),
    121: (4, "Neutral", None, "Roleplay", "inferred", "Blackwater house of economy 4"),
    123: (5, "Alliance", None, "Roleplay PvP", "inferred",
          "not in the client redirect table; the only remaining economy with US listings and RP-PvP the only remaining US ruleset"),
    124: (5, "Horde", None, "Roleplay PvP", "inferred", "Horde house of economy 5; listings present in US CSVs"),
    125: (5, "Neutral", None, "Roleplay PvP", "inferred", "Blackwater house of economy 5"),
}

# (region, house_id): (market key, verified_on, evidence). Only verified houses.
MARKETS = {
    ("us", 111): ("CAMWOW US2 Realm 5 PvE-Alliance", "2026-11-10", HOUSES[111][5]),
    ("us", 114): ("CAMWOW US2 Realm 5 PvE-Horde", "2026-11-10", HOUSES[114][5]),
    ("eu", 111): ("eu:forever:CAMWOW EU4 Realm 7 PvE-Alliance", "2026-11-15",
                  "2026-11-15: 227 of 395 exact rare listings vs addon scan of CAMWOW EU4 Realm 7 PvE-Alliance "
                  "(20:38 UTC) and the EU API at 21:38 UTC; other houses <= 4; 112/115 empty in EU too"),
    ("eu", 114): ("eu:forever:CAMWOW EU4 Realm 7 PvE-Horde", "2026-11-15",
                  "2026-11-15: 303 of 436 exact rare listings vs addon scan of CAMWOW EU4 Realm 7 PvE-Horde "
                  "(21:47 UTC) and the EU API at 21:49 UTC; other houses <= 3"),
}

HOUSE_FACTION = {h: v[1] for h, v in HOUSES.items()}


def ensure(cur, schema="marketlens_import"):
    """Create and seed both tables with a psycopg2 cursor; leaves the transaction open."""
    cur.execute(f"""CREATE TABLE IF NOT EXISTS "{schema}".forever_auction_houses (
        house_id integer PRIMARY KEY,
        economy smallint NOT NULL,
        faction text NOT NULL CHECK (faction IN ('Alliance','Horde','Neutral')),
        client_ruleset smallint,
        ruleset text,
        confidence text NOT NULL CHECK (confidence IN ('verified','inferred','unknown')),
        evidence text,
        updated_at timestamptz NOT NULL DEFAULT now())""")
    cur.execute(f"""CREATE TABLE IF NOT EXISTS "{schema}".forever_auction_house_markets (
        region text NOT NULL CHECK (region IN ('us','eu','kr','tw')),
        house_id integer NOT NULL REFERENCES "{schema}".forever_auction_houses(house_id),
        market text NOT NULL,
        verified_on date NOT NULL,
        evidence text,
        PRIMARY KEY (region, house_id))""")
    for house, (economy, faction, client, ruleset, confidence, evidence) in HOUSES.items():
        # A house verified in the database (by map-house) is never downgraded here.
        cur.execute(f"""INSERT INTO "{schema}".forever_auction_houses AS h
                (house_id, economy, faction, client_ruleset, ruleset, confidence, evidence)
            VALUES (%s,%s,%s,%s,%s,%s,%s)
            ON CONFLICT (house_id) DO UPDATE SET economy=EXCLUDED.economy, faction=EXCLUDED.faction,
                client_ruleset=EXCLUDED.client_ruleset, ruleset=EXCLUDED.ruleset,
                confidence=EXCLUDED.confidence, evidence=EXCLUDED.evidence, updated_at=now()
            WHERE h.confidence <> 'verified' OR EXCLUDED.confidence = 'verified'""",
                    (house, economy, faction, client, ruleset, confidence, evidence))
    for (region, house), (market, day, evidence) in MARKETS.items():
        cur.execute(f"""INSERT INTO "{schema}".forever_auction_house_markets VALUES (%s,%s,%s,%s,%s)
            ON CONFLICT DO NOTHING""", (region, house, market, day, evidence))


def verified_markets(cur, schema="marketlens_import"):
    """{(region, house_id): market key} for every house cleared for publication."""
    cur.execute(f'SELECT region, house_id, market FROM "{schema}".forever_auction_house_markets')
    return {(r, h): m for r, h, m in cur.fetchall()}


def record_market(cur, region, house, market, evidence, schema="marketlens_import"):
    """Mark a house verified and cleared for publication in region."""
    cur.execute(f"""UPDATE "{schema}".forever_auction_houses SET confidence='verified',
        evidence=COALESCE(%s, evidence), updated_at=now() WHERE house_id=%s""", (evidence, house))
    cur.execute(f"""INSERT INTO "{schema}".forever_auction_house_markets VALUES (%s,%s,%s,current_date,%s)
        ON CONFLICT (region, house_id) DO UPDATE SET market=EXCLUDED.market,
            verified_on=EXCLUDED.verified_on, evidence=EXCLUDED.evidence""", (region, house, market, evidence))


def main():
    spec = importlib.util.spec_from_file_location("import_pg", REPO / "tools" / "import-savedvariables-pg.py")
    pg = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(pg)
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--dsn", default=pg.DEFAULT_DSN)
    parser.add_argument("--schema", default="marketlens_import")
    parser.add_argument("--show", action="store_true", help="print the tables without writing")
    args = parser.parse_args()
    conn = pg.psycopg2.connect(args.dsn)
    cur = conn.cursor()
    if not args.show:
        ensure(cur, args.schema)
        conn.commit()
    cur.execute(f"""SELECT h.house_id, h.economy, h.faction, h.client_ruleset, h.ruleset, h.confidence,
            string_agg(m.region || '=' || m.market, '; ')
        FROM "{args.schema}".forever_auction_houses h
        LEFT JOIN "{args.schema}".forever_auction_house_markets m USING (house_id)
        GROUP BY 1,2,3,4,5,6 ORDER BY economy, house_id""")
    for row in cur.fetchall():
        print(*("" if v is None else v for v in row), sep="\t")


if __name__ == "__main__":
    sys.exit(main())
