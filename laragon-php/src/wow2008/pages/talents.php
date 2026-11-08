<?php
namespace Wow2008;
/**
 * Page: Talents
 * Vars: $data (array, TALENT_DATA: builds, totals, talents), $classes (list), $subtitle, $generated, $inspectedAt (strings)
 */
?>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Realm Census', 'url' => url('census')], ['label' => 'Talents']]]) ?>

    <?= component('banner', ['art' => 'talents', 'title' => 'Talents', 'subtitle' => $subtitle . ' · BUILDS & POPULAR SPECS', 'subtitle_color' => '#c0d0ff', 'stamp' => 'Page generated: ' . $generated]) ?>

    <div class="classbar" id="classbar"></div>

    <div class="filters">
      <div class="seg" id="tfac">
        <button data-f="" class="on">Both Factions</button>
        <button data-f="A">Alliance</button>
        <button data-f="H">Horde</button>
      </div>
      <label><input type="checkbox" id="hyb"> Show hybrid builds</label>
      <span class="note">Faction applies to builds only; talent picks aren't split by faction.</span>
    </div>

    <!-- Spec split -->
    <?php component_start('section', ['title' => 'Spec Popularity', 'icon' => 'p', 'sub_id' => 'specSub']) ?>
              <div class="panel" id="specs"></div>
    <?php component_end() ?>

    <!-- Builds -->
    <?php component_start('section', ['title' => 'Class + Build', 'icon' => 'g', 'sub_id' => 'buildSub']) ?>
        <table class="rtable btable">
          <thead><tr>
            <th style="cursor:default">#</th>
            <th style="cursor:default">Combination</th>
            <th style="cursor:default" class="num">Players</th>
            <th style="cursor:default" class="num">% of Class</th>
          </tr></thead>
          <tbody id="brows"></tbody>
        </table>
        <div class="showmore"><a class="btn-parch" href="#" id="bmore"></a></div>
    <?php component_end() ?>

    <!-- Talent picks -->
    <?php component_start('section', ['title' => 'Selected Talent Popularity', 'icon' => 'r', 'sub_id' => 'talSub']) ?>
        <div class="toolbar"><input id="tq" type="text" placeholder="Find a talent…" aria-label="Find a talent"></div>
        <div class="panel"><div class="tlist" id="tlist"></div></div>
        <div class="pager" id="tpager"></div>
        <p class="tfoot">Shares count only players whose talent picks could be read, within each class. No inspected players were left out for unreadable talents.</p>
    <?php component_end() ?>

    <div class="source">Talent data from inspected nearby players · latest inspection <?= e($inspectedAt) ?> · page generated <?= e($generated) ?></div>
  </main>

<?php start_section('scripts') ?>
<script>
window.TALENT_DATA = <?= js($data) ?>;
</script>
<script>
// class order used by the source site
const CLASSES = <?= js($classes) ?>;
const { builds: BUILDS, totals: TOTALS, talents: TALENTS } = window.TALENT_DATA;
const isHybrid = spec => / Hybrid$/.test(spec);

// shade a hex colour: f < 0 darkens, f > 0 lightens

let cls = '', fac = '', showHyb = false, bShown = 20, tPage = 0, tq = '';

// ---------- Class picker ----------
const bar = document.getElementById('classbar');
bar.innerHTML = `<button class="cbtn on" data-c=""><span class="ci" style="background:radial-gradient(circle at 40% 35%,#fff6c0,#c89010 55%,#3a2400)">✦</span>All</button>` +
  CLASSES.map(c => `<button class="cbtn" data-c="${c}"><span class="ci">${wowClassIcon(c)}</span>${c}</button>`).join('');
bar.addEventListener('click', e => {
  const b = e.target.closest('.cbtn'); if (!b) return;
  cls = b.dataset.c; bShown = 20; tPage = 0;
  bar.querySelectorAll('.cbtn').forEach(x => x.classList.toggle('on', x === b));
  renderAll();
});
document.getElementById('tfac').addEventListener('click', e => {
  if (e.target.dataset.f == null) return;
  fac = e.target.dataset.f; bShown = 20;
  document.querySelectorAll('#tfac button').forEach(b => b.classList.toggle('on', b === e.target));
  renderSpecs(); renderBuilds();
});
document.getElementById('hyb').addEventListener('change', e => { showHyb = e.target.checked; bShown = 20; renderSpecs(); renderBuilds(); });

// combined build list for the current faction filter
function buildRows() {
  const src = fac ? BUILDS[fac] : [...BUILDS.A, ...BUILDS.H];
  const m = new Map();
  for (const [c, s, n] of src) { const k = c + '|' + s; m.set(k, (m.get(k) || 0) + n); }
  const classTotal = {};
  for (const [k, n] of m) { const c = k.split('|')[0]; classTotal[c] = (classTotal[c] || 0) + n; }
  return [...m].map(([k, n]) => { const [c, s] = k.split('|'); return { c, s, n, pct: n / classTotal[c] * 100, ct: classTotal[c] }; })
    .sort((a, b) => b.n - a.n || a.c.localeCompare(b.c));
}
const facLabel = () => fac === 'A' ? 'Alliance' : fac === 'H' ? 'Horde' : 'both factions';

// ---------- Spec stacks ----------
function renderSpecs() {
  const rows = buildRows();
  const list = cls ? [cls] : CLASSES;
  document.getElementById('specSub').textContent = `Share of each class's classified players · ${facLabel()}`;
  const el = document.getElementById('specs');
  el.innerHTML = list.map(c => {
    const mine = rows.filter(r => r.c === c);
    const pure = mine.filter(r => !isHybrid(r.s)).sort((a, b) => b.n - a.n);
    const hy = mine.filter(r => isHybrid(r.s));
    const hyN = hy.reduce((a, r) => a + r.n, 0), tot = mine.reduce((a, r) => a + r.n, 0);
    const segs = pure.map((r, i) => ({ label: r.s, n: r.n, col: shade(CLASS_COLOR[c], [0, -.3, -.5][i] ?? -.6), hy: false }));
    if (hyN) segs.push({ label: 'Hybrid', n: hyN, col: shade(CLASS_COLOR[c], -.75), hy: true, detail: hy.length });
    return `<div class="specrow">
      <span class="cn" style="color:${CLASS_COLOR[c]}">${c}</span>
      <div class="sstack">${segs.map(s => {
        const p = s.n / tot * 100;
        return `<div class="${s.hy ? 'hy' : ''}" data-g="${p}" style="flex:0 1 0;background:${s.col}"
          data-tip="${esc(c)} · ${esc(s.label)}|${fmt(s.n)} players · ${p.toFixed(1)}%${s.hy ? `|${s.detail} hybrid variants combined` : ''}">${p >= 14 ? `${esc(s.label)} ${Math.round(p)}%` : p >= 6 ? Math.round(p) + '%' : ''}</div>`;
      }).join('')}</div>
      <span class="n">${fmt(tot)}</span></div>`;
  }).join('');
  // dark segments need light text
  el.querySelectorAll('.sstack div').forEach(d => {
    const m = d.style.background.match(/\d+/g); if (!m) return;
    const lum = (0.299 * m[0] + 0.587 * m[1] + 0.114 * m[2]);
    d.style.color = lum < 110 ? '#fff' : '#000';
    d.style.textShadow = lum < 110 ? '0 1px 1px #000' : '0 1px 0 rgba(255,255,255,.35)';
  });
  grow(el);
}

// ---------- Build table ----------
function renderBuilds() {
  let rows = buildRows().filter(r => (!cls || r.c === cls) && (showHyb || !isHybrid(r.s)));
  document.getElementById('buildSub').textContent = `${cls || 'All classes'} · ${facLabel()} · ${rows.length} builds`;
  const shown = rows.slice(0, bShown);
  document.getElementById('brows').innerHTML = shown.map((r, i) => `<tr>
    <td class="rk">${i < 3 ? `<span class="medal m${i + 1}">${i + 1}</span>` : i + 1}</td>
    <td><span class="spec" style="color:${shade(CLASS_COLOR[r.c], -.35)}">${r.c}</span> · ${esc(r.s.replace(/ Hybrid$/, ''))}${isHybrid(r.s) ? '<span class="hyb">hybrid</span>' : ''}</td>
    <td class="num"><b>${fmt(r.n)}</b></td>
    <td class="num"><div class="gbar" data-tip="${esc(r.c)} · ${esc(r.s)}|${fmt(r.n)} of ${fmt(r.ct)} classified ${esc(r.c)}s|${r.pct.toFixed(1)}% · ${facLabel()}">
      <div class="track"><div class="fill" data-w="${r.pct}%" style="background:${CLASS_COLOR[r.c]}"></div></div>
      <span>${r.pct.toFixed(1)}%</span></div></td></tr>`).join('') ||
    `<tr class="empty"><td colspan="4">No builds for this selection.</td></tr>`;
  const more = document.getElementById('bmore');
  more.parentElement.hidden = rows.length <= 20;
  more.textContent = shown.length < rows.length ? `Show all ${rows.length} builds ↓` : 'Show fewer ↑';
  grow(document.getElementById('brows'));
}
document.getElementById('bmore').addEventListener('click', e => {
  e.preventDefault(); bShown = bShown >= 999 ? 20 : 999; renderBuilds();
});

// ---------- Talent list ----------
const TPER = 30;
function renderTalents() {
  const rows = TALENTS
    .filter(([c, t]) => (!cls || c === cls) && t.toLowerCase().includes(tq))
    .map(([c, t, n, icon]) => ({ c, t, n, icon, tot: TOTALS[c], pct: n / TOTALS[c] * 100 }))
    .sort((a, b) => b.pct - a.pct || a.t.localeCompare(b.t));
  const pages = Math.max(1, Math.ceil(rows.length / TPER));
  tPage = Math.min(tPage, pages - 1);
  document.getElementById('talSub').textContent = cls
    ? `${cls} · ${fmt(TOTALS[cls])} players with readable talents · ${rows.length} talents`
    : `All classes · ${rows.length} talents`;
  const slice = rows.slice(tPage * TPER, tPage * TPER + TPER);
  const el = document.getElementById('tlist');
  el.innerHTML = slice.map(r => {
    const col = CLASS_COLOR[r.c];
    const initials = r.t.replace(/\(.*\)/, '').split(/[\s:-]+/).filter(w => /^[A-Z]/.test(w)).slice(0, 2).map(w => w[0]).join('');
    return `<div class="trow" data-tip="${esc(r.t)}|${esc(r.c)} talent|Picked by ${fmt(r.n)} of ${fmt(r.tot)} (${r.pct.toFixed(1)}%)">
      <span class="ticon" style="background:radial-gradient(circle at 35% 30%,${shade(col, .2)},${shade(col, -.45)} 70%,${shade(col, -.8)})">${wowIcon(r.icon || 'inv_misc_questionmark', initials)}</span>
      <div><div class="tname"><b>${esc(r.t)}</b><span>${r.pct.toFixed(1)}%</span></div>
        <div class="tbar"><i data-w="${r.pct}%" style="background:${col}"></i></div>
        <div class="tmeta">${cls ? '' : `<span style="color:${col}">${r.c}</span> · `}${fmt(r.n)} / ${fmt(r.tot)} players</div></div></div>`;
  }).join('') || '<div class="pending">No talents match.</div>';
  grow(el);

  const pg = document.getElementById('tpager');
  let html = `<button data-p="${tPage - 1}" ${tPage === 0 ? 'disabled' : ''}>«</button>`;
  const win = [...Array(pages).keys()].filter(i => i === 0 || i === pages - 1 || Math.abs(i - tPage) <= 2);
  let last = -1;
  for (const i of win) { if (i - last > 1) html += '<span class="info">…</span>'; html += `<button data-p="${i}" class="${i === tPage ? 'on' : ''}">${i + 1}</button>`; last = i; }
  html += `<button data-p="${tPage + 1}" ${tPage >= pages - 1 ? 'disabled' : ''}>»</button>`;
  html += `<span class="info">${rows.length ? tPage * TPER + 1 : 0}–${Math.min(rows.length, tPage * TPER + TPER)} of ${rows.length}</span>`;
  pg.innerHTML = html;
}
document.getElementById('tpager').addEventListener('click', e => {
  const p = e.target.dataset.p; if (p == null || e.target.disabled) return;
  tPage = +p; renderTalents();
  document.getElementById('tlist').scrollIntoView({ behavior: 'smooth', block: 'start' });
});
document.getElementById('tq').addEventListener('input', e => { tq = e.target.value.trim().toLowerCase(); tPage = 0; renderTalents(); });

function renderAll() { renderSpecs(); renderBuilds(); renderTalents(); }
renderAll();

</script>
<?php end_section() ?>
