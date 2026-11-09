<?php
// Frequently asked questions at /faq.php, a plain PHP file like blog.php.
// Plain visitor-facing answers; keep the technical detail out.
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header('X-Content-Type-Options: nosniff');

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header('Cloudflare-CDN-Cache-Control: no-store');

function faq_escape(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// The editions the site covers, each linked to its census page.
$editionLinks = [];
foreach (EDITIONS as $source => $edition)
  if ($source !== 'classic-beta') $editionLinks[] = '<li><a href="' . faq_escape(page_path($source, 'index')) . '">' . faq_escape($edition['label']) . '</a></li>';
$betaLink = '<a href="' . faq_escape(page_path('classic-beta', 'index')) . '">WoW: Forever beta</a>';

// [section title, [[question, answer HTML], ...]]. Answers are trusted markup written here.
$sections = [
  ['About the site', [
    ['What is WoWCensus?',
      '<p>WoWCensus is a fan-made census of World of Warcraft. It looks at the characters people are playing and shows the overall picture: which races and classes are popular, how realms compare, how guilds are sized, what talents and gear people use, and what the auction house looks like.</p>'
      . '<p>The aim is to step back from your own corner of the game and see what a whole realm or edition looks like.</p>'],
    ['Is it affiliated with Blizzard?',
      '<p>No. WoWCensus is not affiliated with or endorsed by Blizzard Entertainment. World of Warcraft and related names belong to Blizzard.</p>'],
    ['Which game editions do you cover?',
      '<p>Each edition has its own census, realm list, guilds, talents, gear and auction house pages:</p><ul>' . implode('', $editionLinks) . '</ul>'
      . '<p>There is also an archive of the ' . $betaLink . ' for anyone curious how the beta looked.</p>'],
    ['Is the site free?',
      '<p>Yes. There are no accounts, no paywall and nothing to sign up for.</p>'],
    ['Why does the site look different sometimes?',
      '<p>The site comes in three looks: <b>2008</b>, <b>Retail</b> and <b>Pixel</b>. You can switch between them with the skin picker on any page, and your choice is remembered. The numbers are the same in every one.</p>'],
  ]],
  ['About the numbers', [
    ['Where do the numbers come from?',
      '<p>From characters seen in the game. While playing, we note the characters we come across — their race, class, level, realm and guild — and add them up. Everything we record is information any player can see in game; we have no access to accounts or to Blizzard\'s own data.</p>'],
    ['Is this how many people are playing?',
      '<p>No. The census shows the <b>mix</b> of characters — for example what share of the Horde are Undead, or how common Paladins are — not how many people are online. A realm with more characters in our sample may simply be one we spent more time on.</p>'
      . '<p>We also count characters, not people. One player with three alts counts three times.</p>'],
    ['Why are the figures estimates?',
      '<p>Because we can only count the characters we actually see. Who shows up depends on which realms we visited, at what time of day, and on which faction. Busy realms and popular hours are easier to sample than quiet ones.</p>'
      . '<p>So treat the percentages as a good sketch of the population, not an exact count. Larger samples are more reliable than small ones, and differences of a percent or two usually aren\'t meaningful.</p>'],
    ['Why do you compare races within a faction?',
      '<p>How long we spent on each faction changes the raw totals, but not the mix inside a faction. Comparing Alliance races with each other, and Horde races with each other, gives a fairer picture than mixing both together.</p>'],
    ['How up to date is it?',
      '<p>The pages always show the latest data we have, and new sightings are added as they come in. Each census page shows the date of its most recent observation, so you can see how fresh it is.</p>'
      . '<p>Editions we visit less often are updated less often, so their numbers can be a little older.</p>'],
    ['Why is my realm missing, or so small?',
      '<p>Most likely we haven\'t spent much time there yet. Smaller and quieter realms take longer to build up a useful sample. The <a href="/realms">realm list</a> shows how many characters we have seen on each realm.</p>'],
  ]],
  ['Getting involved', [
    ['How can I help?',
      '<p>We are looking for players who would like to contribute. The best place to start is our <a href="https://discord.gg/Dzw8H3QZX" target="_blank" rel="noopener">Discord</a>, where you can ask questions and find out how to get involved.</p>'
      . '<p>Telling other players about the site helps too.</p>'],
    ['Who has helped?',
      '<p>A big thank you to <b>Duxa</b>, <b>Vertical Bar</b> and <b>HockeyTemper</b> from our <a href="https://discord.gg/Dzw8H3QZX" target="_blank" rel="noopener">Discord</a> for their contributions. WoWCensus is better for their help.</p>'],
    ['How do I get in touch?',
      '<p>Use the <a href="/contact">contact form</a>, join the <a href="https://discord.gg/Dzw8H3QZX" target="_blank" rel="noopener">Discord</a>, or leave a note in the <a href="/shoutbox/">shoutbox</a>. Corrections, ideas and bug reports are all welcome.</p>'],
    ['Where can I find out what\'s new?',
      '<p>Changes and write-ups are posted on the <a href="/blog.php">blog</a>.</p>'],
  ]],
];

$nav = edition_nav('forever', 'index.html');
$games = str_replace(' aria-current="true"', '', $nav['games']);
$games = str_replace('</nav></div>', '<a class="game" aria-current="true" href="/faq.php">FAQ</a></nav></div>', $games);
$crumbs = '<nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">WoWCensus</a><span aria-hidden="true">/</span><span aria-current="page">FAQ</span></nav>';
$cssName = stylesheet()['name'];

ob_start();
?><!doctype html>
<html lang="en"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Frequently Asked Questions – WoWCensus</title>
  <meta name="description" content="Answers to common questions about WoWCensus: what it is, where the numbers come from, how current they are and how to get involved.">
  <link rel="icon" href="/favicon.ico">
  <link rel="stylesheet" href="/<?= faq_escape($cssName) ?>">
</head><body class="faq-page">
<div class="wrap">
  <header class="ihead">
    <div class="page-heading"><h1 class="iname">WoWCensus<span class="itopic">Frequently Asked Questions</span></h1><?= $games ?></div>
    <?= $crumbs ?>
  </header>
  <main>
    <section class="panel faq-intro"><div class="ptitle">FAQ</div><p>Answers to the questions people ask most about WoWCensus. Can't find yours? <a href="/contact">Ask us</a>.</p></section>
    <div class="faq-list">
<?php foreach ($sections as [$title, $items]): ?>
      <section class="panel faq-section"><div class="ptitle"><?= faq_escape($title) ?></div>
<?php foreach ($items as [$question, $answer]): ?>
        <h3 class="faq-q"><?= faq_escape($question) ?></h3><?= $answer ?>

<?php endforeach; ?>
      </section>
<?php endforeach; ?>
    </div>
  </main>
</div>
</body></html>
<?php
// Added after skinning: the Retail skin drops a page's own <style> blocks.
$html = with_skin(with_analytics(localize_content(ob_get_clean())));
echo str_replace('</head>', '<style>.faq-intro{margin-bottom:18px}.faq-intro p{margin:0}.faq-list{display:grid;gap:18px}.faq-q{margin:18px 0 6px;font-size:1.05em;line-height:1.3}.faq-section .ptitle+.faq-q{margin-top:8px}.faq-section p{line-height:1.45;margin:0 0 10px}.faq-section ul{margin:0 0 10px;padding-left:22px;line-height:1.5}</style></head>', $html);
