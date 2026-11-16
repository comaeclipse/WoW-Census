<?php
namespace Wow2008;
/**
 * Page: Professions (WoW: Forever). The armory profession census, then the Trade Board linking to each trade's market page.
 * Vars: $cards (slug => name, role, kind, icon, blurb, url), $data (generated, views: realm, faction, scanned, empty,
 *       p: slug => [items, units, gold, mats, crafted, recipes]), $pcensus (src/forever-profession-census.json), $subtitle
 */
$pc = $pcensus;
$n = $pc['profiles'];
$prim = $pc['primaries'];
$combos = $pc['combos'];
$isRetail = ($professionSource ?? 'forever') === 'retail';
$slugOf = [];
foreach ($cards as $s => $c) $slugOf[$c['name']] = $s;
$iconOf = fn($name) => $cards[$slugOf[$name] ?? '']['icon'] ?? 'inv_misc_questionmark';
$ic = fn($name) => '<span class="ic"><img src="/assets/images/wow/icons/small/' . e($iconOf($name)) . '.jpg" alt=""></span>';
$link = fn($name) => isset($slugOf[$name]) ? '<a href="' . e($cards[$slugOf[$name]]['url']) . '">' . e($name) . '</a>' : e($name);
$bar = fn($pct, $max) => '<span class="qbar"><i style="width:' . round($pct / max(0.1, $max) * 100) . '%"></i></span>';
$classic = ['Leatherworking + Skinning', 'Blacksmithing + Mining', 'Enchanting + Tailoring', 'Alchemy + Herbalism', 'Engineering + Mining'];
$classicPct = round(array_sum(array_map(fn($c) => in_array($c[0], $classic, true) ? $c[2] : 0, $combos)));
$avgPrimary = round(array_sum(array_map(fn($p) => $p['avg'] * $p['count'], $prim)) / max(1, array_sum(array_column($prim, 'count'))));
$freeSlot = ($pc['primaryCounts']['1'] ?? 0) + ($pc['primaryCounts']['0'] ?? 0);
$classColor = ['Hunter' => '#abd473', 'Paladin' => '#f58cba', 'Shaman' => '#0070de', 'Priest' => '#ffffff', 'Mage' => '#69ccf0',
  'Rogue' => '#fff569', 'Warrior' => '#c79c6e', 'Warlock' => '#9482c9', 'Druid' => '#ff7d0a', 'Death Knight' => '#c41f3b',
  'Monk' => '#00ff96', 'Demon Hunter' => '#a330c9', 'Evoker' => '#33937f'];
$classIcon = fn($name) => strtolower(str_replace(' ', '', $name));
[$topA, $topB] = array_pad(explode(' + ', $combos[0][0] ?? 'No pairing'), 2, '');
$short = ['Leatherworking' => 'LW', 'Blacksmithing' => 'BS'];
?>
  <style>
    .pc-tallies{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px}
    .pc-tally{display:grid;grid-template-columns:40px 1fr;gap:2px 9px;align-items:center;padding:8px 10px;
      background:linear-gradient(#fff6d8,#ecd59c);border:1px solid #8a6a2a;border-radius:3px;box-shadow:inset 0 0 0 1px #fff7d8,0 1px 2px rgba(60,30,0,.35)}
    .pc-tally .ti{grid-row:1 / span 2;width:40px;height:40px;border:2px solid #1a1206;outline:1px ridge #c8a050;border-radius:3px;overflow:hidden;background:#111}
    .pc-tally .ti img{display:block;width:100%;height:100%}
    .pc-tally .v{font:700 18px/1 'Cinzel',serif;color:#5a2a00}
    .pc-tally .v small{font:700 11px Tahoma,Arial,sans-serif;color:#3a1a00}
    .pc-tally .l{font:700 9px Tahoma,Arial,sans-serif;text-transform:uppercase;letter-spacing:.6px;color:#8a5a10}
    .pc-cols{display:grid;grid-template-columns:1.1fr 1fr;gap:12px;margin-bottom:12px}
    .pc-cols .ahwin{margin:0}
    .ahtable .trade a,.ahtable .trade span{vertical-align:middle}
    .ahtable .pair .ic + .ic{margin-left:-3px}
    .ahtable .pair em{font-style:normal;color:#9a8a6a;padding:0 2px}
    .ahtable .num b{color:#fff}
    .pc-cols .qbar i{transition:none}
    .pc-note{padding:6px 8px;font-size:10px;color:#d8b8a0;border-top:1px solid #2a2418;background:rgba(0,0,0,.25)}
    .pc-note b{color:#ffd75a}
    .pc-h{margin:2px 0 6px;padding-bottom:3px;border-bottom:1px dashed #b8955a;font:700 12px 'Cinzel',serif;color:#5a2a00;font-variant:small-caps;letter-spacing:.5px}
    .pc-secs{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px}
    .pc-sec{display:flex;gap:8px;align-items:center;padding:5px 8px;background:rgba(255,248,225,.7);border:1px solid #b8955a;color:#3a1a00;text-decoration:none}
    .pc-sec:hover{box-shadow:0 0 0 1px #ffd75a}
    .pc-sec .si{flex:none;width:26px;height:26px;border:1px solid #000;outline:1px solid #8a7040;border-radius:2px;overflow:hidden}
    .pc-sec .si img{display:block;width:100%;height:100%}
    .pc-sec b{display:block;font:700 11px Tahoma,Arial,sans-serif}
    .pc-sec span{font-size:10px;color:#6a4a20}
    .pc-classes{display:grid;grid-template-columns:repeat(9,1fr);gap:6px}
    .pc-cls{padding:6px 5px;text-align:center;background:linear-gradient(#1c1810,#0e0c08);border:1px solid #000;outline:1px solid #8a7040;border-radius:3px}
    .pc-cls img{width:30px;height:30px;border:1px solid #000;outline:1px solid #6a6050;border-radius:3px}
    .pc-cls b{display:block;margin:3px 0 2px;font:700 10px Tahoma,Arial,sans-serif;text-shadow:0 1px 1px #000}
    .pc-cls span{display:block;font-size:9px;color:#e8dcc0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .pc-cls span + span{color:#9a8a6a}
    .pc-cls i{font-style:normal;color:#ffd75a}
    .pc-cls span + span i{color:#b8a070}
    .pf-board .trade a{display:inline-flex;align-items:center;gap:6px}
    .pf-board .trade img{width:18px;height:18px;border:1px solid #000;outline:1px solid #6a6050;border-radius:2px}
    @media (max-width:900px){.pc-tallies{grid-template-columns:1fr 1fr}.pc-cols{grid-template-columns:1fr}.pc-classes{grid-template-columns:repeat(3,1fr)}}
    @media (max-width:560px){.pc-secs{grid-template-columns:1fr}.pf-board .qbar{display:none}}
  </style>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Auction House', 'url' => url('auction-house')], ['label' => 'Professions']]]) ?>

    <?= component('banner', ['art' => 'auction-house', 'title' => 'Professions', 'subtitle' => $subtitle . ' · TRADE SKILLS', 'subtitle_color' => '#ffe8a0', 'stamp_id' => 'pfstamp']) ?>

    <?php component_start('section', ['title' => 'Profession Census', 'icon' => 'g',
      'sub' => fmt($n) . ' characters sampled from the ' . ($isRetail ? 'Retail' : 'Forever') . ' armory' . (!empty($pc['fetchedTo']) ? ' · ' . gmdate('j M Y', strtotime($pc['fetchedTo'])) : '')]) ?>
        <div class="pc-tallies">
          <div class="pc-tally"><span class="ti"><img src="/assets/images/wow/icons/large/inv_misc_book_09.jpg" alt=""></span>
            <span class="v"><?= fmt($n) ?></span><span class="l">Characters sampled</span></div>
          <div class="pc-tally"><span class="ti"><img src="/assets/images/wow/icons/large/<?= e($iconOf($prim[0]['name'] ?? '')) ?>.jpg" alt=""></span>
            <span class="v"><?= e($prim[0]['name'] ?? 'No data') ?> <small><?= round($prim[0]['pct'] ?? 0) ?>%</small></span><span class="l">Most learned trade</span></div>
          <div class="pc-tally"><span class="ti"><img src="/assets/images/wow/icons/large/<?= e($iconOf($topA)) ?>.jpg" alt=""></span>
            <span class="v"><?= round($combos[0][2] ?? 0) ?>% <small><?= e($short[$topA] ?? $topA) ?><?= $topB !== '' ? ' &amp; ' . e($short[$topB] ?? $topB) : '' ?></small></span><span class="l">Top pairing</span></div>
          <div class="pc-tally"><span class="ti"><img src="/assets/images/wow/icons/large/inv_misc_note_01.jpg" alt=""></span>
            <span class="v"><?= $avgPrimary ?> <small>skill</small></span><span class="l">Average primary</span></div>
        </div>

        <div class="pc-cols">
          <div class="ahwin"><div class="wt">Most Learned<small>share of characters with each trade</small></div>
            <table class="ahtable"><thead><tr><th class="rk">#</th><th>Profession</th><th class="num">Learned by</th><th class="num">Avg Skill</th></tr></thead><tbody>
<?php foreach ($prim as $i => $p): ?>
              <tr><td class="rk"><?= $i + 1 ?></td><td class="trade"><?= $ic($p['name']) . $link($p['name']) ?></td>
                <td class="num"><b><?= number_format($p['pct'], 1) ?>%</b><?= $bar($p['pct'], $prim[0]['pct'] ?? 1) ?></td><td class="num"><?= round($p['avg']) ?></td></tr>
<?php endforeach; ?>
            </tbody></table></div>
          <div class="ahwin"><div class="wt">Common Pairings<small>the two primaries together</small></div>
            <table class="ahtable"><thead><tr><th class="rk">#</th><th>Pairing</th><th class="num">Characters</th></tr></thead><tbody>
<?php foreach (array_slice($combos, 0, 6) as $i => [$combo, $k, $pct]): $pair = explode(' + ', $combo); ?>
              <tr><td class="rk"><?= $i + 1 ?></td>
                <td class="trade pair"><?= count($pair) === 2 ? $ic($pair[0]) . $ic($pair[1]) . '<span>' . e($pair[0]) . ' <em>&amp;</em> ' . e($pair[1]) . '</span>' : e($combo) ?></td>
                <td class="num"><b><?= number_format($pct, 1) ?>%</b><?= $bar($pct, $combos[0][2] ?? 1) ?></td></tr>
<?php endforeach; ?>
            </tbody></table>
            <div class="pc-note"><?php if ($isRetail): ?><b><?= fmt($freeSlot) ?></b> of <?= fmt($n) ?> sampled characters have fewer than two recorded primary professions.<?php else: ?><b><?= $classicPct ?>%</b> of characters run one of the five classic pairings. Only <?= fmt($freeSlot) ?> of <?= fmt($n) ?> have a free slot.<?php endif ?></div></div>
        </div>

        <div class="pc-h">Secondary Skills</div>
        <div class="pc-secs">
<?php foreach ($pc['secondaries'] as $p): ?>
          <a class="pc-sec" href="<?= e($cards[$slugOf[$p['name']] ?? '']['url'] ?? '#') ?>"><span class="si"><img src="/assets/images/wow/icons/small/<?= e($iconOf($p['name'])) ?>.jpg" alt=""></span>
            <div><b><?= e($p['name']) ?></b><span><?= round($p['pct']) ?>% have it &middot; avg <?= round($p['avg']) ?></span></div></a>
<?php endforeach; ?>
        </div>

        <div class="pc-h">Class Favourites</div>
        <div class="pc-classes">
<?php foreach ($pc['classes'] as $c): ?>
          <div class="pc-cls"><img src="/assets/images/wow/icons/large/classicon_<?= e($classIcon($c['class'])) ?>.jpg" alt="">
            <b style="color:<?= $classColor[$c['class']] ?? '#fff' ?>"><?= e($c['class']) ?></b>
<?php foreach (array_slice($c['top'], 0, 2) as [$t, $tp]): ?>
            <span><?= e($t) ?> <i><?= round($tp) ?>%</i></span>
<?php endforeach; ?>
          </div>
<?php endforeach; ?>
        </div>
    <?php component_end() ?>

    <div class="ahwin pf-board" style="margin-bottom:16px">
      <div class="wt">Trade Board<small>professions ranked by listed asking value · click a trade for its market</small></div>
      <table class="ahtable"><thead><tr><th class="rk">#</th><th>Profession</th><th class="num">Items</th><th class="num">Units</th><th class="num">Listed Value</th></tr></thead>
        <tbody id="pfBoard"></tbody></table>
    </div>

    <div class="source">Census from armory profiles of sampled characters · auction data from in-game scans, asking prices on listed auctions · an item can count toward more than one trade</div>
  </main>

<?php start_section('scripts') ?>
<script>
const PF = <?= js($data) ?>;
const CARDS = <?= js($cards) ?>;
const goldShort = g => g >= 1e6 ? (g / 1e6).toFixed(1) + 'M' : g >= 1e3 ? Math.round(g / 1e3) + 'k' : fmt(g);
const goldMoney = g => `<span class="money"><span>${goldShort(g)}<i class="g"></i></span></span>`;
const v = PF.views.find(v => v.realm === 'all' && v.faction === 'all') || PF.views[0];
const ok = v && !v.empty;
document.getElementById('pfstamp').textContent = ok ? `Scanned: ${v.scanned} · Page generated: ${PF.generated}` : `Page generated: ${PF.generated}`;
const rows = ok ? Object.entries(v.p).sort((a, b) => b[1][2] - a[1][2]) : [];
const max = rows.length ? rows[0][1][2] || 1 : 1;
document.getElementById('pfBoard').innerHTML = rows.length ? rows.map(([s, p], i) => `<tr>
    <td class="rk">${i + 1}</td>
    <td class="trade"><a href="${esc(CARDS[s].url)}">${wowIcon(CARDS[s].icon)}${esc(CARDS[s].name)}</a></td>
    <td class="num">${fmt(p[0])}</td><td class="num">${fmt(p[1])}</td>
    <td class="num">${goldMoney(p[2])}<span class="qbar"><i data-w="${p[2] / max * 100}%"></i></span></td></tr>`).join('')
  : '<tr><td colspan="5" style="padding:14px">No auction scan yet.</td></tr>';
grow(document.getElementById('pfBoard'));
</script>
<?php end_section() ?>
