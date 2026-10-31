[← AESBin docs](README.md) · [Project home](../README.md)

# Features

## 🔐 Security & Encryption

- **AES-256-GCM** server-side encryption with a fresh random IV per file
- **Per-file keys** (DEKs), each wrapped by the installation's master key
- **Argon2id** key derivation for your master password and recovery phrase,
  expensive for brute-force and GPU attacks. Share-scoped subkeys use a fast
  keyed hash instead, which is the right tool for a key that is already fully
  random; [Security model](security-model.md) explains why.
- **Share links via URL fragment**: the decryption key never appears in the
  requested URL or the server access log. It does stay in the address bar and
  the visitor's browser history, like any link. See
  [Security model](security-model.md) for what this does and does not protect
  against.
- **Passkeys** (WebAuthn/FIDO2) with the PRF extension: the passkey itself
  derives the key that unlocks the vault. Support depends on the browser and
  authenticator ([current picture](security-model.md#design-notes)); where PRF
  is missing, login falls back to password + TOTP.
- **TOTP** two-factor authentication, mandatory, with any authenticator app;
  the installer suggests [Stratum](https://stratumauth.com), which is open
  source
- **10-word recovery phrase** for account recovery, TOTP reset and its own
  rotation, **disabled by default**. Enabling it means editing `config.php` on
  the server, it switches itself off after a successful recovery, and even when
  enabled it asks for a code from a file that is never sent in any response.
  Every recovery also removes all passkeys and ends all sessions. The phrase
  is accepted in any letter case and with any spacing or line breaks between
  the words. Details in [Security model § Threat model](security-model.md#threat-model),
  "Recovery phrase".
- **Active sessions**: see every logged-in device and revoke any of them
- **Elevation gate** ("step-up auth"): revealing the TOTP secret, managing
  passkeys, changing security settings and permanently destroying data ask for
  the password again, even inside a logged-in session. File and share actions
  do not; [§ Elevation](security-model.md#elevation) explains the line.
- **Per-tab session isolation**: the key that unlocks your vault lives only in
  the tab that created it. It travels with each request but is never kept in a
  cookie, on the server or in another tab, so each tab logs in on its own
- `__Host-` session cookie (requires HTTPS)
- Per-request **CSP nonce**: no `unsafe-inline`, no `unsafe-eval`
- Self-hosted **ALTCHA** proof-of-work captcha: no API key, no external
  service, no tracking. Three effort levels (low / medium / high) so the
  owner can calibrate solve time to their hardware. A benchmark button in
  the dashboard measures the time on the current device before committing.
  The footer credit link and the widget's small logo can each be shown or
  hidden independently. Both HMAC secrets are required: if either is absent,
  shorter than the 64 characters a genuine one always is, or identical to the
  other, ALTCHA is inactive and login still works; saving any setting
  regenerates whichever one is wrong
- **Independent rate-limit buckets** for password login, passkey login,
  elevation, recovery and share access, each counted per client (the address
  for IPv4, the /64 network for IPv6, since one IPv6 client routinely holds a
  whole /64):
  - Failing at one never freezes the others. A forgotten password must not
    also lock out the passkey that still works, or the actions you need when
    you suspect a compromise (revoking sessions, removing passkeys).
  - Getting in clears the slate: a successful login empties that IP's buckets,
    and opening a share with the right key and password clears its bucket, so
    a recipient who mistyped a few times (or an office behind one NAT address)
    is not left locked out.
  - Lockout state lives in its own table, not in the activity log, so emptying
    the log never lifts an active block. Failed attempts stay visible in the
    log either way.
  - Per-client limits stop a single-source script, not a distributed attack.
    Against many addresses the real defence is Argon2id.
  - Wrong TOTP codes are the exception, counted across all clients as well:
    a code has a million values and is only reached with the right password,
    so a botnet would otherwise turn a per-client limit into none. Someone
    holding your password can therefore keep TOTP logins blocked for as long
    as they keep failing; passkeys and recovery keep working.
- Honeypot anti-bot field (server-generated, invisible to users)
- **Guarded installer.** Between uploading the files and finishing the
  install, whoever reaches the URL first could point the installation at a
  database they control and take it over. AESBin closes that window: the
  installer writes a randomly named file into `app/setup/_viewme/` containing an
  8-character code, and asks for that code before anything else. Neither the
  code nor the filename is ever sent to the browser, so continuing requires
  FTP/SSH/file-manager access to the server. The reconnect step (for a
  database restored without its `config.php`) sits behind the same gate. The
  file is deleted once installation succeeds, and `config.php` is written
  atomically, so a failed write can never blank it and reopen the installer.
- The installer **refuses plain HTTP**: the master password, TOTP secret and
  recovery phrase all cross the wire on that page, and the application itself
  refuses to run without HTTPS

## 📁 File Management

- **Container → Directory → File** hierarchy (flat: no nested directories)
- Upload, rename, move, trash, restore, permanently delete
- **Uploads are checked by their content.** The accepted formats (listed in
  the upload area) are recognised from the content, never from the name: an
  extension is one only if the list holds it. A listed one that names another
  format is replaced (a ZIP named `photo.png` becomes `photo.zip`, and the
  upload says so); anything else after a dot is part of the name, which stays
  whole and gets the right extension added (`File.v2` holding a PNG becomes
  `File.v2.png`, `Invoice no. 12` holding a PDF `Invoice no. 12.pdf`). An
  extension left twice is kept once: `backup.zip.txt` holding a ZIP becomes
  `backup.zip` (mail gateways add such a `.txt`). Renaming reads extensions
  the same way, and both store them in lower case (`IMG_0001.JPG` is stored as
  `IMG_0001.jpg`). Another spelling (`.jpeg`) keeps its name, and so does a
  format built on a container other formats share (an `.apk` or `.xpi` read as
  a plain ZIP, an `.appimage` read as a Linux program). A shared type alone
  never gives a file one of those names: a plain ZIP named otherwise gets
  `.zip`, and a Linux program named otherwise is refused. Text whose name has
  no listed extension gets one: `README.md`, `LICENSE.txt`, `.htaccess` as
  `htaccess.txt`, `script.pl` as `script.pl.txt`. Any other format is refused:
  zipped, it uploads, and so does a text file the content check cannot read as
  text (UTF-16 without a byte-order mark, text holding NUL bytes): the type
  the browser declares is never used, since browsers derive it from the name.
  The list holds what AESBin previews or edits, plus everyday documents,
  archives, installers and fonts; formats with no preview in every browser
  (camera RAW, TIFF, HEIC, MKV, AVI) are left to a .zip on purpose. Previews
  go by the stored type and a per-format flag. For text, which libmagic cannot
  tell apart (a `.py`, a `.md` and a `.json` all read as plain text), that
  type comes from the listed extension, as does the syntax highlighting.
- **Filenames stay yours**, spaces, capitals, accents, emoji and common
  punctuation (`-`, `+`, `( )`, `[ ]`, `&`, `,`) included; on the disk every
  file has a random name of its own, so yours never becomes a path. What
  causes trouble goes, following the OWASP File Upload Cheat Sheet and
  Windows' rules, the strictest a download meets:
  - control characters, bidirectional overrides (a hidden right-to-left mark
    can make `…exe.jpg` out of a program) and the invisible characters that
    let two names look the same (zero-width space, byte-order mark, soft
    hyphen, word joiner, Hangul fillers). The joiners emoji and some scripts
    need, and emoji colour selectors, stay: `👨‍👩‍👧.png` keeps its name;
  - the characters Windows refuses in a name, `< > : " / \ | ? *`, each run
    replaced by `_`, since the download would fail or be renamed on the way
    to the disk anyway (`The "quote".txt` becomes `The _quote_.txt`).
    A `/` or `\` is never read as a folder: `Invoice 1/2026.pdf` becomes
    `Invoice 1_2026.pdf`, not `2026.pdf`;
  - runs of dots, leading and trailing spaces and trailing dots, and, on
    upload, leading dots (`.htaccess` is uploaded as `htaccess.txt`; a
    rename to a name with a leading dot is refused instead);
  - a Windows device name gets a leading `_` (`lpt1.md` becomes `_lpt1.md`,
    and so does `.nul.txt`, as `_nul.txt`). The device names are `CON`,
    `PRN`, `AUX`, `NUL`, `COM1`–`COM9` and `LPT1`–`LPT9` (plus `COM¹`,
    `COM²`, `COM³`, `LPT¹`, `LPT²`, `LPT³`), in any case and whatever
    follows the first dot, so `nul.tar.gz` counts too; Windows cannot create
    a file with one of them
    ([Naming Files, Paths, and Namespaces](https://learn.microsoft.com/en-us/windows/win32/fileio/naming-a-file)).
    Other systems have no such names, and the rest is left alone: `lpt.md`,
    `COM0.txt` and `CONSOLE.txt` keep their names.

  A name holds at most 200 characters and 255 bytes, extension included
  (fewer characters with emoji or scripts that take more bytes each), and a
  `_01` added for a name already taken stays within that. A cut never
  leaves a trailing space or dot, nor two dots in a row. A compound
  extension counts as one: `archive.tar.gz` is followed by
  `archive_01.tar.gz`. All of this happens when a name is stored, on upload,
  in "Create file" and on rename, never on download, and the rules apply
  until the name stops changing, so cleaning a clean name changes nothing.
  A name not kept as typed is followed by one message, from the name typed
  to the name stored, with every reason.
- Optional multi-line description on files and directories, with a small
  formatting toolbar (bold, italic, strikethrough, quote, code, https link)
- Filename search (names are decrypted once, again after each change to the
  files, and matched in the browser)
- Text files are edited in the browser and saved back as they were: same
  encoding, same byte-order mark, same line endings. Previews name them next
  to the Copy button, and the editor can convert a file to another
  ([Text encoding and line endings](#-text-encoding-and-line-endings)).
  Closing the editor with Escape, a click outside it or the browser's Back
  asks first while it holds unsaved text; the ✕ closes it at once
- Trash with one-click restore, and **Empty trash** to delete everything in
  it for good after one confirmation (with the count and the total size).
  It runs the same permanent delete as each file's own button, one file at a
  time: the same password check, a log entry per file, and nothing left half
  done if it stops. Trashed files count towards the storage quota until then;
  nothing empties the trash on its own.
- Activity log: one card per kind of event, with a configurable number of
  recent occurrences shown and kept, and a manual "flush now". Requests for
  pages that do not exist and failed attempts before login, the events that
  record addresses of people other than the owner, also expire after 90 days.
  A failed sign-in says where it failed: a wrong password, or the right
  password with a wrong TOTP code, shown in orange because whoever tried
  knows the password
- Moving a file to the trash **permanently destroys the file-type share links
  pointing at it**, so a trashed file disappears from its public URLs at once.
  Restoring it does not bring those links back. Each destroyed link is written
  to the activity log and the count is reported, so the loss is never silent.
- **Checksums** (CRC32, MD5, SHA-1, SHA-256, SHA-512) computed on upload, with
  one-click copy and a VirusTotal lookup link
- **Photo metadata removal** on upload, ticked by default and visible under the
  upload area: GPS position, device, dates and editing software are taken out
  of JPEG, PNG and WebP in the browser, before the file leaves it, without
  re-encoding. Colour profile and rotation stay; a motion-photo video or HDR
  layer appended to a JPEG goes too. A photo that cannot be parsed is refused
  rather than uploaded with its metadata. Untick the box to keep the original.
- **Video metadata removal**, under the same box: MP4, M4V and MOV lose their
  user data (recording location, device, software, author, comments, titles)
  and the creation and modification dates in their headers. Only the moov
  index is read; each entry becomes a blank `free` box of the same size, so
  nothing else in the file moves and the video is never re-encoded. Rotation
  stays: it is part of the picture, not metadata. M4A audio loses only its
  location: title, artist and cover are what a song is shared with.
  Other files (PDF, office documents) are uploaded as they are,
  and a note in the upload area says so: they can carry an author name or a
  location of their own.
- **Metadata panel** in the dashboard's preview of every image, video, audio
  file and PDF: what the file carries (device, dates, software, position,
  author, serial number, XMP/IPTC blocks; a PDF's title, subject, keywords,
  programs and custom fields; an MP3, FLAC or M4A's tags and cover), read in
  the browser after decryption, and under it what the upload does with that
  format: cleaned (photos, MP4/MOV), location only (M4A), or left as it is. A
  format it cannot read (WebM, OGG...) says so instead of looking
  clean. It opens by itself
  when there is a position or personal data, so an upload made with the box
  unticked, or a file that predates it, shows what it would give away before
  it is shared. Share visitors never see it.

### 🔤 Text encoding and line endings

A text file is a run of bytes, and two things decide how they read as text:
the **encoding** (which bytes stand for which character) and the **line
ending** (which invisible bytes close a line). AESBin never changes either on
its own: the editor writes a file back in the format it found, and a text
preview names that format next to the Copy button ("Encoding: UTF-8",
"Line endings: LF (Unix)"), on share pages too.

| Encoding | What it is | When to pick it |
|---|---|---|
| UTF-8 | Today's standard: every language and emoji, one byte for plain letters | Always, unless something asks for another |
| UTF-8 (BOM) | UTF-8 with a three-byte mark at the start | A Windows program that insists on it. Scripts, PHP and configuration files can break on the mark |
| UTF-16 LE / BE | Two bytes or more per character, in either byte order, always with a mark | A tool that produces or expects it. About twice the size |
| Windows-1252 | The old Western European table of Windows, one byte per character: accents and the euro sign, no emoji, no other alphabets | An old program that requires it |

| Line ending | Used by |
|---|---|
| LF | Linux, macOS, the web |
| CRLF | Windows; a few files, such as `.bat`, need it |
| CR | Very old Macs only |

**How the format is recognised.** A byte-order mark says what the file is.
Without one, bytes that are valid UTF-8 are UTF-8, and anything else is read
as Windows-1252. A file in another single-byte encoding (Latin-1 is nearly
the same table; a Cyrillic or Greek code page is not) is therefore shown as
Windows-1252, possibly with the wrong letters, but every byte left untouched
is written back unchanged. A file that mixes line endings is saved with the
one most of its lines use.

**Converting.** The two menus under the editor show the format and convert
the file when another is chosen and saved. A new file is UTF-8 with LF unless
set otherwise. Conversion cannot be undone from AESBin: download a copy first.

**Menus that follow the text.** Nothing in a file states its format: it is
read off the bytes. The menus therefore show what the file would be found to
be if it were saved and opened again, and offer only what can be told apart
from it:

- Plain letters are the same bytes in UTF-8 and Windows-1252, and such a file
  is UTF-8. Windows-1252 is greyed out until the text has an accent or a
  symbol; a Windows-1252 file whose last accent is deleted shows UTF-8, and
  goes back to Windows-1252 when one is typed again.
- Windows-1252 is also greyed out while the text holds a character it has no
  byte for (an emoji, a letter of another alphabet). In a file that already
  is Windows-1252, saving such a character is refused with a message: remove
  it, or switch the file to UTF-8.
- A text with no line break has no line ending, and shows LF. CRLF and CR
  are offered once there is a break to apply them to.

A menu set by hand keeps that choice for as long as the text supports it;
one left alone follows the file, so passing through plain text never
converts anything by itself. While a menu shows something other than the
file's own format for this reason, a line under it says so: what the file
was, and what a save will leave it as.

## 👁️ File Preview

- Images (JPEG, PNG, GIF, WebP…)
- SVG: highlighted source, then the rendered graphic (drawn as an image, so
  any script inside it never runs)
- Audio and video in the formats every current browser plays: MP4, M4V,
  WebM and MOV video; MP3, M4A, AAC, OGG, Opus, WAV and FLAC audio. A file the
  browser still cannot play (an iPhone MOV in HEVC, on a computer without that
  codec) says so instead of showing a blank player
- PDF via pdf.js, locally, with no external service
- Markdown is shown as source, never rendered. A rendered document would make
  the reader's browser fetch the images and links it names, telling those
  sites the file was opened, from where, and when. `markdown_tools` in
  `config.php` can offer external editors in the preview; the list is empty by
  default, since the package cannot vouch for a third-party site.
- Source code with syntax highlighting: Prism.js core plus 33 components,
  covering every extension the editor offers except the plain-text formats
  with no grammar (`.txt`, contacts, calendars, subtitles, certificates; full
  table in [Vendored libraries](vendored-libraries.md))

## 🔗 Sharing

- Share entire directories or individual files
- **Retarget an existing share** to a different file or directory: the URL
  and key stay the same, only the target changes. Made for "people already
  have the link and I need to update the file": upload the new version, then
  point the link at it (or at a folder holding several versions).
- **Edit an existing link**: change its expiry, or add, replace and remove its
  password, without the URL changing. Neither takes part in key derivation, so
  a link already posted somewhere keeps working under the new rules. Setting
  or replacing a password also ends visitor sessions opened under the old one.
- Optional **password protection**
- A **QR code** for every new link (kjua)
- **Expiration**: 1 hour, 24 hours, 7 days, 30 days, or never
- **Expiring soon**: a link with less than 24 hours left gets a dot on the
  links icon, its expiry in orange, and a place in a card at the top of the
  Links page, so it can be extended before it goes. Nowhere else: no banner
  and no reminder on the other pages.
- **Statistics**, each number kept where it is true:
  - **Openings belong to the link**: shown on its share card, kept
    when the link is retargeted (that URL really was opened that many
    times), and written to the activity log when it is revoked or expires.
    An opening is a visit to the link, not a person (see
    [SECURITY.md](../SECURITY.md#in-scope)).
  - **Downloads and previews belong to the file**: the badges on
    its Download and Preview buttons count them through any link, a shared folder's
    included, and they survive revocation, expiry, moves and edits.
  - The badge on the Link button is the number of live links to that file or folder.
  - A **Share statistics** panel on the dashboard shows the live links and
    the **totals since installation** (openings, downloads, previews). They
    include deleted links and files, and emptying the log does not reset them. They refresh when
    you come back to the tab, not on a timer: every request renews the idle
    timeout, so polling would keep an unattended session alive.
  - Hovering a badge, or a long press on its button on a touch screen,
    shows what it counts. Each opening, preview and download is also in the
    activity log, numbered. A visit counts once: reloading or reopening the
    link in the same browser within the hour is not a new opening, and previewing or downloading the same
    file again in that visit is not counted or logged again.
- **Nothing opens on its own.** The share page never starts a preview by
  itself (a lone file shows its checksums instead), so a file's content
  appears on screen only when the visitor asks for it.
- Public viewer with no account needed, and a language switcher (the same 5
  languages as the dashboard)
- **Visitors are not identified.** No address or browser is stored for
  anything they do on a share. The visit is a session cookie for that link
  only (HttpOnly, invisible to the page's scripts, valid for an hour): within
  it a reload neither asks for the password again nor opens a new visit. On
  a password link an **Exit** button ends the visit at once, on the server
  too. The only other cookie is the language, if they pick one from the menu. Addresses are used for rate limiting only, kept
  for two hours and never paired with what was opened.
  The exceptions and consequences are listed in
  [SECURITY.md](../SECURITY.md#in-scope).

## 📄 Compliance & Public Pages

- Optional **public `/privacy` page**: generated text (session cookie, activity
  log retention, encryption at rest, how share links work) plus a
  data-controller block you fill in (name, address, VAT/tax ID, email). It is
  off until you enable it and enter a name and email.
- A generated **`/.well-known/security.txt`** (RFC 9116; the legacy
  `/security.txt` is a permanent redirect to it, so there is one address),
  with no file to create, so it works where `.well-known` cannot exist. Its
  `Contact` lines, in order of preference: the "Contact me" link when it is
  enabled and valid, else the privacy page's email when that page is
  published, and in every case the project's private GitHub advisory form
  last, for flaws in AESBin's code. A `#` comment above each one says which
  is for what: the site's contact for the site, the form for the code only
  (and, when the site has no contact, that it has none). `Policy` points at
  the project's `SECURITY.md`; `Preferred-Languages` is English plus the
  interface language set in `config.php` when it is another. `Expires` is
  recomputed on every request (six months ahead), so it never lapses; there
  is no `Canonical`, since the only source for it would be the request's
  `Host` header. An email used there appears in plain text: the format has no
  obfuscation, unlike the pages. A contact form address avoids that. To
  publish your own file instead (one you sign, for instance), put a
  `.well-known/security.txt` inside `public/`: the web server serves it and
  AESBin's is not used. A `security.txt` directly in `public/` is never
  served: `/security.txt` is redirected before any file is looked up
  (`public/.htaccess`; the nginx and Caddy rules are in
  [Installation](installation.md)). A file the host serves from outside
  `public/` (some panels map `/.well-known/` elsewhere) wins the same way,
  without AESBin being involved.
  Generated only when AESBin is at the root of its domain. RFC 9116 clients
  read the file from the domain's root and apply it to the whole domain, which
  an install in a subdirectory (`/aesbin/`) does not own: there neither path
  is answered, and the domain's own file, if any, is the main site's to write
  (add the advisory form line to it if you want AESBin's code covered). The
  installer says so on its last screen, and the settings hints about the file
  are not shown there.
- Optional **"Contact me" link** in the footer of every page but your
  dashboard (share pages, `/privacy`, login, recovery, 404), independent of
  the privacy page's email. It takes an email address, which never appears in
  plain text in the page source (see
  [SECURITY.md](../SECURITY.md#accepted-risks) for how, and the limits), or an
  `https://` address such as a contact form on your main site. In that address
  `{lang}` becomes the language the page is shown in (`it`, `en`...), so a
  multilingual form opens in the visitor's language. A form on another site
  opens in a new tab, like every link that leaves AESBin; one inside this
  installation, in the same tab. By default the link sends no referrer; an
  option lets the form see this site's origin (never the page address), to
  tell which messages come from AESBin.

## ⚙️ Administration

- Settings panel: language, session timeout, IP lock (off by default: on
  mobile data or a VPN every address change would log you out), captcha,
  security options
- Update notifications: checks GitHub for new releases, never auto-updates
- Interface in **Italian**, **English**, **Spanish**, **French**
  and **German**. Pages meant for visitors (share, privacy, 404) follow the
  visitor: the language picked from their menu, else the browser's. Only a
  pick from the menu sets the language cookie: the request must come from
  the page itself (`Sec-Fetch-Site: same-origin`, or a Referer naming this
  host on a browser without it). A `?lang=` in a link from elsewhere is
  dropped, so no link can choose the language for the visitor. The
  owner's pages (login, recovery, dashboard) use the language chosen in
  Settings.
- Activity log as a **historical record**: each entry keeps the name the object
  had at that moment, so a later rename or deletion does not rewrite it. Those
  names are stored encrypted like the originals and decrypted only when you
  read the log. A settings change records each setting with its old and new
  value; the privacy identity and the contact link only as set, cleared or
  changed, and the captcha secrets never.
- **Integrity checker**: compares the database with what is on disk and with
  the schema.
  - Storage: rows with no file, files with no row, files on the wrong side of
    the trash or in the wrong subfolder, the same file on both sides, and
    abandoned temp files at least an hour old. All repairable in one click.
    Rows of missing files are removed only while `storage_path` is the
    folder recorded for the installation: otherwise the files are more
    likely in the wrong place than lost.
  - Database: a missing `CHECK` constraint (repairable in one click), a table
    whose engine or collation has drifted, and more than one row in the
    single-user auth table. A `mysqldump`/phpMyAdmin export can silently drop
    the `CHECK` clause and the table options, so a restored database may differ
    from a fresh install in ways nothing else would show. The last two are
    reported with instructions rather than fixed automatically: converting a
    collation rewrites every row, and only a human can tell which auth row is
    the real one.

## 🏗️ Infrastructure

- Works on **shared hosting**: developed on ddev, tested on Altervista
- Works in the web root `/` or any subdirectory such as `/aesbin/`, with
  `public/` as the document root or the whole project uploaded as it is
- **No Composer, no npm, no build step**: upload and run
- **No CDN**: all vendor JS/CSS is served locally, so it works offline or on an
  intranet (the only outbound request is the update check, which then reports
  GitHub as unreachable)
- Reverse-proxy aware (Altervista, Cloudflare, nginx): `X-Forwarded-Proto` is
  honoured only from a proxy declared in `security.trusted_proxies`
- MySQL / MariaDB backend

---

[← AESBin docs](README.md) · [Project home](../README.md)
