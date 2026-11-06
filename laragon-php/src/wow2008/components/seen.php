<?php
namespace Wow2008;
/**
 * Banner overlay: distinct characters seen in the last 7 or 30 days or overall,
 * with a running-total line chart and a range toggle. Each range is rendered
 * here; the toggle only shows one. See seen_activity() in src/Data.php.
 *
 * Props:
 *   seen  seen_activity() result
 */
$ranges = ['all' => ['All', 0, 'mo'], '30' => ['30', 30, 'mo'], '7' => ['7 days', 7, 'wk']];
// PHP stores the keys '30' and '7' as integers.
$default = 30;
$day = fn(int $d) => gmdate('M j', $d * 86400);
$firstDay = $seen['all']['firstDay'];
?>
<div class="seen" data-seen>
<div class="hd"><span>Seen recently</span><span class="rng"><?php foreach ($ranges as $key => [$label]): ?><button type="button" data-r="<?= e($key) ?>" aria-pressed="<?= $key === $default ? 'true' : 'false' ?>"><?= e($label) ?></button><?php endforeach; ?></span></div>
<?php foreach ($ranges as $key => [$label, $len, $per]):
  $r = $seen[$key];
  // A change figure only when the data covers the whole span before this one.
  $delta = null;
  if ($len && $r['prev'] && $firstDay <= $seen['lastDay'] - 2 * $len + 1) {
    $pct = 100 * ($r['n'] - $r['prev']) / $r['prev'];
    $delta = [($pct < 0 ? '▼ ' : '▲ ') . (abs($pct) < 10 ? number_format(abs($pct), 1) : round(abs($pct))) . '% ' . $per, $pct < 0];
  }
  $vals = $r['running'];
  $lo = min($vals);
  $hi = max($vals);
  $count = count($vals);
  $pts = [];
  foreach ($vals as $i => $v)
    $pts[] = sprintf('%.1f,%.1f', $count > 1 ? $i * 208 / ($count - 1) : 208, 33 - ($hi > $lo ? ($v - $lo) / ($hi - $lo) : 1) * 28);
  $line = implode(' ', $pts);
  [$endX, $endY] = explode(',', end($pts));
?>
<div class="seen-v" data-r="<?= e($key) ?>"<?= $key === $default ? '' : ' hidden' ?>>
<div class="row"><span class="n"><?= e(\js_locale($r['n'])) ?></span><span class="lbl">characters</span><?php if ($delta): ?><span class="d<?= $delta[1] ? ' dn' : '' ?>"><?= e($delta[0]) ?></span><?php elseif ($key === 'all'): ?><span class="d since">since <?= e($day($firstDay)) ?></span><?php endif; ?></div>
<svg viewBox="0 0 208 38" preserveAspectRatio="none" aria-hidden="true">
<defs><linearGradient id="seen-g-<?= e($key) ?>" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffd75a" stop-opacity=".35"/><stop offset="1" stop-color="#ffd75a" stop-opacity="0"/></linearGradient></defs>
<line x1="0" y1="12.5" x2="208" y2="12.5" stroke="#3a2a10" stroke-dasharray="2 3"/>
<line x1="0" y1="25.5" x2="208" y2="25.5" stroke="#3a2a10" stroke-dasharray="2 3"/>
<path d="M<?= str_replace(' ', ' L', $line) ?> L208,38 L0,38 Z" fill="url(#seen-g-<?= e($key) ?>)"/>
<polyline points="<?= $line ?>" fill="none" stroke="#ffd75a" stroke-width="1.5" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
<circle cx="<?= $endX - 2 ?>" cy="<?= $endY ?>" r="2.2" fill="#fff3c0" stroke="#ffd75a"/>
</svg>
<div class="ax"><span><?= e($day($r['firstDay'])) ?></span><span><?= e($day(intdiv($r['firstDay'] + $seen['lastDay'], 2))) ?></span><span><?= e($day($seen['lastDay'])) ?></span></div>
</div>
<?php endforeach; ?>
</div>
<script>document.querySelectorAll('[data-seen]').forEach(function(box){box.querySelectorAll('.rng button').forEach(function(b){b.onclick=function(){box.querySelectorAll('.rng button').forEach(function(o){o.setAttribute('aria-pressed',String(o===b))});box.querySelectorAll('.seen-v').forEach(function(v){v.hidden=v.dataset.r!==b.dataset.r})}})})</script>
