/**
 * public/assets/js/api.js — AESBin API client (dashboard)
 *
 * All authenticated owner-side requests from the dashboard (dashboard.js,
 * passkey.js). Login, recovery and the share viewer use auth.js and share.js.
 *
 * The password travels as a JSON body field, so HTTPS is mandatory. The
 * MASTER_KEY is not kept client-side: sessionStorage holds only the per-tab
 * session key, useless without the server's wrapped copy. (passkey.js holds
 * the MASTER_KEY in page memory while wrapping and unwrapping it.)
 */
/* global AESBinHelper */

const API = (() => {
    'use strict';
    const L = window.AESBIN_LANG || {};
    const t = k => L[k] || k;

    // Per-tab session key, see AESBinHelper.sessionKey in helper.js.
    const { sessionKey, appUrl } = AESBinHelper;

    // No cross-tab key channel, deliberately: a new tab has no key and lands on
    // the conflict screen. A BroadcastChannel or postMessage would reach any
    // script on the origin (share viewer, /privacy included), so one XSS would
    // get the key to MASTER_KEY. An extra login is recoverable; a leak is not.

    // Single-flight: parallel requests that need elevation share one
    // password prompt.
    let _elevPending = null;
    function _requestElevation() {
        if (!_elevPending) {
            _elevPending = Promise.resolve()
                .then(() => API.onReauthRequired())
                .catch(() => false)
                .finally(() => { _elevPending = null; });
        }
        return _elevPending;
    }


    /**
     * A lost owner session: show the session-lock overlay with the reason
     * the server gave. 'conflict' (missing or stale key in this tab) offers
     * to log in here, see the note above. Returns a promise that never
     * settles, so the caller chain halts silently.
     */
    function _sessionLost(code) {
        const reason = {
            session_expired: 'expired',
            ip_changed: 'ip',
            session_key_conflict: 'conflict',
            session_revoked: 'revoked',
        }[code] || 'auth';
        if (typeof API.onSessionLost === 'function') {
            API.onSessionLost(reason);
        } else {
            // No handler registered yet by dashboard.js.
            location.href = appUrl('login');
        }
        return new Promise(() => {});
    }

    // Calls whose work grows with the vault (search, integrity, migrations,
    // directory shares) pass this instead of fetchJson()'s default timeout.
    const SLOW_TIMEOUT_MS = 120000;

    /**
     * An owner request through AESBinHelper.fetchJson(), plus what every
     * dashboard call needs: the CSRF token and session key, a reload on a
     * stale CSRF token, the elevation prompt, and the session-lock overlay.
     */
    async function _req(url, opts = {}) {
        const { _elevRetried = false, headers = {}, ...rest } = opts;
        try {
            return await AESBinHelper.fetchJson(appUrl(url), {
                ...rest,
                // Auth headers last, so a caller's `headers` cannot drop them.
                headers: {
                    'Content-Type': 'application/json',
                    ...headers,
                    ...AESBinHelper.authHeaders(),
                },
            });
        } catch (err) {
            // Elevation ("sudo mode"): on 403 reauth_required, run the
            // password modal (API.onReauthRequired) and retry the request once.
            if (err.httpStatus === 403 && err.errorCode === 'reauth_required' &&
                !_elevRetried &&
                typeof API.onReauthRequired === 'function') {
                const granted = await _requestElevation();
                if (granted) return _req(url, { ...opts, _elevRetried: true });
            }
            return _ownerFailure(err);
        }
    }

    /**
     * What every owner request does with a failure, through _req() or not
     * (the upload XHR, the file download): a stale CSRF token reloads, a lost
     * session locks the page, anything else goes to the caller. A share
     * password prompt (401 password_required) is not a lost owner session.
     */
    function _ownerFailure(err) {
        if (AESBinHelper.reloadOnStaleCsrf(err)) return new Promise(() => {});
        if (err.httpStatus === 401 && err.errorCode !== 'password_required') {
            return _sessionLost(err.errorCode);
        }
        throw err;
    }

    /**
     * A file's decrypted bytes, read with `read` (text or blob). Not through
     * _req(): the body is the file, not JSON, unless the request failed.
     */
    async function _download(fileId, intent, read) {
        let res;
        try {
            res = await fetch(
                appUrl(`/api/files/${fileId}/download?inline=1&intent=${intent === 'download' ? 'download' : 'preview'}`),
                { headers: AESBinHelper.authHeaders() }
            );
        } catch {
            // As in fetchJson(): the browser's own message is not translated.
            throw new Error(t('err_network'));
        }
        if (!res.ok) {
            const d = await res.json().catch(() => ({}));
            return _ownerFailure(Object.assign(new Error(d.error || `HTTP ${res.status}`), {
                httpStatus: res.status,
                errorCode: d.code,
            }));
        }
        return read(res);
    }

    // Every stored name (file, directory, container) leaves the browser in
    // NFC. macOS and iOS often send "è" as "e" + combining accent, which
    // compares as a different string, so two identical-looking names could
    // share a folder. The server can normalise only with ext-intl; every
    // browser can.
    const nfc = s => (typeof s === 'string' ? s.normalize('NFC') : s);

    // ── Auth ──────────────────────────────────────────────────────────────────
    const auth = {
        // Lightweight session check, e.g. on tab re-focus. The server runs
        // requireAuth() when a session is authenticated, so an expired or
        // IP-changed one returns 401 and reaches onSessionLost via _req().
        status: () =>
            _req('/api/auth/status'),

        totpSecret: () =>
            _req('/api/auth/totp/secret'),
        elevate: (password) =>
            _req('/api/auth/elevate', {
                method: 'POST',
                body: JSON.stringify({ password })
            }),
        elevateDrop: () =>
            _req('/api/auth/elevate/drop', { method: 'POST' }),
        // By credential id, not list index: a register/remove elsewhere
        // shifts indexes. See passkey_remove.php.
        passkeyRemove: (credentialId) =>
            _req('/api/auth/passkey/remove', {
                method: 'POST',
                body: JSON.stringify({
                    credential_id: credentialId
                })
            }),
    };

    // ── Files ─────────────────────────────────────────────────────────────────
    const files = {
        metadata: (id) => _req(`/api/files/${id}/metadata`),
        list: (containerId = null, dirId = null) => {
            const p = new URLSearchParams();
            if (containerId !== null) p.set('container_id', containerId);
            if (dirId !== null) p.set('directory_id', dirId);
            return _req('/api/files?' + p);
        },

        /** Fetch all non-trashed files (decrypted server-side) for client search. */
        search: () => _req('/api/files/search', { timeout: SLOW_TIMEOUT_MS }),

        // Files always live in a directory, so dirId is required.
        upload: async (file, containerId, dirId, onProgress = null, description = '') => {
            // PHP cuts a multipart filename at its last "/" or "\\" (it takes
            // what comes before for a folder), so "a\\b.txt", a valid name on
            // Linux and macOS, would arrive as "b.txt". Each run of them
            // becomes "_" here instead, as sanitize_filename() makes it in a
            // rename, and the result says so like any other adjustment.
            const typedName = nfc(file.name);
            const sendName = typedName.replace(/[\\/]+/g, '_');
            const attempt = () => new Promise((resolve, reject) => {
                const fd = new FormData();
                // Third argument: the filename sent to the server, NFC.
                fd.append('file', file, sendName);
                fd.append('container_id', containerId);
                fd.append('directory_id', dirId);
                // "Create file" sends the description with the file: one
                // request, so a failure cannot leave a file without it.
                if (description) fd.append('description', description);

                const xhr = new XMLHttpRequest();
                xhr.open('POST', appUrl('/api/files/upload'));
                for (const [name, value] of Object.entries(AESBinHelper.authHeaders())) {
                    xhr.setRequestHeader(name, value);
                }

                if (onProgress) {
                    xhr.upload.addEventListener('progress', e => {
                        if (e.lengthComputable) onProgress(Math.round(e.loaded / e.total * 100));
                    });
                }

                xhr.onload = () => {
                    try {
                        const d = JSON.parse(xhr.responseText);
                        if (xhr.status >= 400) reject(Object.assign(new Error(d.error || `HTTP ${xhr.status}`), {
                            httpStatus: xhr.status,
                            errorCode: d.code
                        }));
                        else {
                            if (sendName !== typedName) {
                                d.adjusted = d.adjusted || 'characters';
                                d.adjusted_from = typedName;
                            }
                            resolve(d);
                        }
                    } catch {
                        // A body the web server refuses before PHP runs
                        // (nginx's client_max_body_size, Apache's
                        // LimitRequestBody) gets its HTML 413 page, not JSON.
                        reject(new Error(xhr.status === 413 ? t('upload_too_large_server') : t('err_bad_response')));
                    }
                };
                xhr.onerror = () => reject(new Error(t('err_network')));
                xhr.send(fd);
            });

            try {
                return await attempt();
            } catch (err) {
                // XHR bypasses _req().
                return _ownerFailure(err);
            }
        },

        /**
         * A text file's raw bytes, for the inline editor: decoded with
         * AESBinHelper.decodeText(), which keeps what res.text() would lose
         * (encoding, byte-order mark, line endings).
         */
        downloadBytes: (fileId) => _download(fileId, 'preview', async res => new Uint8Array(await res.arrayBuffer())),

        /** Fetch the raw file bytes as a Blob (for inline preview, or a real download; see intent). */
        fetchBlob: (fileId, intent = 'preview') => _download(fileId, intent, res => res.blob()),

        rename: (id, name, description = undefined) => {
            const body = {
                name: nfc(name)
            };
            if (description !== undefined) body.description = description;
            return _req(`/api/files/${id}/rename`, {
                method: 'POST',
                body: JSON.stringify(body)
            });
        },
        // bytes: the file as AESBinHelper.encodeText() rebuilt it. Sent as
        // base64 because a JSON string can only carry valid UTF-8, and a
        // file in another encoding has to arrive as it is.
        write: (id, bytes) =>
            _req(`/api/files/${id}/write`, {
                method: 'POST',
                timeout: SLOW_TIMEOUT_MS,
                body: JSON.stringify({
                    content_b64: AESBinHelper.bytesToBase64(bytes)
                })
            }),
        move: (id, cid, did) =>
            _req(`/api/files/${id}/move`, {
                method: 'POST',
                body: JSON.stringify({
                    container_id: cid,
                    directory_id: did
                })
            }),
        trash: (id) => _req(`/api/files/${id}/trash`, {
            method: 'POST'
        }),
        restore: (id) => _req(`/api/files/${id}/restore`, {
            method: 'POST'
        }),
        delete: (id) => _req(`/api/files/${id}`, {
            method: 'DELETE'
        }),
    };

    // ── Containers ────────────────────────────────────────────────────────────
    const containers = {
        list: () => _req('/api/containers'),
        create: (name) => _req('/api/containers', {
            method: 'POST',
            body: JSON.stringify({
                name: nfc(name)
            })
        }),
        rename: (id, name) => _req(`/api/containers/${id}/rename`, {
            method: 'POST',
            body: JSON.stringify({
                name: nfc(name)
            })
        }),
        delete: (id) => _req(`/api/containers/${id}`, {
            method: 'DELETE'
        }),
    };

    // ── Directories ───────────────────────────────────────────────────────────
    const directories = {
        list: (cid = null) => {
            const p = new URLSearchParams();
            if (cid) p.set('container_id', cid);
            return _req('/api/directories?' + p);
        },
        create: (cid, name, description = undefined) => _req('/api/directories', {
            method: 'POST',
            body: JSON.stringify({
                container_id: cid,
                name: nfc(name),
                ...(description ? { description } : {})
            })
        }),
        rename: (id, name, description = undefined) => {
            const body = {
                name: nfc(name)
            };
            if (description !== undefined) body.description = description;
            return _req(`/api/directories/${id}/rename`, {
                method: 'POST',
                body: JSON.stringify(body)
            });
        },
        move: (id, cid) => _req(`/api/directories/${id}/move`, {
            method: 'POST',
            body: JSON.stringify({
                container_id: cid
            })
        }),
        delete: (id) => _req(`/api/directories/${id}`, {
            method: 'DELETE'
        }),
    };

    // ── Shares (owner) ────────────────────────────────────────────────────────
    const shares = {
        list: () => _req('/api/shares'),
        // expiresIn and password are sent only when set: absent means a
        // permanent link without a password.
        create: (type, targetId, expiresIn = null, password = null) =>
            _req('/api/shares', {
                method: 'POST',
                timeout: SLOW_TIMEOUT_MS,
                body: JSON.stringify({
                    type,
                    target_id: targetId,
                    ...(expiresIn ? { expires_in: expiresIn } : {}),
                    ...(password ? { password } : {})
                })
            }),
        revoke: (id) => _req(`/api/shares/${id}`, {
            method: 'DELETE'
        }),
        retarget: (id, type, targetId) => _req(`/api/shares/${id}/retarget`, {
            method: 'POST',
            timeout: SLOW_TIMEOUT_MS,
            body: JSON.stringify({ type, target_id: targetId })
        }),
        // Only the keys present are changed. password '' removes it and
        // expires_in 0 makes the link permanent, so both are explicit keys.
        update: (id, patch) => _req(`/api/shares/${id}/update`, {
            method: 'POST',
            body: JSON.stringify(patch)
        }),
        refresh: (id) => _req(`/api/shares/${id}/refresh`, {
            method: 'POST',
            timeout: SLOW_TIMEOUT_MS,
        }),
    };

    // ── System ────────────────────────────────────────────────────────────────
    const system = {
        // No limit/offset: activity.php returns every card (a few dozen at
        // most, see its docblock). Callers slice client-side.
        activity: () => _req('/api/activity'),
        activityPurge: () => _req('/api/system/activity/purge', { method: 'POST' }),
        status: () => _req('/api/system/status'),
        trash: () => _req('/api/trash'),
        getSettings: () => _req('/api/system/settings'),
        saveSettings: (body) => _req('/api/system/settings', {
            method: 'POST',
            body: JSON.stringify(body)
        }),
        sessions: () => _req('/api/system/sessions'),
        revokeSession: (id) => _req('/api/system/sessions/revoke', {
            method: 'POST',
            body: JSON.stringify({ id })
        }),
        revokeOtherSessions: () => _req('/api/system/sessions/revoke', {
            method: 'POST',
            body: JSON.stringify({ others: true })
        }),
        checkUpdate: (force = false) => _req('/api/system/update/check' + (force ? '?force=1' : '')),
        // Fetched here, not by the widget: its own fetch() cannot send the
        // session key that requireKeyHolder() asks for.
        captchaTest: (level) => _req('/api/system/captcha/test?level=' + encodeURIComponent(level)),

        // Runs one chunk of the lowest pending schema migration. The caller
        // loops until the response reports all_done; the server bumps
        // schema_version only when a migration completes.
        migrate: (offset = 0) => _req('/api/system/migrate', {
            method: 'POST',
            timeout: SLOW_TIMEOUT_MS,
            body: JSON.stringify({ offset })
        }),
        // Every check is live (see integrity_check.php): no cache to bypass,
        // hence no force argument.
        checkIntegrity: () => _req('/api/system/integrity', { timeout: SLOW_TIMEOUT_MS }),
        fixIntegrity: (body) => _req('/api/system/integrity/fix', {
            method: 'POST',
            timeout: SLOW_TIMEOUT_MS,
            body: JSON.stringify(body)
        }),
        // Record config.php's storage_path in the database (elevated).
        recordStoragePath: () => _req('/api/system/storage-meta', { method: 'POST' }),
        fix403: () => _req('/api/system/fix-403', { method: 'POST' }),
    };

    return {
        auth,
        files,
        containers,
        directories,
        shares,
        system,
        clearSessionKey: sessionKey.clear,
        // Hooks, set by dashboard.js:
        // onSessionLost(reason: 'expired' | 'ip' | 'conflict' | 'revoked' | 'auth')
        // shows the session-lock overlay; onReauthRequired() runs the
        // elevation prompt and resolves true when granted, and the request is
        // then retried once.
        onSessionLost: null,
        onReauthRequired: null,
    };
})();
