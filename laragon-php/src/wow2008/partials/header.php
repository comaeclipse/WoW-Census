<?php
namespace Wow2008;
/**
 * Header band: the WoWCensus wordmark and an edition picker
 * in the search box's place. Vars: $source (?string)
 */
?>
<!-- ======= Header band ======= -->
<div class="header">
  <div class="inner">
    <a class="logo" href="<?= e(url()) ?>" aria-label="WoWCensus home">
      <img src="<?= e(asset('logo-wowcensus.png')) ?>" alt="" width="480" height="191">
    </a>
    <form class="search" action="/" onsubmit="if(this.edition.value)location.href=this.edition.value;return false">
      <label class="w8-pick" for="w8-edition">Edition</label>
      <select id="w8-edition" name="edition" aria-label="Edition">
<?php if ($source === null): ?>
        <option value="" selected disabled>Choose edition…</option>
<?php endif; ?>
<?php foreach (\EDITIONS as $key => $ed): ?>
        <option value="<?= e(url('census', $key)) ?>"<?= $key === $source ? ' selected' : '' ?>><?= e(edition_name($key)) ?></option>
<?php endforeach; ?>
      </select>
      <button class="btn-red" type="submit">Go</button>
    </form>
  </div>
</div>
