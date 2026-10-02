import { esc, seoLabel, seoHead, pageHeading, CENSUS_STYLE } from "./census.mjs";

function time(t) { return t ? new Date(t*1000).toISOString().slice(0,16).replace("T"," ")+"Z" : "Unavailable"; }
export function renderInspectCoverage(data, href) {
  const c=data.coverage;
  return `<section class="panel" style="margin-top:20px"><div class="ptitle">Inspected talent builds</div>
    <p>${c.inspected.toLocaleString()} nearby players inspected · ${c.withSelectedTalents.toLocaleString()} with selected talents · ${c.linked.toLocaleString()} linked to retained census characters</p>
    <p class="hint">${c.unmatched.toLocaleString()} unlinked inspections. Nearby inspect samples have their own coverage and do not measure population-wide specialization shares.</p>
    <p class="hint">Latest inspection: ${esc(time(data.lastT))}</p><a class="game" href="${esc(href)}">Explore talents</a></section>`;
}

// All filtering and aggregation runs against this page's frozen snapshot.
export function renderTalentsHtml(data, opts={}) {
  const nav=opts.nav||{};
  const seoGameLabel=seoLabel(opts.gameLabel||"WoW Forever");
  const topic=seoGameLabel+" Talent Builds & Popular Specs";
  const payload=JSON.stringify(data).replace(/</g,"\\u003c").replace(/\u2028/g,"\\u2028").replace(/\u2029/g,"\\u2029");
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    ${seoHead(topic+" – WoWCensus", seoGameLabel+" talent builds seen on inspected players: most common builds, talent tree point totals and selected talent popularity.", opts.canonical)}
    <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96"><link rel="icon" href="/favicon-96.png" type="image/png" sizes="96x96">
    <link rel="preload" href="/fonts/press-start-2p-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/fonts/vt323-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/fonts/cinzel-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/fonts/friz-quadrata-regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="${esc(opts.stylesheet||"/style.css")}">
    <style>
    ${CENSUS_STYLE}
    .talent-summary{font-family:"Friz Quadrata Web","Friz Quadrata","Friz Quadrata Std","Fritz Quadrata","Cinzel",Georgia,serif;font-size:16px;font-weight:400;letter-spacing:normal;line-height:1.66;color:#ccc;text-align:left}
    .talent-filters{display:flex;flex-wrap:wrap;gap:14px;margin-top:14px}.talent-filters label{display:grid;gap:5px;font-size:17px;color:var(--muted)}
    .talent-filters select{color:var(--ink);background:var(--panel2);border:2px solid var(--line);font-family:var(--term);font-size:18px;padding:6px 10px;max-width:230px}
    .talent-section{margin-top:22px}.talent-empty{margin:0}
    .talent-combo-table{min-width:0}.talent-combo-table th:first-child,.talent-combo-table td:first-child{width:72%}
    .talent-combo-table th:nth-child(2),.talent-combo-table td:nth-child(2){width:12%}
    .talent-combo-table th:nth-child(3),.talent-combo-table td:nth-child(3){width:16%}
    .talent-combo-table th:not(:first-child),.talent-combo-table td:not(:first-child){padding-left:6px;padding-right:6px}
    .talent-combo-table .combo-name{display:table-cell;text-align:left;white-space:normal;overflow:visible;text-overflow:clip;line-height:1.4}
    .talent-combo-table .combo-name img{vertical-align:middle;margin-right:7px;flex:none}
    .talent-popularity-table{table-layout:auto}.talent-popularity-table td:nth-child(2){white-space:normal;text-overflow:clip}
    @media(max-width:1050px){.combo-breakdown{grid-template-columns:1fr}.combo-panel{margin-bottom:22px}.combo-panel:last-child{margin-bottom:0}}
    @media(max-width:600px){.talent-filters label{flex:1 1 40%}.talent-filters select{max-width:100%;width:100%}}
    </style></head><body><div class="crt" aria-hidden="true"></div><div class="wrap">
    <header class="ihead"><div class="page-heading">${pageHeading("WoWCensus", nav, topic)}${nav.games||""}</div>${nav.breadcrumbs||""}</header>
    <div class="page-controls">${nav.pages||""}</div>
    <section class="panel" style="margin-bottom:22px"><div class="ptitle">What this sample shows</div>
    <p class="hint talent-summary" style="margin:0">${data.coverage.inspected.toLocaleString()} nearby players inspected in the latest ${data.windowDays} days; ${data.coverage.withSelectedTalents.toLocaleString()} have readable talent selections. Build shares use only classified inspections and do not describe the full character population. Latest inspection: ${esc(time(data.lastT))}.${data.truncated?' Results are limited to the newest 10,000 inspected players.':""}</p>
    <div class="talent-filters" id="talent-filters">${talentMarkup("filters", data.records)}</div></section>
    <div id="talent-content" aria-live="polite">${talentMarkup("content", data.records)}</div>
    ${opts.generatedNote?`<div class="itag" style="margin-top:16px;line-height:1.6">${esc(opts.generatedNote)}</div>`:""}</div>
    <script type="application/json" id="talent-data">${payload}</script>
    <script>${TALENT_SCRIPT}</script></body></html>`;
}
// Builds the filter row or the results for a set of inspection records. It runs
// at build time for the unfiltered view, so the page paints complete with no
// layout shift, and again in the browser on each filter change: the page
// script embeds this function's source, so the two renders cannot drift.
// autocomplete="off" stops Back/reload restoring a filter over unfiltered content.
function talentMarkup(part, records) {
 const escape=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const num=n=>n.toLocaleString('en-US');
 const classNames={WARRIOR:'Warrior',PALADIN:'Paladin',HUNTER:'Hunter',ROGUE:'Rogue',PRIEST:'Priest',SHAMAN:'Shaman',MAGE:'Mage',WARLOCK:'Warlock',DRUID:'Druid',MONK:'Monk',DEATHKNIGHT:'Death Knight',DEMONHUNTER:'Demon Hunter',EVOKER:'Evoker'};
 const cls=c=>classNames[c]||c;
 if(part==='filters'){
  const fields=[['classFile','Class'],['race','Race'],['realm','Realm'],['faction','Faction'],['level','Level']];
  return fields.map(([key,label])=>{const values=[...new Set(records.map(r=>r[key]).filter(v=>v!=null&&v!==''))].sort((a,b)=>key==='level'?a-b:String(a).localeCompare(String(b)));
   return '<label>'+escape(label)+'<select data-field="'+key+'" autocomplete="off"><option value="">All</option>'+values.map(v=>'<option value="'+escape(v)+'">'+escape(key==='classFile'?cls(v):v)+'</option>').join('')+'</select></label>';
  }).join('');
 }
 const rows=records, withNodes=rows.filter(r=>r.talents.length), classified=rows.filter(r=>r.classification&&r.classification!=='Unknown build'), talents=new Map(), denominators={};
 for(const r of withNodes)denominators[r.classFile]=(denominators[r.classFile]||0)+1;
 for(const r of withNodes){
  for(const t of r.talents){const k=r.classFile+'	'+t.node, old=talents.get(k)||{...t,classFile:r.classFile,players:0,ranks:{}};old.players++;old.ranks[t.rank]=(old.ranks[t.rank]||0)+1;talents.set(k,old);}}
 const iconRoot='https://wow.zamimg.com/images/wow/icons/large/';
 const distribution=[...new Set(rows.map(r=>r.faction).filter(Boolean))].sort().map(faction=>{
  const members=classified.filter(r=>r.faction===faction), combos=new Map();
  for(const r of members){const key=r.classFile+'\t'+r.classification;const group=combos.get(key)||{n:0,hybrid:false};group.n++;group.hybrid ||= !!r.hybrid;combos.set(key,group);}
  const body=[...combos].sort((a,b)=>b[1].n-a[1].n||a[0].localeCompare(b[0])).map(([key,n])=>{
   const [c,tree]=key.split('\t'),classTotal=members.filter(r=>r.classFile===c).length,share=(100*n.n/classTotal).toFixed(1);
   return '<tr><td class="combo-name"><img src="'+iconRoot+'classicon_'+escape(c.toLowerCase())+'.jpg" alt="" loading="lazy">'+escape(cls(c))+' · '+escape(tree)+(n.hybrid?' Hybrid':'')+'</td><td>'+num(n.n)+'</td><td>'+share+'%</td></tr>';
  }).join('');
  return '<section class="panel combo-panel"><div class="ptitle">'+escape(faction)+' class + build</div>'+(members.length?'<div class="tablewrap"><table class="combo-table talent-combo-table"><thead><tr><th class="l">Combination</th><th>Players</th><th>%</th></tr></thead><tbody>'+body+'</tbody></table></div>':'')+'</section>';
 }).join('');
 const popular=[...talents.values()].sort((a,b)=>b.players/denominators[b.classFile]-a.players/denominators[a.classFile]||b.players-a.players||a.name.localeCompare(b.name)).map(t=>{const d=denominators[t.classFile],pct=(100*t.players/d).toFixed(1);return '<tr><td class="l">'+escape(cls(t.classFile))+'</td><td class="l">'+escape(t.name)+'</td><td>'+t.players+' / '+d+' ('+pct+'%)</td></tr>';}).join('');
 return rows.length?'<div class="combo-breakdown">'+distribution+'</div>'+(withNodes.length?'<section class="panel talent-section"><div class="ptitle">Selected talent popularity</div><p class="hint" style="margin-top:0">Shares use players with readable nodes in each filtered class. '+(rows.length-withNodes.length)+' inspected player(s) without readable talent selections excluded.</p><div class="tablewrap"><table class="talent-popularity-table"><thead><tr><th class="l">Class</th><th class="l">Talent</th><th>Players selecting</th></tr></thead><tbody>'+popular+'</tbody></table></div></section>':''):'<section class="panel"><p class="hint talent-empty">No inspections available for these filters.</p></section>';
}

const TALENT_SCRIPT = `
(()=>{
 const records=JSON.parse(document.getElementById('talent-data').textContent).records;
 ${talentMarkup}
 const selects=[...document.querySelectorAll('#talent-filters select')], content=document.getElementById('talent-content');
 const render=()=>{content.innerHTML=talentMarkup('content',records.filter(r=>selects.every(s=>!s.value||String(r[s.dataset.field])===s.value)));};
 for(const s of selects)s.addEventListener('change',render);
})();`;
