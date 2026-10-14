#!/usr/bin/env node
// Refresh the spell icon names used by MoP Classic talent inspections.
// The client records spell IDs but does not send icon names.
import fs from "node:fs";

const source = "https://nether.wowhead.com/mop-classic/data/talents-classic?dv=29";
const marker = "WH.Gatherer.addData(6, 15, ";
const response = await fetch(source);
if (!response.ok) throw new Error(`MoP talent catalog: HTTP ${response.status}`);
const text = await response.text();
const start = text.indexOf(marker);
if (start < 0) throw new Error("MoP spell catalog missing");
const bodyStart = start + marker.length;
const end = text.indexOf("});", bodyStart);
if (end < 0) throw new Error("MoP spell catalog incomplete");
const catalog = JSON.parse(text.slice(bodyStart, end + 1));
const spells = Object.fromEntries(Object.entries(catalog)
  .filter(([id, entry]) => /^\d+$/.test(id) && /^[a-z0-9_-]+$/i.test(entry?.icon || ""))
  .map(([id, entry]) => [id, entry.icon.toLowerCase()]));
if (Object.keys(spells).length < 200 || spells["108843"] !== "spell_fire_burningspeed")
  throw new Error("MoP spell catalog failed validation");
const file = new URL("../site/src/mop-talent-icons.json", import.meta.url);
const content = JSON.stringify({ source, retrievedAt: new Date().toISOString(), spells }, null, 2) + "\n";
fs.writeFileSync(file, content);
console.log(`Saved ${Object.keys(spells).length} MoP spell icons`);
