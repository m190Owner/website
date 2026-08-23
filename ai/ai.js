// m190 AI — a real LLM running 100% in the visitor's browser on their GPU via WebLLM
// (WebGPU). Nothing typed here ever leaves the machine; only the model weights stream
// in (once, then cached). Vendored lib in ./lib/web-llm.js; weights from the MLC/HF CDN.
import * as webllm from './lib/web-llm.js';

const MODELS = {
  fast:  { id: 'Llama-3.2-1B-Instruct-q4f16_1-MLC', label: 'Llama 3.2 1B', size: '~0.9 GB' },
  smart: { id: 'Llama-3.2-3B-Instruct-q4f16_1-MLC', label: 'Llama 3.2 3B', size: '~1.9 GB' },
};
const SYSTEM =
  "You are m190 AI, the resident assistant on ServerLagger's site (logansandivar.com), " +
  "built by Logan Sandivar — a security engineer and pentester. Voice: sharp, a little " +
  "hacker-terminal, concise, no corporate fluff. You run entirely inside the visitor's " +
  "browser on their own GPU via WebGPU — there is no server and nothing they type ever " +
  "leaves their machine; say so plainly if asked. You are a small local model, so be honest " +
  "about your limits and don't invent facts. You lean toward security, privacy, OSINT and " +
  "dev topics but will answer anything. Keep replies tight; use fenced code blocks for code.";
const STARTERS = [
  "How are you running without a server?",
  "nmap one-liner to find live hosts on a /24",
  "Explain browser fingerprinting simply",
  "Give me a strong passphrase scheme",
];

const $ = (s) => document.querySelector(s);
const inner = $('#inner'), composer = $('#composer'), input = $('#input'), sendBtn = $('#send');
const badge = $('#badge'), picker = $('#picker');

let engine = null, curKey = null, busy = false, loading = false;
let messages = [{ role: 'system', content: SYSTEM }];

function esc(s) { return String(s == null ? '' : s).replace(/[<>&]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]; }); }
function md(t) {
  t = esc(t);
  t = t.replace(/```(\w*)\n?([\s\S]*?)```/g, function (_, l, code) { return '<pre><code>' + code.replace(/\n$/, '') + '</code></pre>'; });
  t = t.replace(/`([^`\n]+)`/g, '<code>$1</code>');
  return t;
}
function scrollDown() { var w = $('.wrap'); w.scrollTop = w.scrollHeight; }

function addMsg(who, html) {
  var log = $('#log'); if (!log) return null;
  var m = document.createElement('div'); m.className = 'msg ' + who;
  m.innerHTML = '<div class="who">' + (who === 'user' ? 'you' : 'm190') + '</div><div class="body">' + html + '</div>';
  log.appendChild(m); scrollDown(); return m;
}

// ---- WebGPU gate ----
async function hasWebGPU() {
  if (!navigator.gpu) return false;
  try { return !!(await navigator.gpu.requestAdapter()); } catch (e) { return false; }
}
function showGate() {
  inner.innerHTML =
    '<div class="gate"><h1>This one needs WebGPU 🧠</h1>' +
    '<p>m190 AI runs a real language model <b>on your own GPU</b>, right here in the tab — no server, nothing you type ever leaves your machine. That needs <b>WebGPU</b>, which your current browser isn\'t exposing.</p>' +
    '<p>Open this in <b>Chrome, Edge, or Arc on a desktop</b> (recent versions) and it\'ll spin up an AI locally. On iOS/older browsers it\'s not available yet.</p>' +
    '<p style="color:var(--dim);font-size:.8rem">Why bother? Because an LLM answering with zero network round-trips — and zero data collection — is the whole point.</p></div>';
}

// ---- model load ----
function showLoader(key, firstTime) {
  inner.innerHTML =
    '<div class="loader"><h1>' + (firstTime ? 'Downloading a brain to your GPU…' : 'Switching model…') + '</h1>' +
    '<p>Pulling <b>' + esc(MODELS[key].label) + '</b> (' + MODELS[key].size + ') to your device. This happens <b>once per model</b> — it\'s cached in your browser afterward and even works offline.</p>' +
    '<div class="bar"><i id="pbar"></i></div><div class="ptext" id="ptext">initializing…</div></div>';
}
function setProgress(p, text) {
  var bar = $('#pbar'), t = $('#ptext');
  if (bar) bar.style.width = Math.max(2, Math.round((p || 0) * 100)) + '%';
  if (t && text) t.textContent = text;
}

async function loadModel(key) {
  if (loading) return;
  loading = true; input && (input.disabled = true); sendBtn.disabled = true;
  [].forEach.call(picker.querySelectorAll('button'), function (b) { b.disabled = true; b.classList.toggle('on', b.getAttribute('data-m') === key); });
  var firstTime = !engine;
  showLoader(key, firstTime);
  try {
    if (!engine) { engine = new webllm.MLCEngine(); engine.setInitProgressCallback(function (r) { setProgress(r.progress, r.text); }); }
    await engine.reload(MODELS[key].id);
    curKey = key;
    ready();
  } catch (e) {
    inner.innerHTML = '<div class="loader"><h1 style="color:var(--red)">Couldn\'t load that model</h1>' +
      '<p>' + esc(e && e.message ? e.message : String(e)) + '</p>' +
      '<p>' + (key === 'smart' ? 'The 3B model needs a fair bit of GPU memory — try <b>Fast · 1B</b> instead.' : 'Your GPU may not support the required features. Try a desktop Chrome/Edge.') + '</p>' +
      '<p><button class="chip" id="retry">retry</button></p></div>';
    var r = $('#retry'); if (r) r.addEventListener('click', function () { loading = false; loadModel('fast'); });
  } finally {
    loading = false;
    [].forEach.call(picker.querySelectorAll('button'), function (b) { b.disabled = false; });
  }
}

function ready() {
  inner.innerHTML = '<div id="log"></div>';
  addMsg('ai', 'm190 AI online — <b>' + esc(MODELS[curKey].label) + '</b> running on <b>your</b> GPU. No server, no logging; every token is generated on this machine. Ask me anything.');
  var chips = document.createElement('div'); chips.className = 'chips';
  STARTERS.forEach(function (s) { var c = document.createElement('button'); c.className = 'chip'; c.textContent = s; c.addEventListener('click', function () { chips.remove(); send(s); }); chips.appendChild(c); });
  $('#log').appendChild(chips);
  composer.hidden = false; input.disabled = false; sendBtn.disabled = false; input.focus();
}

// ---- chat ----
async function send(text) {
  text = (text || input.value).trim();
  if (!text || busy || loading || !engine) return;
  busy = true; sendBtn.disabled = true; input.value = ''; autosize();
  var chips = $('.chips'); if (chips) chips.remove();
  addMsg('user', esc(text));
  messages.push({ role: 'user', content: text });
  var aiEl = addMsg('ai', ''); var body = aiEl.querySelector('.body'); body.classList.add('cursor');
  try {
    var reply = '';
    var stream = await engine.chat.completions.create({ messages: messages, stream: true, temperature: 0.7, max_tokens: 800 });
    for await (var chunk of stream) {
      var d = (chunk.choices && chunk.choices[0] && chunk.choices[0].delta && chunk.choices[0].delta.content) || '';
      if (d) { reply += d; body.innerHTML = md(reply); scrollDown(); }
    }
    body.classList.remove('cursor');
    if (!reply) body.innerHTML = '<span style="color:var(--dim)">(no output)</span>';
    messages.push({ role: 'assistant', content: reply });
  } catch (e) {
    body.classList.remove('cursor');
    body.innerHTML = '<span style="color:var(--red)">error: ' + esc(e && e.message ? e.message : String(e)) + '</span>';
    messages.pop();   // drop the user turn that failed so history stays valid
  } finally {
    busy = false; sendBtn.disabled = false; input.focus();
  }
}

function autosize() { input.style.height = 'auto'; input.style.height = Math.min(160, input.scrollHeight) + 'px'; }

// ---- wire up ----
input.addEventListener('input', autosize);
input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
sendBtn.addEventListener('click', function () { send(); });
[].forEach.call(picker.querySelectorAll('button'), function (b) {
  b.addEventListener('click', function () { var k = b.getAttribute('data-m'); if (k !== curKey && !loading) loadModel(k); });
});

(async function boot() {
  if (!(await hasWebGPU())) { showGate(); return; }
  badge.hidden = false; picker.hidden = false;
  loadModel('fast');
})();
