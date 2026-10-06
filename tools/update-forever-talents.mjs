#!/usr/bin/env node
// Refreshes site/src/forever-talents.json from Wowhead's Forever talent
// calculator data. Patches reuse node IDs for reworked talents (Boundless Rage
// became Furious Precision on 105953), and describeBuild rejects a node whose
// spell no longer matches, so a stale catalog shows up as "Unknown build".
// Tabs keep the class and tree names already in the catalog; a new tab stops
// the run. Nodes Wowhead dropped are kept so older inspections still classify.
// Usage: node tools/update-forever-talents.mjs   (then rebuild static/)
import fs from "node:fs";
import crypto from "node:crypto";

const SOURCE = "https://nether.wowhead.com/forever/data/talents-classic?dv=29";
const file = new URL("../site/src/forever-talents.json", import.meta.url);
const old = JSON.parse(fs.readFileSync(file, "utf8"));

const res = await fetch(SOURCE);
if (!res.ok) throw new Error(`${res.status} ${SOURCE}`);
const text = await res.text();
// The payload is a JS call, WH.setPageData("...classicplus.data",{...}); cut out the object.
const start = text.indexOf("{", text.indexOf("classicplus.data"));
let depth = 0, end = start, inString = false;
for (; end < text.length; end++) {
  const c = text[end];
  if (inString) { if (c === "\\") end++; else if (c === '"') inString = false; continue; }
  if (c === '"') inString = true;
  else if (c === "{") depth++;
  else if (c === "}" && !--depth) break;
}
const data = JSON.parse(text.slice(start, end + 1));

const tabs = new Map(old.nodes.map((n) => [n.tab, { classFile: n.classFile, branch: n.branch }]));
const nodes = [];
for (const [tab, talents] of Object.entries(data.talents)) {
  const t = tabs.get(Number(tab));
  if (!t) throw new Error(`New talent tab ${tab} (${data.trees?.[tab]?.description}); add its class and tree by hand`);
  for (const n of Object.values(talents))
    nodes.push({ node: n.id, classFile: t.classFile, branch: t.branch, tab: Number(tab), name: n.name,
      spell: n.ranks[0], max: n.ranks.length, icon: n.icon, definition: n.definition, row: n.row, col: n.col });
}
const current = new Set(nodes.map((n) => n.node));
const kept = old.nodes.filter((n) => !current.has(n.node));
const before = new Map(old.nodes.map((n) => [n.node, JSON.stringify(n)]));
const changed = nodes.filter((n) => before.has(n.node) && before.get(n.node) !== JSON.stringify(n));
const added = nodes.filter((n) => !before.has(n.node));
for (const n of changed) console.log("changed", n.classFile, n.branch, n.node, n.name);
for (const n of added) console.log("added  ", n.classFile, n.branch, n.node, n.name);
for (const n of kept) console.log("kept   ", n.classFile, n.branch, n.node, n.name, "(no longer on Wowhead)");

const retrievedAt = new Date().toISOString();
const hash = crypto.createHash("sha256").update(text).digest("hex").slice(0, 12);
fs.writeFileSync(file, JSON.stringify({ version: `forever-${Math.floor(Date.parse(retrievedAt) / 1000)}-${hash}`,
  source: SOURCE, retrievedAt, nodes: [...nodes, ...kept] }, null, 2) + "\n");
console.log(`${nodes.length} current + ${kept.length} kept nodes written to site/src/forever-talents.json`);
