<?php
// Copy this file to `config.php` (which is gitignored) and fill in your values.
//
// Create the API key in Jellyfin:  Dashboard -> API Keys -> +  (name it e.g.
// "website-dashboard"). It is used server-side only, sent to Jellyfin as a
// request header, and never reaches the browser or the git repo.
return [
    'url'     => 'https://your-jellyfin-host.example.com',  // base URL, no trailing slash
    'api_key' => 'YOUR_JELLYFIN_API_KEY',

    // Optional. The cert is always verified. If your host's PHP has no CA bundle
    // configured and you get an "unable to get local issuer certificate" error,
    // point this at a CA bundle file (e.g. /etc/ssl/certs/ca-certificates.crt).
    // 'cainfo' => '/etc/ssl/certs/ca-certificates.crt',
];
