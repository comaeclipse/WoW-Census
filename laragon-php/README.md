# The WoWCensus website

One PHP application renders every page on request from PostgreSQL. The same code runs in two places: on this PC for checking changes, and on the production server behind wowcensus.io. Nothing is built as static files, and there is no snapshot step: production has its own PostgreSQL database that the importers write to.

This file is the reference for the site. The step-by-step runbooks are `ingest.md` (get collected saves into local PostgreSQL) and `upload.md` (publish data and code to wowcensus.io) in the repository root.

## Where everything lives

| | Local (this PC) | Production (wowcensus.io) |
| --- | --- | --- |
| Web server | Laragon Apache on <http://127.0.0.1:8081/> | nginx + PHP-FPM 8.3 on `162.243.9.180`, behind Cloudflare |
| Code it runs | A **copy** in `C:\laragon\www\marketlens`, made by `deploy.ps1`. Edits in the repo show on :8081 only after running it | `/srv/wowcensus/current`, a symlink to one directory in `/srv/wowcensus/releases/` |
| Source of that code | This repository: `laragon-php/` (renderer and public files), `site/src/` and `site/public/` (browser scripts, templates, stylesheets, fonts), `tools/item-names/`, `tools/item-icons/`, `assets/` (mirrored icons) | `site.tar.gz` from `package-release.py`, laid over a copy of the previous release |
| Database | PostgreSQL 18 (Laragon, data in `C:\laragon\data\postgresql`), `127.0.0.1:5433`, database `marketlens_local`, schema `marketlens_import` | PostgreSQL 16 on the server, `127.0.0.1:5432`, database `marketlens`, role `wowcensus` |
| How data gets in | `tools/import-savedvariables-pg.py` and the other `tools/*-pg.py` importers | The same importers, run on this PC through one SSH tunnel by `tools/import-live.py` |
| Settings | Defaults in `src/config.php` | `env[...]` lines in `/etc/php/8.3/fpm/pool.d/wowcensus.conf` and `wowcensus-live.conf`; secrets in `/etc/wowcensus/` |
| Caches | The pre-computed census tables only | Census tables, APCu query results, the nginx page cache, Cloudflare's edge |
| Publishing scripts | Repo copies: `laragon-php/wowcensus-data-refresh`, `wowcensus-rollout`, `wowcensus-release-rollout` | Installed in `/usr/local/bin/`; cron `/etc/cron.d/wowcensus-data` |
| Shoutbox, blog comments, poll | SQLite files in the system temp folder | `/srv/wowcensus/shoutbox/shoutbox.sqlite`, with the poll's file beside it |
| Uploaded saves | `Documents\WoW Census Saves`, `Documents\MarketLensData` | `/srv/wowcensus/uploads`, `/srv/wowcensus/discord-submissions` |

The shoutbox, blog comments and poll are the only things stored in SQLite. Census, auction and inspection data is in PostgreSQL on both sides.

To run the site straight from the repository without copying it, start PHP's built-in server (the `site-repo` entry in `.claude/launch.json`, <http://127.0.0.1:8082/>). It serves one request at a time, so it suits checking a page, not browsing.

### Retired, do not bring back

- **The SQLite snapshot origin** (until 2026-11-06): the old droplet `157.230.90.141` served a SQLite file of captured query results. Its capture, build and refresh scripts and the `ML_QUERY_CACHE` / `ML_SQLITE_PATH` / `ML_CAPTURE_DIR` code paths were removed on 2026-11-10. Old `compact-origin-*.sqlite` files and capture folders in `Documents\MarketLensData` are leftovers of it.
- **The static build** (until 2026-10-27): `build-static.php`, S3, Cloudflare Pages, Vercel, and the Cloudflare D1 database before that.

## Code

- `src/`: the renderer. `Data.php` loads census and inspect data, `Edition.php` builds the edition pages, `Markdown.php` writes the Markdown copies, `Routes.php` lists the published routes, `Db.php` is the PostgreSQL access and the APCu query cache.
- `src/Js.php`: JavaScript-compatible number formatting, JSON, rounding, sorting and string comparison, which the pages' embedded scripts rely on. The site began as a byte-exact port of a retired Node generator, which is why some modules mirror JavaScript behaviour.
- The browser scripts and inline CSS the pages embed are read straight from `site/src/*.mjs`.
- `public/`: the front controller `index.php` and files served as they are.

## Run locally

Apache serves `C:\laragon\www\marketlens\public` on <http://127.0.0.1:8081/> (`C:\laragon\etc\apache2\sites-enabled\marketlens.conf`). Fonts, favicons and mirrored icons are plain files; every other path goes to `public/index.php`.

After changing `laragon-php/`, `site/src/` or `site/public/`, refresh the copy Apache serves:

```powershell
powershell -ExecutionPolicy Bypass -File laragon-php\deploy.ps1
```

PostgreSQL is the source of truth. `tools/import-savedvariables-pg.py` imports new game saves into it and then rebuilds the pre-computed census tables (`build-census-cache.php`); see `ingest.md`.

`http://127.0.0.1:8081/stats.php` is a local-only, 30-day realm collection history for AH scans and recorded `/who` commands from PostgreSQL. Its database snapshot is cached on the local server for 10 minutes. **Never publish `stats.php`.** `package-release.py` copies only the public files it names, so keep it out of that list; the PHP source stays in `laragon-php/public/` only.

`http://127.0.0.1:8081/schedule.php` is the local-only `/who` scan calendar: which realm and faction to sweep at which time, US and EU, with each slot checked off against the sweeps in PostgreSQL. It also offers an `.ics` download for phone reminders. The plan (lanes, slot times, realm order, calibration days) is `src/scan-plan.php`. Like `stats.php`, it must never be published, so it is not in the release package either.

`/faq.php` (`public/faq.php`) is the visitor FAQ; edit its questions in that file.

`/blog.php` is the public changelog index; `/blog.php?id=1234` displays post 1234. To publish an update, add a unique positive integer key with `date` (`YYYY-MM-DD`), `title`, and plain-text `body` to `src/blog-posts.php`. Separate body paragraphs with a blank line. The file ships with `src/` in every release. Release the updated PHP files and purge `/blog.php` and the changed post URL from Cloudflare so cached copies refresh.

## Rendered pages for the icon tools

```powershell
C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe laragon-php\render-routes.php [--sources=classic,sod] [--out=<dir>]
```

It writes the published routes of those editions (`src/Routes.php`) to `Documents\MarketLensData\rendered-pages`, emptied first. Nothing there is published. `tools/mirror-profile-icons.mjs` reads the class pages to find talent icons missing from `assets/`, and `tools/update-item-icons.mjs` reads the gear data and Auction House pages for items with no cached icon. Run these checks when preparing a release; the save watcher only imports into PostgreSQL by default.

## Production (wowcensus.io)

`/upload` is a private Lua file exchange using `UPLOAD_PASSWORD_HASH_FILE`
(falling back to the admin password when not configured). Uploaded
files are kept outside the public document root in `LUA_UPLOAD_DIR`
(`/srv/wowcensus/uploads` on the server), with download and deletion limited
to signed-in sessions. The page displays each file's server-calculated MD5.
The nginx cache map must bypass `/upload`; nginx allows 32 MB request bodies,
and PHP-FPM allows 25 MB files with a 32 MB POST limit. Files are not part
of site releases.

`www.wowcensus.io` and `d.wowcensus.io` point at the same server.

### Releasing code

```powershell
python -B laragon-php\package-release.py "$HOME\Documents\MarketLensData\release-<date>"
```

That writes `site.tar.gz`: `src/`, the public files the script names, and the resources the renderer reads. On the server:

1. Copy the current release to a new directory: `cp -a /srv/wowcensus/releases/<current> /srv/wowcensus/releases/<yyyymmdd>-<name>-<n>`. Copying keeps the mirrored icons and anything else only the server has.
2. Extract `site.tar.gz` over the copy, then `chown -R root:root` it (archives made on Windows carry a Windows user id).
3. Run `php -l` on the changed PHP files, repoint `/srv/wowcensus/current` to the new directory, and `systemctl restart php8.3-fpm`.
4. Start `wowcensus-release-rollout` (see "Cache strategy"). Without it cached pages keep the old release's HTML.

Bump `ASSET_VERSION` in `src/Wow2008.php` when `public/wow2008/` changed. Keep only the newest few releases; the disk is small. New PHP-FPM settings go in both pools.

### Page cache and publishing data

nginx keeps every rendered page, and a
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
minutes, log `/var/log/wowcensus-data-refresh.log`). At most every 15 minutes
(`PUBLISH_INTERVAL_MINUTES`) it rebuilds the census tables at low priority (about 5
minutes), then `wowcensus-rollout` (copy: `laragon-php/wowcensus-rollout`) re-renders
in place, one at a time, only the pages of the editions whose `local_imports` rows are
newer than the last publish (`ROLLOUT_SOURCES`), plus the home page, `index.*`,
`sitemap.xml` and `llms.txt`. The site root is Forever, so top-level pages (`/realms`,
`/guilds`, `/?fbclid=…` variants of the home page) go with Forever. Measured
2026-11-10 on the live cache of 2,490 entries: Forever 928, TBC 226, Classic + SoD
406, an unmapped AH house 12. Main pages go first, then by visits, pausing while the
load average is above 1.5. `ROLLOUT_DRY_RUN=1 wowcensus-rollout` (with
`ROLLOUT_SOURCES` or `ROLLOUT_MAIN=1`) lists what a pass would re-render without
rendering anything. `ROLLOUT_PATHS='/(guilds|geography)$'` limits a pass to matching paths (for a
release that changed only some page types). The cache is never emptied (the data part of the key is fixed in
`conf.d/wowcensus-data-version.conf`), so visitors get each page's saved copy until
it is replaced. The pools set `ML_CENSUS_PUBLISHED=1`, so until the new tables are
in, pages keep using the previous ones instead of the slow live SQL. A failure
leaves the old data up and is retried after 30 minutes. `FORCE=1` publishes now
(with no new data it re-renders everything); `REFRESH_FULL=1` re-renders every page.
After a code release run `wowcensus-release-rollout` (below).

New data normally reaches the live database without a sync: `tools/import-live.py`
runs an importer (`saves` or `csv`) against local PostgreSQL and then, through one SSH
tunnel to the server's PostgreSQL (127.0.0.1:5432, db `marketlens`, role `wowcensus`,
password read from `/etc/wowcensus/pg-password` into memory only), against the live
database. Parsing stays on the PC; only SQL crosses the wire, and the importers'
SHA-256 ledger makes a repeat a no-op on either side. The live run skips the local
census-cache rebuild; the next `wowcensus-data-refresh` publishes the change.
`--publish-now` starts that refresh with `FORCE=1`, but it exits at once while a
rollout holds `/run/wowcensus-data-refresh.lock`, and the cron then publishes it.
Each round trip over the tunnel costs ~40 ms, so the import functions in
`tools/import-functions.py` batch their writes (AH items and history, sellers,
inspects): two CSV snapshots took 36 s live instead of about 30 minutes. On
PostgreSQL (`PgDb.narrow_reads`) `import_inspects` reads only the characters and
stored talents its records name, and skips an edition with no records; it used
to download every character of Classic, SoD and Hardcore for each Era save. After a
changed import only the tables the importer writes are `ANALYZE`d. Live runs keep
their report and file copies in `MarketLensData\imports-live\`. See `upload.md` step 5.

A full database sync from local is still safe at any time and is the path for rebuilds
(schema changes, local-only repairs): dump `marketlens_import`, restore into `marketlens_next`,
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

### Cache strategy

Each page should be rendered about once per change; caches serve everyone else, and no step
ever empties a cache.

1. **Every change re-renders in place.** Data publishes already run `wowcensus-rollout`. After a
   code release, start `wowcensus-release-rollout` (copy: `laragon-php/wowcensus-release-rollout`,
   install to `/usr/local/bin/`):

   ```sh
   nohup /usr/local/bin/wowcensus-release-rollout >> /var/log/wowcensus-data-refresh.log 2>&1 &
   ```

   It waits 2 minutes for PHP's realpath cache to drop the old release, then runs one
   `wowcensus-data-refresh` pass with `FORCE=1 REFRESH_FULL=1 REFRESH_WAIT=1`: it waits for the
   lock (so two rollouts never overlap), publishes any data waiting to go live, and re-renders
   every cached page once, main pages first. A release whose code an earlier full pass already
   rendered (`/var/lib/wowcensus/code-rollout`) is skipped when no new data arrived meanwhile,
   so releases queued behind one rollout don't each re-render the whole site again. (Until
   2026-11-10 a release ran two full passes: `ROLLOUT_MAIN=1` was documented but not
   implemented, so its "main pages" pass re-rendered every page.)
2. **A 24-hour safety net in nginx.** Pages go stale after 24 hours instead of never, but are
   never thrown away and never make a visitor wait: nginx serves the saved copy and one
   background request re-renders it. In `snippets/wowcensus-cache.conf`, replace the
   `fastcgi_cache_valid` line with these (keep `inactive=3650d` on `fastcgi_cache_path`):

   ```nginx
   fastcgi_cache_valid 200 301 302 404 24h;
   fastcgi_cache_use_stale updating error timeout http_500 http_503;
   fastcgi_cache_background_update on;
   fastcgi_cache_lock on;
   fastcgi_cache_lock_timeout 10s;
   ```

   `nginx -t && systemctl reload nginx`. The lock means one render per page at a time; a failed
   render keeps serving the old copy. nginx stores each page's expiry when it saves it, so pages
   saved before this change keep their 10-year expiry until the next full rollout re-saves them.
3. **Cloudflare serves most traffic.** Turn on Tiered Cache (Caching > Tiered Cache > Smart
   Tiered Caching), so a miss at one location asks a large Cloudflare location before the
   origin. Then let pages live at the edge for a day and purge them when they change:
   `wowcensus-rollout` purges each re-rendered default-skin HTML page (30 URLs per API call)
   when `/etc/wowcensus/cloudflare.env` (root:root 600) holds

   ```sh
   CF_ZONE_ID=<wowcensus.io zone id>
   CF_API_TOKEN=<token with Zone > Cache Purge on wowcensus.io only>
   ```

   Once a rollout log shows `N purged at Cloudflare`, add `env[ML_EDGE_HTML_TTL] = 86400` to both
   PHP-FPM pools and restart PHP-FPM; the next rollout re-saves pages with the longer edge TTL.
   Leave the TTL at its 900-second default wherever purging is not set up.
4. **Load guards stay.** One page at a time, the load-average pause, and the separate
   `wowcensus-live` pool. A bigger VPS changes settings only: more `pm.max_children`, a higher
   `ROLLOUT_MAX_LOAD`, a shorter `ROLLOUT_PAUSE_SECONDS`.

## Contact form

`/contact` is handled by `src/contact.php` (`public/index.php` hands the path
over; nginx refuses every other `*.php` URL). DigitalOcean blocks outbound
SMTP, so mail goes out through the Resend HTTPS API. Spam checks: a math
question kept in the PHP session, a hidden honeypot field and a 3-second
minimum between showing the form and sending it. Sessions start only on this
page, so the cacheable pages never get a session cookie.

Set these in the server's pool, `/etc/php/8.3/fpm/pool.d/wowcensus.conf`,
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

## Discord /submit

Players run `/submit file:<WoWCensus.lua>` in Discord. Discord POSTs the
interaction to `https://wowcensus.io/discord/interactions`, handled by
`src/discord-interactions.php`: it checks the Ed25519 signature, answers with an
ephemeral "thinking…" reply within Discord's 3 seconds, then (after
`fastcgi_finish_request()`) downloads the attachment from Discord's CDN and edits
the reply with the result. No bot process or gateway connection runs.

The attachment may be a `.lua` or a `.zip` of at most 25 MB. Each `.lua` in a
zip (folders inside it are ignored, as are `__MACOSX` and hidden entries) is
unzipped and handled as if sent alone, so the MD5 is of the unzipped save and a
save already received plain or zipped is a duplicate either way. Unzipping stops
at 100 MB per file and 200 MB per zip, whatever the zip header claims, and
password-protected entries are skipped. Reading zips needs PHP's zip extension
(`apt install php8.3-zip`); without it players are asked to send the `.lua`.

A file is kept only when its text has a `WoWCensusDB = {` or
`MarketLensDB = {` line and no null bytes; it is never executed. It is stored
in `DISCORD_SUBMIT_DIR` as `<name>-<discord username>-<YYYYMMDD-HHMMSS UTC>.lua`
(for example `WoWCensus-thrall.88-20261109-214520.lua`; a file from a zip takes
its own name, not the zip's), and a line in `submissions.jsonl` there records its
MD5, size, original name, the zip it came in (`archive`), and the sender's
Discord id and username. A file whose MD5 is already listed is not stored again,
and each Discord user may store 10 files an hour, counting each file in a zip.

Each `/submit` that stores something also sends one email through Resend (the
contact form's `RESEND_API_KEY` and `CONTACT_FROM`) to `DISCORD_SUBMIT_EMAIL_TO`,
or `CONTACT_TO` when that is unset. The email lists each file's MD5 and size and
attaches them in one `.zip` (left off above 28 MB zipped). It is sent after the
Discord reply, and a failure is only logged. Duplicates and rejected files send
nothing. The signed-in `/upload` page lists the submissions under "Discord
submissions", newest first, with download and delete. Deleting removes the file
but keeps its `submissions.jsonl` line, so the same save is still a duplicate. Submissions are unverified
player data: review them before importing.

Setup:

1. In the Discord Developer Portal, create an application with a bot. Copy the
   application id and public key; reset and copy the bot token (only the
   registration script needs it, never the server).
2. In the live pool, `/etc/php/8.3/fpm/pool.d/wowcensus-live.conf`, then
   `systemctl restart php8.3-fpm`:

   ```ini
   env[DISCORD_PUBLIC_KEY] = <public key>
   env[DISCORD_SUBMIT_DIR] = /srv/wowcensus/discord-submissions
   ```

   Create that directory owned by `www-data` (mode 770).
3. In `/etc/nginx/conf.d/wowcensus-code-version.conf` add `/discord/` to the
   per-visitor routes that go to the live pool, and add `~^/discord` to the
   `$wowcensus_nocache` map. Make sure the Cloudflare rule for per-visitor routes
   (and any WAF or bot-fight rule) lets POSTs to `/discord/interactions` through.
4. Set the application's Interactions Endpoint URL to
   `https://wowcensus.io/discord/interactions`. Discord saves it only after a
   signed PING succeeds and a forged one gets 401.
5. Register the command: `python -B tools/register-discord-commands.py` (env
   `DISCORD_APP_ID`, `DISCORD_BOT_TOKEN`; add a server id to test in one server
   first), then invite the bot with the `applications.commands` scope.

## Admin portal

`/admin/` (`src/admin.php`) shows the collection status page that local
`public/stats.php` shows, read live from PostgreSQL. The
password hash (output of `php -r 'echo password_hash("...", PASSWORD_DEFAULT);'`)
lives in `/etc/wowcensus/admin-password-hash` (root:www-data 640), named by
`env[ADMIN_PASSWORD_HASH_FILE]` in the pool. Don't put the hash itself in
`env[]`: PHP-FPM reads a value starting with `$` as another variable's name,
so `$2y$...` arrives empty. Without a hash `/admin/` is a 404. Sign-in uses a
`/admin/`-scoped session cookie, CSRF tokens, and 8 failed attempts per
address per 15 minutes. The page sends `X-Accel-Expires: 0`, and the nginx
`$wowcensus_nocache` map must list `~^/admin`, since fastcgi_cache ignores
`Cache-Control` and would otherwise serve a signed-in page to anyone.

`/admin/?view=cache` (`src/AdminCache.php`) shows every cache layer as the
PHP-FPM user sees it right now: the nginx page cache (scans the cache files'
headers and keys; results reused for 2 minutes, "Rescan now" forces it; look up
any path's cached variants), publishes and rollouts (refresh log, state files,
running jobs from `/proc`), origin traffic from the tail of the nginx access log,
APCu (memory, hit rate, entries per data version, most read / slowest / largest
queries from the `ml:qm:` metadata `Db.php` stores beside each result), OPcache
and the realpath entry for `/srv/wowcensus/current`, and PostgreSQL (census
tables vs. the newest import, buffer hit rates, running queries). Its one action
drops APCu results for older data versions. A source the pool user can't read
shows why instead of numbers. Add `$upstream_cache_status` to nginx's log_format
to get page-cache hit/miss counts there.

## Shoutbox

The WoW 2008 sidebar links to `/shoutbox/` (`src/shoutbox.php` and
`src/wow2008/pages/shoutbox.php`). It uses its own writable SQLite file, apart
from the PostgreSQL census data (`/srv/wowcensus/shoutbox/shoutbox.sqlite` on the server);
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

Blog posts (`/blog.php?id=N`) take comments (`src/blog-comments.php`). They
are stored in the same SQLite file as the shoutbox (table `blog_comments`, with
a `hidden_at` column for hiding one), filtered by the shoutbox word list, and
blocked for shoutbox-banned posters. The human check is a 5-character PNG drawn
with GD (`/blog.php?id=N&captcha=…`), or a math question when GD is missing;
there is also a honeypot field, a CSRF token and a 60-second cooldown. Every
post page re-sends its session cookie so nginx's page cache skips it. There is
no admin view yet: hide a comment with
`UPDATE blog_comments SET hidden_at = strftime('%s','now') WHERE id = …`.
