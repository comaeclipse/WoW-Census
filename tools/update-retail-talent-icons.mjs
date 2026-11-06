#!/usr/bin/env node
// Refresh the spell icon names used by retail talent inspections.
// The client records spell IDs but does not send icon names.
import fs from "node:fs";

const source = "https://nether.wowhead.com/data/talents-dragonflight?dv=29";
const response = await fetch(source);
if (!response.ok) throw new Error(`Retail talent catalog: HTTP ${response.status}`);
const text = await response.text();
// Cut one page-data value out of its JS call; strings may contain brackets.
function pageData(key) {
  const marker = `WH.setPageData("wow.talentCalcDragonflight.live.${key}",`;
  const start = text.indexOf(marker);
  if (start < 0) throw new Error(`Retail talent ${key} missing`);
  const bodyStart = start + marker.length;
  let depth = 0, end = bodyStart, inString = false;
  for (; end < text.length; end++) {
    const c = text[end];
    if (inString) { if (c === "\\") end++; else if (c === '"') inString = false; continue; }
    if (c === '"') inString = true;
    else if (c === "[" || c === "{") depth++;
    else if ((c === "]" || c === "}") && !--depth) break;
  }
  return JSON.parse(text.slice(bodyStart, end + 1));
}
const trees = pageData("trees");
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
// Spells clients report that the calculator lists under another ID or no
// longer lists; their icons come from Wowhead's spell tooltips.
const EXTRA_SPELLS = [
  414659, // Ice Cold: the client reports the Ice Block override, the calculator the talent (414658)
  1277548, // Unload: node 104095 has since become Blood Fletching
];
for (const id of EXTRA_SPELLS) {
  if (spells[id]) continue;
  const tooltip = await fetch(`https://nether.wowhead.com/tooltip/spell/${id}`);
  if (!tooltip.ok) throw new Error(`Spell ${id} tooltip: HTTP ${tooltip.status}`);
  const { icon } = await tooltip.json();
  if (!/^[a-z0-9_-]+$/i.test(icon || "")) throw new Error(`Spell ${id} tooltip has no icon`);
  spells[id] = icon.toLowerCase();
}
const sorted = Object.fromEntries(Object.entries(spells).sort(([a], [b]) => a - b));
// Each spec's hero-tree choice node has no spell; the client reports it with
// a null spell. Keep the subtrees it chooses between (name, nodes and the
// keystone's icon) so the pick can be named after the chosen hero tree.
const heroTrees = {};
for (const tree of trees.filter(t => t.type === 3)) {
  const talents = Object.values(tree.talents).flat();
  const keystone = talents.filter(t => !t.requires.length && !t.requiredPoints).sort((a, b) => a.cell - b.cell)[0];
  heroTrees[tree.id] = { name: tree.name, icon: keystone?.spells?.[0]?.icon?.toLowerCase() || null, nodes: talents.map(t => t.node).sort((a, b) => a - b) };
}
const heroChoices = {};
for (const spec of Object.values(pageData("nodes")))
  for (const [node, ids] of Object.entries(spec.heroTreeChoices || {})) heroChoices[node] = ids;
if (Object.keys(heroTrees).length < 30 || Object.keys(heroChoices).length < 30
  || Object.values(heroChoices).flat().some(id => !heroTrees[id]?.icon))
  throw new Error("Retail hero tree catalog failed validation");
const file = new URL("../site/src/retail-talent-icons.json", import.meta.url);
const content = JSON.stringify({ source, retrievedAt: new Date().toISOString(), spells: sorted, heroChoices, heroTrees }, null, 2) + "\n";
fs.writeFileSync(file, content);
console.log(`Saved ${Object.keys(sorted).length} retail spell icons, ${Object.keys(heroChoices).length} hero tree choices`);
