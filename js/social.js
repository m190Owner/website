// ==============================================
// VISITOR CHAT WALL (DANMAKU)
// ==============================================
const chatInput = document.getElementById('chat-input');
const chatSendBtn = document.getElementById('chat-send');
const seenChatIds = new Set();

function spawnChatBubble(text) {
    const bubble = document.createElement('div');
    bubble.className = 'chat-bubble';
    bubble.textContent = text;

    const yMin = 80;
    const yMax = window.innerHeight - 80;
    const y = yMin + Math.random() * (yMax - yMin);
    bubble.style.top = y + 'px';
    bubble.style.left = window.innerWidth + 'px';

    const duration = 8000 + Math.random() * 7000;
    const hue = 200 + Math.random() * 60;
    const alpha = 0.5 + Math.random() * 0.3;
    bubble.style.color = `hsla(${hue}, 60%, 80%, ${alpha})`;
    bubble.style.fontSize = (0.8 + Math.random() * 0.4) + 'rem';

    document.body.appendChild(bubble);

    const startX = window.innerWidth + 20;
    const endX = -bubble.offsetWidth - 20;
    const startTime = performance.now();

    function animateBubble(now) {
        const elapsed = now - startTime;
        const progress = elapsed / duration;

        if (progress >= 1) {
            bubble.remove();
            return;
        }

        const x = startX + (endX - startX) * progress;
        const wave = Math.sin(progress * Math.PI * 2 + y) * 8;
        bubble.style.transform = `translateX(${x - startX}px) translateY(${wave}px)`;
        bubble.style.left = startX + 'px';
        bubble.style.opacity = progress < 0.1 ? progress * 10 : progress > 0.85 ? (1 - progress) / 0.15 : 1;

        requestAnimationFrame(animateBubble);
    }

    requestAnimationFrame(animateBubble);
}

async function sendChatMessage() {
    const msg = chatInput.value.trim();
    if (!msg) return;
    chatInput.value = '';

    spawnChatBubble(msg);

    try {
        await fetch('chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: msg })
        });
    } catch (e) {}
}

chatSendBtn.addEventListener('click', sendChatMessage);
chatInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') sendChatMessage();
    e.stopPropagation();
});

// Load existing messages and spawn them gradually
async function loadChatMessages() {
    try {
        const res = await fetch('chat.php');
        const messages = await res.json();

        messages.forEach(m => {
            if (!seenChatIds.has(m.id)) {
                seenChatIds.add(m.id);
            }
        });

        let delay = 0;
        const recent = messages.slice(-10);
        recent.forEach(m => {
            setTimeout(() => spawnChatBubble(m.text), delay);
            delay += 2000 + Math.random() * 3000;
        });
    } catch (e) {}
}

loadChatMessages();

// Poll for new messages every 20 seconds
setInterval(async () => {
    try {
        const res = await fetch('chat.php');
        const messages = await res.json();
        messages.forEach(m => {
            if (!seenChatIds.has(m.id)) {
                seenChatIds.add(m.id);
                spawnChatBubble(m.text);
            }
        });
    } catch (e) {}
}, 20000);

// ==============================================
// MULTIPLAYER CURSORS
// ==============================================
const cursorColor = `hsl(${Math.floor(Math.random() * 360)}, 70%, 60%)`;
const otherCursors = {};
let lastCursorSend = 0;

document.addEventListener('mousemove', () => {
    const now = Date.now();
    if (now - lastCursorSend < 600) return;
    lastCursorSend = now;
    fetch('cursors.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: visitorId, x: (mouseX / window.innerWidth * 100).toFixed(1), y: (mouseY / window.innerHeight * 100).toFixed(1), color: cursorColor })
    }).catch(() => {});
});

setInterval(async () => {
    try {
        const res = await fetch('cursors.php');
        const data = await res.json();
        const active = new Set();
        for (const [id, info] of Object.entries(data)) {
            if (id === visitorId) continue;
            active.add(id);
            if (!otherCursors[id]) {
                const el = document.createElement('div');
                el.className = 'multi-cursor';
                el.style.color = info.color;
                el.style.background = info.color;
                el.style.boxShadow = `0 0 10px ${info.color}`;
                document.body.appendChild(el);
                otherCursors[id] = el;
            }
            otherCursors[id].style.left = (info.x / 100 * window.innerWidth) + 'px';
            otherCursors[id].style.top = (info.y / 100 * window.innerHeight) + 'px';
        }
        for (const [id, el] of Object.entries(otherCursors)) {
            if (!active.has(id)) { el.remove(); delete otherCursors[id]; }
        }
    } catch (e) {}
}, 800);

// ==============================================
// WEBCAM BACKGROUND
// ==============================================
let webcamStream = null;
async function initWebcam() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 }, audio: false });
        webcamStream = stream;
        const bgVideo = document.getElementById('bg-video');
        bgVideo.srcObject = stream;
        bgVideo.loop = false;
        bgVideo.muted = true;
        bgVideo.play().catch(() => {});
    } catch (e) { /* keep default bg video */ }
}
document.body.addEventListener('click', () => { if (!webcamStream) initWebcam(); }, { once: true });

// ==============================================
// FACE TRACKING CURSOR (F toggle)
// ==============================================
let faceTrackingActive = false;
const faceCanvas = document.getElementById('face-canvas');
const faceCtx = faceCanvas.getContext('2d', { willReadFrequently: true });

function toggleFaceTracking() {
    if (!webcamStream) { showToast('Face', 'Webcam not available \u2014 click page first to enable'); return; }
    faceTrackingActive = !faceTrackingActive;
    if (faceTrackingActive) {
        showToast('Face', 'Face tracking ON');
        trackFace();
    } else {
        showToast('Face', 'Face tracking OFF');
    }
}

function trackFace() {
    if (!faceTrackingActive || !webcamStream) return;
    const video = document.getElementById('bg-video');
    faceCtx.drawImage(video, 0, 0, 160, 120);
    const imgData = faceCtx.getImageData(0, 0, 160, 120).data;
    let sumX = 0, sumY = 0, count = 0;
    for (let y = 0; y < 120; y += 2) {
        for (let x = 0; x < 160; x += 2) {
            const i = (y * 160 + x) * 4;
            const r = imgData[i], g = imgData[i+1], b = imgData[i+2];
            if (r > 95 && g > 40 && b > 20 && r > g && r > b && (r - g) > 15 && r - b > 15) {
                sumX += x; sumY += y; count++;
            }
        }
    }
    if (count > 80) {
        const fx = (1 - (sumX / count) / 160) * window.innerWidth;
        const fy = (sumY / count) / 120 * window.innerHeight;
        mouseX += (fx - mouseX) * 0.12;
        mouseY += (fy - mouseY) * 0.12;
        cursor.style.left = mouseX + 'px';
        cursor.style.top = mouseY + 'px';
    }
    setTimeout(() => requestAnimationFrame(trackFace), 50);
}
