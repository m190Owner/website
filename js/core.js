// ==============================================
// SHARED STATE & UTILITIES
// ==============================================
const visitorId = localStorage.getItem('vid') || Math.random().toString(36).slice(2, 10);
localStorage.setItem('vid', visitorId);

let visitorLocations = [];
let mouseX = 0, mouseY = 0;

// ==============================================
// DOM REFERENCES
// ==============================================
const cursor = document.querySelector('.cursor');
const loader = document.getElementById('loader');
const canvas = document.getElementById('particles');
const ctx = canvas.getContext('2d');
const sfCanvas = document.getElementById('starfield');
const sfCtx = sfCanvas.getContext('2d');
const toastContainer = document.getElementById('toast-container');
let w, h;

// ==============================================
// VISITOR COUNTER + ONLINE TRACKING
// ==============================================
async function counterFetch(action) {
    try {
        const res = await fetch(`counter.php?action=${action}&id=${visitorId}`);
        const data = await res.json();
        document.getElementById('visitor-count').textContent = data.count.toLocaleString();
        document.getElementById('online-count').textContent = data.online;
        document.getElementById('click-count').textContent = data.clicks.toLocaleString();
        if (data.locations) {
            visitorLocations = data.locations;
            if (typeof drawVisitorMap === 'function') drawVisitorMap();
        }
    } catch (e) {}
}

counterFetch('visit');
setInterval(() => counterFetch('heartbeat'), 30000);
document.addEventListener('click', () => counterFetch('click'));

// ==============================================
// CURSOR + MOUSE TRACKING (consolidated)
// ==============================================
document.addEventListener('mousemove', e => {
    mouseX = e.clientX;
    mouseY = e.clientY;
    cursor.style.top = e.clientY + 'px';
    cursor.style.left = e.clientX + 'px';
});

// ==============================================
// CANVAS SETUP
// ==============================================
function resize() {
    w = canvas.width = window.innerWidth;
    h = canvas.height = window.innerHeight;
    sfCanvas.width = w;
    sfCanvas.height = h;
}

window.addEventListener('resize', resize);
resize();

// ==============================================
// SITE UPTIME
// ==============================================
const SITE_LAUNCH = new Date('2025-03-22T16:28:00+13:00').getTime();

function updateUptime() {
    const diff = Date.now() - SITE_LAUNCH;
    const days = Math.floor(diff / 86400000);
    const hrs = Math.floor((diff % 86400000) / 3600000);
    const mins = Math.floor((diff % 3600000) / 60000);
    const secs = Math.floor((diff % 60000) / 1000);

    let str = '';
    if (days > 0) str += days + 'd ';
    str += String(hrs).padStart(2, '0') + ':';
    str += String(mins).padStart(2, '0') + ':';
    str += String(secs).padStart(2, '0');

    document.getElementById('uptime-display').textContent = str;
}

setInterval(updateUptime, 1000);
updateUptime();

// ==============================================
// NOTIFICATION TOASTS
// ==============================================
function showToast(title, message, isVisitor) {
    const toast = document.createElement('div');
    toast.className = 'toast';

    if (isVisitor) {
        const location = title && message ? `${title}, ${message}` : message || 'somewhere';
        toast.innerHTML = `<div class="toast-dot"></div><div class="toast-text">Someone from <strong>${location}</strong> just visited</div>`;
    } else {
        toast.innerHTML = `<div class="toast-dot" style="background:#7aa2ff;box-shadow:0 0 6px #7aa2ff;"></div><div class="toast-text"><strong>${title}</strong> ${message}</div>`;
    }

    toastContainer.appendChild(toast);
    setTimeout(() => toast.remove(), 5000);
}
