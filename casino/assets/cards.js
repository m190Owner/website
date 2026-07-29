// Renders a card code ("As","Td","Kh","??") into a .card element. Shared by
// blackjack and poker.
window.renderCard = function (code) {
  const el = document.createElement('div');
  el.className = 'card deal';
  if (!code || code === '??') { el.classList.add('back'); return el; }
  const suits = { s: '♠', h: '♥', d: '♦', c: '♣' };
  const rank = code.slice(0, -1), suitCh = code.slice(-1);
  if (suitCh === 'h' || suitCh === 'd') el.classList.add('red');
  const r = document.createElement('div'); r.className = 'r'; r.textContent = rank === 'T' ? '10' : rank;
  const s = document.createElement('div'); s.className = 's'; s.textContent = suits[suitCh] || '';
  el.append(r, s);
  return el;
};
window.renderHand = function (container, codes) {
  container.innerHTML = '';
  (codes || []).forEach((c) => container.appendChild(renderCard(c)));
};
