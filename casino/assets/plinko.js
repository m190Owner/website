// Plinko: the server decides the path + slot; this animates the ball down to it.
(function () {
  const cv = document.getElementById('plinko-canvas');
  if (!cv) return;
  const ctx = cv.getContext('2d');
  const ROWS = PLINKO.rows, MULTS = PLINKO.mults;
  const W = cv.width, H = cv.height, cx = W / 2, topY = 46, gap = 38, rowH = (H - 130) / ROWS;
  const msg = document.getElementById('plinko-msg');
  const dropBtn = document.getElementById('plinko-drop');
  const chips = document.getElementById('c-bet-chips');
  let bet = 50, busy = false;

  chips.addEventListener('click', (e) => { const c = e.target.closest('.c-chip'); if (!c) return; bet = parseInt(c.dataset.bet, 10); chips.querySelectorAll('.c-chip').forEach((x) => x.classList.toggle('on', x === c)); });

  const px = (lvl, rights) => cx + (rights - lvl / 2) * gap;
  const py = (lvl) => topY + lvl * rowH;
  function rr(x, y, w, h, r) { ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath(); }
  const slotColor = (m) => m >= 10 ? '#eb4b4b' : m >= 3 ? '#e8c15a' : m >= 1.2 ? '#5e98d9' : '#2a2a33';

  function draw(ball, hl) {
    ctx.clearRect(0, 0, W, H);
    ctx.fillStyle = '#cdd3e0';
    for (let i = 0; i < ROWS; i++) for (let k = 0; k <= i; k++) { ctx.beginPath(); ctx.arc(px(i, k), py(i), 3.2, 0, 6.283); ctx.fill(); }
    const slotY = py(ROWS) + 8, slotW = gap * 0.92;
    ctx.textAlign = 'center'; ctx.font = 'bold 11px Segoe UI, sans-serif';
    for (let s = 0; s <= ROWS; s++) {
      const x = px(ROWS, s), m = MULTS[s];
      ctx.fillStyle = s === hl ? '#fff' : slotColor(m);
      rr(x - slotW / 2, slotY, slotW, 26, 5); ctx.fill();
      ctx.fillStyle = (m >= 1.2 || s === hl) ? '#111' : '#cdd3e0';
      ctx.fillText(m + 'x', x, slotY + 17);
    }
    if (ball) { ctx.fillStyle = '#e8c15a'; ctx.beginPath(); ctx.arc(ball.x, ball.y, 7, 0, 6.283); ctx.fill(); ctx.strokeStyle = 'rgba(0,0,0,.4)'; ctx.stroke(); }
  }

  function animate(path, slot) {
    const wps = [{ x: px(0, 0), y: py(0) }]; let rights = 0;
    for (let i = 0; i < ROWS; i++) { rights += path[i]; wps.push({ x: px(i + 1, rights), y: py(i + 1) }); }
    return new Promise((res) => {
      let seg = 0, t = 0, finished = false;
      const finish = () => { if (finished) return; finished = true; clearTimeout(safety); draw({ x: wps[wps.length - 1].x, y: wps[wps.length - 1].y }, slot); res(); };
      const safety = setTimeout(finish, 4000);   // never hang if rAF is throttled
      (function step() {
        if (finished) return;
        if (seg >= wps.length - 1) { finish(); return; }
        t += 0.07;
        const a = wps[seg], b = wps[seg + 1], e = Math.min(1, t);
        draw({ x: a.x + (b.x - a.x) * e, y: a.y + (b.y - a.y) * e - Math.sin(e * Math.PI) * 9 }, -1);
        if (t >= 1) { if (window.SFX) SFX.tick(); seg++; t = 0; }
        requestAnimationFrame(step);
      })();
    });
  }

  async function drop() {
    if (busy) return; busy = true; dropBtn.disabled = true;
    msg.textContent = ''; msg.className = 'c-msg';
    if (window.SFX) SFX.chip();
    const r = await Casino.post('/casino/plinko.php', { action: 'drop', bet });
    if (!r || !r.ok) { msg.textContent = (r && r.error) || 'Error'; msg.className = 'c-msg c-lose'; busy = false; dropBtn.disabled = false; return; }
    await animate(r.path, r.slot);
    Casino.setBalance(r.balance);
    if (r.payout > 0) { msg.textContent = r.mult + '× → +' + Casino.fmt(r.payout) + ' LS' + (r.net > 0 ? '' : ' (net ' + Casino.fmt(r.net) + ')'); msg.className = 'c-msg ' + (r.net > 0 ? 'c-win' : 'c-push'); if (window.SFX) SFX.win(r.mult >= 3); }
    else { msg.textContent = '0× — lost your bet'; msg.className = 'c-msg c-lose'; if (window.SFX) SFX.lose(); }
    busy = false; dropBtn.disabled = false;
  }

  draw(null, -1);
  dropBtn.addEventListener('click', drop);
})();
