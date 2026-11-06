const REALMS = __DATA__;
const EDITION = __EDITION__;
const GROUP_FOREVER = EDITION === "forever";
const REGION_ORDER = {us: 0, eu: 1};
const TYPE_ORDER = {"PvE": 0, "PvP": 1, "RP": 2, "RP-PvP": 3};
const PVP_REALMS = {
  "classic-progression": ["Nightslayer"],
  classic: ["Whitemane", "Thunderfury"],
  "classic-beta": ["PvP", "PvP 2"],
  "forever": ["PvP", "PvP 2"],
};
function realmLabel(r) {
  if (GROUP_FOREVER) return r.displayName || r.name;
  const labels = [];
  if (PVP_REALMS[EDITION]?.includes(r.name)) labels.push("PVP");
  if (r.region && r.regionShown) labels.push(r.region.toUpperCase());
  return r.name + (labels.length ? " " + labels.map(x => `(${x})`).join(" ") : "");
}
const NOTE_HTML = "";
const MIN = 20;
const RACE_ICONS = __RACE_ICONS__, CLASS_ICONS = __CLASS_ICONS__;
const FAC = {Alliance:"#3f83f8", Horde:"#d9363e"};
const CLASS = __CLASSES__;
const RACE_PATHS = __RACE_PATHS__, CLASS_PATHS = __CLASS_PATHS__, COMBOS_HREF = __COMBOS_HREF__;
const raceIcon = r => RACE_ICONS[r] || RACE_ICONS["?"];
const classIcon = c => CLASS_ICONS[c] || RACE_ICONS["?"];
const n = v => v.toLocaleString("en-US");
const pct = (a,b) => b ? Math.round(a*1000/b)/10 : 0;
const esc = s => s.replace(/[&<>"]/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));

const params = new URLSearchParams(location.search);
const requestedRealm = params.get("realm");
const selectedRealm = REALMS.find(r => r.key === requestedRealm || r.aliases?.includes(requestedRealm)) || REALMS.find(r => r.name === requestedRealm) || REALMS[0];
const state = {faction: params.get("faction") || "all", realm: selectedRealm.key, sort: "chars", dir: -1, q: ""};

function sideList(r){ return Object.keys(r.factions).filter(f => state.faction === "all" || f === state.faction); }
function sum(r, key){ return sideList(r).reduce((s,f) => s + r.factions[f][key], 0); }
function merged(r, key){
  const out = {};
  for (const f of sideList(r)) for (const [k,v] of Object.entries(r.factions[f][key])) out[k] = (out[k]||0) + v;
  return out;
}
function topClass(r){ const c = Object.entries(merged(r,"classes")).sort((a,b)=>b[1]-a[1])[0]; return c ? c[0] : null; }

function splitBar(r){
  const a = r.factions.Alliance?.chars || 0, h = r.factions.Horde?.chars || 0;
  if (!a || !h) {
    const only = a ? "Alliance" : "Horde";
    return `<div class="split"><i style="width:100%;background:${FAC[only]}"></i></div><span class="split-cap">${only} only</span>`;
  }
  const ap = pct(a, a+h);
  return `<div class="split" title="Alliance ${ap}% · Horde ${Math.round((100-ap)*10)/10}%"><i style="width:${ap}%;background:${FAC.Alliance}"></i><i style="flex:1;background:${FAC.Horde}"></i></div><span class="split-cap">${Math.round(ap)}% / ${100-Math.round(ap)}%</span>`;
}

function renderTiles(){
  const vis = REALMS.filter(r => sideList(r).length);
  const total = vis.reduce((s,r) => s + sum(r,"chars"), 0);
  const busiest = vis.slice().sort((a,b) => sum(b,"chars") - sum(a,"chars"))[0];
  const week = vis.reduce((s,r) => s + sum(r,"week"), 0);
  document.getElementById("tiles").innerHTML = `
    <div class="tile"><div class="k">${GROUP_FOREVER ? "Region / type groups" : "Realms scanned"}</div><div class="v">${vis.length}</div><div class="s">${vis.filter(r=>sum(r,"chars")>=MIN).length} with enough data</div></div>
    <div class="tile"><div class="k">Characters seen</div><div class="v">${n(total)}</div><div class="s">${n(week)} in the last 7 days</div></div>
    <div class="tile"><div class="k">${GROUP_FOREVER ? "Largest group" : vis.length>1?"Busiest realm":"Realm"}</div><div class="v">${esc(realmLabel(busiest))}</div><div class="s">${vis.length>1?pct(sum(busiest,"chars"), total)+"% of characters seen":"Only group scanned so far"}</div></div>
    <div class="tile"><div class="k">Scanned</div><div class="v mu">__SCANNED__</div><div class="s">Zones over 30 days</div></div>`;
}

function sortKey(r){
  switch (state.sort) {
    case "name": return r.name;
    case "week": return sum(r,"week");
    case "ally": { const a=r.factions.Alliance?.chars||0, h=r.factions.Horde?.chars||0; return a+h ? a/(a+h) : 0; }
    case "top": return topClass(r) || "";
    default: return sum(r,"chars");
  }
}

function renderRows(){
  const q = state.q.trim().toLowerCase();
  const list = REALMS.filter(r => sideList(r).length && [realmLabel(r), r.name, r.region, r.realmType].some(v => v?.toLowerCase().includes(q)))
    .sort((a,b) => {
      if (GROUP_FOREVER) {
        const region = (REGION_ORDER[a.region] ?? 9) - (REGION_ORDER[b.region] ?? 9);
        if (region) return region;
        const type = (TYPE_ORDER[a.realmType] ?? 9) - (TYPE_ORDER[b.realmType] ?? 9);
        if (type) return type;
      }
      const x=sortKey(a), y=sortKey(b); return (x>y?1:x<y?-1:0) * state.dir;
    });
  const body = document.getElementById("rows");
  if (!list.length) { body.innerHTML = `<tr class="empty-row"><td colspan="5">No realm matches “${esc(state.q)}”.</td></tr>`; return; }
  body.innerHTML = list.map(r => {
    const chars = sum(r,"chars"), thin = chars < MIN, tc = topClass(r);
    const tag = r.name.includes("(AU)") ? "Oceanic" : Object.keys(r.factions).length === 1 ? "" : "";
    return `<tr tabindex="0" data-realm="${esc(r.key)}" aria-selected="${r.key===state.realm}" class="${thin?"thin":""}">
      <td class="l"><span class="rname">${esc(realmLabel(r))}</span>${thin?'<span class="rtag">Not enough data</span>':tag?`<span class="rtag">${tag}</span>`:""}</td>
      <td>${n(chars)}</td>
      <td class="bal">${splitBar(r)}</td>
      <td>${n(sum(r,"week"))}</td>
      <td class="l">${tc && !thin ? `<span class="tc"><img src="${classIcon(tc)}" alt="">${CLASS[tc][0]}</span>` : '<span class="mu">—</span>'}</td>
    </tr>`;
  }).join("");
}

function bars(entries, total, colorOf, iconOf){
  return entries.map(([k,v]) => `<div class="bar"><div class="bl">${iconOf?`<img src="${iconOf(k)}" alt="">`:""}<span title="${k in CLASS ? CLASS[k][0] : k}">${k in CLASS ? CLASS[k][0] : k}</span></div><div class="bt"><i style="width:${pct(v, entries[0][1])}%;background:${colorOf(k)}"></i></div><div class="bn">${pct(v,total)}%</div></div>`).join("");
}

function renderDetail(){
  const r = REALMS.find(x => x.key === state.realm) || REALMS[0];
  const chars = sum(r,"chars"), el = document.getElementById("detail");
  const a = r.factions.Alliance?.chars || 0, h = r.factions.Horde?.chars || 0;
  const fb = a && h
    ? `<div class="fbal-bar"><i style="width:${pct(a,a+h)}%;background:${FAC.Alliance}"></i><i style="flex:1;background:${FAC.Horde}"></i></div>
       <div class="fbal-key"><span><b style="color:${FAC.Alliance}">Alliance</b> ${n(a)} · ${Math.round(pct(a,a+h))}%</span><span>${100-Math.round(pct(a,a+h))}% · ${n(h)} <b style="color:${FAC.Horde}">Horde</b></span></div>`
    : `<div class="fbal-bar"><i style="width:100%;background:${FAC[a?"Alliance":"Horde"]}"></i></div>
       <div class="fbal-key"><span><b style="color:${FAC[a?"Alliance":"Horde"]}">${a?"Alliance":"Horde"}</b> ${n(a||h)}</span><span class="mu">${a?"Horde":"Alliance"} not scanned yet</span></div>`;

  if (!chars) { el.innerHTML = `<div class="panel"><div class="rd-name">${esc(realmLabel(r))}</div><p class="rd-sub">No ${state.faction} characters scanned on this realm yet.</p></div>`; document.getElementById("combos").innerHTML=""; return; }

  const races = Object.entries(merged(r,"races")).sort((x,y)=>y[1]-x[1]);
  const raceFac = {}; for (const f of sideList(r)) for (const k of Object.keys(r.factions[f].races)) raceFac[k] = raceFac[k] && raceFac[k] !== FAC[f] ? "#8b90a0" : FAC[f];
  const thin = chars < MIN;
  const classPanels = sideList(r).map(f => {
    const fd = r.factions[f];
    const classes = Object.entries(fd.classes).sort((x,y)=>y[1]-x[1]);
    return `<section class="faction-class" style="--faction-color:${FAC[f] || '#8b90a0'}" aria-label="${esc(f)} class distribution">
      <div class="faction-class-head"><span>${esc(f)}</span><span>${n(fd.chars)} observed characters</span></div>
      ${fd.chars < MIN ? `<p class="rd-sub">Only ${n(fd.chars)} characters seen. Not enough data to rank classes yet.</p>` :
        bars(classes, fd.chars, k => "#" + CLASS[k][1], classIcon)}
    </section>`;
  }).join("");

  el.innerHTML = `
    <div class="panel">
      <div class="rd-head"><div><div class="ptitle" style="margin-bottom:8px">${GROUP_FOREVER ? "Region / type" : "Realm"}</div><div class="rd-name">${esc(realmLabel(r))}</div></div><div class="rd-sub">${state.faction==="all"?"Both factions":state.faction}</div></div>
      <div class="rd-stats">
        <div class="tile"><div class="k">Seen</div><div class="v">${n(chars)}</div></div>
        <div class="tile"><div class="k">7 days</div><div class="v">${n(sum(r,"week"))}</div></div>
        <div class="tile"><div class="k">24 hours</div><div class="v">${n(sum(r,"day"))}</div></div>
      </div>
      <div class="ptitle">Faction balance</div>
      <div class="fbal">${fb}</div>
    </div>
    <div class="panel">
      <div class="ptitle">Classes by faction</div>
      <p class="rd-sub faction-class-note">Each share is within that faction on this realm.</p>
      ${classPanels}
    </div>
    <div class="panel">
      <div class="ptitle">Race mix</div>
      ${thin ? `<p class="rd-sub">Not enough data yet.</p>` : bars(races.slice(0,10), chars, k => raceFac[k], raceIcon)}${races.length>10?`<p class="rd-sub" style="margin:8px 0 0">+ ${races.length-10} more races, ${pct(races.slice(10).reduce((t,x)=>t+x[1],0),chars)}% combined</p>`:""}
    </div>`;

  const blocks = Object.keys(r.factions).filter(f => state.faction==="all" || f===state.faction).map(f => {
    const fd = r.factions[f];
    const top = Object.entries(fd.combos).sort((x,y)=>y[1]-x[1]).slice(0,3);
    const cards = fd.chars < MIN ? `<div class="combo-none">Not enough data yet (${fd.chars} seen).</div>` : top.map(([k,v],i) => {
      const [race, cls] = k.split("\t");
      const raceImg = `<img src="${raceIcon(race)}" alt="${race} icon">`, classImg = `<img src="${classIcon(cls)}" alt="${CLASS[cls][0]} icon">`;
      return `<article class="combocard"><span class="rank">${i+1}</span><div class="comboicons">${RACE_PATHS[race] ? `<a href="${RACE_PATHS[race]}" aria-label="${race} race page">${raceImg}</a>` : raceImg}${CLASS_PATHS[cls] ? `<a href="${CLASS_PATHS[cls]}" aria-label="${CLASS[cls][0]} class page">${classImg}</a>` : classImg}</div><div><div class="comboname">${race} ${CLASS[cls][0]}</div><div class="combostat">${n(v)} characters · ${pct(v, fd.chars)}% of ${f}</div></div></article>`;
    }).join("");
    return `<section class="comboblock" style="--fac:${FAC[f]}"><div class="combofaction">${f}</div>${cards}</section>`;
  }).join("");
  document.getElementById("combos").innerHTML = `<div class="ptitle combo-title">Most popular race + class on ${esc(realmLabel(r))}<a class="combo-more" href="${COMBOS_HREF}">All combinations</a></div><div class="combogrid">${blocks}</div>`;
}

function syncUrl(){
  const p = new URLSearchParams();
  if (state.realm !== REALMS[0].key) p.set("realm", state.realm);
  if (state.faction !== "all") p.set("faction", state.faction);
  // Realm keys are "region:Name"; a bare colon is valid in a query and reads better than %3A.
  const qs = p.toString().replace(/%3A/gi, ":");
  history.replaceState(null, "", qs ? "?" + qs : location.pathname);
}
function render(){ renderTiles(); renderRows(); renderDetail(); }

document.getElementById("rows").addEventListener("click", e => { const tr = e.target.closest("tr[data-realm]"); if (tr) { state.realm = tr.dataset.realm; renderRows(); renderDetail(); syncUrl(); } });
document.getElementById("rows").addEventListener("keydown", e => { if ((e.key==="Enter"||e.key===" ") && e.target.dataset.realm) { e.preventDefault(); e.target.click(); } });
document.getElementById("fchips").addEventListener("click", e => {
  const b = e.target.closest("button"); if (!b) return;
  state.faction = b.dataset.f;
  for (const c of document.querySelectorAll("#fchips button")) c.setAttribute("aria-pressed", c === b);
  render(); syncUrl();
});
document.getElementById("q").addEventListener("input", e => { state.q = e.target.value; renderRows(); });
document.querySelectorAll(".realm-table th").forEach(th => th.addEventListener("click", () => {
  const k = th.dataset.sort;
  state.dir = state.sort === k ? -state.dir : (k === "name" || k === "top" ? 1 : -1);
  state.sort = k;
  document.querySelectorAll(".realm-table th .ar").forEach(a => { a.classList.toggle("off", a.parentElement !== th); });
  th.querySelector(".ar").textContent = state.dir > 0 ? "▲" : "▼";
  renderRows();
}));
for (const c of document.querySelectorAll("#fchips button")) c.setAttribute("aria-pressed", c.dataset.f === state.faction);
render();
