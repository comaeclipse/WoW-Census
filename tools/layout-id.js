#!/usr/bin/env node
// Content hash of the static site's render modules and stylesheets.
//
//   node tools/layout-id.js            print the current layout

const fs = require("fs");
const path = require("path");
const crypto = require("crypto");

const repo = path.resolve(__dirname, "..");
const RENDER_MODULES = ["edition.mjs", "census.mjs", "market.mjs", "guilds.mjs", "geography.mjs", "talents.mjs", "gear.mjs", "race-profile.mjs", "race-template.html", "class-profile.mjs", "profile-games.mjs"];

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
  hash.update(readLf(path.join(repo, "site/public/census.css")));
  return { id: hash.digest("hex").slice(0, 12), css: cssName };
}

module.exports = { stylesheet, layout };

if (require.main === module) {
  console.log(JSON.stringify(layout()));
}
