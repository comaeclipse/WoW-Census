#!/usr/bin/env python3
"""Build universal and client-specific WoWCensus ZIPs for one CurseForge project."""

from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "WoWCensus"
DIST = ROOT / "dist"
INTERFACES = {
    "retail": "120105, 120100, 120007",
    "mop-classic": "50504",
    "tbc-anniversary": "20506",
    "classic-era-sod": "11508, 11509",
    "forever": "16001",
}


def main():
    toc = (SOURCE / "WoWCensus.toc").read_text(encoding="utf-8")
    version = next(line.split(":", 1)[1].strip() for line in toc.splitlines()
                   if line.startswith("## Version:"))
    DIST.mkdir(exist_ok=True)
    files = sorted(path for path in SOURCE.rglob("*") if path.is_file())
    universal = DIST / f"WoWCensus-{version}-universal.zip"
    with ZipFile(universal, "w", ZIP_DEFLATED) as archive:
        for path in files:
            archive.write(path, (Path("WoWCensus") / path.relative_to(SOURCE)).as_posix())
    print(universal)
    for flavor, interface in INTERFACES.items():
        target = DIST / f"WoWCensus-{version}-{flavor}.zip"
        with ZipFile(target, "w", ZIP_DEFLATED) as archive:
            for path in files:
                data = path.read_bytes()
                if path.name == "WoWCensus.toc":
                    lines = toc.splitlines()
                    lines[0] = f"## Interface: {interface}"
                    data = ("\n".join(lines) + "\n").encode("utf-8")
                archive.writestr((Path("WoWCensus") / path.relative_to(SOURCE)).as_posix(), data)
        print(target)


if __name__ == "__main__":
    main()
