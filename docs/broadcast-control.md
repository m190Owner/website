# Owner broadcast control

`/owner/broadcast.php` uses the existing owner session, including its configured
2FA login flow. Links are in the Owner Console, Media Control, and the owner-only
Jellyfin dashboard navigation. There is no videos-admin fallback for this page.

The browser uses `/owner/broadcast-api.php` with the owner's session and CSRF
token. Commands are allowlisted and stored in SQLite inside `owner/data`, whose
existing `.htaccess` denies web access. Uploaded files use generated names and
are staged there in 4 MiB chunks (250 MiB maximum), then downloaded by the media
server for duration validation and H.264/AAC conversion. Completed staging files
are removed after the agent acknowledges processing; abandoned files expire
after one hour, with a 500 MiB aggregate staging cap.

The media-server Node controller makes outbound HTTPS calls to
`/jellyfin/broadcast-agent.php` using the existing `ingest_secret` from the
gitignored Jellyfin configuration. No new host-side secret is needed. It publishes
viewer/clip/job display data and claims commands. The relay does not expose the
localhost controller or put Jellyfin credentials into the browser. Polling is
two seconds while the page is open and fifteen seconds when idle, plus network
latency. The owner page disables commands when the agent is stale.

Playback commands expire after twenty seconds if unclaimed. Delivery is at most
once: an ambiguous network response is never replayed automatically. Claims that
do not receive an acknowledgement are labelled uncertain. The local controller
keeps its own durable delivery checkpoint and revalidates session identity,
current movie and clip selection before controlling a player. Stop and restore
commands run independently of slow uploads or start acknowledgements.

The local controller reads its existing `setup/status-agent.conf`; an ignored
`broadcast/runtime/website.json` containing `{"enabled":true}` enables the bridge.
`broadcast/install-service.ps1` installs its local Windows supervisor. The
standalone localhost panel remains available.

Validation: PHP lint; `php tests/broadcast-test.php` (PDO SQLite required);
Node controller, upload-validation and remote-command-validation tests in the
media-server repository. The deployment uses the existing Hostinger push hook.
