<?php
// RFC 6238 TOTP + RFC 4648 base32, pure PHP (no external library). Powers the
// owner console 2FA. Verified against the official RFC 6238 test vectors.

const TOTP_B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp_base32_encode(string $bytes): string {
    if ($bytes === '') return '';
    $bits = '';
    foreach (str_split($bytes) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= TOTP_B32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function totp_base32_decode(string $b32): string {
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    if ($b32 === '') return '';
    $bits = '';
    foreach (str_split($b32) as $c) {
        $bits .= str_pad(decbin(strpos(TOTP_B32_ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) $bytes .= chr(bindec($chunk));
    }
    return $bytes;
}

/** New random base32 secret (20 bytes = 160 bits, the RFC-recommended size). */
function totp_secret_new(int $bytes = 20): string {
    return totp_base32_encode(random_bytes($bytes));
}

/** The 6-digit code for a given base32 secret at a given unix time. */
function totp_code_at(string $b32secret, int $timestamp, int $digits = 6, int $step = 30): string {
    $key = totp_base32_decode($b32secret);
    $msg = pack('J', intdiv($timestamp, $step));           // 64-bit big-endian counter
    $hash = hash_hmac('sha1', $msg, $key, true);
    $offset = ord($hash[19]) & 0x0f;                        // dynamic truncation
    $part = ((ord($hash[$offset])     & 0x7f) << 24)
          | ((ord($hash[$offset + 1]) & 0xff) << 16)
          | ((ord($hash[$offset + 2]) & 0xff) << 8)
          |  (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string) ($part % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

function totp_now(string $b32secret): string {
    return totp_code_at($b32secret, time());
}

/** Verify a submitted code, tolerating ±$window steps of clock skew. */
function totp_verify(string $b32secret, string $code, int $window = 1, int $step = 30): bool {
    $code = preg_replace('/\D/', '', (string) $code);
    if (strlen($code) !== 6) return false;
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code_at($b32secret, $now + $i * $step), $code)) return true;
    }
    return false;
}

/** otpauth:// URI for authenticator apps (encoded into the enrollment QR). */
function totp_uri(string $b32secret, string $label, string $issuer): string {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
         . '?secret=' . $b32secret
         . '&issuer=' . rawurlencode($issuer)
         . '&algorithm=SHA1&digits=6&period=30';
}
