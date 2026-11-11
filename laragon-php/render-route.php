<?php
// Render one route from the command line, e.g. to diff a page before and after a change.
declare(strict_types=1);
if ($argc !== 2) {
  fwrite(STDERR, "Usage: php render-route.php <route>\n");
  exit(2);
}
$_SERVER['REQUEST_URI'] = $argv[1];
require __DIR__ . '/public/index.php';
