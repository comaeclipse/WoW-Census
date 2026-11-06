"""Read-only, race-balanced Classic character appearance pilot.

Requires BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET. Writes only a local JSON
report; it never changes the source SQLite database or published site.
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
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path


NAMESPACES = {
    "classic": "profile-classic1x-us",
    "hardcore": "profile-classic1x-us",
    "sod": "profile-classic1x-us",
    "classic-progression": "profile-classic-us",
    "mop-classic": "profile-classic-us",
}


def fetch_json(url, *, token=None, basic=None):
    headers = {"User-Agent": "MarketLens-classic-appearance-pilot/1.0"}
    if token:
        headers["Authorization"] = "Bearer " + token
    if basic:
        headers["Authorization"] = "Basic " + base64.b64encode(basic).decode()
    request = urllib.request.Request(
        url, headers=headers, data=b"grant_type=client_credentials" if basic else None
    )
    with urllib.request.urlopen(request, timeout=20) as response:
        return json.load(response)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("database", type=Path)
    parser.add_argument("--per-race", type=int, default=5)
    parser.add_argument("--tbc-extra", type=int, default=20)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    client_id = os.environ.get("BLIZZARD_CLIENT_ID")
    client_secret = os.environ.get("BLIZZARD_CLIENT_SECRET")
    if not client_id or not client_secret:
        parser.error("set BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET")
    if args.per_race < 1 or args.tbc_extra < 0:
        parser.error("sample sizes must be nonnegative, and per-race must be positive")

    db = sqlite3.connect("file:" + args.database.resolve().as_posix() + "?mode=ro", uri=True)
    samples = []
    for edition in NAMESPACES:
        candidates = db.execute(
            """SELECT name, realm, race, level FROM characters
               WHERE source_game=? AND name<>'' AND realm<>'' AND level>=10
               GROUP BY lower(name), lower(realm)
               ORDER BY MAX(last_seen) DESC""", (edition,)
        )
        races = Counter()
        selected = set()
        for name, realm, race, level in candidates:
            if races[race] >= args.per_race:
                continue
            samples.append((edition, name, realm, race, level))
            selected.add((name.casefold(), realm.casefold()))
            races[race] += 1
        if edition == "classic-progression" and args.tbc_extra:
            extras = defaultdict(int)
            for name, realm, race, level in db.execute(
                """SELECT name, realm, race, level FROM characters
                   WHERE source_game=? AND name<>'' AND realm<>'' AND level>=10
                   GROUP BY lower(name), lower(realm)
                   ORDER BY MAX(last_seen) DESC""", (edition,)
            ):
                if (name.casefold(), realm.casefold()) in selected or extras[realm] >= args.tbc_extra // 2:
                    continue
                samples.append((edition, name, realm, race, level))
                extras[realm] += 1

    token = fetch_json("https://us.battle.net/oauth/token", basic=f"{client_id}:{client_secret}".encode())["access_token"]
    results = []
    for index, (edition, name, realm, race, level) in enumerate(samples, 1):
        namespace = NAMESPACES[edition]
        slug = realm.lower().replace(" ", "-").replace("'", "")
        path = "/".join(urllib.parse.quote(value, safe="") for value in (slug, name.lower()))
        url = f"https://us.api.blizzard.com/profile/wow/character/{path}/appearance?namespace={namespace}&locale=en_US"
        entry = {"edition": edition, "name": name, "realm": realm, "race": race,
                 "level": level, "namespace": namespace}
        for attempt in range(3):
            try:
                body = fetch_json(url, token=token)
                options = body.get("customizations") or []
                skin = [
                    {"option_id": item.get("option", {}).get("id"),
                     "option_name": item.get("option", {}).get("name"),
                     "choice_id": item.get("choice", {}).get("id"),
                     "display_order": item.get("choice", {}).get("display_order")}
                    for item in options
                    if "skin color" in str(item.get("option", {}).get("name", "")).lower()
                ]
                entry.update(status="ok", customization_count=len(options), skin_choices=skin)
                break
            except urllib.error.HTTPError as error:
                if error.code in (429, 500, 502, 503, 504) and attempt < 2:
                    time.sleep(2 ** attempt + 1)
                    continue
                entry.update(status=f"http_{error.code}", skin_choices=[])
                break
            except (urllib.error.URLError, TimeoutError) as error:
                entry.update(status=f"network_{type(error).__name__}", skin_choices=[])
                break
        results.append(entry)
        if index % 25 == 0 or index == len(samples):
            print(f"Completed {index}/{len(samples)}", flush=True)
        time.sleep(0.2)

    summary = {}
    for edition in NAMESPACES:
        rows = [row for row in results if row["edition"] == edition]
        summary[edition] = {
            "requested": len(rows),
            "statuses": dict(Counter(row["status"] for row in rows)),
            "with_skin_choice": sum(bool(row["skin_choices"]) for row in rows),
            "multiple_skin_options": sum(len(row["skin_choices"]) > 1 for row in rows),
            "unique_choice_ids": len({choice["choice_id"] for row in rows for choice in row["skin_choices"]}),
            "realms": dict(Counter(row["realm"] for row in rows)),
        }
    report = {"sampled_at_utc": datetime.now(timezone.utc).isoformat(),
              "database": str(args.database.resolve()), "summary": summary, "characters": results}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    main()
