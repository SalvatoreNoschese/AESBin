[← AESBin docs](README.md) · [Project home](../README.md)

# Vendored libraries

AESBin ships with the following third-party libraries. No CDN, no npm and no Composer: every asset a page loads comes from this server. One request does leave it: the update check, which the server (never the visitor's browser) sends to the GitHub releases API at most once a day when the dashboard is open, or when the owner asks for it.

| Library | Directory | Version | Licence | Purpose |
|---------|-----------|---------|---------|---------|
| [report-uri/passkeys-php](https://github.com/report-uri/passkeys-php) | `app/vendor/passkeys-php/` | 2.0.1 | MIT | Passkey / WebAuthn, server side ([notes](#passkeys-php)) |
| [altcha-org/altcha-lib-php](https://github.com/altcha-org/altcha-lib-php) | `app/vendor/altcha-lib-php/` | 2.3.0 | MIT | Self-hosted proof-of-work captcha, server side |
| [altcha-org/altcha](https://github.com/altcha-org/altcha) | `public/vendor/altcha/` | 3.5.0 | MIT | Captcha widget |
| [mozilla/pdf.js](https://github.com/mozilla/pdf.js) | `public/vendor/pdfjs/` | 6.4.299 | Apache-2.0 | PDF preview (includes pdf.worker) |
| [PrismJS/prism](https://github.com/PrismJS/prism) | `public/vendor/prism/` | 1.30.0 | MIT | Syntax highlighting |
| [lrsjng/kjua](https://github.com/lrsjng/kjua) | `public/vendor/kjua/` | 0.10.0 | MIT (in its README) | QR codes |

Versions are declared in [`app/includes/Vendor.php`](../app/includes/Vendor.php), which is the single source of truth: the About → Credits panel inside the app renders from it, and `bin/lint.sh` fails if this table disagrees with it. Update both in the same commit that replaces a library: a stale version string is worse than none, because it gets trusted.

## Where they live

Two folders, one subfolder per library, split by who loads it: the
browser's libraries in `public/vendor/<lib>/`, in the web root, and the PHP
ones in `app/vendor/<lib>/`, outside it, where no URL reaches them. A new
library goes into whichever of the two runs it.

**Nothing in a library's folder is hand-edited**: every file there is an
upstream build, fetched by `bin/fetch-vendor.sh`, with its licence alongside,
plus the same `index.php` silence stub every directory of the project
carries. What the script can't tell you (which build variant, which files,
why) is in this page, not in the vendor folders, so that updating a library
is deleting its folder and pasting the fresh download, with nothing to
rescue from the old copy first.

Which build is not always the plain release:

- **Prism** has no single distributable to copy, so the bundle is assembled
  from `prism-core`, the components listed [below](#prism) and the
  line-numbers plugin. Nothing is edited (the pieces are upstream builds),
  but the combination is ours.
- **pdf.js** ships the `legacy/` build, plus the pure-JS (`nowasm`) image
  decoders in place of the WebAssembly ones.
- **kjua** has no separate licence file, so the script writes
  `public/vendor/kjua/LICENSE.txt` pointing at the "License" section of its
  own README, instead of guessing filenames that all 404. For pdf.js it also
  writes a short `nowasm/README.md`.
- Source maps and the `.map` files are not carried over: they are development
  aids that would roughly triple the size of a release for no runtime benefit.

## passkeys-php

A maintained fork of lbuchs/WebAuthn, forked at upstream 2.2.0. See
`NOTICE.md` in this library's folder for attribution to Lukas Buchs, the
original author.

The fork exists because lbuchs/WebAuthn's origin check accepts any host
that merely *ends with* the RP ID as a literal string (no dot-boundary
required), so an unrelated domain that happens to end that way passes
origin validation for a WebAuthn ceremony. Upstream is effectively dormant
and this is unfixed there; passkeys-php fixes it (exact match, or a true
subdomain), plus a few other WebAuthn Level 2/3 conformance gaps: cross-
origin ceremony rejection, backup-flag validation, Token Binding rejection.

No autoloader: `WebAuthn.php` requires its own dependencies by relative
path. `_test/` and `composer.json` are never fetched; `fetch-vendor.sh`
pulls only `src/`.

**Attestation is not implemented.** Only `fmt: "none"` with an empty
`attStmt` is ever accepted, and `getCreateArgs()` hardcodes
`attestation: "none"` itself. AESBin never verifies device provenance
(single-user vault), so this is exactly the behaviour it needs.

**Namespace:** `ReportUri\Passkeys\*` (same `src/` layout as
lbuchs/WebAuthn, different PSR-4 root, which matters when diffing against
upstream). Constructor: `($rpName, $rpId, $useBase64UrlEncoding)`; see
`app/actions/auth/passkey_challenge.php`, `passkey_register.php` and
`passkey_verify.php`.

## altcha-lib-php

**V2 API only** (`\AltchaOrg\Altcha\Altcha` + `Algorithm\Pbkdf2`), in
`app/actions/auth/captcha_challenge.php` / `SecurityService::verifyCaptcha()`.
`src/V1/` ships because it's part of the package, not because anything
calls it: V1's plain hash challenges are cheap per attempt and favour
native/GPU solving; V2's KDF narrows that gap.

**`counter` must always be provided** when creating a challenge. Omit it and
`keyPrefix` stays at its literal default, `'00'`: the library's
random-prefix behaviour only triggers when `keyPrefix` is passed as empty,
which omitting `counter` does not do. The result isn't unsolvable, it's the
opposite: a fixed single-byte prefix is a trivially weak challenge (~256
tries on average) that still looks valid. See
`app/actions/auth/captcha_challenge.php` for where this is set.

Replay guard keys on the challenge **nonce** (`{P}captcha_used`), read from
the HMAC-verified parameters after `verifySolution()` passes.

No Composer: PSR-4 autoloading lives in `app/includes/AltchaAutoload.php`, a
hand-written shim.

## altcha (widget)

**File:** `altcha.umd.min.cjs`, `dist/main`'s UMD build.

Not `dist/external` (upstream's advice for strict CSP): that drops every
bundled algorithm including PBKDF2, trading one nonce for extra files and a
manual registration step.

UMD over the ES-module build, for the nonce: `document.currentScript` is
`null` inside a module, forcing a fallback to a `<meta name="csp-nonce">`
tag readable by any script for the page's lifetime. The UMD build's
`document.currentScript.nonce` is browser-protected instead. One nonce
value ties together the CSP header, this `<script>` tag, and the inline
i18n script that follows it.

`.cjs` needs `AddType text/javascript .cjs` in `public/.htaccess`:
`X-Content-Type-Options: nosniff` means the browser won't run it without
one.

## Prism

**File:** `prism.min.js` only, assembled from `prism-core`, the 33
components listed below and the line-numbers plugin: up to `yaml` in
`components.json` declaration order, then the rest appended, each after
what it needs (every dependency is satisfied). Recipe lives in
`bin/fetch-vendor.sh`'s `lib_prism()`; keep the two in sync (`bin/lint.sh`
checks that every component the script pulls has a row here).

Component set is driven by `LANG_MAP` in
[`public/assets/js/preview.js`](../public/assets/js/preview.js): every
value there needs a matching component, and one nothing maps to is dead
weight.

| Component | Extensions | |
|---|---|---|
| `core` | — | the engine |
| `markup` | `.htm`, `.html`, `.xml` | |
| `css` | `.css` | |
| `clike` | — | dependency of javascript and php |
| `javascript` | `.js`, `.mjs` | |
| `apacheconf` | *(htaccess.txt)* | matched by filename (see `_detectLang()`) |
| `bash` | `.bash`, `.env`, `.sh` | |
| `batch` | `.bat`, `.cmd` | |
| `diff` | `.diff`, `.patch` | |
| `docker` | `.dockerfile` | |
| `ini` | `.cfg`, `.conf`, `.hosts`, `.inf`, `.ini` | |
| `json` | `.json` | |
| `markdown` | `.markdown`, `.md` | |
| `markup-templating` | — | dependency of php |
| `nginx` | *(nginx.conf)* | matched by filename: `.conf` maps to `ini` |
| `php` | `.php` | |
| `powershell` | `.ps1` | |
| `python` | `.py` | |
| `scss` | `.scss` | |
| `sql` | `.sql` | |
| `yaml` | `.yaml`, `.yml` | |
| `csv` | `.csv` | |
| `log` | `.log` | |
| `toml` | `.toml` | |
| `c` | `.c`, `.h` | |
| `cpp` | `.cpp`, `.hpp` | needs c |
| `csharp` | `.cs` | |
| `java` | `.java` | |
| `kotlin` | `.kt` | |
| `go` | `.go` | |
| `rust` | `.rs` | |
| `typescript` | `.ts` | needs javascript |
| `swift` | `.swift` | |
| `ruby` | `.rb` | |

`apacheconf` and `nginx` are matched by filename only, so neither appears
in `LANG_MAP`; `docker`, `ini` and `bash` are matched by filename too
(`dockerfile.txt`, `editorconfig.txt`, `env.txt`, `bashrc.txt`...), besides
their extensions. Read `_detectLang()` itself before declaring a component
unused from `LANG_MAP` alone. Text formats with no
Prism grammar (`.txt`, `.vcf`, `.ics`, `.srt`, `.vtt`, `.pem`, `.crt`,
`.key`) show as plain text.

**Styling lives outside vendor**, in
[`public/assets/css/prism-aesbin.css`](../public/assets/css/prism-aesbin.css)
(AESBin's own theme, not shipped by Prism), so it stays out of a directory
meant to diff clean against upstream.

## pdf.js

**Files:** `pdf.min.mjs`, `pdf.worker.min.mjs`, `nowasm/`.

From npm, not the GitHub release zip (which ships only unminified builds).
`legacy/build/`, not `build/`: the modern build uses JavaScript only the
latest browser releases have (e.g. `Map.prototype.getOrInsertComputed`),
and PDF preview would fail on Firefox ESR, Safari and older Chromium.
Update both main files together: pdf.js checks the bundle's `apiVersion`
against the worker's.

ES modules only (upstream dropped UMD at v4); `preview.js` loads it via a
memoised dynamic `import()`. `.mjs` needs `AddType text/javascript .mjs`.

**`nowasm/` is AESBin's own curation.** WebAssembly is off by CSP policy,
so pdf.js needs the pure-JS fallback decoders: `jbig2_nowasm_fallback.js`
(also covers CCITT Fax Group 4) and `openjpeg_nowasm_fallback.js` (JPEG
2000). Their four licence files travel with them. `LICENSE_QCMS` /
`LICENSE_PDFJS_QCMS` are deliberately not vendored: qcms has no JS
fallback, so with WebAssembly off it's never loaded.

## kjua

**File:** `kjua.min.js`, one self-contained file. Draws the QR codes of the
TOTP secret (installer, recovery, Settings → Authenticator) and of each new
share link.

## Icons

The interface icons are not a library and are not under a vendor folder: they are a
single SVG sprite, `public/assets/img/icons.svg`, holding the [Lucide](https://lucide.dev)
icons the app draws (lucide-static 1.51.0, ISC, licence in
`public/assets/img/LICENSE-lucide.txt`). Each `<symbol>` is the inner markup of
`lucide-static/icons/<name>.svg`, unchanged, with the id `i-<name>`.
`icon_sprite()` inlines it once per page and `icon()` (PHP and `helper.js`)
draws one; `bin/lint.sh` checks that every name drawn is in the sprite and
every symbol in it is drawn. To add an icon, copy its inner markup from the
same Lucide version into a new `<symbol>`.

Icons replace emoji because emoji are drawn by the visitor's system font:
different on every platform, and missing altogether for some (flags on
Windows, which is why the language flags are SVG files too).

## Why not just load them from a CDN?

It would be less work, and it is the wrong trade for this application. Four reasons:

- **Supply chain.** A script from a third-party origin runs on the dashboard, which is where the session key lives. A compromised or hijacked CDN account means arbitrary JavaScript with access to it. Vendored files change only when *you* change them.
- **The CSP depends on it.** `script-src 'self'` with no external origins is what makes the policy meaningful. Adding a CDN host punches a permanent hole in it.
- **Privacy.** Every visitor to a public share link would have their IP address disclosed to the CDN operator, on every page load. AESBin exists so that no third party sees who is fetching what.
- **It has to work offline.** On a LAN, an intranet, or a box with no outbound internet.

Pinned local copies also mean a release keeps working years later, when a CDN URL has quietly 404'd.

## Updating a library

1. Read the section above for that library, then the release notes for
   every version in between, not just the newest: a patch is usually
   cosmetic, a minor can change an API this application calls.
2. Run `bin/fetch-vendor.sh <name>` (or with no argument, all six). It
   writes a zip, with a HOW-TO-APPLY.txt inside, and never touches the
   project's vendor folders.
3. Delete the library's folder in the project (`app/vendor/<lib>/` or
   `public/vendor/<lib>/`) and paste in the one from the zip.
4. Update the version in `app/includes/Vendor.php` and in the table at the
   top of this page, in the same commit as the files.
5. Smoke-test the feature it powers: PDF preview, syntax highlighting, share
   QR code, captcha on the login page, passkey registration and login.

**Staying informed without any tooling:** on each of the six upstream repositories, use GitHub's *Watch → Custom → Releases*. You get an email when a new version is tagged, with no manifest, no bot and no extra file in this repository. Checking a couple of times a year is enough for a project this size, since none of these libraries move fast.

---

[← AESBin docs](README.md) · [Project home](../README.md)
