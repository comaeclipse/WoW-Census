# MarketLens site (Cloudflare Workers + D1)

An 8-bit auction-house screener with per-item pages and historical price graphs,
powered by TradeSkillMaster's public region data. The Worker fetches the CSVs
server-side (they send no CORS header, so a browser can't), stores a daily
snapshot per item in D1, and serves the site.

```
site/
├── wrangler.toml      config: assets, D1 binding, daily cron, GAMES
├── schema.sql         items (latest) + history (daily time series)
├── src/worker.js      routing, JSON API, SSR item pages, cron collector
└── public/            index.html · app.js · item.js · style.css
```

## Routes

| Route | What |
| --- | --- |
| `/` | arcade screener (reads `/api/items`) |
| `/item/<slug>` or `/item/<id>` | item page + price-history graph |
| `/api/items?game=` | latest snapshot for a dataset (JSON, edge-cached 1h) |
| `/api/history?game=&id=` | daily time series for one item |
| `/admin/refresh?token=&game=` | manual collect (seed after deploy) |
| cron `0 10 * * *` | collect every dataset in `GAMES` |

Datasets (`GAMES` in wrangler.toml): `classic-progression`, `classic`, `retail` (region US).

## Deploy

```bash
cd site
npm i -g wrangler          # if needed
wrangler login

# 1. create the database, paste the printed id into wrangler.toml
wrangler d1 create marketlens

# 2. apply the schema (remote)
wrangler d1 execute marketlens --remote --file=schema.sql

# 3. set the admin token used by /admin/refresh
wrangler secret put REFRESH_TOKEN     # type any long random string

# 4. deploy
wrangler deploy

# 5. seed now (don't wait for the cron). One call per dataset:
curl "https://marketlens.<you>.workers.dev/admin/refresh?token=YOURTOKEN&game=classic-progression"
curl "https://marketlens.<you>.workers.dev/admin/refresh?token=YOURTOKEN&game=classic"
curl "https://marketlens.<you>.workers.dev/admin/refresh?token=YOURTOKEN&game=retail"
```

Then open the Worker URL. Add a custom domain in the Cloudflare dashboard
(Workers → your worker → Domains & Routes) to get e.g. `marketlens.dev/item/fel-iron-ore`.

## History

Graphs accrue **forward** — one snapshot per item per UTC day. There is no past
data to import (TSM's `historical` column is a single smoothed number, not a
series), so a fresh item shows one point until the collector has run a few days.

Your **own realm** history is available immediately: in game run `/ml export`,
copy, and paste it into the "overlay your realm history" box on any item page
(stored only in your browser).

## Notes / free-tier

- D1 free tier allows 100k row writes/day. One daily collection of all three
  datasets is well under that. If you add many datasets or collect more often,
  either drop some from `GAMES` or move the cron to per-game schedules.
- `retail` is the largest file (~2.4 MB). If a single cron run times out, give
  each dataset its own cron that calls `/admin/refresh?game=...`.
- Porting to other regions/versions is just more entries in `GAMES` + the
  `GAMES` list in `public/app.js` — the CSV schema is identical across them.
