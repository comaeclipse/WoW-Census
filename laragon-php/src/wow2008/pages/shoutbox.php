<?php
namespace Wow2008;
/** Vars: $entries, $form, $question, $error, $posted, $csrf. A 2000s phpBB-style shoutbox block. */
?>
<main class="main shoutbox">
  <?= component('crumb', ['trail' => [['label' => 'Shoutbox']]]) ?>
  <table class="forumline sb" cellspacing="1">
    <tr><th>Shoutbox</th></tr>
    <tr><td class="row1 sb-log-cell">
      <div id="shoutbox-recent" class="sb-log" tabindex="0" aria-label="Recent shouts, newest last">
        <?php if (!$entries): ?><p class="sb-empty">No shouts yet. Be the first!</p><?php endif; ?>
        <?php /* Each name hashes to one of eight colors, so a poster keeps the same color. */ ?>
        <?php foreach ($entries as $entry): $at = (int)$entry['created_at']; ?>
        <div class="sb-line" data-id="<?= (int)$entry['id'] ?>"><time class="sb-time" datetime="<?= e(gmdate('c', $at)) ?>" title="<?= e(gmdate('D M j, Y g:i a', $at)) ?> UTC">[<?= e(gmdate('d M H:i', $at)) ?>]</time> <b class="sb-name sb-c<?= crc32(strtolower($entry['name'])) % 8 ?>"><?= e($entry['name']) ?></b>: <span class="sb-msg"><?= e($entry['message']) ?></span></div>
        <?php endforeach; ?>
      </div>
    </td></tr>
    <tr><td class="row2 sb-post-cell">
      <div id="shoutbox-status">
        <?php if ($posted): ?><p class="shoutbox-ok" role="status">Your shout was posted!</p><?php endif; ?>
        <?php if ($error !== null): ?><p class="shoutbox-error" role="alert"><?= e($error) ?></p><?php endif; ?>
      </div>
      <div id="shoutbox-post">
        <?php if (!$fresh): ?>
        <form method="post" action="/shoutbox/" class="sb-form">
          <input type="hidden" name="init" value="1">
          <button type="submit" class="mainoption">Open shout form &raquo;</button>
        </form>
        <?php else: ?>
        <form id="shoutbox-form" method="post" action="/shoutbox/" class="sb-form">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <p class="shoutbox-trap" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></p>
          <label class="sb-name-field">Name: <input class="post" name="name" maxlength="32" required value="<?= e($form['name']) ?>" autocomplete="nickname"></label>
          <label class="sb-msg-field">Shout: <input class="post" name="message" maxlength="280" required value="<?= e($form['message']) ?>" autocomplete="off"></label>
          <label class="sb-check-field"><?= e($question) ?> <input class="post" name="captcha" inputmode="numeric" pattern="[0-9]+" maxlength="3" size="3" required autocomplete="off"></label>
          <button type="submit" class="mainoption">Shout!</button>
          <span class="sb-count" aria-live="polite"><span id="shoutbox-count">280</span> chars left</span>
        </form>
        <?php endif; ?>
      </div>
    </td></tr>
    <tr><td class="catBottom">
      <a href="/shoutbox/" id="shoutbox-refresh">Refresh</a> &middot;
      <span id="shoutbox-auto">Auto-refresh every 30 seconds</span> &middot;
      <label class="sb-sound" hidden><input type="checkbox" id="shoutbox-sound"> Enable sounds</label> &middot;
      Plain text only, one shout every 60 seconds. Keep it friendly!
    </td></tr>
  </table>
  <script>
  (function () {
    var url = '/shoutbox/', busy = false;
    var ding = new Audio('<?= e(asset('shoutbox-new.mp3')) ?>'), sound = document.getElementById('shoutbox-sound');
    try { sound.checked = localStorage.getItem('shoutbox-sound') === '1'; } catch (e) {}
    sound.parentNode.hidden = false;
    sound.addEventListener('change', function () {
      try { localStorage.setItem('shoutbox-sound', sound.checked ? '1' : '0'); } catch (e) {}
      // Playing once on the click also unlocks audio for later automatic plays.
      if (sound.checked) { ding.currentTime = 0; ding.play().catch(function () {}); }
    });
    function newestId() {
      var lines = document.querySelectorAll('#shoutbox-recent .sb-line');
      return lines.length ? +lines[lines.length - 1].getAttribute('data-id') : 0;
    }
    // Newest shouts are at the bottom: stay pinned there unless the reader has scrolled up.
    function swapLog(doc, stick) {
      var log = document.getElementById('shoutbox-recent'), top = log.scrollTop;
      var atBottom = stick || log.scrollHeight - top - log.clientHeight < 20;
      swap(doc, ['shoutbox-recent']);
      log = document.getElementById('shoutbox-recent');
      log.scrollTop = atBottom ? log.scrollHeight : top;
    }
    function request(body) {
      var opts = body === undefined ? {credentials: 'same-origin', cache: 'no-store'}
        : {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body};
      return fetch(url, opts).then(function (r) {
        return r.text().then(function (html) { return {r: r, doc: new DOMParser().parseFromString(html, 'text/html')}; });
      });
    }
    function swap(doc, ids) {
      ids.forEach(function (id) {
        var next = doc.getElementById(id), old = document.getElementById(id);
        if (next && old) old.replaceWith(next);
      });
    }
    function savedName() { try { return localStorage.getItem('shoutbox-name') || ''; } catch (e) { return ''; } }
    function count() {
      var msg = document.querySelector('#shoutbox-form [name=message]'), out = document.getElementById('shoutbox-count');
      if (msg && out) out.textContent = 280 - msg.value.length;
    }
    function status(kind, text) {
      var box = document.getElementById('shoutbox-status');
      box.innerHTML = '';
      if (!text) return;
      var p = document.createElement('p');
      p.className = 'shoutbox-' + kind;
      p.setAttribute('role', kind === 'ok' ? 'status' : 'alert');
      p.textContent = text;
      box.appendChild(p);
    }
    function openForm(name) {
      return request('init=1').then(function (res) {
        swap(res.doc, ['shoutbox-post']);
        swapLog(res.doc);
        var field = document.querySelector('#shoutbox-form [name=name]');
        if (field && !field.value) field.value = name || savedName();
        count();
      });
    }
    function refresh() {
      if (busy) return;
      var seen = newestId();
      request().then(function (res) {
        swapLog(res.doc);
        // Your own shouts update the list when posted, so only others' shouts get here.
        if (sound.checked && newestId() > seen) { ding.currentTime = 0; ding.play().catch(function () {}); }
      }).catch(function () {});
    }
    document.addEventListener('submit', function (ev) {
      var form = ev.target;
      if (form.id !== 'shoutbox-form') return;
      ev.preventDefault();
      if (busy) return;
      busy = true;
      var name = form.elements.name.value;
      try { localStorage.setItem('shoutbox-name', name); } catch (e) {}
      form.querySelector('button').disabled = true;
      request(new URLSearchParams(new FormData(form)).toString()).then(function (res) {
        swapLog(res.doc, true);
        if (res.r.redirected) {
          return openForm(name).then(function () {
            status('ok', 'Your shout was posted!');
            var msg = document.querySelector('#shoutbox-form [name=message]');
            if (msg) msg.focus();
          });
        }
        swap(res.doc, ['shoutbox-post', 'shoutbox-status']);
        count();
      }).catch(function () {
        status('error', 'Could not reach the shoutbox. Please try again.');
        form.querySelector('button').disabled = false;
      }).then(function () { busy = false; });
    });
    document.addEventListener('input', function (ev) { if (ev.target.name === 'message') count(); });
    document.getElementById('shoutbox-refresh').addEventListener('click', function (ev) { ev.preventDefault(); refresh(); });
    setInterval(function () { if (!document.hidden) refresh(); }, 30000);
    var log = document.getElementById('shoutbox-recent');
    log.scrollTop = log.scrollHeight;
    openForm().catch(function () {});
  })();
  </script>
</main>
