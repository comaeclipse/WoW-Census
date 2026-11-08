<?php
namespace Wow2008;
/**
 * Page: Race Specs &amp; Talents
 * Vars: $data (array: races by slug, order, treePoints, levelCap), $route (array, exposed to page JS as window.ROUTE)
 */
?>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Talents', 'url' => url('talents')], ['label' => 'Races', 'id' => 'crumbRace']]]) ?>

    <div class="racebar" id="racebar"></div>

    <div class="race-hero" id="hero"></div>

    <div class="tiles5" id="rtiles"></div>

    <div class="cols2" style="margin-bottom:16px;align-items:start">
      <?php component_start('section', ['title' => 'Most Popular Specs', 'icon' => 'g', 'sub_id' => 'specSub', 'style' => 'margin:0']) ?>
        <div class="panel"><ol class="board" id="specBoard"></ol></div>
      <?php component_end() ?>
      <div>
        <?php component_start('section', ['title' => 'Class Mix', 'icon' => 'p', 'sub' => 'of the inspected sample']) ?>
          <div class="cstrip" id="mixStrip"></div><div class="ckey" id="mixKey"></div>
        <?php component_end() ?>
        <?php component_start('section', ['title' => 'Hybrids & Unknowns', 'icon' => 'r', 'style' => 'margin:0']) ?>
            <p class="note" style="margin:0 0 8px">Builds with points spread across trees. They're counted by class and kept out of the spec ranking.</p>
            <div class="panel"><ol class="board" id="hybBoard"></ol></div>
        <?php component_end() ?>
      </div>
    </div>

    <?php component_start('section', ['title' => 'Talents by Class', 'sub' => 'Average talent points per tree, and the most-picked talents']) ?>
        <div class="cchips" id="cchips"></div>
        <div class="ccards" id="ccards"></div>
    <?php component_end() ?>

    <div class="source">Race data from inspected nearby players with readable talents</div>
  </main>

<?php start_section('scripts') ?>
<script>
const RACE_DATA = <?= js($data) ?>;
const RD = RACE_DATA.races;
// Page URLs: /<edition>/<race>/ with pretty URLs, or index.php?edition=..&page=<race> without.
const ROUTE = window.ROUTE || { edition: 'tbc', base: '/tbc/', initial: 'night-elf' };
const slugUrl = s => ROUTE.queryUrls
  ? `${location.pathname}?edition=${encodeURIComponent(ROUTE.edition)}&page=${encodeURIComponent(s)}`
  : `${ROUTE.base}${s}/`;
function slugFromUrl() {
  const q = new URLSearchParams(location.search);
  const s = ROUTE.queryUrls ? q.get('page') : location.pathname.replace(/\/+$/, '').split('/').pop();
  return RD[s] ? s : null;
}
const ORDER = RACE_DATA.order;
const PLURAL = Object.fromEntries(ORDER.map(s => [s, RD[s].plural]));
const cico = (cls, cl = 'cico') => `<span class="${cl}">${wowClassIcon(cls)}</span>`;

// ---------- Original heraldic crests (generic symbols, not in-game emblems) ----------
const CREST = {
  human:      { f:'#1a3a8a', c:'#ffd75a', s:'M32 14 L36 24 L44 18 L42 30 L22 30 L20 18 L28 24Z M22 33 H42 V37 H22Z' },              // crown
  dwarf:      { f:'#5a3a1a', c:'#e0c090', s:'M22 14 H42 V24 H35 V46 H29 V24 H22Z' },                                                 // hammer
  gnome:      { f:'#2a5a6a', c:'#d0f0ff', s:'M32 18 A10 10 0 1 0 32.01 18Z M32 23 A5 5 0 1 1 31.99 23Z M30 12 H34 V17 H30Z M30 39 H34 V44 H30Z M18 26 H23 V30 H18Z M41 26 H46 V30 H41Z' }, // gear
  'night-elf':{ f:'#2a1a5a', c:'#d8c8ff', s:'M38 12 A14 14 0 1 0 38 44 A11 11 0 1 1 38 12Z' },                                       // crescent
  draenei:    { f:'#3a2a7a', c:'#a8e8ff', s:'M32 10 L40 26 L32 46 L24 26Z M32 10 V46 M24 26 H40' },                                 // crystal
  orc:        { f:'#5a1408', c:'#e0e0e0', s:'M20 14 L44 42 M44 14 L20 42 M16 12 Q22 10 26 18 L21 22 Q16 18 16 12Z M48 12 Q42 10 38 18 L43 22 Q48 18 48 12Z' }, // crossed axes
  undead:     { f:'#2a3a2a', c:'#b8e0a0', s:'M24 30 Q20 14 32 12 Q44 14 40 30 L38 38 H26Z M27 24 A3 3 0 1 0 27.01 24Z M37 24 A3 3 0 1 0 37.01 24Z M29 38 V43 M35 38 V43' }, // skull
  tauren:     { f:'#6a3a14', c:'#f0d8a0', s:'M14 16 Q16 28 26 28 M50 16 Q48 28 38 28 M24 26 Q32 22 40 26 Q40 40 32 44 Q24 40 24 26Z' }, // horns
  troll:      { f:'#1a4a3a', c:'#e8ffd8', s:'M20 40 Q18 22 26 14 Q24 28 28 40Z M44 40 Q46 22 38 14 Q40 28 36 40Z M26 42 H38' },      // tusks
  'blood-elf':{ f:'#7a1010', c:'#ffd75a', s:'M32 18 A8 8 0 1 0 32.01 18Z M32 6 V12 M32 44 V50 M20 28 H14 M50 28 H44 M23 19 L19 15 M41 19 L45 15 M23 37 L19 41 M41 37 L45 41' } // sun
};
// Races the mockup drew no crest for get a plain one in their faction's colours, with their initial.
const crestFor = slug => CREST[slug] || { f: { A: '#1a3a8a', H: '#5a1408' }[RD[slug].faction] || '#3a3a2a', c: '#ffd75a', letter: RD[slug].name[0] };
function crest(slug, w = 64, h = 76) {
  const c = crestFor(slug), trim = RD[slug].faction === 'A' ? '#ffd75a' : '#c9c9c9';
  return `<svg viewBox="0 0 64 76" width="${w}" height="${h}" aria-hidden="true">
    <defs><linearGradient id="cg-${slug}-${w}" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="${shade(c.f, .25)}"/><stop offset="1" stop-color="${shade(c.f, -.45)}"/></linearGradient></defs>
    <path d="M4 4 H60 V40 Q60 64 32 74 Q4 64 4 40Z" fill="url(#cg-${slug}-${w})" stroke="${trim}" stroke-width="3"/>
    <path d="M10 10 H54 V40 Q54 58 32 67 Q10 58 10 40Z" fill="none" stroke="${trim}" stroke-opacity=".35"/>
    ${c.s ? `<path d="${c.s}" transform="translate(0 6)" fill="${c.c}" fill-opacity=".85" stroke="${shade(c.c, -.6)}" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" fill-rule="evenodd"/>`
      : `<text x="32" y="46" text-anchor="middle" font-family="Tahoma, Arial, sans-serif" font-weight="900" font-size="26" fill="${c.c}" stroke="${shade(c.c, -.6)}" stroke-width="1">${esc(c.letter)}</text>`}
  </svg>`;
}

// ---------- Race selector ----------
const bar = document.getElementById('racebar');
bar.innerHTML = [['A','Alliance','#6fa8ff'],['H','Horde','#ff5a4a'],['N','Neutral','#e8d8a0']].filter(([f]) => ORDER.some(s => RD[s].faction === f)).map(([f, label, col]) => `
  <span class="fl" style="color:${col}">${label}</span>
  <div class="row">${ORDER.filter(s => RD[s].faction === f).map(s =>
    `<a class="rbtn" href="${slugUrl(s)}" data-r="${s}">${wowIcon(RD[s].icon, crest(s, 22, 26))}${RD[s].name}</a>`).join('')}</div>`).join('');
bar.addEventListener('click', e => {
  const a = e.target.closest('.rbtn'); if (!a) return;
  e.preventDefault(); show(a.dataset.r, true);
});

let cur = null, clsFilter = '';

function show(slug, push) {
  const r = RD[slug]; if (!r) return;
  cur = slug; clsFilter = '';
  if (push) history.pushState({ slug }, '', slugUrl(slug));
  document.title = `${r.name} Specs & Talents – WoWCensus`;
  document.getElementById('crumbRace').textContent = r.name;
  bar.querySelectorAll('.rbtn').forEach(b => b.classList.toggle('on', b.dataset.r === slug));

  const ally = r.faction === 'A';
  const [topClass] = r.tiles['Top class'] || ['—'], [topSpec, topSpecSub] = r.tiles['Top spec'] || ['—', ''];
  const topSpecRow = r.specs[0] || ['—', topClass, 0, 0];

  // hero
  const hues = ally ? ['#02061a', '#1a3a8a', '#7ab0ff'] : ['#140202', '#7a1408', '#ff8a5a'];
  document.getElementById('hero').innerHTML = `
    <svg class="bg" viewBox="0 0 880 170" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
      <defs>
        <linearGradient id="rh-sky" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="${hues[0]}"/><stop offset=".65" stop-color="${hues[1]}"/><stop offset="1" stop-color="${hues[2]}"/></linearGradient>
        <radialGradient id="rh-glow" cx="86%" cy="45%" r="28%"><stop offset="0" stop-color="#fff" stop-opacity=".55"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></radialGradient>
        <linearGradient id="rh-fade" x1="0" x2="1"><stop offset="0" stop-color="#000" stop-opacity=".85"/><stop offset=".6" stop-color="#000" stop-opacity="0"/></linearGradient>
      </defs>
      <rect width="880" height="170" fill="url(#rh-sky)"/>
      <rect width="880" height="170" fill="url(#rh-glow)"/>
      <path d="M0 170 L0 142 L140 134 L300 146 L460 132 L620 148 L880 138 L880 170Z" fill="#000" opacity=".7"/>
      <rect width="880" height="170" fill="url(#rh-fade)"/>
    </svg>
    <div class="crest">${wowIcon(r.icon, crest(slug, 118, 140))}</div>
    <div class="t">
      <div class="kick" style="color:${ally ? '#9cc4ff' : '#ffb0a0'}">${ally ? 'ALLIANCE' : 'HORDE'} · SPECS &amp; TALENTS</div>
      <h1>${esc(r.name)}</h1>
      <div class="intro">Of the ${fmt(r.sample)} ${PLURAL[slug]} we've inspected, the <b style="color:${CLASS_COLOR[topClass]}">${esc(topClass)}</b> is the most common class,
        and <b style="color:${CLASS_COLOR[topSpecRow[1]]}">${esc(topSpec)} ${esc(topSpecRow[1])}</b> is the most common build.</div>
    </div>`;

  // tiles
  const tile = (k, v, sub, col) => `<div class="stat"><span>${k}</span><b${col ? ` style="color:${shade(col, -.35)}"` : ''}>${esc(v)}</b>${sub ? `<em>${esc(sub)}</em>` : ''}</div>`;
  const tank = r.tiles['Top tank spec'] || ['—', null];
  const tankCls = Object.keys(CLASS_COLOR).find(c => tank[0].includes(c));
  document.getElementById('rtiles').innerHTML =
    tile('Inspected', fmt(r.sample), `${PLURAL[slug]} with talents`) +
    tile('Top class', topClass, r.tiles['Top class'][1], CLASS_COLOR[topClass]) +
    tile('Top spec', topSpec, topSpecSub, CLASS_COLOR[topSpecRow[1]]) +
    tile('Top tank spec', tank[0], tank[1], tankCls && CLASS_COLOR[tankCls]) +
    tile('Median level', r.tiles['Median level'] ? r.tiles['Median level'][0] : '—', 'across the sample');

  // spec board
  document.getElementById('specSub').textContent = `${r.specs.length} identified builds`;
  const maxSpec = r.specs[0][2];
  const boardRow = (rank, label, sub, cls, n, p, max) => `<li data-tip="${esc(label)}${sub ? ' · ' + esc(sub) : ''}|${fmt(n)} players|${p}% of inspected ${PLURAL[slug]}">
    <span class="rk">${rank}</span>${cico(cls)}
    <span class="nm"><b style="color:${CLASS_COLOR[cls]}">${esc(label)}</b><small>${esc(sub)}</small></span>
    <span class="track"><span class="fill" style="display:block;background:${CLASS_COLOR[cls]}" data-w="${n / max * 100}%"></span></span>
    <span class="nv">${fmt(n)}<span>${p}%</span></span></li>`;
  document.getElementById('specBoard').innerHTML = !r.specs.length ? '<li style="display:block" class="nosplit">No single-spec builds identified.</li>' : r.specs.map(([spec, cls, n, p], i) => boardRow(i + 1, spec, cls, cls, n, p, maxSpec)).join('');

  // hybrids
  const hb = document.getElementById('hybBoard');
  hb.innerHTML = r.hybrids.length
    ? r.hybrids.map(([cls, n, p]) => boardRow('~', cls, 'Hybrid / unknown', cls, n, p, maxSpec)).join('')
    : `<li style="display:block" class="nosplit">No hybrid builds among inspected ${PLURAL[slug]}.</li>`;

  // class mix
  document.getElementById('mixStrip').innerHTML = r.classMix.map(([c, p]) =>
    `<div style="flex:0 1 0;background:${CLASS_COLOR[c]}" data-g="${p}" data-tip="${c}|${p}% of inspected ${PLURAL[slug]}"></div>`).join('');
  document.getElementById('mixKey').innerHTML = r.classMix.map(([c, p]) =>
    `<span><i style="background:${CLASS_COLOR[c]}"></i><b style="color:${shade(CLASS_COLOR[c], -.45)}">${c}</b> ${p}%</span>`).join('');

  // class chips + cards
  document.getElementById('cchips').innerHTML = `<button data-c="" class="on">All</button>` +
    r.classes.map(c => `<button data-c="${c.cls}">${c.cls}</button>`).join('');
  renderCards();
  grow(document.querySelector('.main'));
  window.scrollTo({ top: 0, behavior: push ? 'smooth' : 'auto' });
}

function renderCards() {
  const r = RD[cur];
  const el = document.getElementById('ccards');
  el.innerHTML = r.classes.filter(c => !clsFilter || c.cls === clsFilter).map(c => {
    const col = CLASS_COLOR[c.cls];
    // spec colours: shades of the class colour, in the order the source lists them
    const splitCols = c.split.map((s, i) => s[0] === 'Hybrid' ? shade(col, -.8) : shade(col, [0, -.35, -.6][i] ?? -.7));
    return `<article class="ccard" style="border-color:${shade(col, -.25)}">
      <div class="ch">${cico(c.cls)}<h4 style="color:${col}">${c.cls}</h4>
        <div class="cn"><b>${fmt(c.n)}</b>inspected · ${c.pct}%</div></div>
      <div class="cb">
        <p class="lbl">Spec split</p>
        ${c.split.length ? `<div class="sbar">${c.split.map((s, i) => `<i style="width:${s[1]}%;background:${splitCols[i]}" data-tip="${c.cls} · ${esc(s[0])}|${s[1]}% of inspected ${c.cls}s"></i>`).join('')}</div>
          <div class="skey">${c.split.map((s, i) => `<span><b style="color:${splitCols[i] === shade(col, -.8) ? '#aaa' : splitCols[i]}">${esc(s[0])}</b> ${s[1]}%</span>`).join('')}</div>`
          : `<div class="nosplit">Every inspected ${c.cls.toLowerCase()} had a hybrid build, so there's no spec split.</div>`}
        ${c.trees.length ? `<p class="lbl">Avg points per tree</p>
        <div class="trees">${c.trees.map(([t, v]) => `<div class="tree" data-tip="${esc(t)}|${v} points on average|out of ${RACE_DATA.treePoints} at level ${RACE_DATA.levelCap}">
          <div class="tv" style="color:${col}">${v}</div><div class="tn">${esc(t)}</div><div class="tb"><i data-w="${v / RACE_DATA.treePoints * 100}%" style="background:${col}"></i></div></div>`).join('')}</div>` : ''}
        <p class="lbl">Most-picked talents</p>
        <ul class="tal">${c.talents.map(([t, tree, p, avg, max, icon]) => {
          const initials = t.replace(/\(.*\)/, '').split(/[\s:-]+/).filter(w => /^[A-Z]/.test(w)).slice(0, 2).map(w => w[0]).join('');
          const filled = avg != null ? Math.round(avg) : 0;
          return `<li data-tip="${esc(t)}|${esc(tree)} · picked by ${p}%${avg != null ? `|average rank ${avg} of ${max}` : ''}">
            <span class="ticon" style="background:radial-gradient(circle at 35% 30%,${shade(col, .2)},${shade(col, -.45)} 70%,${shade(col, -.8)})">${wowIcon(icon, initials)}</span>
            <div><div class="tn">${esc(t)}</div>
              <div class="tm">${max ? `<span class="pips">${Array.from({ length: max }, (_, i) => `<i class="${i < filled ? 'on' : ''}"></i>`).join('')}</span>` : ''}${esc(tree)}</div></div>
            <div class="tp">${p}%<div class="mini"><i data-w="${p}%"></i></div></div></li>`;
        }).join('')}</ul>
      </div>
      ${c.median ? `<div class="cf">Median level ${c.median}</div>` : ''}
    </article>`;
  }).join('');
  grow(el);
}
document.getElementById('cchips').addEventListener('click', e => {
  if (e.target.dataset.c == null) return;
  clsFilter = e.target.dataset.c;
  document.querySelectorAll('#cchips button').forEach(b => b.classList.toggle('on', b === e.target));
  renderCards();
});

window.addEventListener('popstate', e => show((e.state && e.state.slug) || slugFromUrl() || ROUTE.initial, false));
const start = slugFromUrl() || ROUTE.initial;
show(RD[start] ? start : ORDER[0], false);

</script>
<?php end_section() ?>
