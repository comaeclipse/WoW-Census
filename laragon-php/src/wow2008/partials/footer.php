<?php namespace Wow2008; ?>
<footer class="footer">
  <div style="flex:1">
    <div class="links"><a href="/">WoWCensus</a><a href="/realms">Realms</a><a href="https://discord.gg/Dzw8H3QZX" target="_blank" rel="noopener">Discord</a><a href="/llms.txt">llms.txt</a></div>
<?php if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/'): ?>
    <div class="footer-b88"><?= render('partials/support-buttons.php') ?></div>
<?php endif; ?>
    <p>WoWCensus samples characters seen in-game; counts describe observed characters, not unique players or a live online population. Not affiliated with or endorsed by Blizzard Entertainment.</p>
  </div>
</footer>
