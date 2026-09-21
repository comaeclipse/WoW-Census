// Shared rendering for the Forever (Beta) cross-faction census.
//
// Two things draw this page: the Worker (/wowforever, live from D1) and
// tools/build-forever-page.js, which bakes the same markup into a static
// pages/ bundle for drag-and-drop hosting. Both call renderCensusHtml with the
// same census object so the two never drift apart -- only the links and the
// stylesheet path differ.

export function esc(s) {
  return String(s).replace(/[&<>"]/g, (m) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[m]));
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

// One horizontal bar row. `max` scales every bar in a chart against the same
// value, so bar lengths compare across the whole chart.
function barRow(label, n, max, color) {
  const pct = max > 0 ? Math.max((n / max) * 100, 0.6) : 0;
  return '<div class="bar">' +
    '<div class="bl">' + esc(label) + "</div>" +
    '<div class="bt"><i style="width:' + pct.toFixed(2) + "%;background:" + color + '"></i></div>' +
    '<div class="bn">' + n.toLocaleString() + "</div></div>";
}

function barChart(entries, max, colorFor, labelFor) {
  return entries.map(([k, n]) => barRow(labelFor ? labelFor(k) : k, n, max, colorFor(k))).join("");
}

function sortedEntries(dist) {
  return Object.keys(dist)
    .map((k) => [k, dist[k]])
    .sort((a, b) => b[1] - a[1] || String(a[0]).localeCompare(String(b[0])));
}

const CENSUS_STYLE = `
  .chartgrid{display:grid; grid-template-columns:1fr 1fr; gap:22px; align-items:start}
  @media (max-width:820px){.chartgrid{grid-template-columns:1fr}}
  .census .legend{display:flex; gap:18px; margin:0 0 14px}
  .census .key{display:inline-flex; align-items:center; gap:7px; font-size:17px; color:var(--muted)}
  .census .key i{width:13px; height:13px; display:inline-block}
  .bar{display:grid; grid-template-columns:172px 1fr 62px; align-items:center; gap:10px; margin-bottom:6px}
  .bar .bl{font-family:var(--pixel); font-size:8px; letter-spacing:1px; text-align:right;
    color:var(--ink); overflow:hidden; text-overflow:ellipsis; white-space:nowrap}
  .bar .bt{background:var(--panel2); border:2px solid var(--line); height:20px; padding:1px}
  .bar .bt i{display:block; height:100%}
  .bar .bn{text-align:right; color:var(--ink)}
  @media (max-width:520px){.bar{grid-template-columns:96px 1fr 52px}}`;

// census: { groups, realms, lastT } from loadForeverCensus (or /api/forever).
// opts.stylesheet  path to style.css ("/style.css" on the Worker, "style.css"
//                  in the static bundle, which may sit under a subpath)
// opts.realmHref   game key -> per-realm page URL, or null to drop those links
// opts.back        { href, label } for the top-left crumb, or null
// opts.note        extra line under the header (the static build stamps its age)
export function renderCensusHtml(census, opts = {}) {
  const groups = (census && census.groups) || [];
  const realms = (census && census.realms) || [];
  const lastT = (census && census.lastT) || 0;
  const total = groups.reduce((a, g) => a + g.characters, 0);
  const samples = groups.reduce((a, g) => a + g.samples, 0);
  const sightings = groups.reduce((a, g) => a + g.observed, 0);

  let body;
  if (!total && !samples) {
    body = '<div class="panel"><p class="hint">No Forever (Beta) population data uploaded yet. ' +
      "In game on a beta realm, open the Population tab and press Scan Population (or /ml who), then run " +
      "<code>upload-realm.ps1 -Flavor classic-beta</code>.</p></div>";
  } else {
    // Race chart: every faction's races in one chart, grouped by faction and
    // scaled against the single largest race so the faction blocks compare.
    const raceMax = groups.reduce((m, g) =>
      Object.values(g.races).reduce((mm, n) => Math.max(mm, n), m), 0);
    const raceRows = groups.map((g) =>
      barChart(sortedEntries(g.races), raceMax, () => factionColor(g.faction), (k) => k.toUpperCase())).join("");
    const legend = groups.map((g) =>
      '<span class="key"><i style="background:' + factionColor(g.faction) + '"></i>' + esc(g.faction) + "</span>").join("");
    const racePanel = '<div class="panel census" style="margin-bottom:22px"><div class="ptitle">Population by race</div>' +
      '<p class="hint" style="margin-top:0">Grouped by faction, most populous first.</p>' +
      '<div class="legend">' + legend + "</div>" +
      (raceRows || '<p class="hint">No race data yet.</p>') + "</div>";

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
          esc(game.indexOf("realm:") === 0 ? game.slice(6) : game) + "</a>").join("")).join("")
      : "";
    body = racePanel + '<div class="chartgrid">' + classPanels + "</div>" +
      (links ? '<div class="ptitle" style="margin:26px 0 12px">Per-realm detail</div><nav class="games">' + links + "</nav>" : "");
  }

  const tiles =
    '<div class="tile"><div class="k">Characters</div><div class="v">' + total.toLocaleString() +
      '</div><div class="s">unique, all time</div></div>' +
    '<div class="tile"><div class="k">Realms</div><div class="v">' + (realms.length || "&mdash;") +
      '</div><div class="s">' + esc(realms.join(" · ") || "none yet") + "</div></div>" +
    '<div class="tile"><div class="k">Scans</div><div class="v">' + samples.toLocaleString() +
      '</div><div class="s">' + sightings.toLocaleString() + " sightings</div></div>" +
    '<div class="tile"><div class="k">Updated</div><div class="v">' +
      (lastT ? new Date(lastT * 1000).toISOString().slice(0, 10) : "&mdash;") +
      '</div><div class="s">last sample</div></div>';

  const back = opts.back
    ? '<a class="back" href="' + esc(opts.back.href) + '">&#9664; ' + esc(opts.back.label) + "</a>"
    : "";
  // The 8px pixel font overflows the body's tight line box, so a second .itag
  // line needs its own breathing room or it collides with the tagline above.
  const note = opts.note
    ? '<div class="itag" style="margin-top:10px;line-height:1.6">' + esc(opts.note) + "</div>"
    : "";

  return `<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>WoW Forever census — MarketLens</title>
<meta name="description" content="Observed population census for the WoW Forever beta realms: race and class distribution by faction, sampled via /who.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Press+Start+2P&family=VT323&display=swap">
<link rel="stylesheet" href="${esc(opts.stylesheet || "/style.css")}">
<style>${CENSUS_STYLE}
</style>
</head><body>
<div class="crt" aria-hidden="true"></div>
<div class="wrap">
  ${back}
  <header class="ihead">
    <h1 class="iname">WoW Forever &mdash; Observed Census</h1>
    <div class="itag">Beta realms &middot; both factions &middot; unique characters sampled via /who</div>
    ${note}
  </header>
  <section class="tiles">${tiles}</section>
  ${body}
  <p class="src">
    A /who returns a sample of currently-visible online players (server-capped ~50), not a census.<br>
    Each bar counts unique characters &mdash; one per normalized character name + realm &mdash; so a faction
    that received more scans does not gain share from the extra scans alone.<br>
    Characters are not human players/accounts; a rename appears as a new character. Companion to the MarketLens addon.
  </p>
</div>
</body></html>`;
}
