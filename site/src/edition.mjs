// wowcensus.io edition pages: edition config, the data shaping that turns the
// Worker API's census/items/inspects into per-page models, and the renderers
// that turn a page model into HTML. Shared by tools/build-forever-page.js (static
// bundle) and pages/_worker.js (edge rendering from published snapshots), so
// both produce byte-identical pages from the same data.
//
// Page models are plain JSON so they can be published to KV and rendered later
// by whatever layout code is deployed at that time.

import { renderCensusHtml, renderComboBreakdownHtml, realmName } from "./census.mjs";
import { renderMarketHtml } from "./market.mjs";
import { renderGuildHtml } from "./guilds.mjs";
import { renderGeographyHtml } from "./geography.mjs";
import { renderTalentsHtml, renderInspectCoverage } from "./talents.mjs";

export const SITE_ORIGIN = "https://wowcensus.io";
export const PAGES = ["index", "combos", "auctionhouse", "guilds", "geography", "talents"];

export const EDITIONS = {
  "classic-beta": { dir: "pages", label: "WoW Forever", nav: "Forever", flavor: "classic-beta", branch: "forever", title: "WoW Forever & Classic+ Population Census – WoWCensus", description: "WoWCensus - World of Warcraft: Forever and Classic+ population tracker. Realm population, faction balance, and race/class breakdowns from in-game /who scans.", comboBlurb: "Forever Beta is still evolving, so this snapshot is most useful for reading the current visible community rather than a settled long-term meta." },
  "classic-progression": { dir: "pages/tbc", label: "TBC Anniversary", nav: "TBC Anniversary", flavor: "tbc-anniversary", branch: "tbc", comboBlurb: "TBC Anniversary’s smaller era roster makes race and class choices a direct view of the currently visible progression community." },
  classic: { dir: "pages/classic", label: "Classic Era", nav: "Classic Era", flavor: "classic-era", branch: "classic", comboBlurb: "Classic Era keeps the original-era roster, so the mix reflects the characters currently active in its long-lived realms rather than modern class availability." },
  sod: { dir: "pages/sod", label: "Season of Discovery", nav: "SoD", flavor: "sod", branch: "classic", comboBlurb: "Season of Discovery class balance and player activity can shift sharply between phases, so treat the mix as a current activity signal rather than a durable meta ranking." },
  "mop-classic": { dir: "pages/mop", label: "Mists of Pandaria Classic", nav: "MoP Classic", flavor: "mop-classic", branch: "mop-classic", comboBlurb: "MoP Classic includes its era-specific roster and progression, so the mix captures who is visibly active during this phase rather than a prediction of endgame demand." },
  retail: { dir: "pages/retail", label: "Retail", nav: "Retail", flavor: "retail", branch: "", factionlessMarket: true, marketMetric: "quantity", comboBlurb: "Retail’s broad modern roster and cross-faction play make this a view of the current population mix." },
};

// URL directory of an edition ("/" for Forever, "/tbc/" ...), and the reverse.
export function editionPath(source) {
  const dir = EDITIONS[source].dir.replace(/^pages\/?/, "");
  return dir ? "/" + dir + "/" : "/";
}

export function sourceForPath(dir) {
  return Object.keys(EDITIONS).find((source) => editionPath(source) === dir) || null;
}

export function pagePath(source, page) {
  const root = editionPath(source);
  return page === "index" ? root : root + page;
}

export function utcTimestamp(value) {
  if (!value) return "unavailable";
  const date = typeof value === "number" ? new Date(value * 1000) : new Date(value);
  return Number.isNaN(date.getTime()) ? "unavailable" : date.toISOString().slice(0, 16).replace("T", " ") + "Z";
}

// The pages link to each other; the bundle has no Worker behind it, so the
// header carries no crumb back to one.
export function nav(source, current) {
  const edition = EDITIONS[source];
  const currentPage = current.replace(/\.html$/, "");
  const pageLabels = { index: "Census", combos: "Race + Class", auctionhouse: "Auction House", guilds: "Guilds", geography: "Geography", talents: "Talents" };
  const item = (href, label, active = href === currentPage) =>
    '<a class="game"' + (active ? ' aria-current="true"' : "") +
    ' href="' + href + '">' + label + "</a>";
  return {
    games: '<nav class="games game-nav">' +
      Object.entries(EDITIONS).map(([s, e]) => item(pagePath(s, "index"), e.nav, s === source)).join("") + "</nav>",
    pages: '<nav class="games page-nav">' +
    item(pagePath(source, "index"), "Census", currentPage === "index" || currentPage === "combos") +
      item(pagePath(source, "auctionhouse"), "Auction House", currentPage === "auctionhouse") +
      item(pagePath(source, "guilds"), "Guilds", currentPage === "guilds") +
      item(pagePath(source, "geography"), "Geography", currentPage === "geography") +
      item(pagePath(source, "talents"), "Talents", currentPage === "talents") + "</nav>",
    breadcrumbs: '<nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">WoWCensus</a><span aria-hidden="true">/</span><a href="' +
      pagePath(source, "index") + '">' + edition.nav + '</a><span aria-hidden="true">/</span><span aria-current="page">' +
      pageLabels[currentPage] + "</span></nav>",
  };
}

// Chip labels for realms. Every beta realm is called "Classic Beta <type>",
// and the client names one of them "Classic Beta PvP 2" -- the trailing number
// is the realm's own name, not an index we added. Both are noise in a chip, so
// drop the shared prefix and the trailing number, but only while the shortened
// labels stay distinct: a beta with both "PvP 1" and "PvP 2" keeps its numbers
// rather than showing two chips reading "PvP".
export function realmLabels(names) {
  const short = (n) => n.replace(/^Classic Beta\s+/i, "").trim() || n;
  const pick = new Set(names.map(realmName)).size === names.length ? realmName : short;
  return new Map(names.map((n) => [n, pick(n)]));
}

// Roll realm/faction census units up into one group per faction -- the same
// shape the census renderer charts, mirroring the Worker's own merge.
export function mergeCensusUnits(units) {
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
export function mergeItems(perRealm) {
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

// The Worker API calls an edition's pages are built from. `getJson(path)`
// fetches a path on the MarketLens Worker and returns parsed JSON. Pass
// `items: false` to skip the auction snapshots when the market isn't needed.
export async function fetchEditionData(source, getJson, { items = true } = {}) {
  const enc = encodeURIComponent(source);
  const [inspects, census, gamesBody] = await Promise.all([
    getJson("/api/inspects?source=" + enc),
    getJson("/api/census?source=" + enc),
    getJson("/api/games"),
  ]);
  const games = gamesBody.games || [];
  const marketGames = items ? games.filter((g) => g.sourceGame === source && g.realm && g.hasItems) : [];
  const snaps = await Promise.all(marketGames.map(async (g) => {
    const snap = await getJson("/api/items?game=" + encodeURIComponent(g.game));
    const realm = g.game.replace(/^realm:/, "");
    return {
      realm,
      faction: (/-(Alliance|Horde)$/.exec(realm) || [, "Neutral"])[1],
      items: snap.items || [], columns: snap.columns, updatedAt: snap.updatedAt,
    };
  }));
  return { inspects, census, games, snaps };
}

// Shape one edition's API data into a JSON model per page.
export function buildEditionModels(source, { inspects, census, games, snaps }) {
  const edition = EDITIONS[source];
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
      branch: edition.branch, uploadFlavor: edition.flavor,
      aggregateOnly: edition.marketMetric === "quantity",
    };
  };
  // Both axes list "All" plus every value the beta has a realm dataset for --
  // including a combination with no AH scan yet, whose view then says so rather
  // than the toggle quietly hiding that side.
  const betaRealms = games.filter((g) => g.sourceGame === source && g.realm)
    .map((g) => g.game.replace(/^realm:/, ""));
  const factions = [...new Set((census.units || []).map((u) => u.faction).filter((f) => f && f !== "Unknown"))].sort();
  const realmNames = [...new Set(betaRealms.map((r) => r.replace(/-(Alliance|Horde)$/, "")))].sort();
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
  const marketViews = [];
  for (const r of marketDims.realm) {
    for (const f of marketDims.faction) {
      const from = snaps.filter((s2) =>
        (r.key === "all" || s2.realm.replace(/-(Alliance|Horde)$/, "") === r.key) &&
        (f.key === "all" || s2.faction === f.key));
      const fallback = betaRealms.filter((name) =>
        (r.key === "all" || name.replace(/-(Alliance|Horde)$/, "") === r.key) &&
        (f.key === "all" || name.endsWith("-" + f.key)));
      marketViews.push({ realm: r.key, faction: f.key, snapshot: snapshotOf(from, fallback) });
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

  return {
    index: { censusViews, lastT: census.lastT, inspect: { coverage: inspects.coverage, lastT: inspects.lastT } },
    combos: { census: censusViews[0].census, lastT: census.lastT },
    auctionhouse: { views: marketViews, dims: marketDims },
    guilds: { views: guildViews, dims, lastT: census.lastT },
    geography: { views: geographyViews, dims, lastT: census.lastT },
    talents: { inspects },
  };
}

// Render one page from its model. `generatedAt` is when the data was shaped
// (build time for the static bundle, publish time for edge snapshots).
export function renderEditionPage(source, page, model, { stylesheet, generatedAt }) {
  const edition = EDITIONS[source];
  const generatedNote = "Page generated: " + utcTimestamp(generatedAt);
  const common = { stylesheet, nav: nav(source, page + ".html"), generatedNote, gameLabel: edition.label,
    canonical: SITE_ORIGIN + pagePath(source, page) };
  switch (page) {
    case "index":
      return renderCensusHtml(model.censusViews, {
        ...common,
        comboHref: pagePath(source, "combos"),
        inspectCoverage: renderInspectCoverage(model.inspect, pagePath(source, "talents")),
        notes: ["Latest census observation: " + utcTimestamp(model.lastT)],
        uploadFlavor: edition.flavor,
        title: edition.title, description: edition.description,
      });
    case "combos":
      return renderComboBreakdownHtml(model.census, {
        ...common, notes: ["Latest census observation: " + utcTimestamp(model.lastT)], flavorNote: edition.comboBlurb,
      });
    case "auctionhouse": {
      const first = model.views[0];
      return renderMarketHtml(model.views, model.dims, {
        ...common, notes: ["Latest auction scan: " + utcTimestamp(first && first.snapshot.updatedAt)],
        tbcAnalysis: source === "classic-progression",
      });
    }
    case "guilds":
      return renderGuildHtml(model.views, model.dims, { ...common, notes: ["Latest census observation: " + utcTimestamp(model.lastT)] });
    case "geography":
      return renderGeographyHtml(model.views, model.dims, { ...common, notes: ["Latest location observation: " + utcTimestamp(model.lastT)] });
    case "talents":
      return renderTalentsHtml(model.inspects, common);
  }
  throw new Error("unknown page: " + page);
}
