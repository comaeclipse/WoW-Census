#!/usr/bin/env node
// Mirror the exact class-profile icons referenced by the generated MoP pages.
// Run after a build introduces new icon names, then rebuild to copy them to static/.
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const pages = path.join(repo, "static", "mop");
const icons = path.join(repo, "assets", "wow.zamimg.com", "images", "wow", "icons", "large");
const names = new Set();
for (const entry of await fs.readdir(pages, { withFileTypes: true })) {
  if (!entry.isDirectory()) continue;
  const page = path.join(pages, entry.name, "index.html");
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
console.log(`${names.size} referenced MoP icons, ${pending.length} missing`);
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
