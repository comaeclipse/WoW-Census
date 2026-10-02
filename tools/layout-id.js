#!/usr/bin/env node
// The layout identity of the wowcensus pages: a hash of the render modules and
// the stylesheet. The static build writes it to pages/_layout.json (bundled into
// pages/_worker.js), and tools/publish-pages.js stamps it on every page it
// publishes to KV. The Pages worker serves a published page only when the two
// match, so a page rendered by other layout code -- or pointing at a stylesheet
// this deploy no longer carries -- is never served.
//
//   node tools/layout-id.js            print the current layout
//   node tools/layout-id.js --write    write pages/_layout.json

const fs = require("fs");
const path = require("path");
const crypto = require("crypto");

const repo = path.resolve(__dirname, "..");
const RENDER_MODULES = ["edition.mjs", "census.mjs", "market.mjs", "guilds.mjs", "geography.mjs", "talents.mjs"];

function layout() {
  const css = fs.readFileSync(path.join(repo, "site/public/style.css"));
  const cssName = "style." + crypto.createHash("sha256").update(css).digest("hex").slice(0, 12) + ".css";
  const hash = crypto.createHash("sha256");
  // Normalize line endings so checkouts with different autocrlf agree.
  for (const name of RENDER_MODULES)
    hash.update(fs.readFileSync(path.join(repo, "site/src", name), "utf8").replace(/\r\n/g, "\n"));
  hash.update(cssName);
  return { id: hash.digest("hex").slice(0, 12), css: cssName };
}

function writeLayout() {
  const current = layout();
  fs.writeFileSync(path.join(repo, "pages/_layout.json"), JSON.stringify(current) + "\n");
  return current;
}

module.exports = { layout, writeLayout };

if (require.main === module) {
  console.log(JSON.stringify(process.argv.includes("--write") ? writeLayout() : layout()));
}
