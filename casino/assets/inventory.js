// Inventory: render owned items, quick-sell to the house, or list/delist on market.
(function () {
  const grid = document.getElementById('inv-grid');
  const empty = document.getElementById('inv-empty');
  const bulk = document.getElementById('inv-bulk');
  const RARITY_ORDER = window.RARITY_ORDER || [];
  let inv = window.INV || [];

  function card(it) {
    const el = document.createElement('div');
    el.className = 'item-card';
    el.style.borderTopColor = it.color;
    el.innerHTML =
      '<div class="ic-rarity"></div><div class="ic-name"></div><div class="ic-type"></div>' +
      '<div class="ic-val">🪙 ' + Casino.fmt(it.value) + '</div><div class="ic-actions"></div>';
    el.querySelector('.ic-rarity').textContent = it.rarityLabel;
    el.querySelector('.ic-rarity').style.color = it.color;
    el.querySelector('.ic-name').textContent = it.name;
    el.querySelector('.ic-name').style.color = it.color;
    el.querySelector('.ic-type').textContent = it.type;
    const act = el.querySelector('.ic-actions');
    if (it.listed) {
      act.innerHTML = '<div class="ic-listed">Listed 🪙 ' + Casino.fmt(it.listPrice) + '</div>';
      const b = document.createElement('button'); b.className = 'c-btn'; b.textContent = 'Delist';
      b.onclick = () => act2('delist', it.id); act.appendChild(b);
    } else {
      const sell = document.createElement('button'); sell.className = 'c-btn'; sell.textContent = 'Quick-sell';
      sell.onclick = () => act2('quicksell', it.id);
      const listBtn = document.createElement('button'); listBtn.className = 'c-btn c-btn-gold'; listBtn.textContent = 'Sell on market';
      listBtn.onclick = () => {
        const raw = prompt('List "' + it.name + '" for how many LS coins?', it.value);
        if (raw == null) return; const price = parseInt(raw, 10); if (!price) return;
        act2('list', it.id, { price });
      };
      act.append(sell, listBtn);
    }
    return el;
  }

  // Per-rarity "sell all" bar, built from the unlisted items you currently hold.
  function renderBulk() {
    const groups = {};
    inv.forEach((it) => {
      if (it.listed) return;
      const g = groups[it.rarity] || (groups[it.rarity] = { label: it.rarityLabel, color: it.color, count: 0, total: 0 });
      g.count++; g.total += it.value;
    });
    const order = RARITY_ORDER.length ? RARITY_ORDER : Object.keys(groups);
    const present = order.filter((r) => groups[r]);
    bulk.innerHTML = '';
    if (!present.length) { bulk.style.display = 'none'; return; }
    bulk.style.display = 'flex';
    const lbl = document.createElement('span'); lbl.className = 'c-dim'; lbl.textContent = 'Sell all by rarity:';
    bulk.appendChild(lbl);
    present.forEach((r) => {
      const g = groups[r];
      const b = document.createElement('button');
      b.className = 'c-btn inv-bulk-btn';
      b.style.borderColor = g.color; b.style.color = g.color;
      b.textContent = g.label + ' · ' + g.count + ' · 🪙 ' + Casino.fmt(g.total);
      b.onclick = () => {
        if (!confirm('Quick-sell all ' + g.count + ' ' + g.label + ' item' + (g.count === 1 ? '' : 's') + ' for 🪙 ' + Casino.fmt(g.total) + '?')) return;
        act2('quicksell_rarity', null, { rarity: r });
      };
      bulk.appendChild(b);
    });
  }

  function render() {
    grid.innerHTML = '';
    empty.style.display = inv.length ? 'none' : 'block';
    inv.forEach((it) => grid.appendChild(card(it)));
    renderBulk();
  }

  async function act2(action, id, extra) {
    const body = Object.assign({ action }, extra || {});
    if (id != null) body.item_id = id;
    const r = await Casino.post('/casino/inventory.php', body);
    if (!r || !r.ok) { alert((r && r.error) || 'Error'); return; }
    if (typeof r.balance === 'number') Casino.setBalance(r.balance);
    if (r.amount && window.SFX) SFX.chip();
    if (r.inventory) { inv = r.inventory; render(); }
  }

  render();
})();
