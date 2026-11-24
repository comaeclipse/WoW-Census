<?php
namespace Wow2008;
/**
 * Page: WoW: Forever combat logs. /forever/combat-logs/ is the overview of everything collected;
 * /forever/combat-logs/<class>/ opens the same page on one class ($route['initial']).
 * Vars: $data (forever_combat_logs_model() + classes, or null before any log is imported), $route
 */
$initial = $route['initial'] ?? 'all';
$initialLabel = 'Overview';
foreach ($data['classes'] ?? [] as $c) if ($c['slug'] === $initial) $initialLabel = $c['name'];
?>
<style>
.cbtn.cl-empty{opacity:.45}
.cl-split{display:flex;height:22px;border:2px solid #000;outline:1px solid #8a7040;border-radius:3px;overflow:hidden;background:#1a160c}
.cl-split i{display:flex;align-items:center;justify-content:center;font:700 10px Tahoma,Arial,sans-serif;font-style:normal;color:#fff;text-shadow:0 1px 1px #000;white-space:nowrap;overflow:hidden;transition:flex-grow .8s ease;flex-basis:0}
.cl-legend{display:flex;flex-wrap:wrap;gap:4px 14px;margin-top:8px;font-size:11px}
.cl-legend i{display:inline-block;width:10px;height:10px;margin-right:5px;border:1px solid #000;vertical-align:-1px}
.cl-levels{display:flex;align-items:flex-end;gap:3px;height:70px;margin-top:12px;padding:6px 6px 0;border:2px solid #000;outline:1px solid #8a7040;border-radius:4px;background:linear-gradient(#1c1810,#0e0c08)}
.cl-levels div{flex:1;display:flex;flex-direction:column;justify-content:flex-end;height:100%;cursor:default}
.cl-levels i{display:block;height:0;background:linear-gradient(#ffd75a,#b07a00);border:1px solid #000;border-bottom:0;transition:height .8s ease;min-height:2px}
.cl-levels span{font:9px Verdana;color:#d8b8a0;text-align:center;padding:2px 0}
.cl-cap{font-size:10px;opacity:.75;margin:5px 2px 0}
.cl-tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;margin:0 0 10px}
.cl-mode{display:flex;gap:6px}
.cl-mode .pill{font:700 11px Tahoma,Arial,sans-serif;padding:3px 12px;border:1px solid #6a5022;border-radius:12px;background:linear-gradient(#f4e4b0,#d8bc78);color:#3a1a00;cursor:pointer}
.cl-mode .pill.on{background:linear-gradient(#5a1a10,#2a0806);color:#ffd75a;border-color:#000}
#clq{font:11px Verdana;padding:3px 6px;width:170px}
.cl-sp{display:inline-flex;align-items:center;gap:6px;vertical-align:middle}
.cl-sp img{width:18px;height:18px;border:1px solid #000;border-radius:2px;flex:none}
.ahtable .cl-dim,.cl-dim{color:#a89878}
.ahtable a.cl-mob{font-weight:normal;color:#e8dcc0}
.cl-bar{display:inline-block;width:80px;height:8px;margin-right:6px;vertical-align:middle;background:#2a2418;border:1px solid #000;border-radius:2px;overflow:hidden}
.cl-bar i{display:block;width:0;height:100%;background:#b07a00;transition:width .8s ease}
.cl-ab td,.cl-nw{white-space:nowrap}
.class-hero .intro a{color:#ffd75a}
.ahtable tr.cl-none td{color:#7a6a50}
.ahtable td.cl-k{color:#d8c8a0}
#cloverview .ahwin{margin:0}
</style>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Combat Logs', 'url' => \forever_combat_logs_path()], ['label' => $initialLabel, 'id' => 'crumbCls']]]) ?>
<?php if (!$data): ?>
    <?php component_start('section', ['title' => 'Combat Logs', 'icon' => 'g']) ?>
      <p>No combat logs have been imported yet. <?= discord_link('Join our Discord') ?> to learn how to upload yours.</p>
    <?php component_end() ?>
  </main>
<?php else: ?>
    <div class="classbar" id="clbar"></div>

    <div class="race-hero class-hero" id="clhero"></div>

    <?= component('notice', ['title' => 'This data may be incomplete', 'html' => 'Everything below comes from the combat logs players have uploaded so far, so it covers only the zones, levels and classes those players happened to fight in. '
      . discord_link('Join our Discord') . ' to learn how you can contribute your logs and make it more accurate!']) ?>

    <div class="tiles5" id="cltiles"></div>

    <?php component_start('section', ['title' => 'Everything Collected', 'icon' => 'g', 'sub_id' => 'ovSub', 'id' => 'cloverview']) ?>
      <div class="cols2" style="align-items:start">
        <div class="ahwin"><div class="wt">Class Coverage<small>player side</small></div><div style="overflow-x:auto"><table class="ahtable"><thead><tr><th>Class</th><th class="num">Events</th><th class="num">Damage</th><th class="num">Healing</th><th class="num">Abilities</th><th class="num">Biggest hit</th></tr></thead><tbody id="clcover"></tbody></table></div></div>
        <div class="ahwin"><div class="wt">Mob Data<small>creature side</small></div><table class="ahtable"><thead><tr><th>Measured</th><th class="num">Count</th></tr></thead><tbody id="clmobdata"></tbody></table></div>
      </div>
    <?php component_end() ?>

    <div class="cols2" style="margin-bottom:16px;align-items:start">
      <?php component_start('section', ['title' => 'Damage by Class', 'icon' => 'g', 'sub_id' => 'boardSub', 'id' => 'clboardSec', 'style' => 'margin:0']) ?>
        <div class="panel"><ol class="board" id="clboard"></ol></div>
      <?php component_end() ?>
      <div>
        <?php component_start('section', ['title' => 'Combat Type', 'icon' => 'p', 'sub_id' => 'modeSub', 'style' => 'margin:0 0 14px']) ?>
          <div class="cl-split" id="clsplit"></div>
          <div class="cl-legend" id="cllegend"></div>
        <?php component_end() ?>
        <?php component_start('section', ['title' => 'Where It Was Logged', 'icon' => 'r', 'sub' => 'mobs seen per zone', 'style' => 'margin:0']) ?>
          <div class="ahwin"><div class="wt">Zones<small>sightings</small></div><table class="ahtable"><thead><tr><th>Zone</th><th class="num">Mobs</th><th class="num">Sightings</th><th>Share</th></tr></thead><tbody id="clzones"></tbody></table></div>
          <div class="cl-levels" id="cllevels"></div>
          <div class="cl-cap">Mob levels measured (distinct mobs per level)</div>
        <?php component_end() ?>
      </div>
    </div>

    <?php component_start('section', ['title' => 'Class Abilities', 'icon' => 'p', 'sub' => 'every hit, tick and heal in the logs', 'id' => 'abilities']) ?>
      <div class="cl-tools"><span class="cl-mode" id="clmode"></span><input type="search" id="clq" placeholder="Find an ability…" aria-label="Find an ability"></div>
      <div class="ahwin"><div class="wt" id="abWt">Abilities</div>
        <div style="overflow-x:auto"><table class="ahtable cl-ab"><thead><tr><th>Ability</th><th id="abClsTh">Class</th><th class="num">Average</th><th class="num">Range</th><th class="num">Hits</th><th class="num">Crit</th><th class="num">Total</th><th style="width:150px">Share</th></tr></thead><tbody id="clab"></tbody></table></div>
      </div>
    <?php component_end() ?>

    <div class="cols2" style="margin-bottom:16px;align-items:start">
      <?php component_start('section', ['title' => 'Most Killed', 'icon' => 'r', 'sub' => 'deaths in the logs', 'style' => 'margin:0']) ?>
        <div class="ahwin"><div class="wt">Bestiary<small><a href="<?= e(\forever_npc_path()) ?>" style="color:#d8b8a0">all mobs »</a></small></div><table class="ahtable"><thead><tr><th>#</th><th>Mob</th><th class="num">Level</th><th class="num">Kills</th></tr></thead><tbody id="clkills"></tbody></table></div>
      <?php component_end() ?>
      <?php component_start('section', ['title' => 'Hardest Hitters', 'icon' => 'r', 'sub' => 'biggest single hit on a player', 'style' => 'margin:0']) ?>
        <div class="ahwin"><div class="wt">Mob Abilities<small>top 10</small></div><table class="ahtable"><thead><tr><th>Ability</th><th>Mob</th><th class="num">Hits</th><th class="num">Biggest</th></tr></thead><tbody id="clhits"></tbody></table></div>
      <?php component_end() ?>
    </div>

    <?php component_start('section', ['title' => 'Uploaded Logs', 'icon' => 'g', 'sub' => 'uploaders are never stored or shown']) ?>
      <div class="ahwin"><div class="wt">Combat Log Archive<small id="filesSub"></small></div><table class="ahtable"><thead><tr><th>Log</th><th>Recorded</th><th class="num">Length</th><th class="num">Events</th><th class="num">Mobs</th></tr></thead><tbody id="clfiles"></tbody></table></div>
      <p class="cl-cap" style="margin-top:8px">Logs come from <b>WoWCombatLog.txt</b> files players upload with the WoWCensus Companion. Player names are dropped on import; only class, level and spell totals are kept. Damage includes absorbed damage.</p>
    <?php component_end() ?>
  </main>

<?php start_section('scripts') ?>
<script>
const D = <?= js($data) ?>;
const CLS = Object.fromEntries(D.classes.map(c => [c.slug, c]));
const ORDER = ['all', ...D.classes.map(c => c.slug)];
const NAME = s => s === 'all' ? 'All Classes' : CLS[s].name;
const GOLD = '#ffd75a';
const COL = s => s === 'all' ? '#c9a050' : (CLASS_COLOR[NAME(s)] || '#c9a050');
const MODES = [['PvE', '#c0392b'], ['Healing', '#3fa34d'], ['PvP', '#6a5acd']];
const pct = (a, b) => b ? Math.round(a / b * 1000) / 10 : 0;
const sum = (a, k) => a.reduce((t, r) => t + r[k], 0);
const spellsOf = s => D.spells.filter(r => s === 'all' || r.c === CLS[s].cf);
const mins = (a, b) => Math.max(0, Math.round((new Date(b) - new Date(a)) / 60000));
const dur = m => m >= 60 ? `${Math.floor(m / 60)}h ${m % 60}m` : `${m}m`;
const playMin = D.files.reduce((t, f) => t + mins(f.first, f.last), 0);
const img = src => `<img src="${esc(src)}" alt="" loading="lazy">`;
const spellName = (id, name, ic) => `<span class="cl-sp">${img(ic)}${id ? `<a href="/forever/spell/${id}">${esc(name)}</a>` : `<b>${esc(name)}</b>`}</span>`;
const list = a => a.length > 2 ? a.slice(0, -1).join(', ') + ' and ' + a[a.length - 1] : a.join(' and ');
const route = window.ROUTE || { base: '/forever/combat-logs/', initial: 'all' };
const viewUrl = s => s === 'all' ? route.base : `${route.base}${s}/`;

const bar = document.getElementById('clbar');
bar.innerHTML = ORDER.map(s => `<a class="cbtn${s === 'all' || spellsOf(s).length ? '' : ' cl-empty'}" href="${viewUrl(s)}" data-c="${s}" style="text-decoration:none">
  <span class="ci">${s === 'all' ? wowIcon('ability_dualwield') : wowClassIcon(NAME(s))}</span>${s === 'all' ? 'Overview' : esc(NAME(s))}</a>`).join('');
bar.addEventListener('click', e => { const a = e.target.closest('.cbtn'); if (!a) return; e.preventDefault(); show(a.dataset.c, true); });

let cur = 'all', mode = 'PvE', query = '';

function hero(s) {
  const col = COL(s), rows = spellsOf(s), pve = rows.filter(r => r.m === 'PvE');
  const top = pve.filter(r => r.id !== 0).sort((a, b) => b.amt - a.amt)[0];
  const emb = s === 'all' ? img('/assets/images/wow/icons/large/inv_misc_note_06.jpg').replace('<img', '<img class="hires"')
    : `<img class="hires" src="/wow2008/class-icons/${s}.png?v=<?= e(ASSET_VERSION) ?>" alt="">`;
  const intro = s === 'all'
    ? `We've parsed <b style="color:${GOLD}">${fmt(D.files.length)} combat log${D.files.length === 1 ? '' : 's'}</b> so far: ${fmt(D.totals.events)} events across ${dur(playMin)} of play${D.zones.length ? ' in ' + list(D.zones.slice(0, 4).map(z => esc(z.n))) + (D.zones.length > 4 ? ' and more' : '') : ''}.
       That's <b style="color:${GOLD}">${fmt(D.totals.npcs)}</b> mobs measured, ${fmt(D.totals.kills)} kills and ${fmt(D.totals.classSpells)} player spells identified.`
    : rows.length
      ? `${fmt(sum(rows, 'ev'))} hits, ticks and heals from ${esc(NAME(s).toLowerCase())}s in the logs.${top ? ` <b style="color:${shade(col, .3)}">${esc(top.n)}</b> is their biggest ability after auto-attacks,` : ''}
         with ${fmt(sum(pve, 'amt'))} damage recorded in total. <a href="${esc(CLS[s].path)}">${esc(NAME(s))} talents »</a>`
      : `No ${esc(NAME(s).toLowerCase())} has shown up in an uploaded combat log yet. <a href="${esc(CLS[s].path)}">${esc(NAME(s))} talents »</a>`;
  document.getElementById('clhero').innerHTML = `<div class="emb">${emb}</div>
    <div class="t"><div class="kick" style="color:${shade(col, .35)}">COMBAT LOGS · ${s === 'all' ? 'OVERVIEW' : 'CLASS'}</div>
    <h1 style="color:${shade(col, .1)}">${s === 'all' ? 'Combat Logs' : esc(NAME(s))}</h1><div class="intro">${intro}</div></div>`;
}

function tiles(s) {
  const el = document.getElementById('cltiles');
  if (s === 'all') {
    el.innerHTML = `
      <div class="stat"><span>Logs parsed</span><b>${fmt(D.files.length)}</b><em>${dur(playMin)} of play</em></div>
      <div class="stat"><span>Events</span><b>${fmt(D.totals.events)}</b><em>combat log lines read</em></div>
      <div class="stat"><span>Mobs measured</span><b>${fmt(D.totals.npcs)}</b><em>health, armor &amp; level</em></div>
      <div class="stat"><span>Kills</span><b>${fmt(D.totals.kills)}</b><em>in ${fmt(D.zones.length)} zone${D.zones.length === 1 ? '' : 's'}</em></div>
      <div class="stat"><span>Spells</span><b>${fmt(D.totals.classSpells + D.totals.npcSpells)}</b><em>${fmt(D.totals.classSpells)} player · ${fmt(D.totals.npcSpells)} mob</em></div>`;
    return;
  }
  const col = COL(s), rows = spellsOf(s), pve = rows.filter(r => r.m === 'PvE'), heal = rows.filter(r => r.m === 'Healing');
  const big = pve.slice().sort((a, b) => b.hi - a.hi)[0];
  const top = pve.filter(r => r.id !== 0).sort((a, b) => b.amt - a.amt)[0];
  el.innerHTML = `
    <div class="stat"><span>Damage</span><b>${fmt(sum(pve, 'amt'))}</b><em>${fmt(sum(pve, 'ev'))} hits</em></div>
    <div class="stat"><span>Crit rate</span><b>${pct(sum(pve, 'cr'), sum(pve, 'ev'))}%</b><em>damage only</em></div>
    <div class="stat"><span>Top ability</span><b style="color:${shade(col, -.45)}">${top ? esc(top.n) : '—'}</b><em>${top ? fmt(top.amt) + ' damage' : 'no spells yet'}</em></div>
    <div class="stat"><span>Biggest hit</span><b>${big ? fmt(big.hi) : '—'}</b><em>${big ? esc(big.n) : ''}</em></div>
    <div class="stat"><span>Healing</span><b>${fmt(sum(heal, 'amt'))}</b><em>${fmt(sum(heal, 'ev'))} heals &amp; ticks</em></div>`;
}

function board(s) {
  const ol = document.getElementById('clboard');
  const row = (rk, icon, label, sub, col, n, p, max, tip) => `<li data-tip="${tip}">
    <span class="rk">${rk}</span><span class="cico">${icon}</span>
    <span class="nm"><b style="color:${col}">${esc(label)}</b><small>${esc(sub)}</small></span>
    <span class="track"><span class="fill" style="display:block;background:${col}" data-w="${max ? n / max * 100 : 0}%"></span></span>
    <span class="nv">${fmt(n)}<span>${p}%</span></span></li>`;
  const title = document.querySelector('#clboardSec h2');
  if (s === 'all') {
    title.textContent = 'Damage by Class';
    const cls = ORDER.slice(1).map(c => [c, sum(spellsOf(c).filter(r => r.m === 'PvE'), 'amt')]).filter(x => x[1]).sort((a, b) => b[1] - a[1]);
    const tot = cls.reduce((t, x) => t + x[1], 0);
    document.getElementById('boardSub').textContent = `${fmt(tot)} damage`;
    ol.innerHTML = cls.length ? cls.map(([c, n], i) => row(i + 1, wowClassIcon(NAME(c)), NAME(c), `${spellsOf(c).filter(r => r.m === 'PvE').length} abilities`, COL(c), n, pct(n, tot), cls[0][1],
      `${esc(NAME(c))}|${fmt(n)} damage|${pct(n, tot)}% of all logged damage`)).join('') : '<li style="display:block" class="nosplit">No player damage logged yet.</li>';
  } else {
    title.textContent = 'Top Abilities';
    const all = spellsOf(s).filter(r => r.m === 'PvE'), tot = sum(all, 'amt');
    const rows = all.slice().sort((a, b) => b.amt - a.amt).slice(0, 10);
    document.getElementById('boardSub').textContent = `${NAME(s)} damage`;
    ol.innerHTML = rows.length ? rows.map((r, i) => row(i + 1, img(r.ic), r.n, `${fmt(r.ev)} hits · avg ${(r.amt / r.ev).toFixed(1)}`,
      COL(s), r.amt, pct(r.amt, tot), rows[0].amt, `${esc(r.n)}|${fmt(r.amt)} damage|${fmt(r.lo)}–${fmt(r.hi)} per hit`)).join('')
      : '<li style="display:block" class="nosplit">No damage logged for this class yet.</li>';
  }
}

function split(s) {
  const rows = spellsOf(s), tot = sum(rows, 'ev');
  const parts = MODES.map(([m, c]) => [m, c, sum(rows.filter(r => r.m === m), 'ev')]);
  document.getElementById('modeSub').textContent = `${fmt(tot)} events`;
  document.getElementById('clsplit').innerHTML = parts.map(([m, c, n]) => n ? `<i style="background:${c};flex-grow:0" data-g="${n}" title="${m} ${pct(n, tot)}%">${pct(n, tot) >= 6 ? `${m} ${pct(n, tot)}%` : ''}</i>` : '').join('');
  document.getElementById('cllegend').innerHTML = parts.map(([m, c, n]) => `<span><i style="background:${c}"></i><b>${m}</b> ${fmt(n)} · ${pct(n, tot)}%</span>`).join('');
}

function abilities() {
  const all = cur === 'all';
  document.getElementById('abClsTh').style.display = all ? '' : 'none';
  document.querySelector('#abilities h2').textContent = all ? 'Class Abilities' : `${NAME(cur)} Abilities`;
  document.getElementById('clmode').innerHTML = MODES.map(([m]) => `<button type="button" class="pill${m === mode ? ' on' : ''}" data-m="${m}">${m}</button>`).join('');
  let rows = spellsOf(cur).filter(r => r.m === mode);
  const tot = sum(rows, 'amt');
  if (query) rows = rows.filter(r => r.n.toLowerCase().includes(query));
  rows.sort((a, b) => b.amt - a.amt);
  document.getElementById('abWt').innerHTML = `${mode === 'Healing' ? 'Heals' : 'Damage'}<small>${fmt(rows.length)} abilities · ${fmt(tot)} total</small>`;
  const byCf = Object.fromEntries(D.classes.map(c => [c.cf, c.slug]));
  document.getElementById('clab').innerHTML = rows.length ? rows.slice(0, 60).map(r => {
    const c = byCf[r.c];
    return `<tr><td>${spellName(r.id, r.n, r.ic)}</td><td style="${all ? '' : 'display:none;'}color:${c ? COL(c) : '#e8dcc0'}">${c ? esc(NAME(c)) : esc(r.c)}</td>
      <td class="num">${(r.amt / r.ev).toFixed(1)}</td><td class="num cl-dim">${fmt(r.lo)}–${fmt(r.hi)}</td><td class="num">${fmt(r.ev)}</td>
      <td class="num">${pct(r.cr, r.ev)}%</td><td class="num">${fmt(r.amt)}</td>
      <td><span class="cl-bar"><i style="background:${c ? COL(c) : '#b07a00'}" data-w="${pct(r.amt, tot)}%"></i></span>${pct(r.amt, tot)}%</td></tr>`;
  }).join('') : `<tr><td colspan="8" class="cl-dim" style="text-align:center;padding:14px">No ${mode} events logged${all ? '' : ' for ' + esc(NAME(cur).toLowerCase()) + 's'} yet.</td></tr>`;
  grow(document.getElementById('abilities'));
}
document.getElementById('clmode').addEventListener('click', e => { const b = e.target.closest('[data-m]'); if (b) { mode = b.dataset.m; abilities(); } });
document.getElementById('clq').addEventListener('input', e => { query = e.target.value.trim().toLowerCase(); abilities(); });

// Overview only: what has been collected on each side of the fight.
const cover = ORDER.slice(1).map(s => {
  const rows = spellsOf(s), pve = rows.filter(r => r.m === 'PvE');
  return { s, ev: sum(rows, 'ev'), dmg: sum(pve, 'amt'), heal: sum(rows.filter(r => r.m === 'Healing'), 'amt'),
    ab: new Set(rows.map(r => r.id)).size, hi: pve.reduce((m, r) => Math.max(m, r.hi), 0) };
}).sort((a, b) => b.ev - a.ev);
const seen = cover.filter(c => c.ev).length;
document.getElementById('ovSub').textContent = `${seen} of ${cover.length} classes logged`;
document.getElementById('clcover').innerHTML = cover.map(c => `<tr class="${c.ev ? '' : 'cl-none'}"><td><span class="cl-sp">${wowClassIcon(NAME(c.s))}<a href="${viewUrl(c.s)}" data-c="${c.s}" style="color:${c.ev ? COL(c.s) : '#7a6a50'}">${esc(NAME(c.s))}</a></span></td>
  <td class="num">${c.ev ? fmt(c.ev) : '—'}</td><td class="num">${c.ev ? fmt(c.dmg) : '—'}</td><td class="num">${c.ev ? fmt(c.heal) : '—'}</td>
  <td class="num">${c.ev ? fmt(c.ab) : '—'}</td><td class="num">${c.hi ? fmt(c.hi) : '—'}</td></tr>`).join('');
const TOT = D.totals;
document.getElementById('clmobdata').innerHTML = [
  ['Mobs seen', TOT.npcs], ['… with level and health', TOT.withHp], ['… with armor', TOT.withArmor], ['Level readings', TOT.mobLevels],
  ['Mobs that hit a player', TOT.attackers], ['Mob attacks landed', TOT.mobHits], ['Damage taken by players', TOT.mobDamage],
  ['Mob spells identified', TOT.npcSpells], ['Kills', TOT.kills], ['Zones', D.zones.length],
].map(([k, v]) => `<tr><td class="cl-k">${k}</td><td class="num">${fmt(v)}</td></tr>`).join('');
document.getElementById('clcover').addEventListener('click', e => { const a = e.target.closest('a[data-c]'); if (!a) return; e.preventDefault(); show(a.dataset.c, true); scrollTo({ top: 0, behavior: 'smooth' }); });

// The whole collection, the same for every class.
const zMax = D.zones.length ? D.zones[0].s : 0, zTot = sum(D.zones, 's');
document.getElementById('clzones').innerHTML = D.zones.map(z => `<tr><td><a href="${esc(z.url)}">${esc(z.n)}</a></td><td class="num">${fmt(z.npcs)}</td><td class="num">${fmt(z.s)}</td>
  <td class="cl-nw"><span class="cl-bar" style="width:110px"><i data-w="${zMax ? z.s / zMax * 100 : 0}%"></i></span>${pct(z.s, zTot)}%</td></tr>`).join('');
const lvMax = Math.max(1, ...D.levels.map(l => l.n));
document.getElementById('cllevels').innerHTML = D.levels.map(l => `<div data-tip="Level ${l.lv}|${fmt(l.n)} mob${l.n === 1 ? '' : 's'} measured"><i data-h="${l.n / lvMax * 100}%"></i><span>${l.lv}</span></div>`).join('');
const mob = (name, url) => url ? `<a class="cl-mob" href="${esc(url)}">${esc(name)}</a>` : esc(name);
document.getElementById('clkills').innerHTML = D.kills.map((k, i) => `<tr><td class="cl-dim">${i + 1}</td><td>${k.url ? `<a href="${esc(k.url)}">${esc(k.n)}</a>` : esc(k.n)}</td><td class="num">${k.lv ?? '—'}</td><td class="num">${fmt(k.d)}</td></tr>`).join('');
document.getElementById('clhits').innerHTML = D.hits.map(h => `<tr><td>${spellName(h.id, h.ab, h.ic)}${h.sc ? `<small class="cl-dim"> ${esc(h.sc)}</small>` : ''}</td>
  <td>${mob(h.mob, h.url)}</td><td class="num">${fmt(h.h)}</td><td class="num" style="color:#ff6a4a">${fmt(h.mx)}</td></tr>`).join('');
document.getElementById('filesSub').textContent = `${fmt(D.files.length)} log${D.files.length === 1 ? '' : 's'} · ${fmt(D.totals.events)} events`;
document.getElementById('clfiles').innerHTML = D.files.map((f, i) => [f, i]).reverse().map(([f, i]) => `<tr><td><span class="cl-sp">${img(`/assets/images/wow/icons/large/inv_misc_note_0${(i % 6) + 1}.jpg`)}Log #${i + 1}</span></td>
  <td>${new Date(f.first).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}</td><td class="num">${dur(mins(f.first, f.last))}</td>
  <td class="num">${fmt(f.events)}</td><td class="num">${fmt(f.npcs)}</td></tr>`).join('');

// /forever/combat-logs/<class>/; older ?class=<slug> links move to the path form.
const fromUrl = () => {
  const legacy = new URLSearchParams(location.search).get('class');
  if (ORDER.includes(legacy)) { history.replaceState(null, '', viewUrl(legacy)); return legacy; }
  const seg = location.pathname.slice(route.base.length).replace(/\/+$/, '').split('/')[0];
  return ORDER.includes(seg) ? seg : 'all';
};
function show(s, push) {
  cur = s; query = ''; document.getElementById('clq').value = '';
  if (!spellsOf(s).some(r => r.m === mode)) mode = 'PvE';
  if (push) history.pushState({ s }, '', viewUrl(s));
  document.getElementById('crumbCls').textContent = s === 'all' ? 'Overview' : NAME(s);
  document.getElementById('cloverview').hidden = s !== 'all';
  document.title = s === 'all' ? 'Combat Logs – WoW: Forever – WoWCensus' : `${NAME(s)} Combat Logs – WoW: Forever – WoWCensus`;
  bar.querySelectorAll('.cbtn').forEach(b => b.classList.toggle('on', b.dataset.c === s));
  hero(s); tiles(s); board(s); split(s); abilities();
  grow(document.querySelector('.main'));
  requestAnimationFrame(() => requestAnimationFrame(() => document.querySelectorAll('[data-h]').forEach(el => el.style.height = el.dataset.h)));
}
addEventListener('popstate', () => show(fromUrl()));
show(fromUrl());
</script>
<?php end_section() ?>
<?php endif ?>
