// Auction-house overview for the static wowcensus bundle: a high-level read of
// one or more realm item snapshots -- what the market is worth, which markets
// hold that value, what is listed in bulk, and what the expensive end looks
// like. Styled like the census page and rendered from the same helpers.
//
// Only what a scan actually observes is shown. Listings are not sales, so
// "popular" here means listed in quantity, never sold.

import { esc, barChart, sortedEntries, CENSUS_STYLE } from "./census.mjs";

// Copper -> the site's usual g/s/c shorthand.
function money(cop) {
  cop = Math.round(cop || 0);
  const g = Math.floor(cop / 10000), s = Math.floor((cop % 10000) / 100), c = cop % 100;
  if (g > 0) return g.toLocaleString() + "g " + s + "s";
  if (s > 0) return s + "s " + c + "c";
  return c + "c";
}

function bigGold(cop) {
  const g = Math.floor((cop || 0) / 10000);
  if (g >= 1e6) return (g / 1e6).toFixed(1) + "M g";
  if (g >= 1e3) return Math.round(g / 1e3) + "k g";
  return g.toLocaleString() + " g";
}

// A scan that could not resolve an item's name stores "item:<id>"; show the id
// rather than the raw placeholder, and let Wowhead name it on hover.
function itemLabel(it) {
  const m = /^item:(\d+)$/.exec(it.name || "");
  return m ? "Item " + m[1] : it.name;
}

function itemLink(it, branch) {
  const href = "https://www.wowhead.com/" + (branch ? branch + "/" : "") + "item=" + it.id;
  return '<a href="' + esc(href) + '" target="_blank" rel="noopener">' + esc(itemLabel(it)) + "</a>";
}

function topTable(title, hint, items, branch, cols) {
  const head = cols.map((c) => '<th class="' + (c.left ? "l" : "r") + '">' + esc(c.label) + "</th>").join("");
  const body = items.map((it) =>
    "<tr>" + cols.map((c) =>
      '<td class="' + (c.left ? "l" : "r") + '">' + c.cell(it, branch) + "</td>").join("") + "</tr>").join("");
  return '<div class="panel census mkt"><div class="ptitle">' + esc(title) + "</div>" +
    '<p class="hint" style="margin-top:0">' + esc(hint) + "</p>" +
    '<table class="mkttable"><thead><tr>' + head + "</tr></thead><tbody>" +
    (body || '<tr><td class="l mu" colspan="' + cols.length + '" style="padding:16px">No data.</td></tr>') +
    "</tbody></table></div>";
}

const MARKET_STYLE = `
  .mkttable{width:100%; border-collapse:collapse; min-width:0; table-layout:fixed}
  .mkttable th{font-family:var(--pixel); font-size:7px; letter-spacing:1px; text-transform:uppercase;
    color:var(--gold); padding:6px 8px; border-bottom:2px solid var(--line); white-space:nowrap}
  .mkttable td{padding:6px 8px; border-bottom:1px solid var(--line); white-space:nowrap;
    overflow:hidden; text-overflow:ellipsis}
  .mkttable th.l,.mkttable td.l{text-align:left}
  .mkttable th.r,.mkttable td.r{text-align:right}
  .mkttable tr:last-child td{border-bottom:0}
  .mkttable td.l a{color:var(--ink); text-decoration:none}
  .mkttable td.l a:hover{color:var(--gold); text-decoration:underline}
  .mkttable th:first-child,.mkttable td:first-child{width:52%}
  .mktgrid{display:grid; grid-template-columns:1fr 1fr; gap:22px; align-items:start; margin-bottom:22px}
  @media (max-width:820px){.mktgrid{grid-template-columns:1fr}}`;

// snapshot: { items, realms, updatedAt, branch } — items already merged across
// realms, each { id, name, q, mv, asp, cat }.
export function renderMarketHtml(snapshot, opts = {}) {
  const items = (snapshot && snapshot.items) || [];
  const realms = (snapshot && snapshot.realms) || [];
  const branch = (snapshot && snapshot.branch) || "";
  const updatedAt = snapshot && snapshot.updatedAt;

  const totalValue = items.reduce((a, it) => a + (it.mv || 0), 0);
  const totalQty = items.reduce((a, it) => a + (it.q || 0), 0);

  const byQty = [...items].sort((a, b) => (b.q || 0) - (a.q || 0)).slice(0, 15);
  const byPrice = [...items].filter((it) => (it.q || 0) > 0)
    .sort((a, b) => (b.asp || 0) - (a.asp || 0)).slice(0, 15);
  const byValue = [...items].sort((a, b) => (b.mv || 0) - (a.mv || 0)).slice(0, 15);

  const cats = {};
  for (const it of items) {
    const k = it.cat || "Uncategorized";
    cats[k] = (cats[k] || 0) + (it.mv || 0);
  }
  const catEntries = sortedEntries(cats).slice(0, 14);
  const catMax = catEntries.length ? catEntries[0][1] : 0;
  const catPanel = '<div class="panel census" style="margin-bottom:22px">' +
    '<div class="ptitle">Where the gold sits &mdash; listed value by market</div>' +
    '<p class="hint" style="margin-top:0">Quantity listed &times; unit price, summed per market.</p>' +
    (catEntries.length
      ? barChart(catEntries, catMax, () => "var(--gold)", (k) => k.toUpperCase(), (v) => bigGold(v))
      : '<p class="hint">No market data yet.</p>') + "</div>";

  const tiles =
    '<div class="tile"><div class="k">Items listed</div><div class="v">' + items.length.toLocaleString() +
      '</div><div class="s">distinct items seen</div></div>' +
    '<div class="tile"><div class="k">Listed value</div><div class="v">' + esc(bigGold(totalValue)) +
      '</div><div class="s">asking prices, not sales</div></div>' +
    '<div class="tile"><div class="k">Quantity</div><div class="v">' + totalQty.toLocaleString() +
      '</div><div class="s">units on the AH</div></div>' +
    '<div class="tile"><div class="k">Scanned</div><div class="v">' +
      esc(updatedAt ? String(updatedAt).slice(0, 10) : "&mdash;") +
      '</div><div class="s">' + esc(realms.join(" · ") || "no realm") + "</div></div>";

  const qtyTable = topTable("Most listed items", "By quantity sitting on the auction house.", byQty, branch, [
    { label: "Item", left: true, cell: (it, b) => itemLink(it, b) },
    { label: "Qty", cell: (it) => (it.q || 0).toLocaleString() },
    { label: "Unit", cell: (it) => esc(money(it.asp)) },
  ]);
  const priceTable = topTable("Most expensive items", "Highest unit asking price among items currently listed.", byPrice, branch, [
    { label: "Item", left: true, cell: (it, b) => itemLink(it, b) },
    { label: "Unit", cell: (it) => esc(money(it.asp)) },
    { label: "Qty", cell: (it) => (it.q || 0).toLocaleString() },
  ]);
  const valueTable = topTable("Deepest markets", "Single items holding the most listed value.", byValue, branch, [
    { label: "Item", left: true, cell: (it, b) => itemLink(it, b) },
    { label: "Listed value", cell: (it) => esc(bigGold(it.mv)) },
    { label: "Qty", cell: (it) => (it.q || 0).toLocaleString() },
  ]);

  const body = items.length
    ? catPanel + '<div class="mktgrid">' + qtyTable + priceTable + "</div>" + valueTable
    : '<div class="panel"><p class="hint">No auction data uploaded yet for these realms. In game, run /ml scan at the auction house, /reload, then upload with <code>upload-realm.ps1 -Flavor classic-beta</code>.</p></div>';

  const nav = opts.nav || "";
  return `<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>WoW Forever auction house — MarketLens</title>
<meta name="description" content="High-level auction house overview for the WoW Forever beta realms: biggest markets, most listed items, and the most expensive listings.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Press+Start+2P&family=VT323&display=swap">
<link rel="stylesheet" href="${esc(opts.stylesheet || "style.css")}">
<style>${CENSUS_STYLE}
${MARKET_STYLE}
</style>
</head><body>
<div class="crt" aria-hidden="true"></div>
<div class="wrap">
  ${nav}
  <header class="ihead">
    <h1 class="iname">WoW Forever &mdash; Auction House</h1>
    <div class="itag">Beta realms &middot; observed listings &middot; scanned in game with MarketLens</div>
    ${opts.note ? '<div class="itag" style="margin-top:10px;line-height:1.6">' + esc(opts.note) + "</div>" : ""}
  </header>
  <section class="tiles">${tiles}</section>
  ${body}
  <p class="src">
    An auction house scan observes <b>listings</b>, not sales. Unit price is the weighted median buyout
    asked for an item, and listed value is quantity &times; that price &mdash; what sellers want, not what anyone paid.<br>
    Items a scan could not name appear by id. Companion to the MarketLens addon.
  </p>
</div>
</body></html>`;
}
