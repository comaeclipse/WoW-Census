# WoWCensus on Apache, PHP and PostgreSQL

The WoWCensus website. Every page is rendered when it is requested: locally from PostgreSQL, and on the droplet (wowcensus.io) from a SQLite snapshot of the query results the published pages need. It began as a byte-exact port of a retired Node static generator, which is why some modules mirror JavaScript behaviour.

- `src/`: the renderer. `Data.php` loads census and inspect data, `Edition.php` builds the edition pages, `Markdown.php` writes the Markdown copies (with turndown), `Routes.php` lists the published routes, and so on.
- `src/Js.php`: JavaScript-compatible number formatting, JSON, rounding, sorting and string comparison, which the pages' embedded scripts rely on.
- The browser scripts and inline CSS the pages embed are read straight from `site/src/*.mjs`.

## Run

Apache serves `C:\laragon\www\marketlens\public` on <http://127.0.0.1:8081/> (`C:\laragon\etc\apache2\sites-enabled\marketlens.conf`). Fonts, favicons and mirrored icons are plain files; every other path goes to `public/index.php`.

`http://127.0.0.1:8081/stats.php` is a local-only, 30-day realm collection history for AH scans and recorded `/who` commands from PostgreSQL. Its database snapshot is cached on the local server for 10 minutes. **Never publish `stats.php`.** `package-compact-origin.py` copies only the public files it names, so keep it out of that list; the PHP source stays in `laragon-php/public/` only.

`http://127.0.0.1:8081/schedule.php` is the local-only `/who` scan calendar: which realm and faction to sweep at which time, US and EU, with each slot checked off against the sweeps in PostgreSQL. It also offers an `.ics` download for phone reminders. The plan (lanes, slot times, realm order, calibration days) is `src/scan-plan.php`. Like `stats.php`, it must never be published, so it is not in the origin package either.

`/blog.php` is the public changelog index; `/blog.php?id=1234` displays post 1234. To publish an update, add a unique positive integer key with `date` (`YYYY-MM-DD`), `title`, and plain-text `body` to `src/blog-posts.php`. Separate body paragraphs with a blank line. The file is included in local and compact-origin packages. Deploy the updated PHP files to the origin and purge `/blog.php` and the changed post URL from Cloudflare so cached copies refresh.

After changing `src/`, `site/src/` or `site/public/`, redeploy:

```powershell
powershell -ExecutionPolicy Bypass -File laragon-php\deploy.ps1
```

PostgreSQL is `marketlens_local` on port 5433, schema `marketlens_import`. It is the source of truth: `tools/import-savedvariables-pg.py` imports new game saves into it, and `tools/watch-savedvariables.py` runs that after every save (see `upload.md`).

## Rendered pages for the icon tools

```powershell
C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe laragon-php\render-routes.php [--sources=classic,sod] [--out=<dir>]
```

It writes the published routes of those editions (`src/Routes.php`) to `Documents\MarketLensData
endered-pages`, emptied first. Nothing there is published. `tools/mirror-profile-icons.mjs` reads the class pages to find talent icons missing from `assets/`, and `tools/update-item-icons.mjs` reads the gear data and Auction House pages for items with no cached icon. Run these checks when preparing a release; the save watcher only imports into PostgreSQL by default.

## Starting over from a SQLite snapshot

`tools/migrate-sqlite-to-postgres.py <snapshot>\marketlens-unified.sqlite` replaces every table. It ends with `ANALYZE`, and without that the census queries take tens of seconds. It also adds `items.seq`, which keeps SQLite's insertion order for breaking ties between items with equal quantities.

## The droplet origin (wowcensus.io)

`/upload` is a private Lua file exchange using `UPLOAD_PASSWORD_HASH_FILE`
(falling back to the admin password when not configured). Uploaded
files are kept outside the public document root in `LUA_UPLOAD_DIR`
(`/srv/wowcensus/uploads` on the droplet), with download and deletion limited
to signed-in sessions. The page displays each file's server-calculated MD5.
The nginx cache map must bypass `/upload`; nginx allows 32 MB request bodies,
and PHP-FPM allows 25 MB files with a 32 MB POST limit. Files are not included
in SQLite snapshots or site releases.

`d.wowcensus.io` still resolves to the droplet, but its own nginx server block
(first in `/etc/nginx/sites-available/wowcensus`) answers every request with a
301 to `https://wowcensus.io$request_uri`.

The pipeline is local PostgreSQL -> SQLite snapshot -> DigitalOcean droplet.
The Nginx/PHP-FPM origin runs the same PHP renderer against a SQLite file
(`ML_SQLITE_PATH`, `/srv/wowcensus/data/current.sqlite`) holding only the query
results the published routes need and the page models below. The full local
`marketlens_local` database stays on the Windows machine; the snapshot has no
raw source-file blobs, auction history, character-inspection identities, or
import strings.

`Db::all()` reads `query_results` when `ML_QUERY_CACHE=1`; `load_inspects()`
reads `published_models`. Pages built from inspections or auction scans read
only their own page models there: `inspect-summary:`, `talents:`, `gear:`,
`class-profiles:`, `race-profiles:` and `auctionhouse:` per edition. Decoding
a whole inspection model or every auction item per request needs more memory
than the origin droplet has. The capture writes all six for the edition each
route group is home to; a snapshot without them falls back to the full data.
Guild roster pages (`/guilds/<id>/`, `/tbc/guilds/<id>/`, ...) are not in the
published route list: the capture writes one `guild:<edition>-<id>` model per realm
guild (about 43,500, 21 MB raw) and a page decodes only its own; an unknown id
is a 404. The rosters list names from `/who` sightings; specs are counted per
guild, never tied to a name, since the origin carries no inspection identities.
Missing keys fail closed with a non-cacheable 500, so
new routes or query changes require a fresh capture before deployment. PHP
still renders HTML, Markdown and JSON per request from that data.

After new data reaches PostgreSQL, or a code change alters pages or queries,
capture a new snapshot from the repository root:

For a **data-only refresh of some editions**, the faster path starts from the
deployed snapshot, captures queries for every route group (collection aliases
and region fixes can change SQL keys shared between editions), and replaces only
the listed editions' page models and guild rosters, plus the admin activity
model. Other editions keep their page models and rosters as deployed; their
query-backed pages (census, realms, guild lists) do pick up the new query rows.
Use the edition's source key or its directory name: `forever`, `forever-beta`
(`classic-beta`), `tbc` (`classic-progression`), `classic`, `sod`, `hardcore`,
`mop` (`mop-classic`), `retail`. The first run needs a local copy of the active
droplet SQLite; its SHA-256 must match the droplet's `current.sqlite`.
Subsequent successful deployments remember the new local base
(`Documents\MarketLensData\current-origin-snapshot.txt`).

```powershell
python -B laragon-php\refresh-origin.py --editions=forever,forever-beta --deploy
python -B laragon-php\refresh-origin.py --editions=tbc --base "C:\Users\skarz\Documents\MarketLensData\<active snapshot>.sqlite"
# Forever only, as before: python -B laragon-php\refresh-forever-origin.py --deploy
```

Omit `--deploy` to build and validate a local candidate. The deploy path checks
the active base hash twice, tests both skins and every published route against
the deployed PHP release, uploads a versioned SQLite, atomically switches `current.sqlite`,
clears nginx's rendered-page cache, and checks each listed edition's public realms route.
It retains old query keys that are no longer used until the next full rebuild;
stale guild models of the listed editions are removed immediately
(`refresh-origin-sqlite.py` matches `guild:<edition>-<digits>`, so `classic`
never touches `classic-beta` or `classic-progression` rosters). Use the full
capture below for code/query changes or when every edition changed.

To rebuild all editions:

```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
$capture = Join-Path "$HOME\Documents\MarketLensData" ("compact-origin-capture-" + (Get-Date -Format 'yyyyMMdd-HHmmss'))
foreach ($part in @('', '.well-known', 'classic', 'forever', 'forever-beta', 'hardcore', 'mop', 'retail', 'sod', 'tbc')) {
  & $php -d memory_limit=2G laragon-php\capture-page-data.php $capture $part
  if ($LASTEXITCODE -ne 0) { throw "Capture failed for $part" }
}
python -B laragon-php\build-sqlite-origin.py $capture "$capture.sqlite"
python -B laragon-php\package-compact-origin.py $capture "${capture}-build"
```

Run each top-level route group in a separate PHP process to release large
in-memory inspection and market data between groups. Use a new capture
directory for every refresh so removed routes and queries cannot carry over
from an older snapshot. The capture renders each group's routes from
`src/Routes.php` (pages, Markdown copies, data files, stylesheets, sitemap,
robots, llms.txt). `build-sqlite-origin.py` writes the snapshot; the package's
`site.tar.gz` is the code release. Check the counts both print, then follow
`upload.md` to install them.

The origin PHP pool reads the snapshot through the `current.sqlite` symlink;
repointing it switches data, and repointing `/srv/wowcensus/current` switches
code (repoint data before code). Public PHP responses use a 60-second browser TTL
and request a five-minute Cloudflare TTL; a Cloudflare Cache Rule can override
that origin value. Nginx enables gzip and long-lived asset caching. Cloudflare
also needs a Cache Rule marking the public HTML routes
eligible for caching. Purge changed canonical URLs after a release, since a
cache hit can continue to serve the previous page even when the origin is
updated.

On `d.wowcensus.io` a Cloudflare Cache Rule respects these origin headers
(except `/assets/images/`), so HTML is not cached at the edge and the
`wowcensus_skin` cookie reaches the origin. Nginx caches rendered pages itself
(`/etc/nginx/conf.d/wowcensus-cache.conf` and
`/etc/nginx/snippets/wowcensus-cache.conf`): one copy per URL and skin, for 10
minutes, refreshed in the background. The cache key includes the resolved
release directory, so repointing `current` starts an empty cache; after
replacing origin tables without a new release, clear
`/var/cache/nginx/wowcensus/` or wait out the 10 minutes. `/contact`,
`/shoutbox` and `/skin` bypass the cache, as does any response that sets a
cookie. The `X-Cache` response header shows `HIT`, `MISS` or `BYPASS`.

On the PostgreSQL production server (162.243.9.180) cached pages never expire; a
new data version retires them (`wowcensus-data-refresh`). Since 2026-11-08 the
key holds a fixed code version from `/etc/nginx/conf.d/wowcensus-code-version.conf`
instead of the release directory, because a release that emptied the cache left
3 PHP workers re-rendering cold pages for over an hour. Change that string only to
retire every page on purpose. The same file sends the per-visitor routes
(`/shoutbox`, `/poll`, `/contact`, `/admin`, `/upload`, `/skin`) to their own
PHP-FPM pool (`pool.d/wowcensus-live.conf`, 2 workers), so they never queue
behind page renders.

New data goes live through `/usr/local/bin/wowcensus-data-refresh` (copy:
`laragon-php/wowcensus-data-refresh`; cron `/etc/cron.d/wowcensus-data`, every 5
minutes, log `/var/log/wowcensus-data-refresh.log`). At most once an hour it
rebuilds the census tables at low priority, then `wowcensus-rollout` (copy:
`laragon-php/wowcensus-rollout`) re-renders every cached page in place, one at a
time: the 72 main pages first, then by visits, pausing while the load average is
above 1.5. The cache is never emptied (the data part of the key is fixed in
`conf.d/wowcensus-data-version.conf`), so visitors get each page's saved copy until
it is replaced. The pools set `ML_CENSUS_PUBLISHED=1`, so until the new tables are
in, pages keep using the previous ones instead of the slow live SQL. A failure
leaves the old data up and is retried after 30 minutes. `FORCE=1` publishes now;
after a code release, run `wowcensus-rollout` (or `ROLLOUT_LIMIT=72` for just the
main pages) to bring cached pages up to date.

A database sync from local is safe at any time: restore into `marketlens_next`,
swap the names with PHP-FPM stopped (about a second), and the next pass publishes
it. Check first that every `local_imports.source_sha256` on the server is also
local, so no upload made on the live site is lost.

Cloudflare caches pages for the default 2008 skin at the edge for 15 minutes: the
origin sends `Cloudflare-CDN-Cache-Control: public, max-age=900` for that skin
(browsers still get `private, no-store`, so a skin switch shows at once), and the
zone's Cache Rule "Pages: default skin" makes HTML eligible except for
`wowcensus_skin=current|cobalt` cookies, Accept headers naming Markdown or `text/*`,
and the per-visitor routes. Those always reach the origin (`cf-cache-status: DYNAMIC`).
New data therefore reaches visitors up to 15 minutes after the rollout re-renders a page.

## Contact form

`/contact` is handled by `src/contact.php` (`public/index.php` hands the path
over; nginx refuses every other `*.php` URL). DigitalOcean blocks outbound
SMTP, so mail goes out through the Resend HTTPS API. Spam checks: a math
question kept in the PHP session, a hidden honeypot field and a 3-second
minimum between showing the form and sending it. Sessions start only on this
page, so the cacheable pages never get a session cookie.

Set these in the droplet's pool, `/etc/php/8.3/fpm/pool.d/wowcensus.conf`,
then `systemctl restart php8.3-fpm`:

```ini
env[RESEND_API_KEY] = re_...
env[CONTACT_TO] = you@example.com
; Optional. A sender on a domain verified in Resend; without it Resend's
; onboarding@resend.dev sender only delivers to the account's own address.
env[CONTACT_FROM] = WoWCensus <contact@wowcensus.io>
```

Without `RESEND_API_KEY` and `CONTACT_TO` the form shows "could not be sent"
and logs `contact: RESEND_API_KEY or CONTACT_TO is not set`. `/contact` must
never be cached: it sends `Cache-Control: private, no-store`.

## Admin portal

`/admin/` (`src/admin.php`) shows the collection status page that local
`public/stats.php` shows, read from the origin's snapshot: the root capture
writes the `admin:collection-activity` model and `build-sqlite-origin.py`
refuses a capture without it. Locally it queries PostgreSQL live. The
password hash (output of `php -r 'echo password_hash("...", PASSWORD_DEFAULT);'`)
lives in `/etc/wowcensus/admin-password-hash` (root:www-data 640), named by
`env[ADMIN_PASSWORD_HASH_FILE]` in the pool. Don't put the hash itself in
`env[]`: PHP-FPM reads a value starting with `$` as another variable's name,
so `$2y$...` arrives empty. Without a hash `/admin/` is a 404. Sign-in uses a
`/admin/`-scoped session cookie, CSRF tokens, and 8 failed attempts per
address per 15 minutes. The page sends `X-Accel-Expires: 0`, and the nginx
`$wowcensus_nocache` map must list `~^/admin`, since fastcgi_cache ignores
`Cache-Control` and would otherwise serve a signed-in page to anyone.

## Shoutbox

The WoW 2008 sidebar links to `/shoutbox/` (`src/shoutbox.php` and
`src/wow2008/pages/shoutbox.php`). It uses a separate writable SQLite file;
set `SHOUTBOX_DB_PATH` to a persistent path outside the release directory and
`SHOUTBOX_HASH_KEY` to a stable random secret in the PHP-FPM environment. The
directory must be writable by the PHP-FPM user. Cloudflare caches GET pages,
so the form and recent messages refresh through an uncached POST initialization.
The math check, one-use session challenge, and 60-second visitor cooldown are
enforced on the server.

Moderation lives at `/admin/?view=shoutbox` (`src/AdminShoutbox.php`; the
schema is in `src/shoutbox-db.php`). Hiding a shout sets `hidden_at`, which
drops it from the public log but keeps it restorable. Posters are known only
by `poster_hash` (an HMAC of their address), so the tools act on that: time
out for 1 hour, 24 hours or 7 days (`shoutbox_timeouts`), ban until lifted
(`shoutbox_bans`, with an optional reason), or hide all their shouts. Bans and
timeouts only stop new shouts; someone on a new network gets a new hash.
