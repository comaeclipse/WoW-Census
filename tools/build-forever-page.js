#!/usr/bin/env node
// Bakes the /wowforever census into a standalone static bundle in pages/ that
// can be dragged onto Cloudflare Pages (or any static host) -- no Worker, no
// D1, no build step on the other end.
//
//   node tools/build-forever-page.js
//   node tools/build-forever-page.js --url=http://127.0.0.1:8799 --out=pages
//
// It reads the live census from /api/forever and renders it with the same
// module the Worker uses (site/src/census.mjs), so the static copy and the
// live page cannot drift apart. The numbers are frozen at build time: re-run
// this after each upload to refresh them.

const fs = require("fs");
const path = require("path");
const { pathToFileURL } = require("url");

const args = process.argv.slice(2);
function arg(name, fallback) {
  const hit = args.find((a) => a.startsWith("--" + name + "="));
  return hit ? hit.slice(name.length + 3) : fallback;
}

const base = (arg("url", "https://marketlens.skarz.workers.dev") || "").replace(/\/+$/, "");
const repo = path.resolve(__dirname, "..");
const outDir = path.resolve(repo, arg("out", "pages"));

async function main() {
  const { renderCensusHtml } = await import(pathToFileURL(path.join(repo, "site/src/census.mjs")).href);

  process.stdout.write("Fetching " + base + "/api/forever ... ");
  const res = await fetch(base + "/api/forever");
  if (!res.ok) throw new Error("census fetch failed: HTTP " + res.status);
  const census = await res.json();
  const total = (census.groups || []).reduce((a, g) => a + g.characters, 0);
  console.log(total.toLocaleString() + " characters across " + (census.groups || []).length + " faction(s)");

  const built = new Date();
  const html = renderCensusHtml(census, {
    // Relative, so the bundle works at a domain root or under a subpath.
    stylesheet: "style.css",
    // The per-realm pages only exist on the Worker; point back at it.
    realmHref: (game) => base + "/pop?game=" + encodeURIComponent(game),
    back: { href: base + "/pop", label: "MARKETLENS" },
    note: "Static snapshot built " + built.toISOString().slice(0, 16).replace("T", " ") + "Z",
  });

  fs.mkdirSync(outDir, { recursive: true });
  fs.writeFileSync(path.join(outDir, "index.html"), html);
  // The bundle carries its own stylesheet so it renders with nothing else served.
  fs.copyFileSync(path.join(repo, "site/public/style.css"), path.join(outDir, "style.css"));
  fs.writeFileSync(path.join(outDir, "census.json"), JSON.stringify(census, null, 2));

  console.log("Wrote " + path.relative(repo, outDir) + "/{index.html,style.css,census.json}");
  console.log("Drag that folder onto https://dash.cloudflare.com -> Workers & Pages -> Create -> Pages -> Upload assets.");
}

main().catch((e) => { console.error(String((e && e.message) || e)); process.exit(1); });
