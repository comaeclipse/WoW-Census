import fs from "node:fs";
import { fileURLToPath } from "node:url";
import { CLASS_META } from "./census.mjs";
import { PROFILE_GAMES, gameNav, pageNav, talentsPath, rootPrefix } from "./profile-games.mjs";

// Class talent pages, one column per talent tree, for every game in PROFILE_GAMES.
const CLASSES = ["PALADIN", "WARRIOR", "DRUID", "HUNTER", "SHAMAN", "PRIEST", "ROGUE", "MAGE", "WARLOCK"];
const MODERN_CLASSES = [...CLASSES, "DEATHKNIGHT", "MONK"];
export const CLASS_PROFILES = Object.fromEntries(Object.entries(PROFILE_GAMES).map(([source, game]) => [source, {
  ...game, classes: source === "retail" ? [...MODERN_CLASSES, "DEMONHUNTER", "EVOKER"]
    : source === "mop-classic" ? MODERN_CLASSES : CLASSES,
}]));
// Trees in the game's own order, with the icon and spec color each tree uses.
const CLASS_TREES = {
  PALADIN: [["Holy", "spell_holy_holybolt", "#eeb138"], ["Protection", "spell_holy_devotionaura", "#4c9ddb"], ["Retribution", "spell_holy_auraoflight", "#a96be0"]],
  DRUID: [["Balance", "spell_nature_starfall", "#7d6fe0"], ["Feral Combat", "ability_racial_bearform", "#e88a3a"], ["Restoration", "spell_nature_healingtouch", "#5fbf5f"]],
  HUNTER: [["Beast Mastery", "ability_hunter_beasttaming", "#b6c95e"], ["Marksmanship", "ability_marksmanship", "#e0524a"], ["Survival", "ability_hunter_swiftstrike", "#8fbf4a"]],
  SHAMAN: [["Elemental", "spell_nature_lightning", "#e0763a"], ["Enhancement", "spell_nature_lightningshield", "#4c9ddb"], ["Restoration", "spell_nature_magicimmunity", "#5fbf8f"]],
  PRIEST: [["Discipline", "spell_holy_wordfortitude", "#d8d2c0"], ["Holy", "spell_holy_guardianspirit", "#eeb138"], ["Shadow", "spell_shadow_shadowwordpain", "#8a63c9"]],
  ROGUE: [["Assassination", "ability_rogue_eviscerate", "#5fbf5f"], ["Combat", "ability_backstab", "#e0524a"], ["Subtlety", "ability_stealth", "#8a63c9"]],
  MAGE: [["Arcane", "spell_holy_magicalsentry", "#b06be0"], ["Fire", "spell_fire_firebolt02", "#e0763a"], ["Frost", "spell_frost_frostbolt02", "#5fb0f0"]],
  WARLOCK: [["Affliction", "spell_shadow_deathcoil", "#84d84e"], ["Demonology", "spell_shadow_metamorphosis", "#8a63c9"], ["Destruction", "spell_shadow_rainoffire", "#e0763a"]],
  WARRIOR: [["Arms", "ability_warrior_savageblow", "#e0524a"], ["Fury", "ability_warrior_innerrage", "#eeac38"], ["Protection", "inv_shield_06", "#539ad4"]],
  DEATHKNIGHT: [["Blood", "classicon_deathknight", "#bd3949"], ["Frost", "classicon_deathknight", "#5fb0f0"], ["Unholy", "classicon_deathknight", "#84d84e"]],
  MONK: [["Brewmaster", "classicon_monk", "#eeb138"], ["Windwalker", "classicon_monk", "#5fbf5f"], ["Mistweaver", "classicon_monk", "#5fb0f0"]],
  DEMONHUNTER: [["Havoc", "classicon_demonhunter", "#a96be0"], ["Vengeance", "classicon_demonhunter", "#e0524a"]],
  EVOKER: [["Devastation", "classicon_evoker", "#e0763a"], ["Preservation", "classicon_evoker", "#5fbf5f"], ["Augmentation", "classicon_evoker", "#a96be0"]],
};
const MODERN_TREES = {
  ...Object.fromEntries(Object.entries(CLASS_TREES).map(([cf, trees]) => [cf, trees.map(([name]) => name)])),
  DRUID: ["Balance", "Feral", "Guardian", "Restoration"],
  ROGUE: ["Assassination", "Outlaw", "Subtlety"],
};
// "Spec:CLASS" -> icon and color, shared with the race profiles.
export const SPEC_ICONS = {
  ...Object.fromEntries(Object.entries(CLASS_TREES).flatMap(([cf, trees]) => trees.map(([t, icon]) => [t + ":" + cf, icon]))),
  "Feral:DRUID": "ability_druid_catform", "Guardian:DRUID": "ability_racial_bearform", "Outlaw:ROGUE": "ability_rogue_waylay",
};
export const SPEC_COLORS = Object.fromEntries(Object.entries(CLASS_TREES).flatMap(([cf, trees]) => trees.map(([t, , c]) => [t + ":" + cf, c])));

export const classSlug = (cf) => (CLASS_META[cf]?.[0] || cf).toLowerCase().replace(/\s+/g, "-");
export const classRoute = (source, cf) => `/${CLASS_PROFILES[source].dir}/${classSlug(cf)}/`;
export const classRoutes = () => Object.entries(CLASS_PROFILES).flatMap(([source, p]) => p.classes.map((cf) => classRoute(source, cf)));

const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const med = (arr) => { const a = arr.filter(Number.isFinite).sort((x, y) => x - y); return a.length ? a[Math.floor((a.length - 1) / 2)] : null; };
const pct = (a, b) => (b ? Math.round(100 * a / b) : 0);

// Each column counts only players classified as that tree's spec, and ranks
// every talent of that tree seen on any player of the class by how many of
// those players selected it. Hybrids (points across trees) and unknown builds
// (a talent no tree claims) count in the split, not in a column.
export function classProfile(inspects, classFile, source = "classic-beta") {
  const modern = source === "retail" || source === "mop-classic";
  const rows = (inspects.records || []).filter((r) => r.classFile === classFile && (modern ? r.classification && r.classification !== "Unknown build" : r.talents?.length));
  const known = (modern ? (source === "mop-classic" && classFile === "ROGUE" ? ["Assassination", "Combat", "Subtlety"] : MODERN_TREES[classFile]) : CLASS_TREES[classFile]?.map(([t]) => t)) ||
    [...new Set(rows.flatMap((r) => r.talents.map((t) => t.branch)).filter(Boolean))].sort();
  const nodes = new Map();
  for (const r of rows) for (const t of r.talents || []) {
    const tree = modern ? r.classification : t.branch;
    const key = modern ? `${tree}:${t.node}` : t.node;
    if (!nodes.has(key)) nodes.set(key, { name: t.name, max: t.max, icon: t.icon || "inv_misc_questionmark", tree });
  }
  const trees = known.map((tree) => {
    const members = rows.filter((r) => r.classification === tree), picks = new Map();
    for (const r of members) for (const t of new Map((r.talents || []).map((t) => [t.node, t])).values()) {
      if (!modern && t.branch !== tree) continue;
      const v = picks.get(t.node) || { n: 0, ranks: 0 };
      v.n++; v.ranks += t.rank || 0; picks.set(t.node, v);
    }
    const talents = [...nodes].filter(([, t]) => t.tree === tree).map(([node, t]) => {
      const p = picks.get(modern ? Number(String(node).split(":").pop()) : node) || { n: 0, ranks: 0 };
      return { name: t.name, icon: t.icon, max: t.max, n: p.n, pct: pct(p.n, members.length), avgRank: p.n ? +(p.ranks / p.n).toFixed(1) : 0 };
    }).sort((a, b) => b.n - a.n || a.name.localeCompare(b.name));
    return { tree, n: members.length, medLv: med(members.map((r) => r.level)), talents };
  });
  const hybrid = rows.filter((r) => r.classification?.includes("/")).length;
  const unknown = rows.filter((r) => !known.includes(r.classification) && !r.classification?.includes("/")).length;
  return { n: rows.length, trees, hybrid, unknown, lastT: Math.max(0, ...rows.map((r) => r.time || 0)), windowDays: inspects.windowDays };
}

// The race template owns the profile pages' look; reuse its stylesheet so both
// stay one design.
function profileStyle(prefix) {
  const template = fs.readFileSync(fileURLToPath(new URL("./race-template.html", import.meta.url)), "utf8");
  const style = template.match(/<style>([\s\S]*?)<\/style>/)?.[1];
  if (!style) throw new Error("Race template style missing");
  return style.replaceAll("../static/fonts/", prefix + "fonts/");
}
const CLASS_STYLE = `
.split{margin-bottom:26px}
.speccols{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px;align-items:start}
.speckey span[style]::before{background:var(--c)}
.speccol .card-h h4{font-size:11px}
.speccol .card-b{padding:6px 12px 10px}
.ranks{list-style:none;margin:0;padding:0}
.ranks li{display:grid;grid-template-columns:30px 30px minmax(0,1fr) 54px;gap:9px;align-items:center;padding:7px 0;border-top:2px solid #20263a}
.ranks li:first-child{border-top:0}
.ranks .rank{font-size:9px;padding:6px 0}
.ranks li:nth-child(-n+3) .rank{background:var(--gold);color:#161018;border-color:var(--shadow);box-shadow:2px 2px 0 var(--shadow)}
.ranks img{width:30px;height:30px;border:2px solid #b99b55;box-shadow:2px 2px 0 var(--shadow);background:#111;display:block}
.ranks .tt b{display:block;font-weight:400;font-size:19px;line-height:1.05}
.ranks li.none{opacity:.5}
.speccol .card-h .cn{white-space:nowrap}
.specbar{height:22px}
/* The columns are not clickable, so no hover. */
.speccol:hover{border-color:var(--line)}
/* Three columns need ~300px each; below that one column reads better than squeezed headers. */
@media (max-width:960px){.speccols{grid-template-columns:minmax(0,1fr)}}`;

export function renderClassProfile(inspects, source, classFile, opts) {
  const P = CLASS_PROFILES[source], D = classProfile(inspects, classFile, source);
  const route = classRoute(source, classFile), prefix = rootPrefix(route);
  const icon = (n) => prefix + "assets/images/wow/icons/large/" + n + ".jpg";
  const treeColor = (i) => CLASS_TREES[classFile]?.[i]?.[2] || ["var(--gold)", "var(--blue)", "var(--green)"][i] || "var(--gold)";
  const className = CLASS_META[classFile]?.[0] || classFile;
  const plural = className + "s", title = `${P.gameLabel} ${className} Talents – WoWCensus`;
  const ranked = [...D.trees].sort((a, b) => b.n - a.n);
  const named = (t) => `<strong>${esc(t.tree)}</strong> (${pct(t.n, D.n)}%)`;
  const modern = source === "retail" || source === "mop-classic";
  const intro = !D.n ? `No ${esc(className)} inspections with ${modern ? "a recognized specialization" : "readable talents"} are available in this snapshot.`
    : `Among ${esc(plural)} we've inspected, ${named(ranked[0])} is the most common build, followed by ${ranked.slice(1).filter((t) => t.n).map(named).join(" and ") || "no other single-tree build"}.`;
  const seg = D.trees.map((t, i) => t.n ? `<i style="background:${treeColor(i)};flex:${t.n}" title="${esc(t.tree)} ${t.n}"></i>` : "").join("") +
    (D.hybrid ? `<i class="th" style="flex:${D.hybrid}" title="Hybrid ${D.hybrid}"></i>` : "") +
    (D.unknown ? `<i style="background:var(--muted);flex:${D.unknown}" title="Unknown ${D.unknown}"></i>` : "");
  const key = D.trees.map((t, i) => `<span style="--c:${treeColor(i)}"><b>${esc(t.tree)}</b> ${t.n} · ${pct(t.n, D.n)}%</span>`).join("") +
    (D.hybrid ? `<span class="kh"><b>Hybrid</b> ${D.hybrid} · ${pct(D.hybrid, D.n)}%</span>` : "") +
    (D.unknown ? `<span style="--c:var(--muted)"><b>Unknown</b> ${D.unknown} · ${pct(D.unknown, D.n)}%</span>` : "");
  const column = (t, i) => {
    const rows = t.talents.map((x, k) => {
      return `<li${x.n ? "" : ' class="none"'}><span class="rank">${k + 1}</span><img src="${esc(icon(x.icon))}" alt="" width="30" height="30" loading="lazy">` +
        `<span class="tt"><b>${esc(x.name)}</b></span>` +
        `<span class="pick"><b>${x.pct}%</b><div class="mini"><i style="width:${x.pct}%"></i></div></span></li>`;
    }).join("");
    const body = !t.n ? `<p class="colnote">No ${esc(t.tree)} ${esc(plural)} in this sample.</p>`
      : !t.talents.length ? `<p class="colnote">No readable talent selections for this spec.</p>`
      : `<ol class="ranks">${rows}</ol>`;
    return `<article class="card speccol" id="${esc(String(t.tree).toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""))}" data-tree="${esc(t.tree)}" style="--c:${treeColor(i)}">` +
      `<div class="card-h"><img src="${esc(icon(CLASS_TREES[classFile]?.[i]?.[1] || "classicon_" + classFile.toLowerCase()))}" alt="" width="40" height="40"><h4>${esc(t.tree)}</h4>` +
      `<div class="cn" title="${t.n} players"><b>${t.n}</b>${pct(t.n, D.n)}%</div></div>` +
      `<div class="card-b">${body}</div>` +
      `<div class="card-f">${t.n ? `Median level ${t.medLv}` : "No players classified"}</div></article>`;
  };
  const classChips = P.classes.length > 1 ? `<div class="chips class-chip-links" aria-label="Class">${P.classes.map((cf) =>
    `<a class="chip" href="${classRoute(source, cf)}"${cf === classFile ? ' aria-current="page"' : ""}>` +
    `<img src="${esc(icon("classicon_" + cf.toLowerCase()))}" alt="" width="20" height="20"><span>${esc(CLASS_META[cf]?.[0] || cf)}</span></a>`).join("")}</div>` : "";
  const fonts = ["press-start-2p-latin", "vt323-latin", "cinzel-latin-800-normal", "friz-quadrata-regular"];
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(title)}</title>
<meta name="description" content="${esc(className)} talents in ${esc(P.gameLabel)}: which talents players pick in each spec, ranked by how often they appear in nearby-player inspections."><link rel="canonical" href="${esc(opts.canonical)}"><meta property="og:type" content="website"><meta property="og:site_name" content="WoWCensus"><meta property="og:title" content="${esc(title)}"><meta property="og:url" content="${esc(opts.canonical)}"><link rel="icon" href="${prefix}favicon.ico">
${fonts.map((f) => `<link rel="preload" href="${prefix}fonts/${f}.woff2" as="font" type="font/woff2" crossorigin>`).join("\n")}
<link rel="stylesheet" href="${prefix}${esc(opts.stylesheet)}">
<style>${profileStyle(prefix)}${CLASS_STYLE}</style></head>
<body><div class="wrap">
<header class="ihead">
  <div class="page-heading">
    <h1 class="iname">WoWCensus<span class="itopic">${esc(P.gameLabel)} ${esc(className)} Talents</span></h1>
    <div class="site-nav-stack">
      <nav class="games game-nav" aria-label="Game edition">${gameNav((game) => CLASS_PROFILES[game].classes.includes(classFile) && classRoute(game, classFile)).map(([label, href]) => `<a class="game"${label === P.nav ? ' aria-current="true"' : ""} href="${href}">${label}</a>`).join("")}</nav>
      <nav class="games page-nav" aria-label="Site pages">${pageNav(source).map(([label, href]) => `<a class="game"${label === "Talents" ? ' aria-current="true"' : ""} href="${href}">${label}</a>`).join("")}</nav>
    </div>
  </div>
  <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">WoWCensus</a><span aria-hidden="true">/</span><a href="${P.home}">${esc(P.nav)}</a><span aria-hidden="true">/</span><a href="${talentsPath(source)}">Talents</a><span aria-hidden="true">/</span><span aria-current="page">${esc(className)}</span></nav>
</header>
${classChips}
<section class="hero slab">
  <img class="portrait" src="${esc(icon("classicon_" + classFile.toLowerCase()))}" alt="" width="96" height="96">
  <div>
    <h2>${esc(className)} <span>talents</span></h2>
    <p>${intro}</p>
  </div>
  <div class="bignum"><b>${D.n.toLocaleString("en-US")}</b><small>${esc(plural)} with ${modern ? "known specs" : "talents"}</small></div>
</section>
<section class="panel split">
  <div class="ptitle">Spec split</div>
  <div class="specbar">${seg}</div><div class="speckey">${key}</div>
</section>
<div class="speccols">${D.trees.map(column).join("")}</div>
</div></body></html>`;
}
