import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { pathToFileURL } from "node:url";
import { CLASS_PROFILES, classProfile, classRoute, renderClassProfile } from "../site/src/class-profile.mjs";
import { PROFILE_GAMES } from "../site/src/profile-games.mjs";

// Rendered pages from laragon-php/render-routes.php (all editions); MARKETLENS_PAGES overrides the folder.
const pages = pathToFileURL((process.env.MARKETLENS_PAGES || path.join(os.homedir(), "Documents", "MarketLensData", "rendered-pages")) + "/");
const page = (p) => new URL(p.replace(/^\//, ""), pages);
// The site serves fonts, favicons and mirrored icons straight from the repository.
const repoFile = (url) => {
  const rel = decodeURIComponent(url.href.slice(pages.href.length));
  if (rel.startsWith("fonts/") || /^(favicon[^/]*|role-icons\.png|notice-discord\.png)$/.test(rel)) return new URL(`../site/public/${rel}`, import.meta.url);
  if (rel.startsWith("assets/")) return new URL(`../assets/wow.zamimg.com/${rel.slice(7)}`, import.meta.url);
  return url;
};

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

test("spec columns rank talents at any sample size", () => {
  const talent = { node: 1, name: "Divine Intellect", branch: "Holy", rank: 1, max: 5 };
  const records = (n) => Array.from({ length: n }, () => ({ classFile: "PALADIN", classification: "Holy", level: 20, talents: [talent] }));
  const opts = { canonical: "https://wowcensus.io/tbc/paladin/", stylesheet: "style.css" };
  const small = renderClassProfile({ records: records(1) }, "classic-progression", "PALADIN", opts);
  assert.doesNotMatch(small, /Latest inspection|Last 14 days|class="fresh"/);
  assert.doesNotMatch(small, /Not enough data/);
  assert.match(small, /<ol class="ranks"><li><span class="rank">1<\/span>/);
  assert.doesNotMatch(small, /class="pips"|Average rank/);
  assert.match(small, /class="pick"><b>100%<\/b><div class="mini"><i style="width:100%"><\/i>/);
});

test("modern profiles use inspected specs and retain counts when talent nodes are unavailable", () => {
  const records = [
    { classFile: "DRUID", classification: "Guardian", level: 60, talents: [] },
    { classFile: "DRUID", classification: "Feral", level: 61, talents: [{ node: 1, name: "Observed pick", branch: "Tree 42", rank: 1, max: 1 }] },
  ];
  const data = classProfile({ records }, "DRUID", "retail");
  assert.equal(data.n, 2);
  assert.deepEqual(data.trees.map((tree) => [tree.tree, tree.n]), [["Balance", 0], ["Feral", 1], ["Guardian", 1], ["Restoration", 0]]);
  assert.equal(data.trees[1].talents[0].name, "Observed pick");
  assert.deepEqual(classProfile({ records: [{ classFile: "ROGUE", classification: "Combat", talents: [] }] }, "ROGUE", "mop-classic").trees.map((tree) => tree.tree), ["Assassination", "Combat", "Subtlety"]);
});

test("every edition has only its playable class routes", () => {
  assert.deepEqual(Object.keys(CLASS_PROFILES), Object.keys(PROFILE_GAMES));
  for (const [source, game] of Object.entries(CLASS_PROFILES)) {
    assert.ok(game.classes.includes("DRUID"));
    assert.equal(classRoute(source, "DRUID"), `/${game.dir}/druid/`);
    assert.equal(game.classes.includes("MONK"), ["mop-classic", "retail"].includes(source));
    assert.equal(game.classes.includes("EVOKER"), source === "retail");
  }
});

const CLASS_GAMES = [{ dir: "forever", home: "/", talentsPage: "talents.html" }, { dir: "tbc", home: "/tbc/", talentsPage: "tbc/talents.html" }];
for (const game of CLASS_GAMES) for (const [cls, label, trees] of [["paladin", "Paladin", ["Holy", "Protection", "Retribution"]], ["warrior", "Warrior", ["Arms", "Fury", "Protection"]], ["druid", "Druid", ["Balance", "Feral Combat", "Restoration"]], ["hunter", "Hunter", ["Beast Mastery", "Marksmanship", "Survival"]], ["shaman", "Shaman", ["Elemental", "Enhancement", "Restoration"]], ["priest", "Priest", ["Discipline", "Holy", "Shadow"]], ["rogue", "Rogue", ["Assassination", "Combat", "Subtlety"]], ["mage", "Mage", ["Arcane", "Fire", "Frost"]], ["warlock", "Warlock", ["Affliction", "Demonology", "Destruction"]]]) test(`built ${game.dir}/${cls} route has three pre-rendered spec columns and a sitemap entry`, () => {
  const pageUrl = page(`${game.dir}/${cls}/index.html`);
  const html = fs.readFileSync(pageUrl, "utf8");
  assert.deepEqual([...html.matchAll(/data-tree="([^"]+)"/g)].map((m) => m[1]), trees);
  assert.match(html, new RegExp(`<a class="chip" href="/${game.dir}/${cls}/" aria-current="page"><img[^>]+width="20" height="20"><span>${label}</span></a>`));
  assert.match(html, /<div class="site-nav-stack">\s*<nav class="games game-nav"[^>]*>[\s\S]*?<\/nav>\s*<nav class="games page-nav"/);
  // Every column either ranks talents or says why it does not.
  const bodies = [...html.matchAll(/<div class="card-b">(.*?)<\/div><div class="card-f">/gs)].map((m) => m[1]);
  assert.equal(bodies.length, 3);
  for (const body of bodies) assert.match(body, /^<ol class="ranks"><li><span class="rank">1<\/span>|^<p class="colnote">/);
  if (game.dir === "forever") assert.match(html, /<ol class="ranks">/);
  assert.ok(html.includes(`<link rel="canonical" href="https://wowcensus.io/${game.dir}/${cls}/">`));
  assert.ok(html.includes(`<a class="game" aria-current="true" href="${game.home}talents">Talents</a>`));
  assert.doesNotMatch(html, /<script|https:\/\/wow\.zamimg\.com/);
  for (const [, ref] of html.matchAll(/(?:src|href)="(\.\.\/\.\.\/[^"]*)"/g))
    assert.ok(fs.existsSync(repoFile(new URL(ref, pageUrl))), `Local asset missing: ${ref}`);
  assert.ok(fs.readFileSync(page("sitemap.xml"), "utf8").includes(`<loc>https://wowcensus.io/${game.dir}/${cls}/</loc>`));
  assert.ok(fs.readFileSync(page(`${game.talentsPage}`), "utf8").includes(`<a class="chip" href="/${game.dir}/${cls}/">`));
});

test("every configured class route is built, linked from talents, and listed in the sitemap", () => {
  const sitemap = fs.readFileSync(page("sitemap.xml"), "utf8");
  for (const [source, game] of Object.entries(CLASS_PROFILES)) {
    const talents = fs.readFileSync(page(`${game.home.replace(/^\//, "")}talents.html`), "utf8");
    for (const cf of game.classes) {
      const route = classRoute(source, cf);
      const html = fs.readFileSync(page(`${route}index.html`), "utf8");
      assert.ok(sitemap.includes(`<loc>https://wowcensus.io${route}</loc>`), route);
      assert.ok(talents.includes(`<a class="chip" href="${route}">`), route);
      assert.ok(html.includes(`<link rel="canonical" href="https://wowcensus.io${route}">`), route);
      assert.match(html, /data-tree="[^"]+"/, route);
    }
  }
});

test("built MoP Mage page has spell icons and ranks every spec", () => {
  const html = fs.readFileSync(page("mop/mage/index.html"), "utf8");
  assert.doesNotMatch(html, /inv_misc_questionmark\.jpg/);
  assert.match(html, /spell_fire_burningspeed\.jpg/);
  assert.doesNotMatch(html, /Not enough data/);
});
