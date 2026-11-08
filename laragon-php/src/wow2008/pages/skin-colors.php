<?php
namespace Wow2008;
/**
 * Page: Skin Colors
 * Vars: $data (array: races by slug, spectrum rows [slug, median, [[hex, lightness, n]]], maxShare), $route (array, window.ROUTE)
 */
?>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Realm Census', 'url' => url('census')], ['label' => 'Skin Colors', 'url' => $route['base']], ['label' => 'Human', 'id' => 'crumbRace']]]) ?>

    <div class="racebar" id="racebar"></div>

    <div class="race-hero sk-hero" id="hero"></div>

    <div class="tiles" id="sktiles"></div>

    <?php component_start('section', ['title' => 'Most Observed Skin Colors', 'icon' => 'g', 'sub_id' => 'palSub']) ?>
        <div class="panel sk-pal" id="palette"></div>
        <p class="note" id="palNote" style="margin-top:8px"></p>
    <?php component_end() ?>

    <?php component_start('section', ['title' => 'Light to Dark · All Races', 'icon' => 'p', 'sub' => 'Click a race to see its palette']) ?>
        <div class="panel sk-spec">
          <div class="sk-axis"><span>Race</span><span class="sk-ramp"><i></i><small><b>Darker</b><b>Lighter</b></small></span><span>Sampled</span></div>
          <div id="spectrum"></div>
        </div>
        <p class="note" style="margin-top:8px">Each square is one distinct UI swatch, placed by its lightness and sized by its share of that race's sampled characters. The gold line marks the race median. Lightness comes from Blizzard's customization colors, not rendered skin.</p>
    <?php component_end() ?>

    <div class="source">Skin colors from sampled <?= $route['edition'] === 'forever' ? 'WoW: Forever' : 'Retail' ?> character profiles<?php if ($sampleInfo): ?> · <?= (int)$sampleInfo['requested'] ?> random US names queried; <?= (int)($sampleInfo['statuses']['200'] ?? 0) ?> appearance responses; <?= (int)($sampleInfo['statuses']['404'] ?? 0) ?> returned 404<?php endif ?> · an API-visible sample, not a population estimate</div>
  </main>

<?php start_section('scripts') ?>
<script>
const SK = <?= js($data) ?>;
const RD = SK.races, ORDER = Object.keys(RD);
const ROUTE = window.ROUTE || { base: '/retail/skin-colors/', initial: ORDER[0], edition: 'retail' };
const FACTION = { A: ['Alliance', '#6fa8ff', '#9cc4ff'], H: ['Horde', '#ff5a4a', '#ffb0a0'], N: ['Neutral', '#e8d8a0', '#f0e6c0'] };
const slugUrl = s => `${ROUTE.base}${s}/`;
const slugFromUrl = () => { const s = location.pathname.replace(/\/+$/, '').split('/').pop(); return RD[s] ? s : null; };
const pct = (n, total) => total ? Math.round(n / total * 100) : 0;
// CIE L* of a #rrggbb swatch; light swatches get dark ink.
function lightness(hex) {
  const c = v => { v /= 255; return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; };
  const n = parseInt(hex.slice(1), 16);
  const y = .2126 * c(n >> 16 & 255) + .7152 * c(n >> 8 & 255) + .0722 * c(n & 255);
  return y > .008856 ? 116 * Math.cbrt(y) - 16 : 903.3 * y;
}
const initial = name => `<b class="sk-ini">${esc(name[0])}</b>`;

// ---------- Race selector ----------
const bar = document.getElementById('racebar');
bar.innerHTML = Object.entries(FACTION).filter(([f]) => ORDER.some(s => RD[s].faction === f)).map(([f, [label, col]]) => `
  <span class="fl" style="color:${col}">${label}</span>
  <div class="row">${ORDER.filter(s => RD[s].faction === f).map(s =>
    `<a class="rbtn" href="${slugUrl(s)}" data-r="${s}">${wowIcon(RD[s].icon, initial(RD[s].name))}${esc(RD[s].name)}</a>`).join('')}</div>`).join('');

function treemap(cells, total) {
  if (!cells.length) return '<div class="sk-empty">No sampled Skin Color option could be verified for this playable race or form.</div>';
  return `<div class="sk-map">${cells.map(([hex, n, x, y, w, h]) => {
    const p = pct(n, total), label = w >= 6 && h >= 12 ? `<span>${p}%</span>` : '';
    return `<div class="sk-cell${lightness(hex) > 62 ? ' dark' : ''}" style="left:${x}%;top:${y}%;width:${w}%;height:${h}%;background:${hex}"
      data-tip="${hex}|${fmt(n)} sampled characters|${p}% of this palette">${label}</div>`;
  }).join('')}</div>`;
}

let cur = null;
function show(slug, push) {
  const r = RD[slug]; if (!r) return;
  cur = slug;
  if (push) history.pushState({ slug }, '', slugUrl(slug));
  document.title = `${ROUTE.edition === 'forever' ? 'WoW: Forever' : 'Retail'} skin colors · ${r.name} · WoWCensus`;
  document.getElementById('crumbRace').textContent = r.name;
  bar.querySelectorAll('.rbtn').forEach(b => b.classList.toggle('on', b.dataset.r === slug));
  document.querySelectorAll('.sk-row').forEach(a => a.classList.toggle('on', a.dataset.r === slug));

  const [fname, , kick] = FACTION[r.faction];
  const top = r.cells[0], swatches = r.cells.length;
  // hero: faction sky with the race's palette as the horizon strip
  const hues = { A: ['#02061a', '#1a3a8a', '#7ab0ff'], H: ['#140202', '#7a1408', '#ff8a5a'], N: ['#0a0a06', '#4a4020', '#c8b070'] }[r.faction];
  document.getElementById('hero').innerHTML = `
    <svg class="bg" viewBox="0 0 880 170" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
      <defs>
        <linearGradient id="sh-sky" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="${hues[0]}"/><stop offset=".65" stop-color="${hues[1]}"/><stop offset="1" stop-color="${hues[2]}"/></linearGradient>
        <radialGradient id="sh-glow" cx="86%" cy="45%" r="28%"><stop offset="0" stop-color="#fff" stop-opacity=".55"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></radialGradient>
        <linearGradient id="sh-fade" x1="0" x2="1"><stop offset="0" stop-color="#000" stop-opacity=".85"/><stop offset=".6" stop-color="#000" stop-opacity="0"/></linearGradient>
      </defs>
      <rect width="880" height="170" fill="url(#sh-sky)"/>
      <rect width="880" height="170" fill="url(#sh-glow)"/>
      <rect width="880" height="170" fill="url(#sh-fade)"/>
    </svg>
    <div class="sk-strip">${r.cells.map(([hex, n]) => `<i style="flex-grow:${n};background:${hex}"></i>`).join('')}</div>
    <div class="crest">${wowIcon(r.icon, '')}</div>
    <div class="t">
      <div class="kick" style="color:${kick}">${fname.toUpperCase()} · SKIN COLORS</div>
      <h1>${esc(r.name)}</h1>
      <div class="intro">${r.matched
        ? `Of ${fmt(r.queried)} sampled ${esc(r.name)} characters, ${fmt(r.matched)} have a skin color we could verify, spread over ${swatches} distinct swatches.
           The most common, <b style="color:${top[0]}">■</b> <b>${top[0]}</b>, is worn by ${pct(top[1], r.matched)}% of them.`
        : `None of the ${fmt(r.queried)} sampled ${esc(r.name)} characters had a skin color we could verify.`}</div>
    </div>`;

  // tiles
  const tile = (k, v, sub, extra = '') => `<div class="stat"><span>${k}</span><b${extra}>${v}</b><em>${sub}</em></div>`;
  document.getElementById('sktiles').innerHTML =
    tile('Sampled', fmt(r.queried), `${esc(r.name)} profiles queried`) +
    tile('Verified', fmt(r.matched), `${pct(r.matched, r.queried)}% have a matching skin option`) +
    tile('Swatches', swatches, 'distinct colors observed') +
    (top ? tile('Most common', `<i class="sk-chip" style="background:${top[0]}"></i>${top[0]}`, `${fmt(top[1])} characters · ${pct(top[1], r.matched)}%`, ' style="font-size:15px;line-height:26px"')
      : tile('Most common', '—', 'no verified colors'));

  // palette
  document.getElementById('palSub').textContent = r.bodies ? 'Both body types · area is share of sampled characters' : 'Area is share of sampled characters';
  document.getElementById('palette').innerHTML = r.bodies
    ? r.bodies.map(([body, cells]) => {
        const n = cells.reduce((a, c) => a + c[1], 0);
        return `<h5>${esc(body)}<small>${cells.length} swatches · ${fmt(n)} characters</small></h5>${treemap(cells, n)}`;
      }).join('')
    : treemap(r.cells, r.matched);
  document.getElementById('palNote').textContent = (r.note ? r.note + ' ' : '') +
    'Repeated Blizzard choice IDs with the same UI swatch are combined. UI swatches do not measure rendered skin.';
  window.scrollTo({ top: 0, behavior: push ? 'smooth' : 'auto' });
}

// ---------- Light-to-dark chart ----------
document.getElementById('spectrum').innerHTML = SK.spectrum.map(([slug, median, points]) => {
  const r = RD[slug], total = points.reduce((a, p) => a + p[2], 0);
  const dots = points.map(([hex, l, n]) => {
    const side = Math.max(4, Math.round(30 * Math.sqrt(n / total / SK.maxShare)));
    return `<i style="left:${l}%;width:${side}px;height:${side}px;margin:-${side >> 1}px 0 0 -${side >> 1}px;background:${hex}"
      data-tip="${esc(r.name)} · ${hex}|${fmt(n)} sampled · ${pct(n, total)}%|lightness ${Math.round(l)}"></i>`;
  }).join('');
  return `<a class="sk-row" href="${slugUrl(slug)}" data-r="${slug}">
    <span class="sk-race"><i style="background:${FACTION[r.faction][1]}"></i>${esc(r.name)}</span>
    <span class="sk-track">${total ? `<i class="median" style="left:${median}%" data-tip="${esc(r.name)}|median lightness ${Math.round(median)}"></i>` : ''}${dots}</span>
    <span class="sk-n">${fmt(total)}</span></a>`;
}).join('');

document.addEventListener('click', e => {
  const a = e.target.closest('.rbtn, .sk-row');
  if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
  e.preventDefault(); show(a.dataset.r, true);
});
window.addEventListener('popstate', e => show((e.state && e.state.slug) || slugFromUrl() || ROUTE.initial, false));
show(slugFromUrl() || ROUTE.initial, false);
</script>
<?php end_section() ?>
