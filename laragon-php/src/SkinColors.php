<?php
declare(strict_types=1);

function skin_color_slug(string $race): string {
  return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($race)), '-');
}

function retail_skin_color_factions(): array {
  return [
    'Alliance' => ['Human', 'Dwarf', 'Night Elf', 'Gnome', 'Draenei', 'Worgen', 'Void Elf', 'Lightforged Draenei', 'Dark Iron Dwarf', 'Kul Tiran', 'Mechagnome'],
    'Horde' => ['Orc', 'Undead', 'Tauren', 'Troll', 'Blood Elf', 'Goblin', 'Nightborne', 'Highmountain Tauren', "Mag'har Orc", 'Zandalari Troll', 'Vulpera'],
    'Either faction' => ['Pandaren', 'Dracthyr', 'Earthen', 'Haranir'],
  ];
}

function retail_skin_color_races(): array {
  $races = [];
  foreach (retail_skin_color_factions() as $group)
    foreach ($group as $race) $races[skin_color_slug($race)] = $race;
  return $races;
}

function skin_color_body_options(string $race): ?array {
  return match ($race) {
    'Undead' => [58 => 'Body 1', 63 => 'Body 2'],
    'Goblin' => [100 => 'Body 1', 105 => 'Body 2'],
    default => null,
  };
}

// ChrCustomizationOption -> ChrModel -> ChrRaceXChrModel. Worgen uses its
// human-form Skin Color options; Dracthyr uses its Visage options. Vulpera's
// sampled "Skin Color" options map to dragon mounts, not its playable model.
function skin_color_race_options(): array {
  static $options = null;
  return $options ??= json_decode(file_get_contents(__DIR__ . '/retail-skin-option-ids.json'), true, 512, JSON_THROW_ON_ERROR);
}

// The appearance sample retains the census race at selection time. A character
// can change race before the API lookup. The API can also return mount options
// named "Skin Color", so only race/form options are accepted.
function skin_color_option_is_valid(string $race, int $optionId): bool {
  return in_array($optionId, skin_color_race_options()[$race] ?? [], true);
}

// CIE L* from the sRGB UI swatch, on a shared 0–100 scale across races.
function skin_swatch_lightness(string $hex): float {
  [$red, $green, $blue] = sscanf(substr($hex, 1), '%02x%02x%02x');
  $channel = static function (int $value): float {
    $unit = $value / 255;
    return $unit <= .04045 ? $unit / 12.92 : (($unit + .055) / 1.055) ** 2.4;
  };
  $y = .2126 * $channel($red) + .7152 * $channel($green) + .0722 * $channel($blue);
  return $y > .008856 ? 116 * ($y ** (1 / 3)) - 16 : 903.3 * $y;
}

// Squarified treemap (Bruls et al.): values sorted descending, returns [x, y, w, h] per value.
function skin_treemap(array $values, float $width, float $height): array {
  $total = array_sum($values);
  if (!$total) return [];
  $areas = array_map(static fn($v) => $v / $total * $width * $height, $values);
  $worst = static function (array $row, float $side): float {
    $sum = array_sum($row);
    return max($side * $side * max($row) / ($sum * $sum), $sum * $sum / ($side * $side * min($row)));
  };
  $cells = [];
  $x = 0; $y = 0; $w = $width; $h = $height;
  $row = [];
  $flush = static function () use (&$row, &$cells, &$x, &$y, &$w, &$h): void {
    $sum = array_sum($row);
    if ($w >= $h) {
      $thick = $sum / $h; $offset = $y;
      foreach ($row as $area) { $cells[] = [$x, $offset, $thick, $area / $thick]; $offset += $area / $thick; }
      $x += $thick; $w -= $thick;
    } else {
      $thick = $sum / $w; $offset = $x;
      foreach ($row as $area) { $cells[] = [$offset, $y, $area / $thick, $thick]; $offset += $area / $thick; }
      $y += $thick; $h -= $thick;
    }
    $row = [];
  };
  foreach ($areas as $area) {
    $side = min($w, $h);
    if ($row && $worst([...$row, $area], $side) > $worst($row, $side)) $flush();
    $row[] = $area;
  }
  if ($row) $flush();
  return $cells;
}

function skin_swatch_rows(array $rows): array {
  $colors = [];
  foreach ($rows as $row) {
    $hex = (string)$row['swatch_hex'];
    if (!preg_match('/^#[0-9A-F]{6}$/', $hex)) $hex = '#777777';
    $colors[$hex] = ($colors[$hex] ?? 0) + (int)$row['n'];
  }
  arsort($colors, SORT_NUMERIC);
  $result = [];
  foreach ($colors as $hex => $count) $result[] = ['swatch_hex' => $hex, 'n' => $count];
  return $result;
}

function skin_heatmap(array $rows): string {
  $matched = array_sum(array_column($rows, 'n'));
  if (!$matched) return '';
  $counts = array_map(static fn($row) => (int)$row['n'], $rows);
  $wide = skin_treemap($counts, 300, 100);
  $narrow = skin_treemap($counts, 150, 100);
  $heatmap = '';
  foreach ($rows as $index => $row) {
    $count = (int)$row['n'];
    $hex = (string)$row['swatch_hex'];
    [$x, $y, $w, $h] = $wide[$index];
    [$nx, $ny, $nw, $nh] = $narrow[$index];
    $percent = round($count / $matched * 100);
    $box = sprintf('--x:%.3f%%;--y:%.3f%%;--w:%.3f%%;--h:%.3f%%;--nx:%.3f%%;--ny:%.3f%%;--nw:%.3f%%;--nh:%.3f%%',
      $x / 3, $y, $w / 3, $h, $nx / 1.5, $ny, $nw / 1.5, $nh);
    $ink = skin_swatch_lightness($hex) > 62 ? ' dark' : '';
    $label = min($w / 3, $nw / 1.5) >= 6 && min($h, $nh) >= 10 ? '<span>' . $percent . '%</span>' : '';
    $heatmap .= '<div class="skin-heat-cell' . $ink . '" role="listitem" style="' . $box . ';--c:' . $hex . '" title="' . $hex . ' · ' . $count . ' sampled (' . $percent . '%)" aria-label="Color ' . substr($hex, 1) . ': ' . $count . ' sampled characters, ' . $percent . ' percent">' . $label . '</div>';
  }
  return '<div class="skin-heatmap" role="list" aria-label="Observed skin color swatches, sorted by frequency; area is proportional to character count">' . $heatmap . '</div>';
}

// One race's sample: characters queried, the valid option rows, and the merged
// swatch rows. Shared with the WoW 2008 skin, so the queries stay identical.
function retail_skin_race_sample(string $shown): array {
  $totals = Db::first("SELECT COUNT(*) AS queried, COUNT(*) FILTER (WHERE skin_choice_id IS NOT NULL) AS matched
    FROM character_appearance_latest WHERE source_game = 'retail' AND race_at_query = ?", [$shown]);
  $rawRows = Db::all("SELECT skin_option_id AS option_id, skin_choice_id AS choice_id, swatch_hex, COUNT(*) AS n
    FROM character_appearance_latest
    WHERE source_game = 'retail' AND race_at_query = ? AND skin_choice_id IS NOT NULL
    GROUP BY skin_option_id, skin_choice_id, swatch_hex ORDER BY n DESC, skin_choice_id", [$shown]);
  $validRows = array_values(array_filter($rawRows, static fn($row) => skin_color_option_is_valid($shown, (int)$row['option_id'])));
  return ['queried' => (int)($totals['queried'] ?? 0), 'validRows' => $validRows, 'rows' => skin_swatch_rows($validRows)];
}

/** Swatch rows per body type, for races whose two bodies have separate options; null otherwise. */
function retail_skin_body_rows(string $race, array $validRows): ?array {
  $bodyOptions = skin_color_body_options($race);
  if ($bodyOptions === null) return null;
  $out = [];
  foreach ($bodyOptions as $optionId => $body)
    $out[$body] = skin_swatch_rows(array_values(array_filter($validRows, static fn($row) => (int)$row['option_id'] === $optionId)));
  return $out;
}

function retail_skin_form_note(string $race): string {
  return match ($race) {
    'Worgen' => 'The sampled Skin Color options are for the Worgen human form.',
    'Dracthyr' => 'The sampled Skin Color options are for the Dracthyr Visage form.',
    'Vulpera' => 'The sampled Skin Color options mapped to dragon mounts and were excluded.',
    default => '',
  };
}

// Every race's distinct swatches with lightness and count, ordered by median
// lightness (lightest first), and the largest single-swatch share on the chart.
function retail_skin_spectrum_data(): array {
  $samples = [];
  foreach (retail_skin_color_races() as $race) $samples[$race] = [];
  $rows = Db::all("SELECT race_at_query, skin_option_id, swatch_hex, COUNT(*) AS n
    FROM character_appearance_latest
    WHERE source_game = 'retail' AND skin_choice_id IS NOT NULL
    GROUP BY race_at_query, skin_option_id, swatch_hex", []);
  foreach ($rows as $row) {
    $race = $row['race_at_query'];
    if (!isset($samples[$race])) continue;
    if (!skin_color_option_is_valid($race, (int)$row['skin_option_id'])) continue;
    $hex = preg_match('/^#[0-9A-F]{6}$/', (string)$row['swatch_hex']) ? $row['swatch_hex'] : '#777777';
    if (!isset($samples[$race][$hex])) $samples[$race][$hex] = ['lightness' => skin_swatch_lightness($hex), 'hex' => $hex, 'n' => 0];
    $samples[$race][$hex]['n'] += (int)$row['n'];
  }
  $medians = [];
  foreach ($samples as $race => $points) {
    $values = [];
    foreach ($points as $point) array_push($values, ...array_fill(0, $point['n'], $point['lightness']));
    sort($values, SORT_NUMERIC);
    $n = count($values);
    $medians[$race] = $n ? ($values[intdiv($n - 1, 2)] + $values[intdiv($n, 2)]) / 2 : 0;
  }
  uksort($samples, static fn($a, $b) => ($medians[$b] <=> $medians[$a]) ?: strcmp($a, $b));
  $maxShare = 0;
  foreach ($samples as $points) {
    $total = array_sum(array_column($points, 'n'));
    foreach ($points as $point) $maxShare = max($maxShare, $point['n'] / $total);
  }
  return ['samples' => $samples, 'medians' => $medians, 'maxShare' => $maxShare];
}

function retail_skin_spectrum(?string $selectedSlug): string {
  $factionColors = ['Alliance' => '#5fb0f0', 'Horde' => '#e0574f', 'Either faction' => '#9a9a9a'];
  $factionByRace = [];
  foreach (retail_skin_color_factions() as $faction => $races)
    foreach ($races as $race) $factionByRace[$race] = $faction;
  ['samples' => $samples, 'medians' => $medians, 'maxShare' => $maxShare] = retail_skin_spectrum_data();
  $output = '<section class="panel skin-chart" data-skin-chart aria-label="Lightness spectrum by race">' .
    '<div class="ptitle">Light to dark · all races</div>' .
    '<p class="summary">Each square is one distinct UI swatch, placed by its lightness and sized by its share of that race’s sampled characters. The gold line marks the race median. Select a race to view its palette.</p>' .
    '<div class="skin-chart-axis"><span>Race</span><span class="skin-chart-ramp"><i></i><small><b>Darker</b><b>Lighter</b></small></span><span>n</span></div>';
  foreach ($samples as $race => $points) {
    $raceSlug = skin_color_slug($race);
    $active = $raceSlug === $selectedSlug ? ' aria-current="page"' : '';
    $faction = $factionByRace[$race];
    $total = array_sum(array_column($points, 'n'));
    // Area tracks share; the most common color on the chart gets the 30px square.
    usort($points, static fn($a, $b) => $b['n'] <=> $a['n']);
    $dots = '';
    foreach ($points as $point) {
      $share = $point['n'] / $total;
      $side = max(4, (int)round(30 * sqrt($share / $maxShare)));
      $left = number_format($point['lightness'], 2, '.', '');
      $dots .= '<i aria-hidden="true" style="left:' . $left . '%;width:' . $side . 'px;height:' . $side . 'px;margin:-' . intdiv($side, 2) . 'px 0 0 -' . intdiv($side, 2) . 'px;background:' . $point['hex'] . '" title="' . $point['hex'] . ' · ' . $point['n'] . ' sampled (' . round($share * 100) . '%)"></i>';
    }
    $median = number_format($medians[$race], 2, '.', '');
    $output .= '<a data-skin-link class="skin-chart-row" href="/retail/skin-colors/' . esc($raceSlug) . '/"' . $active . '>' .
      '<span class="skin-chart-race"><i style="background:' . $factionColors[$faction] . '"></i>' . esc($race) . '</span>' .
      '<span class="skin-chart-track"><i class="median" aria-hidden="true" style="left:' . $median . '%"></i>' . $dots . '</span>' .
      '<span class="skin-chart-count">' . $total . '</span></a>';
  }
  return $output . '<p class="note">Swatch lightness is calculated from Blizzard customization colors; it does not measure rendered character skin. Square area is proportional to share. Blue markers are Alliance races, red are Horde, and gray are available to either faction.</p></section>';
}

function retail_skin_colors_page(string $cssName, ?string $forcedSlug = null, bool $detailOnly = false): string {
  $factions = retail_skin_color_factions();
  $races = retail_skin_color_races();
  $requested = $forcedSlug ?? ($_GET['race'] ?? null);
  $slug = is_string($requested) && isset($races[$requested]) ? $requested : null;
  $race = $slug === null ? null : $races[$slug];
  // The index opens on the first race so the swatch column is never empty.
  $shownSlug = $slug ?? array_key_first($races);
  $shown = $races[$shownSlug];

  ['queried' => $queried, 'validRows' => $validRows, 'rows' => $rows] = retail_skin_race_sample($shown);
  $matched = array_sum(array_column($rows, 'n'));
  $rail = '';
  foreach ($factions as $faction => $members) {
    $links = '';
    foreach ($members as $member) {
      $memberSlug = skin_color_slug($member);
      $current = $memberSlug === $shownSlug ? ' aria-current="page"' : '';
      $links .= '<a class="race-tile" data-skin-link href="/retail/skin-colors/' . esc($memberSlug) . '/" title="' . esc($member) . '" aria-label="' . esc($member) . '"' . $current . '><img src="' . esc(race_icon($member)) . '" alt="" width="40" height="40"></a>';
    }
    $label = $faction === 'Either faction' ? 'Neutral' : $faction;
    $rail .= '<section class="race-group faction-col" data-faction="' . esc($faction) . '" aria-label="' . esc($label) . ' races"><h3>' . esc($label) . '<small>' . count($members) . '</small></h3><div class="race-tiles">' . $links . '</div></section>';
  }

  $heatmap = skin_heatmap($rows);
  $paletteSummary = '<strong>' . count($rows) . '</strong> distinct sampled swatches.';
  $matchDescription = 'have a Skin Color option matching this race or form';
  if (($bodies = retail_skin_body_rows($shown, $validRows)) !== null) {
    // ChrCustomizationOption maps these pairs to ChrModel Sex 0/1.
    $heatmap = '';
    $swatchesByBody = 0;
    foreach ($bodies as $body => $bodyRows) {
      $bodyCount = array_sum(array_column($bodyRows, 'n'));
      $swatchesByBody += count($bodyRows);
      $heatmap .= '<div class="skin-body-palette"><h3>' . $body . ' <small>' . count($bodyRows) . ' observed swatches · ' . $bodyCount . ' characters</small></h3>' . skin_heatmap($bodyRows) . '</div>';
    }
    $paletteSummary = '<strong>' . count($rows) . '</strong> distinct swatches across both body types' . ($swatchesByBody > count($rows) ? '; some are shared.' : '.');
    $article = $shown === 'Undead' ? 'an' : 'a';
    $matchDescription = 'have a skin color from ' . $article . ' ' . esc($shown) . ' customization option';
  }
  $empty = $matched ? '' : '<p>No sampled Skin Color option could be verified for this playable race or form.</p>';
  $formNote = retail_skin_form_note($shown);
  $title = $race === null ? 'All races' : esc($race);
  $css = esc($cssName);
  $nav = edition_nav('retail', 'index.html')['games'];
  $chart = retail_skin_spectrum($shownSlug);
  $canonical = 'https://wowcensus.io/retail/skin-colors/' . ($slug === null ? '' : $slug . '/');
  $shownTitle = esc($shown);
  $detail = '<section class="panel skin-detail" aria-label="' . $shownTitle . ' popular skin colors"><div class="ptitle">' . $shownTitle . ' · most observed skin colors</div><p class="summary"><strong>' . $matched . '</strong> of <strong>' . $queried . '</strong> sampled characters ' . $matchDescription . '. ' . $paletteSummary . '</p>' . $empty . $heatmap . '<p class="note">' . $formNote . ($formNote === '' ? '' : ' ') . 'Repeated Blizzard choice IDs with the same UI swatch are combined. Each color’s area is proportional to its share of sampled characters. UI swatches do not measure rendered skin. This is a small API-visible sample, not a population estimate.</p></section>';
  if ($detailOnly) return $detail . "\n";
  return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Retail skin colors · $title · WoWCensus</title><link rel="canonical" href="$canonical"><link rel="preload" href="/fonts/press-start-2p-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/fonts/vt323-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="stylesheet" href="/$css">
<style>
.skin-page{max-width:1040px}.skin-page .iname{font-size:28px}.skin-page .iname .itopic{display:block;font:9px/1.5 var(--pixel);color:var(--muted);margin-top:7px}
.skin-heading{display:none;font:12px/1.5 var(--pixel);color:var(--gold);margin:0 0 14px}
.skin-page .lede{color:var(--muted);margin:0 0 22px}
.skin-layout{display:grid;grid-template-columns:262px minmax(0,1fr);gap:18px;align-items:start}
.race-rail{position:sticky;top:12px;display:flex;flex-direction:column;gap:12px}
.faction-col{background:var(--panel);border:2px solid var(--line);border-left:4px solid var(--fc);box-shadow:3px 3px 0 var(--shadow);padding:10px}
.faction-col[data-faction="Alliance"]{--fc:var(--blue)}.faction-col[data-faction="Horde"]{--fc:var(--red)}.faction-col[data-faction="Either faction"]{--fc:var(--muted)}
.faction-col h3{display:flex;justify-content:space-between;margin:0 0 8px;color:var(--fc);font:7px/1.5 var(--pixel);letter-spacing:1px;text-transform:uppercase}.faction-col h3 small{color:var(--muted);font:inherit}
.race-tiles{display:grid;grid-template-columns:repeat(5,40px);gap:6px;justify-content:space-between}
.race-tile{display:block;width:40px;height:40px;box-sizing:border-box;border:2px solid var(--panel2)}.race-tile img{display:block;width:100%;height:100%;filter:saturate(.75) brightness(.85)}
.race-tile:hover,.race-tile[aria-current="page"]{border-color:var(--gold)}.race-tile:hover img,.race-tile[aria-current="page"] img{filter:none}
.race-tile[aria-current="page"]{box-shadow:0 0 0 2px var(--gold)}.race-tile:focus-visible{outline:2px solid var(--blue);outline-offset:2px}
.skin-page .panel{margin-top:24px}.skin-layout .skin-detail{margin-top:0}.skin-page .summary{color:var(--muted);margin:0 0 16px}.skin-page .summary strong{color:var(--ink)}
.skin-page .specbar{display:flex;gap:2px;height:34px;padding:3px;background:var(--panel2);border:2px solid var(--line);overflow:hidden}
.skin-page .specbar i{min-width:1px;display:block}.skin-page .speckey{display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:8px;margin-top:16px}
.skin-page .speckey span{display:block;background:var(--panel2);padding:8px;border-left:12px solid var(--c);font-size:17px}.skin-page .speckey b{color:var(--ink)}
.skin-page .speckey code{display:block;color:var(--muted);font-size:12px;margin-top:4px}.skin-page .spectrum{display:flex;flex-wrap:wrap;gap:3px;margin:14px 0}
.skin-page .spectrum i{display:block;width:18px;height:18px;border:1px solid #0006}.skin-page .axis{display:flex;justify-content:space-between;color:var(--muted);font-size:16px}
.skin-page .note{color:var(--muted);font-size:16px;margin:16px 0 0;line-height:1.35}
.skin-body-palette+.skin-body-palette{margin-top:22px}.skin-body-palette h3{display:flex;flex-wrap:wrap;align-items:baseline;gap:8px;margin:0 0 8px;color:var(--gold);font:9px/1.5 var(--pixel)}
.skin-body-palette h3 small{color:var(--muted);font:16px/1.2 VT323,monospace}
.skin-heatmap{position:relative;width:100%;aspect-ratio:2/1;background:var(--panel)}
.skin-heat-cell{position:absolute;left:var(--x);top:var(--y);width:var(--w);height:var(--h);box-sizing:border-box;background:var(--c);display:flex;align-items:flex-start;padding:0;overflow:hidden}
.skin-heat-cell span{padding:6px 8px;font:18px/1 VT323,monospace;color:#fff;text-shadow:1px 1px 0 #0008}.skin-heat-cell.dark span{color:#161018;text-shadow:none}
.skin-heat-cell:hover::after{content:"";position:absolute;inset:0;border:2px solid var(--gold)}
.skin-chart{margin-top:24px}.skin-chart-axis,.skin-chart-row{display:grid;grid-template-columns:170px minmax(0,1fr) 42px;gap:10px;align-items:center}
.skin-chart-axis{color:var(--muted);font:8px var(--pixel);padding:0 0 15px}.skin-chart-axis>span:last-child{text-align:right}
.skin-chart-ramp>i{display:block;height:10px;background:linear-gradient(90deg,#101010,#777 50%,#fff);border:1px solid var(--line)}
.skin-chart-ramp small{display:flex;justify-content:space-between;font:7px var(--pixel);margin-top:4px}.skin-chart-ramp b{font-weight:400}
.skin-chart-row{min-height:38px;border-top:1px solid var(--line);color:var(--ink);text-decoration:none}.skin-chart-row{--ring:var(--panel)}.skin-chart-row:hover,.skin-chart-row[aria-current="page"]{--ring:var(--panel2);background:var(--panel2)}
.skin-chart-row[aria-current="page"] .skin-chart-race{color:var(--gold)}.skin-chart-row:focus-visible{outline:2px solid var(--blue);outline-offset:2px}
.skin-chart-race{display:flex;align-items:center;gap:7px;font:8px/1.4 var(--pixel)}.skin-chart-race i{display:block;width:7px;height:7px;flex:none}
.skin-chart-track{position:relative;height:34px;background:repeating-linear-gradient(90deg,transparent 0 calc(10% - 1px),#252b3b calc(10% - 1px) 10%)}
.skin-chart-track>i:not(.median){position:absolute;top:50%;box-shadow:0 0 0 1px var(--ring)}
.skin-chart-track>.median{position:absolute;top:0;bottom:0;width:2px;margin-left:-1px;background:var(--gold);z-index:2}
.skin-chart-count{text-align:right;color:var(--muted)}
@media(max-width:1160px){.skin-heading{display:block}}
@media(max-width:760px){.skin-layout{grid-template-columns:1fr}.race-rail{position:static}.race-tiles{grid-template-columns:repeat(auto-fill,40px);justify-content:start}}
@media(max-width:600px){.skin-page .iname{font-size:25px}.skin-heatmap{aspect-ratio:3/2}.skin-heat-cell{left:var(--nx);top:var(--ny);width:var(--nw);height:var(--nh)}.skin-page .specbar{height:30px}
.skin-chart-axis,.skin-chart-row{grid-template-columns:minmax(0,1fr) 32px;gap:4px 8px}.skin-chart-axis>span:first-child{display:none}
.skin-chart-ramp{grid-column:1}.skin-chart-row{padding:6px 0 4px}.skin-chart-race{grid-column:1/-1}}
</style></head><body><main class="wrap skin-page" data-skin-content>
<header class="ihead"><div class="page-heading"><h1 class="iname">WoWCensus<span class="itopic">Retail Skin Colors</span></h1>$nav</div>
<nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">WoWCensus</a><span aria-hidden="true">/</span><a href="/retail/">Retail</a><span aria-hidden="true">/</span><span aria-current="page">Skin colors</span></nav></header>
<h2 class="skin-heading">Retail skin colors</h2>
<p class="lede">Skin-color choices observed in sampled Retail character profiles.</p>
<div class="skin-layout"><aside class="race-rail" aria-label="Races">$rail</aside>
$detail
</div>
$chart
</main><script>
(() => {
  let pending;
  const cache = new Map();
  function load(url, signal) {
    if (!cache.has(url)) cache.set(url, fetch(url + 'detail.html', {signal, headers: {'X-Requested-With': 'fetch'}}).then(async response => {
      if (!response.ok) throw new Error('Page unavailable');
      return response.text();
    }).catch(error => { cache.delete(url); throw error; }));
    return cache.get(url);
  }
  async function show(url, push, keepChart) {
    if (pending) pending.abort();
    pending = new AbortController();
    const current = document.querySelector('[data-skin-content]');
    current.setAttribute('aria-busy', 'true');
    try {
      const page = new DOMParser().parseFromString(await load(url, pending.signal), 'text/html');
      const next = page.querySelector('.skin-detail');
      if (!next) throw new Error('Page content missing');
      current.querySelector('.skin-detail').replaceWith(next);
      current.querySelectorAll('.race-group [aria-current="page"], .skin-chart-row[aria-current="page"]').forEach(node => node.removeAttribute('aria-current'));
      const selected = new URL(url).pathname.split('/').filter(Boolean).at(-1);
      current.querySelectorAll('a[data-skin-link]').forEach(node => {
        if (new URL(node.href).pathname.split('/').filter(Boolean).at(-1) === selected) node.setAttribute('aria-current', 'page');
      });
      const selectedLink = [...current.querySelectorAll('.race-group a[data-skin-link]')].find(node => node.href === url);
      document.title = 'Retail skin colors · ' + (selectedLink?.getAttribute('aria-label') || 'All races') + ' · WoWCensus';
      document.querySelector('link[rel="canonical"]').href = url;
      if (push) history.pushState({}, '', url);
      if (keepChart) current.querySelector('[data-skin-chart]').scrollIntoView({block: 'start'});
      if (push) current.querySelector(keepChart ? '[data-skin-chart] [aria-current="page"]' : '.race-group [aria-current="page"]')?.focus({preventScroll: true});
    } catch (error) {
      if (error.name === 'AbortError') return;
      location.assign(url);
    } finally {
      current.removeAttribute('aria-busy');
    }
  }
  document.addEventListener('click', event => {
    const link = event.target.closest('a[data-skin-link]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (link.href === location.href) return;
    event.preventDefault();
    show(link.href, true, !!link.closest('[data-skin-chart]'));
  });
  document.addEventListener('pointerover', event => {
    const link = event.target.closest('a[data-skin-link]');
    if (link && !cache.has(link.href)) load(link.href).catch(() => {});
  });
  window.addEventListener('popstate', () => show(location.href, false, false));
})();
</script></body></html>
HTML;
}
