(function () {
  'use strict';
  var csrf = (document.querySelector('meta[name=osint-csrf]') || {}).content || '';
  var filter = 'all';

  function applyStatus(card, status) {
    card.setAttribute('data-status', status);
    card.className = card.className.replace(/\bos-st-\w+\b/g, '').replace(/\s+/g, ' ').trim() + ' os-st-' + status;
    var btns = card.querySelectorAll('.os-triage button');
    for (var i = 0; i < btns.length; i++) btns[i].classList.toggle('on', btns[i].getAttribute('data-set') === status);
  }

  function recount() {
    var counts = { all: 0, attention: 0, 'new': 0, 'false': 0, done: 0 };
    var cards = document.querySelectorAll('.os-fcard');
    for (var i = 0; i < cards.length; i++) { counts.all++; var s = cards[i].getAttribute('data-status') || 'new'; if (counts[s] !== undefined) counts[s]++; }
    var chips = document.querySelectorAll('.os-chip');
    for (var j = 0; j < chips.length; j++) {
      var k = chips[j].getAttribute('data-filter'), n = chips[j].querySelector('.n');
      if (n) n.textContent = counts[k] !== undefined ? counts[k] : counts.all;
    }
    applyFilter();
  }

  function applyFilter() {
    var cards = document.querySelectorAll('.os-fcard');
    for (var i = 0; i < cards.length; i++) {
      var s = cards[i].getAttribute('data-status') || 'new';
      cards[i].style.display = (filter === 'all' || s === filter) ? '' : 'none';
    }
    // hide a section whose cards are all filtered out
    var panels = document.querySelectorAll('.os-cardgrid, .os-breachlist');
    for (var p = 0; p < panels.length; p++) {
      var any = panels[p].querySelector('.os-fcard:not([style*="display: none"])');
      var panel = panels[p].closest('.os-panel');
      if (panel) panel.style.display = any ? '' : (filter === 'all' ? '' : 'none');
    }
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('.os-triage button');
    if (b) {
      e.preventDefault();
      var card = b.closest('.os-fcard'), fid = card.getAttribute('data-fid');
      var set = b.getAttribute('data-set'), cur = card.getAttribute('data-status') || 'new';
      var next = (cur === set) ? 'new' : set;                 // click the active one again to clear
      applyStatus(card, next); recount();                     // optimistic
      fetch('/osint/finding.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ id: fid, status: next, csrf: csrf })
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res || !res.ok) { applyStatus(card, cur); recount(); }   // revert on failure
      }).catch(function () { applyStatus(card, cur); recount(); });
      return;
    }
    var ch = e.target.closest('.os-chip');
    if (ch) {
      filter = ch.getAttribute('data-filter');
      var chips = document.querySelectorAll('.os-chip');
      for (var i = 0; i < chips.length; i++) chips[i].classList.toggle('on', chips[i] === ch);
      applyFilter();
    }
  });

  // reflect initial statuses on the triage buttons + counts
  var cards = document.querySelectorAll('.os-fcard');
  for (var i = 0; i < cards.length; i++) applyStatus(cards[i], cards[i].getAttribute('data-status') || 'new');
  recount();
})();
