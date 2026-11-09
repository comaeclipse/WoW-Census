#!/usr/bin/env python3
"""Package the PHP renderer and captured page query results for a small origin."""

import csv
import gzip
import json
import sys
import tarfile
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("Usage: package-compact-origin.py <capture-dir> <output-dir>")
    capture, output = (Path(p).resolve() for p in sys.argv[1:])
    output.mkdir(parents=True, exist_ok=True)
    records = sorted(capture.glob("*.json"))
    models = sorted((capture / "models").glob("inspects-*.json"))
    # Pages that read only their share of the inspection and auction data, published on their own.
    page_models = {kind: sorted((capture / "models").glob(kind + "-*.json")) for kind in ("inspect-summary", "talents", "gear", "class-profiles", "race-profiles", "auctionhouse")}
    # One roster per realm guild, for the /guilds/<id>/ pages.
    guild_models = sorted((capture / "models").glob("guild-*.json"))
    if not records:
        raise SystemExit("No captured page data")
    if not guild_models:
        raise SystemExit("No guild roster models")
    if len(models) != 8:
        raise SystemExit(f"Expected 8 inspection models, found {len(models)}")
    for kind, paths in page_models.items():
        if len(paths) != 8:
            raise SystemExit(f"Expected 8 {kind} models, found {len(paths)}")

    published_queries = 0
    with gzip.open(output / "query-results.csv.gz", "wt", encoding="utf-8", newline="") as stream:
        writer = csv.writer(stream, lineterminator="\n")
        for path in records:
            record = json.loads(path.read_text(encoding="utf-8"))
            if record["key"] != path.stem or not isinstance(record["rows"], list):
                raise ValueError(f"Invalid capture: {path}")
            # Inspection rows include raw identities and import strings. The
            # renderer uses the separate anonymous, shaped models instead.
            if "character_inspects" in record["sql"] or record["sql"].strip() == "SELECT COUNT(*) n FROM characters WHERE source_game=?":
                continue
            # Every guilded character of an edition: published per guild as roster models instead.
            if record["sql"].startswith("/* guild rosters */"):
                continue
            writer.writerow([record["key"], json.dumps(record["rows"], ensure_ascii=False, separators=(",", ":"))])
            published_queries += 1

    with gzip.open(output / "published-models.csv.gz", "wt", encoding="utf-8", newline="") as stream:
        writer = csv.writer(stream, lineterminator="\n")
        for path in models:
            model = json.loads(path.read_text(encoding="utf-8"))
            if "records" not in model or "coverage" not in model:
                raise ValueError(f"Invalid inspection model: {path}")
            writer.writerow(["inspects:" + path.stem.removeprefix("inspects-"),
                             json.dumps(model, ensure_ascii=False, separators=(",", ":"))])
        for kind, paths in page_models.items():
            for path in paths:
                model = json.loads(path.read_text(encoding="utf-8"))
                if not isinstance(model, (dict, list)):
                    raise ValueError(f"Invalid {kind} model: {path}")
                writer.writerow([kind + ":" + path.stem.removeprefix(kind + "-"),
                                 json.dumps(model, ensure_ascii=False, separators=(",", ":"))])
        for path in guild_models:
            model = json.loads(path.read_text(encoding="utf-8"))
            if not isinstance(model, dict) or "members" not in model:
                raise ValueError(f"Invalid guild roster model: {path}")
            writer.writerow(["guild:" + path.stem.removeprefix("guild-"),
                             json.dumps(model, ensure_ascii=False, separators=(",", ":"))])

    with tarfile.open(output / "site.tar.gz", "w:gz") as archive:
        for source, target in [
            (REPO / "laragon-php/src", "src"),
            (REPO / "laragon-php/public/index.php", "public/index.php"),
            (REPO / "laragon-php/public/blog.php", "public/blog.php"),
            (REPO / "laragon-php/public/faq.php", "public/faq.php"),
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
    print(json.dumps({
        "queries": published_queries,
        "inspection_models": len(models),
        "page_models": sum(map(len, page_models.values())),
        "guild_models": len(guild_models),
        "captured_bytes": sum(path.stat().st_size for path in records),
        "query_archive_bytes": (output / "query-results.csv.gz").stat().st_size,
        "model_archive_bytes": (output / "published-models.csv.gz").stat().st_size,
        "site_archive_bytes": (output / "site.tar.gz").stat().st_size,
    }, indent=2))


if __name__ == "__main__":
    main()
