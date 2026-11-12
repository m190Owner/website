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
  // Returning from the clue (or browser Back) preserves the trail.
  // Explicit reload always starts a fresh case, even on a return URL.
  if (!reload && params.get('trace') === '1') {
    current = find(params.get('case'));
    if (current) stage = 2;
  }
  if (!reload && back && history.state && history.state.signalCase) {
    current = find(history.state.signalCase);
    stage = history.state.signalStage === 2 ? 2 : 1;
  }
  const remember = () => {
    try { localStorage.setItem(key, current.id); } catch (_) {}
    try { history.replaceState({signalCase:current.id, signalStage:stage}, '', location.pathname); } catch (_) {}
  };
  const choose = () => {
    let last = current && current.id;
    if (!last && reload) last = (history.state && history.state.signalCase) || params.get('case');
    if (!last) { try { last = localStorage.getItem(key); } catch (_) {} }
    // URL also avoids an immediate repeat on reload if storage is blocked.
    if (!last) last = params.get('case') || (history.state && history.state.signalCase);
    const pool = cases.filter(c => c.id !== last);
    current = pool[Math.floor(Math.random() * pool.length)];
    stage = 1;
  };
  if (!current && !reload && window.SignalProgress.read().includes(params.get('replay'))) {
    current = find(params.get('replay'));
  }
  if (!current) choose();
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
    document.querySelector('#trace h2 + p').textContent = 'Open the clue-only field note for case ' + current.id + ' and bring back its ' + current.callsign.length + '-letter callsign.';
    document.querySelector('#trace a.button').href = '/unmask/?signal=01&case=' + current.id;
    document.querySelector('#trace details p').textContent = 'Read the first letter of each line in the field note. If you cannot open it, the callsign is ' + current.callsign + '.';
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
  const normalize = value => value.trim().toUpperCase();
  document.getElementById('decode-form').addEventListener('submit', event => {
    event.preventDefault();
    if (normalize(document.getElementById('decode-answer').value) !== current.answer) return respond('Not quite. Check the instructions or open the hint.',true);
    stage = 2; respond('Transmission decoded. Follow the trace.'); render(true);
  });
  document.getElementById('trace-form').addEventListener('submit', event => {
    event.preventDefault();
    if (normalize(document.getElementById('trace-answer').value) !== current.callsign) return respond('That callsign does not match this case. Read the first letter of each field-note line.',true);
    const saved = window.SignalProgress.complete(current.id);
    window.location.assign('/signal/archive.html?recovered=1&case=' + current.id + '&saved=' + (saved ? '1' : '0'));
  });
  document.getElementById('reset').addEventListener('click', () => {
    choose();
    document.querySelectorAll('input').forEach(input => { input.value = ''; });
    document.querySelectorAll('details').forEach(detail => { detail.open = false; });
    respond('New case selected.'); render(true);
  });
  render();
})();
