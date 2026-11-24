#!/usr/bin/env python3
"""Fetch Retail connected realms from Blizzard's Game Data API.

    python -B tools/update-retail-connected-realms.py           # US and EU
    python -B tools/update-retail-connected-realms.py --apac    # Korea and Taiwan

Reads BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET from the environment or the
repository's .env. The default writes laragon-php/src/retail-connected-realms.json,
which the realm explorer uses to show each connected group as one row. --apac
writes tools/retail-connected-realms-apac.json instead (the site does not read it;
check-collection-regions.py uses it to confirm KR/TW-labelled keys). Each realm
keeps its display name (en_US in the US, Korea and Taiwan, en_GB in Europe) and
any other localized names, so names seen in /who in another client language
still match.
"""

import argparse
import json
import os
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
OUT = REPO / "laragon-php" / "src" / "retail-connected-realms.json"
APAC_OUT = REPO / "tools" / "retail-connected-realms-apac.json"
REGIONS = {"us": "en_US", "eu": "en_GB"}
APAC_REGIONS = {"kr": "en_US", "tw": "en_US"}


def credentials():
    env = {}
    path = REPO / ".env"
    if path.is_file():
        for line in path.read_text(encoding="utf-8").splitlines():
            key, sep, value = line.partition("=")
            if sep:
                env[key.strip()] = value.strip().strip('"')
    get = lambda k: os.environ.get(k) or env.get(k)
    client_id, secret = get("BLIZZARD_CLIENT_ID"), get("BLIZZARD_CLIENT_SECRET")
    if not client_id or not secret:
        raise SystemExit("set BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET (environment or .env)")
    return client_id, secret


def request_json(url, *, token=None, data=None, auth=None):
    req = urllib.request.Request(url, data=data)
    if token:
        req.add_header("Authorization", f"Bearer {token}")
    if auth:
        import base64
        req.add_header("Authorization", "Basic " + base64.b64encode(auth.encode()).decode())
    with urllib.request.urlopen(req, timeout=30) as response:
        return json.load(response)


def fetch_region(region, locale, token):
    groups, page = [], 1
    while True:
        query = urllib.parse.urlencode({"namespace": f"dynamic-{region}", "orderby": "id", "_page": page, "_pageSize": 1000})
        body = request_json(f"https://{region}.api.blizzard.com/data/wow/search/connected-realm?{query}", token=token)
        for result in body.get("results", []):
            realms = []
            for realm in result["data"]["realms"]:
                names = realm["name"]
                name = names.get(locale) or names.get("en_US") or next(iter(names.values()))
                alt = sorted({n for n in names.values() if n and n != name})
                realms.append({"name": name, **({"alt": alt} if alt else {})})
            realms.sort(key=lambda r: r["name"])
            groups.append({"id": result["data"]["id"], "realms": realms})
        if page >= body.get("pageCount", 1):
            return sorted(groups, key=lambda g: g["id"])
        page += 1


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--apac", action="store_true", help="fetch Korea and Taiwan into tools/retail-connected-realms-apac.json")
    args = parser.parse_args()
    regions, target = (APAC_REGIONS, APAC_OUT) if args.apac else (REGIONS, OUT)
    client_id, secret = credentials()
    token = request_json("https://oauth.battle.net/token", data=b"grant_type=client_credentials",
                         auth=f"{client_id}:{secret}")["access_token"]
    out = {"fetchedAt": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
           "source": "Blizzard Game Data API, /data/wow/search/connected-realm (dynamic namespace)"}
    for region, locale in regions.items():
        out[region] = fetch_region(region, locale, token)
        connected = sum(1 for g in out[region] if len(g["realms"]) > 1)
        print(f"{region}: {len(out[region])} connected realms, {connected} with more than one realm, "
              f"{sum(len(g['realms']) for g in out[region])} realms", flush=True)
    target.write_text(json.dumps(out, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    print(f"wrote {target.relative_to(REPO)}", flush=True)


if __name__ == "__main__":
    main()
