# Video Section ("YouTube-like") — Design Spec

**Date:** 2026-07-26
**Site:** logansandivar.com (static HTML + PHP on shared hosting, no VPS)
**Status:** Approved design — ready for implementation planning

## 1. Summary

Add a public, YouTube-style video section at `/videos/`. Anyone can register an
account, upload a video, and browse/watch/comment/like/subscribe. Video files are
stored directly on the shared PHP host (no third-party service). Uploads go live
**instantly**, with a report button and an owner-only takedown/ban panel as the
moderation mechanism. Data lives in a single SQLite database. The section reuses
the site's existing dark theme and PHP conventions (`config.php` helpers).

## 2. Decisions (locked)

| Question | Decision |
|---|---|
| Who uploads | Anyone (public, real accounts) |
| Storage/delivery | Directly on the PHP/shared host (free, self-contained) |
| Moderation | Instant-public + report + owner takedown/ban |
| Identity | Simple accounts: username + password (bcrypt) |
| v1 features | Core + comments + likes/dislikes + search + subscriptions |
| Data store | SQLite (PDO) |
| App architecture | PHP multi-page app + progressive-enhancement JS |

### Accepted risks / limitations (explicitly acknowledged)
- **Bandwidth:** serving video bytes uses the host's transfer quota. The global
  storage cap protects disk, but a popular video can still eat bandwidth. If it
  ever bites, `media/` can be moved to a cheap CDN later (drop-in).
- **Legal/abuse exposure:** public-instant UGC on a personal domain means Logan
  hosts whatever is posted until he removes it. Report + takedown + ban is the
  mitigation, not prevention.
- **No transcoding:** shared hosting has no ffmpeg. Only browser-playable
  containers are accepted (MP4/WebM); a file with an exotic codec simply won't
  play in the browser and the uploader sees that.

## 3. Architecture

PHP multi-page app under `/videos/`. Server-rendered pages for the core
experience (real, shareable, indexable URLs per video), with small `fetch()`
calls for interactions (like, comment, subscribe, report). Matches the existing
site pattern (HTML/PHP pages + JSON endpoints like `counter.php`, `chat.php`).

### Folder layout
```
videos/
  index.php        home feed (recent + most-viewed) + search box
  watch.php        player, metadata, like/dislike, subscribe, comments, report
  channel.php      a user's videos + subscribe button
  upload.php       upload form + POST handler (login required)
  login.php  register.php  logout.php
  admin.php        OWNER ONLY: report queue, delete video/comment, ban user
  action.php       POST endpoint for like/comment/subscribe/report/delete (CSRF)
  lib/
    db.php         PDO SQLite connection + schema auto-init on first run
    auth.php       sessions, password_hash/verify, CSRF tokens, current-user
    util.php       validation, escaping, storage/rate caps (reuses config.php)
  media/           uploaded video files — hardened .htaccess (no exec, no listing)
  thumbs/          thumbnail JPEGs — hardened .htaccess
  data/            videos.db (SQLite) — gitignored, never web-served
  assets/          page CSS/JS (reuses dark theme from css/styles.css)
```

### Config additions (`config.php` or a `videos/lib/config.php`)
- `VIDEO_MAX_BYTES` (default 128 MB)
- `VIDEO_MAX_DURATION_SEC` (default 900 = 15 min)
- `VIDEO_GLOBAL_CAP_BYTES` (default 5 GB) — refuse uploads once `media/` exceeds
- `VIDEO_UPLOADS_PER_DAY` (per user, default 10)
- `VIDEO_ADMIN_USERNAME` — the account flagged `is_admin` (Logan)

## 4. Data model (SQLite)

- **users** — `id`, `username` (unique), `password_hash` (bcrypt), `is_admin`
  (bool), `is_banned` (bool), `about` (text), `created_at`
- **videos** — `id` (random slug, PK), `user_id`, `title`, `description`,
  `filename`, `thumb`, `mime`, `size_bytes`, `duration_sec`, `views` (int),
  `status` (`live` | `removed`), `created_at`
- **comments** — `id`, `video_id`, `user_id`, `body`, `status`, `created_at`
- **votes** — `video_id`, `user_id`, `value` (+1 | −1), `UNIQUE(video_id,user_id)`
- **subscriptions** — `subscriber_id`, `channel_id`, `created_at`,
  `UNIQUE(subscriber_id, channel_id)`
- **reports** — `id`, `target_type` (`video` | `comment`), `target_id`,
  `reporter_id`, `reason`, `resolved` (bool), `created_at`

Sessions use PHP's native session handling (no sessions table). The DB schema is
created on first run by `lib/db.php` if the tables don't exist.

## 5. Upload pipeline

**Client-side (JS) pre-checks** before upload:
1. Type is `video/mp4` or `video/webm`; size ≤ `VIDEO_MAX_BYTES`.
2. Load into a hidden `<video>` to read duration; reject > `VIDEO_MAX_DURATION_SEC`.
3. Auto-capture a thumbnail: seek to ~25%, draw a frame to `<canvas>` → JPEG blob.
   Uploader may override with a custom image. (Avoids server-side ffmpeg.)
4. Upload via XHR multipart POST with a progress bar. (Single POST for v1;
   chunked/resumable noted as a future upgrade.)

**Server-side (`upload.php` POST handler):**
1. Require login + valid CSRF token; enforce per-user + per-IP upload rate limits.
2. **Global storage cap:** sum `media/`; refuse if over `VIDEO_GLOBAL_CAP_BYTES`.
3. Validate PHP upload error code; size > 0 and ≤ cap.
4. **Magic-byte MIME sniff** via `finfo` — must be MP4/WebM. Client-sent type and
   original filename are ignored entirely.
5. Generate random slug + safe fixed extension; move into `media/`; `chmod 0644`.
6. Validate + store thumbnail (finfo image/jpeg|png, dimension/size caps).
7. Sanitize title/description (length caps + profanity check); store raw, always
   escape on output.
8. Insert `videos` row (`status=live`); redirect to `watch.php?v=<slug>`.

**Serving:** `media/` and `thumbs/` `.htaccess` = `Options -Indexes` + PHP/CGI
execution disabled. Files served statically by the web server (native HTTP Range
for seeking, minimal PHP overhead), randomized names never derived from input.

## 6. Security (application level)

- **Auth:** `password_hash`/`password_verify` (bcrypt); regenerate session id on
  login; cookies `HttpOnly` + `Secure` + `SameSite=Lax`.
- **CSRF:** token required on every state-changing POST (register, login, upload,
  comment, vote, subscribe, report, delete).
- **Stored XSS:** `htmlspecialchars` on ALL user text on output (titles,
  descriptions, comments, usernames, about). Non-negotiable.
- **SQL injection:** PDO prepared statements everywhere; no string-built SQL.
- **Rate limits:** login/register (brute force), comments (spam), reports —
  reuse `enforceRateLimit()` from `config.php`.
- **Profanity:** `containsProfanity()` on usernames/titles/descriptions/comments.
- **Username validation:** reuse `sanitizeHandle()` pattern; password min length.
- **Bans:** banned users cannot log in, upload, or comment.

## 7. Features (v1)

- **Home feed:** thumbnail grid (title, uploader, views, relative time); sort
  recent / most-viewed; search box.
- **Watch page:** HTML5 `<video controls>`, title/desc/uploader/views/date,
  like/dislike, subscribe, flat comments (box + list), report button, share URL.
- **Channel page:** uploader's videos, subscriber count, subscribe/unsubscribe.
- **Search:** SQLite `LIKE` over title/description/uploader (FTS noted as upgrade).
- **Subscriptions:** subscribe/unsubscribe + a "Subscriptions" feed for logged-in
  users.
- **Votes:** one like/dislike per user per video (toggle).
- **Views:** incremented once per session per video (blunts trivial inflation).
- **Report + admin:** `admin.php` (owner only) shows the report queue; owner can
  delete a video (removes file + thumb + row) or a comment, and ban a user.

Comments are **flat** (not threaded) for v1.

## 8. Testing

- Local PHP built-in server + throwaway SQLite db + a small sample `.mp4`.
- curl-driven tests (same approach as the leaderboard HMAC work) covering:
  - register / login / logout; CSRF rejection of forged POSTs
  - upload happy-path
  - **non-video rejection** (a renamed `.php` → `.mp4` blocked by magic-byte sniff)
  - oversize rejection; global-cap rejection
  - **XSS payload in title/comment renders escaped** (no script execution)
  - auth-required pages redirect when logged out
  - vote toggling; subscribe/unsubscribe; search returns expected rows
  - report → appears in admin queue; admin delete removes the file; ban blocks
    login + upload
- Browser smoke test via the preview pane (upload → watch → comment → like).

## 9. Integration & housekeeping

- Add "Videos" to the main nav in `index.html` (desktop + mobile) with a
  back-link to the site in the videos header.
- Include the casual anti-tamper `js/noinspect.js` like the other main pages.
- **Gitignore** `videos/data/`, `videos/media/`, `videos/thumbs/` — the DB and all
  user-uploaded content stay out of the repo.
- Commit + push per the standard workflow after each working slice.

## 10. Out of scope (future)

Chunked/resumable uploads; server-side transcoding/adaptive streaming; playlists;
threaded comment replies; notifications; CDN offload of `media/`; hotlink
protection; full-text search (FTS5); email verification / password reset.
