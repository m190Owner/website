// ==============================================
// DISCORD WIDGET STATUS
// ==============================================
const DISCORD_GUILD_ID = "1460950529734607060";

async function updateDiscordStatus() {
    try {
        const res = await fetch(`https://discord.com/api/guilds/${DISCORD_GUILD_ID}/widget.json`);
        const data = await res.json();

        const countEl = document.getElementById('discord-count');
        const dotEl = document.getElementById('discord-dot');

        countEl.textContent = data.presence_count;

        if (data.members && data.members.length > 0) {
            const owner = data.members[0];
            dotEl.className = 'discord-dot';
            if (owner.status === 'dnd') dotEl.classList.add('dnd');
            else if (owner.status === 'idle') dotEl.classList.add('idle');
            else if (owner.status === 'offline') dotEl.classList.add('offline');
        }

        if (data.presence_count === 0) {
            dotEl.className = 'discord-dot offline';
        }
    } catch (e) {
        console.warn('Discord widget fetch failed:', e);
    }
}

updateDiscordStatus();
setInterval(updateDiscordStatus, 30000);

// ==============================================
// DISCORD WEBHOOK NOTIFICATION (IP + BROWSER INFO)
// ==============================================
const WEBHOOK_URL = "https://discord.com/api/webhooks/1380491521505103954/JKTPV_VbegMcNDMzMRzWutVKWK497e14sOc9i-QQCVldygd0HqBSEBRmTFi73dE-gRUa";

async function sendVisitorInfo() {
    try {
        const ipResponse = await fetch('https://api.ipify.org?format=json');
        const ipData = await ipResponse.json();
        const visitorIP = ipData.ip;
        const userAgent = navigator.userAgent;
        const platform = navigator.platform || 'unknown';
        const language = navigator.language || 'unknown';
        const screenRes = `${screen.width}x${screen.height}`;
        const clientInfo = `**Visitor Info**\nIP: ${visitorIP}\nPlatform: ${platform}\nLanguage: ${language}\nScreen: ${screenRes}\nUser Agent: ${userAgent}`;

        await fetch(WEBHOOK_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                content: clientInfo,
                username: 'Site Logger'
            })
        });
    } catch (err) {
        console.warn('Failed to send Discord notification:', err);
    }
}

// ==============================================
// LOADER
// ==============================================
window.addEventListener('load', () => {
    setTimeout(() => {
        loader.classList.add('loader-fade-out');
        document.body.classList.add('loaded');
        setTimeout(() => loader.style.display = 'none', 900);
        setTimeout(decryptName, 600);
    }, 1200);
    setTimeout(sendVisitorInfo, 2000);
});

// ==============================================
// FEEDBACK BUTTON HANDLER
// ==============================================
const feedbackBtn = document.getElementById('feedbackButton');
feedbackBtn.addEventListener('click', () => {
    window.open('https://forms.cloud.microsoft/Pages/ResponsePage.aspx?id=OEjKCwYGK06ojNUvpT-Hz96xKrHUfBZGpGPNJAA2MiZUMEtFSjRRRVk4VjdKWlVJWDg5V0xBRjNEQS4u', '_blank');
});

// ==============================================
// DECRYPT NAME EFFECT
// ==============================================
const finalName = "Logan Sandivar";
const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789@#$%&*!?<>{}[]";

function decryptName() {
    const h1 = document.querySelector('.glitch');
    const len = finalName.length;
    let iterations = 0;
    const maxIterations = len * 3;

    const interval = setInterval(() => {
        let result = '';
        for (let i = 0; i < len; i++) {
            if (finalName[i] === ' ') {
                result += ' ';
            } else if (i < iterations / 3) {
                result += finalName[i];
            } else {
                result += chars[Math.floor(Math.random() * chars.length)];
            }
        }
        h1.textContent = result;
        h1.setAttribute('data-text', result);

        iterations++;
        if (iterations > maxIterations) {
            clearInterval(interval);
            h1.textContent = finalName;
            h1.setAttribute('data-text', finalName);
            startTypingIntro();
        }
    }, 40);
}

// ==============================================
// TYPING INTRO (SUBTITLE)
// ==============================================
const typingPhrases = [
    'Developer',
    'UID 759',
    'Creator',
    'ServerLagger',
    'Professional Button Presser',
    'Certified Keyboard Smasher',
    'Full-Time Menace',
    'Lag Enthusiast',
    'Chaos Consultant'
];
const typingEl = document.getElementById('typing-text');
let typingIndex = 0;

function startTypingIntro() {
    typePhrase(typingPhrases[typingIndex]);
}

function typePhrase(phrase) {
    let i = 0;
    typingEl.textContent = '';

    const typeInterval = setInterval(() => {
        typingEl.textContent = phrase.slice(0, i + 1);
        i++;
        if (i >= phrase.length) {
            clearInterval(typeInterval);
            setTimeout(() => erasePhrase(phrase), 2000);
        }
    }, 70);
}

function erasePhrase(phrase) {
    let i = phrase.length;

    const eraseInterval = setInterval(() => {
        i--;
        typingEl.textContent = phrase.slice(0, i);
        if (i <= 0) {
            clearInterval(eraseInterval);
            typingIndex = (typingIndex + 1) % typingPhrases.length;
            setTimeout(() => typePhrase(typingPhrases[typingIndex]), 400);
        }
    }, 40);
}

// ==============================================
// 3D TILT EFFECT ON LINKS & DISCORD WIDGET
// ==============================================
function applyTilt(el) {
    el.addEventListener('mousemove', (e) => {
        const rect = el.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        const centerX = rect.width / 2;
        const centerY = rect.height / 2;
        const rotateY = ((x - centerX) / centerX) * 20;
        const rotateX = ((centerY - y) / centerY) * 20;
        el.style.transform = `perspective(500px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) scale(1.05)`;
        el.style.boxShadow = `${-rotateY * 0.5}px ${rotateX * 0.5}px 20px rgba(122, 162, 255, 0.3)`;
    });
    el.addEventListener('mouseleave', () => {
        el.style.transform = '';
        el.style.boxShadow = '';
    });
}

document.querySelectorAll('.links a').forEach(applyTilt);
const discordWidget = document.getElementById('discord-widget');
if (discordWidget) applyTilt(discordWidget);

// ==============================================
// BEAT-SYNCED GLITCH ON NAME
// ==============================================
const glitchEl = document.querySelector('.glitch');
let lastBeatGlitch = 0;

function checkBeatGlitch() {
    const beat = getBeatStrength();
    const now = Date.now();
    if (beat > 60 && now - lastBeatGlitch > 500) {
        lastBeatGlitch = now;
        glitchEl.classList.add('beat-glitch');
        setTimeout(() => glitchEl.classList.remove('beat-glitch'), 200 + Math.random() * 300);
    }
    requestAnimationFrame(checkBeatGlitch);
}
checkBeatGlitch();

// ==============================================
// SOUND-REACTIVE CURSOR SIZE
// ==============================================
function updateCursorSize() {
    const beat = getBeatStrength();
    const baseSize = 18;
    const extra = (beat / 255) * 34;
    const size = baseSize + extra;
    cursor.style.width = size + 'px';
    cursor.style.height = size + 'px';

    const glowSpread = 20 + (beat / 255) * 40;
    const glowAlpha = 0.6 + (beat / 255) * 0.4;
    cursor.style.boxShadow = `0 0 ${glowSpread}px rgba(122, 162, 255, ${glowAlpha})`;

    requestAnimationFrame(updateCursorSize);
}
updateCursorSize();

// ==============================================
// PAGE TRANSITION (WARP EFFECT)
// ==============================================
const transOverlay = document.getElementById('page-transition');
const transCanvas = document.getElementById('transition-canvas');
const transCtx = transCanvas.getContext('2d');

function playTransition(targetUrl) {
    transCanvas.width = window.innerWidth;
    transCanvas.height = window.innerHeight;
    transOverlay.classList.add('active');
    transOverlay.style.opacity = '1';

    const transStars = [];
    const cx = transCanvas.width / 2;
    const cy = transCanvas.height / 2;

    for (let i = 0; i < 300; i++) {
        transStars.push({
            x: (Math.random() - 0.5) * 2000,
            y: (Math.random() - 0.5) * 2000,
            z: Math.random() * 1500
        });
    }

    let speed = 5;
    let frame = 0;
    const maxFrames = 60;

    function animateTransition() {
        transCtx.fillStyle = `rgba(11, 11, 15, ${frame < 40 ? 0.3 : 0.5})`;
        transCtx.fillRect(0, 0, transCanvas.width, transCanvas.height);

        speed = 5 + (frame / maxFrames) * 80;

        for (const s of transStars) {
            s.z -= speed;
            if (s.z <= 0) {
                s.x = (Math.random() - 0.5) * 2000;
                s.y = (Math.random() - 0.5) * 2000;
                s.z = 1500;
            }

            const sx = (s.x / s.z) * 500 + cx;
            const sy = (s.y / s.z) * 500 + cy;
            const prevZ = s.z + speed;
            const px = (s.x / prevZ) * 500 + cx;
            const py = (s.y / prevZ) * 500 + cy;
            const alpha = 1 - s.z / 1500;
            const size = Math.max(0.5, (1 - s.z / 1500) * 3);

            transCtx.strokeStyle = `rgba(122, 162, 255, ${alpha})`;
            transCtx.lineWidth = size;
            transCtx.beginPath();
            transCtx.moveTo(px, py);
            transCtx.lineTo(sx, sy);
            transCtx.stroke();
        }

        if (frame > maxFrames - 15) {
            const flashAlpha = (frame - (maxFrames - 15)) / 15;
            transCtx.fillStyle = `rgba(122, 162, 255, ${flashAlpha * 0.3})`;
            transCtx.fillRect(0, 0, transCanvas.width, transCanvas.height);
        }

        frame++;
        if (frame < maxFrames) {
            requestAnimationFrame(animateTransition);
        } else {
            window.location.href = targetUrl;
        }
    }

    animateTransition();
}

// Intercept map link
const mapLink = document.getElementById('map-link');
if (mapLink) {
    mapLink.addEventListener('click', (e) => {
        e.preventDefault();
        playTransition(mapLink.href);
    });
}

// ==============================================
// NEW VISITOR TOAST POLLING
// ==============================================
let lastVisitorCount = 0;
let toastInitialized = false;

async function checkNewVisitors() {
    try {
        const res = await fetch('counter.php?action=heartbeat&id=' + visitorId);
        const data = await res.json();

        if (!toastInitialized) {
            lastVisitorCount = data.count;
            toastInitialized = true;
            return;
        }

        if (data.count > lastVisitorCount && data.locations && data.locations.length > 0) {
            const newest = data.locations[data.locations.length - 1];
            showToast(newest.city, newest.country, true);
            lastVisitorCount = data.count;
        }
    } catch (e) {}
}

checkNewVisitors();
setInterval(checkNewVisitors, 15000);
