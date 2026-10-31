/**
 * public/assets/js/auth.js — AESBin login + recovery
 *
 * Login page (password, TOTP, passkey, self-hosted ALTCHA PoW) and
 * recovery page (guard file, recovery phrase, password/TOTP/phrase reset).
 * Carries its own small fetch client; the dashboard and the share viewer
 * use api.js and share.js.
 */
/* global AESBIN_CAPTCHA, AESBinHelper */

(function() {
    'use strict';

    const L = window.AESBIN_LANG || {};
    const t = k => L[k] || k;
    const $ = id => document.getElementById(id);
    const CAP = (typeof AESBIN_CAPTCHA !== 'undefined') ? AESBIN_CAPTCHA : {};

    // Generated here at login, read by api.js on the dashboard. Never sent
    // to other tabs (see the note in api.js); other tabs log in again.
    const { sessionKey, fmt, appUrl } = AESBinHelper;

    // ── API ───────────────────────────────────────────────────────────────────
    /**
     * A login or recovery request through AESBinHelper.fetchJson(). The
     * session key header, once generated, goes too: /api/auth/finalize needs
     * the key sent at login.
     */
    async function _req(url, opts = {}) {
        const { headers = {}, ...rest } = opts;
        try {
            return await AESBinHelper.fetchJson(appUrl(url), {
                ...rest,
                headers: {
                    'Content-Type': 'application/json',
                    ...AESBinHelper.authHeaders(),
                    ...headers,
                },
            });
        } catch (err) {
            if (AESBinHelper.reloadOnStaleCsrf(err)) return new Promise(() => {});
            if (err.httpStatus === 401 && err.errorCode !== 'password_required') {
                // No owner session exists on these pages: send to /login.
                location.href = appUrl('login');
                return new Promise(() => {});
            }
            throw err;
        }
    }

    const auth = {
        /** Login with a pre-built body (includes honeypot + captcha fields). */
        loginWithBody: (body) =>
            _req('/api/auth/login', {
                method: 'POST',
                body: JSON.stringify(body)
            }),

        // The password goes again with the TOTP code: the server keeps
        // neither between the two steps (see Auth::finalizeLogin()), so both
        // are held in page memory.
        finalize: (password, totpCode) =>
            _req('/api/auth/finalize', {
                method: 'POST',
                body: JSON.stringify({
                    password,
                    totp_code: totpCode
                })
            }),

        /** Arm the guard file (idempotent: reuses one already on disk). */
        recoveryGuard: () =>
            _req('/api/auth/recovery/guard', { method: 'POST', body: '{}' }),

        /** Submit the code read out of the guard file over FTP. */
        recoveryGuardVerify: (code) =>
            _req('/api/auth/recovery/guard/verify', {
                method: 'POST',
                body: JSON.stringify({ code })
            }),

        /** Mint the replacement phrase: shown, not yet stored. */
        recoveryPhraseNew: () =>
            _req('/api/auth/recovery/phrase/new', { method: 'POST', body: '{}' }),

        recoveryTotpNew: () =>
            _req('/api/auth/recovery/totp/new', { method: 'POST', body: '{}' }),

        recoveryTotpVerify: (secret, code) =>
            _req('/api/auth/recovery/totp/verify', {
                method: 'POST',
                body: JSON.stringify({ secret, code }),
            }),

        /** Verify recovery phrase with pre-built body (includes honeypot). */
        verifyRecoveryWithBody: (body) =>
            _req('/api/auth/recovery/verify', {
                method: 'POST',
                body: JSON.stringify(body)
            }),

        /**
         * Execute recovery with a pre-built body. The longer timeout covers
         * up to five Argon2id derivations (phrase, new password, new phrase),
         * which a slow shared host can take a while over.
         */
        executeRecoveryWithBody: (body) =>
            _req('/api/auth/recovery/execute', {
                method: 'POST',
                timeout: 60000,
                body: JSON.stringify(body)
            }),

        /** Passive rate-limit check ('login' or 'recovery'); never counts as an attempt. */
        rateLimitStatus: (event) =>
            _req('/api/auth/ratelimit/status?event=' + encodeURIComponent(event)),
    };

    // ── Helpers ───────────────────────────────────────────────────────────────

    function showAlert(el, msg, type = 'danger') {
        if (!el) return;
        el.className = `alert alert-${type}`;
        el.textContent = msg;
        el.hidden = false;
    }

    function hideAlert(el) {
        if (el) {
            el.hidden = true;
            el.textContent = '';
        }
    }

    /**
     * ALTCHA payload from the widget's hidden input, falling back to the
     * element's own .value when that input is absent.
     */
    function captchaPayloadOf(widget) {
        if (!widget) return null;
        const root = widget.shadowRoot || widget;
        return root.querySelector?.('input[type="hidden"]')?.value ||
            widget.value ||
            null;
    }

    /**
     * True when the captcha is off or solved: submit buttons wait on this so a
     * click cannot race a slow proof-of-work. getState() is preferred because
     * after reset() the hidden input may still hold the spent payload.
     */
    function captchaReady() {
        if (!CAP.enabled) return true;
        const widget = document.getElementById('altcha');
        if (!widget) return true;
        if (typeof widget.getState === 'function') return widget.getState() === 'verified';
        return !!captchaPayloadOf(widget);
    }

    /**
     * Every submit gate on the page, re-run when something outside their own
     * input changes: the captcha finishing, a lockout ending, a request settling.
     */
    const _gates = [];
    function refreshGates() {
        _gates.forEach(g => g());
    }

    (function watchCaptcha() {
        if (!CAP.enabled) return;
        const widget = document.getElementById('altcha');
        if (!widget) return;
        widget.addEventListener('statechange', (e) => {
            // An expired challenge would leave the gated submit button
            // disabled with nothing to click, so re-solve it here.
            if (e.detail?.state === 'expired' && typeof widget.verify === 'function') {
                widget.verify();
            }
            refreshGates();
        });
    })();

    /**
     * ALTCHA token from the widget, or null if the captcha is off. Restarts an
     * idle widget and waits up to 60 s, then throws: a null token is a sure
     * captcha_error that also counts against the rate limit.
     */
    async function getCaptchaToken() {
        if (!CAP.enabled) return null;
        const widget = document.getElementById('altcha');
        if (!widget) return null;

        const ready = captchaPayloadOf(widget);
        if (ready) return ready;

        // auto="onload" only fires once, so a stale page needs a manual kick.
        const state = (typeof widget.getState === 'function') ? widget.getState() : null;
        if ((state === 'expired' || state === 'error' || state === 'unverified') &&
            typeof widget.verify === 'function') {
            widget.verify();
        }

        const token = await new Promise(resolve => {
            const handler = (e) => {
                const st = e.detail?.state;
                if (st === 'verified' || st === 'error') {
                    widget.removeEventListener('statechange', handler);
                    clearTimeout(timer);
                    resolve(e.detail?.payload || captchaPayloadOf(widget));
                }
            };
            const timer = setTimeout(() => {
                widget.removeEventListener('statechange', handler);
                resolve(captchaPayloadOf(widget));
            }, 60000);
            widget.addEventListener('statechange', handler);
        });
        if (!token) throw new Error(t('captcha_error'));
        return token;
    }

    /**
     * Drop the payload and start a fresh solve. Each challenge is single-use
     * server-side but the widget keeps offering it, so call this after every
     * request that carried a token.
     */
    function resetCaptcha() {
        if (!CAP.enabled) return;
        const widget = document.getElementById('altcha');
        if (!widget) return;
        try {
            if (typeof widget.reset === 'function') widget.reset();
            if (typeof widget.verify === 'function') widget.verify();
        } catch { /* widget not upgraded yet; the next attempt solves normally */ }
    }

    let _lockoutUntil = 0;
    let _lockoutTimer = null;

    /**
     * True while a rate-limit lockout is running. The password and
     * recovery-phrase gates check it, since Enter on a field bypasses a
     * disabled button. Passkey login deliberately ignores it.
     */
    function isLockedOut() {
        return Date.now() < _lockoutUntil;
    }

    /**
     * Show the lockout countdown and disable `btn` and the login button
     * until it ends. Calling it again replaces the running countdown.
     */
    function startLockout(retryAfter, btn, bannerEl, msgEl) {
        _lockoutUntil = Date.now() + retryAfter * 1000;
        if (_lockoutTimer) clearTimeout(_lockoutTimer);

        // Not the passkey control: it is a way back in for someone who has
        // forgotten the password, and is rate-limited in its own bucket
        // server-side.
        const gated = [btn, $('btn-login')].filter(Boolean);
        gated.forEach(el => { el.disabled = true; });

        if (bannerEl) bannerEl.hidden = false;
        let remaining = retryAfter;

        const tick = () => {
            const mins = Math.floor(remaining / 60);
            const secs = remaining % 60;
            if (msgEl) msgEl.textContent =
                fmt(t('lockout_message'), { time: `${mins}:${String(secs).padStart(2, '0')}` });
            if (remaining <= 0) {
                _lockoutUntil = 0;
                _lockoutTimer = null;
                gated.forEach(el => { el.disabled = false; });
                // The page gates re-disable anything still blocked for
                // another reason (empty field, captcha still solving).
                refreshGates();
                if (bannerEl) bannerEl.hidden = true;
                return;
            }
            remaining--;
            _lockoutTimer = setTimeout(tick, 1000);
        };
        tick();
    }

    /**
     * Common handling for a failed auth request. A 429 shows only the
     * countdown banner; everything else goes to the alert box.
     */
    function handleAuthError(e, btn, errEl, fallbackMsg) {
        if (e.httpStatus === 429) {
            startLockout(e.retryAfter || 900, btn, $('lockout-banner'), $('lockout-msg'));
            return;
        }
        showAlert(errEl, e.message || fallbackMsg);
        if (btn) btn.disabled = false;
        refreshGates();
    }

    // Enable a submit button only once its paired field has content, as on
    // the dashboard. `extra` is a further condition (captcha solved, no
    // lockout, no request in flight); refreshGates() re-evaluates it.
    function gateOnInput(inputEl, btnEl, extra = null) {
        if (!inputEl || !btnEl) return;
        const check = () => {
            btnEl.disabled = !inputEl.value.trim() || (extra !== null && !extra());
        };
        check();
        inputEl.addEventListener('input', check);
        _gates.push(check);
        return check;
    }

    // ── LOGIN PAGE ────────────────────────────────────────────────────────────
    if ($('btn-login')) {

        let _pendingPassword = null;
        const errEl = $('login-err');
        const lockoutEl = $('lockout-banner');
        const lockMsgEl = $('lockout-msg');

        // Show a running lockout on page load (e.g. after a refresh), not
        // only after the next failed attempt.
        auth.rateLimitStatus('login').then(res => {
            if (res.blocked) startLockout(res.retry_after || 900, $('btn-login'), lockoutEl, lockMsgEl);
        }).catch(() => {
            // Not fatal: the countdown then appears after a real attempt.
        });

        // While a request is in flight, neither the captcha settling nor a
        // keystroke may re-enable the button.
        let _loginBusy = false;
        const loginReady = () => !_loginBusy && !isLockedOut() && captchaReady();
        const checkPwGate = gateOnInput($('inp-password'), $('btn-login'), loginReady);

        const doLogin = async function() {
            const pw = $('inp-password')?.value ?? '';
            hideAlert(errEl);
            if (!pw) return;
            // Enter on the field bypasses the disabled button.
            if (!loginReady()) return;

            const btn = $('btn-login');
            _loginBusy = true;
            btn.disabled = true;

            try {
                const captchaToken = await getCaptchaToken();
                // Fresh session key for this login; the server wraps the
                // MASTER_KEY with it.
                const newSessionKey = sessionKey.generate();
                const body = AESBinHelper.withHoneypot({
                    password: pw,
                    captcha_token: captchaToken,
                    session_key: newSessionKey,
                });
                const res = await auth.loginWithBody(body);
                resetCaptcha();

                if (res.status === 'key_mismatch') {
                    $('mismatch-banner').hidden = false;
                    $('step-pw').hidden = false;
                    $('step-totp').hidden = true;
                    $('inp-password').value = '';
                    checkPwGate();

                } else if (res.status === 'totp_required') {
                    _pendingPassword = pw;
                    $('step-pw').hidden = true;
                    $('step-totp').hidden = false;
                    $('inp-totp')?.focus();

                } else if (res.status === 'ok') {
                    _pendingPassword = null;
                    window.location.href = appUrl('');
                }

            } catch (e) {
                // A wrong password spends the token too.
                resetCaptcha();
                handleAuthError(e, btn, errEl, t('login_error'));
            } finally {
                _loginBusy = false;
                refreshGates();
            }
        };

        $('btn-login').addEventListener('click', doLogin);
        $('inp-password')?.addEventListener('keydown', e => {
            if (e.key === 'Enter') doLogin();
        });

        const checkTotpGate = gateOnInput($('inp-totp'), $('btn-totp'));

        let _totpBusy = false; // Enter bypasses the disabled button
        const doTotp = async function() {
            if (_totpBusy) return;
            const code = $('inp-totp')?.value.trim() ?? '';
            hideAlert(errEl);
            if (!code) return;
            // Format only; the server verifies the code. A typo is not worth
            // a round trip, nor an attempt counted against the rate limit.
            if (!/^\d{6}$/.test(code)) {
                showAlert(errEl, t('totp_error'));
                return;
            }
            $('btn-totp').disabled = true;
            _totpBusy = true;

            try {
                const res = await auth.finalize(_pendingPassword, code);
                _pendingPassword = null;
                if (res.status === 'key_mismatch') {
                    $('mismatch-banner').hidden = false;
                    $('step-pw').hidden = false;
                    $('step-totp').hidden = true;
                } else {
                    window.location.href = appUrl('');
                }
            } catch (e) {
                // finalize() re-checks the password, which login() already
                // accepted: bad_password means it changed in between (a
                // recovery elsewhere). Back to step 1.
                if (e.errorCode === 'bad_password') {
                    _pendingPassword = null;
                    $('step-totp').hidden = true;
                    $('step-pw').hidden = false;
                    if ($('inp-password')) $('inp-password').value = '';
                    if ($('inp-totp')) $('inp-totp').value = '';
                    checkPwGate();
                    checkTotpGate();
                    showAlert(errEl, e.message || t('login_error'));
                    $('inp-password')?.focus();
                } else {
                    handleAuthError(e, $('btn-totp'), errEl, t('totp_error'));
                }
            } finally {
                _totpBusy = false;
                // Leave it disabled if a lockout just started.
                if (!isLockedOut()) checkTotpGate();
            }
        };

        $('btn-totp')?.addEventListener('click', doTotp);
        $('inp-totp')?.addEventListener('keydown', e => {
            if (e.key === 'Enter') doTotp();
        });
    }

    // ── RECOVERY PAGE ─────────────────────────────────────────────────────────
    if ($('btn-verify-phrase')) {

        const errEl = $('recovery-err');
        const okEl = $('recovery-ok');
        const lockoutEl = $('recovery-lockout-banner');
        const lockMsgEl = $('recovery-lockout-msg');

        // Same page-load check as the login page.
        auth.rateLimitStatus('recovery').then(res => {
            if (res.blocked) startLockout(res.retry_after || 900, $('btn-verify-phrase'), lockoutEl, lockMsgEl);
        }).catch(() => {});

        // In-flight flags, as _loginBusy.
        let _phraseBusy = false;
        let _execBusy = false;
        // Set once the reset has gone through, so the next captcha solve
        // does not re-enable the button.
        let _execDone = false;
        const phraseReady = () => !_phraseBusy && !isLockedOut() && captchaReady();
        gateOnInput($('inp-phrase'), $('btn-verify-phrase'), phraseReady);
        gateOnInput($('inp-guard'), $('btn-verify-guard'));

        // Step 0: arm the guard file on load, then ask for the code inside
        // it. The endpoint reuses an existing file, so reloads are harmless.
        auth.recoveryGuard().catch(e => {
            showAlert(errEl, e.message || t('recovery_error'));
        });

        $('btn-verify-guard')?.addEventListener('click', async () => {
            const code = $('inp-guard')?.value.trim() ?? '';
            hideAlert(errEl);
            if (!code) return;

            const btn = $('btn-verify-guard');
            btn.disabled = true;
            try {
                await auth.recoveryGuardVerify(code);
                $('step-guard').hidden = true;
                $('step-phrase').hidden = false;
                // The intro explains the phrase field.
                if ($('recovery-hint-intro')) $('recovery-hint-intro').hidden = false;
                $('inp-phrase')?.focus();
            } catch (e) {
                handleAuthError(e, btn, errEl, t('recovery_error'));
            }
        });

        $('inp-guard')?.addEventListener('keydown', e => {
            if (e.key === 'Enter') $('btn-verify-guard')?.click();
        });

        // Step 1: verify phrase
        $('btn-verify-phrase').addEventListener('click', async () => {
            const phrase = $('inp-phrase')?.value.trim() ?? '';
            hideAlert(errEl);
            hideAlert(okEl);
            if (!phrase || !phraseReady()) return;

            const btn = $('btn-verify-phrase');
            _phraseBusy = true;
            btn.disabled = true;

            try {
                const body = AESBinHelper.withHoneypot({
                    phrase,
                    captcha_token: await getCaptchaToken()
                });
                await auth.verifyRecoveryWithBody(body);
                resetCaptcha();
                $('step-phrase').hidden = true;
                $('step-choose').hidden = false;
                if ($('recovery-hint-intro')) $('recovery-hint-intro').hidden = true;
                $('inp-newpw')?.focus();
            } catch (e) {
                resetCaptcha();
                handleAuthError(e, btn, errEl, t('recovery_error'));
            } finally {
                _phraseBusy = false;
                refreshGates();
            }
        });

        // Submit is disabled until at least one reset option is chosen.
        const checkResetGate = () => {
            const btn = $('btn-execute-recovery');
            // Rotation counts only once the new phrase has been saved:
            // until then it exists nowhere but on this screen.
            const rotateReady =
                $('chk-rotate-phrase')?.checked && $('chk-phrase-saved')?.checked;
            // Likewise a new TOTP secret, once a code for it has been verified.
            const totpReady = $('chk-reset-totp')?.checked && newTotpVerified;
            // This step needs a fresh token, and the widget is hidden with
            // the phrase step, so say that it is still solving.
            const capOk = captchaReady();
            const waitEl = $('recovery-captcha-wait');
            if (waitEl) {
                const widget = document.getElementById('altcha');
                const failed = typeof widget?.getState === 'function' && widget.getState() === 'error';
                waitEl.hidden = capOk || _execDone;
                waitEl.textContent = failed ? t('altcha_error') : t('altcha_verifying');
            }
            // Every ticked option must be ready, not just one: with a new
            // password typed, an unsaved phrase would otherwise be committed
            // (lost for good), and an unverified secret refused.
            const pwChosen = $('chk-reset-pw')?.checked;
            const totpChosen = $('chk-reset-totp')?.checked;
            const phraseChosen = $('chk-rotate-phrase')?.checked;
            if (btn) btn.disabled = _execBusy || _execDone || !capOk ||
                !(pwChosen || totpChosen || phraseChosen) ||
                (totpChosen && !totpReady) ||
                (phraseChosen && !rotateReady);
        };
        _gates.push(checkResetGate);
        $('chk-reset-pw')?.addEventListener('change', e => {
            if ($('wrap-new-pw')) $('wrap-new-pw').hidden = !e.target.checked;
            checkResetGate();
        });

        // The new TOTP secret: minted by the server, shown with its QR, and
        // verified with a code from the authenticator before confirm unlocks.
        // The server keeps its hash and accepts only that secret, verified.
        let newTotpSecret = '';
        let newTotpVerified = false;
        const totpStatus = (msg, isError) => {
            const el = $('new-totp-status');
            if (!el) return;
            el.textContent = msg;
            el.hidden = !msg;
            el.classList.toggle('text-danger', !!isError);
        };

        // Asks the server for a secret and shows it, unverified. Also how the
        // page recovers when the server no longer knows the one shown: the
        // session is per browser, so recovery opened in a second tab replaces
        // this tab's secret (codes totp_expired, totp_unverified). notice
        // says so; the code field and its button come back.
        const mintTotp = async (notice = '') => {
            const res = await auth.recoveryTotpNew();
            newTotpSecret = res.totp_secret;
            newTotpVerified = false;
            $('recovery-totp-secret').textContent = newTotpSecret;
            AESBinHelper.renderQr($('recovery-totp-qr'), res.totp_uri, 200);
            const inp = $('inp-new-totp');
            if (inp) { inp.disabled = false; inp.value = ''; }
            const vbtn = $('btn-verify-new-totp');
            if (vbtn) vbtn.disabled = false;
            totpStatus(notice, !!notice);
            checkResetGate();
        };

        $('chk-reset-totp')?.addEventListener('change', async e => {
            const wrap = $('wrap-new-totp');
            if (wrap) wrap.hidden = !e.target.checked;
            checkResetGate();
            if (!e.target.checked) return;
            // Minted once per page, like the phrase: re-ticking must not
            // replace a secret already added to an authenticator.
            if (newTotpSecret) return;
            try {
                await mintTotp();
            } catch (err) {
                showAlert(errEl, err.message || t('recovery_error'));
                e.target.checked = false;
                if (wrap) wrap.hidden = true;
                checkResetGate();
            }
        });

        // Replaces a secret the server no longer holds, see mintTotp().
        const renewTotp = async () => {
            try {
                await mintTotp(t('recovery_totp_renewed'));
                $('wrap-new-totp')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch (err) {
                totpStatus(err.message || t('recovery_error'), true);
            }
        };

        const verifyNewTotp = async () => {
            const inp = $('inp-new-totp');
            const btn = $('btn-verify-new-totp');
            const code = (inp?.value ?? '').replace(/\s+/g, '');
            if (!newTotpSecret || !/^\d{6}$/.test(code)) {
                totpStatus(t('totp_error'), true);
                inp?.focus();
                return;
            }
            if (btn) btn.disabled = true;
            try {
                await auth.recoveryTotpVerify(newTotpSecret, code);
                newTotpVerified = true;
                // An earlier "verify first" refusal no longer applies.
                hideAlert(errEl);
                totpStatus(t('recovery_totp_verified'), false);
                if (inp) inp.disabled = true;
            } catch (err) {
                newTotpVerified = false;
                if (err.errorCode === 'totp_expired') {
                    await renewTotp();
                    return;
                }
                totpStatus(err.message || t('totp_error'), true);
                if (btn) btn.disabled = false;
                inp?.select();
            }
            checkResetGate();
        };
        $('btn-verify-new-totp')?.addEventListener('click', verifyNewTotp);
        $('inp-new-totp')?.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                verifyNewTotp();
            }
        });

        // Shown, offered as a .txt download, and sent back with the reset:
        // the server keeps only its hash and accepts no other phrase.
        let newPhraseText = '';

        // Asks the server for a phrase and shows it, not yet saved. Also how
        // the page recovers when the server no longer knows the one shown
        // (code phrase_expired: recovery opened in a second tab replaced it).
        // The saved-it box is cleared and locked again: a phrase written
        // down before is not the one a confirm would set.
        const mintPhrase = async (notice = '') => {
            const res = await auth.recoveryPhraseNew();
            newPhraseText = res.phrase;
            $('new-phrase-box').textContent = newPhraseText;
            const saved = $('chk-phrase-saved');
            if (saved) { saved.checked = false; saved.disabled = true; }
            const st = $('new-phrase-status');
            if (st) {
                st.textContent = notice;
                st.hidden = !notice;
                st.classList.toggle('text-danger', !!notice);
            }
            checkResetGate();
        };

        $('chk-rotate-phrase')?.addEventListener('change', async e => {
            const wrap = $('wrap-new-phrase');
            if (wrap) wrap.hidden = !e.target.checked;
            checkResetGate();
            if (!e.target.checked) return;

            // Minted once per page: re-ticking must not replace a phrase the
            // owner may already have written down.
            if (newPhraseText) return;

            try {
                await mintPhrase();
            } catch (err) {
                showAlert(errEl, err.message || t('recovery_error'));
                e.target.checked = false;
                if (wrap) wrap.hidden = true;
                checkResetGate();
            }
        });

        // Copying or downloading the phrase unlocks the acknowledgement box,
        // which unlocks the confirm button (as in the installer).
        const phraseSaved = () => {
            const chk = $('chk-phrase-saved');
            if (chk) chk.disabled = false;
        };
        $('btn-copy-new-phrase')?.addEventListener('aesbin:copied', phraseSaved);
        $('btn-dl-new-phrase')?.addEventListener('click', () => {
            if (!newPhraseText) return;
            AESBinHelper.downloadRecoveryPhrase(newPhraseText);
            phraseSaved();
        });
        $('chk-phrase-saved')?.addEventListener('change', e => {
            // A renewal notice (mintPhrase()) is answered once this one is saved.
            if (e.target.checked && $('new-phrase-status')) $('new-phrase-status').hidden = true;
            checkResetGate();
        });

        checkResetGate();

        $('inp-newpw')?.addEventListener('input', () =>
            AESBinHelper.paintPwStrength($('inp-newpw').value, $('pw-fill'), $('pw-label')));

        // Step 2: execute recovery
        $('btn-execute-recovery')?.addEventListener('click', async () => {
            const phrase = $('inp-phrase')?.value.trim() ?? '';
            const resetPw = $('chk-reset-pw')?.checked ?? true;
            const resetTotp = $('chk-reset-totp')?.checked ?? false;
            const rotatePhrase = $('chk-rotate-phrase')?.checked ?? false;
            const newPw = $('inp-newpw')?.value ?? '';
            const confirmPw = $('inp-newpw2')?.value ?? '';

            hideAlert(errEl);
            hideAlert(okEl);

            if (!resetPw && !resetTotp && !rotatePhrase) {
                showAlert(errEl, t('recovery_pick_one'));
                return;
            }

            if (resetPw) {
                if (AESBinHelper.pwTooShort(newPw)) {
                    showAlert(errEl, t('password_too_short'));
                    return;
                }
                if (newPw !== confirmPw) {
                    showAlert(errEl, t('password_mismatch'));
                    return;
                }
            }

            const btn = $('btn-execute-recovery');
            if (_execBusy || _execDone || !captchaReady()) return;
            _execBusy = true;
            btn.disabled = true;

            try {
                // execute re-checks the phrase with Argon2id, so it carries the
                // same captcha gate as verify, with a fresh token.
                const body = AESBinHelper.withHoneypot({
                    phrase,
                    new_password: resetPw ? newPw : undefined,
                    new_password_confirm: resetPw ? confirmPw : undefined,
                    reset_totp: resetTotp || undefined,
                    new_totp_secret: resetTotp ? newTotpSecret : undefined,
                    rotate_phrase: rotatePhrase || undefined,
                    new_phrase: rotatePhrase ? newPhraseText : undefined,
                    // No session_key: recovery does not log in, and generating
                    // one would overwrite aesbin_sk with a key nothing is
                    // wrapped under.
                    captcha_token: await getCaptchaToken(),
                });

                const res = await auth.executeRecoveryWithBody(body);
                _execDone = true;
                resetCaptcha();

                // The recovery went through, but config.php could not be
                // rewritten, so the gate is still open: the owner must act.
                if (res.warning) showAlert(errEl, res.warning, 'warning');

                // Recovery removed every passkey: an empty
                // signalAllAcceptedCredentials list tells this browser's
                // password manager to hide them (Chromium only). Run after the
                // result is shown, with a timeout: a stuck extension must not
                // hold up the redirect to the login page.
                const signalPasskeys = async () => {
                    if (!res.passkey_user_id || !res.passkey_rp_id) return '';
                    let signalled = false;
                    if (window.PublicKeyCredential?.signalAllAcceptedCredentials) {
                        try {
                            await Promise.race([
                                PublicKeyCredential.signalAllAcceptedCredentials({
                                    rpId: res.passkey_rp_id,
                                    userId: res.passkey_user_id,
                                    allAcceptedCredentialIds: [],
                                }),
                                new Promise((_, reject) => setTimeout(reject, 3000)),
                            ]);
                            signalled = true;
                        } catch { /* best-effort */ }
                    }
                    return signalled
                        ? t('passkey_manager_signaled')
                        : t('passkey_manager_manual');
                };

                showAlert(okEl, t('recovery_success'), 'success');
                const passkeyNote = await signalPasskeys();
                if (passkeyNote) {
                    showAlert(okEl, t('recovery_success') + ' ' + passkeyNote, 'success');
                }
                // Longer with the passkey note, which may ask for a manual
                // step; not at all with a warning, which does.
                if (!res.warning) {
                    setTimeout(() => {
                        window.location.href = appUrl('login');
                    }, passkeyNote ? 6000 : 1500);
                }

            } catch (e) {
                resetCaptcha();
                // A secret or phrase this tab holds was replaced (see
                // mintTotp() and mintPhrase()): a fresh one, with a notice,
                // and the owner confirms again once it is verified or saved.
                if (e.errorCode === 'totp_unverified') {
                    hideAlert(errEl);
                    await renewTotp();
                } else if (e.errorCode === 'phrase_expired') {
                    hideAlert(errEl);
                    try {
                        await mintPhrase(t('recovery_phrase_renewed'));
                        $('wrap-new-phrase')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    } catch (err) {
                        showAlert(errEl, err.message || t('recovery_error'));
                    }
                } else {
                    handleAuthError(e, btn, errEl, t('recovery_error'));
                }
            } finally {
                _execBusy = false;
                refreshGates();
            }
        });
    }

    // ── Passkey login ─────────────────────────────────────────────────────────
    const passkeyBtn = $('btn-passkey-login');
    const passkeySection = passkeyBtn?.closest('.passkey-section');
    if (passkeyBtn && window.AESBinPasskey?.supported) {
        // Hidden without PRF support, which passkey login cannot work without.
        (async () => {
            if (!await window.AESBinPasskey.prfSupported()) {
                if (passkeySection) passkeySection.hidden = true;
                return;
            }
            passkeyBtn.addEventListener('click', async () => {
                // No isLockedOut() guard on purpose; see startLockout().
                passkeyBtn.disabled = true;
                const origText = passkeyBtn.innerHTML;
                passkeyBtn.innerHTML = AESBinHelper.icon('hourglass');
                try {
                    // Before authenticate(), so its requests carry X-Session-Key.
                    sessionKey.generate();
                    await window.AESBinPasskey.authenticate();
                    window.location.href = appUrl('');
                } catch (e) {
                    passkeyBtn.innerHTML = origText;
                    handleAuthError(e, passkeyBtn, $('login-err'), t('passkey_login_error'));
                }
            });
        })();
    } else if (passkeySection) {
        passkeySection.hidden = true;
    }

})();
