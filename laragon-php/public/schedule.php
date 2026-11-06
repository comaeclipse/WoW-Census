<?php
// LOCAL ONLY. Weekly /who scan calendar built from src/scan-plan.php, checked
// off against live PostgreSQL sweeps. Never publish to S3.
declare(strict_types=1);

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
  http_response_code(403);
  exit;
}

require dirname(__DIR__) . '/src/config.php';
require dirname(__DIR__) . '/src/Db.php';

$plan = require dirname(__DIR__) . '/src/scan-plan.php';
$editionNames = [
  'classic-progression' => 'TBC Anniversary', 'hardcore' => 'Hardcore', 'sod' => 'Season of Discovery',
  'mop-classic' => 'MoP Classic', 'classic' => 'Classic Era', 'retail' => 'Retail',
];
$editionShort = [
  'classic-progression' => 'TBC', 'hardcore' => 'HC', 'sod' => 'SoD',
  'mop-classic' => 'MoP', 'classic' => 'Era', 'retail' => 'Retail',
];

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$viewerZoneName = $_COOKIE['tz'] ?? '';
if (!in_array($viewerZoneName, timezone_identifiers_list(), true)) $viewerZoneName = 'America/New_York';
$viewerZone = new DateTimeZone($viewerZoneName);
$utc = new DateTimeZone('UTC');
$now = time();
$start = new DateTimeImmutable($plan['start'], $utc);

// Queue per region: lead editions, then the rest round robin, then the last ones.
$queue = [];
foreach (array_keys($plan['lanes']) as $region) {
  $groups = [];
  foreach ($plan['realms'] as $r) if ($r[1] === $region) $groups[$r[0]][] = $r;
  foreach ($plan['lead'] as $e) array_push($queue, ...($groups[$e] ?? []));
  $middle = array_values(array_diff_key($groups, array_flip(array_merge($plan['lead'], $plan['last']))));
  for ($i = 0; $middle && $i < max(array_map('count', $middle)); $i++)
    foreach ($middle as $g) if (isset($g[$i])) $queue[] = $g[$i];
  foreach ($plan['last'] as $e) array_push($queue, ...($groups[$e] ?? []));
}
// Assign queued realms to lanes: each takes the earliest free lane in its region.
$assignments = [];
$laneFree = [];
foreach ($plan['lanes'] as $region => $count) $laneFree[$region] = array_fill(0, $count, 1);
foreach ($queue as [$edition, $region, $realm, $weeks, $tier, $note]) {
  $lane = array_keys($laneFree[$region], min($laneFree[$region]))[0];
  $first = $laneFree[$region][$lane];
  $assignments[] = compact('edition', 'region', 'realm', 'weeks', 'tier', 'note', 'lane', 'first');
  $laneFree[$region][$lane] = $first + $weeks;
}
$totalWeeks = max(array_map(fn($lanes) => max($lanes), $laneFree)) - 1;

function slot_time(DateTimeImmutable $start, int $week, int $weekday, string $time, string $zone): int {
  $date = $start->modify('+' . (($week - 1) * 7 + $weekday - 1) . ' days')->format('Y-m-d');
  return (new DateTimeImmutable("$date $time", new DateTimeZone($zone)))->getTimestamp();
}

// Every slot in the plan.
$slots = [];
foreach ($assignments as $a) {
  for ($week = $a['first']; $week < $a['first'] + $a['weeks']; $week++) {
    foreach ($plan['slots'][$a['region']] as $i => [$weekday, $from, $to, $label]) {
      $factions = ($i + $week + $a['lane']) % 2 ? ['Alliance', 'Horde'] : ['Horde', 'Alliance'];
      $zone = $plan['zones'][$a['region']];
      $slots[] = $a + ['week' => $week, 'label' => $label, 'kind' => 'scan', 'factions' => $factions, 'local_time' => "{$from}–{$to}",
        'at' => slot_time($start, $week, $weekday, $from, $zone), 'until' => slot_time($start, $week, $weekday, $to, $zone)];
    }
  }
}
// Calibration runs in the calibration realm's first week, on the first free
// calibration weekday of that week.
$calibrationTaken = [];
foreach ($plan['calibration']['realms'] as $edition => [$region, $realm, $faction]) {
  $match = array_values(array_filter($assignments, fn($a) => $a['edition'] === $edition && $a['region'] === $region && $a['realm'] === $realm));
  if (!$match) continue;
  $weekday = null;
  for ($week = $match[0]['first']; $week <= $totalWeeks && $weekday === null; $week++)
    foreach ($plan['calibration']['weekdays'] as $d) if (empty($calibrationTaken["$week|$d"])) { $weekday = $d; break; }
  if ($weekday === null) continue;
  $week--;
  $calibrationTaken["$week|$weekday"] = true;
  foreach ($plan['calibration']['times'] as $time) {
    $at = slot_time($start, $week, $weekday, $time, $plan['zones'][$region]);
    $slots[] = ['edition' => $edition, 'region' => $region, 'realm' => $realm, 'tier' => '', 'note' => '', 'lane' => null,
      'week' => $week, 'label' => 'Calibration', 'kind' => 'calibration', 'factions' => [$faction], 'local_time' => $time,
      'at' => $at, 'until' => $at + $plan['calibration']['window_minutes'] * 60];
  }
}
usort($slots, fn($a, $b) => [$a['at'], $a['lane'] ?? -1] <=> [$b['at'], $b['lane'] ?? -1]);

// Live sweeps, indexed by edition|region|realm|faction. Keys without a region
// prefix are older saves and match either region.
$sweeps = [];
$st = Db::pdo()->prepare('SELECT game, source_game, started_at, completed_at, status, capped_count
  FROM marketlens_import.population_sweeps WHERE started_at >= ?');
$st->execute([$start->getTimestamp() - 86400]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
  if (!preg_match('/^realm:(?:(eu|us|kr|tw|cn):[^:]+:)?(.+)-(Alliance|Horde)$/', $row['game'], $m)) continue;
  foreach ($m[1] !== '' ? [$m[1]] : ['us', 'eu'] as $region)
    $sweeps[$row['source_game'] . '|' . $region . '|' . $m[2] . '|' . $m[3]][] = $row;
}
// Ever swept, for the "characters needed" list.
$everSwept = [];
foreach (Db::pdo()->query('SELECT DISTINCT game, source_game FROM marketlens_import.population_sweeps')->fetchAll(PDO::FETCH_ASSOC) as $row) {
  if (!preg_match('/^realm:(?:(eu|us|kr|tw|cn):[^:]+:)?(.+)-(Alliance|Horde)$/', $row['game'], $m)) continue;
  foreach ($m[1] !== '' ? [$m[1]] : ['us', 'eu'] as $region) $everSwept[$row['source_game'] . '|' . $region . '|' . $m[2] . '|' . $m[3]] = true;
}

function faction_status(array $slot, string $faction, array $sweeps, array $plan, int $now): string {
  $from = $slot['at'] - $plan['slack_minutes'] * 60;
  $to = $slot['until'] + $plan['slack_minutes'] * 60;
  $best = '';
  foreach (array_merge([$slot['edition']], $plan['also_match'][$slot['edition']] ?? []) as $source) {
    foreach ($sweeps["$source|{$slot['region']}|{$slot['realm']}|$faction"] ?? [] as $row) {
      $t = (int)$row['started_at'];
      if ($t < $from || $t > $to) continue;
      $clean = $row['status'] === 'complete' && (int)$row['capped_count'] === 0;
      if ($clean) return 'done';
      $best = 'redo';
    }
  }
  if ($best) return $best;
  if ($now > $to) return 'missed';
  if ($now >= $from) return 'now';
  return 'upcoming';
}
foreach ($slots as &$slot) {
  $slot['status'] = [];
  foreach ($slot['factions'] as $f) $slot['status'][$f] = faction_status($slot, $f, $sweeps, $plan, $now);
  $slot['id'] = substr(sha1($slot['kind'] . $slot['edition'] . $slot['region'] . $slot['realm'] . $slot['at']), 0, 16);
}
unset($slot);

$slotLabel = fn(array $s): string => $editionNames[$s['edition']] . ' · ' . strtoupper($s['region']) . ' ' . $s['realm'];

// Calendar file for phone reminders: every slot from today on.
if (isset($_GET['ics'])) {
  header('Content-Type: text/calendar; charset=utf-8');
  header('Content-Disposition: attachment; filename="wowcensus-scans.ics"');
  $esc = fn(string $s): string => addcslashes($s, ",;\\");
  $out = ["BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//WoWCensus//Scan plan//EN", "X-WR-CALNAME:WoWCensus scans"];
  foreach ($slots as $s) {
    if ($s['at'] < $now - 86400) continue;
    $what = $s['kind'] === 'calibration' ? 'Calibration sweep ' . $s['factions'][0] : implode(' then ', $s['factions']);
    $out[] = "BEGIN:VEVENT";
    $out[] = "UID:{$s['id']}@wowcensus.local";
    $out[] = "DTSTAMP:" . gmdate('Ymd\THis\Z', $now);
    $out[] = "DTSTART:" . gmdate('Ymd\THis\Z', $s['at']);
    $out[] = "DTEND:" . gmdate('Ymd\THis\Z', $s['until']);
    $out[] = "SUMMARY:" . $esc("Scan {$editionShort[$s['edition']]} " . strtoupper($s['region']) . " {$s['realm']}: $what");
    $out[] = "DESCRIPTION:" . $esc("{$s['label']}. Realm time {$s['local_time']}. Any time in the window; complete sweep, no capped queries.");
    $out[] = "BEGIN:VALARM";
    $out[] = "TRIGGER:-PT10M";
    $out[] = "ACTION:DISPLAY";
    $out[] = "DESCRIPTION:" . $esc("Scan {$s['realm']}");
    $out[] = "END:VALARM";
    $out[] = "END:VEVENT";
  }
  $out[] = "END:VCALENDAR";
  echo implode("\r\n", $out) . "\r\n";
  exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

$currentWeek = (int)floor(($now - $start->getTimestamp()) / (7 * 86400)) + 1;
$week = isset($_GET['week']) ? (int)$_GET['week'] : $currentWeek;
$week = max(1, min($totalWeeks, $week));
$weekSlots = array_values(array_filter($slots, fn($s) => $s['week'] === $week));
$next = null;
foreach ($slots as $s) {
  if (array_intersect($s['status'], ['now', 'upcoming'])) { $next = $s; break; }
}

$days = [];
foreach ($weekSlots as $s) {
  $day = (new DateTimeImmutable('@' . $s['at']))->setTimezone($viewerZone)->format('Y-m-d');
  $days[$day][] = $s;
}
ksort($days);

$fmt = fn(int $t, string $f, DateTimeZone $z): string => (new DateTimeImmutable('@' . $t))->setTimezone($z)->format($f);
$weekStart = $start->modify('+' . (($week - 1) * 7) . ' days');
$sweepCount = array_sum(array_map(fn($s) => count($s['factions']), $weekSlots));
$doneCount = array_sum(array_map(fn($s) => count(array_keys($s['status'], 'done')), $weekSlots));
$hours = array_sum(array_map(fn($s) => count($s['factions']) * $plan['sweep_minutes'][$s['edition']], $weekSlots)) / 60;
// Estimated sweeping per shared window, to flag windows that can't fit it all.
$windowLoad = [];
foreach ($weekSlots as $s)
  if ($s['kind'] === 'scan') $windowLoad[$s['region'] . '|' . $s['at']] = ($windowLoad[$s['region'] . '|' . $s['at']] ?? 0) + count($s['factions']) * $plan['sweep_minutes'][$s['edition']];
$overloaded = fn(array $s): ?int => $s['kind'] === 'scan' && $s['lane'] === min(array_column(array_filter($weekSlots, fn($o) => $o['kind'] === 'scan' && $o['region'] === $s['region'] && $o['at'] === $s['at']), 'lane'))
  && $windowLoad[$s['region'] . '|' . $s['at']] > ($s['until'] - $s['at']) / 60 ? $windowLoad[$s['region'] . '|' . $s['at']] : null;
$range = fn(array $s): string => $fmt($s['at'], 'g:i', $viewerZone) . '–' . $fmt($s['until'], 'g:i a', $viewerZone);
$early = fn(array $s): bool => $fmt($s['at'], 'H:i', $viewerZone) < $plan['earliest'];
// 'work', 'work-overrun' (starts at work, ends after) or 'home'.
$place = function (array $s) use ($plan, $fmt, $viewerZone): string {
  $work = $plan['work'];
  if (!in_array((int)$fmt($s['at'], 'N', $viewerZone), $work['days'], true)) return 'home';
  $from = $fmt($s['at'], 'H:i', $viewerZone);
  if ($from < $work['from'] || $from >= $work['to']) return 'home';
  return $fmt($s['until'], 'H:i', $viewerZone) > $work['to'] ? 'work-overrun' : 'work';
};
$workEnd = date('g:i a', strtotime($plan['work']['to']));
$statusText = ['done' => 'Done', 'redo' => 'Redo: partial or capped', 'missed' => 'Missed', 'now' => 'Now', 'upcoming' => ''];

// Overall progress per week, for the plan table.
$weekDone = [];
foreach ($slots as $s) {
  foreach ($s['status'] as $st) {
    $weekDone[$s['week']]['total'] = ($weekDone[$s['week']]['total'] ?? 0) + 1;
    if ($st === 'done') $weekDone[$s['week']]['done'] = ($weekDone[$s['week']]['done'] ?? 0) + 1;
  }
}

// Realm-factions with no sweep on record: you probably need a character there.
$needed = [];
foreach ($assignments as $a) {
  foreach (['Horde', 'Alliance'] as $f) {
    $found = false;
    foreach (array_merge([$a['edition']], $plan['also_match'][$a['edition']] ?? []) as $source)
      $found = $found || isset($everSwept["$source|{$a['region']}|{$a['realm']}|$f"]);
    if (!$found) $needed[] = $a + ['faction' => $f];
  }
}
usort($needed, fn($x, $y) => $x['first'] <=> $y['first']);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Scan calendar · Local</title>
<script>
// Times are shown in this browser's time zone; the server reads it from a cookie.
(function () {
  var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
  if (tz && document.cookie.indexOf('tz=' + encodeURIComponent(tz)) === -1) {
    document.cookie = 'tz=' + encodeURIComponent(tz) + '; path=/; max-age=31536000; samesite=lax';
    if (<?= json_encode($viewerZoneName) ?> !== tz) location.reload();
  }
})();
</script>
<style>
:root{color-scheme:dark;font-family:system-ui,sans-serif;background:#111821;color:#e8edf3}
body{max-width:1100px;margin:0 auto;padding:20px}
h1{margin:10px 0 4px}h2{font-size:1.05rem;margin:28px 0 10px}
p{color:#afbdcc;line-height:1.45}a{color:#9ccff0}small,.muted{color:#afbdcc}
.sub{margin:0 0 16px}
.bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 18px}
.bar a,.bar span{display:inline-block;padding:7px 12px;border:1px solid #48617a;border-radius:7px;text-decoration:none;color:#c4d6e8}
.bar span{border-color:transparent;font-weight:700;color:#e8edf3}
.next{background:#253244;border:1px solid #7a9ac0;border-radius:9px;padding:16px;margin:0 0 20px}
.next h2{font-size:.82rem;text-transform:uppercase;letter-spacing:.06em;color:#a9c7e8;margin:0 0 8px}
.next strong{font-size:1.2rem}.next p{margin:6px 0 0}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin:0 0 6px}
.stat{background:#1c2935;border:1px solid #304255;border-radius:9px;padding:12px}
.stat b{display:block;font-size:1.35rem}
.day{margin:0 0 16px}.day h3{font-size:.95rem;margin:0 0 8px;color:#c4d6e8;border-bottom:1px solid #334251;padding-bottom:6px}
.slot{display:grid;grid-template-columns:150px 1fr auto;gap:12px;align-items:center;background:#1c2935;border:1px solid #304255;border-left:4px solid #48617a;border-radius:8px;padding:10px 12px;margin:0 0 6px}
.slot.us{border-left-color:#5b8fd1}.slot.eu{border-left-color:#c79a4a}.slot.calibration{border-style:dashed}
.slot.is-now{outline:2px solid #ffcf79}
.time b{display:block;font-size:1.05rem;white-space:nowrap}.time small{font-size:.78rem}
.what b{font-size:1rem}.what small{display:block;margin-top:2px}
.tag{display:inline-block;font-size:.72rem;font-weight:700;padding:1px 6px;border-radius:5px;background:#304255;color:#c4d6e8;margin-right:4px;vertical-align:1px}
.tag.us{background:#2b4566}.tag.eu{background:#5a4724}.tag.work{background:#2f4a3a;color:#bfe3cb}.tag.home{background:#3b3550;color:#d2c8ee}
.factions{display:flex;flex-direction:column;gap:4px;align-items:flex-end}
.f{font-size:.82rem;padding:3px 8px;border-radius:6px;border:1px solid #3c4f63;white-space:nowrap}
.f.Horde{color:#e2a2a2}.f.Alliance{color:#9fc8f2}
.f.done{background:#1f3b2b;border-color:#3f7a55}.f.done::after{content:" ✓";color:#a8dab7}
.f.redo{background:#3d3220;border-color:#8a6a2c}.f.redo::after{content:" redo";color:#ffcf79}
.f.missed{background:#3a2023;border-color:#7a3940}.f.missed::after{content:" missed";color:#f0a0a0}
.f.now{border-color:#ffcf79}.f.now::after{content:" now";color:#ffcf79}
.table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%}
th,td{padding:8px;text-align:left;border-bottom:1px solid #334251;white-space:nowrap;font-size:.9rem}th{color:#afbdcc}
tr.current td{background:#1f2c3a}
ul{padding-left:20px;color:#c4d6e8}li{margin:3px 0}
details{margin:22px 0;background:#1c2935;border:1px solid #304255;border-radius:9px;padding:14px}summary{cursor:pointer;font-weight:700}
@media (max-width:640px){.slot{grid-template-columns:1fr}.factions{grid-column:1/-1;flex-direction:row;justify-content:flex-start;flex-wrap:wrap}}
</style></head><body>
<a href="/">← Site home</a> · <a href="/stats.php">Scan balance</a>
<h1>Scan calendar</h1>
<p class="sub">Times in <?= h($viewerZoneName) ?> · realm time underneath · each slot is a window, sweep any time inside it · checked against PostgreSQL sweeps when the page loads</p>

<?php if ($next):
  $mins = (int)round(($next['at'] - $now) / 60);
  $left = (int)round(($next['until'] - $now) / 60);
  $when = $mins <= 0 ? "open now, $left min left" : ($mins < 120 ? "opens in $mins min" : $fmt($next['at'], 'D M j', $viewerZone) . ', ' . $range($next));
?>
<section class="next" aria-label="Next scan"><h2>Next scan · <?= h($when) ?></h2>
<strong><?= h($slotLabel($next)) ?></strong>
<p><?= h($next['kind'] === 'calibration' ? 'Calibration sweep, ' . $next['factions'][0] . ' only' : implode(' first, then ', $next['factions'])) ?>
 · <?= h($range($next)) ?> your time (<?= h($next['local_time']) ?> realm time) · <?= h($next['label']) ?></p>
<?php if ($next['note']): ?><p><small><?= h($next['note']) ?></small></p><?php endif; ?>
</section>
<?php endif; ?>

<nav class="bar" aria-label="Week">
<?php if ($week > 1): ?><a href="?week=<?= $week - 1 ?>">← Week <?= $week - 1 ?></a><?php endif; ?>
<span>Week <?= $week ?> of <?= $totalWeeks ?> · <?= h($weekStart->format('M j')) ?>–<?= h($weekStart->modify('+6 days')->format('M j')) ?></span>
<?php if ($week < $totalWeeks): ?><a href="?week=<?= $week + 1 ?>">Week <?= $week + 1 ?> →</a><?php endif; ?>
<?php if ($week !== $currentWeek && $currentWeek >= 1 && $currentWeek <= $totalWeeks): ?><a href="?">This week</a><?php endif; ?>
<a href="?ics=1">Add to calendar (.ics)</a>
</nav>

<div class="stats">
<div class="stat"><b><?= $doneCount ?> / <?= $sweepCount ?></b><small>clean sweeps this week</small></div>
<?php $atWork = array_sum(array_map(fn($s) => $place($s) === 'home' ? 0 : count($s['factions']), $weekSlots)); ?>
<div class="stat"><b>≈ <?= h(number_format($hours, 0)) ?> h</b><small>of sweeping this week · <?= $atWork ?> of <?= $sweepCount ?> sweeps at work</small></div>
<?php foreach (['us', 'eu'] as $region):
  $realms = array_unique(array_map(fn($s) => $editionShort[$s['edition']] . ' ' . $s['realm'], array_filter($weekSlots, fn($s) => $s['region'] === $region && $s['kind'] === 'scan')));
?>
<div class="stat"><small><?= strtoupper($region) ?> realms this week</small><br><?= h($realms ? implode(', ', $realms) : 'None') ?></div>
<?php endforeach; ?>
</div>

<?php foreach ($days as $day => $daySlots): ?>
<section class="day"><h3><?= h((new DateTimeImmutable($day))->format('l, M j')) ?></h3>
<?php foreach ($daySlots as $s): $isNow = in_array('now', $s['status'], true); ?>
<div class="slot <?= h($s['region']) ?> <?= h($s['kind']) ?><?= $isNow ? ' is-now' : '' ?>">
  <div class="time"><b><?= h($range($s)) ?></b><small><?= h($s['local_time']) ?> <?= h($fmt($s['at'], 'T', new DateTimeZone($plan['zones'][$s['region']]))) ?></small></div>
  <div class="what"><span class="tag <?= h($s['region']) ?>"><?= strtoupper($s['region']) ?></span><span class="tag"><?= h($editionShort[$s['edition']]) ?></span><?php $where = $place($s); ?><span class="tag <?= $where === 'home' ? 'home' : 'work' ?>"><?= $where === 'home' ? 'At home' : 'At work' ?></span><b><?= h($s['realm']) ?></b>
    <small><?= h($s['label']) ?><?= $s['lane'] !== null ? ($s['lane'] ? ' · second realm in this window' : ' · first realm in this window') : ' · one faction, any time in the hour' ?><?= $s['note'] ? ' · ' . h($s['note']) : '' ?><?php $load = $overloaded($s); ?><?= $load ? ' · ⚠ about ' . $load . ' min of sweeping for a ' . (int)(($s['until'] - $s['at']) / 60) . '-min window; the last realms may not fit' : '' ?><?= $where === 'work-overrun' ? ' · ⚠ window runs past ' . h($workEnd) . '; finish before you leave' : '' ?><?= $early($s) ? ' · ⚠ starts before ' . h(date('g:i a', strtotime($plan['earliest']))) . ' your time' : '' ?></small></div>
  <div class="factions"><?php foreach ($s['status'] as $f => $st): ?><span class="f <?= h($f) ?> <?= h($st) ?>" title="<?= h($statusText[$st]) ?>"><?= h($f) ?></span><?php endforeach; ?></div>
</div>
<?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php if (!$days): ?><p>Nothing scheduled this week.</p><?php endif; ?>

<h2>Whole plan</h2>
<div class="table-wrap"><table><thead><tr><th>Week</th><th>Starts</th>
<?php foreach ($plan['lanes'] as $region => $count) for ($l = 1; $l <= $count; $l++): ?><th><?= strtoupper($region) ?> lane <?= $l ?></th><?php endfor; ?>
<th>Clean</th></tr></thead><tbody>
<?php for ($w = 1; $w <= $totalWeeks; $w++): ?>
<tr<?= $w === $currentWeek ? ' class="current"' : '' ?>><td><a href="?week=<?= $w ?>"><?= $w ?></a></td><td><?= h($start->modify('+' . (($w - 1) * 7) . ' days')->format('M j')) ?></td>
<?php foreach ($plan['lanes'] as $region => $count) for ($l = 0; $l < $count; $l++):
  $cell = '—';
  foreach ($assignments as $a) if ($a['region'] === $region && $a['lane'] === $l && $w >= $a['first'] && $w < $a['first'] + $a['weeks']) $cell = $editionShort[$a['edition']] . ' ' . $a['realm'];
?><td><?= h($cell) ?></td><?php endfor; ?>
<td><?= (int)($weekDone[$w]['done'] ?? 0) ?> / <?= (int)($weekDone[$w]['total'] ?? 0) ?></td></tr>
<?php endfor; ?>
</tbody></table></div>

<h2>Characters needed</h2>
<?php if ($needed): ?>
<p>No sweep on record for these realm-factions, so you probably need a character there before its week starts.</p>
<ul><?php foreach ($needed as $n): ?>
<li><b>Week <?= $n['first'] ?></b> · <?= h($editionNames[$n['edition']]) ?> · <?= strtoupper($n['region']) ?> <?= h($n['realm']) ?> · <?= h($n['faction']) ?></li>
<?php endforeach; ?></ul>
<?php else: ?><p>Every scheduled realm-faction has been swept before.</p><?php endif; ?>

<details><summary>Rules for a sweep that counts</summary>
<ul>
<li>Each slot is a window: run the sweep any time inside it. Leave the game open and tap your census key (<code>/ml census next</code> in a macro) when you have a moment. The addon needs one keypress per query; never automate the presses.</li>
<li>Sweep both factions of a realm back to back, in the order shown (it alternates so neither faction is always second). In a window with two realms, do the first one first.</li>
<li>The sweep must finish as <b>complete</b> with <b>no capped queries</b>. Split capped queries by level, then class, then race. A partial or capped sweep shows as “redo”.</li>
<li>Once a sweep starts, finish it within about 30 minutes so it is a snapshot. Don't start one you can't finish; a sweep spread over hours counts for less.</li>
<li>Calibration days: one faction, one sweep in each hour shown. Skipping some weakens the calibration but doesn't spoil it.</li>
<li>Missed a window? Don't make it up at a different time; a sweep at the wrong time skews the estimate. Just take the next one.</li>
</ul>
<p><small>Plan file: <code>laragon-php/src/scan-plan.php</code>. Change lanes, slot times or the realm order there; the calendar rebuilds on every load.</small></p>
</details>
</body></html>
