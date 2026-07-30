// Marketplace: browse listings and buy items with LS coins.
(function () {
  const grid = document.getElementById('mkt-grid');
  const empty = document.getElementById('mkt-empty');
  let mkt = window.MKT || [];
  const me = window.MEID || 0;

  function card(it) {
    const el = document.createElement('div');
    el.className = 'item-card';
    el.style.borderTopColor = it.color;
    el.innerHTML =
      '<div class="ic-rarity"></div><div class="ic-name"></div><div class="ic-type"></div>' +
      '<div class="ic-val">🪙 ' + Casino.fmt(it.price) + '</div>' +
      '<div class="ic-seller"></div><div class="ic-actions"></div>';
    el.querySelector('.ic-rarity').textContent = it.rarityLabel;
    el.querySelector('.ic-rarity').style.color = it.color;
    el.querySelector('.ic-name').textContent = it.name;
    el.querySelector('.ic-name').style.color = it.color;
    el.querySelector('.ic-type').textContent = it.type + ' · worth ' + Casino.fmt(it.value);
    el.querySelector('.ic-seller').textContent = 'seller: ' + it.seller;
    const act = el.querySelector('.ic-actions');
    if (it.sellerId === me) {
      act.innerHTML = '<span class="c-dim">Your listing</span>';
    } else {
      const b = document.createElement('button'); b.className = 'c-btn c-btn-gold'; b.textContent = 'Buy 🪙 ' + Casino.fmt(it.price);
      b.onclick = () => buy(it.id, b); act.appendChild(b);
    }
    return el;
  }

  function render() {
    grid.innerHTML = '';
    empty.style.display = mkt.length ? 'none' : 'block';
    mkt.forEach((it) => grid.appendChild(card(it)));
  }

  async function buy(id, btn) {
    btn.disabled = true;
    const r = await Casino.post('/casino/market.php', { action: 'buy', item_id: id });
    if (r && r.ok) { Casino.setBalance(r.balance); if (window.SFX) SFX.chip(); }
    else if (r) alert(r.error || 'Error');
    if (r && r.market) { mkt = r.market; render(); }
  }

  render();
})();
