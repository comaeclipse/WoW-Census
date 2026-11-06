"""Copy existing collector SavedVariables into a new WoWCensus save file.

Run only while WoW is closed. The source files are read only and the destination
must not exist. Example:
  python tools/migrate-wowcensus-savedvariables.py --marketlens PATH/MarketLens.lua \
      --inspect PATH/NameplateInspect.lua --output PATH/WoWCensus.lua
"""

import argparse
import re
from pathlib import Path


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--marketlens", required=True, type=Path)
    parser.add_argument("--inspect", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()

    market = args.marketlens.read_text(encoding="utf-8-sig")
    inspect = args.inspect.read_text(encoding="utf-8-sig")
    market, count = re.subn(r"(?m)^MarketLensDB\s*=", "WoWCensusDB =", market, count=1)
    if count != 1 or not re.search(r"(?m)^NameplateInspectDB\s*=", inspect):
        raise SystemExit("Expected MarketLensDB and NameplateInspectDB assignments were not found")
    if re.search(r"(?m)^NameplateInspectDB\s*=", market):
        raise SystemExit("MarketLens source already includes NameplateInspectDB")

    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("x", encoding="utf-8", newline="\n") as destination:
        destination.write(market.rstrip() + "\n\n" + inspect.rstrip() + "\n")
    print(f"Created {args.output}")


if __name__ == "__main__":
    main()
