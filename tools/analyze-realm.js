#!/usr/bin/env node

// Summarize what upload-realm.ps1 is about to send for one realm bucket, so a
// scan can be sanity-checked before it goes to the site. Read-only: it shells
// out to export-realm-from-savedvariables.js for the same payloads the uploader
// builds and prints stats plus a "Flags" list of anything worth a second look.
//
//   node tools/analyze-realm.js <MarketLens.lua> [--realm=Realm-Faction] [--flavor=tbc-anniversary]

const fs = require("node:fs");
const path = require("node:path");
const { execFileSync } = require("node:child_process");

const args = process.argv.slice(2);
const input = args.find((a) => !a.startsWith("--"));
if (!input) {
  console.error("usage: node analyze-realm.js <MarketLens.lua> [--realm=Realm-Faction] [--flavor=tbc-anniversary]");
  process.exit(2);
}
const opt = (name) => { const a = args.find((x) => x.startsWith(`--${name}=`)); return a ? a.slice(name.length + 3) : null; };
const realmArg = opt("realm");
const flavor = opt("flavor") || "tbc-anniversary";
const helper = path.join(__dirname, "export-realm-from-savedvariables.js");

// Mirrors of site/src/worker.js -- keep in sync if those change.
const TBC_MAX_ITEM_ID = 41000;
const CURRENT_MAX_AGE_SECONDS = 48 * 60 * 60;

const now = Math.floor(Date.now() / 1000);
const flags = [];

const gold = (c) => (c / 10000).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + "g";
const iso = (t) => new Date(t * 1000).toISOString().replace("T", " ").slice(0, 16) + "Z";
function ago(t) {
  const h = (now - t) / 3600;
  return h < 48 ? `${h.toFixed(1)}h ago` : `${(h / 24).toFixed(1)}d ago`;
}
const top = (counts, n) => Object.entries(counts).sort((a, b) => b[1] - a[1]).slice(0, n).map(([k, v]) => `${k} ${v}`).join(", ");
const heading = (s) => console.log(`\n== ${s} ==`);

// Run the exporter with the given switches. Returns { data } or { error }; a
// non-zero exit is routine for sellers/population (no data captured yet).
function exportPayload(extra) {
  const a = [helper, input, ...(realmArg ? [`--realm=${realmArg}`] : []), ...extra];
  try {
    const out = execFileSync(process.execPath, a, { encoding: "utf8", maxBuffer: 1 << 30, stdio: ["ignore", "pipe", "pipe"] });
    return { data: JSON.parse(out) };
  } catch (e) {
    const msg = String((e.stderr || e.message || "")).trim().split("\n").filter((l) => !/^\s+at /.test(l)).slice(0, 2).join(" ");
    return { error: msg || "export failed" };
  }
}

const st = fs.statSync(input);
console.log(`File:  ${input}`);
console.log(`Saved: ${iso(Math.floor(st.mtimeMs / 1000))} (${ago(Math.floor(st.mtimeMs / 1000))}) -- WoW only writes this on /reload or logout`);

const itemsRes = exportPayload([]);
if (itemsRes.error) { console.error(`\nCould not build the item export: ${itemsRes.error}`); process.exit(1); }
const payload = itemsRes.data;
console.log(`Realm: ${payload.realm}   flavor: ${flavor}`);

if (!realmArg) {
  const list = exportPayload(["--list-realms"]);
  const others = Array.isArray(list.data) ? list.data.filter((r) => r !== payload.realm) : [];
  if (others.length)
    flags.push(`Save file also holds ${others.join(", ")}. No --realm given, so this is the active export (${payload.realm}); pass --realm= to pick another.`);
}

// ---- Items -----------------------------------------------------------------
heading("ITEMS");
const caps = payload.capabilities || {};
const rows = Object.entries(payload.items).map(([id, r]) => {
  const s = r.s[r.s.length - 1];
  // Snapshot layout is [t, q, a, s, l, m, w, tc]: time, quantity, auctions,
  // sellers, lowest, median, weighted-avg, top-seller %.
  return { id: +id, name: r.n || "", t: s[0], q: s[1], a: s[2], low: s[4], med: s[5], snaps: r.s };
});
const times = rows.map((r) => r.t).sort((a, b) => a - b);
const scanT = times[Math.floor(times.length / 2)] || 0; // median, as the site's import anchors on
const cutoff = scanT - CURRENT_MAX_AGE_SECONDS;
const cur = rows.filter((r) => r.t >= cutoff);
const stale = rows.length - cur.length;
const name = (r) => r.name || `item:${r.id}`;

console.log(`Scan time (median latest snapshot): ${iso(scanT)} (${ago(scanT)})`);
console.log(`Items in export: ${rows.length}  |  site will keep ${cur.length}, drop ${stale} older than 48h before the scan`);
console.log(`Auctions: ${cur.reduce((n, r) => n + r.a, 0).toLocaleString()}  |  units: ${cur.reduce((n, r) => n + r.q, 0).toLocaleString()}  |  listed value @median: ${gold(cur.reduce((n, r) => n + r.q * r.med, 0))}`);
console.log(`Capabilities: auctions=${caps.auctions} sellers=${caps.sellers} priceDistribution=${caps.priceDistribution}`);
const snapTimes = new Set(rows.flatMap((r) => r.snaps.map((s) => s[0])));
console.log(`History: ${snapTimes.size} distinct snapshot times, ${iso(Math.min(...snapTimes))} -> ${iso(Math.max(...snapTimes))}`);

const unnamed = rows.filter((r) => !r.name).length;
console.log(`Unnamed in export: ${unnamed} (the site fills most from the region table on import; check the post-upload count)`);

console.log("\nTop 10 by listed value (units x median):");
for (const r of [...cur].sort((a, b) => b.q * b.med - a.q * a.med).slice(0, 10))
  console.log(`  ${String(r.id).padEnd(7)}${name(r).padEnd(34)} ${String(r.q).padStart(6)}u  low ${gold(r.low).padStart(13)}  med ${gold(r.med).padStart(13)}`);

// Movers vs the newest snapshot at least 6h older than the current one, so a
// burst of back-to-back partial scans doesn't read as a price move.
const movers = [];
for (const r of cur) {
  if (r.med <= 0 || r.q < 5) continue;
  const prev = [...r.snaps].reverse().find((s) => s[0] <= r.t - 6 * 3600 && s[5] > 0);
  if (!prev || prev[5] * r.q < 1_000_000) continue; // ignore < 100g of listed value
  movers.push({ r, from: prev[5], pct: (r.med - prev[5]) / prev[5] * 100, days: (r.t - prev[0]) / 86400 });
}
const showMovers = (title, list) => {
  console.log(`\n${title}:`);
  if (!list.length) return console.log("  (none)");
  for (const m of list) console.log(`  ${name(m.r).padEnd(36)} ${gold(m.from).padStart(13)} -> ${gold(m.r.med).padStart(13)}  ${(m.pct > 0 ? "+" : "") + m.pct.toFixed(0)}% over ${m.days.toFixed(1)}d  (${m.r.q}u)`);
};
showMovers("Biggest median rises (>=100g listed value)", movers.filter((m) => m.pct > 0).sort((a, b) => b.pct - a.pct).slice(0, 6));
showMovers("Biggest median drops", movers.filter((m) => m.pct < 0).sort((a, b) => a.pct - b.pct).slice(0, 6));

// Flags: things that make the numbers above less trustworthy.
const skew = cur.filter((r) => r.low > 0 && r.med >= r.low * 10 && r.q >= 20 && r.q * r.med >= 1_000_000)
  .sort((a, b) => b.q * b.med - a.q * a.med).slice(0, 8);
if (skew.length)
  flags.push(`Median far above lowest listing (likely a few absurd listings skewing it): ${skew.map((r) => `${name(r)} (${gold(r.low)} vs ${gold(r.med)}, ${r.q}u)`).join("; ")}`);
const oneCopper = cur.filter((r) => r.low === 1 || r.med === 1).length;
if (oneCopper) flags.push(`${oneCopper} item(s) priced at exactly 1 copper.`);
if (flavor === "tbc-anniversary") {
  const far = rows.filter((r) => r.id > TBC_MAX_ITEM_ID);
  if (far.length) flags.push(`${far.length} item id(s) above the TBC ceiling ${TBC_MAX_ITEM_ID}: ${far.map((r) => r.id).join(", ")}`);
}
if (scanT && now - scanT > 6 * 3600) flags.push(`Newest scan is ${ago(scanT)} -- did you /reload after scanning?`);

// ---- Sellers ---------------------------------------------------------------
heading("SELLERS");
const sellRes = exportPayload(["--sellers"]);
if (sellRes.error) {
  console.log(`No seller data (${sellRes.error}). Owner names only come from a paged scan: /ml scan paged, then /reload.`);
} else {
  const { meta, sellers } = sellRes.data;
  const listings = sellers.reduce((n, s) => n + s.L.length, 0);
  const newest = Math.max(...sellers.map((s) => s.ls || 0));
  console.log(`Sellers: ${sellers.length}  |  listings: ${listings.toLocaleString()}  |  newest sighting: ${iso(newest)} (${ago(newest)})`);
  console.log(`Scan: ${meta.scannedPages}/${meta.pages} pages, ${meta.ownerCoverage}% owner coverage${meta.partial ? " (partial sample)" : ""}`);
  console.log("Biggest sellers:");
  for (const s of [...sellers].sort((a, b) => b.L.length - a.L.length).slice(0, 5))
    console.log(`  ${s.o.padEnd(20)} ${String(s.L.length).padStart(4)} listings`);
  if (scanT && newest && scanT - newest > 24 * 3600)
    flags.push(`Seller data is ${((scanT - newest) / 86400).toFixed(1)}d older than the item scan (${iso(newest)}); the newer scan was Get All, which has no owner names. Re-uploading it changes nothing -- run /ml scan paged + /reload for fresh sellers.`);
  else if (meta.partial) flags.push(`Seller scan is partial (${meta.scannedPages}/${meta.pages} pages).`);
}

// ---- Population ------------------------------------------------------------
heading("POPULATION");
const popRes = exportPayload(["--population", `--flavor=${flavor}`]);
if (popRes.error) {
  console.log(`No population data (${popRes.error}). Run /ml who in game.`);
} else {
  const { samples, characters, observations } = popRes.data;
  const last = samples.length ? samples[samples.length - 1].t : 0;
  console.log(`Samples: ${samples.length}${last ? ` (latest ${iso(last)}, ${ago(last)})` : ""}  |  characters: ${characters.length}  |  daily observations: ${observations.length}`);
  const tally = (key) => characters.reduce((o, c) => { o[c[key] || "?"] = (o[c[key] || "?"] || 0) + 1; return o; }, {});
  console.log(`Level 70: ${characters.filter((c) => c.l === 70).length} of ${characters.length}`);
  console.log(`Top classes: ${top(tally("class"), 4)}`);
  console.log(`Top zones:   ${top(tally("z"), 4)}`);
}

// ---- Flags -----------------------------------------------------------------
heading("FLAGS");
if (!flags.length) console.log("Nothing unusual.");
for (const f of flags) console.log(`- ${f}`);
