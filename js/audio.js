// ==============================================
// MUSIC & BEAT DETECTION
// ==============================================
const audio = document.getElementById("bg-music");
let audioCtx, analyser, dataArray;

function initAudioAnalyzer() {
    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    const source = audioCtx.createMediaElementSource(audio);
    analyser = audioCtx.createAnalyser();
    analyser.fftSize = 256;
    dataArray = new Uint8Array(analyser.frequencyBinCount);
    source.connect(analyser);
    analyser.connect(audioCtx.destination);
}

const volumeSlider = document.getElementById('volume-slider');
const muteBtn = document.getElementById('mute-btn');
let isMuted = false;

volumeSlider.addEventListener('input', () => {
    audio.volume = volumeSlider.value;
    if (audio.volume > 0) {
        isMuted = false;
        muteBtn.textContent = 'Mute';
    }
});

muteBtn.addEventListener('click', () => {
    if (!isMuted) {
        audio.dataset.prevVolume = audio.volume;
        audio.volume = 0;
        muteBtn.textContent = 'Unmute';
        isMuted = true;
    } else {
        audio.volume = audio.dataset.prevVolume || 0.3;
        muteBtn.textContent = 'Mute';
        isMuted = false;
    }
});

function getBeatStrength() {
    if (!analyser) return 0;
    analyser.getByteFrequencyData(dataArray);
    let bass = 0;
    for (let i = 0; i < 40; i++) bass += dataArray[i];
    return bass / 40;
}

let musicStarted = false;
function startMusic() {
    if (musicStarted) return;
    musicStarted = true;
    audio.volume = 0;
    const playPromise = audio.play();
    if (playPromise) {
        playPromise.then(() => {
            let v = 0;
            const fade = setInterval(() => {
                if (v < 0.5) {
                    v += 0.02;
                    audio.volume = v;
                } else clearInterval(fade);
            }, 100);
        }).catch(() => {});
    }
    if (!audioCtx) initAudioAnalyzer();
}

document.body.addEventListener('click', startMusic, { once: true });
document.body.addEventListener('keydown', startMusic, { once: true });

// ==============================================
// TRACK SWITCHING (PREV / NEXT)
// ==============================================
const tracks = [
    'bg-music.mp3',
    'bg-music 1.mp3',
    'bg-music 2.mp3',
    'bg-music 3.mp3',
    'bg-music 4.mp3'
];
let currentTrack = 0;
const trackLabel = document.getElementById('track-name');
const prevBtn = document.getElementById('prev-btn');
const nextBtn = document.getElementById('next-btn');

function switchTrack(index) {
    currentTrack = ((index % tracks.length) + tracks.length) % tracks.length;
    const wasPlaying = !audio.paused;
    const vol = audio.volume;
    audio.src = tracks[currentTrack];
    audio.volume = vol;
    trackLabel.textContent = `${currentTrack + 1}/${tracks.length}`;
    if (wasPlaying) audio.play().catch(() => {});
}

prevBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    switchTrack(currentTrack - 1);
});

nextBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    switchTrack(currentTrack + 1);
});

// ==============================================
// AUDIO WAVEFORM VISUALIZER (NAV)
// ==============================================
const waveCanvas = document.getElementById('nav-waveform');
const waveCtx = waveCanvas.getContext('2d');
const barCount = 20;

function drawWaveform() {
    waveCtx.clearRect(0, 0, waveCanvas.width, waveCanvas.height);
    const barW = waveCanvas.width / barCount;
    const wh = waveCanvas.height;

    if (analyser && dataArray) {
        analyser.getByteFrequencyData(dataArray);
        for (let i = 0; i < barCount; i++) {
            const idx = Math.floor(i * (dataArray.length / barCount));
            const val = dataArray[idx] / 255;
            const barH = Math.max(2, val * wh);
            const hue = 220 + val * 40;

            waveCtx.fillStyle = `hsla(${hue}, 80%, 70%, ${0.5 + val * 0.5})`;
            waveCtx.shadowColor = `hsla(${hue}, 100%, 70%, 0.6)`;
            waveCtx.shadowBlur = 4;

            const x = i * barW;
            const y = (wh - barH) / 2;
            waveCtx.beginPath();
            waveCtx.roundRect(x + 1, y, barW - 2, barH, 1);
            waveCtx.fill();
        }
    } else {
        const t = Date.now() / 1000;
        for (let i = 0; i < barCount; i++) {
            const val = 0.15 + Math.sin(t * 2 + i * 0.5) * 0.1;
            const barH = Math.max(2, val * wh);
            waveCtx.fillStyle = `hsla(220, 60%, 60%, 0.3)`;
            const x = i * barW;
            const y = (wh - barH) / 2;
            waveCtx.beginPath();
            waveCtx.roundRect(x + 1, y, barW - 2, barH, 1);
            waveCtx.fill();
        }
    }

    requestAnimationFrame(drawWaveform);
}

drawWaveform();

// ==============================================
// DJ MODE (D toggle)
// ==============================================
let djActive = false, djFilter = null, djDelay = null, djDelayGain = null;

function initDJEffects() {
    if (!audioCtx || djFilter) return;
    djFilter = audioCtx.createBiquadFilter();
    djFilter.type = 'lowpass';
    djFilter.frequency.value = 20000;
    analyser.disconnect();
    analyser.connect(djFilter);
    djFilter.connect(audioCtx.destination);
    djDelay = audioCtx.createDelay(0.5);
    djDelay.delayTime.value = 0.15;
    djDelayGain = audioCtx.createGain();
    djDelayGain.gain.value = 0;
    djFilter.connect(djDelay);
    djDelay.connect(djDelayGain);
    djDelayGain.connect(audioCtx.destination);
    djDelayGain.connect(djDelay);
}

function toggleDJMode() {
    djActive = !djActive;
    const ovl = document.getElementById('dj-overlay');
    const disc = document.getElementById('dj-disc');
    if (djActive) {
        ovl.classList.add('active');
        disc.classList.add('spinning');
        if (audioCtx) initDJEffects();
    } else {
        ovl.classList.remove('active');
        audio.playbackRate = 1;
        if (djFilter) djFilter.frequency.value = 20000;
        if (djDelayGain) djDelayGain.gain.value = 0;
        document.getElementById('dj-speed').value = 1;
        document.getElementById('dj-speed-val').textContent = '1.00x';
        document.getElementById('dj-filter').value = 20000;
        document.getElementById('dj-filter-val').textContent = '20kHz';
        document.getElementById('dj-echo').value = 0;
        document.getElementById('dj-echo-val').textContent = '0%';
    }
}

// DJ sliders
document.getElementById('dj-speed').addEventListener('input', (e) => {
    audio.playbackRate = parseFloat(e.target.value);
    document.getElementById('dj-speed-val').textContent = parseFloat(e.target.value).toFixed(2) + 'x';
});
document.getElementById('dj-filter').addEventListener('input', (e) => {
    if (djFilter) djFilter.frequency.value = parseFloat(e.target.value);
    const v = parseFloat(e.target.value);
    document.getElementById('dj-filter-val').textContent = v >= 1000 ? (v/1000).toFixed(1) + 'kHz' : v + 'Hz';
});
document.getElementById('dj-echo').addEventListener('input', (e) => {
    const v = parseFloat(e.target.value);
    document.getElementById('dj-echo-val').textContent = Math.round(v * 100) + '%';
    if (djDelayGain) djDelayGain.gain.value = v * 0.45;
});

// Disc scratch (drag)
const djDisc = document.getElementById('dj-disc');
let discDragging = false, discLastX = 0;
djDisc.addEventListener('mousedown', (e) => {
    discDragging = true; discLastX = e.clientX;
    djDisc.classList.remove('spinning'); djDisc.style.cursor = 'grabbing';
});
document.addEventListener('mousemove', (e) => {
    if (!discDragging) return;
    const dx = e.clientX - discLastX;
    audio.playbackRate = Math.max(0.1, Math.min(3, 1 + dx * 0.008));
    document.getElementById('dj-speed').value = audio.playbackRate;
    document.getElementById('dj-speed-val').textContent = audio.playbackRate.toFixed(2) + 'x';
    const rot = parseFloat(djDisc.dataset.rot || 0) + dx * 0.5;
    djDisc.dataset.rot = rot;
    djDisc.style.transform = `rotate(${rot}deg)`;
    discLastX = e.clientX;
});
document.addEventListener('mouseup', () => {
    if (!discDragging) return;
    discDragging = false;
    djDisc.style.cursor = 'grab';
    if (djActive) { djDisc.classList.add('spinning'); djDisc.style.transform = ''; }
    const snap = setInterval(() => {
        const diff = 1 - audio.playbackRate;
        if (Math.abs(diff) < 0.05) { audio.playbackRate = 1; clearInterval(snap); }
        else audio.playbackRate += diff * 0.15;
        document.getElementById('dj-speed').value = audio.playbackRate;
        document.getElementById('dj-speed-val').textContent = audio.playbackRate.toFixed(2) + 'x';
    }, 30);
});
