<?php
namespace Wow2008;
/**
 * Page: one WoW: Forever item's auction overview and history.
 * Vars: $item (forever_item_model: id, name, icon, cat, crafter, taught), $trades (list of name, icon, url, role),
 *       $data (generated + forever_item_market_summary(): markets key => [label, faction], points, scans, lastScan), $wowhead, $subtitle
 */
$icon = $item['icon'] ?: 'inv_misc_questionmark';
$w = $item['wearers'];
$listed = (bool)$item['scans'];
$tip = $item['tip'] ?? null;
$aw = $item['armoryWearers'] ?? null;
$onlyFaction = $aw && count($aw['factions']) === 1 ? (string)array_key_first($aw['factions']) : null;
$lvTop = $aw ? array_key_first($aw['levels']) : null;
// Not tradeable and never listed: say so beside the tooltip instead of an empty market section.
$bopNote = $tip && $tip['bop'] && !$listed;
$money = function (int $cu): string {
  $g = intdiv($cu, 10000); $s = intdiv($cu, 100) % 100; $c = $cu % 100; $out = '';
  if ($g) $out .= '<span>' . fmt($g) . '<i class="g"></i></span>';
  if ($s || ($g && $c)) $out .= '<span>' . $s . '<i class="s"></i></span>';
  if ($c && !$g) $out .= '<span>' . $c . '<i class="c"></i></span>';
  return '<span class="money">' . ($out ?: '<span>0<i class="c"></i></span>') . '</span>';
};
$pct = fn($n, $d) => $d ? (int)round(100 * $n / $d) : 0;
$sales = $item['sales'] ?? null;
$loot = $item['loot'] ?? null;
$vendor = $item['vendor'] ?? null;
$whBase = preg_replace('/item=\d+$/', '', $wowhead);
// 4500 -> "1h 15m"; 200000 -> "2d 7h"
$dur = function (int $s): string {
  if ($s < 3600) return max(1, intdiv($s, 60)) . 'm';
  if ($s < 86400) return intdiv($s, 3600) . 'h' . (intdiv($s % 3600, 60) ? ' ' . intdiv($s % 3600, 60) . 'm' : '');
  return intdiv($s, 86400) . 'd' . (intdiv($s % 86400, 3600) ? ' ' . intdiv($s % 86400, 3600) . 'h' : '');
};
$ago = function (int $t) use ($dur): string {
  $s = time() - $t;
  return $s < 60 ? 'just now' : $dur($s) . ' ago';
};
$QCOL = [0 => '#9d9d9d', 1 => '#ffffff', 2 => '#1eff00', 3 => '#0070dd', 4 => '#a335ee', 5 => '#ff8000', 6 => '#e6cc80', 7 => '#00ccff'];
$nameColor = $item['quality'] !== null ? ($QCOL[$item['quality']] ?? '#ffffff') : '#ffffff';
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
    .it-verdict{display:flex;flex-wrap:wrap;gap:10px;align-items:baseline;margin-bottom:10px;padding:8px 12px;border:1px solid #8a6a2a;border-radius:3px;
      background:linear-gradient(#fff6d8,#ecd59c);color:#3a1a00;font-size:12px}
    .it-verdict b{font:700 15px 'Cinzel',serif;font-variant:small-caps;color:#5a2a00}
    .it-verdict.auction{border-color:#2a7a2a;box-shadow:inset 3px 0 0 #2a8a2a}
    .it-verdict.vendor{border-color:#8a5a10;box-shadow:inset 3px 0 0 #b07a00}
    .it-rate{display:inline-block;position:relative;min-width:64px;padding:1px 4px;text-align:right;border:1px solid #6a5020;background:#1a140a;color:#fff;font-weight:700}
    .it-rate i{position:absolute;left:0;top:0;bottom:0;background:linear-gradient(#5a9a2a,#2a6a10)}
    .it-rate b{position:relative}
    .it-rate.early{opacity:.55}
    .it-chart text{font:10px Tahoma,Arial,sans-serif;fill:#b8a888}
    .it-chart .grid{stroke:#2e2818;stroke-width:1}
    .it-chart .ttl{font:700 11px Tahoma,Arial,sans-serif;fill:#ffd75a}
    .it-legend{display:flex;flex-wrap:wrap;gap:4px 14px;margin:8px 2px 2px;font:10px Tahoma,Arial,sans-serif;color:#d8c8a8}
    .it-legend i{display:inline-block;width:14px;height:3px;margin-right:5px;vertical-align:3px}
    .it-legend .lo{display:inline-block;width:14px;border-top:2px dashed #b8a888;margin-right:5px;vertical-align:3px}
    .it-charts{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    .it-one{padding:14px;color:#c8b898;font-size:11px;text-align:center}
    .report .money,.tiles .money{color:#3a1a00;text-shadow:none}
    .it-wear{display:grid;grid-template-columns:1.2fr 1fr;gap:14px}
    .it-bars{background:linear-gradient(#1c1810,#0e0c08);border:2px solid #000;outline:1px solid #8a7040;border-radius:4px;padding:8px 10px}
    .it-bars h3{margin:0 0 6px;font:700 11px Tahoma,Arial,sans-serif;color:#ffd75a}
    .it-bar{display:grid;grid-template-columns:96px 1fr 60px;gap:8px;align-items:center;font:11px Tahoma,Arial,sans-serif;color:#e8dcc0;padding:2px 0}
    .it-bar .tr{display:block;height:10px;background:#000;border:1px solid #3a3020}
    .it-bar .tr i{display:block;height:100%;width:0;transition:width .8s}
    .it-bar .v{text-align:right;color:#d8c8a0}
    .it-fac{display:flex;height:14px;border:1px solid #000;margin:4px 0 8px}
    .it-fac i{display:block}
    .it-nomkt{padding:10px 12px;background:linear-gradient(#fbe9b6,#e8d098);border:1px solid #9c7c42;font-size:12px;color:#3a1a00}
    .it-hero .meta .chip{font:700 9px Tahoma,Arial,sans-serif;letter-spacing:.6px;text-transform:uppercase;padding:3px 7px;border:1px solid #6a4a18;border-radius:2px;color:#f0d8a0;background:rgba(0,0,0,.45)}
    .it-hero .meta .chip.red{color:#ffb0a0;border-color:#7a2a18}
    .it-top{display:grid;grid-template-columns:270px 1fr;gap:14px;align-items:start}
    .it-top.solo{grid-template-columns:minmax(0,320px)}
    .it-tt{background:rgba(8,10,28,.94);border:1px solid #8a8a9a;border-radius:4px;box-shadow:0 0 0 1px #000,0 4px 14px #000;padding:9px 11px;font:12px/1.45 Tahoma,Arial,sans-serif;color:#fff}
    .it-tt .n{font-size:14px;font-weight:700}
    .it-tt .y{color:#ffd100}
    .it-tt .g{color:#1eff00}
    .it-tt .r{display:flex;justify-content:space-between;gap:10px}
    .it-tt .money{text-shadow:none}
    .it-top .tiles{margin:0;grid-template-columns:repeat(2,1fr)}
    .it-why{margin-top:10px;padding:9px 12px;background:linear-gradient(#fbe9b6,#e8d098);border:1px solid #9c7c42;font-size:12px;color:#3a1a00}
    .it-why b{color:#7a1a00}
    .it-why .money{color:#3a1a00;text-shadow:none}
    .it-why.sum{background:linear-gradient(#f4ecd8,#dccfa8)}
    .it-bar.cls{grid-template-columns:20px 70px 1fr 48px 52px}
    .it-bar.cls img{width:18px;height:18px;border:1px solid #000}
    .it-bar .ad{text-align:right;color:#9c8c6c;font-size:10px}
    .it-bars .hd{display:grid;grid-template-columns:20px 70px 1fr 48px 52px;gap:8px;font:700 9px Tahoma,Arial,sans-serif;color:#8a7a5a;text-transform:uppercase;letter-spacing:.5px;padding-bottom:3px;border-bottom:1px solid #2e2818;margin-bottom:3px}
    .it-bars .hd span:nth-child(n+4){text-align:right}
    .it-bars .note{margin:8px 0 0;font:10px Tahoma,Arial,sans-serif;color:#b8a888}
    .it-bars .note b{color:#ffd75a}
    .it-three{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-top:14px}
    .it-hist{display:flex;align-items:flex-end;gap:3px;height:96px;padding-top:4px;border-bottom:1px solid #3a3020}
    .it-hist div{flex:1;display:flex;flex-direction:column;justify-content:flex-end;height:100%}
    .it-hist i{display:block;background:linear-gradient(#ffd75a,#b07a00);border:1px solid #000;border-bottom:0}
    .it-hist div.me i{background:linear-gradient(#7aff5a,#1e8a00)}
    .it-hx{display:flex;gap:3px;font:9px Tahoma,Arial,sans-serif;color:#9c8c6c;margin-top:3px}
    .it-hx span{flex:1;text-align:center}
    .it-hx span.me{color:#7aff5a}
    .it-split{display:flex;height:22px;border:1px solid #000;margin:6px 0 4px;font:700 10px/22px Tahoma,Arial,sans-serif;color:#000;text-align:center;overflow:hidden;white-space:nowrap}
    .it-big{font:900 26px 'Cinzel',serif;color:#ffd75a;text-shadow:0 2px 2px #000}
    .it-big small{font:11px Tahoma,Arial,sans-serif;color:#b8a888;margin-left:6px}
    .it-pairs{display:grid;grid-template-columns:1fr 1fr;gap:6px 16px}
    .it-pair{display:grid;grid-template-columns:1fr 120px 40px;gap:8px;align-items:center;padding:6px 8px;background:linear-gradient(#fff6d8,#ecd59c);border:1px solid #8a6a2a;font-size:12px}
    .it-pair a{font-weight:700;text-decoration:none}
    .it-pair .tr{display:block;height:9px;background:#3a2a10;border:1px solid #000}
    .it-pair .tr i{display:block;height:100%;width:0;background:linear-gradient(#ffd75a,#b07a00);transition:width .8s}
    .it-pair .v{text-align:right;font-weight:700;color:#5a2a00}
    .it-pair .q0{color:#6a6a6a}.it-pair .q1{color:#444}.it-pair .q2{color:#1a8a00}.it-pair .q3{color:#0058b0}.it-pair .q4{color:#8a2ad0}.it-pair .q5{color:#c86000}
    .it-loot{width:100%;background:linear-gradient(#1c1810,#0e0c08);border:2px solid #000;outline:1px solid #8a7040}
    .it-loot td{color:#e8dcc0}
    .it-loot td a{color:#ffd75a}
    .it-loot .kind{color:#9c8c6c}
    .it-verdict .money{color:#3a1a00;text-shadow:none}
    @media (max-width:600px){.it-loot th:nth-child(2),.it-loot td:nth-child(2),.it-loot th:nth-child(6),.it-loot td:nth-child(6){display:none}}
    .it-sales{width:100%;margin-top:10px;background:linear-gradient(#1c1810,#0e0c08);border:2px solid #000;outline:1px solid #8a7040}
    .it-sales td{color:#e8dcc0}
    .it-sales .fac-alliance{color:#7fb2ff}
    .it-sales .fac-horde{color:#ff7a6a}
    .it-sales .dim{color:#9c8c6c}
    @media (max-width:600px){.it-sales th:nth-child(3),.it-sales td:nth-child(3){display:none}}
    @media (max-width:860px){.it-top{grid-template-columns:1fr}.it-pairs{grid-template-columns:1fr}}
    @media (max-width:760px){.it-wear{grid-template-columns:1fr}}
    @media (max-width:760px){.it-charts{grid-template-columns:1fr}}
    @media (max-width:600px){.it-hero{grid-template-columns:auto 1fr}.it-wh{grid-column:1 / -1;justify-self:start}.it-hero h1{font-size:21px}.it-hero .emb{width:52px;height:52px}}
  </style>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Auction House', 'url' => url('auction-house')], ['label' => $item['name']]]]) ?>

    <div class="it-hero">
      <div class="emb"><img src="<?= e(($item['iconUrl'] ?? null) ?: '/assets/images/wow/icons/large/' . $icon . '.jpg') ?>" alt="" width="64" height="64"></div>
      <div>
        <div class="kick"><?= e($item['cat'] ?: 'Item') ?> · <?= e($subtitle) ?></div>
        <h1 style="color:<?= e($nameColor) ?>"><?= e($item['name']) ?></h1>
        <div class="meta">
          <?php if ($item['crafter']): ?><span>Crafted by <?= e($item['crafter']) ?></span><?php endif ?>
          <?php if ($item['taught']): ?><span>Teaches <a href="<?= e(\forever_item_path($item['taught']['id'])) ?>"><?= e($item['taught']['name']) ?></a></span><?php endif ?>
          <span>Item ID <?= (int)$item['id'] ?></span>
<?php if ($tip): ?>
          <?php if ($tip['ilvl']): ?><span class="chip">Item Level <?= (int)$tip['ilvl'] ?></span><?php endif ?>
          <?php if ($tip['binding']): ?><span class="chip<?= $tip['bop'] ? ' red' : '' ?>"><?= e($tip['binding']) ?></span><?php endif ?>
          <?php if ($tip['unique']): ?><span class="chip"><?= e($tip['unique']) ?></span><?php endif ?>
<?php endif ?>
<?php if ($onlyFaction): ?>          <span class="chip red"><?= e($onlyFaction) ?> only (as seen)</span>
<?php endif ?>
        </div>
      </div>
      <a class="it-wh" href="<?= e($wowhead) ?>" target="_blank" rel="noopener">Wowhead »</a>
    </div>

<?php if ($w): $maxClass = max(array_column($w['classes'], 2)); $fa = $w['factions']['Alliance'] ?? 0; $fh = $w['factions']['Horde'] ?? 0; ?>
    <?php component_start('section', ['title' => 'Who Wears It', 'icon' => 'p', 'sub' => 'from ' . fmt($w['inspected']) . ' inspected characters']) ?>
        <div class="tiles">
          <div class="stat"><span>Worn by</span><b><?= fmt($w['players']) ?></b><em>inspected characters</em></div>
          <div class="stat"><span><?= e($w['slot']) ?> share</span><b><?= $w['slotShare'] !== null ? e((string)$w['slotShare']) . '%' : '—' ?></b><em>of characters with that slot filled</em></div>
          <div class="stat"><span>Enchanted</span><b><?= (int)$w['enchanted'] ?>%</b><em>of the copies worn</em></div>
          <div class="stat"><span>Wearer level</span><b><?= $w['levels'] ? e((string)$w['levels']['avg']) : '—' ?></b><em><?= $w['levels'] ? 'average · ' . (int)$w['levels']['min'] . '–' . (int)$w['levels']['max'] : 'unknown' ?></em></div>
        </div>
        <div class="it-wear">
          <div class="it-bars"><h3>By class</h3>
<?php foreach ($w['classes'] as [$cname, $ccol, $n]): ?>
            <div class="it-bar" data-tip="<?= e($cname) ?>|<?= fmt($n) ?> wearing it|<?= round(100 * $n / $w['players'], 1) ?>% of wearers"><span style="color:<?= e($ccol) ?>"><?= e($cname) ?></span><span class="tr"><i data-w="<?= round(100 * $n / $maxClass, 1) ?>%" style="background:<?= e($ccol) ?>"></i></span><span class="v"><?= round(100 * $n / $w['players']) ?>%</span></div>
<?php endforeach ?>
          </div>
          <div class="it-bars"><h3>By faction</h3>
            <div class="it-fac" data-tip="Faction|Alliance <?= fmt($fa) ?>|Horde <?= fmt($fh) ?>"><i style="flex:<?= $fa ?>;background:linear-gradient(#5aa0ff,#2f6ad0)"></i><i style="flex:<?= $fh ?>;background:linear-gradient(#ff5a4a,#a01a10)"></i></div>
            <div class="it-bar" style="grid-template-columns:1fr auto"><span style="color:#9cc8ff">Alliance <?= $w['players'] ? round(100 * $fa / $w['players']) : 0 ?>%</span><span style="color:#ffa090">Horde <?= $w['players'] ? round(100 * $fh / $w['players']) : 0 ?>%</span></div>
            <h3 style="margin-top:8px">By race</h3>
<?php $maxRace = $w['races'] ? $w['races'][0][1] : 1; foreach (array_slice($w['races'], 0, 6) as [$race, $n]): ?>
            <div class="it-bar"><span><?= e(str_replace(['High Order Skyborne', 'Windshaper Skyborne'], ['Skyborne (A)', 'Skyborne (H)'], $race)) ?></span><span class="tr"><i data-w="<?= round(100 * $n / $maxRace, 1) ?>%" style="background:linear-gradient(#ffd75a,#b07a00)"></i></span><span class="v"><?= round(100 * $n / $w['players']) ?>%</span></div>
<?php endforeach ?>
          </div>
        </div>
        <p class="tfoot">Counts each inspected character once, from the same inspections as the <a href="<?= e(url('gear')) ?>">Gear</a> page. Characters aren't named here.</p>
    <?php component_end() ?>
<?php endif ?>

<?php if ($tip): $qc = $nameColor; ?>
    <?php component_start('section', ['title' => 'The Item', 'icon' => '', 'sub' => 'from a Battle.net Armory equipment snapshot']) ?>
        <div class="it-top<?= $aw ? '' : ' solo' ?>">
          <div>
            <div class="it-tt">
              <div class="n" style="color:<?= e($qc) ?>"><?= e($item['name']) ?></div>
              <?php if ($tip['ilvl']): ?><div class="y">Item Level <?= (int)$tip['ilvl'] ?></div><?php endif ?>
              <?php if ($tip['binding']): ?><div><?= e($tip['binding']) ?></div><?php endif ?>
              <?php if ($tip['unique']): ?><div><?= e($tip['unique']) ?></div><?php endif ?>
              <?php if ($tip['slot']): ?><div class="r"><span><?= e($tip['slot']) ?></span><span><?= e((string)$tip['type']) ?></span></div><?php endif ?>
              <?php if ($tip['weapon']): ?><div class="r"><span><?= e($tip['weapon'][0]) ?></span><span><?= e($tip['weapon'][1]) ?></span></div><div><?= e($tip['weapon'][2]) ?></div><?php endif ?>
              <?php if ($tip['armor']): ?><div><?= e($tip['armor']) ?></div><?php endif ?>
              <?php foreach ($tip['stats'] as [$line, $bonus]): ?><div<?= $bonus ? ' class="g"' : '' ?>><?= e($line) ?></div><?php endforeach ?>
              <?php if ($tip['classes']): ?><div><?= e($tip['classes']) ?></div><?php endif ?>
              <?php if ($tip['requires']): ?><div><?= e($tip['requires']) ?></div><?php endif ?>
              <?php foreach ($tip['spells'] as $line): ?><div class="g"><?= e($line) ?></div><?php endforeach ?>
              <?php if ($tip['sell']): ?><div style="margin-top:4px">Sell Price: <?= $money($tip['sell']) ?></div><?php endif ?>
            </div>
<?php if ($bopNote): ?>
            <div class="it-why"><b>Not on the Auction House.</b> It binds when picked up, so it can't be traded or listed.<?php if ($tip['sell']): ?> A vendor buys it for <?= $money($tip['sell']) ?>.<?php endif ?></div>
<?php endif ?>
          </div>
<?php if ($aw):
  $top3 = array_slice($aw['classes'], 0, 3);
  $top3n = array_sum(array_column($top3, 'n'));
  $names = array_map(fn($c) => '<b>' . e($c['name']) . 's</b>', $top3);
  $list = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names) : ($names[0] ?? '');
?>
          <div>
            <div class="tiles">
              <div class="stat"><span>Worn by</span><b><?= fmt($aw['players']) ?></b><em>Armory character<?= $aw['players'] === 1 ? '' : 's' ?></em></div>
              <div class="stat"><span><?= $onlyFaction ? e($onlyFaction) . ' adoption' : 'Adoption' ?></span><b><?= $aw['pool'] ? e((string)round(100 * $aw['players'] / $aw['pool'], $aw['players'] * 100 < $aw['pool'] ? 1 : 0)) : 0 ?>%</b><em>of <?= fmt($aw['pool']) ?> <?= $onlyFaction ? e($onlyFaction) . ' ' : '' ?>characters on file</em></div>
<?php if ($aw['top']): ?>
              <div class="stat"><span>Top class</span><b style="color:<?= e($aw['top']['color']) ?>"><?= e($aw['top']['name']) ?></b><em><?= (int)$aw['top']['of'] ?>% of <?= $onlyFaction ? e($onlyFaction) . ' ' : '' ?><?= e($aw['top']['name']) ?>s wear one</em></div>
<?php endif ?>
<?php if ($aw['hist']): ?>
              <div class="stat"><span>Wearer gear</span><b><?= (int)$aw['hist']['avg'] ?></b><em>avg equipped item level · <?= (int)$aw['hist']['min'] ?>–<?= (int)$aw['hist']['max'] ?></em></div>
<?php endif ?>
            </div>
            <div class="it-why sum">
<?php if (count($aw['classes']) > 1): ?>
              Most worn by <?= $list ?>, together <?= $pct($top3n, $aw['players']) ?>% of wearers.
<?php else: ?>
              Only <?= $list ?> are seen wearing it.
<?php endif ?>
<?php if ($onlyFaction): ?> Every wearer seen is <?= e($onlyFaction) ?>.<?php endif ?>
<?php if ($lvTop !== null): ?> <?= $pct($aw['levels'][$lvTop], $aw['players']) ?>% are level <?= (int)$lvTop ?>.<?php endif ?>
            </div>
          </div>
<?php endif ?>
        </div>
    <?php component_end() ?>
<?php endif ?>

<?php if ($aw): $maxClass = max(array_column($aw['classes'], 'n')); ?>
    <?php component_start('section', ['title' => 'Who Wears It', 'icon' => 'p', 'sub' => fmt($aw['players']) . ' of ' . fmt($aw['chars']) . ' Armory characters with gear on file']) ?>
        <div class="it-wear">
          <div class="it-bars"><h3>By class</h3>
            <div class="hd"><span></span><span>Class</span><span></span><span>Wearers</span><span>Of class</span></div>
<?php foreach ($aw['classes'] as $c): ?>
            <div class="it-bar cls" data-tip="<?= e($c['name']) ?>|<?= fmt($c['n']) ?> wearing it|<?= $pct($c['n'], $aw['players']) ?>% of wearers<?= $c['of'] !== null ? '|' . $c['of'] . '% of ' . fmt($c['pool']) . ' ' . e($c['name']) . 's on file' : '' ?>"><?php if ($c['file']): ?><img src="/wow2008/class-icons/<?= e(str_replace('deathknight', 'death-knight', $c['file'])) ?>.png" alt=""><?php else: ?><span></span><?php endif ?><span style="color:<?= e($c['color']) ?>"><?= e($c['name']) ?></span><span class="tr"><i data-w="<?= round(100 * $c['n'] / $maxClass, 1) ?>%" style="background:<?= e($c['color']) ?>"></i></span><span class="v"><?= $pct($c['n'], $aw['players']) ?>%</span><span class="ad"<?= $aw['top'] && $aw['top']['name'] === $c['name'] ? ' style="color:#ffd75a"' : '' ?>><?= $c['of'] !== null ? $c['of'] . '%' : '—' ?></span></div>
<?php endforeach ?>
            <p class="note"><b>Of class</b> = share of that class's <?= $onlyFaction ? e($onlyFaction) . ' ' : '' ?>Armory characters wearing it, so big classes don't win by size alone.</p>
          </div>
<?php $fa = $aw['factions']['Alliance'] ?? 0; $fh = $aw['factions']['Horde'] ?? 0; ?>
          <div class="it-bars"><h3>By faction</h3>
            <div class="it-fac" data-tip="Faction|Alliance <?= fmt($fa) ?>|Horde <?= fmt($fh) ?>"><i style="flex:<?= $fa ?>;background:linear-gradient(#5aa0ff,#2f6ad0)"></i><i style="flex:<?= $fh ?>;background:linear-gradient(#ff5a4a,#a01a10)"></i></div>
            <div class="it-bar" style="grid-template-columns:1fr auto"><span style="color:#9cc8ff">Alliance <?= $pct($fa, $aw['players']) ?>%</span><span style="color:#ffa090">Horde <?= $pct($fh, $aw['players']) ?>%</span></div>
            <h3 style="margin-top:8px">By race</h3>
<?php $maxRace = $aw['races'][0][1] ?? 1; foreach (array_slice($aw['races'], 0, 6) as [$race, $n]): ?>
            <div class="it-bar"><span><?= e(str_replace(['High Order Skyborne', 'Windshaper Skyborne'], ['Skyborne (A)', 'Skyborne (H)'], (string)$race)) ?></span><span class="tr"><i data-w="<?= round(100 * $n / $maxRace, 1) ?>%" style="background:linear-gradient(#ffd75a,#b07a00)"></i></span><span class="v"><?= $pct($n, $aw['players']) ?>%</span></div>
<?php endforeach ?>
            <h3 style="margin-top:8px">By region</h3>
<?php $maxReg = $aw['regions'][0][1] ?? 1; foreach ($aw['regions'] as [$reg, $n]): ?>
            <div class="it-bar"><span><?= e($reg) ?></span><span class="tr"><i data-w="<?= round(100 * $n / $maxReg, 1) ?>%" style="background:linear-gradient(#9cc8ff,#2f6ad0)"></i></span><span class="v"><?= $pct($n, $aw['players']) ?>%</span></div>
<?php endforeach ?>
          </div>
        </div>

        <div class="it-three">
<?php if ($h = $aw['hist']): $hmax = max($h['buckets']) ?: 1; ?>
          <div class="it-bars"><h3>Wearers' equipped item level</h3>
            <div class="it-hist">
<?php foreach ($h['buckets'] as $b => $n): $me = $h['own'] !== null && $h['own'] >= $b && $h['own'] < $b + $h['w']; ?>
              <div<?= $me ? ' class="me"' : '' ?> data-tip="Item level <?= $h['w'] > 1 ? $b . '–' . ($b + $h['w'] - 1) : $b ?>|<?= fmt($n) ?> wearer<?= $n === 1 ? '' : 's' ?><?= $me ? '|This item is ' . (int)$h['own'] : '' ?>"><i style="height:<?= round(100 * $n / $hmax, 1) ?>%"></i></div>
<?php endforeach ?>
            </div>
            <div class="it-hx"><?php foreach (array_keys($h['buckets']) as $b): $me = $h['own'] !== null && $h['own'] >= $b && $h['own'] < $b + $h['w']; ?><span<?= $me ? ' class="me"' : '' ?>><?= $b ?></span><?php endforeach ?></div>
<?php if ($h['own'] !== null && $h['above'] !== null): ?>
            <p class="note"><b><?= $pct($h['above'], $aw['players']) ?>%</b> of wearers have an equipped item level above this item's own <?= (int)$h['own'] ?>.</p>
<?php endif ?>
          </div>
<?php endif ?>
<?php if ($aw['levels']): ?>
          <div class="it-bars"><h3>Character level</h3>
            <div class="it-big"><?= $pct($aw['levels'][$lvTop], $aw['players']) ?>%<small>at level <?= (int)$lvTop ?></small></div>
<?php foreach ($aw['levels'] as $lv => $n): ?>
            <div class="it-bar" style="grid-template-columns:1fr auto"><span>Level <?= (int)$lv ?></span><span class="v"><?= fmt($n) ?></span></div>
<?php endforeach ?>
          </div>
<?php endif ?>
<?php if (count($aw['slots']) === 2): $st = array_sum($aw['slots']); [$s1, $s2] = array_keys($aw['slots']); ?>
          <div class="it-bars"><h3>Slot</h3>
            <div class="it-split"><span style="flex:<?= (int)$aw['slots'][$s1] ?>;background:linear-gradient(#ffd75a,#b07a00)"><?= e($s1) ?> <?= $pct($aw['slots'][$s1], $st) ?>%</span><span style="flex:<?= (int)$aw['slots'][$s2] ?>;background:linear-gradient(#c8a050,#7a5a20)"><?= e($s2) ?> <?= $pct($aw['slots'][$s2], $st) ?>%</span></div>
            <p class="note">Worn in the <?= e(strtolower($s1)) ?> slot <?= fmt($aw['slots'][$s1]) ?> times and the <?= e(strtolower($s2)) ?> slot <?= fmt($aw['slots'][$s2]) ?> times.</p>
          </div>
<?php endif ?>
        </div>
    <?php component_end() ?>

<?php if ($aw['pairs']): $maxPair = $aw['pairs'][0]['n']; ?>
    <?php component_start('section', ['title' => 'Often Worn With', 'icon' => '', 'sub' => 'the item in the matching ' . (str_contains(strtolower((string)($tip['slot'] ?? '')), 'finger') ? 'ring' : 'other') . ' slot on the same characters']) ?>
        <div class="it-pairs">
<?php foreach ($aw['pairs'] as $p): ?>
          <div class="it-pair" data-tip="<?= e($p['name']) ?>|<?= fmt($p['n']) ?> of <?= fmt($aw['players']) ?> wearers"><a class="q<?= (int)$p['quality'] ?>" href="<?= e(\armory_item_path($item['source'] ?? \PROFESSION_SOURCE, $p['id'])) ?>"><?= e($p['name']) ?></a><span class="tr"><i data-w="<?= round(100 * $p['n'] / $maxPair, 1) ?>%"></i></span><span class="v"><?= $pct($p['n'], $aw['players']) ?>%</span></div>
<?php endforeach ?>
        </div>
        <p class="tfoot">From each character's latest Armory snapshot. Characters aren't named here.</p>
    <?php component_end() ?>
<?php endif ?>
<?php endif ?>

<?php if (!$listed && !$bopNote): ?>
    <?php component_start('section', ['title' => 'Auction House', 'icon' => 'g']) ?>
        <div class="it-nomkt"><?php if (!empty($item['armoryOnly'])): ?>Seen equipped on characters from the Battle.net Armory. No auction scan has seen <b><?= e($item['name']) ?></b> listed yet.<?php elseif (!empty($item['localOnly'])): ?>This item page was created from equipment observed on inspected characters. No auction scan has seen <b><?= e($item['name']) ?></b> listed yet.<?php else: ?>No auction scan has seen <b><?= e($item['name']) ?></b> listed yet. It may bind when picked up, or nobody has put one up for sale. Price history appears here once it's listed.<?php endif ?></div>
    <?php component_end() ?>
<?php elseif ($listed): ?>
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
    </div>
<?php endif ?>

<?php if ($sales): ?>
    <?php component_start('section', ['title' => 'Recent Sales', 'icon' => 'g', 'sub' => 'completed auctions from players\' Journalator and TSM logs', 'style' => 'margin-top:16px']) ?>
        <div class="tiles">
          <div class="stat"><span>Sales recorded</span><b><?= fmt($sales['count']) ?></b><em>completed auctions</em></div>
          <div class="stat"><span>Typical sale</span><b><?= $money($sales['median']) ?></b><em>median price per unit</em></div>
          <div class="stat"><span>Time to sell</span><b><?= $sales['medianSecs'] !== null ? e($dur($sales['medianSecs'])) : '—' ?></b><em><?= $sales['timed'] ? 'median, from ' . fmt($sales['timed']) . ' sale' . ($sales['timed'] === 1 ? '' : 's') . ' with a logged listing' : 'listing time not logged' ?></em></div>
          <div class="stat"><span>Last sale</span><b><?= e($ago($sales['last'])) ?></b><em><?= e(gmdate('M j, Y', $sales['last'])) ?></em></div>
        </div>
        <table class="ahtable it-sales">
          <thead><tr><th>Sold</th><th>Market</th><th class="num">Qty</th><th class="num">Price each</th><th class="num">Sold after</th></tr></thead>
          <tbody>
<?php foreach ($sales['rows'] as [$t, $label, $fac, $qty, $unit, $secs]): ?>
            <tr><td title="<?= e(gmdate('Y-m-d H:i', $t)) ?> UTC"><?= e($ago($t)) ?></td><td class="fac-<?= e(strtolower($fac)) ?>"><?= e($label) ?></td><td class="num"><?= fmt($qty) ?></td><td class="num"><?= $money($unit) ?></td><td class="num"><?= $secs !== null ? e($dur($secs)) : '<span class="dim" title="This listing wasn\'t in the seller\'s log">—</span>' ?></td></tr>
<?php endforeach ?>
          </tbody>
        </table>
        <p class="tfoot">Real sales, not asking prices: each row is an auction a player's Journalator or TradeSkillMaster recorded as sold. "Sold after" is the time from listing to sale, shown when the listing is in the same log. Sellers and buyers aren't named.<?= $sales['count'] > count($sales['rows']) ? ' Showing the latest ' . count($sales['rows']) . '.' : '' ?></p>
    <?php component_end() ?>
<?php endif ?>

<?php if ($vendor): $vv = $vendor['verdict']; $ahp = $vendor['ah']; ?>
    <?php component_start('section', ['title' => 'Vendor or Auction?', 'icon' => 'g', 'sub' => $vv ? 'what each would pay you for one' : 'vendor prices players were paid and charged', 'style' => 'margin-top:16px']) ?>
<?php if ($vv): ?>
        <div class="it-verdict <?= $vv['pick'] ?>"><b><?= $vv['pick'] === 'auction' ? 'Auction it' : 'Vendor it' ?></b>
          <span><?= $vv['pick'] === 'auction'
            ? 'The auction house pays about ' . $money($vv['gain']) . ' more per item after its 5% cut.'
            : ($vv['gain'] === 0 ? 'Both pay about the same; the vendor is instant and costs no deposit.' : 'The vendor pays ' . $money(-$vv['gain']) . ' more than the auction house would after its 5% cut, and costs no deposit.') ?></span></div>
<?php endif ?>
        <div class="tiles">
<?php if ($vendor['sell']): ?>
          <div class="stat"><span>Vendor pays</span><b><?= $money($vendor['sell']) ?></b><em><?= $vendor['sellSource'] === 'armory' ? 'sell price, Battle.net item data' : 'from ' . fmt($vendor['sells']) . ' logged vendor sale' . ($vendor['sells'] === 1 ? '' : 's') ?></em></div>
<?php endif ?>
<?php if ($ahp): ?>
          <div class="stat"><span>Auction house</span><b><?= $money($ahp['price']) ?></b><em><?= $ahp['basis'] === 'sales' ? 'typical completed sale, ' . fmt($ahp['n']) . ' sales' : 'median asking price, ' . fmt($ahp['n']) . ' market' . ($ahp['n'] === 1 ? '' : 's') . ' · ' . e($ago($ahp['when'])) ?></em></div>
<?php endif ?>
<?php if ($vv): ?>
          <div class="stat"><span>You'd get</span><b><?= $money($vv['net']) ?></b><em>after the 5% auction cut</em></div>
<?php endif ?>
<?php if ($vendor['buy']): ?>
          <div class="stat"><span>Vendors sell it for</span><b><?= $money($vendor['buy']) ?></b><em>from <?= fmt($vendor['buys']) ?> logged purchase<?= $vendor['buys'] === 1 ? '' : 's' ?></em></div>
<?php endif ?>
        </div>
        <p class="tfoot"><?= $vv ? 'Per item, before the auction deposit. ' : '' ?><?= $ahp === null && $vendor['sell'] ? 'No auction price yet to compare against; this is the floor any listing should beat. ' : '' ?>Vendor prices come from players' Journalator logs<?= $vendor['sellSource'] === 'armory' ? ' and Battle.net item data' : '' ?>.</p>
    <?php component_end() ?>
<?php endif ?>

<?php if (!empty($item['dungeon'])): ?>
    <?php component_start('section', ['title' => 'Dungeon Drop', 'icon' => 'p', 'sub' => 'from the Dungeon Journal add-on\'s boss loot lists', 'style' => 'margin-top:16px']) ?>
        <table class="ahtable it-loot">
          <thead><tr><th>Dropped by</th><th>Dungeon</th></tr></thead>
          <tbody>
<?php foreach ($item['dungeon'] as $src): ?>
            <tr><td><?= $src['boss'] !== null ? '<a href="' . e(\forever_npc_path(\forever_npc_slug($src['boss']))) . '">' . e($src['boss']) . '</a>' : 'Trash' . (!empty($src['from']) ? ' <span class="kind">(' . e($src['from']) . ')</span>' : '') ?></td>
              <td><a href="<?= e(\forever_dungeon_path($src['slug'])) ?>"><?= e($src['dungeon']) ?></a></td></tr>
<?php endforeach ?>
          </tbody>
        </table>
    <?php component_end() ?>
<?php endif ?>

<?php if ($loot):
  $kinds = array_count_values(array_map(fn($r) => $r['kind'] === 'quest' ? 'quest' : 'loot', $loot['rows']));
  $lootWhat = isset($kinds['loot'], $kinds['quest']) ? 'loots and quest rewards' : (isset($kinds['quest']) ? 'quest rewards' : 'loots'); ?>
    <?php component_start('section', ['title' => 'Where It Drops', 'icon' => 'p', 'sub' => 'from ' . fmt($loot['drops']) . ' ' . $lootWhat . ' in players\' Journalator logs', 'style' => 'margin-top:16px']) ?>
        <table class="ahtable it-loot">
          <thead><tr><th>Source</th><th>Type</th><th class="num">Drop rate</th><th class="num">Dropped</th><th class="num">Looted</th><th class="num">Avg qty</th></tr></thead>
          <tbody>
<?php foreach ($loot['rows'] as $r): $small = $r['windows'] < LOOT_SMALL_SAMPLE; $kindLabel = ['npc' => 'Mob', 'world' => 'Object', 'quest' => 'Quest reward'][$r['kind']] ?? $r['kind']; ?>
            <?php $npcSlug = $r['kind'] === 'npc' && ($item['source'] ?? \PROFESSION_SOURCE) === \DUNGEON_SOURCE ? \forever_npc_slug_by_id((int)$r['id']) : null; ?>
            <tr><td><?php if ($npcSlug !== null): ?><a href="<?= e(\forever_npc_path($npcSlug)) ?>"><?= e($r['name']) ?></a><?php else: ?><a href="<?= e($whBase . ['npc' => 'npc', 'world' => 'object', 'quest' => 'quest'][$r['kind']] . '=' . $r['id']) ?>" rel="nofollow"><?= e($r['name']) ?></a><?php endif ?></td>
              <td class="kind"><?= e($kindLabel) ?></td>
              <td class="num"><span class="it-rate<?= $small ? ' early' : '' ?>" title="<?= $small ? 'Early estimate: fewer than ' . LOOT_SMALL_SAMPLE . ' ' . ($r['kind'] === 'quest' ? 'turn-ins' : 'loots') . ' logged' : '' ?>"><i style="width:<?= max(2, (int)round(100 * $r['rate'])) ?>%"></i><b><?= $r['rate'] < 0.1 ? preg_replace('/\.0$/', '', number_format(100 * $r['rate'], 1)) : (int)round(100 * $r['rate']) ?>%</b></span></td>
              <td class="num"><?= fmt($r['drops']) ?></td>
              <td class="num"><?= fmt($r['windows']) ?></td>
              <td class="num"><?= $r['drops'] ? preg_replace('/\.0$/', '', number_format($r['qty'] / $r['drops'], 1)) : '—' ?></td></tr>
<?php endforeach ?>
          </tbody>
        </table>
        <p class="tfoot">Drop rate is how often looting that mob or object gave this item, out of every time a player's Journalator logged looting it. For a quest, it's how often the turn-in rewarded it; with a choice of rewards, that's how often players picked it. Faded rates come from fewer than <?= LOOT_SMALL_SAMPLE ?> loots and will settle as more players upload. Mobs that left nothing to loot aren't logged, so rates run slightly high.</p>
    <?php component_end() ?>
<?php endif ?>

<?php if ($trades): ?>
    <?php component_start('section', ['title' => 'Used in Professions', 'icon' => 'p', 'sub' => 'open a trade to see its whole market', 'style' => 'margin-top:16px']) ?>
        <div class="it-trades">
<?php foreach ($trades as $t): ?>
          <a class="it-trade" href="<?= e($t['url']) ?>"><img src="/assets/images/wow/icons/large/<?= e($t['icon']) ?>.jpg" alt="" width="28" height="28"><span><b><?= e($t['name']) ?></b><small><?= e($t['role']) ?></small></span></a>
<?php endforeach ?>
        </div>
    <?php component_end() ?>
<?php endif ?>

    <div class="source"><?= !empty($item['localOnly']) || !empty($item['armoryOnly']) ? 'Item details from a Battle.net Armory equipment snapshot' : 'Auction data from in-game scans · asking prices on listed auctions, not completed sales · "lowest" is the cheapest listing, "median" the middle unit price' . ($sales ? ' · Recent Sales are completed auctions from players\' add-on logs' : '') ?></div>
  </main>

<?php start_section('scripts') ?>
<script>
const IT = <?= js($data) ?>;
const NAME = <?= js($item['name']) ?>;
// One point per market and scanned day; markets are region + ruleset + faction ("US PvE · Alliance").
const SC = IT.points.map(p => ({ k: p[0], fac: IT.markets[p[0]][1], t: p[1], qty: p[2], lo: p[3], med: p[4] }));
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
const lastScan = k => IT.lastScan[k];
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
  const meds = SC.map(s => s.med), lastT = Math.max(...Object.values(IT.lastScan));
  document.getElementById('itTiles').innerHTML = `
    <div class="stat"><span>Lowest price now</span><b style="font-size:17px;line-height:26px">${money(cheapest.lo)}</b><em>${esc(label(cheapest))}</em></div>
    <div class="stat"><span>Listed now</span><b>${fmt(listed)}</b><em>units across ${fmt(latest.length)} market${latest.length === 1 ? '' : 's'}</em></div>
    <div class="stat"><span>Median range</span><b style="font-size:15px;line-height:26px">${money(Math.min(...meds))} – ${money(Math.max(...meds))}</b><em>across every scanned day</em></div>
    <div class="stat"><span>Scans</span><b>${fmt(IT.scans)}</b><em>last ${day(lastT)}</em></div>`;

  // Report: compare each market's newest day with its previous one.
  const moved = latest.filter(s => s.prev).map(s => ({ s, dp: s.prev.med ? Math.round((s.med - s.prev.med) / s.prev.med * 100) : 0, dq: s.prev.qty ? Math.round((s.qty - s.prev.qty) / s.prev.qty * 100) : 0 }));
  const lines = [`<b>${esc(NAME)}</b> has been seen in <b>${fmt(IT.scans)}</b> auction scan${IT.scans === 1 ? '' : 's'} across ${fmt(latest.length)} market${latest.length === 1 ? '' : 's'} (${latest.map(s => esc(label(s))).join(', ')}), most recently on <b>${day(lastT)}</b>.`,
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
}
if (SC.length) render();
// The worn-by bars grow in like the other charts.
grow(document.querySelector('.main'));
</script>
<?php end_section() ?>
