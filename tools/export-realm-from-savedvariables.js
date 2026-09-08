#!/usr/bin/env node

// Rebuild an ml-realm-v1 payload from the authoritative SavedVariables table.
// This is intentionally dependency-free and only accepts WoW's serialized Lua
// table subset (tables, keyed fields, strings, numbers, booleans, and nil).

const fs = require("node:fs");

const input = process.argv[2];
if (!input) throw new Error("usage: node export-realm-from-savedvariables.js <MarketLens.lua>");
const source = fs.readFileSync(input, "utf8");
let p = source.indexOf("{");
if (p < 0) throw new Error("MarketLensDB table not found");

function ws() {
  while (p < source.length) {
    if (/\s/.test(source[p])) { p++; continue; }
    if (source.startsWith("--", p)) {
      p = source.indexOf("\n", p + 2);
      if (p < 0) p = source.length;
      continue;
    }
    break;
  }
}

function string() {
  if (source[p++] !== '"') throw new Error(`expected string at ${p - 1}`);
  let out = "";
  while (p < source.length) {
    const c = source[p++];
    if (c === '"') return out;
    if (c !== "\\") { out += c; continue; }
    const e = source[p++];
    const escapes = { a: "\x07", b: "\b", f: "\f", n: "\n", r: "\r", t: "\t", v: "\v" };
    if (escapes[e] !== undefined) out += escapes[e];
    else if (/\d/.test(e)) {
      let digits = e;
      while (digits.length < 3 && /\d/.test(source[p] || "")) digits += source[p++];
      out += String.fromCharCode(Number(digits));
    } else out += e;
  }
  throw new Error("unterminated string");
}

function atom() {
  const start = p;
  while (p < source.length && /[A-Za-z0-9_.+\-]/.test(source[p])) p++;
  const raw = source.slice(start, p);
  if (raw === "true") return true;
  if (raw === "false") return false;
  if (raw === "nil") return null;
  const n = Number(raw);
  if (raw && Number.isFinite(n)) return n;
  if (raw) return raw;
  throw new Error(`unexpected token at ${p}: ${source.slice(p, p + 20)}`);
}

function value() {
  ws();
  if (source[p] === "{") return table();
  if (source[p] === '"') return string();
  return atom();
}

function table() {
  if (source[p++] !== "{") throw new Error(`expected table at ${p - 1}`);
  const out = {};
  let next = 1;
  while (true) {
    ws();
    if (source[p] === "}") { p++; return out; }
    let key;
    if (source[p] === "[") {
      p++; key = value(); ws();
      if (source[p++] !== "]") throw new Error(`expected ] at ${p - 1}`);
      ws();
      if (source[p++] !== "=") throw new Error(`expected = at ${p - 1}`);
      out[String(key)] = value();
    } else if (source[p] === "{" || source[p] === '"') {
      out[String(next++)] = value();
    } else {
      const mark = p;
      const candidate = atom();
      ws();
      if (source[p] === "=") {
        p++; out[String(candidate)] = value();
      } else {
        p = mark; out[String(next++)] = value();
      }
    }
    ws();
    if (source[p] === "," || source[p] === ";") p++;
  }
}

const db = value();
const realms = db.realms || {};
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

const items = {};
for (const [id, rec] of Object.entries(realm.items || {})) {
  const snaps = Object.keys(rec.snaps || {}).sort((a, b) => Number(a) - Number(b)).map((k) => {
    const s = rec.snaps[k];
    return [s.t || 0, s.q || 0, s.a || 0, s.s || 0, s.l || 0, s.m || 0, s.w || 0, s.tc || 0];
  });
  // rec.class is the addon's { profession, sector, market } classification,
  // computed from the real item class/subclass/equip-slot at scan time. Carry
  // the market so the site can categorize properly instead of guessing by name.
  const market = rec.class && rec.class.market;
  if (snaps.length) items[id] = { n: rec.name || "", s: snaps, m: market || "" };
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
  // ml-sellers-v1: one entry per seller with their current listings and a small
  // rolling summary history. Item names are resolved site-side from the realm
  // items table, so listings carry only [itemID, quantity, lowestUnitPrice].
  const sellers = [];
  for (const [owner, rec] of Object.entries(realm.sellers || {})) {
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
    exportedAt: Math.floor(Date.now() / 1000), sellers,
  }));
} else if (process.argv.includes("--population")) {
  const flavorArg = process.argv.find((a) => a.startsWith("--flavor="));
  const flavor = flavorArg ? flavorArg.slice(9) : "classic-progression";
  if (!["classic", "classic-progression", "retail"].includes(flavor))
    throw new Error(`unknown flavor: ${flavor}`);
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
      k: key, fn: c.fullName || c.name || key, n: c.name || "", r: c.realm || "",
      g: c.guild || "", l: c.level || 0, race: c.race || "", class: c.class || "",
      cf: c.classFile || "", z: c.zone || "", fs: c.firstSeen || 0,
      ls: c.lastSeen || 0, sc: c.seenCount || 0,
    });
    for (const [day, d] of Object.entries(c.days || {})) {
      observations.push([key, Number(day), d.count || 0, d.firstSeen || 0, d.lastSeen || 0]);
    }
  }
  if (!samples.length && !characters.length) throw new Error("population export contains zero data");
  process.stdout.write(JSON.stringify({
    type: "ml-pop-v2", realm: realmName, flavor,
    exportedAt: Math.floor(Date.now() / 1000), samples, characters, observations,
  }));
} else {
  if (!Object.keys(items).length) throw new Error("realm export contains zero items");
  process.stdout.write(JSON.stringify(payload));
}
