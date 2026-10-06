#!/usr/bin/env node
// Refreshes the talent catalogs in site/src from Wowhead's talent calculator
// data, then rebuild static/.
//
// forever-talents.json classifies Forever builds. Patches reuse node IDs for
// reworked talents (Boundless Rage became Furious Precision on 105953), and
// describeBuild rejects a node whose spell no longer matches, so a stale
// catalog shows up as "Unknown build". Its tabs keep the class and tree names
// already in the catalog; a new tab stops the run.
//
// tbc-talents.json only supplies icons: the TBC client already reports tree
// names. Wowhead's TBC data has no talent names, so nodes are matched on ID,
// class and rank count.
//
// Nodes Wowhead dropped are kept so older inspections still resolve. A catalog
// whose nodes did not change is left alone.
// Usage: node tools/update-talent-catalogs.mjs
import fs from "node:fs";
import crypto from "node:crypto";

const CLASSES = ["Warrior", "Paladin", "Hunter", "Rogue", "Priest", "Shaman", "Mage", "Warlock", "Druid"];
const CATALOGS = [
  { key: "forever", file: "forever-talents.json", source: "https://nether.wowhead.com/forever/data/talents-classic?dv=29",
    node(n, tab, tabs, trees) {
      const t = tabs.get(tab);
      if (!t) throw new Error(`New Forever talent tab ${tab} (${trees[tab]?.description}); add its class and tree by hand`);
      return { node: n.id, classFile: t.classFile, branch: t.branch, tab, name: n.name,
        spell: n.ranks[0], max: n.ranks.length, icon: n.icon, definition: n.definition, row: n.row, col: n.col };
    } },
  { key: "tbc", file: "tbc-talents.json", source: "https://nether.wowhead.com/tbc/data/talents-classic?dv=29",
    node(n, tab, tabs, trees) {
      // Tree descriptions read "WarriorArms", "DruidFeralCombat", ...
      const cls = CLASSES.find((c) => trees[tab]?.description?.startsWith(c));
      if (!cls) throw new Error(`Unrecognized TBC talent tab ${tab} (${trees[tab]?.description})`);
      return { node: n.id, classFile: cls.toUpperCase(), tab, spell: n.ranks[0], max: n.ranks.length, icon: n.icon, row: n.row, col: n.col };
    } },
];

// The payload is a JS call, WH.setPageData("...data",{...}); cut out the object.
function pageData(text) {
  const start = text.indexOf("{", text.indexOf('.data"'));
  let depth = 0, end = start, inString = false;
  for (; end < text.length; end++) {
    const c = text[end];
    if (inString) { if (c === "\\") end++; else if (c === '"') inString = false; continue; }
    if (c === '"') inString = true;
    else if (c === "{") depth++;
    else if (c === "}" && !--depth) break;
  }
  return JSON.parse(text.slice(start, end + 1));
}

for (const catalog of CATALOGS) {
  const file = new URL(`../site/src/${catalog.file}`, import.meta.url);
  const old = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, "utf8")) : { nodes: [] };
  const res = await fetch(catalog.source);
  if (!res.ok) throw new Error(`${res.status} ${catalog.source}`);
  const text = await res.text(), data = pageData(text);
  const tabs = new Map(old.nodes.map((n) => [n.tab, { classFile: n.classFile, branch: n.branch }]));
  const nodes = Object.entries(data.talents).flatMap(([tab, talents]) =>
    Object.values(talents).map((n) => catalog.node(n, Number(tab), tabs, data.trees)));
  const current = new Set(nodes.map((n) => n.node));
  const kept = old.nodes.filter((n) => !current.has(n.node));
  const before = new Map(old.nodes.map((n) => [n.node, JSON.stringify(n)]));
  const label = (n) => [catalog.key, n.classFile, n.branch, n.node, n.name].filter(Boolean).join(" ");
  for (const n of nodes.filter((n) => before.has(n.node) && before.get(n.node) !== JSON.stringify(n))) console.log("changed", label(n));
  if (old.nodes.length) for (const n of nodes.filter((n) => !before.has(n.node))) console.log("added  ", label(n));
  for (const n of kept) console.log("kept   ", label(n), "(no longer on Wowhead)");
  const all = [...nodes, ...kept];
  if (JSON.stringify(all) === JSON.stringify(old.nodes)) { console.log(`${catalog.file}: unchanged`); continue; }
  const retrievedAt = new Date().toISOString();
  const hash = crypto.createHash("sha256").update(text).digest("hex").slice(0, 12);
  fs.writeFileSync(file, JSON.stringify({ version: `${catalog.key}-${Math.floor(Date.parse(retrievedAt) / 1000)}-${hash}`,
    source: catalog.source, retrievedAt, nodes: all }, null, 2) + "\n");
  console.log(`${catalog.file}: ${nodes.length} current + ${kept.length} kept nodes`);
}
