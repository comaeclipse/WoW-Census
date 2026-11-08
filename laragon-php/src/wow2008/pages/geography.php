<?php
namespace Wow2008;
/**
 * Page: Geography
 * Vars: $data (array: total, locs, mix), $total, $count, $top5, $capital, $mixSum (ints), $top (?array), $window, $updated,
 *       $generated, $subtitle (strings), $showMap, $tbcMap, $classicMap, $mopMap, $retailMap, $foreverMap (bools)
 */
$continentArt = $tbcMap || $classicMap || $mopMap || $retailMap;
?>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Realm Census', 'url' => url('census')], ['label' => 'Geography']]]) ?>

    <?= component('banner', ['art' => 'geography', 'title' => 'Geography', 'subtitle' => $subtitle . ' · WHERE PLAYERS ARE', 'subtitle_color' => '#b0e8ff', 'stamp' => 'Updated: ' . $updated . ' · Page generated: ' . $generated]) ?>

    <div class="tiles">
      <?= component('stat', ['label' => 'Characters', 'value' => fmt($total), 'note' => 'unique, ' . $window]) ?>
      <?= component('stat', ['label' => 'Locations', 'value' => fmt($count), 'note' => 'with recent sightings']) ?>
      <?= component('stat', ['label' => 'Top five share', 'value' => $top5 . '%', 'note' => 'in the five busiest places']) ?>
      <?= component('stat', ['label' => 'Updated', 'value' => $updated, 'value_style' => 'font-size:17px;line-height:26px', 'note' => 'last population sample']) ?>
    </div>

    <!-- Map -->
    <?php component_start('section', ['title' => 'Population Map', 'sub' => 'Top 50 locations · ' . $window . ' · marker size = characters']) ?>
        <div class="chips" id="chips"></div>
<?php if ($showMap): ?>
<?php if ($continentArt): ?>
        <div class="map-tabs" id="map-tabs" aria-label="Choose continent"></div>
        <div class="mapwrap"><svg id="map" viewBox="0 0 1002 668" role="img" aria-label="Population locations on the selected continent"></svg></div>
<?php else: ?>
        <div class="mapwrap"><svg id="map" viewBox="0 0 880 440"></svg></div>
<?php endif; ?>
<?php else: ?>
<?php if ($continentArt): ?><div class="map-tabs" id="map-tabs" hidden></div><?php endif; ?>
        <svg id="map" viewBox="0 0 <?= $continentArt ? '1002 668' : '880 440' ?>" hidden></svg>
        <p class="note">The busiest places without a reliable outdoor map position are listed below.</p>
<?php endif; ?>
    <?php component_end() ?>

    <!-- Top locations -->
    <?php component_start('section', ['title' => 'Where Players Are', 'icon' => 'g', 'sub' => 'Busiest 16 locations']) ?>
              <div class="panel"><div class="bars toplocs" id="top"></div></div>
    <?php component_end() ?>

    <!-- Play-style mix -->
    <?php component_start('section', ['title' => 'Play-Style Mix', 'icon' => 'p', 'sub' => 'Recent characters, by the kind of place they were last seen']) ?>
        <div class="mix" id="mix"></div>
        <div class="mix-leg"><span><?= $mixSum !== 100 ? 'Shares are rounded, so they add up to ' . $mixSum . '%.' : 'Shares are rounded.' ?></span><span><?= e(ucfirst($window)) ?></span></div>
    <?php component_end() ?>

    <!-- Detail table -->
    <?php component_start('section', ['title' => 'Location Detail', 'icon' => 'r', 'sub' => 'Click a heading to sort', 'id' => 'detail']) ?>
        <div class="toolbar">
          <input id="lq" type="text" placeholder="Find a location…" aria-label="Find a location">
        </div>
        <table class="rtable ltable">
          <thead><tr>
            <th data-s="name">Location</th>
            <th data-s="n" class="num">Characters</th>
            <th data-s="n" class="num">Share</th>
            <th data-s="type">Activity</th>
            <th data-s="lvl" class="num">Avg Level</th>
          </tr></thead>
          <tbody id="lrows"></tbody>
        </table>
        <div class="showmore"><a class="btn-parch" href="#" id="more"></a></div>
    <?php component_end() ?>

    <!-- How to read -->
    <?php component_start('section', ['title' => 'How to Read This', 'icon' => 'g', 'body_class' => 'reads']) ?>
<?php if ($top): ?>
        <div class="read"><b>Strongest cluster</b><?= e($top['name']) ?> is the single busiest place, holding <?= $total ? round(100 * $top['characters'] / $total, 1) : 0 ?>% of the characters in this selection.</div>
<?php endif; ?>
        <div class="read"><b>Top-five concentration</b><?= $top5 ?>% of characters sit in just five locations, which shows how bunched up the population is.</div>
        <div class="read"><b>Capital footprint</b><?= $capital ?>% were last spotted in a capital city.</div>
        <div class="read"><b>Level context</b>The average level for each location hints at who goes there: new characters, levelers or max-level players.</div>
    <?php component_end() ?>

    <div class="source">Location data from in-game /who samples · all realms, both factions · updated <?= e($updated) ?> · map positions are approximate</div>
  </main>

<?php start_section('scripts') ?>
<script>
const TOTAL = <?= js($data['total']) ?>;
const TYPES = {
  Capital:  { c:'#ffc23a', label:'Capital' },
  End:      { c:'#b06aff', label:'End game' },
  Leveling: { c:'#5ad02a', label:'Leveling' },
  Starting: { c:'#4ab0ff', label:'Starting' }
};
// [name, characters, type, avg level, continent, map x, map y].
const RAW = <?= js($data['locs']) ?>;
const LOCS = RAW.map(([name, n, type, lvl, continent, x, y], i) => ({ i, name, n, type, lvl, continent, x, y }));
const share = n => (n / TOTAL * 100).toFixed(1) + '%';
const tipOf = l => `${l.name}|${fmt(l.n)} characters · ${share(l.n)}|${TYPES[l.type].label} · average level ${l.lvl}`;

let active = new Set(Object.keys(TYPES)), selected = null;

<?php if ($continentArt): ?>
// ---------- Continent art and interactive population markers ----------
const map = document.getElementById('map');
const MAPS = {
  K: ['Kalimdor', <?= js(asset($classicMap ? 'classic-map-kalimdor.webp' : 'tbc-map-kalimdor.webp')) ?>],
  E: ['Eastern Kingdoms', <?= js(asset($classicMap ? 'classic-map-eastern-kingdoms.webp' : 'tbc-map-eastern-kingdoms.webp')) ?>]<?php if ($tbcMap || $mopMap || $retailMap): ?>,
  O: ['Outland', <?= js(asset('tbc-map-outland.webp')) ?>]
<?php endif; ?><?php if ($mopMap || $retailMap): ?>,
  N: ['Northrend', <?= js(asset('mop-map-northrend.webp')) ?>],
  P: ['Pandaria', <?= js(asset('mop-map-pandaria.webp')) ?>]
<?php endif; ?><?php if ($retailMap): ?>,
  D: ['Dragon Isles', <?= js(asset('retail-map-dragon-isles.webp')) ?>],
  H: ["Khaz Algar", <?= js(asset('retail-map-khaz-algar.webp')) ?>],
  M: ["Quel'Thalas", <?= js(asset('retail-map-quelthalas.webp')) ?>]
<?php endif; ?>
};
const mapTabs = document.getElementById('map-tabs');
let activeMap = Object.keys(MAPS).sort((a, b) =>
  LOCS.filter(l => l.continent === b).reduce((sum, l) => sum + l.n, 0) -
  LOCS.filter(l => l.continent === a).reduce((sum, l) => sum + l.n, 0))[0];
if (MAPS.P && LOCS.some(l => l.continent === 'P')) activeMap = 'P';
<?php if ($retailMap): ?>activeMap = 'M';<?php endif; ?>
mapTabs.innerHTML = Object.entries(MAPS).map(([key, [label]]) =>
  `<button type="button" data-map="${key}" aria-pressed="false">${label}</button>`).join('');
const radius = n => Math.max(7, Math.min(24, Math.sqrt(n) * .52));
function renderMap() {
  const [label, art] = MAPS[activeMap];
  map.setAttribute('aria-label', `${label} population map`);
  mapTabs.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.map === activeMap)));
  map.innerHTML = `<image href="${art}" width="1002" height="668" preserveAspectRatio="none"/>` +
    LOCS.filter(l => l.continent === activeMap && l.x != null).sort((a, b) => b.n - a.n).map(l => `
    <g class="mk${l.i === selected ? ' sel' : ''}${active.has(l.type) ? '' : ' dim'}" data-i="${l.i}" data-tip="${esc(tipOf(l))}">
      <circle class="dot" cx="${l.x}" cy="${l.y}" r="${radius(l.n)}" fill="${TYPES[l.type].c}" fill-opacity=".88"/>
      ${l.n >= <?= $foreverMap ? '3000' : '640' ?> ? `<text x="${l.x}" y="${l.y - radius(l.n) - 5}" text-anchor="middle">${esc(l.name)}</text>` : ''}
    </g>`).join('');
}
mapTabs.addEventListener('click', e => {
  const button = e.target.closest('button[data-map]');
  if (button) { activeMap = button.dataset.map; renderMap(); }
});
renderMap();
<?php else: ?>
// ---------- Map (original schematic) ----------
const map = document.getElementById('map');
map.innerHTML = `
  <defs>
    <radialGradient id="mp-sea" cx="50%" cy="50%" r="70%"><stop offset="0" stop-color="#14304a"/><stop offset="1" stop-color="#050c16"/></radialGradient>
    <linearGradient id="mp-land" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#e8d4a0"/><stop offset="1" stop-color="#c4a868"/></linearGradient>
    <radialGradient id="mp-nether" cx="50%" cy="50%" r="60%"><stop offset="0" stop-color="#3a1a5a"/><stop offset=".75" stop-color="#14061e"/><stop offset="1" stop-color="#05010a"/></radialGradient>
    <linearGradient id="mp-out" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#8a6a50"/><stop offset="1" stop-color="#5a3a30"/></linearGradient>
    <pattern id="mp-waves" width="24" height="12" patternUnits="userSpaceOnUse"><path d="M0 8 Q6 4 12 8 T24 8" fill="none" stroke="#2a5070" stroke-width=".8"/></pattern>
    <filter id="mp-sh"><feDropShadow dx="0" dy="2" stdDeviation="2" flood-color="#000" flood-opacity=".7"/></filter>
  </defs>
  <rect width="560" height="440" fill="url(#mp-sea)"/>
  <rect width="560" height="440" fill="url(#mp-waves)" opacity=".5"/>
  <rect x="560" width="320" height="440" fill="url(#mp-nether)"/>
  <line x1="560" y1="0" x2="560" y2="440" stroke="#c9a050" stroke-width="2"/>

  <!-- Kalimdor -->
  <g filter="url(#mp-sh)" fill="url(#mp-land)" stroke="#7a5a20" stroke-width="1.5">
    <path d="M96 30 Q120 20 128 40 Q126 58 104 62 Q86 54 96 30Z"/>
    <path d="M44 100 Q60 90 72 104 Q70 124 52 124 Q38 116 44 100Z"/>
    <path d="M128 76 Q160 60 186 82 Q206 108 230 120 Q262 140 264 180 Q262 210 250 240 Q260 280 252 320
             Q248 360 236 392 Q210 410 180 400 Q150 404 130 380 Q100 350 90 312 Q80 270 92 230
             Q84 190 100 160 Q112 120 128 76Z"/>
  </g>
  <!-- Eastern Kingdoms -->
  <g filter="url(#mp-sh)" fill="url(#mp-land)" stroke="#7a5a20" stroke-width="1.5">
    <path d="M360 40 Q400 22 450 34 Q500 18 540 30 Q550 60 528 92 Q500 110 490 130 Q500 160 484 190
             Q500 220 490 250 Q480 280 486 310 Q476 340 470 360 Q456 400 432 420 Q410 420 410 396
             Q396 360 384 330 Q370 300 384 270 Q400 240 400 210 Q392 170 398 140 Q376 112 362 84 Q350 60 360 40Z"/>
  </g>
  <!-- Outland -->
  <g filter="url(#mp-sh)" fill="url(#mp-out)" stroke="#c070ff" stroke-width="1.2" stroke-opacity=".6">
    <path d="M730 70 Q770 56 800 80 Q808 110 780 130 Q748 132 734 112 Q722 90 730 70Z"/>
    <path d="M650 110 Q690 100 716 124 Q722 160 694 172 Q660 170 648 146 Q640 126 650 110Z"/>
    <path d="M620 190 Q660 176 700 186 Q750 180 800 200 Q820 226 812 256 Q840 280 860 300 Q862 340 830 356
             Q790 362 752 350 Q710 358 670 340 Q624 330 616 296 Q604 256 620 190Z"/>
  </g>
  <g fill="#c9a8ff" opacity=".7"><circle cx="600" cy="60" r="1.2"/><circle cx="840" cy="150" r="1"/><circle cx="620" cy="400" r="1.3"/><circle cx="700" cy="410" r="1"/><circle cx="860" cy="40" r="1.1"/></g>

  <text class="cont" x="180" y="428" text-anchor="middle" fill="#e0c890">KALIMDOR</text>
  <text class="cont" x="470" y="436" text-anchor="middle" fill="#e0c890">EASTERN KINGDOMS</text>
  <text class="cont" x="720" y="410" text-anchor="middle" fill="#d8b0ff">OUTLAND</text>
  <g id="markers"></g>`;

const radius = n => Math.max(3.5, Math.sqrt(n) * .37);
document.getElementById('markers').innerHTML = LOCS.filter(l => l.x != null).sort((a, b) => b.n - a.n).map(l => `
  <g class="mk" data-i="${l.i}" data-tip="${esc(tipOf(l))}">
    <circle class="dot" cx="${l.x}" cy="${l.y}" r="0" data-r="${radius(l.n)}" fill="${TYPES[l.type].c}" fill-opacity=".82"/>
    ${l.n >= 640 ? `<text x="${l.x}" y="${l.y - radius(l.n) - 3}" text-anchor="middle">${esc(l.name)}</text>` : ''}
  </g>`).join('');
requestAnimationFrame(() => requestAnimationFrame(() =>
  map.querySelectorAll('circle.dot').forEach(c => c.setAttribute('r', c.dataset.r))));
function renderMap() {}
<?php endif; ?>

map.addEventListener('click', e => {
  const g = e.target.closest('.mk'); if (g) select(+g.dataset.i, true);
});

// ---------- Type chips ----------
const chips = document.getElementById('chips');
chips.innerHTML = Object.entries(TYPES).map(([k, t]) =>
  `<span class="chip" data-k="${k}"><i style="background:${t.c}"></i>${t.label}</span>`).join('') +
  `<span class="hint">Click a type to show or hide it · click a marker for details</span>`;
chips.addEventListener('click', e => {
  const c = e.target.closest('.chip'); if (!c) return;
  const k = c.dataset.k;
  if (active.has(k) && active.size > 1) active.delete(k); else active.add(k);
  applyFilter();
});
function applyFilter() {
  chips.querySelectorAll('.chip').forEach(c => c.classList.toggle('off', !active.has(c.dataset.k)));
  map.querySelectorAll('.mk').forEach(g => g.classList.toggle('dim', !active.has(LOCS[g.dataset.i].type)));
  page = 1; renderTable();
}

// ---------- Top 16 ----------
const topList = document.getElementById('top');
const top16 = LOCS.slice(0, 16), max = top16[0].n;
// two columns, read down then across
const order = [...Array(8).keys()].flatMap(i => [top16[i], top16[i + 8]]).filter(Boolean);
topList.innerHTML = order.map(l => `<div class="bar" data-tip="${esc(tipOf(l))}" data-i="${l.i}" style="cursor:pointer">
  <span class="lbl" style="color:${TYPES[l.type].c}">${l.i + 1}. ${esc(l.name)}</span>
  <div class="track"><div class="fill" data-w="${l.n / max * 100}%" style="background:${TYPES[l.type].c}"></div></div>
  <span class="val">${fmt(l.n)}</span></div>`).join('');
topList.addEventListener('click', e => { const b = e.target.closest('[data-i]'); if (b) select(+b.dataset.i, true); });
requestAnimationFrame(() => requestAnimationFrame(() =>
  topList.querySelectorAll('[data-w]').forEach(el => el.style.width = el.dataset.w)));

// ---------- Play-style mix ----------
const MIX = <?= js($data['mix']) ?>;
const mix = document.getElementById('mix');
mix.innerHTML = MIX.map(([k, p]) => `<div style="flex:0 1 0;flex-grow:0;background:${TYPES[k].c}" data-g="${p}"
  data-tip="${TYPES[k].label}|${p}% of recent characters">${p >= 8 ? `${TYPES[k].label} ${p}%` : `${p}%`}</div>`).join('');
requestAnimationFrame(() => requestAnimationFrame(() =>
  mix.querySelectorAll('[data-g]').forEach(d => d.style.flexGrow = d.dataset.g)));

// ---------- Detail table ----------
let sortKey = 'n', sortDir = -1, q = '', page = 1;
const PAGE = 25;
function rows() {
  return LOCS.filter(l => active.has(l.type) && l.name.toLowerCase().includes(q))
    .sort((a, b) => {
      const x = sortKey === 'type' ? TYPES[a.type].label : a[sortKey], y = sortKey === 'type' ? TYPES[b.type].label : b[sortKey];
      return (x > y ? 1 : x < y ? -1 : 0) * sortDir;
    });
}
function renderTable() {
  const all = rows(), shown = all.slice(0, page * PAGE);
  document.querySelectorAll('.ltable th').forEach(th => {
    const base = th.textContent.replace(/[▲▼]/g, '').trim();
    const on = th.dataset.s === sortKey && (sortKey !== 'n' || base === 'Characters');
    th.innerHTML = base + (on ? `<span class="arr">${sortDir < 0 ? '▼' : '▲'}</span>` : '');
  });
  const tb = document.getElementById('lrows');
  tb.innerHTML = shown.length ? shown.map(l => `<tr data-i="${l.i}" class="${l.i === selected ? 'sel' : ''}">
      <td class="rn">${esc(l.name)}</td>
      <td class="num">${fmt(l.n)}</td>
      <td class="num">${share(l.n)}</td>
      <td><span class="ltype"><i style="background:${TYPES[l.type].c}"></i>${TYPES[l.type].label}</span></td>
      <td class="num"><span class="lvl">${l.lvl}</span></td></tr>`).join('')
    : `<tr class="empty"><td colspan="5">No locations match.</td></tr>`;
  const more = document.getElementById('more');
  more.parentElement.hidden = all.length <= PAGE;
  more.textContent = shown.length < all.length ? `Show more (${all.length - shown.length} left) ↓` : 'Show fewer ↑';
}
document.getElementById('more').addEventListener('click', e => {
  e.preventDefault();
  page = rows().length > page * PAGE ? page + 1 : 1;
  renderTable();
});
document.querySelector('.ltable thead').addEventListener('click', e => {
  const k = e.target.closest('th')?.dataset.s; if (!k) return;
  if (k === sortKey) sortDir *= -1; else { sortKey = k; sortDir = k === 'name' || k === 'type' ? 1 : -1; }
  renderTable();
});
document.getElementById('lrows').addEventListener('click', e => {
  const tr = e.target.closest('tr[data-i]'); if (tr) select(+tr.dataset.i, false);
});
document.getElementById('lq').addEventListener('input', e => { q = e.target.value.trim().toLowerCase(); page = 1; renderTable(); });

function select(i, scrollToTable) {
  selected = selected === i ? null : i;
  if (selected != null && LOCS[i].continent) activeMap = LOCS[i].continent;
  renderMap();
  map.querySelectorAll('.mk').forEach(g => g.classList.toggle('sel', +g.dataset.i === selected));
  if (selected != null && !active.has(LOCS[i].type)) { active.add(LOCS[i].type); applyFilter(); }
  // make sure the row is visible
  if (selected != null) {
    const pos = rows().findIndex(l => l.i === selected);
    if (pos >= page * PAGE) page = Math.ceil((pos + 1) / PAGE);
  }
  renderTable();
  if (scrollToTable && selected != null)
    document.querySelector(`#lrows tr[data-i="${selected}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

applyFilter();

</script>
<?php end_section() ?>
