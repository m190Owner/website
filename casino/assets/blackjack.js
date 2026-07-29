// Blackjack client — all outcomes come from the server; this just renders state.
(function () {
  const dealer = document.getElementById('bj-dealer'), player = document.getElementById('bj-player');
  const dval = document.getElementById('bj-dval'), pval = document.getElementById('bj-pval');
  const msg = document.getElementById('bj-msg');
  const betbar = document.getElementById('bj-betbar'), actions = document.getElementById('bj-actions');
  const chips = document.getElementById('c-bet-chips');
  const dealBtn = document.getElementById('bj-deal'), hitBtn = document.getElementById('bj-hit'),
        standBtn = document.getElementById('bj-stand'), doubleBtn = document.getElementById('bj-double');
  let bet = 50, busy = false;

  chips.addEventListener('click', (e) => {
    const c = e.target.closest('.c-chip'); if (!c) return;
    bet = parseInt(c.dataset.bet, 10);
    chips.querySelectorAll('.c-chip').forEach((x) => x.classList.toggle('on', x === c));
  });

  function apply(s) {
    if (!s || !s.ok) { msg.textContent = (s && s.error) || 'Error'; msg.className = 'c-msg c-lose'; return; }
    renderHand(dealer, s.dealer); renderHand(player, s.player);
    pval.textContent = s.playerVal ? '· ' + s.playerVal : '';
    dval.textContent = s.dealerVal != null ? '· ' + s.dealerVal : '';
    Casino.setBalance(s.balance);
    if (s.status === 'player') {
      betbar.style.display = 'none'; actions.style.display = 'flex';
      doubleBtn.style.display = s.canDouble ? '' : 'none';
      msg.textContent = 'Hit, stand' + (s.canDouble ? ', or double?' : '?'); msg.className = 'c-msg';
    } else {
      actions.style.display = 'none'; betbar.style.display = 'flex';
      const tag = s.delta > 0 ? '  +' + Casino.fmt(s.delta) + ' LS' : '';
      msg.textContent = s.result + tag;
      msg.className = 'c-msg ' + (s.delta > 0 ? 'c-win' : s.delta < 0 ? 'c-lose' : 'c-push');
    }
  }

  async function act(action, extra) {
    if (busy) return; busy = true;
    [dealBtn, hitBtn, standBtn, doubleBtn].forEach((b) => b.disabled = true);
    const s = await Casino.post('/casino/blackjack.php', Object.assign({ action, bet }, extra || {}));
    apply(s);
    busy = false;
    [dealBtn, hitBtn, standBtn, doubleBtn].forEach((b) => b.disabled = false);
  }

  dealBtn.addEventListener('click', () => act('deal'));
  hitBtn.addEventListener('click', () => act('hit'));
  standBtn.addEventListener('click', () => act('stand'));
  doubleBtn.addEventListener('click', () => act('double'));
})();
