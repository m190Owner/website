<?php
// Copy this file to `config.php` (which is gitignored) and fill in your values.
//
// Create the API key in Jellyfin:  Dashboard -> API Keys -> +  (name it e.g.
// "website-dashboard"). It is used server-side only, sent to Jellyfin as a
// request header, and never reaches the browser or the git repo.
return [
    'url'     => 'https://your-jellyfin-host.example.com',  // base URL, no trailing slash
    'api_key' => 'YOUR_JELLYFIN_API_KEY',

    // Optional: shared secret for the media-server status agent (the "Stack"
    // panel). Must match setup/status-agent.conf on the box. Leave empty to
    // disable the stack ingest endpoint. Generate one with: openssl rand -hex 32
    'ingest_secret' => '',

    // Optional: alerts. If set, the ingest endpoint posts to this Discord webhook
    // when a container goes down/recovers or a disk volume crosses the threshold.
    // Only Discord webhook URLs are accepted.
    'alert_webhook'  => '',
    'disk_alert_pct' => 90,   // alert when a volume crosses this %, recovers 5% below

    // Optional. The cert is always verified. If your host's PHP has no CA bundle
    // configured and you get an "unable to get local issuer certificate" error,
    // point this at a CA bundle file (e.g. /etc/ssl/certs/ca-certificates.crt).
    // 'cainfo' => '/etc/ssl/certs/ca-certificates.crt',
];
