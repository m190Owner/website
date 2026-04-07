// ==============================================
// 3D STARFIELD
// ==============================================
const STAR_COUNT = 400;
const stars = [];

function initStars() {
    stars.length = 0;
    for (let i = 0; i < STAR_COUNT; i++) {
        stars.push({
            x: (Math.random() - 0.5) * 2000,
            y: (Math.random() - 0.5) * 2000,
            z: Math.random() * 2000
        });
    }
}

function drawStarfield() {
    sfCtx.clearRect(0, 0, w, h);

    const beat = getBeatStrength();
    const speed = 2 + beat / 10;
    const cx = w / 2;
    const cy = h / 2;

    for (let i = 0; i < stars.length; i++) {
        const s = stars[i];
        s.z -= speed;

        if (s.z <= 0) {
            s.x = (Math.random() - 0.5) * 2000;
            s.y = (Math.random() - 0.5) * 2000;
            s.z = 2000;
        }

        const sx = (s.x / s.z) * 500 + cx;
        const sy = (s.y / s.z) * 500 + cy;
        const size = Math.max(0.5, (1 - s.z / 2000) * 3);
        const alpha = 1 - s.z / 2000;

        const prevZ = s.z + speed;
        const px = (s.x / prevZ) * 500 + cx;
        const py = (s.y / prevZ) * 500 + cy;

        if (sx >= 0 && sx <= w && sy >= 0 && sy <= h) {
            const hue = 210 + (beat / 3);
            sfCtx.strokeStyle = `hsla(${hue}, 70%, 80%, ${alpha * 0.6})`;
            sfCtx.lineWidth = size * 0.8;
            sfCtx.beginPath();
            sfCtx.moveTo(px, py);
            sfCtx.lineTo(sx, sy);
            sfCtx.stroke();

            sfCtx.fillStyle = `hsla(${hue}, 80%, 90%, ${alpha})`;
            sfCtx.beginPath();
            sfCtx.arc(sx, sy, size, 0, Math.PI * 2);
            sfCtx.fill();
        }
    }

    requestAnimationFrame(drawStarfield);
}

initStars();
drawStarfield();

// ==============================================
// FLOATING TEXT PARTICLE SYSTEM
// ==============================================
const phrases = [
    "I always get what I want",
    "Just kick me out I don't want to play anymore",
    "I'm so rich it hurts",
    "I don't watch TV I am intelligent"
];

const textParticles = [];
let flashAlpha = 0;
const shockwaves = [];

function randomColor() {
    const hue = Math.floor(Math.random() * 360);
    return {
        hue,
        fill: `hsla(${hue}, 80%, 75%, 1)`,
        glow: `hsla(${hue}, 100%, 70%, 1)`
    };
}

function spawnTextParticle(i) {
    const corner = Math.floor(Math.random() * 4);
    const margin = 60;
    let x, y;

    if (corner === 0) { x = margin; y = margin; }
    else if (corner === 1) { x = w - margin; y = margin; }
    else if (corner === 2) { x = margin; y = h - margin; }
    else { x = w - margin; y = h - margin; }

    textParticles[i] = {
        x, y,
        vx: (Math.random() - 0.5) * 1.2,
        vy: (Math.random() - 0.5) * 1.2,
        phrase: phrases[i],
        size: 18 + Math.random() * 6,
        opacity: 0.9,
        exploding: false,
        color: randomColor()
    };
}

for (let i = 0; i < phrases.length; i++) {
    spawnTextParticle(i);
}

function separateParticles() {
    const minDist = 140;
    const force = 0.12;

    for (let i = 0; i < textParticles.length; i++) {
        for (let j = i + 1; j < textParticles.length; j++) {
            const a = textParticles[i];
            const b = textParticles[j];
            const dx = b.x - a.x;
            const dy = b.y - a.y;
            const dist = Math.hypot(dx, dy);

            if (dist < minDist) {
                const push = (minDist - dist) * force;
                const nx = dx / (dist || 1);
                const ny = dy / (dist || 1);
                a.x -= nx * push;
                a.y -= ny * push;
                b.x += nx * push;
                b.y += ny * push;
            }
        }
    }
}

function addShockwave(x, y, color, beat, scale = 1) {
    shockwaves.push({
        x, y,
        radius: 0,
        maxRadius: (260 + beat * 1.5) * scale,
        alpha: 0.9,
        color: color.glow
    });
}

function updateShockwaves() {
    for (let i = shockwaves.length - 1; i >= 0; i--) {
        const s = shockwaves[i];
        s.radius += 8;
        s.alpha -= 0.02;
        if (s.radius > s.maxRadius || s.alpha <= 0) {
            shockwaves.splice(i, 1);
        }
    }
}

function drawShockwaves() {
    shockwaves.forEach(s => {
        ctx.save();
        ctx.globalAlpha = s.alpha;
        ctx.strokeStyle = s.color;
        ctx.lineWidth = 5;
        ctx.shadowColor = s.color;
        ctx.shadowBlur = 30;
        ctx.beginPath();
        ctx.arc(s.x, s.y, s.radius, 0, Math.PI * 2);
        ctx.stroke();
        ctx.restore();
    });
}

let textShapeMode = false;
let textShapeTargets = [];

function createTextShape(text) {
    const off = document.createElement("canvas");
    const offCtx = off.getContext("2d");
    off.width = 600;
    off.height = 200;
    offCtx.fillStyle = "#fff";
    offCtx.font = "bold 48px Segoe UI";
    offCtx.textAlign = "center";
    offCtx.textBaseline = "middle";
    offCtx.fillText(text, off.width / 2, off.height / 2);
    const data = offCtx.getImageData(0, 0, off.width, off.height).data;
    const pts = [];
    const step = 10;
    for (let y = 0; y < off.height; y += step) {
        for (let x = 0; x < off.width; x += step) {
            const idx = (y * off.width + x) * 4;
            if (data[idx + 3] > 128) {
                pts.push({ x: w / 2 - off.width / 2 + x, y: h / 2 - off.height / 2 + y });
            }
        }
    }
    textShapeTargets = pts;
    textShapeMode = true;
}

function updateFloatingText(beat) {
    const centerX = w / 2;
    const centerY = h / 2;
    const explodeRadius = 150;

    textParticles.forEach((t, i) => {
        if (!t.exploding) {
            t.vx += (Math.random() - 0.5) * 0.05;
            t.vy += (Math.random() - 0.5) * 0.05;
            t.vx += (Math.random() - 0.5) * (beat / 200);
            t.vy += (Math.random() - 0.5) * (beat / 200);
            if (textShapeMode && textShapeTargets[i]) {
                const tx = textShapeTargets[i].x;
                const ty = textShapeTargets[i].y;
                t.vx += (tx - t.x) * 0.02;
                t.vy += (ty - t.y) * 0.02;
            }
            t.vx *= 0.99;
            t.vy *= 0.99;
            t.x += t.vx;
            t.y += t.vy;
            if (t.x < 0) { t.x = 0; t.vx *= -1; }
            if (t.x > w) { t.x = w; t.vx *= -1; }
            if (t.y < 0) { t.y = 0; t.vy *= -1; }
            if (t.y > h) { t.y = h; t.vy *= -1; }
            const dx = centerX - t.x;
            const dy = centerY - t.y;
            const dist = Math.hypot(dx, dy) || 1;
            if (dist < explodeRadius && !idleActive) {
                t.exploding = true;
                const angle = Math.random() * Math.PI * 2;
                const force = 5 + beat / 15;
                t.vx = Math.cos(angle) * force;
                t.vy = Math.sin(angle) * force;
                addShockwave(t.x, t.y, t.color, beat);
            }
        } else {
            t.x += t.vx;
            t.y += t.vy;
            t.opacity -= 0.02;
            if (t.opacity <= 0) spawnTextParticle(i);
        }
    });

    separateParticles();
    updateShockwaves();
}

function drawFloatingText(beat) {
    textParticles.forEach(t => {
        ctx.save();
        ctx.translate(t.x, t.y);
        ctx.font = `${t.size}px Segoe UI`;
        ctx.textAlign = "center";
        ctx.textBaseline = "middle";
        ctx.fillStyle = `hsla(${t.color.hue}, 80%, 75%, ${Math.max(0, t.opacity)})`;
        ctx.shadowColor = t.color.glow;
        ctx.shadowBlur = 14 + beat / 4;
        ctx.fillText(t.phrase, 0, 0);
        ctx.restore();
    });
    drawShockwaves();
}

// ==============================================
// CURSOR TRAIL
// ==============================================
const trailParticles = [];

function spawnTrail() {
    const beat = getBeatStrength();
    const count = beat > 40 ? 3 : 1;
    for (let i = 0; i < count; i++) {
        trailParticles.push({
            x: mouseX + (Math.random() - 0.5) * 8,
            y: mouseY + (Math.random() - 0.5) * 8,
            vx: (Math.random() - 0.5) * (1 + beat / 40),
            vy: (Math.random() - 0.5) * (1 + beat / 40),
            size: 2 + Math.random() * 3 + beat / 30,
            alpha: 0.8,
            hue: 210 + Math.random() * 50 + beat / 3
        });
    }
    if (trailParticles.length > 120) trailParticles.splice(0, trailParticles.length - 120);
}

function updateTrail() {
    for (let i = trailParticles.length - 1; i >= 0; i--) {
        const p = trailParticles[i];
        p.x += p.vx;
        p.y += p.vy;
        p.alpha -= 0.02;
        p.size *= 0.97;
        if (p.alpha <= 0 || p.size < 0.3) {
            trailParticles.splice(i, 1);
        }
    }
}

function drawTrail() {
    trailParticles.forEach(p => {
        ctx.save();
        ctx.globalAlpha = p.alpha;
        ctx.fillStyle = `hsla(${p.hue}, 85%, 70%, 1)`;
        ctx.shadowColor = `hsla(${p.hue}, 100%, 70%, 0.8)`;
        ctx.shadowBlur = 10;
        ctx.beginPath();
        ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
    });
}

setInterval(spawnTrail, 30);

// ==============================================
// LIGHTNING ARCS ON BEAT DROPS
// ==============================================
const lightningBolts = [];
let lastLightning = 0;

function createLightning(x1, y1, x2, y2, depth) {
    const points = [{ x: x1, y: y1 }];
    const segments = 8 + Math.floor(Math.random() * 6);
    const dx = (x2 - x1) / segments;
    const dy = (y2 - y1) / segments;
    const jitter = Math.hypot(x2 - x1, y2 - y1) * 0.15;

    for (let i = 1; i < segments; i++) {
        points.push({
            x: x1 + dx * i + (Math.random() - 0.5) * jitter,
            y: y1 + dy * i + (Math.random() - 0.5) * jitter
        });
    }
    points.push({ x: x2, y: y2 });

    lightningBolts.push({
        points,
        alpha: 1,
        width: depth === 0 ? 3 : 1.5,
        hue: 210 + Math.random() * 40
    });

    if (depth < 2 && Math.random() < 0.4) {
        const branchIdx = Math.floor(Math.random() * points.length * 0.6) + 2;
        if (branchIdx < points.length) {
            const bp = points[branchIdx];
            const bx = bp.x + (Math.random() - 0.5) * 200;
            const by = bp.y + (Math.random() - 0.5) * 200;
            createLightning(bp.x, bp.y, bx, by, depth + 1);
        }
    }
}

function checkLightning() {
    const beat = getBeatStrength();
    const now = Date.now();
    if (beat > 70 && now - lastLightning > 400) {
        lastLightning = now;
        const x1 = Math.random() * w;
        const y1 = Math.random() * h * 0.3;
        const x2 = x1 + (Math.random() - 0.5) * w * 0.6;
        const y2 = y1 + h * 0.3 + Math.random() * h * 0.4;
        createLightning(x1, y1, x2, y2, 0);

        if (beat > 90 && Math.random() < 0.5) {
            createLightning(Math.random() * w, 0, Math.random() * w, h * 0.7, 0);
        }
    }
}

function updateLightning() {
    for (let i = lightningBolts.length - 1; i >= 0; i--) {
        lightningBolts[i].alpha -= 0.04;
        if (lightningBolts[i].alpha <= 0) {
            lightningBolts.splice(i, 1);
        }
    }
}

function drawLightning() {
    lightningBolts.forEach(bolt => {
        ctx.save();
        ctx.globalAlpha = bolt.alpha;
        ctx.strokeStyle = `hsla(${bolt.hue}, 90%, 80%, 1)`;
        ctx.shadowColor = `hsla(${bolt.hue}, 100%, 70%, 1)`;
        ctx.shadowBlur = 20;
        ctx.lineWidth = bolt.width;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.beginPath();
        ctx.moveTo(bolt.points[0].x, bolt.points[0].y);
        for (let i = 1; i < bolt.points.length; i++) {
            ctx.lineTo(bolt.points[i].x, bolt.points[i].y);
        }
        ctx.stroke();
        ctx.strokeStyle = `hsla(${bolt.hue}, 60%, 95%, ${bolt.alpha})`;
        ctx.lineWidth = bolt.width * 0.4;
        ctx.shadowBlur = 0;
        ctx.stroke();
        ctx.restore();
    });
}

// ==============================================
// IDLE ANIMATION (10s no mouse movement)
// ==============================================
let lastMouseMove = Date.now();
let idleActive = false;
let idleAngle = 0;

document.addEventListener('mousemove', () => {
    lastMouseMove = Date.now();
    if (idleActive) {
        idleActive = false;
    }
});

function checkIdle() {
    if (Date.now() - lastMouseMove > 10000 && !idleActive) {
        idleActive = true;
    }
}

function updateIdle() {
    if (!idleActive) return;
    idleAngle += 0.015;

    const cx = w / 2;
    const cy = h / 2;
    textParticles.forEach((t, i) => {
        if (t.exploding) return;
        const targetX = cx + Math.cos(idleAngle + i * 1.5) * (100 + i * 30);
        const targetY = cy + Math.sin(idleAngle + i * 1.5) * (60 + i * 20);
        t.vx += (targetX - t.x) * 0.008;
        t.vy += (targetY - t.y) * 0.008;
    });
}

// ==============================================
// PORTAL EFFECT (click and hold)
// ==============================================
let portalActive = false, portalX = 0, portalY = 0, portalRadius = 0, portalAngle = 0;
let portalTimeout;

document.addEventListener('mousedown', (e) => {
    if (e.target.closest('nav, .bottom-bar, .chat-input-container, .dj-overlay, .boss-overlay, .destruction-canvas, input, button, a')) return;
    portalTimeout = setTimeout(() => {
        portalActive = true;
        portalX = e.clientX;
        portalY = e.clientY;
        portalRadius = 1;
    }, 400);
});
document.addEventListener('mouseup', () => {
    clearTimeout(portalTimeout);
    if (portalActive) {
        portalActive = false;
        portalRadius = 0;
    }
});

// ==============================================
// MAIN DRAW LOOP
// ==============================================
let hueBase = 220;

function draw() {
    ctx.clearRect(0, 0, w, h);
    const beat = getBeatStrength();
    hueBase = (hueBase + 0.2 + beat / 50) % 360;

    checkIdle();
    updateIdle();

    updateFloatingText(beat);
    drawFloatingText(beat);

    updateTrail();
    drawTrail();

    checkLightning();
    updateLightning();
    drawLightning();

    // Portal effect
    if (portalActive && portalRadius > 0) {
        portalAngle += 0.12;
        portalRadius = Math.min(portalRadius + 3, 150);
        for (let i = 0; i < 6; i++) {
            const r = portalRadius * (1 - i * 0.13);
            const a = portalAngle + i * 0.6;
            ctx.save();
            ctx.globalAlpha = 0.6 - i * 0.08;
            ctx.strokeStyle = `hsla(${270 + i * 15}, 80%, 60%, 1)`;
            ctx.lineWidth = 3 - i * 0.3;
            ctx.shadowColor = `hsla(${270 + i * 15}, 100%, 60%, 1)`;
            ctx.shadowBlur = 25;
            ctx.beginPath();
            ctx.ellipse(portalX, portalY, r, r * 0.55, a, 0, Math.PI * 2);
            ctx.stroke();
            ctx.restore();
        }
        const grad = ctx.createRadialGradient(portalX, portalY, 0, portalX, portalY, portalRadius * 0.4);
        grad.addColorStop(0, 'rgba(150, 100, 255, 0.3)');
        grad.addColorStop(1, 'rgba(150, 100, 255, 0)');
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.arc(portalX, portalY, portalRadius * 0.4, 0, Math.PI * 2);
        ctx.fill();

        textParticles.forEach(t => {
            if (t.exploding) return;
            const dx = portalX - t.x, dy = portalY - t.y;
            const dist = Math.hypot(dx, dy);
            if (dist < portalRadius * 3 && dist > 10) {
                const f = (portalRadius * 3 - dist) / (portalRadius * 3) * 0.6;
                t.vx += (dx / dist) * f;
                t.vy += (dy / dist) * f;
            }
        });
        trailParticles.forEach(p => {
            const dx = portalX - p.x, dy = portalY - p.y;
            const dist = Math.hypot(dx, dy);
            if (dist < portalRadius * 2) { p.vx += (dx / dist) * 0.4; p.vy += (dy / dist) * 0.4; }
        });
    }

    requestAnimationFrame(draw);
}

draw();
