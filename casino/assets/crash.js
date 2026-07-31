// Crash client: polls the authoritative global round and renders the rocket
// from the server clock. Sends only intent (bet / cash out); the server owns the
// bust point and every payout. The multiplier is predicted locally between polls
// (re-synced each poll) so the curve animates smoothly.
(function () {
  const cv = document.getElementById('crash-canvas');
  if (!cv) return;
  const ctx = cv.getContext('2d');
  const W = cv.width, H = cv.height;
  const rate = CRASH.rate;

  const multEl = document.getElementById('crash-mult');
  const phaseEl = document.getElementById('crash-phase');
  const msgEl = document.getElementById('crash-msg');
  const actionBtn = document.getElementById('crash-action');
  const chips = document.getElementById('c-bet-chips');
  const autoInp = document.getElementById('crash-auto');
  const boardList = document.getElementById('crash-board-list');
  const boardCount = document.getElementById('crash-board-count');
  const histEl = document.getElementById('crash-history');
  const fairEl = document.getElementById('crash-fair');

  let view = null, bet = 50, busy = false;
  let flyAnchor = 0, bettingEndsAt = 0, localMult = 1, prevPhase = null, spinning = false;

  chips.addEventListener('click', (e) => { const c = e.target.closest('.c-chip'); if (!c) return; bet = parseInt(c.dataset.bet, 10); chips.querySelectorAll('.c-chip').forEach((x) => x.classList.toggle('on', x === c)); renderControls(); });

  const fmt = (m) => Number(m).toFixed(2);

  async function post(action, body) { return Casino.post('/casino/crash.php', Object.assign({ action }, body || {})); }

  async function poll() {
    if (busy) return;
    const v = await post('poll');
    if (v && v.ok) apply(v);
  }

  function apply(v) {
    const before = view;
    view = v;
    if (typeof v.balance === 'number') Casino.setBalance(v.balance);
    const now = performance.now();
    if (v.phase === 'flying') flyAnchor = now - v.elapsed;
    if (v.phase === 'betting') bettingEndsAt = now + v.bettingEndsIn;
    if (v.phase !== prevPhase) onPhaseChange(prevPhase, v.phase, before, v);
    prevPhase = v.phase;
    renderBoard(); renderHistory(); renderFair(); renderControls();
  }

  function onPhaseChange(from, to, before, v) {
    if (to === 'flying') { if (window.SFX && !spinning) { SFX.spinStart(); spinning = true; } msgEl.textContent = ''; msgEl.className = 'c-msg'; }
    if (to !== 'flying' && spinning) { if (window.SFX) SFX.spinStop(); spinning = false; }
    if (to === 'over') {
      const me = v.me;
      if (me && me.cashed > 0) { if (window.SFX) SFX.win(me.cashed >= 3); msgEl.textContent = 'Cashed @ ' + fmt(me.cashed) + '× — won 🪙 ' + Casino.fmt(me.payout); msgEl.className = 'c-msg c-win'; }
      else if (me) { if (window.SFX) SFX.lose(); msgEl.textContent = 'Busted @ ' + fmt(v.bust) + '× — lost 🪙 ' + Casino.fmt(me.bet); msgEl.className = 'c-msg c-lose'; }
    }
    if (to === 'betting') { msgEl.textContent = ''; msgEl.className = 'c-msg'; }
  }

  // ---------- rendering ----------
  function draw(mult, mode) {
    ctx.clearRect(0, 0, W, H);
    // grid
    ctx.strokeStyle = 'rgba(255,255,255,.05)'; ctx.lineWidth = 1;
    for (let gx = 0; gx <= W; gx += 90) { ctx.beginPath(); ctx.moveTo(gx, 0); ctx.lineTo(gx, H); ctx.stroke(); }
    for (let gy = 0; gy <= H; gy += 76) { ctx.beginPath(); ctx.moveTo(0, gy); ctx.lineTo(W, gy); ctx.stroke(); }

    const pad = 24;
    const scaleMax = Math.max(2, mult * 1.35);           // keep the tip ~70% up as it grows
    const yFor = (m) => (H - pad) - ((m - 1) / (scaleMax - 1)) * (H - pad * 2);
    const N = 60, curEl = mode === 'idle' ? 0 : Math.log(mult) / rate;   // ms of flight represented
    const col = mode === 'bust' ? '#eb4b4b' : mode === 'idle' ? '#5e98d9' : '#43d17a';

    if (mode !== 'idle') {
      ctx.beginPath(); ctx.moveTo(pad, H - pad);
      for (let i = 0; i <= N; i++) { const t = curEl * i / N; const m = Math.exp(rate * t); ctx.lineTo(pad + (i / N) * (W - pad * 2), yFor(m)); }
      const tipX = W - pad, tipY = yFor(mult);
      // filled area
      ctx.lineTo(tipX, H - pad); ctx.closePath();
      const grad = ctx.createLinearGradient(0, 0, 0, H);
      grad.addColorStop(0, mode === 'bust' ? 'rgba(235,75,75,.28)' : 'rgba(67,209,122,.28)');
      grad.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = grad; ctx.fill();
      // curve stroke
      ctx.beginPath(); ctx.moveTo(pad, H - pad);
      for (let i = 0; i <= N; i++) { const t = curEl * i / N; const m = Math.exp(rate * t); ctx.lineTo(pad + (i / N) * (W - pad * 2), yFor(m)); }
      ctx.strokeStyle = col; ctx.lineWidth = 3; ctx.stroke();
      // rocket / burst
      ctx.font = '26px serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText(mode === 'bust' ? '💥' : '🚀', Math.min(tipX, W - 16), Math.max(16, tipY));
    } else {
      ctx.font = '26px serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText('🚀', pad + 10, H - pad - 6);
    }
  }

  function renderControls() {
    const v = view, me = v && v.me;
    let label = 'BET', mode = 'idle', disabled = true;
    if (!v) { label = '…'; }
    else if (v.phase === 'betting') {
      if (me) { label = 'In this round · 🪙 ' + Casino.fmt(me.bet); disabled = true; }
      else { label = 'BET · 🪙 ' + Casino.fmt(bet); mode = 'bet'; disabled = false; }
    } else if (v.phase === 'flying') {
      if (me && me.cashed === 0) { label = 'CASH OUT ' + fmt(localMult) + '× · +🪙 ' + Casino.fmt(Math.floor(me.bet * localMult)); mode = 'cashout'; disabled = false; actionBtn.classList.add('crash-cashbtn'); }
      else if (me) { label = 'Cashed @ ' + fmt(me.cashed) + '×'; disabled = true; }
      else { label = 'Next round…'; disabled = true; }
    } else { // over
      label = me && me.cashed > 0 ? 'Won 🪙 ' + Casino.fmt(me.payout) : 'Next round soon…'; disabled = true;
    }
    if (mode !== 'cashout') actionBtn.classList.remove('crash-cashbtn');
    actionBtn.textContent = label; actionBtn.disabled = disabled || busy; actionBtn.dataset.mode = mode;
  }

  function updateCashLabel() {
    if (view && view.phase === 'flying' && view.me && view.me.cashed === 0 && actionBtn.dataset.mode === 'cashout') {
      actionBtn.textContent = 'CASH OUT ' + fmt(localMult) + '× · +🪙 ' + Casino.fmt(Math.floor(view.me.bet * localMult));
    }
  }

  function renderBoard() {
    const b = view ? view.board : [];
    boardCount.textContent = b.length + (b.length === 1 ? ' player' : ' players');
    boardList.innerHTML = '';
    b.forEach((p) => {
      const row = document.createElement('div'); row.className = 'crash-brow';
      let status = '<span class="c-dim">in</span>', cls = '';
      if (p.cashed > 0) { status = '<b>' + fmt(p.cashed) + '× · +' + Casino.fmt(p.payout) + '</b>'; cls = 'won'; }
      else if (view && view.phase === 'over') { status = 'bust'; cls = 'bust'; }
      row.className = 'crash-brow ' + cls;
      row.innerHTML = '<span class="crash-bname"></span><span class="crash-bbet">🪙 ' + Casino.fmt(p.bet) + '</span><span class="crash-bstat">' + status + '</span>';
      row.querySelector('.crash-bname').textContent = p.username;
      boardList.appendChild(row);
    });
    if (!b.length) boardList.innerHTML = '<div class="c-dim" style="padding:8px">No bets yet this round.</div>';
  }

  let lastHistKey = '';
  function renderHistory() {
    const h = view ? view.history : [];
    const key = h.join(',');
    if (key === lastHistKey) return; lastHistKey = key;
    histEl.innerHTML = '';
    h.forEach((m) => {
      const s = document.createElement('span');
      s.className = 'crash-hchip ' + (m < 2 ? 'lo' : m < 10 ? 'mid' : 'hi');
      s.textContent = fmt(m) + '×';
      histEl.appendChild(s);
    });
  }

  function renderFair() {
    if (!view) return;
    const hash = (view.seedHash || '').slice(0, 12);
    fairEl.textContent = view.seed ? 'seed ' + view.seed.slice(0, 12) + '… (revealed)' : 'hash ' + hash + '…';
    fairEl.title = view.seed ? 'seed: ' + view.seed + '\nhash: ' + view.seedHash : 'SHA-256 of this round\'s seed (revealed after the bust)';
  }

  // ---------- actions ----------
  actionBtn.addEventListener('click', async () => {
    const mode = actionBtn.dataset.mode;
    if (busy || !mode || mode === 'idle') return;
    busy = true; actionBtn.disabled = true;
    try {
      if (mode === 'bet') {
        let auto = parseFloat(autoInp.value); if (!(auto >= 1.01)) auto = 0;
        if (window.SFX) SFX.chip();
        const r = await post('bet', { bet, auto });
        if (r && r.ok) { apply(r); } else { flash((r && r.error) || 'Error'); }
      } else if (mode === 'cashout') {
        const r = await post('cashout');
        if (r && r.ok) {
          apply(r);
          if (r.me && r.me.cashed > 0) { if (window.SFX) SFX.win(r.me.cashed >= 3); flash('Cashed @ ' + fmt(r.me.cashed) + '× — won 🪙 ' + Casino.fmt(r.me.payout), 'c-win'); }
        } else { flash((r && r.error) || 'Error'); }
      }
    } finally { busy = false; renderControls(); }
  });

  function flash(text, cls) { msgEl.textContent = text; msgEl.className = 'c-msg ' + (cls || 'c-lose'); }

  // ---------- loops ----------
  function frame() {
    if (view) {
      if (view.phase === 'flying') {
        const t = performance.now() - flyAnchor;
        localMult = Math.max(1, Math.exp(rate * t));
        multEl.textContent = fmt(localMult) + '×'; multEl.className = 'crash-mult fly';
        draw(localMult, 'fly');
        updateCashLabel();
        phaseEl.textContent = '🚀 Flying';
      } else if (view.phase === 'over') {
        const b = view.bust || localMult;
        multEl.textContent = fmt(b) + '×'; multEl.className = 'crash-mult bust';
        draw(b, 'bust');
        phaseEl.textContent = '💥 Busted @ ' + fmt(b) + '×';
      } else {
        localMult = 1; multEl.textContent = '1.00×'; multEl.className = 'crash-mult';
        draw(1, 'idle');
        const left = Math.max(0, bettingEndsAt - performance.now());
        phaseEl.textContent = 'Place bets — ' + (left / 1000).toFixed(1) + 's';
      }
    }
    requestAnimationFrame(frame);
  }

  poll();
  setInterval(poll, 700);
  requestAnimationFrame(frame);
})();
