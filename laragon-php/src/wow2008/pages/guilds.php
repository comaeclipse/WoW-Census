<?php
namespace Wow2008;
/**
 * Page: Guilds
 * Vars: $raw (string, 'name|realm key|A or H|characters|guild id|overall rank' per line: the top 100 plus each realm and
 *       faction's top 25), $guildBase (guild page URL prefix),
 *       $realmNames, $realmColors (objects keyed by realm key),
 *       $guildCount, $guilded, $surveyed (ints), $inGuild (float), $updated, $generated, $subtitle (strings), $realmList (list)
 */
?>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Realm Census', 'url' => url('census')], ['label' => 'Guilds']]]) ?>

    <?= component('banner', ['art' => 'guilds', 'title' => 'Guilds', 'subtitle' => $subtitle . ' · GUILDS BY REALM', 'subtitle_color' => '#ffd8a0', 'stamp' => 'Updated: ' . $updated . ' · Page generated: ' . $generated]) ?>

    <div class="tiles">
      <?= component('stat', ['label' => 'Guilds found', 'value' => fmt($guildCount), 'note' => 'distinct realm guilds']) ?>
      <?= component('stat', ['label' => 'Guilded characters', 'value' => fmt($guilded), 'note' => 'by latest known guild']) ?>
      <?= component('stat', ['label' => 'Surveyed', 'value' => fmt($surveyed), 'note' => 'unique characters']) ?>
      <?= component('stat', ['label' => 'In a guild', 'value' => $inGuild . '%', 'note' => 'of surveyed characters']) ?>
    </div>

    <!-- Top-100 breakdown -->
    <?php component_start('section', ['title' => 'Top 100 at a Glance', 'icon' => 'r', 'sub' => 'Members of the 100 most popular guilds', 'body_class' => 'gsplit']) ?>
        <div class="panel"><h5 style="color:#ffd75a">By Realm</h5><div class="ring" id="ringRealm"></div></div>
        <div class="panel"><h5 style="color:#ffd75a">By Faction</h5><div class="ring" id="ringFac"></div></div>
    <?php component_end() ?>

    <!-- Guild list -->
    <?php component_start('section', ['title' => 'Most Popular Guilds', 'icon' => 'g', 'sub' => 'Ranked by unique surveyed characters whose latest recorded guild has this name']) ?>
        <div class="gtools">
          <input id="gq" type="text" placeholder="Find a guild…" aria-label="Find a guild">
          <select class="realm-pick" id="grealm" aria-label="Realm">
            <option value="">All Realms</option>
          </select>
          <div class="seg" id="gfac">
            <button data-f="" class="on">Both</button>
            <button data-f="A">Alliance</button>
            <button data-f="H">Horde</button>
          </div>
        </div>
        <table class="rtable gtable">
          <thead><tr>
            <th style="cursor:default;text-align:center">#</th>
            <th style="cursor:default">Guild</th>
            <th style="cursor:default">Faction</th>
            <th style="cursor:default" class="num">Characters</th>
          </tr></thead>
          <tbody id="grows"></tbody>
        </table>
        <div class="pager" id="pager"></div>
        <p class="tfoot">All Realms shows the overall top 100. Choosing a realm shows that realm's top 25 guilds for each faction, ranked overall.</p>
    <?php component_end() ?>

    <div class="source">Guild data from in-game /who samples · updated <?= e($updated) ?><?= count($realmList) <= 6 ? ' · covers ' . e(implode(', ', $realmList)) : '' ?></div>
  </main>

<?php start_section('scripts') ?>
<script>
// name | realm key (see REALM_NAME) | faction | characters | guild page id | overall rank
const RAW = <?= js($raw) ?>;
const GUILD_BASE = <?= js($guildBase) ?>;

const REALM_NAME = <?= js($realmNames) ?>;
const REALM_COLOR = <?= js($realmColors) ?>;
const FAC_NAME = { A:'Alliance', H:'Horde' };
const GUILDS = RAW.split('\n').map(l => {
  const [name, realm, fac, n, id, rank] = l.split('|');
  return { rank: +rank, name, realm, fac, n: +n, id };
});
const TOP = GUILDS.filter(g => g.rank <= 100), PER = 25;

// ---------- Top-100 donuts ----------
function donut(el, parts) {
  const total = parts.reduce((a, p) => a + p.v, 0), r = 40, C = 2 * Math.PI * r;
  let off = 0;
  const arcs = parts.map(p => {
    const len = p.v / total * C;
    const s = `<circle cx="55" cy="55" r="${r}" fill="none" stroke="${p.c}" stroke-width="18"
      stroke-dasharray="${len} ${C - len}" stroke-dashoffset="${-off}" transform="rotate(-90 55 55)"
      data-tip="${p.label}|${fmt(p.v)} characters|${(p.v/total*100).toFixed(1)}% of top-100 members"/>`;
    off += len; return s;
  }).join('');
  el.innerHTML = `<svg viewBox="0 0 110 110"><circle cx="55" cy="55" r="51" fill="#000" stroke="#5a4a2a"/>${arcs}
    <text x="55" y="53" text-anchor="middle" fill="#ffd75a" font-family="Tahoma, Arial, sans-serif" font-weight="700" font-size="13">${fmt(total)}</text>
    <text x="55" y="66" text-anchor="middle" fill="#aaa" font-size="7">members</text></svg>
    <div class="leg">${parts.map(p => `<div><i style="background:${p.c}"></i>${p.label} — <b style="color:#ffd75a">${fmt(p.v)}</b>
      <span style="color:#999">(${p.g} guilds)</span></div>`).join('')}</div>`;
}
const agg = key => TOP.reduce((m, g) => { (m[g[key]] ??= { v: 0, g: 0 }); m[g[key]].v += g.n; m[g[key]].g++; return m; }, {});
const byRealm = agg('realm'), byFac = agg('fac');
const realmParts = Object.entries(byRealm).sort((a, b) => b[1].v - a[1].v).map(([k, o]) => ({ label: REALM_NAME[k], c: REALM_COLOR[k], ...o }));
if (realmParts.length > 7) realmParts.splice(6, Infinity, realmParts.slice(6).reduce((o, p) => ({ ...o, v: o.v + p.v, g: o.g + p.g }), { label: 'Other realms', c: '#6a5a3a', v: 0, g: 0 }));
donut(document.getElementById('ringRealm'), realmParts);
donut(document.getElementById('ringFac'),
  [['A','#3a6ad0'],['H','#c0281a']].filter(([k]) => byFac[k]).map(([k, c]) => ({ label: FAC_NAME[k], c, ...byFac[k] })));

// ---------- Guild table ----------
const grealm = document.getElementById('grealm');
grealm.insertAdjacentHTML('beforeend', Object.entries(REALM_NAME).map(([k, v]) => `<option value="${k}">${v}</option>`).join(''));
let q = '', realm = '', fac = '', page = 0;

function filtered() {
  return (realm ? GUILDS : TOP).filter(g => (!realm || g.realm === realm) && (!fac || g.fac === fac) && g.name.toLowerCase().includes(q));
}
function render() {
  const list = filtered(), max = list.length ? list[0].n : 1, pages = Math.max(1, Math.ceil(list.length / PER));
  page = Math.min(page, pages - 1);
  const slice = list.slice(page * PER, page * PER + PER);
  const tb = document.getElementById('grows');
  tb.innerHTML = slice.length ? slice.map(g => `<tr>
      <td class="rk">${g.rank <= 3 ? `<span class="medal m${g.rank}">${g.rank}</span>` : g.rank}</td>
      <td><a class="gname" href="${GUILD_BASE}${g.id}/"><b>${esc(g.name)}</b></a></td>
      <td><span class="fac ${g.fac}">${FAC_NAME[g.fac]}</span></td>
      <td><div class="gbar" data-tip="&lt;${esc(g.name)}&gt;|${fmt(g.n)} characters|${FAC_NAME[g.fac]}">
        <div class="track"><div class="fill" data-w="${g.n / max * 100}%" style="background:${g.fac === 'A' ? 'linear-gradient(#6a9aff,#2a4a9a)' : 'linear-gradient(#ff6a5a,#9a1a10)'}"></div></div>
        <span>${fmt(g.n)}</span></div></td></tr>`).join('')
    : `<tr class="empty"><td colspan="4">No guilds match these filters.</td></tr>`;
  requestAnimationFrame(() => requestAnimationFrame(() =>
    tb.querySelectorAll('[data-w]').forEach(el => el.style.width = el.dataset.w)));

  const pg = document.getElementById('pager');
  let html = `<button data-p="${page - 1}" ${page === 0 ? 'disabled' : ''}>«</button>`;
  for (let i = 0; i < pages; i++) html += `<button data-p="${i}" class="${i === page ? 'on' : ''}">${i + 1}</button>`;
  html += `<button data-p="${page + 1}" ${page >= pages - 1 ? 'disabled' : ''}>»</button>`;
  const from = list.length ? page * PER + 1 : 0, to = Math.min(list.length, page * PER + PER);
  html += `<span class="info">Showing ${from}–${to} of ${list.length}</span>`;
  pg.innerHTML = html;
}
document.getElementById('pager').addEventListener('click', e => {
  const p = e.target.dataset.p; if (p == null || e.target.disabled) return;
  page = +p; render();
  document.querySelector('.gtable').scrollIntoView({ behavior: 'smooth', block: 'start' });
});
document.getElementById('gq').addEventListener('input', e => { q = e.target.value.trim().toLowerCase(); page = 0; render(); });
grealm.addEventListener('change', () => { realm = grealm.value; page = 0; render(); });
document.getElementById('gfac').addEventListener('click', e => {
  if (e.target.dataset.f == null) return;
  fac = e.target.dataset.f; page = 0;
  document.querySelectorAll('#gfac button').forEach(b => b.classList.toggle('on', b === e.target));
  render();
});
render();

</script>
<?php end_section() ?>
