<?php
namespace Wow2008;
/**
 * Page: Race to 60 (phpBB-style leaderboard)
 * Vars: $rows (list of [name, level, class file, race, faction A/H, guild, realm group label, region, realm type,
 *       first seen at this level, last seen, guild page id] in rank order), $guildBase (guild page URL prefix),
 *       $tracked, $at60, $topCount, $leadingClassCount (ints),
 *       $top (?array, the leader: name, level, ...), $leadingClass, $leadingClassFile, $updated, $generated, $subtitle (strings)
 */
$classIcon = fn(?string $cf) => $cf ? '/wow2008/class-icons/' . str_replace(' ', '-', strtolower(class_name($cf))) . '.png?v=' . ASSET_VERSION : '';
$regions = array_values(array_unique(array_filter(array_map(fn($r) => $r[7], $rows))));
$types = array_values(array_unique(array_map(fn($r) => $r[8], $rows)));
sort($regions);
$typeOrder = ['PvP', 'PvE', 'RP', 'RP-PvP', 'Other'];
usort($types, fn($a, $b) => array_search($a, $typeOrder) <=> array_search($b, $typeOrder));
?>
  <style>
    #r60-announce .section-bd{font-size:11px;line-height:1.55;color:#3a2410}
    .r60-tools{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
    .r60-tools input{flex:1;min-width:180px}
    .forumline{width:100%;border-collapse:separate;border-spacing:1px;background:#6a4a18;border:2px solid #2a1a08;font:11px Tahoma,Verdana,Arial,sans-serif}
    .forumline th{background:linear-gradient(#3a2a12,#1a1208);color:#ffd75a;font-weight:700;text-align:left;padding:6px 7px;white-space:nowrap}
    .forumline th.c,.forumline td.c{text-align:center}
    .forumline th.r,.forumline td.r{text-align:right}
    .forumline td{padding:5px 7px;vertical-align:middle;background:#f8ecc8;color:#3a2410}
    .forumline tr:nth-child(even) td{background:#efdfb4}
    .forumline tr:hover td{background:#fff6d8}
    .forumline td.r60-rank{font:700 13px Tahoma,Arial,sans-serif;color:#5a2a00;width:28px}
    .forumline tr.top td.r60-rank{color:#b07a00;text-shadow:0 1px 0 #fff3cc}
    .forumline .r60-who{font-weight:700;font-size:12px;text-shadow:0 1px 0 rgba(255,255,255,.4)}
    .forumline .sub{display:block;font-size:9px;color:#7a5a30}
    .r60-cls{display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
    .r60-cls img{width:16px;height:16px;border:1px solid #2a1a08}
    .r60-fac{height:18px;width:auto;max-width:none;vertical-align:middle}
    .r60-guild,.r60-guild a{color:#5a2a00}
    .r60-guild a{text-decoration:none;font-weight:700}
    .r60-guild a:hover{text-decoration:underline;color:#a03a00}
    .r60-lvl{display:inline-block;min-width:44px}
    .r60-lvl b{display:block;font:700 12px Tahoma,Arial,sans-serif;color:#3a1a00}
    .r60-lvl i{display:block;height:5px;margin-top:2px;background:#3a2a12;border:1px solid #1a1208}
    .r60-lvl i s{display:block;height:100%;background:linear-gradient(#b0e060,#4a8a10);text-decoration:none}
    .r60-lvl.max b{color:#b07a00}
    .r60-lvl.max i s{background:linear-gradient(#ffe48a,#c88a10)}
    .r60-ding{display:inline-block;padding:1px 5px;border:1px solid #7a5a20;background:linear-gradient(#ffe7a0,#d9a840);font:700 9px Tahoma,Arial,sans-serif;color:#3a1a00;letter-spacing:.5px}
    .r60-tag{display:inline-block;padding:0 4px;margin-right:3px;border:1px solid;font:700 9px/14px Tahoma,Arial,sans-serif}
    .r60-tag.US{background:#dfe8f8;border-color:#3a5a8a;color:#1a3a6a}
    .r60-tag.EU{background:#e8f0d8;border-color:#4a6a2a;color:#2a4a10}
    .r60-tag.PvP{background:#f8d8d0;border-color:#8a2a1a;color:#6a1a0a}
    .r60-tag.PvE{background:#f2ecd8;border-color:#7a6a3a;color:#4a3a10}
    .r60-tag.RP{background:#ecdcf4;border-color:#5a3a7a;color:#3a1a5a}
    .r60-tag.RP-PvP{background:#f4d8e8;border-color:#7a2a5a;color:#5a1040}
    .r60-seen{white-space:nowrap}
    .r60-seen.on{color:#2a7a10;font-weight:700}
    .r60-seen.on::before{content:"\25CF ";font-size:9px}
    .r60-ok{display:inline-block;margin-left:4px;vertical-align:top;color:#2a8a10;font-size:13px;font-weight:700;cursor:help;text-shadow:0 1px 0 #fff3cc}
    .r60-nav{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:8px;font-size:11px;color:#5a3a10}
    .r60-pages a{color:#7a2a00;font-weight:700;cursor:pointer}
    .r60-pages .cur{color:#3a1a00}
    .r60-empty td{text-align:center;padding:18px;color:#7a5a30}
    .cw-DRUID{color:#c86a00}.cw-HUNTER{color:#4a7a10}.cw-MAGE{color:#1a7aa8}.cw-PALADIN{color:#c0407a}.cw-PRIEST{color:#5a5a5a}
    .cw-ROGUE{color:#9a7a00}.cw-SHAMAN{color:#0050b0}.cw-WARLOCK{color:#6a3aa8}.cw-WARRIOR{color:#8a5a2a}
  </style>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Realm Census', 'url' => url('census')], ['label' => 'Race to 60']]]) ?>

    <?= component('banner', ['art' => 'lava-dragon', 'title' => 'Race to 60', 'subtitle' => $subtitle . ' · LEVELING LEADERBOARD', 'subtitle_color' => '#ffd8a0', 'stamp' => 'Updated: ' . $updated . ' · All times are UTC']) ?>

    <div class="tiles">
      <?= component('stat', ['label' => 'Characters seen', 'value' => fmt($tracked), 'note' => 'unique, every level']) ?>
      <?= component('stat', ['label' => 'Highest level', 'value' => $top ? (string)$top['level'] : '—', 'note' => $top ? $top['name'] : 'no racers yet']) ?>
      <?= component('stat', ['label' => 'Reached 60', 'value' => fmt($at60), 'note' => $at60 ? 'characters at the cap' : 'nobody yet']) ?>
      <?= component('stat', ['label' => 'Leading class', 'value' => $leadingClass, 'note' => $leadingClassCount . ' of the top ' . $topCount]) ?>
    </div>

    <?php component_start('section', ['title' => 'Announcement', 'icon' => 'g', 'id' => 'r60-announce']) ?>
        This leaderboard is for fun only. There is no guarantee of accuracy, and results may be outdated.
    <?php component_end() ?>

    <?php component_start('section', ['title' => 'Leaderboard', 'icon' => 'r', 'sub' => 'Top ' . fmt(count($rows)) . ' by level, then by who got there first']) ?>
        <div class="gtools r60-tools">
          <input id="q" type="text" placeholder="Find a player or guild…" aria-label="Find a player or guild">
          <div class="seg" id="fRegion"><button data-v="" class="on">All</button><?php foreach ($regions as $r): ?><button data-v="<?= e($r) ?>"><?= e($r) ?></button><?php endforeach ?></div>
          <div class="seg" id="fType"><button data-v="" class="on">All</button><?php foreach ($types as $t): ?><button data-v="<?= e($t) ?>"><?= e($t) ?></button><?php endforeach ?></div>
          <div class="seg" id="fFaction"><button data-v="" class="on">Both</button><button data-v="A">Alliance</button><button data-v="H">Horde</button></div>
        </div>

        <table class="forumline">
          <thead><tr>
            <th class="c">#</th><th>Player</th><th class="c">Level</th><th>Class</th><th>Race</th><th class="c">Faction</th>
            <th>Guild</th><th>Realm</th><th class="r">Last seen</th>
          </tr></thead>
          <tbody id="rows"></tbody>
        </table>

        <div class="r60-nav">
          <span id="count"></span>
          <span class="r60-pages" id="pages"></span>
        </div>
    <?php component_end() ?>

    <div class="source">Levels from in-game /who samples and Blizzard profile checks · /who updated <?= e($updated) ?> · realms grouped by region and type</div>
  </main>

<?php start_section('scripts') ?>
<script>
(() => {
// name, level, class file, race, faction, guild, realm, region, realm type, first seen at this level, last seen, guild id, level source, API check
const RACERS = <?= js($rows) ?>;
const GUILD_BASE = <?= js($guildBase) ?>;
const ICON = <?= js(array_combine(array_keys(\CLASS_META), array_map($classIcon, array_keys(\CLASS_META)))) ?>;
const CLASS = <?= js(array_map(fn($m) => $m[0], \CLASS_META)) ?>;
const FAC_IMG = {A: <?= js(asset('faction-alliance.png')) ?>, H: <?= js(asset('faction-horde.png')) ?>};
const PER_PAGE = 50;
const filt = {region: '', type: '', faction: '', q: ''};
let page = 0;
const h = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const stamp = t => { const d = new Date(t * 1000); const p = n => String(n).padStart(2, '0');
  return MONTHS[d.getUTCMonth()] + ' ' + p(d.getUTCDate()) + ' ' + p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()); };
function ago(t) {
  const m = Math.max(0, Math.round((Date.now() / 1000 - t) / 60));
  if (m < 10) return '<span class="r60-seen on">Just now</span>';
  if (m < 60) return '<span class="r60-seen">' + m + ' min ago</span>';
  if (m < 1440) return '<span class="r60-seen">' + Math.round(m / 60) + ' hr ago</span>';
  const d = Math.round(m / 1440);
  return '<span class="r60-seen">' + d + (d === 1 ? ' day' : ' days') + ' ago</span>';
}
function matches(r) {
  const q = filt.q.toLowerCase();
  return (!filt.region || r[7] === filt.region) && (!filt.type || r[8] === filt.type) && (!filt.faction || r[4] === filt.faction)
    && (!q || r[0].toLowerCase().includes(q) || r[5].toLowerCase().includes(q));
}
function row(r, rank) {
  const [name, lvl, cf, race, fac, guild, realm, region, type, reached, seen, guildId, source, api] = r;
  const max = lvl >= 60, cls = CLASS[cf] || cf, facName = fac === 'A' ? 'Alliance' : 'Horde';
  // A green check when the Blizzard API confirmed the level shown; hover for when.
  const verified = api && api.status === 'ok' && api.level === lvl && api.checked_at;
  const check = verified ? '<span class="r60-ok" title="Level ' + lvl + ' verified by the Blizzard API on ' + stamp(api.checked_at) + ' UTC">&#10004;</span>' : '';
  return '<tr class="' + (rank <= 3 ? 'top' : '') + '">' +
    '<td class="c r60-rank">' + rank + '</td>' +
    '<td><span class="r60-who cw-' + cf + '">' + h(name) + '</span><span class="sub">' + h(race) + ' ' + h(cls) + '</span></td>' +
    '<td class="c"><span class="r60-lvl' + (max ? ' max' : '') + '"><b>' + lvl + '</b><i><s style="width:' + Math.min(100, Math.round(lvl / 60 * 100)) + '%"></s></i></span>' + check + '</td>' +
    '<td><span class="r60-cls">' + (ICON[cf] ? '<img src="' + ICON[cf] + '" alt="">' : '') + h(cls) + '</span></td>' +
    '<td>' + h(race) + '</td>' +
    '<td class="c"><img class="r60-fac" src="' + FAC_IMG[fac] + '" alt="' + facName + '" title="' + facName + '"></td>' +
    '<td class="r60-guild">' + (guild ? (guildId ? '<a href="' + GUILD_BASE + guildId + '/">&lt;' + h(guild) + '&gt;</a>' : '&lt;' + h(guild) + '&gt;')
      : '<span class="sub">—</span>') + '</td>' +
    '<td style="white-space:nowrap">' + (region ? '<span class="r60-tag ' + region + '">' + region + '</span>' : '') + '<span class="r60-tag ' + h(type) + '">' + h(type) + '</span></td>' +
    '<td class="r">' + ago(seen) + '</td></tr>';
}
function render() {
  // Ranks stay overall ranks, so a filtered list shows where each racer stands in the whole race.
  const list = RACERS.map((r, i) => [r, i + 1]).filter(([r]) => matches(r));
  const pages = Math.max(1, Math.ceil(list.length / PER_PAGE));
  page = Math.min(page, pages - 1);
  const shown = list.slice(page * PER_PAGE, (page + 1) * PER_PAGE);
  document.getElementById('rows').innerHTML = shown.length ? shown.map(([r, rank]) => row(r, rank)).join('')
    : '<tr class="r60-empty"><td colspan="9">No racers match these filters.</td></tr>';
  document.getElementById('count').textContent = list.length
    ? 'Showing ' + (page * PER_PAGE + 1) + '–' + (page * PER_PAGE + shown.length) + ' of ' + list.length + ' racers' : '';
  const links = [];
  for (let p = 0; p < pages; p++) {
    if (pages > 7 && p > 1 && p < pages - 2 && Math.abs(p - page) > 1) { if (links[links.length - 1] !== '…') links.push('…'); continue; }
    links.push(p === page ? '<b class="cur">' + (p + 1) + '</b>' : '<a data-p="' + p + '">' + (p + 1) + '</a>');
  }
  document.getElementById('pages').innerHTML = pages > 1 ? '<b>Goto page</b> ' + links.join(', ').replace(/, …, /g, ' … ')
    + (page < pages - 1 ? ' &nbsp;<a data-p="' + (page + 1) + '">Next</a>' : '') : '';
}
document.getElementById('pages').addEventListener('click', e => {
  const a = e.target.closest('a[data-p]'); if (!a) return;
  page = +a.dataset.p; render();
  document.querySelector('.forumline').scrollIntoView({block: 'start'});
});
for (const [id, key] of [['fRegion', 'region'], ['fType', 'type'], ['fFaction', 'faction']]) {
  const seg = document.getElementById(id);
  seg.addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    seg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
    filt[key] = b.dataset.v; page = 0; render();
  });
}
document.getElementById('q').addEventListener('input', e => { filt.q = e.target.value; page = 0; render(); });

render();
})();
</script>
<?php end_section() ?>
