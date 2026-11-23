<?php
namespace Wow2008;
/**
 * Page: one WoW: Forever character's Armory snapshot, drawn as the in-game character sheet
 * (gear around the model, weapons below) with tabs for talents, professions & PvP, stats and reputation.
 * Vars: $c (forever_character_model()), $classColor (string), $subtitle, $generated
 */
$QCLS = [0 => 'q0', 1 => 'q1', 2 => 'q2', 3 => 'q3', 4 => 'q4', 5 => 'q5', 6 => 'q5', 7 => 'q5'];
$bySlot = [];
foreach ($c['gear'] as $g) $bySlot[$g['slot']] = $g;
$ago = function (?int $t): string {
  if (!$t) return '—';
  $m = max(1, intdiv(time() - $t, 60));
  return $m < 120 ? "$m minutes ago" : ($m < 2880 ? intdiv($m, 60) . ' hours ago' : intdiv($m, 1440) . ' days ago');
};
$slot = function (string $name) use ($bySlot, $QCLS): string {
  $g = $bySlot[$name] ?? null;
  if (!$g) return '<div class="cp-slot cp-empty"><span class="cp-icon"></span><span class="cp-txt"><b>' . e($name) . '</b><small>Empty</small></span></div>';
  $tip = js(['n' => $g['name'], 'q' => $g['quality'], 't' => $g['type'], 'i' => $g['ilvl'], 'l' => array_map('wow_text', $g['lines']), 'x' => array_map('wow_text', $g['enchants'])]);
  $img = $g['iconUrl'] ? '<img src="' . e($g['iconUrl']) . '" alt="" loading="lazy">' : '';
  $ench = $g['enchants'] ? ' · <em>' . \wow_text_html(preg_replace('/^Enchanted: /', '', $g['enchants'][0])) . '</em>' : '';
  $sub = ($g['ilvl'] ? '<span class="cp-ilvl">iLvl ' . e($g['ilvl']) . '</span>' : e($name)) . $ench;
  return '<a class="cp-slot ' . ($QCLS[$g['quality']] ?? 'q1') . '" href="' . e($g['url'] ?? \forever_item_path($g['id'])) . '" data-item="' . e($tip) . '" aria-label="' . e($name . ': ' . $g['name']) . '">' .
    '<span class="cp-icon">' . $img . '</span><span class="cp-txt"><b>' . e($g['name']) . '</b><small>' . $sub . '</small></span></a>';
};
$st = $c['stats']; $base = $st['Base'] ?? [];
$strip = array_filter([
  'Health' => $base['Health'] ?? null, ($c['cf'] === 'WARRIOR' ? 'Rage' : ($c['cf'] === 'ROGUE' ? 'Energy' : 'Mana')) => $base['Mana'] ?? $base['Rage'] ?? $base['Energy'] ?? null,
  'Attack power' => $st['Melee & Ranged']['Attack power'] ?? null, 'Armor' => $base['Armor'] ?? null,
], fn($v) => $v !== null);
$TIER = [0 => '#cc2222', 1 => '#ff4400', 2 => '#ff8800', 3 => '#c8c800', 4 => '#00b800', 5 => '#00b880', 6 => '#00b8b8', 7 => '#00a0ff'];
?>
  <style>
    .cp-tabs{display:flex;gap:4px;margin:0}
    .cp-tabs button{padding:5px 14px;border:1px solid #6f511f;border-bottom:0;border-radius:4px 4px 0 0;background:linear-gradient(#e6ca87,#ac8848);color:#382208;font:bold 10px Tahoma,Arial,sans-serif;cursor:pointer}
    .cp-tabs button.on{background:linear-gradient(#67491f,#271a09);color:#ffda73}
    .cp-pane{display:none;padding:12px;border:3px ridge #81602e;background:linear-gradient(rgba(6,6,8,.12),rgba(6,6,8,.12)),url('/wow2008/stone-dark.webp') 50% 30%/cover no-repeat #0c0c0d;color:#e3d3b0}
    .cp-pane.on{display:block}
    .cp-doll.on{display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,300px) minmax(0,1fr);grid-template-rows:auto 1fr;gap:10px 18px;padding:18px 16px;box-shadow:inset 0 0 60px rgba(0,0,0,.85)}
    .cp-hero{display:grid;grid-template-columns:auto 1fr;gap:16px;align-items:center;margin-bottom:14px;padding:14px 16px;
      background:radial-gradient(ellipse at 20% 0%,rgba(255,190,80,.22),transparent 60%),linear-gradient(#2c1c0c,#0e0904);border:2px solid #3a2608;outline:1px solid #b08a40}
    .cp-emb{width:84px;height:84px;border:3px ridge #b08a40;box-shadow:0 3px 12px #000;background:#000}
    .cp-emb img{display:block;width:100%;height:100%;object-fit:cover}
    .cp-hero h1{margin:0;font:900 28px/1.1 'Cinzel',serif;color:#fff;text-shadow:0 3px 3px #000}
    .cp-kick{font:700 11px Tahoma,Arial,sans-serif;letter-spacing:2px;color:#e8c070;text-transform:uppercase;text-shadow:0 1px 2px #000}
    .cp-meta{margin-top:6px;font-size:12px;color:#eee}.cp-meta a{color:#ffd75a}
    .cp-frame{grid-column:2;grid-row:1;position:relative;overflow:hidden;aspect-ratio:3/4;border:1px solid #4d4a52;border-radius:3px;
      background:radial-gradient(ellipse at 50% 42%,rgba(150,170,175,.22),rgba(0,0,0,0) 62%),rgba(6,6,8,.55);box-shadow:inset 0 0 30px #000,0 2px 10px rgba(0,0,0,.7)}
    .cp-model{display:block;width:100%;height:100%;object-fit:cover;object-position:50% 50%;transform:scale(1.28);transform-origin:50% 53%;filter:drop-shadow(0 6px 6px #000)}
    .cp-gear{grid-row:1/span 2;display:flex;flex-direction:column;gap:4px;min-width:0}
    .cp-gear.l{grid-column:1}.cp-gear.r{grid-column:3}
    .cp-slot{display:flex;align-items:center;gap:9px;min-width:0;padding:3px 2px;text-decoration:none;border-radius:3px}
    .cp-gear.r .cp-slot{flex-direction:row-reverse;text-align:right}
    a.cp-slot:hover{background:rgba(255,215,120,.07)}
    .cp-txt{min-width:0}
    .cp-icon{position:relative;width:40px;height:40px;flex:none;border:2px solid #4a4a4a;border-radius:3px;background:#0b0b0d;box-shadow:0 0 0 1px #000,0 2px 4px rgba(0,0,0,.7)}
    .cp-icon img{display:block;width:100%;height:100%;object-fit:cover}
    .cp-slot b{display:block;overflow:hidden;font-size:12px;line-height:1.25;white-space:nowrap;text-overflow:ellipsis;text-shadow:0 1px 2px #000}
    .cp-slot small{display:block;margin-top:2px;color:#9c968c;font-size:10px;line-height:1.3}.cp-ilvl{color:#c9c2b4}
    .cp-slot em{font-style:normal;color:#1eff00}
    .cp-slot.q0 b{color:#9d9d9d}.cp-slot.q1 b{color:#fff}.cp-slot.q2 b{color:#1eff00}.cp-slot.q3 b{color:#3d8bff}.cp-slot.q4 b{color:#b579ff}.cp-slot.q5 b{color:#ff8c32}
    .cp-slot.q2 .cp-icon{border-color:#1e9a00}.cp-slot.q3 .cp-icon{border-color:#2a64c0}.cp-slot.q4 .cp-icon{border-color:#8a3fd0}.cp-slot.q5 .cp-icon{border-color:#d07020}
    .cp-empty .cp-icon{background:rgba(20,20,22,.7);border-color:#333}.cp-empty b{color:#7a7670}.cp-empty small{color:#5e5a55}
    .cp-weapons{grid-column:2;grid-row:2;display:flex;justify-content:center;gap:6px}
    .cp-weapons .cp-slot{flex:1 1 0;flex-direction:column;align-items:center;text-align:center;gap:4px;padding:2px}
    .cp-weapons .cp-txt{width:100%}.cp-weapons .cp-slot b{white-space:normal}
    .wow-tier{display:inline-block;min-width:11px;height:11px;margin-left:2px;padding:0 1px;border-radius:2px;font:bold 8px/11px Tahoma,Arial,sans-serif;color:#1a1206;text-align:center;vertical-align:1px;box-shadow:inset 0 0 0 1px rgba(0,0,0,.45)}
    .wow-tier.gold{background:linear-gradient(#ffe48a,#c99a2e)}.wow-tier.silver{background:linear-gradient(#f2f2f2,#9aa0a6)}.wow-tier.bronze{background:linear-gradient(#e7b07a,#9a5b2a)}
    .cp-strip{display:grid;grid-template-columns:repeat(<?= count($strip) ?>,1fr);gap:1px;margin-top:9px;border:2px ridge #805e2d;background:#80602f}
    .cp-strip div{padding:8px 5px;background:linear-gradient(#2b2518,#15130d);color:#d4c39c;text-align:center}
    .cp-strip strong{display:block;color:#ffd86b;font:700 17px Cinzel,serif}.cp-strip span{font-size:9px;text-transform:uppercase;letter-spacing:.08em}
    .cp-trees{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
    .cp-tree{padding:8px 10px;border:1px solid #5c4825;background:#0d0b07}
    .cp-tree h3{display:flex;justify-content:space-between;margin:0 0 5px;color:#ffd86b;font:700 15px Cinzel,serif}
    .cp-bar{height:7px;margin-bottom:7px;background:#000;border:1px solid #3e3220}.cp-bar i{display:block;height:100%;background:linear-gradient(#ff9d3a,#b35a08)}
    .cp-tree ul{list-style:none;margin:0;padding:0}.cp-tree li{display:flex;justify-content:space-between;padding:2px 0;border-bottom:1px dotted #3a2e1a;font-size:11px}
    .cp-tree li b{color:#1eff00}.cp-tree li.none{color:#777}
    .cp-two{display:grid;grid-template-columns:1.3fr 1fr;gap:14px}.cp-pane h4{margin:0 0 6px;color:#ffd86b;font:700 13px Cinzel,serif}
    .cp-list{width:100%;border-collapse:collapse;font-size:11px}
    .cp-list th{padding:3px 4px;text-align:left;color:#b59c6a;font-size:9px;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #5c4825}
    .cp-list td{padding:4px;border-bottom:1px dotted #3a2e1a}.cp-list small{color:#9a8a68}.cp-list a{color:#ffd86b}.cp-list .num{text-align:right}
    .cp-meter{position:relative;display:block;height:16px;background:#000;border:1px solid #4c3c20}.cp-meter i{display:block;height:100%;background:var(--c,linear-gradient(#3a7a2a,#1d4a12))}
    .cp-meter em{position:absolute;inset:0;text-align:center;font:normal 10px/15px Tahoma,Arial,sans-serif;color:#fff;text-shadow:0 1px 1px #000}
    .cp-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
    .cp-panel{padding:8px;border:1px solid #5c4825;background:#0d0b07}.cp-panel table{width:100%;font-size:11px}.cp-panel td+td{text-align:right;color:#fff;font-weight:bold}
    .cp-reps{columns:2;column-gap:16px}.cp-rep{display:grid;grid-template-columns:1fr 150px;gap:8px;align-items:center;padding:3px 0;break-inside:avoid;font-size:11px}
    .cp-tag{display:inline-block;margin:0 5px 5px 0;padding:3px 8px;border:1px solid #6f511f;background:#2a2114;color:#ffd86b;font-size:11px}
    #cp-tt{position:fixed;z-index:99;display:none;max-width:300px;padding:8px 10px;border:1px solid #9aa4b8;border-radius:4px;background:rgba(8,12,28,.95);box-shadow:0 0 0 1px #000,0 4px 14px #000;color:#fff;font:12px/1.45 Verdana,Arial,sans-serif;pointer-events:none}
    #cp-tt b{display:block;font-size:13px}#cp-tt .y{color:#ffd100}#cp-tt .g{color:#1eff00}
    @media (max-width:820px){
      .cp-doll.on{grid-template-columns:minmax(0,1fr) minmax(0,1fr);grid-template-rows:auto auto auto}
      .cp-frame{grid-column:1/-1;grid-row:1;width:min(100%,320px);justify-self:center}
      .cp-weapons{grid-column:1/-1;grid-row:2}
      .cp-gear{grid-row:3}.cp-gear.l{grid-column:1}.cp-gear.r{grid-column:2}
      .cp-hero{grid-template-columns:1fr}
      .cp-trees,.cp-two,.cp-stats{grid-template-columns:1fr}.cp-reps{columns:1}.cp-strip{grid-template-columns:repeat(3,1fr)}
    }
    @media (max-width:560px){
      .cp-doll.on{grid-template-columns:minmax(0,1fr);padding:14px 12px}
      .cp-gear.l,.cp-gear.r{grid-column:1}.cp-gear.r{grid-row:4}
      .cp-gear.r .cp-slot{flex-direction:row;text-align:left}
      .cp-strip{grid-template-columns:repeat(2,1fr)}
    }
  </style>
  <main class="main">
    <?= component('crumb', ['trail' => array_values(array_filter([['label' => 'Realm Census', 'url' => url('census')], ['label' => 'Guilds', 'url' => url('guilds')],
      $c['guildUrl'] ? ['label' => '<' . $c['guild'] . '>', 'url' => $c['guildUrl']] : null, ['label' => $c['name']]]))]) ?>

    <div class="cp-hero">
      <?php if ($c['avatar']): ?><div class="cp-emb"><img src="<?= e($c['avatar']) ?>" alt=""></div><?php endif; ?>
      <div>
        <div class="cp-kick"><?= e(implode(' · ', array_filter([$c['edition'] ?? 'WoW: Forever', trim(strtoupper((string)$c['region']) . ' ' . ($c['ruleset'] ?? '')), $c['faction']]))) ?></div>
        <h1><?= e($c['name']) ?></h1>
        <div class="cp-meta">
          <?php if ($c['title']): ?><i><?= e(str_replace('{name}', $c['first'], $c['title'])) ?></i> · <?php endif; ?>
          Level <?= e($c['level']) ?> <?= e($c['race']) ?> <span style="color:<?= e($classColor) ?>;font-weight:700"><?= e($c['class']) ?></span>
          <?php if ($c['guild']): ?> · <?= $c['guildUrl'] ? '<a href="' . e($c['guildUrl']) . '">&lt;' . e($c['guild']) . '&gt;</a>' : '&lt;' . e($c['guild']) . '&gt;' ?><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="tiles">
      <?= component('stat', ['label' => 'Item level', 'value' => (string)($c['ilvl'] ?? '—'), 'note' => 'equipped average']) ?>
      <?= component('stat', ['label' => 'Talents', 'value' => $c['build'] ?: '—', 'note' => !$c['trees'] ? 'talent data unavailable' : ($c['activeSpec'] ?? ($c['mainTree'] ? 'mostly ' . $c['mainTree'] : 'no points spent'))]) ?>
      <?= component('stat', ['label' => 'Honorable kills', 'value' => fmt($c['pvp']['hks']), 'note' => $c['pvp']['rank'] ? 'PvP rank ' . $c['pvp']['rank'] : 'lifetime']) ?>
      <?= component('stat', ['label' => 'Last login', 'value' => $c['lastLogin'] ? gmdate('M j, Y', $c['lastLogin']) : '—', 'value_style' => 'font-size:15px;line-height:26px', 'note' => 'Armory read ' . gmdate('M j, Y', $c['fetched'])]) ?>
    </div>

    <div class="cp-tabs" role="tablist">
      <button class="on" data-pane="cp-equip">Equipment</button>
      <?php if ($c['trees']): ?><button data-pane="cp-talents">Talents</button><?php endif; ?>
      <button data-pane="cp-prof">Professions &amp; PvP</button>
      <button data-pane="cp-stats">Stats</button>
      <?php if ($c['reputations']): ?><button data-pane="cp-rep">Reputation</button><?php endif; ?>
    </div>

    <section class="cp-pane cp-doll on" id="cp-equip" aria-label="<?= e($c['name']) ?>'s equipped gear">
      <div class="cp-gear l"><?php foreach (['Head', 'Neck', 'Shoulders', 'Back', 'Chest', 'Shirt', 'Tabard', 'Wrist'] as $s) echo $slot($s); ?></div>
      <div class="cp-gear r"><?php foreach (['Hands', 'Waist', 'Legs', 'Feet', 'Ring 1', 'Ring 2', 'Trinket 1', 'Trinket 2'] as $s) echo $slot($s); ?></div>
      <div class="cp-frame"><?php if ($c['render']): ?><img class="cp-model" src="<?= e($c['render']) ?>" alt="<?= e($c['name']) ?>"><?php endif; ?></div>
      <div class="cp-weapons"><?php foreach (['Main Hand', 'Off Hand', 'Ranged'] as $s) echo $slot($s); ?></div>
    </section>

    <?php if ($c['trees']): ?>
    <div class="cp-pane" id="cp-talents"><div class="cp-trees">
      <?php foreach ($c['trees'] as $t): ?>
      <div class="cp-tree"><h3><span><?= e($t['name']) ?></span><span><?= e($t['points']) ?></span></h3>
        <div class="cp-bar"><i style="width:<?= min(100, round($t['points'] / 51 * 100)) ?>%"></i></div>
        <ul><?php foreach ($t['talents'] as $x): ?><li title="<?= e($x['desc']) ?>"><span><?= e($x['name']) ?></span><b><?= e($x['rank']) ?></b></li><?php endforeach; ?>
          <?php if (!$t['talents']): ?><li class="none">No points spent</li><?php endif; ?></ul>
      </div>
      <?php endforeach; ?>
    </div></div>
    <?php endif; ?>

    <div class="cp-pane" id="cp-prof"><div class="cp-two">
      <div><h4>Professions</h4>
        <table class="cp-list"><tr><th>Profession</th><th>Skill</th><th class="num">Recipes</th></tr>
          <?php foreach ($c['professions'] as $p): $pslug = strtolower(str_replace(' ', '-', $p['name'])); ?>
          <tr><td><?= ($c['source'] ?? 'forever') === 'forever' && isset(\PROFESSIONS[$pslug]) ? '<a href="' . e(\profession_path($pslug)) . '">' . e($p['name']) . '</a>' : e($p['name']) ?> <small><?= e($p['kind']) ?></small></td>
            <td><span class="cp-meter"><i style="width:<?= $p['max'] ? round($p['skill'] / $p['max'] * 100) : 0 ?>%"></i><em><?= e($p['skill']) ?> / <?= e($p['max']) ?></em></span></td>
            <td class="num"><?= $p['recipes'] ? fmt($p['recipes']) : '' ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$c['professions']): ?><tr><td colspan="3"><?= ($c['professionsAvailable'] ?? true) ? 'No professions learned.' : 'Profession data unavailable.' ?></td></tr><?php endif; ?>
        </table>
      </div>
      <div><h4>Battlegrounds · <?= fmt($c['pvp']['hks']) ?> honorable kills<?= $c['pvp']['rank'] ? ' · rank ' . e($c['pvp']['rank']) : '' ?></h4>
        <table class="cp-list"><tr><th>Battleground</th><th class="num">Played</th><th class="num">Won</th><th class="num">Lost</th></tr>
          <?php foreach ($c['pvp']['maps'] as $m): ?><tr><td><?= e($m['name']) ?></td><td class="num"><?= fmt($m['played']) ?></td><td class="num"><?= fmt($m['won']) ?></td><td class="num"><?= fmt($m['lost']) ?></td></tr><?php endforeach; ?>
          <?php if (!$c['pvp']['maps']): ?><tr><td colspan="4">No battlegrounds played.</td></tr><?php endif; ?>
        </table>
        <h4 style="margin-top:14px">Mounts · <?= count($c['mounts']) ?></h4>
        <?php foreach ($c['mounts'] as $m): ?><span class="cp-tag"><?= e($m) ?></span><?php endforeach; ?>
        <?php if ($c['titles']): ?><h4 style="margin-top:10px">Titles</h4><?php foreach ($c['titles'] as $t): ?><span class="cp-tag"><?= e($t) ?></span><?php endforeach; ?><?php endif; ?>
        <?php if ($c['pets']): ?><h4 style="margin-top:10px">Hunter Pets</h4>
          <table class="cp-list"><?php foreach ($c['pets'] as $p): ?><tr><td><?= e($p['name']) ?><?= $p['active'] ? ' <small>active</small>' : '' ?></td><td><?= e($p['creature']) ?></td><td class="num"><?= e($p['level']) ?></td></tr><?php endforeach; ?></table>
        <?php endif; ?>
        <p style="color:#9a8a68;font-size:10px"><?= fmt($c['achievements']) ?> achievements earned</p>
      </div>
    </div></div>

    <div class="cp-pane" id="cp-stats"><div class="cp-stats">
      <?php foreach ($c['stats'] as $group => $rows): ?>
      <div class="cp-panel"><h4><?= e($group) ?></h4><table>
        <?php foreach ($rows as $k => $v): ?><tr><td><?= e($k) ?></td><td><?= e(is_int($v) ? fmt($v) : $v) ?></td></tr><?php endforeach; ?>
      </table></div>
      <?php endforeach; ?>
    </div></div>

    <?php if ($c['reputations']): ?>
    <div class="cp-pane" id="cp-rep"><div class="cp-reps">
      <?php foreach ($c['reputations'] as $r): ?>
      <div class="cp-rep"><span><?= e($r['name']) ?></span><span class="cp-meter" style="--c:<?= $TIER[$r['tier']] ?? '#00b800' ?>" title="<?= e(fmt($r['value']) . ' / ' . fmt($r['max'])) ?>"><i style="width:<?= $r['max'] ? min(100, round($r['value'] / $r['max'] * 100)) : 100 ?>%"></i><em><?= e($r['standing']) ?></em></span></div>
      <?php endforeach; ?>
    </div></div>
    <?php endif; ?>

    <div class="cp-strip"><?php foreach ($strip as $k => $v): ?><div><strong><?= e(is_int($v) ? fmt($v) : $v) ?></strong><span><?= e($k) ?></span></div><?php endforeach; ?></div>

    <div id="cp-tt"></div>
  </main>

<?php start_section('scripts') ?>
<script>
document.querySelectorAll('.cp-tabs button').forEach(b => b.addEventListener('click', () => {
  document.querySelectorAll('.cp-tabs button').forEach(x => x.classList.toggle('on', x === b));
  document.querySelectorAll('.cp-pane').forEach(p => p.classList.toggle('on', p.id === b.dataset.pane));
}));
(() => {
  const tt = document.getElementById('cp-tt'), QC = ['#9d9d9d', '#fff', '#1eff00', '#0070dd', '#a335ee', '#ff8000', '#e6cc80', '#00ccff'];
  const h = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
  document.querySelectorAll('.cp-slot[data-item]').forEach(el => {
    el.addEventListener('mouseenter', () => {
      const d = JSON.parse(el.dataset.item);
      tt.innerHTML = `<b style="color:${QC[d.q] || '#fff'}">${h(d.n)}</b>` + (d.i ? `<div class="y">Item Level ${d.i}</div>` : '') +
        (d.t ? `<div>${h(d.t)}</div>` : '') + d.l.map(l => `<div class="${/^(Use|Equip|Chance)/.test(l) ? 'g' : ''}">${h(l)}</div>`).join('') +
        d.x.map(x => `<div class="g">${h(x)}</div>`).join('');
      tt.style.display = 'block';
    });
    el.addEventListener('mousemove', ev => {
      tt.style.left = Math.min(ev.clientX + 16, innerWidth - 320) + 'px';
      tt.style.top = Math.max(8, Math.min(ev.clientY + 16, innerHeight - tt.offsetHeight - 8)) + 'px';
    });
    el.addEventListener('mouseleave', () => { tt.style.display = 'none'; });
  });
})();
</script>
<?php end_section() ?>
