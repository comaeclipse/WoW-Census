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
const sourceGame = arg("source", "classic-beta");
const isTbc = sourceGame === "classic-progression";
const outDir = path.resolve(repo, arg("out", isTbc ? "pages/tbc" : "pages"));
const project = arg("project", "wowcensus");
const SOURCE_GAME = sourceGame;
const GAME_LABEL = isTbc ? "TBC Anniversary" : "WoW Forever";
const SCOPE_LABEL = isTbc ? "Anniversary realms" : "Beta realms";
const UPLOAD_FLAVOR = isTbc ? "tbc-anniversary" : "classic-beta";
const WH_BRANCH = isTbc ? "tbc" : "forever";
const ITEM_NAME_CACHE = path.join(__dirname, "forever-item-names.json");

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
    fs.writeFileSync(ITEM_NAME_CACHE, JSON.stringify(ordered, null, 2) + "\n");
  }
  for (const it of items) {
    if (/^item:\d+$/.test(it.name) && cache[it.id]) it.name = cache[it.id];
  }
  return { resolved, unresolved: items.filter((it) => /^item:\d+$/.test(it.name)).length };
}

// The pages link to each other; the bundle has no Worker behind it, so the
// header carries no crumb back to one.
function nav(current) {
  const item = (href, label) =>
    '<a class="game"' + (href === current ? ' aria-current="true"' : "") +
    ' href="' + href + '">' + label + "</a>";
  const foreverHref = isTbc ? "../index.html" : "index.html";
  const tbcHref = isTbc ? "index.html" : "tbc/index.html";
  return '<nav class="games" style="margin-bottom:10px">' +
    item(foreverHref, "Forever") + item(tbcHref, "TBC Anniversary") + "</nav>" +
    '<nav class="games" style="margin-bottom:18px">' +
    item("index.html", "Census") + item("auctionhouse.html", "Auction House") +
    item("guilds.html", "Guilds") + "</nav>";
}

// Chip labels for realms. Every beta realm is called "Classic Beta <type>",
// and the client names one of them "Classic Beta PvP 2" -- the trailing number
// is the realm's own name, not an index we added. Both are noise in a chip, so
// drop the shared prefix and the trailing number, but only while the shortened
// labels stay distinct: a beta with both "PvP 1" and "PvP 2" keeps its numbers
// rather than showing two chips reading "PvP".
function realmLabels(names) {
  const short = (n) => n.replace(/^Classic Beta\s+/i, "").trim() || n;
  const shorter = (n) => short(n).replace(/\s+\d+$/, "").trim() || short(n);
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
        faction: u.faction, races: {}, classes: {}, characters: 0, samples: 0, observed: 0, games: [],
      });
    const g = byFaction.get(u.faction);
    g.characters += u.characters;
    g.samples += u.samples;
    g.observed += u.observed;
    if (g.games.indexOf(u.game) < 0) g.games.push(u.game);
    for (const k in u.races) g.races[k] = (g.races[k] || 0) + u.races[k];
    for (const k in u.classes) g.classes[k] = (g.classes[k] || 0) + u.classes[k];
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
  const { renderCensusHtml } = await import(src("census.mjs"));
  const { renderMarketHtml } = await import(src("market.mjs"));
  const { renderGuildHtml } = await import(src("guilds.mjs"));

  const built = new Date();
  const stamp = "Static snapshot built " + built.toISOString().slice(0, 16).replace("T", " ") + "Z";

  process.stdout.write("Census   ... ");
  const census = await getJson(base + "/api/census?source=" + encodeURIComponent(SOURCE_GAME));
  const chars = (census.groups || []).reduce((a, g) => a + g.characters, 0);
  console.log(chars.toLocaleString() + " characters, " + (census.groups || []).length + " faction(s)");

  process.stdout.write("Auctions ... ");
  const games = (await getJson(base + "/api/games")).games || [];
  const marketGames = games.filter((g) => g.sourceGame === SOURCE_GAME && g.hasItems);
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
  const factions = [...new Set(betaRealms.map((r) => (/-(Alliance|Horde)$/.exec(r) || [, "Neutral"])[1]))].sort();
  const realmNames = [...new Set(betaRealms.map((r) => r.replace(/-(Alliance|Horde)$/, "")))].sort();

  const snapshotOf = (from, fallbackRealms) => ({
    items: mergeItems(from),
    realms: from.length ? from.map((s2) => s2.realm) : fallbackRealms,
    updatedAt: from.map((s2) => s2.updatedAt).filter(Boolean).sort().pop() || null,
    branch: WH_BRANCH, uploadFlavor: UPLOAD_FLAVOR,
  });
  const marketRealmLabels = realmLabels(realmNames);
  const dims = {
    realm: [{ key: "all", label: "All realms" }]
      .concat(realmNames.map((r) => ({ key: r, label: marketRealmLabels.get(r) }))),
    faction: [{ key: "all", label: "Both" }].concat(factions.map((f) => ({ key: f, label: f }))),
  };
  const views = [];
  for (const r of dims.realm) {
    for (const f of dims.faction) {
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
  fs.writeFileSync(path.join(outDir, "index.html"), renderCensusHtml(censusViews, {
    stylesheet: "style.css",
    nav: nav("index.html"),
    note: stamp,
    gameLabel: GAME_LABEL, scopeLabel: SCOPE_LABEL, uploadFlavor: UPLOAD_FLAVOR,
  }));
  fs.writeFileSync(path.join(outDir, "auctionhouse.html"),
    renderMarketHtml(views, dims, { stylesheet: "style.css", nav: nav("auctionhouse.html"), note: stamp,
      gameLabel: GAME_LABEL, scopeLabel: SCOPE_LABEL }));
  fs.writeFileSync(path.join(outDir, "guilds.html"),
    renderGuildHtml(guildViews, dims, { stylesheet: "style.css", nav: nav("guilds.html"), note: stamp,
      gameLabel: GAME_LABEL, scopeLabel: SCOPE_LABEL }));
  // The bundle carries its own stylesheet so it renders with nothing else served.
  fs.copyFileSync(path.join(repo, "site/public/style.css"), path.join(outDir, "style.css"));
  // Keep the existing public census artifact focused on census data; guilds
  // are rendered into guilds.html and do not need to duplicate thousands of
  // rows in this JSON file.
  const censusJson = {
    ...census,
    units: (census.units || []).map(({ guilds, ...unit }) => unit),
  };
  fs.writeFileSync(path.join(outDir, "census.json"), JSON.stringify(censusJson, null, 2));

  const rel = path.relative(repo, outDir).replace(/\\/g, "/");
  console.log("Wrote " + rel + "/{index.html,auctionhouse.html,guilds.html,style.css,census.json}");

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
