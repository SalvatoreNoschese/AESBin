# 🔐 AESBin

**Self-hosted, zero-knowledge encrypted file manager.**

> Store, share and preview your files — encrypted server-side with AES-256-GCM. The server never sees your plaintext.

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4)](https://www.php.net/)
[![No Composer](https://img.shields.io/badge/Composer-not_required-brightgreen)]()
[![No CDN](https://img.shields.io/badge/CDN-none-brightgreen)]()

---

## What is AESBin?

AESBin is a self-hosted file manager where everything is encrypted before being written to disk. The server holds only ciphertext: filenames, MIME types and content are all encrypted with per-file keys derived from a master key that never leaves your session.

Think of it as a **self-hosted, encrypted alternative** to:

- 📋 **Pastebin** — but for files, not just text, and fully encrypted
- 🖼️ **Imgur** — but private, zero-knowledge, under your control
- 📦 **PrivateBin** — but with a full file manager dashboard and sharing

It is best suited for **documents, config files, keys, notes, screenshots and similar small to medium assets** that you want to store or share quickly and securely. All encryption and decryption happen in-browser via the WebCrypto API, so very large files (tens of MB) will be slow to upload, download or preview depending on your device.

Unlike PrivateBin or similar open-upload tools, **AESBin is single-user and closed by design** — only the owner can log in and upload. Share links are read-only and require no account. This eliminates the abuse risk that comes with publicly open paste/upload services.

---

## Live Demo

👉 **[https://aesbin.salvatorenoschese.it/demo](https://aesbin.salvatorenoschese.it/demo)**

A public shared folder with sample files — no account needed. Try the file preview, download and share viewer firsthand.

---

## Features

### 🔐 Security & Encryption

- **AES-256-GCM** server-side encryption with a fresh random IV per file
- **Per-file DEKs** (Data Encryption Keys) wrapped by a master KEK
- **Argon2id** key derivation — resistant to brute-force and GPU attacks
- **Zero-knowledge share links** — decryption key lives in the URL fragment only, never sent to the server
- **WebAuthn / Passkey** authentication (FIDO2)
- **TOTP** two-factor authentication via any authenticator app (mandatory)
- **12-word recovery phrase** for account recovery and TOTP reset
- `__Host-` cookie prefix — session cookie hardening (requires HTTPS)
- Per-request **CSP nonce** — Content Security Policy A+ rating
- Self-hosted **ALTCHA** proof-of-work captcha (no API key, no external service, no tracking)
- Rate limiting on login and recovery endpoints
- Honeypot anti-bot field (server-generated, invisible to users)

### 📁 File Management

- **Container → Directory → File** hierarchy (flat structure, no nested directories)
- Upload, rename, move, trash, restore, permanently delete
- Full-text file search
- Trash with one-click restore
- Activity log

### 👁️ File Preview

- Images (JPEG, PNG, GIF, WebP…)
- SVG — rendered graphic + highlighted source, side by side
- PDF (via pdf.js — local, no external service)
- Source code with syntax highlighting (via Prism.js — 200+ languages including Markdown)
- QR code generation (via kjua)

### 🔗 Sharing

- Share entire directories or individual files
- Optional **password protection** for share links
- **Expiration**: 1 hour, 24 hours, 7 days, 30 days, or never
- Public viewer — recipients need no account
- Share key travels exclusively in the URL fragment — server cannot decrypt shared content

### ⚙️ Administration

- Full settings panel (locale, session timeout, IP lock, captcha, security options)
- Update notifications — checks GitHub for new releases, never auto-updates
- Multilingual interface: **Italian 🇮🇹**, **English 🇬🇧**, **Spanish 🇪🇸**, **French 🇫🇷**, **German 🇩🇪**

### 🏗️ Infrastructure

- Works on **shared hosting** — developed on ddev, tested on Altervista
- Works in webroot `/` or any subdirectory `/aesbin/`
- **No Composer**, no npm, no build step — upload and run
- **No CDN** — all vendor JS/CSS served locally, works fully offline/intranet
- Reverse-proxy aware (Altervista, Cloudflare, nginx — `X-Forwarded-Proto` handled)
- MySQL / MariaDB backend

---

## Security Model

```
Master Password
      │
      └─ Argon2id ──► KEK  (Key Encryption Key — never stored)
                        │
                        ├─ wraps per-file DEK ──► AES-256-GCM ──► ciphertext on disk (.bin)
                        │
                        └─ encrypts metadata (filename, MIME type)

Share link:  https://host/s/{16-char token}#{base64url(DEK)}
                                             └── URL fragment: never sent to server
```

The server stores only ciphertext, wrapped (encrypted) DEKs, and encrypted metadata. Without the master password (combined with TOTP or passkey), no file content or filename can be recovered — not even by the server administrator.

---

## Requirements

| Component | Minimum |
|-----------|---------|
| PHP | 8.1+ |
| MySQL | 5.7+ or MariaDB 10.3+ |
| Apache | mod_rewrite enabled, AllowOverride All |
| HTTPS | Required for passkeys and `__Host-` cookies. Strongly recommended. |

---

## Installation

1. **Download** the latest release and upload all files to your server.
2. **Create a MySQL database** for AESBin (any name, any charset).
3. **Visit** `https://yourdomain.com/setup/install.php` in your browser.
4. **Follow the setup wizard** — it will:
   - Write `config.php` with your database credentials and settings
   - Create all database tables
   - Set your admin password
   - Configure TOTP (scan the QR code with your authenticator app)
   - Generate your 12-word recovery phrase — **save it somewhere safe**
5. **Log in** at `https://yourdomain.com/login`.

> 💡 The setup wizard disables itself automatically once installation is complete. Removing the `/setup/` directory entirely after setup is best practice.

---

## Configuration

After installation, `config.php` in the project root holds all settings. The full list of available options with descriptions and defaults is documented in `config-sample.php`.

Key options:

```php
// Custom storage path — any directory writable by PHP.
// Default: {project_root}/storage
'storage_path' => '/absolute/path/to/your/storage',

// Interface language: 'en' (English) or 'it' (Italian)
'locale' => 'en',

// Force HTTPS detection (useful if your reverse proxy doesn't send X-Forwarded-Proto)
'https' => true,

'security' => [
    'session_idle_timeout' => 1800,  // Auto-logout after N seconds of inactivity. 0 = disabled.
    'session_ip_lock'      => true,  // Bind session to client IP address.
],

'captcha' => [
    'enabled' => true,  // Self-hosted ALTCHA proof-of-work captcha on login/recovery.
],
```

---

## Vendored Libraries

AESBin ships with the following third-party libraries. No CDN, no Composer, no external requests at runtime.

| Library | License | Purpose |
|---------|---------|---------|
| [lbuchs/WebAuthn](https://github.com/lbuchs/WebAuthn) | MIT | Passkey / WebAuthn server-side |
| [altcha-org/altcha-lib-php](https://github.com/altcha-org/altcha-lib-php) | MIT | Self-hosted PoW captcha (PHP) |
| [altcha-org/altcha](https://github.com/altcha-org/altcha) | MIT | ALTCHA widget (JS) |
| [pdf.js](https://mozilla.github.io/pdf.js/) | Apache 2.0 | PDF preview (includes pdf.worker) |
| [Prism.js](https://prismjs.com/) | MIT | Syntax highlighting |
| [kjua](https://larsjung.de/kjua/) | MIT | QR code generation |

---

## License

AESBin is licensed under the **GNU Affero General Public License v3.0 (AGPL-3.0)**.

You are free to use, study, and modify AESBin. If you distribute a modified version **or offer it as a hosted network service**, you must release the corresponding source code under the same AGPL-3.0 license.

See [LICENSE](LICENSE) for the full text.

---

## Author

Built by [Salvatore Noschese](https://github.com/SalvatoreNoschese).

Vendored library authors retain their respective copyrights — see individual license files inside `includes/WebAuthn/`, `includes/Altcha/`, and `views/assets/js/vendor/`.

---

## Support

If you find AESBin useful, consider buying me a coffee:

[![Donate via PayPal](https://img.shields.io/badge/Donate-PayPal-blue.svg)](https://paypal.me/SalvatoreN)
