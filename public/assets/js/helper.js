/**
 * public/assets/js/helper.js — shared helpers, no dependencies
 *
 *   • Password show/hide: any <input data-pw> gets an eye toggle.
 *   • Copy to clipboard: any <button data-copy-target="elemId"> copies the
 *     text of #elemId. Wired on load; markup added later is wired with
 *     AESBinHelper.bindCopyButtons().
 *   • Tooltips on touch screens: a .has-tip element shows its title on a
 *     tap, or on a long press when it is or sits inside a button or link.
 *   • window.AESBinHelper: escaping and description markdown, application
 *     URLs, MIME classification, date and size formatting, the password
 *     strength meter, the checksum block, the per-tab session key and the
 *     recovery phrase download.
 *
 * Loaded on setup, login, recovery, share and dashboard, before any script
 * that uses AESBinHelper. The UI part waits for DOMContentLoaded.
 */
(function() {
    'use strict';
    // Read on each call: the installer switches language without a reload
    // (updateLang() in setup.js replaces window.AESBIN_LANG).
    const t = k => (window.AESBIN_LANG || {})[k] || k;

    // ── HTML escaping and description rendering ───────────────────────────────
    // The only copy of the escaping rules: copies in several files drift,
    // and one that stops escaping a character reopens an XSS.
    //
    // esc() escapes &<>"' (the apostrophe too, so it is safe in a
    // single-quoted attribute). null/undefined become ''.
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    /**
     * Fill the {name} placeholders of a translated string in one pass, e.g.
     * fmt(t('update_available_msg'), { latest, current }). Same rules as
     * Lang::fill() on the server: a value is never scanned again, a "$&" in a
     * file name is not expanded as String.replace() with a string argument
     * would, and a placeholder with no value is left as it is.
     */
    const fmt = (str, subs) => String(str).replace(/\{([a-z_]+)\}/g, (m, k) =>
        Object.prototype.hasOwnProperty.call(subs, k) ? String(subs[k]) : m);

    /**
     * The attributes of every link that leaves the site, as one string for
     * the HTML built here and in dashboard.js: a new tab that cannot reach
     * this one, no referrer (the rel and the attribute, for browsers that
     * know only one) and nofollow. app/views/dashboard.php writes the same set.
     */
    const EXT_LINK = 'target="_blank" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer"';

    /**
     * Description mini-markdown: **bold**, *italic*, ~~strike~~, `code`,
     * [text](https://url), >>quote<<. Escapes first, so no raw HTML survives;
     * links are https only, like every other link the app prints, and get
     * EXT_LINK. Code spans and links are stashed as they are built,
     * so no later pattern reaches inside an href. >>quote<< becomes a <span>: a
     * <blockquote> in inline content would be reparented by the parser.
     */
    const fmtDesc = text => {
        // The stash markers use \u0000, so strip it from the input first.
        let h = esc(String(text ?? '').replace(/\u0000/g, ''));

        const stash = [];
        const keep = html => '\u0000' + (stash.push(html) - 1) + '\u0000';
        const inline = s => s
            .replace(/\*\*([^*\n]{1,200})\*\*/g, '<strong>$1</strong>')
            .replace(/~~([^~\n]{1,200})~~/g, '<s>$1</s>')
            .replace(/\*([^*\n]{1,200})\*/g, '<em>$1</em>');

        h = h.replace(/`([^`\n]{1,200})`/g, (_, code) => keep('<code class="desc-code">' + code + '</code>'));
        h = h.replace(/\[([^\]\n]{1,120})\]\((https:\/\/[^\s)\u0000]{1,500})\)/g, (_, label, url) =>
            keep('<a href="' + url + '" ' + EXT_LINK + '>' + inline(label) + '</a>'));
        h = inline(h);
        h = h.replace(/&gt;&gt;([\s\S]{1,1000}?)&lt;&lt;/g, '<span class="desc-quote">$1</span>');

        // Two passes: a link's text may hold a code span's marker.
        for (let pass = 0; pass < 2; pass++) {
            h = h.replace(/\u0000(\d+)\u0000/g, (_, i) => stash[+i]);
        }
        return h;
    };

    // ── JSON requests ─────────────────────────────────────────────────────────
    // The one fetch() wrapper for the JSON API, used by api.js, auth.js,
    // share.js and passkey.js: a timeout (opts.timeout, 12 s by default), a
    // translated message when the network fails, and on an error status an
    // Error carrying httpStatus, errorCode, retryAfter and the parsed body
    // (data). Each caller adds its own handling of those codes.
    const REQUEST_TIMEOUT_MS = 12000;
    async function fetchJson(url, opts = {}) {
        const { timeout = REQUEST_TIMEOUT_MS, ...fetchOpts } = opts;
        const ctrl = new AbortController();
        const tid = setTimeout(() => ctrl.abort(), timeout);
        let res;
        try {
            res = await fetch(url, { ...fetchOpts, signal: ctrl.signal });
        } catch {
            // Timeout (AbortError) or no connection (TypeError): the
            // browser's own message is technical and not translated.
            throw new Error(t('err_network'));
        } finally {
            clearTimeout(tid);
        }
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw Object.assign(new Error(data.error || `HTTP ${res.status}`), {
                httpStatus: res.status,
                errorCode: data.code,
                retryAfter: data.retry_after || null,
                data,
            });
        }
        return data;
    }

    /**
     * A 403 csrf_failed means the session was recreated (a login in another
     * tab, for one) and the page's CSRF token is stale: reload to pick up the
     * new one. True when it reloaded; the caller then returns a promise that
     * never settles, as the page is going away.
     */
    function reloadOnStaleCsrf(err) {
        if (err.httpStatus !== 403 || err.errorCode !== 'csrf_failed') return false;
        location.reload();
        return true;
    }

    // ── MIME classification ───────────────────────────────────────────────────
    // Rules come from MimeRegistry (PHP) via window.AESBIN_MIME_RULES
    // (app/views/partials/document-end.php). "editable" and "previewable" are
    // independent rulesets on purpose.
    const MIME_RULES = window.AESBIN_MIME_RULES || {
        editable: { extra: [], exceptions: [] },
        previewable: { extra: [], exceptions: [], media: [] },
    };
    const EDITABLE_EXTRA = new Set(MIME_RULES.editable.extra);
    const NON_EDITABLE_TEXT = new Set(MIME_RULES.editable.exceptions);
    const PREVIEWABLE_EXTRA = new Set(MIME_RULES.previewable.extra);
    const NON_PREVIEWABLE_TEXT = new Set(MIME_RULES.previewable.exceptions);
    // Images, video, audio and PDF with a preview: an exact list, since a
    // stored type is the content's as detected (MimeRegistry::isPreviewable()).
    const PREVIEWABLE_MEDIA = new Set(MIME_RULES.previewable.media || []);

    /** True if the inline editor opens this MIME (text/* or a known code MIME). */
    const isEditableMime = mime => {
        if (!mime || NON_EDITABLE_TEXT.has(mime)) return false;
        return mime.startsWith('text/') || EDITABLE_EXTRA.has(mime);
    };

    /** True if the preview shows this MIME as text (syntax-highlighted source). */
    const isPreviewableText = mime => {
        if (!mime || NON_PREVIEWABLE_TEXT.has(mime)) return false;
        return mime.startsWith('text/') || PREVIEWABLE_EXTRA.has(mime);
    };

    const isPreviewable = mime => {
        if (!mime) return false;
        return PREVIEWABLE_MEDIA.has(mime) || isPreviewableText(mime);
    };

    // An icon from the page's inline sprite; mirrors icon() in helpers.php.
    // cls is a colour modifier from base.css (ic-ok, ic-warn, ic-err, ic-muted).
    const icon = (name, cls = '') =>
        `<svg class="ic${cls ? ' ' + cls : ''}" aria-hidden="true"><use href="#i-${name}"></use></svg>`;

    /**
     * True if a stored file is audio: an audio/ type, or an .m4a that finfo
     * calls video/mp4 (generic MP4 brand, as ffmpeg and Android recorders
     * write it; MimeRegistry keeps the name). Icon and preview player agree.
     */
    const isAudioFile = (mime, name = '') =>
        !!mime && (mime.startsWith('audio/') || (mime.startsWith('video/') && /\.m4a$/i.test(name)));

    /** The icon for a file list row, by MIME type and name (markup, see icon()). */
    const mimeIcon = (mime, name = '') => {
        if (!mime) return icon('file');
        if (mime.startsWith('image/')) return icon('file-image');
        if (isAudioFile(mime, name)) return icon('file-audio');
        if (mime.startsWith('video/')) return icon('file-video');
        if (mime === 'application/pdf') return icon('file-text');
        if (mime.includes('zip') || mime.includes('tar')) return icon('file-archive');
        if (isEditableMime(mime)) return icon('file-code');
        return icon('file');
    };

    // ── Dates and sizes ───────────────────────────────────────────────────────
    // Server datetimes arrive as naive "YYYY-MM-DD HH:MM:SS" in UTC, which
    // `new Date(str)` parses as local time (enough to show a 1-hour share
    // as expired east of UTC). Made explicit UTC; a string with a zone
    // passes through.
    const parseServerDate = str => {
        if (!str) return null;
        if (/[Zz]|[+-]\d\d:?\d\d$/.test(str)) return new Date(str);
        return new Date(str.replace(' ', 'T') + 'Z');
    };

    // One date format for the whole app: day, abbreviated month, full year
    // ("3 ott 2026", "3 Oct 2026", "3. Okt. 2026"), so day and month can
    // never be swapped by a reader from another country; share visitors see
    // the page in their own language. Abbreviations, dots and order come from
    // the browser's Intl. Times are 24-hour everywhere.
    // "en" is formatted as en-GB: plain "en" means the US order, "Oct 3, 2026".
    const _dateLocale = () => {
        const lang = document.documentElement.lang || 'en';
        return lang === 'en' ? 'en-GB' : lang;
    };
    /** A Date as the day only: "3 ott 2026". */
    const fmtDay = d => d.toLocaleDateString(_dateLocale(), { day: 'numeric', month: 'short', year: 'numeric' });
    /** A Date with its time: "3 ott 2026, 14:05:09". */
    const fmtDateTime = (d, seconds = true) => fmtDay(d) + ', ' +
        d.toLocaleTimeString(_dateLocale(), {
            hour: '2-digit', minute: '2-digit', ...(seconds ? { second: '2-digit' } : {}), hourCycle: 'h23',
        });

    const relDate = iso => {
        const d = parseServerDate(iso);
        if (!d || Number.isNaN(d.getTime())) return '—';
        const diff = (Date.now() - d) / 1000;
        if (diff < 60) return t('just_now');
        if (diff < 3600) return fmt(t('min_ago'), { count: Math.floor(diff / 60) });
        if (diff < 86400) return fmt(t('hour_ago'), { count: Math.floor(diff / 3600) });
        return fmtDay(d);
    };
    // The exact moment behind a relDate(), for its tooltip.
    const fullDate = iso => {
        const d = parseServerDate(iso);
        if (!d || Number.isNaN(d.getTime())) return '';
        return fmtDateTime(d);
    };

    // ── Password strength ─────────────────────────────────────────────────────
    // Minimum master password length, counted in code points as mb_strlen()
    // counts on the server. Must equal CryptoService::PASSWORD_MIN_LENGTH
    // (bin/lint.sh checks).
    const PASSWORD_MIN_LENGTH = 12;
    const pwTooShort = pw => [...pw].length < PASSWORD_MIN_LENGTH;

    // A heuristic for the meter under a new-password field (installer and
    // recovery): length plus character classes, scored 0–4. Only a hint;
    // the one rule enforced, server-side too, is the minimum length.
    const pwStrength = pw => {
        const len = [...pw].length;
        let score = 0;
        if (len >= 8) score++;
        if (len >= PASSWORD_MIN_LENGTH) score++;
        if (len >= 16) score++;
        if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
        if (/[0-9]/.test(pw)) score++;
        if (/[^a-zA-Z0-9]/.test(pw)) score++;
        return Math.min(4, Math.floor(score * 4 / 6));
    };
    /**
     * Paints the meter (pw_meter() in helpers.php) for `pw`: the bar, and the
     * word under it, empty with no password. The word's key goes in data-t,
     * which the installer re-translates when its language changes.
     */
    // The label key is built from the score: ship every pw_strength_* key.
    // @lang-prefix pw_strength_
    const paintPwStrength = (pw, fillEl, labelEl) => {
        const score = pwStrength(pw);
        if (fillEl) {
            fillEl.style.width = (score + 1) * 20 + '%';
            fillEl.style.background = `var(--pw-${score})`;
        }
        if (labelEl && pw) {
            labelEl.dataset.t = 'pw_strength_' + score;
            labelEl.textContent = t(labelEl.dataset.t);
        } else if (labelEl) {
            delete labelEl.dataset.t;
            labelEl.textContent = '';
        }
    };

    // Sizes in steps of `base`: KB drops trailing zeros (and decimals from
    // 100), MB and GB show two; a value rounding up moves to the next unit.
    // Rounds half up on the decimal value like PHP's round() (toFixed() would
    // give 34.98 for 34.985). Must match format_bytes_in() in helpers.php.
    const roundTo = (v, digits) => Number(Math.round(Number(v + 'e' + digits)) + 'e-' + digits);
    // The language's decimal separator (decimal_sep in app/lang, as PHP's
    // decimal_separator()); a full stop where no language is loaded.
    const decimalSep = () => {
        const sep = t('decimal_sep');
        return sep === 'decimal_sep' ? '.' : sep;
    };
    const fmtSize = (b, base) => {
        if (!b) return '0 B';
        if (b < base) return b + ' B';
        const units = ['KB', 'MB', 'GB'];
        let v = b / base;
        for (let i = 0; i < units.length; i++) {
            const isKb = units[i] === 'KB';
            const rounded = roundTo(v, isKb && v >= 100 ? 0 : 2);
            if (rounded < base || i === units.length - 1) {
                return (isKb ? String(rounded) : rounded.toFixed(2)).replace('.', decimalSep()) + ' ' + units[i];
            }
            v /= base;
        }
    };
    // Decimal units, as the browser's download dialog: file sizes.
    const fmtBytes = b => fmtSize(b, 1000);
    // 1024-based, for values binary to begin with: the storage quota (set in
    // GB of 1024^3) and the PHP-ini upload limit.
    const fmtBytesBinary = b => fmtSize(b, 1024);

    // ── Checksums ─────────────────────────────────────────────────────────────
    const HASH_ALGOS = [
        ['crc32', 'CRC32'],
        ['md5', 'MD5'],
        ['sha1', 'SHA-1'],
        ['sha256', 'SHA-256'],
        ['sha512', 'SHA-512'],
    ];

    /**
     * <details> block with a file's checksums, or '' when it has none.
     * `idSuffix` keeps copy targets unique (file id or share file token).
     * The caller wires the copy buttons with bindCopyButtons().
     */
    const renderHashDetails = (f, idSuffix, open = false) => {
        if (!f.hashes) return '';
        const rows = HASH_ALGOS
            .filter(([key]) => f.hashes[key])
            .map(([key, label]) => {
                const id = `hash-${key}-${idSuffix}`;
                return `<div class="fc-hash-box">
            <div class="fc-hash-head">
                <span class="fc-hash-label">${esc(label)}</span>
                <button type="button" class="fc-hash-copy" data-copy-target="${esc(id)}" aria-label="${esc(t('copy'))}">
                    ${icon('copy')} ${esc(t('copy'))}
                </button>
            </div>
            <div class="fc-hash-val" id="${esc(id)}">${esc(f.hashes[key])}</div>
          </div>`;
            })
            .join('');
        if (!rows) return '';
        const vt = f.hashes.sha256
            ? `<a class="fc-hash-vt has-tip" title="${esc(t('file_hash_vt_hint'))}" href="https://www.virustotal.com/gui/file/${encodeURIComponent(f.hashes.sha256)}" ${EXT_LINK}>&raquo; ${esc(t('file_hash_check_virustotal'))}</a>`
            : '';
        return `<details class="fc-hashes"${open ? ' open' : ''}>
          <summary>${esc(t('file_hashes_title'))}&nbsp;${icon('shield-check')}</summary>
          <div class="fc-hash-table">${rows}</div>
          ${vt}
        </details>`;
    };

    /**
     * One panel at a time: opening a checksum block closes the others and any
     * preview. Capture phase, since 'toggle' does not bubble and cards are
     * rebuilt on every render.
     */
    const closeAllHashes = except => {
        document.querySelectorAll('details.fc-hashes[open]').forEach(d => {
            if (d !== except) d.open = false;
        });
    };
    document.addEventListener('toggle', e => {
        const d = e.target;
        if (!(d instanceof HTMLDetailsElement) || !d.classList.contains('fc-hashes') || !d.open) return;
        closeAllHashes(d);
        window.AESBinPreview?.closeAllPreviews();
    }, true);

    // ── Application URLs ──────────────────────────────────────────────────────
    // The one place the base path is read. AESBIN_BASE is set by the page
    // before any script runs: '' at the domain root, e.g. '/aesbin' in a
    // subdirectory. appUrl('api/files') and appUrl('/api/files') agree.
    const appUrl = path => (window.AESBIN_BASE || '') + '/' + String(path).replace(/^\//, '');

    // ── Per-tab session key ───────────────────────────────────────────────────
    // Generated at login, kept only in sessionStorage and sent as
    // X-Session-Key: the server wraps the MASTER_KEY in the PHP session
    // file with it. Never shared across tabs (see the note in api.js).
    const SESSION_KEY_STORAGE = 'aesbin_sk';
    const sessionKey = Object.freeze({
        get: () => sessionStorage.getItem(SESSION_KEY_STORAGE),
        /** A fresh random 32-byte key, stored and returned as base64. */
        generate: () => {
            const b64 = btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))));
            sessionStorage.setItem(SESSION_KEY_STORAGE, b64);
            return b64;
        },
        clear: () => sessionStorage.removeItem(SESSION_KEY_STORAGE),
        /** { 'X-Session-Key': … } when a key exists, {} otherwise. */
        header: () => {
            const sk = sessionStorage.getItem(SESSION_KEY_STORAGE);
            return sk ? { 'X-Session-Key': sk } : {};
        },
    });

    // ── Request headers and bodies ────────────────────────────────────────────
    // The CSRF token alone, for the few calls that must work without a
    // session key (logout from a tab that lost it).
    const csrfHeader = () =>
        window.AESBIN_CSRF !== undefined ? { 'X-CSRF-Token': window.AESBIN_CSRF } : {};
    /** Headers every owner request carries: CSRF token and session key. */
    const authHeaders = () => ({ ...csrfHeader(), ...sessionKey.header() });
    /**
     * body plus the page's honeypot field, empty (bots fill it). Unchanged on
     * pages that render no honeypot.
     */
    const withHoneypot = body =>
        window.AESBIN_HP_NAME ? { ...body, [window.AESBIN_HP_NAME]: '' } : body;

    // ── Recovery phrase download ──────────────────────────────────────────────
    // The installer and the recovery page offer the same .txt, named after
    // the host so it can be filed with its instance.
    const downloadRecoveryPhrase = phrase => {
        const host = window.location.hostname || 'aesbin';
        const blob = new Blob(
            [t('recovery_file_title') + ' (' + host + ')\n\n' + phrase + '\n\n' + t('recovery_file_note') + '\n'],
            { type: 'text/plain' }
        );
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'aesbin-recovery-' + host + '.txt';
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    };

    // ── Tooltips on touch screens ─────────────────────────────────────────────
    // A finger has no hover, so after a touch a .has-tip title shows in a
    // bubble: on a tap for a plain element, on a long press on or inside a
    // button or link (a tap keeps its normal action). A tap elsewhere, a scroll
    // or 5 s close it; a mouse keeps the native tooltip. Android reports the
    // long press as contextmenu (cancelled here); iOS does not, hence the timer
    // and -webkit-touch-callout in base.css.
    const LONG_PRESS_MS = 500;
    const MOVE_TOLERANCE_PX = 10;
    let tipEl = null;
    let tipTimer = null;
    let pressTimer = null;
    let press = null;          // {tip, x, y} while a finger is down on a control with a tip
    let swallowClick = false;
    let lastPointerTouch = false;
    const hideTip = () => {
        clearTimeout(tipTimer);
        if (tipEl) tipEl.hidden = true;
    };
    const showTip = target => {
        const text = target.getAttribute('title');
        if (!text) return;
        if (!tipEl) {
            tipEl = document.createElement('div');
            tipEl.className = 'tip-bubble';
            tipEl.setAttribute('role', 'tooltip');
            document.body.appendChild(tipEl);
        }
        tipEl.textContent = text;
        tipEl.hidden = false;
        // Above the target, centred and kept inside the viewport; below it
        // when there is no room above.
        const r = target.getBoundingClientRect();
        const b = tipEl.getBoundingClientRect();
        const viewW = document.documentElement.clientWidth;
        const left = Math.min(Math.max(8, r.left + r.width / 2 - b.width / 2), viewW - b.width - 8);
        const top = r.top - b.height - 6 >= 8 ? r.top - b.height - 6 : r.bottom + 6;
        tipEl.style.left = (left + window.scrollX) + 'px';
        tipEl.style.top = (top + window.scrollY) + 'px';
        clearTimeout(tipTimer);
        tipTimer = setTimeout(hideTip, 5000);
    };
    // The tip a touch on `node` refers to, and whether it sits in a control.
    const tipFor = node => {
        const control = node.closest?.('button, a');
        const tip = node.closest?.('.has-tip[title]') || control?.querySelector('.has-tip[title]');
        return tip ? { tip, inControl: !!control } : null;
    };
    // Fires once per press, from whichever comes first: the browser's own
    // long press (contextmenu) or the timer.
    const longPress = () => {
        clearTimeout(pressTimer);
        if (!press) return;
        swallowClick = true;
        showTip(press.tip);
        press = null;
        window.getSelection?.()?.removeAllRanges();
    };
    const endPress = () => {
        clearTimeout(pressTimer);
        press = null;
    };
    document.addEventListener('pointerdown', e => {
        lastPointerTouch = e.pointerType === 'touch';
    }, true);
    document.addEventListener('touchstart', e => {
        endPress();
        // A new gesture: some browsers send no click after a long press,
        // and a pending swallow must not eat this tap instead.
        swallowClick = false;
        const found = e.touches.length === 1 ? tipFor(e.target) : null;
        if (!found?.inControl) return;
        const touch = e.touches[0];
        press = { tip: found.tip, x: touch.clientX, y: touch.clientY };
        pressTimer = setTimeout(longPress, LONG_PRESS_MS);
    }, { passive: true });
    document.addEventListener('touchmove', e => {
        if (!press) return;
        const touch = e.touches[0];
        if (Math.abs(touch.clientX - press.x) > MOVE_TOLERANCE_PX ||
            Math.abs(touch.clientY - press.y) > MOVE_TOLERANCE_PX) endPress();
    }, { passive: true });
    ['touchend', 'touchcancel'].forEach(type =>
        document.addEventListener(type, endPress, { passive: true }));
    document.addEventListener('contextmenu', e => {
        if (press) longPress();
        if (swallowClick) e.preventDefault();
    });
    // Capture phase: the click that ends a long press, or a tap on a
    // stand-alone tip, never reaches the page's own handlers.
    document.addEventListener('click', e => {
        if (swallowClick) {
            swallowClick = false;
            e.preventDefault();
            e.stopPropagation();
            return;
        }
        const found = lastPointerTouch ? tipFor(e.target) : null;
        if (!found || found.inControl) {
            hideTip();
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        showTip(found.tip);
    }, true);
    window.addEventListener('scroll', hideTip, { passive: true });

    /** A palette token from base.css, e.g. cssVar('--bg2'). */
    const cssVar = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    /**
     * Replace el's content with a QR code of text (TOTP secrets, share links),
     * in the dark palette every page uses. A no-op without kjua (loaded only
     * by pages that draw one) or without el.
     */
    function renderQr(el, text, size = 180) {
        if (!el || !window.kjua) return;
        el.replaceChildren(window.kjua({
            text,
            render: 'canvas',
            size,
            quiet: 2,
            back: cssVar('--bg2'),
            fill: cssVar('--text'),
        }));
    }

    // ── Text files as bytes ───────────────────────────────────────────────────
    // The editor must hand a file back as it found it: same encoding, same
    // byte-order mark, same line endings. A textarea only holds LF, and
    // Response.text() reads everything as UTF-8 and drops the mark, so the
    // bytes are read here and what they were is remembered for the save.
    //
    //   - a UTF-8 or UTF-16 mark says what the file is;
    //   - without one, bytes that are valid UTF-8 are UTF-8;
    //   - anything else is read as Windows-1252. The browser's table for it
    //     gives every one of the 256 bytes its own character, so whatever
    //     the real encoding (Latin-1, another code page), each byte the user
    //     leaves alone goes back unchanged, even when it is shown as the
    //     wrong letter.
    const WIN1252 = 'windows-1252';

    /**
     * Decode a text file's bytes.
     *
     * @param {Uint8Array} bytes
     * @returns {{text: string, format: {encoding: string, bom: boolean, eol: string}}}
     *          text keeps the file's own line endings; format is what
     *          encodeText() needs to write it back.
     */
    const decodeText = bytes => {
        let encoding = 'utf-8';
        let bom = false;
        let body = bytes;
        if (bytes.length >= 3 && bytes[0] === 0xEF && bytes[1] === 0xBB && bytes[2] === 0xBF) {
            bom = true;
            body = bytes.subarray(3);
        } else if (bytes.length >= 2 && bytes[0] === 0xFF && bytes[1] === 0xFE) {
            encoding = 'utf-16le';
            bom = true;
            body = bytes.subarray(2);
        } else if (bytes.length >= 2 && bytes[0] === 0xFE && bytes[1] === 0xFF) {
            encoding = 'utf-16be';
            bom = true;
            body = bytes.subarray(2);
        }
        let text;
        if (encoding === 'utf-8') {
            try {
                text = new TextDecoder('utf-8', { fatal: true, ignoreBOM: true }).decode(body);
            } catch {
                encoding = WIN1252;
                text = new TextDecoder(WIN1252).decode(body);
            }
        } else {
            text = new TextDecoder(encoding, { ignoreBOM: true }).decode(body);
        }
        // The ending most lines have. A file that mixes them is saved with
        // this one throughout.
        const crlf = (text.match(/\r\n/g) || []).length;
        const lf = (text.match(/\n/g) || []).length - crlf;
        const cr = (text.match(/\r/g) || []).length - crlf;
        const eol = crlf > lf && crlf >= cr ? '\r\n' : (cr > lf ? '\r' : '\n');
        return { text, format: { encoding, bom, eol } };
    };

    // Character → byte for Windows-1252, built from the browser's own table
    // the first time it is needed (TextEncoder writes UTF-8 only).
    let _win1252 = null;
    const win1252Map = () => {
        if (!_win1252) {
            _win1252 = new Map();
            const dec = new TextDecoder(WIN1252);
            for (let b = 0; b < 256; b++) {
                _win1252.set(dec.decode(new Uint8Array([b])), b);
            }
        }
        return _win1252;
    };

    /**
     * The bytes of an edited text in the format decodeText() found. text
     * comes from a textarea, so its line endings are LF.
     *
     * @returns {Uint8Array|null} null when the text holds a character the
     *          file's encoding has no byte for (a "€" typed into a Latin-1
     *          file is fine, an emoji is not).
     */
    const encodeText = (text, format) => {
        const { encoding = 'utf-8', bom = false, eol = '\n' } = format || {};
        const s = eol === '\n' ? text : text.replace(/\n/g, eol);
        let body;
        if (encoding === WIN1252) {
            const map = win1252Map();
            body = new Uint8Array(s.length);
            for (let i = 0; i < s.length; i++) {
                const b = map.get(s[i]);
                if (b === undefined) return null;
                body[i] = b;
            }
        } else if (encoding === 'utf-16le' || encoding === 'utf-16be') {
            const little = encoding === 'utf-16le';
            body = new Uint8Array(s.length * 2);
            const view = new DataView(body.buffer);
            for (let i = 0; i < s.length; i++) {
                view.setUint16(i * 2, s.charCodeAt(i), little);
            }
        } else {
            body = new TextEncoder().encode(s);
        }
        if (!bom) return body;
        const mark = encoding === 'utf-16le' ? [0xFF, 0xFE]
            : encoding === 'utf-16be' ? [0xFE, 0xFF]
                : [0xEF, 0xBB, 0xBF];
        const out = new Uint8Array(mark.length + body.length);
        out.set(mark, 0);
        out.set(body, mark.length);
        return out;
    };

    /**
     * A format's names as the previews show them next to the Copy button:
     * encoding "UTF-8", "UTF-8 (BOM)", "Windows-1252"…, line endings
     * "LF (Unix)", "CRLF (Windows)", "CR". Technical names, the same in every
     * language and the ones the editor's two menus list.
     */
    const ENCODING_NAMES = {
        'utf-8': 'UTF-8', 'utf-16le': 'UTF-16 LE', 'utf-16be': 'UTF-16 BE', [WIN1252]: 'Windows-1252'
    };
    const formatNames = ({ encoding, bom, eol }) => ({
        encoding: (ENCODING_NAMES[encoding] || encoding) + (encoding === 'utf-8' && bom ? ' (BOM)' : ''),
        eol: eol === '\r\n' ? 'CRLF (Windows)' : eol === '\r' ? 'CR' : 'LF (Unix)',
    });

    // ── Copy to clipboard ─────────────────────────────────────────────────────
    // Every copy in the app goes through copyText(); the caller decides how to
    // confirm it, on the button (flashCopyButton()) or elsewhere.

    /**
     * Copies `text`; resolves true once it is on the clipboard. The Clipboard
     * API where the page has it (HTTPS); otherwise, or if it refuses, a hidden
     * textarea and execCommand('copy'), whose own answer is the result.
     */
    async function copyText(text) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            // Missing (plain HTTP) or refused: try the old way.
        }
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        let ok = false;
        try {
            ok = document.execCommand('copy');
        } catch {
            ok = false;
        }
        area.remove();
        return ok;
    }

    /**
     * A copy's outcome on its button for two seconds, then the button as it
     * was: a check and "Copied", or an x and "Copy failed". The word goes in
     * the .btn-label when the button has one (and so hides with it on a
     * phone), beside the icon when the button shows text, nowhere on an
     * icon-only button.
     */
    const COPY_FEEDBACK_MS = 2000;
    function flashCopyButton(btn, ok) {
        if (!('copyRest' in btn.dataset)) btn.dataset.copyRest = btn.innerHTML;
        clearTimeout(btn._copyTimer);
        const mark = icon(ok ? 'check' : 'x');
        const word = esc(t(ok ? 'copied' : 'copy_failed'));
        if (btn.querySelector('.btn-label')) {
            btn.innerHTML = `${mark} <span class="btn-label">${word}</span>`;
        } else {
            btn.innerHTML = btn.textContent.trim() ? `${mark} ${word}` : mark;
        }
        btn._copyTimer = setTimeout(() => {
            btn.innerHTML = btn.dataset.copyRest;
            delete btn.dataset.copyRest;
        }, COPY_FEEDBACK_MS);
    }

    // ── Sort bars ─────────────────────────────────────────────────────────────
    // The dashboard's files view and shares list, and the share page: markup
    // from sort_bar() (helpers.php), one rule for all three. An order is
    // {by, dir}. A new key starts in its natural direction, names A→Z and
    // the rest (dates, sizes, visits) newest or largest first, as in a file
    // manager; a second click on the same key flips it.
    const nextSort = ({ by, dir }, key) => by === key
        ? { by, dir: dir === 'asc' ? 'desc' : 'asc' }
        : { by: key, dir: key === 'name' ? 'asc' : 'desc' };

    /** Marks a sort bar's active key and its direction (↑ / ↓). */
    const paintSortBar = (bar, { by, dir }) => bar?.querySelectorAll('.sort-btn').forEach(btn => {
        const on = btn.dataset.sort === by;
        btn.classList.toggle('active', on);
        const arrow = btn.querySelector('.sort-dir');
        if (arrow) arrow.textContent = on ? (dir === 'desc' ? '↓' : '↑') : '';
    });

    /** Wires a sort bar: a click hands onChange the next order (nextSort()). */
    const bindSortBar = (bar, current, onChange) => bar?.querySelectorAll('.sort-btn').forEach(btn =>
        btn.addEventListener('click', () => onChange(nextSort(current(), btn.dataset.sort))));

    // Names in the order a file manager shows them: case and accents aside,
    // numbers by value ("photo 2" before "photo 10"), and the name before its
    // extension, so "report.pdf" comes before the "report_01.pdf" an upload
    // of the same name gets ("_" sorts before "." otherwise).
    const nameCollator = new Intl.Collator(undefined, { sensitivity: 'base', numeric: true });
    const splitForSort = s => {
        // ".tar.gz" and its kin count as one, as filename_split_ext() has
        // it (FILENAME_COMPOUND_EXTS, shipped by document-end.php).
        const lower = s.toLowerCase();
        const compound = (window.AESBIN_COMPOUND_EXTS || [])
            .find(c => lower.endsWith('.' + c) && s.length > c.length + 1);
        const dot = compound ? s.length - compound.length - 1 : s.lastIndexOf('.');
        // A dot at the start (".env") or followed by a space or a long tail
        // ("v1.2 final") is part of the name, not an extension.
        return dot > 0 && s.length - dot <= 16 && !/\s/.test(s.slice(dot)) ? [s.slice(0, dot), s.slice(dot)] : [s, ''];
    };
    function compareNames(x, y) {
        const [bx, ex] = splitForSort(x);
        const [by, ey] = splitForSort(y);
        return nameCollator.compare(bx, by) || nameCollator.compare(ex, ey);
    }

    /**
     * `list` in the order {by, dir}, as a new array. `keys` gives each sort
     * key's value for an item, and must have `name`: a string compares as a
     * name (compareNames()), anything else as a number (a date as
     * parseServerDate() returns it). Ties fall back to the name, A→Z, so equal
     * sizes or visits keep one readable order.
     */
    function sortItems(list, { by, dir }, keys) {
        const value = keys[by] || keys.name;
        const cmp = (get, a, b) => {
            const x = get(a);
            const y = get(b);
            return typeof x === 'string'
                ? compareNames(x, y)
                : (Number(x) || 0) - (Number(y) || 0);
        };
        return [...list].sort((a, b) =>
            (dir === 'desc' ? -cmp(value, a, b) : cmp(value, a, b)) ||
            (value === keys.name ? 0 : cmp(keys.name, a, b)));
    }
    /** A file's sort keys: the dashboard's files view and the share page. */
    const FILE_SORT_KEYS = Object.freeze({
        name: f => f.original_name,
        date: f => parseServerDate(f.created_at),
        size: f => f.size,
    });

    /** Bytes as standard base64, in slices: one call would overflow the stack on a large file. */
    const bytesToBase64 = bytes => {
        let bin = '';
        for (let i = 0; i < bytes.length; i += 0x8000) {
            bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
        }
        return btoa(bin);
    };

    window.AESBinHelper = Object.freeze({
        appUrl, sessionKey, csrfHeader, authHeaders, withHoneypot,
        esc, fmt, fmtDesc, icon, EXT_LINK,
        isEditableMime, isPreviewable, isPreviewableText, isAudioFile, mimeIcon,
        parseServerDate, relDate, fullDate, fmtDay, fmtDateTime, fmtBytes, fmtBytesBinary, decimalSep,
        renderHashDetails, closeAllHashes, paintPwStrength, pwTooShort,
        bindCopyButtons, fetchJson, reloadOnStaleCsrf, downloadRecoveryPhrase, renderQr,
        decodeText, encodeText, bytesToBase64, formatNames,
        nextSort, paintSortBar, bindSortBar, sortItems, FILE_SORT_KEYS, copyText, flashCopyButton,
    });

    function initPasswordToggles() {
        document.querySelectorAll('input[data-pw]').forEach(function(inp) {
            if (inp.dataset.pwReady) return;
            inp.dataset.pwReady = '1';

            const wrap = document.createElement('span');
            wrap.className = 'pw-field';
            inp.parentNode.insertBefore(wrap, inp);
            wrap.appendChild(inp);

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'pw-toggle';
            btn.setAttribute('aria-label', t('pw_toggle_label'));
            btn.innerHTML = icon('eye');
            wrap.appendChild(btn);

            // data-pw-mask opts a type="text" field into CSS
            // -webkit-text-security, which password managers ignore. Used
            // for the share-link passphrase, which does not belong in a
            // vault. Without support for it the field becomes a real
            // password field so the characters are never visible.
            const canMask = window.CSS && CSS.supports &&
                CSS.supports('-webkit-text-security', 'disc');
            const useCssMask = inp.hasAttribute('data-pw-mask') && canMask;
            if (inp.hasAttribute('data-pw-mask') && !canMask) {
                inp.type = 'password';
            }
            if (useCssMask) {
                inp.classList.add('pw-masked');
            }

            btn.addEventListener('click', function() {
                // The field in the wrapper now, not the one wired above: a
                // page may swap it for a clone to drop its listeners
                // (rebind() in share.js), and the clone keeps the mask.
                const field = wrap.querySelector('input');
                if (!field) return;
                const hidden = useCssMask ?
                    field.classList.contains('pw-masked') :
                    field.type === 'password';
                if (useCssMask) {
                    field.classList.toggle('pw-masked', !hidden);
                } else {
                    field.type = hidden ? 'text' : 'password';
                }
                btn.innerHTML = icon(hidden ? 'eye-off' : 'eye');
            });
        });
    }

    /** Wire every not-yet-wired <button data-copy-target> under `root`. */
    function bindCopyButtons(root = document) {
        root.querySelectorAll('[data-copy-target]').forEach(function(btn) {
            if (btn.dataset.copyReady) return;
            btn.dataset.copyReady = '1';

            btn.addEventListener('click', async function() {
                const el = document.getElementById(btn.dataset.copyTarget);
                const text = (el?.textContent || '').trim();
                if (!text) return;
                const ok = await copyText(text);
                flashCopyButton(btn, ok);
                // For a page that needs to know the text really reached the
                // clipboard (the recovery phrase's acknowledgement box).
                if (ok) btn.dispatchEvent(new CustomEvent('aesbin:copied'));
            });
        });
    }

    function init() {
        initPasswordToggles();
        bindCopyButtons();
    }

    if (document.readyState !== 'loading') init();
    else document.addEventListener('DOMContentLoaded', init);
})();
