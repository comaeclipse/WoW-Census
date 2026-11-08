<?php
namespace Wow2008;
/**
 * Not-found page.
 * Vars: $requested (string)
 */
?>
  <style>
    .lost{display:flex;align-items:center;justify-content:center;gap:28px;flex-wrap:wrap;padding:18px 10px 26px;text-align:left}
    .lost img{width:280px;height:280px;flex:none}
    .lost-text{max-width:340px;color:#5a3a10}
    .lost-text h1{margin:0 0 10px;font:700 26px Tahoma,Arial,sans-serif;color:#7a1a00}
    .lost-text p{margin:0 0 10px;font:13px/1.5 Tahoma,Arial,sans-serif}
    .lost-text .lost-lead{font-weight:700;font-size:15px;color:#5a2a00}
    .lost-text code{font-size:11px;word-break:break-all}
    .lost-btn{display:inline-block;margin-top:8px;padding:7px 16px;border:1px solid #5a2a00;border-radius:3px;
      background:linear-gradient(#e8b84a,#b5781c);color:#2a1200;font:700 13px Tahoma,Arial,sans-serif;text-decoration:none;
      box-shadow:inset 0 1px 0 rgba(255,255,255,.5),0 1px 2px rgba(0,0,0,.35)}
    .lost-btn:hover{background:linear-gradient(#f4ca62,#c88a26)}
  </style>
  <main class="main">
    <?= component('crumb', ['trail' => [['label' => 'Page Not Found']]]) ?>

    <?php component_start('section', ['title' => 'Page Not Found', 'icon' => 'r']) ?>
        <div class="lost">
          <img src="/murloc-404.webp" width="280" height="280" alt="A confused Murloc holding a torn map marked 404">
          <div class="lost-text">
            <h1>404 — MRGLGLGL!</h1>
            <p class="lost-lead">A wild Murloc has eaten this page.</p>
            <p>We tried asking for it back, but negotiations broke down into gurgling.</p>
            <p>Nothing lives at <code><?= e($requested) ?></code>.</p>
            <a class="lost-btn" href="<?= e(url()) ?>">Respawn at Homepage</a>
          </div>
        </div>
    <?php component_end() ?>
  </main>
