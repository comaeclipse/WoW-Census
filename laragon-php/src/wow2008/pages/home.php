<?php
namespace Wow2008;
/**
 * Page: the front page (news rotator, totals, edition directory, news, and a right column).
 * Vars: $editions (list, busiest first; see home_page()), $news (newest posts), $totals (array),
 *       $poll (src/poll-question.php)
 */
$pct = fn(float $p) => (abs($p) < 10 ? number_format(abs($p), 1) : (string)round(abs($p))) . '%';
$trend = fn(?float $d, string $cls = 'tr') => $d === null
  ? '<span class="' . $cls . ' new">new</span>'
  : '<span class="' . $cls . ' ' . ($d < 0 ? 'dn' : 'up') . '">' . ($d < 0 ? '▼' : '▲') . ' ' . $pct($d) . '</span>';
/** A 7-day running-total line, in the banner's 208 x 38 box. */
$spark = function (array $vals): string {
  $lo = min($vals); $hi = max($vals); $n = count($vals);
  $pts = [];
  foreach ($vals as $i => $v) $pts[] = sprintf('%.1f,%.1f', $n > 1 ? $i * 208 / ($n - 1) : 208, 33 - ($hi > $lo ? ($v - $lo) / ($hi - $lo) : 1) * 28);
  return implode(' ', $pts);
};
// The Forever beta archive still counts toward the totals, but isn't offered in the edition lists.
$listed = array_values(array_filter($editions, fn($ed) => $ed['source'] !== 'classic-beta'));
$top = $editions[0] ?? null;
$slides = [];
if ($top) $slides[] = ['lava-dragon', $top['name'] . ': ' . fmt($top['n7']) . ' characters seen this week',
  'The busiest edition in our scans over the last seven days.', $top['href'], 'See the census »'];
foreach (array_slice($news, 0, 2) as $i => $post)
  $slides[] = [$i ? 'moonbrook' : 'molten-core', $post['title'], mb_strimwidth($post['excerpt'], 0, 150, '…'), '/blog.php?id=' . $post['id'], 'Read more »'];
$slides[] = ['realms', 'Every realm, side by side', 'Compare population and faction balance across the realms we scan.', '/realms', 'Open the Realm Viewer »'];
// Slides take turns through the homepage screenshots, moving on a step each day; banner-home-5 is kept
// for the milestone slide below so it never shows twice.
$day = intdiv(time(), 86400);
$rotation = [1, 2, 3, 4, 6, 7, 8, 9];
foreach ($slides as $i => &$slide) $slide[0] = 'home-' . $rotation[($day + $i) % count($rotation)];
unset($slide);
// Fixed slides first. The Discord banner carries its own text, so it shows whole ("plain": not cropped),
// sitting above the caption bar, and the whole picture links to the invite as well.
$discord = 'https://discord.gg/Dzw8H3QZX';
$fixed = [['discord', 'Volunteers needed!', 'Help us scan realms and grow the census. Everyone is welcome.', $discord, 'Join the Discord »', true]];
if ($totals['tracked'] >= 1000000)
  $fixed[] = ['home-5', 'Over ' . fmt((int)(floor($totals['tracked'] / 1000000) * 1000000)) . ' characters in our database',
    fmt($totals['tracked']) . ' characters tracked across ' . count($editions) . ' editions, and counting.', $top['href'] ?? '/realms', 'See the census »'];
$slides = array_merge($fixed, $slides);
$thumbs = ['shoutbox-stone.avif', 'banner-census.webp', 'banner-guilds.webp'];
?>
  <main class="main home">
    <div class="crumb">» Home</div>

    <div class="hero" id="hero">
<?php foreach ($slides as $i => $slide): [$art, $title, $text, $href, $more] = $slide; $plain = $slide[5] ?? false; ?>
<?php $ext = str_starts_with($href, 'http') ? ' target="_blank" rel="noopener"' : ''; ?>
      <div class="slide<?= $plain ? ' plain' : '' ?><?= $i ? '' : ' on' ?>">
<?php if ($plain): ?>
        <a href="<?= e($href) ?>"<?= $ext ?>><img src="<?= e(asset("banner-$art.webp")) ?>" alt="<?= e($title) ?>" width="639" height="246"></a>
<?php else: ?>
        <img src="<?= e(asset("banner-$art.webp")) ?>" alt="" width="880" height="150">
<?php endif; ?>
        <div class="cap"><b><?= e($title) ?></b><?= e($text) ?> <a href="<?= e($href) ?>"<?= $ext ?>><?= e($more) ?></a></div>
      </div>
<?php endforeach; ?>
      <div class="dots"><?php foreach ($slides as $i => $_): ?><button type="button"<?= $i ? '' : ' class="on"' ?> aria-label="Story <?= $i + 1 ?>"><?= $i + 1 ?></button><?php endforeach; ?></div>
    </div>

    <div class="tiles">
      <div class="stat"><b><?= e(fmt($totals['tracked'])) ?></b><span>Characters tracked</span><em>across <?= count($editions) ?> editions</em></div>
      <div class="stat"><b><?= e(fmt($totals['seen7'])) ?></b><span>Seen last 7 days</span><?php if ($totals['change'] !== null): ?><em class="<?= $totals['change'] < 0 ? 'dnt' : 'upt' ?>"><?= $totals['change'] < 0 ? '▼' : '▲' ?> <?= e($pct($totals['change'])) ?> vs last week</em><?php else: ?><em>across <?= (int)$totals['seen7Editions'] ?> editions</em><?php endif; ?></div>
      <div class="stat"><b><?= e(fmt($totals['realms'])) ?></b><span>Realms surveyed</span><em>in the census sample</em></div>
      <div class="stat"><b data-ago="<?= (int)$totals['newestT'] ?>" data-short><?= e(gmdate('M j', (int)$totals['newestT'])) ?></b><span>Since last scan</span><em><?= e($totals['newestName']) ?></em></div>
    </div>

    <?php component_start('section', ['title' => 'Pick Your Edition', 'sub' => 'Sorted by characters seen this week', 'body_class' => 'flush']) ?>
        <table class="dir">
          <thead><tr><th colspan="2">Edition</th><th>Seen 7d</th><th>This week</th><th class="r">Tracked</th><th>Last scan</th></tr></thead>
          <tbody>
<?php foreach ($listed as $ed): $pts = $spark($ed['week']); ?>
            <tr>
              <td class="ic"><span class="crest" style="background:radial-gradient(circle at 40% 35%,#fff6,<?= e($ed['color']) ?> 55%,#000 95%)"></span></td>
              <td class="nm"><a href="<?= e($ed['href']) ?>"><?= e($ed['name']) ?></a></td>
              <td><span class="n"><?= e(fmt($ed['n7'])) ?></span> <?= $trend($ed['delta']) ?></td>
              <td><svg viewBox="0 0 208 38" preserveAspectRatio="none" aria-hidden="true"><path d="M<?= str_replace(' ', ' L', $pts) ?> L208,38 L0,38Z" fill="rgba(176,122,0,.22)"/><polyline points="<?= $pts ?>" fill="none" stroke="#7a2a00" stroke-width="1.5" vector-effect="non-scaling-stroke"/></svg></td>
              <td class="r"><?= e(fmt($ed['total'])) ?></td>
              <td class="last" data-ago="<?= $ed['lastT'] ?>"><?= e(gmdate('M j', $ed['lastT'])) ?></td>
            </tr>
<?php endforeach; ?>
          </tbody>
        </table>
    <?php component_end() ?>

    <?php component_start('section', ['title' => 'Latest News', 'icon' => 'r', 'sub_html' => '<a href="/blog.php">News archive »</a>']) ?>
<?php foreach ($news as $i => $post): ?>
        <article class="post">
          <div class="post-hd"><span class="ico<?= $i ? '' : ' g' ?>"></span><div><h2><a href="/blog.php?id=<?= e($post['id']) ?>"><?= e($post['title']) ?></a></h2><div class="by">Posted by <b>WoWCensus</b> on <?= e(gmdate('l, F j, Y', strtotime($post['date'] . ' UTC'))) ?></div></div></div>
          <div class="post-bd">
            <img class="thumb" src="<?= e(asset($thumbs[$i % count($thumbs)])) ?>" alt="" width="96" height="64">
            <p><?= e($post['excerpt']) ?></p>
            <div class="more"><a href="/blog.php?id=<?= e($post['id']) ?>">Read more »</a></div>
          </div>
        </article>
<?php endforeach; ?>
    <?php component_end() ?>

    <div class="source">Counts describe characters seen in in-game /who samples, not unique players or a live online population.</div>
  </main>

  <aside class="side home-side">
    <div class="box">
      <h4><i></i>Shoutbox</h4>
      <div class="mini-sb">
        <div class="sb-log" id="mini-sb" tabindex="0" aria-label="Recent shouts, newest last"><p class="sb-empty">Loading shouts…</p></div>
        <form method="post" action="/shoutbox/"><input type="hidden" name="init" value="1"><button class="btn-red" type="submit">Post a shout »</button></form>
        <div class="box-ft"><a href="/shoutbox/">Full shoutbox »</a> · Refreshes every 30s</div>
      </div>
    </div>

    <div class="box">
      <h4><i></i>Edition Status</h4>
      <div class="realm">
<?php foreach ($listed as $ed): ?>
        <div class="row"><a href="<?= e($ed['href']) ?>"><?= e($ed['name']) ?></a><b><?= e(fmt($ed['n7'])) ?></b><?= $trend($ed['delta'], 'st') ?></div>
<?php endforeach; ?>
      </div>
      <div class="box-ft">Characters seen, last 7 days</div>
    </div>

    <div class="box">
      <h4><i></i>Community Poll</h4>
      <form class="poll" id="poll" method="post" action="/poll">
        <div class="q"><?= e($poll['question']) ?></div>
<?php foreach ($poll['options'] as $key => $label): ?>
        <label><input type="radio" name="option" value="<?= e($key) ?>"> <?= e($label) ?></label>
<?php endforeach; ?>
        <button class="btn-red" type="submit">Vote</button> <a class="pl" href="#" id="poll-show">View results</a>
        <div class="poll-err" id="poll-err" role="alert" hidden></div>
      </form>
      <div class="poll poll-res" id="poll-res" hidden></div>
    </div>

    <div class="box">
      <h4><i></i>Quick Links</h4>
      <ul class="ql">
        <li><span class="qi y"></span><a href="/realms">Realm Viewer</a></li>
        <li><span class="qi"></span><a href="/combos">Every Race &amp; Class</a></li>
        <li><span class="qi g"></span><a href="/auctionhouse">Auction House Prices</a></li>
        <li><span class="qi y"></span><a href="/blog.php">News &amp; Updates</a></li>
        <li><span class="qi"></span><a href="https://discord.gg/Dzw8H3QZX" target="_blank" rel="noopener">Join us on Discord</a></li>
      </ul>
    </div>
  </aside>

<?php start_section('scripts') ?>
<script>
(function () {
  var nf = function (n) { return n.toLocaleString('en-US'); };

  // Times since each scan, worked out in the browser (pages are cached).
  var now = Date.now() / 1000;
  document.querySelectorAll('[data-ago]').forEach(function (el) {
    var s = now - +el.dataset.ago, hrs = Math.floor(s / 3600), days = Math.floor(s / 86400);
    if (!+el.dataset.ago || s < 0) return;
    el.textContent = 'short' in el.dataset ? (s < 7200 ? Math.max(1, Math.floor(s / 60)) + ' min' : days < 2 ? hrs + ' hrs' : days + ' days')
      : s < 3600 ? 'under an hour ago' : days < 1 ? hrs + (hrs === 1 ? ' hr' : ' hrs') + ' ago' : days + (days === 1 ? ' day' : ' days') + ' ago';
    if (el.classList.contains('last')) el.classList.add(s < 86400 ? 'fresh' : s > 7 * 86400 ? 'stale' : 'ok');
  });

  // News rotator.
  var slides = [].slice.call(document.querySelectorAll('#hero .slide')), dots = [].slice.call(document.querySelectorAll('#hero .dots button')), cur = 0, timer;
  function go(i) { slides[cur].classList.remove('on'); dots[cur].classList.remove('on'); cur = i; slides[cur].classList.add('on'); dots[cur].classList.add('on'); }
  function tick() { clearInterval(timer); timer = setInterval(function () { go((cur + 1) % slides.length); }, 6000); }
  dots.forEach(function (d, i) { d.onclick = function () { go(i); tick(); }; });
  if (slides.length > 1) tick();

  // The newest shouts, fetched from the shoutbox page (this page is cached; the shoutbox is not).
  var log = document.getElementById('mini-sb');
  function shouts() {
    fetch('/shoutbox/', {credentials: 'same-origin', cache: 'no-store'}).then(function (r) { if (!r.ok) throw r; return r.text(); }).then(function (html) {
      var lines = new DOMParser().parseFromString(html, 'text/html').querySelectorAll('#shoutbox-recent .sb-line');
      if (!lines.length) { log.innerHTML = '<p class="sb-empty">No shouts yet. Be the first!</p>'; return; }
      var stick = log.scrollHeight - log.scrollTop - log.clientHeight < 20 || !log.querySelector('.sb-line');
      log.innerHTML = '';
      [].slice.call(lines, -15).forEach(function (l) {
        var t = l.querySelector('.sb-time');
        if (t && t.dateTime) t.textContent = '[' + new Date(t.dateTime).toTimeString().slice(0, 5) + ']';
        log.appendChild(document.importNode(l, true));
      });
      if (stick) log.scrollTop = log.scrollHeight;
    }).catch(function () { if (!log.querySelector('.sb-line')) log.innerHTML = '<p class="sb-empty">The shoutbox is unavailable right now.</p>'; });
  }
  shouts();
  setInterval(function () { if (!document.hidden) shouts(); }, 30000);

  // Community poll: the tally lives at /poll (this page is cached, so a vote never shows up in its HTML).
  var form = document.getElementById('poll'), res = document.getElementById('poll-res'), err = document.getElementById('poll-err');
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function results(p) {
    var h = '<div class="q">' + esc(p.question) + '</div>';
    p.options.forEach(function (o) {
      var pc = p.total ? Math.round(100 * o.votes / p.total) : 0;
      h += '<div class="pr' + (o.key === p.voted ? ' mine' : '') + '"><span>' + esc(o.label) + '</span><i style="width:' + pc + '%"></i><b>' + pc + '%</b></div>';
    });
    h += '<div class="pv">' + nf(p.total) + (p.total === 1 ? ' vote' : ' votes') + (p.voted ? ' · thanks for voting!' : ' · <a href="#" id="poll-back">Vote</a>') + '</div>';
    res.innerHTML = h;
    form.hidden = true; res.hidden = false;
    var back = document.getElementById('poll-back');
    if (back) back.onclick = function (e) { e.preventDefault(); res.hidden = true; form.hidden = false; };
  }
  function fail(text) { err.textContent = text; err.hidden = false; }
  function poll(opts) {
    return fetch('/poll', Object.assign({credentials: 'same-origin', cache: 'no-store'}, opts)).then(function (r) {
      return r.json().then(function (body) { if (!r.ok) throw new Error(body.error || 'The poll is unavailable right now'); return body; });
    });
  }
  poll().then(function (p) { if (p.voted) results(p); }).catch(function () {});
  document.getElementById('poll-show').onclick = function (e) {
    e.preventDefault();
    poll().then(results).catch(function (x) { fail(x.message); });
  };
  form.onsubmit = function (e) {
    e.preventDefault();
    var pick = form.querySelector('input[name=option]:checked');
    if (!pick) { fail('Pick an answer first.'); return; }
    err.hidden = true;
    form.querySelector('button').disabled = true;
    poll({method: 'POST', body: new URLSearchParams({option: pick.value})}).then(results)
      .catch(function (x) { fail(x.message); }).then(function () { form.querySelector('button').disabled = false; });
  };
})();
</script>
<?php end_section() ?>
