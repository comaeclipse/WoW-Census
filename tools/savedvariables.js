// Parse WoW serialized tables without executing Lua.
const fs = require("node:fs");
function readSavedVariables(file) {
const source = fs.readFileSync(file, "utf8");
let p = source.indexOf("{");
if (p < 0) throw new Error("SavedVariables table not found");

function ws() {
  while (p < source.length) {
    if (/\s/.test(source[p])) { p++; continue; }
    if (source.startsWith("--", p)) {
      p = source.indexOf("\n", p + 2);
      if (p < 0) p = source.length;
      continue;
    }
    break;
  }
}

function string() {
  if (source[p++] !== '"') throw new Error(`expected string at ${p - 1}`);
  let out = "";
  while (p < source.length) {
    const c = source[p++];
    if (c === '"') return out;
    if (c !== "\\") { out += c; continue; }
    const e = source[p++];
    const escapes = { a: "\x07", b: "\b", f: "\f", n: "\n", r: "\r", t: "\t", v: "\v" };
    if (escapes[e] !== undefined) out += escapes[e];
    else if (/\d/.test(e)) {
      let digits = e;
      while (digits.length < 3 && /\d/.test(source[p] || "")) digits += source[p++];
      out += String.fromCharCode(Number(digits));
    } else out += e;
  }
  throw new Error("unterminated string");
}

function atom() {
  const start = p;
  while (p < source.length && /[A-Za-z0-9_.+\-]/.test(source[p])) p++;
  const raw = source.slice(start, p);
  if (raw === "true") return true;
  if (raw === "false") return false;
  if (raw === "nil") return null;
  const n = Number(raw);
  if (raw && Number.isFinite(n)) return n;
  if (raw) return raw;
  throw new Error(`unexpected token at ${p}: ${source.slice(p, p + 20)}`);
}

function value() {
  ws();
  if (source[p] === "{") return table();
  if (source[p] === '"') return string();
  return atom();
}

function table() {
  if (source[p++] !== "{") throw new Error(`expected table at ${p - 1}`);
  const out = {};
  let next = 1;
  while (true) {
    ws();
    if (source[p] === "}") { p++; return out; }
    let key;
    if (source[p] === "[") {
      p++; key = value(); ws();
      if (source[p++] !== "]") throw new Error(`expected ] at ${p - 1}`);
      ws();
      if (source[p++] !== "=") throw new Error(`expected = at ${p - 1}`);
      out[String(key)] = value();
    } else if (source[p] === "{" || source[p] === '"') {
      out[String(next++)] = value();
    } else {
      const mark = p;
      const candidate = atom();
      ws();
      if (source[p] === "=") {
        p++; out[String(candidate)] = value();
      } else {
        p = mark; out[String(next++)] = value();
      }
    }
    ws();
    if (source[p] === "," || source[p] === ";") p++;
  }
}

return value();
}
module.exports = { readSavedVariables };
