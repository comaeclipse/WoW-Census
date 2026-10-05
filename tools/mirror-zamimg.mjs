// Crawls every page on wowcensus.io and saves the zamimg.com assets it uses to
// pages/assets/, mirroring the remote path (assets/wow.zamimg.com/images/...).
// Covers URLs written into the HTML and icons the page scripts build at runtime
// from embedded data (gear item icons in both sizes, talent class icons).
// Usage: node tools/mirror-zamimg.mjs [origin]
import { mkdir, writeFile, access } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const ORIGIN = (process.argv[2] || "https://wowcensus.io").replace(/\/$/, "");
const OUT = join(dirname(fileURLToPath(import.meta.url)), "..", "pages", "assets");
const ICONS = "https://wow.zamimg.com/images/wow/icons/";

async function text(url) {
  const res = await fetch(url, { headers: { accept: "text/html" } });
  if (!res.ok) throw new Error(`${res.status} ${url}`);
  return res.text();
}

// Crawl: seed from the sitemap, then follow same-origin links.
const queue = [ORIGIN + "/"];
try {
  for (const m of (await text(ORIGIN + "/sitemap.xml")).matchAll(/<loc>([^<]+)<\/loc>/g)) queue.push(m[1].trim());
} catch (e) { console.warn("sitemap:", e.message); }
const seen = new Set(), assets = new Set();
const normalize = (u) => { const x = new URL(u); x.hash = ""; x.search = ""; return x.href.replace(/\.html$/, ""); };

while (queue.length) {
  const url = normalize(queue.shift());
  if (seen.has(url)) continue;
  seen.add(url);
  let html;
  try { html = await text(url); } catch (e) { console.warn("page:", e.message); continue; }
  console.log("page", url);

  for (const m of html.matchAll(/https?:\/\/[a-z0-9.-]*zamimg\.com\/[^"'\s)`<>\\]+/gi)) {
    if (/\.[a-z0-9]{2,4}$/i.test(m[0])) assets.add(m[0]);
  }
  // Gear items are [id, name, quality, icon]; the page renders them small and large.
  for (const m of html.matchAll(/\[\d+,"(?:[^"\\]|\\.)*",\d+,"([a-z0-9_\-]+)"\]/gi)) {
    assets.add(`${ICONS}small/${m[1].toLowerCase()}.jpg`);
    assets.add(`${ICONS}large/${m[1].toLowerCase()}.jpg`);
  }
  // Talent tables build classicon_<class>.jpg from the classFile values.
  for (const m of html.matchAll(/"classFile":\[([^\]]*)\]/g)) {
    for (const c of m[1].matchAll(/"([A-Z]+)"/g)) assets.add(`${ICONS}large/classicon_${c[1].toLowerCase()}.jpg`);
  }
  assets.add(`${ICONS}large/inv_misc_questionmark.jpg`);
  assets.add(`${ICONS}small/inv_misc_questionmark.jpg`);

  for (const m of html.matchAll(/href="([^"#'+]+)"/g)) {
    let next;
    try { next = new URL(m[1], url); } catch { continue; }
    if (next.origin !== ORIGIN || /\.(css|js|json|png|ico|xml|txt|md|woff2?)$/i.test(next.pathname)) continue;
    queue.push(next.href);
  }
}

console.log(`\n${seen.size} pages, ${assets.size} assets`);
let saved = 0, skipped = 0, failed = [];
const list = [...assets];
async function worker() {
  while (list.length) {
    const url = list.shift(), u = new URL(url);
    const file = join(OUT, u.hostname, ...u.pathname.split("/").filter(Boolean));
    try { await access(file); skipped++; continue; } catch {}
    try {
      const res = await fetch(url);
      if (!res.ok) throw new Error(String(res.status));
      await mkdir(dirname(file), { recursive: true });
      await writeFile(file, Buffer.from(await res.arrayBuffer()));
      saved++;
    } catch (e) { failed.push(`${e.message} ${url}`); }
  }
}
await Promise.all(Array.from({ length: 8 }, worker));
console.log(`saved ${saved}, already present ${skipped}, failed ${failed.length}`);
if (failed.length) console.log(failed.join("\n"));
