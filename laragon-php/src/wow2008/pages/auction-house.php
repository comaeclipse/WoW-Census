<?php
namespace Wow2008;
/**
 * Page: Auction House
 * Vars: $data (array, AH_DATA: generated, views), $realms, $factions (lists of [key, label]), $wowhead (item link prefix), $subtitle (string)
 */
?>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Realm Census', 'url' => url('census')], ['label' => 'Auction House']]]) ?>

    <?= component('banner', ['art' => 'auction-house', 'title' => 'Auction House', 'subtitle' => $subtitle . ' · MARKET WATCH', 'subtitle_color' => '#ffe8a0', 'stamp_id' => 'ahstamp']) ?>

    <div class="ahfilters">
      <span class="lbl">Realm</span>
      <div class="seg" id="ahRealm"></div>
      <span class="lbl" style="margin-left:8px">Faction</span>
      <div class="seg" id="ahFac"></div>
    </div>

    <div id="ahBody"></div>

    <div class="source">Auction data from in-game scans · asking prices on listed auctions, not completed sales · <?= !empty($itemBase) ? 'click an item for its price history' : 'item names link to Wowhead' ?></div>
  </main>

<?php start_section('scripts') ?>
<script>
const AH = <?= js($data) ?>;
const REALMS = <?= js($realms) ?>;
const FACTIONS = <?= js($factions) ?>;
const view = (r, f) => AH.views.find(v => v.realm === r && v.faction === f);
const hasData = v => v && !v.empty;

// copper -> coin display
function money(cu) {
  const g = Math.floor(cu / 10000), s = Math.floor(cu / 100) % 100, c = cu % 100;
  const parts = [];
  if (g) parts.push(`<span>${fmt(g)}<i class="g"></i></span>`);
  if (s || (g && c)) parts.push(`<span>${s}<i class="s"></i></span>`);
  if (c && !g) parts.push(`<span>${c}<i class="c"></i></span>`);
  if (!parts.length) parts.push(`<span>0<i class="c"></i></span>`);
  return `<span class="money">${parts.join('')}</span>`;
}
// gold amount -> short label, e.g. 1.0M g, 835k g
const goldShort = g => g >= 1e6 ? (g / 1e6).toFixed(1) + 'M' : g >= 1e3 ? Math.round(g / 1e3) + 'k' : fmt(g);
const goldMoney = g => `<span class="money"><span>${goldShort(g)}<i class="g"></i></span></span>`;
function chg(p) {
  if (p == null) return '<span class="chg none" title="No earlier scan to compare">—</span>';
  if (p === 'NEW') return '<span class="chg up" title="Not listed in the previous scan">NEW</span>';
  if (p === 0) return '<span class="chg flat">0%</span>';
  return `<span class="chg ${p > 0 ? 'up' : 'dn'}">${p > 0 ? '+' : ''}${p}%</span>`;
}
function badge(name, icon) {
  return `<span class="ic">${wowIcon(icon || 'inv_misc_questionmark', esc(name[0]))}</span>`;
}
const itemLink = it => it.id
  ? <?php if (!empty($itemBase)): ?>`${badge(it.name, it.icon)}<a href="<?= e($itemBase) ?>${it.id}" title="Price history for ${esc(it.name)}">${esc(it.name)}</a>`<?php else: ?>`${badge(it.name, it.icon)}<a href="<?= e($wowhead) ?>${it.id}" target="_blank" rel="noopener">${esc(it.name)}</a>`<?php endif ?>
  : `${badge(it.name, it.icon)}${esc(it.name)}`;
const titleCase = s => s.toLowerCase().replace(/(^|[\s&])\w/g, m => m.toUpperCase());

let realm = REALMS[0][0], fac = FACTIONS[0][0];

// More realms than fit in a row of buttons (Retail): pick them from a list instead.
const manyRealms = REALMS.length > 6;
function renderFilters() {
  document.getElementById('ahRealm').innerHTML = manyRealms
    ? `<select class="realm-pick" aria-label="Realm">${REALMS.map(([k, l]) => {
        const any = FACTIONS.some(([f]) => hasData(view(k, f)));
        return `<option value="${esc(k)}"${k === realm ? ' selected' : ''}>${esc(l)}${any ? '' : ' (no scans yet)'}</option>`;
      }).join('')}</select>`
    : REALMS.map(([k, l]) => {
    const any = FACTIONS.some(([f]) => hasData(view(k, f)));
    return `<button data-r="${k}" class="${k === realm ? 'on' : ''} ${any ? '' : 'nodata'}" ${any ? '' : 'title="No auction scans yet"'}>${l}</button>`;
  }).join('');
  document.getElementById('ahFac').innerHTML = FACTIONS.map(([k, l]) => {
    const ok = hasData(view(realm, k));
    return `<button data-f="${k}" class="${k === fac ? 'on' : ''} ${ok ? '' : 'nodata'}" ${ok ? '' : 'title="No auction scans yet"'}>${l}</button>`;
  }).join('');
}
const pickRealm = r => {
  realm = r;
  // keep the faction if it has data on this realm, otherwise fall back to the first one that does
  if (!hasData(view(realm, fac))) fac = (FACTIONS.find(([f]) => hasData(view(realm, f))) || ['all'])[0];
  render();
};
document.getElementById('ahRealm').addEventListener('click', e => { if (e.target.dataset.r) pickRealm(e.target.dataset.r); });
document.getElementById('ahRealm').addEventListener('change', e => pickRealm(e.target.value));
document.getElementById('ahFac').addEventListener('click', e => {
  const f = e.target.dataset.f; if (!f) return;
  fac = f; render();
});

function table(title, sub, cols, rows) {
  return `<div class="ahwin"><div class="wt">${title}<small>${sub}</small></div>
    <table class="ahtable"><thead><tr><th class="rk">#</th>${cols.map(c => `<th class="${c.num ? 'num' : ''}">${c.h}</th>`).join('')}</tr></thead>
    <tbody>${rows.map((r, i) => `<tr><td class="rk">${i + 1}</td>${cols.map(c => `<td class="${c.num ? 'num' : ''}">${c.v(r)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
}

function render() {
  renderFilters();
  const v = view(realm, fac), body = document.getElementById('ahBody');
  const realmName = REALMS.find(r => r[0] === realm)[1], facName = FACTIONS.find(f => f[0] === fac)[1];
  document.getElementById('ahstamp').textContent = hasData(v)
    ? `Scanned: ${v.scanned} · Page generated: ${AH.generated}` : `Page generated: ${AH.generated}`;

  if (!hasData(v)) {
    body.innerHTML = `<div class="nodata-box"><b>No auctions scanned yet</b>
      There's no auction house scan for ${esc(realmName)}${fac === 'all' ? '' : ' · ' + esc(facName)} yet. Try another realm or faction.</div>`;
    return;
  }

  // Aggregate-only scans (Retail) carry quantities and minimum prices, not listed value.
  const agg = v.aggregate, mkt = g => agg ? `${fmt(g)} units` : `${goldShort(g)} g`;
  const top3 = v.markets.slice(0, 3).map(([n, g]) => `${titleCase(n).toLowerCase()} (${mkt(g)})`);
  const perUnit = v.value * 10000 / v.units;
  const maxQty = v.listed[0].qty, maxMkt = v.markets[0][1];

  body.innerHTML = `
    <div class="tiles">
      <div class="stat"><span>Units listed</span><b>${fmt(v.units)}</b><em>sitting on the auction house</em></div>
      <div class="stat"><span>Different items</span><b>${fmt(v.items)}</b><em>with at least one listing</em></div>
      ${agg ? `<div class="stat"><span>Price coverage</span><b>Minimum</b><em>aggregate browse summaries</em></div>`
        : `<div class="stat"><span>Asking value</span><b>${goldShort(v.value)} g</b><em>quantity × unit price</em></div>`}
      <div class="stat"><span>Scanned</span><b style="font-size:17px;line-height:26px">${v.scanned}</b><em>${esc(realmName)} · ${esc(facName)}</em></div>
    </div>

    <section class="section">
      <div class="post-hd"><span class="ico g"></span><h2>Market Report</h2><div class="sub">${esc(realmName)} · ${esc(facName)}</div></div>
      <div class="section-bd">
        <div class="report">
          <svg viewBox="0 0 64 64" aria-hidden="true">
            <defs><radialGradient id="rp-c" cx="35%" cy="30%" r="70%"><stop offset="0" stop-color="#fff6b0"/><stop offset=".55" stop-color="#e0a810"/><stop offset="1" stop-color="#6a4400"/></radialGradient></defs>
            <rect x="8" y="6" width="40" height="52" rx="2" fill="#f8ecc8" stroke="#7a5a20" stroke-width="2"/>
            <g stroke="#9c7c42" stroke-width="2"><line x1="14" y1="16" x2="42" y2="16"/><line x1="14" y1="24" x2="42" y2="24"/><line x1="14" y1="32" x2="36" y2="32"/><line x1="14" y1="40" x2="40" y2="40"/></g>
            <circle cx="46" cy="46" r="13" fill="url(#rp-c)" stroke="#5a3a00" stroke-width="1.5"/>
            <text x="46" y="51" text-anchor="middle" font-family="Tahoma, Arial, sans-serif" font-weight="900" font-size="13" fill="#5a3a00">g</text>
          </svg>
          <div>
            <p>The scan on <b>${v.scanned}</b> counted <b>${fmt(v.units)}</b> units across <b>${fmt(v.items)}</b> different items${agg
              ? `. These scans record each item's quantity and lowest asking price, not every listing, so there's no total value to report.`
              : `, with a combined asking value of about <b>${goldShort(v.value)} gold</b>. That works out to roughly ${money(Math.round(perUnit))} per unit on average.`}</p>
            <p>Most of that ${agg ? 'supply' : 'gold'} is in ${top3.slice(0, -1).join(', ')} and ${top3[top3.length - 1]}.
              <b>${esc(v.listed[0].name)}</b> is the most-listed item, with ${fmt(v.listed[0].qty)} units up for sale.</p>
            ${v.breakouts.length ? `<p>Biggest jumps in supply since the previous scan: ${v.breakouts.map(([n, p]) => `<b>${esc(n)}</b> <span class="up-t">+${p}%</span>`).join(' and ')}.</p>` : ''}
            <p class="caveat">These figures describe what's listed and at what asking price. They don't show what actually sold.</p>
          </div>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="post-hd"><span class="ico"></span><h2>${agg ? 'Where Supply Sits' : 'Where the Gold Sits'}</h2><div class="sub">${agg ? 'Listed quantity per market' : 'Listed value per market (quantity × unit price)'}</div></div>
      <div class="section-bd">
        <div class="panel"><div class="bars mkts">${v.markets.map(([n, g]) => `
          <div class="bar" data-tip="${esc(titleCase(n))}|${mkt(g)} listed|${(g / (agg ? v.units : v.value) * 100).toFixed(1)}% of all listed ${agg ? 'units' : 'value'}">
            <span class="lbl">${esc(titleCase(n))}</span>
            <div class="track"><div class="fill" data-w="${g / maxMkt * 100}%" style="background:linear-gradient(#ffe070,#b07a00)"></div></div>
            <span class="val">${mkt(g)}</span></div>`).join('')}</div></div>
      </div>
    </section>

    <div class="ahgrid">
      ${table('Most Listed', 'by quantity on the AH', [
        { h: 'Item', v: r => itemLink(r) },
        { h: 'Qty', num: true, v: r => `${fmt(r.qty)}<span class="qbar"><i data-w="${r.qty / maxQty * 100}%"></i></span>` },
        { h: 'Change', num: true, v: r => chg(r.change) },
        { h: 'Unit', num: true, v: r => money(r.unit) }
      ], v.listed)}
      ${table('Most Expensive', 'highest unit asking price', [
        { h: 'Item', v: r => itemLink(r) },
        { h: 'Unit', num: true, v: r => money(r.unit) },
        { h: 'Qty', num: true, v: r => fmt(r.qty) },
        { h: 'Change', num: true, v: r => chg(r.change) }
      ], v.expensive)}
      ${agg ? '' : `<div class="wide">${table('Deepest Markets', 'single items holding the most listed value', [
        { h: 'Item', v: r => itemLink(r) },
        { h: 'Listed Value', num: true, v: r => goldMoney(r.value) },
        { h: 'Qty', num: true, v: r => fmt(r.qty) },
        { h: 'Change', num: true, v: r => chg(r.change) }
      ], v.deepest)}</div>`}
      ${v.excluded && v.excluded.length ? `<div class="wide">${table('Excluded: Joke Listings', `priced above ${goldShort(v.excludeAbove)} g a unit, so left out of the totals and lists above`, [
        { h: 'Item', v: r => itemLink(r) },
        { h: 'Unit', num: true, v: r => money(r.unit) },
        { h: 'Qty', num: true, v: r => fmt(r.qty) },
        { h: 'Change', num: true, v: r => chg(r.change) }
      ], v.excluded)}</div>` : ''}
    </div>
    <p class="tfoot">"Change" compares listed supply with the previous scan. A dash means there's no earlier scan to compare against.</p>`;

  requestAnimationFrame(() => requestAnimationFrame(() =>
    body.querySelectorAll('[data-w]').forEach(el => el.style.width = el.dataset.w)));
}
render();

</script>
<?php end_section() ?>
