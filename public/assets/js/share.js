/**
 * public/assets/js/share.js — AESBin share viewer
 *
 * Public share page: key and password screens, directory banner, language
 * dropdown, file list with sort, preview and download. Carries its own
 * small client for share auth and file listing; downloads use
 * fetchShareBlob().
 */
/* global AESBinPreview, AESBinHelper */
(function() {
    'use strict';

    const L = window.AESBIN_LANG || {};
    const t = k => L[k] || k;
    const $ = id => document.getElementById(id);
    const {
        esc, fmtDesc, icon, isPreviewable, mimeIcon, relDate, fmtBytes,
        renderHashDetails, closeAllHashes, appUrl,
    } = AESBinHelper;

    // Known error codes use the page's translation (each is also written
    // literally in this file, so it gets shipped); the server's message is
    // only a fallback. Expired and revoked shares arrive as share_invalid.
    function localizedError(e, fallbackMsg) {
        if (e.errorCode && L[e.errorCode]) return t(e.errorCode);
        return e.message || fallbackMsg;
    }

    const TOKEN = window.SHARE_TOKEN;

    // ── API ───────────────────────────────────────────────────────────────────
    // Every error goes to the caller: a visitor has no login page to be sent
    // to, and password_required opens the prompt.
    function _req(url, opts = {}) {
        const { headers = {}, ...rest } = opts;
        return AESBinHelper.fetchJson(appUrl(url), {
            ...rest,
            headers: {
                'Content-Type': 'application/json',
                ...headers,
            },
        });
    }

    // The two expected answers that are not errors come back as a 200 (the
    // server keeps the visitor's console clean) and are thrown here, so the
    // callers handle them with the real errors.
    const expected = (code, message) => Object.assign(new Error(message), { errorCode: code });

    const shares = {
        /** Authenticate with a pre-built body (includes honeypot field). */
        authWithBody: async (body) => {
            const res = await _req('/api/share/auth', {
                method: 'POST',
                body: JSON.stringify(body)
            });
            if (res.password_required) throw expected('password_required', t('share_enter_password'));
            return res;
        },

        // The visitor session travels in an HttpOnly cookie of its own for
        // this link (share_session_cookie()): the page never holds it.
        listFiles: async (token) => {
            const res = await _req(`/api/share/${token}/files`);
            if (res.session === false) throw expected('share_session_required', t('share_invalid'));
            return res;
        },
    };

    /** An auth request body: honeypot field and page context appended. */
    function authBody(body) {
        return AESBinHelper.withHoneypot({
            ...body,
            // An AJAX Referer is this page: send the real one for
            // visitor_failure_context().
            page_referrer: document.referrer || '',
            page_url: location.pathname || '',
        });
    }

    let _shareFiles = []; // kept for re-sorting
    let _shareSort = { by: 'name', dir: 'asc' }; // name | date | size (helper.js nextSort())
    let _deepLinkEntry = false; // true only for the first render after a deep link (one-time scroll)
    let _selectedFileId = null; // selected file token, null = the folder; survives re-renders
    let _shareKey = ''; // needed to rebuild the fragment
    let _shareIsDir = false; // file anchor and sort go in the fragment only for directory shares
    let _sharePassword = false; // a password link shows "Exit" (placeExitButton())

    // Fragment format: KEY | KEY:fileId | KEY!sort[:dir] | KEY:fileId!sort[:dir]
    // The fragment is never sent to the server.
    function parseFragment() {
        const raw = location.hash.slice(1);
        const bang = raw.indexOf('!');
        const sortPart = bang >= 0 ? raw.slice(bang + 1) : '';
        const beforeBang = bang >= 0 ? raw.slice(0, bang) : raw;
        const colon = beforeBang.indexOf(':');
        const key = colon >= 0 ? beforeBang.slice(0, colon) : beforeBang;
        const fileId = colon >= 0 ? beforeBang.slice(colon + 1) : null;
        const dirColon = sortPart.indexOf(':');
        const sort = dirColon >= 0 ? sortPart.slice(0, dirColon) : (sortPart || null);
        const dir = dirColon >= 0 ? sortPart.slice(dirColon + 1) : null;
        return { key, fileId, sort, dir };
    }

    // replaceState, so no hashchange fires. No query string, as in tryKey():
    // the page never carries one (see syncShareUrlForCopy()).
    function updateFragment(key, fileId, { by, dir }) {
        let frag = key;
        if (fileId) frag += ':' + fileId;
        const isDefaultSort = by === 'name' && dir !== 'desc';
        if (!isDefaultSort) frag += '!' + by + (dir === 'desc' ? ':desc' : '');
        history.replaceState(null, '', location.pathname + location.search + '#' + frag);
        syncShareUrlForCopy();
    }

    // The hidden #sv-share-url is what helper.js's "Copy link" button copies:
    // keep it equal to the address bar. A language picked from the menu
    // never reaches it (visitor_lang_choice() stores it and redirects).
    function syncShareUrlForCopy() {
        const el = $('sv-share-url');
        if (el) el.textContent = location.href;
    }

    // One card at a time carries fc-highlight, a persistent "this is the
    // linked item" marker. The flash restarts even if `card` already had it.
    function highlightFileCard(card) {
        document.querySelectorAll('.fc-highlight').forEach(el => {
            if (el !== card) el.classList.remove('fc-highlight');
        });
        card.classList.remove('fc-highlight');
        void card.offsetWidth; // restart the animation if it just ran
        card.classList.add('fc-highlight');
    }

    // ── Language dropdown ─────────────────────────────────────────────────────
    const langSel = $('sv-lang-sel');
    if (langSel) {
        langSel.addEventListener('change', () => {
            // The server stores the choice and redirects back without ?lang=;
            // the #KEY fragment survives the redirect.
            location.href = location.pathname + '?lang=' + encodeURIComponent(langSel.value) + location.hash;
        });
    }

    // ── Copy or share the link ────────────────────────────────────────────────
    // One button: "Copy link" (helper.js), or "Share" on touch devices with
    // navigator.share, whose system sheet has its own "Copy". Any failure but
    // a cancel switches to "Copy link" for good and copies in the same tap,
    // still within the user activation the clipboard needs. That covers
    // Firefox for Android, which refuses every share after the first
    // (InvalidStateError) and cannot be feature-detected.
    syncShareUrlForCopy();
    const shareBtn = $('sv-share-btn');
    if (
        shareBtn &&
        typeof navigator.share === 'function' &&
        window.matchMedia('(pointer: coarse)').matches
    ) {
        const copyLabel = shareBtn.innerHTML;
        // Claimed before helper.js wires copy buttons on DOMContentLoaded.
        shareBtn.dataset.copyReady = '1';
        shareBtn.innerHTML = icon('share-2') + ' ' + esc(t('share_native'));
        const onShare = () => {
            let promise;
            try {
                // The URL only: nothing about the files reaches the other app.
                promise = navigator.share({ url: $('sv-share-url')?.textContent || location.href });
            } catch (err) {
                promise = Promise.reject(err);
            }
            promise.catch(err => {
                if (err && err.name === 'AbortError') return;
                shareBtn.removeEventListener('click', onShare);
                shareBtn.innerHTML = copyLabel;
                delete shareBtn.dataset.copyReady;
                AESBinHelper.bindCopyButtons(shareBtn.parentNode);
                // The copy handler just bound, so this tap is not wasted.
                shareBtn.click();
            });
        };
        shareBtn.addEventListener('click', onShare);
    }

    $('sv-exit-btn')?.addEventListener('click', exitVisit);

    // ── Boot ──────────────────────────────────────────────────────────────────
    async function boot() {
        if (!TOKEN) {
            showError(t('share_invalid'));
            return;
        }
        // Cookies off in the browser's settings: say so before opening a
        // visit the server would count and the browser could not keep, and
        // before asking for a key that could not open it. authenticate()
        // catches the cases this misses (an extension dropping them).
        if (!navigator.cookieEnabled) {
            showError(t('share_cookies_blocked'));
            return;
        }
        const frag = parseFragment();
        const { key, sort, dir } = frag;
        if (!key) {
            showKeyInputScreen();
            return;
        }
        if (sort === 'name' || sort === 'date' || sort === 'size') _shareSort = { by: sort, dir: dir === 'desc' ? 'desc' : 'asc' };
        // 6 hex chars, as the router's file_token: anything else could
        // break the [data-fid="..."] selector.
        const fileId = frag.fileId !== null && /^[a-f0-9]{6}$/.test(frag.fileId) ? frag.fileId : null;
        _deepLinkEntry = fileId !== null;
        _selectedFileId = fileId;
        _shareKey = key;
        showLoading();
        // A visit already open in this browser (its cookie, SESSION_TTL)
        // goes on: no password again, and not a new opening. Otherwise
        // (share_session_required, or a session the owner ended) a new one.
        try {
            await loadFiles();
            return;
        } catch {
            // Opened below.
        }
        try {
            await authenticate(key);
        } catch (e) {
            if (e.errorCode === 'password_required') showPasswordScreen(key);
            else showError(localizedError(e, t('share_invalid')));
        }
    }

    async function authenticate(key, password = '') {
        await shares.authWithBody(authBody({
            token: TOKEN,
            key,
            password
        }));
        try {
            await loadFiles();
        } catch (e) {
            // The visit the server just opened is not in the next request:
            // the browser refused its cookie.
            if (e.errorCode === 'share_session_required') throw expected('share_cookies_blocked', t('share_cookies_blocked'));
            throw e;
        }
    }

    // The session only caches the key check for an hour
    // (ShareService::SESSION_TTL); the key is still in memory, so it is
    // renewed silently. A password share asks for the password again
    // instead (it is not kept): the prompt goes up, and the error thrown
    // carries passwordPrompted so callers leave that screen in place.
    async function _renewSession(errorToThrow = null) {
        try {
            const res = await shares.authWithBody(authBody({ token: TOKEN, key: _shareKey, password: '', refresh: true }));
            sessionEndsIn(res.expires_in);
        } catch (e) {
            const err = errorToThrow || e;
            if (e.errorCode === 'password_required') {
                showPasswordScreen(_shareKey);
                err.passwordPrompted = true;
            }
            throw err;
        }
    }

    // When the visit's session ends (session_expires_in, expires_in from the
    // server: seconds, so the visitor's clock does not matter).
    let _visitEndsAt = 0;
    const sessionEndsIn = secs => { _visitEndsAt = Date.now() + Math.max(0, Number(secs) || 0) * 1000; };

    // Only once this page has opened a visit: before that, "no session" is
    // the first visit, which boot() opens itself.
    let _visitOpen = false;
    const SESSION_ENDED = ['share_invalid', 'share_session_required'];
    // A request due after the session's end renews it first, rather than
    // meeting the 401 of a cookie the browser has dropped with its hour. One
    // that still finds it ended (the owner revoked it, set a password) renews
    // once and retries; if that fails too, the original error goes up.
    async function _withSessionRetry(fn) {
        if (_visitOpen && _shareKey && Date.now() > _visitEndsAt - 5000) {
            await _renewSession();
        }
        try {
            return await fn();
        } catch (e) {
            if (!_visitOpen || !SESSION_ENDED.includes(e.errorCode) || !_shareKey) throw e;
            await _renewSession(e);
            return await fn();
        }
    }

    // ── Key input ─────────────────────────────────────────────────────────────
    function showKeyInputScreen() {
        hideAll();
        $('aesbin-key-screen').hidden = false;
        const err = $('aesbin-key-err');
        let busy = false; // Enter bypasses a disabled button
        async function tryKey() {
            if (busy) return;
            const key = $('aesbin-key-inp')?.value?.trim() || '';
            if (!key) {
                showInlineErr(err, t('share_key_required'));
                return;
            }
            if (err) err.hidden = true;
            busy = true;
            showLoading();
            // Into the address once the server has accepted it, as if the
            // link had carried it: a reload, "Exit" and the renewal of the
            // visit need it there. A password link accepts the key before it
            // asks for the password, so that answer counts too.
            const keepKey = () => history.replaceState(null, '', location.pathname + location.search + '#' + key);
            _shareKey = key;
            try {
                await authenticate(key);
                keepKey();
            } catch (e) {
                hideAll();
                $('aesbin-key-screen').hidden = false;
                if (e.errorCode === 'password_required') {
                    keepKey();
                    showPasswordScreen(key);
                } else {
                    showInlineErr(err, localizedError(e, t('share_invalid')));
                }
            } finally {
                busy = false;
            }
        }
        rebind('aesbin-key-btn', 'click', tryKey);
        rebind('aesbin-key-inp', 'keydown', e => {
            if (e.key === 'Enter') tryKey();
        });
        $('aesbin-key-inp')?.focus();
    }

    // ── Password ──────────────────────────────────────────────────────────────
    function showPasswordScreen(key) {
        hideAll();
        $('aesbin-pw-screen').hidden = false;
        const err = $('aesbin-pw-err');
        if (err) err.hidden = true;
        let busy = false; // Enter bypasses the disabled button
        async function tryAuth() {
            if (busy) return;
            // Not trimmed: the owner's side stores the password as typed.
            const pw = $('aesbin-pw-inp')?.value || '';
            if (!pw) {
                showInlineErr(err, t('share_enter_password'));
                return;
            }
            if (err) err.hidden = true;
            const btn = $('aesbin-unlock-btn');
            if (btn) btn.disabled = true;
            busy = true;
            try {
                await authenticate(key, pw);
            } catch (e) {
                showInlineErr(err,
                    e.errorCode === 'password_required' ?
                    t('share_wrong_password') :
                    localizedError(e, t('share_invalid')));
            } finally {
                busy = false;
                if (btn) btn.disabled = false;
            }
        }
        rebind('aesbin-unlock-btn', 'click', tryAuth);
        rebind('aesbin-pw-inp', 'keydown', e => {
            if (e.key === 'Enter') tryAuth();
        });
        const pwInp = $('aesbin-pw-inp');
        if (pwInp) {
            pwInp.value = '';
            pwInp.focus();
        }
    }

    // "Exit" on a password link, in the box the link opens on: beside the
    // folder's name, or beside the file's for a single file (its card is
    // rebuilt on every render, so the button moves back in each time).
    function placeExitButton() {
        const btn = $('sv-exit-btn');
        if (!btn) return;
        btn.hidden = !_sharePassword;
        const row = _shareIsDir
            ? document.querySelector('#aesbin-dir-hdr .sv-title-row')
            : document.querySelector('#aesbin-file-list .file-card .sv-title-row');
        if (row && btn.parentNode !== row) row.appendChild(btn);
    }

    // The visitor's "Exit" on a password link: the server ends this link's
    // visit and deletes its cookie, as the privacy page's delete link does
    // for all of them (visitor_cookie_forget()), and comes back to this page
    // with its #KEY, which asks for the password again.
    function exitVisit() {
        location.href = location.pathname + '?forget_cookie=share' + location.hash;
    }

    // ── File list ─────────────────────────────────────────────────────────────
    async function loadFiles() {
        const result = await _withSessionRetry(() => shares.listFiles(TOKEN));
        _visitOpen = true;
        sessionEndsIn(result.session_expires_in);
        const files = result.files || [];
        const meta = result.meta || {};

        hideAll();
        $('aesbin-viewer').hidden = false;

        _shareIsDir = meta.type === 'directory';

        const dirBanner = $('aesbin-dir-hdr');
        const dirName = $('aesbin-dir-name');
        const dirDesc = $('aesbin-dir-desc');
        if (dirBanner && meta.type === 'directory' && meta.target_name) {
            if (dirName) dirName.innerHTML = icon('folder') + ' ' + esc(meta.target_name);
            if (dirDesc && meta.target_desc) {
                dirDesc.innerHTML = icon('quote') + ' ' + fmtDesc(meta.target_desc);
                dirDesc.hidden = false;
            }
            dirBanner.hidden = false;

            // Clicking the folder card resets the fragment to plain #KEY
            // (no file anchor, default sort).
            if (!dirBanner.dataset.resetBound) {
                dirBanner.dataset.resetBound = '1';
                dirBanner.classList.add('sv-dir-hdr-clickable');
                dirBanner.tabIndex = 0;
                dirBanner.setAttribute('role', 'button');
                const resetAnchor = e => {
                    // The Exit button inside the box has its own action.
                    if (e.target.closest('#sv-exit-btn')) return;
                    _shareSort = { by: 'name', dir: 'asc' };
                    _selectedFileId = null;
                    updateFragment(_shareKey, null, _shareSort);
                    renderShareFiles(); // also updates the sort buttons and highlights the folder card
                };
                dirBanner.addEventListener('click', resetAnchor);
                dirBanner.addEventListener('keydown', e => {
                    if ((e.key === 'Enter' || e.key === ' ') && !e.target.closest('#sv-exit-btn')) {
                        e.preventDefault();
                        resetAnchor(e);
                    }
                });
            }
        }

        _sharePassword = Boolean(meta.password);

        _shareFiles = files;
        // The bar is in share.php (sort_bar()); wired once, shown with two files.
        const sortBar = $('sv-sort-bar');
        if (sortBar && !sortBar.dataset.bound) {
            sortBar.dataset.bound = '1';
            AESBinHelper.bindSortBar(sortBar, () => _shareSort, order => {
                _shareSort = order;
                renderShareFiles();
                if (_shareIsDir) updateFragment(_shareKey, _selectedFileId, _shareSort);
            });
        }
        if (sortBar) sortBar.hidden = files.length <= 1;

        renderShareFiles();
    }

    function renderShareFiles() {
        const tbody = $('aesbin-file-list');
        const noFile = $('aesbin-no-files');
        if (!tbody) return;

        const sorted = AESBinHelper.sortItems(_shareFiles, _shareSort, AESBinHelper.FILE_SORT_KEYS);

        if (noFile) noFile.hidden = sorted.length > 0;
        // Back to its place in share.php before the cards go: a lone file's
        // card holds it (placeExitButton()).
        const exitBtn = $('sv-exit-btn');
        if (exitBtn && tbody.contains(exitBtn)) document.querySelector('#aesbin-dir-hdr .sv-title-row')?.appendChild(exitBtn);
        tbody.innerHTML = '';
        AESBinPreview.resetPreviews();

        AESBinHelper.paintSortBar($('sv-sort-bar'), _shareSort);

        sorted.forEach(f => {
            f.id = f.file_token; // preview.js uses f.id as DOM handle
            const canPreview = isPreviewable(f.mime);
            // A lone file shows its checksums open. Previews never open on
            // their own: each one is a deliberate, logged action.
            const openHashes = sorted.length === 1;
            const card = document.createElement('div');
            card.className = 'file-card';
            card.dataset.fid = String(f.file_token);
            card.innerHTML = `
      <div class="fc-main">
        <div class="fc-name">
          <div class="sv-title-row"><span class="fname">${mimeIcon(f.mime, f.original_name)} ${esc(f.original_name)}</span></div>
          ${f.description ? `<span class="file-sub-desc">${icon('quote')} ${fmtDesc(f.description)}</span>` : ''}
        </div>
        <div class="fc-meta">
          <span class="fc-meta-item">${f.size_human || fmtBytes(f.size)}</span>
          <span class="fc-meta-item">${esc(f.mime || '—')}</span>
          <span class="fc-meta-item">${relDate(f.created_at)}</span>
        </div>
        ${renderHashDetails(f, f.file_token, openHashes)}
      </div>
      <div class="fc-actions">
        <button class="btn btn-primary btn-sm" id="dl-btn-${f.file_token}" data-a="dl">${icon('download')} ${esc(t('download'))}</button>
        ${canPreview ? `<button class="btn btn-ghost btn-sm" id="prev-btn-${f.file_token}" data-a="prev"><span class="icon">${icon('eye')}</span> ${esc(t('preview'))}</button>` : ''}
      </div>
      ${canPreview ? `<div id="prev-row-${f.file_token}" class="fc-preview" hidden><div id="prev-body-${f.file_token}"></div></div>` : ''}`;

            AESBinHelper.bindCopyButtons(card);

            card.querySelector('[data-a="dl"]').addEventListener('click', async () => {
                const dlBtn = $(`dl-btn-${f.file_token}`);
                if (dlBtn) dlBtn.disabled = true;
                try {
                    const blob = await fetchShareBlob(f.file_token, 'download');
                    AESBinPreview.forceDownloadBlob(blob, f.original_name);
                    // In standalone/PWA mode there is no browser download bar,
                    // so this box is the only confirmation.
                    const okCard = dlBtn?.closest('.file-card');
                    let okBox = okCard?.querySelector('.sv-dl-ok');
                    if (!okBox && okCard) {
                        okBox = document.createElement('div');
                        okBox.className = 'alert alert-success sv-dl-ok';
                        // After the actions row, not at the end of the card,
                        // where an open preview would push it out of sight.
                        const actions = okCard.querySelector('.fc-actions');
                        if (actions) actions.insertAdjacentElement('afterend', okBox);
                        else okCard.appendChild(okBox);
                    }
                    if (okBox) {
                        okBox.textContent = t('file_downloaded');
                        okBox.hidden = false;
                        setTimeout(() => {
                            okBox.hidden = true;
                        }, 2500);
                    }
                } catch (err) {
                    // The share itself is gone (revoked, expired, retargeted
                    // away): _withSessionRetry() already tried a fresh session,
                    // so show the full-page error, as on load.
                    if (err.errorCode === 'share_invalid') {
                        // A password was added since: its prompt is showing.
                        if (err.passwordPrompted) return;
                        showError(localizedError(err, t('share_invalid')));
                        return;
                    }
                    const card = dlBtn?.closest('.file-card');
                    let box = card?.querySelector('.sv-dl-err');
                    if (!box && card) {
                        box = document.createElement('div');
                        box.className = 'alert alert-danger sv-dl-err';
                        // Placed like the success box above.
                        const actions = card.querySelector('.fc-actions');
                        if (actions) actions.insertAdjacentElement('afterend', box);
                        else card.appendChild(box);
                    }
                    if (box) {
                        box.textContent = err.message || t('error');
                        box.hidden = false;
                        setTimeout(() => {
                            box.hidden = true;
                        }, 5000);
                    }
                } finally {
                    if (dlBtn) dlBtn.disabled = false;
                }
            });

            if (canPreview) {
                card.querySelector('[data-a="prev"]').addEventListener('click', () => {
                    closeAllHashes();
                    AESBinPreview.togglePreview(f, id => fetchShareBlob(id, 'preview'));
                });
            }

            // A click anywhere on the card except the preview body and the
            // error box selects the file and makes it the URL anchor
            // (share.css gives the cards a pointer cursor).
            card.addEventListener('click', e => {
                if (e.target.closest('.fc-preview, .sv-dl-err, #sv-exit-btn')) return;
                _selectedFileId = f.file_token;
                if (_shareIsDir) updateFragment(_shareKey, f.file_token, _shareSort);
                highlightFileCard(card);
            });

            tbody.appendChild(card);
        });
        placeExitButton();

        // Every render rebuilds the cards, so the highlight is re-applied
        // from _selectedFileId each time.
        if (_shareIsDir) {
            const targetId = _selectedFileId;
            const isDeepLinkEntry = _deepLinkEntry;
            _deepLinkEntry = false;
            setTimeout(() => {
                if (targetId != null) {
                    const target = tbody.querySelector(`[data-fid="${targetId}"]`);
                    if (target) {
                        highlightFileCard(target);
                        // Scroll only on the render right after a deep link.
                        if (isDeepLinkEntry) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        return;
                    }
                }
                // No valid file selected: the URL points at the folder.
                const dirBanner = $('aesbin-dir-hdr');
                if (dirBanner && !dirBanner.hidden) highlightFileCard(dirBanner);
            }, 150);
        } else if (sorted.length === 1) {
            setTimeout(() => highlightFileCard(tbody.querySelector('.file-card')), 150);
        }
    }
    async function fetchShareBlob(fileToken, intent = 'download') {
        return _withSessionRetry(() => _rawFetchShareBlob(fileToken, intent));
    }

    async function _rawFetchShareBlob(fileToken, intent = 'download') {
        const qs = intent === 'preview' ? '?intent=preview' : '?intent=download';
        let res;
        try {
            res = await fetch(appUrl(`/api/share/${TOKEN}/download/${fileToken}${qs}`), {
                headers: {
                    // As page_referrer in authBody(), as a header since a
                    // GET has no body.
                    'X-Page-Referrer': document.referrer || ''
                }
            });
        } catch {
            // As in fetchJson(): the browser's own message is not translated.
            throw new Error(t('err_network'));
        }
        if (!res.ok) {
            const d = await res.json().catch(() => ({}));
            const e = new Error(d.error || `HTTP ${res.status}`);
            e.httpStatus = res.status;
            e.errorCode = d.code;
            throw e;
        }
        return res.blob();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    function rebind(id, event, handler) {
        const el = $(id);
        if (!el) return;
        const clone = el.cloneNode(true);
        el.parentNode.replaceChild(clone, el);
        $(id).addEventListener(event, handler);
    }

    function hideAll() {
        ['aesbin-loading', 'aesbin-key-screen', 'aesbin-pw-screen', 'aesbin-error-screen', 'aesbin-viewer']
        .forEach(id => {
            const el = $(id);
            if (el) el.hidden = true;
        });
    }

    function showLoading() {
        hideAll();
        if ($('aesbin-loading')) $('aesbin-loading').hidden = false;
    }

    function showError(msg) {
        hideAll();
        if ($('aesbin-error-screen')) $('aesbin-error-screen').hidden = false;
        const em = $('aesbin-err-msg');
        if (em) em.textContent = msg;
    }

    function showInlineErr(el, msg) {
        if (!el) return;
        el.textContent = msg;
        el.hidden = false;
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
