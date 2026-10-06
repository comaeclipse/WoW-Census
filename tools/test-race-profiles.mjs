import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { raceProfile } from "../site/src/race-profile.mjs";

test("race profile counts only readable inspections of that race", () => {
  const record = (race, classification, talents, level = 20) => ({ race, classFile: "SHAMAN", classification,
    talents, level, time: 100, orderedPoints: [{ tree: "Enhancement", points: 10 }] });
  const node = { node: 1, name: "Example Talent", rank: 2, max: 5, branch: "Enhancement", icon: "ability_example" };
  const data = raceProfile({ windowDays: 14, records: [
    record("Dwarf", "Enhancement", [node, node]),
    record("Dwarf", "Unknown build", [{ ...node, rank: 4 }], 22),
    record("Dwarf", "Enhancement", []),
    record("Human", "Enhancement", [node]),
  ] }, "Dwarf");
  assert.equal(data.n, 2);
  assert.deepEqual(data.classes.SHAMAN.spec, { Enhancement: 1, "Unknown build": 1 });
  assert.deepEqual(data.classes.SHAMAN.tal[0], { name: "Example Talent", pct: 100, avgRank: 3, max: 5,
    icon: "ability_example", tree: "Enhancement" });
});

test("Skyborne profiles keep Alliance and Horde records separate", () => {
  const talent = { node: 1, name: "Example Talent", rank: 1, max: 5, branch: "Feral Combat" };
  const records = [
    { race: "High Order Skyborne", faction: "Alliance", classFile: "DRUID", talents: [talent] },
    { race: "Windshaper Skyborne", faction: "Horde", classFile: "DRUID", talents: [talent] },
  ];
  assert.equal(raceProfile({ records }, "High Order Skyborne").n, 1);
  assert.equal(raceProfile({ records }, "Windshaper Skyborne").n, 1);
});

for (const race of ["human", "dwarf", "gnome", "skyborne", "night-elf", "orc", "undead", "tauren", "horde-skyborne", "troll"]) test(`built ${race} route has real pre-rendered content and sitemap entry`, () => {
  const pageUrl = new URL(`../static/forever/${race}/index.html`, import.meta.url);
  const html = fs.readFileSync(pageUrl, "utf8");
  const sitemap = fs.readFileSync(new URL("../static/sitemap.xml", import.meta.url), "utf8");
  assert.match(html, /<ol class="board" id="board"><li /);
  assert.match(html, /<div class="cards" id="cards"><article /);
  assert.ok(html.includes(`<link rel="canonical" href="https://wowcensus.io/forever/${race}/">`));
  assert.doesNotMatch(html, /class="crt"|MOCKUP|illustrative|\{\{RACE/);
  assert.doesNotMatch(html, /class="src"|Pick rate =|Pips show the average rank|Page generated:/);
  assert.doesNotMatch(html, /A\/B<\/span>|is the most inspected/);
  assert.match(html, /<p>Among [^<]+ we've inspected, <strong>[^<]+<\/strong> is the most common class, with <strong>[^<]+<\/strong> leading among identified builds\.<\/p>/);
  assert.match(html, /<div class="k">Median level<\/div><div class="v">[^<]+<\/div><\/div>/);
  assert.ok(sitemap.includes(`<loc>https://wowcensus.io/forever/${race}/</loc>`));
  assert.doesNotMatch(html, /(?:src|href)="\/(?:assets|fonts|style\.)/);
  for (const [, ref] of html.matchAll(/(?:src|href)="(\.\.\/\.\.\/(?:assets|fonts|style\.|favicon)[^"]*)"/g))
    assert.ok(fs.existsSync(new URL(ref, pageUrl)), `Local asset missing from ${race}: ${ref}`);
  const chipMarkup = html.match(/<div class="chips" aria-label="Race">([\s\S]*?)<\/div>/)?.[1];
  assert.ok(chipMarkup);
  const chips = [...chipMarkup.matchAll(/<a\b[^>]*class="chip"[^>]*>[^<]*<\/a>/g)].map(([tag]) => ({
    href: tag.match(/href="([^"]+)"/)?.[1],
    current: tag.includes('aria-current="page"'),
    faction: tag.match(/data-faction="([^"]+)"/)?.[1],
    hidden: /<a\b[^>]*\bhidden\b/.test(tag),
  }));
  assert.deepEqual(chips.map((chip) => chip.href), [
    "/forever/human/", "/forever/dwarf/", "/forever/gnome/",
    "/forever/skyborne/", "/forever/night-elf/", "/forever/orc/", "/forever/undead/", "/forever/tauren/", "/forever/horde-skyborne/", "/forever/troll/",
  ]);
  assert.deepEqual(chips.filter((chip) => chip.current).map((chip) => chip.href), [`/forever/${race}/`]);
  const selectedFaction = ["orc", "undead", "tauren", "horde-skyborne", "troll"].includes(race) ? "Horde" : "Alliance";
  assert.match(html, new RegExp(`<option selected value="${selectedFaction}">`));
  assert.deepEqual(chips.filter((chip) => !chip.hidden).map((chip) => chip.faction), Array(5).fill(selectedFaction));
  assert.deepEqual(chips.filter((chip) => chip.hidden).map((chip) => chip.faction), Array(5).fill(selectedFaction === "Horde" ? "Alliance" : "Horde"));
  assert.match(html, /select\.addEventListener\('change',/);
  assert.match(html, /\.page-controls \.chip\[aria-current="page"\]\{background:var\(--gold\)/);
});
