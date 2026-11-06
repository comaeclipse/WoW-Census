<?php
// Local-only hourly view of recorded /who commands.
declare(strict_types=1);

/**
 * Retail realm names that exist in both US and EU, as a JSON array. A
 * region-less key on one of these cannot take its region from a same-named
 * labelled dataset: US Tichondrius was shown as EU because the only labelled
 * Tichondrius scans were the German realm's.
 */
function cs_shared_retail_realms_json(): string {
  static $json = null;
  if ($json === null) {
    $data = json_decode((string)file_get_contents(__DIR__ . '/retail-connected-realms.json'), true, 512, JSON_THROW_ON_ERROR);
    $names = [];
    foreach (['us', 'eu'] as $region)
      foreach ($data[$region] as $group)
        foreach ($group['realms'] as $realm) $names[$region][$realm['name']] = true;
    $json = json_encode(array_keys(array_intersect_key($names['us'], $names['eu'])), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  }
  return $json;
}

function who_heatmap_query(): array {
  $zone = new DateTimeZone('America/Chicago');
  // Include a margin for EU hours that fall on the previous Pensacola day.
  $start = (new DateTimeImmutable('today', $zone))->modify('-31 days');
  $sql = <<<'SQL'
WITH labelled_regions AS (
  SELECT source_game,
    regexp_replace(regexp_replace(game, '^realm:[a-z]{2}:[^:]+:', ''), '-(Alliance|Horde|Neutral)$', '') AS realm,
    min(split_part(game, ':', 2)) AS region
  FROM marketlens_import.datasets
  WHERE game ~ '^realm:(us|eu|kr|tw|cn):[^:]+:'
  GROUP BY source_game, realm
  HAVING count(DISTINCT split_part(game, ':', 2)) = 1
), commands AS (
  SELECT q.source_game, q.game, q.observed_at,
    COALESCE(marketlens_import.collection_region_at(q.game, q.observed_at), lr.region, 'unknown') AS region
  FROM marketlens_import.population_sweep_queries q
  LEFT JOIN labelled_regions lr ON lr.source_game = q.source_game
    AND lr.realm = regexp_replace(substr(q.game, 7), '-(Alliance|Horde|Neutral)$', '')
    AND NOT (q.source_game = 'retail' AND lr.realm IN (SELECT jsonb_array_elements_text(CAST(:shared AS jsonb))))
  WHERE q.observed_at >= :cutoff
), bucketed AS (
  SELECT source_game, game, region,
    to_timestamp(observed_at) AT TIME ZONE 'America/Chicago' AS local_time,
    to_timestamp(observed_at) AT TIME ZONE
      CASE region WHEN 'us' THEN 'America/New_York' WHEN 'eu' THEN 'Europe/Paris' ELSE 'America/Chicago' END AS realm_time
  FROM commands
)
SELECT source_game, game, region,
  to_char(local_time, 'YYYY-MM-DD') AS local_day, EXTRACT(HOUR FROM local_time)::int AS local_hour,
  to_char(realm_time, 'YYYY-MM-DD') AS realm_day, EXTRACT(HOUR FROM realm_time)::int AS realm_hour,
  count(*) AS total
FROM bucketed
GROUP BY source_game, game, region, local_day, local_hour, realm_day, realm_hour
ORDER BY source_game, region, game, local_day, local_hour
SQL;
  $stmt = Db::pdo()->prepare($sql);
  $stmt->execute(['cutoff' => $start->getTimestamp(), 'shared' => cs_shared_retail_realms_json()]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function who_heatmap_grid(array $days, array $hours, string $zoneLabel, string $name): string {
  ob_start(); ?>
  <div class="heat-scroll"><div class="heat-grid" role="img" aria-label="Hourly /who commands for <?= cs_h($name) ?>, last 30 days in <?= cs_h($zoneLabel) ?>">
  <div class="heat-corner">Hour</div>
  <?php foreach ($days as $day): ?><div class="heat-date" title="<?= cs_h($day) ?>"><?= cs_h((new DateTimeImmutable($day))->format('M j')) ?></div><?php endforeach; ?>
  <?php for ($hour = 0; $hour < 24; ++$hour): ?>
    <div class="heat-hour"><?= sprintf('%02d:00', $hour) ?></div>
    <?php foreach ($days as $day):
      $count = (int)($hours[$day][$hour] ?? 0);
      $level = $count === 0 ? 0 : ($count < 3 ? 1 : ($count < 7 ? 2 : ($count < 15 ? 3 : 4)));
      $labelText = sprintf('%s %02d:00–%02d:00 %s: %d /who command%s', $day, $hour, $hour + 1, $zoneLabel, $count, $count === 1 ? '' : 's');
    ?><span class="heat-cell level-<?= $level ?>" title="<?= cs_h($labelText) ?>" aria-label="<?= cs_h($labelText) ?>"></span><?php endforeach; ?>
  <?php endfor; ?></div></div>
  <?php return (string)ob_get_clean();
}

function render_who_heatmap(array $snapshot, string $region, string $timeMode = 'local', string $self = '/stats.php'): string {
  if (!isset(cs_region_choices()[$region])) $region = 'both';
  $timeMode = $timeMode === 'realm' ? 'realm' : 'local';
  $names = ['forever'=>'WoW: Forever', 'classic-beta'=>'WoW: Forever (Beta)', 'retail'=>'Retail', 'classic-progression'=>'TBC Anniversary', 'classic'=>'Classic Era', 'mop-classic'=>'MoP Classic', 'sod'=>'Season of Discovery', 'hardcore'=>'Hardcore'];
  $groups = [];
  foreach ($snapshot['who_hours'] ?? [] as $row) {
    if (!isset($names[$row['source_game']]) || !in_array($row['region'], cs_visible_regions($region), true)) continue;
    $key = $row['region'] . '|' . cs_realm_name($row['game']);
    $groups[$row['source_game']][$key]['name'] = cs_realm_name($row['game']);
    $groups[$row['source_game']][$key]['region'] = $row['region'];
    $day = $row[$timeMode . '_day'];
    $hour = (int)$row[$timeMode . '_hour'];
    $groups[$row['source_game']][$key]['hours'][$day][$hour] =
      ($groups[$row['source_game']][$key]['hours'][$day][$hour] ?? 0) + (int)$row['total'];
  }
  ob_start();
  ?>
  <h1>/who command times</h1>
  <p class="intro">Each column is one day; each row is an hour from midnight through 11 pm. A square counts recorded /who commands during that hour. The 24:00 boundary is the next day’s 00:00 row.</p>
  <nav class="heat-time-toggle" aria-label="Heatmap time zone">
    <a href="<?= cs_h($self) ?>?view=who-hours&amp;region=<?= cs_h($region) ?>&amp;time=local"<?= $timeMode === 'local' ? ' aria-current="page"' : '' ?>>My time · Pensacola</a>
    <a href="<?= cs_h($self) ?>?view=who-hours&amp;region=<?= cs_h($region) ?>&amp;time=realm"<?= $timeMode === 'realm' ? ' aria-current="page"' : '' ?>>Realm time · US Eastern / EU Paris</a>
  </nav>
  <div class="heat-legend" aria-label="Hourly /who command frequency"><span>Commands per hour</span>
    <?php foreach (['0', '1–2', '3–6', '7–14', '15+'] as $level=>$range): ?>
      <span class="heat-legend-item"><i class="heat-cell level-<?= $level ?>" aria-hidden="true"></i><?= $range ?></span>
    <?php endforeach; ?>
  </div>
  <?php foreach ($names as $edition=>$label):
    $realms = $groups[$edition] ?? [];
    uasort($realms, fn($a, $b) => [$a['region'], $a['name']] <=> [$b['region'], $b['name']]);
  ?>
  <details class="edition heatmap-edition"<?= $edition === 'classic-beta' ? ' open' : '' ?>><summary><span class="chevron" aria-hidden="true">▶</span><span class="edition-name"><?= cs_h($label) ?></span><span class="edition-meta"><?= count($realms) ?> realms</span></summary>
  <?php if ($realms): ?><div class="heat-controls"><label class="heat-selector">Show <select aria-label="Choose realm for <?= cs_h($label) ?> heatmap"><option value="all">All realms</option><option value="merged">All realms merged</option>
  <?php $realmIndex = 0; foreach ($realms as $realm): ?><option value="<?= $realmIndex++ ?>"><?= cs_h(strtoupper($realm['region']) . ' · ' . $realm['name']) ?></option><?php endforeach; ?>
  </select></label><div class="heat-region-pills" role="group" aria-label="Merge region for <?= cs_h($label) ?>">
  <?php foreach (['all'=>'All', 'us'=>'US', 'eu'=>'EU'] as $mergeRegion=>$mergeLabel):
    $hasRegion = $mergeRegion === 'all' || in_array($mergeRegion, array_column($realms, 'region'), true);
  ?><button type="button" data-merge-region="<?= $mergeRegion ?>" aria-pressed="<?= $mergeRegion === 'all' ? 'true' : 'false' ?>"<?= $hasRegion ? '' : ' disabled' ?>><?= $mergeLabel ?></button><?php endforeach; ?>
  </div></div><?php endif; ?>
  <div class="heat-edition">
  <?php if (!$realms): ?><div class="empty">No recorded /who commands in this 30-day window.</div><?php endif; ?>
  <?php foreach (['all'=>'All realms', 'us'=>'US realms', 'eu'=>'EU realms'] as $mergeRegion=>$mergeName):
    $mergeRealms = array_filter($realms, fn($realm) => $mergeRegion === 'all' || $realm['region'] === $mergeRegion);
    if (!$mergeRealms) continue;
    $mergedHours = []; $endDay = ''; $zones = [];
    foreach ($mergeRealms as $realm) {
      $realmZone = $timeMode === 'local' ? 'America/Chicago' : ($realm['region'] === 'eu' ? 'Europe/Paris' : 'America/New_York');
      $zones[$realmZone] = true;
      $realmToday = (new DateTimeImmutable('@' . (int)$snapshot['generated_at']))->setTimezone(new DateTimeZone($realmZone))->format('Y-m-d');
      if ($realmToday > $endDay) $endDay = $realmToday;
      foreach ($realm['hours'] as $day=>$hours) foreach ($hours as $hour=>$count)
        $mergedHours[$day][$hour] = ($mergedHours[$day][$hour] ?? 0) + $count;
    }
    $mergedDays = []; $mergedEnd = new DateTimeImmutable($endDay, new DateTimeZone('UTC'));
    for ($i = 29; $i >= 0; --$i) $mergedDays[] = $mergedEnd->modify("-$i days")->format('Y-m-d');
    $mergedTotal = 0; foreach ($mergedDays as $day) $mergedTotal += array_sum($mergedHours[$day] ?? []);
    $mergedZoneLabel = implode(' / ', array_keys($zones));
  ?>
  <section class="heat-realm heat-merged" data-merge-region="<?= $mergeRegion ?>" hidden><h2><?= cs_h($mergeName) ?> merged <small><?= cs_h($mergedZoneLabel) ?> · <?= number_format($mergedTotal) ?> commands</small></h2>
    <?= who_heatmap_grid($mergedDays, $mergedHours, $mergedZoneLabel, $label . ' · ' . $mergeName . ' merged') ?>
  </section>
  <?php endforeach; ?>
  <?php $realmIndex = 0; foreach ($realms as $realm):
    $zoneName = $timeMode === 'local' ? 'America/Chicago' : ($realm['region'] === 'eu' ? 'Europe/Paris' : 'America/New_York');
    $zone = new DateTimeZone($zoneName);
    $today = (new DateTimeImmutable('@' . (int)$snapshot['generated_at']))->setTimezone($zone)->setTime(0, 0);
    $days = [];
    for ($i = 29; $i >= 0; --$i) $days[] = $today->modify("-$i days")->format('Y-m-d');
    $total = 0; foreach ($days as $day) $total += array_sum($realm['hours'][$day] ?? []);
  ?>
  <section class="heat-realm" data-realm="<?= $realmIndex++ ?>"><h2><?= cs_h(strtoupper($realm['region']) . ' · ' . $realm['name']) ?> <small><?= cs_h($zoneName) ?> · <?= number_format($total) ?> commands</small></h2>
  <?= who_heatmap_grid($days, $realm['hours'], $zoneName, $realm['name']) ?></section>
  <?php endforeach; ?></div></details>
  <?php endforeach; ?>
  <p class="note">Realm time uses the collection calendar’s region clocks: US Eastern and EU Paris. Other and unknown regions use America/Chicago when realm time is selected. In a merged map, the same hour row combines each region’s displayed hour. Individual realm server time zones are not stored. Daylight saving changes are applied; repeated 1 am hours on a fall clock change share one square. Counts describe recorded commands, not characters or population.</p>
  <script>document.querySelectorAll('.heatmap-edition').forEach(edition => {
    const select = edition.querySelector('.heat-selector select');
    if (!select) return;
    let mergeRegion = 'all';
    const update = () => {
      edition.open = true;
      edition.querySelectorAll('.heat-merged').forEach(map => {
        map.hidden = select.value !== 'merged' || map.dataset.mergeRegion !== mergeRegion;
      });
      edition.querySelectorAll('.heat-realm[data-realm]').forEach(realm => {
        realm.hidden = select.value !== 'all' && realm.dataset.realm !== select.value;
      });
      edition.querySelectorAll('.heat-region-pills button').forEach(button => {
        button.setAttribute('aria-pressed', String(button.dataset.mergeRegion === mergeRegion));
      });
    };
    select.addEventListener('change', update);
    edition.querySelectorAll('.heat-region-pills button').forEach(button => {
      button.addEventListener('click', () => {
        mergeRegion = button.dataset.mergeRegion;
        select.value = 'merged';
        update();
      });
    });
  });</script>
  <?php return (string)ob_get_clean();
}
