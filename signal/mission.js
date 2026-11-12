(() => {
  'use strict';
  const key = 'm190.signal01.stage';
  let stage = 1;
  try { stage = Number(localStorage.getItem(key)) || 1; } catch (_) {}
  if (![1, 2, 3].includes(stage)) stage = 1;
  if (new URLSearchParams(location.search).get('trace') === '1') stage = Math.max(stage, 2);
  const feedback = document.getElementById('feedback');
  const save = (next) => { stage = next; try { localStorage.setItem(key, String(stage)); } catch (_) {} };
  const show = (focus = false) => {
    // Completed visitors can revisit the trail without being forced away.
    const shown = stage === 1 ? 'decode' : 'trace';
    ['decode', 'trace'].forEach(id => { document.getElementById(id).hidden = id !== shown; });
    [1, 2, 3].forEach(n => {
      const el = document.getElementById('progress-' + n);
      el.classList.toggle('active', n === Math.min(stage, 2));
      if (n === Math.min(stage, 2)) el.setAttribute('aria-current', 'step'); else el.removeAttribute('aria-current');
    });
    document.getElementById('case-status').textContent = stage === 1 ? 'AWAITING INPUT' : 'TRACE ACQUIRED';
    document.getElementById('reset').hidden = false;
    if (focus) document.querySelector('#' + shown + ' h2').focus();
  };
  const normalize = value => value.trim().toUpperCase();
  const respond = (text, error = false) => { feedback.textContent = text; feedback.classList.toggle('error', error); };
  document.getElementById('decode-form').addEventListener('submit', event => {
    event.preventDefault();
    if (normalize(document.getElementById('decode-answer').value) !== 'UNMASK') return respond('Not quite. Move each letter back three places. The hint can help.', true);
    save(2); respond('Transmission decoded. Follow the trace.'); show(true);
  });
  document.getElementById('trace-form').addEventListener('submit', event => {
    event.preventDefault();
    if (normalize(document.getElementById('trace-answer').value) !== 'BEACON') return respond('That callsign does not match. Read the first letter of each line in the field note.', true);
    save(3);
    // URL flag also completes the journey if browser storage is unavailable.
    window.location.assign('/signal/archive.html?recovered=1');
  });
  document.getElementById('reset').addEventListener('click', () => {
    save(1);
    try { history.replaceState(null, '', location.pathname); } catch (_) {}
    document.querySelectorAll('input').forEach(input => { input.value = ''; });
    document.querySelectorAll('details').forEach(detail => { detail.open = false; });
    respond('Case restarted.'); show(true);
  });
  show();
})();
