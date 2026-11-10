#!/usr/bin/env node
// Fills tools/item-icons/<branch>.json for gear and Auction House items the
// rendered pages show without an icon (the "?" fallback), then mirrors them.
// The gear data files list items as [id, name, quality, icon]; an empty icon
// means the cache had no entry, e.g. Season of Discovery items added after the
// cache was made. Icons come from Wowhead's tooltip API for the edition's branch.
// The cache was a one-off snapshot until this existed, so every item first
// seen after it showed "?"; the watcher now runs this after each import.
//
// Usage: node tools/update-item-icons.mjs [--sources=sod,classic,...] [--pages=<dir>] [--live-auctions] [--ids-from=<file>]
// --ids-from adds item ids listed in a file (e.g. php laragon-php/auction-item-ids.php forever).
// Reads pages from laragon-php/render-routes.php. --live-auctions also reads the current
// wowcensus.io AH pages. Prints "ADDED icons=<n>"; the PHP site picks new icons up on request.
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
// Rendered pages to read, from laragon-php/render-routes.php (the watcher runs it first).
const pagesArg = process.argv.find(a => a.startsWith("--pages="));
const pages = pagesArg ? pagesArg.slice(8)
  : process.env.MARKETLENS_PAGES || path.join(os.homedir(), "Documents", "MarketLensData", "rendered-pages");
// Edition key -> page folder and Wowhead branch (laragon-php/src/Edition.php EDITIONS).
const EDITIONS = {
  "forever": { dir: "forever", branch: "forever" }, "classic-beta": { dir: "forever-beta", branch: "forever" }, "classic-progression": { dir: "tbc", branch: "tbc" },
  classic: { dir: "classic", branch: "classic" }, sod: { dir: "sod", branch: "classic" },
  hardcore: { dir: "hardcore", branch: "classic" }, "mop-classic": { dir: "mop", branch: "mop-classic" },
  retail: { dir: "retail", branch: "" },
};
const arg = process.argv.find(a => a.startsWith("--sources="));
const sources = arg ? arg.slice(10).split(",").filter(s => EDITIONS[s]) : Object.keys(EDITIONS);
const liveAuctions = process.argv.includes("--live-auctions");
const idsArg = process.argv.find(a => a.startsWith("--ids-from="));
const idsFrom = idsArg ? idsArg.slice(11) : null;
const sleep = ms => new Promise(r => setTimeout(r, ms));
// Windows can briefly lock the file (virus scan, editor): retry rather than lose the run.
async function save(file, cache) {
  const sorted = Object.fromEntries(Object.entries(cache).sort((a, b) => Number(a[0]) - Number(b[0])));
  for (let attempt = 1; ; attempt++) {
    try { fs.writeFileSync(file, JSON.stringify(sorted, null, 2) + "\n"); return; }
    catch (e) { if (attempt >= 10) throw e; await sleep(500 * attempt); }
  }
}

async function iconFor(id, branch) {
  try {
    const res = await fetch(`https://nether.wowhead.com/${branch ? branch + "/" : ""}tooltip/item/${id}`);
    const icon = res.ok ? (await res.json()).icon : null;
    if (typeof icon === "string" && /^[a-z0-9_-]+$/i.test(icon)) return icon.toLowerCase();
    console.warn(`no icon for ${branch || "retail"} item ${id} (${res.status})`);
  } catch (e) { console.warn(`${branch || "retail"} item ${id}: ${e.message}`); }
  return null;
}

// The pages draw gear icons small (tables) and large (top tiles).
async function mirror(icon) {
  let n = 0;
  for (const size of ["small", "large"]) {
    const out = path.join(repo, "assets", "wow.zamimg.com", "images", "wow", "icons", size, `${icon}.jpg`);
    if (fs.existsSync(out)) continue;
    const res = await fetch(`https://wow.zamimg.com/images/wow/icons/${size}/${icon}.jpg`);
    if (!res.ok) { console.warn(`icon ${size}/${icon}: ${res.status}`); continue; }
    fs.mkdirSync(path.dirname(out), { recursive: true });
    fs.writeFileSync(out, Buffer.from(await res.arrayBuffer()));
    n++;
  }
  return n;
}

let added = 0, mirrored = 0;
for (const branch of new Set(sources.map(s => EDITIONS[s].branch))) {
  const cacheFile = path.join(repo, "tools", "item-icons", `${branch || "retail"}.json`);
  const cache = fs.existsSync(cacheFile) ? JSON.parse(fs.readFileSync(cacheFile, "utf8")) : {};
  const missing = new Set();
  for (const s of sources.filter(s => EDITIONS[s].branch === branch)) {
    const data = path.join(pages, EDITIONS[s].dir, "data");
    if (fs.existsSync(data)) {
      for (const file of fs.readdirSync(data).filter(f => /^gear\.[0-9a-f]{12}\.json$/.test(f)))
        for (const [id, , , icon] of JSON.parse(fs.readFileSync(path.join(data, file), "utf8")).items || [])
          if (!icon && !cache[id]) missing.add(id);
    }
    // Auction House tables are rendered into HTML and do not use gear data.
    const auctionPage = path.join(pages, EDITIONS[s].dir, "auctionhouse.html");
    if (fs.existsSync(auctionPage)) {
      const html = fs.readFileSync(auctionPage, "utf8");
      for (const match of html.matchAll(/wowhead\.com\/(?:[^"<>\s]+\/)?item=(\d+)/g))
        if (!cache[match[1]]) missing.add(Number(match[1]));
    }
    // Every id in the edition's auction scans (laragon-php/auction-item-ids.php), so items
    // that join a top list after a data refresh already have an icon.
    if (idsFrom)
      for (const id of fs.readFileSync(idsFrom, "utf8").split(/\s+/).filter(x => /^\d+$/.test(x)))
        if (!cache[id]) missing.add(Number(id));
    if (liveAuctions) {
      const url = `https://wowcensus.io/${EDITIONS[s].dir ? EDITIONS[s].dir + "/" : ""}auctionhouse`;
      const response = await fetch(url);
      if (!response.ok) throw new Error(`${url}: HTTP ${response.status}`);
      const html = await response.text();
      for (const match of html.matchAll(/"id":(\d+),"icon":null/g))
        if (!cache[match[1]]) missing.add(Number(match[1]));
    }
  }
  if (!missing.size) continue;
  console.log(`${missing.size} ${branch || "retail"} item(s) without an icon`);
  let found = 0;
  for (const id of missing) {
    const icon = await iconFor(id, branch);
    if (icon) {
      cache[id] = icon; found++;
      mirrored += await mirror(icon);
      // Save as it goes, so an interrupted run keeps what it found.
      if (found % 50 === 0) await save(cacheFile, cache);
    }
    await sleep(150);  // gentle on Wowhead
  }
  await save(cacheFile, cache);
  console.log(`added ${found} icon(s) to ${path.relative(repo, cacheFile)}`);
  added += found;
}
console.log(`mirrored ${mirrored} icon image(s) into assets/`);
console.log(`ADDED icons=${added}`);
