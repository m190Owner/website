// Item Upgrader: stake owned items for a shot at a bigger one. The server owns
// the odds and the roll and consumes the stake atomically; this collects the
// selection, shows the live chance, and animates the win/lose reveal.
(function () {
  const ITEMS = window.ITEMS || {}, U = window.UPGRADE || { factor: 0.9, max: 0.95 };
  const invEl = document.getElementById('upg-inv');
  const invEmpty = document.getElementById('upg-inv-empty');
  const targetsEl = document.getElementById('upg-targets');
  const stakeValEl = document.getElementById('upg-stakeval');
  const chanceEl = document.getElementById('upg-chance');
  const greenEl = document.getElementById('upg-green');
  const pointer = document.getElementById('upg-pointer');
  const goBtn = document.getElementById('upg-go');
  const msg = document.getElementById('upg-msg');

  let inv = window.INV || [];
  let stake = new Set();   // selected inventory ids
  let target = null;       // target item key
  let busy = false;

  const unlisted = () => inv.filter((it) => !it.listed);
  const stakeValue = () => unlisted().filter((it) => stake.has(it.id)).reduce((a, b) => a + b.value, 0);
  const chanceFor = (tv, sv) => (sv > 0 && tv > sv) ? Math.min(U.max, sv / tv * U.factor) : 0;

  function itemCard(it, opts) {
    const el = document.createElement('div');
    el.className = 'item-card upg-card' + (opts.selected ? ' sel' : '') + (opts.disabled ? ' dis' : '');
    el.style.borderTopColor = it.color;
    el.innerHTML = '<div class="ic-rarity"></div><div class="ic-name"></div><div class="ic-type"></div><div class="ic-val">🪙 ' + Casino.fmt(it.value) + '</div>' + (opts.badge ? '<div class="upg-odds">' + opts.badge + '</div>' : '');
    el.querySelector('.ic-rarity').textContent = it.rarityLabel; el.querySelector('.ic-rarity').style.color = it.color;
    el.querySelector('.ic-name').textContent = it.name; el.querySelector('.ic-name').style.color = it.color;
    el.querySelector('.ic-type').textContent = it.type;
    return el;
  }

  function renderInv() {
    const list = unlisted();
    invEmpty.style.display = list.length ? 'none' : 'block';
    invEl.innerHTML = '';
    list.forEach((it) => {
      const card = itemCard(it, { selected: stake.has(it.id) });
      card.onclick = () => { if (busy) return; stake.has(it.id) ? stake.delete(it.id) : stake.add(it.id); afterStakeChange(); };
      invEl.appendChild(card);
    });
  }

  function renderTargets() {
    const sv = stakeValue();
    targetsEl.innerHTML = '';
    Object.keys(ITEMS).map((k) => ITEMS[k]).sort((a, b) => a.value - b.value).forEach((def) => {
      const selectable = sv > 0 && def.value > sv;
      const ch = chanceFor(def.value, sv);
      const card = itemCard(def, { selected: target === def.key, disabled: !selectable, badge: selectable ? (ch * 100).toFixed(1) + '%' : '' });
      if (selectable) card.onclick = () => { if (busy) return; target = def.key; renderTargets(); updateChance(); };
      targetsEl.appendChild(card);
    });
  }

  function updateChance() {
    const sv = stakeValue();
    const tdef = target ? ITEMS[target] : null;
    const ch = tdef ? chanceFor(tdef.value, sv) : 0;
    greenEl.style.width = (ch * 100) + '%';
    chanceEl.textContent = tdef ? (ch * 100).toFixed(1) + '%' : '—';
    goBtn.disabled = busy || !(sv > 0 && tdef && tdef.value > sv);
  }

  function afterStakeChange() {
    const sv = stakeValue();
    stakeValEl.textContent = Casino.fmt(sv);
    if (target && ITEMS[target].value <= sv) target = null;   // stake grew past the target
    renderInv(); renderTargets(); updateChance();
  }

  async function go() {
    if (busy) return;
    const ids = [...stake], tdef = target ? ITEMS[target] : null;
    if (!ids.length || !tdef) return;
    busy = true; goBtn.disabled = true; msg.textContent = ''; msg.className = 'c-msg';
    if (window.SFX) SFX.spinStart();

    const r = await Casino.post('/casino/upgrader.php', { action: 'upgrade', stake: JSON.stringify(ids), target });
    if (!r || !r.ok) { if (window.SFX) SFX.spinStop(); msg.textContent = (r && r.error) || 'Error'; msg.className = 'c-msg c-lose'; busy = false; updateChance(); return; }

    const res = r.result, chPct = res.chance * 100;
    // land the pointer inside the winning (green) or losing (red) zone, sized to the real odds
    let land = res.won ? Math.min(chPct - 0.5, Math.max(0.5, Math.random() * chPct))
                       : Math.min(99.5, chPct + 0.5 + Math.random() * Math.max(1, 99 - chPct));
    greenEl.style.width = chPct + '%';
    pointer.style.transition = 'none'; pointer.style.left = '0%'; void pointer.offsetWidth;   // reset, force reflow
    pointer.style.transition = 'left 3.2s cubic-bezier(.1,.7,.12,1)';
    pointer.style.left = land + '%';
    await new Promise((resolve) => { let done = false; const fin = () => { if (done) return; done = true; pointer.removeEventListener('transitionend', fin); resolve(); }; pointer.addEventListener('transitionend', fin); setTimeout(fin, 3600); });

    if (window.SFX) SFX.spinStop();
    inv = r.inventory; stake.clear(); target = null; Casino.setBalance(r.balance);
    if (res.won) { if (window.SFX) SFX.win(true); msg.innerHTML = '✅ Upgraded to <b style="color:' + res.target.color + '">' + res.target.name + '</b> — worth 🪙 ' + Casino.fmt(res.targetValue) + '!'; msg.className = 'c-msg c-win'; }
    else { if (window.SFX) SFX.lose(); msg.textContent = '❌ Lost — your staked items are gone.'; msg.className = 'c-msg c-lose'; }
    stakeValEl.textContent = '0';
    busy = false;
    renderInv(); renderTargets(); updateChance();
  }

  goBtn.addEventListener('click', go);
  renderInv(); renderTargets(); updateChance();
})();
