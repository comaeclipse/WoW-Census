import fs from "node:fs";
import vm from "node:vm";
import { fileURLToPath } from "node:url";
import { parseHTML } from "linkedom";
import { CLASS_META } from "./census.mjs";
import { SPEC_COLORS } from "./class-profile.mjs";

const template = fileURLToPath(new URL("./race-template.html", import.meta.url));
// Forever races with their own profile route, linked from the Talents page.
export const RACE_PROFILES = {
  Human: { plural: "Humans", path: "/forever/human/", faction: "Alliance" },
  Dwarf: { plural: "Dwarves", path: "/forever/dwarf/", faction: "Alliance" },
  Gnome: { plural: "Gnomes", path: "/forever/gnome/", faction: "Alliance" },
  "High Order Skyborne": { label: "Alliance Skyborne", plural: "Skyborne", path: "/forever/skyborne/", icon: "achievement_character_bloodelf_male", faction: "Alliance" },
  "Night Elf": { plural: "Night Elves", path: "/forever/night-elf/", icon: "achievement_character_nightelf_male", faction: "Alliance" },
  Orc: { plural: "Orcs", path: "/forever/orc/", faction: "Horde" },
  Undead: { plural: "Undead", path: "/forever/undead/", faction: "Horde" },
  Tauren: { plural: "Tauren", path: "/forever/tauren/", faction: "Horde" },
  "Windshaper Skyborne": { label: "Horde Skyborne", plural: "Skyborne", path: "/forever/horde-skyborne/", icon: "achievement_character_bloodelf_male", faction: "Horde" },
  Troll: { plural: "Trolls", path: "/forever/troll/", faction: "Horde" },
};
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

export function renderRaceProfile(inspects,race,opts){
  const D=raceProfile(inspects,race),{plural,path,label=race,icon}=RACE_PROFILES[race];
  let source=fs.readFileSync(template,"utf8").replaceAll("{{RACES}}",plural).replaceAll("{{RACE_SLUG}}",label.toLowerCase().replace(/\s+/g,"")).replaceAll("{{RACE}}",label).replaceAll("../static/fonts/","../../fonts/").replaceAll("../static/assets/","../../assets/")
    .replace('const ICON = n => "https://wow.zamimg.com/images/wow/icons/large/" + n + ".jpg";','const ICON = n => "../../assets/images/wow/icons/large/" + n + ".jpg";')
    .replace("const SPECCOLOR = {};", "const SPECCOLOR = "+JSON.stringify(SPEC_COLORS)+";")
    .replace(/const D = \{.*\};/,"const D = "+JSON.stringify({n:D.n,classes:D.classes})+";");
  const script=source.match(/<script>([\s\S]*?)<\/script>/)?.[1];
  if(!script)throw new Error("Race template script missing");
  const {document}=parseHTML(source);
  if(icon)document.querySelector(".portrait").src="../../assets/images/wow/icons/large/"+icon+".jpg";
  vm.runInNewContext(script,{document,URL,location:"https://wowcensus.io"+path,history:{pushState(){}},addEventListener(){}},{timeout:5000});
  document.querySelector("script").remove();
  document.title="WoW Forever "+label+" Specs & Talents – WoWCensus";
  document.head.insertAdjacentHTML("beforeend",'<meta name="description" content="'+label+' class builds and selected talents observed in WoW Forever nearby-player inspections."><link rel="canonical" href="'+opts.canonical+'"><meta property="og:type" content="website"><meta property="og:site_name" content="WoWCensus"><meta property="og:title" content="WoW Forever '+label+' Specs &amp; Talents – WoWCensus"><meta property="og:url" content="'+opts.canonical+'"><link rel="icon" href="../../favicon.ico">');
  document.head.querySelector("style").insertAdjacentHTML("beforebegin",'<link rel="stylesheet" href="../../'+opts.stylesheet+'">');
  for(const [i,url] of ["/","/tbc/","/classic/","/sod/","/mop/","/retail/"].entries())document.querySelector(".game-nav").children[i].href=url;
  for(const [i,url] of ["/","/","/talents"].entries())document.querySelectorAll(".breadcrumbs a")[i].href=url;
  for(const [i,url] of ["/","/auctionhouse","/guilds","/geography","/talents","/gear"].entries())document.querySelector(".page-nav").children[i].href=url;
  const currentFaction=RACE_PROFILES[race].faction;
  const factionSelect=document.querySelector(".faction-picker select");
  for(const option of factionSelect.options)if(option.value===currentFaction)option.setAttribute("selected","");else option.removeAttribute("selected");
  for(const a of document.querySelectorAll(".race-filter .chips a")){const name=a.textContent.trim(),profile=Object.entries(RACE_PROFILES).find(([key,value])=>(value.label||key)===name);if(profile){a.href=profile[1].path;a.dataset.faction=profile[1].faction;if(profile[1].faction!==currentFaction)a.hidden=true;if(profile[0]===race)a.setAttribute("aria-current","page");}else{const span=document.createElement("span");span.className="chip";span.textContent=name;a.replaceWith(span);}}
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
  return "<!doctype html>"+document.toString();
}
