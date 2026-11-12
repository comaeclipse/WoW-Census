#!/usr/bin/env python3
"""Summarize a WoW: Forever armory profession scan into src/forever-profession-census.json.

    python -B laragon-php/build-profession-census.py <combined_*.json>

The input is a tools/forever_professions combined_*.json file (one row per sampled character with
its primaries and secondaries). The output holds only aggregates; no character names are kept.
"""

import json
import statistics
import sys
from collections import Counter, defaultdict
from pathlib import Path

OUT = Path(__file__).resolve().parent / "src" / "forever-profession-census.json"
BRACKETS = {75: "Apprentice", 150: "Journeyman", 225: "Expert", 300: "Artisan"}


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: build-profession-census.py <combined.json>")
    source = Path(sys.argv[1])
    rows = json.loads(source.read_text(encoding="utf-8"))
    ok = [r for r in rows if r.get("status") == "ok"]
    n = len(ok)

    def summarize(kind: str) -> list:
        skills, recipes, brackets, maxed = defaultdict(list), defaultdict(list), defaultdict(Counter), Counter()
        for r in ok:
            for p in r[kind]:
                name = p["name"]
                skills[name].append(p["skill_points"])
                recipes[name].append(p["known_recipes_count"])
                brackets[name][p["max_skill_points"]] += 1
                if p["skill_points"] >= p["max_skill_points"]:
                    maxed[name] += 1
        out = []
        for name, s in skills.items():
            out.append({
                "name": name, "count": len(s), "pct": round(len(s) / n * 100, 1),
                "avg": round(statistics.mean(s), 1), "median": statistics.median(s), "top": max(s),
                "recipes": round(statistics.mean(recipes[name]), 1),
                "maxed": round(maxed[name] / len(s) * 100, 1),
                "brackets": {BRACKETS[k]: brackets[name][k] for k in sorted(BRACKETS) if brackets[name][k]},
            })
        return sorted(out, key=lambda x: -x["count"])

    combos = Counter(" + ".join(sorted(p["name"] for p in r["primaries"])) or "(none)" for r in ok)
    by_class = defaultdict(Counter)
    class_n = Counter()
    for r in ok:
        class_n[r["local_class"]] += 1
        for p in r["primaries"]:
            by_class[r["local_class"]][p["name"]] += 1
    classes = [{"class": c, "count": class_n[c],
                "top": [[p, round(k / class_n[c] * 100, 1)] for p, k in by_class[c].most_common(3)]}
               for c, _ in class_n.most_common()]

    bands = defaultdict(list)
    for r in ok:
        lo = min(r["local_level"] // 10 * 10, 50)
        bands[lo].append(statistics.mean(p["skill_points"] for p in r["primaries"]) if r["primaries"] else 0)
    levels = [[f"{lo}-{lo + 9 if lo < 50 else 60}", len(v), round(statistics.mean(v), 1)] for lo, v in sorted(bands.items())]
    fetched = sorted(r["fetched_at"] for r in ok if r.get("fetched_at"))
    all_primary = [p for r in ok for p in r["primaries"]]
    data = {
        "source": source.name,
        "sampled": len(rows), "profiles": n, "notFound": len(rows) - n,
        "fetchedFrom": fetched[0], "fetchedTo": fetched[-1],
        "regions": dict(Counter(r["region"].upper() for r in ok).most_common()),
        "rulesets": dict(Counter(r["local_ruleset"] for r in ok).most_common()),
        "primaryCounts": {str(k): v for k, v in sorted(Counter(len(r["primaries"]) for r in ok).items())},
        "primaries": summarize("primaries"),
        "secondaries": summarize("secondaries"),
        "combos": [[c, k, round(k / n * 100, 1)] for c, k in combos.most_common(10)],
        "classes": classes,
        "levels": levels,
        "learned": len(all_primary) + sum(len(r["secondaries"]) for r in ok),
        "recipes": sum(p["known_recipes_count"] for r in ok for p in r["primaries"] + r["secondaries"]),
        "capped": sum(1 for p in all_primary if p["skill_points"] >= p["max_skill_points"]),
    }
    OUT.write_text(json.dumps(data, indent=1) + "\n", encoding="utf-8")
    print(f"{OUT}: {n} profiles")


if __name__ == "__main__":
    main()
