(() => {
  'use strict';
  let recovered = new URLSearchParams(location.search).get('recovered') === '1';
  try { recovered = recovered || localStorage.getItem('m190.signal01.stage') === '3'; } catch (_) {}
  if (!recovered) return;
  document.getElementById('locked').hidden = true;
  document.getElementById('recovered').hidden = false;
})();
