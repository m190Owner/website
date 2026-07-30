// Plinko: the server decides every ball's path + slot; this animates them down.
// Up to PLINKO_MAX_BALLS balls drop at once, staggered so they cascade.
(function () {
  const cv = document.getElementById('plinko-canvas');
  if (!cv) return;
  const ctx = cv.getContext('2d');
  const ROWS = PLINKO.rows, MULTS = PLINKO.mults;
  const W = cv.width, H = cv.height, cx = W / 2, topY = 46, gap = 38, rowH = (H - 130) / ROWS;
  const msg = document.getElementById('plinko-msg');
  const dropBtn = document.getElementById('plinko-drop');
  const chips = document.getElementById('c-bet-chips');
  const ballChips = document.getElementById('c-balls-chips');
  const stakeEl = document.querySelector('#plinko-stake b');
  let bet = 50, balls = 1, busy = false;

  const syncStake = () => { if (stakeEl) stakeEl.textContent = Casino.fmt(bet * balls); };
  chips.addEventListener('click', (e) => { const c = e.target.closest('.c-chip'); if (!c) return; bet = parseInt(c.dataset.bet, 10); chips.querySelectorAll('.c-chip').forEach((x) => x.classList.toggle('on', x === c)); syncStake(); });
  ballChips.addEventListener('click', (e) => { const c = e.target.closest('.c-chip'); if (!c) return; balls = parseInt(c.dataset.balls, 10); ballChips.querySelectorAll('.c-chip').forEach((x) => x.classList.toggle('on', x === c)); syncStake(); });

  const px = (lvl, rights) => cx + (rights - lvl / 2) * gap;
  const py = (lvl) => topY + lvl * rowH;
  function rr(x, y, w, h, r) { ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath(); }
  const slotColor = (m) => m >= 10 ? '#eb4b4b' : m >= 3 ? '#e8c15a' : m >= 1.2 ? '#5e98d9' : '#2a2a33';

  // hl: {slot: count} of balls that have landed there.
  function draw(active, hl) {
    ctx.clearRect(0, 0, W, H);
    ctx.fillStyle = '#cdd3e0';
    for (let i = 0; i < ROWS; i++) for (let k = 0; k <= i; k++) { ctx.beginPath(); ctx.arc(px(i, k), py(i), 3.2, 0, 6.283); ctx.fill(); }
    const slotY = py(ROWS) + 8, slotW = gap * 0.92;
    ctx.textAlign = 'center'; ctx.font = 'bold 11px Segoe UI, sans-serif';
    for (let s = 0; s <= ROWS; s++) {
      const x = px(ROWS, s), m = MULTS[s], hit = (hl && hl[s]) || 0;
      ctx.fillStyle = hit ? '#fff' : slotColor(m);
      rr(x - slotW / 2, slotY, slotW, 26, 5); ctx.fill();
      ctx.fillStyle = (m >= 1.2 || hit) ? '#111' : '#cdd3e0';
      ctx.fillText(m + 'x', x, slotY + 17);
      if (hit > 1) { ctx.fillStyle = '#eb4b4b'; ctx.font = 'bold 10px Segoe UI, sans-serif'; ctx.fillText('×' + hit, x, slotY - 3); ctx.font = 'bold 11px Segoe UI, sans-serif'; }
    }
    if (active) for (const b of active) { ctx.fillStyle = '#e8c15a'; ctx.beginPath(); ctx.arc(b.x, b.y, 6.5, 0, 6.283); ctx.fill(); ctx.strokeStyle = 'rgba(0,0,0,.4)'; ctx.stroke(); }
  }

  function waypoints(path) {
    const wps = [{ x: px(0, 0), y: py(0) }]; let rights = 0;
    for (let i = 0; i < ROWS; i++) { rights += path[i]; wps.push({ x: px(i + 1, rights), y: py(i + 1) }); }
    return wps;
  }

  // Animate every result concurrently, each staggered by a few frames.
  function animate(results) {
    const bs = results.map((r, i) => ({
      wps: waypoints(r.path), slot: r.slot,
      wait: i * 8, seg: 0, t: 0, done: false,
      x: px(0, 0), y: py(0),
    }));
    const landed = () => { const h = {}; for (const b of bs) if (b.done) h[b.slot] = (h[b.slot] || 0) + 1; return h; };
    return new Promise((res) => {
      let finished = false, tickCd = 0;
      const finish = () => {
        if (finished) return; finished = true; clearTimeout(safety);
        for (const b of bs) { const last = b.wps[b.wps.length - 1]; b.x = last.x; b.y = last.y; b.done = true; }
        draw(bs, landed()); res();
      };
      const safety = setTimeout(finish, 3000 + results.length * 500);   // never hang if rAF is throttled
      (function step() {
        if (finished) return;
        let allDone = true, tick = false;
        for (const b of bs) {
          if (b.done) continue;
          allDone = false;
          if (b.wait > 0) { b.wait--; continue; }
          if (b.seg >= b.wps.length - 1) { const last = b.wps[b.wps.length - 1]; b.x = last.x; b.y = last.y; b.done = true; continue; }
          b.t += 0.07;
          const a = b.wps[b.seg], c = b.wps[b.seg + 1], e = Math.min(1, b.t);
          b.x = a.x + (c.x - a.x) * e;
          b.y = a.y + (c.y - a.y) * e - Math.sin(e * Math.PI) * 9;
          if (b.t >= 1) { tick = true; b.seg++; b.t = 0; }
        }
        if (tickCd > 0) tickCd--;
        if (tick && tickCd === 0 && window.SFX) { SFX.tick(); tickCd = 4; }   // steady patter, not a machine-gun
        draw(bs, landed());
        if (allDone) { finish(); return; }
        requestAnimationFrame(step);
      })();
    });
  }

  async function drop() {
    if (busy) return; busy = true; dropBtn.disabled = true;
    msg.textContent = ''; msg.className = 'c-msg';
    if (window.SFX) SFX.chip();
    const r = await Casino.post('/casino/plinko.php', { action: 'drop', bet, balls });
    if (!r || !r.ok) { msg.textContent = (r && r.error) || 'Error'; msg.className = 'c-msg c-lose'; busy = false; dropBtn.disabled = false; return; }
    await animate(r.balls);
    Casino.setBalance(r.balance);
    const net = r.net;
    if (r.balls.length === 1) {
      const b0 = r.balls[0];
      if (b0.payout > 0) { msg.textContent = b0.mult + '× → +' + Casino.fmt(b0.payout) + ' LS' + (net > 0 ? '' : ' (net ' + Casino.fmt(net) + ')'); msg.className = 'c-msg ' + (net > 0 ? 'c-win' : 'c-push'); if (window.SFX) SFX.win(b0.mult >= 3); }
      else { msg.textContent = '0× — lost your bet'; msg.className = 'c-msg c-lose'; if (window.SFX) SFX.lose(); }
    } else {
      msg.textContent = r.balls.length + ' balls · staked ' + Casino.fmt(r.totalBet) + ' → won ' + Casino.fmt(r.totalPayout) + ' LS (net ' + (net >= 0 ? '+' : '') + Casino.fmt(net) + ')';
      msg.className = 'c-msg ' + (net > 0 ? 'c-win' : net === 0 ? 'c-push' : 'c-lose');
      if (window.SFX) { if (net > 0) SFX.win(net >= r.totalBet); else SFX.lose(); }
    }
    busy = false; dropBtn.disabled = false;
  }

  draw(null, null);
  syncStake();
  dropBtn.addEventListener('click', drop);
})();
