// Mechanical checks for DESIGN.md against the built static bundle in pages/.
// Run: node --test tools/test-design.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';

const root = new URL('../', import.meta.url);
const read = (p) => fs.readFileSync(new URL(p, root), 'utf8');
const exists = (p) => fs.existsSync(new URL(p, root));
const css = read('site/public/style.css');
const cssName = 'style.' + crypto.createHash('sha256').update(fs.readFileSync(new URL('site/public/style.css', root))).digest('hex').slice(0, 12) + '.css';
const editions = { '': 'Forever', tbc: 'TBC Anniversary', classic: 'Classic Era', sod: 'SoD', mop: 'MoP Classic', retail: 'Retail' };
const pages = ['index', 'combos', 'auctionhouse', 'guilds', 'geography', 'talents'];
const files = Object.keys(editions).flatMap((dir) => pages.map((page) => ({ dir, page, file: 'pages/' + (dir ? dir + '/' : '') + page + '.html' })));
const count = (html, re) => (html.match(re) || []).length;

test('design tokens match DESIGN.md', () => {
  const tokens = { bg: '#0d0f17', panel: '#161a28', panel2: '#1e2334', ink: '#eae7d6', muted: '#8b90a0', gold: '#ffce43', green: '#66d16b', red: '#e8595f', blue: '#5fb0f0', line: '#2b3145', shadow: '#000' };
  const block = css.match(/:root\{([^}]*)\}/)[1];
  const found = Object.fromEntries([...block.matchAll(/--([\w-]+):([^;]+);/g)].map(([, k, v]) => [k, v.trim()]));
  for (const [k, v] of Object.entries(tokens)) assert.equal(found[k], v, '--' + k);
  assert.deepEqual(Object.keys(found).sort(), [...Object.keys(tokens), 'pixel', 'term'].sort(), 'new :root token without a DESIGN.md entry');
});

test('no font face swaps in layout', () => {
  for (const family of ['Press Start 2P', 'VT323', 'Cinzel']) {
    const face = css.match(new RegExp('@font-face\\{[^}]*' + family + '[^}]*\\}'))[0];
    assert.match(face, /font-display:optional/, family);
  }
});

test('every edition ships every page', () => {
  for (const { file } of files) assert.ok(exists(file), file);
  assert.ok(exists('pages/' + cssName), 'bundle is missing the current stylesheet ' + cssName);
});

for (const { dir, page, file } of files) {
  test('anatomy: ' + file, () => {
    const html = read(file);
    assert.match(html, /^<!doctype html><html lang="en">/i);
    assert.match(html, /<meta name="viewport" content="width=device-width,initial-scale=1">/);
    assert.ok(html.includes('href="' + cssName + '"'), 'stale or missing stylesheet (rebuild every edition after CSS changes)');
    for (const font of ['press-start-2p-latin.woff2', 'vt323-latin.woff2', 'cinzel-latin-800-normal.woff2'])
      assert.ok(html.includes('rel="preload" href="/fonts/' + font + '"'), 'preload ' + font);
    assert.match(html, /<div class="crt" aria-hidden="true"><\/div>\s*<div class="wrap">/);
    assert.match(html, /<header class="ihead">\s*<div class="page-heading"><h1 class="iname">WoWCensus<span class="itopic">/);
    const games = html.match(/<nav class="games game-nav">(.*?)<\/nav>/s);
    assert.ok(games, 'game nav');
    assert.equal(count(games[1], /class="game"/g), Object.keys(editions).length, 'one button per edition');
    assert.equal(count(games[1], /aria-current="true"/g), 1, 'one current game');
    assert.ok(games[1].includes('aria-current="true" href="/' + (dir ? dir + '/' : '') + '"'), 'current game is this edition');
    const crumbs = html.match(/<nav class="breadcrumbs" aria-label="Breadcrumb">(.*?)<\/nav>/s);
    assert.ok(crumbs, 'breadcrumbs');
    assert.ok(crumbs[1].includes('>' + editions[dir] + '</a>'), 'breadcrumb names the edition');
    assert.equal(count(crumbs[1], /aria-current="page"/g), 1);
    const nav = html.match(/<div class="page-controls">\s*<nav class="games page-nav">(.*?)<\/nav>/s);
    assert.ok(nav, 'page nav inside .page-controls');
    assert.equal(count(nav[1], /class="game"/g), 5);
    assert.equal(count(nav[1], /aria-current="true"/g), 1, 'one current page');
    assert.ok(html.indexOf('class="breadcrumbs"') < html.indexOf('class="page-controls"'), 'breadcrumbs before page controls');
    for (const [, font] of html.matchAll(/url\("?(\/fonts\/[^")]+)"?\)/g)) assert.ok(exists('pages' + font), font);
    assert.doesNotMatch(html, /fonts\.googleapis|fonts\.gstatic|use\.typekit/);
    for (const [face] of html.matchAll(/@font-face\{[^}]*\}/g)) {
      assert.match(face, /font-display:optional/, 'inline face swaps in layout: ' + face);
      assert.match(face, /format\("woff2"\)/, 'inline face is not woff2: ' + face);
    }
    if (/class="[^"]*\b(combo-analysis|talent-summary)\b/.test(html))
      assert.ok(html.includes('rel="preload" href="/fonts/friz-quadrata-regular.woff2"'), 'prose page preloads Friz Quadrata');
    if (page === 'talents') {
      assert.doesNotMatch(html, /\.talent-combo-table[^{]*\{[^}]*font-size/, 'talent tables keep base type size');
      // Every class view ships as HTML; the chips only show and hide panes.
      assert.match(html, /<div id="talent-content"><div class="vpane" data-class="all">/, 'all-classes view pre-rendered first');
      assert.doesNotMatch(html, /id="talent-data"|application\/json/, 'no embedded data payload');
      const panes = count(html, /<div class="vpane" data-class="/g);
      if (panes > 1) assert.equal(count(html, /<button class="chip" type="button" data-key="/g), panes, 'one class chip per pane');
    }
  });
}
