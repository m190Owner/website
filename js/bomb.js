// ==============================================
// TIME BOMB (T) + AFTERMATH SEQUENCE
// ==============================================
let bombActive = false;
let bombCount = 0;

function startTimeBomb() {
    if (bombActive) return;
    bombActive = true;
    bombCount++;
    const display = document.getElementById('bomb-display');
    const numEl = document.getElementById('bomb-number');
    display.classList.add('active');
    let count = 10;
    numEl.textContent = count;
    numEl.style.color = '#ff3333';
    numEl.style.fontSize = '14rem';

    const tick = setInterval(() => {
        count--;
        if (count > 0) {
            numEl.textContent = count;
            if (count <= 3) { numEl.style.color = '#ff0000'; numEl.style.fontSize = '18rem'; }
            const shake = (10 - count) * 2;
            document.body.style.transform = `translate(${(Math.random()-0.5)*shake}px, ${(Math.random()-0.5)*shake}px)`;
        } else {
            clearInterval(tick);
            numEl.textContent = '\u{1F4A5}';
            numEl.style.fontSize = '16rem';
            audio.pause();
            addShockwave(w / 2, h / 2, { glow: '#ff4400' }, 200, 3);
            addShockwave(w / 2, h / 2, { glow: '#ff8800' }, 150, 2);
            let sf = 0;
            function bombShake() {
                sf++;
                const i = Math.max(0, (1 - sf / 60)) * 45;
                document.body.style.transform = `translate(${(Math.random()-0.5)*i}px, ${(Math.random()-0.5)*i}px)`;
                if (sf < 60) requestAnimationFrame(bombShake);
                else {
                    document.body.style.transform = '';
                    display.classList.remove('active');
                    if (bombCount >= 3) {
                        playBIOSBoot();
                    } else if (bombCount >= 2) {
                        playBombRefusal();
                    } else {
                        playBombAftermath();
                    }
                }
            }
            bombShake();
        }
    }, 1000);
}

// ==============================================
// BOMB AFTERMATH (1st time)
// ==============================================
function playBombAftermath() {
    const ac_el = document.createElement('canvas');
    ac_el.style.cssText = 'position:fixed;inset:0;z-index:99999;cursor:default;';
    ac_el.width = window.innerWidth;
    ac_el.height = window.innerHeight;
    document.body.appendChild(ac_el);
    const ac = ac_el.getContext('2d');
    const W = ac_el.width, H = ac_el.height;
    const centerX = W / 2;

    const deskX = centerX;
    const floorY = H * 0.68;
    const targetX = deskX - 50;
    let stickX = -60;
    let frame = 0;
    let walkCycle = 0;
    let currentSpeech = '';
    let speechStart = 0;
    let codeLines = [];
    let revealedZones = [];

    const speeches = [
        { frame: 180, text: '...' },
        { frame: 350, text: 'Are you serious right now.' },
        { frame: 600, text: 'I was on my lunch break.' },
        { frame: 900, text: '*sigh*' },
        { frame: 1100, text: 'I hate people.' },
        { frame: 1500, text: 'Let me find my chair...' },
        { frame: 1800, text: 'Ok. Where do I even begin.' },
        { frame: 2100, text: 'The ENTIRE site is gone. Everything.' },
        { frame: 2400, text: 'Do you understand what you\'ve done?' },
        { frame: 2700, text: 'Months of work. Gone. Because you pressed T.' },
        { frame: 3000, text: 'You know what... whatever. Let me just rebuild it.' },
        { frame: 3300, text: '*opens IDE*' },
        { frame: 3500, text: 'First the navigation...' },
        { frame: 3800, text: 'There. Was that so hard? Oh wait... I\'M the one doing the work.' },
        { frame: 4100, text: 'Now the title... Logan Sandivar...' },
        { frame: 4400, text: 'You know he\'s going to blame me for this, right?' },
        { frame: 4700, text: '*aggressive typing intensifies*' },
        { frame: 5000, text: 'Links... particles... starfield... the whole shabang.' },
        { frame: 5300, text: 'I could be gaming right now. I could be doing ANYTHING else.' },
        { frame: 5600, text: 'But no. Someone had to press the button.' },
        { frame: 5900, text: 'The button that LITERALLY says "bomb" next to it.' },
        { frame: 6200, text: 'You read "time bomb" and thought "yeah let me click that"' },
        { frame: 6500, text: 'Genius. Absolute genius.' },
        { frame: 6800, text: 'Almost done... just the bottom bar left...' },
        { frame: 7100, text: 'There. It\'s back. The website exists again.' },
        { frame: 7400, text: 'Now listen to me very carefully.' },
        { frame: 7600, text: 'Don\'t. EVER. Press T. Again.' },
        { frame: 7900, text: 'I am not paid enough for this.' },
        { frame: 8100, text: 'Actually... I\'m not paid at all.' },
        { frame: 8300, text: 'I\'m a stick figure. I don\'t even have fingers.' },
        { frame: 8500, text: 'And yet here I am. Rebuilding YOUR website.' },
        { frame: 8700, text: 'Alright I\'m leaving. Don\'t touch ANYTHING.' },
    ];

    const zones = [
        { frame: 3500, x: 0, y: 0, w: W, h: 55 },
        { frame: 4100, x: W*0.25, y: H*0.25, w: W*0.5, h: 60 },
        { frame: 4700, x: W*0.15, y: H*0.4, w: W*0.7, h: 80 },
        { frame: 5500, x: 0, y: 0, w: W, h: H },
        { frame: 6800, x: 0, y: H-45, w: W, h: 45 },
    ];

    const fakeCode = [
        '// INCIDENT REPORT: visitor pressed T',
        '// date: today. damage: total.',
        '',
        'function fixWebsite() {',
        '  // step 1: question life choices',
        '  console.log("why me");',
        '',
        '  // step 2: ban the visitor',
        '  if (visitor.pressedBomb) {',
        '    visitor.ban("forever");',
        '    visitor.sendEmail("you are banned");',
        '    return "deserved";',
        '  }',
        '',
        '  // step 3: rebuild everything',
        '  rebuildNav();',
        '  // this took 3 hours originally',
        '  rebuildContent();',
        '  // this took 5 hours originally',
        '  loadParticles();',
        '  loadStarfield();',
        '  loadCursorEffects();',
        '  initBeatDetection();',
        '  initDiscordWidget();',
        '  // ffs',
        '  loadVisitorCounter();',
        '  loadChatWall();',
        '  initGravityMode();',
        '  // i should add a password to T',
        '  initEarthquake();',
        '  initDJMode();',
        '  loadMultiplayerCursors();',
        '',
        '  // step 4: contemplate existence',
        '  while (true) {',
        '    cry();',
        '    if (feelBetter()) break;',
        '    // spoiler: never breaks',
        '  }',
        '',
        '  console.log("site rebuilt... again");',
        '  alert("STOP PRESSING T");',
        '}',
        '',
        '// note to self:',
        '// - remove the T key from keyboard',
        '// - add landmine under T key',
        '// - require 3 forms of ID to press T',
        '',
        'fixWebsite();',
        '// time elapsed: too long',
        '// will to live: depleted',
        '// coffee consumed: yes',
    ];

    function draw404() {
        ac.fillStyle = '#f0f0f0';
        ac.fillRect(0, 0, W, H);
        ac.fillStyle = '#e0e0e0';
        ac.fillRect(0, 0, W, 35);
        ac.fillStyle = '#fff';
        ac.beginPath();
        ac.roundRect(70, 7, W - 140, 21, 10);
        ac.fill();
        ac.fillStyle = '#999';
        ac.font = '12px Segoe UI';
        ac.textAlign = 'left';
        ac.fillText('logansandivar.com', 85, 22);
        ac.fillStyle = '#ff5f57'; ac.beginPath(); ac.arc(20, 17, 6, 0, Math.PI*2); ac.fill();
        ac.fillStyle = '#febc2e'; ac.beginPath(); ac.arc(38, 17, 6, 0, Math.PI*2); ac.fill();
        ac.fillStyle = '#28c840'; ac.beginPath(); ac.arc(56, 17, 6, 0, Math.PI*2); ac.fill();

        ac.fillStyle = '#222';
        ac.font = 'bold 140px Segoe UI';
        ac.textAlign = 'center';
        ac.fillText('404', centerX, H * 0.3);
        ac.fillStyle = '#555';
        ac.font = '28px Segoe UI';
        ac.fillText('Page Not Found', centerX, H * 0.3 + 50);
        ac.fillStyle = '#999';
        ac.font = '16px Segoe UI';
        ac.fillText('The page you were looking for has been obliterated by a visitor.', centerX, H * 0.3 + 90);
        ac.fillText('Dispatching developer...', centerX, H * 0.3 + 120);
    }

    function drawDesk(x, y) {
        ac.fillStyle = '#444';
        ac.fillRect(x - 65, y - 5, 8, 35);
        ac.fillRect(x - 65, y - 35, 8, 35);
        ac.fillStyle = '#6b4226';
        ac.fillRect(x - 45, y, 130, 6);
        ac.fillStyle = '#5a3520';
        ac.fillRect(x - 40, y + 6, 5, 35);
        ac.fillRect(x + 80, y + 6, 5, 35);
        ac.fillStyle = '#1a1a1a';
        ac.fillRect(x, y - 42, 65, 42);
        ac.fillStyle = '#0d1117';
        ac.fillRect(x + 3, y - 39, 59, 36);
        ac.fillStyle = '#333';
        ac.fillRect(x + 27, y, 11, 5);
        ac.fillRect(x + 20, y + 2, 25, 3);
        ac.fillStyle = '#555';
        ac.fillRect(x + 5, y - 3, 40, 4);
        ac.font = '6px Consolas, monospace';
        ac.textAlign = 'left';
        const visibleLines = codeLines.slice(-5);
        visibleLines.forEach((line, i) => {
            ac.fillStyle = line.startsWith('//') ? '#6a9955' : line.startsWith('  ') ? '#9cdcfe' : '#dcdcaa';
            ac.fillText(line.substring(0, 14), x + 5, y - 32 + i * 7);
        });
    }

    function drawStickFigure(x, y, pose, anim) {
        const headR = 14;
        const headY = y - 58;
        const neckY = headY + headR;
        const shoulderY = y - 38;
        const hipY = y - 5;

        ac.strokeStyle = '#333';
        ac.fillStyle = '#333';
        ac.lineWidth = 3;
        ac.lineCap = 'round';
        ac.lineJoin = 'round';

        ac.beginPath();
        ac.arc(x, headY, headR, 0, Math.PI * 2);
        ac.stroke();

        ac.beginPath(); ac.arc(x - 5, headY - 3, 2, 0, Math.PI * 2); ac.fill();
        ac.beginPath(); ac.arc(x + 5, headY - 3, 2, 0, Math.PI * 2); ac.fill();
        ac.lineWidth = 2;
        ac.beginPath(); ac.moveTo(x - 9, headY - 9); ac.lineTo(x - 2, headY - 7); ac.stroke();
        ac.beginPath(); ac.moveTo(x + 9, headY - 9); ac.lineTo(x + 2, headY - 7); ac.stroke();
        ac.beginPath(); ac.arc(x, headY + 10, 5, 0.15 * Math.PI, 0.85 * Math.PI, false); ac.stroke();
        ac.lineWidth = 3;

        ac.beginPath(); ac.moveTo(x, neckY); ac.lineTo(x, hipY); ac.stroke();

        if (pose === 'walk') {
            const legSwing = Math.sin(anim) * 18;
            const armSwing = Math.sin(anim) * 15;
            ac.beginPath(); ac.moveTo(x, shoulderY); ac.lineTo(x - 12 + armSwing, shoulderY + 22); ac.stroke();
            ac.beginPath(); ac.moveTo(x, shoulderY); ac.lineTo(x + 12 - armSwing, shoulderY + 22); ac.stroke();
            ac.beginPath(); ac.moveTo(x, hipY); ac.lineTo(x + legSwing, y + 20); ac.stroke();
            ac.beginPath(); ac.moveTo(x, hipY); ac.lineTo(x - legSwing, y + 20); ac.stroke();
        } else if (pose === 'sit') {
            const armBob = Math.sin(anim * 0.3) * 2;
            ac.beginPath(); ac.moveTo(x, shoulderY); ac.lineTo(x + 30, y - 2 + armBob); ac.stroke();
            ac.beginPath(); ac.moveTo(x, shoulderY); ac.lineTo(x + 45, y - 2 - armBob); ac.stroke();
            ac.beginPath(); ac.moveTo(x, hipY); ac.lineTo(x + 12, y + 10); ac.lineTo(x + 5, y + 30); ac.stroke();
            ac.beginPath(); ac.moveTo(x, hipY); ac.lineTo(x - 5, y + 10); ac.lineTo(x - 12, y + 30); ac.stroke();
        }
    }

    function drawSpeechBubble(x, y, text) {
        ac.save();
        ac.font = '15px Segoe UI';
        ac.textAlign = 'center';
        const tw = ac.measureText(text).width;
        const pad = 16;
        const bw = tw + pad * 2;
        const bh = 34;
        const bx = x - bw / 2;
        const by = y - bh - 18;

        ac.fillStyle = 'white';
        ac.shadowColor = 'rgba(0,0,0,0.15)';
        ac.shadowBlur = 8;
        ac.beginPath();
        ac.roundRect(bx, by, bw, bh, 12);
        ac.fill();
        ac.shadowBlur = 0;
        ac.strokeStyle = '#ddd';
        ac.lineWidth = 1;
        ac.stroke();

        ac.fillStyle = 'white';
        ac.beginPath();
        ac.moveTo(x - 6, by + bh);
        ac.lineTo(x, by + bh + 10);
        ac.lineTo(x + 6, by + bh);
        ac.closePath();
        ac.fill();
        ac.stroke();

        ac.fillStyle = '#333';
        ac.fillText(text, x, by + bh / 2 + 5);
        ac.restore();
    }

    function animateAftermath() {
        frame++;
        ac.clearRect(0, 0, W, H);

        for (let i = speeches.length - 1; i >= 0; i--) {
            if (frame >= speeches[i].frame && frame < speeches[i].frame + 200) {
                currentSpeech = speeches[i].text;
                speechStart = speeches[i].frame;
                break;
            }
        }

        if (frame > 1800 && frame % 40 === 0) {
            const lineIdx = Math.floor((frame - 1800) / 40);
            if (lineIdx < fakeCode.length) codeLines.push(fakeCode[lineIdx]);
        }

        zones.forEach(z => {
            if (frame >= z.frame && !revealedZones.includes(z)) revealedZones.push(z);
        });

        const fadeProgress = Math.max(0, Math.min(1, (frame - 4000) / 3000));
        if (fadeProgress < 1) {
            ac.globalAlpha = 1 - fadeProgress * 0.8;
            draw404();
            ac.globalAlpha = 1;
        }

        ac.save();
        ac.globalCompositeOperation = 'destination-out';
        revealedZones.forEach(z => {
            const zoneAge = frame - z.frame;
            const zoneAlpha = Math.min(1, zoneAge / 30);
            ac.globalAlpha = zoneAlpha;
            ac.fillStyle = 'white';
            ac.fillRect(z.x, z.y, z.w, z.h);
        });
        ac.restore();

        if (frame > 1300) {
            drawDesk(deskX, floorY);
        }

        if (frame > 300 && stickX < targetX) {
            stickX += 1.8;
            walkCycle += 0.14;
            drawStickFigure(stickX, floorY, 'walk', walkCycle);
        } else if (stickX >= targetX) {
            drawStickFigure(targetX, floorY, 'sit', frame);
        }

        if (frame > 8700) {
            const walkAwayX = targetX - (frame - 8700) * 2;
            if (walkAwayX > -80) {
                drawStickFigure(walkAwayX, floorY, 'walk', frame * 0.14);
            }
        }

        const speechAge = frame - speechStart;
        if (currentSpeech && speechAge < 200) {
            const bubbleAlpha = speechAge < 15 ? speechAge / 15 : speechAge > 170 ? (200 - speechAge) / 30 : 1;
            ac.globalAlpha = bubbleAlpha;
            const bubbleX = Math.min(stickX, targetX);
            drawSpeechBubble(bubbleX, floorY - 60, currentSpeech);
            ac.globalAlpha = 1;
        }

        if (frame > 9000) {
            const endFade = (frame - 9000) / 90;
            ac.globalAlpha = 1 - endFade;
            if (endFade >= 1) {
                ac_el.remove();
                bombActive = false;
                audio.play().catch(() => {});
                return;
            }
        }

        requestAnimationFrame(animateAftermath);
    }

    ac.fillStyle = '#000';
    ac.fillRect(0, 0, W, H);
    setTimeout(() => {
        requestAnimationFrame(animateAftermath);
    }, 300);
}

// ==============================================
// BOMB REFUSAL (2nd time)
// ==============================================
function playBombRefusal() {
    const ac_el = document.createElement('canvas');
    ac_el.style.cssText = 'position:fixed;inset:0;z-index:99999;cursor:default;';
    ac_el.width = window.innerWidth;
    ac_el.height = window.innerHeight;
    document.body.appendChild(ac_el);
    const ac = ac_el.getContext('2d');
    const W = ac_el.width, H = ac_el.height;
    const centerX = W / 2;
    const floorY = H * 0.68;
    const targetX = centerX;
    let stickX = -60;
    let frame = 0;
    let walkCycle = 0;
    let currentSpeech = '';
    let speechStart = 0;
    let waitingForSorry = false;
    let sorryReceived = false;
    let sorryFrame = 0;
    let typedText = '';
    let inputActive = false;

    const angryLines = [
        'You did NOT just do that again.',
        'I TOLD you not to press T.',
        'What part of "don\'t touch anything" was unclear?',
        'No. Absolutely not. I\'m not doing this again.',
        'You want the site back? Say sorry.',
        'Type "sorry" right now.',
        'I\'m waiting...',
    ];

    const sorryLines = [
        'Oh NOW you\'re sorry.',
        '...fine.',
        'But I swear, one more time and I quit.',
        'Rebuilding... AGAIN...',
        'There. Don\'t even LOOK at the T key.',
    ];

    let lineIndex = 0;
    let lineTimer = 0;

    const sorryInput = document.createElement('input');
    sorryInput.type = 'text';
    sorryInput.placeholder = 'Type here...';
    sorryInput.autocomplete = 'off';
    sorryInput.style.cssText = 'position:fixed;bottom:12%;left:50%;transform:translateX(-50%);z-index:100000;background:rgba(255,255,255,0.95);border:2px solid #ff4444;border-radius:12px;padding:12px 24px;font-size:1.2rem;font-family:Segoe UI,sans-serif;color:#333;outline:none;width:280px;text-align:center;display:none;cursor:text;';

    sorryInput.addEventListener('input', () => {
        typedText = sorryInput.value.toLowerCase().trim();
        if (typedText === 'sorry') {
            sorryReceived = true;
            sorryFrame = frame;
            sorryInput.style.border = '2px solid #28c840';
            sorryInput.disabled = true;
            sorryInput.value = 'sorry';
            lineIndex = 0;
            lineTimer = frame;
        }
    });

    sorryInput.addEventListener('keydown', (e) => { e.stopPropagation(); });

    document.body.appendChild(sorryInput);

    function drawRefusalFrame() {
        frame++;
        ac.clearRect(0, 0, W, H);

        ac.fillStyle = '#0b0b0f';
        ac.fillRect(0, 0, W, H);
        ac.fillStyle = '#ff3333';
        ac.font = 'bold 100px Segoe UI';
        ac.textAlign = 'center';
        ac.fillText('404', centerX, H * 0.22);
        ac.fillStyle = '#ff6666';
        ac.font = '22px Segoe UI';
        ac.fillText('Oh no. Not again.', centerX, H * 0.22 + 40);
        ac.fillStyle = '#666';
        ac.font = '14px Segoe UI';
        ac.fillText(`Times bombed: ${bombCount}`, centerX, H * 0.22 + 70);

        if (frame > 60 && stickX < targetX) {
            stickX += 3;
            walkCycle += 0.2;
        }

        if (frame > 60) {
            const drawX = Math.min(stickX, targetX);
            const headY = floorY - 58;
            const shoulderY = floorY - 38;
            const hipY = floorY - 5;

            ac.strokeStyle = '#ccc';
            ac.fillStyle = '#ccc';
            ac.lineWidth = 3;
            ac.lineCap = 'round';

            ac.beginPath(); ac.arc(drawX, headY, 14, 0, Math.PI * 2); ac.stroke();
            ac.beginPath(); ac.arc(drawX - 5, headY - 3, 2, 0, Math.PI * 2); ac.fill();
            ac.beginPath(); ac.arc(drawX + 5, headY - 3, 2, 0, Math.PI * 2); ac.fill();
            ac.lineWidth = 2.5;
            ac.beginPath(); ac.moveTo(drawX - 10, headY - 11); ac.lineTo(drawX - 1, headY - 6); ac.stroke();
            ac.beginPath(); ac.moveTo(drawX + 10, headY - 11); ac.lineTo(drawX + 1, headY - 6); ac.stroke();
            ac.beginPath(); ac.ellipse(drawX, headY + 10, 5, 3, 0, 0, Math.PI * 2); ac.stroke();
            ac.lineWidth = 3;
            ac.beginPath(); ac.moveTo(drawX, headY + 14); ac.lineTo(drawX, hipY); ac.stroke();

            if (stickX < targetX) {
                const ls = Math.sin(walkCycle) * 18;
                const as = Math.sin(walkCycle) * 15;
                ac.beginPath(); ac.moveTo(drawX, shoulderY); ac.lineTo(drawX - 12 + as, shoulderY + 22); ac.stroke();
                ac.beginPath(); ac.moveTo(drawX, shoulderY); ac.lineTo(drawX + 12 - as, shoulderY + 22); ac.stroke();
                ac.beginPath(); ac.moveTo(drawX, hipY); ac.lineTo(drawX + ls, floorY + 20); ac.stroke();
                ac.beginPath(); ac.moveTo(drawX, hipY); ac.lineTo(drawX - ls, floorY + 20); ac.stroke();
            } else if (!sorryReceived) {
                ac.beginPath(); ac.moveTo(drawX - 15, shoulderY + 5); ac.lineTo(drawX + 15, shoulderY + 15); ac.stroke();
                ac.beginPath(); ac.moveTo(drawX + 15, shoulderY + 5); ac.lineTo(drawX - 15, shoulderY + 15); ac.stroke();
                const tap = Math.sin(frame * 0.15) * 3;
                ac.beginPath(); ac.moveTo(drawX, hipY); ac.lineTo(drawX + 12, floorY + 20); ac.stroke();
                ac.beginPath(); ac.moveTo(drawX, hipY); ac.lineTo(drawX - 10, floorY + 18 + tap); ac.stroke();
            } else {
                const armBob = Math.sin(frame * 0.2) * 4;
                ac.beginPath(); ac.moveTo(drawX, shoulderY); ac.lineTo(drawX - 20, shoulderY + 20 + armBob); ac.stroke();
                ac.beginPath(); ac.moveTo(drawX, shoulderY); ac.lineTo(drawX + 20, shoulderY + 20 - armBob); ac.stroke();
                ac.beginPath(); ac.moveTo(drawX, hipY); ac.lineTo(drawX + 12, floorY + 20); ac.stroke();
                ac.beginPath(); ac.moveTo(drawX, hipY); ac.lineTo(drawX - 10, floorY + 20); ac.stroke();
            }
        }

        if (!sorryReceived && stickX >= targetX) {
            if (!waitingForSorry) {
                if (frame - lineTimer > 180 || lineTimer === 0) {
                    lineTimer = frame;
                    if (lineIndex < angryLines.length) {
                        currentSpeech = angryLines[lineIndex];
                        speechStart = frame;
                        lineIndex++;
                        if (lineIndex >= 6 && !inputActive) {
                            inputActive = true;
                            sorryInput.style.display = 'block';
                            setTimeout(() => sorryInput.focus(), 100);
                        }
                        if (lineIndex >= angryLines.length) {
                            waitingForSorry = true;
                        }
                    }
                }
            } else {
                if ((frame - lineTimer) % 300 === 0) {
                    const reminders = [
                        'Still waiting.',
                        '...',
                        'The keyboard is right there.',
                        'S-O-R-R-Y. Five letters.',
                        'I have all day. Actually I don\'t. Type it.',
                        'You know what you did.',
                        '*taps foot aggressively*',
                        'Any day now.',
                    ];
                    currentSpeech = reminders[Math.floor(Math.random() * reminders.length)];
                    speechStart = frame;
                }
            }
        }

        if (sorryReceived) {
            const sinceSorry = frame - sorryFrame;
            if (sinceSorry > 60 && lineIndex < sorryLines.length) {
                if ((sinceSorry - 60) % 180 === 0) {
                    currentSpeech = sorryLines[lineIndex];
                    speechStart = frame;
                    lineIndex++;
                }
            }

            if (sinceSorry > 300) {
                const revealProgress = Math.min(1, (sinceSorry - 300) / 400);
                ac.save();
                ac.globalCompositeOperation = 'destination-out';
                ac.globalAlpha = revealProgress;
                ac.fillRect(0, 0, W, H);
                ac.restore();
            }

            if (sinceSorry > 900) {
                const endFade = (sinceSorry - 900) / 60;
                ac.globalAlpha = 1 - endFade;
                if (endFade >= 1) {
                    ac_el.remove();
                    sorryInput.remove();
                    bombActive = false;
                    audio.play().catch(() => {});
                    return;
                }
            }
        }

        const speechAge = frame - speechStart;
        if (currentSpeech && speechAge < 200) {
            const bubbleAlpha = speechAge < 15 ? speechAge / 15 : speechAge > 170 ? (200 - speechAge) / 30 : 1;
            ac.save();
            ac.globalAlpha = bubbleAlpha;
            const bText = currentSpeech;
            ac.font = '18px Segoe UI';
            ac.textAlign = 'center';
            const tw = ac.measureText(bText).width;
            const pad = 22, bw = tw + pad * 2, bh = 44;
            const bx = centerX - bw / 2;
            const by = H * 0.42;
            ac.fillStyle = 'rgba(20,20,30,0.95)';
            ac.beginPath(); ac.roundRect(bx, by, bw, bh, 14); ac.fill();
            ac.strokeStyle = '#ff4444';
            ac.lineWidth = 1.5;
            ac.stroke();
            ac.fillStyle = 'rgba(20,20,30,0.95)';
            ac.beginPath();
            ac.moveTo(centerX - 8, by + bh); ac.lineTo(centerX, by + bh + 12); ac.lineTo(centerX + 8, by + bh);
            ac.closePath(); ac.fill();
            ac.strokeStyle = '#ff4444'; ac.lineWidth = 1.5;
            ac.beginPath(); ac.moveTo(centerX - 8, by + bh); ac.lineTo(centerX, by + bh + 12); ac.lineTo(centerX + 8, by + bh); ac.stroke();
            ac.fillStyle = '#ff6666';
            ac.fillText(bText, centerX, by + bh / 2 + 6);
            ac.restore();
        }

        requestAnimationFrame(drawRefusalFrame);
    }

    ac.fillStyle = '#000';
    ac.fillRect(0, 0, W, H);
    setTimeout(() => requestAnimationFrame(drawRefusalFrame), 300);
}

// ==============================================
// FAKE BIOS BOOT SEQUENCE (3rd+ bomb press)
// ==============================================
function playBIOSBoot() {
    const bios = document.createElement('div');
    bios.style.cssText = 'position:fixed;inset:0;z-index:99999;background:#000;font-family:"Courier New",monospace;color:#aaa;font-size:14px;padding:20px;overflow:hidden;white-space:pre;line-height:1.6;';
    document.body.appendChild(bios);

    const beep = () => {
        try {
            const actx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = actx.createOscillator();
            const gain = actx.createGain();
            osc.connect(gain); gain.connect(actx.destination);
            osc.frequency.value = 800; gain.gain.value = 0.15;
            osc.start(); osc.stop(actx.currentTime + 0.15);
        } catch(e){}
    };

    const lines = [];
    let cursorLine = 0;

    function addLine(text, color, delay) {
        lines.push({ text, color: color || '#aaa', delay: delay || 80 });
    }

    addLine('', '', 200);
    addLine('LoganBIOS (C) 2024 Logan Industries Ltd.', '#fff', 100);
    addLine('BIOS Date: 04/06/2026  Ver: 6.90.420', '#aaa', 80);
    addLine('CPU: Intel Core i9-14900K @ 6.20 GHz', '#aaa', 60);
    addLine('', '', 50);
    addLine('Press DEL to enter BIOS Setup, F12 for Boot Menu', '#555', 100);
    addLine('', '', 300);
    addLine('Detecting Primary Master... Samsung 990 PRO 2TB', '#aaa', 400);
    addLine('Detecting Primary Slave... NONE', '#aaa', 200);
    addLine('Detecting Secondary Master... ASUS BW-16D1HT', '#aaa', 300);
    addLine('Detecting Secondary Slave... Logan\'s Secret Files (DO NOT OPEN)', '#ff6666', 200);
    addLine('', '', 100);
    addLine('Memory Test:', '#fff', 100);

    const memSteps = [128, 512, 1024, 2048, 4096, 8192, 16384, 32768, 65536, 69420];
    memSteps.forEach((m, i) => {
        addLine(`  ${m}K OK`, '#0f0', i < 8 ? 60 : 200);
    });
    addLine('  Memory Test PASSED - 69420K Verified', '#0f0', 150);
    addLine('', '', 100);

    addLine('Initializing USB Controllers... Done', '#aaa', 200);
    addLine('  USB Device(s): 3 Keyboards, 2 Mice, 1 Suspicious Device', '#aaa', 100);
    addLine('Initializing GPU... NVIDIA RTX 5090 - "Overkill Edition"', '#aaa', 300);
    addLine('  VRAM: 32GB GDDR7 (mostly used for Chrome tabs)', '#888', 100);
    addLine('', '', 100);
    addLine('Checking RAID Array... RAID-69 (Nice)', '#aaa', 200);
    addLine('  Drive 0: [OK]  Drive 1: [OK]  Drive 2: [VIBES]', '#aaa', 100);
    addLine('', '', 200);

    addLine('WARNING: T-key detected on keyboard', '#ff6666', 300);
    addLine('  THREAT LEVEL: CATASTROPHIC', '#ff3333', 200);
    addLine('  Recommendation: Remove T-key immediately', '#ff6666', 150);
    addLine('  Times this system has been bombed: ' + bombCount, '#ff3333', 200);
    addLine('', '', 100);
    addLine('WARNING: Stick figure employee morale critically low', '#ffaa00', 200);
    addLine('  Status: Considering resignation', '#ffaa00', 100);
    addLine('  Reason: "I am not paid enough for this"', '#ffaa00', 150);
    addLine('', '', 200);

    addLine('Scanning for boot devices...', '#fff', 400);
    addLine('  Boot Device 0: LoganOS v4.20 [logansandivar.com]', '#aaa', 200);
    addLine('  Boot Device 1: Windows 95 (why is this here)', '#888', 100);
    addLine('  Boot Device 2: TempleOS (in memory of Terry)', '#888', 100);
    addLine('', '', 200);
    addLine('Loading LoganOS...', '#7aa2ff', 300);
    addLine('', '', 100);
    addLine('  \u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588 100%', '#7aa2ff', 800);
    addLine('', '', 200);
    addLine('Mounting file systems...', '#aaa', 200);
    addLine('  /dev/vibes      mounted on /home/logan/vibes', '#aaa', 100);
    addLine('  /dev/portfolio  mounted on /var/www/logansandivar.com', '#aaa', 100);
    addLine('  /dev/regrets    mounted on /home/logan/.hidden', '#888', 100);
    addLine('', '', 200);
    addLine('Starting services...', '#aaa', 300);
    addLine('  [OK] particle-engine.service', '#0f0', 80);
    addLine('  [OK] starfield-renderer.service', '#0f0', 80);
    addLine('  [OK] beat-detection.service', '#0f0', 80);
    addLine('  [OK] visitor-counter.service', '#0f0', 80);
    addLine('  [OK] stick-figure-therapy.service', '#ffaa00', 80);
    addLine('  [FAILED] t-key-disabler.service - Permission denied', '#ff3333', 200);
    addLine('  [OK] chaos-engine.service', '#0f0', 80);
    addLine('', '', 300);
    addLine('All systems operational. Booting into logansandivar.com...', '#7aa2ff', 500);
    addLine('', '', 200);
    addLine('Welcome back. Please don\'t press T again.', '#ff6666', 1000);

    let totalDelay = 0;
    beep();

    lines.forEach((line, idx) => {
        totalDelay += line.delay;
        setTimeout(() => {
            const el = document.createElement('div');
            el.textContent = line.text || '\u00A0';
            el.style.color = line.color;
            bios.appendChild(el);
            bios.scrollTop = bios.scrollHeight;

            if (line.text.includes('PASSED') || line.text.includes('Welcome back')) beep();
        }, totalDelay);
    });

    setTimeout(() => {
        bios.style.transition = 'opacity 1.5s';
        bios.style.opacity = '0';
        setTimeout(() => {
            bios.remove();
            bombActive = false;
            audio.play().catch(() => {});
        }, 1500);
    }, totalDelay + 2000);
}
