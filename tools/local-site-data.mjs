import { apiInspects } from "../site/src/inspects.mjs";
import { CENSUS_WINDOW_DAYS, CLASS_META, realmName } from "../site/src/census.mjs";

const REGION = "us";
const GAMES = {
  "classic-progression": { label: "TBC Anniversary" },
  "classic":             { label: "Classic Era" },
  "retail":              { label: "Retail" },
  "classic-beta":        { label: "Forever (Beta)" },
  "mop-classic":         { label: "Mists of Pandaria Classic" },
  "sod":                 { label: "Season of Discovery" },
  "hardcore":            { label: "Classic Hardcore" },
};
const DEFAULT_GAME = "classic-progression";
const ITEM_COLUMNS = ["id", "name", "slug", "mv", "asp", "q", "pq", "sc", "tc", "cat", "src", "crafter"];

function json(data, ttl, status = 200) {
  const h = { "content-type": "application/json; charset=utf-8" };
  if (ttl) h["cache-control"] = `public, max-age=${ttl}`;
  return new Response(JSON.stringify(data), { status, headers: h });
}


function pickGame(url) {
  const g = url.searchParams.get("game");
  if (!g) return DEFAULT_GAME;
  if (GAMES[g] || g.indexOf("realm:") === 0) return g; // realm datasets allowed
  return DEFAULT_GAME;
}

async function apiItems(url, env) {
  const game = pickGame(url);
  // Only uploaded realms carry market data; the flavor keys are just labels.
  if (game.indexOf("realm:") !== 0)
    return json({ game, sourceGame: game, region: REGION, count: 0, updatedAt: null, days: 0, columns: ITEM_COLUMNS, items: [] }, 300);
  const { results } = await env.DB.prepare(
    "SELECT id,name,slug,mv,asp,q,pq,sc,tc,cat,src,crafter FROM items WHERE game=? ORDER BY q DESC"
  ).bind(game).all();
  const rows = results.map((r) => [r.id, r.name, r.slug, r.mv, r.asp, r.q, r.pq, r.sc, r.tc, r.cat, r.src, r.crafter]);

  // Freshness metadata for the confidence indicator.
  const meta = await env.DB.prepare("SELECT MAX(updated_at) u FROM items WHERE game=?").bind(game).first();
  const dataset = await env.DB.prepare("SELECT source_game FROM datasets WHERE game=?").bind(game).first();
  const hd = await env.DB.prepare("SELECT COUNT(DISTINCT ts) d FROM history WHERE game=?").bind(game).first();

  const res = json({
    game, sourceGame: (dataset && dataset.source_game) || game, region: REGION, count: rows.length,
    updatedAt: meta && meta.u, days: (hd && hd.d) || 0, columns: ITEM_COLUMNS, items: rows,
  }, 3600);
  return res;
}

async function apiGames(env) {
  const itemRows = (await env.DB.prepare(
    "SELECT game, COUNT(*) c, MAX(updated_at) u FROM items GROUP BY game"
  ).all()).results;
  // Realms with population data but no AH items should still appear in the
  // switcher, so union the two sources and flag what each dataset has.
  const popRows = (await env.DB.prepare(
    "SELECT game, COUNT(*) c FROM pop_samples GROUP BY game"
  ).all()).results;

  const dsRows = (await env.DB.prepare("SELECT game, source_game FROM datasets").all()).results;
  const source = new Map();
  for (const r of dsRows) source.set(r.game, r.source_game);

  const map = new Map();
  for (const r of itemRows)
    map.set(r.game, { game: r.game, count: r.c, updatedAt: r.u, hasItems: true, hasPop: false });
  for (const r of popRows) {
    const e = map.get(r.game);
    if (e) e.hasPop = true;
    else map.set(r.game, { game: r.game, count: 0, updatedAt: null, hasItems: false, hasPop: true });
  }

  const games = [...map.values()].map((e) => {
    const realm = e.game.indexOf("realm:") === 0;
    return {
      game: e.game, count: e.count, updatedAt: e.updatedAt, realm,
      hasItems: e.hasItems, hasPop: e.hasPop,
      sourceGame: realm ? (source.get(e.game) || null) : e.game,
      label: realm ? realmName(e.game) : (GAMES[e.game] ? GAMES[e.game].label : e.game),
    };
  });
  return json({ games }, 120);
}


const ZONE_WINDOW_DAYS = 30;

function splitRealm(label) {
  const cut = label.lastIndexOf("-");
  return cut > 0 ? { name: label.slice(0, cut), fac: label.slice(cut + 1) } : { name: label, fac: "" };
}

async function loadCensus(env, sourceGame = "classic-beta") {
  // Forever shows every observed character once, whether found by a manual
  // /ml who or a census sweep. The characters table already merges both paths
  // and keeps the most recent race/class/level for each identity.
  const allObserved = sourceGame === "classic-beta";
  // Nazgrim and Galakras scans see the same connected MoP population. Assign
  // each character to its latest scan origin before building additive units.
  const connected = sourceGame === "mop-classic";
  // Other editions retain their rolling census selection. It excludes zone
  // queries and ends each window at that dataset's newest census sighting.
  const censusWindow = CENSUS_WINDOW_DAYS * 86400;
  const censusRows = allObserved ? [] : (await env.DB.prepare(
    `WITH obs AS (
       SELECT o.game game, o.race race, o.class_file cf, o.observed_at t,
         ROW_NUMBER() OVER (PARTITION BY ${connected ? "o.character_key" : "o.game, o.character_key"} ORDER BY o.observed_at DESC) rn
       FROM character_location_observations o
       JOIN population_sweeps s
         ON s.game = o.game AND s.source_game = o.source_game AND s.sweep_id = o.sweep_id
       JOIN population_sweep_queries q
         ON q.game = o.game AND q.source_game = o.source_game AND q.sweep_id = o.sweep_id
        AND q.query_index = o.query_index
       JOIN datasets d ON d.game = o.game
       WHERE d.source_game = ? AND s.label LIKE 'census %' AND COALESCE(q.filter, '') NOT LIKE '%z-%'
     ), latest AS (SELECT game, MAX(t) t FROM obs GROUP BY game)
     SELECT obs.game game, obs.race race, obs.cf cf, COUNT(*) n
     FROM obs JOIN latest l ON l.game = obs.game
     WHERE obs.rn = 1 AND obs.t >= l.t - ?
     GROUP BY obs.game, obs.race, obs.cf`
  ).bind(sourceGame, censusWindow).all()).results;
  // Forever uses all stored identities; other editions fall back to recent
  // plain sightings only when they have no census passes.
  const censused = new Set(censusRows.map((r) => r.game));
  const sightingRows = (await env.DB.prepare(
    `SELECT c.game game, c.race race, c.class_file cf, COUNT(*) n
     FROM characters c JOIN datasets d ON d.game = c.game
     ${allObserved ? "" : "JOIN (SELECT game, MAX(last_seen) latest FROM characters GROUP BY game) m ON m.game = c.game"}
     WHERE d.source_game = ? ${allObserved ? "" : "AND c.last_seen >= m.latest - ?"}
     GROUP BY c.game, c.race, c.class_file`
  ).bind(...(allObserved ? [sourceGame] : [sourceGame, censusWindow])).all()).results.filter((r) => !censused.has(r.game));
  const rows = censusRows.concat(sightingRows);
  // Distinct characters by race for the chart's three selectable windows.
  // Anchor each window to its dataset's latest observation, as above.
  const raceCensusRows = allObserved ? [] : (await env.DB.prepare(
    `WITH obs AS (
       SELECT o.game, o.race, o.observed_at t,
         ROW_NUMBER() OVER (PARTITION BY ${connected ? "o.character_key" : "o.game, o.character_key"} ORDER BY o.observed_at DESC) rn
       FROM character_location_observations o
       JOIN population_sweeps s ON s.game=o.game AND s.source_game=o.source_game AND s.sweep_id=o.sweep_id
       JOIN population_sweep_queries q ON q.game=o.game AND q.source_game=o.source_game AND q.sweep_id=o.sweep_id AND q.query_index=o.query_index
       JOIN datasets d ON d.game=o.game
       WHERE d.source_game=? AND s.label LIKE 'census %' AND COALESCE(q.filter,'') NOT LIKE '%z-%'
     ), latest AS (SELECT game, MAX(t) t FROM obs GROUP BY game)
     SELECT o.game, o.race, COUNT(*) all_count,
       SUM(CASE WHEN o.t>=l.t-604800 THEN 1 ELSE 0 END) week_count,
       SUM(CASE WHEN o.t>=l.t-86400 THEN 1 ELSE 0 END) day_count
     FROM obs o JOIN latest l ON l.game=o.game
     WHERE o.rn=1 AND o.race IS NOT NULL
     GROUP BY o.game,o.race`
  ).bind(sourceGame).all()).results;
  const raceSightingRows = (await env.DB.prepare(
    `SELECT c.game, c.race, COUNT(*) all_count,
       SUM(CASE WHEN c.last_seen>=m.latest-604800 THEN 1 ELSE 0 END) week_count,
       SUM(CASE WHEN c.last_seen>=m.latest-86400 THEN 1 ELSE 0 END) day_count
     FROM characters c JOIN datasets d ON d.game=c.game
     JOIN (SELECT game,MAX(last_seen) latest FROM characters GROUP BY game) m ON m.game=c.game
     WHERE d.source_game=? AND c.race IS NOT NULL
     GROUP BY c.game,c.race`
  ).bind(sourceGame).all()).results.filter((r) => !censused.has(r.game));
  const raceWindowRows = raceCensusRows.concat(raceSightingRows);
  const scans = (await env.DB.prepare(
    `SELECT p.game game, COUNT(*) samples, SUM(p.observed) observed, MAX(p.t) lastT
     FROM pop_samples p JOIN datasets d ON d.game = p.game
     WHERE d.source_game = ? GROUP BY p.game`
  ).bind(sourceGame).all()).results;
  const guilds = (await env.DB.prepare(
    `SELECT c.game game, c.guild guild, COUNT(*) members
     FROM ${connected ? "(SELECT *, ROW_NUMBER() OVER (PARTITION BY character_key ORDER BY last_seen DESC) rn FROM characters WHERE source_game='mop-classic')" : "characters"} c
     JOIN datasets d ON d.game = c.game
     WHERE d.source_game = ? ${connected ? "AND c.rn=1" : ""} AND TRIM(COALESCE(c.guild, '')) <> ''
     GROUP BY c.game, c.guild`
  ).bind(sourceGame).all()).results;
  // Geography is a rolling window, not a lifetime tally: only characters seen
  // within ZONE_WINDOW_DAYS of that dataset's newest sighting count, so players
  // who stopped showing up age out instead of pinning their last zone forever.
  // Anchoring on the dataset's own latest scan (rather than wall-clock now)
  // keeps a realm that simply hasn't been scanned lately from going blank.
  const zones = (await env.DB.prepare(
    `SELECT c.game game, COALESCE(NULLIF(TRIM(c.zone), ''), 'Unknown') zone,
       COUNT(*) characters, CAST(ROUND(AVG(c.level)) AS INT) avgLevel, MAX(c.level) maxLevel
     FROM ${connected ? "(SELECT *, ROW_NUMBER() OVER (PARTITION BY character_key ORDER BY last_seen DESC) rn FROM characters WHERE source_game='mop-classic')" : "characters"} c JOIN datasets d ON d.game = c.game
     JOIN (SELECT game, MAX(last_seen) latest FROM characters GROUP BY game) m ON m.game = c.game
     WHERE d.source_game = ? ${connected ? "AND c.rn=1" : ""} AND c.last_seen >= m.latest - ?
     GROUP BY c.game, COALESCE(NULLIF(TRIM(c.zone), ''), 'Unknown')`
  ).bind(sourceGame, ZONE_WINDOW_DAYS * 86400).all()).results;

  // One unit per dataset -- a realm/faction pair. Callers that want the whole
  // beta merged use `groups` below; the static bundle slices `units` per realm
  // for its realm toggle.
  const units = new Map();
  const realms = new Set();
  let lastT = 0;
  function unit(game) {
    if (!units.has(game)) {
      const label = game.indexOf("realm:") === 0 ? game.slice(6) : game;
      const { name, fac } = splitRealm(label);
      realms.add(name);
      units.set(game, {
        game, realm: name, faction: fac || "Unknown",
        races: {}, raceWindows: { all: {}, week: {}, day: {} }, classes: {}, combos: {}, guilds: [], zones: [], zoneCharacters: 0,
        characters: 0, samples: 0, observed: 0,
      });
    }
    return units.get(game);
  }
  for (const r of rows) {
    const u = unit(r.game);
    u.basis = censused.has(r.game) ? "census" : "sightings";
    u.characters += r.n;
    if (r.race) u.races[r.race] = (u.races[r.race] || 0) + r.n;
    if (r.cf) u.classes[r.cf] = (u.classes[r.cf] || 0) + r.n;
    if (r.race && r.cf) {
      const key = r.race + "\t" + r.cf;
      u.combos[key] = (u.combos[key] || 0) + r.n;
    }
  }
  for (const r of raceWindowRows) {
    const windows = unit(r.game).raceWindows;
    windows.all[r.race] = r.all_count || 0;
    windows.week[r.race] = r.week_count || 0;
    windows.day[r.race] = r.day_count || 0;
  }
  for (const sc of scans) {
    const u = unit(sc.game);
    u.samples += sc.samples || 0;
    u.observed += sc.observed || 0;
    if ((sc.lastT || 0) > lastT) lastT = sc.lastT;
  }
  for (const g of guilds) {
    unit(g.game).guilds.push({ name: g.guild, members: g.members || 0 });
  }
  for (const z of zones) {
    unit(z.game).zoneCharacters += z.characters || 0;
    unit(z.game).zones.push({
      name: z.zone, characters: z.characters || 0,
      avgLevel: z.avgLevel || 0, maxLevel: z.maxLevel || 0,
    });
  }
  for (const u of units.values()) {
    u.guilds.sort((a, b) => b.members - a.members || a.name.localeCompare(b.name));
    u.zones.sort((a, b) => b.characters - a.characters || a.name.localeCompare(b.name));
  }

  return {
    groups: mergeCensusUnits([...units.values()]),
    units: [...units.values()],
    realms: [...realms].sort(),
    lastT,
    zoneWindowDays: ZONE_WINDOW_DAYS,
  };
}

// Roll realm/faction units up into one group per faction, which is what the
// page charts. Exported shape matches what renderCensusHtml expects.
function mergeCensusUnits(units) {
  const byFaction = new Map();
  for (const u of units) {
    if (!byFaction.has(u.faction))
      byFaction.set(u.faction, {
        faction: u.faction, races: {}, raceWindows: { all: {}, week: {}, day: {} }, classes: {}, combos: {}, characters: 0, samples: 0, observed: 0, games: [],
      });
    const g = byFaction.get(u.faction);
    g.characters += u.characters;
    g.samples += u.samples;
    g.observed += u.observed;
    if (g.games.indexOf(u.game) < 0) g.games.push(u.game);
    for (const k in u.races) g.races[k] = (g.races[k] || 0) + u.races[k];
    for (const window of ["all", "week", "day"])
      for (const k in (u.raceWindows?.[window] || {}))
        g.raceWindows[window][k] = (g.raceWindows[window][k] || 0) + u.raceWindows[window][k];
    for (const k in u.classes) g.classes[k] = (g.classes[k] || 0) + u.classes[k];
    for (const k in u.combos) g.combos[k] = (g.combos[k] || 0) + u.combos[k];
  }
  const order = ["Alliance", "Horde"];
  return [...byFaction.values()].sort((a, b) =>
    ((order.indexOf(a.faction) + 1) || 99) - ((order.indexOf(b.faction) + 1) || 99) || b.characters - a.characters);
}


async function apiCensus(url, env) {
  const sourceGame = url.searchParams.get("source") || "classic-beta";
  if (!["classic-beta", "classic-progression", "classic", "retail", "mop-classic", "sod", "hardcore"].includes(sourceGame))
    return json({ error: "unknown source game" }, 400);
  return json(await loadCensus(env, sourceGame), 300);
}


// Read-only queries used only while building the static Pages output.
export async function localJson(route, env) {
  const url = new URL(route, "http://local.invalid");
  const routes = {
    "/api/games": () => apiGames(env),
    "/api/items": () => apiItems(url, env),
    "/api/census": () => apiCensus(url, env),
    "/api/inspects": () => apiInspects(url, env),
  };
  const query = routes[url.pathname];
  if (!query) throw new Error(`Unsupported local data route: ${route}`);
  const response = await query();
  if (!response.ok) throw new Error(`Local data ${route}: HTTP ${response.status} ${(await response.text()).slice(0, 300)}`);
  return response.json();
}
