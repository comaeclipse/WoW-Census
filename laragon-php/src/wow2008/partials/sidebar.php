<?php
namespace Wow2008;
/**
 * Left sidebar: the site's own pages, then one section per game edition, each
 * listing that edition's census pages.
 * Vars: $source (?string, current edition), $page (?string, current page key)
 */
$requested = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
?>
  <nav class="nav">
    <h3>Site <button class="nav-toggle" type="button" aria-label="Collapse Site" aria-controls="nav-site" aria-expanded="true"></button></h3>
    <ul class="cur" id="nav-site">
<?php foreach ([['Home', '/'], ['Blog', '/blog.php'], ['FAQ', '/faq.php'], ['Shoutbox', '/shoutbox/'], ['Contact', '/contact']] as [$label, $href]):
        $active = $requested === $href; ?>
      <li><a href="<?= e($href) ?>"<?= $active ? ' style="color:#fff" aria-current="page"' : '' ?>><?= $active ? '» ' : '' ?><?= e($label) ?></a></li>
<?php endforeach; ?>
    </ul>
<?php foreach (\EDITIONS as $key => $ed):
    // The Forever beta archive is only listed while you're on one of its pages.
    if ($key === 'classic-beta' && $source !== $key) continue;
    // WoW: Forever and the edition you're browsing start open; the rest start collapsed.
    $open = $key === 'forever' || $key === $source; ?>
    <h3><?= e(edition_name($key)) ?> <button class="nav-toggle" type="button" aria-label="<?= $open ? 'Collapse' : 'Expand' ?> <?= e(edition_name($key)) ?>" aria-controls="nav-<?= e($key) ?>" aria-expanded="<?= $open ? 'true' : 'false' ?>"></button></h3>
    <ul id="nav-<?= e($key) ?>"<?= $key === $source ? ' class="cur"' : '' ?><?= $open ? '' : ' hidden' ?>>
<?php foreach (edition_links($key) as [$label, $href, $sub, $pageKey]):
        $active = $key === $source && $pageKey === $page; ?>
      <li<?= $sub ? ' class="sub"' : '' ?>><a href="<?= e($href) ?>"<?= $active ? ' style="color:#fff" aria-current="page"' : '' ?>><?= $active ? '» ' : '' ?><?= e($label) ?></a></li>
<?php endforeach; ?>
    </ul>
<?php if ($key === 'retail'): ?>
    <div class="lamp-badges<?= $source === 'retail' ? ' is-retail' : '' ?>" aria-label="Retro web badges">
      <img src="<?= e(asset('lamp-linux.png')) ?>" width="88" height="31" alt="GNU/Linux badge">
      <img src="<?= e(asset('lamp-apache.png')) ?>" width="88" height="31" alt="Apache badge">
      <img src="<?= e(asset('lamp-mysql.png')) ?>" width="88" height="31" alt="MySQL badge">
      <img src="<?= e(asset('lamp-php.png')) ?>" width="88" height="31" alt="PHP badge">
      <img src="<?= e(asset('badge-amd-k6.png')) ?>" width="88" height="31" alt="AMD K6 Upgrade Now badge">
      <img src="<?= e(asset('badge-take-back-the-web.png')) ?>" width="88" height="31" alt="Take back the web badge">
    </div>
<?php endif; ?>

<?php endforeach; ?>
  </nav>
