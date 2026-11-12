(() => {
  'use strict';
  const card = document.getElementById('signal-invitation');
  const dismiss = document.getElementById('signal-dismiss');
  if (!card || !dismiss) return;
  dismiss.hidden = false;
  try { card.hidden = sessionStorage.getItem('m190.signal.dismissed') === '1'; } catch (_) {}
  dismiss.addEventListener('click', () => {
    card.hidden = true;
    try { sessionStorage.setItem('m190.signal.dismissed', '1'); } catch (_) {}
  });
})();
