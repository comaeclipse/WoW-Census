#!/usr/bin/env node
// Build a readable Markdown companion for each published HTML content page.
const fs = require('node:fs');
const path = require('node:path');
const { parseHTML } = require('../site/node_modules/linkedom');
const TurndownService = require('../site/node_modules/turndown');
const { gfm } = require('../site/node_modules/turndown-plugin-gfm');

const root = path.resolve(__dirname, '..', 'pages');
const editions = ['', 'tbc', 'classic', 'sod', 'mop', 'retail'];
const names = ['index', 'combos', 'auctionhouse', 'guilds', 'geography', 'talents'];
const routes = [];
const yaml = (value) => JSON.stringify(value || '');
const cell = (value) => String(value ?? '').replace(/\|/g, '\\|').replace(/\s+/g, ' ').trim();
const classNames = { DEATHKNIGHT: 'Death Knight', DEMONHUNTER: 'Demon Hunter' };
const className = (value) => classNames[value] || String(value || 'Unknown class').toLowerCase().replace(/^./, (c) => c.toUpperCase());

function talentSummary(data) {
  const records = data.records || [];
  const rows = records.filter((r) => r.faction && r.classification &&
    r.classification !== 'Unknown build' && r.classification !== 'Unresolved');
  const grouped = new Map();
  const classTotals = new Map();
  for (const row of rows) {
    const key = [row.faction, row.classFile, row.classification].join('\t');
    const entry = grouped.get(key) || { count: 0, hybrid: false };
    entry.count++;
    entry.hybrid ||= !!row.hybrid;
    grouped.set(key, entry);
    const classKey = [row.faction, row.classFile].join('\t');
    classTotals.set(classKey, (classTotals.get(classKey) || 0) + 1);
  }
  const lines = ['## Observed talent builds', '',
    'Shares are within the classified inspect sample for each faction and class, not the full character population.',
    '', '| Faction | Class | Build | Players | Share |', '| --- | --- | --- | ---: | ---: |'];
  for (const [key, entry] of [...grouped].sort((a, b) => b[1].count - a[1].count || a[0].localeCompare(b[0]))) {
    const [faction, classFile, build] = key.split('\t');
    const share = (100 * entry.count / classTotals.get([faction, classFile].join('\t'))).toFixed(1);
    lines.push(`| ${cell(faction)} | ${cell(className(classFile))} | ${cell(build)}${entry.hybrid ? ' Hybrid' : ''} | ${entry.count} | ${share}% |`);
  }
  const denominators = new Map();
  const talents = new Map();
  for (const row of records.filter((r) => r.talents?.length)) {
    denominators.set(row.classFile, (denominators.get(row.classFile) || 0) + 1);
    for (const talent of row.talents) {
      const key = `${row.classFile}\t${talent.node}`;
      const entry = talents.get(key) || { classFile: row.classFile, name: talent.name, count: 0 };
      entry.count++;
      talents.set(key, entry);
    }
  }
  if (talents.size) {
    lines.push('', '## Selected talent popularity', '',
      'Shares use inspected players with readable talent selections in each class.', '',
      '| Class | Talent | Players selecting | Share |', '| --- | --- | ---: | ---: |');
    for (const entry of [...talents.values()].sort((a, b) =>
      b.count / denominators.get(b.classFile) - a.count / denominators.get(a.classFile) ||
      b.count - a.count || String(a.name).localeCompare(String(b.name)))) {
      const denominator = denominators.get(entry.classFile);
      lines.push(`| ${cell(className(entry.classFile))} | ${cell(entry.name)} | ${entry.count} / ${denominator} | ${(100 * entry.count / denominator).toFixed(1)}% |`);
    }
  }
  return lines.join('\n');
}

// The Markdown companion of one rendered content page.
function htmlToMarkdown(source) {
  const { document } = parseHTML(source);
  const title = document.querySelector('title')?.textContent.trim() || 'WoWCensus';
  const description = document.querySelector('meta[name="description"]')?.getAttribute('content') || '';
  const canonical = document.querySelector('link[rel="canonical"]')?.getAttribute('href') || '';
  const talentData = document.querySelector('#talent-data')?.textContent;
  for (const el of [...document.querySelectorAll('script, style, nav, header, footer, .crt, .page-controls, .talent-filters, button, select, img, .ptitle a')]) el.remove();
  // Headings are styled divs in the browser layout. Give them semantic structure here.
  for (const el of [...document.querySelectorAll('.ptitle')]) {
    const heading = document.createElement('h2');
    heading.textContent = el.textContent;
    el.replaceWith(heading);
  }
  for (const el of [...document.querySelectorAll('.tile')]) {
    const label = el.querySelector('.k')?.textContent.trim();
    const value = el.querySelector('.v')?.textContent.trim();
    const detail = el.querySelector('.s')?.textContent.trim();
    if (label && value) el.textContent = `${label}: ${value}${detail ? ` (${detail})` : ''}`;
  }
  const turndown = new TurndownService({ headingStyle: 'atx', bulletListMarker: '-', codeBlockStyle: 'fenced' });
  turndown.use(gfm);
  const body = document.querySelector('.wrap') || document.body;
  const content = turndown.turndown(body.innerHTML).trim();
  const frontmatter = ['---', `title: ${yaml(title)}`];
  if (description) frontmatter.push(`description: ${yaml(description)}`);
  if (canonical) frontmatter.push(`url: ${yaml(canonical)}`);
  frontmatter.push('---', '', `# ${title}`, '');
  let markdown = frontmatter.join('\n') + content + '\n';
  if (talentData) markdown += '\n' + talentSummary(JSON.parse(talentData)) + '\n';
  return markdown;
}

module.exports = { htmlToMarkdown };

function main() {
  for (const edition of editions) {
    for (const name of names) {
      const dir = path.join(root, edition);
      const html = path.join(dir, `${name}.html`);
      if (!fs.existsSync(html)) throw new Error(`Missing content page: ${html}`);
      fs.writeFileSync(path.join(dir, `${name}.md`), htmlToMarkdown(fs.readFileSync(html, 'utf8')));
      const prefix = edition ? `/${edition}` : '';
      routes.push(name === 'index' ? (prefix || '/') + (prefix ? '/' : '') : `${prefix}/${name}`);
    }
  }

  // Explicit routes keep CSS, JSON, fonts, and other assets on static Pages serving.
  const includes = routes.flatMap((route) => route === '/' ? ['/', '/index.html'] :
    route.endsWith('/') ? [route, route.slice(0, -1), `${route}index.html`] : [route, `${route}.html`]);
  if (includes.length > 100) throw new Error('Pages _routes.json exceeds 100 rules');
  fs.writeFileSync(path.join(root, '_routes.json'), JSON.stringify({ version: 1, include: includes, exclude: [] }, null, 2) + '\n');
  fs.copyFileSync(path.join(__dirname, 'pages-markdown-worker.js'), path.join(root, '_worker.js'));
  console.log(`Built ${routes.length} Markdown page variants`);
}

if (require.main === module) main();
