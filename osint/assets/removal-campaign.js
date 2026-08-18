// Removal campaign: track the legal deadline on each pending opt-out. Sent date comes
// from the server (when the item was marked pending); the horizon (CCPA 45 / GDPR 30) is
// a browser-side choice. Overdue items get an escalation template + complaint links.
(function () {
  var panel = document.getElementById('os-rc-panel');
  if (!panel) return;
  var rows = Array.prototype.slice.call(panel.querySelectorAll('[data-rc]'));
  if (!rows.length) return;
  var horizonEl = document.getElementById('os-rc-horizon');
  horizonEl.value = localStorage.getItem('os_rc_horizon') || '45';
  var DAY = 86400000;
  function esc(s) { return String(s == null ? '' : s).replace(/[<>&"]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]; }); }
  function fmt(d) { return d.toISOString().slice(0, 10); }

  function escalation(name) {
    var body = 'Follow-up: my data-deletion request to ' + name + '\n\n'
      + 'On [date] I submitted a request to delete my personal information and opt out of its sale. '
      + 'The statutory deadline to comply has now passed. This is a formal follow-up requiring confirmation of deletion within 10 business days. '
      + 'If I do not receive confirmation, I will file a complaint with the relevant regulator.\n\nName: [your name]\nRequest reference: [if any]';
    return '<div class="os-warn-box" style="margin-top:0">Past the deadline. Send a firm follow-up, then escalate to a regulator if ignored.</div>'
      + '<pre class="os-phish-body" style="margin-top:8px">' + esc(body) + '</pre>'
      + '<div class="os-inrow" style="margin-top:6px"><button type="button" class="os-btn os-btn-sm os-rc-copy">Copy follow-up</button></div>'
      + '<div class="os-subhead">File a complaint</div><div class="os-srch">'
      + '<a href="https://oag.ca.gov/contact/consumer-complaint-against-business-or-company" target="_blank" rel="noopener nofollow">California AG (CCPA)</a>'
      + '<a href="https://reportfraud.ftc.gov/" target="_blank" rel="noopener nofollow">FTC</a>'
      + '<a href="https://edpb.europa.eu/about-edpb/about-edpb/members_en" target="_blank" rel="noopener nofollow">EU DPA (GDPR)</a></div>';
  }

  function render() {
    var horizon = parseInt(horizonEl.value, 10) || 45;
    var inflight = 0, dueSoon = 0, overdue = 0, now = Date.now();
    rows.forEach(function (row) {
      var sent = parseInt(row.getAttribute('data-sent'), 10) * 1000;
      var due = sent + horizon * DAY;
      var daysLeft = Math.ceil((due - now) / DAY);
      var badge = row.querySelector('.os-rc-badge'), meta = row.querySelector('.os-rc-meta');
      var escBtn = row.querySelector('.os-rc-escbtn'), escBox = row.querySelector('.os-rc-esc');
      meta.textContent = 'Sent ' + fmt(new Date(sent)) + ' · due ' + fmt(new Date(due));
      inflight++;
      if (daysLeft < 0) {
        overdue++;
        badge.className = 'os-rc-badge os-tag os-tag-hi'; badge.textContent = 'OVERDUE ' + (-daysLeft) + 'd';
        escBtn.hidden = false;
      } else if (daysLeft <= 7) {
        dueSoon++;
        badge.className = 'os-rc-badge os-tag'; badge.style.color = 'var(--os-warn)'; badge.textContent = 'due in ' + daysLeft + 'd';
        escBtn.hidden = true; escBox.hidden = true;
      } else {
        badge.className = 'os-rc-badge os-tag'; badge.style.color = ''; badge.textContent = daysLeft + 'd left';
        escBtn.hidden = true; escBox.hidden = true;
      }
      if (!escBtn.__wired) {
        escBtn.__wired = true;
        escBtn.addEventListener('click', function () {
          if (escBox.hidden) { escBox.innerHTML = escalation(row.getAttribute('data-name')); escBox.hidden = false;
            var cp = escBox.querySelector('.os-rc-copy');
            if (cp) cp.addEventListener('click', function () { var t = escBox.querySelector('.os-phish-body').textContent; if (navigator.clipboard) navigator.clipboard.writeText(t); cp.textContent = 'Copied'; });
          } else escBox.hidden = true;
        });
      }
    });
    var sum = document.getElementById('os-rc-summary');
    sum.innerHTML = '<span class="os-pill"><b>' + inflight + '</b> in flight</span>'
      + '<span class="os-pill' + (dueSoon ? ' os-pill-warn' : '') + '"><b>' + dueSoon + '</b> due soon</span>'
      + '<span class="os-pill' + (overdue ? ' os-pill-bad' : '') + '"><b>' + overdue + '</b> overdue</span>';
  }
  horizonEl.addEventListener('change', function () { localStorage.setItem('os_rc_horizon', horizonEl.value); render(); });
  render();
})();
