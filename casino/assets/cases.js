// Case opening. Single open = the CS:GO-style horizontal reel that decelerates
// onto the item the server rolled. Multi open (up to CASE_MAX_OPEN) = a compact
// grid that reveals each pull in a staggered cascade. The server decides every
// prize; this is only the reveal.
(function () {
  const ITEMS = window.ITEMS || {}, CASES = window.CASES || {};
  const list = document.getElementById('case-list');
  const revealWrap = document.getElementById('case-reveal');
  const reelWindow = document.getElementById('case-reel-window');
  const reel = document.getElementById('case-reel');
  const multi = document.getElementById('case-multi');
  const resultEl = document.getElementById('case-result');
  const countChips = document.getElementById('c-count-chips');
  const TILE = 132; // px per tile incl. margin
  let busy = false, count = 1;

  const buttons = () => list.querySelectorAll('button[data-case]');
  function relabel() {
    buttons().forEach((b) => {
      const price = parseInt(b.dataset.price, 10) || 0;
      b.textContent = count === 1
        ? 'Open · 🪙 ' + Casino.fmt(price)
        : 'Open ×' + count + ' · 🪙 ' + Casino.fmt(price * count);
    });
  }
  countChips.addEventListener('click', (e) => {
    const c = e.target.closest('.c-chip'); if (!c) return;
    count = parseInt(c.dataset.count, 10);
    countChips.querySelectorAll('.c-chip').forEach((x) => x.classList.toggle('on', x === c));
    relabel();
  });

  const randKey = (pool) => pool[(Math.random() * pool.length) | 0];

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

  const lockBtns = (dis) => buttons().forEach((b) => b.disabled = dis);
  const finish = () => { lockBtns(false); busy = false; };

  async function openCases(caseId) {
    if (busy) return; busy = true; lockBtns(true);
    resultEl.innerHTML = ''; multi.innerHTML = ''; revealWrap.style.display = 'block';
    if (window.SFX) SFX.chip();
    const r = await Casino.post('/casino/cases.php', { action: 'open', case: caseId, count });
    if (!r || !r.ok) { resultEl.textContent = (r && r.error) || 'Error'; finish(); return; }
    Casino.setBalance(r.balance);
    if (r.items.length === 1) singleReel(caseId, r.items[0]);
    else multiReveal(caseId, r);
  }

  // ---- single open: the classic horizontal reel ----
  function singleReel(caseId, item) {
    multi.style.display = 'none';
    reelWindow.style.display = 'block';
    const pool = (CASES[caseId] && CASES[caseId].pool) || Object.keys(ITEMS);
    const LAND = 48, N = 56;
    reel.style.transition = 'none'; reel.style.transform = 'translateX(0)'; reel.innerHTML = '';
    for (let i = 0; i < N; i++) reel.appendChild(tile(i === LAND ? item.key : randKey(pool)));
    void reel.offsetWidth; // force reflow before animating
    const winW = reel.parentElement.offsetWidth;
    const jitter = (Math.random() * 0.6 - 0.3) * TILE;
    const target = -(LAND * TILE) + (winW / 2 - TILE / 2) + jitter;
    if (window.SFX) SFX.spinStart();
    reel.style.transition = 'transform 5.5s cubic-bezier(.12,.66,.16,1)';
    reel.style.transform = 'translateX(' + target + 'px)';

    let done = false;
    const settle = () => {
      if (done) return; done = true;
      reel.removeEventListener('transitionend', settle);
      if (window.SFX) { SFX.spinStop(); (item.rarity === 'exceedingly' || item.rarity === 'covert') ? SFX.win(true) : SFX.reelStop(); }
      showSingleResult(item);
      finish();
    };
    reel.addEventListener('transitionend', settle);
    setTimeout(settle, 6200); // safety if transitionend never fires
  }

  function showSingleResult(item) {
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

  // ---- multi open: compact staggered grid reveal ----
  function multiReveal(caseId, r) {
    reelWindow.style.display = 'none';
    multi.style.display = 'grid';
    multi.innerHTML = '';
    const pool = (CASES[caseId] && CASES[caseId].pool) || Object.keys(ITEMS);
    const cards = r.items.map(() => {
      const el = document.createElement('div');
      el.className = 'case-mcard rolling';
      el.innerHTML = '<div class="mc-type"></div><div class="mc-name">?</div><div class="mc-val"></div><button class="c-btn mc-sell" style="display:none"></button>';
      multi.appendChild(el);
      return el;
    });
    if (window.SFX) SFX.spinStart();

    let locked = 0, ended = false;
    const lockOne = (idx) => {
      const el = cards[idx], it = r.items[idx];
      if (el.classList.contains('locked')) return;
      el.classList.remove('rolling'); el.classList.add('locked');
      el.style.borderBottomColor = it.color;
      el.querySelector('.mc-type').textContent = it.type;
      const n = el.querySelector('.mc-name'); n.textContent = it.name; n.style.color = it.color;
      el.querySelector('.mc-val').textContent = '🪙 ' + Casino.fmt(it.value);
      if (window.SFX) (it.rarity === 'exceedingly' || it.rarity === 'covert') ? SFX.win(true) : SFX.reelStop();
      const sell = el.querySelector('.mc-sell');
      sell.textContent = 'Sell 🪙 ' + Casino.fmt(it.value);
      sell.style.display = '';
      sell.addEventListener('click', async () => {
        sell.disabled = true;
        const rr = await Casino.post('/casino/cases.php', { action: 'quicksell', item_id: it.invId || 0 });
        if (rr && rr.ok) { Casino.setBalance(rr.balance); el.classList.add('sold'); sell.textContent = 'Sold 🪙 ' + Casino.fmt(rr.amount); if (window.SFX) SFX.chip(); }
        else { sell.disabled = false; sell.textContent = (rr && rr.error) || 'Error'; }
      }, { once: true });
    };
    const allDone = () => { if (ended) return; ended = true; if (window.SFX) SFX.spinStop(); showMultiSummary(r); finish(); };

    cards.forEach((el, idx) => {
      const nameEl = el.querySelector('.mc-name');
      let ticks = 7 + idx * 3; // later cards spin longer, so they lock in a cascade
      const iv = setInterval(() => {
        if (el.classList.contains('locked')) { clearInterval(iv); return; }
        if (ticks-- <= 0) { clearInterval(iv); lockOne(idx); if (++locked === cards.length) allDone(); return; }
        nameEl.textContent = (ITEMS[randKey(pool)] || {}).name || '?';
      }, 70);
    });
    // overall safety: force-lock everything if a timer stalls
    setTimeout(() => { if (ended) return; cards.forEach((_, idx) => lockOne(idx)); allDone(); }, (7 + cards.length * 3) * 70 + 2500);
  }

  function showMultiSummary(r) {
    const total = r.items.reduce((a, b) => a + b.value, 0);
    const best = r.items.reduce((a, b) => (b.value > a.value ? b : a), r.items[0]);
    resultEl.innerHTML =
      '<div class="case-msum">' +
        '<div>Opened <b>' + r.count + '</b> · spent 🪙 ' + Casino.fmt(r.totalCost) + ' · pulls worth <b>🪙 ' + Casino.fmt(total) + '</b></div>' +
        '<div class="c-dim">Best pull: <span class="cmsum-best"></span> · sell what you don\'t want above.</div>' +
        '<div class="cw-actions"><a class="c-btn" href="/casino/inventory.php">Inventory</a><a class="c-btn" href="/casino/market.php">Marketplace</a></div>' +
      '</div>';
    const b = resultEl.querySelector('.cmsum-best');
    b.textContent = best.name; b.style.color = best.color;
  }

  list.addEventListener('click', (e) => { const b = e.target.closest('button[data-case]'); if (b) openCases(b.dataset.case); });
  relabel();
})();
