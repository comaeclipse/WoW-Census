#!/usr/bin/env node
// Parse ClassicPlus SavedVariables as data. Never execute the supplied Lua.
const { readSavedVariables } = require("./savedvariables.js");

const file = process.argv[2];
if (!file) throw new Error("usage: node tools/export-classicplus-savedvariables.js FILE.lua");
const db = readSavedVariables(file, "ClassicPlus_Database");
const info = db.Info || {};
if (info.LogVer !== "2.13.0" || info.DataFormat !== "0.8" || db.Packed?.format !== 1)
  throw new Error(`unsupported ClassicPlus format: ${info.LogVer}/${info.DataFormat}/${db.Packed?.format}`);
const realmKeys = Object.keys(db.Realms || {});
if (realmKeys.length !== 1 || Object.keys(db.Packed?.realms || {}).join() !== realmKeys[0])
  throw new Error("expected one matching realm in Realms and Packed.realms");
const realmKey = realmKeys[0], realm = db.Realms[realmKey];
const match = /^(\d+)_(.+)$/.exec(realmKey);
const region = String(realm.region || "").split("/")[1];
if (!match || Number(match[1]) !== Number(realm.id) || match[2] !== realm.name ||
    !["us", "eu", "kr"].includes(region) || String(info.LoginServer).toLowerCase() !== region)
  throw new Error(`inconsistent realm identity: ${realmKey}`);
const packed = db.Packed.realms[realmKey];
const factions = Object.keys(packed);
if (factions.length !== 1 || !["Alliance", "Horde"].includes(factions[0]))
  throw new Error(`expected one faction: ${factions}`);
const faction = factions[0], rows = [], names = new Set();
const days = [];
for (const [race, classes] of Object.entries(packed[faction])) {
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
        days.push(day.slice(1));
        rows.push([name, race, characterClass, Number(level.slice(1)), guild.slice(1),
                   day.slice(1), Number(sourceId.slice(1)), Number(seenCount.slice(1)), surname.slice(1)]);
      }
    }
  }
}
if (!rows.length || !Number.isInteger(info.SavedAt) || info.SavedAt <= 0)
  throw new Error("missing characters or SavedAt");
const savedDay = new Date(info.SavedAt * 1000).toISOString().slice(0, 10);
if (days.some(day => day > savedDay)) throw new Error("character day is after SavedAt UTC day");
process.stdout.write(JSON.stringify({source: "ClassicPlus", version: info.LogVer, dataFormat: info.DataFormat,
  savedAt: info.SavedAt, locale: info.ClientLocale, region, realmId: Number(realm.id), realmName: realm.name,
  ruleset: realm.ruleset, faction, scanPasses: Object.keys(info.Scanner?.Passes || {}).length,
  scanTimes: Object.keys(db.TimesPlus?.[realmKey]?.[faction] || {}).length,
  firstDay: days.reduce((a, b) => a < b ? a : b), lastDay: days.reduce((a, b) => a > b ? a : b), rows}));
