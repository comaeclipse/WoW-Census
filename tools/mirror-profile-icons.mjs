#!/usr/bin/env node
// Mirror the exact class-profile icons referenced by an edition's rendered class pages
// (laragon-php/render-routes.php writes them). The PHP site serves them from assets/.
// Usage: node tools/mirror-profile-icons.mjs [--pages=<dir>] [edition dir ...]   (default: every rendered edition)
// The watcher calls it with no arguments, so the default must cover every edition:
// it used to be mop only, and retail talent icons stayed missing.
import fs from "node:fs/promises";
import { existsSync } from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
// Rendered pages to read, from laragon-php/render-routes.php (the watcher runs it first).
const pagesArg = process.argv.find(a => a.startsWith("--pages="));
const pages = pagesArg ? pagesArg.slice(8)
  : process.env.MARKETLENS_PAGES || path.join(os.homedir(), "Documents", "MarketLensData", "rendered-pages");
const dirs = process.argv.slice(2).filter(a => !a.startsWith("--"));
const editions = dirs.length ? dirs
  : ["tbc", "classic", "sod", "hardcore", "mop", "retail"].filter(dir => existsSync(path.join(pages, dir)));
const icons = path.join(repo, "assets", "wow.zamimg.com", "images", "wow", "icons", "large");
const names = new Set();
for (const edition of editions) for (const entry of await fs.readdir(path.join(pages, edition), { withFileTypes: true })) {
  if (!entry.isDirectory()) continue;
  const page = path.join(pages, edition, entry.name, "index.html");
  try { await fs.access(page); } catch { continue; }
  const html = await fs.readFile(page, "utf8");
  for (const match of html.matchAll(/\.\.\/\.\.\/assets\/images\/wow\/icons\/large\/([a-z0-9_-]+\.jpg)/g))
    names.add(match[1]);
}
const pending = [];
for (const name of names) {
  try { await fs.access(path.join(icons, name)); }
  catch { pending.push(name); }
}
console.log(`${names.size} referenced ${editions.join(", ")} icons, ${pending.length} missing`);
const needed = pending.length;
const failures = [];
async function worker() {
  while (pending.length) {
    const name = pending.shift();
    try {
      const response = await fetch(`https://wow.zamimg.com/images/wow/icons/large/${name}`);
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const bytes = Buffer.from(await response.arrayBuffer());
      if (bytes.length < 100 || bytes[0] !== 0xff || bytes[1] !== 0xd8)
        throw new Error("Response is not a JPEG icon");
      await fs.writeFile(path.join(icons, name), bytes);
    } catch (error) { failures.push(`${name}: ${error.message}`); }
  }
}
await Promise.all(Array.from({ length: 8 }, worker));
console.log(`Mirrored ${needed - failures.length} new icons`);
if (failures.length) throw new Error(failures.join("\n"));
