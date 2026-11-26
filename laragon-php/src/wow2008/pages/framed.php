<?php
namespace Wow2008;
/**
 * Page: a site page with no 2008 design of its own (Race + Class), shown inside the 2008 frame.
 * Vars: $title (string), $body (string, the page's own markup and scripts), $styles (string, its <style> blocks),
 *       $source (?string), $art (string, banner art), $subtitle (?string, banner subtitle override), $icon (?string, banner icon URL),
 *       $intro (?string, banner description), $tools (?string, raw HTML drawn top right of the banner),
 *       $overlay (?string, raw HTML drawn bottom right of the banner), $notice (?string, raw HTML note under the banner)
 */
?>
  <main class="main w8-framed">
    <?= component('crumb', ['trail' => $page === 'spells' ? [['label' => 'Spells'], ['label' => $title]] : [['label' => $title]]]) ?>

    <?= component('banner', ['art' => $art, 'title' => $title, 'subtitle' => $subtitle ?? ($source !== null ? edition_subtitle($source) : 'WOWCENSUS'), 'icon' => $icon ?? null, 'intro' => $intro ?? null, 'tools' => $tools ?? null, 'overlay' => $overlay ?? null]) ?>
<?php if (!empty($notice)): ?>
<?= $notice ?>
<?php endif ?>

<?= $styles ?>
<?= $body ?>
  </main>
