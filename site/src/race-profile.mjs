import fs from "node:fs";
import vm from "node:vm";
import { fileURLToPath } from "node:url";
import { parseHTML } from "linkedom";
import { CLASS_META } from "./census.mjs";
import { SPEC_COLORS } from "./class-profile.mjs";
import { PROFILE_GAMES, GAME_NAV, pageNav, talentsPath, MIN_SAMPLE, rootPrefix } from "./profile-games.mjs";

const template = fileURLToPath(new URL("./race-template.html", import.meta.url));
// Races with their own profile page, per game, keyed by the race name in the
// inspect records; `label` and `slug` override the displayed name and route.
const Human = { plural: "Humans", faction: "Alliance" }, Dwarf = { plural: "Dwarves", faction: "Alliance" },
  Gnome = { plural: "Gnomes", faction: "Alliance" }, NightElf = { plural: "Night Elves", icon: "achievement_character_nightelf_male", faction: "Alliance" },
  Orc = { plural: "Orcs", faction: "Horde" }, Undead = { plural: "Undead", faction: "Horde" },
  Tauren = { plural: "Tauren", faction: "Horde" }, Troll = { plural: "Trolls", faction: "Horde" };
const RACES = {
  "classic-beta": { Human, Dwarf, Gnome,
    "High Order Skyborne": { label: "Alliance Skyborne", slug: "skyborne", plural: "Skyborne", icon: "achievement_character_bloodelf_male", faction: "Alliance" },
    "Night Elf": NightElf, Orc, Undead, Tauren,
    "Windshaper Skyborne": { label: "Horde Skyborne", plural: "Skyborne", icon: "achievement_character_bloodelf_male", faction: "Horde" },
    Troll },
  "classic-progression": { Human, Dwarf, Gnome, "Night Elf": NightElf,
    Draenei: { plural: "Draenei", faction: "Alliance" },
    Orc, Undead, Tauren, Troll,
    "Blood Elf": { plural: "Blood Elves", faction: "Horde" } },
};
export const RACE_PROFILES = Object.fromEntries(Object.entries(RACES).map(([source, races]) => [source, Object.fromEntries(
  Object.entries(races).map(([race, p]) => [race, { ...p, path: `/${PROFILE_GAMES[source].dir}/${p.slug || (p.label || race).toLowerCase().replace(/\s+/g, "-")}/` }]))]));
export const raceRoutes = () => Object.values(RACE_PROFILES).flatMap((races) => Object.values(races).map((p) => p.path));
const med = (arr) => { const a=arr.filter(Number.isFinite).sort((x,y)=>x-y); return a.length?a[Math.floor((a.length-1)/2)]:null; };
const pct = (a,b) => b?Math.round(100*a/b):0;
export function raceProfile(inspects, race) {
  const rows=(inspects.records||[]).filter(r=>r.race===race&&r.talents?.length), classes={};
  for(const cf of [...new Set(rows.map(r=>r.classFile))]){
    const members=rows.filter(r=>r.classFile===cf), spec={}, tm=new Map(), tree=new Map();
    for(const r of members){
      const name=r.classification||"Unknown build"; spec[name]=(spec[name]||0)+1;
      for(const p of r.orderedPoints||[])tree.set(p.tree,(tree.get(p.tree)||0)+p.points);
      for(const t of new Map(r.talents.map(t=>[t.node,t])).values()){
        const key=String(t.node),v=tm.get(key)||{name:t.name,n:0,ranks:0,max:t.max,icon:t.icon||"inv_misc_questionmark",tree:t.branch||"Unmapped"};
        v.n++;v.ranks+=t.rank||0;tm.set(key,v);
      }
    }
    classes[cf]={n:members.length,spec,
      tal:[...tm.values()].sort((a,b)=>b.n-a.n||a.name.localeCompare(b.name)).slice(0,6).map(t=>({name:t.name,pct:pct(t.n,members.length),avgRank:+(t.ranks/t.n).toFixed(1),max:t.max,icon:t.icon,tree:t.tree})),
      trees:Object.fromEntries([...tree].sort((a,b)=>b[1]-a[1]).map(([k,n])=>[k,+(n/members.length).toFixed(1)])),
      medLv:med(members.map(r=>r.level))};
  }
  return {n:rows.length,classes,medLv:med(rows.map(r=>r.level)),lastT:Math.max(0,...rows.map(r=>r.time||0)),windowDays:inspects.windowDays,truncated:inspects.truncated};
}

export function renderRaceProfile(inspects,game,race,opts){
  const G=PROFILE_GAMES[game],PROFILES=RACE_PROFILES[game],D=raceProfile(inspects,race),{plural,path,label=race,icon}=PROFILES[race],prefix=rootPrefix(path);
  let source=fs.readFileSync(template,"utf8").replaceAll("{{GAME}}",G.gameLabel).replaceAll("{{RACES}}",plural).replaceAll("{{RACE_SLUG}}",label.toLowerCase().replace(/\s+/g,"")).replaceAll("{{RACE}}",label).replaceAll("../static/fonts/",prefix+"fonts/").replaceAll("../static/assets/",prefix+"assets/")
    .replace('const ICON = n => "https://wow.zamimg.com/images/wow/icons/large/" + n + ".jpg";','const ICON = n => "'+prefix+'assets/images/wow/icons/large/" + n + ".jpg";')
    .replace("const SPECCOLOR = {};", "const SPECCOLOR = "+JSON.stringify(SPEC_COLORS)+";")
    .replace("const MIN_SAMPLE = 0;", "const MIN_SAMPLE = "+MIN_SAMPLE+";")
    .replace(/const D = \{.*\};/,"const D = "+JSON.stringify({n:D.n,classes:D.classes})+";");
  const script=source.match(/<script>([\s\S]*?)<\/script>/)?.[1];
  if(!script)throw new Error("Race template script missing");
  const {document}=parseHTML(source);
  if(icon)document.querySelector(".portrait").src=prefix+"assets/images/wow/icons/large/"+icon+".jpg";
  vm.runInNewContext(script,{document,URL,location:"https://wowcensus.io"+path,history:{pushState(){}},addEventListener(){}},{timeout:5000});
  document.querySelector("script").remove();
  document.title=G.gameLabel+" "+label+" Specs & Talents – WoWCensus";
  document.head.insertAdjacentHTML("beforeend",'<meta name="description" content="'+label+' class builds and selected talents observed in '+G.gameLabel+' nearby-player inspections."><link rel="canonical" href="'+opts.canonical+'"><meta property="og:type" content="website"><meta property="og:site_name" content="WoWCensus"><meta property="og:title" content="'+G.gameLabel+' '+label+' Specs &amp; Talents – WoWCensus"><meta property="og:url" content="'+opts.canonical+'"><link rel="icon" href="'+prefix+'favicon.ico">');
  document.head.querySelector("style").insertAdjacentHTML("beforebegin",'<link rel="stylesheet" href="'+prefix+opts.stylesheet+'">');
  // Navigation comes from the game config, not the template's placeholder links.
  const links=(nav,items,current)=>{nav.innerHTML="";for(const [text,href] of items){const a=document.createElement("a");a.className="game";a.href=href;a.textContent=text;if(text===current)a.setAttribute("aria-current","true");nav.appendChild(a);}};
  links(document.querySelector(".game-nav"),GAME_NAV,G.nav);
  links(document.querySelector(".page-nav"),pageNav(game),"Talents");
  const crumbs=document.querySelectorAll(".breadcrumbs a");
  crumbs[0].href="/";crumbs[1].href=G.home;crumbs[1].textContent=G.nav;crumbs[2].href=talentsPath(game);
  const chipRow=document.querySelector(".race-filter .chips");
  chipRow.innerHTML="";
  for(const [key,p] of Object.entries(PROFILES)){const a=document.createElement("a");a.className="chip";a.href=p.path;a.textContent=p.label||key;chipRow.appendChild(a);}
  const currentFaction=PROFILES[race].faction;
  const factionSelect=document.querySelector(".faction-picker select");
  for(const option of factionSelect.options)if(option.value===currentFaction)option.setAttribute("selected","");else option.removeAttribute("selected");
  for(const a of document.querySelectorAll(".race-filter .chips a")){const name=a.textContent.trim(),profile=Object.entries(PROFILES).find(([key,value])=>(value.label||key)===name);if(profile){a.href=profile[1].path;a.dataset.faction=profile[1].faction;if(profile[1].faction!==currentFaction)a.hidden=true;if(profile[0]===race)a.setAttribute("aria-current","page");}else{const span=document.createElement("span");span.className="chip";span.textContent=name;a.replaceWith(span);}}
  const order=Object.keys(D.classes).sort((a,b)=>D.classes[b].n-D.classes[a].n),top=order[0],topN=D.classes[top]?.n||0;
  const specs=order.flatMap(cf=>Object.entries(D.classes[cf].spec).filter(([s])=>s!=="Unknown build"&&!s.includes("/")).map(([s,n])=>({cf,s,n}))).sort((a,b)=>b.n-a.n);
  const tank=specs.filter(x=>x.s==="Protection").sort((a,b)=>b.n-a.n),first=specs[0];
  const hero=document.querySelector(".hero");
  const intro=hero.querySelector("p");
  intro.textContent="";
  if(D.n){
    const topClass=document.createElement("strong");topClass.textContent=CLASS_META[top]?.[0]||top;
    intro.appendChild(document.createTextNode("Among "+plural+" we've inspected, "));
    intro.appendChild(topClass);
    intro.appendChild(document.createTextNode(" is the most common class"));
    if(first){const topBuild=document.createElement("strong");topBuild.textContent=first.s;intro.appendChild(document.createTextNode(", with "));intro.appendChild(topBuild);intro.appendChild(document.createTextNode(" leading among identified builds."));}
    else intro.appendChild(document.createTextNode(". No identified builds are available in this sample."));
  }else intro.textContent="No "+label+" inspections with readable talents are available in this snapshot.";
  hero.querySelector(".fresh").innerHTML='<span class="tag">Latest inspection '+(D.lastT?new Date(D.lastT*1000).toISOString().slice(0,16).replace("T"," ")+"Z":"unavailable")+'</span><span class="tag ghost">Last '+D.windowDays+' days · all realms</span>';
  hero.querySelector(".bignum b").textContent=D.n.toLocaleString("en-US");
  hero.querySelector(".bignum small").textContent=plural+" with talents";
  const tiles=document.querySelectorAll(".tile");
  if(top){tiles[0].style.setProperty("--c","#"+(CLASS_META[top]?.[1]||"ffce43"));tiles[0].querySelector(".v").textContent=CLASS_META[top]?.[0]||top;tiles[0].querySelector(".s").textContent=topN+" of "+D.n+" · "+pct(topN,D.n)+"%";}
  if(first){tiles[1].style.setProperty("--c",SPEC_COLORS[first.s+":"+first.cf]||"#"+(CLASS_META[first.cf]?.[1]||"ffce43"));tiles[1].querySelector(".v").textContent=first.s;tiles[1].querySelector(".s").textContent=first.n+" players · "+pct(first.n,D.n)+"%";}
  tiles[2].querySelector(".v").textContent=tank[0]?"Prot "+(CLASS_META[tank[0].cf]?.[0]||tank[0].cf):"None classified";
  tiles[2].querySelector(".s").textContent=tank[0]?tank[0].n+" inspected"+(tank[1]?" · ahead of "+tank[1].n+" "+(CLASS_META[tank[1].cf]?.[0]||tank[1].cf):""):"No Protection build observed";
  tiles[3].querySelector(".v").textContent=D.medLv??"—";
  document.body.insertAdjacentHTML("beforeend",`<script>(()=>{const chips=document.getElementById('classchips'),cards=[...document.querySelectorAll('.card')];function apply(s){const v=cards.some(c=>c.dataset.class===s)?s:'';chips.querySelectorAll('button').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.c===v)));cards.forEach(c=>c.hidden=!!v&&c.dataset.class!==v)}chips.addEventListener('click',e=>{const b=e.target.closest('button[data-c]');if(!b)return;const u=new URL(location.href);b.dataset.c?u.searchParams.set('class',b.dataset.c):u.searchParams.delete('class');history.pushState(null,'',u.pathname+u.search+u.hash);apply(b.dataset.c)});addEventListener('popstate',()=>apply(new URL(location.href).searchParams.get('class')||''));apply(new URL(location.href).searchParams.get('class')||'')})();(()=>{const select=document.querySelector('.faction-picker select'),links=[...document.querySelectorAll('.race-filter .chips a')];select.addEventListener('change',()=>links.forEach(link=>link.hidden=link.dataset.faction!==select.value))})();</script>`);
  // linkedom keeps the template's own doctype.
  return document.toString();
}
