#!/usr/bin/env node
// Print the per-row bulk scans a MarketLens.lua holds (realm.rowScans, written
// by MarketLens/Analytics/ScanRows.lua) as JSON:
//   {"<realm-faction>": [{"t":<unix>,"mode":"getAll"|"replicate","rows":N,
//                         "items":{"<itemID>":[[stack,buyout,bucket,n],...]}}]}
//
//   node tools/export-scan-rows.js <MarketLens.lua> [--realm=<realm-faction>]
"use strict";
const { readSavedVariables } = require("./savedvariables.js");

const HEADER = "MLR1\n";

function decode(data) {
  const items = {};
  if (typeof data !== "string") return null;
  // A line break inside a saved string can come back as CRLF on Windows.
  data = data.replace(/\r\n/g, "\n");
  if (!data.startsWith(HEADER)) return null;
  for (const line of data.slice(HEADER.length).split("\n")) {
    if (!line) continue;
    const tab = line.indexOf("\t");
    const id = Number(line.slice(0, tab));
    if (!id) continue;
    const rows = [];
    for (const tuple of line.slice(tab + 1).split(",")) {
      const [row, times] = tuple.split("*");
      const [stack, buyout, bucket] = row.split(":").map(Number);
      if (!(stack > 0) || !(buyout > 0)) continue;
      rows.push([stack, buyout, bucket || 0, Number(times) || 1]);
    }
    if (rows.length) items[id] = rows;
  }
  return items;
}

const file = process.argv[2];
if (!file) {
  console.error("usage: node tools/export-scan-rows.js <MarketLens.lua> [--realm=<realm-faction>]");
  process.exit(2);
}
const only = (process.argv.find((a) => a.startsWith("--realm=")) || "").slice(8) || null;
const db = readSavedVariables(file);
const out = {};
for (const [bucket, realm] of Object.entries(db.realms || {})) {
  if (only && bucket !== only) continue;
  const scans = [];
  for (const scan of Object.values(realm.rowScans || {})) {
    const items = decode(scan && scan.data);
    if (!items || !scan.t) continue;
    scans.push({ t: scan.t, mode: scan.mode || "getAll", rows: scan.rows || 0, items });
  }
  if (scans.length) out[bucket] = scans.sort((a, b) => a.t - b.t);
}
process.stdout.write(JSON.stringify(out));
