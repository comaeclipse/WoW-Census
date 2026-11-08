<?php
namespace Wow2008;
/**
 * Page: Realm Census
 * Vars: $data (array, the page script's DATA), $coverage (array), $inspectedAt, $observedAt, $stamp, $subtitle (strings),
 *       $seen (seen_activity() result or null)
 */
?>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Realm Census']]]) ?>

    <?= component('banner', ['art' => 'census', 'title' => 'Realm Census', 'subtitle' => $subtitle, 'stamp' => $stamp, 'seen' => $seen]) ?>

    <!-- Faction balance -->
    <?php component_start('section', ['title' => 'Faction Balance', 'icon' => 'g', 'sub_id' => 'totalNote']) ?>
        <div class="balance">
          <div class="crest ally">
            <span class="faction-emblem"><img src="<?= e(asset('faction-alliance.png')) ?>" alt=""></span>
            <b class="ally-c">Alliance</b><span id="aTot"></span>
          </div>
          <div>
            <div class="tug"><div class="a" id="tugA"></div><div class="h" id="tugH"></div></div>
            <div class="tug-note" id="tugNote"></div>
          </div>
          <div class="crest horde">
            <span class="faction-emblem"><img src="<?= e(asset('faction-horde.png')) ?>" alt=""></span>
            <b class="horde-c">Horde</b><span id="hTot"></span>
          </div>
        </div>
    <?php component_end() ?>

    <!-- Top combos -->
    <?php component_start('section', ['title' => 'Most Popular Race & Class', 'sub' => 'Top three combinations per faction', 'body_class' => 'cols2']) ?>
        <div class="panel ally"><h5 class="ally-c">Alliance</h5><ul class="rank" id="topA"></ul></div>
        <div class="panel horde"><h5 class="horde-c">Horde</h5><ul class="rank" id="topH"></ul></div>
    <?php component_end() ?>

<?php if (isset($data['roles'])): ?>
    <!-- Roles -->
    <?php component_start('section', ['title' => 'Role Split', 'icon' => 'p', 'sub' => $data['roleNote']]) ?>
        <div class="roles" id="roles"></div>
        <div class="stack" id="roleStack"></div>
    <?php component_end() ?>
<?php endif; ?>

    <!-- Race population -->
    <?php component_start('section', ['title' => 'Population by Race', 'icon' => 'r', 'sub' => 'All time', 'body_class' => 'cols2']) ?>
        <div class="panel ally"><h5 class="ally-c">Alliance <small id="raceAT"></small></h5><div class="bars" id="raceA"></div></div>
        <div class="panel horde"><h5 class="horde-c">Horde <small id="raceHT"></small></h5><div class="bars" id="raceH"></div></div>
    <?php component_end() ?>

    <!-- Class distribution -->
    <?php component_start('section', ['title' => 'Class Distribution', 'icon' => 'g', 'sub' => 'Hover a bar for details']) ?>
        <div class="tabs" id="classTabs">
          <button data-k="A" class="on">Alliance</button>
          <button data-k="H">Horde</button>
          <button data-k="C">Combined</button>
        </div>
        <div class="panel">
          <h5 id="classTitle"></h5>
          <div class="bars" id="classBars"></div>
          <div class="legend" id="classLegend" hidden>
            <span><i style="background:#3a6ad0"></i>Alliance</span>
            <span><i style="background:#c0281a"></i>Horde</span>
          </div>
        </div>
    <?php component_end() ?>

    <!-- Talents -->
    <?php component_start('section', ['title' => 'Inspected Talent Builds', 'sub' => 'Latest inspection: ' . $inspectedAt]) ?>
        <div class="stats">
          <?= component('stat', ['label' => 'Players inspected', 'value' => fmt($coverage['inspected']), 'order' => 'value-first']) ?>
          <?= component('stat', ['label' => 'With talents chosen', 'value' => fmt($coverage['withSelectedTalents']), 'order' => 'value-first']) ?>
          <?= component('stat', ['label' => 'Linked to census', 'value' => fmt($coverage['linked']), 'order' => 'value-first']) ?>
          <?= component('stat', ['label' => 'Unlinked', 'value' => fmt($coverage['unmatched']), 'order' => 'value-first']) ?>
        </div>
        <p class="note">Inspections come from players who happened to be nearby, so they cover a different sample than the census and don't measure specialization shares for the whole population.</p>
    <?php component_end() ?>

    <div class="archived" style="margin-bottom:10px"><a class="btn-parch" href="<?= e(url('realms')) ?>">Open the Realm Viewer »</a> <a class="btn-parch" href="<?= e(url('combos')) ?>">Every Race &amp; Class »</a></div>
    <div class="source">Counts describe characters seen in in-game /who samples, not unique players. Latest census observation: <?= e($observedAt) ?></div>
  </main>

<?php start_section('scripts') ?>
<script>
const DATA = <?= js($data) ?>;
const sum = o => Object.values(o).reduce((a,b)=>a+b,0);
const pct = (n,t) => (n/t*100).toFixed(1) + '%';

// Faction balance (class-distribution totals = characters shown)
const aT = sum(DATA.cls.A), hT = sum(DATA.cls.H), all = aT + hT;
document.getElementById('aTot').textContent = fmt(aT);
document.getElementById('hTot').textContent = fmt(hT);
const tugA = document.getElementById('tugA'), tugH = document.getElementById('tugH');
tugA.style.width = tugH.style.width = '50%';
tugA.dataset.w = pct(aT, all); tugH.dataset.w = pct(hT, all);
tugA.textContent = pct(aT, all); tugH.textContent = pct(hT, all);
document.getElementById('tugNote').textContent =
  `Alliance leads by ${fmt(aT - hT)} characters (${fmt(all)} shown in total)`;
document.getElementById('totalNote').textContent = fmt(all) + ' characters';
grow(document.querySelector('.tug'));

// Top combos
for (const k of ['A','H']) {
  document.getElementById('top'+k).innerHTML = DATA.top[k].map(([n,c,p],i) => {
    const cls = n.split(' ').pop();
    return `<li><span class="medal m${i+1}">${i+1}</span>
      <div><div class="nm" style="color:${CLASS_COLOR[cls]}">${esc(shortRace(n))}</div>
      <div class="meta">${fmt(c)} characters</div></div>
      <span class="pct">${p}%</span></li>`;
  }).join('');
}

// Roles
if (DATA.roles) {
document.getElementById('roles').innerHTML = DATA.roles.map(([n,p,c]) => {
  const r = 38, C = 2*Math.PI*r;
  return `<div class="role"><div class="orb">
    <svg viewBox="0 0 86 86">
      <circle cx="43" cy="43" r="40" fill="#f8e7b5" stroke="#7a5a20" stroke-width="2"/>
      <circle cx="43" cy="43" r="${r}" fill="none" stroke="#3a2608" stroke-width="7" opacity=".25"/>
      <circle class="arc" cx="43" cy="43" r="${r}" fill="none" stroke="${c}" stroke-width="7"
        stroke-dasharray="0 ${C}" data-d="${C*p/100} ${C}" style="transition:stroke-dasharray 1.1s ease"/>
    </svg><b>${p}%</b></div><span>${n}</span></div>`;
}).join('');
document.getElementById('roleStack').innerHTML = DATA.roles.map(([n,p,c]) =>
  `<div style="width:${p}%;background:${c}" data-tip="${n}|${p}% of characters"></div>`).join('');
requestAnimationFrame(() => requestAnimationFrame(() =>
  document.querySelectorAll('.arc').forEach(a => a.setAttribute('stroke-dasharray', a.dataset.d))));
}

// Bars helper
function bars(el, rows, max, color, fmtTip) {
  el.innerHTML = rows.map(r => {
    const [name, n] = r;
    const fill = r.split
      ? `<div class="fill split" data-w="${n/max*100}%">${r.split.map(([v,c]) =>
          `<i style="width:${v/n*100}%;background:${c}"></i>`).join('')}</div>`
      : `<div class="fill" data-w="${n/max*100}%" style="background:${color(name)}"></div>`;
    return `<div class="bar" data-tip="${esc(shortRace(fmtTip(r)))}">
      <span class="lbl" style="color:${color(name)}">${esc(shortRace(name))}</span>
      <div class="track">${fill}</div><span class="val">${fmt(n)}</span></div>`;
  }).join('');
  grow(el);
}

// Races
const raceShade = { A:['#7ab0ff','#5a90e8','#4a7ad0','#3a64b8','#2e52a0'], H:['#ff7a6a','#e85a48','#d04a3a','#b83a2c','#a02e22'] };
for (const k of ['A','H']) {
  const rows = DATA.race[k], t = rows.reduce((a,r)=>a+r[1],0);
  document.getElementById('race'+k+'T').textContent = fmt(t) + ' characters';
  const shade = Object.fromEntries(rows.map((r,i)=>[r[0], raceShade[k][Math.min(i, 4)]]));
  bars(document.getElementById('race'+k), rows, rows[0][1], n => shade[n],
    ([n,c]) => `${n}|${fmt(c)} characters|${pct(c,t)} of ${k==='A'?'Alliance':'Horde'}`);
}

// Classes
function showClass(k) {
  document.querySelectorAll('#classTabs button').forEach(b => b.classList.toggle('on', b.dataset.k === k));
  const title = document.getElementById('classTitle'), el = document.getElementById('classBars');
  document.getElementById('classLegend').hidden = k !== 'C';
  let rows, t, tip;
  if (k === 'C') {
    rows = Object.keys(CLASS_COLOR).filter(c => DATA.cls.A[c] || DATA.cls.H[c]).map(c => {
      const a = DATA.cls.A[c] || 0, h = DATA.cls.H[c] || 0;
      const r = [c, a+h]; r.split = [[a,'#3a6ad0'],[h,'#c0281a']]; r.a = a; r.h = h; return r;
    }).sort((x,y)=>y[1]-x[1]);
    t = all;
    title.innerHTML = `<span style="color:#ffd75a">Both Factions</span><small>${fmt(t)} characters shown</small>`;
    tip = r => `${r[0]}|${fmt(r[1])} total (${pct(r[1],t)})|Alliance ${fmt(r.a)} · Horde ${fmt(r.h)}`;
  } else {
    rows = Object.entries(DATA.cls[k]).sort((x,y)=>y[1]-x[1]);
    t = sum(DATA.cls[k]);
    const name = k==='A' ? 'Alliance' : 'Horde';
    title.innerHTML = `<span class="${k==='A'?'ally-c':'horde-c'}">${name}</span><small>${fmt(t)} characters shown</small>`;
    tip = ([n,c]) => `${n}|${fmt(c)} characters|${pct(c,t)} of ${name}`;
  }
  bars(el, rows, rows[0][1], n => CLASS_COLOR[n], tip);
}
document.getElementById('classTabs').addEventListener('click', e => {
  if (e.target.dataset.k) showClass(e.target.dataset.k);
});
showClass('A');

</script>
<?php end_section() ?>
