<?php
namespace Wow2008;
/**
 * Amber note box with an info icon under a page banner.
 *
 * Props:
 *   title  string
 *   html   string, the note's text (raw HTML, may hold links)
 */
?>
    <div class="w8-notice" role="note"><img class="ico" src="/wow2008/notice-info.png" alt="" width="32" height="32"><div>
      <b class="t"><?= e($title) ?></b>
      <p><?= $html ?></p>
    </div></div>
