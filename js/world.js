// Homepage "MMO" overlay: every visitor online becomes a live emoji avatar that
// follows their cursor, with a name tag, emotes, and chat bubbles — all synced
// through world.php by polling (same approach as cursors.php). Purely additive:
// the avatar layer is pointer-events:none so it never blocks the real page.
(function () {
  const ENDPOINT = 'world.php';
  const POLL_MS = 700;
  const AVATARS = ['🦊','🐸','🐙','🦄','🐢','🐧','🦖','🐳','👾','🤖','🐼','🦉','🐝','🦩','🐈','🍄','👻','🛸','⭐','🔥'];
  const EMOTES = ['👋','❤️','😂','🔥','😮','👍','🎉','💀'];

  // ---- identity (name shared with the Canvas page) ----
  const id = (() => { let s = sessionStorage.getItem('world_id'); if (!s) { s = Math.random().toString(36).slice(2, 12); sessionStorage.setItem('world_id', s); } return s; })();
  let myAv = localStorage.getItem('world_av') || AVATARS[(Math.random() * AVATARS.length) | 0];
  localStorage.setItem('world_av', myAv);
  let myName = (localStorage.getItem('place_name') || '').trim() || ('guest' + (1000 + ((Math.random() * 9000) | 0)));

  // ---- styles ----
  const style = document.createElement('style');
  style.textContent = `
    #world-layer{position:fixed;inset:0;pointer-events:none;z-index:50;overflow:hidden}
    .world-av{position:absolute;left:0;top:0;will-change:transform;transition:transform .5s linear}
    .world-av.me{transition:none;z-index:2}
    .world-face{font-size:30px;line-height:1;transform:translate(-50%,-50%);filter:drop-shadow(0 2px 3px rgba(0,0,0,.55))}
    .world-name{position:absolute;top:14px;left:50%;transform:translateX(-50%);white-space:nowrap;
      font:600 11px/1 'Segoe UI',sans-serif;color:#e5e5e5;background:rgba(12,12,16,.72);padding:2px 6px;border-radius:6px}
    .world-av.me .world-name{color:#7aa2ff}
    .world-bubble{position:absolute;bottom:24px;left:50%;transform:translateX(-50%);white-space:nowrap;max-width:240px;
      overflow:hidden;text-overflow:ellipsis;font:13px 'Segoe UI',sans-serif;color:#0b0b0f;background:#fff;
      padding:5px 10px;border-radius:12px;box-shadow:0 3px 10px rgba(0,0,0,.45);animation:world-pop .18s ease}
    .world-bubble.emote{background:none;box-shadow:none;font-size:28px;padding:0;filter:drop-shadow(0 2px 4px rgba(0,0,0,.5))}
    @keyframes world-pop{from{transform:translateX(-50%) scale(.5);opacity:0}to{transform:translateX(-50%) scale(1);opacity:1}}
    @keyframes world-float{to{transform:translateX(-50%) translateY(-26px) scale(1.25);opacity:0}}
    .world-bubble.emote{animation:world-pop .18s ease, world-float 1.4s ease .3s forwards}
    #world-bar{position:fixed;left:50%;bottom:14px;transform:translateX(-50%);z-index:60;display:flex;align-items:center;gap:7px;
      pointer-events:auto;background:rgba(17,17,24,.9);border:1px solid rgba(122,162,255,.22);border-radius:12px;
      padding:6px 9px;backdrop-filter:blur(10px);font-family:'Segoe UI',sans-serif}
    #world-bar input{background:#0b0b0f;border:1px solid rgba(122,162,255,.22);color:#e5e5e5;border-radius:7px;font:inherit;font-size:.82rem;padding:5px 8px}
    #wb-name{width:84px}#wb-chat{width:150px}
    #world-bar .wb-face{font-size:20px;line-height:1;cursor:pointer}
    .wb-emote{cursor:pointer;font-size:18px;line-height:1;padding:2px 3px;border-radius:6px}
    .wb-emote:hover{background:rgba(122,162,255,.16)}
    .wb-count{color:#8a96b8;font-size:.76rem;white-space:nowrap}
    .wb-x{cursor:pointer;color:#8a96b8;font-size:1rem;padding:0 3px}.wb-x:hover{color:#e5e5e5}
    #wb-toggle{position:fixed;left:50%;bottom:14px;transform:translateX(-50%);z-index:60;pointer-events:auto;cursor:pointer;display:none;
      background:rgba(17,17,24,.9);border:1px solid rgba(122,162,255,.22);border-radius:10px;padding:6px 12px;color:#e5e5e5;font:600 12px 'Segoe UI'}
  `;
  document.head.appendChild(style);

  // ---- layer + local avatar ----
  const layer = document.createElement('div');
  layer.id = 'world-layer';
  document.body.appendChild(layer);

  function makeAvatar(av, name, isMe) {
    const el = document.createElement('div');
    el.className = 'world-av' + (isMe ? ' me' : '');
    const face = document.createElement('div'); face.className = 'world-face'; face.textContent = av;
    const nm = document.createElement('div'); nm.className = 'world-name'; nm.textContent = name;
    el.appendChild(face); el.appendChild(nm);
    layer.appendChild(el);
    return { el, face, nm, lastEt: 0, lastMt: 0 };
  }

  const me = makeAvatar(myAv, myName, true);
  let tX = 0.5, tY = 0.5;         // target (normalized cursor)
  let pX = 0.5, pY = 0.5;         // smoothed avatar position
  let pending = {};               // {e,et} / {m,mt} to send next poll

  function setPos(o, fx, fy) { o.el.style.transform = `translate(${fx * innerWidth}px, ${fy * innerHeight}px)`; }

  addEventListener('pointermove', (e) => { tX = e.clientX / innerWidth; tY = e.clientY / innerHeight; }, { passive: true });
  addEventListener('touchmove', (e) => { const t = e.touches[0]; if (t) { tX = t.clientX / innerWidth; tY = t.clientY / innerHeight; } }, { passive: true });

  function tick() {
    pX += (tX - pX) * 0.22; pY += (tY - pY) * 0.22;
    setPos(me, pX, pY);
    requestAnimationFrame(tick);
  }
  requestAnimationFrame(tick);

  function showBubble(o, text, emote) {
    const b = document.createElement('div');
    b.className = 'world-bubble' + (emote ? ' emote' : '');
    b.textContent = text;
    o.el.appendChild(b);
    setTimeout(() => b.remove(), emote ? 1700 : 5000);
  }

  // ---- toolbar ----
  const bar = document.createElement('div'); bar.id = 'world-bar';
  const faceBtn = document.createElement('span'); faceBtn.className = 'wb-face'; faceBtn.textContent = myAv; faceBtn.title = 'Change avatar';
  const nameIn = document.createElement('input'); nameIn.id = 'wb-name'; nameIn.value = myName; nameIn.maxLength = 16; nameIn.placeholder = 'name';
  const emoteWrap = document.createElement('span');
  EMOTES.forEach((em) => { const s = document.createElement('span'); s.className = 'wb-emote'; s.textContent = em; s.addEventListener('click', () => doEmote(em)); emoteWrap.appendChild(s); });
  const chatIn = document.createElement('input'); chatIn.id = 'wb-chat'; chatIn.placeholder = 'say something…'; chatIn.maxLength = 120;
  const count = document.createElement('span'); count.className = 'wb-count'; count.textContent = '1 online';
  const xBtn = document.createElement('span'); xBtn.className = 'wb-x'; xBtn.textContent = '×'; xBtn.title = 'Hide';
  bar.append(faceBtn, nameIn, emoteWrap, chatIn, count, xBtn);
  document.body.appendChild(bar);

  const toggle = document.createElement('div'); toggle.id = 'wb-toggle'; toggle.textContent = '🙂 show players';
  document.body.appendChild(toggle);
  xBtn.addEventListener('click', () => { bar.style.display = 'none'; toggle.style.display = 'block'; });
  toggle.addEventListener('click', () => { bar.style.display = 'flex'; toggle.style.display = 'none'; });

  faceBtn.addEventListener('click', () => {
    myAv = AVATARS[(Math.random() * AVATARS.length) | 0];
    localStorage.setItem('world_av', myAv); faceBtn.textContent = myAv; me.face.textContent = myAv;
  });
  nameIn.addEventListener('input', () => {
    myName = nameIn.value.trim() || 'guest';
    localStorage.setItem('place_name', myName); me.nm.textContent = myName;
  });
  chatIn.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    const msg = chatIn.value.trim(); if (!msg) return;
    pending.m = msg; pending.mt = Date.now();
    showBubble(me, msg, false); chatIn.value = '';
  });
  function doEmote(em) { pending.e = em; pending.et = Date.now(); showBubble(me, em, true); }

  // ---- networking ----
  const remotes = new Map(); // id -> avatar object
  async function poll() {
    const body = { id, x: +tX.toFixed(4), y: +tY.toFixed(4), n: myName, a: myAv, ...pending };
    pending = {};
    let data;
    try {
      data = await fetch(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then((r) => r.json());
    } catch { return; }
    if (!data) return;
    const users = data.users || {};
    count.textContent = (data.count || 1) + ' online';

    const seen = new Set();
    for (const uid in users) {
      const u = users[uid]; seen.add(uid);
      let o = remotes.get(uid);
      if (!o) { o = makeAvatar(u.a || '🙂', u.n || 'guest', false); remotes.set(uid, o); }
      if (o.face.textContent !== (u.a || '🙂')) o.face.textContent = u.a || '🙂';
      if (o.nm.textContent !== (u.n || 'guest')) o.nm.textContent = u.n || 'guest';
      setPos(o, Math.max(0, Math.min(1, u.x)), Math.max(0, Math.min(1, u.y)));
      if (u.et && u.et > o.lastEt) { o.lastEt = u.et; showBubble(o, u.e || '👋', true); }
      if (u.mt && u.mt > o.lastMt) { o.lastMt = u.mt; showBubble(o, u.m || '', false); }
    }
    // remove those who left
    for (const [uid, o] of remotes) if (!seen.has(uid)) { o.el.remove(); remotes.delete(uid); }
  }

  poll();
  setInterval(poll, POLL_MS);
})();
