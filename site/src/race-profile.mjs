import fs from "node:fs";
import vm from "node:vm";
import { fileURLToPath } from "node:url";
import { parseHTML } from "linkedom";
import { CLASS_META } from "./census.mjs";

const template = fileURLToPath(new URL("./dwarf-template.html", import.meta.url));
const med = (arr) => { const a=arr.filter(Number.isFinite).sort((x,y)=>x-y); return a.length?a[Math.floor((a.length-1)/2)]:null; };
const pct = (a,b) => b?Math.round(100*a/b):0;
export function dwarfProfile(inspects) {
  const rows=(inspects.records||[]).filter(r=>r.race==="Dwarf"&&r.talents?.length), classes={};
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

export function renderDwarfProfile(inspects,opts){
  const D=dwarfProfile(inspects);
  let source=fs.readFileSync(template,"utf8").replaceAll("../static/fonts/","/fonts/").replaceAll("../static/assets/","/assets/")
    .replace('const ICON = n => "https://wow.zamimg.com/images/wow/icons/large/" + n + ".jpg";','const ICON = n => "/assets/images/wow/icons/large/" + n + ".jpg";')
    .replace(/const D = \{.*\};/, "const D = "+JSON.stringify({n:D.n,classes:D.classes})+";");
  const script=source.match(/<script>([\s\S]*?)<\/script>/)?.[1];
  if(!script)throw new Error("Dwarf template script missing");
  const {document}=parseHTML(source);
  vm.runInNewContext(script,{document,URL,location:"https://wowcensus.io/forever/dwarf/",history:{pushState(){}},addEventListener(){}},{timeout:5000});
  document.querySelector("script").remove();
  document.title="WoW Forever Dwarf Specs & Talents – WoWCensus";
  document.head.insertAdjacentHTML("beforeend",'<meta name="description" content="Dwarf class builds and selected talents observed in WoW Forever nearby-player inspections."><link rel="canonical" href="'+opts.canonical+'"><meta property="og:type" content="website"><meta property="og:site_name" content="WoWCensus"><meta property="og:title" content="WoW Forever Dwarf Specs &amp; Talents – WoWCensus"><meta property="og:url" content="'+opts.canonical+'"><link rel="icon" href="/favicon.ico">');
  document.head.querySelector("style").insertAdjacentHTML("beforebegin",'<link rel="stylesheet" href="/'+opts.stylesheet+'">');
  for(const [i,url] of ["/","/tbc/","/classic/","/sod/","/mop/","/retail/"].entries())document.querySelector(".game-nav").children[i].href=url;
  for(const [i,url] of ["/","/","/talents"].entries())document.querySelectorAll(".breadcrumbs a")[i].href=url;
  for(const [i,url] of ["/","/auctionhouse","/guilds","/geography","/talents","/gear"].entries())document.querySelector(".page-nav").children[i].href=url;
  for(const a of document.querySelectorAll(".page-controls .chips a")){const name=a.textContent.trim();if(name==="Dwarf"){a.href="/forever/dwarf/";a.removeAttribute("aria-pressed");a.setAttribute("aria-current","page");}else{const span=document.createElement("span");span.className="chip";span.textContent=name;a.replaceWith(span);}}
  const order=Object.keys(D.classes).sort((a,b)=>D.classes[b].n-D.classes[a].n),top=order[0],topN=D.classes[top]?.n||0;
  const specs=order.flatMap(cf=>Object.entries(D.classes[cf].spec).filter(([s])=>s!=="Unknown build"&&!s.includes("/")).map(([s,n])=>({cf,s,n}))).sort((a,b)=>b.n-a.n);
  const tank=specs.filter(x=>x.s==="Protection").sort((a,b)=>b.n-a.n),first=specs[0];
  const hero=document.querySelector(".hero");
  hero.querySelector("p").textContent=D.n?((CLASS_META[top]?.[0]||top)+" is the most inspected Dwarf class, and "+(first?.s||"no single build")+" is the most common classified build. These are nearby-player inspections, not every Dwarf in WoW Forever."):"No Dwarf inspections with readable talents are available in this snapshot.";
  hero.querySelector(".fresh").innerHTML='<span class="tag">Latest inspection '+(D.lastT?new Date(D.lastT*1000).toISOString().slice(0,16).replace("T"," ")+"Z":"unavailable")+'</span><span class="tag ghost">Last '+D.windowDays+' days · all realms</span>';
  hero.querySelector(".bignum b").textContent=D.n.toLocaleString("en-US");
  hero.querySelector(".bignum small").textContent="Dwarves with talents";
  const tiles=document.querySelectorAll(".tile");
  if(top){tiles[0].style.setProperty("--c","#"+(CLASS_META[top]?.[1]||"ffce43"));tiles[0].querySelector(".v").textContent=CLASS_META[top]?.[0]||top;tiles[0].querySelector(".s").textContent=topN+" of "+D.n+" · "+pct(topN,D.n)+"%";}
  if(first){tiles[1].style.setProperty("--c","#"+(CLASS_META[first.cf]?.[1]||"ffce43"));tiles[1].querySelector(".v").textContent=first.s;tiles[1].querySelector(".s").textContent=first.n+" players · "+pct(first.n,D.n)+"%";}
  tiles[2].querySelector(".v").textContent=tank[0]?"Prot "+(CLASS_META[tank[0].cf]?.[0]||tank[0].cf):"None classified";
  tiles[2].querySelector(".s").textContent=tank[0]?tank[0].n+" inspected"+(tank[1]?" · ahead of "+tank[1].n+" "+(CLASS_META[tank[1].cf]?.[0]||tank[1].cf):""):"No Protection build observed";
  tiles[3].querySelector(".v").textContent=D.medLv??"—";
  tiles[3].querySelector(".s").textContent=Object.values(D.classes).reduce((n,c)=>n+(c.spec["Unknown build"]||0),0)+" unknown builds";
  document.querySelector(".src").textContent+=(D.truncated?" The edition snapshot is capped at its newest 10,000 inspections.":"")+" Page generated: "+opts.generatedNote+".";
  document.body.insertAdjacentHTML("beforeend",`<script>(()=>{const chips=document.getElementById('classchips'),cards=[...document.querySelectorAll('.card')];function apply(s){const v=cards.some(c=>c.dataset.class===s)?s:'';chips.querySelectorAll('button').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.c===v)));cards.forEach(c=>c.hidden=!!v&&c.dataset.class!==v)}chips.addEventListener('click',e=>{const b=e.target.closest('button[data-c]');if(!b)return;const u=new URL(location.href);b.dataset.c?u.searchParams.set('class',b.dataset.c):u.searchParams.delete('class');history.pushState(null,'',u.pathname+u.search+u.hash);apply(b.dataset.c)});addEventListener('popstate',()=>apply(new URL(location.href).searchParams.get('class')||''));apply(new URL(location.href).searchParams.get('class')||'')})();</script>`);
  return "<!doctype html>"+document.toString();
}
