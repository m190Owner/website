// Opt-in WebGPU hero background — a raymarched "cyber/data field" that reacts to the
// mouse, the live crowd (#online-count), and the music (getBeatStrength). Pure progressive
// enhancement: the toggle only appears on WebGPU-capable desktops; the video stays the
// default and the fallback. Choice remembered in localStorage; paused when the tab hides.
(function () {
  'use strict';
  var reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var mobile  = matchMedia('(max-width: 768px)').matches || matchMedia('(pointer: coarse)').matches;
  var btn = document.getElementById('fx-toggle');
  if (!navigator.gpu || reduced || mobile || !btn) return;   // keep the video, no toggle

  var WGSL = `
struct Uni { res: vec2f, time: f32, audio: f32, mouse: vec2f, crowd: f32, pad: f32 };
@group(0) @binding(0) var<uniform> u: Uni;
@vertex fn vs(@builtin(vertex_index) i: u32) -> @builtin(position) vec4f {
  var p = array<vec2f,3>(vec2f(-1.,-1.), vec2f(3.,-1.), vec2f(-1.,3.));
  return vec4f(p[i], 0., 1.);
}
fn h31(p: vec3f) -> f32 { return fract(sin(dot(p, vec3f(12.9898, 78.233, 37.719))) * 43758.5453); }
@fragment fn fs(@builtin(position) fc: vec4f) -> @location(0) vec4f {
  let uv = (fc.xy - 0.5 * u.res) / u.res.y;
  let t = u.time;
  let speed = 1.4 + u.crowd * 2.6;
  let ro = vec3f(0.0, 0.0, t * speed);
  let rd = normalize(vec3f(uv + u.mouse * 0.18, 1.0));
  var col = vec3f(0.0);
  for (var i = 0; i < 46; i = i + 1) {
    let march = f32(i) * 0.17;
    let pos = ro + rd * march;
    let cell = fract(pos * 0.5) - 0.5;
    let g = min(abs(cell.x), abs(cell.y));
    let line = smoothstep(0.055, 0.0, g);
    let id = floor(pos * 0.5);
    let flick = step(0.62, fract(h31(id) + t * 1.7));
    let glow = line * (0.35 + 0.65 * flick);
    let fog = exp(-march * 0.13);
    let c = mix(vec3f(0.13, 0.34, 1.0), vec3f(0.16, 1.0, 0.62), clamp(flick * 0.8 + march * 0.02, 0.0, 1.0));
    col = col + c * glow * fog * 0.055;
  }
  col = col * (1.0 + u.audio * 1.3);
  col = col * (0.9 + 0.1 * sin(fc.y * 1.5 + t * 2.0));            // scanlines
  col = col * (1.0 - 0.42 * dot(uv, uv));                         // vignette
  col = col + vec3f(0.015, 0.03, 0.075);                          // base tint
  return vec4f(col, 1.0);
}`;

  var canvas = document.getElementById('hero-gpu');
  var video = document.getElementById('bg-video');
  var gpu = null, raf = 0, active = false, initing = false, start = performance.now();
  var mx = 0, my = 0, tmx = 0, tmy = 0;
  var data = new Float32Array(8);

  addEventListener('mousemove', function (e) { tmx = (e.clientX / innerWidth) * 2 - 1; tmy = -((e.clientY / innerHeight) * 2 - 1); });

  function sizeCanvas() {
    var dpr = Math.min(1.25, window.devicePixelRatio || 1);
    canvas.width = Math.floor(innerWidth * dpr); canvas.height = Math.floor(innerHeight * dpr);
  }
  function crowd() { var n = parseInt((document.getElementById('online-count') || {}).textContent, 10); return isFinite(n) ? Math.min(1, n / 18) : 0.2; }
  function audio() { try { return typeof getBeatStrength === 'function' ? Math.min(1, getBeatStrength() / 190) : 0; } catch (e) { return 0; } }

  async function initGPU() {
    var adapter = await navigator.gpu.requestAdapter();
    if (!adapter) throw new Error('no adapter');
    var device = await adapter.requestDevice();
    var ctx = canvas.getContext('webgpu');
    var format = navigator.gpu.getPreferredCanvasFormat();
    ctx.configure({ device: device, format: format, alphaMode: 'opaque' });
    var mod = device.createShaderModule({ code: WGSL });
    var pipeline = device.createRenderPipeline({
      layout: 'auto',
      vertex: { module: mod, entryPoint: 'vs' },
      fragment: { module: mod, entryPoint: 'fs', targets: [{ format: format }] },
      primitive: { topology: 'triangle-list' },
    });
    var uni = device.createBuffer({ size: 32, usage: GPUBufferUsage.UNIFORM | GPUBufferUsage.COPY_DST });
    var bind = device.createBindGroup({ layout: pipeline.getBindGroupLayout(0), entries: [{ binding: 0, resource: { buffer: uni } }] });
    device.lost.then(function () { deactivate(true); });
    return { device: device, ctx: ctx, pipeline: pipeline, uni: uni, bind: bind };
  }

  function frame(now) {
    if (!active) return;
    raf = requestAnimationFrame(frame);
    if (document.hidden) return;
    mx += (tmx - mx) * 0.05; my += (tmy - my) * 0.05;
    data[0] = canvas.width; data[1] = canvas.height; data[2] = (now - start) / 1000;
    data[3] = audio(); data[4] = mx; data[5] = my; data[6] = crowd(); data[7] = 0;
    gpu.device.queue.writeBuffer(gpu.uni, 0, data);
    var enc = gpu.device.createCommandEncoder();
    var pass = enc.beginRenderPass({ colorAttachments: [{ view: gpu.ctx.getCurrentTexture().createView(), clearValue: { r: 0, g: 0, b: 0, a: 1 }, loadOp: 'clear', storeOp: 'store' }] });
    pass.setPipeline(gpu.pipeline); pass.setBindGroup(0, gpu.bind); pass.draw(3); pass.end();
    gpu.device.queue.submit([enc.finish()]);
  }

  async function activate() {
    if (active || initing) return;
    initing = true;
    try {
      sizeCanvas();
      if (!gpu) gpu = await initGPU();
      active = true; canvas.classList.add('on');
      if (video) video.style.visibility = 'hidden';
      start = performance.now(); raf = requestAnimationFrame(frame);
      setBtn(true);
    } catch (e) { active = false; gpu = null; if (video) video.style.visibility = ''; canvas.classList.remove('on'); setBtn(false); }
    finally { initing = false; }
  }
  function deactivate(failed) {
    active = false; cancelAnimationFrame(raf);
    canvas.classList.remove('on');
    if (video) video.style.visibility = '';
    setBtn(false);
    if (failed) gpu = null;
  }
  function setBtn(on) { btn.classList.toggle('on', on); btn.title = on ? 'Background: cyber field (click for video)' : 'Background: video (click for cyber field)'; }

  addEventListener('resize', function () { if (active) sizeCanvas(); });

  var pref = 'video'; try { pref = localStorage.getItem('hero_fx') || 'video'; } catch (e) {}
  btn.hidden = false;                 // WebGPU is available -> offer the toggle
  setBtn(false);
  btn.addEventListener('click', function () {
    if (active) { pref = 'video'; deactivate(); }
    else { pref = 'cyber'; activate(); }
    try { localStorage.setItem('hero_fx', pref); } catch (e) {}
  });
  if (pref === 'cyber') activate();
})();
