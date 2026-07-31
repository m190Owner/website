// Roulette client: place chips on the board, spin, and reveal. The server rolls
// the winning pocket and settles every bet; this only collects the bet map and
// animates the wheel-order reel onto the result.
(function () {
  const board = document.getElementById('rou-board');
  const chips = document.getElementById('rou-chips');
  const totalEl = document.getElementById('rou-total');
  const spinBtn = document.getElementById('rou-spin');
  const clearBtn = document.getElementById('rou-clear');
  const msg = document.getElementById('rou-msg');
  const reel = document.getElementById('rou-reel');
  const WHEEL = ROULETTE.wheel.map(String), RED = ROULETTE.red;
  const TILE = 54; // px per reel tile incl. margin
  let chip = 5, bets = {}, busy = false;

  chips.addEventListener('click', (e) => { const c = e.target.closest('.c-chip'); if (!c) return; chip = parseInt(c.dataset.chip, 10); chips.querySelectorAll('.c-chip').forEach((x) => x.classList.toggle('on', x === c)); });

  const total = () => Object.values(bets).reduce((a, b) => a + b, 0);
  const colorOf = (n) => (n === '0' || n === '00') ? 'green' : (RED.includes(+n) ? 'red' : 'black');

  function refresh() {
    totalEl.textContent = Casino.fmt(total());
    board.querySelectorAll('.rou-cell').forEach((cell) => {
      const amt = bets[cell.dataset.bet] || 0;
      let b = cell.querySelector('.rou-chip');
      if (amt > 0) { if (!b) { b = document.createElement('span'); b.className = 'rou-chip'; cell.appendChild(b); } b.textContent = amt > 999 ? (amt / 1000).toFixed(0) + 'k' : amt; }
      else if (b) b.remove();
    });
  }

  board.addEventListener('click', (e) => {
    if (busy) return;
    const cell = e.target.closest('.rou-cell'); if (!cell) return;
    bets[cell.dataset.bet] = (bets[cell.dataset.bet] || 0) + chip;
    if (window.SFX) SFX.chip();
    refresh();
  });
  clearBtn.addEventListener('click', () => { if (busy) return; bets = {}; refresh(); msg.textContent = 'Place your bets.'; msg.className = 'c-msg'; });

  function tile(n) { const el = document.createElement('div'); el.className = 'rou-tile ' + colorOf(n); el.textContent = n; return el; }

  function animateTo(winning) {
    const idx = WHEEL.indexOf(String(winning));
    const loops = 6, strip = [];
    for (let l = 0; l < loops; l++) for (let i = 0; i < WHEEL.length; i++) strip.push(WHEEL[i]);
    reel.style.transition = 'none'; reel.style.transform = 'translateX(0)'; reel.innerHTML = '';
    strip.forEach((n) => reel.appendChild(tile(n)));
    const landAt = (loops - 1) * WHEEL.length + idx;
    void reel.offsetWidth;
    const winW = reel.parentElement.offsetWidth;
    const jitter = (Math.random() * 0.5 - 0.25) * TILE;
    const target = -(landAt * TILE) + (winW / 2 - TILE / 2) + jitter;
    if (window.SFX) SFX.spinStart();
    reel.style.transition = 'transform 4.8s cubic-bezier(.12,.66,.16,1)';
    reel.style.transform = 'translateX(' + target + 'px)';
    return new Promise((res) => {
      let done = false;
      const fin = () => { if (done) return; done = true; reel.removeEventListener('transitionend', fin); if (window.SFX) { SFX.spinStop(); SFX.reelStop(); } res(); };
      reel.addEventListener('transitionend', fin);
      setTimeout(fin, 5400);
    });
  }

  async function spin() {
    if (busy) return;
    const t = total();
    if (t < ROULETTE.min) { msg.textContent = 'Minimum stake is ' + ROULETTE.min + '.'; msg.className = 'c-msg c-lose'; return; }
    if (t > ROULETTE.max) { msg.textContent = 'Max stake is ' + Casino.fmt(ROULETTE.max) + '.'; msg.className = 'c-msg c-lose'; return; }
    busy = true; spinBtn.disabled = true; clearBtn.disabled = true;
    msg.textContent = ''; msg.className = 'c-msg';
    board.querySelectorAll('.win').forEach((c) => c.classList.remove('win'));

    const r = await Casino.post('/casino/roulette.php', { action: 'spin', bets: JSON.stringify(bets) });
    if (!r || !r.ok) { msg.textContent = (r && r.error) || 'Error'; msg.className = 'c-msg c-lose'; busy = false; spinBtn.disabled = false; clearBtn.disabled = false; return; }

    await animateTo(r.winning);
    Casino.setBalance(r.balance);

    const numCell = board.querySelector('[data-bet="s:' + r.winning + '"]'); if (numCell) numCell.classList.add('win');
    Object.keys(r.results).forEach((k) => { if (r.results[k].win) { const cell = board.querySelector('[data-bet="' + k + '"]'); if (cell) cell.classList.add('win'); } });

    const net = r.net;
    msg.innerHTML = '<b class="rou-num ' + r.color + '">' + r.winning + '</b> ' + r.color +
      ' — staked ' + Casino.fmt(r.totalBet) + ', won ' + Casino.fmt(r.totalPayout) +
      ' <b>(net ' + (net >= 0 ? '+' : '') + Casino.fmt(net) + ')</b>';
    msg.className = 'c-msg ' + (net > 0 ? 'c-win' : net === 0 ? 'c-push' : 'c-lose');
    if (window.SFX) { net > 0 ? SFX.win(net >= r.totalBet) : SFX.lose(); }

    busy = false; spinBtn.disabled = false; clearBtn.disabled = false;   // bets kept for a quick rebet
  }

  spinBtn.addEventListener('click', spin);
  refresh();
})();
