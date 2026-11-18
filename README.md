# WoW-Census

WoW-Census is a data project for the World of Warcraft economy and player
population, spanning **Classic Era, TBC Anniversary, MoP Classic, and Retail**.
Its collection responsibilities are split between two addons:

- **MarketLens** — an in-game addon that scans the Auction House, aggregates
  listings by item, stores compact historical snapshots, and scores each
  **profession → market → item** for **Demand**, **Saturation**, and a composite
  **Opportunity** — a "Bloomberg terminal" style view of the economy. MarketLens
  only collects Auction House data.
- **WoWCensus** — the population addon. It owns `/who` census collection and
  character observations.
- **The companion website** (wowcensus.io) — PHP pages (`laragon-php/`)
  rendered on request from PostgreSQL. Saves are imported into the local
  PostgreSQL database and, to publish, into the production server's own
  PostgreSQL database.

Everything is collected from the game's own APIs. WoW-Census is rigorous about
never claiming data it cannot observe — see *What it measures* below.

## Repository layout

```
MarketLens/   the in-game addon — drop this folder into Interface/AddOns
  MarketLens.toc, Core.lua
  AH/         Parser (one row), Scanner (paged scan + per-item aggregation)
  Analytics/  Snapshots, Trends, Demand, Saturation, Scores
  Data/       Professions, Categories, curated Items, item sources
  UI/         MainFrame (drill-down engine), market tables, Tooltips, Minimap
  Utils/      Tables (stats), Money (copper formatting)
  Libs/       embedded LibStub + LibAHTab (native AH tab on modern clients)
WoWCensus/    the census-only addon (see WoWCensus/README.md)
laragon-php/  the website: PHP renderer, skins, release packaging, server script copies
site/src/     browser scripts, templates and talent catalogs the PHP site reads
site/public/  stylesheet, fonts, and favicons the PHP site serves
tools/        SavedVariables import into PostgreSQL, icon and catalog updaters
assets/       mirrored site images (icons) the PHP site serves
archive/      early prototypes
```

## Documentation

| File | What it covers |
| --- | --- |
| `README.md` (this file) | The addon: install, commands, what it measures |
| `laragon-php/README.md` | The website: where it lives locally and in production, releases, caches, admin, shoutbox |
| `ingest.md` | Runbook: validate collected saves and import them into local PostgreSQL |
| `upload.md` | Runbook: publish data and code to wowcensus.io (kept local, not committed) |
| `servermap.md` | Edition names, internal keys, install folders, site paths and realms |
| `DESIGN.md` | Visual design rules for the site |
| `tools/CHARACTER_APPEARANCE.md` | Retail appearance sampling and its tables |

## Install (addon)

Copy the `MarketLens` folder from this repo into the client you use:

```
World of Warcraft\_classic_\Interface\AddOns\MarketLens\
World of Warcraft\_retail_\Interface\AddOns\MarketLens\
```

The folder must be named `MarketLens` and contain `MarketLens.toc` — WoW loads an
addon by matching the folder name to the `.toc` filename.

## Usage

| Command | Action |
| --- | --- |
| `/ml` | Open / close the dashboard |
| `/ml scan` | Run a full AH scan (must be at the Auction House) |
| `/ml scan paged` | Classic Era/TBC Anniversary: run a quick seller sample across the AH (Get All omits seller names) |
| `/ml scan paged fast [start] [stop]` | Classic Era/TBC Anniversary: walk all pages at the server's allowed pace, accepting missing seller names; 20-minute default budget. Optional 1-based page range restricts the walk, e.g. `/ml scan paged fast 400` starts at page 400, `/ml scan paged fast 400 800` stops after page 800 |
| `/ml scan sellers full` | Classic Era/TBC Anniversary: run an exhaustive paged seller scan for measurement |
| `/ml scan item <name>` | Classic Era/TBC Anniversary: query the AH for one item by name, print its sellers/prices to chat, and merge that listing into each seller's stored row |
| `/ml scan replicate` | Retail only: request a throttled, high-detail replicate scan |
| `/ml purge` | Drop Auction House snapshots older than the retention window |
| `/ml debug` | Toggle debug logging |
| `/ml reset` | Wipe the database |

1. Open the Auction House.
2. Run `/ml scan` and wait for "Scan complete".
3. Open `/ml` and drill down: **Profession → Market → Item**.
4. Hover an item row (or any item tooltip) for its MarketLens stats.

Scan a few times over hours/days — demand needs repeated observations.

On Classic Era/TBC Anniversary, `/ml scan` uses Get All (one fast bulk dump), but that
dump omits seller names, so seller counts show as N/A. If Get All is on cooldown,
MarketLens waits instead of falling back to a huge page-by-page crawl. Use
`/ml scan paged` when you want seller data: it samples 60 pages spread across the
AH by default, briefly re-reads loaded pages while names resolve, and records
seller profiles without letting an incomplete sample overwrite full AH item
totals. A 20-minute time limit remains as a hard guardrail, but the page cap
should normally finish much sooner. If the sample covers the whole AH, it is
treated as a complete seller-aware item snapshot. Use `/ml scan sellers full` to
measure whether an exhaustive seller scan is practical on your realm; it uses the
same local owner-resolution behavior but has no time cap and reports owner
coverage plus projected full-scan timing as it runs.

Use `/ml scan paged fast` for a full page walk without seller-resolution waits
or added query delays. It accepts nil seller names immediately and reports the
percentage of parsed auction rows with known owners. Missing names are not
retried, so seller counts remain observed counts, not a complete census. The
server's query throttle still applies, and completion in minutes is not
guaranteed. The default 20-minute budget stops the walk between pages; an
unfinished walk updates seller profiles without replacing full AH item totals.

On a realm too large to walk in one budget, pass a start/stop page (1-based,
matching the page numbers shown in scan progress) to sweep a different section
each run instead of always covering the same early pages and timing out at the
same spot, e.g. `/ml scan paged fast 800` to resume past where the last run
stopped, or `/ml scan paged fast 1 800` / `/ml scan paged fast 801 1600` to
split a realm into deliberate chunks across multiple sessions.

For a single item, `/ml scan item <name>` uses `QueryAuctionItems`' built-in
name search instead of walking pages, e.g. `/ml scan item Shadow Dust`.
Results (seller, quantity, price) print to chat, the item's own price history
gets recorded, and each matched seller's row for that item is merged into
their stored profile (new sellers are added; existing sellers just get that
one listing refreshed, everything else they sell is left alone). It never
repoints the realm's "latest seller scan" marker the way a full/fast scan
does -- doing that for a one-item query would make the next seller upload
think only that item's sellers were observed and drop everyone else -- so it's
safe to run any time as a top-up between full scans.

On Retail, `/ml scan` uses the browse-summary API: minimum price and total
quantity are available, but individual auction and seller counts are not. The
optional `/ml scan replicate` path retains individual auction rows but is
globally throttled by Blizzard to roughly once every 15 minutes and may be
silently rejected during that window. It waits for the replicate result event
or for the Auction House to close; it does not invent a client-side timeout.

### Retail data workflow

`/reload`, open the Retail Auction House, run `/ml scan`, and wait for the
summary scan to complete. Run `/reload` again to flush the export to disk. To update the companion site, follow `ingest.md` and `upload.md`: import the save into local PostgreSQL, check the pages on the local PHP site, then run the same import against the live database.

The first scan immediately supplies market, minimum buyout, quantity, and
saturation. Demand, local supply, and price trends become meaningful after
repeated scans over several hours or days. Seller concentration, individual-auction turnover, and true price-depth
statistics remain unavailable from a Retail summary scan and are shown as
unavailable rather than zero.

## What it measures (and what it can't)

Blizzard's Classic API exposes **listings**, not sales. MarketLens is rigorous about this:

- **Supply, Price, Competition, Supply-change** — directly observed.
- **Demand / velocity** — *inferred* from supply movement + price movement +
  listing turnover + seller contraction, across repeated snapshots.
- **Actual sales** — not knowable for other players' auctions; never claimed.

Until an item has `minimumSamples` (default 3) snapshots *and* a comparison window,
its Demand/Opportunity shows "collecting data".

## Population collection

Population and census collection now belongs exclusively to the standalone
`WoWCensus` addon. MarketLens does not issue `/who` requests, inspect players,
or write new population observations.

## Scores

- **Demand (0–100):** 35% supply contraction · 25% price strength · 20% listing
  turnover · 10% seller contraction · 10% persistence.
- **Saturation (0–100):** supply pressure (hours of inventory) · price decline ·
  supply growth · seller crowding. High = glutted.
- **Opportunity (0–100):** 35% demand · 25% scarcity · 15% price trend ·
  15% market size · 10% competition. Profession/market rollups are **value-weighted**
  so a 4-silver recipe can't outvote Flask of Relentless Assault.

Snapshots are stored compactly (`t,q,a,s,l,m,w,tc`) per item, capped by retention,
to keep `SavedVariables` small.

## Companion website

The website is the PHP site in `laragon-php/`. WoW saves (`MarketLens.lua` and
`NameplateInspect.lua`, or `WoWCensus.lua`) are imported into the local
PostgreSQL database, which is the source of truth; `http://127.0.0.1:8081/`
renders every page from it on request. wowcensus.io is a server running the
same PHP against its own PostgreSQL database, which the same importers write
to over an SSH tunnel. `ingest.md` and `upload.md` have the import and
publishing steps, and `laragon-php/README.md` says where everything lives
locally and on the server.

Inspection records retain verified same-realm context (otherwise unknown), observer
context, locale and client build. Existing schema v1 cache records remain usable; missing realm/faction is not
inferred from the observer. MarketLens copies contextual builds into its realm store.
The importer reads the companion SavedVariables file directly,
preserving legacy records too. It does not import the old `specSamples`
placeholder classifications.

The local build imports inspection caches without incrementing /who sightings.
`site/src/forever-talents.json` and the shared renderer classify talent builds
from the imported inspect records.

Forever spec IDs in the current cache identify classes, and the raw role field
reports DAMAGER for every player. Neither field is used to classify builds or roles.
The versioned catalog in `site/src/forever-talents.json` comes from Wowhead's Forever
node dataset; nodes must match class, spell and maximum rank before branch assignment.
TBC Anniversary reports its own tree names; `site/src/tbc-talents.json` (Wowhead's TBC
data, matched on talent ID, class and rank count) only supplies its icons.
After a patch reworks talents, refresh both with `node tools/update-talent-catalogs.mjs`
and rebuild; an "Unmapped talent" on the talent pages is the usual sign it is stale.
Tree-based builds retain ranked points for each mapped branch. A second or third
tree joins a named hybrid when it has at least 25% of allocated points and is
within 10 points of the leader. Retail and MoP Classic use class-validated
inspected specialization IDs instead. Insufficient or unmapped evidence is
shown as Unknown build with a reason and excluded from build shares; raw
inspection data remains available for later reclassification. Combat role
remains unknown.

The shared builder creates `talents.html` for every edition (raw inspect records
stay in the local database and are never published),
updates navigation/sitemap/discovery, and adds inspect coverage to census pages.
Talent popularity uses unique players with readable node data within each filtered
class; string-only builds are explicitly excluded. These nearby samples are not
population-wide spec shares. Run `node --test tools/test-inspects.mjs` for identity,
classification, validation, replay/history and HTML escaping checks.

### Forever Armory enrichment

Forever's public Battle.net profile is a separate logout snapshot, not a census
sighting. `tools/sync-forever-armory.py` queues already-known level-10+ Forever
names, reads the undocumented `profile-forever-{region}` endpoints, and stores
profiles, specialization/talent groups, gear, statistics, professions,
achievements, reputations, PvP, appearance and media in `forever_armory_*`
PostgreSQL tables. It never merges those rows into canonical census counts.

Set `BLIZZARD_CLIENT_ID` and `BLIZZARD_CLIENT_SECRET`, then run a bounded,
resumable refresh such as `python tools/sync-forever-armory.py --limit 500`.
Successful characters wait 24 hours by default; 404s wait seven days. Use
`--region us --name "First Surname"` for one character. Blizzard provides no
name search, so candidates come from WoWCensus and the API-verified ForeverPulse
table. Add `--include all guilds` to snapshot the discovered guild rosters and
queue their two-part character names. Guild expansion is deliberately not
population ingestion.

`--min-level 60` limits the run to characters whose verified or last fetched
level is at least 60 (run one `--region` at a time; without it the limit is
split evenly over us/eu/kr/tw). The default rate is 5 requests/second so a
ForeverPulse verify loop can share the same 36,000/hour credentials; a fully
fetched character costs 13 requests (14 for hunters). Titles, mounts and hunter
pets are stored too; raid encounters are skipped because Forever returns them
empty.

The site serves each stored character at `/forever/characters/<region>/<slug>/`
(origin only, from `laragon-php/src/ForeverCharacters.php`), and WoW: Forever
guild rosters link members that have one. Without the `forever_armory_*`
tables the route is a 404, so the live site needs those tables synced first.

Known names are selected directly from their indexed source tables in bounded
batches. `forever_armory_candidates` stores only actual attempt/backoff state,
explicit requests and guild discoveries; it does not duplicate the full census.

`forever_armory_current_builds` exposes the latest API-reported specialization,
spent points and normalized talents. Treat it as authoritative for that
character's last-logout Armory snapshot; nearby `character_inspects` remain the
authority for the build actually observed at their inspection time. Raw endpoint
JSON and endpoint-specific statuses remain in `forever_armory_snapshots` for
reprocessing when Blizzard changes this currently undocumented API.

Forever talent mappings must be checked after patches. Run
`node tools/update-talent-catalogs.mjs --check forever` for a read-only drift
check, or omit `--check` to refresh the catalog while retaining historical nodes.
