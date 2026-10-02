// Guild popularity for the static WoW Forever census bundle. Counts are unique
// surveyed characters grouped by their latest observed guild, never accounts or
// a claim about a guild's complete/current roster.

import { esc, seoLabel, seoHead, pageHeading, realmName, chipRow, CENSUS_STYLE, TOGGLE_SCRIPT } from "./census.mjs";

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
  return [manyRealms ? realmName(g.realm) : "", g.faction]
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
    '<div class="tile"><div class="k">Guilds found</div><div class="v">' + guilds.length.toLocaleString() +
      '</div><div class="s">distinct realm guilds</div></div>' +
    '<div class="tile"><div class="k">Guilded characters</div><div class="v">' + guilded.toLocaleString() +
      '</div><div class="s">latest known guild</div></div>' +
    '<div class="tile"><div class="k">Surveyed</div><div class="v">' + surveyed.toLocaleString() +
      '</div><div class="s">unique characters</div></div>' +
    '<div class="tile"><div class="k">Updated</div><div class="v">' +
      (lastT ? new Date(lastT * 1000).toISOString().slice(0, 10) : '&mdash;') +
      '</div><div class="s">' + esc(realms.map(realmName).join(' · ') || 'no realm') + '</div></div>';

  const body = rows
    ? '<div class="panel census"><div class="ptitle">Most popular guilds</div>' +
      '<p class="hint" style="margin-top:0">Ranked by unique surveyed characters whose latest recorded guild matches this name.</p>' +
      '<table class="guildtable"><thead><tr><th class="rank">#</th><th>Guild</th><th class="scope">Realm · faction</th>' +
      '<th class="members">Characters</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
    : '<div class="panel"><p class="hint">No guilded characters were found for this selection yet.</p></div>';
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

  const gameLabel = opts.gameLabel || "WoW Forever";
  const navigation = opts.nav && typeof opts.nav === "object" ? opts.nav : { games: opts.nav || "", pages: "" };
  const heading = navigation.games ? "WoWCensus" : gameLabel + " — Guilds";
  const notes = (opts.notes || (opts.note ? [opts.note] : [])).map((note, i) =>
    '<div class="itag" style="' + (i ? "margin-top:4px;" : "margin-top:10px;") + 'line-height:1.6">' + esc(note) + "</div>").join("");
  const generatedNote = opts.generatedNote
    ? '<div class="itag" style="margin-top:16px;line-height:1.6">' + esc(opts.generatedNote) + "</div>"
    : "";
  const seoGameLabel = seoLabel(gameLabel);
  const topic = seoGameLabel + " Guilds by Realm";
  return `<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="google-site-verification" content="N0-ZNJyDo16jefYUGxfcAda_mKf7S2oATfRGWETdsHs">
${seoHead(topic + " – WoWCensus", seoGameLabel + " guild activity by realm and faction, based on characters found in sampled in-game /who results.", opts.canonical)}
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96">
<link rel="icon" href="/favicon-96.png" type="image/png" sizes="96x96">
<link rel="preload" href="/fonts/press-start-2p-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/vt323-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/cinzel-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="${esc(opts.stylesheet || "style.css")}">
<style>${CENSUS_STYLE}${GUILD_STYLE}</style>
</head><body><div class="crt" aria-hidden="true"></div><div class="wrap">
  <header class="ihead"><div class="page-heading">${pageHeading(heading, navigation, topic)}${navigation.games || ""}</div>
    ${navigation.breadcrumbs || notes}
  </header>
  <div class="page-controls">${navigation.pages || ""}${chips}</div>${panes}
  ${generatedNote}
</div>${chips ? TOGGLE_SCRIPT : ""}</body></html>`;
}
