# WoWCensus on Apache, PHP and PostgreSQL

A PHP port of the static generator (`tools/build-static-site.mjs`). Each page is rendered from PostgreSQL when it is requested, and the output is byte-for-byte identical to the matching file in `static/`. The one exception is the "Page generated" time, which here is the time of the request.

- `src/`: the port. The files map onto the generator's modules: `Data.php` is `local-site-data.mjs` plus the inspect loader, `Edition.php` is `edition.mjs`, `Markdown.php` is `build-markdown-pages.js` with turndown, and so on.
- `src/Js.php`: JavaScript-compatible number formatting, JSON, rounding, sorting and string comparison. Byte-exact output depends on these.
- The browser scripts and inline CSS the pages embed are read straight from `site/src/*.mjs`, so they cannot drift from the static build.

## Run

Apache serves `C:\laragon\www\marketlens\public` on <http://127.0.0.1:8081/> (`C:\laragon\etc\apache2\sites-enabled\marketlens.conf`). Fonts, favicons and mirrored icons are plain files; every other path goes to `public/index.php`.

After changing `src/`, `site/src/` or `site/public/`, redeploy:

```powershell
powershell -ExecutionPolicy Bypass -File laragon-php\deploy.ps1
```

PostgreSQL is `marketlens_local` on port 5433, schema `marketlens_import`. It is the source of truth: `tools/import-savedvariables-pg.py` imports new game saves into it, and `tools/watch-savedvariables.py` runs that after every save (see `upload.md`).

## Build static/ from PostgreSQL

```powershell
C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe laragon-php\build-static.php [--sources=classic,sod] [--dry-run]
```

It renders every file with the code above and writes only those whose content changed. "Page generated" times are left out of the comparison, so unchanged pages keep theirs. It runs from the repository directly: templates and scripts come from `site/src/`, `site/public/` and `tools/item-*` when there is no deployed `resources/` folder beside `src/`.

## Starting over from a SQLite snapshot

`tools/migrate-sqlite-to-postgres.py <snapshot>\marketlens-unified.sqlite` replaces every table. It ends with `ANALYZE`, and without that the census queries take tens of seconds. It also adds `items.seq`, which keeps SQLite's insertion order for breaking ties between items with equal quantities.

## Check against the static build

Build `static/` from the same snapshot that is loaded into PostgreSQL, then run:

```powershell
python -B laragon-php\compare-static.py
```

Every published file is fetched from the PHP site, including the clean route for each HTML page, and compared byte for byte.

## Compact PostgreSQL origin at d.wowcensus.io

The Nginx/PHP-FPM origin uses the same PHP renderer with a small, published-data
database. The full local `marketlens_local` database remains on the Windows
machine. Only query results needed by published routes and seven anonymous
inspection models are transferred. The `marketlens` database on the origin is
about 39 MB for the October 15, 2026 capture; it has no raw source-file blobs,
auction history, character-inspection identities, or import strings.

`Db::all()` reads `query_results` when `ML_QUERY_CACHE=1`; `load_inspects()`
reads `published_models`. Missing keys fail closed with a non-cacheable 500, so
new routes or query changes require a fresh capture before deployment. PHP
still renders HTML, Markdown and JSON per request from PostgreSQL data.

After updating the local PostgreSQL source and static route set, refresh the
compact package from the repository root:

```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
$capture = Join-Path "$HOME\Documents\MarketLensData" ("compact-origin-capture-" + (Get-Date -Format 'yyyyMMdd-HHmmss'))
foreach ($part in @('', '.well-known', 'classic', 'data', 'forever', 'hardcore', 'mop', 'retail', 'sod', 'tbc')) {
  & $php -d memory_limit=2G laragon-php\capture-page-data.php static $capture $part
  if ($LASTEXITCODE -ne 0) { throw "Capture failed for $part" }
}
python -B laragon-php\package-compact-origin.py $capture "${capture}-build"
```

Run each top-level route group in a separate PHP process to release large
in-memory inspection and market data between groups. Use a new capture
directory for every refresh so removed routes and queries cannot carry over
from an older snapshot. The capture visits
published HTML, Markdown, JSON, CSS, XML and text routes; `build-report.json`
is intentionally not a public route. The package contains `site.tar.gz`,
`query-results.csv.gz`, and `published-models.csv.gz`. Inspect the package
counts and test it before replacing the origin tables and Nginx release root.

The origin PHP pool connects through PostgreSQL's local peer-authenticated
socket as the dedicated `marketlens` user. PostgreSQL listens on loopback,
not the public interface. Public PHP responses use a 60-second browser TTL
and request a five-minute Cloudflare TTL; a Cloudflare Cache Rule can override
that origin value. Nginx enables gzip and long-lived asset caching. Cloudflare
also needs a Cache Rule marking the public HTML routes
eligible for caching. Purge changed canonical URLs after a release, since a
cache hit can continue to serve the previous page even when the origin is
updated.
