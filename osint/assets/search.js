// Reverse-image search: turn a pasted image URL into ready-made lookups. Client-side.
(function () {
  var form = document.getElementById('os-rev');
  if (!form) return;
  var inp = document.getElementById('os-revurl');
  var out = document.getElementById('os-revout');

  function build() {
    var u = inp.value.trim();
    if (!/^https?:\/\//i.test(u)) { out.innerHTML = '<span class="os-dim" style="font-size:.82rem">Paste a direct image URL starting with http.</span>'; return; }
    var e = encodeURIComponent(u);
    var links = [
      ['Google Lens', 'https://lens.google.com/uploadbyurl?url=' + e, '🔍'],
      ['Yandex Images', 'https://yandex.com/images/search?rpt=imageview&url=' + e, 'Я'],
      ['TinEye', 'https://tineye.com/search?url=' + e, '👁️'],
      ['Bing Visual', 'https://www.bing.com/images/search?view=detailv2&iss=sbi&q=imgurl:' + e, 'Ⓑ']
    ];
    out.innerHTML = links.map(function (l) {
      return '<a href="' + l[1] + '" target="_blank" rel="noopener nofollow"><span class="os-srch-ic">' + l[2] + '</span>' + l[0] + '</a>';
    }).join('');
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); build(); });
  inp.addEventListener('input', function () { if (inp.value.trim()) build(); });
})();
