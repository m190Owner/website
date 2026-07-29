// Texas Hold'em client: polls the authoritative table and renders it. Sends only
// intent (sit / leave / fold / check / call / raise). Server decides everything.
(function () {
  const page = document.querySelector('.poker-page');
  if (!page) return;
  const T = page.dataset.table, MINBUY = +page.dataset.minbuy, MAXBUY = +page.dataset.maxbuy, BB = +page.dataset.bb;
  const seatsEl = document.getElementById('pk-seats');
  const boardEl = document.getElementById('pk-board');
  const potEl = document.getElementById('pk-pot');
  const statusEl = document.getElementById('pk-status');
  const actionsEl = document.getElementById('pk-actions');
  const logEl = document.getElementById('pk-log');
  const leaveBtn = document.getElementById('pk-leave');
  const checkCall = document.getElementById('pk-checkcall');
  const raiseRange = document.getElementById('pk-raise-range');
  const raiseAmt = document.getElementById('pk-raise-amt');
  const raiseBtn = document.getElementById('pk-raise-btn');
  const raiseWrap = document.querySelector('.pk-raisewrap');
  // seat ring positions (index 0 = bottom/local player, going clockwise). All
  // use left/top so translate(-50%,-50%) centres each seat on its anchor.
  const POS = [
    { left: '50%', top: '88%' }, { left: '14%', top: '76%' }, { left: '14%', top: '24%' },
    { left: '50%', top: '8%' }, { left: '86%', top: '24%' }, { left: '86%', top: '76%' },
  ];
  let view = null, busy = false;
  let sBoard = 0, sPot = 0, sResult = '';   // last-seen values, for SFX diffing

  function post(action, extra) { return Casino.post('/casino/poker.php', Object.assign({ action, t: T }, extra || {})); }

  async function poll() { if (busy) return; const v = await post('poll'); if (v && v.ok) render(v); }

  function render(v) {
    view = v;
    // sound effects (diffed against the previous poll)
    if (window.SFX) {
      const bl = (v.board || []).length;
      if (bl > sBoard) SFX.deal(bl - sBoard);                 // flop/turn/river
      else if (bl === 0 && sBoard > 0 && v.street) SFX.deal(2); // new hand -> hole cards
      sBoard = bl;
      const pot = v.pot || 0;
      if (pot > sPot) SFX.chip();                             // a bet/call went in
      sPot = pot;
      const rk = v.result ? JSON.stringify(v.result.winners || []) + (v.board || []).join('') : '';
      if (rk && rk !== sResult) {
        const iWon = v.mySeat != null && (v.result.winners || []).some((w) => w.seat === v.mySeat);
        iWon ? SFX.win(true) : SFX.chip();
      }
      sResult = rk;
    }
    // board
    boardEl.innerHTML = '';
    (v.board || []).forEach((c) => boardEl.appendChild(renderCard(c)));
    potEl.textContent = v.pot ? '🪙 Pot ' + Casino.fmt(v.pot) : '';
    leaveBtn.style.display = v.mySeat != null ? '' : 'none';

    // status line
    if (v.result && v.result.winners && v.result.winners.length) {
      const w = v.result.winners.map((x) => x.name + (x.hand ? ' (' + x.hand + ')' : '') + ' +' + Casino.fmt(x.amount)).join(', ');
      statusEl.textContent = '🏆 ' + w + (v.nextHandIn ? '  ·  next hand in ' + v.nextHandIn + 's' : '');
    } else if (v.street) {
      statusEl.textContent = v.toActName ? (v.myTurn ? 'Your turn' : v.toActName + ' to act…') : '…';
    } else if (v.nextHandIn) { statusEl.textContent = 'Next hand in ' + v.nextHandIn + 's';
    } else { statusEl.textContent = (v.seats.filter(Boolean).length < 2) ? 'Waiting for players…' : 'Starting…'; }

    // seats
    seatsEl.innerHTML = '';
    const my = v.mySeat;
    for (let i = 0; i < v.nseats; i++) {
      const disp = (my != null) ? ((i - my + v.nseats) % v.nseats) : i;
      const slot = document.createElement('div');
      slot.className = 'pk-seat';
      Object.assign(slot.style, POS[disp]);
      const p = v.seats[i];
      if (!p) {
        if (my == null) { const b = document.createElement('button'); b.className = 'c-btn pk-sit'; b.textContent = 'Sit'; b.onclick = () => sit(i); slot.appendChild(b); }
        else slot.classList.add('empty');
      } else {
        if (p.folded) slot.classList.add('folded');
        if (v.toAct === i && v.street) slot.classList.add('toact');
        if (i === v.button) slot.classList.add('dealer');
        const cards = document.createElement('div'); cards.className = 'pk-cards';
        (p.hole || []).forEach((c) => { const el = renderCard(c); el.classList.add('mini'); cards.appendChild(el); });
        const info = document.createElement('div'); info.className = 'pk-info';
        info.innerHTML = '<div class="pk-name">' + (i === my ? '★ ' : '') + '</div><div class="pk-stack">🪙 ' + Casino.fmt(p.stack) + '</div>';
        info.querySelector('.pk-name').textContent = (i === my ? '★ ' : '') + p.name;
        if (p.allin) info.querySelector('.pk-name').textContent += ' (all-in)';
        const bet = document.createElement('div'); bet.className = 'pk-bet'; if (p.bet > 0) bet.textContent = Casino.fmt(p.bet);
        slot.append(cards, info, bet);
        if (v.toAct === i && v.deadline && v.street) {
          const left = Math.max(0, v.deadline - v.now);
          const tm = document.createElement('div'); tm.className = 'pk-timer'; tm.textContent = left + 's'; slot.appendChild(tm);
        }
      }
      seatsEl.appendChild(slot);
    }

    // my action bar
    if (v.myTurn) {
      actionsEl.style.display = 'flex';
      checkCall.textContent = v.canCheck ? 'Check' : 'Call ' + Casino.fmt(v.toCall);
      checkCall.dataset.move = v.canCheck ? 'check' : 'call';
      const canRaise = v.maxRaiseTo > v.currentBet && v.myStack > v.toCall;
      raiseWrap.style.display = canRaise ? 'flex' : 'none';
      if (canRaise) {
        const lo = Math.min(v.minRaiseTo, v.maxRaiseTo), hi = v.maxRaiseTo;
        raiseRange.min = lo; raiseRange.max = hi; raiseRange.step = BB;
        if (+raiseAmt.value < lo || +raiseAmt.value > hi) { raiseRange.value = lo; raiseAmt.value = lo; }
        raiseBtn.textContent = (+raiseAmt.value >= hi) ? 'All-in ' + Casino.fmt(hi) : 'Raise to ' + Casino.fmt(+raiseAmt.value);
      }
    } else actionsEl.style.display = 'none';

    // log
    logEl.innerHTML = '';
    (v.log || []).slice().reverse().forEach((l) => { const d = document.createElement('div'); d.textContent = l.m; logEl.appendChild(d); });
  }

  async function sit(seat) {
    const def = Math.min(MAXBUY, Math.max(MINBUY, 20 * BB));
    const raw = prompt('Buy-in (' + MINBUY + '–' + MAXBUY + ' LS):', def);
    if (raw == null) return;
    const buyin = parseInt(raw, 10); if (!buyin) return;
    busy = true; const v = await post('sit', { seat, buyin }); busy = false;
    if (v && v.ok) render(v); else alert((v && v.error) || 'Could not sit.');
  }
  async function act(move, amount) {
    busy = true; actionsEl.style.display = 'none';
    const v = await post('act', { move, amount: amount || 0 }); busy = false;
    if (v && v.ok) render(v); else if (v && v.error) { /* stale turn */ }
  }

  leaveBtn.onclick = async () => { if (!confirm('Leave the table and cash out your chips?')) return; busy = true; const v = await post('leave'); busy = false; if (v && v.ok) render(v); };
  actionsEl.querySelector('[data-move="fold"]').onclick = () => act('fold');
  checkCall.onclick = () => act(checkCall.dataset.move);
  raiseBtn.onclick = () => act('raise', +raiseAmt.value);
  raiseRange.oninput = () => { raiseAmt.value = raiseRange.value; if (view) raiseBtn.textContent = (+raiseAmt.value >= view.maxRaiseTo) ? 'All-in ' + Casino.fmt(+raiseAmt.value) : 'Raise to ' + Casino.fmt(+raiseAmt.value); };
  raiseAmt.oninput = () => { raiseRange.value = raiseAmt.value; };

  poll();
  setInterval(poll, 1200);
})();
