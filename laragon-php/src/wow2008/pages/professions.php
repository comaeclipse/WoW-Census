<?php
namespace Wow2008;
/**
 * Page: Professions (WoW: Forever). One card per trade, linking to its market page.
 * Vars: $cards (slug => name, role, kind, icon, blurb, url), $data (generated, views: realm, faction, scanned, empty,
 *       p: slug => [items, units, gold, mats, crafted, recipes]), $realms, $factions (lists of [key, label]), $subtitle
 */
$groups = [
  'Crafting' => ['Crafting Professions', 'Turn raw goods into gear, potions and gadgets', 'g'],
  'Gathering' => ['Gathering Professions', 'Harvest the world for the crafters', ''],
  'Secondary' => ['Secondary Skills', 'Any character can learn all three', 'p'],
];
?>
  <style>
    .pf-intro{display:grid;grid-template-columns:72px 1fr;gap:14px;align-items:center;padding:10px 14px;margin-bottom:14px;
      background:linear-gradient(#fbe9b6,#e8d098);border:1px solid #9c7c42;box-shadow:inset 0 0 0 1px #fff3cc;font-size:12px;line-height:1.6;color:#3a1a00}
    .pf-intro p{margin:0}
    .pf-intro b{color:#5a2a00}
    .pf-intro .book{width:72px;height:72px}
    .pf-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
    .pf-card{position:relative;display:grid;grid-template-columns:52px 1fr;gap:4px 10px;padding:10px 10px 9px;text-decoration:none;color:#3a1a00;
      background:linear-gradient(#fff6d8,#ecd59c);border:1px solid #8a6a2a;border-radius:3px;text-align:left;
      box-shadow:inset 0 0 0 1px #fff7d8,0 1px 2px rgba(60,30,0,.35);transition:transform .12s,box-shadow .12s}
    .pf-card:hover{transform:translateY(-1px);box-shadow:inset 0 0 0 1px #fff7d8,0 0 0 2px #ffd75a,0 4px 10px rgba(60,30,0,.45)}
    .pf-card:hover h3{color:#a03a00}
    .pf-ic{grid-row:1 / span 3;width:52px;height:52px;border:2px solid #1a1206;outline:2px ridge #c8a050;border-radius:4px;overflow:hidden;background:#111;
      box-shadow:0 2px 4px rgba(0,0,0,.5)}
    .pf-ic img{display:block;width:100%;height:100%}
    .pf-card h3{margin:0;font:700 15px/1.1 'Cinzel',serif;color:#5a2a00;font-variant:small-caps}
    .pf-card .role{text-align:left;font:700 9px Tahoma,Arial,sans-serif;text-transform:uppercase;letter-spacing:.8px;color:#8a5a10}
    .pf-card .blurb{font-size:11px;line-height:1.4;color:#4a2a08}
    .pf-card .ledger{grid-column:1 / -1;display:flex;justify-content:space-between;gap:6px;margin-top:6px;padding-top:6px;border-top:1px dashed #b8955a;
      font:10px Tahoma,Arial,sans-serif;color:#6a4a20}
    .pf-card .ledger b{color:#3a1a00}
    .pf-card .go{font-weight:700;color:#7a1a00;white-space:nowrap}
    .pf-card.quiet .ledger b{color:#9a7a4a}
    .pf-board .trade a{display:inline-flex;align-items:center;gap:6px}
    .pf-board .trade img{width:18px;height:18px;border:1px solid #000;outline:1px solid #6a6050;border-radius:2px}
    @media (max-width:900px){.pf-cards{grid-template-columns:repeat(2,1fr)}}
    @media (max-width:560px){.pf-cards{grid-template-columns:1fr}.pf-intro{grid-template-columns:1fr}.pf-intro .book{display:none}.pf-board .qbar{display:none}}
  </style>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Auction House', 'url' => url('auction-house')], ['label' => 'Professions']]]) ?>

    <?= component('banner', ['art' => 'auction-house', 'title' => 'Professions', 'subtitle' => $subtitle . ' · TRADE SKILLS', 'subtitle_color' => '#ffe8a0', 'stamp_id' => 'pfstamp']) ?>

<?php
  // phpBB2-style board of the armory profession census (src/forever-profession-census.json).
  $pc = $pcensus;
  $slugOf = []; foreach ($cards as $s => $c) $slugOf[$c['name']] = $s;
  $bbDate = fn($iso) => gmdate('D M d, Y g:i a', strtotime($iso));
  $bar = fn($pct) => '<span class="bb-vote"><i style="width:' . max(1, round($pct * 2)) . 'px"></i></span>';
  $trade = function ($name) use ($slugOf, $cards) {
    $s = $slugOf[$name] ?? null;
    if (!$s) return '<span class="bb-forumlink">' . e($name) . '</span>';
    return '<img class="bb-fi" src="/assets/images/wow/icons/small/' . e($cards[$s]['icon']) . '.jpg" alt="" width="18" height="18">'
      . '<a class="bb-forumlink" href="' . e($cards[$s]['url']) . '">' . e($name) . '</a>';
  };
  $groupsBB = ['Gathering Professions' => 'Gathering', 'Crafting Professions' => 'Crafting'];
  $prim = $pc['primaries'];
?>
    <style>
      .bb{margin:0 0 16px;padding:8px;background:#e5e5e5;border:1px solid #a9b8c2;font:11px Verdana,Arial,Helvetica,sans-serif;color:#000;text-align:left}
      .bb a{color:#006699;text-decoration:none}.bb a:hover{color:#dd6900;text-decoration:underline}
      .bb-top{display:flex;justify-content:space-between;align-items:flex-end;gap:10px;margin:2px 2px 6px}
      .bb-maintitle{font:bold 20px 'Trebuchet MS',Verdana,sans-serif;color:#000;letter-spacing:-.5px;line-height:1.1}
      .bb-nav{font-weight:bold;font-size:11px}
      .bb-small{font-size:10px;color:#000}
      .bb table.forumline{width:100%;border-collapse:separate;border-spacing:1px;background:#fff;border:2px solid #006699;margin-bottom:8px}
      .bb th{height:25px;padding:0 6px;color:#ffa34f;font-size:11px;font-weight:bold;white-space:nowrap;
        background:linear-gradient(#3d82ab,#006699 45%,#005b88);border-top:1px solid #6aa4c8}
      .bb td{padding:4px 6px;vertical-align:middle}
      .bb td.row1{background:#efefef}.bb td.row2{background:#dee3e7}.bb td.row3{background:#d1d7dc}
      .bb td.catHead{height:24px;padding:3px 6px;background:linear-gradient(#fdfdfd,#d9e1e6 60%,#c7d1d7);border-bottom:1px solid #a9b8c2}
      .bb .cattitle{font:bold 12px Verdana,sans-serif;color:#000;letter-spacing:.5px}
      .bb td.spaceRow{height:1px;padding:0;background:#d1d7dc}
      .bb td.c{text-align:center}.bb td.n{text-align:right;white-space:nowrap}
      .bb .bb-forumlink{font-weight:bold;font-size:12px}
      .bb img.bb-fi{width:18px;height:18px;vertical-align:-4px;margin-right:6px;border:1px solid #6b7c88}
      .bb .bb-desc{display:block;margin-top:2px;font-size:10px;color:#333}
      .bb .bb-folder{display:inline-block;width:19px;height:15px;background:linear-gradient(#ffe8a4,#f2b84a);border:1px solid #b07a1c;border-radius:2px 5px 2px 2px;
        box-shadow:inset 0 1px 0 #fff6d6;position:relative;vertical-align:middle}
      .bb .bb-folder.new::after{content:'';position:absolute;right:-3px;top:-4px;width:7px;height:7px;border-radius:50%;background:#e06a00;border:1px solid #8a3a00}
      .bb-vote{display:inline-block;height:13px;vertical-align:middle;padding:0 3px;border-radius:7px;background:#fff;border:1px solid #9aa9b4}
      .bb-vote i{display:block;height:9px;margin-top:2px;border-radius:5px;background:linear-gradient(#ffd27a,#f08a00 55%,#c66400)}
      .bb .bb-pct{display:inline-block;min-width:38px;margin-left:5px;font-weight:bold}
      .bb .bb-cnt{color:#444}
      .bb .bb-cols{display:grid;grid-template-columns:1fr 1fr;gap:0 8px}
      .bb .bb-stats td.icon{width:46px;text-align:center}
      .bb .bb-users{display:inline-block;width:30px;height:24px;position:relative}
      .bb .bb-users::before,.bb .bb-users::after{content:'';position:absolute;bottom:0;width:15px;height:20px;border-radius:8px 8px 3px 3px;
        background:radial-gradient(circle at 50% 28%,#ffd9b0 0 4px,transparent 4.5px),linear-gradient(transparent 45%,#3c7aa8 45%);border:1px solid #2a5a80}
      .bb .bb-users::before{left:1px}.bb .bb-users::after{right:1px;filter:hue-rotate(160deg)}
      .bb .bb-legend b{padding:0 3px}
      .bb .rs-pvp{color:#aa0000}.bb .rs-normal{color:#006600}.bb .rs-rp{color:#6a3aa0}.bb .rs-rppvp{color:#cc6600}
      .bb-foot{display:flex;justify-content:space-between;gap:8px;margin:0 2px}
      @media (max-width:760px){.bb .bb-cols{grid-template-columns:1fr}.bb .hide-sm{display:none}.bb-top{flex-direction:column;align-items:flex-start}}
    </style>
    <div class="bb" id="census">
      <div class="bb-top">
        <div>
          <div class="bb-maintitle">Profession Census</div>
          <div class="bb-nav"><a href="<?= e(url('')) ?>">WoWCensus Index</a> &raquo; <a href="<?= e(url('auction-house')) ?>">Auction House</a> &raquo; Professions</div>
        </div>
        <div class="bb-small" style="text-align:right">Armory sample taken <?= e($bbDate($pc['fetchedFrom'])) ?> &ndash; <?= e(gmdate('g:i a', strtotime($pc['fetchedTo']))) ?> UTC<br>
          <b><?= fmt($pc['profiles']) ?></b> characters answered of <?= fmt($pc['sampled']) ?> looked up</div>
      </div>

      <table class="forumline">
        <tr><th colspan="2">Profession</th><th>Characters</th><th class="hide-sm">Share of sample</th><th>Avg Skill</th><th class="hide-sm">Median</th><th class="hide-sm">Avg Recipes</th><th>At Cap</th></tr>
<?php foreach ($groupsBB as $title => $role): ?>
        <tr><td class="catHead" colspan="8"><span class="cattitle"><?= e($title) ?></span></td></tr>
<?php foreach ($prim as $p): if (($cards[$slugOf[$p['name']] ?? '']['role'] ?? '') !== $role) continue; $b = $p['brackets']; ?>
        <tr>
          <td class="row1 c hide-sm" width="30"><span class="bb-folder<?= $p === $prim[0] || $p === $prim[1] ? ' new' : '' ?>"></span></td>
          <td class="row1"><?= $trade($p['name']) ?>
            <span class="bb-desc">Apprentice <?= fmt($b['Apprentice'] ?? 0) ?> &middot; Journeyman <?= fmt($b['Journeyman'] ?? 0) ?> &middot; Expert <?= fmt($b['Expert'] ?? 0) ?> &middot; Artisan <b><?= fmt($b['Artisan'] ?? 0) ?></b> &middot; best seen <?= fmt($p['top']) ?></span></td>
          <td class="row2 c"><?= fmt($p['count']) ?></td>
          <td class="row2 hide-sm" nowrap><?= $bar($p['pct']) ?><span class="bb-pct"><?= $p['pct'] ?>%</span></td>
          <td class="row2 c"><b><?= $p['avg'] ?></b></td>
          <td class="row2 c hide-sm"><?= $p['median'] + 0 ?></td>
          <td class="row2 c hide-sm"><?= $p['recipes'] ?></td>
          <td class="row3 c"><?= $p['maxed'] ?>%</td>
        </tr>
<?php endforeach; endforeach; ?>
        <tr><td class="catHead" colspan="8"><span class="cattitle">Secondary Skills</span></td></tr>
<?php foreach ($pc['secondaries'] as $p): $b = $p['brackets']; ?>
        <tr>
          <td class="row1 c hide-sm"><span class="bb-folder"></span></td>
          <td class="row1"><?= $trade($p['name']) ?>
            <span class="bb-desc">Apprentice <?= fmt($b['Apprentice'] ?? 0) ?> &middot; Journeyman <?= fmt($b['Journeyman'] ?? 0) ?> &middot; Expert <?= fmt($b['Expert'] ?? 0) ?> &middot; Artisan <b><?= fmt($b['Artisan'] ?? 0) ?></b></span></td>
          <td class="row2 c"><?= fmt($p['count']) ?></td>
          <td class="row2 hide-sm" nowrap><?= $bar($p['pct'] / 2) ?><span class="bb-pct"><?= $p['pct'] ?>%</span></td>
          <td class="row2 c"><b><?= $p['avg'] ?></b></td>
          <td class="row2 c hide-sm"><?= $p['median'] + 0 ?></td>
          <td class="row2 c hide-sm"><?= $p['recipes'] ?></td>
          <td class="row3 c"><?= $p['maxed'] ?>%</td>
        </tr>
<?php endforeach; ?>
        <tr><td class="spaceRow" colspan="8"></td></tr>
      </table>

      <div class="bb-cols">
        <table class="forumline">
          <tr><th colspan="2">Poll :: What two trades did they pick?</th></tr>
<?php foreach ($pc['combos'] as [$combo, $k, $pct]): ?>
          <tr><td class="row1"><span class="bb-small"><?= e($combo) ?></span></td>
            <td class="row1" nowrap><?= $bar($pct * 3) ?><span class="bb-pct"><?= $pct ?>%</span> <span class="bb-small bb-cnt">[ <?= fmt($k) ?> ]</span></td></tr>
<?php endforeach; ?>
          <tr><td class="row2 c" colspan="2"><b class="bb-small">Total Votes : <?= fmt($pc['profiles']) ?></b></td></tr>
        </table>

        <table class="forumline">
          <tr><th>Class</th><th>Favourite</th><th>Runner-up</th><th class="hide-sm">Third</th></tr>
<?php foreach ($pc['classes'] as $i => $c): $r = $i % 2 ? 'row2' : 'row1'; ?>
          <tr><td class="<?= $r ?>"><b><?= e($c['class']) ?></b> <span class="bb-small bb-cnt">(<?= fmt($c['count']) ?>)</span></td>
<?php foreach ([0, 1, 2] as $j): $t = $c['top'][$j] ?? null; ?>
            <td class="<?= $r ?> bb-small<?= $j === 2 ? ' hide-sm' : '' ?>"><?= $t ? e($t[0]) . ' <span class="bb-cnt">' . $t[1] . '%</span>' : '&nbsp;' ?></td>
<?php endforeach; ?>
          </tr>
<?php endforeach; ?>
        </table>
      </div>

      <table class="forumline bb-stats">
        <tr><td class="catHead" colspan="2"><span class="cattitle">Census Statistics</span></td></tr>
        <tr><td class="row1 icon" rowspan="3"><span class="bb-users"></span></td>
          <td class="row1 bb-small">Our sampled characters have learned a total of <b><?= fmt($pc['learned']) ?></b> skills and <b><?= fmt($pc['recipes']) ?></b> recipes<br>
            <b><?= fmt($pc['primaryCounts']['2'] ?? 0) ?></b> carry two primary professions, <b><?= fmt($pc['primaryCounts']['1'] ?? 0) ?></b> just one and <b><?= fmt($pc['primaryCounts']['0'] ?? 0) ?></b> none at all &middot;
            <b><?= fmt($pc['capped']) ?></b> primaries are at their current bracket cap<br>
            Average primary skill by level:
<?php foreach ($pc['levels'] as $i => [$band, $k, $avg]): ?>
            <?= $i ? '&middot; ' : '' ?><?= e($band) ?> <b><?= $avg ?></b> <span class="bb-cnt">(<?= fmt($k) ?>)</span>
<?php endforeach; ?></td></tr>
        <tr><td class="row1 bb-small">Characters by region:
<?php foreach ($pc['regions'] as $reg => $k): ?> <b><?= e($reg) ?></b> <?= fmt($k) ?><?php endforeach; ?>
            &middot; <?= fmt($pc['notFound']) ?> profiles could not be found on the armory</td></tr>
        <tr><td class="row1 bb-small bb-legend">[ <?php $rc = ['PvP' => 'rs-pvp', 'Normal' => 'rs-normal', 'Roleplay' => 'rs-rp', 'Roleplay PvP' => 'rs-rppvp']; $first = true;
            foreach ($pc['rulesets'] as $rs => $k): ?><?= $first ? '' : ' ] &nbsp; [ ' ?><b class="<?= $rc[$rs] ?? '' ?>"><?= e($rs) ?></b> <?= fmt($k) ?><?php $first = false; endforeach; ?> ]</td></tr>
      </table>

      <div class="bb-foot bb-small"><span>Skill is the character's current points; &ldquo;At Cap&rdquo; means maxed for the rank they have trained.</span>
        <a href="#census">Back to top</a></div>
    </div>

    <div class="pf-intro">
      <svg class="book" viewBox="0 0 64 64" aria-hidden="true">
        <defs><linearGradient id="pf-bk" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#a0381a"/><stop offset="1" stop-color="#4a1208"/></linearGradient></defs>
        <path d="M8 12c8-4 16-4 24 2v40c-8-6-16-6-24-2z" fill="#f8ecc8" stroke="#7a5a20" stroke-width="2"/>
        <path d="M56 12c-8-4-16-4-24 2v40c8-6 16-6 24-2z" fill="#f1e1b4" stroke="#7a5a20" stroke-width="2"/>
        <g stroke="#9c7c42" stroke-width="1.6"><line x1="13" y1="22" x2="27" y2="22"/><line x1="13" y1="28" x2="27" y2="28"/><line x1="13" y1="34" x2="24" y2="34"/>
          <line x1="37" y1="22" x2="51" y2="22"/><line x1="37" y1="28" x2="51" y2="28"/><line x1="37" y1="34" x2="48" y2="34"/></g>
        <path d="M4 52c10-4 20-4 28 3 8-7 18-7 28-3v4c-10-4-20-4-28 3-8-7-18-7-28-3z" fill="url(#pf-bk)"/>
      </svg>
      <p>Every adventurer may learn <b>two primary professions</b> and all <b>three secondary skills</b>. Choose a trade below to open its
        market ledger: what its <b>materials</b>, <b>crafted goods</b> and <b>recipes</b> are listing for on the WoW: Forever auction house,
        taken from the same in-game scans as the <a href="<?= e(url('auction-house')) ?>">Auction House</a> page.</p>
    </div>

    <div class="ahfilters">
      <span class="lbl">Realm</span>
      <div class="seg" id="pfRealm"></div>
      <span class="lbl" style="margin-left:8px">Faction</span>
      <div class="seg" id="pfFac"></div>
    </div>

<?php foreach ($groups as $role => [$title, $sub, $icon]): ?>
    <?php component_start('section', ['title' => $title, 'icon' => $icon, 'sub' => $sub]) ?>
        <div class="pf-cards">
<?php foreach ($cards as $slug => $c): if ($c['role'] !== $role) continue; ?>
          <a class="pf-card" href="<?= e($c['url']) ?>" data-slug="<?= e($slug) ?>">
            <span class="pf-ic"><img src="/assets/images/wow/icons/large/<?= e($c['icon']) ?>.jpg" alt="" width="52" height="52"></span>
            <h3><?= e($c['name']) ?></h3>
            <span class="role"><?= e($c['role'] === 'Secondary' ? 'Secondary skill' : $c['role']) ?></span>
            <span class="blurb"><?= e($c['blurb']) ?></span>
            <span class="ledger"><span><b data-k="items">—</b> items · <b data-k="gold">—</b> listed</span><span class="go">Market »</span></span>
          </a>
<?php endforeach; ?>
        </div>
    <?php component_end() ?>
<?php endforeach; ?>

    <div class="ahwin pf-board" style="margin-bottom:16px">
      <div class="wt">Trade Board<small>professions ranked by listed asking value</small></div>
      <table class="ahtable"><thead><tr><th class="rk">#</th><th>Profession</th><th class="num">Items</th><th class="num">Units</th><th class="num">Listed Value</th></tr></thead>
        <tbody id="pfBoard"></tbody></table>
    </div>

    <div class="source">Auction data from in-game scans · asking prices on listed auctions, not completed sales · an item can count toward more than one trade</div>
  </main>

<?php start_section('scripts') ?>
<script>
const PF = <?= js($data) ?>;
const CARDS = <?= js($cards) ?>;
const REALMS = <?= js($realms) ?>;
const FACTIONS = <?= js($factions) ?>;
const view = (r, f) => PF.views.find(v => v.realm === r && v.faction === f);
const hasData = v => v && !v.empty;
const goldShort = g => g >= 1e6 ? (g / 1e6).toFixed(1) + 'M' : g >= 1e3 ? Math.round(g / 1e3) + 'k' : fmt(g);
const goldMoney = g => `<span class="money"><span>${goldShort(g)}<i class="g"></i></span></span>`;
let realm = REALMS[0][0], fac = FACTIONS[0][0];

function renderFilters() {
  const btn = (attr, k, l, on, ok) => `<button data-${attr}="${esc(k)}" class="${on ? 'on' : ''} ${ok ? '' : 'nodata'}" ${ok ? '' : 'title="No auction scans yet"'}>${esc(l)}</button>`;
  document.getElementById('pfRealm').innerHTML = REALMS.length > 6
    ? `<select class="realm-pick" aria-label="Realm">${REALMS.map(([k, l]) => `<option value="${esc(k)}"${k === realm ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>`
    : REALMS.map(([k, l]) => btn('r', k, l, k === realm, FACTIONS.some(([f]) => hasData(view(k, f))))).join('');
  document.getElementById('pfFac').innerHTML = FACTIONS.map(([k, l]) => btn('f', k, l, k === fac, hasData(view(realm, k)))).join('');
}
const pickRealm = r => { realm = r; if (!hasData(view(realm, fac))) fac = (FACTIONS.find(([f]) => hasData(view(realm, f))) || ['all'])[0]; render(); };
document.getElementById('pfRealm').addEventListener('click', e => { if (e.target.dataset.r) pickRealm(e.target.dataset.r); });
document.getElementById('pfRealm').addEventListener('change', e => pickRealm(e.target.value));
document.getElementById('pfFac').addEventListener('click', e => { if (e.target.dataset.f) { fac = e.target.dataset.f; render(); } });

function render() {
  renderFilters();
  const v = view(realm, fac), ok = hasData(v);
  document.getElementById('pfstamp').textContent = ok ? `Scanned: ${v.scanned} · Page generated: ${PF.generated}` : `Page generated: ${PF.generated}`;
  // Card links carry the filter so the trade page opens on the same realm and faction.
  const q = (realm === 'all' && fac === 'all') ? '' : `?realm=${encodeURIComponent(realm)}&faction=${encodeURIComponent(fac)}`;
  document.querySelectorAll('.pf-card').forEach(a => {
    const p = ok ? v.p[a.dataset.slug] : null;
    a.href = CARDS[a.dataset.slug].url + q;
    a.querySelector('[data-k=items]').textContent = p ? fmt(p[0]) : '0';
    a.querySelector('[data-k=gold]').textContent = p ? goldShort(p[2]) + ' g' : '0 g';
    a.classList.toggle('quiet', !p || !p[0]);
  });
  const rows = ok ? Object.entries(v.p).sort((a, b) => b[1][2] - a[1][2]) : [];
  const max = rows.length ? rows[0][1][2] || 1 : 1;
  document.getElementById('pfBoard').innerHTML = rows.length ? rows.map(([s, p], i) => `<tr>
      <td class="rk">${i + 1}</td>
      <td class="trade"><a href="${esc(CARDS[s].url + q)}">${wowIcon(CARDS[s].icon)}${esc(CARDS[s].name)}</a></td>
      <td class="num">${fmt(p[0])}</td><td class="num">${fmt(p[1])}</td>
      <td class="num">${goldMoney(p[2])}<span class="qbar"><i data-w="${p[2] / max * 100}%"></i></span></td></tr>`).join('')
    : '<tr><td colspan="5" style="padding:14px">No auction scan for this realm and faction yet.</td></tr>';
  grow(document.getElementById('pfBoard'));
}
render();
</script>
<?php end_section() ?>
