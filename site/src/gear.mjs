import { esc, seoLabel, seoHead, pageHeading, CENSUS_STYLESHEET, SELECT_SCRIPT, filterData, FILTER_LOADER } from "./census.mjs";

function time(t) { return t ? new Date(t*1000).toISOString().slice(0,16).replace("T"," ")+"Z" : "Unavailable"; }

// Packed like the talents page: repeated strings become indexes into per-field
// lists, and each equipped item is [slot, item-table index, enchanted], plus a
// fourth index into `runes` when the item carries a Season of Discovery rune.
const PACKED_FIELDS = ["classFile", "race", "realm", "faction"];
export function packGearRecords(records) {
  const values = {}, index = {}, items = [], itemIndex = new Map(), runes = [], runeIndex = new Map();
  for (const f of PACKED_FIELDS) { values[f] = []; index[f] = new Map(); }
  const ref = (f, v) => {
    if (v == null || v === "") return -1;
    if (!index[f].has(v)) { index[f].set(v, values[f].length); values[f].push(v); }
    return index[f].get(v);
  };
  const rows = records.filter((r) => r.gear && r.gear.length).map((r) => [...PACKED_FIELDS.map((f) => ref(f, r[f])), r.level ?? -1,
    r.gear.map((g) => {
      if (!itemIndex.has(g.id)) { itemIndex.set(g.id, items.length); items.push([g.id, g.name, g.quality ?? -1, g.icon || ""]); }
      const w = [g.slot, itemIndex.get(g.id), g.enchant ? 1 : 0];
      if (g.rune) {
        if (!runeIndex.has(g.rune)) { runeIndex.set(g.rune, runes.length); runes.push(g.rune); }
        w.push(runeIndex.get(g.rune));
      }
      return w;
    })]);
  return { fields: PACKED_FIELDS, values, items, rows, ...(runes.length ? { runes } : {}) };
}
// Inverse of packGearRecords, in the record shape gearMarkup reads. The page
// script embeds this function's source.
export function unpackGearRecords(packed) {
 const n=packed.fields.length;
 return packed.rows.map(row=>{
  const r={};packed.fields.forEach((f,i)=>{r[f]=row[i]<0?null:packed.values[f][row[i]];});
  r.level=row[n]<0?null:row[n];
  r.gear=row[n+1].map(([slot,i,enchanted,rune])=>{const it=packed.items[i];return {slot,id:it[0],name:it[1],quality:it[2]<0?null:it[2],icon:it[3]||null,enchanted:enchanted===1,rune:rune==null?null:packed.runes[rune]};});
  return r;
 });
}

// The filter records, written by the build next to the page (see filterData).
function packGearPage(data, branch) {
  const packed=packGearRecords(data.records);
  packed.wowhead="https://www.wowhead.com/"+(branch?branch+"/":"")+"item=";
  return packed;
}
export function gearData(data, opts={}) { return filterData("gear", packGearPage(data, opts.branch)); }

export function renderGearHtml(data, opts={}) {
  const nav=opts.nav||{};
  const seoGameLabel=seoLabel(opts.gameLabel||"WoW Forever");
  const topic=seoGameLabel+" Gear: Most Equipped Items";
  const packed=packGearPage(data, opts.branch);
  const dataFile=filterData("gear", packed).file;
  const records=unpackGearRecords(packed);
  const withGear=records.length;
  const summary=withGear
    ? `Latest inspection: ${esc(time(data.lastT))}`
    : `No inspected gear for ${esc(opts.gameLabel||"this edition")} yet. Gear is collected during the same inspection as talents and will appear in a future snapshot when available.`;
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    ${seoHead(topic+" – WoWCensus", seoGameLabel+" gear seen on inspected players: the most equipped items in every slot for each class and spec, and how often they are enchanted.", opts.canonical)}
    <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96"><link rel="icon" href="/favicon-96.png" type="image/png" sizes="96x96">
    <link rel="preload" href="/fonts/press-start-2p-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/fonts/vt323-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/fonts/cinzel-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/fonts/friz-quadrata-regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="${esc(opts.stylesheet||"/style.css")}">
    ${CENSUS_STYLESHEET}
    <style>
    .gear-summary{font-family:"Friz Quadrata Web","Friz Quadrata","Friz Quadrata Std","Fritz Quadrata","Cinzel",Georgia,serif;font-size:16px;font-weight:400;letter-spacing:normal;line-height:1.66;color:#ccc;text-align:left}
    .gear-filters{display:flex;flex-wrap:wrap;gap:14px;margin-top:14px}.gear-filters label{display:grid;gap:5px;font-size:17px;color:var(--muted)}
    .gear-filters select{color:var(--ink);background:var(--panel2);border:2px solid var(--line);font-family:var(--term);font-size:18px;padding:6px 10px;max-width:230px}
    .gear-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px}
    .gear-top-panel{margin-bottom:22px}.gear-top-grid{display:grid;grid-template-columns:repeat(9,minmax(0,1fr));gap:8px}
    .gear-top-item{min-width:0;min-height:144px;display:flex;flex-direction:column;align-items:center;text-align:center;padding:10px 5px;background:var(--panel2);border:2px solid var(--line);box-shadow:3px 3px 0 var(--shadow);text-decoration:none}
    .gear-top-item:hover,.gear-top-item:focus-visible{border-color:var(--gold)}
    .gear-top-slot{font-family:var(--pixel);font-size:8px;line-height:1.5;letter-spacing:1px;text-transform:uppercase;color:var(--muted);min-height:25px}
    .gear-top-item img{border:2px solid #000;box-shadow:0 0 0 1px var(--line);margin:3px 0 6px}
    .gear-top-name{font-family:var(--term);font-size:18px;line-height:1.05;overflow-wrap:anywhere}
    .gear-top-count{font-family:var(--term);font-size:15px;color:var(--muted);margin-top:auto;padding-top:5px}
    .gear-slot .hint{margin:0 0 8px}.gear-slot .gear-rune-hint{margin:16px 0 8px}.gear-table td.gear-rune{color:#1eff00}
    .gear-table{table-layout:fixed;min-width:0}.gear-table th:first-child,.gear-table td:first-child{width:54%}
    .gear-table th:not(:first-child),.gear-table td:not(:first-child){padding-left:6px;padding-right:6px}
    .gear-table th:nth-child(2),.gear-table td:nth-child(2){width:26%}.gear-table th:nth-child(3),.gear-table td:nth-child(3){width:20%}
    .gear-table td:first-child{white-space:normal;line-height:1.35}
    .gear-table a{text-decoration:none;display:inline-flex;align-items:center;gap:8px}.gear-table a:hover span{text-decoration:underline}
    .gear-table img{width:20px;height:20px;flex:none;border:1px solid #000;box-shadow:0 0 0 1px var(--line)}
    .wowhead-tooltip table{width:auto;min-width:0;table-layout:auto}
    .wowhead-tooltip td,.wowhead-tooltip th{white-space:normal;overflow:visible;text-overflow:clip;border-bottom:0;font-variant-numeric:normal}
    .wowhead-tooltip tr:hover{background:none}
    .iq0{color:#9d9d9d}.iq1{color:#fff}.iq2{color:#1eff00}.iq3{color:#0070dd}.iq4{color:#a335ee}.iq5{color:#ff8000}.iq6,.iq7{color:#e6cc80}
    @media(max-width:1050px){.gear-grid{grid-template-columns:1fr}}
    @media(max-width:900px){.gear-top-grid{grid-template-columns:repeat(5,minmax(0,1fr))}}
    @media(max-width:600px){.gear-filters label{flex:1 1 40%}.gear-filters select{max-width:100%;width:100%}.gear-filters .vsel-trigger{width:100%}
      .gear-top-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.gear-top-item{min-height:132px}
      .gear-table th:first-child,.gear-table td:first-child{width:46%}.gear-table th:nth-child(2),.gear-table td:nth-child(2){width:31%}.gear-table th:nth-child(3),.gear-table td:nth-child(3){width:23%}}
    </style></head><body><div class="crt" aria-hidden="true"></div><div class="wrap">
    <header class="ihead"><div class="page-heading">${pageHeading("WoWCensus", nav, topic)}${nav.games||""}</div>${nav.breadcrumbs||""}</header>
    <div class="page-controls">${nav.pages||""}</div>
    ${withGear?`<section class="panel gear-top-panel"><div class="ptitle">Most equipped by slot</div><div id="gear-top">${gearMarkup("top", records, packed.wowhead)}</div><div class="gear-filters" id="gear-filters">${gearMarkup("filters", records, packed.wowhead)}</div><p class="hint" style="margin:14px 0 0">${summary}</p></section>`:`<section class="panel" style="margin-bottom:22px"><p class="hint gear-summary" style="margin:0">${summary}</p></section>`}
    ${withGear?`<div id="gear-content" aria-live="polite" data-src="${esc(dataFile)}">${gearMarkup("content", records, packed.wowhead)}</div>`:""}
    ${opts.generatedNote?`<div class="itag" style="margin-top:16px;line-height:1.6">${esc(opts.generatedNote)}</div>`:""}</div>
    ${withGear?`<script>${GEAR_SCRIPT}</script>${SELECT_SCRIPT}
    <script>const whTooltips={colorLinks:false,iconizeLinks:false,renameLinks:false};</script><script src="https://wow.zamimg.com/js/tooltips.js" async></script>`:""}</body></html>`;
}

// Builds the filter row or the per-slot results. Runs at build time for the
// unfiltered view and in the browser on each filter change (the page script
// embeds this source), exactly like the talents page.
function gearMarkup(part, records, wowhead) {
 const escape=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const classNames={WARRIOR:'Warrior',PALADIN:'Paladin',HUNTER:'Hunter',ROGUE:'Rogue',PRIEST:'Priest',SHAMAN:'Shaman',MAGE:'Mage',WARLOCK:'Warlock',DRUID:'Druid',MONK:'Monk',DEATHKNIGHT:'Death Knight',DEMONHUNTER:'Demon Hunter',EVOKER:'Evoker'};
 const cls=c=>classNames[c]||c;
 if(part==='filters'){
  const fields=[['classFile','Class'],['race','Race'],['realm','Realm'],['faction','Faction'],['level','Level']];
  return fields.map(([key,label])=>{const values=[...new Set(records.map(r=>r[key]).filter(v=>v!=null&&v!==''))].sort((a,b)=>key==='level'?a-b:String(a).localeCompare(String(b)));
   return '<label>'+escape(label)+'<select class="vsel-native" data-field="'+key+'" autocomplete="off"><option value="">All</option>'+values.map(v=>'<option value="'+escape(v)+'">'+escape(key==='classFile'?cls(v):v)+'</option>').join('')+'</select></label>';
  }).join('');
 }
 if(!records.length)return part==='top'?'<p class="hint">No inspected gear for these filters.</p>':'<section class="panel"><p class="hint" style="margin:0">No inspected gear for these filters.</p></section>';
 // Paper-doll order; paired ring and trinket slots are pooled.
 const groups=[['Head',[1]],['Neck',[2]],['Shoulder',[3]],['Back',[15]],['Chest',[5]],['Wrist',[9]],['Hands',[10]],['Waist',[6]],['Legs',[7]],['Feet',[8]],
  ['Rings',[11,12]],['Trinkets',[13,14]],['Main hand',[16]],['Off hand',[17]],['Ranged',[18]],['Shirt',[4]],['Tabard',[19]]];
 const total=records.length;
 const tiles=groups.map(([label,slots])=>{
  const items=new Map(),runes=new Map(),iconRoot='https://wow.zamimg.com/images/wow/icons/small/';let filled=0,enchanted=0,runed=0;
  for(const r of records){
   const worn=r.gear.filter(g=>slots.includes(g.slot));
   if(!worn.length)continue;
   filled++;if(worn.some(g=>g.enchanted))enchanted++;
   for(const id of new Set(worn.map(g=>g.id))){const g=worn.find(w=>w.id===id),old=items.get(id)||{id,name:g.name,quality:g.quality,icon:g.icon,players:0};old.players++;items.set(id,old);}
   // Season of Discovery: each player counts once per rune; paired rings pool.
   const wornRunes=new Set(worn.map(g=>g.rune).filter(Boolean));
   if(wornRunes.size)runed++;
   for(const name of wornRunes)runes.set(name,(runes.get(name)||0)+1);
  }
  if(!filled)return '';
  const top=[...items.values()].sort((a,b)=>b.players-a.players||String(a.name).localeCompare(String(b.name))).slice(0,10);
  if(part==='top'){
   const it=top[0];
   return '<a class="gear-top-item" href="'+escape(wowhead+it.id)+'" target="_blank" rel="noopener"><span class="gear-top-slot">'+escape(label)+'</span><img src="https://wow.zamimg.com/images/wow/icons/large/'+escape(it.icon||'inv_misc_questionmark')+'.jpg" alt="" width="40" height="40"><span class="gear-top-name iq'+(it.quality??1)+'">'+escape(it.name)+'</span><span class="gear-top-count">'+it.players.toLocaleString('en-US')+' players</span></a>';
  }
  const body=top.map(it=>'<tr><td class="l"><a class="iq'+(it.quality??1)+'" href="'+escape(wowhead+it.id)+'" target="_blank" rel="noopener"><img src="'+iconRoot+escape(it.icon||'inv_misc_questionmark')+'.jpg" alt="" width="20" height="20" loading="lazy"><span>'+escape(it.name)+'</span></a></td><td>'+it.players.toLocaleString('en-US')+'</td><td>'+(100*it.players/filled).toFixed(1)+'%</td></tr>').join('');
  // Shares are of players with a rune recorded here: older inspects carry none.
  const runeRows=[...runes].sort((a,b)=>b[1]-a[1]||a[0].localeCompare(b[0])).slice(0,10).map(([name,n])=>'<tr><td class="l gear-rune">'+escape(name)+'</td><td>'+n.toLocaleString('en-US')+'</td><td>'+(100*n/runed).toFixed(1)+'%</td></tr>').join('');
  const runeTable=runed?'<p class="hint gear-rune-hint">Runes · '+runed.toLocaleString('en-US')+' player'+(runed===1?'':'s')+' with a rune recorded</p><div class="tablewrap"><table class="gear-table"><thead><tr><th class="l">Rune</th><th>Players</th><th>%</th></tr></thead><tbody>'+runeRows+'</tbody></table></div>':'';
  return '<section class="panel gear-slot"><div class="ptitle">'+escape(label)+'</div><p class="hint">'+filled.toLocaleString('en-US')+' of '+total.toLocaleString('en-US')+' players with this slot filled · '+(100*enchanted/filled).toFixed(0)+'% enchanted · '+items.size.toLocaleString('en-US')+' distinct item'+(items.size===1?'':'s')+'</p><div class="tablewrap"><table class="gear-table"><thead><tr><th class="l">Item</th><th>Players</th><th>%</th></tr></thead><tbody>'+body+'</tbody></table></div>'+runeTable+'</section>';
 }).join('');
 return '<div class="'+(part==='top'?'gear-top-grid':'gear-grid')+'">'+tiles+'</div>';
}

const GEAR_SCRIPT = `
(()=>{
 ${unpackGearRecords}
 ${gearMarkup}
 ${FILTER_LOADER}
 const filters=document.getElementById('gear-filters'), selects=[...filters.querySelectorAll('select')], content=document.getElementById('gear-content'), top=document.getElementById('gear-top');
 const load=loadFilterData(filters,content,packed=>({wowhead:packed.wowhead,records:unpackGearRecords(packed)}));
 const render=async()=>{
  writeFilterUrl(selects);
  content.setAttribute('aria-busy','true');
  let data;try{data=await load();}catch{filterError(content);return;}
  const filtered=data.records.filter(r=>selects.every(s=>!s.value||String(r[s.dataset.field])===s.value));
  top.innerHTML=gearMarkup('top',filtered,data.wowhead);content.innerHTML=gearMarkup('content',filtered,data.wowhead);
  content.removeAttribute('aria-busy');
 };
 for(const s of selects)s.addEventListener('change',render);
 if(readFilterUrl(selects))render();
})();`;
