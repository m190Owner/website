(() => {
  'use strict';
  const params = new URLSearchParams(location.search);
  if (params.get('recovered') !== '1') return;
  const current = window.SignalCases.find(c => c.id === params.get('case')) || window.SignalCases[0];
  document.getElementById('collection-status').textContent = window.SignalProgress.read().includes(current.id) ? 'Added to your completed cases in this browser.' : 'This completion was not saved to your library. Browser storage may be unavailable, or this is an older result link.';
  document.getElementById('locked').hidden = true;
  document.getElementById('recovered').hidden = false;
  document.querySelector('.intro .eyebrow').textContent = 'Case ' + current.id.padStart(3,'0') + ' / The hidden archive';
  if (current.resolution) document.querySelector('#recovered h2 + p').textContent = current.resolution;
  document.querySelector('.badge small').textContent = 'CASE ' + current.id.padStart(3,'0') + ' / ' + current.callsign + ' RECOVERED';
})();
