<?php
namespace Wow2008;
/**
 * Page: a site page with no 2008 design of its own (Race + Class), shown inside the 2008 frame.
 * Vars: $title (string), $body (string, the page's own markup and scripts), $styles (string, its <style> blocks),
 *       $source (?string), $art (string, banner art)
 */
?>
  <main class="main w8-framed">
    <?= component('crumb', ['trail' => [['label' => $title]]]) ?>

    <?= component('banner', ['art' => $art, 'title' => $title, 'subtitle' => $source !== null ? edition_subtitle($source) : 'WOWCENSUS']) ?>

<?= $styles ?>
<?= $body ?>
  </main>
