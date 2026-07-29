<?php
// SQLite data layer for /videos/. One file DB in videos/data/, created and
// migrated on first use. Everything goes through prepared statements.

function videos_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $pdo = new PDO('sqlite:' . VIDEOS_DATA_DIR . '/videos.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    videos_db_init($pdo);
    return $pdo;
}

function videos_db_init(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            is_admin      INTEGER NOT NULL DEFAULT 0,
            is_banned     INTEGER NOT NULL DEFAULT 0,
            is_muted      INTEGER NOT NULL DEFAULT 0,
            avatar        TEXT NOT NULL DEFAULT '',
            about         TEXT NOT NULL DEFAULT '',
            coins         INTEGER NOT NULL DEFAULT 0,
            last_bonus    INTEGER NOT NULL DEFAULT 0,
            created_at    INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS videos (
            id           TEXT PRIMARY KEY,
            user_id      INTEGER NOT NULL,
            title        TEXT NOT NULL,
            description  TEXT NOT NULL DEFAULT '',
            filename     TEXT NOT NULL,
            thumb        TEXT NOT NULL DEFAULT '',
            mime         TEXT NOT NULL,
            size_bytes   INTEGER NOT NULL DEFAULT 0,
            duration_sec INTEGER NOT NULL DEFAULT 0,
            views        INTEGER NOT NULL DEFAULT 0,
            status       TEXT NOT NULL DEFAULT 'live',
            created_at   INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS comments (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            video_id   TEXT NOT NULL,
            user_id    INTEGER NOT NULL,
            body       TEXT NOT NULL,
            status     TEXT NOT NULL DEFAULT 'live',
            created_at INTEGER NOT NULL,
            FOREIGN KEY (video_id) REFERENCES videos(id),
            FOREIGN KEY (user_id)  REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS votes (
            video_id TEXT NOT NULL,
            user_id  INTEGER NOT NULL,
            value    INTEGER NOT NULL,
            PRIMARY KEY (video_id, user_id)
        );
        CREATE TABLE IF NOT EXISTS subscriptions (
            subscriber_id INTEGER NOT NULL,
            channel_id    INTEGER NOT NULL,
            created_at    INTEGER NOT NULL,
            PRIMARY KEY (subscriber_id, channel_id)
        );
        CREATE TABLE IF NOT EXISTS reports (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            target_type TEXT NOT NULL,
            target_id   TEXT NOT NULL,
            reporter_id INTEGER,
            reason      TEXT NOT NULL DEFAULT '',
            resolved    INTEGER NOT NULL DEFAULT 0,
            created_at  INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS warnings (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id      INTEGER NOT NULL,
            issued_by    INTEGER,
            reason       TEXT NOT NULL DEFAULT '',
            acknowledged INTEGER NOT NULL DEFAULT 0,
            created_at   INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_videos_created ON videos(created_at DESC);
        CREATE INDEX IF NOT EXISTS idx_videos_user    ON videos(user_id);
        CREATE INDEX IF NOT EXISTS idx_comments_video ON comments(video_id);
        CREATE INDEX IF NOT EXISTS idx_reports_open   ON reports(resolved);
        CREATE INDEX IF NOT EXISTS idx_warnings_user  ON warnings(user_id);
    ");

    // Migrate older databases that predate the profile/moderation columns.
    $cols = array_column($db->query("PRAGMA table_info(users)")->fetchAll(), 'name');
    if (!in_array('is_muted', $cols, true)) $db->exec("ALTER TABLE users ADD COLUMN is_muted INTEGER NOT NULL DEFAULT 0");
    if (!in_array('avatar',   $cols, true)) $db->exec("ALTER TABLE users ADD COLUMN avatar TEXT NOT NULL DEFAULT ''");
    if (!in_array('about',    $cols, true)) $db->exec("ALTER TABLE users ADD COLUMN about TEXT NOT NULL DEFAULT ''");
    if (!in_array('coins',      $cols, true)) $db->exec("ALTER TABLE users ADD COLUMN coins INTEGER NOT NULL DEFAULT 0");
    if (!in_array('last_bonus', $cols, true)) $db->exec("ALTER TABLE users ADD COLUMN last_bonus INTEGER NOT NULL DEFAULT 0");

    // Keep the designated owner account flagged as admin (idempotent).
    $st = $db->prepare("UPDATE users SET is_admin = 1 WHERE username = ?");
    $st->execute([VIDEO_ADMIN_USERNAME]);
}
