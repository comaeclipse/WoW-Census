import catalog from "./forever-talents.json" with { type: "json" };

export const INSPECT_WINDOW_DAYS = 14;
const classes = new Set(["WARRIOR","PALADIN","HUNTER","ROGUE","PRIEST","SHAMAN","MAGE","WARLOCK","DRUID","MONK","DEATHKNIGHT","DEMONHUNTER","EVOKER"]);
const sources = new Set(["classic-beta","classic-progression","classic","retail","mop-classic","sod"]);
const nodeCatalog = new Map(catalog.nodes.map(n => [n.node, n]));
// The inspected specialization is authoritative on spec-based clients. Keep
// this class-scoped so a placeholder or a stale ID cannot label another class.
const specNames = {
  WARRIOR:{71:"Arms",72:"Fury",73:"Protection"}, PALADIN:{65:"Holy",66:"Protection",70:"Retribution"},
  HUNTER:{253:"Beast Mastery",254:"Marksmanship",255:"Survival"}, ROGUE:{259:"Assassination",260:"Outlaw",261:"Subtlety"},
  PRIEST:{256:"Discipline",257:"Holy",258:"Shadow"}, DEATHKNIGHT:{250:"Blood",251:"Frost",252:"Unholy"},
  SHAMAN:{262:"Elemental",263:"Enhancement",264:"Restoration"}, MAGE:{62:"Arcane",63:"Fire",64:"Frost"},
  WARLOCK:{265:"Affliction",266:"Demonology",267:"Destruction"}, MONK:{268:"Brewmaster",269:"Windwalker",270:"Mistweaver"},
  DRUID:{102:"Balance",103:"Feral",104:"Guardian",105:"Restoration"},
  DEMONHUNTER:{577:"Havoc",581:"Vengeance"}, EVOKER:{1467:"Devastation",1468:"Preservation",1473:"Augmentation"}
};
export const catalogInfo = {version: catalog.version, source: catalog.source, retrievedAt: catalog.retrievedAt};
const text = (value, max = 200) => typeof value === "string" ? value.slice(0, max) : "";
const norm = value => String(value || "").normalize("NFC").toLocaleLowerCase().replace(/\s+/g, " ").trim();
const integer = value => Number.isSafeInteger(value);

export function normalizeInspect(record, source, now = Math.floor(Date.now()/1000)) {
  if (!record || !/^Player-[0-9]+-[A-Fa-f0-9]+$/.test(record.guid || "") || !integer(record.time)
      || record.time <= 0 || record.time > now + 300 || !classes.has(record.class)
      || !integer(record.level) || record.level < 1 || record.level > 100
      || !Array.isArray(record.list) || record.list.length > 500 || !text(record.name)) return null;
  const talents = text(record.talents, 4096);
  if (talents && !/^[A-Za-z0-9+/=]+$/.test(talents)) return null;
  const list = [], seen = new Set();
  for (const t of record.list) {
    const node = t && (integer(t.node) ? t.node : (record.schemaVersion === 3 ? t.talent : null));
    if (!t || !integer(node) || node <= 0 || seen.has(node) || !integer(t.rank)
        || !integer(t.max) || t.rank < 1 || t.rank > t.max || t.max > 100 || !text(t.name)) return null;
    seen.add(node);
    const branch = record.schemaVersion === 3 ? text(record.trees?.[t.tree]?.name, 40) : "";
    list.push({node,tree:integer(t.tree)?t.tree:null,spell:integer(t.spell)?t.spell:null,branch,
      name:text(t.name),rank:t.rank,max:t.max,x:Number.isFinite(t.x)?t.x:null,y:Number.isFinite(t.y)?t.y:null,
      entry:integer(t.entry)?t.entry:null,definition:integer(t.definition)?t.definition:null,
      groups:Array.isArray(t.groups)?t.groups.filter(integer).slice(0,50):[]});
  }
  if (!talents && !list.length && !(source === "mop-classic" && integer(record.spec) && record.spec > 0)) return null;
  const contextual = record.schemaVersion >= 2;
  return {guid:record.guid,time:record.time,rawName:text(record.rawName || record.name),
    name:contextual?text(record.name):text(record.name).replace(/-/," "),
    realm:contextual?text(record.realm):"",faction:contextual&&["Alliance","Horde","Neutral"].includes(record.faction)?record.faction:"",
    observerRealm:contextual?text(record.observerRealm):"",observerFaction:contextual?text(record.observerFaction):"",
    observerZone:contextual?text(record.observerZone):"",class:record.class,race:text(record.race),level:record.level,guild:text(record.guild),
    spec:integer(record.spec)?record.spec:null,role:text(record.role,30),talents,list,schemaVersion:contextual?record.schemaVersion:1,
    clientBuild:contextual?text(record.clientBuild,40):"",locale:contextual?text(record.locale,10):"",source};
}

export function matchInspect(e, characters) {
  const matches = characters.filter(c => norm(c.name) === norm(e.name) && c.class_file === e.class
    && (!e.race || norm(c.race) === norm(e.race)) && (!e.realm || norm(c.realm) === norm(e.realm))
    && (!e.faction || c.game.endsWith("-" + e.faction)));
  return matches.length === 1 ? {game:matches[0].game,key:matches[0].character_key,status:e.schemaVersion>=2?"context-name":"legacy-name"}
    : {game:null,key:null,status:matches.length?"ambiguous":"unmatched"};
}

export function describeBuild(e, source) {
  const points = {}, talents = [], unresolved = [];
  const specBased=source==="retail"||source==="mop-classic";
  for (const t of e.list || []) {
    const candidate = source === "classic-beta" ? nodeCatalog.get(t.node) : null;
    // Validate node identity against the observed spell and maximum rank before
    // assigning a beta branch. Old catalogs must not silently mislabel changes.
    const mapped = candidate && candidate.classFile === e.class && candidate.max === t.max
      && (!t.spell || candidate.spell === t.spell) ? candidate : null;
    const branch = mapped?.branch || (source !== "classic-beta" && t.branch ? t.branch : null);
    if (branch) points[branch] = (points[branch] || 0) + t.rank;
    else unresolved.push(t.node);
    talents.push({...t,branch:branch || (specBased&&t.tree?`Tree ${t.tree}`:"Unmapped talent"),icon:mapped?.icon || null});
  }
  const orderedPoints = Object.entries(points).sort((a,b)=>b[1]-a[1]||a[0].localeCompare(b[0]))
    .map(([tree,points])=>({tree,points}));
  const totalPoints=(e.list||[]).reduce((n,t)=>n+t.rank,0);
  const specID=e.spec??e.rawSpecID;
  const spec=specNames[e.class]?.[specID];
  const eraValid=source!=="mop-classic"||!["DEMONHUNTER","EVOKER"].includes(e.class);
  let primaryTree=null, classification="Unknown build", hybrid=false, unknownReason=null;
  if(specBased){
    if(spec && eraValid) classification=source==="mop-classic"&&Number(specID)===260?"Combat":spec;
    else unknownReason=specID == null || specID === 0 ? "No inspected specialization ID" : "Unrecognized specialization ID for this class and edition";
  } else if(unresolved.length){
    unknownReason="Selected talent nodes could not be mapped to trusted trees";
  } else if(!orderedPoints.length){
    unknownReason="No allocated talent points observed";
  } else {
    primaryTree=orderedPoints[0].tree;
    const participating=orderedPoints.filter((p,i)=>i===0||(p.points/totalPoints>=0.25
      && orderedPoints[0].points-p.points<=10));
    hybrid=participating.length>1;
    classification=participating.map(p=>p.tree).join("/");
  }
  return {points,orderedPoints,primaryTree,classification,hybrid,unknownReason,
    dominant:classification,talents,unresolvedNodes:unresolved.length,totalPoints,
    classificationMethod:specBased?"inspected-specialization-id":"inferred-talent-trees",
    role:null,catalogVersion:source==="classic-beta"?catalog.version:null};
}

const response = (body, status = 200) => Response.json(body,{status,headers:{"cache-control":"no-store"}});
export async function importInspects(url, env, req) {
  if (!env.REFRESH_TOKEN || url.searchParams.get("token") !== env.REFRESH_TOKEN) return new Response("forbidden",{status:403});
  let body; try { body=await req.json(); } catch { return response({error:"invalid JSON"},400); }
  if (body?.type!=="ml-inspects-v1" || !sources.has(body.source) || body.collector!=="NameplateInspect"
      || !Array.isArray(body.records) || body.records.length>1000) return response({error:"invalid inspect payload (maximum 1000 records)"},400);
  const characters=(await env.DB.prepare("SELECT game,character_key,name,realm,race,class_file FROM characters WHERE source_game=?").bind(body.source).all()).results;
  let imported=0,rejected=0,linked=0,unmatched=0,ambiguous=0,nodes=0;
  const now=Math.floor(Date.now()/1000);
  const inspectRows=[], talentRows=[];
  for (const record of body.records) {
    const e=normalizeInspect(record,body.source,now); if(!e){rejected++;continue;}
    const match=matchInspect(e,characters); if(match.game)linked++;else if(match.status==="ambiguous")ambiguous++;else unmatched++;
    // Observer context never assigns the inspected character to a faction.
    const bucket=text(body.observerBucket), suffix=/^(.*)-(Alliance|Horde)$/.exec(bucket);
    const values=[body.source,e.guid,e.time,e.rawName,e.name,e.realm||null,e.faction||null,
      e.observerRealm||suffix?.[1]||null,e.observerFaction||suffix?.[2]||null,e.observerZone||null,
      e.class,e.race,e.level,e.guild,e.spec,e.role,e.talents,e.schemaVersion,e.clientBuild,e.locale,
      match.game,match.key,match.status,body.collector,JSON.stringify(e.list),JSON.stringify(record)];
    inspectRows.push(values);
    for (const t of e.list) talentRows.push([body.source,e.guid,e.time,t.node,t.tree,t.spell,t.name,t.rank,t.max,t.x,t.y,t.entry,t.definition,JSON.stringify(t.groups)]);
    imported++;nodes+=e.list.length;
  }
  // D1 permits 100 bound parameters per statement. Multi-row inserts keep
  // large caches comfortably below the per-request D1 query limit.
  if(talentRows.length>4000)return response({error:"too many talent nodes; send smaller chunks"},400);
  const statements=[];
  const columns="source_game,guid,captured_at,raw_name,name,realm,faction,observer_realm,observer_faction,observer_zone,class_file,race,level,guild,raw_spec_id,raw_role,import_string,schema_version,client_build,locale,game,character_key,match_status,collector,nodes_json,raw_json";
  for(let i=0;i<inspectRows.length;i+=3){
    const chunk=inspectRows.slice(i,i+3);
    statements.push(env.DB.prepare(`INSERT INTO character_inspects (${columns}) VALUES ${chunk.map(r=>"("+r.map(()=>"?").join(",")+")").join(",")}
      ON CONFLICT(source_game,guid,captured_at) DO UPDATE SET
      name=excluded.name,raw_name=excluded.raw_name,
      game=excluded.game,character_key=excluded.character_key,match_status=excluded.match_status
      WHERE excluded.schema_version>=character_inspects.schema_version`).bind(...chunk.flat()));
  }
  for(let i=0;i<talentRows.length;i+=7){
    const chunk=talentRows.slice(i,i+7);
    statements.push(env.DB.prepare(`INSERT INTO inspect_talents
      (source_game,guid,captured_at,node_id,tree_id,spell_id,name,rank,max_rank,x,y,entry_id,definition_id,groups_json)
      VALUES ${chunk.map(r=>"("+r.map(()=>"?").join(",")+")").join(",")} ON CONFLICT(source_game,guid,captured_at,node_id) DO NOTHING`).bind(...chunk.flat()));
  }
  for(let i=0;i<statements.length;i+=40)await env.DB.batch(statements.slice(i,i+40));
  return response({ok:true,imported,rejected,linked,unmatched,ambiguous,nodes});
}

export async function loadInspects(env, source) {
  const latest=await env.DB.prepare("SELECT MAX(captured_at) latest FROM character_inspects WHERE source_game=?").bind(source).first();
  const end=latest?.latest || 0;
  const rows=(await env.DB.prepare(`SELECT i.* FROM character_inspects i
    JOIN (SELECT guid,MAX(captured_at) t FROM character_inspects WHERE source_game=? GROUP BY guid) m
      ON m.guid=i.guid AND m.t=i.captured_at
    WHERE i.source_game=? AND i.captured_at>=? ORDER BY i.captured_at DESC,i.guid LIMIT 10001`)
    .bind(source,source,end-INSPECT_WINDOW_DAYS*86400).all()).results;
  const truncated=rows.length>10000;
  const records=rows.slice(0,10000).map(r=>{
    const e={class:r.class_file,spec:r.raw_spec_id,list:JSON.parse(r.nodes_json)};
    const build=describeBuild(e,source);
    // Names and import strings are public; internal GUIDs and raw payloads stay in D1.
    return {name:r.name,classFile:r.class_file,race:r.race,level:r.level,guild:r.guild||null,time:r.captured_at,
      realm:r.realm||r.game?.replace(/^realm:/,"").replace(/-(Alliance|Horde)$/,"")||null,
      faction:r.faction||(/-(Alliance|Horde)$/.exec(r.game||"")||[])[1]||null,
      matchStatus:r.match_status,game:r.game,characterKey:r.character_key,
      importString:r.import_string,rawSpecID:r.raw_spec_id,rawRole:r.raw_role,...build};
  });
  const retained=(await env.DB.prepare("SELECT COUNT(*) n FROM characters WHERE source_game=?").bind(source).first())?.n||0;
  const linked=new Set(records.filter(r=>r.game).map(r=>r.game+":"+r.characterKey)).size;
  const history=(await env.DB.prepare("SELECT COUNT(*) n FROM character_inspects WHERE source_game=?").bind(source).first())?.n||0;
  return {source,lastT:end,windowDays:INSPECT_WINDOW_DAYS,truncated,records,
    coverage:{inspected:records.length,withSelectedTalents:records.filter(r=>r.talents.length).length,linked,unmatched:records.filter(r=>!r.game).length,
      ambiguous:records.filter(r=>r.matchStatus==="ambiguous").length,retainedCensusCharacters:retained,history},
    catalog:source==="classic-beta"?catalogInfo:null};
}
export async function apiInspects(url,env) {
  const source=url.searchParams.get("source")||"classic-beta";
  if(!sources.has(source))return response({error:"unknown source game"},400);
  return Response.json(await loadInspects(env,source),{headers:{"cache-control":"public, max-age=60"}});
}
