"""Build a set of Blizzard-verified Forever characters from the Forever Pulse scrape.

Usage: python -I tools/build-foreverpulse-verified.py <scrape.jsonl> <verified.jsonl> --target 2000 [--seed 11] [--rate 10]
Needs BLIZZARD_CLIENT_ID / BLIZZARD_CLIENT_SECRET in the environment.

A character is kept only when:
  - the Forever profile exists in the scraped region (us/eu),
  - the full name (name_search) and race, class and faction match the scrape,
  - the profile's own ruleset matches the scraped realm type.
Guild (may be none), level and ruleset are taken from Blizzard. Candidates are level 10+ (the
profile is a 404 below 10), drawn at random across every realm scope. Resumes: characters already
in the output are skipped, as are ids listed in --exclude files (earlier batches).
"""
import argparse
import base64
import concurrent.futures
import json
import os
import random
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

REGION = {"NA": "us", "EU": "eu"}
MODE_TO_RULESET = {"pve": "Normal", "pvp": "PvP", "rp": "Roleplay", "rp-pvp": "Roleplay PvP"}
RACE = {"scourge": "undead", "nightelf": "night elf"}


def norm_race(r):
    r = (r or "").lower()
    if r.endswith("skyborne"):
        return "skyborne"
    return RACE.get(r.replace(" ", ""), r)


def fix_name(name):
    w = name.split()
    if len(w) >= 3 and w[-1] == w[-2]:
        w = w[:-1]
    return " ".join(w)


class Api:
    def __init__(self, rate):
        cid, secret = os.environ["BLIZZARD_CLIENT_ID"], os.environ["BLIZZARD_CLIENT_SECRET"]
        basic = base64.b64encode(f"{cid}:{secret}".encode()).decode()
        req = urllib.request.Request("https://us.battle.net/oauth/token", data=b"grant_type=client_credentials",
                                     headers={"Authorization": "Basic " + basic})
        with urllib.request.urlopen(req, timeout=20) as r:
            self.token = json.load(r)["access_token"]
        self.gap, self.lock, self.next = 1.0 / rate, threading.Lock(), 0.0
        self.guilds, self.glock = {}, threading.Lock()

    def get(self, url):
        for attempt in range(4):
            with self.lock:
                now = time.monotonic()
                t = max(now, self.next)
                self.next = t + self.gap
            time.sleep(max(0, t - now))
            try:
                req = urllib.request.Request(url, headers={"Authorization": "Bearer " + self.token})
                with urllib.request.urlopen(req, timeout=20) as r:
                    return json.load(r)
            except urllib.error.HTTPError as e:
                if e.code == 404:
                    return None
                if e.code in (429, 500, 502, 503, 504):
                    time.sleep(2 * (attempt + 1))
                    continue
                return None
            except (urllib.error.URLError, TimeoutError, ValueError):
                time.sleep(2 * (attempt + 1))
        return None

    def guild(self, href):
        with self.glock:
            if href in self.guilds:
                return self.guilds[href]
        g = self.get(href + ("&" if "?" in href else "?") + "locale=en_US")
        with self.glock:
            self.guilds[href] = g
        return g


def check(api, row):
    region = REGION[row["region"]]
    name = fix_name(row["name"])
    slug = urllib.parse.quote("-".join(name.lower().split()), safe="-")
    p = api.get(f"https://{region}.api.blizzard.com/profile/wow/character/{slug}"
                f"?namespace=profile-forever-{region}&locale=en_US")
    if not p:
        return None, "not_found"
    full = " ".join(str(p.get("name_search") or f"{p.get('name', '')} {p.get('surname', '')}").split())
    if (full.casefold() != name.casefold()
            or norm_race((p.get("race") or {}).get("name")) != norm_race(row["race"])
            or str((p.get("character_class") or {}).get("name", "")).casefold() != (row["class"] or "").casefold()
            or str((p.get("faction") or {}).get("name", "")).casefold() != (row["faction"] or "").casefold()):
        return None, "identity_mismatch"
    ruleset = (p.get("ruleset") or {}).get("name")
    if ruleset != MODE_TO_RULESET.get(row["mode"]):
        return None, "ruleset_mismatch" if ruleset else "no_ruleset"
    g = p.get("guild") or {}
    return {
        "name": full,
        "region": region.upper(),
        "ruleset": ruleset,
        "faction": (p.get("faction") or {}).get("name"),
        "race": (p.get("race") or {}).get("name"),
        "class": (p.get("character_class") or {}).get("name"),
        "level": p.get("level"),
        "guild": g.get("name"),
        "guild_id": g.get("id"),
        "guild_slug": g.get("slug"),
        "blizzard_character_id": p.get("id"),
        "last_login_utc": p.get("last_login_timestamp"),
        "realm_id_observed": row["realmId"],
        "forever_pulse_id": row["id"],
        "verified_at_utc": int(time.time()),
    }, "ok"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("src")
    ap.add_argument("dst")
    ap.add_argument("--target", type=int, default=2000)
    ap.add_argument("--seed", type=int, default=11)
    ap.add_argument("--rate", type=float, default=10.0)
    ap.add_argument("--workers", type=int, default=6)
    ap.add_argument("--exclude", action="append", default=[], help="earlier batch files whose characters to skip")
    a = ap.parse_args()

    done = set()
    if os.path.exists(a.dst):
        done = {json.loads(l)["forever_pulse_id"] for l in open(a.dst, encoding="utf-8") if l.strip()}
    skip = set(done)
    for path in a.exclude:
        skip |= {json.loads(l)["forever_pulse_id"] for l in open(path, encoding="utf-8") if l.strip()}
    rows = []
    for l in open(a.src, encoding="utf-8"):
        if l.strip():
            r = json.loads(l)
            if (r.get("level") or 0) >= 10 and r["region"] in REGION and r["id"] not in skip:
                rows.append(r)
    random.Random(a.seed).shuffle(rows)

    api = Api(a.rate)
    kept, stats, lock = len(done), {}, threading.Lock()
    it = iter(rows)
    with open(a.dst, "a", encoding="utf-8") as out, concurrent.futures.ThreadPoolExecutor(a.workers) as pool:
        pending = set()
        while kept < a.target:
            while len(pending) < a.workers * 2:
                r = next(it, None)
                if r is None:
                    break
                pending.add(pool.submit(check, api, r))
            if not pending:
                break
            finished, pending = concurrent.futures.wait(pending, return_when=concurrent.futures.FIRST_COMPLETED)
            for f in finished:
                rec, status = f.result()
                stats[status] = stats.get(status, 0) + 1
                if rec and kept < a.target:
                    out.write(json.dumps(rec, ensure_ascii=False) + "\n")
                    out.flush()
                    kept += 1
        for f in pending:
            f.cancel()
    print(json.dumps({"kept": kept, "checked": sum(stats.values()), "status": stats}))


if __name__ == "__main__":
    main()
