<?php
namespace Wow2008;
/**
 * Page: Class Talents
 * Vars: $data (array: classes by slug, order), $route (array, exposed to page JS as window.ROUTE)
 */
?>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Talents', 'url' => url('talents')], ['label' => 'Classes', 'id' => 'crumbClass']]]) ?>

    <div class="classbar" id="clsbar"></div>

    <div class="race-hero class-hero" id="chero"></div>

    <div class="tiles5" id="ctiles"></div>

    <?php component_start('section', ['title' => 'Spec Split', 'icon' => 'p', 'sub_id' => 'splitSub']) ?>
        <div class="splitbig" id="splitBar"></div>
        <div class="splitkey" id="splitKey"></div>
    <?php component_end() ?>

    <?php component_start('section', ['title' => 'Talents by Spec', 'icon' => 'g', 'sub' => 'Share of each spec\'s players who picked the talent']) ?>
        <div class="legend2">
          <span><span class="tag core">CORE</span> picked by 90% or more</span>
          <span><span class="tag rare">RARE</span> picked by under 30%</span>
          <input id="ctq" type="text" placeholder="Find a talent…" aria-label="Find a talent">
        </div>
        <div class="speccols" id="cols"></div>
        <p class="shared" id="shared" style="margin:10px 2px 0"></p>
    <?php component_end() ?>

  </main>

<?php start_section('scripts') ?>
<script>
const CLASS_DATA = <?= js($data) ?>;
const CD = CLASS_DATA.classes;
// Page URLs: /<edition>/<class>/ with pretty URLs, or index.php?edition=..&page=<class> without.
const ROUTE = window.ROUTE || { edition: 'tbc', base: '/tbc/', initial: 'druid' };
const slugUrl = s => ROUTE.queryUrls
  ? `${location.pathname}?edition=${encodeURIComponent(ROUTE.edition)}&page=${encodeURIComponent(s)}`
  : `${ROUTE.base}${s}/`;
function slugFromUrl() {
  const q = new URLSearchParams(location.search);
  const s = ROUTE.queryUrls ? q.get('page') : location.pathname.replace(/\/+$/, '').split('/').pop();
  return CD[s] ? s : null;
}
const ORDER = CLASS_DATA.order;
const HIGH_RES_CLASS_ICONS = new Set(['druid', 'hunter', 'mage', 'paladin', 'priest', 'rogue', 'shaman', 'warlock', 'warrior', 'death-knight', 'monk', 'demon-hunter', 'evoker']);
const heroClassIcon = (slug, name) => HIGH_RES_CLASS_ICONS.has(slug)
  ? `<img class="hires" src="/wow2008/class-icons/${slug}.png?v=<?= e(ASSET_VERSION) ?>" alt="" loading="lazy">`
  : wowClassIcon(name);
const SLUG_COLOR = Object.fromEntries(ORDER.map(s => [s, CLASS_COLOR[CD[s].name] || '#c9a050']));
const PLURAL = s => CD[s].name + 's';
const lum = rgb => { const m = rgb.match(/\d+/g).map(Number); return 0.299 * m[0] + 0.587 * m[1] + 0.114 * m[2]; };

// ---------- Original class emblems (simple generic symbols, 24x24) ----------
const EMBLEM = {
  paladin: 'M12 2 L14 9 L21 9 L15.5 13.5 L17.5 21 L12 16.5 L6.5 21 L8.5 13.5 L3 9 L10 9Z',                     // star of light
  warrior: 'M5 3 L19 17 M19 3 L5 17 M3 19 L7 15 M21 19 L17 15 M4 2 L7 2 L7 5 M20 2 L17 2 L17 5',                // crossed swords
  druid:   'M12 21 V11 M12 11 Q5 11 5 4 Q12 4 12 11 M12 11 Q19 11 19 4 Q12 4 12 11 M12 15 Q7 15 6 12 M12 15 Q17 15 18 12', // sprouting leaf
  hunter:  'M5 3 Q17 12 5 21 M5 3 V21 M3 12 H21 M18 9 L21 12 L18 15',                                         // bow
  shaman:  'M12 2 L16 10 L13 10 L16 22 L8 11 L11 11 L8 2Z',                                                     // lightning
  priest:  'M12 2 V22 M5 8 H19 M8 4 Q12 1 16 4',                                                                // halo cross
  rogue:   'M12 2 L14 14 L12 17 L10 14Z M8 15 H16 M12 17 V22',                                                  // dagger
  mage:    'M12 3 A9 9 0 1 0 12.01 3Z M12 7 L13.4 10.6 L17 12 L13.4 13.4 L12 17 L10.6 13.4 L7 12 L10.6 10.6Z', // arcane orb
  warlock: 'M12 3 Q4 6 5 14 Q6 21 12 21 Q18 21 19 14 Q20 6 12 3Z M9 10 Q12 13 15 10 M12 13 V18',                // flame
  // Classes after The Burning Crusade, drawn in the same plain style.
  'death-knight': 'M12 2 L19 8 L16 21 H8 L5 8Z M9 10 L11 12 M15 10 L13 12 M9 16 H15 M12 2 V6',                // runed helm
  monk:    'M12 3 A9 9 0 1 0 12.01 3Z M12 3 Q7 7.5 12 12 Q17 16.5 12 21 M12 7.5 A1 1 0 1 0 12.01 7.5Z',         // balance
  'demon-hunter': 'M4 4 Q9 9 12 20 Q15 9 20 4 M7 9 Q9 12 12 12 Q15 12 17 9',                                // horns
  evoker:  'M5 20 Q6 10 12 4 Q18 10 19 20 M8 14 L12 10 L16 14 M10 18 L12 15 L14 18'                           // drake wing
};
const emblem = (slug, col, sw = 1.6) => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${EMBLEM[slug] || EMBLEM.paladin}" fill="${col}" fill-opacity=".3" stroke="${col}" stroke-width="${sw}" stroke-linejoin="round" stroke-linecap="round" fill-rule="evenodd"/></svg>`;

// ---------- Class picker (reuses the Talents page button style) ----------
const bar = document.getElementById('clsbar');
bar.innerHTML = ORDER.map(s => `<a class="cbtn" href="${slugUrl(s)}" data-c="${s}" style="text-decoration:none">
  <span class="ci">${wowClassIcon(CD[s].name)}</span>${CD[s].name}</a>`).join('');
bar.addEventListener('click', e => {
  const a = e.target.closest('.cbtn'); if (!a) return;
  e.preventDefault(); show(a.dataset.c, true);
});

let cur = null, query = '';

function show(slug, push) {
  const c = CD[slug]; if (!c) return;
  cur = slug; query = ''; document.getElementById('ctq').value = '';
  if (push) history.pushState({ slug }, '', slugUrl(slug));
  const col = SLUG_COLOR[slug];
  document.title = `${c.name} Talents – WoWCensus`;
  document.getElementById('crumbClass').textContent = c.name;
  bar.querySelectorAll('.cbtn').forEach(b => b.classList.toggle('on', b.dataset.c === slug));

  const specs = c.split.filter(s => s[0] !== 'Hybrid').sort((a, b) => b[1] - a[1]);
  const least = specs[specs.length - 1] || ['—', 0, 0];
  const top0 = specs[0] || ["—", 0, 0];
  const hyb = c.split.find(s => s[0] === 'Hybrid') || ['Hybrid', 0, 0];
  // spec colours: shades of the class colour, in the source's tree order
  const treeOrder = c.specs.map(s => s.tree);
  const specCol = t => t === 'Hybrid' ? shade(col, -.82) : shade(col, [0, -.35, -.6][treeOrder.indexOf(t)] ?? -.7);

  // hero
  document.getElementById('chero').innerHTML = `
    <svg class="bg" viewBox="0 0 880 170" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
      <defs>
        <linearGradient id="ch-sky" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#05060a"/><stop offset=".6" stop-color="${shade(col, -.7)}"/><stop offset="1" stop-color="${shade(col, -.2)}"/></linearGradient>
        <radialGradient id="ch-glow" cx="86%" cy="48%" r="26%"><stop offset="0" stop-color="${shade(col, .6)}" stop-opacity=".8"/><stop offset="1" stop-color="${col}" stop-opacity="0"/></radialGradient>
        <linearGradient id="ch-fade" x1="0" x2="1"><stop offset="0" stop-color="#000" stop-opacity=".85"/><stop offset=".6" stop-color="#000" stop-opacity="0"/></linearGradient>
      </defs>
      <rect width="880" height="170" fill="url(#ch-sky)"/>
      <rect width="880" height="170" fill="url(#ch-glow)"/>
      <g fill="none" stroke="${shade(col, .3)}" stroke-opacity=".35"><circle cx="757" cy="82" r="78"/><circle cx="757" cy="82" r="92" stroke-dasharray="4 7"/></g>
      <path d="M0 170 L0 144 L160 136 L340 148 L520 134 L700 150 L880 140 L880 170Z" fill="#000" opacity=".7"/>
      <rect width="880" height="170" fill="url(#ch-fade)"/>
    </svg>
    <div class="emb">${heroClassIcon(slug, c.name)}</div>
    <div class="t">
      <div class="kick" style="color:${shade(col, .35)}">CLASS · TALENTS</div>
      <h1 style="color:${shade(col, .1)}">${c.name}</h1>
      <div class="intro">${specs.length ? `The most common build among the ${fmt(c.sample)} ${PLURAL(slug).toLowerCase()} we've inspected is
        <b style="color:${shade(col, .3)}">${esc(specs[0][0])}</b> (${specs[0][2]}%)${specs.length > 1 ? `,
        followed by ${specs.slice(1).map(s => `${esc(s[0])} (${s[2]}%)`).join(specs.length > 3 ? ', ' : ' and ').replace(/, ([^,]*)$/, ' and $1')}` : ''}.`
        : `No ${PLURAL(slug).toLowerCase()} with readable talents have been inspected yet.`}</div>
    </div>`;

  // tiles
  const topSpec = c.specs.find(s => s.tree === top0[0]);
  const levels = c.specs.map(s => s.median).filter(Boolean);
  document.getElementById('ctiles').innerHTML = `
    <div class="stat"><span>Inspected</span><b>${fmt(c.sample)}</b><em>${PLURAL(slug).toLowerCase()} with talents</em></div>
    <div class="stat"><span>Top spec</span><b style="color:${shade(col, -.45)}">${esc(top0[0])}</b><em>${fmt(top0[1])} players · ${top0[2]}%</em></div>
    <div class="stat"><span>Least played</span><b>${esc(least[0])}</b><em>${fmt(least[1])} players · ${least[2]}%</em></div>
    <div class="stat"><span>Hybrid builds</span><b>${fmt(hyb[1])}</b><em>${hyb[2]}% split across trees</em></div>
    <div class="stat"><span>Median level</span><b>${topSpec && topSpec.median ? topSpec.median : '—'}</b><em>${esc(top0[0])}${levels.length > 1 ? ` · others ${c.specs.filter(s => s !== topSpec && s.median).map(s => s.median).join(' / ')}` : ''}</em></div>`;

  // split bar
  document.getElementById('splitSub').textContent = `${fmt(c.sample)} ${PLURAL(slug).toLowerCase()}`;
  document.getElementById('splitBar').innerHTML = c.split.map(([t, n, p]) => {
    const bg = specCol(t), dark = lum(bg) < 110;
    return `<div class="${t === 'Hybrid' ? 'hy' : ''}" data-g="${n}" style="flex:0 1 0;background:${bg};color:${dark ? '#fff' : '#000'};text-shadow:${dark ? '0 1px 1px #000' : '0 1px 0 rgba(255,255,255,.4)'}"
      data-tip="${esc(t)}|${fmt(n)} players|${p}% of inspected ${PLURAL(slug).toLowerCase()}">${p >= 12 ? `${esc(t)} ${p}%` : p >= 5 ? p + '%' : ''}</div>`;
  }).join('');
  document.getElementById('splitKey').innerHTML = c.split.map(([t, n, p]) =>
    `<span><i style="background:${specCol(t)}"></i><b>${esc(t)}</b> ${fmt(n)} · ${p}%</span>`).join('');

  // spec columns, in the source's tree order
  document.getElementById('cols').innerHTML = c.specs.map(s => {
    const sc = specCol(s.tree), bright = shade(col, .15);
    return `<article class="scol" style="border-color:${shade(col, -.3)}">
      <div class="sh"><span class="sico">${wowIcon(s.icon, wowClassIcon(c.name))}</span>
        <h4 style="color:${bright}">${esc(s.tree)}</h4>
        <div class="cn"><b>${fmt(s.n)}</b>${s.pct}%</div></div>
      <div class="sb">${s.talents.length ? `<ol class="ranks">${s.talents.map(([t, p, icon], i) => {
        const initials = t.replace(/\(.*\)/, '').split(/[\s:-]+/).filter(w => /^[A-Z]/.test(w)).slice(0, 2).map(w => w[0]).join('');
        const tag = p >= 90 ? '<span class="tag core">CORE</span>' : p < 30 ? '<span class="tag rare">RARE</span>' : '';
        return `<li data-t="${esc(t.toLowerCase())}" data-tip="${esc(t)}|${esc(s.tree)} ${c.name}|Picked by ${p}% of ${fmt(s.n)} ${esc(s.tree)} players">
          <span class="rk">${i + 1}</span>
          <span class="ticon" style="background:radial-gradient(circle at 35% 30%,${shade(col, .2)},${shade(col, -.45)} 70%,${shade(col, -.8)})">${wowIcon(icon, initials)}</span>
          <span class="tn">${esc(t)}${tag}</span>
          <span class="tp" style="color:${p >= 90 ? '#1eff00' : p < 30 ? '#c8b080' : '#ffd75a'}">${p}%<div class="mini"><i data-w="${p}%" style="background:${bright}"></i></div></span></li>`;
      }).join('')}</ol>` : '<div class="empty">No talent data for this spec.</div>'}</div>
      ${s.median ? `<div class="sf">Median level ${s.median}</div>` : ''}
    </article>`;
  }).join('');

  // talents that appear in more than one spec's list
  const counts = {};
  c.specs.forEach(s => s.talents.forEach(([t]) => (counts[t] = (counts[t] || 0) + 1)));
  const shared = Object.keys(counts).filter(t => counts[t] > 1);
  document.getElementById('shared').innerHTML = shared.length
    ? `<b>Shared across specs:</b> ${shared.map(esc).join(', ')}. These talents show up in more than one spec's list.`
    : `Every talent above belongs to just one spec's list; no picks are shared between specs.`;

  grow(document.querySelector('.main'));
  window.scrollTo({ top: 0, behavior: push ? 'smooth' : 'auto' });
}

// talent search: dim non-matching rows across all three columns
document.getElementById('ctq').addEventListener('input', e => {
  query = e.target.value.trim().toLowerCase();
  document.querySelectorAll('#cols .ranks li').forEach(li => {
    const hit = query && li.dataset.t.includes(query);
    li.classList.toggle('dim', !!query && !hit);
    li.classList.toggle('hit', !!hit);
  });
});

window.addEventListener('popstate', e => show((e.state && e.state.slug) || slugFromUrl() || ROUTE.initial, false));
const start = slugFromUrl() || ROUTE.initial;
show(CD[start] ? start : ORDER[0], false);

</script>
<?php end_section() ?>
