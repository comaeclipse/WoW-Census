#!/usr/bin/env node

// Generate MarketLens/Data/ItemSourcesGen.lua -- the long-tail map of crafted
// items to the profession that produces them -- from Blizzard's DB2 data,
// served as CSV by wago.tools.
//
// The relationship the client's item API can't express:
//
//   SkillLine            profession skill line (Blacksmithing, Smelting, ...)
//        v  SkillLineAbility.SkillLine -> .Spell
//   Spell (recipe)       e.g. 29356 "Smelt Fel Iron"
//        v  SpellEffect.SpellID where Effect == 24 (CREATE_ITEM)
//   EffectItemType       the created itemID, e.g. 23445 Fel Iron Bar
//        v  ItemSparse.ID -> .Display_lang
//   item name            for the annotating comment
//
// Only the curated GATHERED / DISENCHANT pins and any manual crafted overrides
// live in Data/ItemSources.lua; those take priority over this generated table.
//
// Also computes MarketLens/Data/GearCoverageGen.lua: what fraction of each
// armor-crafting profession's addressable gear (its armor material, plus
// weapons for Blacksmithing) is a player recipe (the rest is drops, quest
// rewards, vendor items, or anything else no profession can supply). This
// feeds Population/WhoScan.lua's demand heuristic, which otherwise has no way
// to know whether a class's generic "Gear" weight should lean toward its
// crafting profession or stay unattributed.
//
// Usage:
//   node build-item-sources.js [--build=2.5.6.69546] [--out=<path>] [--coverage-out=<path>]
//
// Default build is the current TBC Anniversary (wow_anniversary) build.

const fs = require("node:fs");
const path = require("node:path");
const https = require("node:https");

const DEFAULT_BUILD = "2.5.6.69546";
const DEFAULT_OUT = path.join(__dirname, "..", "MarketLens", "Data", "ItemSourcesGen.lua");
const DEFAULT_COVERAGE_OUT = path.join(__dirname, "..", "MarketLens", "Data", "GearCoverageGen.lua");

// Item.db2 ClassID values (mirrors Data/Categories.lua's CLASS_ARMOR/CLASS_WEAPON).
const ITEM_CLASS_WEAPON = 2;
const ITEM_CLASS_ARMOR = 4;
// Armor SubclassID values for the three player-craftable materials (TBC/Classic
// numbering, same table Categories.lua reads -- 0=Misc,1=Cloth,2=Leather,
// 3=Mail,4=Plate; 5+ is shields/relics, not modeled here).
const ARMOR_SUBCLASS_CLOTH = 1;
const ARMOR_SUBCLASS_LEATHER = 2;
const ARMOR_SUBCLASS_MAIL = 3;
const ARMOR_SUBCLASS_PLATE = 4;

// wago.tools sits behind Cloudflare; a browser-like UA is required or it 403s.
const USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36";

// SpellEffect.Effect value for SPELL_EFFECT_CREATE_ITEM in TBC 2.5.x. Verified
// against the live data: it is the only effect type that carries crafting yields.
const EFFECT_CREATE_ITEM = "24";

// DB2 skill-line display name -> MarketLens profession key. Only lines listed
// here are treated as crafting sources; everything else (weapon skills,
// languages, riding, class abilities, the pure gathering lines Herbalism /
// Skinning / Fishing) is ignored. First Aid has no MarketLens bucket but is a
// real crafter, so it is passed through as source metadata.
//
// Mining is included even though it is a gathering profession: in TBC there is
// no separate "Smelting" line -- smelting recipes sit under Mining, and only
// those carry a CREATE_ITEM effect (bars). Actual gathering (mining a node) is
// not a spell with a create-item effect, so the Effect==24 filter naturally
// keeps only the smelted output.
const PROFESSION_MAP = {
  Alchemy: "Alchemy",
  Blacksmithing: "Blacksmithing",
  Enchanting: "Enchanting",
  Engineering: "Engineering",
  Leatherworking: "Leatherworking",
  Tailoring: "Tailoring",
  Jewelcrafting: "Jewelcrafting",
  Cooking: "Cooking",
  "First Aid": "First Aid",
  Mining: "Mining",
};

function parseArgs(argv) {
  const args = { build: DEFAULT_BUILD, out: DEFAULT_OUT, "coverage-out": DEFAULT_COVERAGE_OUT };
  for (const a of argv) {
    const m = /^--([^=]+)=(.*)$/.exec(a);
    if (m) args[m[1]] = m[2];
    else throw new Error(`unrecognized argument: ${a} (use --build= / --out=)`);
  }
  return args;
}

function fetchText(url) {
  return new Promise((resolve, reject) => {
    https
      .get(url, { headers: { "User-Agent": USER_AGENT, Accept: "text/csv,*/*" } }, (res) => {
        if (res.statusCode !== 200) {
          res.resume();
          return reject(new Error(`GET ${url} -> HTTP ${res.statusCode}`));
        }
        const chunks = [];
        res.on("data", (c) => chunks.push(c));
        res.on("end", () => resolve(Buffer.concat(chunks).toString("utf8")));
      })
      .on("error", reject);
  });
}

function csvUrl(build, table) {
  return `https://wago.tools/db2/${table}/csv?build=${encodeURIComponent(build)}`;
}

// RFC4180-ish parser: handles quoted fields, doubled quotes, and newlines inside
// quotes (ItemSparse descriptions contain both). Returns an array of row arrays.
function parseCsv(text) {
  const rows = [];
  let field = [], cur = "", quoted = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (quoted) {
      if (c === '"') {
        if (text[i + 1] === '"') { cur += '"'; i++; }
        else quoted = false;
      } else cur += c;
    } else if (c === '"') quoted = true;
    else if (c === ",") { field.push(cur); cur = ""; }
    else if (c === "\n") { field.push(cur); rows.push(field); field = []; cur = ""; }
    else if (c === "\r") { /* ignore */ }
    else cur += c;
  }
  if (cur.length || field.length) { field.push(cur); rows.push(field); }
  return rows;
}

// Parse a CSV into { header: [...], indexOf: name->col, rows: [...] }.
function table(text) {
  const rows = parseCsv(text);
  const header = rows[0] || [];
  const indexOf = {};
  header.forEach((name, i) => { indexOf[name] = i; });
  return { header, indexOf, rows: rows.slice(1) };
}

function requireCols(t, name, cols) {
  for (const c of cols) {
    if (t.indexOf[c] === undefined) {
      throw new Error(`${name}.csv is missing expected column "${c}" -- DB2 layout may have changed for this build`);
    }
  }
}

function luaString(s) {
  return String(s).replace(/\\/g, "\\\\").replace(/"/g, '\\"');
}

async function main() {
  const args = parseArgs(process.argv.slice(2));

  process.stderr.write(`Fetching DB2 CSVs for build ${args.build} ...\n`);
  const [skillLineText, slaText, spellEffectText] = await Promise.all([
    fetchText(csvUrl(args.build, "SkillLine")),
    fetchText(csvUrl(args.build, "SkillLineAbility")),
    fetchText(csvUrl(args.build, "SpellEffect")),
  ]);

  const skillLine = table(skillLineText);
  requireCols(skillLine, "SkillLine", ["ID", "DisplayName_lang"]);
  const iSlName = skillLine.indexOf.DisplayName_lang, iSlId = skillLine.indexOf.ID;

  // skillLineID -> MarketLens profession, for crafting lines only.
  const professionByLine = {};
  for (const row of skillLine.rows) {
    const prof = PROFESSION_MAP[row[iSlName]];
    if (prof) professionByLine[row[iSlId]] = prof;
  }
  process.stderr.write(`Crafting skill lines matched: ${Object.keys(professionByLine).length}\n`);

  const sla = table(slaText);
  requireCols(sla, "SkillLineAbility", ["SkillLine", "Spell"]);
  const iSlaLine = sla.indexOf.SkillLine, iSlaSpell = sla.indexOf.Spell;

  // recipe spellID -> profession (first crafting line that teaches it wins).
  const professionBySpell = {};
  for (const row of sla.rows) {
    const prof = professionByLine[row[iSlaLine]];
    if (prof && professionBySpell[row[iSlaSpell]] === undefined) {
      professionBySpell[row[iSlaSpell]] = prof;
    }
  }
  process.stderr.write(`Recipe spells attributed: ${Object.keys(professionBySpell).length}\n`);

  const spellEffect = table(spellEffectText);
  requireCols(spellEffect, "SpellEffect", ["Effect", "EffectItemType", "SpellID"]);
  const iEff = spellEffect.indexOf.Effect, iItem = spellEffect.indexOf.EffectItemType,
        iSpell = spellEffect.indexOf.SpellID;

  // itemID -> { profession, spellID }. First crafting recipe that yields the item
  // wins; a later recipe for the same item is skipped (kept deterministic by the
  // CSV's own ID order).
  const sources = {};
  for (const row of spellEffect.rows) {
    if (row[iEff] !== EFFECT_CREATE_ITEM) continue;
    const itemID = Number(row[iItem]);
    if (!itemID) continue;
    const spellID = row[iSpell];
    const prof = professionBySpell[spellID];
    if (!prof) continue;
    if (sources[itemID] === undefined) {
      sources[itemID] = { profession: prof, spellID: Number(spellID) };
    }
  }
  const itemIDs = Object.keys(sources).map(Number).sort((a, b) => a - b);
  process.stderr.write(`Crafted items resolved: ${itemIDs.length}\n`);

  if (itemIDs.length === 0) {
    throw new Error("no crafted items resolved -- refusing to write an empty table");
  }

  // Names are only needed for the annotating comments; fetch ItemSparse last and
  // pull just the ones we reference to keep memory modest.
  process.stderr.write(`Fetching ItemSparse for ${itemIDs.length} names ...\n`);
  const itemSparse = table(await fetchText(csvUrl(args.build, "ItemSparse")));
  requireCols(itemSparse, "ItemSparse", ["ID", "Display_lang"]);
  const iIsId = itemSparse.indexOf.ID, iIsName = itemSparse.indexOf.Display_lang;
  const names = {};
  for (const row of itemSparse.rows) {
    const id = Number(row[iIsId]);
    if (sources[id]) names[id] = row[iIsName];
  }

  // Per-profession tallies for the header, so a diff shows coverage at a glance.
  const perProf = {};
  for (const id of itemIDs) perProf[sources[id].profession] = (perProf[sources[id].profession] || 0) + 1;
  const tally = Object.keys(perProf).sort().map((p) => `${p}=${perProf[p]}`).join(", ");

  const lines = [];
  lines.push("");
  lines.push("-- GENERATED by tools/build-item-sources.js -- do not edit by hand.");
  lines.push(`-- Source: wago.tools DB2 export, build ${args.build}.`);
  lines.push(`-- Crafted items: ${itemIDs.length} (${tally}).`);
  lines.push("-- Crafted item -> producing profession, joined via");
  lines.push("-- SkillLine -> SkillLineAbility -> SpellEffect(CREATE_ITEM) -> EffectItemType.");
  lines.push("-- Curated pins in Data/ItemSources.lua take priority over this table.");
  lines.push("");
  lines.push("local ML = MarketLens");
  lines.push("local D = ML.Data");
  lines.push("");
  lines.push("D.ItemSourcesGenerated = {");
  for (const id of itemIDs) {
    const s = sources[id];
    const name = names[id] ? " -- " + names[id] : "";
    lines.push(`    [${id}] = { source = D.Source.CRAFTED, profession = "${luaString(s.profession)}", spellID = ${s.spellID} },${name}`);
  }
  lines.push("}");
  lines.push("");

  fs.writeFileSync(args.out, lines.join("\n"), "utf8");
  process.stderr.write(`Wrote ${args.out}\n`);

  // ---- Gear coverage: what share of each profession's addressable gear is
  // actually a known player recipe, vs. drop/quest/vendor/other. Item.db2 has
  // the class/subclass census the SpellEffect join above doesn't need but this
  // does: total items per armor material + weapon, to use as a denominator.
  process.stderr.write("Fetching Item for gear coverage ...\n");
  const item = table(await fetchText(csvUrl(args.build, "Item")));
  requireCols(item, "Item", ["ID", "ClassID", "SubclassID"]);
  const iItId = item.indexOf.ID, iItClass = item.indexOf.ClassID, iItSub = item.indexOf.SubclassID;
  const classOf = {};
  const totalArmorBySub = {};
  let totalWeapons = 0;
  for (const row of item.rows) {
    const id = Number(row[iItId]);
    const classID = Number(row[iItClass]);
    const subClassID = Number(row[iItSub]);
    classOf[id] = { classID, subClassID };
    if (classID === ITEM_CLASS_ARMOR) totalArmorBySub[subClassID] = (totalArmorBySub[subClassID] || 0) + 1;
    else if (classID === ITEM_CLASS_WEAPON) totalWeapons++;
  }

  const craftedArmorBySub = {};
  let craftedWeaponsByBlacksmithing = 0;
  for (const id of itemIDs) {
    const info = classOf[id];
    if (!info) continue;
    const prof = sources[id].profession;
    if (info.classID === ITEM_CLASS_ARMOR) {
      craftedArmorBySub[info.subClassID] = craftedArmorBySub[info.subClassID] || {};
      craftedArmorBySub[info.subClassID][prof] = (craftedArmorBySub[info.subClassID][prof] || 0) + 1;
    } else if (info.classID === ITEM_CLASS_WEAPON && prof === "Blacksmithing") {
      craftedWeaponsByBlacksmithing++;
    }
  }
  const armorCraftedBy = (sub, prof) => (craftedArmorBySub[sub] && craftedArmorBySub[sub][prof]) || 0;
  const armorTotal = (sub) => totalArmorBySub[sub] || 0;

  // Tailoring: Cloth armor only (Tailoring doesn't craft weapons).
  const tailoringCrafted = armorCraftedBy(ARMOR_SUBCLASS_CLOTH, "Tailoring");
  const tailoringTotal = armorTotal(ARMOR_SUBCLASS_CLOTH);
  // Leatherworking: Leather + Mail armor (both are LW's domain in TBC; no weapons).
  const lwCrafted = armorCraftedBy(ARMOR_SUBCLASS_LEATHER, "Leatherworking") + armorCraftedBy(ARMOR_SUBCLASS_MAIL, "Leatherworking");
  const lwTotal = armorTotal(ARMOR_SUBCLASS_LEATHER) + armorTotal(ARMOR_SUBCLASS_MAIL);
  // Blacksmithing: Plate armor + weapons (Blacksmithing's other big output).
  const bsCrafted = armorCraftedBy(ARMOR_SUBCLASS_PLATE, "Blacksmithing") + craftedWeaponsByBlacksmithing;
  const bsTotal = armorTotal(ARMOR_SUBCLASS_PLATE) + totalWeapons;

  const coverage = {
    Tailoring: tailoringTotal > 0 ? tailoringCrafted / tailoringTotal : 0,
    Leatherworking: lwTotal > 0 ? lwCrafted / lwTotal : 0,
    Blacksmithing: bsTotal > 0 ? bsCrafted / bsTotal : 0,
  };
  process.stderr.write(
    `Gear coverage: Tailoring ${tailoringCrafted}/${tailoringTotal}, ` +
    `Leatherworking ${lwCrafted}/${lwTotal}, Blacksmithing ${bsCrafted}/${bsTotal}\n`
  );

  const covLines = [];
  covLines.push("");
  covLines.push("-- GENERATED by tools/build-item-sources.js -- do not edit by hand.");
  covLines.push(`-- Source: wago.tools DB2 export, build ${args.build} (Item.db2 class/subclass census).`);
  covLines.push("--");
  covLines.push("-- What fraction of each armor-crafting profession's addressable gear is an");
  covLines.push("-- actual player recipe (Data/ItemSourcesGen.lua), vs. drops/quests/vendor/other");
  covLines.push("-- that no profession supplies. Population/WhoScan.lua uses this to split each");
  covLines.push("-- class's flat generic-Gear demand weight into a profession-attributed share");
  covLines.push("-- and a true unattributed-Gear share, instead of a guessed constant.");
  covLines.push("--");
  covLines.push(`--   Tailoring:      ${tailoringCrafted} / ${tailoringTotal} cloth armor items = ${(coverage.Tailoring * 100).toFixed(1)}%`);
  covLines.push(`--   Leatherworking: ${lwCrafted} / ${lwTotal} leather+mail armor items = ${(coverage.Leatherworking * 100).toFixed(1)}%`);
  covLines.push(`--   Blacksmithing:  ${bsCrafted} / ${bsTotal} plate armor + weapon items = ${(coverage.Blacksmithing * 100).toFixed(1)}%`);
  covLines.push("");
  covLines.push("local ML = MarketLens");
  covLines.push("local D = ML.Data");
  covLines.push("");
  covLines.push("D.GearCraftShare = {");
  covLines.push(`    Tailoring = ${coverage.Tailoring.toFixed(4)},`);
  covLines.push(`    Leatherworking = ${coverage.Leatherworking.toFixed(4)},`);
  covLines.push(`    Blacksmithing = ${coverage.Blacksmithing.toFixed(4)},`);
  covLines.push("}");
  covLines.push("");

  fs.writeFileSync(args["coverage-out"], covLines.join("\n"), "utf8");
  process.stderr.write(`Wrote ${args["coverage-out"]}\n`);

  // Also emit a compact JSON keyed by itemID, for the site pipeline: the export
  // tool enriches any scan (old or new) with source purely from the itemID, so
  // the backend never depends on which addon version produced the scan.
  const jsonOut = args.json || path.join(__dirname, "item-sources.json");
  const jsonMap = {};
  for (const id of itemIDs) {
    jsonMap[id] = { source: "crafted", profession: sources[id].profession, spellID: sources[id].spellID };
  }
  fs.writeFileSync(jsonOut, JSON.stringify(jsonMap), "utf8");
  process.stderr.write(`Wrote ${jsonOut}\n`);
}

main().catch((err) => {
  process.stderr.write(`error: ${err.message}\n`);
  process.exit(1);
});
