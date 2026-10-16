<?php
// Render one route from CLI for full-versus-compact output comparison.
declare(strict_types=1);
if ($argc !== 2) {
  fwrite(STDERR, "Usage: php render-route.php <route>\n");
  exit(2);
}
$_SERVER['REQUEST_URI'] = $argv[1];
require __DIR__ . '/public/index.php';
