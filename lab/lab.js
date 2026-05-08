// m190 pwn lab — frontend orchestrator

const $ = (sel) => document.querySelector(sel);
const TIERS = ['easy', 'medium', 'hard'];

// --- Feature detection ---
function isUnsupported() {
    if ('ontouchstart' in window && !window.matchMedia('(hover: hover)').matches) return 'mobile';
    if (typeof SharedArrayBuffer === 'undefined') return 'no-sab';
    if (typeof window.crossOriginIsolated !== 'undefined' && !window.crossOriginIsolated) return 'no-coop';
    if (typeof WebAssembly === 'undefined') return 'no-wasm';
    return null;
}

// --- API helpers ---
async function api(action, opts = {}) {
    const url = `/lab/api.php?action=${action}` + (opts.qs ? '&' + new URLSearchParams(opts.qs) : '');
    const init = { method: opts.method || 'GET' };
    if (opts.body) {
        init.method = 'POST';
        init.body = new URLSearchParams(opts.body);
    }
    const r = await fetch(url, init);
    if (opts.binary) return new Uint8Array(await r.arrayBuffer());
    const j = await r.json();
    if (!r.ok) throw new Error(j.error || `HTTP ${r.status}`);
    return j;
}

// --- Session ---
let session = null;  // { token, leaderboard }

async function ensureSession() {
    const stored = localStorage.getItem('lab_token');
    if (stored && /^[a-f0-9]{64}$/.test(stored)) {
        // We don't have a "resume" endpoint; reuse the token. If invalid the
        // first protected call will 401 and we'll re-init.
        session = { token: stored, leaderboard: [] };
        // Refresh leaderboard
        try {
            const r = await api('leaderboard');
            session.leaderboard = r.leaderboard;
        } catch {}
        return;
    }
    const r = await api('init', { method: 'POST' });
    session = r;
    localStorage.setItem('lab_token', r.token);
}

async function restorePreviousSolves() {
    let lastWriteup = null;
    for (const tier of TIERS) {
        try {
            const r = await api('writeup', { qs: { tier, token: session.token } });
            // Server returned ok=true and html — this tier is solved.
            if (!solves.includes(tier)) solves.push(tier);
            markTierSolved(tier);
            lastWriteup = r.html;
        } catch (err) {
            // 403 = not solved yet; stop walking.
            break;
        }
    }
    if (lastWriteup) showWriteup(lastWriteup);
}

// --- v86 boot ---
let emulator = null;

async function bootVm() {
    $('#boot-status').textContent = 'booting Alpine VM…';
    emulator = new V86({
        wasm_path: '/lab/lib/v86.wasm',
        memory_size: 256 * 1024 * 1024,
        vga_memory_size: 8 * 1024 * 1024,
        screen_container: $('#screen_container'),
        bios:     { url: '/lab/lib/seabios.bin' },
        vga_bios: { url: '/lab/lib/vgabios.bin' },
        hda:      { url: '/lab/vm/alpine.img', async: true },
        initial_state: { url: '/lab/vm/state.bin' },
        autostart: true,
        disable_keyboard: false,
        disable_mouse: true,
        filesystem: {},  // 9p mount; populated below
    });
    // Wait until the VM is responsive (state-based boots fire 'emulator-ready' immediately)
    await new Promise((res) => emulator.add_listener('emulator-ready', res));
    $('#boot-status').textContent = 'VM ready — fetching crackme';
}

async function injectBinary(tier) {
    const bytes = (tier === 'easy')
        ? new Uint8Array(await (await fetch('/lab/binaries/crackme-easy')).arrayBuffer())
        : await api('fetch_binary', { qs: { tier, token: session.token }, binary: true });
    // Write to /crackme on the 9p root. Inside the VM, 9p is mounted at /lab,
    // so this file appears as /lab/crackme.
    await emulator.create_file('/crackme', bytes);
    // Make executable via the serial console (VM auto-logs in as `user`).
    const cmd = '\nchmod +x /lab/crackme && ls -l /lab/crackme\n';
    emulator.serial0_send(cmd);
    $('#boot-status').textContent = `${tier} crackme loaded at /lab/crackme`;
}

// --- Reset ---
function setupResetButton() {
    const btn = $('#reset-vm');
    // Reveal after 15s if boot hasn't completed
    let revealed = false;
    setTimeout(() => {
        if (!revealed && $('#boot-status').textContent.startsWith('booting')) {
            btn.classList.remove('hidden');
            revealed = true;
        }
    }, 15000);
    btn.addEventListener('click', async () => {
        btn.classList.add('hidden');
        if (emulator) emulator.destroy();
        await bootVm();
        // Re-inject the current tier (default easy)
        const tier = currentTier();
        await injectBinary(tier);
    });
}

// --- Tier state (persisted in session.solves on the server, mirrored locally) ---
let solves = [];
function currentTier() {
    // The next unsolved tier in the linear chain
    for (const t of TIERS) if (!solves.includes(t)) return t;
    return 'hard';  // all done
}

// --- Boot ---
async function bootLab() {
    try {
        setupResetButton();
        await ensureSession();
        await restorePreviousSolves();
        renderLeaderboard();
        await bootVm();
        await injectBinary(currentTier());
        if (typeof initUi === 'function') initUi();
    } catch (err) {
        console.error('lab boot failed:', err);
        $('#boot-status').textContent = `boot failed: ${err.message} — try refreshing`;
    }
}

// --- UI helpers (subset; rest in Task 19) ---
function renderLeaderboard() {
    const ol = $('#leaderboard');
    ol.innerHTML = '';
    const board = (session.leaderboard || []).slice(-50).reverse();
    for (const e of board) {
        const li = document.createElement('li');
        const fmtTime = (s) => {
            if (s < 60) return `${s}s`;
            if (s < 3600) return `${Math.floor(s/60)}m`;
            return `${Math.floor(s/3600)}h${Math.floor((s%3600)/60)}m`;
        };
        li.innerHTML = `${e.handle}<span class="lb-tier">${e.tier}</span><span class="lb-time">${fmtTime(e.time_to_solve_seconds)}</span>`;
        ol.appendChild(li);
    }
}

// --- UI: flag form, handle dialog, tier transitions ---
function setStatus(text, kind = '') {
    const el = $('#flag-status');
    el.textContent = text;
    el.className = `flag-status ${kind}`;
}

async function onSubmitFlag(e) {
    e.preventDefault();
    const flag = $('#flag-input').value.trim();
    if (!flag) return;
    const tier = currentTier();
    setStatus('verifying…');
    try {
        const r = await api('submit_flag', {
            body: { token: session.token, tier, flag },
        });
        if (!r.correct) {
            setStatus('incorrect', 'bad');
            return;
        }
        setStatus(`correct — ${tier} solved`, 'good');
        $('#flag-input').value = '';
        if (!solves.includes(tier)) solves.push(tier);
        markTierSolved(tier);
        // Show writeup
        showWriteup(r.writeup_html);
        // Handle prompt if needed
        if (r.needs_handle) {
            await promptHandle();
            await refreshLeaderboard();
        }
        // Advance to next tier
        if (r.next_tier_unlocked) {
            await advanceTier(r.next_tier_unlocked);
        } else {
            setStatus('all tiers solved 🎉', 'good');
        }
    } catch (err) {
        if (err.message.includes('invalid token')) {
            localStorage.removeItem('lab_token');
            setStatus('session expired — refresh page', 'bad');
            return;
        }
        setStatus(`error: ${err.message}`, 'bad');
    }
}

function markTierSolved(tier) {
    const card = document.querySelector(`.tier-card[data-tier="${tier}"]`);
    if (card) {
        card.classList.remove('locked', 'active');
        card.classList.add('solved');
        $(`#status-${tier}`).textContent = 'solved';
    }
}
function markTierActive(tier) {
    document.querySelectorAll('.tier-card').forEach(c => c.classList.remove('active'));
    const card = document.querySelector(`.tier-card[data-tier="${tier}"]`);
    if (card) {
        card.classList.remove('locked');
        card.classList.add('active');
        $(`#status-${tier}`).textContent = 'active';
    }
}

async function advanceTier(nextTier) {
    markTierActive(nextTier);
    try {
        await injectBinary(nextTier);
    } catch (err) {
        setStatus(`couldn't load ${nextTier}: ${err.message}`, 'bad');
    }
}

function showWriteup(html) {
    $('#writeup-body').innerHTML = html;
    $('#writeup-panel').classList.remove('hidden');
}

async function refreshLeaderboard() {
    try {
        const r = await api('leaderboard');
        session.leaderboard = r.leaderboard;
        renderLeaderboard();
    } catch {}
}

function promptHandle() {
    return new Promise((resolve) => {
        const dlg = $('#handle-dialog');
        const errEl = $('#handle-error');
        errEl.textContent = '';
        dlg.showModal();
        $('#handle-cancel').onclick = () => { dlg.close('cancel'); resolve(null); };
        $('#handle-form').onsubmit = async (e) => {
            if (e.submitter && e.submitter.id !== 'handle-submit') return;
            e.preventDefault();
            const h = $('#handle-input').value.trim();
            try {
                const r = await api('set_handle', { body: { token: session.token, handle: h } });
                dlg.close('ok');
                resolve(r.handle);
            } catch (err) {
                errEl.textContent = err.message;
            }
        };
    });
}

function initUi() {
    $('#flag-form').addEventListener('submit', onSubmitFlag);
    markTierActive('easy');
}

// --- Entry ---
const reason = isUnsupported();
if (reason) {
    const card = $('#unsupported');
    const msg = card.querySelector('p');
    if (reason === 'mobile') msg.textContent = 'This lab is keyboard-driven RE work in a real Linux VM. Please open on a desktop browser.';
    else if (reason === 'no-coop') msg.textContent = 'Your browser/server lacks cross-origin isolation. The lab requires COOP/COEP headers, set by .htaccess on production.';
    else msg.textContent = 'Your browser is missing features required by the in-browser VM (SharedArrayBuffer, WebAssembly).';
    card.classList.remove('hidden');
} else {
    $('#lab-root').classList.remove('hidden');
    window.addEventListener('DOMContentLoaded', bootLab);
}
