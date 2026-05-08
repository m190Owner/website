// m190 pwn lab — frontend orchestrator
// Boots v86, manages 9pfs binary injection, handles UI.

const $ = (sel) => document.querySelector(sel);

function isUnsupported() {
    // Touch-only with no hover → mobile
    if ('ontouchstart' in window && !window.matchMedia('(hover: hover)').matches) return 'mobile';
    // SharedArrayBuffer required for v86
    if (typeof SharedArrayBuffer === 'undefined') return 'no-sab';
    // Cross-origin isolation required
    if (typeof window.crossOriginIsolated !== 'undefined' && !window.crossOriginIsolated) return 'no-coop';
    // WebAssembly required
    if (typeof WebAssembly === 'undefined') return 'no-wasm';
    return null;
}

// Stub: filled in by Task 18.
async function bootLab() {
    $('#boot-status').textContent = 'lab.js loaded — VM boot pending';
}

const reason = isUnsupported();
if (reason) {
    const card = $('#unsupported');
    const msg = card.querySelector('p');
    if (reason === 'mobile') msg.textContent = 'This lab is keyboard-driven RE work in a real Linux VM. Please open on a desktop browser.';
    else if (reason === 'no-coop') msg.textContent = 'Your browser/server lacks cross-origin isolation. The lab requires COOP/COEP headers, which are set by .htaccess on production.';
    else msg.textContent = 'Your browser is missing features required by the in-browser VM (SharedArrayBuffer, WebAssembly). Try Chrome or Firefox on desktop.';
    card.classList.remove('hidden');
} else {
    $('#lab-root').classList.remove('hidden');
    window.addEventListener('DOMContentLoaded', bootLab);
}
