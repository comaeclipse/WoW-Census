import fs from "node:fs";
import vm from "node:vm";
import { fileURLToPath } from "node:url";
import { parseHTML } from "linkedom";
import { CLASS_META, RACE_ICON } from "./census.mjs";
import { SPEC_COLORS, SPEC_ICONS } from "./class-profile.mjs";
import { PROFILE_GAMES, gameNav, pageNav, talentsPath, MIN_SAMPLE, rootPrefix } from "./profile-games.mjs";

const template = fileURLToPath(new URL("./race-template.html", import.meta.url));
// Races with their own profile page, per game, keyed by the race name in the
// inspect records; `label` and `slug` override the displayed name and route.
// Races playable on both sides use faction "Both" and list under either.
const Human = { plural: "Humans", faction: "Alliance" }, Dwarf = { plural: "Dwarves", faction: "Alliance" },
  Gnome = { plural: "Gnomes", faction: "Alliance" }, NightElf = { plural: "Night Elves", icon: "achievement_character_nightelf_male", faction: "Alliance" },
  Orc = { plural: "Orcs", faction: "Horde" }, Undead = { plural: "Undead", faction: "Horde" },
  Tauren = { plural: "Tauren", faction: "Horde" }, Troll = { plural: "Trolls", faction: "Horde" },
  Draenei = { plural: "Draenei", faction: "Alliance" }, BloodElf = { plural: "Blood Elves", faction: "Horde" },
  Worgen = { plural: "Worgen", faction: "Alliance" }, Goblin = { plural: "Goblins", faction: "Horde" },
  Pandaren = { plural: "Pandaren", faction: "Both" };
const RACES = {
  "classic-beta": { Human, Dwarf, Gnome,
    "High Order Skyborne": { label: "Alliance Skyborne", slug: "skyborne", plural: "Skyborne", icon: "achievement_character_bloodelf_male", faction: "Alliance" },
    "Night Elf": NightElf, Orc, Undead, Tauren,
    "Windshaper Skyborne": { label: "Horde Skyborne", plural: "Skyborne", icon: "achievement_character_bloodelf_male", faction: "Horde" },
    Troll },
  "classic-progression": { Human, Dwarf, Gnome, "Night Elf": NightElf, Draenei, Orc, Undead, Tauren, Troll, "Blood Elf": BloodElf },
  classic: { Human, Dwarf, Gnome, "Night Elf": NightElf, Orc, Undead, Tauren, Troll },
  sod: { Human, Dwarf, Gnome, "Night Elf": NightElf, Orc, Undead, Tauren, Troll },
  hardcore: { Human, Dwarf, Gnome, "Night Elf": NightElf, Orc, Undead, Tauren, Troll },
  "mop-classic": { Human, Dwarf, Gnome, "Night Elf": NightElf, Draenei, Worgen,
    Pandaren, Orc, Undead, Tauren, Troll, "Blood Elf": BloodElf, Goblin },
  retail: { Human, Dwarf, Gnome, "Night Elf": NightElf, Draenei, Worgen,
    "Void Elf": { plural: "Void Elves", faction: "Alliance" }, "Lightforged Draenei": { plural: "Lightforged Draenei", faction: "Alliance" },
    "Dark Iron Dwarf": { plural: "Dark Iron Dwarves", faction: "Alliance" }, "Kul Tiran": { plural: "Kul Tirans", faction: "Alliance" },
    Mechagnome: { plural: "Mechagnomes", faction: "Alliance" },
    Pandaren, Dracthyr: { plural: "Dracthyr", faction: "Both" }, Earthen: { plural: "Earthen", faction: "Both" },
    Haranir: { plural: "Haranir", faction: "Both" },
    Orc, Undead, Tauren, Troll, "Blood Elf": BloodElf, Goblin,
    Nightborne: { plural: "Nightborne", faction: "Horde" }, "Highmountain Tauren": { plural: "Highmountain Tauren", faction: "Horde" },
    "Mag'har Orc": { slug: "maghar-orc", plural: "Mag'har Orcs", faction: "Horde" },
    "Zandalari Troll": { plural: "Zandalari Trolls", faction: "Horde" }, Vulpera: { plural: "Vulpera", faction: "Horde" } },
};
export const RACE_PROFILES = Object.fromEntries(Object.entries(RACES).map(([source, races]) => [source, Object.fromEntries(
  Object.entries(races).map(([race, p]) => [race, { ...p, path: `/${PROFILE_GAMES[source].dir}/${p.slug || (p.label || race).toLowerCase().replace(/\s+/g, "-")}/` }]))]));
export const raceRoutes = () => Object.values(RACE_PROFILES).flatMap((races) => Object.values(races).map((p) => p.path));
const med = (arr) => { const a=arr.filter(Number.isFinite).sort((x,y)=>x-y); return a.length?a[Math.floor((a.length-1)/2)]:null; };
const pct = (a,b) => b?Math.round(100*a/b):0;
// Modern games (retail, MoP Classic) classify a build by its spec, not by
// points spent per tree, so those profiles have no per-tree averages. Their
// records carry no tree names either: a talent picked by one spec is labelled
// with it, one picked across specs (class and hero talents) is "Shared".
const MODERN = new Set(["retail", "mop-classic"]);
const TANK_SPECS = new Set(["Protection", "Blood", "Guardian", "Brewmaster", "Vengeance"]);
export function raceProfile(inspects, race, source) {
  const modern=MODERN.has(source);
  const rows=(inspects.records||[]).filter(r=>r.race===race&&(modern?r.classification&&r.classification!=="Unknown build":r.talents?.length)), classes={};
  for(const cf of [...new Set(rows.map(r=>r.classFile))]){
    const members=rows.filter(r=>r.classFile===cf), spec={}, tm=new Map(), tree=new Map();
    for(const r of members){
      const name=r.classification||"Unknown build"; spec[name]=(spec[name]||0)+1;
      if(!modern)for(const p of r.orderedPoints||[])tree.set(p.tree,(tree.get(p.tree)||0)+p.points);
      for(const t of new Map((r.talents||[]).map(t=>[t.node,t])).values()){
        // Hero-tree choice nodes carry no name; they pick a hero tree, not a talent.
        if(modern&&/^node \d+$/.test(t.name))continue;
        const key=String(t.node),v=tm.get(key)||{name:t.name,n:0,ranks:0,max:t.max,icon:t.icon||"inv_misc_questionmark",tree:t.branch||"Unmapped",specs:{}};
        v.n++;v.ranks+=t.rank||0;if(modern)v.specs[name]=(v.specs[name]||0)+1;tm.set(key,v);
      }
    }
    classes[cf]={n:members.length,spec,
      tal:[...tm.values()].sort((a,b)=>b.n-a.n||a.name.localeCompare(b.name)).slice(0,6).map(t=>({name:t.name,pct:pct(t.n,members.length),avgRank:+(t.ranks/t.n).toFixed(1),max:t.max,icon:t.icon,tree:modern?(Object.keys(t.specs).length>1?"Shared":Object.keys(t.specs)[0]):t.tree})),
      trees:Object.fromEntries([...tree].sort((a,b)=>b[1]-a[1]).map(([k,n])=>[k,+(n/members.length).toFixed(1)])),
      medLv:med(members.map(r=>r.level))};
  }
  return {n:rows.length,classes,medLv:med(rows.map(r=>r.level)),lastT:Math.max(0,...rows.map(r=>r.time||0)),windowDays:inspects.windowDays,truncated:inspects.truncated};
}

export function renderRaceProfile(inspects,game,race,opts){
  const G=PROFILE_GAMES[game],PROFILES=RACE_PROFILES[game],modern=MODERN.has(game),D=raceProfile(inspects,race,game),{plural,path,label=race,icon=RACE_ICON[race]}=PROFILES[race],prefix=rootPrefix(path);
  let source=fs.readFileSync(template,"utf8").replaceAll("{{GAME}}",G.gameLabel).replaceAll("{{RACES}}",plural).replaceAll("{{RACE_SLUG}}",label.toLowerCase().replace(/\s+/g,"")).replaceAll("{{RACE}}",label).replaceAll("../static/fonts/",prefix+"fonts/").replaceAll("../static/assets/",prefix+"assets/")
    .replace('const ICON = n => "https://wow.zamimg.com/images/wow/icons/large/" + n + ".jpg";','const ICON = n => "'+prefix+'assets/images/wow/icons/large/" + n + ".jpg";')
    .replace(/const CLASS = \{.*\};/,"const CLASS = "+JSON.stringify(Object.fromEntries(Object.entries(CLASS_META).map(([cf,[n,c]])=>[cf,[n,"#"+c]])))+";")
    .replace(/const SPECICON = \{[\s\S]*?\};/,"const SPECICON = "+JSON.stringify(SPEC_ICONS)+";")
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
  links(document.querySelector(".game-nav"),gameNav(g=>RACE_PROFILES[g]?.[race]?.path),G.nav);
  links(document.querySelector(".page-nav"),pageNav(game),"Talents");
  const crumbs=document.querySelectorAll(".breadcrumbs a");
  crumbs[0].href="/";crumbs[1].href=G.home;crumbs[1].textContent=G.nav;crumbs[2].href=talentsPath(game);
  const chipRow=document.querySelector(".race-filter .chips");
  chipRow.innerHTML="";
  for(const [key,p] of Object.entries(PROFILES)){const a=document.createElement("a");a.className="chip";a.href=p.path;a.textContent=p.label||key;chipRow.appendChild(a);}
  const currentFaction=PROFILES[race].faction==="Both"?"Alliance":PROFILES[race].faction;
  const factionSelect=document.querySelector(".faction-picker select");
  for(const option of factionSelect.options)if(option.value===currentFaction)option.setAttribute("selected","");else option.removeAttribute("selected");
  for(const a of document.querySelectorAll(".race-filter .chips a")){const name=a.textContent.trim(),profile=Object.entries(PROFILES).find(([key,value])=>(value.label||key)===name);if(profile){a.href=profile[1].path;a.dataset.faction=profile[1].faction;if(profile[1].faction!=="Both"&&profile[1].faction!==currentFaction)a.hidden=true;if(profile[0]===race)a.setAttribute("aria-current","page");}else{const span=document.createElement("span");span.className="chip";span.textContent=name;a.replaceWith(span);}}
  const order=Object.keys(D.classes).sort((a,b)=>D.classes[b].n-D.classes[a].n),top=order[0],topN=D.classes[top]?.n||0;
  const specs=order.flatMap(cf=>Object.entries(D.classes[cf].spec).filter(([s])=>s!=="Unknown build"&&!s.includes("/")).map(([s,n])=>({cf,s,n}))).sort((a,b)=>b.n-a.n);
  const tank=specs.filter(x=>modern?TANK_SPECS.has(x.s):x.s==="Protection").sort((a,b)=>b.n-a.n),first=specs[0];
  const tankName=x=>(x.s==="Protection"?"Prot ":x.s+" ")+(CLASS_META[x.cf]?.[0]||x.cf);
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
  hero.querySelector(".bignum b").textContent=D.n.toLocaleString("en-US");
  hero.querySelector(".bignum small").textContent=plural+(modern?" with a known spec":" with talents");
  // Spec-classified games have no split builds to list.
  if(modern)document.getElementById("hybrids").closest(".panel").remove();
  const tiles=document.querySelectorAll(".tile");
  if(top){tiles[0].style.setProperty("--c","#"+(CLASS_META[top]?.[1]||"ffce43"));tiles[0].querySelector(".v").textContent=CLASS_META[top]?.[0]||top;tiles[0].querySelector(".s").textContent=topN+" of "+D.n+" · "+pct(topN,D.n)+"%";}
  if(first){tiles[1].style.setProperty("--c",SPEC_COLORS[first.s+":"+first.cf]||"#"+(CLASS_META[first.cf]?.[1]||"ffce43"));tiles[1].querySelector(".v").textContent=first.s;tiles[1].querySelector(".s").textContent=first.n+" players · "+pct(first.n,D.n)+"%";}
  tiles[2].querySelector(".v").textContent=tank[0]?tankName(tank[0]):"None classified";
  tiles[2].querySelector(".s").textContent=tank[0]?tank[0].n+" inspected"+(tank[1]?" · ahead of "+tank[1].n+" "+(modern?tankName(tank[1]):CLASS_META[tank[1].cf]?.[0]||tank[1].cf):""):modern?"No tank spec observed":"No Protection build observed";
  tiles[3].querySelector(".v").textContent=D.medLv??"—";
  document.body.insertAdjacentHTML("beforeend",`<script>(()=>{const chips=document.getElementById('classchips'),cards=[...document.querySelectorAll('.card')];function apply(s){const v=cards.some(c=>c.dataset.class===s)?s:'';chips.querySelectorAll('button').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.c===v)));cards.forEach(c=>c.hidden=!!v&&c.dataset.class!==v)}chips.addEventListener('click',e=>{const b=e.target.closest('button[data-c]');if(!b)return;const u=new URL(location.href);b.dataset.c?u.searchParams.set('class',b.dataset.c):u.searchParams.delete('class');history.pushState(null,'',u.pathname+u.search+u.hash);apply(b.dataset.c)});addEventListener('popstate',()=>apply(new URL(location.href).searchParams.get('class')||''));apply(new URL(location.href).searchParams.get('class')||'')})();(()=>{const select=document.querySelector('.faction-picker select'),links=[...document.querySelectorAll('.race-filter .chips a')];select.addEventListener('change',()=>links.forEach(link=>link.hidden=link.dataset.faction!=='Both'&&link.dataset.faction!==select.value))})();</script>`);
  // linkedom keeps the template's own doctype.
  return document.toString();
}
