<?php
// Owner-console 2FA state: the TOTP secret + hashed one-time backup codes, stored
// in a gitignored JSON file in the owner's data dir. Written by /owner/2fa.php
// (enrollment), read by the login flow. Independent of owner_auth (no cycle).
require_once __DIR__ . '/totp.php';

define('OWNER_2FA_FILE', __DIR__ . '/../data/owner_2fa.json');
const OWNER_2FA_BACKUP_COUNT = 10;

function owner_2fa_state(bool $fresh = false): array {
    static $s = null;
    if ($s !== null && !$fresh) return $s;
    $raw = @file_get_contents(OWNER_2FA_FILE);
    $d = $raw ? json_decode($raw, true) : null;
    return $s = is_array($d) ? $d : ['enabled' => false, 'secret' => '', 'backup' => []];
}

function owner_2fa_save(array $state): void {
    $dir = dirname(OWNER_2FA_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    file_put_contents(OWNER_2FA_FILE, json_encode($state), LOCK_EX);
    owner_2fa_state(true);   // refresh the static cache
}

function owner_2fa_enabled(): bool {
    $s = owner_2fa_state();
    return !empty($s['enabled']) && !empty($s['secret']);
}

function owner_2fa_backup_remaining(): int {
    return count(owner_2fa_state()['backup'] ?? []);
}

/** N human-friendly one-time backup codes (plaintext — shown once at enable). */
function owner_2fa_gen_backup_codes(int $n = OWNER_2FA_BACKUP_COUNT): array {
    $codes = [];
    for ($i = 0; $i < $n; $i++) {
        $raw = strtolower(bin2hex(random_bytes(4)));      // 8 hex chars
        $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    }
    return $codes;
}

function owner_2fa_normalize(string $code): string {
    return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $code));
}

/** Enable 2FA with a (already-confirmed) secret + a fresh set of hashed backups. */
function owner_2fa_enable(string $secret, array $plainBackupCodes): void {
    $hashes = [];
    foreach ($plainBackupCodes as $c) $hashes[] = password_hash(owner_2fa_normalize($c), PASSWORD_DEFAULT);
    owner_2fa_save(['enabled' => true, 'secret' => $secret, 'backup' => $hashes, 'enabled_at' => time()]);
}

function owner_2fa_disable(): void {
    owner_2fa_save(['enabled' => false, 'secret' => '', 'backup' => []]);
}

/**
 * Verify a login code. A valid TOTP passes; otherwise a one-time backup code is
 * consumed. Returns ['ok' => bool, 'backup' => bool].
 */
function owner_2fa_check(string $code): array {
    $s = owner_2fa_state(true);
    if (empty($s['secret'])) return ['ok' => false, 'backup' => false];
    if (totp_verify((string) $s['secret'], $code)) return ['ok' => true, 'backup' => false];
    $norm = owner_2fa_normalize($code);
    if ($norm === '') return ['ok' => false, 'backup' => false];
    foreach (($s['backup'] ?? []) as $i => $hash) {
        if (password_verify($norm, $hash)) {
            unset($s['backup'][$i]);
            $s['backup'] = array_values($s['backup']);
            owner_2fa_save($s);
            return ['ok' => true, 'backup' => true];
        }
    }
    return ['ok' => false, 'backup' => false];
}
