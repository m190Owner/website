// ==============================================
// FLAPPY BIRD (F1)
// ==============================================
let flappyActive = false, flappyCanvas, flappyCtx;
let fBird, fPipes, fScore, fState;

function toggleFlappy() {
    flappyActive = !flappyActive;
    if (flappyActive) {
        flappyCanvas = document.createElement('canvas');
        flappyCanvas.style.cssText = 'position:fixed;inset:0;z-index:99998;cursor:pointer;';
        flappyCanvas.width = window.innerWidth;
        flappyCanvas.height = window.innerHeight;
        flappyCtx = flappyCanvas.getContext('2d');
        document.body.appendChild(flappyCanvas);
        fBird = { x: 150, y: flappyCanvas.height / 2, vy: 0 };
        fPipes = []; fScore = 0; fState = 'wait';
        flappyCanvas.onclick = flappyClick;
        flappyLoop();
    } else {
        if (flappyCanvas) { flappyCanvas.remove(); flappyCanvas = null; }
    }
}

function flappyClick() {
    if (fState === 'wait') { fState = 'play'; fBird.vy = -6.5; }
    else if (fState === 'play') { fBird.vy = -6.5; }
    else if (fState === 'dead') { fBird = { x: 150, y: flappyCanvas.height / 2, vy: 0 }; fPipes = []; fScore = 0; fState = 'wait'; }
}

function flappyLoop() {
    if (!flappyActive || !flappyCanvas) return;
    const W = flappyCanvas.width, H = flappyCanvas.height, fc = flappyCtx;
    const GAP = 160, PW = 55;
    fc.clearRect(0, 0, W, H);
    fc.fillStyle = 'rgba(11,11,15,0.95)'; fc.fillRect(0, 0, W, H);
    // Ground
    fc.fillStyle = '#1a1a2e'; fc.fillRect(0, H - 35, W, 35);
    fc.fillStyle = '#7aa2ff'; fc.fillRect(0, H - 35, W, 2);

    if (fState === 'play') {
        fBird.vy += 0.35; fBird.y += fBird.vy;
        if (fPipes.length === 0 || fPipes[fPipes.length - 1].x < W - 280)
            fPipes.push({ x: W, gy: 100 + Math.random() * (H - 300), sc: false });
        fPipes.forEach(p => p.x -= 2.8);
        fPipes = fPipes.filter(p => p.x > -PW);
        if (fBird.y > H - 55 || fBird.y < 10) fState = 'dead';
        fPipes.forEach(p => {
            if (fBird.x + 18 > p.x && fBird.x - 18 < p.x + PW) {
                if (fBird.y - 18 < p.gy || fBird.y + 18 > p.gy + GAP) fState = 'dead';
            }
            if (!p.sc && p.x + PW < fBird.x) { p.sc = true; fScore++; }
        });
    }

    // Pipes
    fPipes.forEach(p => {
        fc.fillStyle = '#7aa2ff';
        fc.fillRect(p.x, 0, PW, p.gy);
        fc.fillStyle = '#5a82d0'; fc.fillRect(p.x - 3, p.gy - 18, PW + 6, 18);
        fc.fillStyle = '#7aa2ff';
        fc.fillRect(p.x, p.gy + GAP, PW, H - p.gy - GAP - 35);
        fc.fillStyle = '#5a82d0'; fc.fillRect(p.x - 3, p.gy + GAP, PW + 6, 18);
    });

    // Bird
    fc.save();
    fc.translate(fBird.x, fBird.y);
    fc.rotate(Math.min(Math.max(fBird.vy * 0.05, -0.4), 0.8));
    fc.fillStyle = '#7aa2ff'; fc.shadowColor = '#7aa2ff'; fc.shadowBlur = 15;
    fc.beginPath(); fc.arc(0, 0, 18, 0, Math.PI * 2); fc.fill();
    fc.shadowBlur = 0; fc.fillStyle = '#0b0b0f';
    fc.font = 'bold 13px Segoe UI'; fc.textAlign = 'center'; fc.textBaseline = 'middle';
    fc.fillText('LS', 0, 0);
    fc.restore();

    // Score
    fc.fillStyle = '#fff'; fc.font = 'bold 48px Segoe UI'; fc.textAlign = 'center';
    fc.fillText(fScore, W / 2, 80);

    if (fState === 'wait') {
        fc.fillStyle = '#7aa2ff'; fc.font = '22px Segoe UI';
        fc.fillText('Click or Space to Start', W / 2, H / 2 + 60);
        fc.fillStyle = '#5a6480'; fc.font = '14px Segoe UI';
        fc.fillText('F1 to exit', W / 2, H / 2 + 90);
    }
    if (fState === 'dead') {
        fc.fillStyle = 'rgba(0,0,0,0.5)'; fc.fillRect(0, 0, W, H);
        fc.fillStyle = '#ff4444'; fc.font = 'bold 64px Segoe UI'; fc.textAlign = 'center';
        fc.fillText('GAME OVER', W / 2, H / 2 - 20);
        fc.fillStyle = '#fff'; fc.font = '26px Segoe UI';
        fc.fillText('Score: ' + fScore, W / 2, H / 2 + 30);
        fc.fillStyle = '#7aa2ff'; fc.font = '16px Segoe UI';
        fc.fillText('Click to retry \u00B7 F1 to exit', W / 2, H / 2 + 65);
    }
    requestAnimationFrame(flappyLoop);
}
