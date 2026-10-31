/* AESBin — installer wizard (app/setup/index.php, app/setup/template.php) */
/* global AESBinHelper */
(function() {
    'use strict';

    // ── State ─────────────────────────────────────────────────────────────────
    // Everything the install request needs lives only here, in page memory.
    // The server session keeps only hashes of the TOTP secret and the phrase
    // (setup_secret_hash() in index.php), so a reload starts over.
    const state = {
        db: null,          // {db_host, db_port, db_name, db_user, db_pass, prefix}
        storage: '',
        totpSecret: '',
        password: '',
        phrase: '',
        // Set once verify_totp accepts a code for totpSecret. Coming back to
        // step 3 then skips the check: the code the field still holds has
        // expired, and a failed verify_totp would clear the server's mark.
        totpVerified: false,
    };

    // ── Helpers ───────────────────────────────────────────────────────────────
    const $ = id => document.getElementById(id);
    const { esc, icon } = AESBinHelper;

    let lang = window.SETUP_CURRENT_LANG || 'en';
    /** The string for $key in the current language, English, or undefined. */
    const lookup = key => {
        const all = window.SETUP_LANGS || {};
        return (all[lang] && all[lang][key]) || (all.en && all.en[key]) || undefined;
    };
    const t = key => lookup(key) ?? key;

    const showMsg = (elId, msg) => {
        const el = $(elId);
        if (!el) return;
        el.textContent = msg;
        el.classList.add('show');
    };
    const hideMsg = elId => {
        const el = $(elId);
        if (!el) return;
        el.textContent = '';
        el.classList.remove('show');
    };

    /**
     * Button label bound to a language key, so the switcher also translates
     * transient states ("Testing…"). `prefixIcon` names a sprite icon drawn
     * before the text (helper.js icon()); `suffix` is a character after it.
     */
    function setLabel(btn, key, prefixIcon = '', suffix = '') {
        btn.replaceChildren();
        if (prefixIcon) btn.insertAdjacentHTML('beforeend', icon(prefixIcon) + ' ');
        const span = document.createElement('span');
        span.dataset.t = key;
        span.textContent = t(key);
        btn.append(span);
        if (suffix) btn.append(' ' + suffix);
    }

    /**
     * POST JSON through fetchJson() (helper.js), always resolving to an
     * object. An error status still brings the server's {ok: false, error};
     * a response that is not JSON (a PHP fatal, a proxy error page), a
     * dropped connection or no answer within two minutes becomes
     * {ok: false}, so no button is left disabled on a spinner forever. Two
     * minutes, not the usual twelve seconds: the install step creates the
     * schema and hashes the password, slow on a small shared host.
     */
    async function post(action, body) {
        // Sent with every call so server messages come back in the on-screen language.
        const qs = '?action=' + encodeURIComponent(action) + '&lang=' + encodeURIComponent(lang);
        try {
            const data = await AESBinHelper.fetchJson(qs, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
                credentials: 'same-origin',
                timeout: 120000,
            });
            return Object.keys(data).length ? data : { ok: false };
        } catch (e) {
            return e.data && Object.keys(e.data).length ? e.data : { ok: false };
        }
    }

    // From step 2 on, what was typed (database password, TOTP secret,
    // recovery phrase) lives only in this page: leaving it, the browser's
    // Back included, starts the wizard over. The browser asks first. Off
    // again on step 1 and once the installation is written.
    let _holdsWork = false;
    window.addEventListener('beforeunload', e => {
        if (!_holdsWork) return;
        e.preventDefault();
        e.returnValue = ''; // older Chromium shows the prompt only with this
    });

    function setStep(n) {
        _holdsWork = n >= 2;
        $('panel-existing').hidden = true;
        document.querySelector('.steps').hidden = false;
        document.querySelectorAll('.step-panel').forEach(p =>
            p.classList.toggle('active', p.id === 'panel-' + n));
        document.querySelectorAll('.steps .step').forEach((s, i) => {
            s.classList.toggle('active', i + 1 === n);
            s.classList.toggle('done', i + 1 < n);
        });
        window.scrollTo(0, 0);
    }

    /** Same shape as the server's suggestion: "aesbin_" + 8 hex + "_". */
    function newPrefix() {
        const b = crypto.getRandomValues(new Uint8Array(4));
        return 'aesbin_' + Array.from(b, x => x.toString(16).padStart(2, '0')).join('') + '_';
    }

    function setPrefix(prefix) {
        $('db_prefix').value = prefix;
        if (state.db) state.db.prefix = prefix;
    }

    // ── Password strength ─────────────────────────────────────────────────────
    // Shared with the recovery page: paintPwStrength() in helper.js.
    $('pw1').addEventListener('input', () =>
        AESBinHelper.paintPwStrength($('pw1').value, $('pw-fill'), $('pw-label')));

    // ── Requirements screen ───────────────────────────────────────────────────
    // Present only when nothing blocking failed; otherwise there is no button
    // and no wizard to reveal.
    $('btn-check')?.addEventListener('click', () => {
        $('step-check').hidden = true;
        $('wizard').hidden = false;
        $('db_name')?.focus();
    });

    // ── STEP 1: database ──────────────────────────────────────────────────────
    function readDbFields() {
        return {
            db_host: $('db_host').value.trim(),
            db_port: $('db_port').value.trim(),
            db_name: $('db_name').value.trim(),
            db_user: $('db_user').value.trim(),
            db_pass: $('db_pass').value,
            prefix: $('db_prefix').value.trim(),
        };
    }

    $('btn-step1').addEventListener('click', async () => {
        const btn = $('btn-step1');
        const status = $('db-status');
        status.replaceChildren();
        hideMsg('global-err');

        const data = readDbFields();
        if (!data.db_name || !data.db_user || !data.prefix) {
            showMsg('global-err', t('setup_db_required'));
            return;
        }

        btn.disabled = true;
        setLabel(btn, 'setup_testing');
        const res = await post('test_db', data);
        btn.disabled = false;
        setLabel(btn, 'setup_test_btn', '', '→');

        if (!res.ok) {
            status.innerHTML = '<span class="fail">✗ ' + esc(res.error || t('setup_db_connect_failed')) + '</span>';
            return;
        }
        status.innerHTML = '<span class="ok">✓ ' + esc(t('setup_db_success')) +
            (res.server ? ' (' + esc(res.server) + ')' : '') + '</span>';
        state.db = data;

        // A moment on step 1 so the verified server version is read, not
        // flashed: both ways on leave this panel. The button stays disabled
        // until then.
        btn.disabled = true;
        await new Promise(r => setTimeout(r, 1200));
        btn.disabled = false;

        if (res.existing) {
            showExistingPanel(Array.isArray(res.installs) ? res.installs : []);
            return;
        }
        setStep(2);
        $('storage_path').focus();
    });

    // ── STEP 2: storage ───────────────────────────────────────────────────────
    // The warning (storage inside the webroot on a server that ignores
    // .htaccess) is advisory: shown once for a given path, and the next click
    // on the same path continues.
    let warnedFor = null;

    // A storage field's submit button stays disabled while the field is empty.
    // The server validates the path anyway; this only saves a round trip.
    const bindRequired = (inputId, buttonId) => {
        const sync = () => { $(buttonId).disabled = $(inputId).value.trim() === ''; };
        $(inputId).addEventListener('input', sync);
        sync();
        return sync;
    };
    const syncStorage = bindRequired('storage_path', 'btn-step2');
    const syncExisting = bindRequired('existing-storage', 'btn-existing-reconnect');

    $('storage_path').addEventListener('input', () => {
        warnedFor = null;
        hideMsg('storage-err');
        hideMsg('storage-warn');
    });

    $('btn-back2').addEventListener('click', () => setStep(1));
    $('btn-back3').addEventListener('click', () => setStep(2));
    $('btn-back4').addEventListener('click', () => setStep(3));
    $('btn-back5').addEventListener('click', () => setStep(4));

    $('btn-step2').addEventListener('click', async () => {
        const btn = $('btn-step2');
        hideMsg('storage-err');
        const path = $('storage_path').value.trim();

        btn.disabled = true;
        const res = await post('check_storage', { storage: path });
        syncStorage();

        if (!res.ok) {
            showMsg('storage-err', res.error || t('setup_storage_invalid'));
            return;
        }
        if (res.warning && warnedFor !== res.storage) {
            warnedFor = res.storage;
            showMsg('storage-warn', res.warning);
            return;
        }
        state.storage = res.storage;
        $('storage_path').value = res.storage;
        setStep(3);
        // Back from step 3 and forward again: keep the secret the owner may
        // already have scanned. A new one only if there is none yet.
        if (!state.totpSecret) await initStep3();
    });

    // ── STEP 3: password + TOTP ───────────────────────────────────────────────
    async function initStep3() {
        const box = $('totp-secret-text');
        box.textContent = t('setup_generating');
        $('totp-qr').replaceChildren();
        hideMsg('totp-err');
        state.totpSecret = '';
        state.totpVerified = false;

        const res = await post('totp_init', {});
        if (!res.ok || !res.totp_secret) {
            showMsg('totp-err', t('setup_install_failed'));
            return;
        }
        state.totpSecret = res.totp_secret;
        box.textContent = res.totp_secret;

        AESBinHelper.renderQr($('totp-qr'), res.totp_uri, 200);
        $('pw1').focus();
    }

    $('btn-step3').addEventListener('click', async () => {
        const btn = $('btn-step3');
        hideMsg('totp-err');

        const pw1 = $('pw1').value;
        const pw2 = $('pw2').value;
        const code = $('totp-code').value.trim();

        if (AESBinHelper.pwTooShort(pw1)) {
            showMsg('totp-err', t('password_too_short'));
            return;
        }
        if (pw1 !== pw2) {
            showMsg('totp-err', t('password_mismatch'));
            return;
        }
        if (!state.totpVerified && !/^\d{6}$/.test(code)) {
            showMsg('totp-err', t('setup_totp_required'));
            return;
        }
        if (!state.totpSecret) {
            await initStep3();
            return;
        }

        if (!state.totpVerified) {
            btn.disabled = true;
            setLabel(btn, 'setup_verifying');
            const res = await post('verify_totp', { code, secret: state.totpSecret });
            if (!res.ok) {
                showMsg('totp-err', t('setup_totp_invalid'));
                btn.disabled = false;
                setLabel(btn, 'continue', '', '→');
                return;
            }
            state.totpVerified = true;
        }

        state.password = pw1;

        // Back from step 4 (to change the password) and forward again: the
        // phrase shown before may already be copied or downloaded, so it is
        // kept, with its acknowledgement, rather than replaced.
        if (state.phrase) {
            setStep(4);
            return;
        }

        btn.disabled = true;

        const pRes = await post('gen_phrase', {});
        btn.disabled = false;
        setLabel(btn, 'continue', '', '→');
        if (!pRes.ok || !pRes.phrase) {
            showMsg('totp-err', t('setup_install_failed'));
            return;
        }
        state.phrase = pRes.phrase;
        $('phrase-box').textContent = state.phrase;
        $('phrase-check').checked = false;
        $('phrase-check').disabled = true;
        $('btn-step4').disabled = true;
        setStep(4);
    });

    // ── STEP 4: recovery phrase ───────────────────────────────────────────────
    // The acknowledgement stays disabled until the phrase has been copied or
    // downloaded, so it cannot be ticked while the words exist nowhere else.
    const phraseSaved = () => { $('phrase-check').disabled = false; };
    // The copy itself is done by helper.js through data-copy-target, which
    // reports a copy that worked with "aesbin:copied".
    $('btn-copy-phrase').addEventListener('aesbin:copied', phraseSaved);

    $('btn-dl-phrase').addEventListener('click', () => {
        phraseSaved();
        AESBinHelper.downloadRecoveryPhrase(state.phrase);
    });

    $('phrase-check').addEventListener('change', () => {
        $('btn-step4').disabled = !$('phrase-check').checked;
    });

    $('btn-step4').addEventListener('click', () => {
        $('sum-db').textContent = state.db.db_name;
        $('sum-prefix').textContent = state.db.prefix;
        $('sum-storage').textContent = state.storage;
        const sel = $('locale');
        $('sum-lang').textContent = sel && sel.selectedOptions
            ? sel.selectedOptions[0].textContent.trim()
            : (sel ? sel.value : 'en');
        setStep(5);
    });

    // ── STEP 5: install ───────────────────────────────────────────────────────
    $('btn-install').addEventListener('click', async () => {
        const btn = $('btn-install');
        btn.disabled = true;
        setLabel(btn, 'setup_installing');
        hideMsg('install-err');

        const res = await post('install', {
            ...state.db,
            storage: state.storage,
            locale: $('locale') ? $('locale').value : 'en',
            password: state.password,
            totp_secret: state.totpSecret,
            phrase: state.phrase,
        });

        if (!res.ok) {
            showMsg('install-err', res.error || t('setup_install_failed'));
            btn.disabled = false;
            setLabel(btn, 'setup_install_btn', 'lock');
            return;
        }

        state.password = '';
        state.phrase = '';
        state.totpSecret = '';
        _holdsWork = false;
        btn.hidden = true;
        // Installed: nothing left to go back to.
        $('btn-back5').hidden = true;

        // An advisory must not flash past during a redirect: stop and let the
        // owner continue.
        if (res.warning) {
            showMsg('install-warning', res.warning);
            $('btn-install-done').hidden = false;
            return;
        }
        showMsg('install-warning', t('setup_redirecting'));
        setTimeout(() => { window.location.href = $('btn-install-done').href; }, 1200);
    });

    // ── Existing installations ────────────────────────────────────────────────
    let selected = null;

    // One installation: straight to its reconnect form, prefix in the title.
    // Several: a list to pick from, each row with its recorded storage path,
    // and no prefix in the title.
    function showExistingPanel(installs) {
        document.querySelectorAll('.step-panel').forEach(p => p.classList.remove('active'));
        document.querySelector('.steps').hidden = true;
        $('panel-existing').hidden = false;
        $('existing-reconnect-form').hidden = true;
        hideMsg('existing-err');
        selected = null;

        const many = installs.length > 1;
        const intro = $('existing-intro');
        intro.dataset.t = many ? 'setup_existing_many' : 'setup_existing_one';
        intro.textContent = t(intro.dataset.t);
        const legend = $('existing-legend');
        legend.dataset.t = many ? 'setup_reconnect_btn' : 'setup_reconnect_legend';
        legend.textContent = t(legend.dataset.t);

        const list = $('existing-list');
        list.replaceChildren();
        list.hidden = !many;
        if (!many) {
            if (installs[0]) selectInstall(installs[0]);
            return;
        }
        installs.forEach(inst => {
            const row = document.createElement('label');
            row.className = 'existing-item';
            const radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = 'existing-install';
            radio.value = inst.prefix;
            radio.addEventListener('change', () => selectInstall(inst));
            const body = document.createElement('span');
            body.className = 'existing-item-body';
            const name = document.createElement('span');
            name.className = 'existing-prefix-name';
            name.textContent = inst.prefix;
            const path = document.createElement('span');
            path.className = 'existing-item-path';
            if (inst.storage) {
                path.textContent = inst.storage;
            } else {
                path.dataset.t = 'setup_reconnect_path_none';
                path.textContent = t(path.dataset.t);
            }
            body.append(name, path);
            row.append(radio, body);
            list.appendChild(row);
        });
    }

    function selectInstall(inst) {
        selected = inst;
        $('existing-selected-prefix').textContent = $('existing-list').hidden ? inst.prefix : '';
        const input = $('existing-storage');
        const hint = $('existing-storage-hint');
        input.value = inst.storage || '';
        // Keyed, so the language switcher keeps it translated.
        hint.dataset.t = inst.storage ? 'setup_reconnect_storage_recorded' : 'setup_reconnect_storage_unknown';
        hint.textContent = t(hint.dataset.t);
        syncExisting();
        hideMsg('existing-err');
        $('existing-reconnect-form').hidden = false;
        input.focus();
    }

    $('btn-existing-reconnect').addEventListener('click', async () => {
        if (!selected || !state.db) return;
        const btn = $('btn-existing-reconnect');
        hideMsg('existing-err');

        btn.disabled = true;
        setLabel(btn, 'setup_verifying');
        const res = await post('verify_existing', {
            ...state.db,
            prefix: selected.prefix,
            storage: $('existing-storage').value.trim(),
            locale: $('locale') ? $('locale').value : 'en',
        });

        if (!res.ok) {
            showMsg('existing-err', res.error || t('setup_verify_failed'));
            syncExisting();
            setLabel(btn, 'setup_reconnect_btn', 'key-round');
            return;
        }
        _holdsWork = false;
        setLabel(btn, 'setup_redirecting', 'check');
        setTimeout(() => { window.location.href = $('btn-install-done').href; }, 1200);
    });

    $('btn-existing-fresh').addEventListener('click', () => {
        // A fresh prefix, so the new installation cannot touch the listed ones.
        setPrefix(newPrefix());
        setStep(2);
        $('storage_path').focus();
    });

    $('btn-existing-back').addEventListener('click', () => setStep(1));

    // ── Language switcher ─────────────────────────────────────────────────────
    // Every element carrying data-t is redrawn from the bundle, including the
    // transient labels setLabel() and the reconnect hint create.
    function updateLang(code) {
        if (!window.SETUP_LANGS || !window.SETUP_LANGS[code]) return;
        lang = code;
        // helper.js reads it on every call (copy labels, the phrase file).
        window.AESBIN_LANG = window.SETUP_LANGS[code];
        document.querySelectorAll('[data-t]').forEach(el => {
            // Keys outside the bundle keep the server-rendered text.
            const v = lookup(el.dataset.t);
            if (v !== undefined) el.textContent = v;
        });
        document.documentElement.lang = code;
    }

    $('locale')?.addEventListener('change', e => updateLang(e.target.value));
})();
