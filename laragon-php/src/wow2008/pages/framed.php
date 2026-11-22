<?php
namespace Wow2008;
/**
 * Page: a site page with no 2008 design of its own (Race + Class), shown inside the 2008 frame.
 * Vars: $title (string), $body (string, the page's own markup and scripts), $styles (string, its <style> blocks),
 *       $source (?string), $art (string, banner art), $subtitle (?string, banner subtitle override), $icon (?string, banner icon URL)
 */
?>
  <main class="main w8-framed">
    <?= component('crumb', ['trail' => $page === 'spells' ? [['label' => 'Spells'], ['label' => $title]] : [['label' => $title]]]) ?>

    <?= component('banner', ['art' => $art, 'title' => $title, 'subtitle' => $subtitle ?? ($source !== null ? edition_subtitle($source) : 'WOWCENSUS'), 'icon' => $icon ?? null]) ?>

<?= $styles ?>
<?= $body ?>
  </main>
