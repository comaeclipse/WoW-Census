// MarketLens — Cloudflare Worker
// Routes:
//   GET  /                         arcade screener (static shell + /api/items)
//   GET  /api/items?game=          latest snapshot for a dataset (JSON, cached)
//   GET  /api/history?game=&id=    daily time series for one item (JSON)
//   GET  /item/<slug|id>?game=     server-rendered item page + history graph
//   GET  /admin/refresh?token=&game=   manual collect (seed after deploy)
//   cron                           daily collect of every dataset in env.GAMES
//
// Data source: TradeSkillMaster public data (CSVs have no CORS header, so the
// browser can't fetch them — the Worker fetches server-side and serves JSON).

const REGION = "us";
const GAMES = {
  "classic-progression": { label: "TBC Anniversary" },
  "classic":             { label: "Classic Era" },
  "retail":              { label: "Retail" },
};
const DEFAULT_GAME = "classic-progression";
const csvUrl = (game) =>
  `https://public-data.tradeskillmaster.com/${game}/${REGION}/region/items.csv`;

function slugify(name) {
  return name.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
}
// Retail's auction house is shared across both factions, so retail realm market
// data is keyed by realm only (population stays per-faction — /who rosters differ).
function stripFaction(name) { return name.replace(/-(Alliance|Horde|Neutral)$/, ""); }
// Keep the readable "realm:" colon in a link; encode only the realm name.
function gameHref(prefix, g) {
  return prefix + (g.indexOf("realm:") === 0 ? "realm:" + encodeURIComponent(g.slice(6)) : encodeURIComponent(g));
}
function esc(s) {
  return String(s).replace(/[&<>"]/g, (m) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[m]));
}
function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }
function scale100(v, mn, mx) { return mx === mn ? 0 : clamp(((v - mn) / (mx - mn)) * 100, 0, 100); }
function demandScore(sr, spd) {
  const rate = scale100(sr, 0, 0.5);
  const vol = scale100(Math.log(1 + spd), 0, Math.log(101));
  return Math.round(clamp(0.75 * rate + 0.25 * vol, 0, 100));
}

// Locale-independent class token -> [display name, WoW class color]. Used to
// label and tint the class distribution on the population page.
const CLASS_META = {
  WARRIOR: ["Warrior", "C79C6E"], PALADIN: ["Paladin", "F58CBA"],
  HUNTER:  ["Hunter", "ABD473"],  ROGUE:   ["Rogue", "FFF569"],
  PRIEST:  ["Priest", "FFFFFF"],  SHAMAN:  ["Shaman", "0070DE"],
  MAGE:    ["Mage", "69CCF0"],    WARLOCK: ["Warlock", "9482C9"],
  DRUID:   ["Druid", "FF7D0A"],   DEATHKNIGHT: ["Death Knight", "C41F3B"],
  MONK: ["Monk", "00FF96"], DEMONHUNTER: ["Demon Hunter", "A330C9"],
  EVOKER: ["Evoker", "33937F"],
};

// Class -> crafting-market affinity (mirrors the addon's Population/WhoScan.lua).
// A demand HINT from population mix, never observed sales.
const POP_AFFINITY = {
  WARRIOR: { Blacksmithing: 3, Gear: 2, Enchanting: 2, Alchemy: 1, Jewelcrafting: 1 },
  PALADIN: { Blacksmithing: 3, Gear: 2, Enchanting: 2, Alchemy: 1, Jewelcrafting: 1 },
  HUNTER:  { Leatherworking: 3, Gear: 2, Engineering: 1, Enchanting: 1, Alchemy: 1 },
  ROGUE:   { Leatherworking: 3, Gear: 2, Alchemy: 2, Enchanting: 1 },
  SHAMAN:  { Leatherworking: 3, Gear: 2, Enchanting: 1, Jewelcrafting: 1, Alchemy: 1 },
  DRUID:   { Leatherworking: 3, Gear: 2, Alchemy: 1, Enchanting: 1 },
  PRIEST:  { Tailoring: 3, Gear: 2, Enchanting: 2, Alchemy: 1, Jewelcrafting: 1 },
  MAGE:    { Tailoring: 3, Gear: 2, Enchanting: 2, Jewelcrafting: 1, Alchemy: 1 },
  WARLOCK: { Tailoring: 3, Gear: 2, Enchanting: 2, Alchemy: 1 },
  DEATHKNIGHT: { Blacksmithing: 3, Gear: 2, Enchanting: 2, Alchemy: 1, Jewelcrafting: 1 },
  MONK: { Leatherworking: 3, Gear: 2, Alchemy: 1, Enchanting: 1, Jewelcrafting: 1 },
  DEMONHUNTER: { Leatherworking: 3, Gear: 2, Alchemy: 2, Enchanting: 1 },
  EVOKER: { Leatherworking: 3, Gear: 2, Enchanting: 2, Alchemy: 1, Jewelcrafting: 1 },
};

function popDemand(classes) {
  const w = {};
  for (const tok in classes) {
    const aff = POP_AFFINITY[tok];
    if (!aff) continue;
    for (const prof in aff) w[prof] = (w[prof] || 0) + aff[prof] * classes[tok];
  }
  let max = 0;
  for (const p in w) if (w[p] > max) max = w[p];
  return Object.keys(w)
    .map((p) => ({ prof: p, weight: w[p], score: max > 0 ? Math.round((w[p] / max) * 100) : 0 }))
    .sort((a, b) => b.weight - a.weight);
}

function safeParse(s) { try { return JSON.parse(s) || {}; } catch (e) { return {}; } }
function gsc(cop) {
  cop = Math.round(cop || 0);
  const g = Math.floor(cop / 10000), s = Math.floor((cop % 10000) / 100), c = cop % 100;
  if (g > 0) return g.toLocaleString() + "g " + s + "s";
  if (s > 0) return s + "s " + c + "c";
  return c + "c";
}
function bigGold(cop) {
  const g = Math.floor((cop || 0) / 10000);
  if (g >= 1e6) return (g / 1e6).toFixed(1) + "M";
  if (g >= 1e3) return Math.round(g / 1e3) + "k";
  return "" + g;
}

// RFC4180-ish CSV line parser (handles quoted fields with doubled quotes).
function parseLine(line) {
  const out = [];
  let i = 0, cur = "", q = false;
  while (i < line.length) {
    const ch = line[i];
    if (q) {
      if (ch === '"') {
        if (line[i + 1] === '"') { cur += '"'; i += 2; continue; }
        q = false; i++; continue;
      }
      cur += ch; i++;
    } else {
      if (ch === '"') { q = true; i++; }
      else if (ch === ",") { out.push(cur); cur = ""; i++; }
      else { cur += ch; i++; }
    }
  }
  out.push(cur);
  return out;
}

function json(data, ttl) {
  const h = { "content-type": "application/json; charset=utf-8" };
  if (ttl) h["cache-control"] = `public, max-age=${ttl}`;
  return new Response(JSON.stringify(data), { headers: h });
}

function todayBucket() {
  const d = new Date();
  return d.getUTCFullYear() * 10000 + (d.getUTCMonth() + 1) * 100 + d.getUTCDate();
}

// D1 limits bound parameters (~100) per query, so inline escaped literals
// instead of binding — lets us write many rows per statement safely.
function sqlVal(v) {
  if (v === null || v === undefined) return "NULL";
  if (typeof v === "number") return Number.isFinite(v) ? String(v) : "NULL";
  return "'" + String(v).replace(/'/g, "''") + "'";
}
async function bulkInsert(db, table, cols, rows, per) {
  const colList = cols.join(",");
  for (let i = 0; i < rows.length; i += per) {
    const chunk = rows.slice(i, i + per);
    const values = chunk.map((r) => "(" + r.map(sqlVal).join(",") + ")").join(",");
    await db.prepare(`INSERT OR REPLACE INTO ${table} (${colList}) VALUES ${values}`).run();
  }
}

async function collectGame(env, game) {
  if (!GAMES[game]) return { game, error: "unknown game" };
  const res = await fetch(csvUrl(game), { cf: { cacheTtl: 300 } });
  if (!res.ok) return { game, error: "fetch " + res.status };
  const text = await res.text();
  const lines = text.split("\n");
  const ts = todayBucket();

  const itemRows = [], histRows = [];
  for (let i = 1; i < lines.length; i++) {
    const line = lines[i].replace(/\r$/, "");
    if (!line) continue;
    const f = parseLine(line);
    if (f.length < 8) continue;
    const id = parseInt(f[0], 10);
    if (!id) continue;
    const name = f[1];
    const mv = Math.round(+f[2] || 0), hist = Math.round(+f[3] || 0);
    const asp = Math.round(+f[4] || 0), sr = +f[5] || 0, spd = +f[6] || 0;
    if (!(sr > 0 || spd > 0)) continue;
    itemRows.push([game, id, name, slugify(name), mv, asp, sr, spd, hist, f[7] || ""]);
    histRows.push([game, id, ts, mv, asp, sr, spd]);
  }

  await bulkInsert(env.DB, "items",
    ["game", "id", "name", "slug", "mv", "asp", "sr", "spd", "hist", "updated_at"], itemRows, 150);
  await bulkInsert(env.DB, "history",
    ["game", "id", "ts", "mv", "asp", "sr", "spd"], histRows, 200);

  return { game, count: itemRows.length };
}

async function collectAll(env) {
  const games = (env.GAMES || DEFAULT_GAME).split(",").map((s) => s.trim()).filter(Boolean);
  const results = [];
  for (const g of games) {
    try { results.push(await collectGame(env, g)); }
    catch (e) { results.push({ game: g, error: String(e) }); }
  }
  return results;
}

function pickGame(url) {
  const g = url.searchParams.get("game");
  if (!g) return DEFAULT_GAME;
  if (GAMES[g] || g.indexOf("realm:") === 0) return g; // realm datasets allowed
  return DEFAULT_GAME;
}
function dayBucketFromUnix(sec) {
  const d = new Date(sec * 1000);
  return d.getUTCFullYear() * 10000 + (d.getUTCMonth() + 1) * 100 + d.getUTCDate();
}

// List every dataset present (region datasets + uploaded realms) for the selector.
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
      label: realm ? e.game.slice(6) : (GAMES[e.game] ? GAMES[e.game].label : e.game),
    };
  });
  return json({ games }, 120);
}

// Import a realm's addon export (/ml export). Stores it as game "realm:<name>",
// joining region sale data by itemID so demand + the deal signal work.
async function importRealm(url, env, req) {
  if (!env.REFRESH_TOKEN || url.searchParams.get("token") !== env.REFRESH_TOKEN)
    return new Response("forbidden", { status: 403 });
  const regionGame = GAMES[url.searchParams.get("region")] ? url.searchParams.get("region") : DEFAULT_GAME;

  let body;
  try { body = await req.json(); } catch (e) { return json({ error: "invalid json" }); }
  if (!body || body.type !== "ml-realm-v1" || !body.realm || !body.items)
    return json({ error: "expected an ml-realm-v1 export from /ml export" });
  const realmName = regionGame === "retail" ? stripFaction(body.realm) : body.realm;
  const game = "realm:" + realmName;
  const capabilities = body.capabilities || {};
  const sellersAvailable = capabilities.sellers !== false;

  const reg = await env.DB.prepare("SELECT id,name,sr,spd,asp FROM items WHERE game=?").bind(regionGame).all();
  const rmap = new Map();
  for (const r of reg.results) rmap.set(r.id, r);

  const itemRows = [], histRows = [];
  for (const idStr in body.items) {
    const id = parseInt(idStr, 10);
    if (!id) continue;
    const rec = body.items[idStr];
    const snaps = rec && rec.s;
    if (!snaps || !snaps.length) continue;
    const rr = rmap.get(id) || {};
    // Prefer the addon's name; fall back to the region dataset (TSM has every
    // item's name), since getAll scans often leave names uncached.
    const name = (rec.n && rec.n.trim()) ? rec.n : (rr.name || ("item:" + id));
    const last = snaps[snaps.length - 1];
    const q = last[1] || 0, sc = sellersAvailable ? (last[3] || 0) : null;
    const w = last[6] || 0; // [t,q,a,s,l,m,w,tc]
    itemRows.push([game, id, name, slugify(name), q * w, w, rr.sr || 0, rr.spd || 0, rr.asp || 0,
      new Date().toISOString(), q, sc]);
    for (const sn of snaps) {
      histRows.push([game, id, dayBucketFromUnix(sn[0]), (sn[1] || 0) * (sn[6] || 0), sn[6] || 0,
        rr.sr || 0, rr.spd || 0, sn[1] || 0]);
    }
  }
  await bulkInsert(env.DB, "items",
    ["game", "id", "name", "slug", "mv", "asp", "sr", "spd", "hist", "updated_at", "q", "sc"], itemRows, 120);
  await bulkInsert(env.DB, "history",
    ["game", "id", "ts", "mv", "asp", "sr", "spd", "q"], histRows, 150);
  await env.DB.prepare(
    "INSERT OR REPLACE INTO datasets (game,source_game,updated_at) VALUES (?,?,?)"
  ).bind(game, regionGame, new Date().toISOString()).run();
  return json({ ok: true, realm: realmName, items: itemRows.length });
}

async function apiItems(url, env, ctx) {
  const game = pickGame(url);
  const cache = caches.default;
  // Bump the version suffix whenever the response shape changes, to bust the edge cache.
  const key = new Request(url.origin + "/api/items?game=" + game + "&v=8");
  const hit = await cache.match(key);
  if (hit) return hit;

  const { results } = await env.DB.prepare(
    "SELECT id,name,slug,mv,asp,sr,spd,q,sc,hist FROM items WHERE game=? ORDER BY spd DESC"
  ).bind(game).all();
  const rows = results.map((r) => [r.id, r.name, r.slug, r.mv, r.asp, r.sr, r.spd, r.q, r.sc, r.hist]);

  // Freshness metadata for the confidence indicator.
  const meta = await env.DB.prepare("SELECT MAX(updated_at) u FROM items WHERE game=?").bind(game).first();
  const dataset = await env.DB.prepare("SELECT source_game FROM datasets WHERE game=?").bind(game).first();
  const hd = await env.DB.prepare("SELECT COUNT(DISTINCT ts) d FROM history WHERE game=?").bind(game).first();

  const res = json({
    game, sourceGame: (dataset && dataset.source_game) || game, region: REGION, count: rows.length,
    updatedAt: meta && meta.u, days: (hd && hd.d) || 0, items: rows,
  }, 3600);
  ctx.waitUntil(cache.put(key, res.clone()));
  return res;
}

async function apiHistory(url, env) {
  const game = pickGame(url);
  const id = parseInt(url.searchParams.get("id"), 10);
  if (!id) return json({ error: "missing id" });
  const { results } = await env.DB.prepare(
    "SELECT ts,mv,asp,sr,spd FROM history WHERE game=? AND id=? ORDER BY ts"
  ).bind(game, id).all();
  return json({ game, id, points: results }, 1800);
}

async function itemPage(url, env) {
  const game = pickGame(url);
  const key = decodeURIComponent(url.pathname.replace(/^\/item\//, "")).replace(/\/$/, "");
  let row;
  if (/^\d+$/.test(key)) {
    row = await env.DB.prepare("SELECT * FROM items WHERE game=? AND id=?").bind(game, +key).first();
  } else {
    // slug may not be unique; pick the most-traded match.
    row = await env.DB.prepare(
      "SELECT * FROM items WHERE game=? AND slug=? ORDER BY spd DESC LIMIT 1"
    ).bind(game, key).first();
  }
  if (!row) return new Response(notFound(game, key), { status: 404, headers: { "content-type": "text/html; charset=utf-8" } });

  const hist = await env.DB.prepare(
    "SELECT ts,mv,asp,sr,spd FROM history WHERE game=? AND id=? ORDER BY ts"
  ).bind(game, row.id).all();

  const demand = demandScore(row.sr, row.spd);
  const isRealm = game.indexOf("realm:") === 0;
  const gameLabel = isRealm ? game.slice(6) : (GAMES[game] ? GAMES[game].label : game);
  const points = JSON.stringify(hist.results || []);

  let statsHtml;
  if (isRealm) {
    const deal = row.hist > 0 ? Math.round((row.hist - row.asp) / row.hist * 100) : null;
    statsHtml =
      stat("Demand", demand + "/100", demand >= 70 ? "gr" : demand >= 45 ? "g" : "mu") +
      stat("Your buyout", gsc(row.asp), "g") +
      stat("Quantity", (row.q || 0).toLocaleString()) +
      stat("Sellers", row.sc == null ? "N/A" : row.sc) +
      (deal === null ? "" : stat("vs region", (deal >= 0 ? "+" : "") + deal + "%", deal >= 0 ? "gr" : "rd"));
  } else {
    statsHtml =
      stat("Demand", demand + "/100", demand >= 70 ? "gr" : demand >= 45 ? "g" : "mu") +
      stat("Sale rate", Math.round(row.sr * 100) + "%") +
      stat("Sold / day", row.spd >= 10 ? Math.round(row.spd) : row.spd.toFixed(1)) +
      stat("Avg sale", gsc(row.asp), "g") +
      stat("Market value", gsc(row.mv), "mu");
  }

  const html = `<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(row.name)} — MarketLens</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Press+Start+2P&family=VT323&display=swap">
<link rel="stylesheet" href="/style.css">
</head><body>
<div class="crt" aria-hidden="true"></div>
<div class="wrap">
  <a class="back" href="/?game=${game}">&#9664; MARKETLENS</a>
  <header class="ihead">
    <h1 class="iname">${esc(row.name)}</h1>
    <div class="itag">${esc(gameLabel)} &middot; region ${REGION.toUpperCase()} &middot; item ${row.id}</div>
  </header>

  <section class="istats">${statsHtml}</section>

  <div class="panel">
    <div class="ptitle">PRICE HISTORY <span class="mu" id="range"></span></div>
    <div id="chart" class="chart"></div>
    <div class="legend">
      <span><i style="background:var(--gold)"></i> Avg sale</span>
      <span><i style="background:var(--blue)"></i> Market value</span>
      <span><i style="background:var(--green)"></i> Sale rate</span>
    </div>
    <p class="hint" id="hhint"></p>
  </div>

  <p class="src">Data: TradeSkillMaster public data (${esc(game)} / ${REGION}). History accrues daily from this site's collector.</p>
</div>
<script>window.ITEM=${JSON.stringify({ id: row.id, name: row.name, game })};window.POINTS=${points};</script>
<script src="/item.js"></script>
</body></html>`;
  return new Response(html, { headers: { "content-type": "text/html; charset=utf-8", "cache-control": "public, max-age=1800" } });

  function stat(k, v, cls) {
    return `<div class="tile"><div class="k">${esc(k)}</div><div class="v ${cls || ""}">${esc(v)}</div></div>`;
  }
}

function notFound(game, key) {
  return `<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/style.css">
  <div class="wrap"><p class="src">No item "${esc(key)}" in ${esc(game)}. <a href="/?game=${esc(game)}">Back to screener</a></p></div>`;
}

// Import aggregate samples (v1/v2) plus identity/history (v2). Re-uploading a
// cumulative addon export is idempotent: lifetime bounds and daily counts only
// move forward.
async function importPop(url, env, req) {
  if (!env.REFRESH_TOKEN || url.searchParams.get("token") !== env.REFRESH_TOKEN)
    return new Response("forbidden", { status: 403 });

  let body;
  try { body = await req.json(); } catch (e) { return json({ error: "invalid json" }); }
  if (!body || !["ml-pop-v1", "ml-pop-v2"].includes(body.type) || !body.realm || !Array.isArray(body.samples))
    return json({ error: "expected an ml-pop-v1 or ml-pop-v2 population export" });
  const game = "realm:" + body.realm;
  let sourceGame = GAMES[body.flavor] ? body.flavor : null;
  if (!sourceGame) {
    const dataset = await env.DB.prepare("SELECT source_game FROM datasets WHERE game=?").bind(game).first();
    sourceGame = dataset && GAMES[dataset.source_game] ? dataset.source_game : DEFAULT_GAME;
  }

  const rows = [];
  for (const s of body.samples) {
    if (!s || !s.t) continue;
    rows.push([
      game, s.t, s.f || null, s.o || 0, s.tot || s.o || 0, s.flt || "",
      JSON.stringify(s.c || {}), JSON.stringify(s.r || {}),
    ]);
  }
  if (rows.length) await bulkInsert(env.DB, "pop_samples",
    ["game", "t", "faction", "observed", "total", "filter", "classes", "races"], rows, 80);

  const characterRows = [];
  for (const c of (body.characters || [])) {
    if (!c || !c.k || !c.fs || !c.ls) continue;
    characterRows.push([game, sourceGame, c.k, c.fn || c.n || c.k, c.n || "", c.r || "",
      c.g || "", c.l || 0, c.race || "", c.class || "", c.cf || "", c.z || "",
      c.fs, c.ls, c.sc || 0]);
  }
  for (let i = 0; i < characterRows.length; i += 60) {
    const values = characterRows.slice(i, i + 60).map((r) => "(" + r.map(sqlVal).join(",") + ")").join(",");
    await env.DB.prepare(`INSERT INTO characters
      (game,source_game,character_key,full_name,name,realm,guild,level,race,class,class_file,zone,first_seen,last_seen,seen_count)
      VALUES ${values} ON CONFLICT(game,source_game,character_key) DO UPDATE SET
      full_name=excluded.full_name,name=excluded.name,realm=excluded.realm,guild=excluded.guild,
      level=excluded.level,race=excluded.race,class=excluded.class,class_file=excluded.class_file,zone=excluded.zone,
      first_seen=MIN(characters.first_seen,excluded.first_seen),
      last_seen=MAX(characters.last_seen,excluded.last_seen),seen_count=MAX(characters.seen_count,excluded.seen_count)`).run();
  }

  const observationRows = [];
  for (const o of (body.observations || [])) {
    if (!Array.isArray(o) || !o[0] || !o[1]) continue;
    observationRows.push([game, sourceGame, o[0], o[1], o[2] || 0, o[3] || 0, o[4] || 0]);
  }
  for (let i = 0; i < observationRows.length; i += 100) {
    const values = observationRows.slice(i, i + 100).map((r) => "(" + r.map(sqlVal).join(",") + ")").join(",");
    await env.DB.prepare(`INSERT INTO character_observations
      (game,source_game,character_key,day,sightings,first_seen,last_seen) VALUES ${values}
      ON CONFLICT(game,source_game,character_key,day) DO UPDATE SET
      sightings=MAX(character_observations.sightings,excluded.sightings),
      first_seen=MIN(character_observations.first_seen,excluded.first_seen),
      last_seen=MAX(character_observations.last_seen,excluded.last_seen)`).run();
  }
  await env.DB.prepare(
    "INSERT OR REPLACE INTO datasets (game,source_game,updated_at) VALUES (?,?,?)"
  ).bind(game, sourceGame, new Date().toISOString()).run();
  if (!rows.length && !characterRows.length) return json({ error: "no population data in export" });
  return json({ ok: true, realm: body.realm, sourceGame, samples: rows.length,
    characters: characterRows.length, observations: observationRows.length });
}

// Aggregate every stored sample for a realm into class/race totals + demand.
async function loadPop(env, game) {
  const { results } = await env.DB.prepare(
    "SELECT t,faction,observed,total,classes,races FROM pop_samples WHERE game=? ORDER BY t"
  ).bind(game).all();
  const classes = {}, races = {};
  let observed = 0, samples = 0, lastT = 0, faction = null;
  for (const r of results) {
    samples++;
    observed += r.observed || 0;
    if ((r.t || 0) > lastT) { lastT = r.t; faction = r.faction || faction; }
    const c = safeParse(r.classes), rc = safeParse(r.races);
    for (const k in c) classes[k] = (classes[k] || 0) + c[k];
    for (const k in rc) races[k] = (races[k] || 0) + rc[k];
  }
  return { classes, races, observed, samples, lastT, faction };
}

// Per-day-unique population from identity data: each character counts once per
// day, no matter how many /who scans ran that day. Empty for older aggregate-
// only realms, where callers fall back to loadPop's per-sample totals.
async function loadPopUnique(env, game) {
  const rows = (await env.DB.prepare(
    `SELECT c.class_file cf, c.race race FROM character_observations o
     JOIN characters c ON c.game = o.game AND c.source_game = o.source_game AND c.character_key = o.character_key
     WHERE o.game = ?`
  ).bind(game).all()).results;
  const classes = {}, races = {};
  for (const r of rows) {
    if (r.cf) classes[r.cf] = (classes[r.cf] || 0) + 1;
    if (r.race) races[r.race] = (races[r.race] || 0) + 1;
  }
  return { sightings: rows.length, classes, races };
}

async function loadCharacterStats(env, game) {
  const today = Math.floor(Date.now() / 86400000);
  const weekStart = today - 6, monthStart = today - 29;
  const lifetime = await env.DB.prepare(
    "SELECT COUNT(*) n FROM characters WHERE game=?"
  ).bind(game).first();
  const windows = await env.DB.prepare(`SELECT
    COUNT(DISTINCT CASE WHEN day=? THEN character_key END) today,
    COUNT(DISTINCT CASE WHEN day>=? THEN character_key END) week,
    COUNT(DISTINCT CASE WHEN day>=? THEN character_key END) month
    FROM character_observations WHERE game=? AND day>=?`
  ).bind(today, weekStart, monthStart, game, monthStart).first();
  const cohorts = await env.DB.prepare(`SELECT
    COUNT(DISTINCT CASE WHEN o.day>=? AND c.first_seen>=? THEN o.character_key END) new_week,
    COUNT(DISTINCT CASE WHEN o.day>=? AND c.first_seen<? THEN o.character_key END) returning_week
    FROM character_observations o JOIN characters c
      ON c.game=o.game AND c.source_game=o.source_game AND c.character_key=o.character_key
    WHERE o.game=? AND o.day>=?`
  ).bind(weekStart, weekStart * 86400, weekStart, weekStart * 86400, game, weekStart).first();
  const active = await env.DB.prepare(`SELECT COUNT(*) n FROM (
    SELECT character_key FROM character_observations WHERE game=? AND day>=?
    GROUP BY character_key HAVING COUNT(DISTINCT day)>=3)`
  ).bind(game, monthStart).first();
  const activity = (await env.DB.prepare(`SELECT c.full_name,c.class,c.class_file,c.level,c.zone,
    c.first_seen,c.last_seen,c.seen_count,COUNT(DISTINCT o.day) seen_days,SUM(o.sightings) window_sightings
    FROM character_observations o JOIN characters c
      ON c.game=o.game AND c.source_game=o.source_game AND c.character_key=o.character_key
    WHERE o.game=? AND o.day>=? GROUP BY o.source_game,o.character_key
    ORDER BY seen_days DESC,window_sightings DESC,c.last_seen DESC LIMIT 50`
  ).bind(game, monthStart).all()).results;
  const week = (windows && windows.week) || 0;
  const returning = (cohorts && cohorts.returning_week) || 0;
  return {
    lifetime: (lifetime && lifetime.n) || 0,
    today: (windows && windows.today) || 0,
    week, month: (windows && windows.month) || 0,
    newWeek: (cohorts && cohorts.new_week) || 0,
    returningWeek: returning, active3: (active && active.n) || 0,
    returningRate: week ? Math.round(returning / week * 100) : 0,
    activity,
  };
}

async function apiPopulation(url, env) {
  const game = pickGame(url);
  const p = await loadPop(env, game);
  const characters = await loadCharacterStats(env, game);
  const uniq = await loadPopUnique(env, game);
  const perDayUnique = uniq.sightings > 0;
  const classes = perDayUnique ? uniq.classes : p.classes;
  const races = perDayUnique ? uniq.races : p.races;
  const sightings = perDayUnique ? uniq.sightings : p.observed;
  return json({
    game, realm: game.indexOf("realm:") === 0 ? game.slice(6) : game,
    faction: p.faction, samples: p.samples, sightings, observed: sightings, perDayUnique, updatedAt: p.lastT,
    classes, races, demand: popDemand(classes), characters,
  }, 300);
}

function popMeter(pct) {
  const on = Math.round(pct / 10);
  let h = "";
  for (let i = 0; i < 10; i++) h += '<i class="' + (i < on ? (pct >= 70 ? "hi" : "on") : "") + '"></i>';
  return '<span class="meter">' + h + "</span>";
}

// Render one distribution as a sorted table inside a panel.
function distPanel(title, dist, colorFor, nameFor) {
  const total = Object.values(dist).reduce((a, b) => a + b, 0);
  const keys = Object.keys(dist).sort((a, b) => dist[b] - dist[a] || String(a).localeCompare(b));
  const body = keys.map((k) => {
    const n = dist[k], pct = total > 0 ? (n / total) * 100 : 0;
    const color = colorFor ? colorFor(k) : null;
    const label = nameFor ? nameFor(k) : k;
    return "<tr><td class=\"l\"" + (color ? ' style="color:#' + color + '"' : "") + ">" + esc(label) +
      "</td><td>" + n + "</td><td>" + pct.toFixed(0) + "%</td><td class=\"l\">" + popMeter(pct) + "</td></tr>";
  }).join("");
  return '<div class="panel" style="margin-bottom:22px"><div class="ptitle">' + esc(title) +
    '</div><table class="poptable"><thead><tr><th class="l">' + (title.indexOf("Race") === 0 ? "Race" : title.indexOf("Class") === 0 ? "Class" : "Profession") +
    '</th><th>Seen</th><th>Share</th><th class="l">&nbsp;</th></tr></thead><tbody>' +
    (body || '<tr><td class="l mu" colspan="4" style="padding:16px">No data.</td></tr>') + "</tbody></table></div>";
}

function splitRealm(label) {
  const cut = label.lastIndexOf("-");
  return cut > 0 ? { name: label.slice(0, cut), fac: label.slice(cut + 1) } : { name: label, fac: "" };
}

// Landing screen for /pop with no realm: pick a realm (grouped by client) to
// view its population survey.
async function popChooserPage(env) {
  const rows = (await env.DB.prepare(
    `SELECT p.game g, COUNT(*) samples, SUM(p.observed) obs, MAX(p.t) lastT, d.source_game src
     FROM pop_samples p LEFT JOIN datasets d ON d.game = p.game
     GROUP BY p.game ORDER BY obs DESC`
  ).all()).results;

  const order = ["classic-progression", "classic", "retail"];
  const groups = new Map();
  for (const r of rows) {
    const src = GAMES[r.src] ? r.src : "other";
    if (!groups.has(src)) groups.set(src, []);
    groups.get(src).push(r);
  }
  const groupKeys = [...groups.keys()].sort((a, b) => ((order.indexOf(a) + 1) || 99) - ((order.indexOf(b) + 1) || 99));

  let body = groupKeys.map((src) => {
    const label = GAMES[src] ? GAMES[src].label : "Other realms";
    const btns = groups.get(src).map((r) => {
      const realm = r.g.slice(6);
      const { name, fac } = splitRealm(realm);
      const href = "/pop?game=realm:" + encodeURIComponent(realm);
      const updated = r.lastT ? new Date(r.lastT * 1000).toISOString().slice(0, 10) : "";
      return `<a class="game" href="${href}" title="${esc(r.obs || 0)} sightings${updated ? " · updated " + updated : ""}">` +
        `${esc(name)}${fac ? ' <span class="fac">' + esc(fac) + "</span>" : ""}</a>`;
    }).join("");
    return `<div class="ptitle" style="margin:24px 0 12px">${esc(label)}</div><nav class="games">${btns}</nav>`;
  }).join("");
  if (!rows.length)
    body = '<div class="panel"><p class="hint">No population data uploaded yet. In game, open the Population tab and press Scan Population (or /ml who), then run the uploader.</p></div>';

  const html = `<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Population survey — MarketLens</title>
<meta name="description" content="Choose a realm to view its observed population survey (class and race distribution sampled via /who).">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Press+Start+2P&family=VT323&display=swap">
<link rel="stylesheet" href="/style.css">
</head><body>
<div class="crt" aria-hidden="true"></div>
<div class="wrap">
  <a class="back" href="/">&#9664; MARKETLENS</a>
  <header class="ihead">
    <h1 class="iname">Population Survey</h1>
    <div class="itag">Choose a realm &middot; observed via /who</div>
  </header>
  ${body}
  <p class="src">
    A /who returns a sample of currently-visible online players (server-capped ~50), not a census.<br>
    Companion to the MarketLens addon. Realms appear here once population samples are uploaded.
  </p>
</div>
</body></html>`;
  return new Response(html, { headers: { "content-type": "text/html; charset=utf-8", "cache-control": "public, max-age=300" } });
}

async function popPage(url, env) {
  const game = pickGame(url);
  if (game.indexOf("realm:") !== 0)
    return await popChooserPage(env);
  const realm = game.slice(6);
  const p = await loadPop(env, game);
  const characters = await loadCharacterStats(env, game);
  const uniq = await loadPopUnique(env, game);
  // Count each character once per day when identity data exists; older aggregate
  // realms fall back to the raw per-sample sightings.
  const perDay = uniq.sightings > 0;
  const classes = perDay ? uniq.classes : p.classes;
  const races = perDay ? uniq.races : p.races;
  const sightings = perDay ? uniq.sightings : p.observed;
  // Link back to this realm's market screener. Retail market lives under the
  // faction-agnostic key, so fall back to the stripped realm if the exact key
  // has no items. If neither has items, go home.
  let marketGame = game;
  let hasItems = await env.DB.prepare("SELECT 1 FROM items WHERE game=? LIMIT 1").bind(game).first();
  if (!hasItems) {
    const alt = "realm:" + stripFaction(realm);
    if (alt !== game && await env.DB.prepare("SELECT 1 FROM items WHERE game=? LIMIT 1").bind(alt).first()) {
      marketGame = alt; hasItems = true;
    }
  }
  const backHref = hasItems ? gameHref("/?game=", marketGame) : "/";
  const backLabel = hasItems ? esc(marketGame.slice(6)) + " MARKET" : "MARKETLENS";

  const scanLabel = p.samples + " /who scan" + (p.samples === 1 ? "" : "s");
  const tiles =
    tile("Realm", realm, p.faction || "") +
    tile("Sightings", sightings.toLocaleString(), perDay ? "unique per day · " + scanLabel : "across " + scanLabel) +
    tile("Unique · 7 days", characters.week.toLocaleString(), characters.returningWeek + " returning · " + characters.newWeek + " new") +
    tile("Unique · 30 days", characters.month.toLocaleString(), characters.active3 + " seen on 3+ days") +
    tile("Updated", p.lastT ? new Date(p.lastT * 1000).toISOString().slice(0, 10) : "—", "last sample");

  const classPanel = distPanel("Class distribution", classes,
    (k) => (CLASS_META[k] ? CLASS_META[k][1] : null),
    (k) => (CLASS_META[k] ? CLASS_META[k][0] : k));
  const racePanel = distPanel("Race distribution", races, null, null);

  const demand = popDemand(classes);
  const demandBody = demand.map((d) =>
    '<tr><td class="l">' + esc(d.prof) + '</td><td>' + d.score + '</td><td class="l">' + popMeter(d.score) + "</td></tr>"
  ).join("");
  const demandPanel = '<div class="panel" style="margin-bottom:22px"><div class="ptitle">Inferred profession demand</div>' +
    '<table class="poptable"><thead><tr><th class="l">Profession</th><th>Score</th><th class="l">&nbsp;</th></tr></thead><tbody>' +
    (demandBody || '<tr><td class="l mu" colspan="3" style="padding:16px">No data.</td></tr>') + "</tbody></table>" +
    '<p class="hint">Inferred from the observed class mix &mdash; a demand hint, not observed sales.</p></div>';

  const activityBody = characters.activity.map((c) =>
    '<tr><td class="l">' + esc(c.full_name) + '</td><td>' + c.seen_days + '</td><td>' + c.window_sightings +
    '</td><td class="l">' + esc((c.class || "") + (c.level ? " · level " + c.level : "") + (c.zone ? " · " + c.zone : "")) + '</td></tr>'
  ).join("");
  const identityPanel = '<div class="panel" style="margin-bottom:22px"><div class="ptitle">Unique &amp; returning characters</div>' +
    '<table class="poptable"><thead><tr><th class="l">Character</th><th>Days</th><th>Sightings</th><th class="l">Last profile</th></tr></thead><tbody>' +
    (activityBody || '<tr><td class="l mu" colspan="4" style="padding:16px">Identity tracking begins with the next scan made by an identity-enabled addon.</td></tr>') +
    '</tbody></table><p class="hint">' + characters.lifetime.toLocaleString() + ' lifetime unique characters · ' +
    characters.returningRate + '% 7-day returning-character rate. Names identify characters, not people or Battle.net accounts.</p></div>';

  const empty = p.samples === 0;
  const html = `<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(realm)} population — MarketLens</title>
<meta name="description" content="Observed population survey for ${esc(realm)}: class and race distribution sampled via /who.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Press+Start+2P&family=VT323&display=swap">
<link rel="stylesheet" href="/style.css">
<style>
  table.poptable{min-width:0}
  table.poptable td,table.poptable th{white-space:nowrap}
  table.poptable td .meter{margin:0}
  table.poptable td.l .meter i{height:14px}
</style>
</head><body>
<div class="crt" aria-hidden="true"></div>
<div class="wrap">
  <a class="back" href="${backHref}">&#9664; ${backLabel}</a>
  <header class="ihead">
    <h1 class="iname">${esc(realm)} &mdash; Observed Population</h1>
    <div class="itag">${esc(p.faction || "")} &middot; ${sightings.toLocaleString()} sightings &middot; ${p.samples} scan${p.samples === 1 ? "" : "s"} &middot; sampled via /who</div>
  </header>
  <section class="tiles">${tiles}</section>
  ${empty ? '<div class="panel"><p class="hint">No population samples uploaded yet. In game, open the Population tab and press Scan Population (or /ml who), then upload.</p></div>'
    : identityPanel + classPanel + racePanel + demandPanel}
  <p class="src">
    A /who returns a sample of currently-visible online players (server-capped ~50), not a census.<br>
    ${perDay ? "Each character is counted once per day, however many scans ran that day" : "Tables count raw sightings across all scans"}. Unique metrics use normalized character name + realm + game flavor.<br>
    Characters are not human players/accounts; a rename appears as a new character. Companion to the MarketLens addon.
  </p>
</div>
</body></html>`;
  return new Response(html, { headers: { "content-type": "text/html; charset=utf-8", "cache-control": "public, max-age=300" } });

  function tile(k, v, s) {
    return '<div class="tile"><div class="k">' + esc(k) + '</div><div class="v">' + esc(v) + '</div><div class="s">' + esc(s || "") + "</div></div>";
  }
}

export default {
  async fetch(req, env, ctx) {
    const url = new URL(req.url);
    const p = url.pathname;
    try {
      if (p === "/api/items") return await apiItems(url, env, ctx);
      if (p === "/api/history") return await apiHistory(url, env);
      if (p === "/api/games") return await apiGames(env);
      if (p === "/api/population") return await apiPopulation(url, env);
      if (p === "/admin/import-realm" && req.method === "POST") return await importRealm(url, env, req);
      if (p === "/admin/import-pop" && req.method === "POST") return await importPop(url, env, req);
      if (p === "/pop") return await popPage(url, env);
      if (p.startsWith("/item/")) return await itemPage(url, env);
      if (p === "/admin/refresh") {
        if (!env.REFRESH_TOKEN || url.searchParams.get("token") !== env.REFRESH_TOKEN)
          return new Response("forbidden", { status: 403 });
        const g = url.searchParams.get("game");
        const out = g ? [await collectGame(env, g)] : await collectAll(env);
        return json({ ok: true, out });
      }
      return env.ASSETS.fetch(req);
    } catch (e) {
      return new Response("error: " + (e && e.stack || e), { status: 500 });
    }
  },
  async scheduled(event, env, ctx) {
    ctx.waitUntil(collectAll(env));
  },
};
