// Upload page client logic: pre-flight validation, auto-thumbnail capture from a
// video frame, and an XHR upload with a progress bar. The server re-validates
// everything — this is only for fast feedback and to generate the thumbnail.
(function () {
  const L = window.VIDEO_LIMITS || { maxBytes: 1e12, maxDuration: 1e9, maxHuman: '' };
  const form = document.getElementById('upload-form');
  const fVideo = document.getElementById('f-video');
  const fDuration = document.getElementById('f-duration');
  const fThumb = document.getElementById('f-thumb');
  const fThumbCustom = document.getElementById('f-thumbcustom');
  const thumbWrap = document.getElementById('f-thumbwrap');
  const thumbPrev = document.getElementById('f-thumbprev');
  const fileInfo = document.getElementById('f-fileinfo');
  const progress = document.getElementById('f-progress');
  const bar = document.getElementById('f-progress-bar');
  const submit = document.getElementById('f-submit');
  if (!form) return;

  function fail(msg) { alert(msg); }

  function setThumbFile(blob, name) {
    try {
      const dt = new DataTransfer();
      dt.items.add(new File([blob], name, { type: blob.type }));
      fThumb.files = dt.files;
    } catch (_) { /* DataTransfer unsupported — server just gets no thumb */ }
  }

  fVideo.addEventListener('change', function () {
    const file = fVideo.files[0];
    thumbWrap.hidden = true;
    fileInfo.textContent = '';
    fDuration.value = '0';
    if (!file) return;

    if (file.size > L.maxBytes) {
      fail('That file is ' + fmt(file.size) + ' — the limit is ' + L.maxHuman + '.');
      fVideo.value = '';
      return;
    }

    const url = URL.createObjectURL(file);
    const v = document.createElement('video');
    v.preload = 'metadata';
    v.muted = true;

    v.addEventListener('loadedmetadata', function () {
      const dur = Math.round(v.duration) || 0;
      if (dur > L.maxDuration) {
        fail('That video is ' + fmtDur(dur) + ' — the limit is ' + fmtDur(L.maxDuration) + '.');
        fVideo.value = '';
        URL.revokeObjectURL(url);
        return;
      }
      fDuration.value = String(dur);
      fileInfo.textContent = file.name + ' · ' + fmt(file.size) + ' · ' + fmtDur(dur);
      // Seek to ~25% (clamped) to grab a representative frame.
      v.currentTime = Math.min(Math.max(0.1, dur * 0.25), Math.max(0.1, v.duration - 0.1));
    });

    v.addEventListener('seeked', function () {
      try {
        const scale = Math.min(1, 640 / (v.videoWidth || 640));
        const c = document.createElement('canvas');
        c.width = Math.max(1, Math.round((v.videoWidth || 640) * scale));
        c.height = Math.max(1, Math.round((v.videoHeight || 360) * scale));
        c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
        c.toBlob(function (blob) {
          if (blob && !fThumbCustom.files.length) {
            setThumbFile(blob, 'thumb.jpg');
            thumbPrev.src = URL.createObjectURL(blob);
            thumbWrap.hidden = false;
          }
          URL.revokeObjectURL(url);
        }, 'image/jpeg', 0.82);
      } catch (_) { URL.revokeObjectURL(url); }
    });

    v.addEventListener('error', function () { URL.revokeObjectURL(url); });
    v.src = url;
  });

  // Custom thumbnail overrides the auto-captured frame.
  fThumbCustom.addEventListener('change', function () {
    const img = fThumbCustom.files[0];
    if (!img) return;
    if (img.size > 2 * 1024 * 1024) { fail('Thumbnail must be under 2 MB.'); fThumbCustom.value = ''; return; }
    setThumbFile(img, img.name || 'thumb');
    thumbPrev.src = URL.createObjectURL(img);
    thumbWrap.hidden = false;
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!fVideo.files.length) { fail('Choose a video first.'); return; }
    submit.disabled = true;
    submit.textContent = 'Uploading…';
    progress.hidden = false;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.getAttribute('action') || location.href);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.upload.addEventListener('progress', function (ev) {
      if (ev.lengthComputable) bar.style.width = (ev.loaded / ev.total * 100).toFixed(1) + '%';
    });
    xhr.addEventListener('load', function () {
      let data = {};
      try { data = JSON.parse(xhr.responseText); } catch (_) {}
      if (xhr.status >= 200 && xhr.status < 300 && data.ok) {
        window.location = data.redirect || '/videos/';
      } else {
        fail(data.error || ('Upload failed (' + xhr.status + ').'));
        submit.disabled = false;
        submit.textContent = 'Publish';
        progress.hidden = true;
        bar.style.width = '0';
      }
    });
    xhr.addEventListener('error', function () {
      fail('Network error during upload.');
      submit.disabled = false;
      submit.textContent = 'Publish';
      progress.hidden = true;
    });

    const fd = new FormData(form);
    fd.delete('thumb_custom'); // only the resolved `thumb` file is sent
    xhr.send(fd);
  });

  function fmt(b) {
    const u = ['B', 'KB', 'MB', 'GB']; let i = 0, n = b;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i === 0 ? n : n.toFixed(1)) + ' ' + u[i];
  }
  function fmtDur(s) {
    s = Math.round(s); const m = Math.floor(s / 60), r = s % 60;
    return m + ':' + String(r).padStart(2, '0');
  }
})();
