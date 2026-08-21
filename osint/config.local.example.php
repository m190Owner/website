<?php
// Optional API keys for the email-intelligence sources that aren't keyless.
// Copy this file to  osint/config.local.php  (that name is gitignored) and fill in
// only the keys you have. Any key you leave blank simply keeps that one source dark —
// the rest of the tool keeps working keyless. Never commit the real config.local.php.
//
//   emailrep — EmailRep.io reputation + "seen on these services" list.
//              Free key: https://emailrep.io/key  (request from the site; rate-limited
//              free tier). Sent as the  Key:  header.
//   hibp     — Have I Been Pwned breached-account API (paid, ~$3.95/mo).
//              Buy a key: https://haveibeenpwned.com/API/Key  → hibp-api-key header.
//   intelx   — Intelligence X leak/paste search (free tier with limited credits).
//              Register for a key: https://intelx.io/account?tab=developer
//
return [
    'emailrep' => '',
    'hibp'     => '',
    'intelx'   => '',
];
