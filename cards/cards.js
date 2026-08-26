// Cards Against Lag — client. Renders whatever the server-authoritative poll returns and
// sends the player's moves. Session (room code + secret token) lives in localStorage so a
// refresh keeps your seat. Re-renders only when the visible state actually changes, so an
// in-progress card selection isn't wiped by the background poll.
(function () {
  'use strict';
  var API = '/cards/api.php';
  var app = document.getElementById('app');
  var session = null, timer = null, lastSig = '', selected = [], busy = false;
  try { session = JSON.parse(localStorage.getItem('cah_session') || 'null'); } catch (e) {}

  function esc(s) { return String(s == null ? '' : s).replace(/[<>&"]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]; }); }
  function saveSession(s) { session = s; try { localStorage.setItem('cah_session', JSON.stringify(s)); } catch (e) {} }
  function clearSession() { session = null; try { localStorage.removeItem('cah_session'); } catch (e) {} }

  function api(action, params) {
    var body = new URLSearchParams(Object.assign({ action: action }, params || {}));
    return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
      .then(function (r) { return r.json(); });
  }
  function poll() {
    if (!session) return;
    var u = API + '?' + new URLSearchParams({ action: 'state', code: session.code, token: session.token });
    fetch(u).then(function (r) { return r.json(); }).then(onState).catch(function () {});
  }
  function startPolling() { stopPolling(); poll(); timer = setInterval(poll, 1500); }
  function stopPolling() { if (timer) clearInterval(timer); timer = null; }

  function onState(s) {
    if (!s || !s.ok) {
      stopPolling(); clearSession(); lastSig = '';
      renderLanding(s && s.error ? s.error : 'That room has closed.');
      return;
    }
    var sig = JSON.stringify([s.phase, s.round, s.me.czar, s.me.submitted, (s.me.hand || []).length,
      s.players.map(function (p) { return [p.id, p.score, p.connected, p.czar, p.submitted]; }),
      s.winner && s.winner.id, s.black && s.black.t, (s.submissions || []).length]);
    if (sig === lastSig) return;
    lastSig = sig;
    render(s);
  }

  // ---- landing ----
  function renderLanding(err) {
    stopPolling();
    var room = new URLSearchParams(location.search).get('room') || '';
    app.innerHTML =
      '<div class="grid2">' +
        '<div class="panel"><h2>Start a room</h2>' +
          '<label>Your name</label><input type="text" id="cName" maxlength="20" placeholder="e.g. ' + esc(rndName()) + '">' +
          '<label>Deck</label><div class="deckpick">' +
            '<div class="deckopt on" data-deck="clean"><div class="t">Squeaky Clean</div><div class="d">family-friendly</div></div>' +
            '<div class="deckopt nsfw" data-deck="nsfw"><div class="t">Uncut 🔞</div><div class="d">rude · 18+</div></div>' +
          '</div>' +
          '<label>Play to</label><select id="cTarget"><option>5</option><option selected>7</option><option>10</option></select>' +
          '<div class="row" style="margin-top:14px"><button class="btn grn" id="createBtn">Create room</button></div>' +
          '<div class="err" id="cErr"></div>' +
        '</div>' +
        '<div class="panel"><h2>Join a room</h2>' +
          '<label>Room code</label><input type="text" id="jCode" maxlength="4" placeholder="ABCD" style="text-transform:uppercase" value="' + esc(room) + '">' +
          '<label>Your name</label><input type="text" id="jName" maxlength="20" placeholder="e.g. ' + esc(rndName()) + '">' +
          '<div class="row" style="margin-top:14px"><button class="btn" id="joinBtn">Join</button></div>' +
          '<div class="err" id="jErr"></div>' +
          '<p class="muted" style="margin-top:14px">3+ players needed. Share the room code and everyone joins from here.</p>' +
        '</div>' +
      '</div>' + (err ? '<div class="err" style="text-align:center">' + esc(err) + '</div>' : '');

    var deck = 'clean';
    [].forEach.call(app.querySelectorAll('.deckopt'), function (o) {
      o.addEventListener('click', function () {
        app.querySelectorAll('.deckopt').forEach(function (x) { x.classList.remove('on'); });
        o.classList.add('on'); deck = o.getAttribute('data-deck');
      });
    });
    document.getElementById('createBtn').addEventListener('click', function () {
      var name = document.getElementById('cName').value.trim();
      if (!name) { document.getElementById('cErr').textContent = 'Enter a name.'; return; }
      var go = function () { doCreate(name, deck, document.getElementById('cTarget').value); };
      if (deck === 'nsfw') ageGate(go); else go();
    });
    document.getElementById('joinBtn').addEventListener('click', function () {
      var code = document.getElementById('jCode').value.trim().toUpperCase();
      var name = document.getElementById('jName').value.trim();
      if (!code || !name) { document.getElementById('jErr').textContent = 'Code and name required.'; return; }
      doJoin(code, name);
    });
  }
  function doCreate(name, deck, target) {
    api('create', { name: name, deck: deck, target: target }).then(function (r) {
      if (!r.ok) { document.getElementById('cErr').textContent = r.error || 'Failed.'; return; }
      saveSession({ code: r.code, token: r.token, pid: r.player_id, name: name });
      history.replaceState(null, '', '/cards/?room=' + r.code);
      startPolling();
    });
  }
  function doJoin(code, name) {
    api('join', { code: code, name: name }).then(function (r) {
      if (!r.ok) { document.getElementById('jErr').textContent = r.error || 'Failed.'; return; }
      saveSession({ code: r.code, token: r.token, pid: r.player_id, name: name });
      history.replaceState(null, '', '/cards/?room=' + r.code);
      startPolling();
    });
  }
  function ageGate(go) {
    var m = document.createElement('div'); m.className = 'modal';
    m.innerHTML = '<div class="box"><h3>Adults only 🔞</h3><p>The “Uncut” deck contains crude, offensive, adult content. Only continue if everyone playing is 18+ and cool with that.</p>' +
      '<div class="row" style="justify-content:center"><button class="btn ghost sm" id="agNo">Back</button><button class="btn red" id="agYes">I’m 18+, continue</button></div></div>';
    document.body.appendChild(m);
    m.querySelector('#agNo').addEventListener('click', function () { m.remove(); });
    m.querySelector('#agYes').addEventListener('click', function () { m.remove(); go(); });
  }

  // ---- game render ----
  function render(s) {
    var html = '<div class="top" style="margin-top:4px">' +
      '<span class="pill">room <b>' + esc(s.code) + '</b></span>' +
      '<button class="btn ghost sm" id="shareBtn">share</button>' +
      (s.nsfw ? '<span class="pill" style="color:var(--red)">18+ deck</span>' : '') +
      '<span class="sp"></span><button class="btn ghost sm" id="leaveBtn">leave</button></div>';

    html += '<div class="board"><div class="side"><div class="panel" style="padding:12px">' +
      '<div class="muted" style="margin-bottom:8px">Players · first to ' + s.target + '</div><ul class="scores">' +
      s.players.map(function (p) {
        return '<li class="' + (p.id === s.me.id ? 'me' : '') + '"><span class="dot ' + (p.connected ? '' : 'off') + '"></span>' +
          '<span class="nm">' + esc(p.name) + (p.host ? ' <span class="muted">host</span>' : '') + '</span>' +
          (p.czar ? '<span class="crown" title="Card Czar">👑</span>' : (p.submitted && (s.phase === 'playing') ? '<span title="locked in">✓</span>' : '')) +
          '<span class="sc">' + p.score + '</span></li>';
      }).join('') + '</ul></div></div><div class="main">';

    if (s.phase === 'lobby') html += lobby(s);
    else if (s.phase === 'gameover') html += gameover(s);
    else if (s.phase === 'roundover') html += roundover(s);
    else if (s.phase === 'judging') html += judging(s);
    else html += playing(s);

    html += '</div></div>';
    app.innerHTML = html;

    // wire common
    var sb = document.getElementById('shareBtn');
    if (sb) sb.addEventListener('click', function () {
      var url = location.origin + '/cards/?room=' + s.code;
      if (navigator.clipboard) navigator.clipboard.writeText(url).then(function () { sb.textContent = 'copied!'; setTimeout(function () { sb.textContent = 'share'; }, 1500); });
      else prompt('Share this link:', url);
    });
    var lv = document.getElementById('leaveBtn');
    if (lv) lv.addEventListener('click', function () { api('leave', { code: s.code, token: s.token || session.token }); clearSession(); stopPolling(); history.replaceState(null, '', '/cards/'); lastSig = ''; renderLanding(); });
    wirePhase(s);
  }

  function lobby(s) {
    var host = s.me.host, enough = s.players.filter(function (p) { return p.connected; }).length >= 3;
    return '<div class="panel"><h2>Waiting in the lobby…</h2>' +
      '<p class="muted">Deck: <b>' + (s.nsfw ? 'Uncut 🔞' : 'Squeaky Clean') + '</b> · playing to <b>' + s.target + '</b>. Share the room code so friends can join.</p>' +
      '<div class="row" style="margin-top:16px">' +
      (host ? '<button class="btn grn" id="startBtn"' + (enough ? '' : ' disabled') + '>Start game</button>' +
              (enough ? '' : '<span class="muted">need 3+ players</span>')
            : '<span class="muted">Waiting for the host to start…</span>') +
      '</div></div>';
  }

  function playing(s) {
    var black = '<div class="card black">' + fillBlack(s.black, null, false) + pickTag(s.black) + '</div>';
    if (s.me.czar) return black + '<p class="status" style="margin-top:16px">You’re the <b>Card Czar</b> 👑 — sit tight while everyone answers.</p>' + waitingList(s);
    if (s.me.submitted) return black + '<p class="status" style="margin-top:16px">Locked in ✓ — waiting for the others…</p>' + waitingList(s);
    var pick = (s.black && s.black.pick) || 1;
    var hand = '<div class="hand">' + (s.me.hand || []).map(function (t, i) {
      return '<div class="card white" data-txt="' + esc(t) + '">' + esc(t) + '</div>';
    }).join('') + '</div>';
    return black + '<p class="status" style="margin-top:16px">Pick <b>' + pick + '</b> card' + (pick > 1 ? 's' : '') + ' to answer.</p>' + hand +
      '<div class="actions"><button class="btn grn" id="submitBtn" disabled>Submit</button></div>';
  }
  function waitingList(s) {
    var waiting = s.players.filter(function (p) { return !p.czar && p.connected && !p.submitted; }).map(function (p) { return esc(p.name); });
    return '<p class="status">' + (waiting.length ? 'Waiting on: ' + waiting.join(', ') : 'Everyone’s in…') + '</p>';
  }

  function judging(s) {
    var black = '<div class="card black">' + fillBlack(s.black, null, false) + pickTag(s.black) + '</div>';
    var mine = s.me.czar;
    var subs = '<div class="subs">' + (s.submissions || []).map(function (sub) {
      return '<div class="sub ' + (mine ? 'judge' : '') + '" data-sid="' + sub.sid + '">' + fillBlack(s.black, sub.cards, true) + '</div>';
    }).join('') + '</div>';
    return black + '<p class="status" style="margin-top:16px">' + (mine ? 'You’re judging 👑 — tap the funniest answer.' : 'The Card Czar is judging…') + '</p>' + subs;
  }

  function roundover(s) {
    var w = s.winner || {};
    return '<div class="panel winbanner"><div class="muted">round winner</div><div class="who">' + esc(w.name) + '</div>' +
      '<div class="card black combo">' + fillBlack(s.black, w.cards, true) + '</div>' +
      '<p class="status" style="margin-top:14px">Next round starting…</p></div>';
  }

  function gameover(s) {
    var w = s.winner || {};
    var ranked = s.players.slice().sort(function (a, b) { return b.score - a.score; });
    return '<div class="panel winbanner"><div class="muted">winner 🏆</div><div class="who">' + esc(ranked[0] ? ranked[0].name : w.name) + '</div>' +
      '<ul class="scores" style="max-width:320px;margin:16px auto 0;text-align:left">' +
      ranked.map(function (p) { return '<li><span class="nm">' + esc(p.name) + '</span><span class="sc">' + p.score + '</span></li>'; }).join('') + '</ul>' +
      '<div class="row" style="justify-content:center;margin-top:18px">' +
      (s.me.host ? '<button class="btn grn" id="againBtn">Play again</button>' : '<span class="muted">Waiting for the host to restart…</span>') +
      '</div></div>';
  }

  function pickTag(black) { return (black && black.pick > 1) ? '<span class="pickn">PICK ' + black.pick + '</span>' : ''; }
  function fillBlack(black, whites, reveal) {
    if (!black) return '';
    var t = esc(black.t), i = 0;
    if (t.indexOf('_') >= 0) {
      t = t.replace(/_+/g, function () {
        if (reveal && whites && i < whites.length) return '<u style="color:var(--grn);text-decoration:none;font-weight:800">' + esc(whites[i++]) + '</u>';
        return '<span class="blank">&nbsp;&nbsp;&nbsp;</span>';
      });
    } else if (reveal && whites && whites.length) {
      t += ' <u style="color:var(--grn);text-decoration:none;font-weight:800">' + esc(whites.join(' / ')) + '</u>';
    }
    return t;
  }

  function wirePhase(s) {
    var startBtn = document.getElementById('startBtn');
    if (startBtn) startBtn.addEventListener('click', function () { startBtn.disabled = true; api('start', { code: s.code, token: session.token }).then(function (r) { if (!r.ok) { startBtn.disabled = false; alert(r.error); } }); });
    var againBtn = document.getElementById('againBtn');
    if (againBtn) againBtn.addEventListener('click', function () { againBtn.disabled = true; api('again', { code: s.code, token: session.token }); });

    if (s.phase === 'playing' && !s.me.czar && !s.me.submitted) {
      var pick = (s.black && s.black.pick) || 1;
      selected = selected.filter(function (t) { return (s.me.hand || []).indexOf(t) >= 0; });
      var cards = app.querySelectorAll('.card.white');
      var submitBtn = document.getElementById('submitBtn');
      function repaint() {
        [].forEach.call(cards, function (c) {
          var t = c.getAttribute('data-txt'), k = selected.indexOf(t);
          c.classList.toggle('sel', k >= 0);
          var o = c.querySelector('.ord'); if (o) o.remove();
          if (k >= 0 && pick > 1) c.insertAdjacentHTML('afterbegin', '<span class="ord">' + (k + 1) + '</span>');
        });
        if (submitBtn) submitBtn.disabled = selected.length !== pick;
      }
      [].forEach.call(cards, function (c) {
        c.addEventListener('click', function () {
          var t = c.getAttribute('data-txt'), k = selected.indexOf(t);
          if (k >= 0) selected.splice(k, 1);
          else { if (selected.length >= pick) selected.shift(); selected.push(t); }
          repaint();
        });
      });
      repaint();
      if (submitBtn) submitBtn.addEventListener('click', function () {
        if (busy || selected.length !== pick) return; busy = true; submitBtn.disabled = true;
        api('submit', { code: s.code, token: session.token, cards: JSON.stringify(selected) }).then(function (r) {
          busy = false; if (!r.ok) { alert(r.error); submitBtn.disabled = false; } else { selected = []; lastSig = ''; poll(); }
        });
      });
    }

    if (s.phase === 'judging' && s.me.czar) {
      [].forEach.call(app.querySelectorAll('.sub.judge'), function (el) {
        el.addEventListener('click', function () {
          if (busy) return; busy = true;
          api('pick', { code: s.code, token: session.token, winner: el.getAttribute('data-sid') }).then(function (r) {
            busy = false; if (!r.ok) alert(r.error); else { lastSig = ''; poll(); }
          });
        });
      });
    }
  }

  function rndName() { var a = ['Fox', 'Ace', 'Bolt', 'Nova', 'Zap', 'Kilo', 'Echo', 'Rex']; return a[Math.floor(Math.random() * a.length)]; }

  // boot
  if (session && session.code && session.token) startPolling();
  else renderLanding();
})();
