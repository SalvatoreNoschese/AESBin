<p align="center">
  <img src="https://github.com/SalvatoreNoschese/AESBin/raw/main/public/assets/img/banner.webp" alt="AESBin: encrypted file manager for your own server" width="100%">
</p>

**A self-hosted, single-user file manager that keeps everything it stores encrypted at rest, and runs on ordinary shared PHP hosting.**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)
[![Version](https://img.shields.io/github/v/release/SalvatoreNoschese/AESBin?label=version&color=informational)](https://github.com/SalvatoreNoschese/AESBin/releases)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)
[![No Composer](https://img.shields.io/badge/Composer-not_required-brightgreen)](docs/vendored-libraries.md)
[![No CDN](https://img.shields.io/badge/CDN-none-brightgreen)](docs/vendored-libraries.md)

Files, filenames, MIME types and descriptions are stored as AES-256-GCM
ciphertext. The keys are derived from your master password (Argon2id), your
passkey (WebAuthn PRF), or your 10-word recovery phrase. A stolen database or
disk yields no file content, name or description without one of the three;
sizes, timestamps and the activity log stay readable
([what stays visible](docs/security-model.md#threat-model)).

> **Encryption is server-side, not client-side.** During an active session the
> server unwraps the master key for every request (the PHP session holds it
> only wrapped with a per-tab key), so this is not the same guarantee a
> zero-knowledge client-side app like PrivateBin or Cryptomator gives you.
> What it defeats is theft of the database, the stored files, or the whole
> disk. Read the [threat model](docs/security-model.md#threat-model), including
> what stays visible, before deciding it fits your needs.

---

## 💡 Why it exists

I wanted one place of my own to publish **screenshots, code snippets and
assorted files**, and to share them with a link, instead of scattering them
across Pastebin, Imgur and similar services I do not control.

I tried the obvious self-hosted options first:

- **PrivateBin** was the closest: self-hosted, genuinely zero-knowledge. I ran
  one for a while and had to shut it down because of abuse. The problem was
  not how *sharing* worked, but that *creating* a paste was just as open.
  Anyone who found the URL could post to it. It became a dead drop for
  strangers instead of a tool for sharing my own files.
- **Nextcloud** does all of it, but it is heavy to run, and it is not
  encrypted at rest by default: the administrator can read everything in it.

AESBin sits between the two: Nextcloud's idea of a personal dashboard,
encryption at rest by default, and uploading locked behind a single-user login.
Share links stay public and read-only, and need no account.

It suits documents, config files, keys, notes, screenshots and similar small to
medium assets. Encryption runs server-side in PHP, in memory, so it is the wrong
tool for tens of gigabytes.

---

## 📸 Screenshots

**Desktop**

<p align="center">
  <img src="https://github.com/SalvatoreNoschese/AESBin/raw/main/screenshots/01-dashboard.png" width="49%" alt="Dashboard" />
  <img src="https://github.com/SalvatoreNoschese/AESBin/raw/main/screenshots/02-preview.png" width="49%" alt="File preview" />
</p>
<p align="center">
  <img src="https://github.com/SalvatoreNoschese/AESBin/raw/main/screenshots/03-share-viewer.png" width="49%" alt="Public share viewer" />
  <img src="https://github.com/SalvatoreNoschese/AESBin/raw/main/screenshots/04-settings.png" width="49%" alt="Settings" />
</p>

**Mobile**

<p align="center">
  <img src="https://github.com/SalvatoreNoschese/AESBin/raw/main/screenshots/05-mobile-dashboard.png" width="23%" alt="Dashboard on mobile" />
  <img src="https://github.com/SalvatoreNoschese/AESBin/raw/main/screenshots/06-mobile-preview.png" width="23%" alt="Preview on mobile" />
  <img src="https://github.com/SalvatoreNoschese/AESBin/raw/main/screenshots/07-mobile-share.png" width="23%" alt="Share viewer on mobile" />
  <img src="https://github.com/SalvatoreNoschese/AESBin/raw/main/screenshots/08-mobile-settings.png" width="23%" alt="Settings on mobile" />
</p>

**Live demo:** [aesbin.salvatorenoschese.it/demo](https://aesbin.salvatorenoschese.it/demo),
a public read-only directory share with nothing to install. It is not a
sandbox but a real share on the instance that serves the downloads on my blog,
[salvatorenoschese.it](https://salvatorenoschese.it), which were on a Nextcloud
instance before AESBin existed.

---

## ⚖️ How it compares

| | encrypted at rest | dashboard | shared hosting | files & folders | closed uploads |
|---|---|---|---|---|---|
| **AESBin** | ✅ | ✅ | ✅ PHP | ✅ | ✅ single-user |
| PrivateBin | ✅ client-side | ❌ one-off pastes | ✅ PHP | ❌ text, optional attachments | ❌ open by default |
| XBackBone | ❌ | ✅ | ⚠️ PHP 8.4, queue worker and cron | ✅ | ✅ |
| Lufi | ✅ client-side | ⚠️ list kept in the browser | ❌ Perl | ❌ files, no folders | ❌ open by default |
| Nextcloud | ⚠️ built in, off by default | ✅ | ⚠️ heavy on shared hosting | ✅ | ✅ |
| Filestash | ❌ | ✅ | ❌ Go / dedicated | ✅ | ✅ |
| Chevereto | ❌ | ✅ | ✅ PHP | ❌ images and videos | ✅ |

As each project documents itself in October 2026.

**XBackBone** is the closest neighbour, and the difference is why this project
exists: it stores your files as they are. **PrivateBin** has the stronger
encryption model and remains the better choice for text snippets; what it does
not have is a dashboard, folders, or a way to update the content behind an
existing link.

Three things the table does not show: a share link whose **target, expiry and password can
all change while the URL stays the same**, no record of anything identifying
about the people who open those links, and an account-recovery path that stays
**closed** until someone with access to the server opens it.

---

## ✨ Highlights

- **AES-256-GCM** at rest, per-file keys wrapped by a master key; filenames,
  MIME types and descriptions encrypted too
- **Argon2id** (64 MiB, 3 iterations) for the master password, the recovery
  phrase and share passwords
- **Passkey login** via WebAuthn **PRF**, with automatic fallback to password + TOTP
- **TOTP is mandatory.** A 10-word recovery phrase can reset the password, the
  TOTP secret and itself, each independently. Recovery is **off by default**:
  opening it takes an edit to `config.php` over FTP/SSH plus a one-time code
  read from a file on the server, and it closes again after use. A stolen
  phrase is inert on its own
- **Share links** with the key in the URL fragment, optional password, expiry,
  and **retargeting**: point an existing link at a new file without changing it
- **Visitors are never identified**: no tracking cookie, no address or browser
  logged for anything they do on a share; one technical cookie per link, valid
  for an hour, so a reload neither asks for the password again nor counts as
  a new opening (details in [`SECURITY.md`](SECURITY.md))
- **Preview** for images, SVG, audio, video, PDF (pdf.js) and
  syntax-highlighted source code (Prism). Markdown is shown as source on
  purpose, since rendering it would make the reader's browser fetch whatever
  the document links to. In the dashboard, images, videos, audio and PDFs
  also list the metadata they carry, and whether the upload removes it, flagging a position or personal data
- **Metadata removal** before upload, in the browser (on by default, one tick
  to keep the original): GPS position and device details from JPEG, PNG and
  WebP, losslessly, and location, device and dates from MP4, M4V and MOV
  videos, without rewriting them; M4A audio loses only its location. Other
  formats are uploaded as they are, and the upload area says so
- **Uploads checked by their content**: a file named for another format gets
  its real extension back, and anything outside a short list of everyday
  formats is refused, to be uploaded as a .zip
- **Guarded installer**: finishing the install requires reading a file on the
  server over FTP/SSH, which closes the takeover window every web installer has
- Self-hosted **ALTCHA** proof-of-work captcha, per-request **CSP nonce**,
  `__Host-` cookies, per-tab session isolation, independent rate-limit buckets
- **No Composer, no npm, no build step, no CDN**: upload the files and run
- Italian, English, Spanish, French, German

The full list, with the reasoning behind the non-obvious choices, is in
[docs/features.md](docs/features.md).

---

## 📋 Requirements

| Component | Minimum |
|---|---|
| PHP | 8.3+ with `sodium`, `openssl`, `pdo_mysql`, `fileinfo`, `mbstring`; `curl` optional (update check only) |
| Database | MySQL 5.7.8+ / MariaDB 10.3+ |
| Web server | Apache with `mod_rewrite` and `AllowOverride All`, or nginx/Caddy with the [supplied rules](docs/installation.md#running-behind-nginx-or-caddy) |
| Web root | `public/` where the host lets you choose it; otherwise the whole project as it is, and the top `.htaccess` maps requests into `public/` |
| HTTPS | **Mandatory**: the app refuses to boot without it |

## 🚀 Install

1. Download the [latest release](https://github.com/SalvatoreNoschese/AESBin/releases/latest)
   (the *Source code* zip) and upload what is inside its `AESBin-<version>/`
   folder to your server: only the site, nothing else. Point the domain at `public/` if
   your host lets you choose the folder; otherwise upload everything to the
   web root as it is ([details](docs/installation.md#where-the-files-go)).
2. Create an empty MySQL database.
3. Open `https://yourdomain.com/`. With no `config.php` yet, it redirects to
   the installer.
4. Unlock the installer: it writes a randomly named file into
   `app/setup/_viewme/`
   and asks for the 8-character code inside it. Read it over FTP/SFTP/SSH:
   being able to read that file is what proves the server is yours.
5. Check the requirements screen (extensions, PHP limits, web-server details).
6. Follow the wizard (database, storage folder, master password and TOTP,
   recovery phrase), then log in.

Detailed steps, the `config.php` reference and the nginx/Caddy rules are in
[docs/installation.md](docs/installation.md). To install a newer release
later, see [docs/updating.md](docs/updating.md): keep `config.php` and the
storage folder.

---

## 📚 Documentation

- [Installation & configuration](docs/installation.md): server requirements,
  the wizard step by step, nginx and Caddy rules, and the main `config.php`
  options
- [Updating](docs/updating.md): installing a new release while keeping
  `config.php` and the storage folder
- [Security model & threat model](docs/security-model.md): what the encryption
  covers, what it does not, and how the recovery gate works
- [Features in full](docs/features.md): what is there, with the reasoning
  behind the less obvious choices
- [Architecture](docs/architecture.md): the file tree and what each part does
- [Vendored libraries](docs/vendored-libraries.md): the six libraries, their
  versions and licences, and how they are updated
- [Maintaining](docs/maintaining.md): for anyone changing the code, the traps
  to avoid
- [Contributing](CONTRIBUTING.md) · [Security policy](SECURITY.md) · [Changelog](CHANGELOG.md)

## 🧭 Design choices

<details>
<summary><b>Why AGPL and not MIT?</b></summary>

Most of GitHub is MIT because most of GitHub is libraries, and a library lives
on adoption. AESBin is an application, and the risk it faces is different: not
that someone copies the code, but that someone runs it as a service with
changes nobody can see, such as added telemetry, an extra key or one more
recovery path. The GPL does not cover that, because serving
software over a network is not distribution. The AGPL does. For a tool whose
only promise is that nobody but you reads your files, being able to audit the
code that is actually running *is* the feature. Nextcloud, Mastodon, Bitwarden
and Grafana are AGPL for the same reason. The cost is real: some companies
forbid AGPL outright, so there will be fewer corporate users and
fewer contributions from people who work at them.

</details>

<details>
<summary><b>Why no Composer?</b></summary>

Because the target is shared hosting with FTP and no shell, where Composer
cannot be installed: the promise is that you upload the files and it runs.
The six vendored libraries are self-contained, with no
transitive dependencies to resolve, and four of the six are JavaScript, which
Composer does not handle anyway. `bin/fetch-vendor.sh` updates all six and
reports when they drift from the declared versions. Adding Composer would cover
a third of them and leave two update systems instead of one; it would start
making sense with a PHP dependency that has dependencies of its own.

</details>

## 📄 License

**AGPL-3.0.** You may use, study and modify AESBin. If you distribute a modified
version **or offer it as a hosted network service**, you must release the
corresponding source under the same license. Full text in [LICENSE](LICENSE).

Vendored library authors retain their respective copyrights; see the license
files under `app/vendor/` and `public/vendor/`.

## 👤 Author

Built by [Salvatore Noschese](https://about.salvatorenoschese.it/).

If AESBin is useful to you, [support the project](https://donate.salvatorenoschese.it/).
