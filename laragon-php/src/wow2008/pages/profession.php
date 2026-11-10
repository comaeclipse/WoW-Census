<?php
namespace Wow2008;
/**
 * Page: one WoW: Forever profession's auction-house overview.
 * Vars: $slug, $prof (PROFESSIONS entry), $cards (every trade, for the switcher), $data (generated, views: realm, faction, scanned, empty,
 *       p: {mats, crafted, recipes: rows [name, id, icon, qty, unit cu, value cu, change], *Count, units, value, items}),
 *       $realms, $factions (lists of [key, label]), $wowhead (item link prefix), $subtitle
 */
$gathering = $prof['role'] === 'Gathering';
?>
  <style>
    .pf-bar{display:flex;flex-wrap:wrap;gap:5px;margin:0 0 12px;padding:8px 10px;background:linear-gradient(#2a1d0e,#140d05);
      border:2px solid #000;outline:1px solid #8a7040;border-radius:3px}
    .pf-bar a{display:block;width:34px;height:34px;border:1px solid #000;outline:1px solid #6a5030;border-radius:3px;overflow:hidden;opacity:.72;transition:opacity .12s,outline-color .12s}
    .pf-bar a:hover{opacity:1;outline-color:#ffd75a}
    .pf-bar a[aria-current]{opacity:1;outline:2px solid #ffd75a;box-shadow:0 0 8px rgba(255,215,90,.6)}
    .pf-bar img{display:block;width:100%;height:100%}
    .pf-hero{display:grid;grid-template-columns:auto 1fr;gap:16px;align-items:center;margin-bottom:14px;padding:14px 16px;
      background:radial-gradient(ellipse at 20% 0%,rgba(255,190,80,.22),transparent 60%),linear-gradient(#2c1c0c,#0e0904);
      border:2px solid #3a2608;outline:1px solid #b08a40}
    .pf-hero .emb{width:76px;height:76px;border:3px ridge #b08a40;box-shadow:0 3px 12px #000;background:#000}
    .pf-hero .emb img{display:block;width:100%;height:100%}
    .pf-hero h1{margin:0;font:900 30px/1.05 'Cinzel Decorative',serif;color:var(--gold-lt,#ffd75a);text-shadow:0 0 8px rgba(255,170,0,.4),0 3px 3px #000}
    .pf-hero .kick{font:700 11px Tahoma,Arial,sans-serif;letter-spacing:2px;color:#e8c070;text-transform:uppercase;text-shadow:0 1px 2px #000}
    .pf-hero .intro{margin:5px 0 0;font-size:12px;color:#eee;text-shadow:0 1px 2px #000;max-width:600px}
    .pf-sort{display:flex;margin-left:auto}
    .pf-sort button{font:700 9px Tahoma,Arial,sans-serif;padding:2px 7px;cursor:pointer;color:#e8dcc0;background:linear-gradient(#4a3a20,#221a0c);border:1px solid #000;margin-left:-1px}
    .pf-sort button.on{background:linear-gradient(#ffd75a,#b07a00);color:#2a1200}
    .ahwin .wt .pf-sort + small{margin-left:8px}
    .pf-more{display:block;width:100%;padding:5px;border:0;border-top:1px solid #000;cursor:pointer;font:700 10px Tahoma,Arial,sans-serif;color:#ffd75a;background:linear-gradient(#2a2418,#14100a)}
    .pf-more:hover{color:#fff}
    .pf-empty{padding:14px 10px;color:#9a8a6a;font-size:11px}
    .report .money{color:#3a1a00;text-shadow:none}
    @media (max-width:600px){.pf-hero{grid-template-columns:1fr}.pf-hero .emb{width:56px;height:56px}.pf-hero h1{font-size:24px}.ahwin .wt{flex-wrap:wrap}.ahwin .wt small{display:none}}
  </style>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Auction House', 'url' => url('auction-house')], ['label' => 'Professions', 'url' => \profession_path()], ['label' => $prof['name']]]]) ?>

    <nav class="pf-bar" aria-label="Professions">
<?php foreach ($cards as $s => $c): ?>
      <a href="<?= e($c['url']) ?>" data-slug="<?= e($s) ?>" title="<?= e($c['name']) ?>" aria-label="<?= e($c['name']) ?>"<?= $s === $slug ? ' aria-current="page"' : '' ?>><img src="/assets/images/wow/icons/large/<?= e($c['icon']) ?>.jpg" alt="" width="34" height="34"></a>
<?php endforeach; ?>
    </nav>

    <div class="pf-hero">
      <div class="emb"><img src="/assets/images/wow/icons/large/<?= e($prof['icon']) ?>.jpg" alt="" width="76" height="76"></div>
      <div>
        <div class="kick"><?= e($prof['role'] === 'Secondary' ? 'Secondary skill' : $prof['role'] . ' profession') ?> · <?= e($subtitle) ?></div>
        <h1><?= e($prof['name']) ?></h1>
        <p class="intro"><?= e($prof['blurb']) ?></p>
      </div>
    </div>

    <div class="ahfilters">
      <span class="lbl">Realm</span>
      <div class="seg" id="pfRealm"></div>
      <span class="lbl" style="margin-left:8px">Faction</span>
      <div class="seg" id="pfFac"></div>
      <span class="lbl" id="pfstamp" style="margin-left:auto;text-transform:none;font-weight:normal"></span>
    </div>

    <div id="pfBody"></div>

    <div class="source">Auction data from in-game scans · asking prices on listed auctions, not completed sales · crafted goods are items the scan saw as made by <?= e($prof['name']) ?><?= $gathering ? '' : '; recipes are sorted by their name' ?> · click an item for its price history</div>
  </main>

<?php start_section('scripts') ?>
<script>
const PF = <?= js($data) ?>;
const REALMS = <?= js($realms) ?>;
const FACTIONS = <?= js($factions) ?>;
const PROF = <?= js(['name' => $prof['name'], 'gathering' => $gathering, 'slug' => $slug]) ?>;
const CARDS = <?= js($cards) ?>;
const view = (r, f) => PF.views.find(v => v.realm === r && v.faction === f);
const hasData = v => v && !v.empty;
const SHOW = 15;

function money(cu) {
  const g = Math.floor(cu / 10000), s = Math.floor(cu / 100) % 100, c = cu % 100;
  const parts = [];
  if (g) parts.push(`<span>${fmt(g)}<i class="g"></i></span>`);
  if (s || (g && c)) parts.push(`<span>${s}<i class="s"></i></span>`);
  if (c && !g) parts.push(`<span>${c}<i class="c"></i></span>`);
  if (!parts.length) parts.push(`<span>0<i class="c"></i></span>`);
  return `<span class="money">${parts.join('')}</span>`;
}
const goldShort = g => g >= 1e6 ? (g / 1e6).toFixed(1) + 'M' : g >= 1e3 ? Math.round(g / 1e3) + 'k' : fmt(g);
const goldMoney = cu => `<span class="money"><span>${goldShort(Math.floor(cu / 10000))}<i class="g"></i></span></span>`;
function chg(p) {
  if (p == null) return '<span class="chg none" title="No earlier scan to compare">—</span>';
  if (p === 'NEW') return '<span class="chg up" title="Not listed in the previous scan">NEW</span>';
  if (p === 0) return '<span class="chg flat">0%</span>';
  return `<span class="chg ${p > 0 ? 'up' : 'dn'}">${p > 0 ? '+' : ''}${p}%</span>`;
}
const itemLink = r => `<span class="ic">${wowIcon(r[2] || 'inv_misc_questionmark', esc(r[0][0]))}</span>` +
  (r[1] ? `<a href="<?= e(\forever_item_path('')) ?>${r[1]}" title="Price history for ${esc(r[0])}">${esc(r[0])}</a>` : esc(r[0]));

const params = new URLSearchParams(location.search);
let realm = REALMS.some(r => r[0] === params.get('realm')) ? params.get('realm') : REALMS[0][0];
let fac = FACTIONS.some(f => f[0] === params.get('faction')) ? params.get('faction') : FACTIONS[0][0];
// Per table: sort key (3 qty, 4 unit, 5 value) and whether every row is shown.
const state = { mats: { k: 3, all: false }, crafted: { k: 3, all: false }, recipes: { k: 3, all: false } };

function renderFilters() {
  const btn = (attr, k, l, on, ok) => `<button data-${attr}="${esc(k)}" class="${on ? 'on' : ''} ${ok ? '' : 'nodata'}" ${ok ? '' : 'title="No listings for this trade yet"'}>${esc(l)}</button>`;
  document.getElementById('pfRealm').innerHTML = REALMS.length > 6
    ? `<select class="realm-pick" aria-label="Realm">${REALMS.map(([k, l]) => `<option value="${esc(k)}"${k === realm ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>`
    : REALMS.map(([k, l]) => btn('r', k, l, k === realm, FACTIONS.some(([f]) => hasData(view(k, f))))).join('');
  document.getElementById('pfFac').innerHTML = FACTIONS.map(([k, l]) => btn('f', k, l, k === fac, hasData(view(realm, k)))).join('');
}
const pickRealm = r => { realm = r; if (!hasData(view(realm, fac))) fac = (FACTIONS.find(([f]) => hasData(view(realm, f))) || ['all'])[0]; render(); };
document.getElementById('pfRealm').addEventListener('click', e => { if (e.target.dataset.r) pickRealm(e.target.dataset.r); });
document.getElementById('pfRealm').addEventListener('change', e => pickRealm(e.target.value));
document.getElementById('pfFac').addEventListener('click', e => { if (e.target.dataset.f) { fac = e.target.dataset.f; render(); } });
document.getElementById('pfBody').addEventListener('click', e => {
  const b = e.target.closest('button[data-t]'); if (!b) return;
  const st = state[b.dataset.t];
  if (b.dataset.k) st.k = +b.dataset.k; else st.all = !st.all;
  render();
});

function table(key, title, sub, v) {
  const p = v.p, st = state[key];
  const rows = [...p[key]].sort((a, b) => b[st.k] - a[st.k] || a[0].localeCompare(b[0]));
  const shown = st.all ? rows : rows.slice(0, SHOW);
  const max = Math.max(1, ...rows.map(r => r[3]));
  const sorts = [[3, 'Qty'], [4, 'Price'], [5, 'Value']].map(([k, l]) =>
    `<button data-t="${key}" data-k="${k}" class="${st.k === k ? 'on' : ''}">${l}</button>`).join('');
  const total = p[key + 'Count'];
  const more = rows.length > SHOW ? `<button class="pf-more" data-t="${key}">${st.all ? 'Show top ' + SHOW : `Show all ${fmt(rows.length)}${total > rows.length ? ` of ${fmt(total)}` : ''}`}</button>` : '';
  return `<div class="ahwin"><div class="wt">${title}<span class="pf-sort" role="group" aria-label="Sort ${esc(title)}">${sorts}</span><small>${sub}</small></div>
    ${rows.length ? `<table class="ahtable"><thead><tr><th class="rk">#</th><th>Item</th><th class="num">Qty</th><th class="num">Unit</th><th class="num">Change</th></tr></thead>
    <tbody>${shown.map((r, i) => `<tr><td class="rk">${i + 1}</td><td>${itemLink(r)}</td>
      <td class="num">${fmt(r[3])}<span class="qbar"><i data-w="${r[3] / max * 100}%"></i></span></td>
      <td class="num">${money(r[4])}</td><td class="num">${chg(r[6])}</td></tr>`).join('')}</tbody></table>${more}`
      : `<div class="pf-empty">Nothing listed in this scan.</div>`}</div>`;
}

function render() {
  renderFilters();
  const v = view(realm, fac), body = document.getElementById('pfBody');
  const realmName = REALMS.find(r => r[0] === realm)[1], facName = FACTIONS.find(f => f[0] === fac)[1];
  const q = (realm === 'all' && fac === 'all') ? '' : `?realm=${encodeURIComponent(realm)}&faction=${encodeURIComponent(fac)}`;
  document.querySelectorAll('.pf-bar a').forEach(a => a.href = CARDS[a.dataset.slug].url + q);
  document.getElementById('pfstamp').textContent = hasData(v) ? `Scanned ${v.scanned}` : '';
  if (!hasData(v)) {
    body.innerHTML = `<div class="nodata-box"><b>Nothing listed yet</b>
      There's no ${esc(PROF.name)} listing in the scans for ${esc(realmName)}${fac === 'all' ? '' : ' · ' + esc(facName)} yet. Try another realm or faction.</div>`;
    return;
  }
  const p = v.p;
  const matLabel = PROF.gathering ? 'Gathered Goods' : 'Materials';
  const top = (rows, k) => [...rows].sort((a, b) => b[k] - a[k])[0];
  const topMat = top(p.mats, 3), topCraft = top(p.crafted, 5), topRecipe = top(p.recipes, 4);
  const sentences = [
    `The scan on <b>${v.scanned}</b> found <b>${fmt(p.items)}</b> ${esc(PROF.name)} items listed on the ${esc(realmName)}${fac === 'all' ? '' : ' ' + esc(facName)} auction house: <b>${fmt(p.units)}</b> units asking about <b>${goldShort(Math.floor(p.value / 10000))} gold</b> in all.`,
    topMat ? `The most plentiful ${PROF.gathering ? 'gathered good' : 'material'} is <b>${esc(topMat[0])}</b>, with ${fmt(topMat[3])} up for sale at ${money(topMat[4])} each.` : '',
    topCraft ? `Among crafted goods, <b>${esc(topCraft[0])}</b> holds the most listed value (${goldMoney(topCraft[5])}).` : '',
    topRecipe ? `The priciest recipe listed is <b>${esc(topRecipe[0])}</b> at ${money(topRecipe[4])}.` : '',
  ].filter(Boolean);

  body.innerHTML = `
    <div class="tiles">
      <div class="stat"><span>Items listed</span><b>${fmt(p.items)}</b><em>across this trade</em></div>
      <div class="stat"><span>${matLabel}</span><b>${fmt(p.matsCount)}</b><em>different items</em></div>
      <div class="stat"><span>${PROF.gathering && !p.craftedCount ? 'Recipes' : 'Crafted goods'}</span><b>${fmt(PROF.gathering && !p.craftedCount ? p.recipesCount : p.craftedCount)}</b><em>different items</em></div>
      <div class="stat"><span>Asking value</span><b>${goldShort(Math.floor(p.value / 10000))} g</b><em>quantity × unit price</em></div>
    </div>

    <section class="section">
      <div class="post-hd"><span class="ico g"></span><h2>${esc(PROF.name)} Market Report</h2><div class="sub">${esc(realmName)} · ${esc(facName)}</div></div>
      <div class="section-bd">
        <div class="report">
          <svg viewBox="0 0 64 64" aria-hidden="true">
            <defs><radialGradient id="pf-c" cx="35%" cy="30%" r="70%"><stop offset="0" stop-color="#fff6b0"/><stop offset=".55" stop-color="#e0a810"/><stop offset="1" stop-color="#6a4400"/></radialGradient></defs>
            <rect x="8" y="6" width="40" height="52" rx="2" fill="#f8ecc8" stroke="#7a5a20" stroke-width="2"/>
            <g stroke="#9c7c42" stroke-width="2"><line x1="14" y1="16" x2="42" y2="16"/><line x1="14" y1="24" x2="42" y2="24"/><line x1="14" y1="32" x2="36" y2="32"/><line x1="14" y1="40" x2="40" y2="40"/></g>
            <circle cx="46" cy="46" r="13" fill="url(#pf-c)" stroke="#5a3a00" stroke-width="1.5"/>
            <text x="46" y="51" text-anchor="middle" font-family="Tahoma, Arial, sans-serif" font-weight="900" font-size="13" fill="#5a3a00">g</text>
          </svg>
          <div>
            <p>${sentences.join(' ')}</p>
            <p class="caveat">These figures describe what's listed and at what asking price. They don't show what actually sold.</p>
          </div>
        </div>
      </div>
    </section>

    <div class="ahgrid">
      <div class="wide">${table('mats', matLabel, PROF.gathering ? 'what this trade brings to market' : 'reagents this trade works with', v)}</div>
      ${p.craftedCount || !PROF.gathering ? `<div class="wide">${table('crafted', 'Crafted Goods', 'made by ' + esc(PROF.name.toLowerCase()), v)}</div>` : ''}
      ${p.recipesCount || !PROF.gathering ? `<div class="wide">${table('recipes', 'Recipes', 'patterns, plans and formulas on sale', v)}</div>` : ''}
    </div>
    <p class="tfoot">"Change" compares listed supply with the previous scan. Sort buttons rank by quantity, unit price or total listed value.</p>`;
  grow(body);
}
render();
</script>
<?php end_section() ?>
