<?php
// Support page at /donate.php, a plain PHP file like faq.php: the server-fund bar and the Ko-fi / Buy Me a Coffee buttons.
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Wow2008.php';

header('X-Content-Type-Options: nosniff');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header('Cloudflare-CDN-Cache-Control: no-store');

function donate_escape(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$raised = \Wow2008\SUPPORT_RAISED;
$goal = \Wow2008\SUPPORT_GOAL;
$pct = $goal > 0 ? max(0, min(1, $raised / $goal)) : 0;
$buttons = \Wow2008\render('partials/support-buttons.php');

$nav = edition_nav('forever', 'index.html');
$games = str_replace(' aria-current="true"', '', $nav['games']);
$games = str_replace('</nav></div>', '<a class="game" aria-current="true" href="/donate.php">Support</a></nav></div>', $games);
$crumbs = '<nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">WoWCensus</a><span aria-hidden="true">/</span><span aria-current="page">Support</span></nav>';
$cssName = stylesheet()['name'];

ob_start();
?><!doctype html>
<html lang="en"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Support WoWCensus – WoWCensus</title>
  <meta name="description" content="Help cover the monthly cost of running WoWCensus.">
  <link rel="icon" href="/favicon.ico">
  <link rel="stylesheet" href="/<?= donate_escape($cssName) ?>">
</head><body class="donate-page">
<div class="wrap">
  <header class="ihead">
    <div class="page-heading"><h1 class="iname">WoWCensus<span class="itopic">Support</span></h1><?= $games ?></div>
    <?= $crumbs ?>
  </header>
  <main>
    <section class="panel donate-panel"><div class="ptitle">Server fund</div>
      <div class="donate-bar">
        <span class="w8-gbar<?= $pct <= 0 ? ' empty' : '' ?>" style="--p:<?= donate_escape((string)round($pct, 4)) ?>;--h:28px" role="progressbar" aria-label="Monthly server upkeep funded" aria-valuemin="0" aria-valuemax="<?= donate_escape((string)$goal) ?>" aria-valuenow="<?= donate_escape((string)$raised) ?>"><span class="track"><span class="fill"></span></span></span>
        <span class="donate-amt">$<?= donate_escape((string)$raised) ?> / $<?= donate_escape((string)$goal) ?> this month</span>
      </div>
      <div class="sup-btns"><?= $buttons ?></div>
      <p>WoWCensus is built and maintained by one person. I work a full-time job and I'm a dad to three girls, so the site gets built in whatever evenings and weekends are left over. The project is entirely self-funded.</p>
      <p>If you found the information on this site helpful or interesting, please consider donating towards the expenses of running it.</p>
    </section>
  </main>
</div>
</body></html>
<?php
$html = with_skin(with_analytics(localize_content(ob_get_clean())));
echo str_replace('</head>', '<style>.donate-panel{text-align:center}.donate-bar{display:flex;flex-direction:column;align-items:center;gap:8px;margin:6px 0 18px}.donate-amt{font-weight:bold}.donate-panel p{line-height:1.45;margin:18px auto 4px;max-width:520px}'
  . '.sup-btns{display:flex;justify-content:center;flex-wrap:wrap;gap:6px}'
  . 'html:not([data-skin=wow2008]) .w8-gbar{display:block;width:340px;max-width:100%;height:16px;border:1px solid #000;background:#2c2011;position:relative;overflow:hidden}html:not([data-skin=wow2008]) .w8-gbar::after{display:none}html:not([data-skin=wow2008]) .w8-gbar .track{position:static;display:block;height:100%;background:none}html:not([data-skin=wow2008]) .w8-gbar .fill{display:block;height:100%;width:calc(var(--p) * 100%);background:#4caf50}'
  . 'html:not([data-skin=wow2008]) .b88{width:88px;height:31px;box-sizing:border-box;display:flex;border:1px solid #000;text-decoration:none;font:bold 10px/1 Verdana,Tahoma,sans-serif;color:#fff;text-align:left}html:not([data-skin=wow2008]) .b88-ico{width:29px;flex:none;display:flex;align-items:center;justify-content:center;border-right:1px solid #000}html:not([data-skin=wow2008]) .b88-ico svg{width:21px;height:16px}html:not([data-skin=wow2008]) .b88-txt{flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center}html:not([data-skin=wow2008]) .b88-txt small{font:7px/1.2 Verdana,sans-serif;margin-bottom:1px}html:not([data-skin=wow2008]) .b88.kofi{background:#29abe0}html:not([data-skin=wow2008]) .b88.bmc{background:#ffdd00;color:#151414}'
  . '</style></head>', $html);
