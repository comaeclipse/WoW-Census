<?php
// Router for PHP's built-in server (php -S): files in public/ are served as
// they are, every other path goes to the front controller like Apache's rewrite.
$file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($_SERVER['REQUEST_URI'] !== '/' && is_file($file) && basename($file) !== 'cli-router.php') return false;
require __DIR__ . '/index.php';
