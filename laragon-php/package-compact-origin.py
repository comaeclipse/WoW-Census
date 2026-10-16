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
    if not records:
        raise SystemExit("No captured page data")
    if len(models) != 7:
        raise SystemExit(f"Expected 7 inspection models, found {len(models)}")

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

    with tarfile.open(output / "site.tar.gz", "w:gz") as archive:
        for source, target in [
            (REPO / "laragon-php/src", "src"),
            (REPO / "laragon-php/public/index.php", "public/index.php"),
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
            (REPO / "assets/wow.zamimg.com", "public/assets"),
        ]:
            if not source.exists():
                raise FileNotFoundError(source)
            archive.add(source, arcname=target)
    print(json.dumps({
        "queries": published_queries,
        "inspection_models": len(models),
        "captured_bytes": sum(path.stat().st_size for path in records),
        "query_archive_bytes": (output / "query-results.csv.gz").stat().st_size,
        "model_archive_bytes": (output / "published-models.csv.gz").stat().st_size,
        "site_archive_bytes": (output / "site.tar.gz").stat().st_size,
    }, indent=2))


if __name__ == "__main__":
    main()
