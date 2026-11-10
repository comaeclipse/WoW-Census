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

function blog_body(string $value, array $table = []): string {
    $paragraphs = preg_split('/\R\s*\R/u', trim($value)) ?: [];
    $rendered = array_map(
        static fn(string $paragraph): string => '<p>' . nl2br(blog_escape(trim($paragraph)), false) . '</p>',
        array_filter($paragraphs, static fn(string $paragraph): bool => trim($paragraph) !== '')
    );
    if ($table) {
        $head = array_shift($table);
        $cells = static fn(array $row, string $tag): string => '<tr>' . implode('', array_map(
            static fn(mixed $cell): string => '<' . $tag . '>' . blog_escape((string)$cell) . '</' . $tag . '>', $row
        )) . '</tr>';
        $html = '<div class="blog-table-wrap"><table class="blog-table"><thead>' . $cells($head, 'th') . '</thead><tbody>';
        foreach ($table as $row) $html .= $cells($row, 'td');
        $html .= '</tbody></table></div>';
        array_splice($rendered, 1, 0, [$html]);
    }
    return implode("\n", $rendered);
}

uksort($posts, static fn(int|string $a, int|string $b): int => strcmp($posts[$b]['date'], $posts[$a]['date']) ?: ((int)$b <=> (int)$a));
$single = isset($id, $posts[(int)$id]) && !isset($error);
$title = isset($error) ? $error : ($single ? $posts[(int)$id]['title'] : 'Updates');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true) || ($method === 'POST' && !$single)) {
    header('Allow: GET, HEAD' . ($single ? ', POST' : ''));
    http_response_code(405);
    exit;
}

// Comments live on single-post pages only.
$comments = [];
$commentForm = ['name' => '', 'message' => ''];
$commentError = null;
$commentQuestion = null;
if ($single) {
    require_once dirname(__DIR__) . '/src/blog-comments.php';
    blog_session();
    if (isset($_GET['captcha'])) {
        blog_captcha_image();
        exit;
    }
    try {
        $commentDb = blog_comment_db();
        if ($method === 'POST') {
            foreach ($commentForm as $key => $_) $commentForm[$key] = trim((string)($_POST[$key] ?? ''));
            $commentError = blog_comment_post($commentDb, (int)$id, $commentForm);
            if ($commentError === null) {
                unset($_SESSION['blog_csrf']);
                header('Location: /blog.php?id=' . (int)$id . '&posted=' . bin2hex(random_bytes(4)) . '#comments', true, 303);
                exit;
            }
            http_response_code(400);
        }
        $comments = blog_comments($commentDb, (int)$id);
    } catch (Throwable $e) {
        error_log('blog comments: ' . $e);
        $commentError = 'Comments are temporarily unavailable. Please try again later.';
    }
    $_SESSION['blog_csrf'] ??= bin2hex(random_bytes(24));
    $commentQuestion = blog_captcha_issue();
}

function blog_comment_html(array $comment): string {
    $when = gmdate('Y-m-d H:i', (int)$comment['created_at']) . ' UTC';
    return '<li class="blog-comment"><div class="blog-comment-meta"><strong>' . blog_escape($comment['name']) . '</strong> · <time datetime="'
        . gmdate('c', (int)$comment['created_at']) . '">' . $when . '</time></div>'
        . '<div class="blog-comment-body">' . nl2br(blog_escape($comment['message']), false) . '</div></li>';
}

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
        <h2><?= blog_escape($post['title']) ?></h2><?php if (isset($post['html_file'])): ?><div class="blog-feature"><?= file_get_contents(dirname(__DIR__) . '/src/' . basename($post['html_file'])) ?></div><?php else: ?><?= blog_body($post['body'], $post['table'] ?? []) ?><?php endif; ?></article>
      <section class="panel blog-comments" id="comments"><div class="ptitle">Comments (<?= count($comments) ?>)</div>
        <?php if (isset($_GET['posted']) && $commentError === null): ?><p class="blog-comment-ok">Thanks, your comment was posted.</p><?php endif; ?>
        <?php if ($comments): ?><ol class="blog-comment-list"><?= implode('', array_map('blog_comment_html', $comments)) ?></ol>
        <?php else: ?><p>No comments yet. Be the first.</p><?php endif; ?>
        <form class="blog-comment-form" method="post" action="/blog.php?id=<?= (int)$id ?>#comments">
          <h3>Leave a comment</h3>
          <?php if ($commentError !== null): ?><p class="blog-comment-error" role="alert"><?= blog_escape($commentError) ?></p><?php endif; ?>
          <input type="hidden" name="csrf" value="<?= blog_escape((string)($_SESSION['blog_csrf'] ?? '')) ?>">
          <p class="blog-hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></p>
          <p><label>Name<br><input name="name" maxlength="32" required value="<?= blog_escape($commentForm['name']) ?>"></label></p>
          <p><label>Comment<br><textarea name="message" rows="5" maxlength="<?= BLOG_COMMENT_MAX ?>" required><?= blog_escape($commentForm['message']) ?></textarea></label></p>
          <?php if ($commentQuestion === null): ?>
            <p><img class="blog-captcha" src="/blog.php?id=<?= (int)$id ?>&amp;captcha=<?= bin2hex(random_bytes(4)) ?>" width="180" height="52" alt="Human check: type the characters shown"><br>
            <label>Type the characters above <input name="captcha" maxlength="5" size="6" required autocomplete="off" autocapitalize="characters" spellcheck="false"></label></p>
          <?php else: ?>
            <p><label><?= blog_escape($commentQuestion) ?> <input name="captcha" inputmode="numeric" maxlength="3" size="3" required autocomplete="off"></label></p>
          <?php endif; ?>
          <p><button type="submit">Post comment</button></p>
        </form>
      </section>
    <?php else: ?>
      <div class="blog-list">
      <?php if (!$posts): ?><section class="panel"><p>No updates have been posted yet.</p></section><?php endif; ?>
      <?php foreach ($posts as $postId => $post): ?>
        <article class="panel blog-post"><div class="ptitle"><time datetime="<?= blog_escape($post['date']) ?>"><?= blog_escape($post['date']) ?></time></div>
          <h2><a href="/blog.php?id=<?= (int)$postId ?>"><?= blog_escape($post['title']) ?></a></h2><?= blog_body($post['body'], $post['table'] ?? []) ?>
          <p class="blog-comments-link"><a href="/blog.php?id=<?= (int)$postId ?>#comments">Comments →</a></p></article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
</div>
</body></html>
<?php
$html = ob_get_clean();
$html = with_skin(with_analytics($html));
$blogStyle = '<style>.blog-list{display:grid;gap:18px}.blog-post h2{margin:0 0 14px;line-height:1.2}.blog-post h2 a{color:inherit;text-decoration:none}.blog-post h2 a:hover{text-decoration:underline}.blog-post p{line-height:1.4;margin:0 0 14px}.blog-post p:last-child{margin-bottom:0}.blog-table-wrap{overflow-x:auto;margin:0 0 14px}.blog-table{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}.blog-table th,.blog-table td{padding:7px 9px;border-bottom:1px solid #66543b;text-align:left;white-space:nowrap}.blog-table th{font-weight:700}.blog-comments{margin-top:18px}.blog-comment-list{list-style:none;margin:0 0 18px;padding:0}.blog-comment{padding:10px 0;border-bottom:1px solid #66543b}.blog-comment:last-child{border-bottom:0}.blog-comment-meta{font-size:.9em;opacity:.8;margin-bottom:4px}.blog-comment-body{line-height:1.4;overflow-wrap:anywhere}.blog-comment-form h3{margin:0 0 10px}.blog-comment-form p{margin:0 0 10px}.blog-comment-form input:not([type=hidden]),.blog-comment-form textarea{max-width:100%;box-sizing:border-box;font:inherit}.blog-comment-form textarea{width:100%}.blog-captcha{border:1px solid #66543b;border-radius:3px}.blog-comment-error{color:#e0614f;font-weight:700}.blog-comment-ok{color:#7fbf5b;font-weight:700}.blog-hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}.blog-comments-link{margin-top:14px}</style>';
echo str_replace('</head>', $blogStyle . '<link rel="stylesheet" href="/blog-economy.css"></head>', $html);
