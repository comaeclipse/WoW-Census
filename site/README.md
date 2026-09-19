# MarketLens site (Cloudflare Workers + D1)

An 8-bit auction-house screener with per-item pages and historical price and
quantity graphs, built from realm scans the MarketLens addon uploads
(`POST /admin/import-realm`, see `tools/upload-realm.ps1`). The Worker stores the
latest snapshot per item plus a daily history row in D1 and serves the site.

```
site/
├── wrangler.toml      config: assets, D1 binding
├── schema.sql         items (latest) + history (daily time series)
├── src/worker.js      routing, JSON API, SSR item pages, realm import
└── public/            index.html · app.js · item.js · style.css
```

## Routes

| Route | What |
| --- | --- |
| `/` | arcade screener for a realm (reads `/api/items`); bare `/` redirects to the most recently uploaded realm |
| `/item/<slug>` or `/item/<id>` | item page + price/quantity history graph |
| `/api/items?game=realm:<name>` | latest snapshot for a realm (JSON, edge-cached 1h) |
| `/api/history?game=&id=` | daily time series for one item |
| `/admin/import-realm?token=&region=` | upload a realm's `/ml export` |

The screener's last column is the % change in listed quantity since the previous
scan (`items.pq` holds that scan's quantity).

## Deploy

```bash
cd site
npm i -g wrangler          # if needed
wrangler login

# 1. create the database, paste the printed id into wrangler.toml
wrangler d1 create marketlens

# 2. apply the schema (remote)
wrangler d1 execute marketlens --remote --file=schema.sql

# existing databases: apply new migration files as they appear
wrangler d1 execute marketlens --remote --file=migrate-seller-meta.sql

# 3. set the admin token used by the /admin/* endpoints
wrangler secret put REFRESH_TOKEN     # type any long random string

# 4. deploy
wrangler deploy
```

Then upload a realm scan with `tools/upload-realm.ps1` and open the Worker URL. Add a custom domain in the Cloudflare dashboard
(Workers → your worker → Domains & Routes) to get e.g. `marketlens.dev/item/fel-iron-ore`.

## History

Graphs come from the snapshots in each upload: the addon keeps up to ~14 days of
scans per item and every upload replays them, bucketed per UTC day (several scans
on one day collapse into that day's last one). The screener's "% vs last scan"
uses the item's previous snapshot itself, so same-day scans still count.

Your **own realm** history is available immediately: in game run `/ml export`,
copy, and paste it into the "overlay your realm history" box on any item page
(stored only in your browser).

## Notes / free-tier

- D1 free tier allows 100k row writes/day. Each realm upload rewrites that
  realm's `items` rows and replays its snapshot history, so very frequent
  uploads of large realms add up.
- Rows keyed by a bare flavor (`classic-progression`, `classic`, `retail`) are
  frozen leftovers from the retired region collector. They're read only to look
  up item names for items the addon didn't cache.
