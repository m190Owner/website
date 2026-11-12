(() => {
  'use strict';
  const key = 'm190.signal.completed';
  const read = () => {
    try {
      const raw = JSON.parse(localStorage.getItem(key) || '[]');
      return Array.isArray(raw) ? [...new Set(raw.filter(id => typeof id === 'string' && window.SignalCases.some(c => c.id === id)))] : [];
    } catch (_) { return []; }
  };
  const available = () => {
    try {
      const probe = key + '.probe';
      localStorage.setItem(probe, '1'); localStorage.removeItem(probe); return true;
    } catch (_) { return false; }
  };
  window.SignalProgress = {
    read, available,
    complete(id) {
      if (!window.SignalCases.some(c => c.id === id)) return false;
      try {
        localStorage.setItem(key, JSON.stringify([...new Set([...read(), id])]));
        return true;
      } catch (_) { return false; }
    }
  };
})();
