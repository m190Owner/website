// Homepage "MMO" PvP arena. Every visitor is an emoji avatar that follows their
// cursor. Click another player to damage them; power grows the longer you survive
// (resets on death); survive 60s and you nuke everyone to win the round. All
// combat is resolved by world.php — this file just renders state and sends intent.
(function () {
  const ENDPOINT = 'world.php';
  const POLL_MS = 400;
  const ATTACK_CD_MS = 300;
  const WIN_MS = 60000;
  const AVATARS = ['🦊','🐸','🐙','🦄','🐢','🐧','🦖','🐳','👾','🤖','🐼','🦉','🐝','🦩','🐈','🍄','👻','🛸','⭐','🔥'];
  const EMOTES = ['👋','❤️','😂','🔥','😮','👍','🎉','💀'];

  const id = (() => { let s = sessionStorage.getItem('world_id'); if (!s) { s = Math.random().toString(36).slice(2, 12); sessionStorage.setItem('world_id', s); } return s; })();
  let myAv = localStorage.getItem('world_av') || AVATARS[(Math.random() * AVATARS.length) | 0];
  localStorage.setItem('world_av', myAv);
  let myName = (localStorage.getItem('place_name') || '').trim() || ('guest' + (1000 + ((Math.random() * 9000) | 0)));

  const style = document.createElement('style');
  style.textContent = `
    #world-layer{position:fixed;inset:0;pointer-events:none;z-index:50;overflow:hidden}
    .world-av{position:absolute;left:0;top:0;will-change:transform;transition:transform .4s linear}
    .world-av.me{transition:none;z-index:2}
    .world-av.dead{opacity:.5;filter:grayscale(.7)}
    .world-face{font-size:30px;line-height:1;transform:translate(-50%,-50%);filter:drop-shadow(0 2px 3px rgba(0,0,0,.55))}
    .world-av:not(.me):not(.dead) .world-face{pointer-events:auto;cursor:crosshair}
    .world-av:not(.me):not(.dead) .world-face:hover{transform:translate(-50%,-50%) scale(1.18)}
    .world-hp{position:absolute;top:16px;left:50%;transform:translateX(-50%);width:36px;height:4px;border-radius:3px;
      background:rgba(0,0,0,.55);overflow:hidden}
    .world-hp>i{display:block;height:100%;width:100%;background:#3ba55d;transition:width .2s,background .2s}
    .world-name{position:absolute;top:22px;left:50%;transform:translateX(-50%);white-space:nowrap;
      font:600 11px/1 'Segoe UI',sans-serif;color:#e5e5e5;background:rgba(12,12,16,.72);padding:2px 6px;border-radius:6px}
    .world-av.me .world-name{color:#7aa2ff}
    .world-pow{color:#ffd27b;font-weight:700;margin-left:3px}
    .world-status{position:absolute;top:38px;left:50%;transform:translateX(-50%);white-space:nowrap;font:600 10px 'Segoe UI';color:#ff9a9a}
    .world-bubble{position:absolute;bottom:24px;left:50%;transform:translateX(-50%);white-space:nowrap;max-width:240px;
      overflow:hidden;text-overflow:ellipsis;font:13px 'Segoe UI',sans-serif;color:#0b0b0f;background:#fff;
      padding:5px 10px;border-radius:12px;box-shadow:0 3px 10px rgba(0,0,0,.45);animation:world-pop .18s ease}
    .world-bubble.emote{background:none;box-shadow:none;font-size:28px;padding:0;animation:world-pop .18s ease, world-float 1.4s ease .3s forwards}
    .world-hit{position:absolute;left:0;top:0;pointer-events:none;font:800 15px 'Segoe UI';color:#ff5555;
      text-shadow:0 1px 2px #000;animation:world-hit .6s ease forwards}
    @keyframes world-pop{from{transform:translateX(-50%) scale(.5);opacity:0}to{transform:translateX(-50%) scale(1);opacity:1}}
    @keyframes world-float{to{transform:translateX(-50%) translateY(-26px) scale(1.25);opacity:0}}
    @keyframes world-hit{from{transform:translate(-50%,-6px);opacity:1}to{transform:translate(-50%,-26px);opacity:0}}
    #world-bar{position:fixed;left:14px;bottom:14px;z-index:60;display:flex;align-items:center;gap:7px;flex-wrap:wrap;
      max-width:min(94vw,470px);pointer-events:auto;background:rgba(17,17,24,.9);border:1px solid rgba(122,162,255,.22);
      border-radius:12px;padding:6px 9px;backdrop-filter:blur(10px);font-family:'Segoe UI',sans-serif}
    #world-bar input{background:#0b0b0f;border:1px solid rgba(122,162,255,.22);color:#e5e5e5;border-radius:7px;font:inherit;font-size:.82rem;padding:5px 8px}
    #wb-name{width:84px}#wb-chat{width:150px}
    #world-bar .wb-face{font-size:20px;line-height:1;cursor:pointer}
    .wb-emote{cursor:pointer;font-size:18px;line-height:1;padding:2px 3px;border-radius:6px}
    .wb-emote:hover{background:rgba(122,162,255,.16)}
    .wb-count{color:#8a96b8;font-size:.76rem;white-space:nowrap}
    .wb-x{cursor:pointer;color:#8a96b8;font-size:1rem;padding:0 3px}.wb-x:hover{color:#e5e5e5}
    #wb-toggle{position:fixed;left:14px;bottom:14px;z-index:60;pointer-events:auto;cursor:pointer;display:none;
      background:rgba(17,17,24,.9);border:1px solid rgba(122,162,255,.22);border-radius:10px;padding:6px 12px;color:#e5e5e5;font:600 12px 'Segoe UI'}
    /* combat HUD row */
    .wb-hud{display:flex;align-items:center;gap:8px;width:100%;order:9;padding-top:2px;border-top:1px solid rgba(122,162,255,.14)}
    .wb-hud .lbl{color:#8a96b8;font-size:.68rem}
    .wb-hp,.wb-nuke{width:70px;height:8px;border-radius:5px;background:#222;overflow:hidden}
    .wb-hp>i{display:block;height:100%;width:100%;background:#3ba55d;transition:width .2s,background .2s}
    .wb-nuke>i{display:block;height:100%;width:0;background:linear-gradient(90deg,#faa61a,#ff5555)}
    .wb-pow{color:#ffd27b;font-weight:700;font-size:.82rem}
    .wb-status{font-size:.72rem;color:#8a96b8}
    #world-banner{position:fixed;left:50%;top:32%;transform:translate(-50%,-50%);z-index:70;pointer-events:none;display:none;
      text-align:center;background:rgba(12,12,16,.86);border:1px solid rgba(122,162,255,.35);border-radius:16px;padding:20px 34px;
      box-shadow:0 10px 40px rgba(0,0,0,.6)}
    #world-banner .wbn-title{font:800 28px 'Segoe UI';color:#ffd27b}
    #world-banner .wbn-sub{font:600 15px 'Segoe UI';color:#e5e5e5;margin-top:6px}
    #world-flash{position:fixed;inset:0;z-index:69;pointer-events:none;background:#fff;opacity:0}
    #world-flash.go{animation:world-nuke 1s ease forwards}
    @keyframes world-nuke{0%{opacity:0}12%{opacity:.9}100%{opacity:0}}
  `;
  document.head.appendChild(style);

  const layer = document.createElement('div'); layer.id = 'world-layer'; document.body.appendChild(layer);
  const flash = document.createElement('div'); flash.id = 'world-flash'; document.body.appendChild(flash);
  const banner = document.createElement('div'); banner.id = 'world-banner';
  banner.innerHTML = '<div class="wbn-title"></div><div class="wbn-sub"></div>'; document.body.appendChild(banner);

  function makeAvatar(av, name, isMe) {
    const el = document.createElement('div');
    el.className = 'world-av' + (isMe ? ' me' : '');
    const face = document.createElement('div'); face.className = 'world-face'; face.textContent = av;
    const hp = document.createElement('div'); hp.className = 'world-hp'; const hpi = document.createElement('i'); hp.appendChild(hpi);
    const nm = document.createElement('div'); nm.className = 'world-name';
    const nmText = document.createTextNode(name); const pow = document.createElement('span'); pow.className = 'world-pow'; pow.textContent = '⚡1';
    nm.appendChild(nmText); nm.appendChild(pow);
    const status = document.createElement('div'); status.className = 'world-status';
    el.append(face, hp, nm, status);
    layer.appendChild(el);
    const o = { el, face, hpi, nm, nmText, pow, status, av, dead: false, lastEt: 0, lastMt: 0 };
    if (!isMe) face.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); attack(o._id); });
    return o;
  }

  function hpColor(pct) { return pct > 60 ? '#3ba55d' : pct > 30 ? '#faa61a' : '#ff5555'; }

  function updateCombat(o, pd, avEmoji) {
    o.dead = !!pd.dead;
    o.pow.textContent = '⚡' + pd.power;
    if (pd.dead) {
      o.el.classList.add('dead'); o.face.textContent = '💀'; o.hpi.style.width = '0%';
      o.status.textContent = pd.respawnMs ? 'respawn ' + Math.ceil(pd.respawnMs / 1000) + 's' : '';
    } else {
      o.el.classList.remove('dead'); o.face.textContent = avEmoji; o.status.textContent = '';
      const pct = Math.max(0, Math.min(100, pd.hp));
      o.hpi.style.width = pct + '%'; o.hpi.style.background = hpColor(pct);
    }
  }

  // ---- local avatar + movement ----
  const me = makeAvatar(myAv, myName, true);
  let tX = 0.5, tY = 0.5, pX = 0.5, pY = 0.5, pending = {}, iAmDead = false, roundPhase = 'active';
  const setPos = (o, fx, fy) => { o.el.style.transform = `translate(${fx * innerWidth}px, ${fy * innerHeight}px)`; };
  addEventListener('pointermove', (e) => { tX = e.clientX / innerWidth; tY = e.clientY / innerHeight; }, { passive: true });
  addEventListener('touchmove', (e) => { const t = e.touches[0]; if (t) { tX = t.clientX / innerWidth; tY = t.clientY / innerHeight; } }, { passive: true });
  (function tick() { pX += (tX - pX) * 0.22; pY += (tY - pY) * 0.22; setPos(me, pX, pY); requestAnimationFrame(tick); })();

  function showBubble(o, text, emote) {
    const b = document.createElement('div'); b.className = 'world-bubble' + (emote ? ' emote' : ''); b.textContent = text;
    o.el.appendChild(b); setTimeout(() => b.remove(), emote ? 1700 : 5000);
  }
  function hitSpark(o, dmg) {
    const s = document.createElement('div'); s.className = 'world-hit'; s.textContent = '-' + dmg;
    s.style.transform = 'translate(-50%,-6px)'; o.el.appendChild(s); setTimeout(() => s.remove(), 600);
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
  // combat HUD row
  const hud = document.createElement('span'); hud.className = 'wb-hud';
  hud.innerHTML = '<span class="lbl">HP</span><span class="wb-hp"><i></i></span>' +
                  '<span class="wb-pow">⚡1</span>' +
                  '<span class="lbl">☢</span><span class="wb-nuke"><i></i></span>' +
                  '<span class="wb-status">alive</span>';
  bar.append(faceBtn, nameIn, emoteWrap, chatIn, count, xBtn, hud);
  document.body.appendChild(bar);
  const hudHp = hud.querySelector('.wb-hp>i'), hudPow = hud.querySelector('.wb-pow'), hudNuke = hud.querySelector('.wb-nuke>i'), hudStatus = hud.querySelector('.wb-status');

  const toggle = document.createElement('div'); toggle.id = 'wb-toggle'; toggle.textContent = '⚔ show arena'; document.body.appendChild(toggle);
  xBtn.addEventListener('click', () => { bar.style.display = 'none'; toggle.style.display = 'block'; });
  toggle.addEventListener('click', () => { bar.style.display = 'flex'; toggle.style.display = 'none'; });
  faceBtn.addEventListener('click', () => { myAv = AVATARS[(Math.random() * AVATARS.length) | 0]; localStorage.setItem('world_av', myAv); faceBtn.textContent = myAv; if (!iAmDead) me.face.textContent = myAv; });
  nameIn.addEventListener('input', () => { myName = nameIn.value.trim() || 'guest'; localStorage.setItem('place_name', myName); me.nmText.textContent = myName; });
  chatIn.addEventListener('keydown', (e) => { if (e.key !== 'Enter') return; const msg = chatIn.value.trim(); if (!msg) return; pending.m = msg; pending.mt = Date.now(); showBubble(me, msg, false); chatIn.value = ''; });
  function doEmote(em) { pending.e = em; pending.et = Date.now(); showBubble(me, em, true); }

  // ---- networking ----
  const remotes = new Map();
  async function send(action, extra) {
    const url = action ? `${ENDPOINT}?action=${action}` : ENDPOINT;
    const body = Object.assign({ id, x: +tX.toFixed(4), y: +tY.toFixed(4), n: myName, a: myAv }, action ? {} : pending, extra || {});
    if (!action) pending = {};
    try { return await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then((r) => r.json()); }
    catch { return null; }
  }

  let myAtkCd = 0;
  async function attack(targetId) {
    if (iAmDead || roundPhase !== 'active' || !targetId) return;
    if (Date.now() < myAtkCd) return;
    myAtkCd = Date.now() + ATTACK_CD_MS;
    const o = remotes.get(targetId); if (o) hitSpark(o, ''); // instant feedback
    applyState(await send('attack', { target: targetId }));
  }

  let lastRound = 1, lastPhase = 'active', prevHp = {};
  function applyState(data) {
    if (!data) return;
    const players = data.players || {}, round = data.round || {};
    roundPhase = round.phase; count.textContent = (data.count || 1) + ' online';

    const seen = new Set();
    for (const uid in players) {
      const pd = players[uid]; seen.add(uid);
      if (uid === id) {
        // my own combat state (position stays local)
        iAmDead = !!pd.dead;
        updateCombat(me, pd, myAv);
        hudPow.textContent = '⚡' + pd.power;
        const pct = pd.dead ? 0 : Math.max(0, Math.min(100, pd.hp));
        hudHp.style.width = pct + '%'; hudHp.style.background = hpColor(pct);
        hudNuke.style.width = Math.min(100, (pd.aliveMs / WIN_MS) * 100) + '%';
        hudStatus.textContent = pd.dead ? ('DEAD · ' + Math.ceil(pd.respawnMs / 1000) + 's') : 'alive';
        continue;
      }
      let o = remotes.get(uid);
      if (!o) { o = makeAvatar(pd.a, pd.n, false); o._id = uid; remotes.set(uid, o); }
      if (o.nmText.textContent !== pd.n) o.nmText.textContent = pd.n;
      o.av = pd.a;
      setPos(o, Math.max(0, Math.min(1, pd.x)), Math.max(0, Math.min(1, pd.y)));
      // damage flash if their hp dropped
      const was = prevHp[uid]; if (was != null && pd.hp < was && !pd.dead) hitSpark(o, was - pd.hp);
      prevHp[uid] = pd.hp;
      updateCombat(o, pd, pd.a);
      if (pd.et && pd.et > o.lastEt) { o.lastEt = pd.et; showBubble(o, pd.e || '👋', true); }
      if (pd.mt && pd.mt > o.lastMt) { o.lastMt = pd.mt; showBubble(o, pd.m || '', false); }
    }
    for (const [uid, o] of remotes) if (!seen.has(uid)) { o.el.remove(); remotes.delete(uid); delete prevHp[uid]; }

    // round / nuke banner
    if (round.phase === 'inter') {
      if (lastPhase !== 'inter') { flash.classList.remove('go'); void flash.offsetWidth; flash.classList.add('go'); } // nuke flash on the transition
      banner.style.display = 'block';
      banner.querySelector('.wbn-title').textContent = '🏆 ' + (round.winner || 'someone') + ' won!';
      banner.querySelector('.wbn-sub').textContent = 'New round in ' + Math.ceil((round.interMs || 0) / 1000) + 's';
    } else {
      banner.style.display = 'none';
    }
    lastPhase = round.phase; lastRound = round.id;
  }

  send(null).then(applyState);
  setInterval(() => send(null).then(applyState), POLL_MS);
})();
