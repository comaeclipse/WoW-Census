#!/usr/bin/env node
// Render every WoWCensus edition from the local SQLite database. The only
// Pages Function selects prebuilt Markdown for content negotiation.
// Usage: node tools/build-static-site.mjs --db=C:/path/marketlens-unified.sqlite
//        node tools/build-static-site.mjs --db=... --out=static
//        node tools/build-static-site.mjs --db=... --source=classic-beta[,retail]
// --source re-renders only those editions and leaves every other edition's files
// untouched.
import fs from "node:fs";
import path from "node:path";
import { spawnSync } from "node:child_process";
import { createRequire } from "node:module";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";
import { localJson } from "./local-site-data.mjs";
import { EDITIONS, PAGES, SITE_ORIGIN, editionPath, pagePath,
  fetchEditionData, buildEditionModels, renderEditionPage } from "../site/src/edition.mjs";
import { RACE_PROFILES, raceRoutes, renderRaceProfile } from "../site/src/race-profile.mjs";
import { CLASS_PROFILES, classRoute, classRoutes, renderClassProfile } from "../site/src/class-profile.mjs";

const require = createRequire(import.meta.url);
const { stylesheet } = require("./layout-id.js");
const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const arg = (name) => process.argv.find((item) => item.startsWith(`--${name}=`))?.slice(name.length + 3);
const databasePath = arg("db");
if (!databasePath) throw new Error("Pass --db=<local SQLite database path>");
const out = path.resolve(repo, arg("out") || "static");
const only = arg("source")?.split(",").filter(Boolean);
for (const source of only || [])
  if (!EDITIONS[source]) throw new Error(`Unknown --source ${source}; use ${Object.keys(EDITIONS).join(", ")}`);
if (!fs.existsSync(databasePath)) throw new Error(`Database missing: ${databasePath}`);
if (out === repo || out === path.join(repo, "pages")) throw new Error("Choose a separate static output directory");
const sqlite = new DatabaseSync(databasePath, { readOnly: true });
const prepared = new Map();
const db = {
  prepare(sql) {
    let statement = prepared.get(sql);
    if (!statement) { statement = sqlite.prepare(sql); prepared.set(sql, statement); }
    const query = (values) => ({
      all: async () => ({ results: statement.all(...values) }),
      first: async () => statement.get(...values) || null,
    });
    return { ...query([]), bind: (...values) => query(values) };
  },
};
const env = { DB: db };
const jsonCache = new Map();
async function getLocalJson(route) {
  if (jsonCache.has(route)) return jsonCache.get(route);
  const value = await localJson(route, env);
  jsonCache.set(route, value);
  return value;
}
function jsonCacheFile(kind, branch) {
  const file = path.join(repo, "tools", kind, `${branch || "retail"}.json`);
  return fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, "utf8")) : {};
}
function applyLocalCaches(source, models) {
  const branch = EDITIONS[source].branch;
  const names = jsonCacheFile("item-names", branch);
  const icons = jsonCacheFile("item-icons", branch);
  const savedNames = new Map(sqlite.prepare(`SELECT item_id, name
    FROM local_auction_snapshots
    WHERE source_game=? AND name!='' AND name NOT LIKE 'item:%'
    GROUP BY item_id`).all(source).map((row) => [row.item_id, row.name]));
  let unresolvedNames = 0;
  for (const view of models.auctionhouse.views)
    for (const item of view.snapshot.items) {
      if (/^item:\d+$/.test(item.name)) item.name = names[item.id] || savedNames.get(item.id) || item.name;
      if (/^item:\d+$/.test(item.name)) unresolvedNames++;
    }
  let unresolvedIcons = 0;
  for (const record of models.gear.inspects.records)
    for (const item of record.gear || []) {
      if (!item.icon) item.icon = icons[item.id] || null;
      if (!item.icon) unresolvedIcons++;
    }
  return { unresolvedNames, unresolvedIcons };
}
function write(file, content) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  // Leave identical files alone so an edition-only build touches only what changed.
  if (fs.existsSync(file) && fs.readFileSync(file).equals(Buffer.from(content))) return;
  fs.writeFileSync(file, content);
  if (fs.statSync(file).size > 25 * 1024 * 1024)
    throw new Error(`Cloudflare Pages asset exceeds 25 MiB: ${file}`);
}
function localizeMirroredAssets() {
  const visit = (dir) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      if (dir === out && entry.name === 'assets') continue;
      const file = path.join(dir, entry.name);
      if (entry.isDirectory()) { visit(file); continue; }
      if (!/\.(?:html|js|css)$/.test(entry.name)) continue;
      const original = fs.readFileSync(file, 'utf8');
      let content = original;
      content = content.replaceAll('https://wow.zamimg.com/', '/assets/');
      // The mirrored library still asks Wowhead for hover-card data at runtime.
      // Static pages render their own names and locally mirrored icons instead.
      content = content.replace(/<script\s+src="\/assets\/js\/tooltips\.js"[^>]*><\/script>/g, '');
      if (content !== original) fs.writeFileSync(file, content);
    }
  };
  visit(out);
}
function outputDir(source) {
  return path.join(out, editionPath(source).replace(/^\//, ""));
}
function writeSiteMetadata() {
  const routes = Object.keys(EDITIONS).flatMap((source) => PAGES.map((page) => pagePath(source, page))).concat(raceRoutes(), classRoutes());
  write(path.join(out, "sitemap.xml"), '<?xml version="1.0" encoding="UTF-8"?>\n' +
    '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' +
    routes.map((route) => `  <url><loc>${SITE_ORIGIN}${route}</loc></url>`).join("\n") +
    '\n</urlset>\n');
  write(path.join(out, "robots.txt"),
    `User-agent: *\nContent-Signal: ai-train=no, search=yes, ai-input=no\nAllow: /\n\nSitemap: ${SITE_ORIGIN}/sitemap.xml\n`);
  write(path.join(out, "llms.txt"), "# WoWCensus\n\nStatic population, auction, guild, geography, talent, and gear snapshots.\n" +
    "Data is collected in game and frozen when these files are built.\n\n" +
    Object.keys(EDITIONS).flatMap((source) => [
      `## ${EDITIONS[source].label}`, "",
      ...PAGES.map((page) => `- [${page}](${SITE_ORIGIN}${pagePath(source, page)})`), "",
    ]).join("\n") + "\n");
  write(path.join(out, ".well-known/ai-catalog.json"), JSON.stringify({
    specVersion: "1.0",
    host: { displayName: "wowcensus", documentationUrl: `${SITE_ORIGIN}/llms.txt` },
    entries: Object.keys(EDITIONS).map((source) => ({
      identifier: `urn:air:wowcensus.io:census:${source}`,
      displayName: `${EDITIONS[source].label} census data`,
      type: "application/json",
      url: `${SITE_ORIGIN}${editionPath(source)}census.json`,
      description: "Observed population from sampled in-game /who results.",
      tags: ["world-of-warcraft", "census", "population"],
    })),
  }, null, 2) + "\n");
  write(path.join(out, "404.html"), '<!doctype html><html lang="en"><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Not found</title>' +
    '<meta name="robots" content="noindex"><h1>Not found</h1><p><a href="/">WoWCensus home</a></p></html>\n');
}
// Pages serves HTML at extensionless URLs (/tbc/combos, /tbc/), which no /*.html
// rule matches, and repeating a header across matching rules comma-joins it, so
// each page gets its own rule. Runs after every HTML file has been written.
function writeHeaders() {
  const pages = [];
  const visit = (dir) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const file = path.join(dir, entry.name);
      if (entry.isDirectory()) visit(file);
      else if (entry.name.endsWith(".html") && file !== path.join(out, "404.html"))
        pages.push("/" + path.relative(out, file).split(path.sep).join("/")
          .replace(/(^|\/)index\.html$/, "$1").replace(/\.html$/, ""));
    }
  };
  visit(out);
  const htmlRules = pages.sort().map((url) => `${url}\n  Cache-Control: public, max-age=300\n\n`);
  // Cloudflare Pages ignores _headers rules past the 100th.
  if (htmlRules.length + 5 > 100) throw new Error(`Too many _headers rules: ${htmlRules.length + 5}`);
  write(path.join(out, "_headers"), htmlRules.join("") +
    `/*.css\n  Cache-Control: public, max-age=31536000, immutable\n\n` +
    `/fonts/*\n  Cache-Control: public, max-age=31536000, immutable\n\n` +
    `/*.json\n  Cache-Control: public, max-age=300\n\n` +
    `/*.md\n  Content-Type: text/markdown; charset=utf-8\n\n` +
    `/*\n  X-Content-Type-Options: nosniff\n`);
}

async function main() {
  fs.mkdirSync(out, { recursive: true });
  const { css, name: cssName } = stylesheet();
  const builtAt = new Date().toISOString();
  const reportFile = path.join(out, "build-report.json");
  const previous = only && fs.existsSync(reportFile) ? JSON.parse(fs.readFileSync(reportFile, "utf8")) : {};
  const report = { builtAt, source: "local SQLite snapshot", editions: { ...previous.editions } };
  if (only) {
    // Untouched editions keep linking their own stylesheet; a changed one needs every edition rebuilt.
    for (const source of Object.keys(EDITIONS).filter((s) => !only.includes(s)))
      if (!fs.existsSync(path.join(outputDir(source), "index.html")) || !fs.existsSync(path.join(outputDir(source), cssName)))
        throw new Error(`${source} has no build with stylesheet ${cssName}; run a full build without --source`);
  }
  for (const source of only || Object.keys(EDITIONS)) {
    const data = await fetchEditionData(source, getLocalJson);
    const models = buildEditionModels(source, data);
    const caches = applyLocalCaches(source, models);
    const dir = outputDir(source);
    fs.mkdirSync(dir, { recursive: true });
    for (const page of PAGES) {
      let html = renderEditionPage(source, page, models[page],
        { stylesheet: cssName, generatedAt: builtAt });
      if (page === "index") {
        const link = `<link rel="stylesheet" href="${cssName}">`;
        if (!html.includes(link)) throw new Error(`Landing page stylesheet missing: ${source}`);
        html = html.replace(link, `<style>${css}</style>`);
      }
      write(path.join(dir, `${page}.html`), html);
    }
    for (const [race, { path: route }] of Object.entries(RACE_PROFILES[source] || {}))
      write(path.join(out, route, "index.html"), renderRaceProfile(data.inspects, source, race, {
        stylesheet: cssName, canonical: SITE_ORIGIN + route,
      }));
    for (const classFile of CLASS_PROFILES[source]?.classes || []) {
      const route = classRoute(source, classFile);
      write(path.join(out, route, "index.html"), renderClassProfile(data.inspects, source, classFile, {
        stylesheet: cssName, canonical: SITE_ORIGIN + route,
      }));
    }
    write(path.join(dir, "inspects.json"), JSON.stringify(data.inspects) + "\n");
    write(path.join(dir, "census.json"), JSON.stringify({ ...data.census,
      units: (data.census.units || []).map(({ guilds, zones, ...unit }) => unit),
    }) + "\n");
    write(path.join(dir, cssName), css);
    report.editions[source] = {
      realmDatasets: data.snaps.length,
      censusCharacters: (data.census.groups || []).reduce((sum, group) => sum + group.characters, 0),
      inspections: data.inspects.records.length,
      ...caches,
    };
    console.log(`${source}: ${report.editions[source].censusCharacters} census characters, ` +
      `${data.snaps.length} auction datasets, ${data.inspects.records.length} inspections`);
  }
  fs.cpSync(path.join(repo, "site/public/fonts"), path.join(out, "fonts"), { recursive: true, force: true });
  for (const name of ["favicon.ico", "favicon-96.png"])
    fs.copyFileSync(path.join(repo, "site/public", name), path.join(out, name));
  const mirrored = path.join(repo, "assets/wow.zamimg.com");
  const oldAssetPath = path.join(out, "assets/wow.zamimg.com");
  if (fs.existsSync(oldAssetPath)) fs.rmSync(oldAssetPath, { recursive: true, force: true });
  if (fs.existsSync(mirrored))
    fs.cpSync(mirrored, path.join(out, "assets"), { recursive: true, force: true });
  writeSiteMetadata();
  const markdown = spawnSync(process.execPath, [path.join(repo, "tools/build-markdown-pages.js"),
    `--out=${out}`,
    ...(only ? [`--edition=${only.map((s) => editionPath(s).replace(/^\/|\/$/g, "")).join(",")}`] : [])],
    { cwd: repo, stdio: "inherit" });
  if (markdown.status !== 0) throw new Error("Markdown build failed");
  localizeMirroredAssets();
  writeHeaders();
  const negotiation = spawnSync(process.execPath, [path.join(repo, "tools/build-markdown-negotiation.mjs"), `--out=${out}`],
    { cwd: repo, stdio: "inherit" });
  if (negotiation.status !== 0) throw new Error("Markdown negotiation build failed");
  write(path.join(out, "build-report.json"), JSON.stringify(report, null, 2) + "\n");
  const verify = spawnSync("python", ["-B", path.join(repo, "tools/verify-static-site.py"), out],
    { cwd: repo, stdio: "inherit" });
  if (verify.status !== 0) throw new Error("Static output verification failed");
  console.log(`Built pure static output in ${out}`);
}
try { await main(); } finally { sqlite.close(); }
