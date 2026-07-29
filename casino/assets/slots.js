// Slots client: pick a bet, spin (server decides the reels), animate + show result.
(function () {
  const reelsEl = document.getElementById('slot-reels');
  const reels = [...reelsEl.querySelectorAll('.slot-reel')];
  const result = document.getElementById('slot-result');
  const spinBtn = document.getElementById('slot-spin');
  const chips = document.getElementById('c-bet-chips');
  let bet = 50, spinning = false;

  chips.addEventListener('click', (e) => {
    const c = e.target.closest('.c-chip'); if (!c) return;
    bet = parseInt(c.dataset.bet, 10);
    chips.querySelectorAll('.c-chip').forEach((x) => x.classList.toggle('on', x === c));
  });

  async function spin() {
    if (spinning) return;
    spinning = true; spinBtn.disabled = true;
    result.textContent = ''; result.className = 'slot-result';
    reels.forEach((r) => r.classList.add('spinning'));
    if (window.SFX) SFX.spinStart();
    const shuffle = setInterval(() => {
      reels.forEach((r) => { r.textContent = SLOT.symbols[(Math.random() * SLOT.symbols.length) | 0]; });
    }, 70);

    const r = await Casino.post('/casino/slots.php', { action: 'spin', bet });

    setTimeout(() => {
      clearInterval(shuffle);
      reels.forEach((el) => el.classList.remove('spinning'));
      if (!r.ok) { if (window.SFX) SFX.spinStop(); result.textContent = r.error || 'Error'; spinning = false; spinBtn.disabled = false; return; }
      // settle reels one at a time
      r.reels.forEach((sym, i) => setTimeout(() => {
        reels[i].textContent = sym;
        if (window.SFX) SFX.reelStop();
        if (i === 2) { if (window.SFX) SFX.spinStop(); settle(r); }
      }, i * 220));
    }, 500);
  }

  function settle(r) {
    Casino.setBalance(r.balance);
    if (r.payout > 0) {
      result.className = 'slot-result c-win';
      result.textContent = (r.line || 'Winner') + '  +' + Casino.fmt(r.payout) + ' LS';
      if (window.SFX) SFX.win(r.payout >= bet * 10);
    } else {
      result.className = 'slot-result c-lose';
      result.textContent = 'No win — try again';
      if (window.SFX) SFX.lose();
    }
    spinning = false; spinBtn.disabled = false;
  }

  spinBtn.addEventListener('click', spin);
})();
