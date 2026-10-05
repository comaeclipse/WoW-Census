#!/usr/bin/env node
// Recover a WoW SavedVariables file that exceeds Lua 5.1's constant limit.
// Only replace population.characters tables; keep every other byte intact.
const fs = require("node:fs");
const path = require("node:path");
const { readSavedVariables } = require("./savedvariables.js");
const { packCharacters, unpackCharacters } = require("./population-packed.js");

const [input, output] = process.argv.slice(2);
if (!input || !output || path.resolve(input) === path.resolve(output))
  throw new Error("usage: node pack-savedvariables-population.js <input.lua> <output.lua>");

const source = fs.readFileSync(input, "utf8");
const db = readSavedVariables(input);
const realms = Object.entries(db.realms || {}).filter(([, r]) => r.population?.characters);
const matches = [...source.matchAll(/\["characters"\]\s*=\s*\{/g)];
if (matches.length !== realms.length)
  throw new Error(`found ${matches.length} character tables for ${realms.length} realm buckets`);

function tableEnd(open) {
  let depth = 0, inString = false;
  for (let i = open; i < source.length; i++) {
    const c = source[i];
    if (inString) {
      if (c === "\\") i++;
      else if (c === '"') inString = false;
    } else if (c === '"') inString = true;
    else if (c === "{") depth++;
    else if (c === "}" && --depth === 0) return i + 1;
  }
  throw new Error("unterminated character table");
}

function luaString(value) {
  return '"' + value.replace(/["\\\x00-\x1f\x7f]/g, (c) => {
    if (c === '"') return '\\"';
    if (c === "\\") return "\\\\";
    return "\\" + c.charCodeAt(0).toString().padStart(3, "0");
  }) + '"';
}

const edits = matches.map((m, i) => {
  const [realm, record] = realms[i];
  const original = record.population.characters;
  const packed = packCharacters(original);
  if (Object.keys(unpackCharacters(packed)).length !== Object.keys(original).length)
    throw new Error(`packing changed character count for ${realm}`);
  const start = m.index, open = source.indexOf("{", start + m[0].indexOf("="));
  const end = tableEnd(open);
  console.log(`${realm}: ${Object.keys(original).length} characters in ${packed.length - 1} packed chunks`);
  return { start, end, replacement: '["charactersPacked"] = {\n' + packed.map(luaString).join(",\n") + "\n}" };
});

let repaired = source;
for (const edit of edits.reverse())
  repaired = repaired.slice(0, edit.start) + edit.replacement + repaired.slice(edit.end);
fs.writeFileSync(output, repaired);
const verify = readSavedVariables(output);
for (const [realm, record] of realms) {
  const restored = unpackCharacters(verify.realms[realm].population.charactersPacked);
  if (Object.keys(restored).length !== Object.keys(record.population.characters).length)
    throw new Error(`repaired file lost characters for ${realm}`);
}
console.log(`Wrote ${output} (${Buffer.byteLength(repaired)} bytes)`);
