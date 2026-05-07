
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

    }

    requestAnimationFrame(draw);
}

draw();
