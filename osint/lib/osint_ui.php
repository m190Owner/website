<?php
// Shared chrome for the signed-in m190 finder pages: <head>, the top bar, and the
// tool navigation — so every section of the suite looks like one app. The login and
// register pages keep their own centered-card layout and do NOT use this.
require_once __DIR__ . '/scan.php';

/** The suite's tool tabs, in order: slug => [href, label]. */
function osint_nav(): array {
    return [
        'dashboard' => ['/osint/',             'Dashboard'],
        'profile'   => ['/osint/profile.php',  'Profile'],
        'results'   => ['/osint/results.php',  'Results'],
        'removal'   => ['/osint/brokers.php',  'Removal'],
        'takedowns' => ['/osint/takedowns.php', 'Takedowns'],
        'search'    => ['/osint/search.php',   'Self-search'],
        'metadata'  => ['/osint/metadata.php', 'Photo EXIF'],
        'domains'   => ['/osint/domain.php',   'Domains'],
        'password'  => ['/osint/password.php', 'Passwords'],
        'network'   => ['/osint/network.php',  'Network'],
        'harden'    => ['/osint/harden.php',   'Harden'],
    ];
}

/** Emit the page head + top bar + nav, and open <main>. Call osint_foot() to close. */
function osint_head(string $title, string $active = '', array $opt = []): void {
    $u = osint_current_user();
    $narrow = !empty($opt['narrow']);
    $cssv = @filemtime(__DIR__ . '/../assets/osint.css') ?: 1;
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= ose($title) ?></title>
<meta name="osint-csrf" content="<?= ose(osint_csrf_token()) ?>">
<link rel="icon" type="image/png" href="/osint/assets/m190-logo.png">
<link rel="stylesheet" href="/osint/assets/osint.css?v=<?= $cssv ?>">
</head>
<body>
<header class="os-top">
  <a class="os-top-l" href="/osint/"><img class="os-logo" src="/osint/assets/m190-logo.png" alt="m190 OPSEC Team"><b>m190 finder</b></a>
  <div class="os-top-r">
    <span class="os-whoami">signed in as <b><?= ose($u['username'] ?? '') ?></b></span>
    <a class="os-btn os-btn-sm" href="/osint/logout.php">Sign out</a>
  </div>
</header>
<nav class="os-nav" aria-label="Tools">
  <?php foreach (osint_nav() as $slug => [$href, $label]): ?>
    <a class="os-navlink<?= $slug === $active ? ' on' : '' ?>" href="<?= ose($href) ?>"><?= ose($label) ?></a>
  <?php endforeach; ?>
</nav>
<main class="os-main<?= $narrow ? ' os-main-narrow' : '' ?>">
<?php
}

/** Close <main> and load any page scripts (basenames in osint/assets/). */
function osint_foot(array $scripts = []): void {
    echo "</main>\n";
    foreach ($scripts as $s) {
        $v = @filemtime(__DIR__ . '/../assets/' . $s) ?: 1;
        echo '<script src="/osint/assets/' . ose($s) . '?v=' . $v . "\"></script>\n";
    }
    echo "</body>\n</html>\n";
}
