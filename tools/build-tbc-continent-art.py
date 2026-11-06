"""Build web-ready TBC continent art from WoWMapUprezTBC's 4x BLP tiles.

Usage: python tools/build-tbc-continent-art.py PATH_TO_WoWMapUprezTBC
Source: https://github.com/keyboardturner/WoWMapUprezTBC (TBC 2.5.1 map art)
"""

import sys
from pathlib import Path

from PIL import Image


SOURCE = Path(sys.argv[1]) / "Interface" / "Worldmap"
DEST = Path(__file__).resolve().parents[1] / "laragon-php" / "public" / "wow2008"

for slug, name in (
    ("kalimdor", "Kalimdor"),
    ("eastern-kingdoms", "Azeroth"),
    ("outland", "Expansion01"),
):
    canvas = Image.new("RGBA", (4096, 3072))
    for number in range(1, 13):
        with Image.open(SOURCE / name / f"{name}{number}.blp") as tile:
            canvas.paste(tile.convert("RGBA"), (((number - 1) % 4) * 1024, ((number - 1) // 4) * 1024))
    # The source world map has a 1002:668 aspect ratio. The final 3006px width
    # stays sharp at more than 3x the page's usual displayed width.
    canvas = canvas.crop((0, 0, 4008, 2672)).resize((3006, 2004), Image.Resampling.LANCZOS)
    target = DEST / f"tbc-map-{slug}.webp"
    canvas.save(target, "WEBP", quality=83, method=6)
    print(f"{target}: {target.stat().st_size:,} bytes")
