<?php
// Collection activity: 30 days of AH scans and /who commands per realm.
// Local stats.php queries PostgreSQL; the origin's /admin/ reads the copy the
// capture publishes as the `admin:collection-activity` model.
declare(strict_types=1);

require_once __DIR__ . '/CollectionWhoHeatmap.php';

const COLLECTION_DAYS = 30;

function cs_region_choices(): array { return ['both'=>'All regions', 'us'=>'US', 'eu'=>'EU', 'other'=>'Other / unknown']; }
function cs_visible_regions(string $region): array {
  return match ($region) {
    'us' => ['us'], 'eu' => ['eu'],
    'other' => ['kr', 'tw', 'cn', 'unknown'],
    default => ['us', 'eu', 'kr', 'tw', 'cn', 'unknown'],
  };
}
function cs_region_label(string $region): string { return $region === 'unknown' ? 'Unknown' : strtoupper($region); }

function cs_h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function cs_realm_name(string $game, string $edition = ''): string {
  $name = preg_replace('/^realm:(?:(?:eu|us|kr|tw|cn):[^:]+:)?/', '', $game);
  $name = preg_replace('/-(?:Alliance|Horde)$/', '', $name);
  if ($edition === 'forever' && preg_match('/^CAMWOW\s+(?:US|EU)\d+\s+Realm\s+\d+\s+(RP PvP|PvP|PvE|RP)$/i', $name, $m)) {
    return strcasecmp($m[1], 'RP PvP') === 0 ? 'RP-PvP' : (strcasecmp($m[1], 'PvE') === 0 ? 'PvE' : (strcasecmp($m[1], 'PvP') === 0 ? 'PvP' : 'RP'));
  }
  return in_array($edition, ['forever', 'classic-beta'], true)
    ? preg_replace('/^CAMWOW\s+/i', '', $name)
    : $name;
}

function cs_realm_faction(string $game): string {
  return preg_match('/-(Alliance|Horde)$/', $game, $m) ? strtolower($m[1]) : 'unknown';
}

/** Live from PostgreSQL: ['generated_at' => unix, 'rows' => [...]]. */
function collection_activity_query(): array {
  $cutoff = strtotime('today UTC') - (COLLECTION_DAYS - 1) * 86400;
  // A /who command is one recorded query. One AH scan is one distinct
  // capture timestamp per source game and realm, regardless of item count.
  $sql = <<<'SQL'
WITH labelled_regions AS (
  SELECT source_game,
    regexp_replace(regexp_replace(game, '^realm:[a-z]{2}:[^:]+:', ''), '-(Alliance|Horde|Neutral)$', '') AS realm,
    min(split_part(game, ':', 2)) AS region
  FROM marketlens_import.datasets
  WHERE game ~ '^realm:(us|eu|kr|tw|cn):[^:]+:'
  GROUP BY source_game, realm
  HAVING count(DISTINCT split_part(game, ':', 2)) = 1
), activity AS (
  SELECT q.source_game, q.game,
    COALESCE(marketlens_import.collection_region_at(q.game, q.observed_at), lr.region, 'unknown') AS region,
    to_char(to_timestamp(q.observed_at) AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS day,
    CASE WHEN q.game LIKE '%-Alliance' THEN 'alliance'
         WHEN q.game LIKE '%-Horde' THEN 'horde'
         ELSE 'unknown' END AS kind, count(*) AS total
  FROM marketlens_import.population_sweep_queries q
  LEFT JOIN labelled_regions lr ON lr.source_game = q.source_game
    AND lr.realm = regexp_replace(substr(q.game, 7), '-(Alliance|Horde|Neutral)$', '')
    AND NOT (q.source_game = 'retail' AND lr.realm IN (SELECT jsonb_array_elements_text(CAST(:who_shared AS jsonb))))
  WHERE q.observed_at >= :who_cutoff
  GROUP BY 1, 2, 3, 4, 5
  UNION ALL
  SELECT a.source_game, a.game,
    COALESCE(marketlens_import.collection_region_at(a.game, a.captured_at), lr.region, 'unknown') AS region,
    to_char(to_timestamp(a.captured_at) AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS day,
    'ah' AS kind, count(DISTINCT a.captured_at) AS total
  FROM marketlens_import.local_auction_snapshots a
  LEFT JOIN labelled_regions lr ON lr.source_game = a.source_game
    AND lr.realm = regexp_replace(substr(a.game, 7), '-(Alliance|Horde|Neutral)$', '')
    AND NOT (a.source_game = 'retail' AND lr.realm IN (SELECT jsonb_array_elements_text(CAST(:ah_shared AS jsonb))))
  WHERE a.captured_at >= :ah_cutoff
  GROUP BY 1, 2, 3, 4
)
SELECT source_game, game, region, day, kind, sum(total) AS total
FROM activity GROUP BY source_game, game, region, day, kind
ORDER BY source_game, region, game, day
SQL;
  $stmt = Db::pdo()->prepare($sql);
  $shared = cs_shared_retail_realms_json();
  $stmt->execute(['who_cutoff' => $cutoff, 'ah_cutoff' => $cutoff, 'who_shared' => $shared, 'ah_shared' => $shared]);
  return ['generated_at' => time(), 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'sweep_peaks' => collection_sweep_peaks_query($cutoff, $shared)];
}

/**
 * The largest sweep (finished or not) per realm faction in the window: characters it
 * saw and the /who commands it took. Population is time-of-day dependent, so
 * the peak is the closest thing to "how big is this realm".
 */
function collection_sweep_peaks_query(int $cutoff, string $shared): array {
  $sql = <<<'SQL'
WITH labelled_regions AS (
  SELECT source_game,
    regexp_replace(regexp_replace(game, '^realm:[a-z]{2}:[^:]+:', ''), '-(Alliance|Horde|Neutral)$', '') AS realm,
    min(split_part(game, ':', 2)) AS region
  FROM marketlens_import.datasets
  WHERE game ~ '^realm:(us|eu|kr|tw|cn):[^:]+:'
  GROUP BY source_game, realm
  HAVING count(DISTINCT split_part(game, ':', 2)) = 1
), sweeps AS (
  SELECT s.source_game, s.game,
    COALESCE(marketlens_import.collection_region_at(s.game, s.started_at), lr.region, 'unknown') AS region,
    s.character_count, s.query_count, s.started_at
  FROM marketlens_import.population_sweeps s
  LEFT JOIN labelled_regions lr ON lr.source_game = s.source_game
    AND lr.realm = regexp_replace(substr(s.game, 7), '-(Alliance|Horde|Neutral)$', '')
    AND NOT (s.source_game = 'retail' AND lr.realm IN (SELECT jsonb_array_elements_text(CAST(:shared AS jsonb))))
  -- Forever sweeps are rarely finalized, so an unfinished ('active') sweep
  -- counts too: each row is still one sweep run.
  WHERE s.started_at >= :cutoff AND s.status IN ('complete', 'partial', 'active') AND s.character_count > 0
)
SELECT DISTINCT ON (source_game, game, region) source_game, game, region,
  character_count AS characters, query_count AS queries, started_at
FROM sweeps
ORDER BY source_game, game, region, character_count DESC, started_at DESC
SQL;
  $stmt = Db::pdo()->prepare($sql);
  $stmt->execute(['cutoff' => $cutoff, 'shared' => $shared]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function cs_sort_choices(): array { return ['name'=>'Realm', 'population'=>'Observed population', 'commands'=>'/who per sweep']; }

/** The published copy on the origin, live data locally. A capture writes it out. */
function collection_activity_model(): array {
  if (getenv('ML_QUERY_CACHE') === '1') return Db::model('admin:collection-activity');
  $model = collection_activity_query();
  $model['who_hours'] = who_heatmap_query();
  if ($dir = getenv('ML_CAPTURE_DIR')) {
    if (!is_dir($dir . '/models')) mkdir($dir . '/models', 0700, true);
    file_put_contents($dir . '/models/admin-collection-activity.json', json_encode($model, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
  }
  return $model;
}

/**
 * The full status page. $self is the page's own path (region links append
 * ?region=); $chrome is extra HTML placed above the heading (nav, logout).
 * The 30-day window ends on the snapshot's day, so an older published
 * snapshot still lines up with its own data.
 */
function render_collection_stats(array $snapshot, string $region, string $self, string $chrome, string $note): string {
  if (!isset(cs_region_choices()[$region])) $region = 'both';
  $sort = (string)($_GET['sort'] ?? 'name');
  if (!isset(cs_sort_choices()[$sort])) $sort = 'name';
  $visible = cs_visible_regions($region);
  $editionNames = [
    'forever' => 'WoW: Forever', 'classic-beta' => 'WoW: Forever (Beta)', 'retail' => 'Retail',
    'classic-progression' => 'TBC Anniversary', 'classic' => 'Classic Era',
    'mop-classic' => 'MoP Classic', 'sod' => 'Season of Discovery', 'hardcore' => 'Hardcore',
  ];
  $end = strtotime(gmdate('Y-m-d', (int)$snapshot['generated_at']) . ' UTC');
  $days = [];
  for ($i = COLLECTION_DAYS - 1; $i >= 0; --$i) $days[] = gmdate('Y-m-d', $end - $i * 86400);
  $groups = [];
  foreach ($snapshot['rows'] as $row) {
    $edition = $row['source_game'];
    if (!isset($editionNames[$edition]) || !in_array($row['region'], $visible, true)) continue;
    $key = $row['region'] . '|' . cs_realm_name($row['game'], $edition);
    $kind = $row['kind'];
    $groups[$edition][$key]['region'] = $row['region'];
    $groups[$edition][$key]['realm'] = cs_realm_name($row['game'], $edition);
    $groups[$edition][$key]['days'][$row['day']][$kind] =
      ($groups[$edition][$key]['days'][$row['day']][$kind] ?? 0) + (int)$row['total'];
  }
  // Forever's collector realm numbers are random. Keep the largest sweep for
  // each faction across those numbers, then combine Alliance and Horde.
  $foreverPeaks = [];
  foreach ($snapshot['sweep_peaks'] ?? [] as $row) {
    $edition = $row['source_game'];
    if (!isset($editionNames[$edition]) || !in_array($row['region'], $visible, true)) continue;
    $key = $row['region'] . '|' . cs_realm_name($row['game'], $edition);
    $realm = &$groups[$edition][$key];
    $realm['region'] = $row['region'];
    $realm['realm'] = cs_realm_name($row['game'], $edition);
    $realm['days'] ??= [];
    if ($edition === 'forever') {
      $faction = cs_realm_faction($row['game']);
      $peak = &$foreverPeaks[$key][$faction];
      if (!isset($peak) || (int)$row['characters'] > (int)$peak['characters'])
        $peak = ['characters' => (int)$row['characters'], 'queries' => (int)$row['queries']];
      unset($peak);
    } else {
      $realm['peak_characters'] = ($realm['peak_characters'] ?? 0) + (int)$row['characters'];
      $realm['peak_queries'] = ($realm['peak_queries'] ?? 0) + (int)$row['queries'];
    }
    unset($realm);
  }
  foreach ($foreverPeaks as $key => $factions) {
    $groups['forever'][$key]['peak_characters'] = array_sum(array_column($factions, 'characters'));
    $groups['forever'][$key]['peak_queries'] = array_sum(array_column($factions, 'queries'));
  }
  $sortQuery = $sort === 'name' ? '' : '&amp;sort=' . cs_h($sort);
  ob_start();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Collection status · MarketLens</title>
<style>
:root{color-scheme:dark;font-family:system-ui,sans-serif;background:#101720;color:#edf3fa}
*{box-sizing:border-box}body{max-width:1400px;margin:auto;padding:28px 24px 64px}
a{color:#a9d4ff}.back{font-size:.88rem;text-decoration:none}.eyebrow{margin:26px 0 5px;color:#8ea3b9;text-transform:uppercase;letter-spacing:.14em;font-size:.72rem;font-weight:700}
.view-nav{display:flex;gap:12px;margin:12px 0 22px}.view-nav a{font-size:.85rem;text-decoration:none;padding:7px 12px;border:1px solid #344759;border-radius:7px}.view-nav a[aria-current=page]{background:#456e96;color:white}
.chrome{display:flex;justify-content:space-between;align-items:center;gap:12px}.chrome form{margin:0}.chrome button{font:inherit;font-size:.85rem;background:#1b2936;color:#b9cbdc;border:1px solid #344759;border-radius:7px;padding:6px 12px;cursor:pointer}.chrome button:hover{color:white;border-color:#456e96}
h1{font-size:clamp(1.8rem,4vw,2.8rem);margin:0 0 8px;letter-spacing:-.035em}
.intro{color:#a8b9ca;line-height:1.5;margin:0 0 22px}.toolbar{display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.regions{display:flex;flex-wrap:wrap;gap:5px;padding:4px;background:#1b2936;border:1px solid #344759;border-radius:9px}.regions a{padding:7px 15px;border-radius:6px;text-decoration:none;color:#b9cbdc;font-size:.85rem}.regions a[aria-current=page]{background:#456e96;color:white;font-weight:700}
.legend{display:flex;gap:18px;color:#b9cbdc;font-size:.82rem}.legend span{display:inline-flex;align-items:center;gap:7px}.swatch{width:11px;height:11px;border-radius:2px;background:#a7d9f2}.swatch.alliance{background:#477df5}.swatch.horde{background:#d65f64}
.summary-strip{display:flex;gap:22px;flex-wrap:wrap;background:#1a2734;border:1px solid #344759;border-radius:11px;padding:14px 18px;margin-bottom:22px}.summary-strip strong{font-size:1.12rem}.summary-strip small{display:block;color:#9db0c2;margin-top:2px}
.edition{border:1px solid #344759;border-radius:11px;background:#192632;margin:12px 0;overflow:hidden}.edition>summary{cursor:pointer;list-style:none;display:flex;align-items:center;gap:13px;padding:18px 20px}.edition>summary::-webkit-details-marker{display:none}
.chevron{color:#96afc5;transition:transform .2s}.edition[open] .chevron{transform:rotate(90deg)}.edition-name{font-weight:750;font-size:1.05rem}.edition-meta{color:#99aec2;font-size:.83rem;margin-left:auto;text-align:right}
.history-wrap{overflow-x:auto;border-top:1px solid #344759}.history{min-width:890px;padding:15px 18px 20px}.history-head,.realm-row{display:grid;grid-template-columns:190px minmax(0,1fr) 98px 92px;gap:14px;align-items:center}
.history-head{font-size:.75rem;color:#8fa5ba;padding-bottom:12px}.realm-row{min-height:45px;border-top:1px solid #2b3b4b}.realm-name{font-size:.86rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.realm-name small{display:inline-block;margin-right:7px;color:#91aac0;font-size:.7rem}
.track{display:grid;grid-template-columns:repeat(30,minmax(0,1fr));gap:3px;height:28px;align-items:end}.day{height:100%;background:#263646;border-radius:2px;display:flex;flex-direction:column;justify-content:flex-end;overflow:hidden}.day.active{background:#34495c}.segment{display:block;width:100%;min-height:2px}.segment.ah{background:#a7d9f2}.segment.alliance{background:#477df5}.segment.horde{background:#d65f64}.segment.unknown{background:#9b9ba9}
.totals.peak strong{color:#edf3fa}.totals{text-align:right;font-size:.75rem;color:#b0c2d1;white-space:nowrap}.axis{display:flex;justify-content:space-between;color:#8399ae;font-size:.72rem;margin-top:10px}.empty{color:#91a9bc;padding:14px 0}
.note{font-size:.8rem;color:#91a7bb;line-height:1.5;margin-top:22px}
@media(max-width:650px){body{padding:18px 14px 48px}.edition>summary{padding:15px 14px}.edition-meta{font-size:.73rem}.history{padding:12px 14px}.legend{width:100%}}
</style></head><body>
<?= $chrome ?>
<p class="eyebrow">Collection activity</p><h1>Realm collection history</h1>
<p class="intro">A 30-day view of recorded auction house scans and /who commands by realm. Each column is one UTC day. Unknown means the saved scan did not identify its region.</p>
<div class="toolbar"><nav class="regions" aria-label="Collection region">
<?php foreach (cs_region_choices() as $choice=>$label): ?>
<a href="<?= cs_h($self) ?>?region=<?= cs_h($choice) ?><?= $sortQuery ?>"<?= $region === $choice ? ' aria-current="page"' : '' ?>><?= cs_h($label) ?></a>
<?php endforeach; ?></nav>
<nav class="regions" aria-label="Sort realms"><?php foreach (cs_sort_choices() as $choice=>$label): ?>
<a href="<?= cs_h($self) ?>?region=<?= cs_h($region) ?><?= $choice === 'name' ? '' : '&amp;sort=' . cs_h($choice) ?>"<?= $sort === $choice ? ' aria-current="page"' : '' ?>><?= cs_h($label) ?></a>
<?php endforeach; ?></nav>
<div class="legend"><span><i class="swatch"></i> AH scans</span><span><i class="swatch alliance"></i> Alliance /who</span><span><i class="swatch horde"></i> Horde /who</span></div></div>
<?php
  $realmCount = 0; $ahTotal = 0; $whoTotal = 0;
  foreach ($groups as $realms) foreach ($realms as $realm) {
    ++$realmCount;
    foreach ($realm['days'] as $counts) { $ahTotal += $counts['ah'] ?? 0; $whoTotal += ($counts['alliance'] ?? 0) + ($counts['horde'] ?? 0) + ($counts['unknown'] ?? 0); }
  }
?>
<div class="summary-strip"><div><strong><?= number_format($realmCount) ?></strong><small>realms / groups with activity</small></div><div><strong><?= number_format($ahTotal) ?></strong><small>AH scans</small></div><div><strong><?= number_format($whoTotal) ?></strong><small>/who commands</small></div></div>
<?php foreach ($editionNames as $edition=>$name):
  $realms = $groups[$edition] ?? [];
  $byName = $edition === 'forever'
    ? function ($a, $b) {
        $regions = ['us' => 0, 'eu' => 1]; $types = ['PvE' => 0, 'PvP' => 1, 'RP' => 2, 'RP-PvP' => 3];
        return [($regions[$a['region']] ?? 9), ($types[$a['realm']] ?? 9), $a['realm']]
          <=> [($regions[$b['region']] ?? 9), ($types[$b['realm']] ?? 9), $b['realm']];
      }
    : fn($a,$b) => [$a['region'],$a['realm']] <=> [$b['region'],$b['realm']];
  $metric = ['population'=>'peak_characters', 'commands'=>'peak_queries'][$sort] ?? null;
  uasort($realms, $metric
    ? fn($a,$b) => [isset($b[$metric]) ? 1 : 0, $b[$metric] ?? 0] <=> [isset($a[$metric]) ? 1 : 0, $a[$metric] ?? 0] ?: $byName($a,$b)
    : $byName);
  $activeDays = [];
  foreach ($realms as $realm) foreach ($realm['days'] as $day=>$counts) $activeDays[$day] = true;
?>
<details class="edition"><summary><span class="chevron" aria-hidden="true">▶</span><span class="edition-name"><?= cs_h($name) ?></span><span class="edition-meta"><?= count($realms) ?> <?= $edition === 'forever' ? 'region/type groups' : 'realms' ?> · <?= count($activeDays) ?> active days</span></summary>
<div class="history-wrap"><div class="history">
<?php if (!$realms): ?><div class="empty">No recorded scans in this 30-day window.</div><?php else: ?>
<div class="history-head"><span><?= $edition === 'forever' ? 'Region · type' : 'Region · realm' ?></span><span>Daily activity</span><span style="text-align:right">30-day total</span><span style="text-align:right">Peak sweep</span></div>
<?php foreach ($realms as $realm):
  $realmAh = 0; $realmWho = 0;
  foreach ($realm['days'] as $counts) { $realmAh += $counts['ah'] ?? 0; $realmWho += ($counts['alliance'] ?? 0) + ($counts['horde'] ?? 0) + ($counts['unknown'] ?? 0); }
?>
<div class="realm-row"><div class="realm-name" title="<?= cs_h(cs_region_label($realm['region']) . ' · ' . $realm['realm']) ?>"><small><?= cs_h(cs_region_label($realm['region'])) ?></small><?= cs_h($realm['realm']) ?></div>
<div class="track" role="group" aria-label="<?= cs_h($realm['realm']) ?> daily scan activity">
<?php foreach ($days as $day):
  $ah = (int)($realm['days'][$day]['ah'] ?? 0);
  $alliance = (int)($realm['days'][$day]['alliance'] ?? 0);
  $horde = (int)($realm['days'][$day]['horde'] ?? 0);
  $unknown = (int)($realm['days'][$day]['unknown'] ?? 0);
  $total = $ah + $alliance + $horde + $unknown;
  $height = $total ? min(100, 25 + 18 * log(1 + $total)) : 0;
  $label = "$day: $ah AH scans, $alliance Alliance /who, $horde Horde /who" . ($unknown ? ", $unknown unclassified /who" : '');
?>
<div class="day<?= $total ? ' active' : '' ?>" title="<?= cs_h($label) ?>" aria-label="<?= cs_h($label) ?>">
<?php foreach (['unknown'=>$unknown, 'horde'=>$horde, 'alliance'=>$alliance, 'ah'=>$ah] as $kind=>$count): if ($count): ?>
<span class="segment <?= cs_h($kind) ?>" style="height:<?= cs_h(round($height * $count / $total, 1)) ?>%"></span>
<?php endif; endforeach; ?>
</div><?php endforeach; ?></div><div class="totals"><?= number_format($realmAh) ?> AH · <?= number_format($realmWho) ?> who</div><div class="totals peak"<?= isset($realm['peak_characters']) ? ' title="Largest sweep per faction in 30 days, Alliance + Horde"' : '' ?>><?php if (isset($realm['peak_characters'])): ?><strong><?= number_format($realm['peak_characters']) ?></strong> seen<br><?= number_format($realm['peak_queries']) ?> /who<?php else: ?>—<?php endif; ?></div></div>
<?php endforeach; ?>
<div class="history-head" style="padding-top:12px;padding-bottom:0"><span></span><div class="axis"><span><?= cs_h(gmdate('M j', strtotime($days[0]))) ?></span><span><?= cs_h(gmdate('M j', strtotime($days[14]))) ?></span><span><?= cs_h(gmdate('M j', strtotime($days[29]))) ?></span></div><span></span><span></span></div>
<?php endif; ?></div></div></details>
<?php endforeach; ?>
<p class="note">Updated <?= cs_h(gmdate('M j, Y g:i a', (int)$snapshot['generated_at'])) ?> UTC · <?= cs_h($note) ?> AH scans count distinct capture times, not item rows. /who counts recorded commands, not characters or population. Peak sweep is the largest sweep per faction in the window (Alliance + Horde): characters seen and the /who commands that sweep took. Forever groups combine command activity across random collector realm numbers and use the largest sweep per faction. Empty days mean no recorded activity; they do not indicate server outages.</p>
</body></html>
<?php
  return (string)ob_get_clean();
}
