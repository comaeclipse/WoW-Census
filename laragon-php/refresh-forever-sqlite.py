#!/usr/bin/env python3
"""Forever-only snapshot merge: refresh-origin-sqlite.py --editions=forever.

    python -B laragon-php/refresh-forever-sqlite.py <base.sqlite> <capture-dir> <output.sqlite>
"""

import argparse
import importlib.util
import json
from pathlib import Path

spec = importlib.util.spec_from_file_location("refresh_origin_sqlite", Path(__file__).with_name("refresh-origin-sqlite.py"))
merge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(merge)


def refresh(base, capture, output):
    return merge.refresh(base, capture, output, ["forever"])


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("base", type=Path)
    parser.add_argument("capture", type=Path)
    parser.add_argument("output", type=Path)
    args = parser.parse_args()
    print(json.dumps(refresh(args.base, args.capture, args.output)))
