#!/usr/bin/env node

// Rebuild an ml-realm-v1 payload from the authoritative SavedVariables table.
// This is intentionally dependency-free and only accepts WoW's serialized Lua
// table subset (tables, keyed fields, strings, numbers, booleans, and nil).

const fs = require("node:fs");

const input = process.argv[2];
if (!input) throw new Error("usage: node export-realm-from-savedvariables.js <MarketLens.lua>");
const { readSavedVariables } = require("./savedvariables.js");
const db = readSavedVariables(input);
const realms = db.realms || {};
if (process.argv.includes("--list-realms")) {
  process.stdout.write(JSON.stringify(Object.keys(realms)));
  process.exit(0);
}
const realmArg = process.argv.find((a) => a.startsWith("--realm="));
const wanted = realmArg ? realmArg.slice(8) : null;
if (wanted && !realms[wanted])
  throw new Error(`realm "${wanted}" not found in SavedVariables (have: ${Object.keys(realms).join(", ")})`);
const realmName = wanted
  || (db.exportRealm && realms[db.exportRealm]
    ? db.exportRealm
    : Object.keys(realms).sort((a, b) => (realms[b].lastScan || 0) - (realms[a].lastScan || 0))[0]);
const realm = realms[realmName];
if (!realm) throw new Error("no realm data found");

function normalizeFlavor(flavor) {
  const f = String(flavor || "").toLowerCase();
  if (["tbc-anniversary", "anniversary", "tbc", "classic-progression"].includes(f))
    return "tbc-anniversary";
  if (["classic-era", "era", "classic"].includes(f)) return "classic-era";
  if (f === "retail") return "retail";
  if (["classic-beta", "forever", "classicbeta"].includes(f)) return "classic-beta";
  if (["mop-classic", "mists-classic", "mop", "mists"].includes(f)) return "mop-classic";
  if (["sod", "season-of-discovery"].includes(f)) return "sod";
  throw new Error(`unknown flavor: ${flavor}`);
}

// Crafted-item source map (itemID -> { source, profession, spellID }), generated
// from DB2 by build-item-sources.js. Optional: if it isn't present we fall back
// to whatever the addon stored on rec.class. Keying source off the itemID here
// means even scans taken before the addon knew about source get enriched.
let sourceMap = {};
try { sourceMap = require("./item-sources.json"); } catch (e) { /* not generated yet */ }

// The addon stores each realm's item history as one packed string
// (Analytics/Snapshots.lua, Snap:Pack): "ML1\n" then per item
// id \t name \t link \t market \t source \t crafter \t t,q,a,s,l,m,w,tc[,...].
// Expand it to the table shape older saves used; table entries win.
function unpackItems(packed, into) {
  if (typeof packed !== "string" || !packed.startsWith("ML1\n")) return into;
  const keys = ["t", "q", "a", "s", "l", "m", "w", "tc"];
  for (const line of packed.slice(4).split("\n")) {
    const f = line.split("\t");
    if (f.length < 7 || into[f[0]]) continue;
    const nums = f[6].split(",").map((v) => Number(v) || 0);
    const snaps = {};
    for (let i = 0; i + keys.length <= nums.length; i += keys.length) {
      const s = {};
      keys.forEach((k, j) => { s[k] = nums[i + j]; });
      snaps[String(i / keys.length + 1)] = s;
    }
    into[f[0]] = { name: f[1], link: f[2], class: { market: f[3], source: f[4], crafter: f[5] }, snaps };
  }
  return into;
}

const items = {};
for (const [id, rec] of Object.entries(unpackItems(realm.itemsPacked, { ...(realm.items || {}) }))) {
  const rawSnaps = Object.keys(rec.snaps || {}).sort((a, b) => Number(a) - Number(b)).map((k) => {
    const s = rec.snaps[k];
    return [s.t || 0, s.q || 0, s.a || 0, s.s || 0, s.l || 0, s.m || 0, s.w || 0, s.tc || 0];
  });
  // A paged seller scan is useful for owner profiles, but pagination observes a
  // moving auction house and is not an authoritative per-item market snapshot.
  // Do not use the latest seller-scan point as the previous quantity for Get All
  // percentage changes. Keep it only when it is the sole observation available.
  const sellerT = Number(realm.lastSellerSample) || 0;
  const marketSnaps = sellerT ? rawSnaps.filter((s) => Number(s[0]) !== sellerT) : rawSnaps;
  const snaps = marketSnaps.length ? marketSnaps : rawSnaps;
  // rec.class is the addon's { profession, sector, market, source, crafter }
  // classification, computed from the real item class/subclass/equip-slot at
  // scan time. Carry the market so the site categorizes properly instead of
  // guessing by name, plus the source axis (crafted/gathered/...) and the
  // producing profession -- itemID lookup takes priority so old scans enrich too.
  const cls = rec.class || {};
  const mapped = sourceMap[id];
  const source = (mapped && mapped.source) || cls.source || "";
  const crafter = (mapped && mapped.profession) || cls.crafter || "";
  if (snaps.length) {
    items[id] = { n: rec.name || "", s: snaps, m: cls.market || "", src: source, cr: crafter };
  }
}

const payload = {
  type: "ml-realm-v1",
  realm: realmName,
  exportedAt: Math.floor(Date.now() / 1000),
  capabilities: {
    auctions: realm.auctionsAvailable !== false,
    sellers: realm.ownersAvailable === true,
    priceDistribution: realm.priceDistributionAvailable !== false,
  },
  items,
};

if (process.argv.includes("--sellers")) {
  // ml-sellers-v1: one entry per seller observed in the latest seller scan/sample
  // with their sampled listings and a small rolling summary history. Item names
  // are resolved site-side from the realm items table, so listings carry only
  // [itemID, quantity, lowestUnitPrice].
  const latestSellerScanID = realm.lastSellerScanID || (realm.lastSellerScanStats && realm.lastSellerScanStats.id) || null;
  const stats = realm.lastSellerScanStats || {};
  const meta = {
    partial: stats.partial === true || realm.sellerSamplePartial === true,
    rows: stats.rows || realm.sellerSampleRows || 0,
    pages: stats.pages || 0,
    scannedPages: stats.scannedPages || 0,
    samplePages: stats.samplePages || 0,
    ownerCoverage: stats.ownerCoverage || 0,
    elapsed: stats.elapsed || 0,
    projectedFullSeconds: stats.projectedFullSeconds || 0,
  };
  const sellers = [];
  for (const [owner, rec] of Object.entries(realm.sellers || {})) {
    if (latestSellerScanID && rec.lastSellerScanID !== latestSellerScanID) continue;
    const L = [];
    for (const [id, li] of Object.entries(rec.listings || {})) {
      const itemId = Number(id);
      if (!itemId) continue;
      L.push([itemId, li.q || 0, li.l || 0]);
    }
    if (!L.length) continue;
    const h = Object.keys(rec.hist || {})
      .sort((a, b) => Number(a) - Number(b))
      .map((k) => {
        const p = rec.hist[k];
        return [p.t || 0, p.items || 0, p.qty || 0, p.value || 0];
      });
    sellers.push({
      o: rec.name || owner, fs: rec.firstSeen || 0, ls: rec.lastSeen || 0,
      sc: rec.seenCount || 0, L, h,
    });
  }
  if (!sellers.length) {
    // Routine, not a crash: no realm.sellers yet (a Get-All scan, or a paged scan
    // run before the seller-capture code loaded). Exit quietly so the uploader
    // just skips the seller step instead of dumping a stack trace.
    process.stderr.write("no seller data (run /ml scan paged after loading the current addon)\n");
    process.exit(1);
  }
  process.stdout.write(JSON.stringify({
    type: "ml-sellers-v1", realm: realmName,
    exportedAt: Math.floor(Date.now() / 1000), meta, sellers,
  }));
} else if (process.argv.includes("--population")) {
  const flavorArg = process.argv.find((a) => a.startsWith("--flavor="));
  const flavor = normalizeFlavor(flavorArg ? flavorArg.slice(9) : "tbc-anniversary");
  const samples = Object.keys((realm.population && realm.population.samples) || {})
    .sort((a, b) => Number(a) - Number(b))
    .map((key) => {
      const s = realm.population.samples[key];
      return {
        t: s.t || 0, f: s.faction || "", o: s.observed || 0,
        tot: s.total || s.observed || 0, flt: s.filter || "",
        c: s.classes || {}, r: s.races || {},
      };
    });
  const characters = [];
  const observations = [];
  for (const [key, c] of Object.entries((realm.population && realm.population.characters) || {})) {
    characters.push({
      k: key, fn: c.fullName || c.name || key, n: c.name || "",
      first: c.firstName || "", last: c.lastName || "", r: c.realm || "",
      g: c.guild || "", l: c.level || 0, race: c.race || "", class: c.class || "",
      cf: c.classFile || "", z: c.zone || "", fs: c.firstSeen || 0,
      ls: c.lastSeen || 0, sc: c.seenCount || 0,
    });
    for (const [day, d] of Object.entries(c.days || {})) {
      observations.push([
        key, Number(day), d.count || d[1] || 0,
        d.firstSeen || d[2] || 0, d.lastSeen || d[3] || 0,
      ]);
    }
  }
  const sweeps = [];
  const sweepQueries = [];
  const locationObservations = [];
  for (const [id, sweep] of Object.entries((realm.population && realm.population.sweeps) || {})) {
    const queries = Object.keys(sweep.queries || {}).sort((a, b) => Number(a) - Number(b)).map((k) => sweep.queries[k]);
    let cappedCount = 0;
    queries.forEach((q, i) => {
      // A census splits a capped query into children that cover it exactly
      // (q.split); only caps left uncovered are real coverage gaps.
      if (q.capped === true && q.split !== true) cappedCount++;
      sweepQueries.push([
        id, q.index || i + 1, q.t || 0, q.filter || "",
        q.observed || 0, q.total || q.observed || 0, q.capped === true,
      ]);
    });
    const observed = Object.entries(sweep.observations || {});
    for (const [key, o] of observed) {
      locationObservations.push([
        id, key, o.queryIndex || o[1] || 0, o.observedAt || o[2] || 0,
        o.zone || o[3] || "", o.level || o[4] || 0,
        o.classFile || o[5] || "", o.race || o[6] || "",
      ]);
    }
    sweeps.push({
      id, startedAt: sweep.startedAt || 0, completedAt: sweep.completedAt || 0,
      status: sweep.status || "partial", label: sweep.label || "", faction: sweep.faction || "",
      queryCount: queries.length, characterCount: observed.length, cappedCount,
    });
  }
  if (!samples.length && !characters.length) throw new Error("population export contains zero data");
  process.stdout.write(JSON.stringify({
    type: "ml-pop-v3", realm: realmName, flavor,
    exportedAt: Math.floor(Date.now() / 1000), samples, characters, observations,
    sweeps, sweepQueries, locationObservations,
  }));
} else {
  if (!Object.keys(items).length) throw new Error("realm export contains zero items");
  process.stdout.write(JSON.stringify(payload));
}
