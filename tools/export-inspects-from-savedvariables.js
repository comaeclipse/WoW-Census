#!/usr/bin/env node
const { readSavedVariables } = require("./savedvariables.js");
const args = process.argv.slice(2);
const file = args.find(a => !a.startsWith("--"));
const option = (name, fallback) => (args.find(a => a.startsWith("--" + name + "=")) || "").slice(name.length + 3) || fallback;
if (!file) throw new Error("usage: export-inspects-from-savedvariables.js <NameplateInspect.lua> --source=classic-beta [--observer-realm=<Realm-Faction>]");
const db = readSavedVariables(file);
const source = option("source", "classic-beta");
// Talents are pooled per game, never per realm: connected realms and cross-realm
// play mean nearby players come from many realms. The only split kept is by game,
// since SoD and Era share one save file. Beta records predate the flavor field.
const sourceByFlavor = { "classic-era": "classic", "tbc-anniversary": "classic-progression" };
const records = Object.entries(db).filter(([guid, e]) => /^Player-/.test(guid) && e && typeof e === "object").filter(([, e]) => (e.flavor ? sourceByFlavor[e.flavor] || e.flavor : "classic-beta") === source).map(([guid, e]) => ({ guid, ...e, list: Object.values(e.list || {}).map(t => ({...t, groups: Object.values(t.groups || {})})) }));
process.stdout.write(JSON.stringify({type: "ml-inspects-v1", source, collector: "NameplateInspect", observerBucket: option("observer-realm", null), records}));
