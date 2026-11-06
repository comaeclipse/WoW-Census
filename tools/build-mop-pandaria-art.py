"""Build the Pandaria continent art from WoWMapUprez's 4x in-game BLP tiles.

Usage: python tools/build-mop-pandaria-art.py PATH_TO_WoWMapUprez PATH_TO_NORTHREND_JPG
Sources: https://github.com/keyboardturner/WoWMapUprez
         https://github.com/Old-Man-Warcraft/wowmin/blob/main/assets/maps/571.jpg
"""

import sys
from pathlib import Path

from PIL import Image


source = Path(sys.argv[1]) / "Interface" / "Worldmap" / "Pandaria"
destination = Path(__file__).resolve().parents[1] / "laragon-php" / "public" / "wow2008" / "mop-map-pandaria.webp"
canvas = Image.new("RGBA", (4096, 3072))
for number in range(1, 13):
    with Image.open(source / f"Pandaria{number}.blp") as tile:
        canvas.paste(tile.convert("RGBA"), (((number - 1) % 4) * 1024, ((number - 1) // 4) * 1024))
canvas = canvas.crop((0, 0, 4008, 2672)).resize((3006, 2004), Image.Resampling.LANCZOS)
canvas.save(destination, "WEBP", quality=83, method=6)
print(f"{destination}: {destination.stat().st_size:,} bytes")

with Image.open(sys.argv[2]) as northrend:
    northrend = northrend.convert("RGB").crop((200, 600, 9784, 6989))
    northrend = northrend.resize((3006, 2004), Image.Resampling.LANCZOS)
    target = destination.with_name("mop-map-northrend.webp")
    northrend.save(target, "WEBP", quality=83, method=6)
    print(f"{target}: {target.stat().st_size:,} bytes")
