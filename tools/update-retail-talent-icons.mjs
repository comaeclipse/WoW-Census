#!/usr/bin/env node
// Refresh the spell icon names used by retail talent inspections.
// The client records spell IDs but does not send icon names.
import fs from "node:fs";

const source = "https://nether.wowhead.com/data/talents-dragonflight?dv=29";
const marker = 'WH.setPageData("wow.talentCalcDragonflight.live.trees",';
const response = await fetch(source);
if (!response.ok) throw new Error(`Retail talent catalog: HTTP ${response.status}`);
const text = await response.text();
const start = text.indexOf(marker);
if (start < 0) throw new Error("Retail talent trees missing");
// Cut the trees array out of the JS call; strings may contain brackets.
const bodyStart = start + marker.length;
let depth = 0, end = bodyStart, inString = false;
for (; end < text.length; end++) {
  const c = text[end];
  if (inString) { if (c === "\\") end++; else if (c === '"') inString = false; continue; }
  if (c === '"') inString = true;
  else if (c === "[" || c === "{") depth++;
  else if ((c === "]" || c === "}") && !--depth) break;
}
const trees = JSON.parse(text.slice(bodyStart, end + 1));
// Talent entries sit at varying depths (class, spec and hero trees); collect
// every {spell, icon} pair.
const spells = {};
(function walk(v) {
  if (Array.isArray(v)) return v.forEach(walk);
  if (!v || typeof v !== "object") return;
  if (Number.isInteger(v.spell) && v.spell > 0 && /^[a-z0-9_-]+$/i.test(v.icon || "")) spells[v.spell] ??= v.icon.toLowerCase();
  Object.values(v).forEach(walk);
})(trees);
if (Object.keys(spells).length < 1000 || spells["434969"] !== "ability_vehicle_electrocharge")
  throw new Error("Retail spell catalog failed validation");
const sorted = Object.fromEntries(Object.entries(spells).sort(([a], [b]) => a - b));
const file = new URL("../site/src/retail-talent-icons.json", import.meta.url);
const content = JSON.stringify({ source, retrievedAt: new Date().toISOString(), spells: sorted }, null, 2) + "\n";
fs.writeFileSync(file, content);
console.log(`Saved ${Object.keys(sorted).length} retail spell icons`);
