<?php
namespace Wow2008;
/**
 * Stat tile: a big number with a small uppercase label.
 *
 * Props:
 *   label        small uppercase caption
 *   value        the number or short text (escaped); leave empty when a script fills it in
 *   value_id     optional id on the value, for page scripts
 *   value_style  optional inline style on the value
 *   note         optional small line under the value
 *   order        'label-first' (default: label, value, note) or 'value-first' (value, label)
 */
$valueAttrs = (isset($value_id) ? ' id="' . e($value_id) . '"' : '') . (isset($value_style) ? ' style="' . e($value_style) . '"' : '');
$valueHtml = '<b' . $valueAttrs . '>' . e($value ?? '') . '</b>';
?>
<?php if (($order ?? 'label-first') === 'value-first'): ?>
<div class="stat"><?= $valueHtml ?><span><?= e($label) ?></span></div>
<?php else: ?>
<div class="stat"><span><?= e($label) ?></span><?= $valueHtml ?><?= isset($note) ? '<em>' . e($note) . '</em>' : '' ?></div>
<?php endif; ?>
