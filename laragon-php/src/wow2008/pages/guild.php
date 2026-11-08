<?php
namespace Wow2008;
/**
 * Page: one guild's roster
 * Vars: $name, $realm, $faction, $firstSeen, $lastSeen, $generated, $subtitle (strings),
 *       $members (list of [name, level, race, class name, zone, last seen date]),
 *       $classes, $races, $bands (objects: label => characters), $specs (list of [class name, spec, characters]),
 *       $classColor (object: class name => color), $inspected (int), $avgLevel (float), $maxLevel (int), $allianceRaces (list)
 */
?>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Realm Census', 'url' => url('census')], ['label' => 'Guilds', 'url' => url('guilds')], ['label' => '<' . $name . '>']]]) ?>

    <?= component('banner', ['art' => 'guilds', 'title' => '<' . $name . '>', 'subtitle' => $subtitle . ' · ' . strtoupper($faction), 'subtitle_color' => '#ffd8a0', 'stamp' => 'Last seen: ' . $lastSeen . ' · Page generated: ' . $generated]) ?>

    <div class="tiles">
      <?= component('stat', ['label' => 'Members seen', 'value' => fmt(count($members)), 'note' => 'latest known guild is this one']) ?>
      <?= component('stat', ['label' => 'Average level', 'value' => (string)$avgLevel, 'note' => 'highest seen ' . $maxLevel]) ?>
      <?= component('stat', ['label' => 'Inspected', 'value' => fmt($inspected), 'note' => 'members with a known spec']) ?>
      <?= component('stat', ['label' => 'First seen', 'value' => $firstSeen, 'value_style' => 'font-size:15px;line-height:26px', 'note' => 'earliest member sighting']) ?>
    </div>

    <?php component_start('section', ['title' => 'Guild Makeup', 'icon' => 'g', 'sub' => $faction, 'body_class' => 'cols2']) ?>
        <div class="panel"><h5 style="color:#ffd75a">Classes <small>members seen</small></h5><div class="bars" id="gClass"></div></div>
        <div class="panel"><h5 style="color:#ffd75a">Races <small>members seen</small></h5><div class="bars" id="gRace"></div></div>
    <?php component_end() ?>

    <?php component_start('section', ['title' => 'Specs & Levels', 'icon' => 'p', 'body_class' => 'cols2']) ?>
        <div class="panel"><h5 style="color:#ffd75a">Specs <small><?= e(fmt($inspected)) ?> inspected</small></h5><div class="bars wide" id="gSpec"></div></div>
        <div class="panel"><h5 style="color:#ffd75a">Levels <small>members seen</small></h5><div class="bars" id="gLevel"></div></div>
    <?php component_end() ?>

    <?php component_start('section', ['title' => 'Roster', 'icon' => 'r', 'sub' => 'Every character seen whose latest recorded guild is this one']) ?>
        <div class="gtools">
          <input id="mq" type="text" placeholder="Find a member…" aria-label="Find a member">
          <select class="realm-pick" id="mclass" aria-label="Class">
            <option value="">All Classes</option>
          </select>
        </div>
        <table class="rtable mtable">
          <thead><tr>
            <th data-s="0">Name</th>
            <th data-s="1" class="num">Level</th>
            <th data-s="2">Race</th>
            <th data-s="3">Class</th>
            <th data-s="4">Zone</th>
            <th data-s="5">Last Seen</th>
          </tr></thead>
          <tbody id="mrows"></tbody>
        </table>
        <div class="pager" id="mpager"></div>
        <p class="tfoot">Members are characters found in /who results whose most recent sighting showed this guild tag. Zone and level are from that sighting. Specs come from inspections and are counted for the guild, not listed per character.</p>
    <?php component_end() ?>

    <div class="source">Guild data from in-game /who samples and inspections · last member seen <?= e($lastSeen) ?></div>
  </main>

<?php start_section('scripts') ?>
<script>
// name | level | race | class | zone | last seen
const MEMBERS = <?= js($members) ?>;
const CLASSES = <?= js($classes) ?>, RACES = <?= js($races) ?>, BANDS = <?= js($bands) ?>;
const SPECS = <?= js($specs) ?>;
const COLOR = <?= js($classColor) ?>;
const ALLIANCE_RACES = <?= js($allianceRaces) ?>;
const PER = 50;

// ---------- Breakdowns ----------
function countBars(el, rows, tip) {
  if (!rows.length) { el.innerHTML = '<p class="tfoot" style="color:#a8a8a8">No inspections of these members yet.</p>'; return; }
  const max = Math.max(...rows.map(r => r[1]));
  el.innerHTML = rows.map(([n, c, col]) => `<div class="bar" data-tip="${esc(shortRace(n))}|${fmt(c)} ${c === 1 ? 'character' : 'characters'}|${tip(c)}">
    <span class="lbl" style="color:${col}">${esc(shortRace(n))}</span>
    <div class="track"><div class="fill" data-w="${c / max * 100}%" style="background:${col}"></div></div>
    <span class="val">${fmt(c)}</span></div>`).join('');
  grow(el);
}
const total = MEMBERS.length, pct = c => (c / total * 100).toFixed(1) + '% of members seen';
countBars(document.getElementById('gClass'), Object.entries(CLASSES).map(([n, c]) => [n, c, COLOR[n]]), pct);
countBars(document.getElementById('gRace'), Object.entries(RACES).map(([n, c]) => [n, c, ALLIANCE_RACES.includes(n) ? '#6fa8ff' : '#ff6a5a']), pct);
countBars(document.getElementById('gLevel'), Object.entries(BANDS).map(([n, c]) => ['Level ' + n, c, '#c9a050']), pct);
const inspected = SPECS.reduce((a, s) => a + s[2], 0);
countBars(document.getElementById('gSpec'), SPECS.map(([cls, spec, c]) => [`${spec} ${cls}`, c, COLOR[cls]]),
  c => (c / inspected * 100).toFixed(1) + '% of inspected members');

// ---------- Roster ----------
const mclass = document.getElementById('mclass');
mclass.insertAdjacentHTML('beforeend', Object.keys(CLASSES).sort().map(c => `<option>${esc(c)}</option>`).join(''));
let q = '', cls = '', sortKey = 1, sortDir = -1, page = 0;

function rows() {
  return MEMBERS.filter(m => (!cls || m[3] === cls) && m[0].toLowerCase().includes(q))
    .sort((a, b) => {
      const x = a[sortKey] ?? '', y = b[sortKey] ?? '';
      return (x > y ? 1 : x < y ? -1 : 0) * sortDir || a[0].localeCompare(b[0]);
    });
}
function render() {
  const list = rows(), pages = Math.max(1, Math.ceil(list.length / PER));
  page = Math.min(page, pages - 1);
  document.querySelectorAll('.mtable th').forEach(th => {
    th.innerHTML = th.textContent.replace(/[▲▼]/g, '').trim() + (+th.dataset.s === sortKey ? `<span class="arr">${sortDir < 0 ? '▼' : '▲'}</span>` : '');
  });
  const tb = document.getElementById('mrows');
  tb.innerHTML = list.length ? list.slice(page * PER, page * PER + PER).map(([n, lvl, race, c, zone, seen]) => `<tr>
      <td><span class="rn">${esc(n)}</span></td>
      <td class="num">${lvl ?? '—'}</td>
      <td>${race ? esc(shortRace(race)) : '—'}</td>
      <td><span class="tc" style="color:${COLOR[c] ?? '#5a2a00'}">${c ? esc(c) : '—'}</span></td>
      <td>${zone ? esc(zone) : '—'}</td>
      <td>${seen ?? '—'}</td></tr>`).join('')
    : `<tr class="empty"><td colspan="6">No members match these filters.</td></tr>`;

  const pg = document.getElementById('mpager');
  let html = `<button data-p="${page - 1}" ${page === 0 ? 'disabled' : ''}>«</button>`;
  for (let i = 0; i < pages; i++) html += `<button data-p="${i}" class="${i === page ? 'on' : ''}">${i + 1}</button>`;
  html += `<button data-p="${page + 1}" ${page >= pages - 1 ? 'disabled' : ''}>»</button>`;
  const from = list.length ? page * PER + 1 : 0, to = Math.min(list.length, page * PER + PER);
  html += `<span class="info">Showing ${from}–${to} of ${list.length}</span>`;
  pg.innerHTML = html;
}
document.querySelector('.mtable thead').addEventListener('click', e => {
  const th = e.target.closest('th'); if (!th) return;
  const k = +th.dataset.s;
  if (k === sortKey) sortDir *= -1; else { sortKey = k; sortDir = k === 1 || k === 5 ? -1 : 1; }
  page = 0; render();
});
document.getElementById('mpager').addEventListener('click', e => {
  const p = e.target.dataset.p; if (p == null || e.target.disabled) return;
  page = +p; render();
  document.querySelector('.mtable').scrollIntoView({ behavior: 'smooth', block: 'start' });
});
document.getElementById('mq').addEventListener('input', e => { q = e.target.value.trim().toLowerCase(); page = 0; render(); });
mclass.addEventListener('change', () => { cls = mclass.value; page = 0; render(); });
render();
</script>
<?php end_section() ?>
