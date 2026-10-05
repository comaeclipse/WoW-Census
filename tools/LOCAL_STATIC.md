# Local snapshot and static preview

From the repository root on this computer:

```powershell
python -B tools/refresh-local-static.py
```

The command reuses the newest verified local historical D1 archive, copies the
current MarketLens and NameplateInspect SavedVariables from the five WoW
installations, merges all captured data into `marketlens-unified.sqlite`,
and builds `static/`. It stops on export, integrity, import, or static verification
failure. The snapshot directory is printed at the end and defaults to
`%USERPROFILE%\Documents\MarketLensData\<UTC timestamp>`. The SQLite database
and raw Lua copies stay outside the repository. `static/` is generated output.

To rebuild from an existing captured snapshot without contacting Cloudflare:

```powershell
python -B tools/refresh-local-static.py --from-snapshot 'C:\path\to\snapshot'
```

To choose a specific archive, pass `--d1-from`. Add `--source` to re-render only the
editions that got new data (untouched files keep their bytes and mtimes):

```powershell
python -B tools/refresh-local-static.py --d1-from 'C:\path\to\snapshot' --source=classic-beta
```

Only when the historical D1 data actually changes, run
`python -B tools/refresh-local-static.py --refresh-d1`. This uses
`tools/d1-archive.wrangler.toml`, which has a D1 binding but no Worker entry
point. Run `npx wrangler login` from the repository root if that explicit
export reports an authentication error. Exit the game or use `/reload` before capturing if you want the latest
in-game SavedVariables written to disk.

wowcensus.io serves `static/` from the `wowcensus` Pages project; the root
`wrangler.toml` points that project at `./static`. Deploy a verified `static/`
directory to production with:

```powershell
npx wrangler pages deploy static --project-name wowcensus --branch main --commit-dirty=true
```

To check a build first, deploy it to the static preview project
<https://wowcensus-static-preview.pages.dev/>:

```powershell
npx wrangler pages deploy static --project-name wowcensus-static-preview --branch main --commit-dirty=true
```

The build writes `static/_headers` with a `Cache-Control: public, max-age=300`
rule per HTML page (pages are served at extensionless URLs, so a `/*.html`
rule would not match them), immutable caching for fingerprinted CSS and fonts,
and five minutes for JSON. Cloudflare Pages ignores rules past the 100th, so the
build fails if the page count would exceed that.

The six WoWCensus edition pages are pre-rendered. The old worker's item browser
(`/item/<slug>`, `/seller/<slug>`) has no static equivalent. Admin imports remain
a local build step and cannot be submitted to the static site.

The static build rewrites `wow.zamimg.com` image URLs to the mirrored files in
`static/assets/images` (served as `/assets/images/...`). Item icons use the local icon cache with
a local question-mark fallback. Static pages omit Wowhead's tooltip runtime,
which would otherwise fetch hover cards from Wowhead. Item links on the
WoWCensus pages can still navigate to Wowhead when clicked.
