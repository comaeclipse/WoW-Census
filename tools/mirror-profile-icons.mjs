#!/usr/bin/env node
// Mirror the exact class-profile icons referenced by an edition's generated pages.
// Run after a build introduces new icon names, then rebuild to copy them to static/.
// Usage: node tools/mirror-profile-icons.mjs [edition dir ...]   (default: every edition with class pages)
// The watcher calls it with no arguments, so the default must cover every edition:
// it used to be mop only, and retail talent icons stayed missing.
import fs from "node:fs/promises";
import { existsSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const editions = process.argv.slice(2).length ? process.argv.slice(2)
  : ["tbc", "classic", "sod", "hardcore", "mop", "retail"].filter(dir => existsSync(path.join(repo, "static", dir)));
const icons = path.join(repo, "assets", "wow.zamimg.com", "images", "wow", "icons", "large");
const names = new Set();
for (const edition of editions) for (const entry of await fs.readdir(path.join(repo, "static", edition), { withFileTypes: true })) {
  if (!entry.isDirectory()) continue;
  const page = path.join(repo, "static", edition, entry.name, "index.html");
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
