#!/usr/bin/env python3
"""Check that every recent collection key has the right region.

    python -B tools/check-collection-regions.py [--days=30] [--dsn=...]

Keys without a region prefix ("realm:Thrall-Horde") get theirs from
collection_region_at(); WoWCensus keys carry the region the client reported
("realm:tw:retail:Doomhammer-Alliance"), which can also be wrong. This lists,
for keys with rows in the last --days:

  UNRESOLVED   rows the function leaves without a region (the stats page
               shows them as unknown, or guesses from a same-named realm);
  CONFLICT     a MarketLens bucket recorded from two regions
               (collection_key_regions.conflicts);
  MISMATCH     Retail only, labelled keys included: the characters seen on the
               key come from the other region's connected realms. Retail /who lists only the realm's
               connected group, and most realm names exist in both US and EU,
               so the home realms say which one was scanned.

Each problem line ends with the evidence. Exit status is 1 when anything is
listed. import-savedvariables-pg.py runs this after every import.
"""

import argparse
import json
import os
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

import psycopg2

if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="backslashreplace")

REPO = Path(__file__).resolve().parent.parent
CATALOG = REPO / "laragon-php" / "src" / "retail-connected-realms.json"
DEFAULT_DSN = os.environ.get("MARKETLENS_PG_DSN", "host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz")


def norm(name):
    return re.sub(r"[\s'\-]", "", (name or "").casefold())


def realm_of(game):
    return re.sub(r"-(Alliance|Horde|Neutral)$", "", re.sub(r"^realm:(?:(?:us|eu|kr|tw|cn):[^:]+:)?", "", game))


def game_realm(game):
    """Evidence pool for a key: region-less keys pool both factions by realm
    name; region-labelled keys stay apart from them and from the other region."""
    prefix = re.match(r"^realm:((?:us|eu|kr|tw|cn):[^:]+:)?", game).group(1) or ""
    return prefix, norm(realm_of(game))


def retail_groups():
    """{region: {normalized realm: set of normalized realms in its connected group}}

    Keyed by display name only: the localized alternates include Chinese names
    that also belong to Taiwan realms. Members include alternates, since /who
    in another client language reports those."""
    data = json.loads(CATALOG.read_text(encoding="utf-8"))
    groups = {}
    for region in ("us", "eu"):
        groups[region] = {}
        for group in data[region]:
            members = {norm(n) for r in group["realms"] for n in [r["name"], *r.get("alt", [])]}
            for r in group["realms"]:
                groups[region][norm(r["name"])] = members
    return groups


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--days", type=int, default=30)
    parser.add_argument("--dsn", default=DEFAULT_DSN)
    args = parser.parse_args()
    cur = psycopg2.connect(args.dsn).cursor()
    cur.execute("SET search_path TO marketlens_import")
    cutoff = f"extract(epoch from now() - interval '{int(args.days)} days')"

    # Recent rows per key and how many of them the function resolves to each region.
    cur.execute(f"""
      SELECT source_game, game, collection_region_at(game, t) AS region, count(*) FROM (
        SELECT source_game, game, observed_at AS t FROM population_sweep_queries WHERE observed_at >= {cutoff}
        UNION ALL
        SELECT source_game, game, captured_at FROM local_auction_snapshots WHERE captured_at >= {cutoff}
      ) rows WHERE game !~ '^realm:(us|eu|kr|tw|cn):' OR source_game = 'retail'
      GROUP BY 1, 2, 3""")
    keys = defaultdict(Counter)
    for source, game, region, n in cur.fetchall():
        keys[(source, game)][region] += n

    cur.execute("SELECT source_game, game, region, conflicts FROM collection_key_regions WHERE conflicts IS NOT NULL")
    conflicts = {(s, g): (r, c) for s, g, r, c in cur.fetchall()}

    # Home realms of the characters seen on each Retail realm, and the realms
    # named in their guilds' "-Realm" suffixes, both factions pooled.
    home = defaultdict(Counter)
    guilds = defaultdict(Counter)
    cur.execute("""SELECT game, realm, substring(guild from '-([^-]+)$'), count(*) FROM characters
      WHERE source_game = 'retail' GROUP BY 1, 2, 3""")
    for game, realm, guild_realm, n in cur.fetchall():
        home[game_realm(game)][norm(realm)] += n
        if guild_realm:
            guilds[game_realm(game)][norm(guild_realm)] += n
    groups = retail_groups()
    only_in = {region: set(groups[region]) - set(groups[other])
               for region, other in (("us", "eu"), ("eu", "us"))}

    problems = []
    for (source, game), regions in sorted(keys.items()):
        unresolved = regions.pop(None, 0)
        if unresolved:
            problems.append(f"UNRESOLVED {source} {game}: {unresolved} recent rows have no region")
        if (source, game) in conflicts:
            region, other = conflicts[(source, game)]
            problems.append(f"CONFLICT   {source} {game}: recorded in {region.upper()} and {other.upper()}")
        if source != "retail" or not regions:
            continue
        realm = norm(realm_of(game))
        seen = home.get(game_realm(game), Counter())
        votes = {}
        for region in ("us", "eu"):
            group = groups[region].get(realm)
            # The realm's own name is in both groups when it exists in both regions.
            votes[region] = sum(n for r, n in seen.items() if group and r in group and r != realm) if group else None
        if votes["us"] is None or votes["eu"] is None:
            expected = "eu" if votes["us"] is None and votes["eu"] is not None else "us" if votes["eu"] is None and votes["us"] is not None else None
        else:
            # A standalone realm has no connected neighbours to count, so guilds
            # named after a realm that exists in only one region vote too.
            for region in ("us", "eu"):
                votes[region] += sum(n for r, n in guilds.get(game_realm(game), Counter()).items() if r in only_in[region])
            expected = "us" if votes["us"] >= 5 and votes["us"] >= 3 * votes["eu"] else \
                       "eu" if votes["eu"] >= 5 and votes["eu"] >= 3 * votes["us"] else None
        if expected is None:
            continue
        for region, n in regions.items():
            if region != expected:
                top = ", ".join(f"{r}={n}" for r, n in seen.most_common(4))
                problems.append(f"MISMATCH   {source} {game}: {n} rows labelled {region.upper()}, but its characters are "
                                f"{expected.upper()} (connected-realm and guild votes US={votes['us']} EU={votes['eu']}; home realms {top})")

    if problems:
        print(f"Region check: {len(problems)} problem(s) in the last {args.days} days. "
              "Fix them in tools/collection-region-provenance.sql (rows collected before MarketLens recorded regions).")
        for line in problems:
            print("  " + line)
        sys.exit(1)
    print(f"Region check: every region-less key in the last {args.days} days has a consistent region.")


if __name__ == "__main__":
    main()
