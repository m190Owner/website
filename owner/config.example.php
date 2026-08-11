<?php
// Copy this to `owner/config.php` (gitignored) and fill it in. Powers the private
// /owner/ console (security audit log now; more later). Created by hand on the
// host — like jellyfin/config.php, it does NOT deploy via git.
return [
    // Owner login password hash. Generate it on the host WITHOUT exposing the
    // plaintext — run this and paste the output here:
    //   php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
    'pass_hash' => '',

    // Dedicated #security Discord webhook. Security events (failed admin logins,
    // bans, 2FA changes, ...) post here. Only discord.com webhook URLs are accepted.
    'security_webhook' => '',

    // Optional. Local dev only: if your PHP has no CA bundle and the webhook TLS
    // verify fails, point this at one (e.g. Git's ca-bundle.crt). Prod hosts have one.
    // 'cainfo' => '/etc/ssl/certs/ca-certificates.crt',

    // Reserved for the TOTP 2FA feature. Leave empty for now.
    'totp_secret' => '',
];
