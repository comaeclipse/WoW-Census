// Auction-house overview for the static wowcensus bundle: a high-level read of
// one or more realm item snapshots -- what the market is worth, which markets
// hold that value, what is listed in bulk, and what the expensive end looks
// like. Styled like the census page and rendered from the same helpers.
//
// Only what a scan actually observes is shown. Listings are not sales, so
// "popular" here means listed in quantity, never sold.

import { esc, realmName, barChart, sortedEntries, chipRow, CENSUS_STYLE, TOGGLE_SCRIPT } from "./census.mjs";

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

// Change in listed quantity since the preceding scan. A zero-to-positive move
// is new supply rather than an infinite percentage; no prior snapshot stays
// explicitly unavailable instead of being rendered as 0%.
function qtyChange(it) {
  if (it.pq == null) return '<span class="mu">&mdash;</span>';
  if (it.pq === 0) return (it.q || 0) > 0 ? '<span class="gr">NEW</span>' : '<span class="mu">0%</span>';
  const pct = Math.round(((it.q || 0) - it.pq) / it.pq * 100);
  const cls = pct > 0 ? "gr" : pct < 0 ? "rd" : "mu";
  return '<span class="' + cls + '">' + (pct > 0 ? "+" : "") + pct.toLocaleString() + "%</span>";
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
  @media (max-width:820px){.mktgrid{grid-template-columns:1fr}}
  .vchips{margin-bottom:18px}`;

// Someone listing a Moon Harvest Pumpkin at 999,999g is not a market -- one
// such listing was 99% of a faction's entire listed value. Prices that absurd
// are excluded from the page's totals and top lists, but never silently: the
// page names what it dropped.
//
// The cutoff is data-driven rather than a fixed gold number, since a healthy
// economy's ceiling rises over time: 50x the 99th-percentile unit price across
// every realm scanned, floored at 500g so a thin market cannot produce a cutoff
// low enough to drop a genuine epic. On the beta's current data p99 is 8g and
// real listings top out near 50g, so the floor governs and the gap to the joke
// listing is four orders of magnitude -- nothing borderline is at stake.
const JOKE_FLOOR = 500 * 10000; // copper
export function jokePriceThreshold(items) {
  const prices = (items || []).filter((it) => (it.q || 0) > 0 && (it.asp || 0) > 0)
    .map((it) => it.asp).sort((a, b) => a - b);
  if (!prices.length) return Infinity;
  const p99 = prices[Math.floor(0.99 * (prices.length - 1))];
  return Math.max(50 * p99, JOKE_FLOOR);
}

// One view's worth of markup: the tiles and every panel under them.
// snapshot: { items, realms, updatedAt, branch } -- items already merged across
// that view's realms, each { id, name, q, pq, mv, asp, cat }.
function renderMarketView(snapshot, threshold) {
  const all = (snapshot && snapshot.items) || [];
  const aggregateOnly = snapshot && snapshot.aggregateOnly;
  const joke = aggregateOnly ? [] : all.filter((it) => (it.asp || 0) > threshold)
    .sort((a, b) => (b.asp || 0) - (a.asp || 0));
  const items = joke.length ? all.filter((it) => (it.asp || 0) <= threshold) : all;
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
    cats[k] = (cats[k] || 0) + (aggregateOnly ? (it.q || 0) : (it.mv || 0));
  }
  const catEntries = sortedEntries(cats).slice(0, 14);
  const catMax = catEntries.length ? catEntries[0][1] : 0;
  const catPanel = '<div class="panel census" style="margin-bottom:22px">' +
    '<div class="ptitle">' + (aggregateOnly
      ? 'Where supply sits &mdash; listed quantity by market'
      : 'Where the gold sits &mdash; listed value by market') + '</div>' +
    '<p class="hint" style="margin-top:0">' + (aggregateOnly
      ? 'Total units currently listed, summed per market.'
      : 'Quantity listed &times; unit price, summed per market.') + '</p>' +
    (catEntries.length
      ? barChart(catEntries, catMax, () => "var(--gold)", (k) => k.toUpperCase(),
          (v) => aggregateOnly ? v.toLocaleString() + " units" : bigGold(v))
      : '<p class="hint">No market data yet.</p>') + "</div>";

  const tiles =
    '<div class="tile"><div class="k">Items listed</div><div class="v">' + items.length.toLocaleString() +
      '</div><div class="s">distinct items seen</div></div>' +
    (aggregateOnly
      ? '<div class="tile"><div class="k">Price coverage</div><div class="v">MINIMUM</div><div class="s">aggregate browse summaries</div></div>'
      : '<div class="tile"><div class="k">Listed value</div><div class="v">' + esc(bigGold(totalValue)) +
        '</div><div class="s">asking prices, not sales</div></div>') +
    '<div class="tile"><div class="k">Quantity</div><div class="v">' + totalQty.toLocaleString() +
      '</div><div class="s">units on the AH</div></div>' +
    '<div class="tile"><div class="k">Scanned</div><div class="v">' +
      (updatedAt ? esc(String(updatedAt).slice(0, 10)) : "&mdash;") +
      '</div><div class="s">' + esc(realms.map(realmName).join(" · ") || "no realm") + "</div></div>";

  const qtyTable = topTable("Most listed items", "By quantity sitting on the auction house.", byQty, branch, [
    { label: "Item", left: true, cell: (it, b) => itemLink(it, b) },
    { label: "Qty", cell: (it) => (it.q || 0).toLocaleString() },
    { label: "Change", cell: (it) => qtyChange(it) },
    { label: aggregateOnly ? "Min unit" : "Unit", cell: (it) => esc(money(it.asp)) },
  ]);
  const priceTable = topTable(aggregateOnly ? "Highest minimum prices" : "Most expensive items",
    aggregateOnly ? "Highest minimum asking price in Retail's aggregate summary." : "Highest unit asking price among items currently listed.", byPrice, branch, [
    { label: "Item", left: true, cell: (it, b) => itemLink(it, b) },
    { label: aggregateOnly ? "Min unit" : "Unit", cell: (it) => esc(money(it.asp)) },
    { label: "Qty", cell: (it) => (it.q || 0).toLocaleString() },
    { label: "Change", cell: (it) => qtyChange(it) },
  ]);
  const valueTable = topTable("Deepest markets", "Single items holding the most listed value.", byValue, branch, [
    { label: "Item", left: true, cell: (it, b) => itemLink(it, b) },
    { label: "Listed value", cell: (it) => esc(bigGold(it.mv)) },
    { label: "Qty", cell: (it) => (it.q || 0).toLocaleString() },
    { label: "Change", cell: (it) => qtyChange(it) },
  ]);

  const body = items.length
    ? catPanel + '<div class="mktgrid">' + qtyTable + priceTable + "</div>" + (aggregateOnly ? "" : valueTable)
    : '<div class="panel"><p class="hint">No auction scan uploaded for ' +
      esc(realms.map(realmName).join(" · ") || "this faction") + ' yet. In game, run /ml scan at the auction house, ' +
      '/reload, then upload with <code>upload-realm.ps1 -Flavor ' + esc(snapshot.uploadFlavor || "classic-beta") + '</code>.</p></div>';

  const jokePanel = joke.length
    ? topTable("Excluded as joke listings",
        "Priced above " + bigGold(threshold) + " a unit — left out of the totals and lists above.",
        joke.slice(0, 10), branch, [
          { label: "Item", left: true, cell: (it, b) => itemLink(it, b) },
          { label: "Unit", cell: (it) => esc(money(it.asp)) },
          { label: "Qty", cell: (it) => (it.q || 0).toLocaleString() },
          { label: "Change", cell: (it) => qtyChange(it) },
        ])
    : "";

  return '<section class="tiles">' + tiles + "</section>" + body +
    (jokePanel ? '<div style="margin-top:22px">' + jokePanel + "</div>" : "");
}

// views: [{ realm, faction, snapshot }] -- one per realm x faction combination,
// including "all" on either axis. Every combination ships in the page and the
// chips only swap which is visible, so the bundle stays a static file with no
// fetching. dims: { realm: [{key,label}], faction: [{key,label}] }.
export function renderMarketHtml(views, dims = {}, opts = {}) {
  const list = (views || []).filter(Boolean);
  const realmOpts = dims.realm || [];
  const factionOpts = dims.faction || [];
  const activeRealm = realmOpts.length ? realmOpts[0].key : "all";
  const activeFaction = factionOpts.length ? factionOpts[0].key : "all";
  const chips = chipRow("realm", realmOpts, activeRealm) + chipRow("faction", factionOpts, activeFaction);

  // One threshold shared by every view -- computed from the widest item set, so
  // each realm and faction view judges prices by the same yardstick as All.
  const widest = list.reduce((best, v) =>
    ((v.snapshot && v.snapshot.items) || []).length > (best.items || []).length ? v.snapshot : best,
    { items: [] });
  const threshold = jokePriceThreshold(widest.items);
  const sections = list.map((v) => {
    const on = v.realm === activeRealm && v.faction === activeFaction;
    return '<div class="vpane" data-realm="' + esc(v.realm) + '" data-faction="' + esc(v.faction) + '"' +
      (on ? "" : " hidden") + ">" + renderMarketView(v.snapshot, threshold) + "</div>";
  }).join("");
  const script = chips ? TOGGLE_SCRIPT : "";

  const gameLabel = opts.gameLabel || "WoW Forever";
  const navigation = opts.nav && typeof opts.nav === "object" ? opts.nav : { games: opts.nav || "", pages: "" };
  const notes = (opts.notes || (opts.note ? [opts.note] : [])).map((note, i) =>
    '<div class="itag" style="' + (i ? "margin-top:4px;" : "margin-top:10px;") + 'line-height:1.6">' + esc(note) + "</div>").join("");
  const generatedNote = opts.generatedNote
    ? '<div class="itag" style="margin-top:16px;line-height:1.6">' + esc(opts.generatedNote) + "</div>"
    : "";
  const seoGameLabel = /^WoW\b/i.test(gameLabel) ? gameLabel : "WoW " + gameLabel;
  return `<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="google-site-verification" content="N0-ZNJyDo16jefYUGxfcAda_mKf7S2oATfRGWETdsHs">
<title>${esc(seoGameLabel)} auction house</title>
<meta name="description" content="${esc(seoGameLabel)} auction house snapshot with item supply, asking prices, listed value, and market changes from recent scans.">
${opts.canonical ? '<link rel="canonical" href="' + esc(opts.canonical) + '">\n<meta property="og:type" content="website">\n<meta property="og:title" content="' + esc(seoGameLabel) + ' auction house">\n<meta property="og:description" content="' + esc(seoGameLabel) + ' auction house snapshot with item supply, asking prices, listed value, and market changes from recent scans.">\n<meta property="og:url" content="' + esc(opts.canonical) + '">' : ""}
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96">
<link rel="icon" href="/favicon-96.png" type="image/png" sizes="96x96">
<link rel="preload" href="/fonts/press-start-2p-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/vt323-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/cinzel-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="${esc(opts.stylesheet || "style.css")}">
<style>${CENSUS_STYLE}
${MARKET_STYLE}
</style>
</head><body>
<div class="crt" aria-hidden="true"></div>
<div class="wrap">
  <header class="ihead">
    <div class="page-heading"><h1 class="iname">${esc(gameLabel)} &mdash; Auction House</h1>${navigation.games || ""}</div>
    ${notes}
  </header>
  <div class="page-controls">${navigation.pages || ""}${chips}</div>
  ${sections}
  ${generatedNote}
</div>
${script}
</body></html>`;
}
