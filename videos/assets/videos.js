// Small progressive enhancements. Everything works without this file.
(function () {
  // Drop the ?flash= param from the URL so a refresh doesn't re-show the banner.
  try {
    const url = new URL(location.href);
    if (url.searchParams.has('flash')) {
      url.searchParams.delete('flash');
      history.replaceState(null, '', url.pathname + url.search + url.hash);
    }
  } catch (_) {}

  // Close an open report popover when clicking elsewhere.
  document.addEventListener('click', function (e) {
    document.querySelectorAll('details.v-report[open]').forEach(function (d) {
      if (!d.contains(e.target)) d.removeAttribute('open');
    });
  });
})();
