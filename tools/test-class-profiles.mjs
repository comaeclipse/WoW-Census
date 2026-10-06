import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { classProfile } from "../site/src/class-profile.mjs";

test("class profile ranks each tree's talents among that spec's players only", () => {
  const t = (node, name, branch, rank = 1) => ({ node, name, branch, rank, max: 3, icon: "x" });
  const data = classProfile({ windowDays: 14, records: [
    { classFile: "PALADIN", classification: "Retribution", level: 20, time: 5, talents: [t(1, "Benediction", "Retribution", 3), t(2, "Conviction", "Retribution"), t(9, "Divine Intellect", "Holy")] },
    { classFile: "PALADIN", classification: "Retribution", level: 22, time: 7, talents: [t(2, "Conviction", "Retribution", 2)] },
    { classFile: "PALADIN", classification: "Retribution/Holy", talents: [t(1, "Benediction", "Retribution")] },
    { classFile: "PALADIN", classification: "Holy", talents: [] },
    { classFile: "WARRIOR", classification: "Arms", talents: [t(1, "Benediction", "Retribution")] },
  ] }, "PALADIN");
  assert.equal(data.n, 3);
  assert.equal(data.hybrid, 1);
  assert.equal(data.lastT, 7);
  assert.deepEqual(data.trees.map((x) => [x.tree, x.n]), [["Holy", 0], ["Protection", 0], ["Retribution", 2]]);
  assert.deepEqual(data.trees[2].talents, [
    { name: "Conviction", icon: "x", max: 3, n: 2, pct: 100, avgRank: 1.5 },
    { name: "Benediction", icon: "x", max: 3, n: 1, pct: 50, avgRank: 3 },
  ]);
  // A Holy talent seen on a Retribution player still lists under Holy, unpicked by any Holy player.
  assert.deepEqual(data.trees[0].talents.map((x) => [x.name, x.n]), [["Divine Intellect", 0]]);
});

test("unknown builds are counted apart from hybrids", () => {
  const records = ["Arms", "Arms/Fury", "Unknown build"].map((classification) => ({ classFile: "WARRIOR", classification, talents: [{ node: 1, name: "X", branch: "Arms", rank: 1, max: 1 }] }));
  const data = classProfile({ records }, "WARRIOR");
  assert.deepEqual([data.hybrid, data.unknown, data.trees[0].n], [1, 1, 1]);
});

for (const [cls, label, trees] of [["paladin", "Paladin", ["Holy", "Protection", "Retribution"]], ["warrior", "Warrior", ["Arms", "Fury", "Protection"]], ["druid", "Druid", ["Balance", "Feral Combat", "Restoration"]], ["hunter", "Hunter", ["Beast Mastery", "Marksmanship", "Survival"]], ["shaman", "Shaman", ["Elemental", "Enhancement", "Restoration"]], ["priest", "Priest", ["Discipline", "Holy", "Shadow"]], ["rogue", "Rogue", ["Assassination", "Combat", "Subtlety"]], ["mage", "Mage", ["Arcane", "Fire", "Frost"]], ["warlock", "Warlock", ["Affliction", "Demonology", "Destruction"]]]) test(`built ${cls} route has three pre-rendered spec columns and a sitemap entry`, () => {
  const pageUrl = new URL(`../static/forever/${cls}/index.html`, import.meta.url);
  const html = fs.readFileSync(pageUrl, "utf8");
  assert.deepEqual([...html.matchAll(/data-tree="([^"]+)"/g)].map((m) => m[1]), trees);
  assert.match(html, new RegExp(`<a class="chip" href="/forever/${cls}/" aria-current="page">${label}</a>`));
  assert.match(html, /<ol class="ranks"><li><span class="rank">1<\/span>/);
  assert.ok(html.includes(`<link rel="canonical" href="https://wowcensus.io/forever/${cls}/">`));
  assert.match(html, /<nav class="games page-nav">.*<a class="game" aria-current="true" href="\/talents">Talents<\/a>/s);
  assert.doesNotMatch(html, /<script|https:\/\/wow\.zamimg\.com/);
  for (const [, ref] of html.matchAll(/(?:src|href)="(\.\.\/\.\.\/[^"]*)"/g))
    assert.ok(fs.existsSync(new URL(ref, pageUrl)), `Local asset missing: ${ref}`);
  assert.ok(fs.readFileSync(new URL("../static/sitemap.xml", import.meta.url), "utf8").includes(`<loc>https://wowcensus.io/forever/${cls}/</loc>`));
  assert.ok(fs.readFileSync(new URL("../static/talents.html", import.meta.url), "utf8").includes(`<a href="/forever/${cls}/">Explore ${label} talents by spec</a>`));
});
