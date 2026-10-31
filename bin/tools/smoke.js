/**
 * bin/tools/smoke.js — loads each page's scripts in a fake DOM, in the order
 * the page declares them, and checks the public objects other files call.
 *
 * `node --check` only proves a file parses; this catches a script that uses a
 * name defined by a later one, or a public function that was renamed. The
 * page lists are read from $pageJs in app/views/*.php, so nothing here needs
 * updating when they change. No canvas, WebAuthn or network.
 *
 * Usage:  node bin/tools/smoke.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const JS_DIR = path.join(ROOT, 'public/assets/js');
let failed = 0;
const ok = (label) => console.log('  OK  ' + label);
const no = (label, detail) => {
    failed++;
    console.log('  NO  ' + label + (detail ? '  — ' + detail : ''));
};

/** Minimal DOM: just enough for the scripts to load. */
function makeElement(id) {
    const classes = new Set();
    const el = {
        id,
        hidden: false,
        textContent: '',
        innerHTML: '',
        value: '',
        disabled: false,
        checked: false,
        dataset: {},
        style: { removeProperty() {}, setProperty() {} },
        classList: {
            add: (c) => classes.add(c),
            remove: (c) => classes.delete(c),
            toggle: (c, on) => (on ? classes.add(c) : classes.delete(c)),
            contains: (c) => classes.has(c),
        },
        _classes: classes,
        appendChild() {},
        removeChild() {},
        insertBefore() {},
        setAttribute() {},
        getAttribute: () => null,
        removeAttribute() {},
        addEventListener() {},
        removeEventListener() {},
        querySelector: () => null,
        querySelectorAll: () => [],
        closest: () => null,
        focus() {},
        click() {},
        remove() {},
    };
    return el;
}

function makeSandbox() {
    const doc = {
        readyState: 'complete',
        documentElement: makeElement('html'),
        body: makeElement('body'),
        head: makeElement('head'),
        createElement: (tag) => makeElement(tag),
        createTextNode: () => makeElement('#text'),
        getElementById: () => null,
        querySelector: () => null,
        querySelectorAll: () => [],
        addEventListener() {},
        removeEventListener() {},
        cookie: '',
    };
    const win = {
        document: doc,
        location: { href: 'https://example.test/', hostname: 'example.test', pathname: '/', search: '' },
        navigator: { userAgent: 'smoke', clipboard: { writeText: () => Promise.resolve() } },
        // The globals app/views/partials/document-end.php emits, empty but present.
        AESBIN_LANG: {},
        AESBIN_CSRF: 'smoke',
        // Same shape as MimeRegistry::jsRules(): helper.js reads it at load.
        AESBIN_MIME_RULES: {
            editable: { extra: [], exceptions: [] },
            previewable: { extra: [], exceptions: [], media: [] },
        },
        AESBIN_OBFMAIL: {},
        AESBIN_MD_TOOLS: {},
        AESBIN_PREVIEW_ASSETS: {},
        setTimeout: () => 0,
        clearTimeout() {},
        setInterval: () => 0,
        clearInterval() {},
        requestAnimationFrame: () => 0,
        fetch: () => Promise.resolve({ ok: true, json: () => Promise.resolve({}) }),
        matchMedia: () => ({ matches: false, addEventListener() {} }),
        addEventListener() {},
        removeEventListener() {},
        scrollTo() {},
        getComputedStyle: () => ({ getPropertyValue: () => '' }),
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        console,
        JSON,
        Promise,
        Math,
        Date,
        URL,
        TextEncoder,
        TextDecoder,
    };
    win.window = win;
    win.self = win;
    win.globalThis = win;
    return win;
}

function load(sandbox, file) {
    const full = path.join(JS_DIR, file);
    if (!fs.existsSync(full)) {
        no('load ' + file, 'file not found');
        return false;
    }
    try {
        vm.runInContext(fs.readFileSync(full, 'utf8'), sandbox, { filename: file });
        return true;
    } catch (e) {
        return e;
    }
}

/**
 * The $pageJs list each view declares, in order. "defer:" is stripped: it
 * changes when a script runs, not which earlier files it can rely on.
 */
function pageScripts() {
    const views = fs.readdirSync(path.join(ROOT, 'app/views')).filter((f) => f.endsWith('.php'));
    const out = [];
    for (const v of views) {
        const src = fs.readFileSync(path.join(ROOT, 'app/views', v), 'utf8');
        const files = [];
        const assign = /\$pageJs\s*(?:=|\[\]\s*=)\s*(\[[^\]]*\]|"[^"]+")/g;
        let m;
        while ((m = assign.exec(src)) !== null) {
            for (const q of m[1].matchAll(/"([^"]+\.js)"/g)) {
                files.push(q[1].replace(/^defer:/, ''));
            }
        }
        if (files.length) out.push([v, files]);
    }
    return out;
}

// ── Every page loads in its declared order ────────────────────────────────────
// A fresh context per page, so no page relies on another's globals.
const pages = pageScripts();
pages.length ? ok(`found $pageJs in ${pages.length} views`) : no('found $pageJs in the views');

const OURS = ['AESBinHelper', 'AESBinPreview', 'AESBinPasskey'];
const skipped = [];

for (const [view, files] of pages) {
    const sb = vm.createContext(makeSandbox());
    let broke = false;
    for (const f of files) {
        const r = load(sb, f);
        if (r === true) continue;
        if (r === false) {
            broke = true;
            break;
        }
        // Tripping over a DOM detail the stub lacks is expected. Only one of
        // OUR names being undefined counts: that is an ordering mistake.
        const m = /^(\w+) is not defined$/.exec(r.message);
        if (m && OURS.includes(m[1])) {
            no(view + ' → ' + f, r.message + '  <-- loaded before whatever defines it');
            broke = true;
        } else {
            skipped.push(view + ' → ' + f + ' (' + r.message + ')');
        }
    }
    if (!broke) ok(view + ': ' + files.join(', '));
}

// ── The public surfaces ───────────────────────────────────────────────────────
// The three objects other files call by name; everything else is an IIFE
// that only binds listeners.
const sb = vm.createContext(makeSandbox());
load(sb, 'helper.js');
load(sb, 'preview.js');
load(sb, 'passkey.js');

const X = sb.AESBinHelper;
X ? ok('helper.js exposes AESBinHelper') : no('helper.js exposes AESBinHelper');
if (X) {
    ['esc', 'fmtDesc'].forEach((f) => {
        typeof X[f] === 'function' ? ok('AESBinHelper.' + f) : no('AESBinHelper.' + f, 'missing or not a function');
    });
    X.esc(`<a href='x'>&"`) === '&lt;a href=&#39;x&#39;&gt;&amp;&quot;'
        ? ok('AESBinHelper.esc escapes &<>"\'')
        : no('AESBinHelper.esc', 'unexpected output');
    /<script/i.test(X.fmtDesc('<script>alert(1)</script> **b** [x](javascript:alert(1))'))
        ? no('AESBinHelper.fmtDesc', 'raw HTML survived')
        : ok('AESBinHelper.fmtDesc leaves no raw HTML');
    // A * or >> inside a link's URL must not become markup in the href.
    /href="[^"]*</.test(X.fmtDesc('[x](https://a.b/*y*) [z](https://c/>>) <<'))
        ? no('AESBinHelper.fmtDesc', 'markup inside an href')
        : ok('AESBinHelper.fmtDesc keeps hrefs intact');
    X.fmt('{from} → {to}, {count} {missing}', { from: 'a$&', to: '{count}', count: 2 }) === 'a$& → {count}, 2 {missing}'
        ? ok('AESBinHelper.fmt fills placeholders in one pass')
        : no('AESBinHelper.fmt', 'unexpected output');
    // Same cases as format_bytes_in() in app/includes/helpers.php.
    const sizes = [[999, '999 B', '999 B'], [1500, '1.5 KB', '1.46 KB'], [999999, '1.00 MB', '977 KB'],
        [1048575, '1.05 MB', '1.00 MB'], [10737418240, '10.74 GB', '10.00 GB'],
        [34985, '34.99 KB', '34.17 KB'], [1005, '1.01 KB', '1005 B']];
    sizes.every(([b, dec, bin]) => X.fmtBytes(b) === dec && X.fmtBytesBinary(b) === bin)
        ? ok('AESBinHelper.fmtBytes / fmtBytesBinary')
        : no('AESBinHelper.fmtBytes', 'unexpected output');
}

const P = sb.AESBinPreview;
P ? ok('preview.js exposes AESBinPreview') : no('preview.js exposes AESBinPreview');
if (P) {
    ['forceDownloadBlob', 'togglePreview', 'closeAllPreviews', 'resetPreviews'].forEach((f) => {
        typeof P[f] === 'function' ? ok('AESBinPreview.' + f) : no('AESBinPreview.' + f, 'missing or not a function');
    });
}

const K = sb.AESBinPasskey;
K ? ok('passkey.js exposes AESBinPasskey') : no('passkey.js exposes AESBinPasskey');

if (skipped.length) {
    console.log('  --  ' + skipped.length + ' script(s) not runnable outside a browser (expected):');
    skipped.forEach((x) => console.log('      ' + x));
}

console.log('='.repeat(50));
if (failed === 0) {
    console.log('Smoke test passed.');
    process.exit(0);
}
console.log(`${failed} problem(s).`);
process.exit(1);
