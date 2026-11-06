"""Add swatches for choice IDs in a Retail appearance report from the DB2 catalog."""

import csv
import io
import json
import sys
import urllib.request
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CATALOG = ROOT / "mockups/retail-appearance-choice-swatches.json"
URL = "https://wago.tools/db2/ChrCustomizationChoice/csv"


def main(report_path):
    document = json.loads(CATALOG.read_text(encoding="utf-8"))
    report = json.loads(Path(report_path).read_text(encoding="utf-8"))
    wanted = {str(row["skin_choice_id"]): row["skin_option_id"]
              for row in report["characters"] if row.get("skin_choice_id") is not None}
    request = urllib.request.Request(URL, headers={"User-Agent": "Mozilla/5.0"})
    with urllib.request.urlopen(request, timeout=30) as response:
        reader = csv.DictReader(io.TextIOWrapper(response, encoding="utf-8-sig"))
        found = {}
        for row in reader:
            choice_id = row["ID"]
            if choice_id not in wanted:
                continue
            option_id = int(row["ChrCustomizationOptionID"])
            if option_id != wanted[choice_id]:
                raise ValueError(f"Option mismatch for choice {choice_id}")
            found[choice_id] = {"option_id": option_id,
                                "hex": f"#{int(row['SwatchColor_0']) & 0xffffff:06X}"}
    if set(found) != set(wanted):
        raise ValueError(f"Missing DB2 choices: {sorted(set(wanted) - set(found))}")
    old = document["choices"]
    additions = 0
    for key, value in found.items():
        if key in old and old[key] != value:
            raise ValueError(f"Existing swatch changed for choice {key}")
        if key not in old:
            old[key] = value
            additions += 1
    document["retrieved"] = date.today().isoformat()
    document["choices"] = dict(sorted(old.items(), key=lambda pair: int(pair[0])))
    CATALOG.write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"{len(wanted)} report choices mapped; {additions} new swatches; {len(old)} catalog choices")


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("usage: python tools/update-retail-appearance-swatches.py REPORT.json")
    main(sys.argv[1])
