// WoW's MLC1 character packing. Keep this aligned with Population/WhoScan.lua.
const FIELDS = [
  "fullName", "name", "firstName", "lastName", "realm", "guild",
  "level", "race", "class", "classFile", "zone", "firstSeen",
  "lastSeen", "seenCount",
];
const NUMERIC = new Set(["level", "firstSeen", "lastSeen", "seenCount"]);

function field(value) {
  const text = value == null ? "" : String(value);
  return `${Buffer.byteLength(text, "utf8")}:${text}`;
}

// A day is [count, first, last] when unpacked here (0-based JS array), or a
// Lua table read by savedvariables.js: { count, firstSeen, lastSeen } or the
// 1-based sequence { "1": count, "2": first, "3": last }.
function dayValues(d) {
  if (Array.isArray(d)) return [d[0] || 0, d[1] || 0, d[2] || 0];
  return [d.count ?? d[1] ?? 0, d.firstSeen ?? d[2] ?? 0, d.lastSeen ?? d[3] ?? 0];
}

function packCharacters(characters) {
  const chunks = ["MLC1"];
  let records = [];
  for (const [key, c] of Object.entries(characters || {})) {
    const days = Object.entries(c.days || {})
      .map(([day, d]) => [day, ...dayValues(d)].join(",")).join(";");
    records.push(field(key) + FIELDS.map((name) => field(c[name])).join("") + field(days));
    if (records.length === 256) { chunks.push(records.join("")); records = []; }
  }
  if (records.length) chunks.push(records.join(""));
  return chunks;
}

function unpackCharacters(chunks) {
  if (chunks && !Array.isArray(chunks))
    chunks = Object.keys(chunks).sort((a, b) => Number(a) - Number(b)).map((k) => chunks[k]);
  if (!Array.isArray(chunks) || chunks[0] !== "MLC1")
    throw new Error("unknown packed population format");
  const characters = {};
  for (const part of chunks.slice(1)) {
    const bytes = Buffer.from(part, "utf8");
    let cursor = 0;
    const read = () => {
      const colon = bytes.indexOf(58, cursor);
      if (colon < 0) throw new Error("truncated packed population field");
      const size = Number(bytes.toString("ascii", cursor, colon));
      if (!Number.isSafeInteger(size) || size < 0 || colon + 1 + size > bytes.length)
        throw new Error("invalid packed population field");
      const value = bytes.toString("utf8", colon + 1, colon + 1 + size);
      cursor = colon + 1 + size;
      return value;
    };
    while (cursor < bytes.length) {
      const key = read(), c = { key, days: {} };
      for (const name of FIELDS) c[name] = NUMERIC.has(name) ? Number(read()) || 0 : read();
      for (const token of read().split(";")) {
        if (!token) continue;
        const match = /^(\d+),(\d+),(\d+),(\d+)$/.exec(token);
        if (!match) throw new Error("invalid packed population day");
        c.days[match[1]] = [Number(match[2]), Number(match[3]), Number(match[4])];
      }
      characters[key] = c;
    }
  }
  return characters;
}

module.exports = { dayValues, packCharacters, unpackCharacters };
