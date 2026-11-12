(() => {
  'use strict';
  const cases = window.SignalCases;
  const params = new URLSearchParams(location.search);
  const navigation = performance.getEntriesByType('navigation')[0];
  const reload = navigation && navigation.type === 'reload';
  const back = navigation && navigation.type === 'back_forward';
  const key = 'm190.signal.lastCase';
  let current, stage = 1;
  const find = id => cases.find(c => c.id === id);
  const sessionKey = 'm190.signal.active';
  let active;
  try { active = JSON.parse(sessionStorage.getItem(sessionKey) || 'null'); } catch (_) {}
  // A chosen replay and explicit clue return are intentional navigation.
  if (window.SignalProgress.read().includes(params.get('replay'))) {
    current = find(params.get('replay'));
  } else if (params.get('trace') === '1') {
    current = find(params.get('case'));
    if (current) stage = 2;
  } else if ((reload || back) && history.state && find(history.state.signalCase)) {
    current = find(history.state.signalCase);
    stage = history.state.signalStage === 2 ? 2 : 1;
  } else if (active && find(active.id)) {
    current = find(active.id);
    stage = active.stage === 2 ? 2 : 1;
  }
  const remember = () => {
    try { localStorage.setItem(key, current.id); } catch (_) {}
    try {
      sessionStorage.setItem(sessionKey, JSON.stringify({id:current.id, stage}));
      sessionStorage.setItem('m190.signal.invitationSeen', '1');
    } catch (_) {}
    try { history.replaceState({signalCase:current.id, signalStage:stage}, '', location.pathname); } catch (_) {}
  };
  if (!current) {
    let last;
    try { last = localStorage.getItem(key); } catch (_) {}
    const pool = cases.filter(c => !c.retired && c.id !== last);
    current = pool[Math.floor(Math.random() * pool.length)];
  }
  const feedback = document.getElementById('feedback');
  const respond = (text, error = false) => { feedback.textContent = text; feedback.classList.toggle('error', error); };
  const render = (focus = false) => {
    document.getElementById('case-number').textContent = 'Case ' + current.id.padStart(3, '0');
    document.getElementById('case-label').textContent = 'CASE ' + current.id.padStart(3, '0') + ' / SIGNAL';
    document.querySelector('#decode h2').textContent = current.title;
    document.querySelector('#decode h2 + p').textContent = current.instruction;
    const cipher = document.querySelector('.transmission');
    cipher.textContent = current.cipher;
    cipher.setAttribute('aria-label', 'Encoded transmission: ' + current.cipher);
    document.querySelector('#decode details p').textContent = current.hint;
    document.getElementById('case-story').textContent = current.story || 'First edition: a short decoding exercise.';
    document.getElementById('case-difficulty').textContent = current.difficulty ? current.difficulty + ' · ' + current.minutes + ' minutes' : 'First edition · 3 minutes';
    document.querySelector('#trace h2').textContent = current.traceTitle || 'The signal points to Unmask.';
    document.querySelector('#trace h2 + p').textContent = 'Open the clue-only field note for case ' + current.id + ' and bring back its ' + current.callsign.length + '-letter callsign.';
    document.querySelector('#trace a.button').href = '/unmask/?signal=01&case=' + current.id;
    document.querySelector('#trace details p').textContent = 'Read the first letter of each line in the field note. If you cannot open it, the callsign is ' + current.callsign + '.';
    const links = document.getElementById('evidence-links');
    links.replaceChildren();
    const primary = document.querySelector('#trace a.button');
    const note = document.querySelector('#trace .note');
    if (current.evidence) {
      const locations = {projects:'/projects/field-note.html',map:'/map-field-note.html',canvas:'/canvas-field-note.html',room:'/room-field-note.html'};
      document.querySelector('#trace h2 + p').textContent = current.tracePrompt;
      document.querySelector('#trace details p').textContent = current.traceHint;
      current.evidence.forEach((n,i) => {
        const a = document.createElement('a');
        a.href = locations[n.location] + '?fieldcase=' + current.id + '&note=' + i;
        a.textContent = (i + 1) + '. ' + n.title + ' / ' + n.location;
        links.append(a);
      });
      primary.href = links.firstChild.href;
      primary.textContent = 'Open the first evidence note';
      note.textContent = 'Read all the notes, then connect the evidence. Everything in these notes is fictional; no live data, editing or account access is needed.';
    } else {
      primary.textContent = 'Follow the trace ↗';
      note.textContent = 'This is a clue-only visit to Unmask. The browser-privacy demonstration will not run.';
    }
    ['decode','trace'].forEach(id => { document.getElementById(id).hidden = id !== (stage === 1 ? 'decode' : 'trace'); });
    [1,2,3].forEach(n => {
      const el = document.getElementById('progress-' + n);
      el.classList.toggle('active', n === stage);
      if (n === stage) el.setAttribute('aria-current','step'); else el.removeAttribute('aria-current');
    });
    document.getElementById('case-status').textContent = stage === 1 ? 'AWAITING INPUT' : 'TRACE ACQUIRED';
    document.getElementById('reset').hidden = false;
    remember();
    if (focus) document.querySelector(stage === 1 ? '#decode h2' : '#trace h2').focus();
  };
  const normalize = value => value.trim().toUpperCase().replace(/[\s-]+/g, '');
  document.getElementById('decode-form').addEventListener('submit', event => {
    event.preventDefault();
    if (normalize(document.getElementById('decode-answer').value) !== normalize(current.answer)) return respond('Not quite. Check the instructions or open the hint.',true);
    stage = 2; respond('Transmission decoded. Follow the trace.'); render(true);
  });
  document.getElementById('trace-form').addEventListener('submit', event => {
    event.preventDefault();
    if (normalize(document.getElementById('trace-answer').value) !== normalize(current.callsign)) return respond('That answer does not fit this case. Recheck the evidence and the final question.',true);
    const saved = window.SignalProgress.complete(current.id);
    window.location.assign('/signal/archive.html?recovered=1&case=' + current.id + '&saved=' + (saved ? '1' : '0'));
  });
  document.getElementById('reset').addEventListener('click', () => {
    stage = 1;
    document.querySelectorAll('input').forEach(input => { input.value = ''; });
    document.querySelectorAll('details').forEach(detail => { detail.open = false; });
    respond('This case restarted.'); render(true);
  });
  render();
})();
