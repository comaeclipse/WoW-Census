<?php
namespace Wow2008;
/**
 * Page shell shared by every page.
 * Vars: $head (string, the site page's title, meta and analytics tags), $content (string, already rendered),
 *       $wide (bool), $source (?string), $page (?string), $route (array, exposed to page JS as window.ROUTE)
 */
?>
<!DOCTYPE html>
<html lang="en" data-skin="wow2008">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?= $head ?>

<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96">
<link rel="icon" href="/favicon-96.png" type="image/png" sizes="96x96">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;700;900&family=Cinzel+Decorative:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
</head>
<body>

<?= render('partials/backdrop.php') ?>
<?= render('partials/topbar.php') ?>
<?= render('partials/header.php', ['source' => $source]) ?>
<!-- ======= Page ======= -->
<div class="page<?= $wide ? ' wide' : '' ?>">

<?= render('partials/sidebar.php', ['source' => $source, 'page' => $page]) ?>

<?= $content ?>
</div>

<div id="tip"></div>

<?= render('partials/footer.php') ?>

<script src="<?= e(asset('js/common.js')) ?>"></script>
<script src="<?= e(asset('js/site.js')) ?>"></script>
<script>window.ROUTE =<?= json_encode($route, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?= section('scripts') ?>
</body>
</html>
