<?php
namespace Wow2008;
/**
 * Page banner (stands in for the 2008 site's Flash banner). See DESIGN.md, "Banners and artwork".
 *
 * Props:
 *   art             name of a painted banner, with SVG fallback for no-data
 *   title           large title
 *   subtitle        small uppercase line under the title
 *   subtitle_color  optional CSS color for the subtitle
 *   stamp           optional text for the dark date box (top right)
 *   stamp_id        optional id for the date box, when a page script fills it in
 *   seen            optional seen_activity() result, drawn bottom right (components/seen.php)
 *   icon            optional icon URL, drawn before the title
 *   intro           optional description under the subtitle
 *   tools           optional raw HTML (a picker), drawn top right
 */
// Echo the rotating image banners used by the original site's Flash-era headers.
$paintedBanners = ['census', 'realms', 'auction-house', 'guilds', 'geography', 'talents', 'gear', 'molten-core', 'moonbrook', 'lava-dragon'];
// Pages with an image of their own always show it.
$fixedBanners = ['contact'];
// Pages with their own set rotate within it (banner-<name>.webp).
$bannerSets = [
  'dungeons' => ['dungeons-1', 'dungeons-2', 'dungeons-3'],
  'ragefire-chasm' => ['dungeons-1', 'dungeons-2'],
  'dire-maul' => ['dungeons-3'],
];
$randomBanner = in_array($art, $fixedBanners, true) ? $art
  : (isset($bannerSets[$art]) ? $bannerSets[$art][random_int(0, count($bannerSets[$art]) - 1)]
  : (in_array($art, $paintedBanners, true) ? $paintedBanners[random_int(0, count($paintedBanners) - 1)] : null));
?>
<div class="census-banner<?= !empty($intro) ? ' has-intro' : '' ?>">
<?php if ($randomBanner !== null): ?>
<img class="banner-art" src="<?= e(asset("banner-{$randomBanner}.webp")) ?>" alt="" width="880" height="150">
<?php else: ?>
<?= render("art/banners/{$art}.php") ?>
<?php endif; ?>
<?php if (isset($stamp) || isset($stamp_id)): ?>
<div class="stamp"<?= isset($stamp_id) ? ' id="' . e($stamp_id) . '"' : '' ?>><?= e($stamp ?? '') ?></div>
<?php endif; ?>
<div class="t">
<h1><?php if (!empty($icon)): ?><img src="<?= e($icon) ?>" alt="" width="40" height="40" style="width:40px;height:40px;margin-right:12px;vertical-align:-7px;border:1px solid #b08a40;border-radius:4px;box-shadow:0 2px 6px #000"><?php endif; ?><?= e($title) ?></h1>
<div<?= isset($subtitle_color) ? ' style="color:' . e($subtitle_color) . '"' : '' ?>><?= e($subtitle) ?></div>
<?php if (!empty($intro)): ?>
<p class="intro"><?= e($intro) ?></p>
<?php endif; ?>
</div>
<?php if (!empty($tools)): ?>
<div class="banner-tools"><?= $tools ?></div>
<?php endif; ?>
<?php if (!empty($seen)): ?>
<?= component('seen', ['seen' => $seen]) ?>
<?php endif; ?>
</div>
