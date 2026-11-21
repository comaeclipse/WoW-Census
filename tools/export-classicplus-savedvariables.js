#!/usr/bin/env node
// Parse ClassicPlus SavedVariables as data. Never execute the supplied Lua.
const { readSavedVariables } = require("./savedvariables.js");

const file = process.argv[2];
if (!file) throw new Error("usage: node tools/export-classicplus-savedvariables.js FILE.lua");
const db = readSavedVariables(file, "ClassicPlus_Database");
const info = db.Info || {};
if (info.LogVer !== "2.13.0" || info.DataFormat !== "0.8" || db.Packed?.format !== 1)
  throw new Error(`unsupported ClassicPlus format: ${info.LogVer}/${info.DataFormat}/${db.Packed?.format}`);
// Realms lists every realm the player ever logged into; only Packed.realms carries this save's census.
const allKeys = Object.keys(db.Packed?.realms || {});
if (!allKeys.length) throw new Error("expected at least one packed realm");
if (!Number.isInteger(info.SavedAt) || info.SavedAt <= 0) throw new Error("missing SavedAt");
const savedDay = new Date(info.SavedAt * 1000).toISOString().slice(0, 10);
// Scans taken before the client knows the realm name pack under "<id>_"; fold them into the named realm with that id.
const realmKeys = allKeys.filter(key => !/^\d+_$/.test(key));
const aliases = {};
for (const key of allKeys.filter(key => /^\d+_$/.test(key))) {
  const id = Number(key.slice(0, -1));
  const named = realmKeys.filter(k => Number(db.Realms?.[k]?.id) === id && k.startsWith(key));
  const alias = db.Realms?.[key];
  if (named.length !== 1 || !alias || Number(alias.id) !== id ||
      alias.region !== db.Realms[named[0]].region)
    throw new Error(`nameless realm ${key} has no single named realm with the same id and region`);
  (aliases[named[0]] ||= []).push(key);
}

function readRows(realmKey, faction) {
  const rows = [], names = new Set();
  for (const [race, classes] of Object.entries(db.Packed.realms[realmKey][faction] || {})) {
    for (const [characterClass, groups] of Object.entries(classes)) {
      for (const blob of Object.values(groups)) {
        for (const record of String(blob).split(";")) {
          if (!record) continue;
          const fields = record.split(",");
          if (fields.length !== 7 || !/^n\d+$/.test(fields[1]) || !/^s/.test(fields[2]) ||
              !/^s\d{4}-\d{2}-\d{2}$/.test(fields[3]) || !/^n\d+$/.test(fields[4]) ||
              !/^n\d+$/.test(fields[5]) || !/^s/.test(fields[6]))
            throw new Error(`malformed packed record in ${realmKey}`);
          const [name, level, guild, day, sourceId, seenCount, surname] = fields;
          if (!name || names.has(name.toLocaleLowerCase()) || Number(level.slice(1)) < 1 ||
              Number(level.slice(1)) > 60 || Number(seenCount.slice(1)) < 1 ||
              !Number.isFinite(Date.parse(day.slice(1) + "T00:00:00Z")))
            throw new Error(`invalid or duplicate character in ${realmKey}: ${name}`);
          names.add(name.toLocaleLowerCase());
          rows.push([name, race, characterClass, Number(level.slice(1)), guild.slice(1),
                     day.slice(1), Number(sourceId.slice(1)), Number(seenCount.slice(1)), surname.slice(1)]);
        }
      }
    }
  }
  return rows;
}

// Same character in both copies: the later day wins, then more sightings, then the higher level.
const newer = (a, b) => a[5] !== b[5] ? a[5] > b[5] : a[7] !== b[7] ? a[7] > b[7] : a[3] > b[3];

function exportRealm(realmKey) {
  const realm = db.Realms?.[realmKey];
  const match = /^(\d+)_(.+)$/.exec(realmKey);
  const region = String(realm?.region || "").split("/")[1];
  if (!realm || !match || Number(match[1]) !== Number(realm.id) || match[2] !== realm.name ||
      !["us", "eu", "kr"].includes(region) || String(info.LoginServer).toLowerCase() !== region)
    throw new Error(`inconsistent realm identity: ${realmKey}`);
  const sources = [realmKey, ...(aliases[realmKey] || [])];
  const factions = [...new Set(sources.flatMap(key => Object.keys(db.Packed.realms[key])))];
  if (!factions.length || factions.some(f => !["Alliance", "Horde"].includes(f)))
    throw new Error(`unexpected factions: ${factions}`);
  return factions.map(faction => {
    const byName = new Map();
    let folded = 0, collisions = 0;
    for (const key of sources) {
      for (const row of readRows(key, faction)) {
        const lower = row[0].toLocaleLowerCase(), seen = byName.get(lower);
        if (key !== realmKey) folded++;
        if (seen) { collisions++; if (newer(row, seen)) byName.set(lower, row); }
        else byName.set(lower, row);
      }
    }
    const rows = [...byName.values()], days = rows.map(row => row[5]);
    if (!rows.length) throw new Error(`no ${faction} characters`);
    if (days.some(day => day > savedDay)) throw new Error("character day is after SavedAt UTC day");
    if (folded) process.stderr.write(`${realmKey} ${faction}: folded ${folded} rows from nameless copy, ${collisions} already present\n`);
    return {source: "ClassicPlus", version: info.LogVer, dataFormat: info.DataFormat,
      savedAt: info.SavedAt, locale: info.ClientLocale, region, realmId: Number(realm.id), realmName: realm.name,
      ruleset: realm.ruleset, faction, scanPasses: Object.keys(info.Scanner?.Passes || {}).length,
      scanTimes: Object.keys(db.TimesPlus?.[realmKey]?.[faction] || {}).length,
      firstDay: days.reduce((a, b) => a < b ? a : b), lastDay: days.reduce((a, b) => a > b ? a : b), rows};
  });
}

process.stdout.write(JSON.stringify(realmKeys.flatMap(exportRealm)));
