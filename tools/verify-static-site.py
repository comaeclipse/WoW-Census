#!/usr/bin/env python3
"""Check that the locally built Pages tree needs no runtime Function."""
import json
import re
import sys
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urljoin, urlsplit

EDITIONS = ("", "tbc", "classic", "sod", "mop", "retail")
PAGES = ("index", "combos", "auctionhouse", "guilds", "geography", "talents", "gear")
LIMIT = 25 * 1024 * 1024


class Links(HTMLParser):
    def __init__(self):
        super().__init__()
        self.urls = []
        self.sources = []

    def handle_starttag(self, tag, attrs):
        fields = dict(attrs)
        for key in ("href", "src"):
            if fields.get(key):
                self.urls.append(fields[key])
        if fields.get("src"):
            self.sources.append(fields["src"])


def resolve_asset(root, pathname):
    path = root / pathname.lstrip("/")
    if path.is_file():
        return path
    if path.is_dir() and (path / "index.html").is_file():
        return path / "index.html"
    if (root / (pathname.lstrip("/") + ".html")).is_file():
        return root / (pathname.lstrip("/") + ".html")
    return None


def main():
    root = Path(sys.argv[1] if len(sys.argv) > 1 else "static").resolve()
    errors = []
    for name in ("_worker.js", "_routes.json"):
        if (root / name).exists():
            errors.append(f"Function artifact: {name}")
    for edition in EDITIONS:
        directory = root / edition
        for page in PAGES:
            html = directory / f"{page}.html"
            markdown = directory / f"{page}.md"
            if not html.is_file() or not markdown.is_file():
                errors.append(f"Missing page pair: {edition}/{page}")
                continue
            parser = Links()
            source = html.read_text(encoding="utf-8")
            parser.feed(source)
            if page == "index" and ('rel="stylesheet"' in source or "<style>" not in source):
                errors.append(f"Landing page CSS is not inline: {edition or '/'}")
            if page == "index":
                if source.count("<main>") != 1 or source.count("</main>") != 1:
                    errors.append(f"Landing page needs one main landmark: {edition or '/'}")
                if 'class="combo-more"' in source and '>See more</a>' in source:
                    errors.append(f"Generic combinations link: {edition or '/'}")
            route = "/" + (edition + "/" if edition else "") + ("" if page == "index" else page)
            for ref in parser.urls:
                url = urlsplit(urljoin("https://wowcensus.io" + route, ref))
                if url.netloc != "wowcensus.io":
                    continue
                if url.path.startswith(("/api/", "/admin/", "/item/", "/seller/", "/pop")):
                    errors.append(f"Dynamic link: {route} -> {ref}")
                elif not resolve_asset(root, url.path):
                    errors.append(f"Missing local asset: {route} -> {ref}")
            for ref in parser.sources:
                if ref.startswith(("https://wow.zamimg.com/", "https://www.wowhead.com/")):
                    errors.append(f"Remote asset load: {route} -> {ref}")
        for name in ("census.json", "inspects.json"):
            file = directory / name
            if not file.is_file():
                errors.append(f"Missing data: {edition}/{name}")
            else:
                json.loads(file.read_text(encoding="utf-8"))
    # Race and class profiles are the sitemap's two-segment routes (/forever/troll/, /tbc/paladin/).
    sitemap = (root / "sitemap.xml").read_text(encoding="utf-8")
    profiles = re.findall(r"<loc>https://[^/<]+(/[^/<]+/[^/<]+/)</loc>", sitemap)
    if not profiles:
        errors.append("No race or class profiles in sitemap")
    for route in profiles:
        directory = root / route.strip("/")
        for name in ("index.html", "index.md"):
            if not (directory / name).is_file():
                errors.append(f"Missing profile page: {route}{name}")
        profile_html = directory / "index.html"
        if profile_html.is_file():
            parser = Links()
            parser.feed(profile_html.read_text(encoding="utf-8"))
            for ref in parser.urls:
                url = urlsplit(urljoin(f"https://wowcensus.io{route}", ref))
                if url.netloc == "wowcensus.io" and not resolve_asset(root, url.path):
                    errors.append(f"Missing {route} profile asset: {ref}")
            if "https://wow.zamimg.com/" in profile_html.read_text(encoding="utf-8"):
                errors.append(f"Remote {route} profile icon URL")
    for file in root.rglob("*"):
        if not file.is_file() or file.suffix not in (".html", ".js", ".css") or "assets" in file.parts:
            continue
        source = file.read_text(encoding="utf-8")
        if "https://wow.zamimg.com/" in source:
            errors.append(f"Unlocalized zamimg URL: {file.relative_to(root)}")
        if "/assets/wow.zamimg.com/" in source:
            errors.append(f"Old asset URL: {file.relative_to(root)}")
        if file.suffix == ".html" and "<script src=\"/assets/js/tooltips.js\"" in source:
            errors.append(f"Remote tooltip runtime remains: {file.relative_to(root)}")
    if (root / "assets/wow.zamimg.com").exists():
        errors.append("Old asset directory remains")
    files = [file for file in root.rglob("*") if file.is_file()]
    for file in files:
        if file.stat().st_size > LIMIT:
            errors.append(f"Asset over 25 MiB: {file}")
    print(f"Checked {len(files)} files, {len(EDITIONS) * len(PAGES)} HTML pages, "
          f"{len(EDITIONS) * len(PAGES)} Markdown pages; errors={len(errors)}")
    for issue in errors[:30]:
        print(issue)
    if errors:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
