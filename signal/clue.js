(() => {
  'use strict';
  const params = new URLSearchParams(location.search);
  if (params.get('signal') !== '01') return;
  const current = window.SignalCases.find(c => c.id === params.get('case') && Array.isArray(c.lines)) || window.SignalCases[0];
  const clue = document.getElementById('signal-clue');
  clue.hidden = false;
  clue.querySelector('.signal-clue-tag').textContent = 'CASE ' + current.id.padStart(3,'0') + ' / FICTIONAL FIELD NOTE';
  const lines = clue.querySelector('.signal-lines');
  lines.replaceChildren(...current.lines.map(text => {
    const span = document.createElement('span');
    const b = document.createElement('b');
    b.textContent = text[0]; span.append(b, text.slice(1)); return span;
  }));
  clue.querySelector('a').href = '/signal/?trace=1&case=' + current.id;
  const heading = document.querySelector('h1');
  heading.textContent = 'The trace ends here.';
  heading.dataset.t = 'The trace ends here.';
  document.getElementById('sub').textContent = 'Clue-only view. The privacy demonstration is paused.';
  const link = document.createElement('a');
  link.href = '/unmask/';
  link.textContent = 'Leave the puzzle and open the browser-privacy demo →';
  document.getElementById('stage').appendChild(link);
})();
