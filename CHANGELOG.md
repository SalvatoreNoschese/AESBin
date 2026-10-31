# Changelog

All notable changes to AESBin are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-31

First public release.

- Single-user file manager with files, folders and descriptions, all stored
  as AES-256-GCM ciphertext: per-file keys wrapped by a master key, filenames
  and MIME types included.
- Keys derived from the master password with Argon2id; TOTP is mandatory; passkey login
  through WebAuthn PRF, with password + TOTP as the fallback.
- 10-word recovery phrase that can reset the password, the TOTP secret and
  itself. Recovery is off by default and is opened from the server
  (`config.php` plus a one-time code read from a file).
- Share links for files and folders, with the key in the URL fragment,
  optional password and expiry, and retargeting: the same link can point at a
  new file. Visitors are never identified: visits are counted and logged
  without their address or browser.
- Preview for images, SVG, audio, video, PDF and syntax-highlighted source
  code; Markdown is shown as source on purpose.
- Uploads checked by their content: a file named for another format gets its
  real extension back, and a format outside the list (what AESBin previews or
  edits, plus everyday documents, archives, installers and fonts) is refused,
  to be uploaded as a .zip.
- Text editor that keeps each file's encoding and line endings (UTF-8 with or
  without BOM, UTF-16, Windows-1252; LF, CRLF, CR) and lets you change them;
  it asks before Escape, a click outside or Back discards unsaved text.
- Trash with one-click restore; permanent delete and "Empty trash" ask for
  the password again.
- Metadata removal in the browser before upload, for JPEG, PNG, WebP, MP4,
  M4V and MOV (M4A audio: location only); the dashboard lists the metadata a
  file carries.
- Guarded installer: finishing the install requires reading a file on the
  server.
- Self-hosted ALTCHA captcha, strict CSP with a per-request nonce, `__Host-`
  cookies, per-tab session isolation, independent rate limits.
- English, Italian, Spanish, French and German.
- No Composer, no npm, no build step, no CDN. Third-party libraries are
  vendored unmodified; `bin/fetch-vendor.sh` downloads new releases without
  installing them.
- `bin/lint.sh`: syntax, language files, CSP invariants, vendored versions,
  a JavaScript smoke test, PHPStan, and that GitHub's *Source code* archive of
  a tag holds the site only.

The full list, with the reasoning behind the non-obvious choices, is in
`docs/features.md`; the security posture, including the risks accepted on
purpose, in `SECURITY.md`.

[1.0.0]: https://github.com/SalvatoreNoschese/AESBin/releases/tag/v1.0.0
