import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { classProfile, renderClassProfile } from "../site/src/class-profile.mjs";
import { MIN_SAMPLE } from "../site/src/profile-games.mjs";

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

test("spec columns below the minimum sample show a note instead of a ranking", () => {
  const talent = { node: 1, name: "Divine Intellect", branch: "Holy", rank: 1, max: 5 };
  const records = (n) => Array.from({ length: n }, () => ({ classFile: "PALADIN", classification: "Holy", level: 20, talents: [talent] }));
  const opts = { canonical: "https://wowcensus.io/tbc/paladin/", stylesheet: "style.css" };
  const small = renderClassProfile({ records: records(MIN_SAMPLE - 1) }, "classic-progression", "PALADIN", opts);
  assert.match(small, /Not enough data yet to rank talents\./);
  assert.doesNotMatch(small, /<ol class="ranks">/);
  const enough = renderClassProfile({ records: records(MIN_SAMPLE) }, "classic-progression", "PALADIN", opts);
  assert.match(enough, /<ol class="ranks"><li><span class="rank">1<\/span>/);
});

const CLASS_GAMES = [{ dir: "forever", home: "/", talentsPage: "talents.html" }, { dir: "tbc", home: "/tbc/", talentsPage: "tbc/talents.html" }];
for (const game of CLASS_GAMES) for (const [cls, label, trees] of [["paladin", "Paladin", ["Holy", "Protection", "Retribution"]], ["warrior", "Warrior", ["Arms", "Fury", "Protection"]], ["druid", "Druid", ["Balance", "Feral Combat", "Restoration"]], ["hunter", "Hunter", ["Beast Mastery", "Marksmanship", "Survival"]], ["shaman", "Shaman", ["Elemental", "Enhancement", "Restoration"]], ["priest", "Priest", ["Discipline", "Holy", "Shadow"]], ["rogue", "Rogue", ["Assassination", "Combat", "Subtlety"]], ["mage", "Mage", ["Arcane", "Fire", "Frost"]], ["warlock", "Warlock", ["Affliction", "Demonology", "Destruction"]]]) test(`built ${game.dir}/${cls} route has three pre-rendered spec columns and a sitemap entry`, () => {
  const pageUrl = new URL(`../static/${game.dir}/${cls}/index.html`, import.meta.url);
  const html = fs.readFileSync(pageUrl, "utf8");
  assert.deepEqual([...html.matchAll(/data-tree="([^"]+)"/g)].map((m) => m[1]), trees);
  assert.match(html, new RegExp(`<a class="chip" href="/${game.dir}/${cls}/" aria-current="page">${label}</a>`));
  // Every column either ranks talents or says why it does not.
  const bodies = [...html.matchAll(/<div class="card-b">(.*?)<\/div><div class="card-f">/gs)].map((m) => m[1]);
  assert.equal(bodies.length, 3);
  for (const body of bodies) assert.match(body, /^<ol class="ranks"><li><span class="rank">1<\/span>|^<p class="colnote">/);
  if (game.dir === "forever") assert.match(html, /<ol class="ranks">/);
  assert.ok(html.includes(`<link rel="canonical" href="https://wowcensus.io/${game.dir}/${cls}/">`));
  assert.ok(html.includes(`<a class="game" aria-current="true" href="${game.home}talents">Talents</a>`));
  assert.doesNotMatch(html, /<script|https:\/\/wow\.zamimg\.com/);
  for (const [, ref] of html.matchAll(/(?:src|href)="(\.\.\/\.\.\/[^"]*)"/g))
    assert.ok(fs.existsSync(new URL(ref, pageUrl)), `Local asset missing: ${ref}`);
  assert.ok(fs.readFileSync(new URL("../static/sitemap.xml", import.meta.url), "utf8").includes(`<loc>https://wowcensus.io/${game.dir}/${cls}/</loc>`));
  assert.ok(fs.readFileSync(new URL(`../static/${game.talentsPage}`, import.meta.url), "utf8").includes(`<a href="/${game.dir}/${cls}/" style="--c:`));
});
