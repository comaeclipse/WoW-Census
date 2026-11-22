<?php
namespace Wow2008;
/**
 * Amber "!" note box under a page banner.
 *
 * Props:
 *   title  string
 *   html   string, the note's text (raw HTML, may hold links)
 */
?>
    <div class="w8-notice" role="note"><span class="ico" aria-hidden="true">!</span><div>
      <b class="t"><?= e($title) ?></b>
      <p><?= $html ?></p>
    </div></div>
