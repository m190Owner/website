// Casual anti-tamper deterrent for the main site. Blocks right-click and the
// common dev-tools / view-source keyboard shortcuts.
//
// NOTE: this CANNOT truly stop inspection — a browser page has no way to block
// the browser's own menu, view-source:, a proxy, or running with JS disabled.
// It only slows casual snooping.
(function () {
  document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  document.addEventListener('keydown', function (e) {
    var k = (e.key || '').toLowerCase();
    var mod = e.ctrlKey || e.metaKey;
    if (k === 'f12' ||
        (mod && (e.shiftKey || e.altKey) && (k === 'i' || k === 'j' || k === 'c')) ||
        (mod && k === 'u')) {
      e.preventDefault();
    }
  });
})();
