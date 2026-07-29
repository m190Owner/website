// Shared casino client helpers: authenticated JSON POSTs, balance display sync,
// and the lobby coin-faucet button.
window.Casino = (function () {
  const csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';

  function fmt(n) { return Number(n).toLocaleString(); }

  async function post(url, body) {
    const data = new URLSearchParams(Object.assign({ csrf }, body || {}));
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: data,
    });
    return res.json().catch(() => ({ ok: false, error: 'bad response' }));
  }

  // Update every balance display on the page.
  function setBalance(n) {
    const b = document.querySelector('#c-balance b'); if (b) b.textContent = fmt(n);
    const big = document.getElementById('c-balance-big'); if (big) big.textContent = fmt(n);
  }

  return { csrf, post, setBalance, fmt };
})();

// Lobby: collect-coins faucet.
(function () {
  const btn = document.getElementById('c-bonus-btn');
  if (!btn) return;
  const msg = document.getElementById('c-bonus-msg');
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    const r = await Casino.post('/casino/index.php', { action: 'bonus' });
    if (r.ok) {
      Casino.setBalance(r.balance);
      msg.textContent = '+' + Casino.fmt(r.amount) + ' LS 🎉';
      msg.style.color = '#e8c15a';
    } else {
      msg.textContent = r.error || 'Try again later.';
      msg.style.color = '#9a9aa6';
      if (typeof r.balance === 'number') Casino.setBalance(r.balance);
    }
    setTimeout(() => { btn.disabled = false; }, 1200);
  });
})();
