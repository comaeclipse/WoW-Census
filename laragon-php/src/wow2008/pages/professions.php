<?php
namespace Wow2008;
/**
 * Page: Professions (WoW: Forever). One card per trade, linking to its market page.
 * Vars: $cards (slug => name, role, kind, icon, blurb, url), $data (generated, views: realm, faction, scanned, empty,
 *       p: slug => [items, units, gold, mats, crafted, recipes]), $realms, $factions (lists of [key, label]), $subtitle
 */
$groups = [
  'Crafting' => ['Crafting Professions', 'Turn raw goods into gear, potions and gadgets', 'g'],
  'Gathering' => ['Gathering Professions', 'Harvest the world for the crafters', ''],
  'Secondary' => ['Secondary Skills', 'Any character can learn all three', 'p'],
];
?>
  <style>
    .pf-intro{display:grid;grid-template-columns:72px 1fr;gap:14px;align-items:center;padding:10px 14px;margin-bottom:14px;
      background:linear-gradient(#fbe9b6,#e8d098);border:1px solid #9c7c42;box-shadow:inset 0 0 0 1px #fff3cc;font-size:12px;line-height:1.6;color:#3a1a00}
    .pf-intro p{margin:0}
    .pf-intro b{color:#5a2a00}
    .pf-intro .book{width:72px;height:72px}
    .pf-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
    .pf-card{position:relative;display:grid;grid-template-columns:52px 1fr;gap:4px 10px;padding:10px 10px 9px;text-decoration:none;color:#3a1a00;
      background:linear-gradient(#fff6d8,#ecd59c);border:1px solid #8a6a2a;border-radius:3px;text-align:left;
      box-shadow:inset 0 0 0 1px #fff7d8,0 1px 2px rgba(60,30,0,.35);transition:transform .12s,box-shadow .12s}
    .pf-card:hover{transform:translateY(-1px);box-shadow:inset 0 0 0 1px #fff7d8,0 0 0 2px #ffd75a,0 4px 10px rgba(60,30,0,.45)}
    .pf-card:hover h3{color:#a03a00}
    .pf-ic{grid-row:1 / span 3;width:52px;height:52px;border:2px solid #1a1206;outline:2px ridge #c8a050;border-radius:4px;overflow:hidden;background:#111;
      box-shadow:0 2px 4px rgba(0,0,0,.5)}
    .pf-ic img{display:block;width:100%;height:100%}
    .pf-card h3{margin:0;font:700 15px/1.1 'Cinzel',serif;color:#5a2a00;font-variant:small-caps}
    .pf-card .role{text-align:left;font:700 9px Tahoma,Arial,sans-serif;text-transform:uppercase;letter-spacing:.8px;color:#8a5a10}
    .pf-card .blurb{font-size:11px;line-height:1.4;color:#4a2a08}
    .pf-card .ledger{grid-column:1 / -1;display:flex;justify-content:space-between;gap:6px;margin-top:6px;padding-top:6px;border-top:1px dashed #b8955a;
      font:10px Tahoma,Arial,sans-serif;color:#6a4a20}
    .pf-card .ledger b{color:#3a1a00}
    .pf-card .go{font-weight:700;color:#7a1a00;white-space:nowrap}
    .pf-card.quiet .ledger b{color:#9a7a4a}
    .pf-board .trade a{display:inline-flex;align-items:center;gap:6px}
    .pf-board .trade img{width:18px;height:18px;border:1px solid #000;outline:1px solid #6a6050;border-radius:2px}
    @media (max-width:900px){.pf-cards{grid-template-columns:repeat(2,1fr)}}
    @media (max-width:560px){.pf-cards{grid-template-columns:1fr}.pf-intro{grid-template-columns:1fr}.pf-intro .book{display:none}.pf-board .qbar{display:none}}
  </style>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Auction House', 'url' => url('auction-house')], ['label' => 'Professions']]]) ?>

    <?= component('banner', ['art' => 'auction-house', 'title' => 'Professions', 'subtitle' => $subtitle . ' · TRADE SKILLS', 'subtitle_color' => '#ffe8a0', 'stamp_id' => 'pfstamp']) ?>

    <div class="pf-intro">
      <svg class="book" viewBox="0 0 64 64" aria-hidden="true">
        <defs><linearGradient id="pf-bk" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#a0381a"/><stop offset="1" stop-color="#4a1208"/></linearGradient></defs>
        <path d="M8 12c8-4 16-4 24 2v40c-8-6-16-6-24-2z" fill="#f8ecc8" stroke="#7a5a20" stroke-width="2"/>
        <path d="M56 12c-8-4-16-4-24 2v40c8-6 16-6 24-2z" fill="#f1e1b4" stroke="#7a5a20" stroke-width="2"/>
        <g stroke="#9c7c42" stroke-width="1.6"><line x1="13" y1="22" x2="27" y2="22"/><line x1="13" y1="28" x2="27" y2="28"/><line x1="13" y1="34" x2="24" y2="34"/>
          <line x1="37" y1="22" x2="51" y2="22"/><line x1="37" y1="28" x2="51" y2="28"/><line x1="37" y1="34" x2="48" y2="34"/></g>
        <path d="M4 52c10-4 20-4 28 3 8-7 18-7 28-3v4c-10-4-20-4-28 3-8-7-18-7-28-3z" fill="url(#pf-bk)"/>
      </svg>
      <p>Every adventurer may learn <b>two primary professions</b> and all <b>three secondary skills</b>. Choose a trade below to open its
        market ledger: what its <b>materials</b>, <b>crafted goods</b> and <b>recipes</b> are listing for on the WoW: Forever auction house,
        taken from the same in-game scans as the <a href="<?= e(url('auction-house')) ?>">Auction House</a> page.</p>
    </div>

    <div class="ahfilters">
      <span class="lbl">Realm</span>
      <div class="seg" id="pfRealm"></div>
      <span class="lbl" style="margin-left:8px">Faction</span>
      <div class="seg" id="pfFac"></div>
    </div>

<?php foreach ($groups as $role => [$title, $sub, $icon]): ?>
    <?php component_start('section', ['title' => $title, 'icon' => $icon, 'sub' => $sub]) ?>
        <div class="pf-cards">
<?php foreach ($cards as $slug => $c): if ($c['role'] !== $role) continue; ?>
          <a class="pf-card" href="<?= e($c['url']) ?>" data-slug="<?= e($slug) ?>">
            <span class="pf-ic"><img src="/assets/images/wow/icons/large/<?= e($c['icon']) ?>.jpg" alt="" width="52" height="52"></span>
            <h3><?= e($c['name']) ?></h3>
            <span class="role"><?= e($c['role'] === 'Secondary' ? 'Secondary skill' : $c['role']) ?></span>
            <span class="blurb"><?= e($c['blurb']) ?></span>
            <span class="ledger"><span><b data-k="items">—</b> items · <b data-k="gold">—</b> listed</span><span class="go">Market »</span></span>
          </a>
<?php endforeach; ?>
        </div>
    <?php component_end() ?>
<?php endforeach; ?>

    <div class="ahwin pf-board" style="margin-bottom:16px">
      <div class="wt">Trade Board<small>professions ranked by listed asking value</small></div>
      <table class="ahtable"><thead><tr><th class="rk">#</th><th>Profession</th><th class="num">Items</th><th class="num">Units</th><th class="num">Listed Value</th></tr></thead>
        <tbody id="pfBoard"></tbody></table>
    </div>

    <div class="source">Auction data from in-game scans · asking prices on listed auctions, not completed sales · an item can count toward more than one trade</div>
  </main>

<?php start_section('scripts') ?>
<script>
const PF = <?= js($data) ?>;
const CARDS = <?= js($cards) ?>;
const REALMS = <?= js($realms) ?>;
const FACTIONS = <?= js($factions) ?>;
const view = (r, f) => PF.views.find(v => v.realm === r && v.faction === f);
const hasData = v => v && !v.empty;
const goldShort = g => g >= 1e6 ? (g / 1e6).toFixed(1) + 'M' : g >= 1e3 ? Math.round(g / 1e3) + 'k' : fmt(g);
const goldMoney = g => `<span class="money"><span>${goldShort(g)}<i class="g"></i></span></span>`;
let realm = REALMS[0][0], fac = FACTIONS[0][0];

function renderFilters() {
  const btn = (attr, k, l, on, ok) => `<button data-${attr}="${esc(k)}" class="${on ? 'on' : ''} ${ok ? '' : 'nodata'}" ${ok ? '' : 'title="No auction scans yet"'}>${esc(l)}</button>`;
  document.getElementById('pfRealm').innerHTML = REALMS.length > 6
    ? `<select class="realm-pick" aria-label="Realm">${REALMS.map(([k, l]) => `<option value="${esc(k)}"${k === realm ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>`
    : REALMS.map(([k, l]) => btn('r', k, l, k === realm, FACTIONS.some(([f]) => hasData(view(k, f))))).join('');
  document.getElementById('pfFac').innerHTML = FACTIONS.map(([k, l]) => btn('f', k, l, k === fac, hasData(view(realm, k)))).join('');
}
const pickRealm = r => { realm = r; if (!hasData(view(realm, fac))) fac = (FACTIONS.find(([f]) => hasData(view(realm, f))) || ['all'])[0]; render(); };
document.getElementById('pfRealm').addEventListener('click', e => { if (e.target.dataset.r) pickRealm(e.target.dataset.r); });
document.getElementById('pfRealm').addEventListener('change', e => pickRealm(e.target.value));
document.getElementById('pfFac').addEventListener('click', e => { if (e.target.dataset.f) { fac = e.target.dataset.f; render(); } });

function render() {
  renderFilters();
  const v = view(realm, fac), ok = hasData(v);
  document.getElementById('pfstamp').textContent = ok ? `Scanned: ${v.scanned} · Page generated: ${PF.generated}` : `Page generated: ${PF.generated}`;
  // Card links carry the filter so the trade page opens on the same realm and faction.
  const q = (realm === 'all' && fac === 'all') ? '' : `?realm=${encodeURIComponent(realm)}&faction=${encodeURIComponent(fac)}`;
  document.querySelectorAll('.pf-card').forEach(a => {
    const p = ok ? v.p[a.dataset.slug] : null;
    a.href = CARDS[a.dataset.slug].url + q;
    a.querySelector('[data-k=items]').textContent = p ? fmt(p[0]) : '0';
    a.querySelector('[data-k=gold]').textContent = p ? goldShort(p[2]) + ' g' : '0 g';
    a.classList.toggle('quiet', !p || !p[0]);
  });
  const rows = ok ? Object.entries(v.p).sort((a, b) => b[1][2] - a[1][2]) : [];
  const max = rows.length ? rows[0][1][2] || 1 : 1;
  document.getElementById('pfBoard').innerHTML = rows.length ? rows.map(([s, p], i) => `<tr>
      <td class="rk">${i + 1}</td>
      <td class="trade"><a href="${esc(CARDS[s].url + q)}">${wowIcon(CARDS[s].icon)}${esc(CARDS[s].name)}</a></td>
      <td class="num">${fmt(p[0])}</td><td class="num">${fmt(p[1])}</td>
      <td class="num">${goldMoney(p[2])}<span class="qbar"><i data-w="${p[2] / max * 100}%"></i></span></td></tr>`).join('')
    : '<tr><td colspan="5" style="padding:14px">No auction scan for this realm and faction yet.</td></tr>';
  grow(document.getElementById('pfBoard'));
}
render();
</script>
<?php end_section() ?>
