#!/usr/bin/env python3
"""Package the PHP site as a code release: <output-dir>/site.tar.gz.

    python -B laragon-php/package-release.py <output-dir>

The archive holds what the production server's release directory needs: the
renderer (src/), the public files named below, and the resources the renderer
reads (site/src, item names and icons, stylesheets). Local-only pages such as
public/stats.php and public/schedule.php are not listed, so they never ship.
laragon-php/README.md has the steps for installing a release.
"""

import json
import sys
import tarfile
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: package-release.py <output-dir>")
    output = Path(sys.argv[1]).resolve()
    output.mkdir(parents=True, exist_ok=True)
    with tarfile.open(output / "site.tar.gz", "w:gz") as archive:
        for source, target in [
            (REPO / "laragon-php/src", "src"),
            (REPO / "laragon-php/public/index.php", "public/index.php"),
            (REPO / "laragon-php/public/blog.php", "public/blog.php"),
            (REPO / "laragon-php/public/blog-economy.css", "public/blog-economy.css"),
            (REPO / "laragon-php/public/blog", "public/blog"),
            (REPO / "laragon-php/public/faq.php", "public/faq.php"),
            (REPO / "laragon-php/public/ads.txt", "public/ads.txt"),
            (REPO / "laragon-php/public/cobalt-skin.css", "public/cobalt-skin.css"),
            (REPO / "laragon-php/public/cobalt-skin.js", "public/cobalt-skin.js"),
            (REPO / "laragon-php/public/cobalt-character.webp", "public/cobalt-character.webp"),
            (REPO / "laragon-php/public/cobalt-paladin.webp", "public/cobalt-paladin.webp"),
            (REPO / "laragon-php/public/cobalt-warrior.webp", "public/cobalt-warrior.webp"),
            (REPO / "laragon-php/public/murloc-404.webp", "public/murloc-404.webp"),
            (REPO / "laragon-php/public/modern-bridge.css", "public/modern-bridge.css"),
            (REPO / "laragon-php/public/modern-page-layouts.css", "public/modern-page-layouts.css"),
            (REPO / "laragon-php/public/geo-aero.css", "public/geo-aero.css"),
            (REPO / "laragon-php/public/geo-aero.js", "public/geo-aero.js"),
            (REPO / "laragon-php/public/skin-switch.css", "public/skin-switch.css"),
            (REPO / "laragon-php/public/modern", "public/modern"),
            (REPO / "laragon-php/public/wow2008", "public/wow2008"),
            (REPO / "site/src", "resources/src"),
            (REPO / "tools/item-names", "resources/item-names"),
            (REPO / "tools/item-icons", "resources/item-icons"),
            (REPO / "site/public/style.css", "resources/public/style.css"),
            (REPO / "site/public/census.css", "resources/public/census.css"),
            (REPO / "site/public/notice-discord.png", "resources/public/notice-discord.png"),
            (REPO / "site/public/fonts", "public/fonts"),
            (REPO / "site/public/favicon.ico", "public/favicon.ico"),
            (REPO / "site/public/favicon-96.png", "public/favicon-96.png"),
            (REPO / "site/public/notice-discord.png", "public/notice-discord.png"),
            (REPO / "site/public/role-icons.png", "public/role-icons.png"),
            (REPO / "assets/wow.zamimg.com", "public/assets"),
        ]:
            if not source.exists():
                raise FileNotFoundError(source)
            archive.add(source, arcname=target)
    print(json.dumps({"site_archive": str(output / "site.tar.gz"),
                      "site_archive_bytes": (output / "site.tar.gz").stat().st_size}, indent=2))


if __name__ == "__main__":
    main()
