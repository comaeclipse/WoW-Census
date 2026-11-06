"""Build Classic-era continent art from WoWMapUprezClassic's 4x BLP tiles.

Usage: python tools/build-classic-continent-art.py PATH_TO_WoWMapUprezClassic
Source: https://github.com/keyboardturner/WoWMapUprezClassic
"""

import sys
from pathlib import Path

from PIL import Image


SOURCE = Path(sys.argv[1]) / "Interface" / "Worldmap"
DEST = Path(__file__).resolve().parents[1] / "laragon-php" / "public" / "wow2008"

for slug, name in (("kalimdor", "Kalimdor"), ("eastern-kingdoms", "Azeroth")):
    canvas = Image.new("RGBA", (4096, 3072))
    for number in range(1, 13):
        with Image.open(SOURCE / name / f"{name}{number}.blp") as tile:
            canvas.paste(tile.convert("RGBA"), (((number - 1) % 4) * 1024, ((number - 1) // 4) * 1024))
    canvas = canvas.crop((0, 0, 4008, 2672)).resize((3006, 2004), Image.Resampling.LANCZOS)
    target = DEST / f"classic-map-{slug}.webp"
    canvas.save(target, "WEBP", quality=83, method=6)
    print(f"{target}: {target.stat().st_size:,} bytes")
