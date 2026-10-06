import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { dwarfProfile } from "../site/src/race-profile.mjs";

test("Dwarf profile counts only readable Dwarf talent inspections", () => {
  const record = (race, classification, talents, level = 20) => ({ race, classFile: "SHAMAN", classification,
    talents, level, time: 100, orderedPoints: [{ tree: "Enhancement", points: 10 }] });
  const node = { node: 1, name: "Example Talent", rank: 2, max: 5, branch: "Enhancement", icon: "ability_example" };
  const data = dwarfProfile({ windowDays: 14, records: [
    record("Dwarf", "Enhancement", [node, node]),
    record("Dwarf", "Unknown build", [{ ...node, rank: 4 }], 22),
    record("Dwarf", "Enhancement", []),
    record("Human", "Enhancement", [node]),
  ] });
  assert.equal(data.n, 2);
  assert.deepEqual(data.classes.SHAMAN.spec, { Enhancement: 1, "Unknown build": 1 });
  assert.deepEqual(data.classes.SHAMAN.tal[0], { name: "Example Talent", pct: 100, avgRank: 3, max: 5,
    icon: "ability_example", tree: "Enhancement" });
});

test("built Dwarf route has real pre-rendered content and sitemap entry", () => {
  const html = fs.readFileSync(new URL("../static/forever/dwarf/index.html", import.meta.url), "utf8");
  const sitemap = fs.readFileSync(new URL("../static/sitemap.xml", import.meta.url), "utf8");
  assert.match(html, /<ol class="board" id="board"><li /);
  assert.match(html, /<div class="cards" id="cards"><article /);
  assert.match(html, /<link rel="canonical" href="https:\/\/wowcensus\.io\/forever\/dwarf\/">/);
  assert.doesNotMatch(html, /class="crt"|MOCKUP|illustrative/);
  assert.match(sitemap, /<loc>https:\/\/wowcensus\.io\/forever\/dwarf\/<\/loc>/);
});
