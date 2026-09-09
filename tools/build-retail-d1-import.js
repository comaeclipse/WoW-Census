#!/usr/bin/env node

// Build a validated, transactional D1 import for the current Retail regional
// CSV plus one MarketLens SavedVariables realm snapshot.

const fs = require("node:fs");
const { execFileSync } = require("node:child_process");

const [savedVariables, output] = process.argv.slice(2);
if (!savedVariables || !output) {
  throw new Error("usage: node build-retail-d1-import.js <MarketLens.lua> <output.sql>");
}

function parseLine(line) {
  const out = [];
  let cur = "", quoted = false;
  for (let i = 0; i < line.length; i++) {
    const c = line[i];
    if (c === '"') {
      if (quoted && line[i + 1] === '"') { cur += '"'; i++; }
      else quoted = !quoted;
    } else if (c === "," && !quoted) { out.push(cur); cur = ""; }
    else cur += c;
  }
  out.push(cur);
  return out;
}

function slugify(name) {
  return name.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
}

function sql(v) {
  if (v === null || v === undefined) return "NULL";
  if (typeof v === "number") return Number.isFinite(v) ? String(v) : "NULL";
  return "'" + String(v).replace(/'/g, "''") + "'";
}

function inserts(table, columns, rows, size) {
  const statements = [];
  for (let i = 0; i < rows.length; i += size) {
    const values = rows.slice(i, i + size).map((r) => "(" + r.map(sql).join(",") + ")").join(",");
    statements.push(`INSERT OR REPLACE INTO ${table} (${columns.join(",")}) VALUES ${values};`);
  }
  return statements;
}

async function main() {
  const exportText = execFileSync(process.execPath,
    [require.resolve("./export-realm-from-savedvariables.js"), savedVariables],
    { encoding: "utf8", maxBuffer: 64 * 1024 * 1024 });
  const realm = JSON.parse(exportText);
  const response = await fetch("https://public-data.tradeskillmaster.com/retail/us/region/items.csv");
  if (!response.ok) throw new Error(`TSM Retail CSV returned HTTP ${response.status}`);
  const csv = await response.text();
  const lines = csv.split(/\r?\n/);
  const region = new Map();
  const regionItems = [], regionHistory = [];
  const now = new Date().toISOString();
  const d = new Date();
  const day = d.getUTCFullYear() * 10000 + (d.getUTCMonth() + 1) * 100 + d.getUTCDate();

  for (let i = 1; i < lines.length; i++) {
    if (!lines[i]) continue;
    const f = parseLine(lines[i]);
    if (f.length < 8) continue;
    const id = Number.parseInt(f[0], 10);
    const name = f[1];
    const mv = Math.round(Number(f[2]) || 0), hist = Math.round(Number(f[3]) || 0);
    const asp = Math.round(Number(f[4]) || 0), sr = Number(f[5]) || 0, spd = Number(f[6]) || 0;
    if (!id || !(sr > 0 || spd > 0)) continue;
    const row = { id, name, mv, hist, asp, sr, spd };
    region.set(id, row);
    regionItems.push(["retail", id, name, slugify(name), mv, asp, sr, spd, hist, f[7] || "", null, null, null]);
    regionHistory.push(["retail", id, day, mv, asp, sr, spd, null]);
  }
  if (regionItems.length < 10000) throw new Error(`refusing suspicious Retail CSV with ${regionItems.length} usable rows`);

  const realmGame = "realm:" + realm.realm;
  const realmItems = [], realmHistory = [];
  for (const [idText, rec] of Object.entries(realm.items)) {
    const id = Number.parseInt(idText, 10);
    if (!id || !rec.s || !rec.s.length) continue;
    const rr = region.get(id) || {};
    const name = rec.n && rec.n.trim() ? rec.n : (rr.name || `item:${id}`);
    const last = rec.s[rec.s.length - 1];
    const q = last[1] || 0, w = last[6] || 0;
    const cat = rec.m && String(rec.m).trim() ? String(rec.m) : null;
    realmItems.push([realmGame, id, name, slugify(name), q * w, w, rr.sr || 0, rr.spd || 0,
      rr.asp || 0, now, q, null, cat]);
    for (const s of rec.s) {
      const sd = new Date((s[0] || 0) * 1000);
      const bucket = sd.getUTCFullYear() * 10000 + (sd.getUTCMonth() + 1) * 100 + sd.getUTCDate();
      realmHistory.push([realmGame, id, bucket, (s[1] || 0) * (s[6] || 0), s[6] || 0,
        rr.sr || 0, rr.spd || 0, s[1] || 0]);
    }
  }
  if (realmItems.length < 1000) throw new Error(`refusing suspicious realm export with ${realmItems.length} rows`);

  const statements = [
    "PRAGMA foreign_keys=ON;",
    "CREATE TABLE IF NOT EXISTS datasets (game TEXT PRIMARY KEY, source_game TEXT NOT NULL, updated_at TEXT, seller_updated_at TEXT, seller_meta TEXT);",
    "DELETE FROM items WHERE game='retail';",
    ...inserts("items", ["game","id","name","slug","mv","asp","sr","spd","hist","updated_at","q","sc","cat"], regionItems, 120),
    ...inserts("history", ["game","id","ts","mv","asp","sr","spd","q"], regionHistory, 150),
    `DELETE FROM items WHERE game=${sql(realmGame)};`,
    ...inserts("items", ["game","id","name","slug","mv","asp","sr","spd","hist","updated_at","q","sc","cat"], realmItems, 100),
    ...inserts("history", ["game","id","ts","mv","asp","sr","spd","q"], realmHistory, 120),
    `INSERT OR REPLACE INTO datasets (game,source_game,updated_at) VALUES (${sql(realmGame)},'retail',${sql(now)});`,
  ];
  fs.writeFileSync(output, statements.join("\n"), "utf8");
  process.stdout.write(JSON.stringify({
    tsmUpdatedAt: regionItems[0][9], regionItems: regionItems.length,
    realm: realm.realm, realmItems: realmItems.length, realmHistory: realmHistory.length,
    namedByAddon: Object.values(realm.items).filter((r) => r.n && r.n.trim()).length,
    output,
  }, null, 2));
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
