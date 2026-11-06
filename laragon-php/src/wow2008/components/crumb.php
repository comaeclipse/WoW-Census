<?php
namespace Wow2008;
/**
 * Breadcrumb trail. "Home" and the current edition are added automatically.
 *
 * Props:
 *   trail  list of ['label' => string, 'url' => ?string, 'id' => ?string]
 *          An item with 'url' becomes a link; one with 'id' becomes a <span> page scripts can update.
 */
$items = [['label' => 'Home', 'url' => url()]];
if (($src = current_source()) !== null) {
    $items[] = ['label' => edition_name($src), 'url' => url('census')];
}
$parts = [];
foreach (array_merge($items, $trail ?? []) as $item) {
    if (!empty($item['url'])) {
        $parts[] = '<a href="' . e($item['url']) . '">' . e($item['label']) . '</a>';
    } elseif (!empty($item['id'])) {
        $parts[] = '<span id="' . e($item['id']) . '">' . e($item['label']) . '</span>';
    } else {
        $parts[] = e($item['label']);
    }
}
?>
<div class="crumb"><?= implode(' » ', $parts) ?></div>
