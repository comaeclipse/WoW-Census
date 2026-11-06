<?php
// Guild rosters: one page per realm guild at /guilds/<id>/ (/classic/guilds/<id>/, ...),
// listing every character whose latest recorded guild is that guild.
//
// The compact origin cannot query characters, so the capture publishes each
// roster as its own model ("guild:<source>-<id>") and a page decodes only its
// own guild. Specs are counted from inspections but never tied to a name: the
// origin carries no inspection identities.

declare(strict_types=1);

/** A stable twelve-digit id for a realm guild. */
function guild_id(string $source, string $game, string $guild): string {
  return (string)(100000000000 + hexdec(substr(hash('sha256', "$source\n$game\n$guild"), 0, 13)) % 900000000000);
}

function guild_path(string $source, string $id): string { return page_path($source, 'guilds') . '/' . $id . '/'; }

/** "eu:sod:Wild Growth" -> "Wild Growth (EU)", "us:classic-era:Mankrik" -> "Mankrik" (see realm_name()). */
function guild_realm_label(string $realm): string { return realm_name($realm); }

/** "True Freedom-Pagle" -> "True Freedom": /who names a cross-realm guild with its realm, and guild names never contain "-". */
function guild_display_name(string $guild): string {
  $name = preg_replace('/-.*$/su', '', $guild);
  return $name !== '' ? $name : $guild;
}

/** Every roster of an edition, keyed by guild id. Built from the full database only. */
function guild_rosters(string $source): array {
  static $cache = [];
  if (isset($cache[$source])) return $cache[$source];
  // Same characters as the guild list's member counts (see load_census()).
  $connected = $source === 'mop-classic';
  $chars = census_characters_sql($source);
  // The marker keeps these rows out of the published query results: the rosters go out as models.
  $rows = Db::all("/* guild rosters */ SELECT c.cgame game, c.guild guild, c.character_key ckey, c.name name, c.level level, c.race race,
       c.class_file cf, c.zone zone, c.first_seen first_seen, c.last_seen last_seen
     FROM $chars c JOIN datasets d ON d.game = c.game
     WHERE d.source_game = ? AND c.rn=1 AND TRIM(COALESCE(c.guild, '')) <> ''
     ORDER BY c.cgame COLLATE \"C\", c.guild COLLATE \"C\", c.level DESC NULLS LAST, c.name COLLATE \"C\"", [$source]);

  return $cache[$source] = guild_rosters_from_rows($source, $rows, $connected, true);
}

/** Assemble the same roster shape for a full capture or one requested guild. */
function guild_rosters_from_rows(string $source, array $rows, bool $connected, bool $publish = false, ?array $specOf = null): array {
  if ($specOf === null) {
    $specOf = [];
    foreach (load_inspects($source)['records'] as $r) {
      if (($r['game'] ?? '') === '' || $r['characterKey'] === null || $r['classification'] === 'Unknown build') continue;
      $key = ($connected ? '' : canonical_game($r['game'])) . "\t" . $r['characterKey'];
      // Records come newest first.
      $specOf[$key] ??= $r['classification'] . ($r['hybrid'] ? ' Hybrid' : '');
    }
  }

  $rosters = [];
  foreach ($rows as $c) {
    $id = guild_id($source, $c['game'], $c['guild']);
    if (!isset($rosters[$id])) {
      ['name' => $realm, 'fac' => $faction] = split_realm(str_starts_with($c['game'], 'realm:') ? substr($c['game'], 6) : $c['game']);
      $rosters[$id] = ['source' => $source, 'id' => $id, 'game' => $c['game'], 'name' => $c['guild'], 'realm' => $realm,
        'faction' => $faction !== '' ? $faction : 'Unknown', 'firstT' => PHP_INT_MAX, 'lastT' => 0, 'members' => [], 'specs' => []];
    } elseif ($rosters[$id]['name'] !== $c['guild'] || $rosters[$id]['game'] !== $c['game']) {
      throw new RuntimeException("Guild id $id is shared by two guilds");
    }
    $g = &$rosters[$id];
    $g['firstT'] = min($g['firstT'], $c['first_seen'] ?: PHP_INT_MAX);
    $g['lastT'] = max($g['lastT'], $c['last_seen'] ?: 0);
    // name, level, race, class file, zone, last seen
    $g['members'][] = [$c['name'], $c['level'], $c['race'], $c['cf'], $c['zone'], $c['last_seen']];
    $spec = $specOf[($connected ? '' : $c['game']) . "\t" . $c['ckey']] ?? null;
    if ($spec !== null && $c['cf'] !== null) {
      $k = $c['cf'] . "\t" . $spec;
      $g['specs'][$k] = ($g['specs'][$k] ?? 0) + 1;
    }
    unset($g);
  }
  foreach ($rosters as &$g) {
    if ($g['firstT'] === PHP_INT_MAX) $g['firstT'] = 0;
    arsort($g['specs']);
    // class file, spec, characters
    $g['specs'] = array_map(fn($k, $n) => [...explode("\t", (string)$k), $n], array_keys($g['specs']), $g['specs']);
  }
  unset($g);

  if ($publish && ($dir = getenv('ML_CAPTURE_DIR'))) {
    if (!is_dir($dir . '/models')) mkdir($dir . '/models', 0700, true);
    foreach ($rosters as $id => $g)
      file_put_contents("$dir/models/guild-$source-$id.json", json_encode($g, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
  }
  return $rosters;
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

/** One guild's roster, or null when the edition has no guild with this id. */
function guild_roster(string $source, string $id): ?array {
  static $cache = [];
  if (array_key_exists("$source-$id", $cache)) return $cache["$source-$id"];
  if (getenv('ML_QUERY_CACHE') !== '1') {
    // Resolve the stable public id from distinct guild names, then fetch only
    // this guild's members. The old path loaded every member of the edition.
    $chars = census_characters_sql($source);
    $match = null;
    $indexed = false;
    if (census_cache_fresh()) {
      try {
        $match = Db::first('SELECT cgame game, guild FROM census_guilds WHERE source_game=? AND id=?', [$source, $id]);
        $indexed = true;
      } catch (PDOException $e) {
        if ($e->getCode() !== '42P01') throw $e; // Older cache, before the guild index was built.
      }
    }
    if (!$indexed) {
      $guilds = Db::all("SELECT DISTINCT c.cgame game, c.guild guild FROM $chars c
        WHERE c.rn=1 AND TRIM(COALESCE(c.guild, '')) <> ''", []);
      foreach ($guilds as $g) {
        if (guild_id($source, $g['game'], $g['guild']) !== $id) continue;
        if ($match !== null) throw new RuntimeException("Guild id $id is shared by two guilds");
        $match = $g;
      }
    }
    if ($match === null) return $cache["$source-$id"] = null;
    $rows = Db::all("SELECT c.cgame game, c.guild guild, c.character_key ckey, c.name name, c.level level,
        c.race race, c.class_file cf, c.zone zone, c.first_seen first_seen, c.last_seen last_seen
      FROM $chars c WHERE c.rn=1 AND c.cgame=? AND c.guild=?
      ORDER BY c.level DESC NULLS LAST, c.name COLLATE \"C\"", [$match['game'], $match['guild']]);
    $connected = $source === 'mop-classic';
    return $cache["$source-$id"] = guild_rosters_from_rows($source, $rows, $connected, false, guild_member_specs($source, $rows, $connected))[$id] ?? null;
  }
  try {
    return $cache["$source-$id"] = Db::model("guild:$source-$id");
  } catch (RuntimeException $e) {
    if (!str_starts_with($e->getMessage(), 'Missing published model')) throw $e;
    return $cache["$source-$id"] = null;
  }
}

/** The plain roster page. The 2008 skin draws its own; Retail and Pixel designs are still to come. */
function guild_page_html(string $source, array $g): string {
  $edition = seo_label(EDITIONS[$source]['label']);
  $guild = guild_display_name($g['name']);
  $title = $guild . ' – ' . $g['faction'] . ' Guild Roster';
  $rows = '';
  foreach ($g['members'] as [$name, $level, $race, $cf, $zone, $seen])
    $rows .= '<tr><td>' . esc($name) . '</td><td>' . esc($level ?? '') . '</td><td>' . esc($race ?? '') . '</td><td>' . esc(CLASS_META[$cf][0] ?? $cf ?? '') .
      '</td><td>' . esc($zone ?? '') . '</td><td>' . ($seen ? gmdate('Y-m-d', (int)$seen) : '') . '</td></tr>';
  return localize_content('<!doctype html><html lang="en"><head><meta charset="utf-8">' .
    '<meta name="viewport" content="width=device-width,initial-scale=1">' .
    seo_head($title . ' – WoWCensus', "Characters seen in the $edition guild <" . $guild . '> and their class, race and spec mix.', null) .
    '<link rel="stylesheet" href="/' . stylesheet()['name'] . '"></head><body><main class="wrap">' .
    '<h1 class="iname">&lt;' . esc($guild) . '&gt;</h1><p><a href="' . esc(page_path($source, 'guilds')) . '">' . esc($edition) . ' guilds</a></p>' .
    '<table><thead><tr><th>Name</th><th>Level</th><th>Race</th><th>Class</th><th>Zone</th><th>Last seen</th></tr></thead><tbody>' . $rows .
    '</tbody></table></main></body></html>' . "\n");
}
