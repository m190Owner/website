<?php
// Minimal self-contained QR Code generator — byte mode, ECC level L, versions
// 1-10 (ample for an otpauth:// URI). Renders locally to inline SVG so the 2FA
// secret is NEVER sent to an external QR service. No dependencies.
//
// Implements ISO/IEC 18004: Reed-Solomon ECC over GF(256), the standard function
// patterns, all 8 data masks with penalty scoring, and BCH format/version info.

// version => [ecCodewordsPerBlock, [[numBlocks, dataCodewordsPerBlock], ...]]
const QR_ECC_L = [
    1 => [7, [[1, 19]]],   2 => [10, [[1, 34]]],  3 => [15, [[1, 55]]],
    4 => [20, [[1, 80]]],  5 => [26, [[1, 108]]], 6 => [18, [[2, 68]]],
    7 => [20, [[2, 78]]],  8 => [24, [[2, 97]]],  9 => [30, [[2, 116]]],
    10 => [18, [[2, 68], [2, 69]]],
];
const QR_ALIGN = [
    1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
    6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
];

function qr_gf(): array {
    static $t = null;
    if ($t) return $t;
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) { $exp[$i] = $x; $log[$x] = $i; $x <<= 1; if ($x & 0x100) $x ^= 0x11d; }
    for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
    return $t = ['exp' => $exp, 'log' => $log];
}
function qr_gf_mul(int $a, int $b): int {
    if ($a === 0 || $b === 0) return 0;
    $g = qr_gf();
    return $g['exp'][$g['log'][$a] + $g['log'][$b]];
}
function qr_poly_mul(array $p, array $q): array {
    $r = array_fill(0, count($p) + count($q) - 1, 0);
    foreach ($p as $i => $pi) foreach ($q as $j => $qj) $r[$i + $j] ^= qr_gf_mul($pi, $qj);
    return $r;
}
/** Reed-Solomon EC codewords (highest-degree-first) for a data block. */
function qr_rs(array $data, int $ecLen): array {
    $gen = [1];
    $exp = qr_gf()['exp'];
    for ($i = 0; $i < $ecLen; $i++) $gen = qr_poly_mul($gen, [1, $exp[$i]]);
    $msg = array_merge($data, array_fill(0, $ecLen, 0));
    for ($i = 0; $i < count($data); $i++) {
        $coef = $msg[$i];
        if ($coef !== 0) foreach ($gen as $j => $gc) $msg[$i + $j] ^= qr_gf_mul($gc, $coef);
    }
    return array_slice($msg, count($data));
}

/** Encode text as byte-mode data codewords for the smallest fitting version. */
function qr_encode_data(string $text): array {
    $len = strlen($text);
    $version = 0;
    foreach (QR_ECC_L as $v => [$ec, $blocks]) {
        $total = 0;
        foreach ($blocks as [$n, $d]) $total += $n * $d;
        $cc = $v < 10 ? 8 : 16;                       // char-count bits (byte mode)
        $capacity = $total - 2 - intdiv($cc, 8);      // minus mode nibble + count bytes
        if ($len <= $capacity) { $version = $v; break; }
    }
    if ($version === 0) throw new RuntimeException('QR data too long');

    [$ecLen, $blocks] = QR_ECC_L[$version];
    $ccBits = $version < 10 ? 8 : 16;

    // Bit stream: mode(0100) + count + data.
    $bits = '0100' . str_pad(decbin($len), $ccBits, '0', STR_PAD_LEFT);
    for ($i = 0; $i < $len; $i++) $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);

    $totalData = 0;
    foreach ($blocks as [$n, $d]) $totalData += $n * $d;
    $cap = $totalData * 8;
    $bits .= substr('0000', 0, min(4, $cap - strlen($bits)));   // terminator
    while (strlen($bits) % 8 !== 0) $bits .= '0';                // pad to byte
    $pads = ['11101100', '00010001'];
    $pi = 0;
    while (strlen($bits) < $cap) { $bits .= $pads[$pi % 2]; $pi++; }

    $codewords = [];
    foreach (str_split($bits, 8) as $b) $codewords[] = bindec($b);

    // Split into blocks, compute EC per block.
    $dataBlocks = [];
    $ecBlocks = [];
    $pos = 0;
    foreach ($blocks as [$n, $d]) {
        for ($b = 0; $b < $n; $b++) {
            $blk = array_slice($codewords, $pos, $d);
            $pos += $d;
            $dataBlocks[] = $blk;
            $ecBlocks[] = qr_rs($blk, $ecLen);
        }
    }
    // Interleave data codewords, then EC codewords.
    $out = [];
    $maxD = max(array_map('count', $dataBlocks));
    for ($i = 0; $i < $maxD; $i++) foreach ($dataBlocks as $blk) if ($i < count($blk)) $out[] = $blk[$i];
    for ($i = 0; $i < $ecLen; $i++) foreach ($ecBlocks as $blk) $out[] = $blk[$i];

    return ['version' => $version, 'codewords' => $out];
}

/** BCH-encoded 15-bit format information for ECC-L + mask. */
function qr_format_bits(int $mask): int {
    $data = (0b01 << 3) | $mask;             // 01 = ECC level L
    $rem = $data << 10;
    for ($i = 14; $i >= 10; $i--) if (($rem >> $i) & 1) $rem ^= 0b10100110111 << ($i - 10);
    return (($data << 10) | $rem) ^ 0b101010000010010;
}
/** BCH-encoded 18-bit version information (v7+). */
function qr_version_bits(int $version): int {
    $rem = $version << 12;
    for ($i = 17; $i >= 12; $i--) if (($rem >> $i) & 1) $rem ^= 0b1111100100101 << ($i - 12);
    return ($version << 12) | $rem;
}

function qr_build_matrix(int $version, array $codewords): array {
    $size = 17 + 4 * $version;
    $m = array_fill(0, $size, array_fill(0, $size, null));   // module color or null
    $fn = array_fill(0, $size, array_fill(0, $size, false)); // reserved (function pattern)?

    $set = function ($r, $c, $v, $reserve = true) use (&$m, &$fn, $size) {
        if ($r < 0 || $c < 0 || $r >= $size || $c >= $size) return;
        $m[$r][$c] = $v ? 1 : 0;
        if ($reserve) $fn[$r][$c] = true;
    };

    // Finder patterns + separators.
    foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$r0, $c0]) {
        for ($r = -1; $r <= 7; $r++) for ($c = -1; $c <= 7; $c++) {
            $rr = $r0 + $r; $cc = $c0 + $c;
            if ($rr < 0 || $cc < 0 || $rr >= $size || $cc >= $size) continue;
            $inRing = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6)) || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6));
            $inCore = ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
            $set($rr, $cc, ($inRing || $inCore) ? 1 : 0);
        }
    }
    // Timing patterns.
    for ($i = 8; $i < $size - 8; $i++) { $set(6, $i, ($i % 2 === 0) ? 1 : 0); $set($i, 6, ($i % 2 === 0) ? 1 : 0); }
    // Alignment patterns.
    $ap = QR_ALIGN[$version];
    foreach ($ap as $ar) foreach ($ap as $ac) {
        if (($ar === 6 && $ac === 6) || ($ar === 6 && $ac === $size - 7) || ($ar === $size - 7 && $ac === 6)) continue;
        for ($r = -2; $r <= 2; $r++) for ($c = -2; $c <= 2; $c++) {
            $ring = max(abs($r), abs($c));
            $set($ar + $r, $ac + $c, ($ring === 1) ? 0 : 1);
        }
    }
    // Dark module + reserve format/version areas.
    $set($size - 8, 8, 1);
    for ($i = 0; $i < 9; $i++) { if ($i !== 6) { $fn[8][$i] = true; $fn[$i][8] = true; } }
    for ($i = 0; $i < 8; $i++) { $fn[8][$size - 1 - $i] = true; $fn[$size - 1 - $i][8] = true; }
    if ($version >= 7) {
        for ($i = 0; $i < 6; $i++) for ($j = 0; $j < 3; $j++) { $fn[$size - 11 + $j][$i] = true; $fn[$i][$size - 11 + $j] = true; }
    }

    // Place data bits (zigzag, right-to-left, skip timing column 6).
    $bits = '';
    foreach ($codewords as $cw) $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
    $bi = 0; $len = strlen($bits);
    $col = $size - 1;
    while ($col > 0) {
        if ($col === 6) $col--;
        for ($i = 0; $i < $size; $i++) {
            $upward = (intdiv($size - 1 - $col, 2) % 2 === 0);
            $row = $upward ? ($size - 1 - $i) : $i;
            foreach ([0, 1] as $dc) {
                $c = $col - $dc;
                if (!$fn[$row][$c] && $m[$row][$c] === null) {
                    $m[$row][$c] = ($bi < $len) ? (int) $bits[$bi] : 0;
                    $bi++;
                }
            }
        }
        $col -= 2;
    }

    // Try all 8 masks, keep the lowest-penalty one.
    $best = null; $bestPen = PHP_INT_MAX; $bestMask = 0;
    for ($mask = 0; $mask < 8; $mask++) {
        $cand = $m;
        for ($r = 0; $r < $size; $r++) for ($c = 0; $c < $size; $c++) {
            if ($fn[$r][$c]) continue;
            if (qr_mask_bit($mask, $r, $c)) $cand[$r][$c] ^= 1;
        }
        qr_place_format($cand, $fn, $size, $mask, $version);
        $pen = qr_penalty($cand, $size);
        if ($pen < $bestPen) { $bestPen = $pen; $best = $cand; $bestMask = $mask; }
    }
    return $best;
}

function qr_mask_bit(int $mask, int $r, int $c): bool {
    switch ($mask) {
        case 0: return ($r + $c) % 2 === 0;
        case 1: return $r % 2 === 0;
        case 2: return $c % 3 === 0;
        case 3: return ($r + $c) % 3 === 0;
        case 4: return (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0;
        case 5: return (($r * $c) % 2) + (($r * $c) % 3) === 0;
        case 6: return ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0;
        default: return ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0;
    }
}

function qr_place_format(array &$m, array $fn, int $size, int $mask, int $version): void {
    $fmt = qr_format_bits($mask);
    for ($i = 0; $i < 15; $i++) {
        $bit = ($fmt >> $i) & 1;
        // around top-left finder
        if ($i < 6)        $m[$i][8] = $bit;
        elseif ($i === 6)  $m[7][8] = $bit;
        elseif ($i === 7)  $m[8][8] = $bit;
        elseif ($i === 8)  $m[8][7] = $bit;
        else               $m[8][14 - $i] = $bit;
        // duplicate copy along the other two finders
        if ($i < 8) $m[8][$size - 1 - $i] = $bit;
        else        $m[$size - 15 + $i][8] = $bit;
    }
    if ($version >= 7) {
        $vinfo = qr_version_bits($version);
        for ($i = 0; $i < 18; $i++) {
            $bit = ($vinfo >> $i) & 1;
            $r = intdiv($i, 3); $c = $i % 3;
            $m[$r][$size - 11 + $c] = $bit;
            $m[$size - 11 + $c][$r] = $bit;
        }
    }
}

function qr_penalty(array $m, int $size): int {
    $p = 0;
    // Rule 1: runs of 5+ in rows and columns.
    for ($r = 0; $r < $size; $r++) {
        $runC = 1; $runR = 1;
        for ($c = 1; $c < $size; $c++) {
            $runC = ($m[$r][$c] === $m[$r][$c - 1]) ? $runC + 1 : 1;
            if ($runC === 5) $p += 3; elseif ($runC > 5) $p += 1;
            $runR = ($m[$c][$r] === $m[$c - 1][$r]) ? $runR + 1 : 1;
            if ($runR === 5) $p += 3; elseif ($runR > 5) $p += 1;
        }
    }
    // Rule 2: 2x2 blocks.
    for ($r = 0; $r < $size - 1; $r++) for ($c = 0; $c < $size - 1; $c++) {
        $v = $m[$r][$c];
        if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) $p += 3;
    }
    // Rule 3: finder-like 1:1:3:1:1 patterns.
    $pat1 = [1,0,1,1,1,0,1,0,0,0,0]; $pat2 = [0,0,0,0,1,0,1,1,1,0,1];
    for ($r = 0; $r < $size; $r++) for ($c = 0; $c <= $size - 11; $c++) {
        $row = []; $colv = [];
        for ($k = 0; $k < 11; $k++) { $row[] = $m[$r][$c + $k]; $colv[] = $m[$c + $k][$r]; }
        if ($row === $pat1 || $row === $pat2) $p += 40;
        if ($colv === $pat1 || $colv === $pat2) $p += 40;
    }
    // Rule 4: dark-module proportion.
    $dark = 0;
    foreach ($m as $row) $dark += array_sum($row);
    $ratio = $dark / ($size * $size) * 100;
    $p += (int) (floor(abs($ratio - 50) / 5) * 10);
    return $p;
}

/** Render text as an inline SVG QR code. */
function qr_svg(string $text, int $scale = 6, int $quiet = 4): string {
    $enc = qr_encode_data($text);
    $m = qr_build_matrix($enc['version'], $enc['codewords']);
    $size = count($m);
    $dim = ($size + 2 * $quiet) * $scale;
    $rects = '';
    for ($r = 0; $r < $size; $r++) for ($c = 0; $c < $size; $c++) {
        if ($m[$r][$c]) {
            $x = ($c + $quiet) * $scale; $y = ($r + $quiet) * $scale;
            $rects .= "<rect x=\"$x\" y=\"$y\" width=\"$scale\" height=\"$scale\"/>";
        }
    }
    return "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"$dim\" height=\"$dim\" viewBox=\"0 0 $dim $dim\" shape-rendering=\"crispEdges\" role=\"img\" aria-label=\"2FA enrollment QR code\">"
         . "<rect width=\"$dim\" height=\"$dim\" fill=\"#ffffff\"/><g fill=\"#000000\">$rects</g></svg>";
}
