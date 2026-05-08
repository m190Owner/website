# m190 Pwn Lab

Three browser-hosted RE crackmes via v86 Linux. Standalone at `/lab/`.

## Where things live

- **Page + JS:** `index.html`, `lab.css`, `lab.js`, `lib/v86_*`
- **Backend:** `api.php` (single file, six actions)
- **VM image:** `vm/alpine.img` + `vm/state.bin` — built once with the v86 build page (see `docs/superpowers/plans/2026-05-08-pwn-lab.md` Task 4)
- **Crackme binaries:** `binaries/crackme-{easy,medium,hard}` — built from `crackmes/` (gitignored)
- **Writeups:** `writeups/{easy,medium,hard}.html` — only served by api.php after solve
- **Runtime data:** `data/sessions.json`, `data/leaderboard.json` — gitignored, created on first request

## Operating

- **Adding a tier or replacing a crackme:** rebuild in `crackmes/`, `make install`, run `make hashes`, update `LAB_HASH_*` constants in `api.php`, commit and push.
- **Tuning rate limits:** edit the `enforceRateLimit` calls in `api.php`.
- **Clearing leaderboard:** delete `data/leaderboard.json` (will be recreated). Sessions are independent.
- **Watching for cheaters:** `tail -f` the PHP error log for the "blocked fetch_binary" entries. Times-to-solve under 5s on hard are likely flag-copy.

## Tests

Run `lab/test/run-all.sh` against a local PHP server for backend integration. Run `BASE=https://logansandivar.com lab/test/test-adversarial.sh` after deploys.

## Future work

See spec: `docs/superpowers/specs/2026-05-08-pwn-lab-design.md` "Out of scope" section.
