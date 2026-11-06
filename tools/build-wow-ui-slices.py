#!/usr/bin/env python3
# Turns raw client UI textures in assets/wow-ui/classic into CSS-ready images
# under assets/wow-ui/classic/css/.
#
# Backdrop edge files (ui-dialogbox-*-border, ui-tooltip-border) are one strip
# of eight square pieces in the order left, right, top, bottom, top-left,
# top-right, bottom-left, bottom-right; top and bottom are stored on their
# side. They become a 3x3 nine-slice image for CSS border-image.
# Red panel buttons only fill the top-left 80x22 of their 128x32 texture (gold
# dialog buttons fill 128x23); they are
# cropped to that so CSS border-image can stretch the middle.
#
# Usage: python tools/build-wow-ui-slices.py
import os
from PIL import Image

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "assets", "wow-ui", "classic")
OUT = os.path.join(ROOT, "css")

def nine_slice(src, name):
    strip = Image.open(os.path.join(ROOT, src)).convert("RGBA")
    s = strip.height
    piece = [strip.crop((i * s, 0, i * s + s, s)) for i in range(8)]
    left, right, top, bottom, tl, tr, bl, br = piece
    out = Image.new("RGBA", (3 * s, 3 * s))
    out.paste(tl, (0, 0)); out.paste(tr, (2 * s, 0))
    out.paste(bl, (0, 2 * s)); out.paste(br, (2 * s, 2 * s))
    out.paste(left, (0, s)); out.paste(right, (2 * s, s))
    out.paste(top.transpose(Image.Transpose.ROTATE_270), (s, 0))
    out.paste(bottom.transpose(Image.Transpose.ROTATE_270), (s, 2 * s))
    out.save(os.path.join(OUT, name))

def crop(src, box, name):
    Image.open(os.path.join(ROOT, src)).convert("RGBA").crop(box).save(os.path.join(OUT, name))

os.makedirs(OUT, exist_ok=True)
nine_slice("dialogframe/ui-dialogbox-gold-border.png", "gold-border.png")
nine_slice("dialogframe/ui-dialogbox-border.png", "silver-border.png")
nine_slice("tooltips/ui-tooltip-border.png", "tooltip-border.png")
for state in ("up", "down", "highlight", "disabled"):
    crop(f"buttons/ui-panel-button-{state}.png", (0, 0, 80, 22), f"red-button-{state}.png")
for state in ("up", "down"):
    crop(f"buttons/ui-dialogbox-button-gold-{state}.png", (0, 0, 128, 23), f"gold-button-{state}.png")
for tone in ("gold-", ""):
    crop(f"dialogframe/ui-dialogbox-{tone}header.png", (59, 0, 197, 40), f"{tone or 'silver-'}header.png")
# Skill bar frame: the art sits in rows 5-25; its opening is x 7-249, y 11-19.
crop("paperdollinfoframe/ui-character-skills-barborder.png", (0, 5, 256, 26), "skills-barborder.png")
print("wrote", OUT)
