<?php
// Build the static site from PostgreSQL with the same PHP that serves :8081.
//
//   php laragon-php/build-static.php [--sources=classic,sod] [--out=static] [--dry-run]
//
// Every file is rendered and compared with the one already in the output
// directory; only files whose content changed are written. "Page generated"
// times are left out of that comparison, so an unchanged page keeps the time
// it last changed. --sources limits the per-edition files to those editions
// (the importer prints which ones it changed); shared files are always checked.
// tools/deploy-s3.sh then uploads only the files that differ from the bucket.

declare(strict_types=1);
ini_set('memory_limit', '2G');
require __DIR__ . '/src/bootstrap.php';

$repo = dirname(__DIR__);
$opts = getopt('', ['sources:', 'out:', 'dry-run']);
$out = rtrim(str_replace('\\', '/', $opts['out'] ?? "$repo/static"), '/');
if (!preg_match('#^([A-Za-z]:)?/#', $out)) $out = "$repo/$out";
$dryRun = isset($opts['dry-run']);
$sources = isset($opts['sources']) ? array_values(array_filter(explode(',', $opts['sources']))) : array_keys(EDITIONS);
foreach ($sources as $s) if (!isset(EDITIONS[$s])) { fwrite(STDERR, "Unknown edition key: $s\n"); exit(2); }

$stats = ['written' => [], 'unchanged' => 0, 'removed' => []];
$generated = '/Page generated: \d{4}-\d\d-\d\d \d\d:\d\dZ/';

function put(string $rel, string $content, bool $timestamped = false): void {
  global $out, $stats, $dryRun, $generated;
  $file = "$out/$rel";
  if (str_ends_with($rel, '.html') && !str_starts_with($rel, 'assets/')) $content = with_analytics($content);
  if (is_file($file)) {
    $old = file_get_contents($file);
    $same = $timestamped ? preg_replace($generated, '', $old) === preg_replace($generated, '', $content) : $old === $content;
    if ($same) { $stats['unchanged']++; return; }
  }
  $stats['written'][] = $rel;
  if ($dryRun) return;
  if (!is_dir(dirname($file))) mkdir(dirname($file), 0777, true);
  file_put_contents($file, $content);
}

function copy_tree(string $from, string $to): void {
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) {
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($from) + 1));
    put("$to/$rel", file_get_contents($f->getPathname()));
  }
}

$started = microtime(true);
['css' => $css, 'name' => $cssName] = stylesheet();
$report = is_file("$out/build-report.json") ? json_decode(file_get_contents("$out/build-report.json"), true) : [];
$report['editions'] ??= [];

foreach ($sources as $source) {
  $dir = ltrim(edition_path($source), '/');
  foreach (PAGES as $page) {
    $html = edition_page_html($source, $page);
    put("{$dir}$page.html", $html, true);
    $packed = $page === 'talents' ? pack_talent_records(load_inspects($source)['records']) : null;
    put("{$dir}$page.md", html_to_markdown($html, $packed), true);
  }
  put("{$dir}realms.html", realms_page_html($source), true);
  // Filter data files are content-hashed: write the current ones, drop the rest.
  $keep = [];
  foreach (['talents', 'gear'] as $page) {
    $data = render_edition_data($source, $page);
    if (!$data) continue;
    put($dir . $data['file'], $data['json'] . "\n");
    $keep[] = basename($data['file']);
  }
  foreach (glob("$out/{$dir}data/*.json") ?: [] as $file)
    if (!in_array(basename($file), $keep, true)) { $stats['removed'][] = "{$dir}data/" . basename($file); if (!$dryRun) unlink($file); }
  foreach (race_profiles()[$source] ?? [] as $race => $p) {
    $html = race_page_html($source, $race);
    put(ltrim($p['path'], '/') . 'index.html', $html);
    put(ltrim($p['path'], '/') . 'index.md', html_to_markdown($html));
  }
  foreach (class_profiles()[$source]['classes'] ?? [] as $cf) {
    $html = class_page_html($source, $cf);
    put(ltrim(class_route($source, $cf), '/') . 'index.html', $html);
    put(ltrim(class_route($source, $cf), '/') . 'index.md', html_to_markdown($html));
  }
  put("{$dir}census.json", census_json($source));
  put("{$dir}$cssName", localize_content($css));
  foreach (glob("$out/{$dir}style.*.css") ?: [] as $file)
    if (preg_match('/^style\.[0-9a-f]{12}\.css$/', basename($file)) && basename($file) !== $cssName) {
      $stats['removed'][] = $dir . basename($file);
      if (!$dryRun) unlink($file);
    }
  $census = load_census($source);
  $report['editions'][$source] = [
    'realmDatasets' => count(edition_snaps($source)),
    'censusCharacters' => array_sum(array_map(fn($g) => $g['characters'], $census['groups'])),
    'inspections' => count(load_inspects($source)['records']),
  ];
  fwrite(STDERR, sprintf("%s: %d census characters, %d auction datasets, %d inspections\n", $source,
    $report['editions'][$source]['censusCharacters'], $report['editions'][$source]['realmDatasets'], $report['editions'][$source]['inspections']));
}

// Files every edition shares.
put('census.css', localize_content(census_css()));
put('sitemap.xml', sitemap_xml());
put('robots.txt', robots_txt());
put('llms.txt', llms_txt());
put('.well-known/ai-catalog.json', ai_catalog_json());
put('404.html', not_found_html());
put('_headers', headers_file());
copy_tree("$repo/site/public/fonts", 'fonts');
put('favicon.ico', file_get_contents("$repo/site/public/favicon.ico"));
put('favicon-96.png', file_get_contents("$repo/site/public/favicon-96.png"));
put('notice-discord.png', file_get_contents("$repo/site/public/notice-discord.png"));
put('role-icons.png', file_get_contents("$repo/site/public/role-icons.png"));
if (is_dir("$repo/assets/wow.zamimg.com")) copy_tree("$repo/assets/wow.zamimg.com", 'assets');

if ($stats['written'] || $stats['removed']) {
  $report['builtAt'] = gmdate('Y-m-d\TH:i:s') . '.000Z';
  $report['source'] = 'PostgreSQL ' . ML_CONFIG['db']['name'] . '.' . ML_CONFIG['db']['schema'];
  ksort($report);
  put('build-report.json', json_encode(['builtAt' => $report['builtAt'], 'source' => $report['source'], 'editions' => $report['editions']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

foreach ($stats['written'] as $rel) echo ($dryRun ? 'would write ' : 'wrote ') . $rel . "\n";
foreach ($stats['removed'] as $rel) echo ($dryRun ? 'would remove ' : 'removed ') . $rel . "\n";
printf("%s %d files, %d unchanged, %d removed in %.1fs -> %s\n", $dryRun ? 'Would write' : 'Wrote',
  count($stats['written']), $stats['unchanged'], count($stats['removed']), microtime(true) - $started, $out);
if (!$dryRun && $stats['written']) {
  passthru('python -B ' . escapeshellarg("$repo/tools/verify-static-site.py") . ' ' . escapeshellarg($out), $code);
  exit($code);
}
