"""Sample local Retail characters against Blizzard's appearance API.

Set BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET in the process environment.
Usage: python tools/pilot-retail-appearance.py DB_PATH [--limit 100] [--output PATH]
       python tools/pilot-retail-appearance.py --candidates-json PATH --profile-race --limit 500 --output PATH
The output is local JSON; this command never changes the source database.
"""

import argparse
import base64
import json
import os
import sqlite3
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path


SKIN_OPTION_IDS = json.loads((Path(__file__).resolve().parents[1] / "laragon-php/src/retail-skin-option-ids.json").read_text(encoding="utf-8"))


def select_skin_color(options, race):
    allowed = set(SKIN_OPTION_IDS.get(race, []))
    skin_options = [x for x in options if "skin color" in str(x.get("option", {}).get("name", "")).lower()]
    chosen = next((x for x in skin_options if x.get("option", {}).get("id") in allowed), None)
    rejected = [x.get("option", {}).get("id") for x in skin_options
                if x.get("option", {}).get("id") not in allowed]
    return chosen, rejected


def request_json(url, *, token=None, auth=None):
    headers = {"User-Agent": "MarketLens-appearance-pilot/1.0"}
    if token:
        headers["Authorization"] = f"Bearer {token}"
    if auth:
        headers["Authorization"] = "Basic " + base64.b64encode(auth.encode()).decode()
    req = urllib.request.Request(url, headers=headers,
                                 data=b"grant_type=client_credentials" if auth else None)
    with urllib.request.urlopen(req, timeout=20) as response:
        return json.load(response)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("database", nargs="?", type=Path)
    parser.add_argument("--candidates-json", type=Path, help="Preselected name, realm, race, level rows")
    parser.add_argument("--profile-race", action="store_true", help="Verify the current race from the character profile")
    parser.add_argument("--limit", type=int, default=100)
    parser.add_argument("--per-race", type=int, help="Select this many new characters per Retail race")
    parser.add_argument("--exclude-report", type=Path, action="append", default=[],
                        help="Skip names and realms in an earlier pilot report; repeat as needed")
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    if (args.database is None) == (args.candidates_json is None):
        parser.error("provide either a database or --candidates-json")
    if not 1 <= args.limit <= 500:
        parser.error("--limit must be between 1 and 500")
    if args.per_race is not None and not 1 <= args.per_race <= 100:
        parser.error("--per-race must be between 1 and 100")
    client_id = os.getenv("BLIZZARD_CLIENT_ID")
    client_secret = os.getenv("BLIZZARD_CLIENT_SECRET")
    if not client_id or not client_secret:
        parser.error("set BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET")
    if args.candidates_json:
        document = json.loads(args.candidates_json.read_text(encoding="utf-8-sig"))
        source_rows = document["candidates"] if isinstance(document, dict) else document
        candidates = [(x["name"], x["realm"], x["race"], x.get("level"), x.get("region", "us"))
                      for x in source_rows]
    else:
        db = sqlite3.connect(f"file:{args.database.resolve().as_posix()}?mode=ro", uri=True)
        # One deterministic, recent sample across the available Retail realms.
        candidates = [(*row, "us") for row in db.execute("""SELECT name, realm, race, level FROM characters
            WHERE source_game='retail' AND level>=10 AND name<>'' AND realm<>''
            GROUP BY lower(name), lower(realm)
            ORDER BY MAX(last_seen) DESC""").fetchall()]
    excluded = set()
    for report in args.exclude_report:
        prior = json.loads(report.read_text(encoding="utf-8"))
        excluded.update((x["name"].casefold(), x["realm"].casefold()) for x in prior["characters"])
    rows = []
    races = Counter()
    by_race = {}
    for row in candidates:
        name, realm, race, _, region = row
        if region not in ("us", "eu", "kr", "tw"):
            raise ValueError(f"Unknown region for {name}-{realm}: {region}")
        if (name.casefold(), realm.casefold()) in excluded:
            continue
        if args.per_race is None:
            rows.append(row)
            races[race] += 1
            if len(rows) >= args.limit:
                break
        elif len(by_race.setdefault(race, [])) < args.per_race:
            by_race[race].append(row)
    if args.per_race is not None:
        # Take one recent candidate per race per round, including when the
        # requested limit is smaller than the total race quotas.
        for index in range(args.per_race):
            for race, group in by_race.items():
                if index < len(group):
                    rows.append(group[index])
                    races[race] += 1
                    if len(rows) >= args.limit:
                        break
            if len(rows) >= args.limit:
                break
    token_url = "https://us.battle.net/oauth/token"
    token = request_json(token_url, auth=f"{client_id}:{client_secret}")["access_token"]
    results = []
    for name, realm, race, level, region in rows:
        slug = {"templenoir": "temple-noir"}.get(
            realm.casefold(), realm.lower().replace(" ", "-").replace("'", ""))
        path = "/".join(urllib.parse.quote(x, safe="") for x in (slug, name.lower()))
        base = f"https://{region}.api.blizzard.com/profile/wow/character/{path}"
        query = f"?namespace=profile-{region}&locale=en_US"
        url = f"{base}/appearance{query}"
        entry = {"name": name, "realm": realm, "race": race, "level": level, "collection_region": region}
        if args.profile_race:
            for attempt in range(3):
                try:
                    profile = request_json(f"{base}{query}", token=token)
                    entry["candidate_race"] = race
                    race = profile.get("race", {}).get("name") or race
                    entry["race"] = race
                    break
                except urllib.error.HTTPError as exc:
                    if exc.code in (429, 500, 502, 503, 504) and attempt < 2:
                        time.sleep(min(2 ** attempt + 1, 10))
                        continue
                    entry["status"] = f"profile_http_{exc.code}"
                    break
                except (urllib.error.URLError, TimeoutError) as exc:
                    entry["status"] = f"profile_network_{type(exc).__name__}"
                    break
            if "status" in entry:
                entry["fetched_at"] = datetime.now(timezone.utc).isoformat()
                results.append(entry)
                if len(results) % 25 == 0:
                    print(f"Completed {len(results)}/{len(rows)} lookups", flush=True)
                continue
        for attempt in range(3):
            try:
                body = request_json(url, token=token)
                options = body.get("customizations") or []
                skin, rejected = select_skin_color(options, race)
                entry.update(status="ok", skin_option_id=skin["option"].get("id") if skin else None,
                             skin_choice_id=skin["choice"].get("id") if skin else None,
                             skin_display_order=skin["choice"].get("display_order") if skin else None,
                             rejected_skin_option_ids=rejected,
                             customization_count=len(options))
                break
            except urllib.error.HTTPError as exc:
                if exc.code in (429, 500, 502, 503, 504) and attempt < 2:
                    time.sleep(min(2 ** attempt + 1, 10))
                    continue
                entry.update(status=f"http_{exc.code}")
                break
            except (urllib.error.URLError, TimeoutError) as exc:
                entry.update(status=f"network_{type(exc).__name__}")
                break
        entry["fetched_at"] = datetime.now(timezone.utc).isoformat()
        results.append(entry)
        if len(results) % 25 == 0:
            print(f"Completed {len(results)}/{len(rows)} lookups", flush=True)
        time.sleep(0.25)
    counts = Counter(x["status"] for x in results)
    summary = {"requested": len(rows), "statuses": dict(counts),
               "with_skin_choice": sum(x.get("skin_choice_id") is not None for x in results),
               "requested_by_race": dict(races)}
    report = {"summary": summary, "characters": results}
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    main()
