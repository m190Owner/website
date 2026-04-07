// ==============================================
// GRAVITY MODE (G)
// ==============================================
let gravityActive = false;

function activateGravity() {
    gravityActive = true;

    const selectors = [
        '.home-content h1',
        '.links a',
        '.discord-widget',
        '#feedbackButton',
        '#volume-controls',
        'nav strong',
        '.nav-visualizer',
        '.bar-item',
        '#map-link',
        '.bar-sep'
    ];

    const gEls = [];
    selectors.forEach(sel => {
        document.querySelectorAll(sel).forEach(el => {
            const rect = el.getBoundingClientRect();
            const floor = window.innerHeight - rect.bottom;
            gEls.push({
                el,
                y: 0,
                vy: Math.random() * -3,
                rotation: 0,
                rotSpeed: (Math.random() - 0.5) * 8,
                floor: Math.max(10, floor - 5 - Math.random() * 20),
                origTransition: el.style.transition,
                origTransform: el.style.transform
            });
            el.style.transition = 'none';
        });
    });

    const gravity = 0.65;
    const bounce = 0.35;
    const friction = 0.98;
    let settled = 0;

    function gravityFrame() {
        settled = 0;
        gEls.forEach(ge => {
            ge.vy += gravity;
            ge.y += ge.vy;
            ge.rotation += ge.rotSpeed;

            if (ge.y >= ge.floor) {
                ge.y = ge.floor;
                ge.vy *= -bounce;
                ge.rotSpeed *= 0.6;
                if (Math.abs(ge.vy) < 0.8) {
                    ge.vy = 0;
                    settled++;
                }
            }

            ge.rotSpeed *= friction;
            ge.el.style.transform = `translateY(${ge.y}px) rotate(${ge.rotation}deg)`;
        });

        if (settled < gEls.length) {
            requestAnimationFrame(gravityFrame);
        } else {
            setTimeout(() => reassemble(gEls), 1500);
        }
    }

    requestAnimationFrame(gravityFrame);
}

function reassemble(gEls) {
    gEls.forEach(ge => {
        ge.el.style.transition = 'transform 0.9s cubic-bezier(0.2, 0.8, 0.2, 1.15)';
        ge.el.style.transform = ge.origTransform || '';
    });

    setTimeout(() => {
        gEls.forEach(ge => {
            ge.el.style.transition = ge.origTransition || '';
        });
        gravityActive = false;
    }, 1000);
}

// ==============================================
// EARTHQUAKE MODE (E)
// ==============================================
let earthquakeActive = false;
function activateEarthquake() {
    if (earthquakeActive) return;
    earthquakeActive = true;
    const crackOvl = document.createElement('canvas');
    crackOvl.style.cssText = 'position:fixed;inset:0;z-index:9997;pointer-events:none;';
    crackOvl.width = window.innerWidth; crackOvl.height = window.innerHeight;
    document.body.appendChild(crackOvl);
    const cc = crackOvl.getContext('2d');
    for (let c = 0; c < 6; c++) {
        cc.strokeStyle = `rgba(255,255,255,${0.5 + Math.random() * 0.4})`;
        cc.lineWidth = 1.5 + Math.random() * 2;
        cc.shadowColor = '#7aa2ff'; cc.shadowBlur = 12;
        cc.beginPath();
        let cx = Math.random() * crackOvl.width, cy = 0;
        cc.moveTo(cx, cy);
        for (let s = 0; s < 12 + Math.random() * 10; s++) {
            cx += (Math.random() - 0.5) * 80;
            cy += 15 + Math.random() * 40;
            cc.lineTo(cx, cy);
            if (Math.random() < 0.3) {
                cc.stroke(); cc.beginPath(); cc.moveTo(cx, cy);
                let bx = cx, by = cy;
                for (let b = 0; b < 4; b++) { bx += (Math.random()-0.5)*50; by += 10+Math.random()*20; cc.lineTo(bx, by); }
                cc.stroke(); cc.beginPath(); cc.moveTo(cx, cy);
            }
        }
        cc.stroke();
    }
    let frame = 0;
    function quake() {
        const p = frame / 120;
        const intensity = (1 - p) * 30;
        document.body.style.transform = `translate(${(Math.random()-0.5)*intensity}px, ${(Math.random()-0.5)*intensity}px) rotate(${(Math.random()-0.5)*intensity*0.15}deg)`;
        if (p > 0.4) crackOvl.style.opacity = 1 - (p - 0.4) / 0.6;
        frame++;
        if (frame < 120) { requestAnimationFrame(quake); }
        else { document.body.style.transform = ''; crackOvl.remove(); earthquakeActive = false; }
    }
    quake();
}

// ==============================================
// SCREEN MELT (M)
// ==============================================
let meltActive = false;
function activateScreenMelt() {
    if (meltActive) return;
    meltActive = true;
    let frame = 0;
    function melt() {
        frame++;
        const t = frame / 90;
        const drop = t * t * window.innerHeight * 0.6;
        const stretch = 1 + t * 0.4;
        const wave = Math.sin(frame * 0.15) * 10 * t;
        document.body.style.transform = `translateY(${drop}px) scaleY(${stretch}) translateX(${wave}px)`;
        document.body.style.transformOrigin = 'top center';
        document.body.style.filter = `blur(${t * 4}px) saturate(${1 - t * 0.6})`;
        document.body.style.opacity = 1 - t * 0.3;
        if (t < 1) { requestAnimationFrame(melt); }
        else {
            setTimeout(() => {
                document.body.style.transition = 'all 0.8s ease-out';
                document.body.style.transform = '';
                document.body.style.filter = '';
                document.body.style.opacity = '';
                document.body.style.transformOrigin = '';
                setTimeout(() => { document.body.style.transition = ''; meltActive = false; }, 900);
            }, 800);
        }
    }
    melt();
}

// ==============================================
// BOSS KEY (B)
// ==============================================
let bossActive = false;
function toggleBossKey() {
    bossActive = !bossActive;
    const ovl = document.getElementById('boss-overlay');
    if (bossActive) {
        if (!ovl.innerHTML) {
            const cols = ['A','B','C','D','E','F','G','H','I','J'];
            const rows = [
                ['Department','Q1 Revenue','Q2 Revenue','Q3 Revenue','Q4 Revenue','YTD Total','Growth %','Target','Variance','Status'],
                ['Sales','$142,500','$156,800','$163,200','$171,400','$633,900','12.4%','$600,000','$33,900','Above'],
                ['Marketing','$98,300','$102,100','$97,800','$105,600','$403,800','3.2%','$400,000','$3,800','On Track'],
                ['Engineering','$267,200','$271,500','$273,800','$269,400','$1,081,900','1.8%','$1,050,000','$31,900','Above'],
                ['Operations','$83,100','$88,700','$85,400','$91,300','$348,500','6.7%','$340,000','$8,500','Above'],
                ['HR','$45,200','$45,200','$46,800','$47,100','$184,300','2.1%','$180,000','$4,300','On Track'],
                ['Finance','$52,800','$53,100','$54,200','$55,600','$215,700','3.4%','$210,000','$5,700','Above'],
                ['Legal','$38,400','$39,200','$38,800','$40,100','$156,500','2.8%','$155,000','$1,500','On Track'],
                ['Support','$71,600','$73,200','$75,100','$76,800','$296,700','4.9%','$290,000','$6,700','Above'],
                ['R&D','$189,500','$195,200','$198,700','$203,100','$786,500','5.2%','$750,000','$36,500','Above'],
            ];
            let h = '<div class="boss-toolbar"><span>\u{1F4CA}</span><span style="font-weight:600">Q4_Financial_Report_FINAL_v3.xlsx - Excel</span><span style="margin-left:auto;opacity:0.4;font-size:10px">press B to return</span></div>';
            h += '<div class="boss-formula"><span style="background:#e8e8e8;padding:1px 6px;border:1px solid #ccc;font-size:11px">B2</span><span style="color:#999">fx</span><span>$142,500</span></div>';
            h += '<table class="boss-table"><thead><tr><th></th>';
            cols.forEach(c => h += `<th>${c}</th>`);
            h += '</tr></thead><tbody>';
            for (let r = 0; r < 35; r++) {
                h += `<tr><td>${r+1}</td>`;
                for (let c = 0; c < cols.length; c++) {
                    const val = rows[r] ? (rows[r][c] || '') : '';
                    const cls = r === 1 && c === 1 ? ' class="selected"' : '';
                    h += `<td${cls}>${val}</td>`;
                }
                h += '</tr>';
            }
            h += '</tbody></table>';
            ovl.innerHTML = h;
        }
        ovl.classList.add('active');
        if (!audio.paused) { audio.pause(); ovl.dataset.wasPlaying = '1'; }
    } else {
        ovl.classList.remove('active');
        if (ovl.dataset.wasPlaying === '1') { audio.play().catch(()=>{}); ovl.dataset.wasPlaying = ''; }
    }
}

// ==============================================
// DESTRUCTION MODE (X toggle)
// ==============================================
let destructionActive = false, isDestroying = false, lastDX, lastDY;
const destroyCanvas = document.getElementById('destruction-canvas');
const destroyCtx = destroyCanvas.getContext('2d');

function toggleDestructionMode() {
    destructionActive = !destructionActive;
    if (destructionActive) {
        destroyCanvas.width = window.innerWidth;
        destroyCanvas.height = window.innerHeight;
        destroyCanvas.classList.add('active');
        showToast('Mode', 'Destruction mode ON \u2014 drag to tear');
    } else {
        destroyCanvas.classList.remove('active');
        destroyCtx.clearRect(0, 0, destroyCanvas.width, destroyCanvas.height);
        showToast('Mode', 'Destruction mode OFF');
    }
}

destroyCanvas.addEventListener('mousedown', (e) => { isDestroying = true; lastDX = e.clientX; lastDY = e.clientY; });
destroyCanvas.addEventListener('mousemove', (e) => {
    if (!isDestroying) return;
    const x = e.clientX, y = e.clientY;
    destroyCtx.save();
    destroyCtx.globalCompositeOperation = 'lighter';
    destroyCtx.strokeStyle = '#ff4422';
    destroyCtx.lineWidth = 3 + Math.random() * 8;
    destroyCtx.shadowColor = '#ff6600';
    destroyCtx.shadowBlur = 25;
    destroyCtx.beginPath();
    destroyCtx.moveTo(lastDX, lastDY);
    const dx = x - lastDX, dy = y - lastDY;
    const steps = Math.max(1, Math.floor(Math.hypot(dx, dy) / 5));
    for (let i = 1; i <= steps; i++) {
        const t = i / steps;
        destroyCtx.lineTo(lastDX + dx * t + (Math.random()-0.5)*12, lastDY + dy * t + (Math.random()-0.5)*12);
    }
    destroyCtx.stroke();
    destroyCtx.fillStyle = 'rgba(255,100,0,0.25)';
    destroyCtx.beginPath();
    destroyCtx.arc(x, y, 12 + Math.random() * 15, 0, Math.PI * 2);
    destroyCtx.fill();
    destroyCtx.restore();
    lastDX = x; lastDY = y;
});
destroyCanvas.addEventListener('mouseup', () => { isDestroying = false; });

// ==============================================
// VOICE COMMANDS (V toggle)
// ==============================================
let voiceActive = false, recognition = null;
function toggleVoiceCommands() {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) return;
    voiceActive = !voiceActive;
    const ind = document.getElementById('voice-indicator');
    if (voiceActive) {
        ind.classList.add('active');
        recognition = new SR();
        recognition.continuous = true;
        recognition.interimResults = false;
        recognition.onresult = (e) => {
            const cmd = e.results[e.results.length - 1][0].transcript.toLowerCase().trim();
            if (cmd.includes('gravity')) activateGravity();
            else if (cmd.includes('quake') || cmd.includes('earthquake')) activateEarthquake();
            else if (cmd.includes('melt')) activateScreenMelt();
            else if (cmd.includes('boss')) toggleBossKey();
            else if (cmd.includes('bomb') || cmd.includes('explode')) startTimeBomb();
            else if (cmd.includes('dj') || cmd.includes('music')) toggleDJMode();
            else if (cmd.includes('destroy')) toggleDestructionMode();
            showToast('Voice', `Heard: "${cmd}"`);
        };
        recognition.onend = () => { if (voiceActive) try { recognition.start(); } catch(e){} };
        recognition.start();
        showToast('Voice', 'Listening for commands...');
    } else {
        ind.classList.remove('active');
        if (recognition) { recognition.onend = null; recognition.abort(); recognition = null; }
    }
}

// ==============================================
// PAINT MODE (C)
// ==============================================
let paintActive = false;

function togglePaintMode() {
    paintActive = !paintActive;
    if (paintActive) {
        const overlay = document.createElement('div');
        overlay.id = 'paint-overlay';
        overlay.style.cssText = 'position:fixed;inset:0;z-index:99997;';

        const toolbar = document.createElement('div');
        toolbar.style.cssText = 'position:absolute;top:0;left:0;right:0;height:44px;background:#e0e0e0;border-bottom:2px solid #999;display:flex;align-items:center;padding:0 10px;gap:8px;z-index:2;font-family:Segoe UI,sans-serif;font-size:12px;cursor:default;';

        const title = document.createElement('span');
        title.textContent = 'Paint Mode';
        title.style.cssText = 'font-weight:bold;margin-right:10px;color:#333;';
        toolbar.appendChild(title);

        const colors = ['#ff0000','#ff8800','#ffff00','#00cc00','#0088ff','#7aa2ff','#aa00ff','#ff00aa','#ffffff','#888888','#333333','#000000'];
        let paintColor = '#ff0000';
        colors.forEach(c => {
            const swatch = document.createElement('div');
            swatch.style.cssText = `width:20px;height:20px;background:${c};border:2px solid ${c === paintColor ? '#000' : '#999'};cursor:pointer;border-radius:2px;`;
            swatch.onclick = () => {
                paintColor = c; erasing = false;
                toolbar.querySelectorAll('[data-swatch]').forEach(s => s.style.borderColor = '#999');
                swatch.style.borderColor = '#000';
            };
            swatch.dataset.swatch = '1';
            toolbar.appendChild(swatch);
        });

        toolbar.appendChild(Object.assign(document.createElement('div'), { style: 'width:1px;height:24px;background:#999;margin:0 6px;' }));

        let paintSize = 4;
        [2, 4, 8, 16].forEach(s => {
            const btn = document.createElement('div');
            btn.style.cssText = `width:${Math.max(12,s+4)}px;height:${Math.max(12,s+4)}px;background:#333;border-radius:50%;cursor:pointer;border:2px solid ${s === paintSize ? '#000' : 'transparent'};`;
            btn.onclick = () => {
                paintSize = s;
                toolbar.querySelectorAll('[data-size]').forEach(b => b.style.borderColor = 'transparent');
                btn.style.borderColor = '#000';
            };
            btn.dataset.size = '1';
            toolbar.appendChild(btn);
        });

        toolbar.appendChild(Object.assign(document.createElement('div'), { style: 'width:1px;height:24px;background:#999;margin:0 6px;' }));

        let erasing = false;
        const eraserBtn = document.createElement('button');
        eraserBtn.textContent = 'Eraser';
        eraserBtn.style.cssText = 'padding:4px 10px;border:1px solid #999;background:#ddd;cursor:pointer;font-size:11px;border-radius:3px;';
        eraserBtn.onclick = () => { erasing = !erasing; eraserBtn.style.background = erasing ? '#aaf' : '#ddd'; };
        toolbar.appendChild(eraserBtn);

        const clearBtn = document.createElement('button');
        clearBtn.textContent = 'Clear';
        clearBtn.style.cssText = 'padding:4px 10px;border:1px solid #999;background:#ddd;cursor:pointer;font-size:11px;border-radius:3px;';
        clearBtn.onclick = () => pCtx.clearRect(0, 0, pCanvas.width, pCanvas.height);
        toolbar.appendChild(clearBtn);

        const closeBtn = document.createElement('button');
        closeBtn.textContent = '\u00D7 Close';
        closeBtn.style.cssText = 'margin-left:auto;padding:4px 12px;border:1px solid #c00;background:#ff4444;color:white;cursor:pointer;font-size:11px;border-radius:3px;font-weight:bold;';
        closeBtn.onclick = () => { overlay.remove(); paintActive = false; };
        toolbar.appendChild(closeBtn);

        const pCanvas = document.createElement('canvas');
        pCanvas.width = window.innerWidth;
        pCanvas.height = window.innerHeight;
        pCanvas.style.cssText = 'position:absolute;top:44px;left:0;cursor:crosshair;';
        const pCtx = pCanvas.getContext('2d');
        pCtx.lineCap = 'round'; pCtx.lineJoin = 'round';

        let painting = false, lastPX, lastPY;
        pCanvas.onmousedown = (e) => { painting = true; lastPX = e.clientX; lastPY = e.clientY - 44; };
        pCanvas.onmousemove = (e) => {
            if (!painting) return;
            const x = e.clientX, y = e.clientY - 44;
            pCtx.strokeStyle = erasing ? 'rgba(0,0,0,1)' : paintColor;
            pCtx.globalCompositeOperation = erasing ? 'destination-out' : 'source-over';
            pCtx.lineWidth = erasing ? paintSize * 3 : paintSize;
            pCtx.beginPath(); pCtx.moveTo(lastPX, lastPY); pCtx.lineTo(x, y); pCtx.stroke();
            lastPX = x; lastPY = y;
        };
        pCanvas.onmouseup = () => painting = false;
        pCanvas.onmouseleave = () => painting = false;

        overlay.appendChild(toolbar);
        overlay.appendChild(pCanvas);
        document.body.appendChild(overlay);
    } else {
        document.getElementById('paint-overlay')?.remove();
    }
}

// ==============================================
// FAKE WINDOWS 95 DESKTOP (W)
// ==============================================
let win95Active = false;

function toggleWin95() {
    if (win95Active) { document.getElementById('win95-desktop')?.remove(); win95Active = false; return; }
    win95Active = true;
    const desk = document.createElement('div');
    desk.id = 'win95-desktop';
    desk.style.cssText = 'position:fixed;inset:0;z-index:99998;background:#008080;cursor:default;font-family:"MS Sans Serif",Tahoma,sans-serif;font-size:12px;overflow:hidden;';

    const icons = [
        { n: 'My Computer', i: '\u{1F5A5}', y: 20 },
        { n: 'Recycle Bin', i: '\u{1F5D1}', y: 100 },
        { n: 'Internet\nExplorer', i: '\u{1F310}', y: 180 },
        { n: 'logansandivar\n.exe', i: '\u{1F480}', y: 260, act: 'exit' },
        { n: 'README.txt', i: '\u{1F4C4}', y: 340, act: 'notepad' },
    ];

    icons.forEach(ic => {
        const el = document.createElement('div');
        el.style.cssText = `position:absolute;left:20px;top:${ic.y}px;width:75px;text-align:center;color:white;cursor:pointer;padding:4px;user-select:none;`;
        el.innerHTML = `<div style="font-size:36px">${ic.i}</div><div style="font-size:11px;text-shadow:1px 1px #000;white-space:pre-line;margin-top:2px">${ic.n}</div>`;
        el.addEventListener('dblclick', () => {
            if (ic.act === 'exit') { desk.remove(); win95Active = false; }
            else if (ic.act === 'notepad') openWin95Window(desk, 'README.txt - Notepad', 400, 300,
                '<div style="background:white;color:black;padding:8px;font-family:Fixedsys,Consolas,monospace;font-size:13px;height:100%;overflow:auto;white-space:pre-wrap;">Dear visitor,\n\nIf you\'re reading this, you pressed W.\nCongratulations. You found the secret Windows 95 desktop.\n\nTo get back to the real site, double-click\n"logansandivar.exe" or click Start > Return to Site.\n\nFun facts about this desktop:\n- It runs on vibes and JavaScript\n- The Recycle Bin contains your productivity\n- Internet Explorer will not open (for your safety)\n- My Computer has no files (just like my motivation)\n\nRegards,\nThe Developer (who is definitely not a stick figure)</div>');
            else if (ic.n === 'My Computer') openWin95Window(desk, 'My Computer', 450, 250,
                '<div style="background:white;padding:12px;display:flex;gap:20px;flex-wrap:wrap;">' +
                ['\u{1F4BE} 3.5 Floppy (A:)','\u{1F4BD} Local Disk (C:)','\u{1F4C0} CD Drive (D:)','\u{1F310} Network'].map(d =>
                    `<div style="text-align:center;width:80px;cursor:pointer;padding:8px;font-size:11px"><div style="font-size:28px">${d.split(' ')[0]}</div>${d.split(' ').slice(1).join(' ')}</div>`
                ).join('') + '</div>');
            else if (ic.n === 'Recycle Bin') openWin95Window(desk, 'Recycle Bin', 350, 200,
                '<div style="background:white;padding:20px;text-align:center;color:#666;font-size:13px"><div style="font-size:48px;margin-bottom:10px">\u{1F5D1}</div>Contains: your free time<br>Size: immeasurable</div>');
        });
        desk.appendChild(el);
    });

    const bar = document.createElement('div');
    bar.style.cssText = 'position:absolute;bottom:0;left:0;right:0;height:32px;background:#c0c0c0;border-top:2px solid #fff;display:flex;align-items:center;padding:2px 4px;gap:4px;';

    const startBtn = document.createElement('button');
    startBtn.innerHTML = '\u{1FAA7} <b>Start</b>';
    startBtn.style.cssText = 'height:26px;border:2px outset #ddd;background:#c0c0c0;font-size:11px;padding:0 8px;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:3px;';

    const startMenu = document.createElement('div');
    startMenu.style.cssText = 'position:absolute;bottom:32px;left:0;width:180px;background:#c0c0c0;border:2px outset #ddd;display:none;';
    const menuLeft = document.createElement('div');
    menuLeft.style.cssText = 'position:absolute;left:0;top:0;bottom:0;width:24px;background:linear-gradient(#000080,#1084d0);';
    menuLeft.innerHTML = '<div style="position:absolute;bottom:4px;left:2px;color:white;font-size:10px;font-weight:bold;writing-mode:vertical-lr;transform:rotate(180deg)">Windows 95</div>';
    startMenu.appendChild(menuLeft);

    const menuContent = document.createElement('div');
    menuContent.style.cssText = 'margin-left:24px;';
    ['\u{1F4C1} Programs','\u{1F4C4} Documents','\u{2699} Settings','\u{1F50D} Find','---','\u{1F50C} Return to Site'].forEach(t => {
        if (t === '---') { const s = document.createElement('div'); s.style.cssText = 'height:1px;background:#808080;margin:2px;border-bottom:1px solid #fff;'; menuContent.appendChild(s); return; }
        const it = document.createElement('div');
        it.style.cssText = 'padding:5px 8px;cursor:pointer;font-size:12px;';
        it.textContent = t;
        it.onmouseenter = () => { it.style.background = '#000080'; it.style.color = '#fff'; };
        it.onmouseleave = () => { it.style.background = ''; it.style.color = ''; };
        it.onclick = () => { if (t.includes('Return')) { desk.remove(); win95Active = false; } startMenu.style.display = 'none'; };
        menuContent.appendChild(it);
    });
    startMenu.appendChild(menuContent);

    let smOpen = false;
    startBtn.onclick = () => { smOpen = !smOpen; startMenu.style.display = smOpen ? 'block' : 'none'; };

    const clock = document.createElement('div');
    clock.style.cssText = 'margin-left:auto;border:1px inset #aaa;padding:2px 8px;font-size:11px;';
    clock.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    bar.appendChild(startBtn);
    bar.appendChild(startMenu);
    bar.appendChild(clock);
    desk.appendChild(bar);
    document.body.appendChild(desk);
}

function openWin95Window(parent, title, width, height, content) {
    const win = document.createElement('div');
    win.style.cssText = `position:absolute;left:${100 + Math.random()*200}px;top:${50 + Math.random()*100}px;width:${width}px;background:#c0c0c0;border:2px outset #ddd;box-shadow:2px 2px 0 #000;z-index:10;`;

    const titleBar = document.createElement('div');
    titleBar.style.cssText = 'background:linear-gradient(90deg,#000080,#1084d0);color:white;padding:3px 4px;font-size:12px;font-weight:bold;display:flex;justify-content:space-between;align-items:center;cursor:move;user-select:none;';
    titleBar.innerHTML = `<span>${title}</span>`;
    const closeBtn = document.createElement('button');
    closeBtn.textContent = '\u00D7';
    closeBtn.style.cssText = 'width:18px;height:16px;border:1px outset #ddd;background:#c0c0c0;font-size:12px;line-height:1;cursor:pointer;padding:0;';
    closeBtn.onclick = () => win.remove();
    titleBar.appendChild(closeBtn);

    let dx, dy, dragging = false;
    titleBar.onmousedown = (e) => { dragging = true; dx = e.clientX - win.offsetLeft; dy = e.clientY - win.offsetTop; };
    document.addEventListener('mousemove', (e) => { if (dragging) { win.style.left = (e.clientX - dx) + 'px'; win.style.top = (e.clientY - dy) + 'px'; } });
    document.addEventListener('mouseup', () => { dragging = false; });

    const body = document.createElement('div');
    body.style.cssText = `height:${height}px;border:2px inset #aaa;margin:2px;overflow:auto;`;
    body.innerHTML = content;

    win.appendChild(titleBar);
    win.appendChild(body);
    parent.appendChild(win);
}

// ==============================================
// SLOT MACHINE (S)
// ==============================================
let slotActive = false;
const siteThemes = [
    { name: 'Normal', filter: 'none', bg: '' },
    { name: 'Vaporwave', filter: 'hue-rotate(180deg) saturate(1.8) brightness(1.1)', bg: 'linear-gradient(135deg, rgba(255,0,128,0.15), rgba(0,200,255,0.15))' },
    { name: 'Matrix', filter: 'hue-rotate(85deg) saturate(3) brightness(0.85)', bg: 'rgba(0,30,0,0.3)' },
    { name: 'Gameboy', filter: 'sepia(0.8) hue-rotate(50deg) saturate(0.6) contrast(1.3) brightness(0.9)', bg: 'rgba(50,80,30,0.15)' },
    { name: 'Retro', filter: 'sepia(0.5) contrast(1.2) brightness(0.95)', bg: 'rgba(60,30,0,0.15)' },
    { name: 'Inverted', filter: 'invert(0.9) hue-rotate(180deg)', bg: '' },
];

function toggleSlotMachine() {
    if (slotActive) { document.getElementById('slot-overlay')?.remove(); slotActive = false; return; }
    slotActive = true;

    const overlay = document.createElement('div');
    overlay.id = 'slot-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:99998;background:rgba(0,0,0,0.85);backdrop-filter:blur(10px);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:20px;cursor:default;font-family:Segoe UI,sans-serif;';

    overlay.innerHTML = `
        <div style="color:#7aa2ff;font-size:2rem;font-weight:900;letter-spacing:4px;text-shadow:0 0 20px rgba(122,162,255,0.5);">SLOT MACHINE</div>
        <div style="color:#8a96b8;font-size:0.85rem;">Spin for a random site theme!</div>
        <div style="display:flex;gap:12px;margin:20px 0;" id="slot-reels">
            <div class="slot-reel" id="reel1" style="width:140px;height:60px;background:#111;border:2px solid #7aa2ff;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;font-weight:700;color:#7aa2ff;overflow:hidden;">?</div>
            <div class="slot-reel" id="reel2" style="width:140px;height:60px;background:#111;border:2px solid #7aa2ff;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;font-weight:700;color:#7aa2ff;overflow:hidden;">?</div>
            <div class="slot-reel" id="reel3" style="width:140px;height:60px;background:#111;border:2px solid #7aa2ff;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;font-weight:700;color:#7aa2ff;overflow:hidden;">?</div>
        </div>
        <button id="spin-btn" style="padding:12px 40px;background:#7aa2ff;color:#0b0b0f;border:none;border-radius:30px;font-size:1.1rem;font-weight:700;cursor:pointer;letter-spacing:2px;transition:0.2s;font-family:inherit;">SPIN</button>
        <div id="slot-result" style="color:#8a96b8;font-size:0.85rem;height:20px;"></div>
        <button id="slot-close" style="padding:8px 20px;background:transparent;border:1px solid #555;color:#888;border-radius:20px;cursor:pointer;font-size:0.8rem;font-family:inherit;">Close (S)</button>
    `;

    document.body.appendChild(overlay);

    const spinBtn = document.getElementById('spin-btn');
    const resultEl = document.getElementById('slot-result');
    const r1 = document.getElementById('reel1'), r2 = document.getElementById('reel2'), r3 = document.getElementById('reel3');

    document.getElementById('slot-close').onclick = () => { overlay.remove(); slotActive = false; };

    spinBtn.onclick = () => {
        spinBtn.disabled = true;
        spinBtn.style.opacity = '0.5';
        const chosen = siteThemes[Math.floor(Math.random() * siteThemes.length)];
        let spins = 0;
        const maxSpins = 40;

        const spinInterval = setInterval(() => {
            spins++;
            const randTheme = () => siteThemes[Math.floor(Math.random() * siteThemes.length)].name;
            r1.textContent = spins < maxSpins - 10 ? randTheme() : (spins < maxSpins - 5 ? randTheme() : chosen.name);
            r2.textContent = spins < maxSpins - 5 ? randTheme() : chosen.name;
            r3.textContent = spins < maxSpins ? randTheme() : chosen.name;

            if (spins >= maxSpins) {
                clearInterval(spinInterval);
                r1.textContent = chosen.name;
                r2.textContent = chosen.name;
                r3.textContent = chosen.name;

                [r1, r2, r3].forEach(r => {
                    r.style.borderColor = '#ffaa00';
                    r.style.color = '#ffaa00';
                    r.style.boxShadow = '0 0 20px rgba(255,170,0,0.5)';
                });

                resultEl.textContent = chosen.name === 'Normal' ? 'Back to normal!' : `Theme: ${chosen.name}!`;
                resultEl.style.color = '#ffaa00';

                setTimeout(() => {
                    document.body.style.filter = chosen.filter;
                    spinBtn.disabled = false;
                    spinBtn.style.opacity = '1';
                    [r1, r2, r3].forEach(r => { r.style.borderColor = '#7aa2ff'; r.style.color = '#7aa2ff'; r.style.boxShadow = ''; });
                }, 800);
            }
        }, spins > maxSpins - 10 ? 150 : 60);
    };
}

// ==============================================
// PAGE FLIP (Q)
// ==============================================
let pageFlipped = false;
let flipContainer = null;

function togglePageFlip() {
    if (pageFlipped) {
        if (flipContainer) {
            flipContainer.querySelector('.flip-inner').style.transform = 'rotateY(0deg)';
            setTimeout(() => { flipContainer.remove(); flipContainer = null; }, 1200);
        }
        pageFlipped = false;
        return;
    }

    pageFlipped = true;

    flipContainer = document.createElement('div');
    flipContainer.style.cssText = 'position:fixed;inset:0;z-index:9996;perspective:1500px;';

    const flipInner = document.createElement('div');
    flipInner.className = 'flip-inner';
    flipInner.style.cssText = 'width:100%;height:100%;transform-style:preserve-3d;transition:transform 1.2s cubic-bezier(0.4,0,0.2,1);transform:rotateY(0deg);';

    const front = document.createElement('div');
    front.style.cssText = 'position:absolute;inset:0;backface-visibility:hidden;background:#0b0b0f;';

    const back = document.createElement('div');
    back.style.cssText = 'position:absolute;inset:0;backface-visibility:hidden;transform:rotateY(180deg);background:#0a0a12;overflow-y:auto;';

    const secrets = [
        { skill: 'Breaking websites with T key', pct: 98 },
        { skill: 'Googling the error message', pct: 95 },
        { skill: 'Closing 47 browser tabs at once', pct: 88 },
        { skill: 'Pretending to understand regex', pct: 72 },
        { skill: 'Ctrl+Z mastery', pct: 100 },
        { skill: 'Making CSS "work" somehow', pct: 65 },
        { skill: 'Blaming the cache', pct: 99 },
        { skill: 'Stack Overflow speed-reading', pct: 91 },
    ];

    const facts = [
        'This website has more lines of code than some operating systems (probably)',
        'The T key bomb was tested 47 times. The stick figure filed a complaint.',
        'Every pixel on this page was hand-artisanally-crafted (by Claude)',
        'The DJ mode filter was originally set to "sounds like a robot in a washing machine"',
        'If you found this page, you are officially a person of culture',
        'The Windows 95 desktop is more stable than actual Windows 95',
        'The Flappy Bird high score to beat is 0 (it is really hard ok)',
        'This entire site is one HTML file. Yes, ONE. We are not sorry.',
    ];

    let skillBars = secrets.map(s => `
        <div style="margin:8px 0;">
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                <span>${s.skill}</span>
                <span style="color:#ff6b6b;">${s.pct}%</span>
            </div>
            <div style="background:#1a1a2e;border-radius:4px;height:8px;overflow:hidden;">
                <div class="flip-skill-bar" style="width:0%;height:100%;background:linear-gradient(90deg,#7aa2ff,#ff6b6b);border-radius:4px;transition:width 1.5s ease;" data-pct="${s.pct}"></div>
            </div>
        </div>
    `).join('');

    let factList = facts.map(f => `
        <div style="padding:10px 14px;background:rgba(122,162,255,0.05);border-left:3px solid #7aa2ff;margin:8px 0;border-radius:0 6px 6px 0;font-size:0.9rem;color:#8a96b8;">
            ${f}
        </div>
    `).join('');

    back.innerHTML = `
        <div style="max-width:700px;margin:0 auto;padding:60px 30px 80px;">
            <div style="text-align:center;margin-bottom:40px;">
                <div style="font-size:0.75rem;letter-spacing:6px;text-transform:uppercase;color:#ff6b6b;margin-bottom:10px;">You flipped the page</div>
                <div style="font-size:3rem;font-weight:900;background:linear-gradient(135deg,#ff6b6b,#7aa2ff);-webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:4px;">THE DARK SIDE</div>
                <div style="color:#5a6480;font-size:0.85rem;margin-top:8px;">Welcome to the back of the internet</div>
            </div>

            <div style="background:rgba(255,107,107,0.05);border:1px solid rgba(255,107,107,0.15);border-radius:12px;padding:24px;margin-bottom:30px;">
                <div style="color:#ff6b6b;font-size:1.1rem;font-weight:700;letter-spacing:3px;text-transform:uppercase;margin-bottom:16px;">&#9760; Logan's REAL Skills</div>
                ${skillBars}
            </div>

            <div style="background:rgba(122,162,255,0.05);border:1px solid rgba(122,162,255,0.15);border-radius:12px;padding:24px;margin-bottom:30px;">
                <div style="color:#7aa2ff;font-size:1.1rem;font-weight:700;letter-spacing:3px;text-transform:uppercase;margin-bottom:16px;">&#128064; Classified Intel</div>
                ${factList}
            </div>

            <div style="text-align:center;padding:30px;background:rgba(255,255,255,0.02);border-radius:12px;border:1px dashed rgba(122,162,255,0.2);">
                <pre style="color:#3a4a6a;font-size:0.7rem;line-height:1.4;font-family:monospace;">
    \u2554\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2557
    \u2551                                      \u2551
    \u2551   if (you.readThis) {                \u2551
    \u2551       you.coolness += 100;           \u2551
    \u2551       logan.respect++;               \u2551
    \u2551   }                                  \u2551
    \u2551                                      \u2551
    \u2551   // press Q to return to reality    \u2551
    \u2551                                      \u2551
    \u255A\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u255D
                </pre>
            </div>

            <div style="text-align:center;margin-top:30px;color:#3a4a6a;font-size:0.75rem;letter-spacing:2px;">
                PRESS Q TO FLIP BACK &middot; YOU SAW NOTHING
            </div>
        </div>
    `;

    flipInner.appendChild(front);
    flipInner.appendChild(back);
    flipContainer.appendChild(flipInner);
    document.body.appendChild(flipContainer);

    requestAnimationFrame(() => {
        flipInner.style.transform = 'rotateY(180deg)';
    });

    setTimeout(() => {
        const bars = back.querySelectorAll('.flip-skill-bar');
        bars.forEach(bar => {
            bar.style.width = bar.dataset.pct + '%';
        });
    }, 1300);
}

// ==============================================
// UNIFIED KEY HANDLER (must be last)
// ==============================================
document.addEventListener('keydown', (e) => {
    if (document.activeElement.tagName === 'INPUT') return;
    if (bossActive && e.key.toLowerCase() !== 'b') return;
    if (flappyActive && e.key !== 'F1') { if (e.key === ' ') { e.preventDefault(); flappyClick(); } return; }
    const k = e.key.toLowerCase();
    switch (k) {
        case 'g': if (!gravityActive) activateGravity(); break;
        case 'e': activateEarthquake(); break;
        case 'm': activateScreenMelt(); break;
        case 'b': toggleBossKey(); break;
        case 'd': toggleDJMode(); break;
        case 't': startTimeBomb(); break;
        case 'v': toggleVoiceCommands(); break;
        case 'f': if (e.key === 'F1') { e.preventDefault(); toggleFlappy(); } else toggleFaceTracking(); break;
        case 'x': toggleDestructionMode(); break;
        case 'c': togglePaintMode(); break;
        case 'w': toggleWin95(); break;
        case 's': toggleSlotMachine(); break;
        case 'q': togglePageFlip(); break;
    }
    if (e.key === 'F1') { e.preventDefault(); toggleFlappy(); }
});
