// Copy-to-clipboard for the takedown templates. Client-side only.
(function () {
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var ta = document.getElementById(btn.getAttribute('data-copy'));
      if (!ta) return;
      var done = function () { var t = btn.textContent; btn.textContent = 'Copied ✓'; setTimeout(function () { btn.textContent = t; }, 1600); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(ta.value).then(done, function () { ta.select(); document.execCommand('copy'); done(); });
      } else {
        ta.select(); document.execCommand('copy'); done();
      }
    });
  });
})();
