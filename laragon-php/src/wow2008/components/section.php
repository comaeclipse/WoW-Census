<?php
namespace Wow2008;
/**
 * Content box with a gold header bar (the 2008 news-post frame). Wraps content:
 *   <?php component_start('section', ['title' => 'Realm List', 'icon' => 'g']) ?> ... <?php component_end() ?>
 *
 * Props:
 *   title       header text
 *   icon        round icon color: '' (blue), 'g' (gold), 'r' (red), 'p' (purple)
 *   sub         optional small text on the right of the header (escaped)
 *   sub_html    optional raw HTML for the right of the header (e.g. a <select>)
 *   sub_id      optional id for the sub element, when a page script fills it in
 *   id, style   optional attributes on the <section>
 *   body_class  optional extra classes on the body (e.g. 'cols2')
 *   slot        the wrapped content (set by component_start/component_end)
 */
$hasSub = isset($sub) || isset($sub_html) || isset($sub_id);
?>
<section class="section"<?= isset($id) ? ' id="' . e($id) . '"' : '' ?><?= isset($style) ? ' style="' . e($style) . '"' : '' ?>>
<div class="post-hd">
<span class="ico<?= !empty($icon) ? ' ' . e($icon) : '' ?>"></span>
<h2><?= e($title) ?></h2>
<?php if ($hasSub): ?>
<div class="sub"<?= isset($sub_id) ? ' id="' . e($sub_id) . '"' : '' ?>><?= $sub_html ?? e($sub ?? '') ?></div>
<?php endif; ?>
</div>
<div class="section-bd<?= !empty($body_class) ? ' ' . e($body_class) : '' ?>">
<?= $slot ?>
</div>
</section>
