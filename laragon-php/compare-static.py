#!/usr/bin/env python3
"""Compare the PHP site with a static build, file by file.

    python -B laragon-php/compare-static.py [static-dir] [base-url] [--only=html,json,md,...]

Every published file in the static tree is fetched from the PHP site and
compared byte for byte. "Page generated" times differ by design (the PHP site
renders on request), so those lines are normalized first. HTML pages are also
checked at their clean route (/classic/combos for combos.html).
"""
import os
import re
import sys
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor

args = [a for a in sys.argv[1:] if not a.startswith('--')]
root = os.path.abspath(args[0] if args else os.path.join(os.path.dirname(__file__), '..', 'static'))
base = (args[1] if len(args) > 1 else 'http://127.0.0.1:8081').rstrip('/')
only = next((a[7:].split(',') for a in sys.argv[1:] if a.startswith('--only=')), None)

# Copied as-is into the PHP document root, or not produced by the generator.
SKIP_DIRS = {'assets', 'fonts', 'mockups'}
SKIP_FILES = {'favicon.ico', 'favicon-96.png', 'build-report.json'}
GENERATED = re.compile(rb'Page generated: \d{4}-\d\d-\d\d \d\d:\d\dZ')


def fetch(path):
    try:
        with urllib.request.urlopen(base + urllib.request.quote(path), timeout=600) as r:
            return r.status, r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


def normalize(body):
    return GENERATED.sub(b'Page generated: <time>', body)


def check(rel):
    with open(os.path.join(root, rel), 'rb') as f:
        expected = normalize(f.read())
    path = '/' + rel.replace(os.sep, '/')
    urls = [path]
    if path.endswith('.html') and not path.endswith(('/index.html', '/404.html')):
        urls.append(path[:-5])
    if path.endswith('/index.html'):
        urls.append(path[:-10])
    problems = []
    for url in urls:
        status, body = fetch(url)
        body = normalize(body)
        if status != 200:
            problems.append(f'{url}: HTTP {status} {body[:160]!r}')
        elif body != expected:
            at = next((i for i, (a, b) in enumerate(zip(body, expected)) if a != b), min(len(body), len(expected)))
            problems.append(f'{url}: differs at byte {at} (got {len(body)}, want {len(expected)})\n'
                            f'    got  {body[max(0, at - 80):at + 120]!r}\n    want {expected[max(0, at - 80):at + 120]!r}')
    return problems


files = []
for dirpath, dirnames, filenames in os.walk(root):
    rel_dir = os.path.relpath(dirpath, root)
    if rel_dir == '.':
        dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS]
    for name in filenames:
        rel = os.path.normpath(os.path.join(rel_dir, name))
        if rel in SKIP_FILES:
            continue
        ext = os.path.splitext(name)[1].lstrip('.') or name
        if only and ext not in only:
            continue
        files.append(rel)

with ThreadPoolExecutor(6) as pool:
    results = list(pool.map(check, sorted(files)))
bad = [p for r in results for p in r]
for p in bad:
    print(p)
print(f'{len(files)} files checked, {len(bad)} problems')
sys.exit(1 if bad else 0)
