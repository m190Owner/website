(() => {
  'use strict';
  const params = new URLSearchParams(location.search);
  if (params.get('recovered') !== '1') return;
  const current = window.SignalCases.find(c => c.id === params.get('case')) || window.SignalCases[0];
  document.getElementById('locked').hidden = true;
  document.getElementById('recovered').hidden = false;
  document.querySelector('.intro .eyebrow').textContent = 'Case ' + current.id.padStart(3,'0') + ' / The hidden archive';
  document.querySelector('.badge small').textContent = 'CASE ' + current.id.padStart(3,'0') + ' / ' + current.callsign + ' RECOVERED';
})();
