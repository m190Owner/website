<?php
// Owner console → honeytokens. Mint tripwire artifacts and review trips. Owner-gated
// (2FA) + CSRF. The public /c/ and /t/ handlers record hits; this page only mints,
// manages, and reviews. A trip fires a #security alert via the shared audit log.
require __DIR__ . '/lib/audit.php';            // owner_auth + audit_log
require __DIR__ . '/lib/qr.php';               // qr_svg (reused from 2FA)
require __DIR__ . '/lib/tokens.php';
owner_require();

// ---- Artifact download (owner-only): the fake .env for a creds token ----
if (($_GET['download'] ?? '') === 'creds') {
    $id  = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['id'] ?? ''));
    $tok = $id !== '' ? token_get($id) : null;
    if (!$tok) { http_response_code(404); exit('Not found'); }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename=".env"');
    echo token_fake_creds($id);
    exit;
}

// ---- Actions ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    owner_csrf_require();
    enforceRateLimit('owner_tokens', 40, 60);
    $act   = (string) ($_POST['act'] ?? '');
    $id    = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST['id'] ?? ''));
    $flash = '';

    if ($act === 'mint') {
        $type = (string) ($_POST['type'] ?? '');
        $memo = (string) ($_POST['memo'] ?? '');
        $tok  = token_mint($type, $memo);
        if ($tok) {
            audit_log('honeytoken_mint', 'info', ['actor' => 'owner',
                'target' => mb_substr(trim($memo), 0, 120),
                'detail' => 'Minted ' . $type . ' token ' . $tok['id']]);
            $flash = 'Minted a ' . (TOKEN_TYPES[$type]['label'] ?? $type) . ' — plant the artifact below.';
        } else {
            $flash = 'ERR:Could not mint that token.';
        }
    } elseif ($act === 'toggle' && $id !== '') {
        $tok = token_get($id);
        if ($tok) { token_set_active($id, (int) $tok['active'] !== 1); $flash = 'Token ' . ((int) $tok['active'] !== 1 ? 'armed' : 'disarmed') . '.'; }
    } elseif ($act === 'delete' && $id !== '') {
        token_delete($id);
        audit_log('honeytoken_delete', 'info', ['actor' => 'owner', 'detail' => 'Deleted token ' . $id]);
        $flash = 'Token deleted.';
    }
    $_SESSION['owner_tokens_flash'] = $flash;
    header('Location: /owner/tokens.php'); exit;                // PRG
}

$msg = ''; $err = '';
if (!empty($_SESSION['owner_tokens_flash'])) {
    $f = $_SESSION['owner_tokens_flash']; unset($_SESSION['owner_tokens_flash']);
    if (str_starts_with($f, 'ERR:')) $err = substr($f, 4); else $msg = $f;
}

$tokens = token_list();
$armed  = 0; $trips = 0;
foreach ($tokens as $t) { $armed += (int) $t['active'] === 1 ? 1 : 0; $trips += (int) $t['trigger_count']; }

/** Compact "x ago" for a unix ts (or '—'). */
function tok_ago(?int $ts): string {
    if (!$ts) return '—';
    $d = time() - $ts;
    if ($d < 60)    return $d . 's ago';
    if ($d < 3600)  return floor($d / 60) . 'm ago';
    if ($d < 86400) return floor($d / 3600) . 'h ago';
    return floor($d / 86400) . 'd ago';
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Honeytokens · Owner Console</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="/owner/assets/owner.css?v=<?= @filemtime(__DIR__ . '/assets/owner.css') ?: 1 ?>">
</head>
<body>
<nav class="ow-nav">
  <div class="ow-brand"><a href="/owner/" style="text-decoration:none;color:inherit"><span class="ow-lock-sm" aria-hidden="true">&#128274;</span> Owner Console</a> <span class="ow-sep">/</span> Honeytokens</div>
  <div class="ow-nav-right"><a class="ow-btn" href="/owner/">&larr; Security log</a><a class="ow-btn" href="/jellyfin/">Dashboard</a><a class="ow-btn" href="/owner/logout.php">Sign out</a></div>
</nav>

<main class="ow-main">
  <?php if ($msg): ?><div class="ow-flash ow-flash-ok"><?= oe($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="ow-error"><?= oe($err) ?></div><?php endif; ?>

  <p class="ow-dim ow-tok-intro">Tripwires with no legitimate use — plant one, and any access pings <b>#security</b> with the source IP, country, and user-agent. <?= count($tokens) ?> token(s), <?= (int) $armed ?> armed, <?= (int) $trips ?> total trip(s).</p>

  <h2 class="ow-mh">Mint a token</h2>
  <form method="post" class="ow-tok-mint" autocomplete="off">
    <?= owner_csrf_field() ?>
    <input type="hidden" name="act" value="mint">
    <select name="type" aria-label="Token type">
      <?php foreach (TOKEN_TYPES as $k => $meta): ?>
        <option value="<?= oe($k) ?>"><?= oe($meta['icon'] . '  ' . $meta['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="memo" maxlength="200" placeholder="Where are you planting it? (e.g. fake HR payroll share)" class="ow-tok-memo">
    <button class="ow-btn ow-btn-accent">Mint</button>
  </form>

  <h2 class="ow-mh">Your tokens</h2>
  <?php if (!$tokens): ?>
    <p class="ow-dim">No tokens yet. Mint one above.</p>
  <?php else: foreach ($tokens as $t):
    $id = $t['id']; $type = $t['type']; $meta = TOKEN_TYPES[$type] ?? ['label' => $type, 'icon' => '?'];
    $active = (int) $t['active'] === 1; $count = (int) $t['trigger_count'];
    $url = token_url($id); $purl = token_pixel_url($id);
    $hits = $count > 0 ? token_hits($id, 25) : [];
  ?>
    <div class="ow-tok <?= $active ? '' : 'ow-tok-off' ?>">
      <div class="ow-tok-head">
        <span class="ow-tok-title"><span class="ow-tok-ico"><?= oe($meta['icon']) ?></span> <?= oe($meta['label']) ?>
          <?php if ($t['memo'] !== ''): ?><span class="ow-dim">· <?= oe($t['memo']) ?></span><?php endif; ?>
        </span>
        <span class="ow-tok-head-r">
          <?php if ($count > 0): ?><span class="ow-sev ow-sev-crit" title="<?= (int) $count ?> trips">&#127907; <?= (int) $count ?></span>
          <?php else: ?><span class="ow-sev ow-sev-ok">armed</span><?php endif; ?>
          <?php if (!$active): ?><span class="ow-sev ow-sev-warn">disarmed</span><?php endif; ?>
        </span>
      </div>

      <div class="ow-tok-art">
        <?php if ($type === 'pixel'): ?>
          <label class="ow-tok-lbl">Embed this pixel (renders invisibly, phones home on load):</label>
          <?php $snippet = '<img src="' . $purl . '" width="1" height="1" alt="" style="display:none">'; ?>
          <div class="ow-copy"><input readonly value="<?= oe($snippet) ?>"><button type="button" class="ow-btn ow-copy-btn" data-copy="<?= oe($snippet) ?>">Copy</button></div>
          <div class="ow-copy"><input readonly value="<?= oe($purl) ?>"><button type="button" class="ow-btn ow-copy-btn" data-copy="<?= oe($purl) ?>">Copy URL</button></div>

        <?php elseif ($type === 'qr'): ?>
          <label class="ow-tok-lbl">Anyone who scans this trips it:</label>
          <div class="ow-tok-qr"><?= qr_svg($url, 4, 3) ?></div>
          <div class="ow-copy"><input readonly value="<?= oe($url) ?>"><button type="button" class="ow-btn ow-copy-btn" data-copy="<?= oe($url) ?>">Copy URL</button></div>

        <?php elseif ($type === 'creds'): ?>
          <label class="ow-tok-lbl">Drop this fake <code>.env</code> somewhere tempting — opening or using its keys trips it:</label>
          <a class="ow-btn ow-btn-accent" href="/owner/tokens.php?download=creds&amp;id=<?= oe($id) ?>">&#11015; Download .env</a>
          <div class="ow-copy"><input readonly value="<?= oe($url) ?>"><button type="button" class="ow-btn ow-copy-btn" data-copy="<?= oe($url) ?>">Copy callback URL</button></div>

        <?php else: /* url */ ?>
          <label class="ow-tok-lbl">Plant this link anywhere a snoop would click:</label>
          <div class="ow-copy"><input readonly value="<?= oe($url) ?>"><button type="button" class="ow-btn ow-copy-btn" data-copy="<?= oe($url) ?>">Copy</button></div>
        <?php endif; ?>
      </div>

      <div class="ow-tok-foot">
        <span class="ow-dim">created <?= oe(date('Y-m-d', (int) $t['created_at'])) ?> · last trip <?= oe(tok_ago($t['last_triggered'] ? (int) $t['last_triggered'] : null)) ?></span>
        <span class="ow-tok-foot-r">
          <form method="post" class="ow-cbtn"><?= owner_csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= oe($id) ?>"><button class="ow-btn ow-btn-sm"><?= $active ? 'Disarm' : 'Arm' ?></button></form>
          <form method="post" class="ow-cbtn" onsubmit="return confirm('Delete this token and its hit log?')"><?= owner_csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= oe($id) ?>"><button class="ow-btn ow-btn-sm ow-btn-danger">Delete</button></form>
        </span>
      </div>

      <?php if ($hits): ?>
        <details class="ow-tok-hits">
          <summary><?= (int) $count ?> trip<?= $count === 1 ? '' : 's' ?> — show log</summary>
          <table class="ow-table"><thead><tr><th>When</th><th>IP</th><th>Where</th><th>User-agent</th></tr></thead><tbody>
            <?php foreach ($hits as $h): ?>
              <tr>
                <td class="ow-when"><?= oe(date('m-d H:i', (int) $h['ts'])) ?></td>
                <td class="ow-mono"><?= oe($h['ip'] ?: '—') ?></td>
                <td><?= oe($h['country'] ?: '??') ?></td>
                <td class="ow-detail"><?= oe(mb_substr($h['ua'] ?: '—', 0, 90)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</main>

<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('.ow-copy-btn'); if (!b) return;
  var txt = b.getAttribute('data-copy') || '';
  navigator.clipboard.writeText(txt).then(function () {
    var old = b.textContent; b.textContent = 'Copied ✓';
    setTimeout(function () { b.textContent = old; }, 1200);
  });
});
</script>
</body>
</html>
