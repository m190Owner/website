// Live site-activity feed: a compact panel in the bottom-right corner showing the
// latest events (arena wins, new videos, canvas paints, game runs) from
// activity.php. Tucked in the corner so it clears the top release banners, the
// centre chat box, and the bottom-left arena toolbar. pointer-events:none.
(function () {
  const ENDPOINT = 'activity.php';
  const POLL_MS = 6000;
  const MAX_ROWS = 4;

  const style = document.createElement('style');
  style.textContent = `
    #act-feed{position:fixed;right:14px;bottom:44px;z-index:9;width:min(300px,82vw);pointer-events:none;
      display:flex;flex-direction:column;gap:6px;font-family:'Segoe UI',Tahoma,sans-serif}
    #act-feed .act-head{display:flex;align-items:center;gap:5px;color:#ff6b6b;font:700 10px 'Segoe UI';letter-spacing:.6px;padding-left:3px;opacity:.9}
    #act-feed .act-head .d{width:6px;height:6px;border-radius:50%;background:#ff5555;box-shadow:0 0 7px #ff5555;animation:act-pulse 1.4s infinite}
    #act-feed .act-rows{display:flex;flex-direction:column;gap:6px}
    #act-feed .act-row{display:flex;align-items:center;gap:8px;background:rgba(9,9,12,.8);border:1px solid rgba(122,162,255,.16);
      border-radius:9px;padding:6px 10px;color:#c9d2ea;font-size:12px;backdrop-filter:blur(6px);box-shadow:0 3px 10px rgba(0,0,0,.35);
      animation:act-in .3s ease}
    #act-feed .act-row .ic{font-size:14px;flex:0 0 auto}
    #act-feed .act-row .tx{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    #act-feed .act-row .tm{flex:0 0 auto;color:#5a6480;font-size:10.5px}
    @keyframes act-in{from{opacity:0;transform:translateX(22px)}to{opacity:1;transform:none}}
    @keyframes act-pulse{0%,100%{opacity:1}50%{opacity:.25}}
    @media(max-width:640px){#act-feed{display:none}}
  `;
  document.head.appendChild(style);

  const feed = document.createElement('div'); feed.id = 'act-feed';
  feed.innerHTML = '<div class="act-head"><span class="d"></span>LIVE ACTIVITY</div><div class="act-rows"></div>';
  document.body.appendChild(feed);
  const rows = feed.querySelector('.act-rows');

  function rel(sec) { sec = Math.max(0, sec); return sec < 60 ? 'now' : sec < 3600 ? Math.floor(sec / 60) + 'm' : Math.floor(sec / 3600) + 'h'; }

  let lastKey = '';
  function render(events, now) {
    events = events.slice(0, MAX_ROWS);
    const key = events.map((e) => e.t + e.x).join('|');
    if (key === lastKey) return; // nothing new
    lastKey = key;
    rows.innerHTML = '';
    if (!events.length) events = [{ i: '🌐', x: "it's quiet right now", t: now }];
    for (const e of events) {
      const row = document.createElement('div'); row.className = 'act-row';
      const ic = document.createElement('span'); ic.className = 'ic'; ic.textContent = e.i || '•';
      const tx = document.createElement('span'); tx.className = 'tx'; tx.textContent = e.x;   // textContent = no XSS
      const tm = document.createElement('span'); tm.className = 'tm'; tm.textContent = rel(now - (e.t || now));
      row.append(ic, tx, tm); rows.appendChild(row);
    }
  }

  async function poll() {
    try {
      const data = await fetch(ENDPOINT).then((r) => r.json());
      if (data && Array.isArray(data.events)) render(data.events, data.now || Math.floor(Date.now() / 1000));
    } catch {}
  }
  poll();
  setInterval(poll, POLL_MS);
})();
