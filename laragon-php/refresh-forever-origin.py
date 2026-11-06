#!/usr/bin/env python3
"""Forever-only origin refresh: refresh-origin.py --editions=forever.

Kept so existing commands work; see refresh-origin.py for the options
(--base, --deploy, --php, --data-root).
"""

import importlib.util
import sys
from pathlib import Path

spec = importlib.util.spec_from_file_location("refresh_origin", Path(__file__).with_name("refresh-origin.py"))
refresh_origin = importlib.util.module_from_spec(spec)
spec.loader.exec_module(refresh_origin)

if __name__ == "__main__":
    refresh_origin.main(["--editions=forever", *sys.argv[1:]])
