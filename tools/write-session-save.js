#!/usr/bin/env node
// Write one session's data from a WoW save to its own Lua file.
//
//   node tools/write-session-save.js --name=classic [--out=DIR] <MarketLens.lua|WoWCensus.lua> [NameplateInspect.lua]
//
// Each save holds up to 14 days of every realm bucket. This writes only what
// was collected since the last session file from the same save into
// <out>/wow-<name>-<YYYYMMDD-HHMMSS>.lua: AH snapshots, seller sightings, /who
// samples, characters seen (with only the days seen), sweep queries and
// locations, spec samples and inspects newer than that file. Playing Alliance,
// switching to Horde and playing again gives two files with nothing shared.
// The file keeps the save's layout, so the realm exporters read it like a save.
// Census planner state is the addon's working memory, not collected data, and
// is left out. A day's sighting count is that day's total so far (the importer
// keeps the larger count). State lives in <out>/.session-state.json; the first
// run for a save only records where it stands and writes nothing.
//
// Last line printed: WROTE <path>, NOTHING NEW, or BASELINE.

const fs = require("node:fs");
const path = require("node:path");
const os = require("node:os");
const { readSavedVariables } = require("./savedvariables.js");
const { packCharacters, unpackCharacters } = require("./population-packed.js");

const DEFAULT_OUT = process.env.MARKETLENS_SESSION_DIR || path.join(os.homedir(), "Documents", "WoW Census Saves");
// Fields that move forward whenever a bucket collects something.
const STAMP_KEYS = new Set(["t", "time", "lastScan", "lastSeen", "lastSellerSample", "startedAt", "completedAt", "finishedAt"]);

function newest(value) {
  let max = 0;
  const walk = (v) => {
    if (!v || typeof v !== "object") return;
    for (const [k, x] of Object.entries(v)) {
      if (typeof x === "number" && STAMP_KEYS.has(k) && x > max) max = x;
      else if (x && typeof x === "object") walk(x);
    }
  };
  walk(value);
  return max;
}

// Byte ranges of each entry in a table body, so entries are copied verbatim
// (the parser turns every key into a string, which would change [1] to ["1"]).
function entries(source, open) {
  const out = [];
  let p = open + 1;
  const ws = () => {
    while (p < source.length) {
      if (/\s/.test(source[p])) p++;
      else if (source.startsWith("--", p)) { p = source.indexOf("\n", p); if (p < 0) p = source.length; }
      else break;
    }
  };
  const skipString = () => {
    p++;
    while (p < source.length) {
      const c = source[p++];
      if (c === '"') return;
      if (c === "\\") p++;
    }
    throw new Error("unterminated string");
  };
  const skipValue = () => {
    ws();
    if (source[p] === '"') return skipString();
    if (source[p] !== "{") {
      while (p < source.length && /[A-Za-z0-9_.+\-]/.test(source[p])) p++;
      return;
    }
    let depth = 0;
    while (p < source.length) {
      const c = source[p];
      if (c === '"') { skipString(); continue; }
      if (c === "-" && source[p + 1] === "-") { p = source.indexOf("\n", p); if (p < 0) p = source.length; continue; }
      p++;
      if (c === "{") depth++;
      else if (c === "}" && --depth === 0) return;
    }
    throw new Error("unterminated table");
  };
  while (true) {
    ws();
    if (source[p] === "}") return out;
    const start = p;
    let key = null;
    if (source[p] === "[") {
      p++;
      ws();
      const keyStart = p;
      skipValue();
      const raw = source.slice(keyStart, p);
      key = raw.startsWith('"') ? JSON.parse(raw) : raw;
      ws();
      p++; // ]
      ws();
      p++; // =
    }
    const valueStart = p;
    skipValue();
    out.push({ key, start, end: p, valueStart });
    ws();
    if (source[p] === "," || source[p] === ";") p++;
  }
}

const list = (table) => Object.keys(table || {}).sort((a, b) => Number(a) - Number(b)).map((k) => table[k]);
const scalars = (table) => Object.fromEntries(Object.entries(table).filter(([, v]) => v === null || typeof v !== "object"));
const size = (table) => Object.keys(table).length;

// Packed AH history ("ML1\n", one tab-separated line per item, 8 numbers per
// snapshot starting with its time): keep the snapshots newer than `since`.
function newSnapshots(packed, since) {
  const lines = ["ML1\n"];
  for (const line of String(packed || "").split("\n").slice(1)) {
    const f = line.split("\t");
    if (f.length < 7) continue;
    const nums = f[6].split(","), kept = [];
    for (let i = 0; i + 8 <= nums.length; i += 8) if (Number(nums[i]) > since) kept.push(...nums.slice(i, i + 8));
    if (kept.length) lines.push(f.slice(0, 6).join("\t") + "\t" + kept.join(",") + "\n");
  }
  return lines.length > 1 ? lines : null;
}

// The part of a realm bucket collected after `since`, and how many rows that is.
function sessionBucket(bucket, since) {
  const out = scalars(bucket);
  delete out.itemsPacked; // a string, but it is the whole AH history
  let rows = 0;
  if (bucket.identity) out.identity = bucket.identity;
  const packed = bucket.itemsPacked && newSnapshots(bucket.itemsPacked, since);
  if (packed) {
    out.itemsPacked = packed.join("");
    rows += packed.length - 1;
  }
  if (bucket.items && typeof bucket.items === "object") {
    const items = {};
    for (const [id, rec] of Object.entries(bucket.items)) {
      const snaps = list(rec.snaps).filter((sn) => (sn.t || 0) > since);
      if (snaps.length) items[id] = { ...rec, snaps };
    }
    if (size(items)) out.items = items;
    rows += size(items);
  }
  const sellers = {};
  for (const [owner, rec] of Object.entries(bucket.sellers || {})) {
    if ((rec.lastSeen || 0) > since) sellers[owner] = { ...rec, hist: list(rec.hist).filter((h) => (h.t || 0) > since) };
  }
  if (size(sellers)) {
    out.sellers = sellers;
    if (bucket.lastSellerScanStats) out.lastSellerScanStats = bucket.lastSellerScanStats;
    rows += size(sellers);
  }
  const pop = bucket.population;
  if (pop) {
    const p = scalars(pop);
    const samples = list(pop.samples).filter((x) => (x.t || 0) > since);
    const specSamples = list(pop.specSamples).filter((x) => (x.t || x.time || 0) > since);
    const builds = Object.fromEntries(Object.entries(pop.inspectBuilds || {}).filter(([, e]) => (e.time || 0) > since));
    const characters = {};
    const known = pop.charactersPacked ? unpackCharacters(pop.charactersPacked) : (pop.characters || {});
    for (const [key, c] of Object.entries(known)) {
      if ((c.lastSeen || 0) <= since) continue;
      // Packed days unpack as [count, first, last]; table days are Lua arrays.
      const days = {};
      for (const [day, d] of Object.entries(c.days || {})) {
        const [count, firstSeen, lastSeen] = Array.isArray(d) ? d
          : [d.count ?? d[1], d.firstSeen ?? d[2], d.lastSeen ?? d[3]];
        if ((lastSeen || 0) > since) days[day] = { count, firstSeen, lastSeen };
      }
      characters[key] = { ...c, days };
    }
    const sweeps = {};
    let sweepRows = 0;
    for (const [id, sweep] of Object.entries(pop.sweeps || {})) {
      const queries = list(sweep.queries).filter((q) => (q.t || 0) > since);
      const observations = Object.fromEntries(Object.entries(sweep.observations || {})
        .filter(([, o]) => (o.observedAt || o[2] || 0) > since));
      if (!queries.length && !size(observations)) continue;
      sweeps[id] = { ...scalars(sweep), queries, observations };
      sweepRows += queries.length + size(observations);
    }
    if (samples.length) p.samples = samples;
    if (specSamples.length) p.specSamples = specSamples;
    if (size(builds)) p.inspectBuilds = builds;
    if (size(characters)) p.charactersPacked = packCharacters(characters);
    if (size(sweeps)) p.sweeps = sweeps;
    out.population = p;
    rows += samples.length + specSamples.length + size(builds) + size(characters) + sweepRows;
  }
  return { bucket: out, rows };
}

// Lua in the save's own style. The saves have no digit-only string keys, so
// digit-only keys are written as numbers (arrays and item IDs).
function lua(value) {
  if (value === null || value === undefined) return "nil";
  if (typeof value === "string") {
    const escapes = { "\\": "\\\\", '"': '\\"', "\n": "\\n", "\r": "\\r", "\0": "\\0" };
    return '"' + value.replace(/[\\"\n\r\0]/g, (c) => escapes[c]) + '"';
  }
  if (typeof value !== "object") return String(value);
  const pairs = Array.isArray(value) ? value.map((v, i) => [String(i + 1), v]) : Object.entries(value);
  return "{\n" + pairs.map(([k, v]) => `[${/^[1-9]\d*$/.test(k) ? k : lua(k)}] = ${lua(v)},\n`).join("") + "}";
}

function topTable(source, variable) {
  const m = source.match(new RegExp("(?:^|\\n)\\s*" + variable + "\\s*=\\s*\\{"));
  if (!m) throw new Error(`${variable} not found`);
  return source.indexOf("{", m.index);
}

function stamp(date) {
  const z = (n) => String(n).padStart(2, "0");
  return `${date.getFullYear()}${z(date.getMonth() + 1)}${z(date.getDate())}-${z(date.getHours())}${z(date.getMinutes())}${z(date.getSeconds())}`;
}

function main() {
  const args = process.argv.slice(2);
  const name = (args.find((a) => a.startsWith("--name=")) || "").slice(7);
  const outDir = (args.find((a) => a.startsWith("--out=")) || "").slice(6) || DEFAULT_OUT;
  const [savePath, inspectPath] = args.filter((a) => !a.startsWith("--")).map((a) => path.resolve(a));
  if (!name || !savePath) throw new Error("usage: write-session-save.js --name=<edition> [--out=DIR] <save.lua> [NameplateInspect.lua]");

  const statePath = path.join(outDir, ".session-state.json");
  const state = fs.existsSync(statePath) ? JSON.parse(fs.readFileSync(statePath, "utf8")) : {};
  const previous = state[savePath];

  const source = fs.readFileSync(savePath, "utf8");
  const variable = /(?:^|\n)\s*WoWCensusDB\s*=/.test(source) ? "WoWCensusDB" : "MarketLensDB";
  const db = readSavedVariables(savePath, variable);
  const buckets = {};
  for (const [key, bucket] of Object.entries(db.realms || {})) buckets[key] = newest(bucket);

  let inspectSource = "", inspects = {};
  if (inspectPath && fs.existsSync(inspectPath)) {
    inspectSource = fs.readFileSync(inspectPath, "utf8");
    if (/(?:^|\n)\s*NameplateInspectDB\s*=\s*\{/.test(inspectSource)) {
      for (const [guid, entry] of Object.entries(readSavedVariables(inspectPath, "NameplateInspectDB") || {}))
        inspects[guid] = Number(entry && entry.time) || 0;
    }
  }
  const inspectNewest = Math.max(0, ...Object.values(inspects));
  const record = { buckets, inspectTime: inspectNewest };

  if (!previous) {
    state[savePath] = record;
    fs.mkdirSync(outDir, { recursive: true });
    fs.writeFileSync(statePath, JSON.stringify(state, null, 1));
    console.log("BASELINE");
    return;
  }

  const parts = {};
  for (const k of Object.keys(buckets)) {
    if (buckets[k] <= (previous.buckets[k] || 0)) continue;
    const part = sessionBucket(db.realms[k], previous.buckets[k] || 0);
    if (part.rows) parts[k] = part;
  }
  const newBuckets = Object.keys(parts);
  const newInspects = Object.keys(inspects).filter((g) => inspects[g] > (previous.inspectTime || 0));
  // Advance every bucket checked: one with activity but no rows (planner state
  // only) has nothing to write.
  const advance = () => {
    for (const k of Object.keys(buckets)) previous.buckets[k] = Math.max(previous.buckets[k] || 0, buckets[k]);
    previous.inspectTime = Math.max(previous.inspectTime || 0, inspectNewest);
    fs.writeFileSync(statePath, JSON.stringify(state, null, 1));
  };
  if (!newBuckets.length && !newInspects.length) {
    advance();
    console.log("NOTHING NEW");
    return;
  }

  const top = topTable(source, variable);
  const topEntries = entries(source, top);
  const realmsEntry = topEntries.find((e) => e.key === "realms");
  const lines = [
    `-- WoW Census session save: ${name}, written ${new Date().toISOString()}`,
    `-- From ${savePath}`,
    `-- ${newBuckets.map((k) => `${k}: ${parts[k].rows} rows`).join("; ") || "no realm data"}; inspects: ${newInspects.length}`,
    `${variable} = {`,
  ];
  for (const e of topEntries) if (e !== realmsEntry) lines.push(source.slice(e.start, e.end) + ",");
  lines.push('["realms"] = {');
  for (const k of newBuckets) lines.push(`[${lua(k)}] = ${lua(parts[k].bucket)},`);
  lines.push("},", "}");
  if (/(?:^|\n)\s*NameplateInspectDB\s*=\s*\{/.test(inspectSource)) {
    const wanted = new Set(newInspects);
    lines.push("NameplateInspectDB = {");
    for (const e of entries(inspectSource, topTable(inspectSource, "NameplateInspectDB")))
      if (wanted.has(e.key)) lines.push(inspectSource.slice(e.start, e.end) + ",");
    lines.push("}");
  }

  fs.mkdirSync(outDir, { recursive: true });
  const base = `wow-${name}-${stamp(new Date())}`;
  let file = path.join(outDir, base + ".lua");
  for (let n = 2; fs.existsSync(file); n++) file = path.join(outDir, `${base}-${n}.lua`);
  fs.writeFileSync(file + ".tmp", lines.join("\n") + "\n");
  fs.renameSync(file + ".tmp", file);
  advance();
  console.log(`WROTE ${file}`);
}

main();
