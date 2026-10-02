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

// Normalize line endings so checkouts with different autocrlf agree: a CRLF
// working copy must not stamp published pages with a layout (or stylesheet
// name) the deployed bundle never had.
const readLf = (file) => fs.readFileSync(file, "utf8").replace(/\r\n/g, "\n");

// The stylesheet as bundled, and its content-hashed file name.
function stylesheet() {
  const css = readLf(path.join(repo, "site/public/style.css"));
  return { css, name: "style." + crypto.createHash("sha256").update(css).digest("hex").slice(0, 12) + ".css" };
}

function layout() {
  const cssName = stylesheet().name;
  const hash = crypto.createHash("sha256");
  for (const name of RENDER_MODULES) hash.update(readLf(path.join(repo, "site/src", name)));
  hash.update(cssName);
  return { id: hash.digest("hex").slice(0, 12), css: cssName };
}

function writeLayout() {
  const current = layout();
  fs.writeFileSync(path.join(repo, "pages/_layout.json"), JSON.stringify(current) + "\n");
  return current;
}

module.exports = { stylesheet, layout, writeLayout };

if (require.main === module) {
  console.log(JSON.stringify(process.argv.includes("--write") ? writeLayout() : layout()));
}
