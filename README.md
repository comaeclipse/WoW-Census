# WoW-Census

WoW-Census is a data project for the World of Warcraft economy and player
population, spanning **Classic Era, Anniversary/TBC, and Retail**. It has two
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
  Analytics/  Snapshots, Trends, Region, Demand, Saturation, Scores
  Population/  WhoScan (/who sampling, storage, class→demand heuristic)
  Data/       Professions, Categories, curated Items, TSM region datasets
  UI/         MainFrame (drill-down engine), tables, Population, Tooltips, Minimap
  Utils/      Tables (stats), Money (copper formatting)
  Libs/       embedded LibStub + LibAHTab (native AH tab on modern clients)
site/         Cloudflare Worker, D1 schema, and static frontend
tools/        out-of-game scripts (TSM region fetch, realm/population upload)
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
| `/ml scan paged` | Classic/Anniversary: run a time-boxed seller sample (Get All omits seller names) |
| `/ml scan sellers full` | Classic/Anniversary: run an exhaustive paged seller scan for measurement |
| `/ml scan replicate` | Retail only: request a throttled, high-detail replicate scan |
| `/ml who` | Sample the observed population via `/who` (see below) |
| `/ml who <filter>` | Sample with a raw `/who` filter, e.g. `/ml who z-"Shattrath City"` |
| `/ml purge` | Drop snapshots / population samples older than the retention window |
| `/ml debug` | Toggle debug logging |
| `/ml reset` | Wipe the database |

1. Open the Auction House.
2. Run `/ml scan` and wait for "Scan complete".
3. Open `/ml` and drill down: **Profession → Market → Item**.
4. Hover an item row (or any item tooltip) for its MarketLens stats.

Scan a few times over hours/days — demand needs repeated observations.

On Classic/Anniversary, `/ml scan` uses Get All (one fast bulk dump), but that
dump omits seller names, so seller counts show as N/A. If Get All is on cooldown,
MarketLens waits instead of falling back to a huge page-by-page crawl. Use
`/ml scan paged` when you want seller data: it runs a time-boxed seller sample
(20 minutes by default), briefly re-reads loaded pages while names resolve, and
records seller profiles without letting an incomplete sample overwrite full AH
item totals. If the sample reaches the end of the AH before the time limit, it is
treated as a complete seller-aware item snapshot. Use `/ml scan sellers full` to
measure whether an exhaustive seller scan is practical on your realm; it uses the
same local owner-resolution behavior but has no time cap and reports owner
coverage plus projected full-scan timing as it runs.

On Retail, `/ml scan` uses the browse-summary API: minimum price and total
quantity are available, but individual auction and seller counts are not. The
optional `/ml scan replicate` path retains individual auction rows but is
globally throttled by Blizzard to roughly once every 15 minutes and may be
silently rejected during that window. It waits for the replicate result event
or for the Auction House to close; it does not invent a client-side timeout.

### Retail data workflow

Generate the Retail region reference once, and refresh it whenever you want a
new regional sale-rate snapshot:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\update-data.ps1 -Flavor retail
```

Then `/reload`, open the Retail Auction House, run `/ml scan`, and wait for the
summary scan to complete. Run `/reload` again to flush the export to disk. To
send that realm snapshot to the companion site:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\upload-realm.ps1 -Flavor retail
```

Use `-Flavor classic` for Anniversary/TBC, `-Flavor classic-era` for Classic
Era, or `-Flavor retail` for Retail.

The first scan immediately supplies market, minimum buyout, quantity,
saturation, regional demand, and local-vs-region analysis. Local supply and
price trends become meaningful after repeated scans over several hours or
days. Seller concentration, individual-auction turnover, and true price-depth
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

## Region demand data (TradeSkillMaster)

Local scans can't observe actual sales. To fix that, MarketLens can import
**TradeSkillMaster's public region data** (`saleRate`, `soldPerDay`,
`avgSalePrice`) — real sell-through data TSM publishes as free, key-less CSVs.

WoW addons can't fetch URLs, so a small out-of-game script does the download
(the same trick TSM's own desktop app uses):

```
tools/update-data.ps1
```

Right-click → Run with PowerShell (or `powershell -File tools\update-data.ps1`).
It writes `MarketLens/Data/TSMRegion.lua`; `/reload` in-game to pick it up.
Re-run it when you want fresh data (the region file updates ~daily). Options:
`-Region eu`, `-GameType classic`, `-Out <path>`.

When present, region data becomes the **authoritative demand signal** (tooltips
tag it `(region)` vs `(local)`), and MarketLens computes a **local-vs-region deal
signal** — items cheaper on your realm than they sell for region-wide. Data is
attributed to TSM in the UI. Without it, MarketLens falls back to local demand
inference. Source: <https://public-data.tradeskillmaster.com>.

## Observed population (`/who` sampling)

The **Population** tab samples who is actually online. Press **Scan Population**
(or `/ml who`) and MarketLens issues one `/who`, captures the returned roster,
and aggregates it into **Class**, **Race**, an inferred **profession demand**
ranking, and **unique/returning character** metrics. Click the crumb line to
cycle those four views.

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
Anniversary/TBC, and Retail. This supports unique-today/7-day/30-day, new,
returning, 3+-active-day, and returning-character-rate metrics.

The **inferred profession demand** ranking is a *heuristic*: it weights each
observed class toward the crafting markets that class buys from (plate → Blacksmithing,
cloth → Tailoring, plus universal Enchanting/Alchemy/gem demand). Like the rest
of MarketLens, it is inferred from what's observable — never claimed as real sales.

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
(Retail vs Classic/TBC) based on each dataset's recorded flavor.

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
