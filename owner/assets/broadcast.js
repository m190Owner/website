let token = document.querySelector('meta[name="csrf-token"]').content, pending = false, connectionError = false;
let uploading = false, clipSignature = '', clips = [];
let uploadCommand = null;
const $ = id => document.getElementById(id);
function remote(route, options = {}) {
  const url = new URL('/owner/broadcast-api.php', location.origin);
  url.search = route.replace('/api/', '?action=');
  return fetch(url, { ...options, credentials: 'same-origin', headers: { ...options.headers, 'X-M190-CSRF': token } });
}
async function post(action, body) {
  const response = await remote('/api/' + action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  const result = await response.json();
  if (!response.ok) throw new Error(result.error);
  return result;
}
const time = s => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
function el(tag, text, cls) {
  const e = document.createElement(tag);
  if (text !== undefined) e.textContent = text;
  if (cls) e.className = cls;
  return e;
}
async function send(route, data) {
  if (pending) return;
  pending = true;
  $('notice').textContent = 'Sending command and waiting for the player…';
  document.querySelectorAll('button').forEach(b => b.disabled = true);
  try {
    const response = await remote(route, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error);
    $('notice').textContent = '';
  } catch (error) { $('notice').textContent = error.message; }
  finally { pending = false; await refresh(); }
}
async function stopBroadcast(id, button) {
  button.disabled = true;
  try {
    const response = await remote('/api/stop', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error);
  } catch (error) { $('notice').textContent = error.message; }
  await refresh();
}
function selectedClip() {
  return clips.find(c => c.id === $('clip-select').value);
}
$('clip-select').onchange = () => {
  localStorage.setItem('m190-clip', $('clip-select').value);
  $('duration').textContent = selectedClip()?.duration ?? '—';
};
$('upload-form').onsubmit = async event => {
  event.preventDefault();
  const file = $('clip-file').files[0];
  if (!file || uploading) return;
  if (file.size > 250 * 1024 * 1024) { $('upload-status').textContent = 'Choose a file smaller than 250 MB.'; return; }
  uploading = true;
  $('upload').disabled = true;
  $('upload-status').textContent = 'Uploading and preparing your clip…';
  try {
    const upload = await post('upload-begin', { name: file.name, size: file.size });
    for (let offset = 0; offset < file.size; offset += 4 * 1024 * 1024) {
      const chunk = file.slice(offset, offset + 4 * 1024 * 1024);
      const response = await remote(`/api/upload-chunk&id=${upload.id}&offset=${offset}`, { method: 'POST', headers: { 'Content-Type': 'application/octet-stream' }, body: chunk });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error);
      $('upload-status').textContent = `Uploading… ${Math.round(result.received / file.size * 100)}%`;
    }
    const result = await post('upload-finish', { id: upload.id });
    uploadCommand = result.commandId;
    $('upload-status').textContent = 'Upload complete. Preparing the clip on your media server…';
    $('clip-file').value = '';
  } catch (error) { $('upload-status').textContent = error.message; }
  finally { uploading = false; $('upload').disabled = false; await refresh(); }
};
async function refresh() {
  try {
    const response = await remote('/api/status');
    const data = await response.json();
    if (!response.ok) throw new Error(data.error);
    if (connectionError) { $('notice').textContent = ''; connectionError = false; }
    $('connection').textContent = data.online ? '● CONTROLLER ONLINE' : 'CONTROLLER OFFLINE';
    if (!data.online) $('notice').textContent = data.error || 'Waiting for your media server to connect. Controls unlock when it is online.';
    else if ($('notice').textContent.startsWith('Waiting for your media server')) $('notice').textContent = '';
    $('commands').replaceChildren(...(data.commands || []).slice(0, 5).map(c => el('p', `${c.action} · ${c.status}${c.result ? ' · ' + c.result : ''}`)));
    const uploaded = data.commands?.find(c => c.id === uploadCommand);
    if (uploaded && ['done','failed','expired','uncertain'].includes(uploaded.status)) {
      $('upload-status').textContent = uploaded.result || uploaded.status;
      uploadCommand = null;
    }
    clips = data.clips;
    const signature = JSON.stringify(clips);
    if (signature !== clipSignature) {
      const selected = localStorage.getItem('m190-clip') || $('clip-select').value;
      $('clip-select').replaceChildren(...clips.map(c => { const option = el('option', c.name); option.value = c.id; return option; }));
      if (clips.some(c => c.id === selected)) $('clip-select').value = selected;
      clipSignature = signature;
    }
    $('duration').textContent = selectedClip()?.duration ?? '—';
    $('upload').disabled = uploading || data.uploading || !data.online || !!uploadCommand;
    $('viewers').replaceChildren();
    if (!data.sessions.length) {
      const empty = el('div', undefined, 'empty');
      empty.append(el('strong', 'The airwaves are quiet.'), el('p', 'Start a movie in Jellyfin, then return here. Connected viewers appear automatically.'));
      $('viewers').append(empty);
    }
    for (const s of data.sessions) {
      const card = el('article', undefined, 'viewer');
      card.append(el('h3', s.user || 'Viewer'), el('p', `${s.device} · ${s.client}`, 'meta'), el('p', `${s.title} · ${time(s.position)}`, 'title'));
      if (s.reason) card.append(el('p', s.reason, 'reason'));
      const button = el('button', 'Interrupt this viewer');
      button.disabled = !!s.reason || pending || !selectedClip() || !data.online;
      button.onclick = () => send('/api/interrupt', { sessionId: s.id, itemId: s.itemId, clipId: selectedClip()?.id });
      card.append(button);
      $('viewers').append(card);
    }
    $('jobs').replaceChildren();
    if (!data.jobs.length) $('jobs').append(el('p', 'No broadcasts yet.', 'note'));
    for (const job of data.jobs) {
      const card = el('article', undefined, 'job');
      card.append(el('strong', `${job.user} · ${job.title}`), el('span', job.status, 'tag'), el('p', job.message), el('p', `${job.device} · Saved position ${time(job.position)}`, 'meta'));
      if (job.clipName) card.append(el('p', `Clip: ${job.clipName}`, 'meta'));
      if (job.canStop) {
        const stop = el('button', 'Stop broadcast', 'stop');
        stop.disabled = !data.online;
        stop.onclick = () => stopBroadcast(job.id, stop);
        card.append(stop);
      } else if (job.status !== 'complete') {
        const restore = el('button', 'Restore movie', 'quiet');
        restore.disabled = job.busy || pending || !data.online;
        restore.onclick = () => send('/api/restore', { id: job.id });
        card.append(restore);
      }
      $('jobs').append(card);
    }
    $('refresh').disabled = pending;
  } catch (error) {
    connectionError = true;
    $('connection').textContent = 'CONTROLLER UNAVAILABLE';
    $('notice').textContent = error.message;
    document.querySelectorAll('.viewer button,.job button').forEach(b => b.disabled = true);
  }
}
$('refresh').onclick = refresh;
refresh();
setInterval(refresh, 2500);
