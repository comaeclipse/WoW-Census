<?php
namespace Wow2008;
/**
 * Page: Gear
 * Vars: $data (array, GEAR_DATA: latest, generated, slots, wowhead, itemBase: Forever item page prefix or null), $subtitle (string)
 */
?>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Realm Census', 'url' => url('census')], ['label' => 'Gear']]]) ?>

    <?= component('banner', ['art' => 'gear', 'title' => 'Gear', 'subtitle' => $subtitle . ' · MOST EQUIPPED ITEMS', 'subtitle_color' => '#ffd0a0', 'stamp_id' => 'gstamp']) ?>

    <div class="tiles" id="gtiles"></div>

    <!-- Most-equipped item ledger -->
    <?php component_start('section', ['title' => 'Most Equipped by Slot', 'icon' => 'g', 'sub' => 'Click a slot to see its top ten items']) ?>
        <div class="doll">
          <div class="dcol l" id="colL"></div>
          <div class="dcol r" id="colR"></div>
        </div>
        <div class="qlegend" id="qlegend"></div>
    <?php component_end() ?>

    <!-- Slot detail -->
    <?php component_start('section', ['title' => 'Slot Detail', 'icon' => 'p', 'sub_html' => '<select class="realm-pick" id="slotPick" aria-label="Choose slot"></select>', 'id' => 'slotDetail']) ?>
        <div class="sd-head" id="sdHead"></div>
        <div class="sd-stats" id="sdStats"></div>
        <table class="rtable itable">
          <thead><tr>
            <th style="cursor:default;text-align:center">#</th>
            <th style="cursor:default">Item</th>
            <th style="cursor:default" class="num">Players</th>
            <th style="cursor:default" class="num">% of Slot</th>
          </tr></thead>
          <tbody id="sdRows"></tbody>
        </table>
        <p class="tfoot">"% of slot" counts only players who had something equipped in that slot. Item names link to Wowhead.</p>
    <?php component_end() ?>

    <!-- Slot overview -->
    <?php component_start('section', ['title' => 'Slot Overview', 'icon' => 'r', 'sub' => 'Click a heading to sort']) ?>
        <table class="rtable otable">
          <thead><tr>
            <th data-s="slot">Slot</th>
            <th data-s="fillPct" class="num">Filled</th>
            <th data-s="ench" class="num">Enchanted</th>
            <th data-s="distinct" class="num">Distinct Items</th>
            <th data-s="topPct">Most Equipped</th>
          </tr></thead>
          <tbody id="oRows"></tbody>
        </table>
    <?php component_end() ?>

    <div class="source">Gear data from inspected nearby players · all classes, races, realms and factions · item names link to Wowhead</div>
  </main>

<?php start_section('scripts') ?>
<script>
const G = <?= js($data) ?>;
const QUALITY = ['Poor','Common','Uncommon','Rare','Epic','Legendary'];
const QCOL = ['#9d9d9d','#ffffff','#1eff00','#0070dd','#a335ee','#ff8000'];
const bySlot = Object.fromEntries(G.slots.map(s => [s.slot, s]));

// Original line-art glyphs for each slot (24x24 viewBox)
const GLYPH = {
  'Head':      'M5 15 Q5 5 12 4 Q19 5 19 15 L17 15 L17 12 L7 12 L7 15Z M8 15 H16 V18 H8Z',
  'Neck':      'M5 4 Q12 16 19 4 M12 13 L15 17 L12 21 L9 17Z',
  'Shoulder':  'M3 14 Q4 6 12 6 Q20 6 21 14 Q16 11 12 11 Q8 11 3 14Z M6 15 L18 15 L17 18 L7 18Z',
  'Back':      'M7 3 H17 L20 21 Q12 18 4 21Z',
  'Chest':     'M6 4 L9 3 Q12 6 15 3 L18 4 L20 9 L17 10 L17 21 H7 L7 10 L4 9Z',
  'Wrist':     'M6 6 H18 V18 H6Z M6 10 H18 M6 14 H18',
  'Hands':     'M8 21 V11 L6 6 Q7 4 9 6 L10 9 V4 Q11 2 12 4 V9 V3 Q13 1 14 3 V9 V5 Q15 3 16 5 V12 L18 9 Q20 9 19 12 L16 18 V21Z',
  'Waist':     'M3 9 H21 V15 H3Z M10 8 H14 V16 H10Z',
  'Legs':      'M6 3 H18 L19 21 H14 L12 9 L10 21 H5Z',
  'Feet':      'M8 3 H13 V14 L20 16 Q21 20 18 20 H6 Q5 20 6 16 L8 14Z',
  'Rings':     'M12 7 A6.5 6.5 0 1 0 12.01 7Z M12 10 A3.5 3.5 0 1 1 11.99 10Z M10 3 H14 L13 7 H11Z',
  'Trinkets':  'M12 3 L19 9 L12 21 L5 9Z M5 9 H19 M9 9 L12 21 L15 9 M9 9 L12 3 L15 9',
  'Main Hand': 'M18 3 L21 3 L21 6 L10 17 L7 14Z M6 15 L9 18 L7 20 L4 17Z M4 19 L5 20 L3 22 L2 21Z',
  'Off Hand':  'M12 3 L20 6 Q20 16 12 21 Q4 16 4 6Z M12 7 V17 M8 11 H16',
  'Ranged':    'M6 3 Q18 12 6 21 M6 3 V21 M3 12 H21 M18 9 L21 12 L18 15',
  'Shirt':     'M8 3 L4 6 L6 10 L8 9 V21 H16 V9 L18 10 L20 6 L16 3 Q12 6 8 3Z',
  'Tabard':    'M7 3 H17 V17 L12 21 L7 17Z M12 7 L14 11 L12 15 L10 11Z'
};
const glyph = (slot, col) => `<svg viewBox="0 0 24 24"><path d="${GLYPH[slot]}" fill="${col}" fill-opacity=".35" stroke="${col}" stroke-width="1.3" stroke-linejoin="round" fill-rule="evenodd"/></svg>`;
const itemTip = (s, it) => `${it[0]}|${s.slot} · ${QUALITY[it[1]]}|${fmt(it[3])} players · ${it[4]}% of filled ${s.slot.toLowerCase()} slots`;

// ---------- Tiles ----------
document.getElementById('gstamp').textContent = `Latest inspection: ${G.latest} · Page generated: ${G.generated}`;
const inspected = Math.max(...G.slots.map(s => s.of));
const topAll = G.slots.flatMap(s => s.items.map(it => ({ s, it }))).sort((a, b) => b.it[3] - a.it[3])[0];
const avgEnch = Math.round(G.slots.filter(s => s.ench > 0).reduce((a, s) => a + s.ench, 0) / G.slots.filter(s => s.ench > 0).length);
document.getElementById('gtiles').innerHTML = `
  <div class="stat"><span>Players inspected</span><b>${fmt(inspected)}</b><em>gear could be read</em></div>
  <div class="stat"><span>Slots tracked</span><b>${G.slots.length}</b><em>${fmt(G.slots.reduce((a, s) => a + s.distinct, 0))} slot-item pairs</em></div>
  <div class="stat"><span>Most equipped</span><b style="font-size:13px;line-height:26px;color:${QCOL[topAll.it[1]] === '#ffffff' ? '#5a2a00' : QCOL[topAll.it[1]]}">${esc(topAll.it[0])}</b><em>${fmt(topAll.it[3])} players · ${topAll.s.slot}</em></div>
  <div class="stat"><span>Enchanted</span><b>${avgEnch}%</b><em>average of enchantable slots</em></div>`;

// ---------- Most-equipped item ledger ----------
const LAYOUT = {
  colL: ['Head','Neck','Shoulder','Back','Chest','Shirt','Tabard','Wrist'],
  colR: ['Hands','Waist','Legs','Feet','Rings','Trinkets','Main Hand','Off Hand','Ranged']
};
let selected = bySlot.Trinkets ? 'Trinkets' : G.slots[0].slot;
for (const [col, slots] of Object.entries(LAYOUT)) {
  document.getElementById(col).innerHTML = slots.filter(name => bySlot[name]).map(name => {
    const s = bySlot[name], top = s.items[0], qc = QCOL[top[1]];
    return `<div class="slot" data-slot="${name}" data-tip="${esc(itemTip(s, top))}">
      <span class="sicon" style="border-color:${qc}">${wowIcon(top[5], glyph(name, qc))}</span>
      <div class="sinfo"><div class="sn">${name}</div>
        <div class="it" style="color:${qc}">${esc(top[0])}</div>
        <div class="pl">${fmt(top[3])} players · ${top[4]}%</div></div></div>`;
  }).join('');
}
document.querySelector('.doll').addEventListener('click', e => {
  const s = e.target.closest('.slot'); if (s) selectSlot(s.dataset.slot, true);
});
document.getElementById('qlegend').innerHTML = QUALITY.slice(0, 5).map((q, i) =>
  `<span><b style="color:${QCOL[i]};text-shadow:0 0 1px #000,0 1px 1px #000">■</b> ${q}</span>`).join('');

// ---------- Slot detail ----------
const pick = document.getElementById('slotPick');
pick.innerHTML = G.slots.map(s => `<option>${s.slot}</option>`).join('');
pick.addEventListener('change', () => selectSlot(pick.value, false));

function selectSlot(name, scroll) {
  selected = name; pick.value = name;
  document.querySelectorAll('.doll .slot').forEach(el => el.classList.toggle('sel', el.dataset.slot === name));
  const s = bySlot[name], fillPct = s.filled / s.of * 100, qc = QCOL[s.items[0][1]];
  document.getElementById('sdHead').innerHTML = `
    <span class="sicon" style="border-color:${qc}">${wowIcon(s.items[0][5], glyph(name, qc))}</span>
    <div><h3>${name}</h3><p>${fmt(s.filled)} of ${fmt(s.of)} players had this slot filled</p></div>`;
  document.getElementById('sdStats').innerHTML = `
    <div class="stat"><b>${fillPct.toFixed(0)}%</b><span>Slot filled</span><div class="meter"><i data-w="${fillPct}%"></i></div></div>
    <div class="stat"><b>${s.ench}%</b><span>Enchanted</span><div class="meter"><i data-w="${s.ench}%"></i></div></div>
    <div class="stat"><b>${fmt(s.distinct)}</b><span>Distinct items</span><div class="meter"><i data-w="${Math.min(100, s.distinct / 5)}%" style="background:linear-gradient(#c9a0ff,#6a2aa0)"></i></div></div>`;
  const max = s.items[0][4];
  document.getElementById('sdRows').innerHTML = s.items.map((it, i) => `<tr data-tip="${esc(itemTip(s, it))}">
    <td class="rk" style="text-align:center">${i < 3 ? `<span class="medal m${i + 1}" style="width:22px;height:22px;margin:0 auto;font-size:11px">${i + 1}</span>` : i + 1}</td>
    <td><div class="ib"><span class="mini-ic" style="outline:1px solid ${QCOL[it[1]]}">${wowIcon(it[5], glyph(name, QCOL[it[1]]))}</span>
      ${G.itemBase ? `<a class="iname" style="color:${QCOL[it[1]]}" href="${G.itemBase}${it[2]}" title="Prices and who wears ${esc(it[0])}">${esc(it[0])}</a>`
        : `<a class="iname" style="color:${QCOL[it[1]]}" href="${G.wowhead}${it[2]}" target="_blank" rel="noopener">${esc(it[0])}</a>`}</div></td>
    <td class="num" style="color:#ffd75a;font-weight:bold">${fmt(it[3])}</td>
    <td class="num"><div class="gbar"><div class="track" style="background:#000;border-color:#3a3020"><div class="fill" data-w="${it[4] / max * 100}%" style="background:${QCOL[it[1]]}"></div></div>
      <span style="color:#d8c8a0">${it[4]}%</span></div></td></tr>`).join('');
  grow(document.getElementById('slotDetail'));
  if (scroll) document.getElementById('slotDetail').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ---------- Overview ----------
let oKey = null, oDir = 1;
const ORDER = G.slots.map(s => s.slot);
function renderOverview() {
  const rows = G.slots.map(s => ({ ...s, fillPct: s.filled / s.of * 100, topPct: s.items[0][4] }));
  if (oKey) rows.sort((a, b) => { const x = a[oKey], y = b[oKey]; return (x > y ? 1 : x < y ? -1 : 0) * oDir; });
  document.querySelectorAll('.otable th').forEach(th => {
    const base = th.textContent.replace(/[▲▼]/g, '').trim();
    th.innerHTML = base + (th.dataset.s === oKey ? `<span class="arr">${oDir < 0 ? '▼' : '▲'}</span>` : '');
  });
  const bar = (p, c) => `<div class="pc"><div class="track"><div class="fill" data-w="${p}%" style="background:${c}"></div></div><span>${Math.round(p)}%</span></div>`;
  const tb = document.getElementById('oRows');
  tb.innerHTML = rows.map(s => `<tr data-slot="${s.slot}" style="cursor:pointer">
    <td><span class="rn">${s.slot}</span></td>
    <td class="num">${bar(s.fillPct, 'linear-gradient(#ffd75a,#b07a00)')}</td>
    <td class="num">${s.ench ? bar(s.ench, 'linear-gradient(#9cff6a,#2a8a10)') : '<span style="color:#9a7a4a">—</span>'}</td>
    <td class="num"><b>${fmt(s.distinct)}</b></td>
    <td>${G.itemBase ? `<a href="${G.itemBase}${s.items[0][2]}" style="font-weight:bold;text-decoration:none;color:${s.items[0][1] >= 2 ? ['', '', '#138a00', '#0058b0', '#7a1ab8'][s.items[0][1]] : '#3a1a00'}">${esc(s.items[0][0])}</a>`
      : `<span style="font-weight:bold;color:${s.items[0][1] >= 2 ? ['', '', '#138a00', '#0058b0', '#7a1ab8'][s.items[0][1]] : '#3a1a00'}">${esc(s.items[0][0])}</span>`}
      <span style="font-size:9px;color:#6a4a20">· ${s.items[0][4]}%</span></td></tr>`).join('');
  grow(tb);
}
document.querySelector('.otable thead').addEventListener('click', e => {
  const k = e.target.closest('th')?.dataset.s; if (!k) return;
  if (k === oKey) oDir *= -1; else { oKey = k; oDir = k === 'slot' ? 1 : -1; }
  renderOverview();
});
document.getElementById('oRows').addEventListener('click', e => {
  const tr = e.target.closest('tr[data-slot]'); if (tr) selectSlot(tr.dataset.slot, true);
});

renderOverview();
selectSlot(selected, false);

// Tooltip titles take the item-quality colour named on their first line (see common.js).
window.tipTitleColor = (title, lines) => {
  const q = QUALITY.findIndex(qn => lines[0] && lines[0].endsWith(qn));
  return q >= 0 ? QCOL[q] : null;
};

</script>
<?php end_section() ?>
