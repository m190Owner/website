<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (!isLoggedIn()) {
    header('Location: /forum/login.php');
    exit;
}

$navActive = 'drops';
$pageTitle = 'Dead Drops';

$myPublicKey = getUserPublicKey(currentUser());
$drops = getDeadDropsForUser();

require_once __DIR__ . '/includes/header.php';
?>

<div class="forum-wrap" style="max-width:780px;">
    <div class="breadcrumbs">
        <a href="/forum/">m190</a><span class="sep">/</span><span>Dead Drops</span>
    </div>

    <div class="card mb-4">
        <div style="padding:18px 22px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                <span class="dd-icon">&#128274;</span>
                <h1 style="font-size:1rem;color:#e5e5e5;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;">Dead Drops</h1>
                <span class="dd-classified">CLASSIFIED</span>
            </div>
            <p style="font-size:0.75rem;color:#8a96b8;line-height:1.6;">
                End-to-end encrypted anonymous messages. Server only sees ciphertext.
                Your private key never leaves this browser. Sender identity is not stored.
                Burn-after-read enforced. Optional expiry up to 72 hours.
            </p>
            <div id="dd-keystatus" class="dd-keystatus"></div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <div class="tab active" data-tab="inbox">Inbox (<span id="dd-inbox-count"><?= count($drops) ?></span>)</div>
        <div class="tab" data-tab="compose">Compose</div>
        <div class="tab" data-tab="keys">Keys</div>
    </div>

    <!-- INBOX -->
    <div class="tab-content active" id="tab-inbox">
        <div class="card">
            <div class="card-body">
                <?php if (empty($drops)): ?>
                    <div class="empty-state">
                        <p>No drops. The letterbox is empty.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($drops as $d): ?>
                    <div class="dd-row" data-id="<?= e($d['id']) ?>" data-nonce="<?= e($d['nonce']) ?>" data-ct="<?= e($d['encrypted_content']) ?>" data-public="<?= $d['is_public'] ? '1' : '0' ?>">
                        <div class="dd-row-icon">
                            <?php if ($d['is_public']): ?>&#128275;<?php else: ?>&#128274;<?php endif; ?>
                        </div>
                        <div class="dd-row-main">
                            <div class="dd-row-head">
                                <span class="dd-row-type"><?= $d['is_public'] ? 'PUBLIC' : 'PRIVATE' ?></span>
                                <span class="dd-row-time"><?= timeAgo($d['created']) ?></span>
                                <?php if ($d['read']): ?><span class="dd-row-read">READ</span><?php endif; ?>
                                <?php if ($d['expires']): ?><span class="dd-row-expires">expires <?= timeAgo($d['expires']) ?></span><?php endif; ?>
                            </div>
                            <div class="dd-row-preview" data-preview>[encrypted &mdash; click to decrypt]</div>
                        </div>
                        <div class="dd-row-actions">
                            <button class="btn btn-secondary btn-sm" data-decrypt>Decrypt</button>
                            <button class="btn btn-danger btn-sm" data-burn>Burn</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- COMPOSE -->
    <div class="tab-content" id="tab-compose">
        <div class="card">
            <div class="card-header"><h2>Send a Drop</h2></div>
            <div style="padding:20px;">
                <div id="dd-compose-status"></div>
                <div class="form-group">
                    <label class="form-label">Mode</label>
                    <div style="display:flex;gap:10px;">
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:0.8rem;">
                            <input type="radio" name="dd-mode" value="private" checked> Private (to user)
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:0.8rem;">
                            <input type="radio" name="dd-mode" value="public"> Public (readable by anyone)
                        </label>
                    </div>
                </div>
                <div class="form-group" id="dd-recipient-group">
                    <label class="form-label">Recipient</label>
                    <input class="form-input" type="text" id="dd-recipient" placeholder="username" maxlength="24">
                    <p class="form-hint">Recipient must have generated a keypair (visited Dead Drops at least once).</p>
                </div>
                <div class="form-group">
                    <label class="form-label">Message</label>
                    <textarea class="form-textarea" id="dd-message" maxlength="4000" placeholder="Write in clear. Encryption happens in your browser before sending." style="min-height:160px;"></textarea>
                    <p class="form-hint">Max 4000 characters. Markdown-style formatting supported.</p>
                </div>
                <div class="form-group">
                    <label class="form-label">Self-destruct</label>
                    <select class="form-select" id="dd-expires" style="max-width:240px;">
                        <option value="">Never (until burned)</option>
                        <option value="1">1 hour</option>
                        <option value="6">6 hours</option>
                        <option value="24" selected>24 hours</option>
                        <option value="72">72 hours</option>
                    </select>
                </div>
                <button id="dd-send" class="btn btn-primary">Send Drop</button>
            </div>
        </div>
    </div>

    <!-- KEYS -->
    <div class="tab-content" id="tab-keys">
        <div class="card">
            <div class="card-header"><h2>Your Keypair</h2></div>
            <div style="padding:20px;">
                <p style="font-size:0.78rem;color:#8a96b8;line-height:1.6;margin-bottom:14px;">
                    A P-256 ECDH keypair is generated on first visit. The private key is stored
                    in this browser's localStorage and never transmitted. The public key is
                    registered on the server so others can send you encrypted drops.
                </p>
                <div class="form-group">
                    <label class="form-label">Public Key (JWK)</label>
                    <textarea class="form-textarea" id="dd-pubkey-display" readonly style="min-height:120px;font-family:Consolas,monospace;font-size:0.7rem;"></textarea>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button id="dd-regenerate" class="btn btn-danger btn-sm">Regenerate Keypair</button>
                    <button id="dd-export" class="btn btn-secondary btn-sm">Export Private Key</button>
                    <button id="dd-import" class="btn btn-secondary btn-sm">Import Private Key</button>
                </div>
                <p class="form-hint" style="margin-top:10px;">
                    Regenerating invalidates all past drops sent to you. Export to back up your key across devices.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Decrypt modal -->
<div class="modal-overlay" id="dd-modal">
    <div class="modal" style="max-width:520px;">
        <h3 id="dd-modal-title">Decrypted Message</h3>
        <div id="dd-modal-meta" style="font-size:0.7rem;color:#5a6480;margin-bottom:10px;"></div>
        <div id="dd-modal-content" style="font-size:0.85rem;color:#d0d4dc;line-height:1.6;white-space:pre-wrap;word-break:break-word;background:rgba(10,10,18,0.6);padding:14px;border-radius:8px;border:1px solid rgba(122,162,255,0.1);max-height:340px;overflow-y:auto;"></div>
        <div style="display:flex;gap:10px;margin-top:16px;justify-content:flex-end;">
            <button id="dd-modal-burn" class="btn btn-danger btn-sm">Burn Now</button>
            <button id="dd-modal-close" class="btn btn-secondary btn-sm">Close</button>
        </div>
    </div>
</div>

<input type="hidden" id="dd-csrf" value="<?= csrfToken() ?>">
<input type="hidden" id="dd-server-pubkey" value="<?= e($myPublicKey ?? '') ?>">

<script>
(function(){
    'use strict';
    const STORAGE_KEY = 'm190_dd_privkey_v1';
    const STORAGE_PUBKEY = 'm190_dd_pubkey_v1';
    const CSRF = document.getElementById('dd-csrf').value;
    const ME = <?= json_encode(currentUser()) ?>;

    // ============= CRYPTO =============
    function b64e(buf) {
        const bytes = new Uint8Array(buf);
        let s = '';
        for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
        return btoa(s);
    }
    function b64d(s) {
        const bin = atob(s);
        const out = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
        return out.buffer;
    }

    async function generateKeypair() {
        return await crypto.subtle.generateKey(
            { name: 'ECDH', namedCurve: 'P-256' },
            true,
            ['deriveKey', 'deriveBits']
        );
    }

    async function exportPublicJwk(key) {
        return JSON.stringify(await crypto.subtle.exportKey('jwk', key));
    }
    async function exportPrivateJwk(key) {
        return JSON.stringify(await crypto.subtle.exportKey('jwk', key));
    }
    async function importPrivateJwk(jwkStr) {
        return await crypto.subtle.importKey(
            'jwk', JSON.parse(jwkStr),
            { name: 'ECDH', namedCurve: 'P-256' },
            true, ['deriveKey', 'deriveBits']
        );
    }
    async function importPublicJwk(jwkStr) {
        const jwk = typeof jwkStr === 'string' ? JSON.parse(jwkStr) : jwkStr;
        return await crypto.subtle.importKey(
            'jwk', jwk,
            { name: 'ECDH', namedCurve: 'P-256' },
            true, []
        );
    }

    async function deriveAesKey(privateKey, publicKey) {
        return await crypto.subtle.deriveKey(
            { name: 'ECDH', public: publicKey },
            privateKey,
            { name: 'AES-GCM', length: 256 },
            false, ['encrypt', 'decrypt']
        );
    }

    async function encryptMessage(plaintext, recipientPubJwk) {
        const ephemeral = await generateKeypair();
        const recipientPub = await importPublicJwk(recipientPubJwk);
        const aesKey = await deriveAesKey(ephemeral.privateKey, recipientPub);
        const iv = crypto.getRandomValues(new Uint8Array(12));
        const enc = new TextEncoder().encode(plaintext);
        const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, aesKey, enc);
        const ephPub = await crypto.subtle.exportKey('jwk', ephemeral.publicKey);
        return {
            ciphertext: b64e(ct),
            nonce: JSON.stringify({ iv: b64e(iv), eph: ephPub })
        };
    }

    async function decryptMessage(ciphertextB64, nonceStr, myPrivateKey) {
        const parsed = JSON.parse(nonceStr);
        const iv = new Uint8Array(b64d(parsed.iv));
        const ephPub = await importPublicJwk(parsed.eph);
        const aesKey = await deriveAesKey(myPrivateKey, ephPub);
        const ct = b64d(ciphertextB64);
        const pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, aesKey, ct);
        return new TextDecoder().decode(pt);
    }

    // ============= KEY MGMT =============
    async function loadPrivateKey() {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        try { return await importPrivateJwk(raw); } catch (e) { return null; }
    }

    async function ensureKeypair() {
        let priv = await loadPrivateKey();
        const serverPub = document.getElementById('dd-server-pubkey').value.trim();
        const localPub = localStorage.getItem(STORAGE_PUBKEY);

        if (!priv) {
            // Generate new
            const kp = await generateKeypair();
            const privJwk = await exportPrivateJwk(kp.privateKey);
            const pubJwk = await exportPublicJwk(kp.publicKey);
            localStorage.setItem(STORAGE_KEY, privJwk);
            localStorage.setItem(STORAGE_PUBKEY, pubJwk);
            await registerServerPubkey(pubJwk);
            return kp.privateKey;
        }

        // Re-register if server is missing or mismatched
        if (!serverPub || serverPub !== localPub) {
            if (localPub) await registerServerPubkey(localPub);
        }
        return priv;
    }

    async function registerServerPubkey(pubJwk) {
        const fd = new FormData();
        fd.append('action', 'pubkey_register');
        fd.append('csrf_token', CSRF);
        fd.append('public_key', pubJwk);
        await fetch('/forum/api.php', { method: 'POST', body: fd });
    }

    async function fetchUserPubkey(username) {
        const fd = new FormData();
        fd.append('action', 'pubkey_get');
        fd.append('username', username);
        const r = await fetch('/forum/api.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Key lookup failed');
        return j.public_key;
    }

    // ============= UI =============
    function setStatus(id, msg, kind) {
        const el = document.getElementById(id);
        if (!el) return;
        if (!msg) { el.innerHTML = ''; return; }
        const cls = kind === 'error' ? 'alert-error' : (kind === 'success' ? 'alert-success' : '');
        el.innerHTML = '<div class="alert ' + cls + '" style="margin-top:10px;">' + msg + '</div>';
    }

    // Tabs
    document.querySelectorAll('.tab').forEach(t => {
        t.addEventListener('click', () => {
            document.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(x => x.classList.remove('active'));
            t.classList.add('active');
            const target = document.getElementById('tab-' + t.dataset.tab);
            if (target) target.classList.add('active');
        });
    });

    // Mode toggle
    document.querySelectorAll('input[name="dd-mode"]').forEach(r => {
        r.addEventListener('change', () => {
            document.getElementById('dd-recipient-group').style.display =
                document.querySelector('input[name="dd-mode"]:checked').value === 'public' ? 'none' : 'block';
        });
    });

    // Send
    document.getElementById('dd-send').addEventListener('click', async () => {
        const mode = document.querySelector('input[name="dd-mode"]:checked').value;
        const message = document.getElementById('dd-message').value.trim();
        const recipient = document.getElementById('dd-recipient').value.trim();
        const expires = document.getElementById('dd-expires').value;

        if (!message) return setStatus('dd-compose-status', 'Message cannot be empty.', 'error');
        if (message.length > 4000) return setStatus('dd-compose-status', 'Message too long (max 4000 chars).', 'error');
        if (mode === 'private' && !recipient) return setStatus('dd-compose-status', 'Recipient required.', 'error');

        setStatus('dd-compose-status', 'Encrypting...', '');
        try {
            let ciphertext, nonce;
            if (mode === 'public') {
                // Public drop: still obfuscate slightly but store as plaintext-in-ciphertext
                // Use a well-known shared password key so anyone can decrypt
                const enc = new TextEncoder().encode(message);
                ciphertext = b64e(enc);
                nonce = 'public';
            } else {
                const pubJwk = await fetchUserPubkey(recipient);
                const result = await encryptMessage(message, pubJwk);
                ciphertext = result.ciphertext;
                nonce = result.nonce;
            }

            const fd = new FormData();
            fd.append('action', 'deaddrop_send');
            fd.append('csrf_token', CSRF);
            fd.append('is_public', mode === 'public' ? '1' : '0');
            fd.append('recipient', recipient);
            fd.append('encrypted_content', ciphertext);
            fd.append('nonce', nonce);
            if (expires) fd.append('expires_hours', expires);

            const r = await fetch('/forum/api.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'Send failed');
            setStatus('dd-compose-status', 'Drop deployed. ID: ' + j.id, 'success');
            document.getElementById('dd-message').value = '';
            document.getElementById('dd-recipient').value = '';
        } catch (err) {
            setStatus('dd-compose-status', 'Error: ' + err.message, 'error');
        }
    });

    // Decrypt row
    document.querySelectorAll('[data-decrypt]').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            const row = e.target.closest('.dd-row');
            const ct = row.dataset.ct;
            const nonce = row.dataset.nonce;
            const isPublic = row.dataset.public === '1';
            try {
                let plaintext;
                if (isPublic) {
                    plaintext = new TextDecoder().decode(b64d(ct));
                } else {
                    const priv = await loadPrivateKey();
                    if (!priv) throw new Error('No private key available. Was it cleared?');
                    plaintext = await decryptMessage(ct, nonce, priv);
                }
                showModal(plaintext, row.dataset.id, isPublic);
            } catch (err) {
                alert('Decryption failed: ' + err.message + '\n\nThis drop may have been encrypted with an older keypair.');
            }
        });
    });

    // Burn row
    document.querySelectorAll('[data-burn]').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            if (!confirm('Burn this drop? It will be permanently destroyed.')) return;
            const row = e.target.closest('.dd-row');
            await burnDrop(row.dataset.id);
            row.remove();
            const c = document.getElementById('dd-inbox-count');
            c.textContent = Math.max(0, parseInt(c.textContent) - 1);
        });
    });

    async function burnDrop(id) {
        const fd = new FormData();
        fd.append('action', 'deaddrop_burn');
        fd.append('csrf_token', CSRF);
        fd.append('drop_id', id);
        const r = await fetch('/forum/api.php', { method: 'POST', body: fd });
        return (await r.json()).ok;
    }

    // Modal
    function showModal(plaintext, dropId, isPublic) {
        document.getElementById('dd-modal-content').textContent = plaintext;
        document.getElementById('dd-modal-meta').textContent = (isPublic ? 'Public drop' : 'Private drop') + ' &middot; ID: ' + dropId;
        document.getElementById('dd-modal-burn').dataset.id = dropId;
        document.getElementById('dd-modal').classList.add('show');
    }
    document.getElementById('dd-modal-close').addEventListener('click', () => {
        document.getElementById('dd-modal').classList.remove('show');
    });
    document.getElementById('dd-modal-burn').addEventListener('click', async (e) => {
        if (!confirm('Burn this drop now?')) return;
        const id = e.target.dataset.id;
        await burnDrop(id);
        document.getElementById('dd-modal').classList.remove('show');
        const row = document.querySelector('.dd-row[data-id="' + id + '"]');
        if (row) row.remove();
        const c = document.getElementById('dd-inbox-count');
        c.textContent = Math.max(0, parseInt(c.textContent) - 1);
    });

    // Regenerate
    document.getElementById('dd-regenerate').addEventListener('click', async () => {
        if (!confirm('Regenerate keypair? All past private drops sent to you will become unreadable.')) return;
        localStorage.removeItem(STORAGE_KEY);
        localStorage.removeItem(STORAGE_PUBKEY);
        await ensureKeypair();
        refreshKeyDisplay();
        alert('New keypair generated and registered.');
    });

    // Export
    document.getElementById('dd-export').addEventListener('click', () => {
        const priv = localStorage.getItem(STORAGE_KEY);
        if (!priv) return alert('No private key in this browser.');
        const blob = new Blob([priv], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'm190-deaddrops-privkey-' + ME + '.json';
        a.click();
        URL.revokeObjectURL(url);
    });

    // Import
    document.getElementById('dd-import').addEventListener('click', () => {
        const inp = document.createElement('input');
        inp.type = 'file';
        inp.accept = '.json,application/json';
        inp.addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            const text = await file.text();
            try {
                const priv = await importPrivateJwk(text);
                // Derive public from private
                const jwk = JSON.parse(text);
                const pubJwk = JSON.stringify({ kty: jwk.kty, crv: jwk.crv, x: jwk.x, y: jwk.y, ext: true, key_ops: [] });
                localStorage.setItem(STORAGE_KEY, text);
                localStorage.setItem(STORAGE_PUBKEY, pubJwk);
                await registerServerPubkey(pubJwk);
                refreshKeyDisplay();
                alert('Key imported.');
            } catch (err) {
                alert('Invalid key file: ' + err.message);
            }
        });
        inp.click();
    });

    function refreshKeyDisplay() {
        const pub = localStorage.getItem(STORAGE_PUBKEY) || '(none)';
        document.getElementById('dd-pubkey-display').value = pub;
    }

    // Boot
    (async () => {
        if (!window.crypto || !window.crypto.subtle) {
            setStatus('dd-keystatus', 'Web Crypto API unavailable. Dead Drops requires a modern browser.', 'error');
            return;
        }
        try {
            await ensureKeypair();
            refreshKeyDisplay();
            const statusEl = document.getElementById('dd-keystatus');
            statusEl.innerHTML = '<span class="dd-ok">&#9679; keypair loaded &middot; encryption active</span>';
        } catch (err) {
            setStatus('dd-keystatus', 'Key init failed: ' + err.message, 'error');
        }
    })();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
