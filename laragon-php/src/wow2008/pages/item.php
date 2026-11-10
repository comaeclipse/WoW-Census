<?php
namespace Wow2008;
/**
 * Page: one WoW: Forever item's auction overview and history.
 * Vars: $item (forever_item_model: id, name, icon, cat, crafter, taught), $trades (list of name, icon, url, role),
 *       $data (generated + forever_item_markets(): markets key => [label, faction], points, log), $wowhead, $subtitle
 */
$icon = $item['icon'] ?: 'inv_misc_questionmark';
?>
  <style>
    .it-hero{display:grid;grid-template-columns:auto 1fr auto;gap:16px;align-items:center;margin-bottom:14px;padding:14px 16px;
      background:radial-gradient(ellipse at 20% 0%,rgba(255,190,80,.22),transparent 60%),linear-gradient(#2c1c0c,#0e0904);
      border:2px solid #3a2608;outline:1px solid #b08a40}
    .it-hero .emb{width:64px;height:64px;border:3px ridge #b08a40;box-shadow:0 3px 12px #000;background:#000}
    .it-hero .emb img{display:block;width:100%;height:100%}
    .it-hero h1{margin:0;font:900 26px/1.1 'Cinzel',serif;color:#fff;text-shadow:0 0 8px rgba(255,170,0,.35),0 3px 3px #000}
    .it-hero .kick{font:700 11px Tahoma,Arial,sans-serif;letter-spacing:2px;color:#e8c070;text-transform:uppercase;text-shadow:0 1px 2px #000}
    .it-hero .meta{margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;font-size:11px;color:#eee}
    .it-hero .meta a{color:#ffd75a}
    .it-wh{align-self:start;font:700 10px Tahoma,Arial,sans-serif;padding:5px 10px;border:1px solid #000;border-radius:3px;text-decoration:none;
      color:#2a1200;background:linear-gradient(#ffd75a,#b07a00);box-shadow:inset 0 1px 0 rgba(255,255,255,.5)}
    .it-wh:hover{background:linear-gradient(#ffe890,#c88a10)}
    .it-trades{display:flex;flex-wrap:wrap;gap:8px}
    .it-trade{display:flex;align-items:center;gap:8px;padding:5px 10px 5px 5px;text-decoration:none;color:#3a1a00;border:1px solid #8a6a2a;border-radius:3px;
      background:linear-gradient(#fff6d8,#ecd59c);box-shadow:inset 0 0 0 1px #fff7d8}
    .it-trade:hover{box-shadow:inset 0 0 0 1px #fff7d8,0 0 0 2px #ffd75a}
    .it-trade img{width:28px;height:28px;border:1px solid #000;outline:1px solid #c8a050;border-radius:2px}
    .it-trade b{display:block;font:700 13px 'Cinzel',serif;font-variant:small-caps;color:#5a2a00}
    .it-trade small{font:700 9px Tahoma,Arial,sans-serif;text-transform:uppercase;letter-spacing:.6px;color:#8a5a10}
    .it-chart{background:linear-gradient(#1c1810,#0e0c08);border:2px solid #000;outline:1px solid #8a7040;border-radius:4px;padding:8px 8px 4px}
    .it-chart svg{display:block;width:100%;height:auto}
    .it-chart text{font:10px Tahoma,Arial,sans-serif;fill:#b8a888}
    .it-chart .grid{stroke:#2e2818;stroke-width:1}
    .it-chart .ttl{font:700 11px Tahoma,Arial,sans-serif;fill:#ffd75a}
    .it-legend{display:flex;flex-wrap:wrap;gap:4px 14px;margin:8px 2px 2px;font:10px Tahoma,Arial,sans-serif;color:#d8c8a8}
    .it-legend i{display:inline-block;width:14px;height:3px;margin-right:5px;vertical-align:3px}
    .it-legend .lo{display:inline-block;width:14px;border-top:2px dashed #b8a888;margin-right:5px;vertical-align:3px}
    .it-charts{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    .it-one{padding:14px;color:#c8b898;font-size:11px;text-align:center}
    .report .money,.tiles .money{color:#3a1a00;text-shadow:none}
    @media (max-width:760px){.it-charts{grid-template-columns:1fr}}
    @media (max-width:600px){.it-hero{grid-template-columns:auto 1fr}.it-wh{grid-column:1 / -1;justify-self:start}.it-hero h1{font-size:21px}.it-hero .emb{width:52px;height:52px}}
  </style>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Auction House', 'url' => url('auction-house')], ['label' => $item['name']]]]) ?>

    <div class="it-hero">
      <div class="emb"><img src="/assets/images/wow/icons/large/<?= e($icon) ?>.jpg" alt="" width="64" height="64"></div>
      <div>
        <div class="kick"><?= e($item['cat'] ?: 'Item') ?> · <?= e($subtitle) ?></div>
        <h1><?= e($item['name']) ?></h1>
        <div class="meta">
          <?php if ($item['crafter']): ?><span>Crafted by <?= e($item['crafter']) ?></span><?php endif ?>
          <?php if ($item['taught']): ?><span>Teaches <a href="<?= e(\forever_item_path($item['taught']['id'])) ?>"><?= e($item['taught']['name']) ?></a></span><?php endif ?>
          <span>Item ID <?= (int)$item['id'] ?></span>
        </div>
      </div>
      <a class="it-wh" href="<?= e($wowhead) ?>" target="_blank" rel="noopener">Wowhead »</a>
    </div>

    <div class="tiles" id="itTiles"></div>

    <section class="section">
      <div class="post-hd"><span class="ico g"></span><h2>Market Report</h2><div class="sub">every region, ruleset and faction</div></div>
      <div class="section-bd"><div class="report" id="itReport"></div></div>
    </section>

    <section class="section">
      <div class="post-hd"><span class="ico"></span><h2>Price &amp; Supply History</h2><div class="sub">one line per region, ruleset and faction · one point per scanned day</div></div>
      <div class="section-bd">
        <div class="it-charts">
          <div class="it-chart" id="itPrice"></div>
          <div class="it-chart" id="itQty"></div>
        </div>
      </div>
    </section>

    <div class="ahgrid">
      <div class="wide" id="itNow"></div>
      <div class="wide" id="itLog"></div>
    </div>

<?php if ($trades): ?>
    <?php component_start('section', ['title' => 'Used in Professions', 'icon' => 'p', 'sub' => 'open a trade to see its whole market', 'style' => 'margin-top:16px']) ?>
        <div class="it-trades">
<?php foreach ($trades as $t): ?>
          <a class="it-trade" href="<?= e($t['url']) ?>"><img src="/assets/images/wow/icons/large/<?= e($t['icon']) ?>.jpg" alt="" width="28" height="28"><span><b><?= e($t['name']) ?></b><small><?= e($t['role']) ?></small></span></a>
<?php endforeach ?>
        </div>
    <?php component_end() ?>
<?php endif ?>

    <div class="source">Auction data from in-game scans · asking prices on listed auctions, not completed sales · "lowest" is the cheapest listing, "median" the middle unit price</div>
  </main>

<?php start_section('scripts') ?>
<script>
const IT = <?= js($data) ?>;
const NAME = <?= js($item['name']) ?>;
// One point per market and scanned day; markets are region + ruleset + faction ("US PvE · Alliance").
const SC = IT.points.map(p => ({ k: p[0], fac: IT.markets[p[0]][1], t: p[1], qty: p[2], lo: p[3], med: p[4] }));
const LOG = IT.log.map(p => ({ k: p[0], t: p[1], qty: p[2], lo: p[3], med: p[4] }));
const label = s => IT.markets[s.k][0];
const key = s => s.k;

function money(cu) {
  const g = Math.floor(cu / 10000), s = Math.floor(cu / 100) % 100, c = cu % 100;
  const parts = [];
  if (g) parts.push(`<span>${fmt(g)}<i class="g"></i></span>`);
  if (s || (g && c)) parts.push(`<span>${s}<i class="s"></i></span>`);
  if (c && !g) parts.push(`<span>${c}<i class="c"></i></span>`);
  if (!parts.length) parts.push(`<span>0<i class="c"></i></span>`);
  return `<span class="money">${parts.join('')}</span>`;
}
// Plain-text price for tooltips and axis labels.
const coins = cu => { const g = Math.floor(cu / 10000), s = Math.floor(cu / 100) % 100, c = cu % 100;
  return g ? `${fmt(g)}g${s ? ' ' + s + 's' : ''}` : s ? `${s}s${c ? ' ' + c + 'c' : ''}` : `${c}c`; };
const day = t => new Date(t * 1000).toISOString().slice(0, 10);
const stamp = t => new Date(t * 1000).toISOString().slice(0, 16).replace('T', ' ');
function chg(now, prev) {
  if (prev == null) return '<span class="chg none" title="No earlier scan to compare">—</span>';
  if (!prev) return '<span class="chg up">NEW</span>';
  const p = Math.round((now - prev) / prev * 100);
  return p === 0 ? '<span class="chg flat">0%</span>' : `<span class="chg ${p > 0 ? 'up' : 'dn'}">${p > 0 ? '+' : ''}${p}%</span>`;
}

// One series per market, in faction colours.
const series = {};
SC.forEach(s => (series[key(s)] ||= { k: s.k, fac: s.fac, pts: [] }).pts.push(s));
const lastScan = k => Math.max(...LOG.filter(l => l.k === k).map(l => l.t));
const PAL = { Alliance: ['#5aa0ff', '#9cc8ff', '#2f6ad0'], Horde: ['#ff5a4a', '#ffa090', '#c02a18'], Neutral: ['#ffd75a', '#e0b040', '#fff0a0'] };
const used = {};
Object.values(series).forEach(sr => { const i = used[sr.fac] = (used[sr.fac] ?? -1) + 1; sr.color = PAL[sr.fac][i % 3]; });
const latest = Object.values(series).map(sr => ({ ...sr.pts[sr.pts.length - 1], prev: sr.pts.length > 1 ? sr.pts[sr.pts.length - 2] : null, color: sr.color }));

function chart(el, title, field, fmtAxis, fmtTip, withLow) {
  const W = 440, H = 210, L = 54, R = 10, T = 22, B = 26;
  const ts = SC.map(s => s.t), t0 = Math.min(...ts), t1 = Math.max(...ts);
  const vals = SC.flatMap(s => withLow ? [s[field], s.lo] : [s[field]]);
  let v1 = Math.max(...vals) * 1.1 || 1, v0 = 0;
  const x = t => t1 === t0 ? L + (W - L - R) / 2 : L + (t - t0) / (t1 - t0) * (W - L - R);
  const y = v => T + (1 - (v - v0) / (v1 - v0)) * (H - T - B);
  let g = `<text class="ttl" x="${L}" y="13">${title}</text>`;
  for (let i = 0; i <= 4; i++) { const v = v0 + (v1 - v0) * i / 4, yy = y(v);
    g += `<line class="grid" x1="${L}" x2="${W - R}" y1="${yy}" y2="${yy}"/><text x="${L - 6}" y="${yy + 3}" text-anchor="end">${fmtAxis(v)}</text>`; }
  const days = [...new Set(SC.map(s => day(s.t)))];
  const tickDays = days.length <= 6 ? days : days.filter((_, i) => i % Math.ceil(days.length / 6) === 0);
  tickDays.forEach(d => { const t = Date.parse(d + 'T12:00:00Z') / 1000; const xx = t1 === t0 ? x(t0) : Math.min(Math.max(x(t), L), W - R);
    const anchor = xx > W - R - 16 ? 'end' : xx < L + 16 ? 'start' : 'middle';
    g += `<text x="${xx}" y="${H - 8}" text-anchor="${anchor}">${d.slice(5)}</text>`; });
  Object.values(series).forEach(sr => {
    const p = sr.pts;
    if (withLow && p.length > 1) g += `<polyline fill="none" stroke="${sr.color}" stroke-width="1.5" stroke-dasharray="4 3" opacity=".55" points="${p.map(s => `${x(s.t)},${y(s.lo)}`).join(' ')}"/>`;
    if (p.length > 1) g += `<polyline fill="none" stroke="${sr.color}" stroke-width="2.5" points="${p.map(s => `${x(s.t)},${y(s[field])}`).join(' ')}"/>`;
    p.forEach(s => g += `<circle cx="${x(s.t)}" cy="${y(s[field])}" r="4.5" fill="${sr.color}" stroke="#000" stroke-width="1.5" style="cursor:pointer"
      data-tip="${esc(label(s))}|${day(s.t)}|${fmtTip(s)}"/>`);
  });
  const legend = Object.values(series).map(sr => `<span><i style="background:${sr.color}"></i>${esc(label(sr))}</span>`).join('') +
    (withLow ? '<span><span class="lo"></span>lowest listing</span>' : '');
  el.innerHTML = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(title)}">${g}</svg><div class="it-legend">${legend}</div>` +
    (Object.values(series).every(sr => sr.pts.length < 2) ? '<div class="it-one">One day of scans so far — the lines appear once this item is seen on another day.</div>' : '');
}

function render() {
  const listed = latest.reduce((a, s) => a + s.qty, 0);
  const cheapest = latest.reduce((a, s) => !a || s.lo < a.lo ? s : a, null);
  const meds = SC.map(s => s.med), lastT = Math.max(...LOG.map(s => s.t));
  document.getElementById('itTiles').innerHTML = `
    <div class="stat"><span>Lowest price now</span><b style="font-size:17px;line-height:26px">${money(cheapest.lo)}</b><em>${esc(label(cheapest))}</em></div>
    <div class="stat"><span>Listed now</span><b>${fmt(listed)}</b><em>units across ${fmt(latest.length)} market${latest.length === 1 ? '' : 's'}</em></div>
    <div class="stat"><span>Median range</span><b style="font-size:15px;line-height:26px">${money(Math.min(...meds))} – ${money(Math.max(...meds))}</b><em>across every scanned day</em></div>
    <div class="stat"><span>Scans</span><b>${fmt(LOG.length)}</b><em>last ${day(lastT)}</em></div>`;

  // Report: compare each market's newest day with its previous one.
  const moved = latest.filter(s => s.prev).map(s => ({ s, dp: s.prev.med ? Math.round((s.med - s.prev.med) / s.prev.med * 100) : 0, dq: s.prev.qty ? Math.round((s.qty - s.prev.qty) / s.prev.qty * 100) : 0 }));
  const lines = [`<b>${esc(NAME)}</b> has been seen in <b>${fmt(LOG.length)}</b> auction scan${LOG.length === 1 ? '' : 's'} across ${fmt(latest.length)} market${latest.length === 1 ? '' : 's'} (${latest.map(s => esc(label(s))).join(', ')}), most recently on <b>${day(lastT)}</b>.`,
    `Right now <b>${fmt(listed)}</b> ${listed === 1 ? 'is' : 'are'} listed; the cheapest is ${money(cheapest.lo)} each on ${esc(label(cheapest))}.`];
  moved.forEach(({ s, dp, dq }) => lines.push(`On ${esc(label(s))} the median price ${dp === 0 ? 'held at' : dp > 0 ? `rose <span class="up-t">${dp}%</span> to` : `fell ${-dp}% to`} ${money(s.med)} and supply ${dq === 0 ? 'was unchanged' : dq > 0 ? `grew ${dq}%` : `shrank ${-dq}%`} since ${day(s.prev.t)}.`));
  document.getElementById('itReport').innerHTML = `
    <svg viewBox="0 0 64 64" aria-hidden="true"><defs><radialGradient id="it-c" cx="35%" cy="30%" r="70%"><stop offset="0" stop-color="#fff6b0"/><stop offset=".55" stop-color="#e0a810"/><stop offset="1" stop-color="#6a4400"/></radialGradient></defs>
      <rect x="8" y="6" width="40" height="52" rx="2" fill="#f8ecc8" stroke="#7a5a20" stroke-width="2"/>
      <g stroke="#9c7c42" stroke-width="2"><line x1="14" y1="16" x2="42" y2="16"/><line x1="14" y1="24" x2="42" y2="24"/><line x1="14" y1="32" x2="36" y2="32"/><line x1="14" y1="40" x2="40" y2="40"/></g>
      <circle cx="46" cy="46" r="13" fill="url(#it-c)" stroke="#5a3a00" stroke-width="1.5"/><text x="46" y="51" text-anchor="middle" font-family="Tahoma, Arial, sans-serif" font-weight="900" font-size="13" fill="#5a3a00">g</text></svg>
    <div><p>${lines.join(' ')}</p><p class="caveat">Asking prices on listed auctions. They don't show what actually sold.</p></div>`;

  chart(document.getElementById('itPrice'), 'Median unit price', 'med', v => coins(Math.round(v)),
    s => `Median ${coins(s.med)}|Lowest ${coins(s.lo)}|${fmt(s.qty)} listed`, true);
  chart(document.getElementById('itQty'), 'Units listed', 'qty', v => v >= 1e3 ? (v / 1e3).toFixed(v >= 1e4 ? 0 : 1) + 'k' : fmt(Math.round(v)),
    s => `${fmt(s.qty)} listed|Median ${coins(s.med)}`, false);

  const tbl = (title, sub, head, rows) => `<div class="ahwin"><div class="wt">${title}<small>${sub}</small></div>
    <table class="ahtable"><thead><tr>${head.map(([h, n]) => `<th class="${n ? 'num' : ''}">${h}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table></div>`;
  const sw = c => `<i style="display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px;background:${c};border:1px solid #000"></i>`;
  document.getElementById('itNow').innerHTML = tbl('Current Listings', 'latest scans per region, ruleset and faction',
    [['Market'], ['Qty', 1], ['Change', 1], ['Lowest', 1], ['Median', 1], ['Scanned', 1]],
    [...latest].sort((a, b) => b.qty - a.qty).map(s => `<tr><td>${sw(s.color)}${esc(label(s))}</td><td class="num">${fmt(s.qty)}</td>
      <td class="num">${chg(s.qty, s.prev ? s.prev.qty : null)}</td><td class="num">${money(s.lo)}</td><td class="num">${money(s.med)}</td><td class="num">${day(lastScan(s.k))}</td></tr>`).join(''));
  document.getElementById('itLog').innerHTML = tbl('Scan Log', 'every scan that saw this item, newest first',
    [['Scanned (UTC)'], ['Market'], ['Qty', 1], ['Lowest', 1], ['Median', 1]],
    [...LOG].sort((a, b) => b.t - a.t).map(s => `<tr><td>${stamp(s.t)}</td><td>${sw(series[key(s)].color)}${esc(label(s))}</td><td class="num">${fmt(s.qty)}</td>
      <td class="num">${money(s.lo)}</td><td class="num">${money(s.med)}</td></tr>`).join(''));
}
render();
</script>
<?php end_section() ?>
