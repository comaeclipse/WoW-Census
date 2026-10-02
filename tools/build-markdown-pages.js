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

// The Markdown companion of one rendered content page.
function htmlToMarkdown(source) {
  const { document } = parseHTML(source);
  const title = document.querySelector('title')?.textContent.trim() || 'WoWCensus';
  const description = document.querySelector('meta[name="description"]')?.getAttribute('content') || '';
  const canonical = document.querySelector('link[rel="canonical"]')?.getAttribute('href') || '';
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
  return frontmatter.join('\n') + content + '\n';
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
