#!/usr/bin/env node
// Builds the static wowcensus bundle in pages/ -- a population census and an
// auction-house and guild overviews for the Forever (Beta) realms -- and optionally
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
const crypto = require("crypto");
const { pathToFileURL } = require("url");
const { spawnSync } = require("child_process");

// Editors and antivirus scanners can briefly hold generated files on Windows.
// Write beside the destination, then replace atomically with bounded retries.
function writeFile(file, data) {
  const temporary = file + "." + crypto.randomBytes(6).toString("hex") + ".tmp";
  try {
    fs.writeFileSync(temporary, data);
    for (let attempt = 0; ; attempt++) {
      try { fs.renameSync(temporary, file); return; }
      catch (e) {
        if (attempt >= 19 || !["UNKNOWN", "EPERM", "EBUSY", "EACCES"].includes(e.code)) throw e;
        Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 50);
      }
    }
  } finally { if (fs.existsSync(temporary)) fs.rmSync(temporary); }
}

const args = process.argv.slice(2);
function arg(name, fallback) {
  const hit = args.find((a) => a.startsWith("--" + name + "="));
  return hit ? hit.slice(name.length + 3) : fallback;
}
const flag = (name) => args.includes("--" + name);

const base = (arg("url", "https://marketlens.skarz.workers.dev") || "").replace(/\/+$/, "");
const repo = path.resolve(__dirname, "..");
const SOURCE_GAME = arg("source", "classic-beta");
const project = arg("project", "wowcensus");

// Edition config, data shaping and page rendering live in site/src/edition.mjs,
// shared with the edge renderer in pages/_worker.js. Loaded in main().
let editions, editionPath, pagePath, SITE_ORIGIN;

async function getJson(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error(url + " -> HTTP " + res.status);
  return res.json();
}

// One cache per Wowhead branch (EDITIONS[].branch): the same id can name a
// different item -- or none -- on different branches, and SoD shares Era's.
const itemNameCache = (branch) => path.join(__dirname, "item-names", (branch || "retail") + ".json");
const isPlaceholder = (name) => /^item:\d+$/.test(name);

function readItemNameCache(branch) {
  try { return JSON.parse(fs.readFileSync(itemNameCache(branch), "utf8")); }
  catch (e) {
    if (e && e.code === "ENOENT") return {};
    throw e;
  }
}

// Blizzard's item API misses some ids the scans contain: Forever's beta-only
// items, and retail ids its static namespace has never exposed. Wowhead's
// tooltip endpoint knows them, so resolve only item:<id> placeholders here
// and retain the result for deterministic future builds.
async function resolvePlaceholderNames(branch, items) {
  const cache = readItemNameCache(branch);
  const prefix = branch ? branch + "/" : "";
  const missing = items.filter((it) => isPlaceholder(it.name) && !cache[it.id]);
  let resolved = 0;
  for (let i = 0; i < missing.length; i += 12) {
    await Promise.all(missing.slice(i, i + 12).map(async (it) => {
      const res = await fetch("https://nether.wowhead.com/" + prefix + "tooltip/item/" + it.id).catch(() => null);
      if (!res || !res.ok) return;
      const body = await res.json().catch(() => null);
      if (!body || !body.name || /^Item \d+$/.test(body.name)) return;
      cache[it.id] = body.name;
      resolved++;
    }));
  }
  if (resolved) {
    const ordered = Object.fromEntries(Object.entries(cache).sort((a, b) => Number(a[0]) - Number(b[0])));
    fs.mkdirSync(path.dirname(itemNameCache(branch)), { recursive: true });
    writeFile(itemNameCache(branch), JSON.stringify(ordered, null, 2) + "\n");
  }
  for (const it of items) {
    if (isPlaceholder(it.name) && cache[it.id]) it.name = cache[it.id];
  }
  return { resolved, unresolved: items.filter((it) => isPlaceholder(it.name)).length };
}

// Name the auction-house items still showing "item:<id>" placeholders.
async function resolveModelNames(source, models) {
  const { EDITIONS } = await import(pathToFileURL(path.join(repo, "site/src/edition.mjs")).href);
  const views = models.auctionhouse.views;
  const uniqueItems = [...new Map(views.flatMap((v) => v.snapshot.items).map((it) => [it.id, it])).values()];
  const names = await resolvePlaceholderNames(EDITIONS[source].branch, uniqueItems);
  // Apply a name learned from either faction to every view containing that id.
  const resolvedNames = new Map(uniqueItems.map((it) => [it.id, it.name]));
  for (const v of views) for (const it of v.snapshot.items) {
    if (isPlaceholder(it.name) && !isPlaceholder(resolvedNames.get(it.id) || ""))
      it.name = resolvedNames.get(it.id);
  }
  return names;
}

function writeCrawlAndCacheFiles() {
  const pagesRoot = path.join(repo, "pages");
  const routes = Object.keys(editions).flatMap((source) =>
    ["index", "combos", "auctionhouse", "guilds", "geography", "talents"].map((page) => pagePath(source, page)));
  const urls = routes.map((route) => SITE_ORIGIN + route);
  const sitemap = '<?xml version="1.0" encoding="UTF-8"?>\n' +
    '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' +
    urls.map((url) => "  <url><loc>" + url + "</loc></url>").join("\n") +
    "\n</urlset>\n";
  writeFile(path.join(pagesRoot, "robots.txt"),
    "User-agent: *\nContent-Signal: ai-train=no, search=yes, ai-input=no\nAllow: /\n\nSitemap: " + SITE_ORIGIN + "/sitemap.xml\n");
  writeFile(path.join(pagesRoot, "sitemap.xml"), sitemap);
  writeAgentDiscoveryFiles(pagesRoot);
  const htmlHeaders = routes.map((route) => route +
    "\n  Cache-Control: public, max-age=300, stale-while-revalidate=86400").join("\n\n");
  writeFile(path.join(pagesRoot, "_headers"), htmlHeaders + `

/*.css
  Cache-Control: public, max-age=31536000, immutable

/fonts/*
  Cache-Control: public, max-age=31536000, immutable

/favicon*
  Cache-Control: public, max-age=86400

/*.json
  Cache-Control: public, max-age=300, stale-while-revalidate=3600

/robots.txt
  Cache-Control: public, max-age=86400

/llms.txt
  Cache-Control: public, max-age=3600

/sitemap.xml
  Cache-Control: public, max-age=3600
`);
}

// llms.txt and /.well-known/ai-catalog.json for agent crawlers, plus a real
// 404 page: without a top-level 404.html, Pages answers every unknown path
// with index.html and a 200, so agents probing for these files got HTML.
function writeAgentDiscoveryFiles(pagesRoot) {
  const sections = [
    ["index", "Census", "sampled population, faction balance, race and class mix"],
    ["combos", "Race + class", "the complete observed race and class combination breakdown"],
    ["auctionhouse", "Auction house", "item supply, asking prices and listed value from recent scans"],
    ["guilds", "Guilds", "sampled guild activity by realm and faction"],
    ["geography", "Geography", "player activity by zone from latest-known character locations"],
    ["talents", "Talents", "nearby inspected talent builds, inferred talent trees and selected talent popularity"],
  ];
  const withCensus = Object.keys(editions).filter((source) =>
    fs.existsSync(path.join(repo, editions[source].dir, "census.json")));
  const llms = [
    "# wowcensus",
    "",
    "> Static World of Warcraft population census, auction-house, guild and zone",
    "> pages, sampled in-game by the MarketLens addon (/who scans and AH scans).",
    "> Numbers are frozen at build time and republished after each upload.",
    "",
    "A /who sample shows currently-visible online players (server-capped), not a",
    "full census. Auction quantities are listings, not sales. Money is in copper",
    "(10000 copper = 1 gold).",
    "",
    ...Object.keys(editions).flatMap((source) => [
      "## " + editions[source].label,
      "",
      ...sections.map(([page, label, blurb]) =>
        "- [" + label + "](" + SITE_ORIGIN + pagePath(source, page) + "): " + blurb),
      ...(withCensus.includes(source)
        ? ["- [census.json](" + SITE_ORIGIN + editionPath(source) + "census.json): the census data behind the page, as JSON"]
        : []),
      "",
    ]),
    "## Live API",
    "",
    "- [MarketLens llms.txt](https://marketlens.skarz.workers.dev/llms.txt): the live JSON API these pages are built from",
    "",
  ].join("\n");
  writeFile(path.join(pagesRoot, "llms.txt"), llms);

  const catalog = {
    specVersion: "1.0",
    host: { displayName: "wowcensus", documentationUrl: SITE_ORIGIN + "/llms.txt" },
    entries: [
      {
        identifier: "urn:air:wowcensus.io:docs:llms-txt",
        displayName: "wowcensus site guide",
        type: "text/markdown",
        url: SITE_ORIGIN + "/llms.txt",
        description: "Index of every census, race-and-class, auction-house, guild and geography page on this site.",
      },
      ...withCensus.map((source) => ({
        identifier: "urn:air:wowcensus.io:census:" + (editions[source].dir.replace(/^pages\/?/, "") || "forever"),
        displayName: editions[source].label + " census data",
        type: "application/json",
        url: SITE_ORIGIN + editionPath(source) + "census.json",
        description: editions[source].label + " population by realm and faction: race and class counts from sampled in-game /who results.",
        tags: ["world-of-warcraft", "census", "population"],
      })),
    ],
  };
  fs.mkdirSync(path.join(pagesRoot, ".well-known"), { recursive: true });
  writeFile(path.join(pagesRoot, ".well-known", "ai-catalog.json"), JSON.stringify(catalog, null, 2) + "\n");

  writeFile(path.join(pagesRoot, "404.html"),
    '<!doctype html><html lang="en"><head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<title>Not found</title><meta name="robots" content="noindex"></head>' +
    '<body><h1>Not found</h1><p><a href="/">wowcensus home</a></p></body></html>\n');
}

async function main() {
  const shared = await import(pathToFileURL(path.join(repo, "site/src/edition.mjs")).href);
  ({ EDITIONS: editions, editionPath, pagePath, SITE_ORIGIN } = shared);
  const edition = editions[SOURCE_GAME];
  if (!edition) throw new Error("unsupported Pages source game: " + SOURCE_GAME);
  const outDir = path.resolve(repo, arg("out", edition.dir));

  const built = new Date();
  const data = await shared.fetchEditionData(SOURCE_GAME, (p) => getJson(base + p));
  const { census, games, snaps } = data;
  const chars = (census.groups || []).reduce((a, g) => a + g.characters, 0);
  console.log("Census   ... " + chars.toLocaleString() + " characters, " + (census.groups || []).length + " faction(s)");
  const models = shared.buildEditionModels(SOURCE_GAME, data);

  const views = models.auctionhouse.views;
  const names = await resolveModelNames(SOURCE_GAME, models);
  const realmNames = models.guilds.dims.realm.slice(1).map((r) => r.key);
  const factions = models.guilds.dims.faction.slice(1).map((f) => f.key);
  console.log("Auctions ... " + views[0].snapshot.items.length.toLocaleString() + " items across " +
    snaps.length + " realm dataset(s); realms: " + (realmNames.join(", ") || "none") +
    "; factions: " + (factions.join(", ") || "none") +
    "; names resolved " + names.resolved + ", unresolved " + names.unresolved);

  fs.mkdirSync(outDir, { recursive: true });
  const css = fs.readFileSync(path.join(repo, "site/public/style.css"));
  const cssName = "style." + crypto.createHash("sha256").update(css).digest("hex").slice(0, 12) + ".css";
  for (const name of fs.readdirSync(outDir)) {
    if (/^style(?:\.[a-f0-9]{12})?\.css$/.test(name)) fs.rmSync(path.join(outDir, name));
  }
  for (const page of shared.PAGES) {
    writeFile(path.join(outDir, page + ".html"),
      shared.renderEditionPage(SOURCE_GAME, page, models[page], { stylesheet: cssName, generatedAt: built.toISOString() }));
  }
  writeFile(path.join(outDir, "inspects.json"), JSON.stringify(data.inspects, null, 2) + "\n");
  // The bundle carries its own stylesheet so it renders with nothing else served.
  writeFile(path.join(outDir, cssName), css);
  // The deployed layout identity, which gates pages published to KV.
  require("./layout-id").writeLayout();
  // Font URLs are root-relative so every edition shares one immutable copy.
  const sourceFonts = path.join(repo, "site/public/fonts");
  const outputFonts = path.join(repo, "pages/fonts");
  fs.mkdirSync(outputFonts, { recursive: true });
  for (const name of fs.readdirSync(sourceFonts)) {
    fs.copyFileSync(path.join(sourceFonts, name), path.join(outputFonts, name));
  }
  fs.copyFileSync(path.join(repo, "site/public/favicon.ico"), path.join(repo, "pages/favicon.ico"));
  fs.copyFileSync(path.join(repo, "site/public/favicon-96.png"), path.join(repo, "pages/favicon-96.png"));
  // Keep the existing public census artifact focused on census data; guilds
  // are rendered into guilds.html and do not need to duplicate thousands of
  // rows in this JSON file.
  const censusJson = {
    ...census,
    units: (census.units || []).map(({ guilds, zones, ...unit }) => unit),
  };
  writeFile(path.join(outDir, "census.json"), JSON.stringify(censusJson, null, 2));
  writeCrawlAndCacheFiles();
  // Rebuild Markdown for every edition because Pages is always deployed as one tree.
  const markdown = spawnSync(process.execPath, [path.join(__dirname, "build-markdown-pages.js")], { stdio: "inherit" });
  if (markdown.status !== 0) throw new Error("Markdown page build failed");

  const rel = path.relative(repo, outDir).replace(/\\/g, "/");
  console.log("Wrote " + rel + "/{index.html,combos.html,auctionhouse.html,guilds.html,geography.html,talents.html," + cssName + ",census.json,inspects.json}");

  if (!flag("deploy")) {
    console.log("Publish it with:  node tools/build-forever-page.js --deploy");
    return;
  }
  console.log("\nDeploying to Cloudflare Pages project \"" + project + "\" ...");
  // Publish the complete tree. Deploying only an edition subdirectory makes it
  // the project root and leaves canonical paths such as /retail/* stale.
  const deployDir = path.join(repo, "pages");
  const r = spawnSync("npx", ["--yes", "wrangler@latest", "pages", "deploy", deployDir,
    "--project-name", project, "--commit-dirty=true"], { stdio: "inherit", shell: true });
  if (r.status !== 0) throw new Error("wrangler pages deploy exited " + r.status);
  // A deploy changes the layout KV pages must match; republish every edition
  // so they serve fresh data again instead of falling back to this bundle.
  console.log("\nPublishing every edition to KV ...");
  const p = spawnSync(process.execPath, [path.join(__dirname, "publish-pages.js"), "--url=" + base],
    { stdio: "inherit" });
  if (p.status !== 0) throw new Error("publish-pages exited " + p.status);
}

module.exports = { resolveModelNames };

if (require.main === module)
  main().catch((e) => { console.error(String((e && e.message) || e)); process.exit(1); });
