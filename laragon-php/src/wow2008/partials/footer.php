<?php namespace Wow2008; ?>
<footer class="footer">
  <div style="flex:1">
    <div class="links"><a href="/">WoWCensus</a><a href="/realms">Realms</a><a href="/faq.php">FAQ</a><a href="https://discord.gg/Dzw8H3QZX" target="_blank" rel="noopener">Discord</a><?php if (isset($_SERVER['REQUEST_TIME_FLOAT'])): ?><span style="color:#8a7a5a">Page generated in <?= number_format(microtime(true) - (float)$_SERVER['REQUEST_TIME_FLOAT'], 4) ?> seconds</span><?php endif ?></div>
<?php if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/'): ?>
    <div class="footer-b88"><?= render('partials/support-buttons.php') ?></div>
<?php endif; ?>
    <p>WoWCensus samples characters seen in-game; counts describe observed characters, not unique players or a live online population. Not affiliated with or endorsed by Blizzard Entertainment.</p>
  </div>
</footer>
