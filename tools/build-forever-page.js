#!/usr/bin/env node
// Builds the static wowcensus bundle in pages/ -- a population census and an
// auction-house overview for the Forever (Beta) realms -- and optionally
// publishes it to Cloudflare Pages.
//
//   node tools/build-forever-page.js                 build pages/ only
//   node tools/build-forever-page.js --deploy        build, then publish
//   node tools/build-forever-page.js --url=http://127.0.0.1:8799
//   node tools/build-forever-page.js --out=pages --project=wowcensus
//
// Data comes from the live Worker API (/api/forever, /api/games, /api/items)
// and is rendered with the same modules the Worker uses (site/src/census.mjs,
// site/src/market.mjs), so the static copy cannot drift from the live pages.
// The numbers freeze at build time: re-run this after each upload.

const fs = require("fs");
const path = require("path");
const { pathToFileURL } = require("url");
const { spawnSync } = require("child_process");

const args = process.argv.slice(2);
function arg(name, fallback) {
  const hit = args.find((a) => a.startsWith("--" + name + "="));
  return hit ? hit.slice(name.length + 3) : fallback;
}
const flag = (name) => args.includes("--" + name);

const base = (arg("url", "https://marketlens.skarz.workers.dev") || "").replace(/\/+$/, "");
const repo = path.resolve(__dirname, "..");
const outDir = path.resolve(repo, arg("out", "pages"));
const project = arg("project", "wowcensus");
// Every beta realm dataset shares this source_game; both pages filter on it.
const SOURCE_GAME = "classic-beta";
const WH_BRANCH = "forever";

async function getJson(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error(url + " -> HTTP " + res.status);
  return res.json();
}

// The two pages link to each other; the bundle has no Worker behind it, so the
// header carries no crumb back to one.
function nav(current) {
  const item = (href, label) =>
    '<a class="game"' + (href === current ? ' aria-current="true"' : "") +
    ' href="' + href + '">' + label + "</a>";
  return '<nav class="games" style="margin-bottom:18px">' +
    item("index.html", "Census") + item("auctionhouse.html", "Auction House") + "</nav>";
}

// Merge every beta realm's item snapshot into one market view. Quantity and
// listed value add up; the unit price is then value/quantity, which is the
// quantity-weighted price across realms rather than an average of averages.
function mergeItems(perRealm) {
  const byId = new Map();
  for (const { items, columns } of perRealm) {
    const col = (name) => columns.indexOf(name);
    const [iId, iName, iMv, iAsp, iQ, iCat] =
      ["id", "name", "mv", "asp", "q", "cat"].map(col);
    for (const row of items) {
      const id = row[iId];
      const cur = byId.get(id) || { id, name: row[iName], cat: row[iCat], q: 0, mv: 0, asp: 0 };
      cur.q += row[iQ] || 0;
      cur.mv += row[iMv] || 0;
      // Prefer a resolved name over an "item:<id>" placeholder from another realm.
      if (/^item:\d+$/.test(cur.name) && !/^item:\d+$/.test(row[iName])) cur.name = row[iName];
      if (!cur.cat) cur.cat = row[iCat];
      byId.set(id, cur);
    }
  }
  for (const it of byId.values()) it.asp = it.q > 0 ? Math.round(it.mv / it.q) : 0;
  return [...byId.values()];
}

async function main() {
  const src = (f) => pathToFileURL(path.join(repo, "site/src", f)).href;
  const { renderCensusHtml } = await import(src("census.mjs"));
  const { renderMarketHtml } = await import(src("market.mjs"));

  const built = new Date();
  const stamp = "Static snapshot built " + built.toISOString().slice(0, 16).replace("T", " ") + "Z";

  process.stdout.write("Census   ... ");
  const census = await getJson(base + "/api/forever");
  const chars = (census.groups || []).reduce((a, g) => a + g.characters, 0);
  console.log(chars.toLocaleString() + " characters, " + (census.groups || []).length + " faction(s)");

  process.stdout.write("Auctions ... ");
  const games = (await getJson(base + "/api/games")).games || [];
  const marketGames = games.filter((g) => g.sourceGame === SOURCE_GAME && g.hasItems);
  const perRealm = [];
  for (const g of marketGames) {
    const snap = await getJson(base + "/api/items?game=" + encodeURIComponent(g.game));
    perRealm.push({ items: snap.items || [], columns: snap.columns, updatedAt: snap.updatedAt });
  }
  const items = mergeItems(perRealm);
  const updatedAt = perRealm.map((p) => p.updatedAt).filter(Boolean).sort().pop() || null;
  console.log(items.length.toLocaleString() + " items across " + marketGames.length + " realm dataset(s)");

  fs.mkdirSync(outDir, { recursive: true });
  fs.writeFileSync(path.join(outDir, "index.html"), renderCensusHtml(census, {
    stylesheet: "style.css",
    nav: nav("index.html"),
    note: stamp,
  }));
  fs.writeFileSync(path.join(outDir, "auctionhouse.html"), renderMarketHtml({
    items,
    realms: marketGames.map((g) => g.game.replace(/^realm:/, "")),
    updatedAt,
    branch: WH_BRANCH,
  }, { stylesheet: "style.css", nav: nav("auctionhouse.html"), note: stamp }));
  // The bundle carries its own stylesheet so it renders with nothing else served.
  fs.copyFileSync(path.join(repo, "site/public/style.css"), path.join(outDir, "style.css"));
  fs.writeFileSync(path.join(outDir, "census.json"), JSON.stringify(census, null, 2));

  const rel = path.relative(repo, outDir).replace(/\\/g, "/");
  console.log("Wrote " + rel + "/{index.html,auctionhouse.html,style.css,census.json}");

  if (!flag("deploy")) {
    console.log("Publish it with:  node tools/build-forever-page.js --deploy");
    return;
  }
  console.log("\nDeploying to Cloudflare Pages project \"" + project + "\" ...");
  const r = spawnSync("npx", ["--yes", "wrangler@latest", "pages", "deploy", outDir,
    "--project-name", project, "--commit-dirty=true"], { stdio: "inherit", shell: true });
  if (r.status !== 0) throw new Error("wrangler pages deploy exited " + r.status);
}

main().catch((e) => { console.error(String((e && e.message) || e)); process.exit(1); });
