#!/usr/bin/env node
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.resolve(process.argv.find((arg) => arg.startsWith("--out="))?.slice(6) || "static");
const routes = JSON.parse(fs.readFileSync(path.join(root, "_routes.json"), "utf8"));
const source = fs.readFileSync(path.join(root, "_worker.js"), "utf8");
const { default: worker } = await import(`data:text/javascript,${encodeURIComponent(source)}`);
const fetched = [];
const env = { ASSETS: { async fetch(request) {
  const url = new URL(request.url);
  fetched.push(url.pathname);
  const pathname = url.pathname === "/" ? "/index.html" :
    url.pathname.endsWith("/") ? `${url.pathname}index.html` :
    url.pathname.endsWith(".md") ? url.pathname : `${url.pathname}.html`;
  const file = path.join(root, pathname.slice(1));
  if (!fs.existsSync(file)) return new Response("missing", { status: 404 });
  return new Response(fs.readFileSync(file), { headers: {
    "Content-Type": pathname.endsWith(".md") ? "text/markdown; charset=utf-8" : "text/html; charset=utf-8",
    "Vary": "Accept-Encoding",
    "Cache-Control": "public, max-age=300",
  } });
} } };

for (const route of routes.include) {
  const url = `https://wowcensus.io${route}`;
  const html = await worker.fetch(new Request(url), env);
  assert.match(html.headers.get("Content-Type"), /^text\/html/);
  assert.match(html.headers.get("Vary"), /Accept-Encoding, Accept/);
  assert.match(await html.text(), /<!doctype html>/i);
  const md = await worker.fetch(new Request(url, { headers: { Accept: "text/markdown" } }), env);
  assert.match(md.headers.get("Content-Type"), /^text\/markdown/);
  assert.equal(md.headers.get("Cache-Control"), "private, max-age=300");
  assert.equal(md.headers.get("Cloudflare-CDN-Cache-Control"), "no-store");
  assert.match(await md.text(), /^---\n/);
}
assert.equal(routes.include.length, 61);
const before = fetched.length;
await worker.fetch(new Request("https://wowcensus.io/fonts/sample.woff2", { headers: { Accept: "text/markdown" } }), env);
assert.equal(fetched.length, before + 1);
assert.equal(fetched.at(-1), "/fonts/sample.woff2");
const rejected = await worker.fetch(new Request("https://wowcensus.io/", { headers: { Accept: "text/markdown;q=0" } }), env);
assert.match(rejected.headers.get("Content-Type"), /^text\/html/);
console.log(`Verified HTML and Markdown responses for ${routes.include.length} routes`);
