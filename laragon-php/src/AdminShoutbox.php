<?php
// Admin shoutbox moderation: hide or restore shouts, time out or ban a poster.
// Posters are known only by the HMAC of their address (poster_hash), never the raw IP.
declare(strict_types=1);

require_once __DIR__ . '/shoutbox-db.php';
require_once __DIR__ . '/shoutbox-filter.php';

const ADMIN_SHOUT_PAGE = 100;
const ADMIN_SHOUT_TIMEOUTS = ['1h' => 3600, '24h' => 86400, '7d' => 604800];

function admin_shout_poster_ok(string $poster): bool {
  return (bool)preg_match('/^[0-9a-f]{64}$/', $poster);
}

/** Runs one moderation POST. Returns a flash message for the next page. */
function admin_shout_action(PDO $db, string $action, array $post): string {
  $id = (int)($post['id'] ?? 0);
  $poster = (string)($post['poster'] ?? '');
  $now = time();
  switch ($action) {
    case 'shout-hide':
      $db->prepare('UPDATE shoutbox_entries SET hidden_at = ? WHERE id = ? AND hidden_at IS NULL')->execute([$now, $id]);
      return "Shout #$id hidden.";
    case 'shout-restore':
      $db->prepare('UPDATE shoutbox_entries SET hidden_at = NULL WHERE id = ?')->execute([$id]);
      return "Shout #$id restored.";
  }
  if (!admin_shout_poster_ok($poster)) return 'Unknown poster.';
  $short = substr($poster, 0, 8);
  switch ($action) {
    case 'poster-hide-all':
      $q = $db->prepare('UPDATE shoutbox_entries SET hidden_at = ? WHERE poster_hash = ? AND hidden_at IS NULL');
      $q->execute([$now, $poster]);
      return 'Hid ' . $q->rowCount() . " shout(s) from poster $short.";
    case 'poster-timeout':
      $seconds = ADMIN_SHOUT_TIMEOUTS[(string)($post['length'] ?? '')] ?? null;
      if ($seconds === null) return 'Pick a timeout length.';
      $db->prepare('INSERT INTO shoutbox_timeouts (poster_hash, until, timeouts) VALUES (?, ?, 0)
        ON CONFLICT (poster_hash) DO UPDATE SET until = excluded.until')->execute([$poster, $now + $seconds]);
      return "Poster $short timed out for " . shout_duration($seconds) . '.';
    case 'poster-ban':
      $reason = mb_substr(trim((string)($post['reason'] ?? '')), 0, 200);
      $db->prepare('INSERT INTO shoutbox_bans (poster_hash, reason, created_at) VALUES (?, ?, ?)
        ON CONFLICT (poster_hash) DO UPDATE SET reason = excluded.reason')->execute([$poster, $reason, $now]);
      return "Poster $short banned.";
    case 'poster-lift':
      $db->prepare('DELETE FROM shoutbox_bans WHERE poster_hash = ?')->execute([$poster]);
      $db->prepare('UPDATE shoutbox_timeouts SET until = 0 WHERE poster_hash = ?')->execute([$poster]);
      return "Ban and timeout lifted for poster $short.";
  }
  return 'Unknown action.';
}

function admin_shout_time(int $at): string {
  return '<time datetime="' . cs_h(gmdate('c', $at)) . '" title="' . cs_h(gmdate('D M j, Y H:i:s', $at)) . ' UTC">' . cs_h(gmdate('M j H:i', $at)) . '</time>';
}

/** A small POST form: one button plus hidden fields (and optional extra inputs). */
function admin_shout_form(string $csrf, string $return, string $action, array $fields, string $label, string $class = '', string $extra = '', string $confirm = ''): string {
  $html = '<form method="post" action="/admin/?view=shoutbox"' . ($confirm !== '' ? ' data-confirm="' . cs_h($confirm) . '"' : '') . '>'
    . '<input type="hidden" name="csrf" value="' . cs_h($csrf) . '"><input type="hidden" name="action" value="' . cs_h($action) . '">'
    . '<input type="hidden" name="return" value="' . cs_h($return) . '">';
  foreach ($fields as $name => $value) $html .= '<input type="hidden" name="' . cs_h($name) . '" value="' . cs_h($value) . '">';
  return $html . $extra . '<button type="submit"' . ($class !== '' ? ' class="' . cs_h($class) . '"' : '') . '>' . cs_h($label) . '</button></form>';
}

function admin_shout_poster_tools(string $csrf, string $return, string $poster, bool $banned, bool $timedOut): string {
  $f = ['poster' => $poster];
  $lengths = '';
  foreach (ADMIN_SHOUT_TIMEOUTS as $key => $seconds) $lengths .= '<option value="' . cs_h($key) . '">' . cs_h(shout_duration($seconds)) . '</option>';
  $html = '<div class="tools">'
    . admin_shout_form($csrf, $return, 'poster-timeout', $f, 'Time out', '', '<select name="length" aria-label="Timeout length">' . $lengths . '</select>');
  $html .= $banned
    ? ''
    : admin_shout_form($csrf, $return, 'poster-ban', $f, 'Ban', 'danger', '<input name="reason" maxlength="200" placeholder="Reason (optional)" aria-label="Ban reason">', 'Ban this poster from the shoutbox?');
  if ($banned || $timedOut) $html .= admin_shout_form($csrf, $return, 'poster-lift', $f, 'Lift ban/timeout');
  $html .= admin_shout_form($csrf, $return, 'poster-hide-all', $f, 'Hide all their shouts', 'danger', '', 'Hide every shout from this poster?');
  return $html . '</div>';
}

function render_admin_shoutbox(PDO $db, string $chrome, string $csrf, string $flash): string {
  $filter = (string)($_GET['filter'] ?? 'all');
  $filters = ['all' => 'All shouts', 'visible' => 'Visible', 'hidden' => 'Hidden', 'filtered' => 'Caught by filter'];
  if (!isset($filters[$filter])) $filter = 'all';
  $poster = (string)($_GET['poster'] ?? '');
  if (!admin_shout_poster_ok($poster)) $poster = '';
  $before = max(0, (int)($_GET['before'] ?? 0));
  $now = time();

  $where = [];
  $args = [];
  if ($filter === 'visible') $where[] = 'e.hidden_at IS NULL';
  if ($filter === 'hidden') $where[] = 'e.hidden_at IS NOT NULL';
  if ($filter === 'filtered') $where[] = 'e.censored = 1';
  if ($poster !== '') { $where[] = 'e.poster_hash = ?'; $args[] = $poster; }
  if ($before > 0) { $where[] = 'e.id < ?'; $args[] = $before; }
  $q = $db->prepare('SELECT e.id, e.name, e.message, e.poster_hash, e.created_at, e.censored, e.hidden_at,
      b.poster_hash IS NOT NULL AS banned, COALESCE(t.until, 0) AS timeout_until
    FROM shoutbox_entries e
    LEFT JOIN shoutbox_bans b ON b.poster_hash = e.poster_hash
    LEFT JOIN shoutbox_timeouts t ON t.poster_hash = e.poster_hash'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY e.id DESC LIMIT ' . (ADMIN_SHOUT_PAGE + 1));
  $q->execute($args);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
  $more = count($rows) > ADMIN_SHOUT_PAGE;
  $rows = array_slice($rows, 0, ADMIN_SHOUT_PAGE);

  $stats = $db->query("SELECT COUNT(*) AS total, SUM(hidden_at IS NOT NULL) AS hidden, SUM(censored) AS filtered,
      SUM(created_at > $now - 86400) AS day, COUNT(DISTINCT poster_hash) AS posters FROM shoutbox_entries")->fetch(PDO::FETCH_ASSOC);
  $restricted = $db->query("SELECT p.poster_hash, b.reason, b.created_at AS banned_at, t.until,
      (SELECT name FROM shoutbox_entries e WHERE e.poster_hash = p.poster_hash ORDER BY e.id DESC LIMIT 1) AS last_name
    FROM (SELECT poster_hash FROM shoutbox_bans UNION SELECT poster_hash FROM shoutbox_timeouts WHERE until > $now) p
    LEFT JOIN shoutbox_bans b ON b.poster_hash = p.poster_hash
    LEFT JOIN shoutbox_timeouts t ON t.poster_hash = p.poster_hash
    ORDER BY COALESCE(b.created_at, t.until) DESC")->fetchAll(PDO::FETCH_ASSOC);

  // This view's URL with some parameters changed; null drops one (http_build_query skips nulls).
  $query = fn(array $over) => '/admin/?' . http_build_query(array_merge(
    ['view' => 'shoutbox', 'filter' => $filter === 'all' ? null : $filter, 'poster' => $poster ?: null], $over));
  $self = (string)($_SERVER['REQUEST_URI'] ?? '/admin/?view=shoutbox');

  ob_start();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Shoutbox moderation · WoWCensus</title>
<style>
:root{color-scheme:dark;font-family:system-ui,sans-serif;background:#101720;color:#edf3fa}
*{box-sizing:border-box}body{max-width:1400px;margin:auto;padding:28px 24px 64px}
a{color:#a9d4ff}.back{font-size:.88rem;text-decoration:none}.eyebrow{margin:26px 0 5px;color:#8ea3b9;text-transform:uppercase;letter-spacing:.14em;font-size:.72rem;font-weight:700}
.view-nav{display:flex;gap:12px;margin:12px 0 22px}.view-nav a{font-size:.85rem;text-decoration:none;padding:7px 12px;border:1px solid #344759;border-radius:7px}.view-nav a[aria-current=page]{background:#456e96;color:white}
.chrome{display:flex;justify-content:space-between;align-items:center;gap:12px}.chrome form{margin:0}.chrome button{font:inherit;font-size:.85rem;background:#1b2936;color:#b9cbdc;border:1px solid #344759;border-radius:7px;padding:6px 12px;cursor:pointer}.chrome button:hover{color:white;border-color:#456e96}
h1{font-size:clamp(1.8rem,4vw,2.8rem);margin:0 0 8px;letter-spacing:-.035em}h2{font-size:1.05rem;margin:28px 0 10px}
.intro{color:#a8b9ca;line-height:1.5;margin:0 0 22px}
.flash{background:#1d3a2c;border:1px solid #2f6b4c;color:#c9f2db;border-radius:9px;padding:10px 14px;margin:0 0 18px}
.summary-strip{display:flex;gap:22px;flex-wrap:wrap;background:#1a2734;border:1px solid #344759;border-radius:11px;padding:14px 18px;margin-bottom:22px}.summary-strip strong{font-size:1.12rem}.summary-strip small{display:block;color:#9db0c2;margin-top:2px}
.toolbar{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.regions{display:flex;flex-wrap:wrap;gap:5px;padding:4px;background:#1b2936;border:1px solid #344759;border-radius:9px}.regions a{padding:7px 15px;border-radius:6px;text-decoration:none;color:#b9cbdc;font-size:.85rem}.regions a[aria-current=page]{background:#456e96;color:white;font-weight:700}
.poster-card{background:#192632;border:1px solid #344759;border-radius:11px;padding:14px 18px;margin-bottom:16px}.poster-card p{margin:0 0 10px;color:#b9cbdc;font-size:.88rem}
table{width:100%;border-collapse:collapse;background:#192632;border:1px solid #344759;border-radius:11px;overflow:hidden;font-size:.86rem}
th,td{text-align:left;vertical-align:top;padding:9px 12px;border-top:1px solid #2b3b4b}th{font-size:.72rem;color:#8fa5ba;font-weight:600;border-top:0;background:#1b2936}
td.when{white-space:nowrap;color:#a8b9ca}td.msg{word-break:break-word;max-width:560px}td.msg .censored{display:block;color:#8fa5ba;font-size:.78rem;margin-top:3px}
tr.hidden td{opacity:.55}tr.hidden td.actions{opacity:1}
.poster{font-family:ui-monospace,monospace;font-size:.78rem}.name{font-weight:650}
.badge{display:inline-block;font-size:.68rem;font-weight:700;border-radius:5px;padding:1px 6px;margin:0 4px 0 0;vertical-align:1px}.badge.hidden{background:#3c4652;color:#cdd7e1}.badge.filtered{background:#5a4a1d;color:#f5dd9b}.badge.banned{background:#5c2328;color:#ffc3c7}.badge.timeout{background:#4a3a5c;color:#e2cdff}
td.actions{white-space:nowrap}form{display:inline;margin:0}
.tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.tools form{display:inline-flex;gap:5px;align-items:center}
button,select,input{font:inherit;font-size:.8rem;background:#1b2936;color:#d4e0eb;border:1px solid #344759;border-radius:6px;padding:5px 10px}button{cursor:pointer}button:hover{border-color:#6f9cc6;color:white}
button.danger{border-color:#7a3a40;color:#ffc3c7}button.danger:hover{background:#5c2328}
input{width:170px}.pager{margin-top:14px;font-size:.88rem}.empty{color:#91a9bc;padding:18px 12px}
@media(max-width:800px){body{padding:18px 12px 48px}table,tbody,tr,td{display:block}thead{display:none}tr{border-top:1px solid #344759;padding:6px 0}td{border:0;padding:4px 12px}}
</style></head><body>
<?= $chrome ?>
<p class="eyebrow">Moderation</p><h1>Shoutbox</h1>
<p class="intro">Hidden shouts leave the public log but stay here and can be restored. Posters are identified by a keyed hash of their address, so the same person on another network shows as a new poster. Bans and timeouts block new shouts only.</p>
<?php if ($flash !== ''): ?><p class="flash" role="status"><?= cs_h($flash) ?></p><?php endif; ?>
<div class="summary-strip">
  <div><strong><?= number_format((int)$stats['total']) ?></strong><small>shouts</small></div>
  <div><strong><?= number_format((int)$stats['day']) ?></strong><small>in the last 24 hours</small></div>
  <div><strong><?= number_format((int)$stats['posters']) ?></strong><small>posters</small></div>
  <div><strong><?= number_format((int)$stats['hidden']) ?></strong><small>hidden</small></div>
  <div><strong><?= number_format((int)$stats['filtered']) ?></strong><small>caught by filter</small></div>
  <div><strong><?= number_format(count($restricted)) ?></strong><small>banned or timed out</small></div>
</div>

<?php if ($poster !== ''):
  $names = $db->prepare('SELECT name, COUNT(*) AS n, MIN(created_at) AS first, MAX(created_at) AS last FROM shoutbox_entries WHERE poster_hash = ? GROUP BY name ORDER BY last DESC');
  $names->execute([$poster]);
  $names = $names->fetchAll(PDO::FETCH_ASSOC);
  $ban = $db->prepare('SELECT reason, created_at FROM shoutbox_bans WHERE poster_hash = ?');
  $ban->execute([$poster]);
  $ban = $ban->fetch(PDO::FETCH_ASSOC);
  $until = $db->prepare('SELECT until FROM shoutbox_timeouts WHERE poster_hash = ?');
  $until->execute([$poster]);
  $until = (int)$until->fetchColumn();
?>
<div class="poster-card">
  <p><strong>Poster <span class="poster"><?= cs_h(substr($poster, 0, 12)) ?></span></strong>
    · names used: <?= $names ? implode(', ', array_map(fn($n) => '<span class="name">' . cs_h($n['name']) . '</span> (' . (int)$n['n'] . ')', $names)) : 'none' ?>
    <?php if ($ban): ?> · <span class="badge banned">Banned</span> <?= admin_shout_time((int)$ban['created_at']) ?><?= $ban['reason'] !== '' ? ': ' . cs_h($ban['reason']) : '' ?><?php endif; ?>
    <?php if ($until > $now): ?> · <span class="badge timeout">Timed out</span> <?= cs_h(shout_duration($until - $now)) ?> left<?php endif; ?>
    · <a href="<?= cs_h($query(['poster' => null])) ?>">Show everyone</a></p>
  <?= admin_shout_poster_tools($csrf, $self, $poster, (bool)$ban, $until > $now) ?>
</div>
<?php endif; ?>

<div class="toolbar"><nav class="regions" aria-label="Filter shouts">
<?php foreach ($filters as $key => $label): ?>
<a href="<?= cs_h($query(['filter' => $key === 'all' ? null : $key])) ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?>><?= cs_h($label) ?></a>
<?php endforeach; ?></nav></div>

<table>
<thead><tr><th>#</th><th>When (UTC)</th><th>Name</th><th>Message</th><th>Poster</th><th>Actions</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="6" class="empty">No shouts match.</td></tr><?php endif; ?>
<?php foreach ($rows as $row):
  $hidden = $row['hidden_at'] !== null;
  $timedOut = (int)$row['timeout_until'] > $now;
  $shown = shout_censor($row['message'])[0];
?>
<tr<?= $hidden ? ' class="hidden"' : '' ?>>
  <td><?= (int)$row['id'] ?></td>
  <td class="when"><?= admin_shout_time((int)$row['created_at']) ?></td>
  <td class="name"><?= cs_h($row['name']) ?></td>
  <td class="msg">
    <?php if ($hidden): ?><span class="badge hidden">Hidden <?= cs_h(gmdate('M j H:i', (int)$row['hidden_at'])) ?></span><?php endif; ?>
    <?php if ((int)$row['censored']): ?><span class="badge filtered">Filtered</span><?php endif; ?>
    <?= cs_h($row['message']) ?>
    <?php if ($shown !== $row['message']): ?><span class="censored">Public sees: <?= cs_h($shown) ?></span><?php endif; ?>
  </td>
  <td><a class="poster" href="<?= cs_h($query(['poster' => $row['poster_hash']])) ?>" title="Show this poster's shouts"><?= cs_h(substr($row['poster_hash'], 0, 8)) ?></a>
    <?php if ($row['banned']): ?><span class="badge banned">Banned</span><?php elseif ($timedOut): ?><span class="badge timeout">Timed out</span><?php endif; ?></td>
  <td class="actions"><?= $hidden
    ? admin_shout_form($csrf, $self, 'shout-restore', ['id' => $row['id']], 'Restore')
    : admin_shout_form($csrf, $self, 'shout-hide', ['id' => $row['id']], 'Hide', 'danger') ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php if ($more): ?><p class="pager"><a href="<?= cs_h($query(['before' => (int)end($rows)['id']])) ?>">Older shouts →</a></p><?php endif; ?>
<?php if ($before > 0): ?><p class="pager"><a href="<?= cs_h($query([])) ?>">← Back to newest</a></p><?php endif; ?>

<h2>Banned and timed-out posters</h2>
<?php if (!$restricted): ?><p class="intro">Nobody is banned or timed out.</p><?php else: ?>
<table>
<thead><tr><th>Poster</th><th>Last name</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($restricted as $r): ?>
<tr>
  <td><a class="poster" href="<?= cs_h($query(['poster' => $r['poster_hash'], 'filter' => null])) ?>"><?= cs_h(substr($r['poster_hash'], 0, 8)) ?></a></td>
  <td class="name"><?= cs_h((string)$r['last_name']) ?></td>
  <td><?php if ($r['banned_at'] !== null): ?><span class="badge banned">Banned</span> since <?= admin_shout_time((int)$r['banned_at']) ?><?= $r['reason'] !== '' ? ' — ' . cs_h($r['reason']) : '' ?>
    <?php else: ?><span class="badge timeout">Timed out</span> <?= cs_h(shout_duration((int)$r['until'] - $now)) ?> left<?php endif; ?></td>
  <td class="actions"><?= admin_shout_form($csrf, $self, 'poster-lift', ['poster' => $r['poster_hash']], 'Lift') ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<script>
document.addEventListener('submit', function (ev) {
  var msg = ev.target.getAttribute('data-confirm');
  if (msg && !confirm(msg)) ev.preventDefault();
});
</script>
</body></html>
<?php
  return (string)ob_get_clean();
}
