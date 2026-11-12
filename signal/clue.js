(() => {
  'use strict';
  if (new URLSearchParams(location.search).get('signal') !== '01') return;
  document.getElementById('signal-clue').hidden = false;
  const heading = document.querySelector('h1');
  heading.textContent = 'The trace ends here.';
  heading.dataset.t = 'The trace ends here.';
  document.getElementById('sub').textContent = 'Clue-only view. The privacy demonstration is paused.';
  const link = document.createElement('a');
  link.href = '/unmask/';
  link.textContent = 'Leave the puzzle and open the browser-privacy demo →';
  document.getElementById('stage').appendChild(link);
})();
