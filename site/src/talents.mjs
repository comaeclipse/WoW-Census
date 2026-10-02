import { esc, seoLabel, seoHead, pageHeading, chipRow, CENSUS_STYLE, TOGGLE_SCRIPT } from "./census.mjs";

function time(t) { return t ? new Date(t*1000).toISOString().slice(0,16).replace("T"," ")+"Z" : "Unavailable"; }
export function renderInspectCoverage(data, href) {
  const c=data.coverage;
  return `<section class="panel" style="margin-top:20px"><div class="ptitle">Inspected talent builds</div>
    <p>${c.inspected.toLocaleString()} nearby players inspected · ${c.withSelectedTalents.toLocaleString()} with selected talents · ${c.linked.toLocaleString()} linked to retained census characters</p>
    <p class="hint">${c.unmatched.toLocaleString()} unlinked inspections. Nearby inspect samples have their own coverage and do not measure population-wide specialization shares.</p>
    <p class="hint">Latest inspection: ${esc(time(data.lastT))}</p><a class="game" href="${esc(href)}">Explore talents</a></section>`;
}

const CLASS_NAMES = {WARRIOR:"Warrior",PALADIN:"Paladin",HUNTER:"Hunter",ROGUE:"Rogue",PRIEST:"Priest",SHAMAN:"Shaman",MAGE:"Mage",WARLOCK:"Warlock",DRUID:"Druid",MONK:"Monk",DEATHKNIGHT:"Death Knight",DEMONHUNTER:"Demon Hunter",EVOKER:"Evoker"};
const cls = (c) => CLASS_NAMES[c] || c;
const num = (n) => n.toLocaleString("en-US");
const ICON_ROOT = "https://wow.zamimg.com/images/wow/icons/large/";
// Rows shown before the rest folds into a "Show all" expander.
const VISIBLE_ROWS = 25;
// Talents per class in the all-classes view; a class pane lists them all.
const TALENTS_PER_CLASS = 10;

// A table whose rows past VISIBLE_ROWS sit in a <details> expander -- the full
// list ships in the HTML and opens without any script.
function foldedTable(tableClass, head, rows) {
  const table = (body) => '<div class="tablewrap"><table class="' + tableClass + '">' + head + "<tbody>" + body.join("") + "</tbody></table></div>";
  if (rows.length <= VISIBLE_ROWS) return table(rows);
  return table(rows.slice(0, VISIBLE_ROWS)) +
    '<details class="talent-more"><summary>Show all ' + num(rows.length) + "</summary>" +
    table(rows.slice(VISIBLE_ROWS)) + "</details>";
}

// One view of the page: every class (classFile null) or a single class. The
// faction panels are the same in every view so switching classes keeps layout.
function talentView(records, factions, classFile) {
  const rows = classFile ? records.filter((r) => r.classFile === classFile) : records;
  if (!rows.length) return '<section class="panel"><p class="hint talent-empty">No inspections available.</p></section>';
  const label = classFile ? cls(classFile) + " " : "";
  const withNodes = rows.filter((r) => r.talents.length);
  const classified = rows.filter((r) => r.classification && r.classification !== "Unknown build");

  const distribution = factions.map((faction) => {
    const members = classified.filter((r) => r.faction === faction), combos = new Map(), classTotals = {};
    for (const r of members) {
      const key = r.classFile + "\t" + r.classification;
      const group = combos.get(key) || { n: 0, hybrid: false };
      group.n++; group.hybrid ||= !!r.hybrid;
      combos.set(key, group);
      classTotals[r.classFile] = (classTotals[r.classFile] || 0) + 1;
    }
    const body = [...combos].sort((a, b) => b[1].n - a[1].n || a[0].localeCompare(b[0])).map(([key, g]) => {
      const [c, tree] = key.split("\t");
      return '<tr><td class="combo-name"><img src="' + ICON_ROOT + "classicon_" + esc(c.toLowerCase()) + '.jpg" alt="" loading="lazy">' +
        esc(cls(c)) + " · " + esc(tree) + (g.hybrid ? " Hybrid" : "") + "</td><td>" + num(g.n) + "</td><td>" +
        (100 * g.n / classTotals[c]).toFixed(1) + "%</td></tr>";
    });
    return '<section class="panel combo-panel"><div class="ptitle">' + esc(faction) + " " + esc(label) + "class + build</div>" +
      (body.length ? foldedTable("combo-table talent-combo-table",
        '<thead><tr><th class="l">Combination</th><th>Players</th><th>%</th></tr></thead>', body)
        : '<p class="hint talent-empty">No classified inspections.</p>') + "</section>";
  }).join("");

  const talents = new Map(), denominators = {};
  for (const r of withNodes) {
    denominators[r.classFile] = (denominators[r.classFile] || 0) + 1;
    for (const t of r.talents) {
      const k = r.classFile + "\t" + t.node;
      const cur = talents.get(k) || { name: t.name, classFile: r.classFile, players: 0 };
      cur.players++;
      talents.set(k, cur);
    }
  }
  const share = (t) => t.players / denominators[t.classFile];
  const byShare = (a, b) => share(b) - share(a) || b.players - a.players || String(a.name).localeCompare(String(b.name));
  const ranked = [...talents.values()].sort(byShare);
  // All classes: each class's most-selected talents, grouped by class.
  const listed = classFile ? ranked : Object.keys(denominators).sort((a, b) => cls(a).localeCompare(cls(b)))
    .flatMap((c) => ranked.filter((t) => t.classFile === c).slice(0, TALENTS_PER_CLASS));
  const popular = listed.map((t) => {
    const d = denominators[t.classFile];
    return '<tr><td class="l">' + esc(cls(t.classFile)) + '</td><td class="l">' + esc(t.name) + "</td><td>" +
      t.players + " / " + d + " (" + (100 * t.players / d).toFixed(1) + "%)</td></tr>";
  });
  const note = classFile ? "Shares use " + cls(classFile) + " players with readable nodes."
    : "Each class's top " + TALENTS_PER_CLASS + " by share of that class's players with readable nodes; pick a class for its full list.";
  const excluded = rows.length - withNodes.length;
  return '<div class="combo-breakdown">' + distribution + "</div>" + (withNodes.length
    ? '<section class="panel talent-section"><div class="ptitle">' + esc(label) + 'Selected talent popularity</div><p class="hint" style="margin-top:0">' +
      esc(note) + (excluded ? " " + excluded + " inspected player(s) without readable talent selections excluded." : "") + "</p>" +
      foldedTable("talent-popularity-table", '<thead><tr><th class="l">Class</th><th class="l">Talent</th><th>Players selecting</th></tr></thead>', popular) +
      "</section>" : "");
}

// Every view is rendered into the HTML; the class chips only show and hide
// panes (and mirror the choice in ?class=), so nothing is fetched or built in
// the browser.
export function renderTalentsHtml(data, opts={}) {
  const nav=opts.nav||{};
  const seoGameLabel=seoLabel(opts.gameLabel||"WoW Forever");
  const topic=seoGameLabel+" Talent Builds & Popular Specs";
  const records=data.records||[];
  const factions=[...new Set(records.map((r)=>r.faction).filter(Boolean))].sort();
  const classes=[...new Set(records.map((r)=>r.classFile).filter(Boolean))].sort((a,b)=>cls(a).localeCompare(cls(b)));
  const chips=chipRow("class",[{key:"all",label:"All classes"}].concat(classes.map((c)=>({key:c,label:cls(c)}))),"all");
  const panes=[["all",null]].concat(classes.map((c)=>[c,c])).map(([key,classFile])=>
    '<div class="vpane" data-class="'+esc(key)+'"'+(key==="all"?"":" hidden")+">"+talentView(records,factions,classFile)+"</div>").join("");
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    ${seoHead(topic+" – WoWCensus", seoGameLabel+" talent builds seen on inspected players: most common builds, talent tree point totals and selected talent popularity.", opts.canonical)}
    <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96"><link rel="icon" href="/favicon-96.png" type="image/png" sizes="96x96">
    <link rel="preload" href="/fonts/press-start-2p-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/fonts/vt323-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/fonts/cinzel-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/fonts/friz-quadrata-regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="${esc(opts.stylesheet||"/style.css")}">
    <style>
    ${CENSUS_STYLE}
    .talent-summary{font-family:"Friz Quadrata Web","Friz Quadrata","Friz Quadrata Std","Fritz Quadrata","Cinzel",Georgia,serif;font-size:16px;font-weight:400;letter-spacing:normal;line-height:1.66;color:#ccc;text-align:left}
    .talent-section{margin-top:22px}.talent-empty{margin:0}
    .talent-combo-table{min-width:0}.talent-combo-table th:first-child,.talent-combo-table td:first-child{width:72%}
    .talent-combo-table th:nth-child(2),.talent-combo-table td:nth-child(2){width:12%}
    .talent-combo-table th:nth-child(3),.talent-combo-table td:nth-child(3){width:16%}
    .talent-combo-table th:not(:first-child),.talent-combo-table td:not(:first-child){padding-left:6px;padding-right:6px}
    .talent-combo-table .combo-name{display:table-cell;text-align:left;white-space:normal;overflow:visible;text-overflow:clip;line-height:1.4}
    .talent-combo-table .combo-name img{vertical-align:middle;margin-right:7px;flex:none}
    .talent-popularity-table{table-layout:auto}.talent-popularity-table td:nth-child(2){white-space:normal;text-overflow:clip}
    .talent-more{margin-top:10px}.talent-more>summary{cursor:pointer;color:var(--blue);font-family:var(--term);font-size:20px}
    .talent-more>.tablewrap{margin-top:10px}
    @media(max-width:1050px){.combo-breakdown{grid-template-columns:1fr}.combo-panel{margin-bottom:22px}.combo-panel:last-child{margin-bottom:0}}
    @media(max-width:600px){.talent-combo-table th:first-child,.talent-combo-table td:first-child{width:50%}
      .talent-combo-table th:nth-child(2),.talent-combo-table td:nth-child(2){width:26%}
      .talent-combo-table th:nth-child(3),.talent-combo-table td:nth-child(3){width:24%}}
    </style></head><body><div class="crt" aria-hidden="true"></div><div class="wrap">
    <header class="ihead"><div class="page-heading">${pageHeading("WoWCensus", nav, topic)}${nav.games||""}</div>${nav.breadcrumbs||""}</header>
    <div class="page-controls">${nav.pages||""}${chips}</div>
    <section class="panel" style="margin-bottom:22px"><div class="ptitle">What this sample shows</div>
    <p class="hint talent-summary" style="margin:0">${data.coverage.inspected.toLocaleString()} nearby players inspected in the latest ${data.windowDays} days; ${data.coverage.withSelectedTalents.toLocaleString()} have readable talent selections. Build shares use only classified inspections and do not describe the full character population. Latest inspection: ${esc(time(data.lastT))}.${data.truncated?' Results are limited to the newest 10,000 inspected players.':""}</p></section>
    <div id="talent-content">${panes}</div>
    ${opts.generatedNote?`<div class="itag" style="margin-top:16px;line-height:1.6">${esc(opts.generatedNote)}</div>`:""}</div>
    ${chips?TOGGLE_SCRIPT:""}</body></html>`;
}
