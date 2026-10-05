// Shared rendering for the Forever (Beta) cross-faction census in the static build.

// Race/class shares cover characters seen in this many days of census /who
// queries (see loadCensus in tools/local-site-data.mjs).
export const CENSUS_WINDOW_DAYS = 14;

export function esc(s) {
  return String(s).replace(/[&<>"]/g, (m) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[m]));
}

// Search-facing labels always lead with "WoW" ("TBC Anniversary" -> "WoW TBC Anniversary").
export function seoLabel(gameLabel) {
  return /^WoW\b/i.test(gameLabel) ? gameLabel : "WoW " + gameLabel;
}

// Title and description, plus the matching Open Graph tags when the page has a canonical URL.
export function seoHead(title, description, canonical) {
  return "<title>" + esc(title) + "</title>\n" +
    '<meta name="description" content="' + esc(description) + '">' +
    (canonical ? '\n<link rel="canonical" href="' + esc(canonical) + '">' +
      '\n<meta property="og:type" content="website">\n<meta property="og:site_name" content="WoWCensus">' +
      '\n<meta property="og:title" content="' + esc(title) + '">\n<meta property="og:description" content="' + esc(description) + '">' +
      '\n<meta property="og:url" content="' + esc(canonical) + '">' : "");
}

// Static pages show the WoWCensus logo as their H1, so the page topic rides beneath it.
export function pageHeading(heading, navigation, topic) {
  return '<h1 class="iname">' + esc(heading) +
    (navigation.games && topic ? '<span class="itopic">' + esc(topic) + "</span>" : "") + "</h1>";
}

// Blizzard's beta realms are internally "Classic Beta PvE" / "Classic Beta PvP 2";
// players just call them PvE and PvP. Display-only -- the raw names stay the
// data keys. Handles "-Faction" suffixes and "realm:" game keys.
export function realmName(name) {
  const s = String(name == null ? "" : name).replace(/^realm:/, "");
  if (!/^Classic Beta\s+/i.test(s)) return s;
  return s.replace(/^Classic Beta\s+/i, "").replace(/\s+\d+(?=-|$)/, "").trim() || s;
}

export const CLASS_META = {
  WARRIOR: ["Warrior", "C79C6E"], PALADIN: ["Paladin", "F58CBA"],
  HUNTER:  ["Hunter", "ABD473"],  ROGUE:   ["Rogue", "FFF569"],
  PRIEST:  ["Priest", "FFFFFF"],  SHAMAN:  ["Shaman", "0070DE"],
  MAGE:    ["Mage", "69CCF0"],    WARLOCK: ["Warlock", "9482C9"],
  DRUID:   ["Druid", "FF7D0A"],   DEATHKNIGHT: ["Death Knight", "C41F3B"],
  MONK: ["Monk", "00FF96"], DEMONHUNTER: ["Demon Hunter", "A330C9"],
  EVOKER: ["Evoker", "33937F"],
};

const FACTION_COLOR = { Alliance: "#3f83f8", Horde: "#d9363e", Unknown: "#8b90a0" };
function factionColor(f) { return FACTION_COLOR[f] || FACTION_COLOR.Unknown; }

const ICON_ROOT = "https://wow.zamimg.com/images/wow/icons/large/";
const RACE_ICON = {
  Human: "achievement_character_human_male", Dwarf: "achievement_character_dwarf_male",
  Gnome: "achievement_character_gnome_male", "Night Elf": "achievement_character_nightelf_male",
  Orc: "achievement_character_orc_male", Tauren: "achievement_character_tauren_male",
  Troll: "achievement_character_troll_male", Undead: "achievement_character_undead_male",
  Scourge: "achievement_character_undead_male",
  "Blood Elf": "achievement_character_bloodelf_male", Draenei: "achievement_character_draenei_male",
  Goblin: "race_goblin_male", Pandaren: "race_pandaren_male", Worgen: "race_worgen_male",
  Nightborne: "achievement_alliedrace_nightborne",
  "Highmountain Tauren": "achievement_alliedrace_highmountaintauren",
  "Void Elf": "achievement_alliedrace_voidelf",
  "Lightforged Draenei": "achievement_alliedrace_lightforgeddraenei",
  "Dark Iron Dwarf": "achievement_alliedrace_darkirondwarf",
  "Mag'har Orc": "achievement_alliedrace_magharorc",
  "Kul Tiran": "achievement_alliedrace_kultiranhuman",
  "Zandalari Troll": "achievement_alliedrace_zandalaritroll",
  Mechagnome: "achievement_alliedrace_mechagnome", Vulpera: "achievement_alliedrace_vulpera",
  Dracthyr: "race_dracthyr_male",
  "High Order Skyborne": "achievement_character_bloodelf_male",
  "Windshaper Skyborne": "achievement_character_bloodelf_male",
};
function iconUrl(name) { return ICON_ROOT + name + ".jpg"; }
function classIcon(token) { return iconUrl("classicon_" + String(token || "warrior").toLowerCase()); }
function raceIcon(race) { return iconUrl(RACE_ICON[race] || "inv_misc_questionmark"); }

function comboCards(groups, opts = {}) {
  const cards = groups.map((g) => {
    const top = sortedEntries(g.combos || {})[0];
    if (!top) return "";
    const [race, token] = top[0].split("\t");
    const className = CLASS_META[token] ? CLASS_META[token][0] : token;
    const share = g.characters ? ((top[1] / g.characters) * 100).toFixed(1) : "0.0";
    return '<article class="combocard" style="--fac:' + factionColor(g.faction) + '">' +
      '<div class="comboicons"><img src="' + esc(raceIcon(race)) + '" alt="' + esc(race) + ' icon" loading="lazy">' +
      '<img src="' + esc(classIcon(token)) + '" alt="' + esc(className) + ' icon" loading="lazy"></div>' +
      '<div><div class="combofaction">' + esc(g.faction) + '</div>' +
      '<div class="comboname">' + esc(race) + ' ' + esc(className) + '</div>' +
      '<div class="combostat">' + top[1].toLocaleString() + ' characters &middot; ' + share + '% of ' + esc(g.faction) + '</div></div>' +
      '</article>';
  }).filter(Boolean).join("");
  const more = opts.comboHref
    ? '<a class="combo-more" href="' + esc(opts.comboHref) + '">See more</a>'
    : "";
  return cards ? '<section class="combos"><div class="ptitle combo-title">Most popular race + class' + more + '</div><div class="combogrid">' + cards + '</div></section>' : "";
}

function comboLabel(key) {
  const [race, token] = String(key || "").split("\t");
  return { race, token, className: CLASS_META[token] ? CLASS_META[token][0] : token };
}

function comboAnalysis(groups, flavorNote) {
  const leaders = groups.map((g) => {
    const top = sortedEntries(g.combos || {})[0];
    if (!top) return "";
    const combo = comboLabel(top[0]);
    const share = g.characters ? ((top[1] / g.characters) * 100).toFixed(1) : "0.0";
    return combo.race + " " + combo.className + " leads the observed " + g.faction +
      " sample (" + share + "% of its characters)";
  }).filter(Boolean);
  return (flavorNote ? flavorNote + " " : "") +
    (leaders.length ? leaders.join("; ") + "." : "");
}

function comboBreakdown(groups) {
  return groups.map((g) => {
    const rows = sortedEntries(g.combos || {}).map(([key, count]) => {
      const combo = comboLabel(key);
      const share = g.characters ? ((count / g.characters) * 100).toFixed(1) : "0.0";
      return '<tr><td class="combo-name"><img src="' + esc(raceIcon(combo.race)) + '" alt="" loading="lazy">' +
        '<img src="' + esc(classIcon(combo.token)) + '" alt="" loading="lazy">' + esc(combo.race) + " " + esc(combo.className) +
        '</td><td>' + count.toLocaleString() + '</td><td>' + share + "%</td></tr>";
    }).join("");
    return '<section class="panel combo-panel"><div class="ptitle">' + esc(g.faction) + ' race + class</div>' +
      '<p class="hint" style="margin-top:0">' + g.characters.toLocaleString() + ' observed characters, most common first.</p>' +
      '<div class="tablewrap"><table class="combo-table"><thead><tr><th class="l">Combination</th><th>Characters</th><th>Share</th></tr></thead><tbody>' +
      (rows || '<tr><td colspan="3" class="l">No race + class data yet.</td></tr>') +
      '</tbody></table></div></section>';
  }).join("");
}

// One horizontal bar row. `max` scales every bar in a chart against the same
// value, so bar lengths compare across the whole chart. `text` is what prints
// at the right -- a plain count unless the caller formats it (gold, say).
export function barRow(label, n, max, color, text) {
  const pct = max > 0 ? Math.max((n / max) * 100, 0.6) : 0;
  return '<div class="bar">' +
    '<div class="bl">' + esc(label) + "</div>" +
    '<div class="bt"><i style="width:' + pct.toFixed(2) + "%;background:" + color + '"></i></div>' +
    '<div class="bn">' + esc(text == null ? n.toLocaleString() : text) + "</div></div>";
}

export function barChart(entries, max, colorFor, labelFor, valueFor) {
  return entries.map(([k, n]) =>
    barRow(labelFor ? labelFor(k) : k, n, max, colorFor(k), valueFor ? valueFor(n, k) : null)).join("");
}

export function sortedEntries(dist) {
  return Object.keys(dist)
    .map((k) => [k, dist[k]])
    .sort((a, b) => b[1] - a[1] || String(a[0]).localeCompare(String(b[0])));
}

export const CENSUS_STYLE = `
  .iname .itopic{display:block; margin-top:6px; font-family:var(--pixel); font-size:9px; font-weight:400; letter-spacing:1px; line-height:1.6; text-transform:uppercase; color:var(--muted); text-shadow:none}
  .chartgrid{display:grid; grid-template-columns:1fr 1fr; gap:22px; align-items:start}
  @media (max-width:820px){.chartgrid{grid-template-columns:1fr}}
  .census .legend{display:flex; gap:18px; margin:0 0 14px}
  .census .key{display:inline-flex; align-items:center; gap:7px; font-size:17px; color:var(--muted)}
  .census .key i{width:13px; height:13px; display:inline-block}
  .race-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap}
  .race-head .ptitle{margin-bottom:10px}
  .race-toggle{display:inline-flex;border:2px solid var(--line);box-shadow:3px 3px 0 var(--shadow);flex-wrap:wrap}
  .race-toggle button{font-family:var(--pixel);font-size:8px;letter-spacing:1px;text-transform:uppercase;color:var(--ink);background:var(--panel2);border:0;border-right:2px solid var(--line);padding:9px 11px;cursor:pointer}
  .race-toggle button:last-child{border-right:0}
  .race-toggle button:hover{color:var(--gold)}
  .race-toggle button[aria-pressed="true"]{background:var(--gold);color:#161018}
  .race-toggle button[aria-pressed="true"]:hover{color:#161018}
  .race-toggle button:focus-visible{outline:2px solid var(--gold);outline-offset:3px}
  .bar{display:grid; grid-template-columns:172px 1fr 62px; align-items:center; gap:10px; margin-bottom:6px}
  .bar .bl{font-family:var(--pixel); font-size:8px; letter-spacing:1px; text-align:right;
    color:var(--ink); overflow:hidden; text-overflow:ellipsis; white-space:nowrap}
  .bar .bt{background:var(--panel2); border:2px solid var(--line); height:20px; padding:1px}
  .bar .bt i{display:block; height:100%}
  .bar .bn{text-align:right; color:var(--ink)}
  @media (max-width:520px){.bar{grid-template-columns:96px 1fr 52px}}
  .vchips{margin-bottom:18px}
  .combos{margin:0 0 22px}.combos>.ptitle{margin:0 0 10px}
  .combo-title{display:flex;align-items:center;justify-content:space-between;gap:14px}
  .combo-more{color:var(--blue);font-family:var(--term);font-size:20px;font-weight:400;letter-spacing:0;text-transform:none}
  .combogrid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  .combocard{display:flex;align-items:center;gap:16px;padding:15px 17px;background:var(--panel);
    border:2px solid var(--line);border-left:5px solid var(--fac);box-shadow:4px 4px 0 rgba(0,0,0,.32)}
  .comboicons{display:flex;min-width:98px}.comboicons img{width:56px;height:56px;object-fit:cover;
    border:3px solid #b99b55;box-shadow:2px 2px 0 #000;background:#111;image-rendering:auto}
  .comboicons img+img{margin-left:-14px;margin-top:12px}
  .combofaction{font-family:var(--pixel);font-size:8px;letter-spacing:1px;color:var(--fac);margin-bottom:7px}
  .comboname{font-family:var(--pixel);font-size:11px;line-height:1.5;color:var(--ink);margin-bottom:5px}
  .combostat{color:var(--muted);font-size:17px}
  @font-face{font-family:"Friz Quadrata Web";src:url("/fonts/friz-quadrata-regular.woff2") format("woff2");font-style:normal;font-weight:400;font-display:optional}
  .combo-analysis{font-family:"Friz Quadrata Web","Friz Quadrata","Friz Quadrata Std","Fritz Quadrata","Cinzel",Georgia,serif;font-size:17px;letter-spacing:.025em;line-height:1.55;color:var(--ink)}
  .combo-breakdown{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px;align-items:start}
  .combo-panel{margin:0}.combo-table{min-width:0}.combo-table th,.combo-table td{width:auto}.combo-table th:nth-child(1),.combo-table td:nth-child(1){width:64%}
  .combo-name{display:flex;align-items:center;gap:7px}.combo-name img{width:24px;height:24px;border:2px solid #b99b55;box-shadow:1px 1px 0 #000;background:#111}
  @media (max-width:760px){.combogrid{grid-template-columns:1fr}}
  @media (max-width:760px){.combo-breakdown{grid-template-columns:1fr}.combo-panel{margin-bottom:22px}.combo-panel:last-child{margin-bottom:0}}
  @media (max-width:420px){.combocard{padding:12px;gap:11px}.comboicons{min-width:82px}
    .comboicons img{width:48px;height:48px}.comboname{font-size:9px}}`;


// A page can slice its content on more than one axis (realm, faction). Each
// axis gets a chip row; every combination is rendered up front as a .vpane
// carrying one data-<dim> per axis, and the script just shows the pane whose
// attributes match every active chip. Static file, no fetching.
// The realm axis renders as an "All realms" chip beside a select holding the
// individual realms, so the row stays one line however many realms there are.
export function chipRow(dim, options, active) {
  if (!options || options.length < 2) return "";
  const chip = (o) => '<button class="chip" type="button" data-key="' + esc(o.key) + '" aria-pressed="' +
    (o.key === active ? "true" : "false") + '">' + esc(o.label) + "</button>";
  const all = options.find((o) => o.key === "all");
  if (dim !== "realm" || !all) {
    return '<div class="chips vchips" data-dim="' + esc(dim) + '">' + options.map(chip).join("") + "</div>";
  }
  const rest = options.filter((o) => o !== all);
  const picked = rest.find((o) => o.key === active);
  const id = "vsel-" + esc(dim);
  const check = '<svg class="vsel-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
  return '<div class="chips vchips vsel-row" data-dim="' + esc(dim) + '">' + chip(all) +
    '<div class="vsel"><button class="vsel-trigger" type="button" aria-haspopup="listbox" aria-expanded="false" aria-controls="' +
    id + '" aria-pressed="' + (picked ? "true" : "false") + '"' + (picked ? "" : " data-placeholder") + '>' +
    '<span class="vsel-value">' + esc(picked ? picked.label : "Select a realm") + "</span>" +
    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>' +
    '<div class="vsel-pop" id="' + id + '" role="listbox" tabindex="-1" aria-label="Realm" hidden>' +
    '<div class="vsel-label" role="presentation">Realms</div>' +
    rest.map((o) => '<div class="vsel-item" role="option" data-key="' + esc(o.key) + '" aria-selected="' +
      (o.key === active ? "true" : "false") + '"><span>' + esc(o.label) + "</span>" + check + "</div>").join("") +
    "</div></div></div>";
}

// The neobrutalist select: a listbox popup that opens over its trigger with
// the chosen option lined up on it. Wires every server-rendered .vsel (the
// realm row) and builds one in front of each <select class="vsel-native">,
// which stays the source of truth: choosing sets its value and fires "change".
export const SELECT_SCRIPT = `<script>
(function(){
  var check='<svg class="vsel-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
  function el(tag,cls,html){var e=document.createElement(tag);e.className=cls;if(html)e.innerHTML=html;return e;}
  [].slice.call(document.querySelectorAll("select.vsel-native")).forEach(function(sel,n){
    var box=el("div","vsel");
    var t=el("button","vsel-trigger",'<span class="vsel-value"></span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>');
    var pop=el("div","vsel-pop");
    t.type="button"; t.setAttribute("aria-haspopup","listbox"); t.setAttribute("aria-expanded","false");
    pop.id="vsel-native-"+n; pop.setAttribute("role","listbox"); pop.tabIndex=-1; pop.hidden=true;
    t.setAttribute("aria-controls",pop.id);
    var label=sel.closest("label"); if(label){pop.setAttribute("aria-label",label.firstChild.textContent.trim());}
    [].slice.call(sel.options).forEach(function(o){
      var it=el("div","vsel-item",'<span></span>'+check);
      it.setAttribute("role","option"); it.dataset.key=o.value; it.firstChild.textContent=o.text;
      pop.appendChild(it);
    });
    function sync(){
      var on=sel.options[sel.selectedIndex];
      [].slice.call(pop.children).forEach(function(it){it.setAttribute("aria-selected",it.dataset.key===sel.value?"true":"false");});
      t.querySelector(".vsel-value").textContent=on?on.text:"";
      t.setAttribute("aria-pressed",sel.value?"true":"false");
    }
    pop.addEventListener("click",function(e){
      var it=e.target.closest(".vsel-item"); if(!it||sel.value===it.dataset.key) return;
      sel.value=it.dataset.key; sync(); sel.dispatchEvent(new Event("change",{bubbles:true}));
    });
    sel.addEventListener("change",sync);
    box.appendChild(t); box.appendChild(pop);
    sel.hidden=true; sel.tabIndex=-1; sel.parentNode.insertBefore(box,sel);
    if(label){label.addEventListener("click",function(e){if(e.target===label||e.target===label.firstChild){e.preventDefault();t.focus();}});}
    sync();
  });
  [].slice.call(document.querySelectorAll(".vsel")).forEach(function(box){
    var t=box.querySelector(".vsel-trigger"), pop=box.querySelector(".vsel-pop");
    var items=[].slice.call(pop.querySelectorAll(".vsel-item")), hi=-1, typed="", typedAt=0;
    function highlight(i){
      items.forEach(function(o,n){o.toggleAttribute("data-highlighted", n===i);});
      hi=i; if(i<0) return;
      var o=items[i];
      if(o.offsetTop<pop.scrollTop) pop.scrollTop=o.offsetTop;
      else if(o.offsetTop+o.offsetHeight>pop.scrollTop+pop.clientHeight) pop.scrollTop=o.offsetTop+o.offsetHeight-pop.clientHeight;
    }
    function place(){
      pop.style.top=""; pop.style.bottom=""; pop.scrollTop=0;
      var sel=pop.querySelector('[aria-selected="true"]');
      if(!sel){
        pop.style.top=(t.offsetHeight+4)+"px";
        if(box.getBoundingClientRect().top+t.offsetHeight+4+pop.offsetHeight>innerHeight-8){pop.style.top="";pop.style.bottom=(t.offsetHeight+4)+"px";}
        return;
      }
      // Line the chosen option up on the trigger; when that would push the
      // popup off the top, scroll the list instead.
      var off=sel.offsetTop-(t.offsetHeight-sel.offsetHeight)/2;
      var room=Math.max(0,t.getBoundingClientRect().top-8);
      var scroll=Math.min(Math.max(0,off-room),pop.scrollHeight-pop.clientHeight);
      pop.scrollTop=scroll; pop.style.top=-(off-scroll)+"px";
      // Layout sizes, not getBoundingClientRect: the open animation scales the popup.
      var popTop=box.getBoundingClientRect().top-(off-scroll);
      var up=Math.min(popTop+pop.offsetHeight-(innerHeight-8), popTop-8);
      if(up>0) pop.style.top=-(off-scroll+up)+"px";
    }
    function open(){
      if(!pop.hidden) return;
      pop.hidden=false; t.setAttribute("aria-expanded","true"); place();
      var s=items.findIndex(function(o){return o.getAttribute("aria-selected")==="true";});
      highlight(s<0?0:s); pop.focus({preventScroll:true});
    }
    function close(refocus){
      if(pop.hidden) return;
      pop.hidden=true; t.setAttribute("aria-expanded","false"); highlight(-1);
      if(refocus) t.focus({preventScroll:true});
    }
    function choose(i){ if(i>=0) items[i].click(); close(true); }
    t.addEventListener("click", function(){ pop.hidden?open():close(false); });
    t.addEventListener("keydown", function(e){
      if(e.key==="ArrowDown"||e.key==="ArrowUp"||e.key==="Enter"||e.key===" "){e.preventDefault();open();}
    });
    pop.addEventListener("keydown", function(e){
      var n=items.length;
      if(e.key==="ArrowDown"){e.preventDefault();highlight(Math.min(n-1,hi+1));}
      else if(e.key==="ArrowUp"){e.preventDefault();highlight(Math.max(0,hi-1));}
      else if(e.key==="Home"){e.preventDefault();highlight(0);}
      else if(e.key==="End"){e.preventDefault();highlight(n-1);}
      else if(e.key==="Enter"||e.key===" "){e.preventDefault();choose(hi);}
      else if(e.key==="Escape"){e.preventDefault();close(true);}
      else if(e.key==="Tab"){close(false);}
      else if(e.key.length===1){
        var now=Date.now(); typed=(now-typedAt>600?"":typed)+e.key.toLowerCase(); typedAt=now;
        var m=items.findIndex(function(o){return o.textContent.toLowerCase().indexOf(typed)===0;});
        if(m>=0) highlight(m);
      }
    });
    pop.addEventListener("mousemove", function(e){
      var o=e.target.closest(".vsel-item"); if(o&&items.indexOf(o)!==hi) highlight(items.indexOf(o));
    });
    pop.addEventListener("click", function(e){ if(e.target.closest(".vsel-item")) close(true); });
    document.addEventListener("pointerdown", function(e){ if(!box.contains(e.target)) close(false); });
    window.addEventListener("resize", function(){ close(false); });
  });
})();
</script>`;

export const TOGGLE_SCRIPT = `<script>
(function(){
  var rows=[].slice.call(document.querySelectorAll(".vchips[data-dim]"));
  var panes=[].slice.call(document.querySelectorAll(".vpane"));
  if(!rows.length||!panes.length) return;
  var state={};
  var defaults={};
  function slug(v){return String(v||"").trim().toLowerCase().replace(/[^a-z0-9]+/g,"-").replace(/^-|-$/g,"");}
  rows.forEach(function(r){
    var on=r.querySelector('.chip[aria-pressed="true"],.vsel-item[aria-selected="true"]')||r.querySelector("[data-key]");
    state[r.dataset.dim]=on?on.dataset.key:"";
    defaults[r.dataset.dim]=state[r.dataset.dim];
  });
  function readUrl(){
    var q=new URLSearchParams(location.search);
    rows.forEach(function(r){
      var wanted=q.get(r.dataset.dim); state[r.dataset.dim]=defaults[r.dataset.dim]; if(!wanted) return;
      var match=[].slice.call(r.querySelectorAll("[data-key]")).find(function(c){return slug(c.dataset.key)===slug(wanted);});
      if(match) state[r.dataset.dim]=match.dataset.key;
    });
  }
  function writeUrl(){
    var u=new URL(location.href);
    rows.forEach(function(r){
      var key=state[r.dataset.dim];
      if(!key||key==="all") u.searchParams.delete(r.dataset.dim);
      else u.searchParams.set(r.dataset.dim,slug(key));
    });
    history.pushState(null,"",u.pathname+(u.search?u.search:"")+u.hash);
  }
  function apply(){
    rows.forEach(function(r){
      var key=state[r.dataset.dim], picked=null;
      [].slice.call(r.querySelectorAll(".chip")).forEach(function(c){
        c.setAttribute("aria-pressed", c.dataset.key===key?"true":"false");
      });
      [].slice.call(r.querySelectorAll(".vsel-item")).forEach(function(o){
        var on=o.dataset.key===key; o.setAttribute("aria-selected", on?"true":"false"); if(on) picked=o;
      });
      var t=r.querySelector(".vsel-trigger");
      if(t){
        t.setAttribute("aria-pressed", picked?"true":"false");
        t.toggleAttribute("data-placeholder", !picked);
        t.querySelector(".vsel-value").textContent=picked?picked.textContent:"Select a realm";
      }
    });
    panes.forEach(function(p){
      var show=true;
      for(var dim in state){
        var want=state[dim], has=p.getAttribute("data-"+dim);
        if(has!==null&&has!==want) show=false;
      }
      p.hidden=!show;
    });
  }
  rows.forEach(function(r){
    r.addEventListener("click", function(e){
      var c=e.target.closest(".chip,.vsel-item"); if(!c||!r.contains(c)) return;
      state[r.dataset.dim]=c.dataset.key; apply(); writeUrl();
    });
  });
  window.addEventListener("popstate",function(){readUrl();apply();});
  readUrl(); apply();
})();
</script>${SELECT_SCRIPT}`;

// census: { groups, realms, lastT } from loadForeverCensus (or /api/forever).
// opts.stylesheet  path to style.css ("/style.css" on the Worker, "style.css"
//                  in the static bundle, which may sit under a subpath)
// opts.realmHref   game key -> per-realm page URL, or null to drop those links
// opts.back        { href, label } for the top-left crumb, or null
// opts.nav         raw markup replacing that crumb (static bundle page nav)
// opts.notes       extra lines under the header (data freshness and static build time)
function renderCensusView(census, opts = {}) {
  const groups = (census && census.groups) || [];
  const realms = (census && census.realms) || [];
  const lastT = (census && census.lastT) || 0;
  const total = groups.reduce((a, g) => a + g.characters, 0);
  const samples = groups.reduce((a, g) => a + g.samples, 0);
  const sightings = groups.reduce((a, g) => a + g.observed, 0);

  let body;
  if (!total && !samples) {
    body = '<div class="panel"><p class="hint">No ' + esc(opts.gameLabel || "WoW Forever") + ' population data is in the current snapshot yet. ' +
      "In game, open the Population tab and press Scan Population (or /ml who), then /reload and rebuild the local site snapshot.</p></div>";
  } else {
    // Race chart: every faction's races in one chart, grouped by faction and
    // scaled against the single largest race so the faction blocks compare.
    const raceMarkup = (window) => {
      const dist = (g) => g.raceWindows?.[window] || g.races;
      const max = groups.reduce((m, g) =>
        Object.values(dist(g)).reduce((mm, n) => Math.max(mm, n), m), 0);
      return groups.map((g) =>
        barChart(sortedEntries(dist(g)).filter(([, n]) => n > 0), max, () => factionColor(g.faction),
          (k) => (/^(High Order|Windshaper) Skyborne$/i.test(k) ? "Skyborne" : k).toUpperCase())).join("") ||
        '<p class="hint">No race observations in this window.</p>';
    };
    const hasRaceWindows = groups.some((g) => g.raceWindows);
    const legend = groups.map((g) =>
      '<span class="key"><i style="background:' + factionColor(g.faction) + '"></i>' + esc(g.faction) + "</span>").join("");
    const racePanel = '<div class="panel census race-panel" style="margin-bottom:22px"><div class="race-head"><div><div class="ptitle">Population by race</div>' +
      '<p class="hint" style="margin-top:0">Grouped by faction, most populous first. Windows end at each realm\'s latest scan.</p></div>' +
      (hasRaceWindows ? '<div class="race-toggle" role="group" aria-label="Population by race time range"><button type="button" data-window="all" aria-pressed="true">All</button><button type="button" data-window="week" aria-pressed="false">7 days</button><button type="button" data-window="day" aria-pressed="false">24 hours</button></div>' : '') + '</div>' +
      '<div class="legend">' + legend + "</div>" +
      (hasRaceWindows ? ['all', 'week', 'day'].map((window) => '<div class="race-bars" data-window="' + window + '"' + (window === 'all' ? '' : ' hidden') + '>' + raceMarkup(window) + '</div>').join('') : raceMarkup('all')) + "</div>";

    // One class chart per faction, each scaled to its own leader.
    const classPanels = groups.map((g) => {
      const entries = sortedEntries(g.classes);
      const max = entries.length ? entries[0][1] : 0;
      return '<div class="panel census"><div class="ptitle">Class distribution &mdash; ' + esc(g.faction) + "</div>" +
        '<p class="hint" style="margin-top:0">' + g.characters.toLocaleString() + " characters shown</p>" +
        (entries.length
          ? barChart(entries, max,
              (k) => "#" + (CLASS_META[k] ? CLASS_META[k][1] : "8b90a0"),
              (k) => (CLASS_META[k] ? CLASS_META[k][0] : k).toUpperCase())
          : '<p class="hint">No class data yet.</p>') + "</div>";
    }).join("");

    const links = opts.realmHref
      ? groups.map((g) => g.games.map((game) =>
          '<a class="game" href="' + esc(opts.realmHref(game)) + '">' +
          esc(realmName(game)) + "</a>").join("")).join("")
      : "";
    body = comboCards(groups, opts) + racePanel + '<div class="chartgrid">' + classPanels + "</div>" +
      (links ? '<div class="ptitle" style="margin:26px 0 12px">Per-realm detail</div><nav class="games">' + links + "</nav>" : "");
  }

  const tiles =
    '<div class="tile"><div class="k">Characters</div><div class="v">' + total.toLocaleString() +
      '</div><div class="s">unique, all time</div></div>' +
    '<div class="tile"><div class="k">Realms</div><div class="v">' + (realms.length || "&mdash;") +
      "</div></div>" +
    '<div class="tile"><div class="k">Scans</div><div class="v">' + samples.toLocaleString() +
      '</div><div class="s">' + sightings.toLocaleString() + " sightings</div></div>" +
    '<div class="tile"><div class="k">Updated</div><div class="v">' +
      (lastT ? new Date(lastT * 1000).toISOString().slice(0, 10) : "&mdash;") +
      '</div><div class="s">last sample</div></div>';

  return '<section class="tiles">' + tiles + "</section>" + body;
}

export function renderComboBreakdownHtml(census, opts = {}) {
  const groups = (census && census.groups) || [];
  const navigation = opts.nav && typeof opts.nav === "object" ? opts.nav : { games: opts.nav || "", pages: "" };
  const gameLabel = opts.gameLabel || "WoW Forever";
  const heading = navigation.games ? "WoWCensus" : gameLabel + " — Race + Class";
  const seoGameLabel = seoLabel(gameLabel);
  const topic = seoGameLabel + " Race & Class Breakdown";
  const notes = (opts.notes || []).map((note, i) =>
    '<div class="itag" style="' + (i ? "margin-top:4px;" : "margin-top:10px;") + 'line-height:1.6">' + esc(note) + "</div>").join("");
  const generatedNote = opts.generatedNote
    ? '<div class="itag" style="margin-top:16px;line-height:1.6">' + esc(opts.generatedNote) + "</div>"
    : "";
  return `<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="google-site-verification" content="N0-ZNJyDo16jefYUGxfcAda_mKf7S2oATfRGWETdsHs">
${seoHead(topic + " – WoWCensus", seoGameLabel + " observed race and class combinations from sampled in-game /who results.", opts.canonical)}
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96">
<link rel="icon" href="/favicon-96.png" type="image/png" sizes="96x96">
<link rel="preload" href="/fonts/press-start-2p-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/vt323-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/cinzel-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/friz-quadrata-regular.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="${esc(opts.stylesheet || "style.css")}"><style>${CENSUS_STYLE}</style>
</head><body><div class="crt" aria-hidden="true"></div><div class="wrap">
<header class="ihead"><div class="page-heading">${pageHeading(heading, navigation, topic)}${navigation.games || ""}</div>${navigation.breadcrumbs || notes}</header>
<div class="page-controls">${navigation.pages || ""}</div>
<section class="panel" style="margin-bottom:22px"><div class="ptitle">What this sample shows</div><p class="hint combo-analysis" style="margin:0">${esc(comboAnalysis(groups, opts.flavorNote))}</p></section>
<div class="combo-breakdown">${comboBreakdown(groups)}</div>${generatedNote}
</div></body></html>`;
}

// views: [{ key, label, census }] -- one entry per realm scope, plus "All".
// A single-entry list renders without chips, which is what the Worker page uses.
export function renderCensusHtml(views, opts = {}) {
  const list = (views || []).filter(Boolean);
  const active = list.length ? list[0].key : "";
  const chips = chipRow("realm", list.map((v) => ({ key: v.key, label: v.label })), active);
  const panes = list.map((v) =>
    '<div class="vpane" data-realm="' + esc(v.key) + '"' + (v.key === active ? "" : " hidden") + ">" +
    renderCensusView(v.census, opts) + "</div>").join("");

  const back = opts.nav || (opts.back
    ? '<a class="back" href="' + esc(opts.back.href) + '">&#9664; ' + esc(opts.back.label) + "</a>"
    : "");
  const navigation = back && typeof back === "object" ? back : { games: back, pages: "" };
  const notes = (opts.notes || (opts.note ? [opts.note] : [])).map((note, i) =>
    '<div class="itag" style="' + (i ? "margin-top:4px;" : "margin-top:10px;") + 'line-height:1.6">' + esc(note) + "</div>").join("");
  const generatedNote = opts.generatedNote
    ? '<div class="itag" style="margin-top:16px;line-height:1.6">' + esc(opts.generatedNote) + "</div>"
    : "";

  const gameLabel = opts.gameLabel || "WoW Forever";
  const heading = navigation.games ? "WoWCensus" : (gameLabel === "WoW Forever" ? "WoW Forever Beta" : gameLabel + " — Census");
  const seoGameLabel = seoLabel(gameLabel);
  const topic = seoGameLabel + " Population Census";
  const description = opts.description || seoGameLabel + " realm population, faction balance, race and class distribution from sampled in-game /who results.";
  return `<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="google-site-verification" content="N0-ZNJyDo16jefYUGxfcAda_mKf7S2oATfRGWETdsHs">
${seoHead(opts.title || topic + " – WoWCensus", description, opts.canonical)}
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96">
<link rel="icon" href="/favicon-96.png" type="image/png" sizes="96x96">
<link rel="preload" href="/fonts/press-start-2p-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/vt323-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/cinzel-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="${esc(opts.stylesheet || "/style.css")}">
<style>${CENSUS_STYLE}
</style>
</head><body${opts.mobileHome ? ' class="census-home"' : ''}>
<div class="crt" aria-hidden="true"></div>
<div class="wrap">
  <header class="ihead">
    <div class="page-heading">${pageHeading(heading, navigation, topic)}${navigation.games || ""}</div>
    ${navigation.breadcrumbs || notes}
  </header>
  <div class="page-controls">${navigation.pages || ""}${chips}</div>
  ${panes}
  ${opts.inspectCoverage || ""}
  ${generatedNote}
</div>
${chips ? TOGGLE_SCRIPT : ""}
<script>(function(){
  var toggles=[].slice.call(document.querySelectorAll('.race-toggle'));
  function apply(window){toggles.forEach(function(toggle){var panel=toggle.closest('.race-panel');toggle.querySelectorAll('button').forEach(function(b){b.setAttribute('aria-pressed',String(b.dataset.window===window))});panel.querySelectorAll('.race-bars').forEach(function(bars){bars.hidden=bars.dataset.window!==window})})}
  function selected(){var value=new URLSearchParams(location.search).get('race');return value==='week'||value==='day'?value:'all'}
  toggles.forEach(function(toggle){toggle.addEventListener('click',function(event){var button=event.target.closest('button[data-window]');if(!button)return;apply(button.dataset.window);var url=new URL(location.href);if(button.dataset.window==='all')url.searchParams.delete('race');else url.searchParams.set('race',button.dataset.window);history.pushState(null,'',url.pathname+url.search+url.hash)})});
  window.addEventListener('popstate',function(){apply(selected())});apply(selected());
})();</script>
</body></html>`;
}
