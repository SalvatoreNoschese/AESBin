/**
 * public/assets/js/preview.js — file preview and download, shared by the
 * dashboard and the share viewer
 */
/* global AESBIN_PREVIEW_ASSETS, AESBinHelper, SHARE_TOKEN */
(function() {
    'use strict';

    const L = window.AESBIN_LANG || {};
    const t = k => L[k] || k;
    const $id = id => document.getElementById(id);
    const { esc, fmt, icon, isPreviewableText, isAudioFile, appUrl } = AESBinHelper;
    // Every page that loads this script (dashboard, share viewer) sets it.

    function loadScript(src) {
        if (document.querySelector(`script[src="${src}"]`)) return Promise.resolve();
        return new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = src;
            s.onload = resolve;
            s.onerror = () => reject(new Error('Failed to load ' + src));
            document.head.appendChild(s);
        });
    }

    /**
     * Load pdf.js (ES module only, hence import()) and return its namespace.
     * Memoised: the ~440 KB module is fetched once, on the first PDF preview.
     */
    let _pdfjsPromise = null;
    function loadPdfJs() {
        if (_pdfjsPromise) return _pdfjsPromise;
        _pdfjsPromise = import(AESBIN_PREVIEW_ASSETS.pdf).then((mod) => {
            // Same-origin module worker, covered by CSP worker-src 'self'.
            // Bundle and worker must be the same release (pdf.js refuses an
            // apiVersion mismatch): always upgrade them together.
            mod.GlobalWorkerOptions.workerSrc = AESBIN_PREVIEW_ASSETS.pdfWorker;
            return mod;
        }).catch((err) => {
            _pdfjsPromise = null; // let a later preview retry
            throw err;
        });
        return _pdfjsPromise;
    }

    function loadCSS(href) {
        if (document.querySelector(`link[href="${href}"]`)) return;
        const l = document.createElement('link');
        l.rel = 'stylesheet';
        l.href = href;
        document.head.appendChild(l);
    }

    async function ensurePrism() {
        if (window.Prism) return true;
        try {
            // The theme is AESBin's own; vendor/prism/ holds only upstream JS.
            loadCSS(AESBIN_PREVIEW_ASSETS.prismCss);
            await loadScript(AESBIN_PREVIEW_ASSETS.prism);
            return !!window.Prism;
        } catch {
            return false;
        }
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    /**
     * Renders `text` as a Prism-highlighted <pre><code> block.
     * `lang` is a Prism language id (e.g. 'php', 'markup') or '' for plain text.
     * Line numbers are always enabled.
     */
    async function _renderCode(text, lang) {
        await ensurePrism();
        const activeLang = lang || 'text';
        const code = document.createElement('code');
        code.textContent = text;
        code.className = `language-${activeLang}`;
        const pre = document.createElement('pre');
        pre.className = `language-${activeLang} line-numbers`;
        pre.appendChild(code);
        if (window.Prism) window.Prism.highlightElement(code);
        return pre;
    }

    /**
     * Copy-to-clipboard button. getText() is called lazily on click.
     * Always copies the raw source text regardless of which tab is active.
     */
    function _makeCopyBtn(getText) {
        const btn = document.createElement('button');
        btn.className = 'btn btn-ghost btn-sm';
        btn.innerHTML = icon('copy') + ' ' + esc(t('copy'));
        btn.addEventListener('click', async () =>
            AESBinHelper.flashCopyButton(btn, await AESBinHelper.copyText(await getText())));
        return btn;
    }

    /**
     * Markdown help panel. Markdown is shown as source, never rendered: that
     * would fetch its images and tell those sites the file was opened. The
     * editor list comes from AESBIN_MD_TOOLS in config.php, empty by default.
     */
    const MD_TOOLS = window.AESBIN_MD_TOOLS || {};

    function _makeMarkdownHelp(toolbar) {
        const box = document.createElement('details');
        box.className = 'md-help';

        // The toolbar sits inside the summary, so label and copy button
        // share one row.
        const head = document.createElement('summary');
        const label = document.createElement('span');
        label.className = 'md-help-label';
        // Icon here rather than in every language file.
        label.innerHTML = esc(t('md_help_btn')) + ' ' + icon('info');
        head.appendChild(label);
        if (toolbar) {
            // Otherwise a click on Copy also toggles the summary.
            toolbar.addEventListener('click', (e) => e.preventDefault());
            head.appendChild(toolbar);
        }
        box.appendChild(head);

        const names = Object.keys(MD_TOOLS);
        // md_help_tools introduces the list, so only with one.
        const paras = names.length
            ? [t('md_help_what'), t('md_help_why'), t('md_help_tools')]
            : [t('md_help_what'), t('md_help_why')];
        paras.forEach((p) => {
            const el = document.createElement('p');
            el.textContent = p;
            box.appendChild(el);
        });

        if (names.length) {
            const list = document.createElement('ul');
            names.forEach((name) => {
                const li = document.createElement('li');
                const a = document.createElement('a');
                a.href = MD_TOOLS[name];
                a.textContent = name;
                a.target = '_blank';
                // noreferrer: the destination must not learn which page sent
                // the visitor.
                a.rel = 'noopener noreferrer nofollow';
                a.referrerPolicy = 'no-referrer';
                li.appendChild(a);
                list.appendChild(li);
            });
            box.appendChild(list);
        }
        return box;
    }

    // ── Language detection ────────────────────────────────────────────────────

    const LANG_MAP = {
        js: 'javascript', mjs: 'javascript',
        html: 'markup', htm: 'markup', xml: 'markup',
        css: 'css', scss: 'scss',
        php: 'php', py: 'python',
        bat: 'batch', cmd: 'batch',
        ps1: 'powershell',
        sh: 'bash', bash: 'bash',
        sql: 'sql', json: 'json', yaml: 'yaml', yml: 'yaml',
        ini: 'ini', inf: 'ini', cfg: 'ini', conf: 'ini', hosts: 'ini',
        env: 'bash',
        dockerfile: 'docker',
        diff: 'diff', patch: 'diff',
        md: 'markdown', markdown: 'markdown',
        csv: 'csv', log: 'log',
        toml: 'toml',
        c: 'c', h: 'c', cpp: 'cpp', hpp: 'cpp', cs: 'csharp',
        java: 'java', kt: 'kotlin', go: 'go', rs: 'rust',
        ts: 'typescript', swift: 'swift', rb: 'ruby',
    };

    /**
     * A listed format can still hold a codec this browser lacks (an iPhone
     * MOV in HEVC, on Firefox or a Chrome without it). The player then fails
     * (error: replaced by a note) or plays the sound over a blank picture
     * (no video size: a note above it).
     */
    function _watchMedia(body, el) {
        const note = () => `<p class="preview-msg">${esc(t('preview_media_unsupported'))}</p>`;
        el.addEventListener('error', () => { body.innerHTML = note(); }, { once: true });
        if (el.tagName === 'VIDEO') {
            el.addEventListener('loadedmetadata', () => {
                if (el.videoWidth === 0) el.insertAdjacentHTML('beforebegin', note());
            }, { once: true });
        }
    }

    /** Returns a Prism language id for `filename`, or '' for plain text. */
    function _detectLang(filename) {
        const fn = (filename || '').toLowerCase();
        // Extension-less uploads are stored with .txt (MimeRegistry, upload):
        // .htaccess as htaccess.txt, Dockerfile as Dockerfile.txt.
        if (fn === 'htaccess.txt') return 'apacheconf';
        if (fn === 'dockerfile.txt' || fn === 'containerfile.txt') return 'docker';
        if (fn === 'editorconfig.txt' || fn === 'gitconfig.txt' || fn === 'npmrc.txt') return 'ini';
        // .conf maps to ini (Apache, systemd and others use it too), so
        // nginx is matched by this exact filename only.
        if (fn === 'nginx.conf') return 'nginx';
        if (
            fn === 'env.txt' ||
            fn === 'bashrc.txt' ||
            fn === 'bash_profile.txt' ||
            fn === 'bash_aliases.txt' ||
            fn === 'bash_logout.txt' ||
            fn === 'profile.txt' ||
            fn === 'zshrc.txt' ||
            fn === 'zprofile.txt'
        ) {
            return 'bash';
        }
        const ext = fn.split('.').pop() || '';
        return Object.prototype.hasOwnProperty.call(LANG_MAP, ext) ? LANG_MAP[ext] : '';
    }

    // ── Right-click/drag guard (share view only) ──
    // A deterrent, not protection: a transparent shield over the <img>
    // blocks "Save image as" and drag-out, so a casual save goes through the
    // tracked download button. DevTools or a screenshot still get the image.
    // SHARE_TOKEN (app/views/share.php $jsVars) marks the share viewer page.
    function _guardedImageEl(img) {
        if (typeof SHARE_TOKEN === 'undefined') return img;
        img.draggable = false;
        const guard = document.createElement('div');
        guard.className = 'preview-media-guard';
        guard.appendChild(img);
        const shield = document.createElement('div');
        shield.className = 'preview-media-shield';
        shield.addEventListener('contextmenu', e => e.preventDefault());
        shield.addEventListener('dragstart', e => e.preventDefault());
        guard.appendChild(shield);
        return guard;
    }

    // ── Main renderer ─────────────────────────────────────────────────────────

    /**
     * A text file's content for display, read with the editor's decoder
     * (AESBinHelper.decodeText()): blob.text() would show every accented
     * letter of a file that is not UTF-8 as "\uFFFD". Returns {text, format}.
     */
    const _blobDecoded = async blob =>
        AESBinHelper.decodeText(new Uint8Array(await blob.arrayBuffer()));

    /**
     * A text preview's toolbar: the file's encoding and line endings on the
     * left ("Encoding: UTF-8  Line endings: LF (Unix)", for owner and share
     * visitor alike: nothing else says what a text file is), the Copy button
     * on the right.
     */
    function _makeTextToolbar(text, format) {
        const bar = document.createElement('div');
        bar.className = 'preview-toolbar';
        const names = AESBinHelper.formatNames(format);
        const tag = document.createElement('span');
        tag.className = 'preview-format';
        for (const part of [
            fmt(t('preview_format_encoding'), { encoding: names.encoding }),
            fmt(t('preview_format_eol'), { eol: names.eol }),
        ]) {
            const item = document.createElement('span');
            item.textContent = part;
            tag.appendChild(item);
        }
        bar.appendChild(tag);
        bar.appendChild(_makeCopyBtn(() => text));
        return bar;
    }

    async function renderPreview(body, blob, mime, filename) {
        if (!body) return;
        mime = mime || 'application/octet-stream';
        const url = URL.createObjectURL(new Blob([blob], { type: mime }));
        body.classList.add('preview-wrap');

        // ── SVG image (Code first, then Image) ────────────────────────────────
        if (mime === 'image/svg+xml') {
            const { text, format } = await _blobDecoded(blob);

            body.innerHTML = '';

            const bar = _makeTextToolbar(text, format);
            body.appendChild(bar);

            const pre = await _renderCode(text, 'markup');
            pre.style.overflowX = 'auto';
            body.appendChild(pre);

            const imgWrapper = document.createElement('div');
            imgWrapper.className = 'svg-image-display';

            // Rendered through <img>, where an SVG runs no script and loads
            // nothing external.
            const img = new Image();
            img.src = url;
            img.alt = filename || '';
            img.className = 'preview-media';
            img.onload = () => setTimeout(() => URL.revokeObjectURL(url), 5000);
            img.onerror = () => URL.revokeObjectURL(url);

            imgWrapper.appendChild(_guardedImageEl(img));
            body.appendChild(imgWrapper);

        // ── Standard images ───────────────────────────────────────────────────
        } else if (mime.startsWith('image/')) {
            const img = new Image();
            img.src = url;
            img.alt = filename || '';
            img.className = 'preview-media';
            body.innerHTML = '';
            body.appendChild(_guardedImageEl(img));
            // Kept alive so a closed (hidden) preview reopens without a
            // re-fetch; resetPreviews() revokes it when the list is rebuilt.
            _trackPreviewUrl(body, url);

        // ── Video ─────────────────────────────────────────────────────────────
        // An .m4a stored as video/mp4 is audio (isAudioFile()): it plays in
        // the audio player below.
        } else if (mime.startsWith('video/') && !isAudioFile(mime, filename || '')) {
            body.innerHTML = `<video src="${url}" controls class="preview-video"></video>`;
            _watchMedia(body, body.querySelector('video'));
            _trackPreviewUrl(body, url);

        // ── Audio ─────────────────────────────────────────────────────────────
        } else if (isAudioFile(mime, filename || '')) {
            body.innerHTML = `<audio src="${url}" controls class="preview-audio"></audio>`;
            _watchMedia(body, body.querySelector('audio'));
            _trackPreviewUrl(body, url);

        // ── PDF (PDF.js paginated engine) ─────────────────────────────────────
        } else if (mime === 'application/pdf') {
            // Tracked like the image URL: the failure fallback links to it.
            _trackPreviewUrl(body, url);
            const uid = 'pv-' + Math.random().toString(36).slice(2, 9);
            const pageInfo = fmt(t('pdf_page_of'), {
                page: `<span id="pdf-page-num-${uid}">1</span>`,
                total: `<span id="pdf-page-count-${uid}">...</span>`,
            });
            body.innerHTML = `
                <div class="pdf-preview-container">
                    <div class="pdf-controls">
                        <button class="btn btn-ghost btn-sm pdf-nav-btn" id="pdf-prev-${uid}" aria-label="${esc(t('pdf_prev_page'))}" title="${esc(t('pdf_prev_page'))}">◀</button>
                        <span class="pdf-page-info">${pageInfo}</span>
                        <button class="btn btn-ghost btn-sm pdf-nav-btn" id="pdf-next-${uid}" aria-label="${esc(t('pdf_next_page'))}" title="${esc(t('pdf_next_page'))}">▶</button>
                    </div>
                    <div class="pdf-scroll-wrap">
                        <canvas id="pdf-canvas-${uid}" class="pdf-canvas"></canvas>
                    </div>
                </div>`;

            try {
                const pdfjsLib = await loadPdfJs();

                const arrayBuffer = await blob.arrayBuffer();
                // useWasm:false for the CSP: WebAssembly would need
                // 'wasm-unsafe-eval' in script-src for every script on the
                // page. pdf.js then loads pure-JS fallbacks from wasmUrl, so
                // JBIG2 and JPEG2000 render more slowly and ICC colour falls
                // back to the default space.
                const pdf = await pdfjsLib.getDocument({
                    data: arrayBuffer,
                    useWasm: false,
                    // Our folder name: only upstream's *_nowasm_fallback.js
                    // files are vendored. pdf.js appends the file name, so
                    // no ?v= here: .htaccess makes these revalidate instead.
                    wasmUrl: appUrl('vendor/pdfjs/nowasm/'),
                }).promise;
                // destroyMedia() frees the worker and the document on reset.
                const pdfId = (body.id || '').replace('prev-body-', '');
                if (pdfId) _pdfDocs.set(pdfId, pdf);

                let pageNum = 1;
                const totalPages = pdf.numPages;
                const countEl = $id(`pdf-page-count-${uid}`);
                if (countEl) countEl.textContent = totalPages;

                let pageRendering = false;
                let pageNumPending = null;

                const renderPage = async function(num) {
                    pageRendering = true;
                    const page = await pdf.getPage(num);
                    const canvas = $id(`pdf-canvas-${uid}`);
                    if (!canvas) return;
                    const ctx = canvas.getContext('2d');
                    const dpr = window.devicePixelRatio || 1;
                    const containerWidth = body.clientWidth || 600;
                    const unscaledViewport = page.getViewport({ scale: 1 });
                    const scale = (containerWidth - 32) / unscaledViewport.width;
                    const viewport = page.getViewport({ scale: Math.min(scale, 1.5) });
                    canvas.width = viewport.width * dpr;
                    canvas.height = viewport.height * dpr;
                    canvas.style.width = viewport.width + 'px';
                    canvas.style.height = viewport.height + 'px';
                    await page.render({
                        canvasContext: ctx,
                        viewport,
                        transform: [dpr, 0, 0, dpr, 0, 0]
                    }).promise;
                    pageRendering = false;
                    const numEl = $id(`pdf-page-num-${uid}`);
                    if (numEl) numEl.textContent = num;
                    if (pageNumPending !== null) {
                        const nextNum = pageNumPending;
                        pageNumPending = null;
                        renderPage(nextNum);
                    }
                };

                const queueRenderPage = function(num) {
                    if (pageRendering) pageNumPending = num;
                    else renderPage(num);
                };

                $id(`pdf-prev-${uid}`)?.addEventListener('click', () => {
                    if (pageNum <= 1) return;
                    pageNum--;
                    queueRenderPage(pageNum);
                });
                $id(`pdf-next-${uid}`)?.addEventListener('click', () => {
                    if (pageNum >= totalPages) return;
                    pageNum++;
                    queueRenderPage(pageNum);
                });

                await renderPage(pageNum);

            } catch (pdfErr) {
                console.error(pdfErr);
                body.innerHTML = `
                    <p class="preview-msg">
                        ${esc(t('pdf_render_failed'))}<br>
                        <a href="${url}" target="_blank" rel="noopener" class="preview-link">${esc(t('pdf_open_new_tab'))}</a>
                    </p>`;
            }

        // ── Markdown source ───────────────────────────────────────────────────
        } else if (mime === 'text/markdown' || ((filename || '').toLowerCase().endsWith('.md') || (filename || '').toLowerCase().endsWith('.markdown'))) {
            const { text, format } = await _blobDecoded(blob);
            URL.revokeObjectURL(url);

            const bar = _makeTextToolbar(text, format);

            const pre = await _renderCode(text, 'markdown');

            body.innerHTML = '';
            // The toolbar goes into the help panel's summary row.
            body.appendChild(_makeMarkdownHelp(bar));
            body.appendChild(pre);

        // ── Plain text and source code ────────────────────────────────────────
        } else if (isPreviewableText(mime)) {
            const { text, format } = await _blobDecoded(blob);
            URL.revokeObjectURL(url);

            const lang = _detectLang(filename);

            const bar = _makeTextToolbar(text, format);

            const pre = await _renderCode(text, lang);

            body.innerHTML = '';
            body.appendChild(bar);
            body.appendChild(pre);

        // ── Unsupported format ────────────────────────────────────────────────
        } else {
            URL.revokeObjectURL(url);
            body.innerHTML = `<p class="preview-msg">${esc(t('preview_not_available'))}</p>`;
        }
    }

    function forceDownloadBlob(blob, filename) {
        const forceBlob = new Blob([blob], { type: 'application/octet-stream' });
        const url = URL.createObjectURL(forceBlob);
        let name = filename || 'download';
        // Firefox opens PDFs in its own viewer despite the download
        // attribute (https://bugzilla.mozilla.org/show_bug.cgi?id=1756980).
        // A trailing space hides the ".pdf" ending; Firefox trims it on
        // save. Firefox only: Chrome turns the space into an underscore.
        const isFirefox = /firefox/i.test(navigator.userAgent);
        if (isFirefox && /\.pdf$/i.test(name)) name += ' ';
        const a = Object.assign(document.createElement('a'), {
            href: url, download: name, type: 'application/octet-stream'
        });
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(url), 10000);
    }

    const _openPrev = new Set();
    const _loadedPrev = new Set();
    const _mediaUrls = new Map(); // id -> blob URL, for every preview that keeps one
    const _pdfDocs = new Map(); // id -> pdf.js document, destroyed with the preview

    function setPrevIcon(btn, open) {
        if (!btn) return;
        const slot = btn.querySelector('.icon');
        if (slot) slot.innerHTML = icon(open ? 'eye-off' : 'eye');
        btn.classList.toggle('active', open);
    }

    /**
     * Pauses <video>/<audio> in a preview body (hiding the row does not).
     * Keeps the src so playback can resume; destroyMedia() tears down.
     */
    function stopMedia(id) {
        const body = $id(`prev-body-${id}`);
        if (!body) return;
        body.querySelectorAll('video, audio').forEach(el => el.pause());
    }

    /**
     * Records a preview's long-lived blob URL (image, video, audio, PDF)
     * so resetPreviews() can revoke it.
     */
    function _trackPreviewUrl(body, url) {
        const id = (body.id || '').replace('prev-body-', '');
        if (id) _mediaUrls.set(id, url);
    }

    /** Stops playback AND revokes the tracked blob URL, freeing the memory. */
    function destroyMedia(id) {
        stopMedia(id);
        const url = _mediaUrls.get(id);
        if (url) {
            URL.revokeObjectURL(url);
            _mediaUrls.delete(id);
        }
        const pdf = _pdfDocs.get(id);
        if (pdf) {
            _pdfDocs.delete(id);
            // pdf.js 6 dropped PDFDocumentProxy.destroy(): the loading task
            // owns the document and its worker now. Called on either, so a
            // library update cannot turn this into an exception again; one
            // here would stop resetPreviews() halfway, leaving the remaining
            // blob URLs unrevoked.
            try {
                (pdf.loadingTask ?? pdf).destroy?.();
            } catch { /* best-effort cleanup */ }
        }
    }

    async function togglePreview(f, fetchBlob) {
        const id = f.id;
        const btn = $id(`prev-btn-${id}`);
        const row = $id(`prev-row-${id}`);
        const body = $id(`prev-body-${id}`);

        if (_openPrev.has(id)) {
            _openPrev.delete(id);
            if (row) row.hidden = true;
            setPrevIcon(btn, false);
            stopMedia(id);
            return;
        }
        _openPrev.forEach(other => {
            const orow = $id(`prev-row-${other}`);
            const obtn = $id(`prev-btn-${other}`);
            if (orow) orow.hidden = true;
            setPrevIcon(obtn, false);
            stopMedia(other);
        });
        _openPrev.clear();
        _openPrev.add(id);
        if (row) {
            row.hidden = false;
            // The body is kept between openings; reopen with the Markdown
            // help collapsed.
            const help = row.querySelector('details.md-help');
            if (help) help.open = false;
        }
        setPrevIcon(btn, true);

        if (!_loadedPrev.has(id)) {
            _loadedPrev.add(id);
            if (body) body.innerHTML = `<p class="preview-msg">${esc(t('loading'))}</p>`;
            try {
                const blob = await fetchBlob(id);
                await renderPreview(body, blob, f.mime || 'application/octet-stream', f.original_name);
                // The metadata box: dashboard only (strip-meta.js is not loaded
                // on the share viewer).
                // A PDF passes the document pdf.js opened for the preview.
                await window.AESBinStripMeta?.renderPanel(body, blob, _pdfDocs.get(String(id)), f.mime || '');
            } catch (err) {
                _loadedPrev.delete(id);
                if (body) body.innerHTML = `<p class="preview-msg preview-error">${esc(err.message)}</p>`;
            }
        }
    }

    /**
     * Closes every open preview (row, button icon, media) while the rows
     * stay on screen. resetPreviews() is for a list about to be rebuilt.
     */
    function closeAllPreviews() {
        _openPrev.forEach(id => {
            const row = $id(`prev-row-${id}`);
            if (row) row.hidden = true;
            setPrevIcon($id(`prev-btn-${id}`), false);
            stopMedia(id);
        });
        _openPrev.clear();
    }

    function resetPreviews() {
        _mediaUrls.forEach((_url, id) => destroyMedia(id));
        _openPrev.clear();
        _loadedPrev.clear();
    }

    window.AESBinPreview = {
        forceDownloadBlob,
        togglePreview,
        closeAllPreviews,
        resetPreviews
    };
})();
