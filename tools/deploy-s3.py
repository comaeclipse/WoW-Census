#!/usr/bin/env python3
"""Upload only static objects whose bytes differ from the S3 website bucket."""
import argparse
import hashlib
import json
import mimetypes
import os
import shutil
import subprocess
from concurrent.futures import ThreadPoolExecutor, as_completed
from dataclasses import dataclass
from pathlib import Path

SHORT = "public, max-age=300"
LONG = "public, max-age=31536000, immutable"


@dataclass(frozen=True)
class Object:
    source: Path
    key: str
    content_type: str
    cache_control: str


def objects(source):
    for file in sorted(source.rglob("*")):
        if not file.is_file() or file.name == "_headers":
            continue
        key = file.relative_to(source).as_posix()
        immutable = (
            key.startswith(("fonts/", "data/")) or "/data/" in key
        )
        cache = LONG if immutable else SHORT
        content_type = {
            ".css": "text/css; charset=utf-8",
            ".html": "text/html; charset=utf-8",
            ".md": "text/markdown; charset=utf-8",
            ".json": "application/json; charset=utf-8",
        }.get(file.suffix, mimetypes.guess_type(file.name)[0] or "application/octet-stream")
        yield Object(file, key, content_type, cache)
        if file.suffix == ".html" and file.name not in ("index.html", "404.html"):
            yield Object(file, key[:-5], "text/html; charset=utf-8", SHORT)


def md5(file):
    digest = hashlib.md5()
    with file.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def changed(objects, remote_etags):
    digests = {}
    for obj in objects:
        if obj.source not in digests:
            digests[obj.source] = md5(obj.source)
        if remote_etags.get(obj.key, "").strip('"').lower() != digests[obj.source]:
            yield obj


def aws(base, *args):
    result = subprocess.run([*base, *args], capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError(result.stderr.strip() or f"AWS CLI exited {result.returncode}")
    return result.stdout


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--src", type=Path, default=Path(__file__).resolve().parents[1] / "static")
    parser.add_argument("--bucket", default=os.getenv("BUCKET", "wowcensus.io"))
    parser.add_argument("--profile", default=os.getenv("AWS_PROFILE_NAME", "wowcensus"))
    parser.add_argument("--region", default="us-east-2")
    parser.add_argument("--aws", default=os.getenv("AWS") or shutil.which("aws") or
                        str(Path(os.getenv("LOCALAPPDATA", "")) / "Programs/Amazon/AWSCLIV2/aws.exe"))
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--prune", action="store_true", help="remove remote keys absent from the static output")
    args = parser.parse_args()
    source = args.src.resolve()
    if not (source / "index.html").is_file() or not (source / "census.css").is_file():
        parser.error("build and verify a complete static tree first")
    base = [args.aws, "--profile", args.profile, "--region", args.region]
    remote_rows = json.loads(aws(base, "s3api", "list-objects-v2", "--bucket", args.bucket,
                                "--query", "Contents[].[Key,ETag]", "--output", "json")) or []
    remote = dict(remote_rows)
    local = list(objects(source))
    updates = list(changed(local, remote))
    stale = sorted(set(remote) - {obj.key for obj in local} - {"_headers"}) if args.prune else []
    print(f"S3 plan: {len(updates)} uploads, {len(stale)} removals, {len(local)} expected objects")
    if args.dry_run:
        for obj in updates:
            print("upload", obj.key)
        for key in stale:
            print("remove", key)
        return

    def upload(obj):
        aws(base, "s3", "cp", str(obj.source), f"s3://{args.bucket}/{obj.key}",
            "--content-type", obj.content_type, "--cache-control", obj.cache_control,
            "--only-show-errors")
        return obj.key

    # Publish CSS before HTML that references it, then the clean-route mirrors.
    assets = [obj for obj in updates if obj.key.endswith(".css")]
    pages = [obj for obj in updates if obj not in assets and
             obj.key == obj.source.relative_to(source).as_posix()]
    mirrors = [obj for obj in updates if obj not in assets and obj not in pages]
    for group in (assets, pages, mirrors):
        with ThreadPoolExecutor(max_workers=8) as pool:
            for result in as_completed([pool.submit(upload, obj) for obj in group]):
                print("uploaded", result.result())
    for key in stale:
        aws(base, "s3", "rm", f"s3://{args.bucket}/{key}", "--only-show-errors")
        print("removed", key)
    print(f"S3 complete: {len(updates)} uploads, {len(stale)} removals")


if __name__ == "__main__":
    main()
