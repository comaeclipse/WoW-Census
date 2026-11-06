<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Cloudflare-CDN-Cache-Control: no-store');

$posts = require dirname(__DIR__) . '/src/blog-posts.php';
$id = $_GET['id'] ?? null;
if ($id !== null && (!is_string($id) || !preg_match('/^[1-9][0-9]*$/D', $id) || strlen($id) > 18)) {
    http_response_code(400);
    $error = 'Invalid post ID';
} elseif ($id !== null && !array_key_exists((int)$id, $posts)) {
    http_response_code(404);
    $error = 'Post not found';
}

function blog_escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function blog_body(string $value): string {
    $paragraphs = preg_split('/\R\s*\R/u', trim($value)) ?: [];
    return implode("\n", array_map(
        static fn(string $paragraph): string => '<p>' . nl2br(blog_escape(trim($paragraph)), false) . '</p>',
        array_filter($paragraphs, static fn(string $paragraph): bool => trim($paragraph) !== '')
    ));
}

uksort($posts, static fn(int|string $a, int|string $b): int => strcmp($posts[$b]['date'], $posts[$a]['date']) ?: ((int)$b <=> (int)$a));
$single = isset($id, $posts[(int)$id]) && !isset($error);
$title = isset($error) ? $error : ($single ? $posts[(int)$id]['title'] : 'Updates');

// Share the site's navigation and stylesheet so the blog follows theme changes.
$nav = edition_nav('classic-beta', 'index.html');
$games = str_replace(' aria-current="true"', '', $nav['games']);
$games = str_replace('</nav></div>', '<a class="game" aria-current="true" href="/blog.php">Updates</a></nav></div>', $games);
$crumbs = '<nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">WoWCensus</a><span aria-hidden="true">/</span><span aria-current="page">Updates</span></nav>';
$cssName = stylesheet()['name'];

ob_start();
?><!doctype html>
<html lang="en"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= blog_escape($title) ?> | WoWCensus</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="stylesheet" href="/<?= blog_escape($cssName) ?>">
</head><body class="blog-page">
<div class="wrap">
  <header class="ihead">
    <div class="page-heading"><h1 class="iname">WoWCensus<span class="itopic">Project updates</span></h1><?= $games ?></div>
    <?= $crumbs ?>
  </header>
  <main>
    <?php if (isset($error)): ?>
      <section class="panel"><div class="ptitle">Updates</div><h2><?= blog_escape($error) ?></h2><p><a href="/blog.php">Back to updates</a></p></section>
    <?php elseif ($single): $post = $posts[(int)$id]; ?>
      <p><a href="/blog.php">← All updates</a></p>
      <article class="panel blog-post"><div class="ptitle"><time datetime="<?= blog_escape($post['date']) ?>"><?= blog_escape($post['date']) ?></time></div>
        <h2><?= blog_escape($post['title']) ?></h2><?= blog_body($post['body']) ?></article>
    <?php else: ?>
      <section class="panel blog-intro"><div class="ptitle">Changelog</div><h2>Updates</h2><p>News and changes from WoWCensus.</p></section>
      <div class="blog-list">
      <?php if (!$posts): ?><section class="panel"><p>No updates have been posted yet.</p></section><?php endif; ?>
      <?php foreach ($posts as $postId => $post): ?>
        <article class="panel blog-post"><div class="ptitle"><time datetime="<?= blog_escape($post['date']) ?>"><?= blog_escape($post['date']) ?></time></div>
          <h2><a href="/blog.php?id=<?= (int)$postId ?>"><?= blog_escape($post['title']) ?></a></h2><?= blog_body($post['body']) ?></article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
</div>
</body></html>
<?php
$html = ob_get_clean();
$html = with_skin(with_analytics($html));
$blogStyle = '<style>.blog-intro{margin-bottom:18px}.blog-list{display:grid;gap:18px}.blog-post h2,.blog-intro h2{margin:0 0 14px;line-height:1.2}.blog-post h2 a{color:inherit;text-decoration:none}.blog-post h2 a:hover{text-decoration:underline}.blog-post p{line-height:1.4;margin:0 0 14px}.blog-post p:last-child{margin-bottom:0}</style>';
echo str_replace('</head>', $blogStyle . '</head>', $html);
