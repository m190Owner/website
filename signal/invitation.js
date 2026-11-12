(() => {
  'use strict';
  const card = document.getElementById('signal-invitation');
  const dismiss = document.getElementById('signal-dismiss');
  if (!card || !dismiss) return;
  const nav = performance.getEntriesByType('navigation')[0];
  let internal = false;
  try { internal = Boolean(document.referrer) && new URL(document.referrer).origin === location.origin; } catch (_) {}
  let seen = false;
  try {
    seen = sessionStorage.getItem('m190.signal.invitationSeen') === '1' ||
      sessionStorage.getItem('m190.signal.dismissed') === '1' ||
      Boolean(sessionStorage.getItem('m190.signal.active'));
    sessionStorage.setItem('m190.signal.invitationSeen', '1');
  } catch (_) {}
  card.hidden = seen || internal || Boolean(nav && nav.type !== 'navigate');
  dismiss.hidden = false;
  dismiss.addEventListener('click', () => {
    card.hidden = true;
    try { sessionStorage.setItem('m190.signal.dismissed', '1'); } catch (_) {}
  });
  // A cached homepage reached through Back must not resurrect its invitation.
  window.addEventListener('pageshow', event => { if (event.persisted) card.hidden = true; });
})();
