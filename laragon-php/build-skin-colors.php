<?php
// Build the PostgreSQL-backed Retail appearance page as static S3-ready HTML.
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

$root = $argv[1] ?? dirname(__DIR__) . '/static';
$base = rtrim($root, '/\\') . '/retail/skin-colors';
$cssName = stylesheet()['name'];
$slugs = [null];
foreach (retail_skin_color_factions() as $races)
  foreach ($races as $race) $slugs[] = skin_color_slug($race);
foreach ($slugs as $slug) {
  $directory = $base . ($slug === null ? '' : '/' . $slug);
  if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory))
    throw new RuntimeException("Cannot create $directory");
  $page = with_analytics(localize_content(retail_skin_colors_page($cssName, $slug)));
  file_put_contents($directory . '/index.html', $page);
  if (!preg_match('~<section class="panel skin-detail".*?</section>~s', $page, $detail))
    throw new RuntimeException("Skin detail missing for $directory");
  file_put_contents($directory . '/detail.html', $detail[0]);
  echo $directory . "/index.html\n";
}
