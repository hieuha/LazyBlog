/* eslint-env browser */
/**
 * WebAuthn client for LazyBlog admin.
 *
 * Two flows, one file, route-selected by which element exists:
 *   - #webauthn-register-form   → POST /admin/webauthn/register/{begin,complete}
 *   - #webauthn-tap             → POST /admin/webauthn/login/{begin,complete}
 *
 * Server returns options with binary fields already base64url-encoded (the
 * lbuchs/WebAuthn lib was constructed with useBase64UrlEncoding=true), so
 * we just need to convert those to ArrayBuffer for the WebAuthn API and
 * back to base64url for the POST body.
 */
(function () {
    'use strict';

    if (!window.PublicKeyCredential) {
        var msg = '// This browser does not support WebAuthn. Use Chrome, Firefox, Safari, or Edge.';
        var status = document.getElementById('webauthn-status');
        if (status) status.textContent = msg;
        var btn = document.getElementById('webauthn-tap');
        if (btn) btn.disabled = true;
        return;
    }

    function b64uToBuf(b64u) {
        var b64 = b64u.replace(/-/g, '+').replace(/_/g, '/');
        while (b64.length % 4) b64 += '=';
        var bin = atob(b64);
        var buf = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
        return buf.buffer;
    }

    function bufToB64u(buf) {
        var bin = '';
        var bytes = new Uint8Array(buf);
        for (var i = 0; i < bytes.byteLength; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    // Convert the publicKey envelope returned by the server into a shape
    // the browser's WebAuthn API expects (ArrayBuffer for challenge,
    // user.id, allowCredentials[].id, excludeCredentials[].id).
    function hydrateRegisterOpts(opts) {
        var pk = opts.publicKey;
        pk.challenge = b64uToBuf(pk.challenge);
        if (pk.user && pk.user.id) pk.user.id = b64uToBuf(pk.user.id);
        if (Array.isArray(pk.excludeCredentials)) {
            pk.excludeCredentials = pk.excludeCredentials.map(function (c) {
                return { type: c.type, id: b64uToBuf(c.id), transports: c.transports };
            });
        }
        return pk;
    }

    function hydrateLoginOpts(opts) {
        var pk = opts.publicKey;
        pk.challenge = b64uToBuf(pk.challenge);
        if (Array.isArray(pk.allowCredentials)) {
            pk.allowCredentials = pk.allowCredentials.map(function (c) {
                return { type: c.type, id: b64uToBuf(c.id), transports: c.transports };
            });
        }
        return pk;
    }

    function postJson(url, csrf, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf,
            },
            body: JSON.stringify(body || {}),
        }).then(function (r) {
            return r.json().then(function (j) { return { status: r.status, body: j }; });
        });
    }

    // ───────────────────── LAST-KEY WARN MODAL ────────────────────
    // Info-only modal — no submit path. Triggered when operator clicks
    // REVOKE on their only registered key while WEBAUTHN=true.
    var warnModal = document.getElementById('last-key-warn-modal');
    if (warnModal) {
        function closeWarn() { warnModal.hidden = true; }
        var warnTriggers = document.querySelectorAll('.js-last-key-warn');
        for (var w = 0; w < warnTriggers.length; w++) {
            warnTriggers[w].addEventListener('click', function () {
                warnModal.hidden = false;
                var ok = warnModal.querySelector('[data-last-key-dismiss]');
                if (ok) setTimeout(function () { ok.focus(); }, 30);
            });
        }
        var warnDismissers = warnModal.querySelectorAll('[data-last-key-dismiss]');
        for (var d = 0; d < warnDismissers.length; d++) {
            warnDismissers[d].addEventListener('click', closeWarn);
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !warnModal.hidden) {
                e.preventDefault();
                closeWarn();
            }
        });
    }

    // ────────────────────────── ADD-KEY MODAL ─────────────────────
    // Open/close the add-key dialog. Re-uses .admin-confirm-modal CSS for
    // visual parity with the REVOKE confirm — only behavioural wiring lives
    // here (the confirm dialog stays driven by _confirm-modal.php).
    var addKeyModal = document.getElementById('add-key-modal');
    var openAddKeyBtn = document.getElementById('open-add-key-modal');
    if (addKeyModal && openAddKeyBtn) {
        var nicknameField = addKeyModal.querySelector('#key-nickname');
        function closeAddKey() {
            addKeyModal.hidden = true;
            var err = document.getElementById('webauthn-register-error');
            if (err) { err.hidden = true; err.textContent = ''; }
        }
        openAddKeyBtn.addEventListener('click', function () {
            addKeyModal.hidden = false;
            // Defer focus so the modal is laid out before the cursor lands.
            setTimeout(function () { if (nicknameField) nicknameField.focus(); }, 30);
        });
        var dismissers = addKeyModal.querySelectorAll('[data-add-key-dismiss]');
        for (var i = 0; i < dismissers.length; i++) {
            dismissers[i].addEventListener('click', closeAddKey);
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !addKeyModal.hidden) {
                e.preventDefault();
                closeAddKey();
            }
        });
    }

    // ────────────────────────── REGISTER ──────────────────────────
    var regForm = document.getElementById('webauthn-register-form');
    if (regForm) {
        var errBox = document.getElementById('webauthn-register-error');
        regForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            if (errBox) { errBox.hidden = true; errBox.textContent = ''; }
            var csrf = regForm.querySelector('input[name="_csrf"]').value;
            var nickname = regForm.querySelector('#key-nickname').value;

            postJson('/admin/webauthn/register/begin', csrf, { nickname: nickname })
                .then(function (res) {
                    if (res.status !== 200 || !res.body || res.body.ok === false) {
                        throw new Error((res.body && res.body.error) || 'Server rejected request.');
                    }
                    var pk = hydrateRegisterOpts(res.body);
                    return navigator.credentials.create({ publicKey: pk });
                })
                .then(function (cred) {
                    if (!cred) throw new Error('Authenticator returned no credential.');
                    var att = cred.response;
                    var payload = {
                        id: cred.id,
                        clientDataJSON: bufToB64u(att.clientDataJSON),
                        attestationObject: bufToB64u(att.attestationObject),
                        transports: typeof att.getTransports === 'function' ? att.getTransports() : [],
                    };
                    return postJson('/admin/webauthn/register/complete', csrf, payload);
                })
                .then(function (res) {
                    if (res.status !== 200 || !res.body || res.body.ok === false) {
                        throw new Error((res.body && res.body.error) || 'Registration failed.');
                    }
                    window.location.reload();
                })
                .catch(function (err) {
                    if (errBox) {
                        errBox.textContent = '// ' + (err.message || String(err));
                        errBox.hidden = false;
                    }
                });
        });
    }

    // ─────────────────────────── LOGIN ────────────────────────────
    var tapBtn = document.getElementById('webauthn-tap');
    if (tapBtn) {
        var statusEl = document.getElementById('webauthn-status');
        function setStatus(text) {
            if (!statusEl) return;
            statusEl.textContent = text;
            statusEl.hidden = text === '';
        }
        function setState(state) { tapBtn.setAttribute('data-state', state); }

        tapBtn.addEventListener('click', function () {
            var csrf = tapBtn.getAttribute('data-csrf') || '';
            var next = tapBtn.getAttribute('data-next') || '/admin';
            setState('waiting');
            setStatus('// Waiting for security key…');

            postJson('/admin/webauthn/login/begin', csrf, {})
                .then(function (res) {
                    if (res.status !== 200 || !res.body || res.body.ok === false) {
                        throw new Error((res.body && res.body.error) || 'Login challenge failed.');
                    }
                    var pk = hydrateLoginOpts(res.body);
                    return navigator.credentials.get({ publicKey: pk });
                })
                .then(function (assertion) {
                    if (!assertion) throw new Error('No assertion returned.');
                    var r = assertion.response;
                    var payload = {
                        id: assertion.id,
                        clientDataJSON: bufToB64u(r.clientDataJSON),
                        authenticatorData: bufToB64u(r.authenticatorData),
                        signature: bufToB64u(r.signature),
                        next: next,
                    };
                    return postJson('/admin/webauthn/login/complete', csrf, payload);
                })
                .then(function (res) {
                    if (res.status !== 200 || !res.body || res.body.ok === false) {
                        throw new Error((res.body && res.body.error) || 'Login failed.');
                    }
                    window.location.href = res.body.redirect || '/admin';
                })
                .catch(function (err) {
                    setState('idle');
                    setStatus('// ' + (err.message || String(err)));
                });
        });
    }
})();
