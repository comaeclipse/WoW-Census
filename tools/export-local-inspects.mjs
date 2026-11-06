#!/usr/bin/env node
// Normalize the complete companion cache with the same rules as the Worker.
import { createRequire } from "node:module";
import { normalizeInspect } from "../site/src/inspects.mjs";

const require = createRequire(import.meta.url);
const { readSavedVariables } = require("./savedvariables.js");
const [file, source] = process.argv.slice(2);
if (!file || !source) throw new Error("usage: export-local-inspects.mjs <NameplateInspect.lua> <source>");
const db = readSavedVariables(file, "NameplateInspectDB");
const sourceByFlavor = { "classic-era": "classic", "tbc-anniversary": "classic-progression", "forever": "forever" };
const records = [];
let selected = 0;
let rejected = 0;
for (const [guid, data] of Object.entries(db)) {
  if (!/^Player-/.test(guid) || !data || typeof data !== "object") continue;
  const realm = String(data.realm || data.observerRealm || "");
  const flavor = data.flavor === "classic-era" && /^Doomhowl(?:$|-)/i.test(realm)
    ? "hardcore" : data.flavor ? sourceByFlavor[data.flavor] || data.flavor : "classic-beta";
  if (flavor !== source) continue;
  selected++;
  const raw = { guid, ...data, list: Object.values(data.list || {}).map(t => ({
    ...t, groups: Object.values(t.groups || {}),
  })) };
  const normalized = normalizeInspect(raw, source);
  if (normalized) records.push({ raw, normalized });
  else rejected++;
}
process.stdout.write(JSON.stringify({ source, selected, rejected, records }));
