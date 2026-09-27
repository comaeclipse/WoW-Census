// Last-known-location footprint for the static census bundle. These are
// observed character locations, not movement history or proof of an activity.

import { esc, realmName, chipRow, CENSUS_STYLE, TOGGLE_SCRIPT } from "./census.mjs";

const CAPITALS = new Set([
  "Darnassus", "Dornogal", "Exodar", "Ironforge", "Orgrimmar", "Shattrath City",
  "Silvermoon City", "Stormwind City", "The Exodar", "Thunder Bluff", "Undercity",
]);

const GEO_STYLE = `
  .geogrid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(280px,.75fr);gap:18px;align-items:start}
  .geogrid .panel{min-width:0}
  .geobar{display:grid;grid-template-columns:minmax(110px,190px) 1fr 58px;gap:10px;align-items:center;margin-bottom:7px}
  .geobar .name{font-family:var(--pixel);font-size:7px;text-align:right;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .geobar .track{height:21px;padding:2px;background:var(--panel2);border:2px solid var(--line)}
  .geobar .track i{display:block;height:100%;background:var(--blue)}
  .geobar .value{text-align:right;color:var(--ink);font-variant-numeric:tabular-nums}
  .geomore{margin-top:10px;border-top:2px solid var(--line);padding-top:8px}
  .geomore summary{width:max-content;list-style:none;cursor:pointer;font-family:var(--pixel);font-size:8px;
    color:var(--gold);letter-spacing:1px;text-transform:uppercase;padding:7px 0}
  .geomore summary::-webkit-details-marker{display:none}
  .geomore summary:hover{color:var(--ink)}
  .geomore .more-open{display:none}.geomore[open] .more-open{display:inline}.geomore[open] .more-closed{display:none}
  .geomore .morebars{padding-top:8px}
  .geotable{width:100%;min-width:0;table-layout:fixed}
  .geotable th,.geotable td{padding:7px 8px}
  .geotable th:nth-child(1),.geotable td:nth-child(1){width:45%;text-align:left}
  .geotable th:nth-child(2),.geotable td:nth-child(2){width:16%}
  .geotable th:nth-child(3),.geotable td:nth-child(3){width:15%}
  .geotable th:nth-child(4),.geotable td:nth-child(4){width:24%;text-align:left}
  .signal{border-left:3px solid var(--blue);padding:0 0 0 12px;margin:0 0 16px}
  .signal b{display:block;font-family:var(--pixel);font-size:8px;color:var(--gold);line-height:1.6;text-transform:uppercase}
  .signal span{display:block;color:var(--muted);margin-top:4px;line-height:1.25}
  @media(max-width:800px){.geogrid{grid-template-columns:1fr}.geobar{grid-template-columns:100px 1fr 52px}}
`;

function activityHint(z, observedMax) {
  if (CAPITALS.has(z.name)) return "Capital hub";
  if ((z.avgLevel || 0) <= 10) return "Starting / early";
  if (observedMax > 0 && (z.avgLevel || 0) >= observedMax * .88) return "High-level / endgame";
  return "Leveling / progression";
}

function renderView(snapshot) {
  const zones = (snapshot.zones || []).filter((z) => z.name !== "Unknown");
  const total = snapshot.characters || zones.reduce((n, z) => n + z.characters, 0);
  const max = zones.length ? zones[0].characters : 0;
  const observedMax = zones.reduce((n, z) => Math.max(n, z.maxLevel || 0), 0);
  const top5 = zones.slice(0, 5).reduce((n, z) => n + z.characters, 0);
  const top5Pct = total ? Math.round(top5 / total * 100) : 0;
  const capitalCount = zones.filter((z) => CAPITALS.has(z.name)).reduce((n, z) => n + z.characters, 0);
  const capitalPct = total ? Math.round(capitalCount / total * 100) : 0;
  const updated = snapshot.lastT ? new Date(snapshot.lastT * 1000).toISOString().slice(0, 10) : "&mdash;";
  const tiles = '<section class="tiles">' +
    '<div class="tile"><div class="k">Characters</div><div class="v">' + total.toLocaleString() + '</div><div class="s">unique, cumulative</div></div>' +
    '<div class="tile"><div class="k">Locations</div><div class="v">' + zones.length.toLocaleString() + '</div><div class="s">latest-known locations</div></div>' +
    '<div class="tile"><div class="k">Top 5 share</div><div class="v">' + top5Pct + '%</div><div class="s">observed concentration</div></div>' +
    '<div class="tile"><div class="k">Updated</div><div class="v">' + updated + '</div><div class="s">last population sample</div></div></section>';

  if (!zones.length) return tiles + '<div class="panel"><p class="hint">No location data has been observed for this selection yet.</p></div>';
  const renderBars = (list) => list.map((z) => '<div class="geobar"><div class="name">' + esc(z.name.toUpperCase()) +
    '</div><div class="track"><i style="width:' + (max ? Math.max(.8, z.characters / max * 100) : 0).toFixed(1) +
    '%"></i></div><div class="value">' + z.characters.toLocaleString() + '</div></div>').join("");
  const bars = renderBars(zones.slice(0, 15));
  const moreBars = zones.length > 15
    ? '<details class="geomore"><summary><span class="more-closed">&#8595; See more</span><span class="more-open">&#8593; See less</span></summary>' +
      '<div class="morebars">' + renderBars(zones.slice(15)) + '</div></details>'
    : '';
  const rows = zones.slice(0, 50).map((z) => '<tr><td>' + esc(z.name) + '</td><td>' + z.characters.toLocaleString() +
    '</td><td>' + (total ? (z.characters / total * 100).toFixed(1) : '0.0') + '%</td><td>' +
    esc(activityHint(z, observedMax)) + ' · avg ' + (z.avgLevel || '&mdash;') + '</td></tr>').join("");
  const top = zones[0];
  const signals = '<div class="panel"><div class="ptitle">How to read this</div>' +
    '<div class="signal"><b>Strongest cluster</b><span>' + esc(top.name) + ' contains ' +
      (total ? (top.characters / total * 100).toFixed(1) : '0.0') + '% of observed characters in this selection.</span></div>' +
    '<div class="signal"><b>Top-five concentration</b><span>' + top5Pct +
      '% indicates how much the footprint is concentrated in its five leading locations.</span></div>' +
    '<div class="signal"><b>Capital footprint</b><span>' + capitalPct +
      '% were last observed in recognized capitals. This is consistent with services, social, travel, or idle time—not proof of any one activity.</span></div>' +
    '<div class="signal"><b>Level context</b><span>Average level helps separate starting, progression, and high-level clusters. Activity labels are interpretation hints only.</span></div></div>';
  return tiles + '<div class="geogrid"><div><div class="panel census" style="margin-bottom:18px"><div class="ptitle">Cumulative latest-known locations</div>' +
    '<p class="hint" style="margin-top:0">Every unique character observed over the dataset lifetime counts once at their most recently recorded location. Inactive characters remain until observed elsewhere.</p>' + bars + moreBars + '</div>' +
    '<div class="panel census"><div class="ptitle">Location detail</div><table class="geotable"><thead><tr><th>Location</th><th>Characters</th><th>Share</th><th>Activity hint</th></tr></thead><tbody>' +
    rows + '</tbody></table></div></div>' + signals + '</div>';
}

export function renderGeographyHtml(views, dims = {}, opts = {}) {
  const realms = dims.realm || [], factions = dims.faction || [];
  const activeRealm = realms.length ? realms[0].key : "all";
  const activeFaction = factions.length ? factions[0].key : "all";
  const chips = chipRow("realm", realms, activeRealm) + chipRow("faction", factions, activeFaction);
  const panes = (views || []).map((v) => '<div class="vpane" data-realm="' + esc(v.realm) +
    '" data-faction="' + esc(v.faction) + '"' +
    (v.realm === activeRealm && v.faction === activeFaction ? '' : ' hidden') + '>' + renderView(v.snapshot) + '</div>').join("");
  const gameLabel = opts.gameLabel || "WoW Forever";
  const scopeLabel = opts.scopeLabel || "Beta realms";
  return `<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(gameLabel)} geography — MarketLens</title>
<meta name="description" content="Observed character location concentration for ${esc(gameLabel)}, sampled via /who.">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Press+Start+2P&family=VT323&display=swap">
<link rel="stylesheet" href="${esc(opts.stylesheet || "style.css")}"><style>${CENSUS_STYLE}${GEO_STYLE}</style>
</head><body><div class="crt" aria-hidden="true"></div><div class="wrap">${opts.nav || ""}
<header class="ihead"><h1 class="iname">${esc(gameLabel)} &mdash; Geography</h1>
<div class="itag">${esc(scopeLabel)} &middot; last known locations &middot; unique characters sampled via /who</div>
${opts.note ? '<div class="itag" style="margin-top:10px;line-height:1.6">' + esc(opts.note) + '</div>' : ''}</header>
${chips}${panes}
<p class="src">This is an observed-location footprint, not a live map or movement history.<br>
Each character counts once at the latest location where a /who scan caught them. Targeted queries, the server result cap, scan timing, and differently sized locations affect the ranking.<br>
Activity labels are conservative interpretation hints; location alone cannot prove questing, raiding, gathering, trading, PvP, or player intent.</p>
</div>${chips ? TOGGLE_SCRIPT : ""}</body></html>`;
}
