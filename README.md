# WoW-Census

WoW-Census is a data project for the World of Warcraft economy and player
population, spanning **Classic Era, TBC Anniversary, and Retail**. It has two
halves:

- **MarketLens** — an in-game addon that scans the Auction House, aggregates
  listings by item, stores compact historical snapshots, and scores each
  **profession → market → item** for **Demand**, **Saturation**, and a composite
  **Opportunity** — a "Bloomberg terminal" style view of the economy. It also
  samples the online population via `/who` (class, race, an inferred profession
  demand ranking, and unique/returning-character metrics).
- **The companion website** (`site/`) — a Cloudflare Worker backed by a D1
  database. It ingests realm snapshots and population samples uploaded from the
  addon and renders per-item price/sale-rate history and realm population views.

Everything is collected from the game's own APIs. WoW-Census is rigorous about
never claiming data it cannot observe — see *What it measures* below.

## Repository layout

```
MarketLens/   the in-game addon — drop this folder into Interface/AddOns
  MarketLens.toc, Core.lua
  AH/         Parser (one row), Scanner (paged scan + per-item aggregation)
  Analytics/  Snapshots, Trends, Demand, Saturation, Scores
  Population/  WhoScan (/who sampling, storage, class→demand heuristic)
  Data/       Professions, Categories, curated Items, item sources
  UI/         MainFrame (drill-down engine), tables, Population, Tooltips, Minimap
  Utils/      Tables (stats), Money (copper formatting)
  Libs/       embedded LibStub + LibAHTab (native AH tab on modern clients)
site/         Cloudflare Worker, D1 schema, and static frontend
tools/        out-of-game scripts (realm/population upload, data builders)
pages/        generated static census bundle (drag-and-drop to Cloudflare Pages)
archive/      early prototypes
```

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
| `/ml who` | Sample the observed population via `/who` (see below) |
| `/ml who <filter>` | Sample with a raw `/who` filter, e.g. `/ml who z-"Shattrath City"` |
| `/ml spec` / `/ml spec status` | Show automatic Spec Census status and client support |
| `/ml spec on` / `off` | Enable/disable automatic, throttled sampling of inspectable nearby, mouseover, target, party, and raid unit tokens |
| `/ml plan` | Window listing this client + faction's planned `/ml who` queries; click a line (or Next) to put it in chat, results fill in, capped lines split underneath. `/ml plan new` starts a fresh pass |
| `/ml plan snapshot` / `full` / `auto` | Plan mode for this realm. **Snapshot**: coarse level bands plus cap-level class sampling; on Retail it uses levels 1-39, 40-59, 60-79, individual levels 80 through cap-1, then rotates two cap-level classes per day through race filters. Characters are deduplicated across the rolling window because Retail text filters can overlap. A capped line keeps its sample. **Auto** (default): snapshot, except a full count once an exact pass shows the realm fits in 40 queries. The window shows the realm's race/class lean over the last 14 days of census queries, the same window the site uses |
| `/ml census` | Start an adaptive census for this client + faction, or run its next query if one is active |
| `/ml census next` | Run the census's next `/who` (bind it to a key with a macro); never starts a new census |
| `/ml census passive on` / `off` | Let normal movement, turning, zooming, and world clicks advance an active census whenever its next query is eligible |
| `/ml census auto on` / `off` | Start a census at login; combine with passive mode for hands-off collection while playing |
| `/ml census start [budget]` / `stop` / `status` | Start (optional max query count, default 300), stop as partial, or print progress |
| `/ml census profile` | Show the detected scan profile, its level backbone, and learned splits |
| `/ml census cap <n>` / `auto` | Forever beta: pin the census level cap (default: the level the population has piled up at) |
| `/ml census chat on` / `off` | Chat mode: the census types each `/ml who <filter>` into chat for you to send with Enter. Turns on automatically if the client blocks the census's own `/who` (the Forever beta does) |
| `/ml census fixed on` / `off` | Fixed list: plan every query when the census starts and never add more; a query still over 50 is recorded as 50+ (and split up front next time). Default on the Forever beta |
| Forever beta / Retail census | Uses a fixed refresh list: levels 1-20, then each faction race, then each class; capped queries remain 50+ and never spawn extra work |
| `/ml census forget` | Drop learned splits so the next census starts from the plain backbone |
| `/ml sweep start [label]` | Start an explicit geographic collection sweep; subsequent `/ml who` results are attached to it |
| `/ml sweep status` | Show the active sweep's query, character, and capped-query counts |
| `/ml sweep complete` | Close the active sweep as complete after all intended queries finish |
| `/ml sweep partial` | Close an interrupted or incomplete sweep without presenting it as complete |
| `/ml purge` | Drop snapshots / population samples older than the retention window |
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
summary scan to complete. Run `/reload` again to flush the export to disk. To
send that realm snapshot to the companion site:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\upload-realm.ps1 -Flavor retail
```

Use `-Flavor tbc-anniversary` for TBC Anniversary, `-Flavor classic-era` for
Classic Era, or `-Flavor retail` for Retail. `-Flavor classic` is retained as a
legacy alias for TBC Anniversary uploads.

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

## Observed population (`/who` sampling)

The **Population** tab samples who is actually online. Press **Scan Population**
(or `/ml who`) and MarketLens issues one `/who`, captures the returned roster,
and aggregates it into **Class**, **Race**, an inferred **profession demand**
ranking, and **unique/returning character** metrics. Click the crumb line to
cycle those views, plus the **Census** view (below).

Blizzard's rules shape how this works, and MarketLens respects them:

- `SendWho` must come from a **hardware event** (a real button/key press) and is
  **rate-limited server-side** — so a scan only ever launches from the button or
  the slash command, never from a timer, with a short client cooldown on top.
- A `/who` returns a **sample** of currently-visible online players (the server
  caps it), **not a realm census**. Aggregate class/race tables count sightings;
  identity metrics deduplicate normalized character name + realm + game flavor.
- `/who` does not provide a character GUID or Battle.net account identity.
  MarketLens therefore reports **unique characters**, never unique people or
  accounts. Alts remain separate, and a rename appears as a new character.

Sample repeatedly — and with filters (`/ml who z-"Hellfire Peninsula"`,
`/ml who c-"Paladin" 60-70`, `/ml who r-"Blood Elf"`) — to build a picture over
time. `first_seen`, `last_seen`, lifetime sighting count, and a rolling 35-day
daily observation history accumulate independently for Classic Era,
TBC Anniversary, and Retail. This supports unique-today/7-day/30-day, new,
returning, 3+-active-day, and returning-character-rate metrics.

For comparable geography collection, wrap a deliberate set of `/who` queries in
an explicit sweep:

```text
/ml sweep start retail-evening
/ml who z-"Dornogal"
/ml who z-"Stormwind City"
...run the rest of the intended filters...
/ml sweep complete
```

Wait for each query's result before issuing the next. Use `/ml sweep partial`
when the intended query set was interrupted or could not be finished. A result
at or above the server cap is recorded as capped; closing a sweep as complete
does not conceal capped queries. Starting another sweep while one is active
automatically closes the older one as partial. Each sweep retains its query
metadata and one latest location observation per unique character within that
sweep, separately from the lifetime character's latest-known zone. Historical
population data from before this format remains valid but is not backfilled into
fabricated sweeps.

### Adaptive census

**Start Census** on the Population tab, or `/ml census`, turns the scan button
into **Run Next**. Each press sends exactly one `/who`. For passive collection,
use `/ml census passive on`; normal play inputs then carry eligible queries.
Use `/ml census auto on` as well to begin a census at each login. MarketLens decides which
one from the result of the last:

- It detects the scan profile from the client and your faction: Retail, WoW:
  Forever beta, Classic Era, Hardcore, Season of Discovery, TBC Anniversary, or
  MoP Classic, each × Alliance/Horde (`Population/CensusProfiles.lua`).
- It starts from a coarse, **disjoint** level backbone, highest levels first.
  Every online player matches exactly one backbone query.
- A capped query is split into parts that cover it exactly. A level range splits
  into narrower ranges, weighted by recent sightings. A range too dense per level
  splits by class. A single level splits by class, then by race.
- A cell still capped with level, class and race all pinned tries up to 12
  high-yield name letters, selected from recent names to maximize new discovery,
  then its hottest zones. Name and zone queries overlap, so these refinements
  find otherwise-hidden characters but remain **unresolved** coverage: the
  census is a lower bound and its sweep closes as partial.
- Every split is remembered per realm bucket for 14 days. The next census skips
  straight to the parts instead of spending a press on a query it knows will cap.
  Each run also tightens those splits from real counts. Small neighbouring level
  ranges merge, and a class split whose levels turn out to fit under the cap on
  their own becomes a level split.

A census runs inside its own sweep, so the site gets the same geography and
query data as a manual sweep. Capped queries that were fully split are marked
`split` and don't count toward the sweep's capped (coverage-gap) total. State
survives `/reload`. If a `/who` never answers, the next press retries it.

The **inferred profession demand** ranking is a *heuristic*: it weights each
observed class toward the crafting markets that class buys from (plate → Blacksmithing,
cloth → Tailoring, plus universal Enchanting/Alchemy/gem demand). Like the rest
of MarketLens, it is inferred from what's observable — never claimed as real sales.
The armor-type profession's share of that weight (vs. the generic "Gear" bucket,
meaning drops/quests/vendor gear no profession supplies) isn't guessed: it's
generated from Blizzard's own item data (`tools/build-item-sources.js` →
`Data/GearCoverageGen.lua`) as the real fraction of that armor type which is a
known player recipe — typically only 4-7%, so "Gear" dominates most realms'
demand ranking, and that's expected, not a bug.

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

## Companion website (`site/`)

The `site/` folder is a Cloudflare Worker plus a D1 schema (`site/schema.sql`) and
a static frontend. It receives realm snapshots and `/who` population samples
uploaded by `tools/upload-realm.ps1`, stores latest-value and day-bucketed
history rows per item, and serves per-item price/sale-rate history and realm
population/census views. Item links and icons follow the correct Wowhead branch
(Retail vs Classic Era/TBC Anniversary) based on each dataset's recorded flavor.

`/wowforever` is a cross-faction census for the Forever (Beta) realms: one race
chart grouped by faction and a class chart per faction, counting **unique
characters** rather than sightings so the faction that happened to get more
`/who` scans doesn't gain share from the extra scans alone.

### Static bundle (`pages/` -> wowcensus.io)

The beta views also ship as a standalone bundle with no Worker and no database
behind it, published at **https://wowcensus.io**:

```bash
node tools/build-forever-page.js            # build pages/ only
node tools/build-forever-page.js --deploy   # build, then publish to Pages
```

It reads the live data from `/api/forever`, `/api/games`, and `/api/items`, and
renders it with the same modules the Worker uses (`site/src/census.mjs` and
`site/src/market.mjs`, so the copies can't drift), writing:

| File | Page |
| --- | --- |
| `pages/index.html` | the cross-faction census |
| `pages/auctionhouse.html` | `/auctionhouse` — biggest markets, most listed items, most expensive listings |
| `pages/style.<hash>.css`, `pages/census.json` | immutable fingerprinted stylesheet and raw census numbers |
| `pages/robots.txt`, `pages/sitemap.xml` | crawler policy and clean canonical route discovery |
| `pages/_headers` | Pages caching rules for HTML, JSON, and fingerprinted assets |

`--deploy` runs `wrangler pages deploy` against the `wowcensus` project; without
it the folder can still be dragged onto **Cloudflare dashboard -> Workers &
Pages -> Create -> Pages -> Upload assets**. The numbers are frozen at build
time -- re-run after each upload to refresh them. `--url=` builds against a
different origin (a local `wrangler dev`, say), `--out=` writes elsewhere, and
`--project=` targets another Pages project.

## Status: v0.1 (MVP)

Implemented: full scan, aggregation, snapshots, demand/saturation/opportunity,
drill-down dashboard and tooltip integration, observed-population (`/who`)
sampling with inferred profession demand, and the companion upload/site pipeline.

**Not yet (v0.2):** crafting profitability (recipe cost vs. AH value), persisted
price-depth ladders, multi-window trend badges (1h/6h/24h/7d), day-of-week seasonality.

## Notes / tuning

- `## Interface: 20506` targets TBC 2.5.6. If the client refuses to load the addon,
  update this to your exact build (the client shows it under AddOns → "out of date").
- The class/subclass fallback in `MarketLens/Data/Categories.lua` uses TBC 2.5.x
  subclass numbering. If an item looks misfiled, add a curated override in
  `MarketLens/Data/Items.lua` or adjust the mapping there.
- Curated item IDs in `MarketLens/Data/Items.lua` cover the highest-value markets;
  the fallback classifier handles everything else automatically.
</content>
</invoke>


### Nearby inspected talent builds

`NameplateInspect/` is the companion addon source. Install it beside MarketLens;
MarketLens declares it as an optional dependency and consumes its cache without
issuing competing inspect requests. The installed beta companion is also updated
when developing against the local WoW installation. Reload WoW after addon edits.
`/npi nearby` reports the current visible nameplates, unique player nameplates,
players within inspect distance, and those WoW currently allows inspecting.
`/npi diag` reports inspect requests and responses since the last reload.

The companion's schema v2 keeps raw names, Forever surnames, player faction,
verified same-realm context (otherwise unknown), observer context, locale and client
build. Existing schema v1 cache records remain usable; missing realm/faction is not
inferred from the observer. MarketLens copies contextual builds into its realm store.
The uploader reads the companion SavedVariables file directly, preserving legacy
records too. It does not import the old `specSamples` placeholder classifications.

`tools/upload-realm.ps1 -Flavor classic-beta -Realm "Classic Beta PvE-Horde" -PopulationOnly`
also imports the companion cache. This separate `ml-inspects-v1` upload never
increments /who sightings. Reuploads are idempotent. Successful uploaded snapshots
retain build history in D1; the addon itself stores the latest build per GUID.

For a new database, apply `site/migrate-inspects.sql`, then
`site/seed-forever-talents.sql` with Wrangler before deploying the Worker.
`site/schema.sql` also includes the new tables. `/admin/import-inspects` uses the
existing refresh token; `/api/inspects?source=classic-beta` exposes the latest build
per GUID within 14 days of the newest collected inspection. Inspect timestamps,
census timestamps and page generation times are separate.

Forever spec IDs in the current cache identify classes, and the raw role field
reports DAMAGER for every player. Neither field is used to classify builds or roles.
The versioned catalog in `site/src/forever-talents.json` comes from Wowhead's Forever
node dataset; nodes must match class, spell and maximum rank before branch assignment.
Tree-based builds retain ranked points for each mapped branch. A second or third
tree joins a named hybrid when it has at least 25% of allocated points and is
within 10 points of the leader. Retail and MoP Classic use class-validated
inspected specialization IDs instead. Insufficient or unmapped evidence is
shown as Unknown build with a reason and excluded from build shares; raw
inspection data remains available for later reclassification. Combat role
remains unknown.

The shared builder creates `talents.html` and `inspects.json` for every edition,
updates navigation/sitemap/discovery, and adds inspect coverage to census pages.
Talent popularity uses unique players with readable node data within each filtered
class; string-only builds are explicitly excluded. These nearby samples are not
population-wide spec shares. Run `node --test tools/test-inspects.mjs` for identity,
classification, validation, replay/history and HTML escaping checks. Rebuild all six
editions and deploy the complete `pages/` tree.
