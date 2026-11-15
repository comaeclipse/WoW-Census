<?php
namespace Wow2008;
/**
 * Page: one WoW: Forever character's Armory snapshot.
 * Vars: $c (forever_character_model()), $classColor (string), $wowhead (item URL prefix), $subtitle, $generated
 */
$QCOL = [0 => '#9d9d9d', 1 => '#ffffff', 2 => '#1eff00', 3 => '#0070dd', 4 => '#a335ee', 5 => '#ff8000', 6 => '#e6cc80', 7 => '#00ccff'];
$day = fn(?int $t) => $t ? gmdate('M j, Y', $t) : '—';
$left = array_slice($c['gear'], 0, (int)ceil(count($c['gear']) / 2));
$right = array_slice($c['gear'], count($left));
$maxTree = max(1, ...array_map(fn($t) => $t['points'], $c['trees'] ?: [['points' => 1]]));
$gearRow = function (array $g) use ($QCOL): string {
  $col = $QCOL[$g['quality']] ?? '#fff';
  $tip = implode("\n", array_merge([$g['name'], $g['type'], $g['ilvl'] ? 'Item level ' . $g['ilvl'] : ''], $g['lines'], $g['enchants']));
  $icon = $g['icon'] ? '<img src="/assets/images/wow/icons/large/' . e($g['icon']) . '.jpg" alt="" loading="lazy">' : '';
  return '<div class="ch-item" title="' . e(trim($tip)) . '"><span class="ch-ic" style="border-color:' . $col . '">' . $icon . '</span><span class="ch-it">' .
    '<small>' . e($g['slot']) . ($g['ilvl'] ? ' · ilvl ' . e($g['ilvl']) : '') . '</small>' .
    '<a href="' . e(\forever_item_path($g['id'])) . '" style="color:' . $col . '">' . e($g['name']) . '</a>' .
    ($g['enchants'] ? '<em>' . e(implode(' · ', $g['enchants'])) . '</em>' : '') . '</span></div>';
};
?>
  <style>
    .ch-hero{display:grid;grid-template-columns:auto 1fr;gap:16px;align-items:center;margin-bottom:14px;padding:14px 16px;
      background:radial-gradient(ellipse at 20% 0%,rgba(255,190,80,.22),transparent 60%),linear-gradient(#2c1c0c,#0e0904);
      border:2px solid #3a2608;outline:1px solid #b08a40}
    .ch-hero .emb{width:84px;height:84px;border:3px ridge #b08a40;box-shadow:0 3px 12px #000;background:#000}
    .ch-hero .emb img{display:block;width:100%;height:100%;object-fit:cover}
    .ch-hero h1{margin:0;font:900 28px/1.1 'Cinzel',serif;color:#fff;text-shadow:0 3px 3px #000}
    .ch-hero .kick{font:700 11px Tahoma,Arial,sans-serif;letter-spacing:2px;color:#e8c070;text-transform:uppercase;text-shadow:0 1px 2px #000}
    .ch-hero .meta{margin-top:6px;font-size:12px;color:#eee}
    .ch-hero .meta a{color:#ffd75a}
    .ch-doll{display:grid;grid-template-columns:1fr minmax(0,240px) 1fr;gap:10px;align-items:start}
    .ch-doll .ch-render{align-self:stretch;background:radial-gradient(ellipse at 50% 40%,#3a2a14,#0c0804);border:2px solid #000;outline:1px solid #8a7040;
      display:flex;align-items:flex-end;justify-content:center;overflow:hidden;min-height:320px}
    .ch-doll .ch-render img{display:block;width:180%;max-width:none;margin:0 -40%}
    .ch-item{display:grid;grid-template-columns:40px 1fr;gap:8px;align-items:center;padding:4px 6px;margin-bottom:4px;
      background:linear-gradient(#1c1810,#0e0c08);border:1px solid #000;outline:1px solid #5a4828;border-radius:3px}
    .ch-ic{width:36px;height:36px;border:2px solid #fff;border-radius:3px;background:#000;overflow:hidden}
    .ch-ic img{display:block;width:100%;height:100%}
    .ch-it{min-width:0;font:11px Tahoma,Arial,sans-serif;line-height:1.3}
    .ch-it small{display:block;color:#a89878;font-size:10px}
    .ch-it a{display:block;font-weight:700;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .ch-it a:hover{text-decoration:underline}
    .ch-it em{display:block;font-style:normal;color:#1eff00;font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .ch-trees{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
    .ch-tree{background:linear-gradient(#1c1810,#0e0c08);border:2px solid #000;outline:1px solid #8a7040;border-radius:4px;padding:8px 10px;color:#e8dcc0;font:11px Tahoma,Arial,sans-serif}
    .ch-tree h3{margin:0 0 4px;font:700 14px 'Cinzel',serif;color:#ffd75a;display:flex;justify-content:space-between}
    .ch-tree .tr{height:8px;background:#000;border:1px solid #3a3020;margin-bottom:6px}
    .ch-tree .tr i{display:block;height:100%}
    .ch-tree ul{margin:0;padding:0;list-style:none}
    .ch-tree li{display:flex;justify-content:space-between;gap:6px;padding:1px 0;border-bottom:1px dotted #3a3020}
    .ch-tree li b{color:#1eff00}
    .ch-kv{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
    .ch-kv .panel table{width:100%;font-size:11px}
    .ch-kv .panel td{padding:1px 0}
    .ch-kv .panel td+td{text-align:right;font-weight:700}
    .ch-tags{display:flex;flex-wrap:wrap;gap:6px}
    .ch-tags span{padding:3px 8px;border:1px solid #8a6a2a;border-radius:3px;background:linear-gradient(#fff6d8,#ecd59c);font:700 11px Tahoma,Arial,sans-serif;color:#5a2a00}
    .ch-rep{display:grid;grid-template-columns:1fr 90px 120px;gap:8px;align-items:center;font-size:11px;padding:2px 0}
    .ch-rep .tr{height:9px;background:#2a2010;border:1px solid #000}
    .ch-rep .tr i{display:block;height:100%;background:#2fa82f}
    @media (max-width:720px){.ch-doll,.ch-trees,.ch-kv{grid-template-columns:1fr}.ch-doll .ch-render{min-height:220px}.ch-hero{grid-template-columns:1fr}}
  </style>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Realm Census', 'url' => url('census')], ['label' => 'Guilds', 'url' => url('guilds')], ['label' => $c['name']]]]) ?>

    <div class="ch-hero">
      <?php if ($c['avatar']): ?><div class="emb"><img src="<?= e($c['avatar']) ?>" alt=""></div><?php endif; ?>
      <div>
        <div class="kick">WoW: Forever · <?= e(strtoupper($c['region'])) ?> <?= e($c['ruleset']) ?> · <?= e($c['faction']) ?></div>
        <h1><?= e($c['name']) ?></h1>
        <div class="meta">
          <?php if ($c['title']): ?><i><?= e(str_replace('{name}', $c['first'], $c['title'])) ?></i> · <?php endif; ?>
          Level <?= e($c['level']) ?> <?= e($c['race']) ?> <span style="color:<?= e($classColor) ?>;font-weight:700"><?= e($c['class']) ?></span>
          <?php if ($c['guild']): ?> · <?php if ($c['guildUrl']): ?><a href="<?= e($c['guildUrl']) ?>">&lt;<?= e($c['guild']) ?>&gt;</a><?php else: ?>&lt;<?= e($c['guild']) ?>&gt;<?php endif; ?><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="tiles">
      <?= component('stat', ['label' => 'Item level', 'value' => (string)($c['ilvl'] ?? '—'), 'note' => 'equipped average']) ?>
      <?= component('stat', ['label' => 'Talents', 'value' => $c['build'] ?: '—', 'note' => $c['mainTree'] ? 'mostly ' . $c['mainTree'] : 'no points spent']) ?>
      <?= component('stat', ['label' => 'Honorable kills', 'value' => fmt($c['pvp']['hks']), 'note' => $c['pvp']['rank'] ? 'PvP rank ' . $c['pvp']['rank'] : 'lifetime']) ?>
      <?= component('stat', ['label' => 'Last login', 'value' => $day($c['lastLogin']), 'value_style' => 'font-size:15px;line-height:26px', 'note' => 'Armory read ' . $day($c['fetched'])]) ?>
    </div>

    <?php component_start('section', ['title' => 'Gear', 'icon' => 'g', 'sub' => count($c['gear']) . ' items equipped · hover an item for its stats']) ?>
      <div class="ch-doll">
        <div><?php foreach ($left as $g) echo $gearRow($g); ?></div>
        <div class="ch-render"><?php if ($c['render']): ?><img src="<?= e($c['render']) ?>" alt="<?= e($c['name']) ?>" loading="lazy"><?php endif; ?></div>
        <div><?php foreach ($right as $g) echo $gearRow($g); ?></div>
      </div>
    <?php component_end() ?>

    <?php if ($c['trees']): ?>
    <?php component_start('section', ['title' => 'Talents', 'icon' => 'p', 'sub' => $c['build'] . ' · active talent set']) ?>
      <div class="ch-trees">
        <?php foreach ($c['trees'] as $t): ?>
        <div class="ch-tree">
          <h3><span><?= e($t['name']) ?></span><span><?= e($t['points']) ?></span></h3>
          <div class="tr"><i style="width:<?= round($t['points'] / $maxTree * 100) ?>%;background:<?= e($classColor) ?>"></i></div>
          <ul>
            <?php foreach ($t['talents'] as $x): ?><li title="<?= e($x['desc']) ?>"><span><?= e($x['name']) ?></span><b><?= e($x['rank']) ?></b></li><?php endforeach; ?>
            <?php if (!$t['talents']): ?><li><span style="color:#888">No points</span></li><?php endif; ?>
          </ul>
        </div>
        <?php endforeach; ?>
      </div>
    <?php component_end() ?>
    <?php endif; ?>

    <?php component_start('section', ['title' => 'Professions & PvP', 'icon' => '', 'body_class' => 'cols2']) ?>
      <div class="panel">
        <h5 style="color:#ffd75a">Professions</h5>
        <table class="rtable"><thead><tr><th style="cursor:default">Profession</th><th style="cursor:default" class="num">Skill</th><th style="cursor:default" class="num">Recipes</th></tr></thead><tbody>
          <?php foreach ($c['professions'] as $p): $slug = strtolower(str_replace(' ', '-', $p['name'])); ?>
          <tr><td><?= isset(\PROFESSIONS[$slug]) ? '<a href="' . e(\profession_path($slug)) . '">' . e($p['name']) . '</a>' : e($p['name']) ?> <small><?= e($p['kind']) ?></small></td>
            <td class="num"><?= e($p['skill']) ?> / <?= e($p['max']) ?></td><td class="num"><?= $p['recipes'] ? fmt($p['recipes']) : '' ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$c['professions']): ?><tr><td colspan="3">No professions learned.</td></tr><?php endif; ?>
        </tbody></table>
      </div>
      <div class="panel">
        <h5 style="color:#ffd75a">Battlegrounds <small><?= fmt($c['pvp']['hks']) ?> honorable kills</small></h5>
        <table class="rtable"><thead><tr><th style="cursor:default">Battleground</th><th style="cursor:default" class="num">Played</th><th style="cursor:default" class="num">Won</th><th style="cursor:default" class="num">Lost</th></tr></thead><tbody>
          <?php foreach ($c['pvp']['maps'] as $m): ?>
          <tr><td><?= e($m['name']) ?></td><td class="num"><?= fmt($m['played']) ?></td><td class="num"><?= fmt($m['won']) ?></td><td class="num"><?= fmt($m['lost']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$c['pvp']['maps']): ?><tr><td colspan="4">No battlegrounds played.</td></tr><?php endif; ?>
        </tbody></table>
      </div>
    <?php component_end() ?>

    <?php component_start('section', ['title' => 'Character Stats', 'icon' => 'r', 'sub' => 'with gear and buffs at logout']) ?>
      <div class="ch-kv">
        <?php foreach ($c['stats'] as $group => $rows): ?>
        <div class="panel"><h5 style="color:#ffd75a"><?= e($group) ?></h5><table>
          <?php foreach ($rows as $k => $v): ?><tr><td><?= e($k) ?></td><td><?= e(is_int($v) ? fmt($v) : $v) ?></td></tr><?php endforeach; ?>
        </table></div>
        <?php endforeach; ?>
      </div>
    <?php component_end() ?>

    <?php if ($c['reputations']): ?>
    <?php component_start('section', ['title' => 'Reputation', 'icon' => 'g', 'sub' => count($c['reputations']) . ' factions']) ?>
      <?php foreach ($c['reputations'] as $r): ?>
      <div class="ch-rep"><span><?= e($r['name']) ?></span><span><?= e($r['standing']) ?></span>
        <span class="tr" title="<?= e(fmt($r['value']) . ' / ' . fmt($r['max'])) ?>"><i style="width:<?= $r['max'] ? min(100, round($r['value'] / $r['max'] * 100)) : 100 ?>%"></i></span></div>
      <?php endforeach; ?>
    <?php component_end() ?>
    <?php endif; ?>

    <?php if ($c['mounts'] || $c['pets'] || $c['titles']): ?>
    <?php component_start('section', ['title' => 'Collections', 'icon' => 'p', 'body_class' => 'cols2']) ?>
      <div class="panel"><h5 style="color:#ffd75a">Mounts <small><?= count($c['mounts']) ?></small></h5>
        <div class="ch-tags"><?php foreach ($c['mounts'] as $m): ?><span><?= e($m) ?></span><?php endforeach; ?><?php if (!$c['mounts']): ?>None yet.<?php endif; ?></div>
        <?php if ($c['titles']): ?><h5 style="color:#ffd75a;margin-top:10px">Titles</h5><div class="ch-tags"><?php foreach ($c['titles'] as $t): ?><span><?= e($t) ?></span><?php endforeach; ?></div><?php endif; ?>
      </div>
      <div class="panel"><h5 style="color:#ffd75a"><?= $c['pets'] ? 'Hunter Pets' : 'Achievements' ?></h5>
        <?php if ($c['pets']): ?>
        <table class="rtable"><tbody><?php foreach ($c['pets'] as $p): ?><tr><td><?= e($p['name']) ?><?= $p['active'] ? ' <small>(active)</small>' : '' ?></td><td><?= e($p['creature']) ?></td><td class="num"><?= e($p['level']) ?></td></tr><?php endforeach; ?></tbody></table>
        <?php endif; ?>
        <p class="tfoot"><?= fmt($c['achievements']) ?> achievements earned</p>
      </div>
    <?php component_end() ?>
    <?php endif; ?>

    <div class="source">From the Battle.net Armory, which shows the character as of their last logout (<?= e($day($c['lastLogin'])) ?>). Read <?= e($day($c['fetched'])) ?>. Not counted in the census.</div>
  </main>
