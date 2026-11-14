<?php
namespace Wow2008;
$fundPct = SUPPORT_GOAL > 0 ? max(0, min(1, SUPPORT_RAISED / SUPPORT_GOAL)) : 0;
?>
<!-- ======= Top utility bar ======= -->
<div class="topbar">
  <div class="inner">
    <a class="w8-fund" href="/donate.php" title="Monthly server upkeep: $<?= e(SUPPORT_RAISED) ?> of $<?= e(SUPPORT_GOAL) ?>">
      <span class="cap">Server fund</span>
      <span class="w8-gbar<?= $fundPct <= 0 ? ' empty' : '' ?>" style="--p:<?= e(round($fundPct, 4)) ?>" role="progressbar" aria-label="Monthly server upkeep funded" aria-valuemin="0" aria-valuemax="<?= e(SUPPORT_GOAL) ?>" aria-valuenow="<?= e(SUPPORT_RAISED) ?>"><span class="track"><span class="fill"></span></span></span>
      <span class="amt">$<?= e(SUPPORT_RAISED) ?> / $<?= e(SUPPORT_GOAL) ?></span>
    </a>
    <a href="/">WoWCensus.io</a>
    <a href="<?= e(url('realms')) ?>">Realms</a>
    <a href="https://discord.gg/Dzw8H3QZX" target="_blank" rel="noopener">Discord</a>
  </div>
</div>
