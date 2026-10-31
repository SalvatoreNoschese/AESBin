/**
 * public/assets/js/passkey.js — WebAuthn passkeys
 *
 * window.AESBinPasskey: register() (settings) and authenticate() (login
 * page). A passkey replaces both password and TOTP.
 *
 * The master key is wrapped with the WebAuthn PRF output as KEK. That
 * output never leaves this script: it is used once as an AES-GCM key and
 * dropped. The server only sees the wrapped {enc, iv, tag}.
 *
 * Without PRF support, registration fails with "PRF not supported" and
 * the account keeps password + TOTP.
 */
/* global AESBinHelper */
(function() {
    'use strict';

    const L = window.AESBIN_LANG || {};
    const t = k => L[k] || k;
    const { appUrl } = AESBinHelper;

    // WebAuthn requires a secure context.
    const _pkSupported = !!window.PublicKeyCredential && window.isSecureContext;
    if (!_pkSupported) {
        // Stub so the dashboard's loadPasskeyList() can explain.
        window.AESBinPasskey = {
            supported: false,
            prfSupported: async () => false,
        };
        return;
    }

    // ── Base64 / base64url helpers ────────────────────────────────────────────

    function toStdBase64(buf) {
        return btoa(String.fromCharCode(...new Uint8Array(buf)));
    }

    function fromStdBase64(str) {
        return Uint8Array.from(atob(str), c => c.charCodeAt(0));
    }

    function base64url(buf) {
        return btoa(String.fromCharCode(...new Uint8Array(buf)))
            .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }

    function fromBase64url(str) {
        str = str.replace(/-/g, '+').replace(/_/g, '/');
        while (str.length % 4) str += '=';
        return Uint8Array.from(atob(str), c => c.charCodeAt(0)).buffer;
    }

    // ── AES-256-GCM wrap/unwrap (WebCrypto) ───────────────────────────────────
    // Matches CryptoService's { enc, iv, tag } (standard base64). WebCrypto
    // appends the 16-byte tag to the ciphertext; OpenSSL keeps it separate,
    // hence the split and join.
    const GCM_IV_BYTES = 12;
    const GCM_TAG_BITS = 128;

    /**
     * Associated data of a passkey's wrapped MASTER_KEY: what the value is
     * and which credential it belongs to, as CryptoService binds everything
     * the server seals (its "Associated data" section). The only place the
     * string is built: wrap and unwrap must agree byte for byte, and a copy
     * wrapped without it (or for another credential) does not open.
     *
     * @param {ArrayBuffer} rawId  The credential's raw id
     */
    const passkeyAad = rawId => new TextEncoder().encode('passkey:' + base64url(rawId));

    async function gcmWrap(plaintextBytes, keyBytes, aad) {
        const key = await crypto.subtle.importKey('raw', keyBytes, { name: 'AES-GCM' }, false, ['encrypt']);
        const iv = crypto.getRandomValues(new Uint8Array(GCM_IV_BYTES));
        const sealed = await crypto.subtle.encrypt({ name: 'AES-GCM', iv, additionalData: aad, tagLength: GCM_TAG_BITS }, key, plaintextBytes);
        const sealedBytes = new Uint8Array(sealed);
        const tagStart = sealedBytes.length - (GCM_TAG_BITS / 8);
        return {
            enc: toStdBase64(sealedBytes.slice(0, tagStart)),
            iv: toStdBase64(iv),
            tag: toStdBase64(sealedBytes.slice(tagStart)),
        };
    }

    async function gcmUnwrap(wrapped, keyBytes, aad) {
        const key = await crypto.subtle.importKey('raw', keyBytes, { name: 'AES-GCM' }, false, ['decrypt']);
        const enc = fromStdBase64(wrapped.enc);
        const iv = fromStdBase64(wrapped.iv);
        const tag = fromStdBase64(wrapped.tag);
        const sealed = new Uint8Array(enc.length + tag.length);
        sealed.set(enc, 0);
        sealed.set(tag, enc.length);
        const plain = await crypto.subtle.decrypt({ name: 'AES-GCM', iv, additionalData: aad, tagLength: GCM_TAG_BITS }, key, sealed);
        return new Uint8Array(plain);
    }

    // ── WebAuthn args preparation ─────────────────────────────────────────────

    // The server sends base64url strings; WebAuthn wants buffers. The PRF
    // extension is filled in by register(), from prf_salt.
    function prepareCreateArgs(args) {
        const pk = args.publicKey;
        pk.challenge = fromBase64url(pk.challenge);
        pk.user.id = fromBase64url(pk.user.id);
        if (pk.excludeCredentials) {
            pk.excludeCredentials = pk.excludeCredentials.map(c => ({
                ...c,
                id: fromBase64url(c.id)
            }));
        }
        return pk;
    }

    function prepareGetArgs(args) {
        const pk = args.publicKey;
        pk.challenge = fromBase64url(pk.challenge);
        if (pk.allowCredentials) {
            pk.allowCredentials = pk.allowCredentials.map(c => ({
                ...c,
                id: fromBase64url(c.id)
            }));
        }
        if (pk.extensions && pk.extensions.prf && pk.extensions.prf.evalByCredential) {
            const out = {};
            for (const [credId, ev] of Object.entries(pk.extensions.prf.evalByCredential)) {
                out[credId] = { first: fromBase64url(ev.first) };
            }
            pk.extensions.prf.evalByCredential = out;
        }
        return pk;
    }

    /**
     * JSON request with the owner headers, through AESBinHelper.fetchJson().
     * Resolves to the response body even for an error status, which the
     * callers read as {ok: false, error, code}; only a network failure rejects.
     */
    async function request(url, opts = {}) {
        try {
            return await AESBinHelper.fetchJson(appUrl(url), {
                ...opts,
                headers: {
                    ...(opts.body ? { 'Content-Type': 'application/json' } : {}),
                    ...AESBinHelper.authHeaders(),
                },
            });
        } catch (err) {
            if (err.data) return err.data;
            throw err;
        }
    }
    const post = (url, body) => request(url, { method: 'POST', body: JSON.stringify(body) });
    const get = url => request(url);

    // How long a request still waiting may go on after the page comes back
    // into view (credentialsCall()).
    const RETURN_GRACE_MS = 2000;

    /**
     * navigator.credentials.create() or .get(), cancelled when the page comes
     * back into view and the request is still waiting. Switching app while
     * the passkey sheet is open closes the sheet without telling the page,
     * which would wait until the request's timeout with its button busy.
     * Not at once: returning from a full-screen password manager, the
     * answer can arrive just after the page is visible again. Cancelled, it
     * rejects with AbortError, which the callers report as passkey_cancelled.
     */
    function credentialsCall(method, options) {
        const ctrl = new AbortController();
        let timer = null;
        const onVisibility = () => {
            if (document.visibilityState !== 'visible' || timer !== null) return;
            timer = setTimeout(() => ctrl.abort(), RETURN_GRACE_MS);
        };
        document.addEventListener('visibilitychange', onVisibility);
        return navigator.credentials[method]({ ...options, signal: ctrl.signal }).finally(() => {
            clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisibility);
        });
    }

    // ── Registration ──────────────────────────────────────────────────────────

    /**
     * Tell the browser's password manager that a passkey it has just created
     * is not known to the server, so that it stops offering it. Best effort:
     * not every browser has the call, and a refusal changes nothing.
     */
    async function signalUnknown(rpId, rawId) {
        try {
            if (window.PublicKeyCredential?.signalUnknownCredential) {
                await PublicKeyCredential.signalUnknownCredential({
                    rpId: rpId || location.hostname,
                    credentialId: base64url(rawId),
                });
            }
        } catch { /* best-effort */ }
    }

    async function register() {
        // No name is sent: passkey_register.php builds the label, so the
        // browser never writes what the owner audits.

        // 1. Challenge, with the server-generated prf_salt. Through get() for
        // the X-Session-Key header: the endpoint unwraps the master key
        // (requireElevated() → requireKeyHolder()) and answers
        // session_key_conflict without it. The login challenge is public.
        const ch = await get('/api/auth/passkey/challenge?mode=register');
        if (!ch.ok) throw Object.assign(new Error(ch.error || t('server_error')), { code: ch.code });
        if (!ch.prf_salt) throw new Error(t('server_error'));

        const prfSaltBytes = fromStdBase64(ch.prf_salt);

        // 2. Create the passkey with prf.eval.first, so (WebAuthn L3) the
        //    PRF result can come back from create() itself. Google Password
        //    Manager and other synced providers require this.
        const createOptions = prepareCreateArgs(ch.args);
        createOptions.extensions = {
            prf: { eval: { first: prfSaltBytes } },
        };
        // The name password managers show ends with the registration date,
        // which the server can only write in UTC and as YYYY-MM-DD: a
        // passkey made just after midnight would carry the day before.
        // Rewritten with today's date as this device writes dates, since
        // the manager that lists it is on this device. Only a label: no
        // part of the verification reads it.
        if (createOptions.user?.name) {
            const today = new Date().toLocaleDateString(undefined, {
                year: 'numeric', month: '2-digit', day: '2-digit',
            });
            createOptions.user.name = createOptions.user.name.replace(/\d{4}-\d{2}-\d{2}$/, today);
        }

        let credential;
        try {
            credential = await credentialsCall('create', { publicKey: createOptions });
        } catch (err) {
            // Credential already on the authenticator: InvalidStateError in
            // Chrome, a "transient reason" message in Firefox.
            const isAlreadyRegistered =
                err.name === 'InvalidStateError' ||
                (typeof err.message === 'string' && /transient/i.test(err.message));
            if (isAlreadyRegistered) throw new Error(t('passkey_already_registered'), { cause: err });
            // Dismissed or timed out: the browser's message is English
            // boilerplate, so use a translated one.
            if (err.name === 'NotAllowedError' || err.name === 'AbortError') {
                throw new Error(t('passkey_cancelled'), { cause: err });
            }
            // Anything else the browser or the password manager reports
            // comes in English and in its own words.
            throw new Error(t('err_passkey_register'), { cause: err });
        }

        const createExt = credential.getClientExtensionResults();
        let prfResult = createExt?.prf?.results?.first ?? null;

        // PRF enabled but not evaluated at creation: prf.results from
        // create() is optional in WebAuthn L3, and some authenticators only
        // evaluate on get(). One extra assertion on the new credential, with
        // the same salt as at login, yields the KEK a later login derives.
        // It is never sent to the server (the attestation below proves the
        // registration), so a random challenge is enough.
        if (!prfResult && createExt?.prf?.enabled === true) {
            let assertion;
            try {
                assertion = await credentialsCall('get', {
                    publicKey: {
                        challenge: crypto.getRandomValues(new Uint8Array(32)),
                        rpId: createOptions.rp && createOptions.rp.id ? createOptions.rp.id : undefined,
                        allowCredentials: [{
                            type: 'public-key',
                            id: credential.rawId,
                            transports: typeof credential.response.getTransports === 'function'
                                ? credential.response.getTransports()
                                : undefined,
                        }],
                        userVerification: 'required',
                        timeout: createOptions.timeout,
                        extensions: { prf: { eval: { first: prfSaltBytes } } },
                    },
                });
            } catch (err) {
                if (err.name === 'NotAllowedError' || err.name === 'AbortError') {
                    throw new Error(t('passkey_cancelled'), { cause: err });
                }
                throw new Error(t('err_passkey_register'), { cause: err });
            }
            prfResult = assertion.getClientExtensionResults()?.prf?.results?.first ?? null;
        }

        if (!prfResult) {
            // No PRF support (enabled:false) or the extension was ignored.
            // The provider has already saved the passkey (Bitwarden, for
            // one, returns no PRF output), but the server never learns of
            // it. Best-effort: tell the manager to drop it.
            await signalUnknown(createOptions.rp?.id, credential.rawId);
            throw new Error(t('passkey_prf_not_supported'));
        }

        // 3. Fetch the master key and wrap it with the PRF output (the KEK).
        // From here on the passkey exists on the device. When the server
        // answers with a refusal (elevation ran out, the limit was reached by
        // another tab) it has not stored it, and the manager is told as
        // above. Not on a network error, which throws out of request():
        // then nothing says whether the server saved it.
        const keyRes = await get('/api/auth/passkey/register/key');
        if (!keyRes.ok) {
            await signalUnknown(createOptions.rp?.id, credential.rawId);
            throw Object.assign(new Error(keyRes.error || t('server_error')), { code: keyRes.code });
        }
        const masterKeyBytes = fromStdBase64(keyRes.master_key);
        const wrapped = await gcmWrap(masterKeyBytes, prfResult, passkeyAad(credential.rawId));
        masterKeyBytes.fill(0);

        // 4. Attestation and wrapped master key; the KEK is never sent.
        const res = await post('/api/auth/passkey/register', {
            id: credential.id,
            rawId: base64url(credential.rawId),
            type: credential.type,
            response: {
                clientDataJSON:    base64url(credential.response.clientDataJSON),
                attestationObject: base64url(credential.response.attestationObject),
            },
            wrapped_master: wrapped.enc,
            wrapped_iv:     wrapped.iv,
            wrapped_tag:    wrapped.tag,
        });
        if (!res.ok) {
            await signalUnknown(createOptions.rp?.id, credential.rawId);
            throw Object.assign(new Error(res.error || t('err_passkey_register')), { code: res.code });
        }
        return res;
    }

    // ── Authentication ────────────────────────────────────────────────────────

    async function authenticate() {
        // 1. Challenge, with each known credential's PRF salt. no_session: the
        // page outlived its server session (an installed app resumed from the
        // background, its session expired or its cookie dropped). A new one
        // (session.php), then the same request again, within the same tap.
        let ch = await get('/api/auth/passkey/challenge?mode=login');
        if (!ch.ok && ch.code === 'no_session') {
            const s = await get('/api/auth/session');
            if (s.ok) {
                window.AESBIN_CSRF = s.csrf;
                ch = await get('/api/auth/passkey/challenge?mode=login');
            }
        }
        if (!ch.ok) throw new Error(ch.error || t('server_error'));

        // 2. The authenticator evaluates the PRF for the credential picked.
        let assertion;
        try {
            assertion = await credentialsCall('get', {
                publicKey: prepareGetArgs(ch.args)
            });
        } catch (err) {
            // As in register().
            if (err.name === 'NotAllowedError' || err.name === 'AbortError') {
                throw new Error(t('passkey_cancelled'), { cause: err });
            }
            // What is left is the device failing to produce the passkey,
            // most often because it was deleted from the password manager
            // while the server still lists it. The browser words it in
            // English ("An unknown error occurred while talking to the
            // credential manager"); this says what to do.
            throw new Error(t('passkey_device_error'), { cause: err });
        }

        const ext = assertion.getClientExtensionResults();
        const prfResult = ext?.prf?.results?.first ?? null;
        if (!prfResult) {
            throw new Error(t('passkey_prf_not_supported'));
        }

        // 3. Server verifies the signature and returns this credential's
        //    wrapped master key (useless without the KEK).
        const verifyRes = await post('/api/auth/passkey/verify', {
            id: assertion.id,
            rawId: base64url(assertion.rawId),
            type: assertion.type,
            response: {
                clientDataJSON: base64url(assertion.response.clientDataJSON),
                authenticatorData: base64url(assertion.response.authenticatorData),
                signature: base64url(assertion.response.signature),
                userHandle: assertion.response.userHandle ? base64url(assertion.response.userHandle) : null,
            },
        });
        if (!verifyRes.ok) throw new Error(verifyRes.error || t('passkey_login_error'));

        // 4. Unwrap the master key locally with the PRF-derived KEK.
        //    A copy that does not open (wrapped for another credential, or
        //    damaged) is a failed login like any other, not WebCrypto's own
        //    untranslated "operation failed".
        let masterKeyBytes;
        try {
            masterKeyBytes = await gcmUnwrap({
                enc: verifyRes.wrapped_master,
                iv: verifyRes.wrapped_iv,
                tag: verifyRes.wrapped_tag,
            }, prfResult, passkeyAad(assertion.rawId));
        } catch (err) {
            throw new Error(t('passkey_login_error'), { cause: err });
        }

        // 5. Send the master key (HTTPS only); the server checks it against
        //    the TOTP canary and creates the session.
        const masterKeyB64 = toStdBase64(masterKeyBytes);
        masterKeyBytes.fill(0);
        const res = await post('/api/auth/passkey/authenticate', {
            master_key: masterKeyB64,
        });
        if (!res.ok) throw new Error(res.error || t('passkey_login_error'));
        return res;
    }

    /**
     * Whether this browser can return the PRF output. getClientCapabilities()
     * answers where it exists, under "extension:prf" as the spec names
     * extension capabilities (silence about PRF counts as yes); otherwise
     * Android, Firefox and Safari count as no, the rest as yes. A wrong yes is
     * cheap: registration reports the missing PRF itself.
     */
    async function prfSupported() {
        if (window.PublicKeyCredential?.getClientCapabilities) {
            try {
                const caps = await PublicKeyCredential.getClientCapabilities();
                const prf = caps?.['extension:prf'];
                return typeof prf === 'boolean' ? prf : true;
            } catch {
                return false;
            }
        }
        const ua = navigator.userAgent;
        const isSafari = /Safari/i.test(ua) && !/Chrome/i.test(ua);
        return !(/Android/i.test(ua) || /Firefox/i.test(ua) || isSafari);
    }

    window.AESBinPasskey = {
        register,
        authenticate,
        prfSupported,
        supported: true
    };
})();
