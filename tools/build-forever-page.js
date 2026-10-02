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
const sourceGame = arg("source", "classic-beta");
const editions = {
  "classic-beta": { dir: "pages", label: "WoW Forever", nav: "Forever", flavor: "classic-beta", branch: "forever", title: "WoW Forever & Classic+ Population Census – WoWCensus", description: "WoWCensus - World of Warcraft: Forever and Classic+ population tracker. Realm population, faction balance, and race/class breakdowns from in-game /who scans.", comboBlurb: "Forever Beta is still evolving, so this snapshot is most useful for reading the current visible community rather than a settled long-term meta." },
  "classic-progression": { dir: "pages/tbc", label: "TBC Anniversary", nav: "TBC Anniversary", flavor: "tbc-anniversary", branch: "tbc", comboBlurb: "TBC Anniversary’s smaller era roster makes race and class choices a direct view of the currently visible progression community." },
  classic: { dir: "pages/classic", label: "Classic Era", nav: "Classic Era", flavor: "classic-era", branch: "classic", comboBlurb: "Classic Era keeps the original-era roster, so the mix reflects the characters currently active in its long-lived realms rather than modern class availability." },
  sod: { dir: "pages/sod", label: "Season of Discovery", nav: "SoD", flavor: "sod", branch: "classic", comboBlurb: "Season of Discovery class balance and player activity can shift sharply between phases, so treat the mix as a current activity signal rather than a durable meta ranking." },
  "mop-classic": { dir: "pages/mop", label: "Mists of Pandaria Classic", nav: "MoP Classic", flavor: "mop-classic", branch: "mop-classic", comboBlurb: "MoP Classic includes its era-specific roster and progression, so the mix captures who is visibly active during this phase rather than a prediction of endgame demand." },
  retail: { dir: "pages/retail", label: "Retail", nav: "Retail", flavor: "retail", branch: "", factionlessMarket: true, marketMetric: "quantity", comboBlurb: "Retail’s broad modern roster and cross-faction play make this a view of the current population mix." },
};
const edition = editions[sourceGame];
if (!edition) throw new Error("unsupported Pages source game: " + sourceGame);
const outDir = path.resolve(repo, arg("out", edition.dir));
const project = arg("project", "wowcensus");
const SOURCE_GAME = sourceGame;
const GAME_LABEL = edition.label;
const UPLOAD_FLAVOR = edition.flavor;
const WH_BRANCH = edition.branch;
const ITEM_NAME_CACHE = path.join(__dirname, "forever-item-names.json");
const SITE_ORIGIN = "https://wowcensus.io";

function editionPath(source) {
  const dir = editions[source].dir.replace(/^pages\/?/, "");
  return dir ? "/" + dir + "/" : "/";
}

function pagePath(source, page) {
  const root = editionPath(source);
  return page === "index" ? root : root + page;
}

function utcTimestamp(value) {
  if (!value) return "unavailable";
  const date = typeof value === "number" ? new Date(value * 1000) : new Date(value);
  return Number.isNaN(date.getTime()) ? "unavailable" : date.toISOString().slice(0, 16).replace("T", " ") + "Z";
}

async function getJson(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error(url + " -> HTTP " + res.status);
  return res.json();
}

function readItemNameCache() {
  try { return JSON.parse(fs.readFileSync(ITEM_NAME_CACHE, "utf8")); }
  catch (e) {
    if (e && e.code === "ENOENT") return {};
    throw e;
  }
}

// Forever includes beta-only ids that Blizzard's public item namespaces do
// not expose. Wowhead's Forever tooltip endpoint does know those ids (and the
// vanilla ids mixed into the same scans), so resolve only item:<id>
// placeholders here and retain the result for deterministic future builds.
async function resolvePlaceholderNames(items) {
  const cache = readItemNameCache();
  const missing = items.filter((it) => /^item:\d+$/.test(it.name) && !cache[it.id]);
  let resolved = 0;
  for (let i = 0; i < missing.length; i += 12) {
    await Promise.all(missing.slice(i, i + 12).map(async (it) => {
      const res = await fetch("https://nether.wowhead.com/forever/tooltip/item/" + it.id);
      if (!res.ok) return;
      const body = await res.json().catch(() => null);
      if (!body || !body.name || /^Item \d+$/.test(body.name)) return;
      cache[it.id] = body.name;
      resolved++;
    }));
  }
  if (resolved) {
    const ordered = Object.fromEntries(Object.entries(cache).sort((a, b) => Number(a[0]) - Number(b[0])));
    writeFile(ITEM_NAME_CACHE, JSON.stringify(ordered, null, 2) + "\n");
  }
  for (const it of items) {
    if (/^item:\d+$/.test(it.name) && cache[it.id]) it.name = cache[it.id];
  }
  return { resolved, unresolved: items.filter((it) => /^item:\d+$/.test(it.name)).length };
}

// The pages link to each other; the bundle has no Worker behind it, so the
// header carries no crumb back to one.
function nav(current) {
  const currentPage = current.replace(/\.html$/, "");
  const pageLabels = { index: "Census", combos: "Race + Class", auctionhouse: "Auction House", guilds: "Guilds", geography: "Geography", talents: "Talents" };
  const item = (href, label, active = href === currentPage) =>
    '<a class="game"' + (active ? ' aria-current="true"' : "") +
    ' href="' + href + '">' + label + "</a>";
  return {
    games: '<nav class="games game-nav">' +
      Object.entries(editions).map(([source, e]) => item(pagePath(source, "index"), e.nav, source === SOURCE_GAME)).join("") + "</nav>",
    pages: '<nav class="games page-nav">' +
    item(pagePath(SOURCE_GAME, "index"), "Census", currentPage === "index" || currentPage === "combos") +
      item(pagePath(SOURCE_GAME, "auctionhouse"), "Auction House", currentPage === "auctionhouse") +
      item(pagePath(SOURCE_GAME, "guilds"), "Guilds", currentPage === "guilds") +
      item(pagePath(SOURCE_GAME, "geography"), "Geography", currentPage === "geography") +
      item(pagePath(SOURCE_GAME, "talents"), "Talents", currentPage === "talents") + "</nav>",
    breadcrumbs: '<nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">WoWCensus</a><span aria-hidden="true">/</span><a href="' +
      pagePath(SOURCE_GAME, "index") + '">' + edition.nav + '</a><span aria-hidden="true">/</span><span aria-current="page">' +
      pageLabels[currentPage] + "</span></nav>",
  };
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

// Chip labels for realms. Every beta realm is called "Classic Beta <type>",
// and the client names one of them "Classic Beta PvP 2" -- the trailing number
// is the realm's own name, not an index we added. Both are noise in a chip, so
// drop the shared prefix and the trailing number, but only while the shortened
// labels stay distinct: a beta with both "PvP 1" and "PvP 2" keeps its numbers
// rather than showing two chips reading "PvP".
let realmName; // census.mjs's display-name helper, loaded in main()
function realmLabels(names) {
  const short = (n) => n.replace(/^Classic Beta\s+/i, "").trim() || n;
  const shorter = realmName;
  const pick = new Set(names.map(shorter)).size === names.length ? shorter : short;
  return new Map(names.map((n) => [n, pick(n)]));
}

// Roll realm/faction census units up into one group per faction -- the same
// shape the census renderer charts, mirroring the Worker's own merge.
function mergeCensusUnits(units) {
  const byFaction = new Map();
  for (const u of units) {
    if (!byFaction.has(u.faction))
      byFaction.set(u.faction, {
        faction: u.faction, races: {}, classes: {}, combos: {}, characters: 0, samples: 0, observed: 0, games: [],
      });
    const g = byFaction.get(u.faction);
    g.characters += u.characters;
    g.samples += u.samples;
    g.observed += u.observed;
    if (g.games.indexOf(u.game) < 0) g.games.push(u.game);
    for (const k in u.races) g.races[k] = (g.races[k] || 0) + u.races[k];
    for (const k in u.classes) g.classes[k] = (g.classes[k] || 0) + u.classes[k];
    for (const k in (u.combos || {})) g.combos[k] = (g.combos[k] || 0) + u.combos[k];
  }
  const order = ["Alliance", "Horde"];
  return [...byFaction.values()].sort((a, b) =>
    ((order.indexOf(a.faction) + 1) || 99) - ((order.indexOf(b.faction) + 1) || 99) || b.characters - a.characters);
}

// Merge every beta realm's item snapshot into one market view. Quantity and
// listed value add up; the unit price is then value/quantity, which is the
// quantity-weighted price across realms rather than an average of averages.
function mergeItems(perRealm) {
  const byId = new Map();
  for (const { items, columns } of perRealm) {
    const col = (name) => columns.indexOf(name);
    const [iId, iName, iMv, iAsp, iQ, iPq, iCat] =
      ["id", "name", "mv", "asp", "q", "pq", "cat"].map(col);
    for (const row of items) {
      const id = row[iId];
      const cur = byId.get(id) || {
        id, name: row[iName], cat: row[iCat], q: 0, pq: 0,
        hasPreviousQty: true, mv: 0, asp: 0,
      };
      cur.q += row[iQ] || 0;
      if (iPq < 0 || row[iPq] == null) cur.hasPreviousQty = false;
      else cur.pq += row[iPq] || 0;
      cur.mv += row[iMv] || 0;
      // Prefer a resolved name over an "item:<id>" placeholder from another realm.
      if (/^item:\d+$/.test(cur.name) && !/^item:\d+$/.test(row[iName])) cur.name = row[iName];
      if (!cur.cat) cur.cat = row[iCat];
      byId.set(id, cur);
    }
  }
  for (const it of byId.values()) {
    it.asp = it.q > 0 ? Math.round(it.mv / it.q) : 0;
    if (!it.hasPreviousQty) it.pq = null;
    delete it.hasPreviousQty;
  }
  return [...byId.values()];
}

async function main() {
  const src = (f) => pathToFileURL(path.join(repo, "site/src", f)).href;
  const censusMod = await import(src("census.mjs"));
  const { renderCensusHtml, renderComboBreakdownHtml } = censusMod;
  realmName = censusMod.realmName;
  const { renderTalentsHtml, renderInspectCoverage } = await import(src("talents.mjs"));
  const inspectData = await getJson(base + "/api/inspects?source=" + encodeURIComponent(SOURCE_GAME));
  const { renderMarketHtml } = await import(src("market.mjs"));
  const { renderGuildHtml } = await import(src("guilds.mjs"));
  const { renderGeographyHtml } = await import(src("geography.mjs"));

  const built = new Date();
  const generatedNote = "Page generated: " + utcTimestamp(built.toISOString());

  process.stdout.write("Census   ... ");
  const census = await getJson(base + "/api/census?source=" + encodeURIComponent(SOURCE_GAME));
  const chars = (census.groups || []).reduce((a, g) => a + g.characters, 0);
  console.log(chars.toLocaleString() + " characters, " + (census.groups || []).length + " faction(s)");

  process.stdout.write("Auctions ... ");
  const games = (await getJson(base + "/api/games")).games || [];
  const marketGames = games.filter((g) => g.sourceGame === SOURCE_GAME && g.realm && g.hasItems);
  const snaps = [];
  for (const g of marketGames) {
    const snap = await getJson(base + "/api/items?game=" + encodeURIComponent(g.game));
    const realm = g.game.replace(/^realm:/, "");
    snaps.push({
      realm,
      faction: (/-(Alliance|Horde)$/.exec(realm) || [, "Neutral"])[1],
      items: snap.items || [], columns: snap.columns, updatedAt: snap.updatedAt,
    });
  }
  // Both axes list "All" plus every value the beta has a realm dataset for --
  // including a combination with no AH scan yet, whose view then says so rather
  // than the toggle quietly hiding that side.
  const betaRealms = games.filter((g) => g.sourceGame === SOURCE_GAME && g.realm)
    .map((g) => g.game.replace(/^realm:/, ""));
  const factions = [...new Set((census.units || []).map((u) => u.faction).filter((f) => f && f !== "Unknown"))].sort();
  const realmNames = [...new Set(betaRealms.map((r) => r.replace(/-(Alliance|Horde)$/, "")))].sort();

  const snapshotOf = (from, fallbackRealms) => {
    // Retail's commodity auction house is regional and cross-realm. Multiple
    // realm uploads are repeated captures of the same market, not independent
    // inventories that can be added together. Use the newest capture for the
    // aggregate view; the realm chips still expose each capture separately.
    const selected = edition.factionlessMarket && from.length > 1
      ? [from.reduce((latest, snap) =>
          String(snap.updatedAt || "") > String(latest.updatedAt || "") ? snap : latest)]
      : from;
    return {
    items: mergeItems(selected),
    realms: selected.length ? selected.map((s2) => s2.realm) : fallbackRealms,
    updatedAt: selected.map((s2) => s2.updatedAt).filter(Boolean).sort().pop() || null,
    branch: WH_BRANCH, uploadFlavor: UPLOAD_FLAVOR,
    aggregateOnly: edition.marketMetric === "quantity",
    };
  };
  const marketRealmLabels = realmLabels(realmNames);
  const dims = {
    realm: [{ key: "all", label: edition.factionlessMarket ? "Latest regional scan" : "All realms" }]
      .concat(realmNames.map((r) => ({ key: r, label: marketRealmLabels.get(r) }))),
    faction: [{ key: "all", label: "Both" }].concat(factions.map((f) => ({ key: f, label: f }))),
  };
  // Retail auction houses are cross-faction. Population and guild census data
  // remain faction-specific, but the market must not imply an Alliance/Horde
  // split that Blizzard's Retail API does not provide.
  const marketDims = edition.factionlessMarket
    ? { realm: dims.realm, faction: [{ key: "all", label: "Cross-faction" }] }
    : dims;
  const views = [];
  for (const r of marketDims.realm) {
    for (const f of marketDims.faction) {
      const from = snaps.filter((s2) =>
        (r.key === "all" || s2.realm.replace(/-(Alliance|Horde)$/, "") === r.key) &&
        (f.key === "all" || s2.faction === f.key));
      const fallback = betaRealms.filter((name) =>
        (r.key === "all" || name.replace(/-(Alliance|Horde)$/, "") === r.key) &&
        (f.key === "all" || name.endsWith("-" + f.key)));
      views.push({ realm: r.key, faction: f.key, snapshot: snapshotOf(from, fallback) });
    }
  }

  const guildUnits = census.units || [];
  const guildViews = [];
  const geographyViews = [];
  for (const r of dims.realm) {
    for (const f of dims.faction) {
      const from = guildUnits.filter((u) =>
        (r.key === "all" || u.realm === r.key) &&
        (f.key === "all" || u.faction === f.key));
      const guilds = from.flatMap((u) => (u.guilds || []).map((g) => ({
        name: g.name, members: g.members, realm: u.realm, faction: u.faction,
      }))).sort((a, b) => b.members - a.members || a.name.localeCompare(b.name));
      guildViews.push({
        realm: r.key, faction: f.key,
        snapshot: {
          guilds,
          guildedCharacters: guilds.reduce((sum, g) => sum + g.members, 0),
          surveyedCharacters: from.reduce((sum, u) => sum + (u.characters || 0), 0),
          realms: [...new Set(from.map((u) => u.realm))],
          lastT: census.lastT || 0,
        },
      });
      const byZone = new Map();
      for (const u of from) for (const z of (u.zones || [])) {
        const cur = byZone.get(z.name) || { name: z.name, characters: 0, levelSum: 0, maxLevel: 0 };
        cur.characters += z.characters || 0;
        cur.levelSum += (z.avgLevel || 0) * (z.characters || 0);
        cur.maxLevel = Math.max(cur.maxLevel, z.maxLevel || 0);
        byZone.set(z.name, cur);
      }
      const zones = [...byZone.values()].map((z) => ({
        name: z.name, characters: z.characters,
        avgLevel: z.characters ? Math.round(z.levelSum / z.characters) : 0,
        maxLevel: z.maxLevel,
      })).sort((a, b) => b.characters - a.characters || a.name.localeCompare(b.name));
      geographyViews.push({ realm: r.key, faction: f.key, snapshot: {
        zones,
        characters: from.reduce((sum, u) => sum + (u.zoneCharacters || 0), 0),
        realms: [...new Set(from.map((u) => u.realm))], lastT: census.lastT || 0,
        windowDays: census.zoneWindowDays || 0,
      }});
    }
  }

  const uniqueItems = [...new Map(views.flatMap((v) => v.snapshot.items).map((it) => [it.id, it])).values()];
  const names = SOURCE_GAME === "classic-beta"
    ? await resolvePlaceholderNames(uniqueItems)
    : { resolved: 0, unresolved: uniqueItems.filter((it) => /^item:\d+$/.test(it.name)).length };
  // Apply a name learned from either faction to every view containing that id.
  const resolvedNames = new Map(uniqueItems.map((it) => [it.id, it.name]));
  for (const v of views) for (const it of v.snapshot.items) {
    if (/^item:\d+$/.test(it.name) && !/^item:\d+$/.test(resolvedNames.get(it.id) || ""))
      it.name = resolvedNames.get(it.id);
  }
  console.log(views[0].snapshot.items.length.toLocaleString() + " items across " +
    marketGames.length + " realm dataset(s); realms: " + (realmNames.join(", ") || "none") +
    "; factions: " + (factions.join(", ") || "none") +
    "; names resolved " + names.resolved + ", unresolved " + names.unresolved);

  fs.mkdirSync(outDir, { recursive: true });
  const css = fs.readFileSync(path.join(repo, "site/public/style.css"));
  const cssName = "style." + crypto.createHash("sha256").update(css).digest("hex").slice(0, 12) + ".css";
  for (const name of fs.readdirSync(outDir)) {
    if (/^style(?:\.[a-f0-9]{12})?\.css$/.test(name)) fs.rmSync(path.join(outDir, name));
  }
  // The census slices on realm only: each view still charts both factions.
  const censusUnits = census.units || [];
  const censusRealms = [...new Set(censusUnits.map((u) => u.realm))].sort();
  const censusView = (key, label, units) => ({
    key, label,
    census: { groups: mergeCensusUnits(units), realms: [...new Set(units.map((u) => u.realm))].sort(), lastT: census.lastT },
  });
  const censusRealmLabels = realmLabels(censusRealms);
  const censusViews = censusUnits.length
    ? [censusView("all", "All realms", censusUnits)].concat(
        censusRealms.map((r) =>
          censusView(r, censusRealmLabels.get(r), censusUnits.filter((u) => u.realm === r))))
    : [{ key: "all", label: "All realms", census }];
  writeFile(path.join(outDir, "index.html"), renderCensusHtml(censusViews, {
    stylesheet: cssName,
    nav: nav("index.html"),
    comboHref: pagePath(SOURCE_GAME, "combos"),
    inspectCoverage: renderInspectCoverage(inspectData, pagePath(SOURCE_GAME, "talents")),
    notes: ["Latest census observation: " + utcTimestamp(census.lastT)], generatedNote,
    gameLabel: GAME_LABEL, uploadFlavor: UPLOAD_FLAVOR,
    canonical: SITE_ORIGIN + pagePath(SOURCE_GAME, "index"),
    title: edition.title, description: edition.description,
  }));
  writeFile(path.join(outDir, "combos.html"), renderComboBreakdownHtml(censusViews[0].census, {
    stylesheet: cssName, nav: nav("combos.html"),
    notes: ["Latest census observation: " + utcTimestamp(census.lastT)], generatedNote,
    gameLabel: GAME_LABEL, flavorNote: edition.comboBlurb,
    canonical: SITE_ORIGIN + pagePath(SOURCE_GAME, "combos"),
  }));
  writeFile(path.join(outDir, "auctionhouse.html"),
    renderMarketHtml(views, marketDims, { stylesheet: cssName, nav: nav("auctionhouse.html"),
      notes: ["Latest auction scan: " + utcTimestamp(views[0] && views[0].snapshot.updatedAt)], generatedNote,
      gameLabel: GAME_LABEL,
      canonical: SITE_ORIGIN + pagePath(SOURCE_GAME, "auctionhouse") }));
  writeFile(path.join(outDir, "guilds.html"),
    renderGuildHtml(guildViews, dims, { stylesheet: cssName, nav: nav("guilds.html"),
      notes: ["Latest census observation: " + utcTimestamp(census.lastT)], generatedNote,
      gameLabel: GAME_LABEL,
      canonical: SITE_ORIGIN + pagePath(SOURCE_GAME, "guilds") }));
  writeFile(path.join(outDir, "geography.html"),
    renderGeographyHtml(geographyViews, dims, { stylesheet: cssName, nav: nav("geography.html"),
      notes: ["Latest location observation: " + utcTimestamp(census.lastT)], generatedNote,
      gameLabel: GAME_LABEL,
      canonical: SITE_ORIGIN + pagePath(SOURCE_GAME, "geography") }));
  writeFile(path.join(outDir, "talents.html"), renderTalentsHtml(inspectData, {
    stylesheet: cssName, nav: nav("talents.html"), gameLabel: GAME_LABEL, generatedNote,
    canonical: SITE_ORIGIN + pagePath(SOURCE_GAME, "talents")
  }));
  writeFile(path.join(outDir, "inspects.json"), JSON.stringify(inspectData, null, 2) + "\n");
  // The bundle carries its own stylesheet so it renders with nothing else served.
  writeFile(path.join(outDir, cssName), css);
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
}

main().catch((e) => { console.error(String((e && e.message) || e)); process.exit(1); });
