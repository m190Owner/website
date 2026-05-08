# m190 Pwn Lab — Design

**Status:** approved design, ready for implementation plan
**Date:** 2026-05-08
**Scope:** v0.1 — single ship, three crackmes, standalone

## Overview

The m190 Pwn Lab lives at `logansandivar.com/lab/`. A visitor boots a real x86 Linux box (Alpine + RE toolkit) inside their browser via [v86](https://github.com/copy/v86), works through three custom crackmes that escalate in difficulty, and submits flags to a server-verified PHP endpoint. Solves go to a public leaderboard with handle, tier, and time-to-solve. Each tier's writeup unlocks for that visitor once the tier is solved.

Linear progression — easy must be solved before the medium binary is even served by the server, same for hard. Standalone product (no forum coupling). Desktop-only with a polite notice on mobile/touch.

**Why this is the differentiator:** running a real OS in-browser to host original crackmes is several layers above what a typical security-engineer portfolio shows. Most show certs and a screenshot. This shows the author can design a graded RE ladder, build it, host it, and prove it works — across web, systems, and binary layers.

## Architecture

```
/lab/
├── index.html              # The page: v86 embed, HUD, flag input, leaderboard
├── lab.css                 # Lab-specific styles (terminal frame, HUD)
├── lab.js                  # Boot v86, manage 9pfs, talk to api.php
├── api.php                 # Backend: init, fetch_binary, submit_flag, leaderboard
├── vm/
│   ├── alpine.img          # Base Alpine image (no crackmes, just toolkit) ~20MB
│   ├── state.bin           # v86 state snapshot for fast boot
│   └── (bios files)        # SeaBIOS / VGABIOS used by v86
├── binaries/
│   ├── .htaccess           # Allow only crackme-easy; deny medium/hard
│   ├── crackme-easy        # Public — served as static asset
│   ├── crackme-medium      # 403 to direct fetch; only api.php streams it
│   └── crackme-hard        # 403 to direct fetch; only api.php streams it
├── writeups/
│   ├── easy.html           # Same gating as binaries
│   ├── medium.html
│   └── hard.html
├── data/
│   ├── sessions.json       # Active sessions: token, ip-hash, solves, created
│   ├── leaderboard.json    # Public board: handle, tier, time, completed_at
│   └── .htaccess           # Deny direct access (matches forum/data/ pattern)
└── lib/
    └── v86.js              # Vendored v86 library
```

### Components

- **`index.html` + `lab.js`** — owns the visitor experience: boots v86, manages 9pfs binary injection, draws the HUD, handles flag submission UI, polls leaderboard.
- **`api.php`** — single PHP endpoint with `?action=init|fetch_binary|submit_flag|set_handle|leaderboard|writeup`. Owns session lifecycle, gating enforcement, flag verification, leaderboard updates. Reuses `config.php` patterns (CORS, `enforceRateLimit`, `readJsonFile`/`writeJsonFile`, `flock`). The `writeup` action returns the HTML for an already-solved tier (so a returning visitor can re-read writeups without solving again); the data flow below covers when it's called.
- **VM image** — built once, committed as a binary asset. Contains busybox, binutils (`objdump`, `strings`, `nm`, `readelf`), `file`, `xxd`, `gdb`, `ltrace`, `strace`, `vim`. No crackmes baked in.
- **Crackmes** — three statically-linked ELF binaries authored by the site owner. All three live in `lab/binaries/`. An `.htaccess` in that directory allows direct fetch of `crackme-easy` only; medium and hard return 403 to direct requests and are streamed by `api.php` after server-side gating (PHP reads them with `readfile()` and pipes the bytes to the response).
- **Writeups** — three HTML files, gated identically to medium/hard binaries. Visitor sees writeup for tier N only after solving tier N.
- **Storage** — JSON-on-disk with `flock`, matches existing forum pattern. Flag hashes live in PHP source as a constant array (`EXPECTED_HASHES`), never touch disk or wire.

## Data flow

### Visitor opens `/lab/`

1. `lab.js` calls `POST /lab/api.php?action=init`. Server creates a session: `{token, ip_hash, created_at, solves: [], handle: null}` and writes to `sessions.json` (with `flock`). Returns `{token, leaderboard}`.
2. `lab.js` stores `token` in `localStorage`. Boots v86 with `alpine.img` + `state.bin` for fast resume.
3. v86 boot completes (~2–4s with state snapshot). `lab.js` fetches `crackme-easy` (public static asset), writes it to `/home/user/crackme` via 9pfs API. Terminal echoes a one-line banner: *"crackme ready at ~/crackme — find the flag, submit above."*

### Visitor solves a tier

4. Visitor uses `objdump`/`gdb`/`strings` etc. inside the VM. Extracts a flag like `m190{...}`.
5. Pastes flag into HUD input, clicks Verify. JS sends `POST /lab/api.php?action=submit_flag` with `{token, tier: "easy", flag}`.
6. Server: rate-limit check → load session → hash submitted flag (SHA-256) → compare against `EXPECTED_HASHES['easy']` → if match: append `easy` to `session.solves`, record `solved_at` timestamp.
7. **First solve only:** server response includes `needs_handle: true`. UI prompts for a handle (3–16 chars, `^[a-zA-Z0-9_]+$`, run through site's profanity filter (the chat word filter introduced in commit `0f1733a`; if it's not yet a reusable function, refactor it into `config.php` as part of this work), must be unique in `leaderboard.json`). Visitor picks one, JS sends `POST ?action=set_handle`. Server writes leaderboard entry: `{handle, tier: "easy", time_to_solve_seconds, completed_at}`.
8. Subsequent solves reuse the stored handle.
9. Server response also includes `writeup_html` (the tier's writeup) and `next_tier_unlocked: "medium"`. UI reveals the writeup panel and the "Fetch medium binary" button.

A returning visitor (same `localStorage` token, fresh page load) can re-fetch any already-solved tier's writeup via `GET /lab/api.php?action=writeup&tier=X&token=Y`. Server checks `tier ∈ session.solves`; if yes, returns the writeup HTML; if no, 403.

### Visitor advances

10. Visitor clicks "Fetch medium." JS calls `GET /lab/api.php?action=fetch_binary&tier=medium&token=X`. Server checks `session.solves` contains `"easy"`; if not, 403. Otherwise streams binary bytes (`Content-Type: application/octet-stream`).
11. JS writes bytes into 9pfs at `/home/user/crackme` (replacing easy). Terminal echoes *"medium loaded."*
12. Repeat for hard.

### Failure paths

- Wrong flag: rate-limited (10/min/session), generic "incorrect" response, no info leakage about which character is wrong.
- Tampered token: server returns 401, JS clears localStorage and asks visitor to refresh.
- Direct binary fetch attempt without prior solve: 403 with no body.

### Time-to-solve

`session.created_at` to `solved_at`. Per-tier time = `solved_at[N] − solved_at[N-1]` (or `created_at` for easy). Stored on the leaderboard entry; not displayed live.

## Error handling, abuse, browser support

### Rate limiting (reuses `config.php::enforceRateLimit`)

- `init`: 5 per IP per 5 min — prevents session spam
- `fetch_binary`: 20 per session per min — generous; legitimate usage retries on flaky bytes
- `submit_flag`: 10 per session per min — slows guessing without frustrating real solvers
- `set_handle`: 3 per session per min
- `leaderboard` (read): 30 per IP per min

### Session hygiene

- Sessions older than 30 days with no solves get garbage-collected on every 50th `init` call
- Solved sessions never expire (so leaderboard times remain stable)
- Token is a 64-char hex string: `bin2hex(random_bytes(32))`. Stored only in `sessions.json` and the visitor's `localStorage`.

### Handle moderation

- Regex: `^[a-zA-Z0-9_]{3,16}$`
- Run through the forum's existing word filter
- Reject if already in `leaderboard.json` (case-insensitive)
- No handle changes after first set — keeps leaderboard stable

### Anti-cheat (v0.1 known limitation)

- Static flags. The first solver's writeup or Discord post will leak the flag string.
- Mitigation: leaderboard explicitly shows time-to-solve. Late submissions with a 4-second time stand out as obvious copy-pastes.
- Documented as v0.2 work: per-session randomized flags via runtime binary patching (replace flag bytes in the ELF before serving).

### Browser support

- v86 requires WASM, SharedArrayBuffer, and cross-origin isolation (COOP/COEP headers). `lab/index.html` and the VM assets need `Cross-Origin-Opener-Policy: same-origin` and `Cross-Origin-Embedder-Policy: require-corp` set via `.htaccess`. Confirm shared host supports custom response headers.
- Feature-detect on page load. If unsupported: show a static "Your browser doesn't support the lab — try Chrome/Firefox on desktop" panel with a screenshot of the lab as a fallback so cold visitors still see what it is.

### Mobile / touch

- Detect: `'ontouchstart' in window && !window.matchMedia('(hover: hover)').matches`.
- Block boot, render a centered card: "This lab is keyboard-driven RE work. Please open on desktop." Include a permalink + the screenshot fallback.

### Logging / observability

- Server logs (PHP `error_log`) on: 403 fetch attempts (binary tier without solve), bad token submissions, rate-limit hits. Helps spot probing.
- No client-side telemetry beyond what already exists (visitor counter).

### Failure modes that leave the user stuck

- v86 boot hang: a "Boot stuck? Click to reset VM" button appears after 15s. Reset re-fetches the snapshot.
- 9pfs write fails: log + show "couldn't load binary, please reset VM."
- Flag verify endpoint 5xx: surface as "server error, try again in a sec" rather than silent fail.

## Crackme design intent

Direction, not blueprints — implementation plan turns each into actual C source. Each tier demonstrates a distinct RE technique so the ladder shows range.

### Easy — "warmup" (~10–20 min)

- C binary, statically linked, stripped.
- Reads input from stdin, compares against the flag.
- Flag is stored in `.rodata` xor'd against a single byte (e.g., `0x42`). The xor loop is inline and obvious in `objdump -d`.
- `strings -a` shows nothing useful (the flag is xor'd, not plaintext). Intentional — punishes the lazy `strings` attempt and forces visitors to actually disassemble.
- Intended path: `objdump -d`, recognize the xor loop, dump `.rodata` with `objcopy` or read it in `xxd`, xor in Python.
- Demonstrates: basic ELF disassembly literacy, `objdump`/`xxd` fluency, recognizing simple obfuscation.

### Medium — "byte-by-byte" (~45–60 min)

- C binary, statically linked, stripped, with `ptrace(PTRACE_TRACEME)` self-check at startup. If a debugger is attached, exits silently. Trivial to bypass once spotted (one `ret` patch, or `LD_PRELOAD` a stub `ptrace`), but the visitor has to spot it first.
- Flag check is per-byte: `for i in range(len(flag)): expected[i] = transform(input[i], i)`. Transform is non-trivial — e.g., `(input[i] << 3 | input[i] >> 5) ^ key[i % 8] - i`. Constants live in `.rodata`.
- Length check first, then per-byte comparison with early exit. Side-channel: timing attack would work. Honest path: read the assembly, reverse the transform.
- Intended path: notice ptrace, neutralize it, then disassemble the verify routine and write a python inverse.
- Demonstrates: anti-debug awareness (a real-world habit), per-byte algorithmic reversal, comfort with bit-twiddling.

### Hard — "tiny VM" (~3–6 hours)

- C binary, statically linked, stripped. Includes a small bytecode interpreter with ~12 opcodes (push, pop, xor, add, mul, jmp, jnz, load, store, cmp, halt, in). The flag check is a *program* in this bytecode — the interpreter walks the bytecode array doing operations on the input buffer, eventually halting with a comparison result.
- The bytecode is ~80–150 bytes in `.rodata`. The interpreter is straightforward C but visually noisy in disasm.
- Intended path: identify the dispatch loop, recover the opcode table, transcribe the bytecode into a Python re-implementation, then either run the VM forward to find a fixed point, or solve it with z3/SMT since the operations are arithmetic and small enough.
- Demonstrates: structural understanding of VM-based obfuscation (a real and current technique), patience, often SMT-solver fluency.

### Flag format (all tiers)

`m190{...}` with 16–32 chars between the braces, mix of letters/digits. Each flag references the lab subtly (e.g., `m190{w4rmup_xor_3z}`, `m190{ptr4ce_n3v3r_1n_pr0d}`, `m190{vm_inside_a_vm_inside_a_vm}`).

### Build / release process (one-time setup)

- `make` in a `crackmes/` directory cross-compiles all three with musl-libc statically (so they run in Alpine without dependencies).
- Output goes to `lab/binaries/`.
- Source for the crackmes lives outside the public webroot (private branch or separate repo) — visitors should not be able to grab `easy.c`.

## Testing approach

Static-asset + small-PHP project, no test framework on the existing site. Verification is mostly manual + a small set of curl scripts kept in `lab/test/` for owner use.

### Manual end-to-end (canonical golden path)

1. Open `/lab/` in fresh Chrome incognito → VM boots cleanly within ~5s.
2. `ls /home/user` → see `crackme` only.
3. Run easy crackme, RE it, find flag, paste, verify.
4. Get prompted for handle. Pick one. Confirm leaderboard updates.
5. Writeup panel reveals. Read it.
6. Click "Fetch medium." Binary swaps. Solve. Verify.
7. Same for hard.
8. Refresh page mid-session → progress restored from `localStorage` token + server session.

### Adversarial checks (run before shipping)

- Fetch medium binary directly with garbage token → 401/403, no body bytes leak.
- Fetch medium binary with valid token but no easy solve → 403.
- Submit wrong flag 11 times in a minute → 429 on attempt 11.
- Submit correct flag for tier 2 with no token → 401.
- Try registering a handle that's already on the leaderboard → 409 with a clear "taken" message.
- Try registering a handle with a profanity → rejected with the forum's filter response.
- Open devtools, scrape page source, network tab, JS bundle for flag hashes → none present.
- Try to download `lab/binaries/crackme-medium` directly via URL → 403 from `.htaccess`.
- Try `lab/data/sessions.json` directly → 403 from `.htaccess` (matches forum pattern).

### Crackme self-test

- For each tier, owner solves it with the intended path and times it. If easy takes >20 min, it's overtuned.
- Each crackme has a known-good solve script committed in `crackmes/solutions/` (private, not in webroot). Re-run them when binaries are rebuilt to confirm flags still match `EXPECTED_HASHES`.

### Browser matrix (manual, one-time before launch)

- Chrome desktop, Firefox desktop, Safari desktop — full flow.
- Chrome mobile, Safari iOS — confirms the "desktop only" notice renders and blocks boot.
- Old browser without SharedArrayBuffer — fallback panel renders.

### Explicitly NOT testing

- v86's correctness (upstream library, trust it).
- Cross-tab race conditions (one visitor, one tab is the supported flow).
- Concurrent leaderboard writes from many visitors (existing `flock` pattern handles it; same as the forum's shoutbox).

## Out of scope (v0.2+)

Deliberate cuts to keep v0.1 shippable:

- **Per-session randomized flags** — proper anti-cheat. Patch flag bytes in the ELF at fetch time, expected hash also per-session. Add if cheating actually shows up.
- **Hint system** — "stuck? show hint" buttons that cost time on the leaderboard.
- **More crackmes** — once the framework is in place, adding tier 4/5 is mostly authoring binaries.
- **Forum integration** — m190 badges for solvers, leaderboard reflected on profile pages. Standalone for now; door is open later.
- **Mobile lab** — would need a different challenge format (no v86 on touch). Not worth it for this audience.
- **Speedrun mode** — live timer, public race.
- **Replay / writeup gallery** — public page showing all writeups for visitors who've solved everything. For now writeups are gated per-visitor.
- **Cross-tier scoring** — single composite "rank" instead of three separate leaderboard rows per visitor.
