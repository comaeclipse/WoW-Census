<?php
// Guild rosters: one page per realm guild at /guilds/<id>/ (/classic/guilds/<id>/, ...),
// listing every character whose latest recorded guild is that guild.
//
// A page reads only its own guild's members from PostgreSQL. Specs are counted
// from inspections but never tied to a name on the page.

declare(strict_types=1);

// Blizzard guild 22648067. The connected-realm /who tag names its home realm.
const TRUE_FREEDOM_ID = '576508665255';
const TRUE_FREEDOM_ALIAS_ID = '476173590530';
const TRUE_FREEDOM_HOME = ['realm:us:mop-classic:Pagle-Alliance', 'True Freedom'];
const TRUE_FREEDOM_ALIAS = ['realm:us:mop-classic:Nazgrim-Alliance', 'True Freedom-Pagle'];

function guild_canonical_identity(string $source, string $game, string $guild): array {
  return $source === 'mop-classic' && [$game, $guild] === TRUE_FREEDOM_ALIAS ? TRUE_FREEDOM_HOME : [$game, $guild];
}

/**
 * The collection a guild belongs to. Usually its realm and faction (the census game). Forever has no
 * player-facing realms: the game places players on hidden servers behind each ruleset, and Blizzard
 * keys guilds by region and name, so one Forever guild is seen on several collector realms. There
 * the scope is region, realm type and faction ("forever:us:RP PvP:Alliance").
 */
function guild_scope(string $source, string $game): string {
  if ($source !== 'forever') return $game;
  ['name' => $realm, 'fac' => $faction] = split_realm(str_starts_with($game, 'realm:') ? substr($game, 6) : $game);
  $group = forever_realm_group($realm);
  return $group ? "forever:{$group['key']}:$faction" : $game;
}

/** A stable twelve-digit id for a guild in its scope (see guild_scope()). */
function guild_id(string $source, string $game, string $guild): string {
  [$game, $guild] = guild_canonical_identity($source, $game, $guild);
  return guild_hash_id($source, guild_scope($source, $game), $guild);
}

/** The id a guild had before Forever guilds were merged across collector realms; old links still resolve. */
function guild_legacy_id(string $source, string $game, string $guild): string {
  return guild_hash_id($source, $game, $guild);
}

function guild_hash_id(string $source, string $scope, string $guild): string {
  return (string)(100000000000 + hexdec(substr(hash('sha256', "$source\n$scope\n$guild"), 0, 13)) % 900000000000);
}

function guild_path(string $source, string $id): string { return page_path($source, 'guilds') . '/' . $id . '/'; }

/** Distinct members per guild id, counting a character seen on several of a merged guild's realms once. */
function guild_member_counts(string $source): array {
  static $cache = [];
  if (isset($cache[$source])) return $cache[$source];
  $chars = census_characters_sql($source);
  // Counted in SQL, one row per (scope, guild): reading one row per character (528k for
  // Forever) ran PHP out of its 512 MB on the guilds page.
  $games = array_column(Db::all("SELECT DISTINCT c.cgame game FROM $chars c
    WHERE c.rn=1 AND TRIM(COALESCE(c.guild, '')) <> ''", []), 'game');
  if (!$games) return $cache[$source] = [];
  $values = []; $params = [];
  foreach ($games as $game) { $values[] = '(?, ?)'; $params[] = $game; $params[] = guild_scope($source, $game); }
  $rows = Db::all("SELECT m.scope scope, c.guild guild, COUNT(DISTINCT lower(c.name)) n FROM $chars c
    JOIN (VALUES " . implode(', ', $values) . ") m(game, scope) ON m.game = c.cgame
    WHERE c.rn=1 AND TRIM(COALESCE(c.guild, '')) <> '' GROUP BY 1, 2", $params);
  $counts = [];
  foreach ($rows as $r) {
    $id = guild_hash_id($source, $r['scope'], $r['guild']);
    $counts[$id] = ($counts[$id] ?? 0) + (int)$r['n'];
  }
  return $cache[$source] = $counts;
}

/**
 * One list entry per guild id: a Forever guild seen on several collector realms is one guild
 * (see guild_scope()). Other editions' guild ids are per realm, so their lists are unchanged.
 */
function merge_guild_list(string $source, array $guilds): array {
  if ($source !== 'forever' && $source !== 'mop-classic') return $guilds;
  $merged = [];
  foreach ($guilds as $g) {
    [$game, $name] = guild_canonical_identity($source, $g['game'], $g['name']);
    if ($game !== $g['game']) {
      $g['game'] = $game;
      $g['name'] = $name;
      $g['realm'] = 'us:mop-classic:Pagle';
    }
    $id = guild_id($source, $game, $name);
    if (!isset($merged[$id])) { $merged[$id] = $g; continue; }
    $merged[$id]['members'] += $g['members'];
    // MoP already keeps one latest row per character across the connected realms.
    if ($source === 'forever') $merged[$id]['realms'] = true;
  }
  $counts = null;
  foreach ($merged as $id => &$g) {
    if (empty($g['realms'])) continue;
    $counts ??= guild_member_counts($source);
    $g['members'] = $counts[$id] ?? $g['members'];
    unset($g['realms']);
  }
  unset($g);
  return array_values($merged);
}

/** "eu:sod:Wild Growth" -> "Wild Growth (EU)", "us:classic-era:Mankrik" -> "Mankrik" (see realm_name()). */
function guild_realm_label(string $realm): string { return realm_name($realm); }

/** "True Freedom-Pagle" -> "True Freedom": /who names a cross-realm guild with its realm, and guild names never contain "-". */
function guild_display_name(string $guild): string {
  $name = preg_replace('/-.*$/su', '', $guild);
  return $name !== '' ? $name : $guild;
}

/** Assemble rosters, keyed by guild id, from member rows and their specs (guild_member_specs()). */
function guild_rosters_from_rows(string $source, array $rows, bool $connected, array $specOf): array {
  $rosters = [];
  $memberAt = [];
  foreach ($rows as $c) {
    [$game, $guild] = guild_canonical_identity($source, $c['game'], $c['guild']);
    $id = guild_id($source, $game, $guild);
    $scope = guild_scope($source, $game);
    if (!isset($rosters[$id])) {
      ['name' => $realm, 'fac' => $faction] = split_realm(str_starts_with($game, 'realm:') ? substr($game, 6) : $game);
      // A merged Forever guild is labelled with its region and realm type ("US RP PvP").
      if ($scope !== $game && ($group = forever_realm_group($realm))) $realm = $group['label'];
      $rosters[$id] = ['source' => $source, 'id' => $id, 'game' => $game, 'scope' => $scope, 'name' => $guild, 'realm' => $realm,
        'faction' => $faction !== '' ? $faction : 'Unknown', 'firstT' => PHP_INT_MAX, 'lastT' => 0, 'members' => [], 'specs' => []];
    } elseif ($rosters[$id]['name'] !== $guild || $rosters[$id]['scope'] !== $scope) {
      throw new RuntimeException("Guild id $id is shared by two guilds");
    }
    $g = &$rosters[$id];
    $g['firstT'] = min($g['firstT'], $c['first_seen'] ?: PHP_INT_MAX);
    $g['lastT'] = max($g['lastT'], $c['last_seen'] ?: 0);
    // name, level, race, class file, zone, last seen. A character seen on two collector realms of a
    // merged guild is one member (names are unique per region): keep the newest sighting.
    $member = [$c['name'], $c['level'], $c['race'], $c['cf'], $c['zone'], $c['last_seen']];
    $nameKey = $source === 'mop-classic' && $id === TRUE_FREEDOM_ID && !empty($c['ckey'])
      ? $c['ckey'] : mb_strtolower((string)$c['name']);
    if (isset($memberAt[$id][$nameKey])) {
      $at = $memberAt[$id][$nameKey];
      if (($c['last_seen'] ?? 0) > ($g['members'][$at][5] ?? 0)) $g['members'][$at] = $member;
      unset($g);
      continue;
    }
    $memberAt[$id][$nameKey] = count($g['members']);
    $g['members'][] = $member;
    $spec = $specOf[($connected ? '' : $c['game']) . "\t" . $c['ckey']] ?? null;
    if ($spec !== null && $c['cf'] !== null) {
      $k = $c['cf'] . "\t" . $spec;
      $g['specs'][$k] = ($g['specs'][$k] ?? 0) + 1;
    }
    unset($g);
  }
  foreach ($rosters as &$g) {
    if ($g['firstT'] === PHP_INT_MAX) $g['firstT'] = 0;
    // Merged rosters arrive realm by realm; keep the page order (level, then name).
    usort($g['members'], fn($a, $b) => ($b[1] ?? -1) <=> ($a[1] ?? -1) ?: strcmp((string)$a[0], (string)$b[0]));
    arsort($g['specs']);
    // class file, spec, characters
    $g['specs'] = array_map(fn($k, $n) => [...explode("\t", (string)$k), $n], array_keys($g['specs']), $g['specs']);
  }
  unset($g);

  return $rosters;
}

/** Character-page URLs for the members whose stored Armory identity matches this guild realm. */
function guild_member_character_urls(string $source, array $g): array {
  $names = array_column($g['members'], 0);
  if ($source === 'forever') {
    if (!preg_match('/CAMWOW\s+(US|EU|KR)/i', (string)$g['game'], $match)) return [];
    $region = strtolower($match[1]);
    return array_map(fn($id) => forever_character_path($id), forever_character_ids($region, $names));
  }
  $region = preg_match('/^(us|eu|kr|tw):/i', (string)$g['realm'], $match) ? strtolower($match[1]) : null;
  $realm = preg_replace('/ \([A-Z]{2}\)$/', '', realm_name((string)$g['realm']));
  $ids = armory_character_ids($source, $realm, $names, $region, (string)$g['name']);
  return array_map(fn($id) => armory_character_path($source, $id), $ids);
}

/** Classify recent inspections only for members of the requested guild. */
function guild_member_specs(string $source, array $rows, bool $connected): array {
  $keys = array_values(array_unique(array_filter(array_column($rows, 'ckey'), fn($x) => $x !== null && $x !== '')));
  if (!$keys) return [];
  $latest = (int)(Db::first('SELECT MAX(captured_at) latest FROM character_inspects WHERE source_game=?', [$source])['latest'] ?? 0);
  $specOf = [];
  foreach (array_chunk($keys, 500) as $chunk) {
    $marks = implode(',', array_fill(0, count($chunk), '?'));
    $inspects = Db::all("SELECT DISTINCT ON (i.guid) i.game, i.character_key, i.class_file, i.raw_spec_id, i.nodes_json, i.captured_at
      FROM character_inspects i WHERE i.source_game=? AND i.character_key IN ($marks)
      ORDER BY i.guid, i.captured_at DESC", [$source, ...$chunk]);
    usort($inspects, fn($a, $b) => $b['captured_at'] <=> $a['captured_at']);
    foreach ($inspects as $r) {
      if ($r['captured_at'] < $latest - INSPECT_WINDOW_DAYS * 86400 || !$r['game'] || $r['character_key'] === null) continue;
      $build = describe_build(['class' => $r['class_file'], 'spec' => $r['raw_spec_id'], 'list' => json_decode($r['nodes_json'], true)], $source);
      if ($build['classification'] === 'Unknown build') continue;
      $key = ($connected ? '' : canonical_game($r['game'])) . "\t" . $r['character_key'];
      $specOf[$key] ??= $build['classification'] . ($build['hybrid'] ? ' Hybrid' : '');
    }
  }
  return $specOf;
}

/** Count the highest-point tree in each current Blizzard roster member's active build. */
function forever_guild_armory_specs(array $guild): ?array {
  if (!preg_match('/^forever:(us|eu|kr|tw):/i', $guild['scope'], $match)) return null;
  $region = strtolower($match[1]);
  $slug = preg_replace('/\s+/u', '-', mb_strtolower(trim($guild['name'])));
  try {
    $roster = Db::uncached("SELECT roster_json FROM forever_armory_guild_rosters
      WHERE region=? AND guild_slug=? AND roster_json->'guild'->'faction'->>'name'=?
      ORDER BY fetched_at DESC LIMIT 1", [$region, $slug, $guild['faction']])[0] ?? null;
    if (!$roster) return null;
    $rows = Db::uncached("WITH members AS (
        SELECT DISTINCT (member->'character'->>'id')::bigint AS character_id
        FROM jsonb_array_elements(CAST(? AS jsonb)->'members') AS member
      ), trees AS (
        SELECT b.character_id, b.class_name, b.specialization_name,
          COALESCE(b.spent_points, (SELECT SUM((talent->>'rank')::int)
            FROM jsonb_array_elements(b.talents) AS talent), 0) AS points
        FROM members m JOIN forever_armory_current_builds b USING (character_id)
        WHERE b.region=? AND b.is_active AND b.specialization_name IS NOT NULL
      ), ranked AS (
        SELECT *, DENSE_RANK() OVER (PARTITION BY character_id ORDER BY points DESC) AS tree_rank
        FROM trees WHERE points > 0
      ), builds AS (
        SELECT class_name, string_agg(specialization_name, ' / ' ORDER BY specialization_name) AS spec
        FROM ranked WHERE tree_rank=1 GROUP BY character_id, class_name
      )
      SELECT class_name, spec, COUNT(*) AS n FROM builds GROUP BY class_name, spec
      ORDER BY n DESC, class_name, spec", [$roster['roster_json'], $region]);
  } catch (PDOException $e) {
    if (in_array($e->getCode(), ['42P01', '42703'], true)) return null;
    throw $e;
  }
  $classFiles = [];
  foreach (CLASS_META as $file => $meta) $classFiles[mb_strtolower($meta[0])] = $file;
  $specs = [];
  foreach ($rows as $row) {
    $file = $classFiles[mb_strtolower((string)$row['class_name'])] ?? null;
    if ($file !== null) $specs[] = [$file, $row['spec'], (int)$row['n']];
  }
  return $specs;
}

/** Summarize the active Armory build of each linked Classic roster member. */
function classic_guild_armory_specs(string $source, array $guild): ?array {
  $ids = array_values(array_unique(array_map(fn($url) => basename(rtrim($url, '/')), guild_member_character_urls($source, $guild))));
  if (!$ids) return null;
  $marks = implode(',', array_fill(0, count($ids), '?'));
  try {
    $rows = Db::uncached("SELECT p.class_id, spec.name, COUNT(*) AS n
      FROM character_armory_profiles p
      CROSS JOIN LATERAL (
        SELECT specializations_json FROM character_armory_snapshots s
        WHERE (s.source_game,s.region,s.character_id)=(p.source_game,p.region,p.character_id)
          AND s.specializations_json IS NOT NULL ORDER BY s.snapshot_at DESC LIMIT 1
      ) snapshot
      LEFT JOIN LATERAL (
        SELECT value FROM jsonb_array_elements(snapshot.specializations_json->'specialization_groups')
          WITH ORDINALITY AS groups(value, position)
        ORDER BY (value->>'is_active'='true') DESC NULLS LAST, position LIMIT 1
      ) active_build ON p.source_game<>'mop-classic'
      LEFT JOIN LATERAL (
        SELECT COALESCE(value->>'specialization_name',value->'specialization'->>'name') AS name
        FROM jsonb_array_elements(active_build.value->'specializations') WITH ORDINALITY AS trees(value, position)
        WHERE (value->>'spent_points')::int > 0
        ORDER BY (value->>'spent_points')::int DESC, position LIMIT 1
      ) tree ON true
      CROSS JOIN LATERAL (
        SELECT CASE WHEN p.source_game='mop-classic'
          THEN snapshot.specializations_json->'active_specialization'->>'name'
          ELSE tree.name END AS name
      ) spec
      WHERE p.source_game=? AND p.public_id IN ($marks) AND spec.name IS NOT NULL
      GROUP BY p.class_id,spec.name ORDER BY n DESC,p.class_id,spec.name", [$source, ...$ids]);
  } catch (PDOException $e) {
    if (in_array($e->getCode(), ['42P01', '42703'], true)) return null;
    throw $e;
  }
  $classFiles = FOREVER_CLASS_FILES + [6 => 'DEATHKNIGHT', 10 => 'MONK'];
  $specs = [];
  foreach ($rows as $row) {
    $file = $classFiles[$row['class_id']] ?? null;
    if ($file !== null) $specs[] = [$file, $row['name'], (int)$row['n']];
  }
  return $specs ?: null;
}

function guild_has_unpublished_armory_refresh(string $source, string $id): bool {
  $published = Db::publishedVersion();
  return $published ? guild_has_armory_refresh($source, $id, $published) : false;
}

function guild_has_armory_refresh(string $source, string $id, ?string $after = null): bool {
  try {
    return (bool)Db::uncached("SELECT 1 FROM guild_roster_snapshots
      WHERE source_game=? AND guild_id=? AND stored_at>COALESCE(CAST(? AS timestamptz), '-infinity')
        AND roster->>'armory_refresh_utc' IS NOT NULL LIMIT 1", [$source, $id, $after]);
  } catch (PDOException $e) {
    if ($e->getCode() !== '42P01') throw $e;
    return false;
  }
}

/** One guild's roster, or null when the edition has no guild with this id. */
function guild_roster(string $source, string $id): ?array {
  static $cache = [];
  if (array_key_exists("$source-$id", $cache)) return $cache["$source-$id"];
  // Resolve the stable public id from distinct guild names, then fetch only
  // this guild's members. The old path loaded every member of the edition.
  // A guild can span several census games (a merged Forever guild), and an old
  // (pre-merge) id still resolves: find the current id, then every game it covers.
  // An explicit Armory refresh is already reconciled in the live census tables.
  // Let this guild show the refreshed roster while the shared census is republishing.
  $freshArmory = guild_has_unpublished_armory_refresh($source, $id);
  $chars = $freshArmory ? census_characters_live_sql($source) : census_characters_sql($source);
  $read = $freshArmory ? [Db::class, 'uncached'] : [Db::class, 'all'];
  $matches = null;
  if ($source === 'mop-classic' && in_array($id, [TRUE_FREEDOM_ID, TRUE_FREEDOM_ALIAS_ID], true)) {
    // Resolve both tuples even if the published guild index predates the merge.
    $matches = array_map(fn($g) => ['game' => $g[0], 'guild' => $g[1], 'id' => TRUE_FREEDOM_ID],
      [TRUE_FREEDOM_HOME, TRUE_FREEDOM_ALIAS]);
  } elseif (!$freshArmory && census_cache_fresh()) {
    try {
      $matches = Db::all("SELECT cgame game, guild, id FROM census_guilds WHERE source_game=? AND id =
          (SELECT id FROM census_guilds WHERE source_game=? AND (id=? OR legacy_id=?) ORDER BY (id=?) DESC LIMIT 1)",
        [$source, $source, $id, $id, $id]);
    } catch (PDOException $e) {
      // Older cache: no guild index (42P01) or no legacy_id column (42703). Use the scan below.
      if (!in_array($e->getCode(), ['42P01', '42703'], true)) throw $e;
    }
  }
  if ($matches === null) {
    $guilds = $read("SELECT DISTINCT c.cgame game, c.guild guild FROM $chars c
      WHERE c.rn=1 AND TRIM(COALESCE(c.guild, '')) <> ''", []);
    $current = null;
    foreach ($guilds as $g) {
      if (guild_id($source, $g['game'], $g['guild']) === $id) { $current = $id; break; }
      if (guild_legacy_id($source, $g['game'], $g['guild']) === $id) $current ??= guild_id($source, $g['game'], $g['guild']);
    }
    $matches = $current === null ? [] : array_values(array_map(fn($g) => $g + ['id' => $current],
      array_filter($guilds, fn($g) => guild_id($source, $g['game'], $g['guild']) === $current)));
  }
  if (!$matches) return $cache["$source-$id"] = null;
  $current = $matches[0]['id'];
  $guards = [];
  $params = [];
  foreach ($matches as $match) {
    [, $name] = guild_canonical_identity($source, $match['game'], $match['guild']);
    $guards[$name] = true;
    array_push($params, $match['game'], $match['guild']);
  }
  if (count($guards) > 1) throw new RuntimeException("Guild id $current is shared by two guilds");
  $marks = implode(',', array_fill(0, count($matches), '(?, ?)'));
  $rows = $read("SELECT c.cgame game, c.guild guild, c.character_key ckey, c.name name, c.level level,
      c.race race, c.class_file cf, c.zone zone, c.first_seen first_seen, c.last_seen last_seen
    FROM $chars c WHERE c.rn=1 AND (c.cgame, c.guild) IN ($marks)
    ORDER BY c.level DESC NULLS LAST, c.name COLLATE \"C\"", $params);
  $connected = $source === 'mop-classic';
  $guild = guild_rosters_from_rows($source, $rows, $connected, [])[$current] ?? null;
  if ($guild === null) return $cache["$source-$id"] = null;
  $armorySpecs = match ($source) {
    'forever' => forever_guild_armory_specs($guild),
    'classic', 'sod', 'hardcore', 'mop-classic' => classic_guild_armory_specs($source, $guild),
    'classic-progression' => ($freshArmory || guild_has_armory_refresh($source, $current))
      ? classic_guild_armory_specs($source, $guild) : null,
    default => null,
  };
  if ($armorySpecs !== null) {
    $guild['specs'] = $armorySpecs;
    $guild['armorySpecs'] = true;
  } else {
    $guild = guild_rosters_from_rows($source, $rows, $connected, guild_member_specs($source, $rows, $connected))[$current];
  }
  return $cache["$source-$id"] = $guild;
}

/** The plain roster page. The 2008 skin draws its own; Retail and Pixel designs are still to come. */
function guild_page_html(string $source, array $g): string {
  $edition = seo_label(EDITIONS[$source]['label']);
  $guild = guild_display_name($g['name']);
  $title = $guild . ' – ' . $g['faction'] . ' Guild Roster';
  $rows = '';
  $characterUrls = guild_member_character_urls($source, $g);
  foreach ($g['members'] as [$name, $level, $race, $cf, $zone, $seen]) {
    $url = $characterUrls[mb_strtolower((string)$name)] ?? null;
    $shownName = $url ? '<a href="' . esc($url) . '">' . esc($name) . '</a>' : esc($name);
    $rows .= '<tr><td>' . $shownName . '</td><td>' . esc($level ?? '') . '</td><td>' . esc($race ?? '') . '</td><td>' . esc(CLASS_META[$cf][0] ?? $cf ?? '') .
      '</td><td>' . esc($zone ?? '') . '</td><td>' . ($seen ? gmdate('Y-m-d', (int)$seen) : '') . '</td></tr>';
  }
  return localize_content('<!doctype html><html lang="en"><head><meta charset="utf-8">' .
    '<meta name="viewport" content="width=device-width,initial-scale=1">' .
    seo_head($title . ' – WoWCensus', "Characters seen in the $edition guild <" . $guild . '> and their class, race and spec mix.', null) .
    '<link rel="stylesheet" href="/' . stylesheet()['name'] . '"></head><body><main class="wrap">' .
    '<h1 class="iname">&lt;' . esc($guild) . '&gt;</h1><p><a href="' . esc(page_path($source, 'guilds')) . '">' . esc($edition) . ' guilds</a></p>' .
    '<table><thead><tr><th>Name</th><th>Level</th><th>Race</th><th>Class</th><th>Zone</th><th>Last seen</th></tr></thead><tbody>' . $rows .
    '</tbody></table></main></body></html>' . "\n");
}
