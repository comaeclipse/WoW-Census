#!/usr/bin/env node
// Publishes freshly rendered wowcensus pages to KV, so new data goes live
// without rebuilding or redeploying the Pages bundle. pages/_worker.js serves
// a published page in place of the static copy while its layout matches the
// deployed one (see tools/layout-id.js).
//
//   node tools/publish-pages.js                              every edition
//   node tools/publish-pages.js --source=classic-progression one edition
//   node tools/publish-pages.js --dry-run                    render only, no upload
//   node tools/publish-pages.js --url=http://127.0.0.1:8799 --site=https://demo.wowcensus.pages.dev
//
// Pages are rendered here rather than at the edge: the auction-house model is
// megabytes of item rows and takes tens of milliseconds to shape and render,
// while serving finished HTML from KV costs the edge nothing.

const fs = require("fs");
const os = require("os");
const path = require("path");
const { pathToFileURL } = require("url");
const { spawnSync } = require("child_process");
const { layout } = require("./layout-id");
const { resolveModelNames } = require("./build-forever-page");
const { htmlToMarkdown } = require("./build-markdown-pages");

const args = process.argv.slice(2);
function arg(name, fallback) {
  const hit = args.find((a) => a.startsWith("--" + name + "="));
  return hit ? hit.slice(name.length + 3) : fallback;
}
const repo = path.resolve(__dirname, "..");
const api = arg("url", "https://marketlens.skarz.workers.dev").replace(/\/+$/, "");
const site = arg("site", "https://wowcensus.io").replace(/\/+$/, "");

async function getJson(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error(url + " -> HTTP " + res.status);
  return res.json();
}

function namespaceId() {
  const toml = fs.readFileSync(path.join(repo, "wrangler.toml"), "utf8");
  const hit = /binding\s*=\s*"PAGES_KV"\s*\n\s*id\s*=\s*"([0-9a-f]+)"/.exec(toml);
  if (!hit) throw new Error("PAGES_KV namespace id not found in wrangler.toml");
  return hit[1];
}

async function main() {
  const shared = await import(pathToFileURL(path.join(repo, "site/src/edition.mjs")).href);
  const sources = arg("source", Object.keys(shared.EDITIONS).join(",")).split(",");
  for (const source of sources)
    if (!shared.EDITIONS[source]) throw new Error("unknown source game: " + source);

  const current = layout();
  const deployed = await getJson(site + "/_layout.json").catch(() => null);
  if (!deployed || deployed.id !== current.id)
    console.warn("warning: " + site + " is deployed with layout " + (deployed ? deployed.id : "(none)") +
      ", these pages use " + current.id + ".\n  They are published, but served only once this layout is deployed.");

  const generatedAt = new Date().toISOString();
  const entries = [];
  for (const source of sources) {
    const started = Date.now();
    const data = await shared.fetchEditionData(source, (p) => getJson(api + p));
    const models = shared.buildEditionModels(source, data);
    await resolveModelNames(source, models);
    let bytes = 0;
    for (const page of shared.PAGES) {
      // Absolute stylesheet: the same HTML is served from more than one path.
      const html = shared.renderEditionPage(source, page, models[page],
        { stylesheet: shared.editionPath(source) + current.css, generatedAt });
      bytes += Buffer.byteLength(html);
      const metadata = { layout: current.id, generatedAt };
      entries.push({ key: "page:" + source + ":" + page, value: html, metadata });
      // The Markdown companion served to Accept: text/markdown clients.
      entries.push({ key: "md:" + source + ":" + page, value: htmlToMarkdown(html), metadata });
    }
    console.log(source.padEnd(20) + shared.PAGES.length + " pages, " + Math.round(bytes / 1024) + " KB, " +
      (Date.now() - started) + " ms");
  }

  if (args.includes("--dry-run")) return;
  const file = path.join(os.tmpdir(), "wowcensus-pages-" + process.pid + ".json");
  fs.writeFileSync(file, JSON.stringify(entries));
  try {
    const r = spawnSync("npx", ["--yes", "wrangler@latest", "kv", "bulk", "put", JSON.stringify(file),
      "--namespace-id=" + namespaceId(), "--remote"], { stdio: "inherit", shell: true, cwd: repo });
    if (r.status !== 0) throw new Error("wrangler kv bulk put exited " + r.status);
  } finally { fs.rmSync(file, { force: true }); }
  console.log("Published " + entries.length / 2 + " pages (+ Markdown) with layout " + current.id + " (KV edge caches refresh within ~5 min).");
}

main().catch((e) => { console.error(String((e && e.message) || e)); process.exit(1); });
