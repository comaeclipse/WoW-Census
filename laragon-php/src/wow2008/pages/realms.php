<?php
namespace Wow2008;
/**
 * Page: Realm Viewer
 * Vars: $data (list of realms), $allianceRaces (list), $count, $enough, $seen, $week (ints), $busiest, $scanned, $generated,
 *       $subtitle (strings), $busiestPct (float), $connected (bool: rows are connected realm groups),
 *       $mixed (bool: some rows are realms connected to nothing, Retail)
 */
$unit = !$connected ? 'Realm' : ($mixed ? 'Realm or group' : 'Realm group');
?>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Realm Census', 'url' => url('census')], ['label' => 'Realm Viewer']]]) ?>

    <?= component('banner', ['art' => 'realms', 'title' => 'Realm Viewer', 'subtitle' => $subtitle . ($groupForever ? ' · POPULATION BY REGION AND TYPE' : ($connected ? ' · POPULATION BY CONNECTED REALM' : ' · POPULATION BY REALM')), 'subtitle_color' => '#e0c0ff', 'stamp' => 'Scanned: ' . $scanned . ' · Page generated: ' . $generated]) ?>

    <div class="tiles">
      <?= component('stat', ['label' => $groupForever ? 'Region / type groups' : (!$connected ? 'Realms scanned' : ($mixed ? 'Realms & groups' : 'Realm groups')), 'value' => fmt($count), 'note' => fmt($enough) . ' with enough data']) ?>
      <?= component('stat', ['label' => 'Characters seen', 'value' => fmt($seen), 'note' => fmt($week) . ' in the last 7 days']) ?>
      <?= component('stat', ['label' => $groupForever ? 'Largest group' : ($connected && !$mixed ? 'Busiest group' : 'Busiest realm'), 'value' => $busiest, 'value_style' => 'font-size:15px;line-height:26px', 'note' => $busiestPct . '% of characters seen']) ?>
      <?= component('stat', ['label' => 'Last scan', 'value' => $scanned, 'value_style' => 'font-size:17px;line-height:26px', 'note' => 'latest census observation']) ?>
    </div>

    <!-- Realm list -->
    <?php component_start('section', ['title' => $groupForever ? 'Region / Type Groups' : ($connected ? 'Realm Groups' : 'Realm List'), 'icon' => 'g', 'sub' => $groupForever || $connected ? 'Click a group to view its details' : 'Click a realm to view its details']) ?>
        <div class="toolbar">
          <input id="q" type="text" placeholder="Find a realm…" aria-label="Find a realm">
          <div class="seg" id="fac">
            <button data-f="all" class="on">Both Factions</button>
            <button data-f="A">Alliance</button>
            <button data-f="H">Horde</button>
          </div>
        </div>
        <table class="rtable">
          <thead><tr>
            <th data-s="name"><?= $groupForever ? 'Region / Type' : e($unit) ?></th>
            <th data-s="chars" class="num">Characters</th>
            <th data-s="bal">Balance</th>
            <th data-s="d7" class="num">7 Days</th>
            <th data-s="top">Top Class</th>
          </tr></thead>
          <tbody id="rows"></tbody>
        </table>
        <p class="tfoot">
<?php if ($groupForever): ?>
          Forever has no player-visible realms. Collector buckets are combined by region and realm type. Counts are characters seen by scans, not players online.
<?php elseif ($connected): ?>
          These are characters seen by scans, not players online. Connected realms share one auction house, world and population, so each connected group is one row and a scan from any of its realms sees them all.<?= $mixed ? ' Realms connected to nothing are rows of their own; groups are named after their realm with the most characters seen.' : '' ?> Each character counts once. The group's details list its home realms, where its characters were created.
<?php else: ?>
          These are characters seen by scans, not players online. Each character counts once, under its home realm, so players seen through a connected realm count on their own realm. Realms with fewer than 20 characters seen are listed but not ranked.
<?php endif ?>
        </p>
    <?php component_end() ?>

    <!-- Realm detail -->
    <?php component_start('section', ['title' => $groupForever || $connected ? 'Group Details' : 'Realm Details', 'icon' => 'p', 'sub_html' => '<select class="realm-pick" id="pick" aria-label="Choose ' . ($groupForever || $connected ? 'group' : 'realm') . '"></select>', 'id' => 'detail']) ?>
        <div class="detail-hd">
          <h3 id="dName"></h3><span id="dBadges"></span>
          <span class="sw">Both factions</span>
        </div>
<?php if ($connected): ?>
        <p class="members" id="dMembers"></p>
<?php endif ?>

        <div class="tiles3">
          <?= component('stat', ['label' => 'Seen', 'value' => '', 'order' => 'value-first', 'value_id' => 'dSeen']) ?>
          <?= component('stat', ['label' => 'Last 7 days', 'value' => '', 'order' => 'value-first', 'value_id' => 'd7']) ?>
          <?= component('stat', ['label' => 'Last 24 hours', 'value' => '', 'order' => 'value-first', 'value_id' => 'd24']) ?>
        </div>

        <div class="balance-row">
          <div class="lbls"><span class="ally-c" id="dAl"></span><span class="horde-c" id="dHl"></span></div>
          <div class="tug"><div class="a" id="dA"></div><div class="h" id="dH"></div></div>
        </div>

        <div class="cols2" style="margin-bottom:14px">
          <div class="panel ally"><h5 class="ally-c">Alliance <small>Top race &amp; class</small></h5><ul class="rank" id="dTopA"></ul></div>
          <div class="panel horde"><h5 class="horde-c">Horde <small>Top race &amp; class</small></h5><ul class="rank" id="dTopH"></ul></div>
        </div>

        <div class="cols2" aria-label="Classes by faction">
          <div class="panel ally"><h5 class="ally-c">Alliance Classes <small id="dClassACount"></small></h5><div class="bars" id="dClassA"></div></div>
          <div class="panel horde"><h5 class="horde-c">Horde Classes <small id="dClassHCount"></small></h5><div class="bars" id="dClassH"></div></div>
        </div>
        <p class="source">Class percentages are within each faction on this realm. Counts are observed characters, not players online.</p>
        <div class="panel"><h5 style="color:#ffd75a">Race Mix <small>% of characters seen</small></h5><div class="bars wide" id="dRace"></div></div>
<?php if ($connected): ?>

        <div class="panel homes">
          <h5 style="color:#ffd75a">Home Realms <small>where this group's characters were created</small></h5>
          <table class="rtable">
            <thead><tr><th>Home realm</th><th class="num">Characters</th><th class="num">Share</th><th>Balance</th></tr></thead>
            <tbody id="dHomes"></tbody>
          </table>
        </div>
<?php endif ?>
    <?php component_end() ?>

    <div class="source">Realm data from in-game /who samples · scanned <?= e($scanned) ?></div>
  </main>

<?php start_section('scripts') ?>
<script>
const ALLIANCE_RACES = <?= js($allianceRaces) ?>;

const REALMS = <?= js($data) ?>;
const GROUP_FOREVER = <?= $groupForever ? 'true' : 'false' ?>;
const REGION_ORDER = {US: 0, EU: 1};
const TYPE_ORDER = {'PvE': 0, 'PvP': 1, 'RP': 2, 'RP-PvP': 3};

const shortLabel = r => GROUP_FOREVER ? r.displayName : r.name + (r.pvp ? ' (PVP)' : '');
const label = r => GROUP_FOREVER
  ? shortLabel(r)
  : shortLabel(r) + (r.region ? ` (${r.region})` : '');
const badges = r =>
  GROUP_FOREVER ? '' :
  (r.region ? `<span class="badge ${r.region.toLowerCase()}">${r.region}</span>` : r.known ? '' : `<span class="badge unk" title="Region not identified">?</span>`) +
  (r.pvp ? `<span class="badge pvp">PVP</span>` : '');
const allianceShare = r => r.H == null ? 1 : r.A + r.H ? r.A / (r.A + r.H) : .5;

// ---------- Realm list ----------
let sortKey = 'chars', sortDir = -1, faction = 'all', query = '', selected = REALMS[0].id;

function charsFor(r) {
  if (faction === 'A') return r.A;
  if (faction === 'H') return r.H;
  return r.seen;
}
function sortVal(r) {
  return { name: label(r).toLowerCase(), chars: charsFor(r) ?? -1, bal: allianceShare(r), d7: r.d7, top: r.top }[sortKey];
}

function renderTable() {
  const rows = REALMS
    .filter(r => [label(r), r.name, ...(r.members || [])].join(' ').toLowerCase().includes(query))
    .sort((a, b) => {
      if (GROUP_FOREVER) {
        const region = (REGION_ORDER[a.groupRegion] ?? 9) - (REGION_ORDER[b.groupRegion] ?? 9);
        if (region) return region;
        const type = (TYPE_ORDER[a.realmType] ?? 9) - (TYPE_ORDER[b.realmType] ?? 9);
        if (type) return type;
      }
      const x = sortVal(a), y = sortVal(b); return (x > y ? 1 : x < y ? -1 : 0) * sortDir;
    });

  document.querySelectorAll('.rtable th').forEach(th => {
    const on = th.dataset.s === sortKey;
    th.innerHTML = th.textContent.replace(/[▲▼]/g, '').trim() + (on ? `<span class="arr">${sortDir < 0 ? '▼' : '▲'}</span>` : '');
  });
  const colName = { all:'Characters', A:'Alliance', H:'Horde' }[faction];
  document.querySelector('th[data-s="chars"]').firstChild.textContent = colName;

  const tb = document.getElementById('rows');
  if (!rows.length) { tb.innerHTML = `<tr class="empty"><td colspan="5">No realms match “${query}”.</td></tr>`; return; }
  tb.innerHTML = rows.map(r => {
    const a = allianceShare(r), c = charsFor(r);
    const balTxt = r.H == null ? 'Alliance only' : `${Math.round(a*100)}% / ${Math.round((1-a)*100)}%`;
    return `<tr data-id="${esc(r.id)}" class="${r.id === selected ? 'sel' : ''}">
      <td><span class="rn">${esc(shortLabel(r))}</span>${GROUP_FOREVER ? '' : badges(r)}</td>
      <td class="num">${c == null ? '<span style="color:#9a7a4a">not scanned</span>' : fmt(c)}</td>
      <td><div class="mini" data-tip="${label(r)}|${balTxt}|Alliance ${fmt(r.A)}${r.H == null ? '' : ' · Horde ' + fmt(r.H)}">
        <i style="width:${a*100}%;background:#3a6ad0"></i><i style="width:${(1-a)*100}%;background:#c0281a"></i></div>
        <div class="mini-l">${balTxt}</div></td>
      <td class="num">${fmt(r.d7)}</td>
      <td><span class="tc" style="color:${CLASS_COLOR[r.top]}">${r.top}</span></td>
    </tr>`;
  }).join('');
}

document.querySelector('.rtable thead').addEventListener('click', e => {
  const k = e.target.closest('th')?.dataset.s; if (!k) return;
  if (k === sortKey) sortDir *= -1; else { sortKey = k; sortDir = k === 'name' || k === 'top' ? 1 : -1; }
  renderTable();
});
document.getElementById('rows').addEventListener('click', e => {
  const tr = e.target.closest('tr[data-id]'); if (tr) select(tr.dataset.id, true);
});
document.getElementById('q').addEventListener('input', e => { query = e.target.value.trim().toLowerCase(); renderTable(); });
document.getElementById('fac').addEventListener('click', e => {
  const f = e.target.dataset.f; if (!f) return;
  faction = f;
  document.querySelectorAll('#fac button').forEach(b => b.classList.toggle('on', b.dataset.f === f));
  renderTable();
});

// ---------- Realm detail ----------
const pick = document.getElementById('pick');
pick.innerHTML = REALMS.map(r => `<option value="${r.id}">${label(r)}</option>`).join('');
pick.addEventListener('change', () => select(pick.value, false));

function rankList(list) {
  return list.map(([n, c, p], i) => {
    const cls = n.split(' ').pop();
    return `<li><span class="medal m${i+1}">${i+1}</span>
      <div><div class="nm" style="color:${CLASS_COLOR[cls]}">${esc(shortRace(n))}</div><div class="meta">${fmt(c)} characters</div></div>
      <span class="pct">${p}%</span></li>`;
  }).join('');
}
function pctBars(el, obj, color, scope = 'characters seen') {
  if (!obj || !Object.keys(obj).length) { el.innerHTML = '<p class="pending">Not scanned yet</p>'; return; }
  const rows = Object.entries(obj), max = Math.max(...rows.map(r => r[1]));
  el.innerHTML = rows.map(([n, p]) => `<div class="bar" data-tip="${esc(shortRace(n))}|${p}% of ${scope}">
    <span class="lbl" style="color:${color(n)}">${esc(shortRace(n))}</span>
    <div class="track"><div class="fill" data-w="${p/max*100}%" style="background:${color(n)}"></div></div>
    <span class="val">${p}%</span></div>`).join('');
  grow(el);
}

function select(id, scroll) {
  const r = REALMS.find(x => x.id === id); if (!r) return;
  selected = id; pick.value = id; renderTable();
  // Realm keys are "region:Name"; keep the colon bare and spaces as + so the URL stays readable.
  history.replaceState(null, '', '?realm=' + encodeURIComponent(id).replace(/%3A/gi, ':').replace(/%20/g, '+'));

  document.getElementById('dName').textContent = shortLabel(r);
  document.getElementById('dBadges').innerHTML = badges(r);
  document.getElementById('dSeen').textContent = fmt(r.seen);
  document.getElementById('d7').textContent = fmt(r.d7);
  document.getElementById('d24').textContent = fmt(r.d24);

  const a = allianceShare(r), dA = document.getElementById('dA'), dH = document.getElementById('dH');
  document.getElementById('dAl').textContent = `Alliance ${fmt(r.A)} · ${Math.round(a*100)}%`;
  document.getElementById('dHl').textContent = r.H == null ? 'Horde not scanned yet' : `${Math.round((1-a)*100)}% · ${fmt(r.H)} Horde`;
  dA.style.width = dH.style.width = '50%';
  dA.dataset.w = a*100 + '%'; dH.dataset.w = (1-a)*100 + '%';
  dA.textContent = a > .12 ? Math.round(a*100) + '%' : '';
  dH.textContent = r.H != null && a < .88 ? Math.round((1-a)*100) + '%' : '';
  grow(document.querySelector('.balance-row'));

  document.getElementById('dTopA').innerHTML = rankList(r.topA);
  document.getElementById('dTopH').innerHTML = r.topH ? rankList(r.topH) : '<li class="pending">Horde not scanned yet</li>';

  document.getElementById('dClassACount').textContent = `${fmt(r.A)} observed`;
  document.getElementById('dClassHCount').textContent = r.H == null ? 'not scanned' : `${fmt(r.H)} observed`;
  pctBars(document.getElementById('dClassA'), r.clsA, n => CLASS_COLOR[n], 'Alliance characters');
  pctBars(document.getElementById('dClassH'), r.clsH, n => CLASS_COLOR[n], 'Horde characters');
  pctBars(document.getElementById('dRace'), r.race, n => ALLIANCE_RACES.includes(n) ? '#6fa8ff' : '#ff6a5a');

  // Connected realm groups (MoP): member realms and each home realm's share.
  const members = document.getElementById('dMembers');
  if (members) members.textContent = r.members && r.members.length > 1
    ? `${r.members.length} connected realms: ${r.members.join(', ')}` : 'Not connected to other realms';
  const homes = document.getElementById('dHomes');
  if (homes) homes.innerHTML = (r.homes || []).map(h => {
    const a = h.A + h.H ? h.A / (h.A + h.H) : .5;
    return `<tr><td><span class="rn">${esc(h.name)}</span></td><td class="num">${fmt(h.chars)}</td>
      <td class="num">${r.seen ? (100 * h.chars / r.seen).toFixed(1) : 0}%</td>
      <td><div class="mini" data-tip="${esc(h.name)}|Alliance ${fmt(h.A)} · Horde ${fmt(h.H)}">
        <i style="width:${a*100}%;background:#3a6ad0"></i><i style="width:${(1-a)*100}%;background:#c0281a"></i></div>
        <div class="mini-l">${Math.round(a*100)}% / ${Math.round((1-a)*100)}%</div></td></tr>`;
  }).join('');

  if (scroll) document.getElementById('detail').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// initial realm from ?realm= (matches the source site's URL format)
const fromUrl = new URLSearchParams(location.search).get('realm');
// A member realm's old link (?realm=us:Galakras) opens its connected group.
const fromRealm = REALMS.find(r => r.id === fromUrl || (r.aliases || []).includes(fromUrl));
select(fromRealm ? fromRealm.id : REALMS[0].id, false);

</script>
<?php end_section() ?>
