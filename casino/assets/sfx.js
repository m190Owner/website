// Casino sound effects, synthesized at runtime with Web Audio (no audio files).
// Card swishes, chip clinks, slot reels, and win/lose jingles. Respects a mute
// toggle (persisted). The AudioContext unlocks on the first user gesture.
window.SFX = (function () {
  let ctx = null, master = null, spinTimer = null;
  let muted = localStorage.getItem('casino_muted') === '1';

  function ac() {
    if (!ctx) {
      const AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return null;
      ctx = new AC();
      master = ctx.createGain(); master.gain.value = 0.75; master.connect(ctx.destination);
    }
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
  }
  // Robustly unlock/keep-alive the AudioContext: browsers only let it start (and
  // stay started) around a user gesture, so resume on every gesture type and when
  // the tab regains focus, and prime it once with a silent buffer.
  function unlock() {
    const c = ac(); if (!c) return;
    if (c.state === 'suspended') c.resume();
    try { const b = c.createBuffer(1, 1, c.sampleRate); const s = c.createBufferSource(); s.buffer = b; s.connect(c.destination); s.start(0); } catch (e) {}
  }
  ['pointerdown', 'touchstart', 'keydown', 'click'].forEach((ev) => addEventListener(ev, unlock, { capture: true, passive: true }));
  document.addEventListener('visibilitychange', () => { if (!document.hidden && ctx && ctx.state === 'suspended') ctx.resume(); });
  function noiseBuf(dur) {
    const c = ac(); const n = Math.max(1, Math.floor(c.sampleRate * dur));
    const b = c.createBuffer(1, n, c.sampleRate); const d = b.getChannelData(0);
    for (let i = 0; i < n; i++) d[i] = Math.random() * 2 - 1;
    return b;
  }
  function env(g, t0, a, d, peak) {
    g.gain.setValueAtTime(0.0001, t0);
    g.gain.linearRampToValueAtTime(peak, t0 + a);
    g.gain.exponentialRampToValueAtTime(0.0001, t0 + a + d);
  }
  function tone(freq, t0, dur, type, peak) {
    const c = ac(); if (!c) return;
    const o = c.createOscillator(), g = c.createGain();
    o.type = type || 'sine'; o.frequency.value = freq;
    env(g, t0, 0.006, dur, peak); o.connect(g).connect(master);
    o.start(t0); o.stop(t0 + dur + 0.05);
  }
  function noise(t0, dur, freq, q, peak) {
    const c = ac(); if (!c) return;
    const s = c.createBufferSource(); s.buffer = noiseBuf(dur + 0.02);
    const f = c.createBiquadFilter(); f.type = 'bandpass'; f.frequency.value = freq; f.Q.value = q || 1;
    const g = c.createGain(); env(g, t0, 0.002, dur, peak);
    s.connect(f).connect(g).connect(master); s.start(t0); s.stop(t0 + dur + 0.05);
  }

  const api = {
    isMuted() { return muted; },
    toggle() { muted = !muted; localStorage.setItem('casino_muted', muted ? '1' : '0'); if (muted) api.spinStop(); return muted; },

    // one card sliding off the deck
    card() { if (muted) return; const c = ac(); if (!c) return; noise(c.currentTime, 0.085, 2400 + Math.random() * 500, 0.8, 0.22); },
    // deal N cards in quick succession
    deal(n) { if (muted) return; const c = ac(); if (!c) return; n = n || 1; for (let i = 0; i < n; i++) noise(c.currentTime + i * 0.12, 0.085, 2400 + Math.random() * 500, 0.8, 0.22); },
    // chips into the pot
    chip() { if (muted) return; const c = ac(); if (!c) return; const t = c.currentTime; tone(1300, t, 0.05, 'triangle', 0.16); tone(1850, t + 0.02, 0.05, 'triangle', 0.12); noise(t, 0.03, 5200, 2, 0.07); },
    // a slot reel locking into place
    reelStop() { if (muted) return; const c = ac(); if (!c) return; const t = c.currentTime; tone(170, t, 0.09, 'square', 0.2); noise(t, 0.05, 900, 1, 0.11); },
    tick() { if (muted) return; const c = ac(); if (!c) return; noise(c.currentTime, 0.02, 3000, 3, 0.05); },
    spinStart() { if (muted) return; api.spinStop(); spinTimer = setInterval(() => api.tick(), 55); },
    spinStop() { if (spinTimer) { clearInterval(spinTimer); spinTimer = null; } },

    win(big) { if (muted) return; const c = ac(); if (!c) return; const t = c.currentTime; const notes = big ? [523, 659, 784, 1047, 1319] : [523, 659, 784]; notes.forEach((f, i) => tone(f, t + i * 0.09, 0.19, 'triangle', 0.28)); },
    lose() { if (muted) return; const c = ac(); if (!c) return; const t = c.currentTime; tone(320, t, 0.14, 'sawtooth', 0.16); tone(220, t + 0.11, 0.2, 'sawtooth', 0.14); },
    push() { if (muted) return; const c = ac(); if (!c) return; tone(440, c.currentTime, 0.12, 'sine', 0.15); },
    state() { return ctx ? ctx.state : 'none'; },
  };

  // mute button in the casino nav
  addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('c-mute');
    if (!btn) return;
    const paint = () => { btn.textContent = muted ? '🔇' : '🔊'; };
    paint();
    btn.addEventListener('click', () => { const m = api.toggle(); paint(); if (!m) api.chip(); });
  });

  return api;
})();
