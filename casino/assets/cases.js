// Case opening: CS:GO-style horizontal reel that decelerates onto the item the
// server rolled. The server decides the prize; this is just the reveal.
(function () {
  const ITEMS = window.ITEMS || {}, CASES = window.CASES || {};
  const list = document.getElementById('case-list');
  const revealWrap = document.getElementById('case-reveal');
  const reel = document.getElementById('case-reel');
  const resultEl = document.getElementById('case-result');
  const TILE = 132; // px per tile incl. margin
  let busy = false;

  function tile(key) {
    const d = ITEMS[key] || { name: key, type: '', color: '#888', rarity: '' };
    const el = document.createElement('div');
    el.className = 'case-tile';
    el.style.borderBottomColor = d.color;
    el.innerHTML = '<div class="ct-type"></div><div class="ct-name"></div>';
    el.querySelector('.ct-type').textContent = d.type || '';
    el.querySelector('.ct-name').textContent = d.name || '';
    el.querySelector('.ct-name').style.color = d.color;
    return el;
  }

  async function open(caseId) {
    if (busy) return; busy = true;
    list.querySelectorAll('button').forEach((b) => b.disabled = true);
    resultEl.innerHTML = ''; revealWrap.style.display = 'block';
    if (window.SFX) SFX.chip();

    const r = await Casino.post('/casino/cases.php', { action: 'open', case: caseId });
    if (!r || !r.ok) {
      resultEl.textContent = (r && r.error) || 'Error';
      list.querySelectorAll('button').forEach((b) => b.disabled = false); busy = false; return;
    }
    Casino.setBalance(r.balance);

    // build reel: random filler with the won item at a fixed landing index
    const pool = (CASES[caseId] && CASES[caseId].pool) || Object.keys(ITEMS);
    const LAND = 48, N = 56;
    reel.style.transition = 'none'; reel.style.transform = 'translateX(0)'; reel.innerHTML = '';
    for (let i = 0; i < N; i++) reel.appendChild(tile(i === LAND ? r.item.key : pool[(Math.random() * pool.length) | 0]));
    // force reflow, then animate to land the won tile under the centre marker
    void reel.offsetWidth;
    const winW = reel.parentElement.offsetWidth;
    const jitter = (Math.random() * 0.6 - 0.3) * TILE;
    const target = -(LAND * TILE) + (winW / 2 - TILE / 2) + jitter;
    if (window.SFX) SFX.spinStart();
    reel.style.transition = 'transform 5.5s cubic-bezier(.12,.66,.16,1)';
    reel.style.transform = 'translateX(' + target + 'px)';

    const done = () => {
      reel.removeEventListener('transitionend', done);
      if (window.SFX) { SFX.spinStop(); r.item.rarity === 'exceedingly' || r.item.rarity === 'covert' ? SFX.win(true) : SFX.reelStop(); }
      showResult(r.item);
      list.querySelectorAll('button').forEach((b) => b.disabled = false); busy = false;
    };
    reel.addEventListener('transitionend', done);
    setTimeout(() => { if (busy) done(); }, 6200); // safety
  }

  function showResult(item) {
    resultEl.innerHTML =
      '<div class="case-won" style="border-color:' + item.color + '">' +
        '<div class="cw-rarity" style="color:' + item.color + '"></div>' +
        '<div class="cw-name"></div>' +
        '<div class="cw-value">worth 🪙 ' + Casino.fmt(item.value) + ' LS</div>' +
        '<div class="cw-actions">' +
          '<button class="c-btn c-btn-gold" id="cw-sell">Quick-sell 🪙 ' + Casino.fmt(item.value) + '</button>' +
          '<a class="c-btn" href="/casino/inventory.php">Keep · Inventory</a>' +
          '<a class="c-btn" href="/casino/market.php">Marketplace</a>' +
        '</div></div>';
    resultEl.querySelector('.cw-rarity').textContent = item.rarityLabel;
    resultEl.querySelector('.cw-name').textContent = item.name;
    resultEl.querySelector('.cw-name').style.color = item.color;
    const sell = document.getElementById('cw-sell');
    sell.addEventListener('click', async () => {
      sell.disabled = true;
      const r = await Casino.post('/casino/cases.php', { action: 'quicksell', item_id: item.invId || 0 });
      if (r && r.ok) { Casino.setBalance(r.balance); sell.textContent = 'Sold for 🪙 ' + Casino.fmt(r.amount); if (window.SFX) SFX.chip(); }
      else { sell.disabled = false; sell.textContent = (r && r.error) || 'Error'; }
    }, { once: true });
  }

  list.addEventListener('click', (e) => { const b = e.target.closest('button[data-case]'); if (b) open(b.dataset.case); });
})();
