// Guild popularity for the static WoW Forever census bundle. Counts are unique
// surveyed characters grouped by their latest observed guild, never accounts or
// a claim about a guild's complete/current roster.

import { esc, chipRow, CENSUS_STYLE, TOGGLE_SCRIPT } from "./census.mjs";

const GUILD_STYLE = `
  .guildtable{width:100%;border-collapse:collapse;table-layout:fixed}
  .guildtable th{font-family:var(--pixel);font-size:7px;letter-spacing:1px;text-transform:uppercase;
    color:var(--gold);padding:8px;border-bottom:2px solid var(--line);white-space:nowrap}
  .guildtable td{padding:8px;border-bottom:1px solid var(--line);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .guildtable th,.guildtable td{text-align:left}
  .guildtable .rank{width:52px;text-align:right;color:var(--muted)}
  .guildtable .members{width:112px;text-align:right}
  .guildtable .scope{width:220px;color:var(--muted)}
  .guildtable tr:last-child td{border-bottom:0}
  @media(max-width:620px){.guildtable .scope{width:120px}.guildtable .members{width:82px}}
`;

function scopeLabel(g, snapshot) {
  const manyRealms = (snapshot.realms || []).length > 1;
  return [manyRealms ? g.realm.replace(/^Classic Beta\s+/i, "") : "", g.faction]
    .filter(Boolean).join(" · ");
}

function renderGuildView(snapshot) {
  const guilds = (snapshot && snapshot.guilds) || [];
  const top = guilds.slice(0, 100);
  const surveyed = (snapshot && snapshot.surveyedCharacters) || 0;
  const guilded = (snapshot && snapshot.guildedCharacters) || 0;
  const realms = (snapshot && snapshot.realms) || [];
  const lastT = (snapshot && snapshot.lastT) || 0;
  const rows = top.map((g, i) => '<tr>' +
    '<td class="rank">' + (i + 1) + '</td>' +
    '<td>&lt;' + esc(g.name) + '&gt;</td>' +
    '<td class="scope">' + esc(scopeLabel(g, snapshot)) + '</td>' +
    '<td class="members">' + g.members.toLocaleString() + '</td></tr>').join("");

  const tiles =
    '<div class="tile"><div class="k">Guilds observed</div><div class="v">' + guilds.length.toLocaleString() +
      '</div><div class="s">distinct realm guilds</div></div>' +
    '<div class="tile"><div class="k">Guilded characters</div><div class="v">' + guilded.toLocaleString() +
      '</div><div class="s">latest known guild</div></div>' +
    '<div class="tile"><div class="k">Surveyed</div><div class="v">' + surveyed.toLocaleString() +
      '</div><div class="s">unique characters</div></div>' +
    '<div class="tile"><div class="k">Updated</div><div class="v">' +
      (lastT ? new Date(lastT * 1000).toISOString().slice(0, 10) : '&mdash;') +
      '</div><div class="s">' + esc(realms.join(' · ') || 'no realm') + '</div></div>';

  const body = rows
    ? '<div class="panel census"><div class="ptitle">Most popular observed guilds</div>' +
      '<p class="hint" style="margin-top:0">Ranked by unique surveyed characters whose latest observed guild matches this name.</p>' +
      '<table class="guildtable"><thead><tr><th class="rank">#</th><th>Guild</th><th class="scope">Realm · faction</th>' +
      '<th class="members">Characters</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
    : '<div class="panel"><p class="hint">No guilded characters have been observed for this selection yet.</p></div>';
  return '<section class="tiles">' + tiles + '</section>' + body;
}

export function renderGuildHtml(views, dims = {}, opts = {}) {
  const list = (views || []).filter(Boolean);
  const realms = dims.realm || [];
  const factions = dims.faction || [];
  const activeRealm = realms.length ? realms[0].key : "all";
  const activeFaction = factions.length ? factions[0].key : "all";
  const chips = chipRow("realm", realms, activeRealm) + chipRow("faction", factions, activeFaction);
  const panes = list.map((v) => {
    const on = v.realm === activeRealm && v.faction === activeFaction;
    return '<div class="vpane" data-realm="' + esc(v.realm) + '" data-faction="' + esc(v.faction) + '"' +
      (on ? '' : ' hidden') + '>' + renderGuildView(v.snapshot) + '</div>';
  }).join("");

  return `<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>WoW Forever guilds — MarketLens</title>
<meta name="description" content="Most commonly observed guilds in WoW Forever beta population surveys, by realm and faction.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Press+Start+2P&family=VT323&display=swap">
<link rel="stylesheet" href="${esc(opts.stylesheet || "style.css")}">
<style>${CENSUS_STYLE}${GUILD_STYLE}</style>
</head><body><div class="crt" aria-hidden="true"></div><div class="wrap">
  ${opts.nav || ""}
  <header class="ihead"><h1 class="iname">WoW Forever &mdash; Observed Guilds</h1>
    <div class="itag">Beta realms &middot; latest known guild &middot; unique characters sampled via /who</div>
    ${opts.note ? '<div class="itag" style="margin-top:10px;line-height:1.6">' + esc(opts.note) + '</div>' : ''}
  </header>
  ${chips}${panes}
  <p class="src">Guild counts come from sampled /who results, not complete guild rosters.<br>
    Each character counts once under its latest observed guild. Unguilded characters are excluded, and identically named guilds
    on different realms remain separate. Characters are not human players/accounts.</p>
</div>${chips ? TOGGLE_SCRIPT : ""}</body></html>`;
}
