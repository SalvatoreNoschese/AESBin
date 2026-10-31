[← AESBin docs](README.md) · [Project home](../README.md)

# Installation

## Requirements

| Component | Minimum |
|-----------|---------|
| PHP | 8.3+, with `sodium`, `openssl`, `pdo_mysql`, `fileinfo`, `mbstring` extensions; `curl` is optional and used only by the update check |
| MySQL | 5.7.8+ or MariaDB 10.3+ |
| Web server | Apache with mod_rewrite and `AllowOverride All`, **or** nginx/Caddy with the rules in [Running behind nginx or Caddy](#running-behind-nginx-or-caddy) |
| Web root | `public/` where the host lets you choose it; otherwise the whole project in the web root, as it is (see [Where the files go](#where-the-files-go)) |
| HTTPS | **Mandatory**: the app refuses to boot without it (see [Security Model](security-model.md)). HSTS is left to the server, your choice: see [Design notes](security-model.md#design-notes). |

Recommended, not required: `upload_max_filesize` and `post_max_size` at or
above the per-file limit you configure, and `max_execution_time` 30 or
unlimited. `max_input_time` is the clock that runs while the upload is still
arriving. Left at its default of `-1` it follows `max_execution_time`, which
is what nearly every host does; only an explicit low value needs attention.
`memory_limit` is the one that needs arithmetic rather than a figure: an upload holds the file twice at once, plus about 16 MiB for the
request, so the largest file a given limit can handle is
`(memory_limit - 16M) / 2`. At 128M that is 56 MiB; at 256M, 120 MiB.

None of this has to be right before installing. AESBin reads the limits in
force, caps uploads at what they actually allow, and refuses a per-file limit
above that ceiling rather than accepting one it would then ignore. The
requirements card reports the shortfall, and lowering the per-file limit is as
valid a fix as raising the PHP ones.

If OPcache is enabled, AESBin needs some way to notice that `config.php`
changed: either a usable `opcache_invalidate()` or
`opcache.validate_timestamps=1`. With neither, edits to `config.php` have no
effect until PHP restarts, which includes opening the recovery gate. Shared
hosts that disable the OPcache API almost always leave revalidation on, so
this is a warning you are unlikely to see there.

The installer shows the whole list on its own screen before the first step,
and refuses to go further if a required item is missing; the recommended ones
appear as warnings and do not block it. After installation the same list is in
**Settings → Server requirements**. When something starts failing (a PHP
downgrade, a directory that stopped being writable, a proxy that hides the
visitor's address), a dot appears on the Settings icon and a banner at the top
of Settings points to it. Optional features that are simply unavailable (such
as the update check without `curl`) are shown greyed out and raise neither.
The database version is checked when the installer tests the connection, and
an older server is refused there. Both screens read
`app/includes/Requirements.php`, so they cannot disagree.

**One installation per domain.** Two AESBin cookies, the session cookie
`__Host-aesbin_session` and the visitors' language cookie `__Host-aesbin_lang`, use
the `__Host-` prefix, which browsers only accept with `Path=/`. (The third,
`__Host-aesbin_share_<token>`, holds a visitor's session on one link and
carries the link in its name, so it never clashes.) Two AESBin
installs in different folders of the same domain (`/a/` and `/b/`) would share
them: signing in to one would sign you out of the other, and a language picked
on one would apply to both. Give each one its own subdomain instead.

---

## Where the files go

Only `public/` is meant to be reached from the web: the pages, the CSS,
JavaScript and images, the libraries the browser loads, and the installer's
stylesheet and script (the installer itself is in `app/setup/`, outside `public/`).
The application (everything under `app/`: the code, the language files, the
PHP libraries, the installer), `config.php` and, by default, the encrypted
files sit one level
up. Two ways to set it up:

- **`public/` as the document root**, the better choice wherever the hosting
  panel (or your own vhost) lets you pick the folder a domain points to.
  Nothing outside `public/` has a URL at all, whatever the server reads or
  ignores.
- **The whole project in the web root, as it is**, for hosts that do not let
  you choose (Altervista, many shared plans). The `.htaccess` at the top maps
  every request into `public/`, so the URLs are the same (`/`, `/login`,
  `/assets/…`) and nothing outside `public/` can be reached either, even by a
  URL that names it. This needs Apache (or LiteSpeed) with `mod_rewrite`;
  without it the top `.htaccess` serves nothing at all, by design.

Both work at the root of a domain and in a subdirectory (`/aesbin/`). In a
subdirectory AESBin publishes no `/.well-known/security.txt`: that file
covers the whole domain and belongs to its root (see
[Features](features.md)).

## Install steps

1. **Download** the latest release (the *Source code* zip under Assets) and
   upload what is inside its `AESBin-<version>/` folder to your server, as
   described in [Where the files go](#where-the-files-go). It holds only the
   site: the documentation, development tools and CI stay in the repository.
   A `git clone` of a release tag works too; its extra files (`bin/`, `docs/`,
   the Markdown files) have no URL, so they are harmless there.
2. **Create a MySQL database** for AESBin (any name, any charset).
3. **Visit** `https://yourdomain.com/` in your browser. With no `config.php`
   yet, AESBin shows the installer there, whatever address you open.
4. **Unlock the installer.** It will tell you to open a file it has just
   written into `app/setup/_viewme/` on your server (its name is random) and to
   paste the 8-character code inside it. Use FTP, SFTP, SSH or your host's
   file manager. The code is never shown in the browser: being able to
   read that file is what proves the server is yours. The folder is
   deleted automatically when the install finishes.

   <img src="https://github.com/SalvatoreNoschese/AESBin/raw/main/screenshots/10-installer-guard.png" width="624" alt="The installer asking for the guard code" />
5. **Read the requirements screen.** It lists what the server provides, with
   the running PHP version, SAPI and web server at the top. Green throughout
   means nothing else to do; a red entry stops the wizard until it is fixed.

   <img src="https://github.com/SalvatoreNoschese/AESBin/raw/main/screenshots/11-installer-requirements.png" width="624" alt="The installer's requirements screen" />
6. **Follow the setup wizard**, five steps:
   1. **Database**: connection details, tested before you move on. The table
      prefix is generated for you. If the database already holds an AESBin
      installation, the wizard offers to reconnect to it instead (see below).

      <img src="https://github.com/SalvatoreNoschese/AESBin/raw/main/screenshots/12-installer-database.png" width="624" alt="Installer step 1: database" />
   2. **Storage**: the folder for the encrypted files, as a full absolute
      path. The suggestion sits next to `public/`, inside the application,
      under a random name: out of the web root with either layout. Any other
      folder PHP can write to works too, except one inside `public/`, which
      the wizard refuses: every file there would have a URL. A `storage_path`
      edited into `public/` later shows up as a warning in Settings → Server
      requirements.

      <img src="https://github.com/SalvatoreNoschese/AESBin/raw/main/screenshots/13-installer-storage.png" width="624" alt="Installer step 2: storage" />
   3. **Password and TOTP**: at least 12 characters, and a code from your
      authenticator app to prove it is set up.
   4. **Recovery phrase**: 10 words, generated for you. It has to be copied
      or downloaded before the wizard lets you continue: **keep it somewhere
      safe**.
   5. **Install**: creates the tables, wraps the keys, writes `config.php`.
7. **Log in** at `https://yourdomain.com/login`.

Nothing you type in the wizard is kept in the PHP session between steps: the
browser holds it and sends it once, with the install request. The session
keeps only hashes of the TOTP secret and the recovery phrase, which prove the
values sent back are the ones the server generated.

> ℹ️ **Keep `app/setup/` after installing.** It has no URL of its own: `public/index.php`
> hands it requests only while `config.php` is missing, so it goes quiet by
> itself as soon as the install writes one. It is
> the only way back in if you ever restore a database backup without its
> `config.php`: the reconnect step detects the existing tables and rebuilds
> the configuration around them. It is protected by the same guard-code check
> as a fresh install, so leaving it in place does not reopen the takeover
> window.

### Reconnecting to an existing installation

At install time AESBin records its storage path in the database (the
`storage_path` row of the `{prefix}meta` table). When step 1 finds an existing
installation, the reconnect form starts from that value, so the rebuilt
`config.php` points at the folder that holds the files rather than at a new
empty one. The field stays editable: if you have moved the folder since, type
the new path. No recovery phrase is asked for, since the guard code has
already proved you control the server.

The path is checked for being usable (absolute, writable), not for being the
right folder. That check happens at your first login, against the path the
database recorded (the `storage_path` row, written at install). When
`config.php` names another folder, the dashboard says so; if files are also
missing, the integrity banner names the path it looked in as the likely
cause. Nothing is deleted in that state: the integrity repair refuses to
remove the rows of missing files until the two paths agree. Correcting
`storage_path` in `config.php` is enough.

The dashboard keeps the row honest. When it is missing (a database restored
without that row) or differs from `config.php` (you moved the folder and
edited `config.php` to match), a banner says so and offers to record the
current path in one click, behind the usual password confirmation. It refuses
when the folder in `config.php` is missing half the files the database lists
or more: then `config.php` is the one that is wrong.
Reconnecting from the installer never writes the row.

Everything else in the rebuilt `config.php` starts from its default: the
privacy page, the "Contact me" link, upload and storage limits, session and
login settings, trusted proxies, `server_rules_configured`. They lived only in
the `config.php` that was lost. Set them again in the dashboard, or copy them
over from a backup of the old file if you have one. To avoid the situation,
back up `config.php` with the database (see [Updating](updating.md)).

> ⚠️ **Recovery is off by default.** `config.php` ships with
> `'recovery' => 'no'`, which makes the recovery endpoints refuse before they
> look at anything and hides the link from the login page. When you actually
> need to reset a forgotten password or a lost TOTP, set it to `'yes'` over
> FTP/SFTP/SSH, run the recovery, and it returns to `'no'` on its own.
>
> Opening the gate is not by itself a way in. `/recovery` then writes a file
> into a `_recovery/` folder in your storage directory, and asks for the code
> inside it before it will look at your phrase. That code is never sent to the
> browser, so you have to read the file the same way you edited `config.php`.
> There is no countdown on it, so take as long as your FTP client needs; only
> wrong codes are throttled.
>
> Both steps are proof that the server is yours, which is what stops a stolen
> recovery phrase from being enough on its own. Save the phrase anyway:
> without it, recovery is impossible even with the gate open. Once you are in,
> you can reset any combination of password, TOTP secret and the phrase
> itself. The reasoning is in
> [Security model](security-model.md#threat-model).

---

## The 403 page

Requests the deny rules refuse get whatever error page the server has, which on
LiteSpeed in particular is bare. AESBin answers them itself instead: the same
page as its 404 (logo, the address asked for, a way back, the footer), in the
visitor's language, served by `index.php` before the database is touched and
without writing to the activity log, since scanners probe these paths by the
thousand.

`public/.htaccess` ships the directive **live**, inside a block marked
`Custom 403 page`:

```apache
ErrorDocument 403 /index.php
```

The server then runs `index.php` in place of the refused request: the refused
address stays in `REQUEST_URI`, and `REDIRECT_STATUS` is `403`, set by the
server for this internal redirect (a client cannot send it). That is how
`app/includes/bootstrap.php` tells a refusal from an ordinary request
(`AESBIN_REFUSED`). Everything else on the page, the way back and the footer's
links, comes from the application as on any other page.

The path is resolved from the document root, not from the folder `.htaccess`
sits in, so it is already correct for an install at the root of a domain, with
either layout: with the project in the web root, `/index.php` is mapped into
`public/` like any other URL. An install in a subdirectory has to carry the
prefix:

```apache
ErrorDocument 403 /aesbin/index.php
```

The installer corrects the path, and nothing else: the comment around it is
left exactly as shipped, so an installation done by hand and one done through
the installer hold the same `.htaccess`, word for word. Installing by hand
(restoring a database export, copying `config-sample.php` into place) skips the
installer, and so does replacing `.htaccess` during an upgrade: both leave the
shipped root path in place.

One consequence for an install kept as a `git clone` in a subdirectory:
`public/.htaccess` is a tracked file, so after the installer (or the
dashboard's fix) has written the prefix, `git status` shows it as modified and
a later `git pull` or `git checkout` of a new tag stops on it. Run
`git stash` before the update and `git stash pop` after it, or
`git checkout -- public/.htaccess` and apply the fix again from the dashboard.
An install at the root of a domain never changes the file.

That is why the directive ships enabled rather than commented out. Of the two
ways the default can be wrong, this is the one that announces itself: the
requirements card on the dashboard compares the live path against the
installation's own base path and turns amber when they disagree, which also
raises the banner. Where `.htaccess` is writable and the marked block is still
there, the row carries a **Fix** button that makes the same one-line edit the
installer makes (after confirmation and the password); otherwise it shows the
line to paste by hand. A commented default would switch the page off in every
install, including the ones where it was working, and leave the row green,
because a deliberately disabled page is a legitimate state.

To keep the server's own error page instead, comment the line out. The row
disappears from the requirements card rather than turning green or amber: with
no directive there is no custom page to confirm, and choosing the server's own
is not a mistake to warn about.

Under nginx or Caddy the directive is inert, and refused requests get the
server's own page.

## Running behind nginx or Caddy

AESBin runs on any PHP 8.3+ server. nginx and Caddy do not read `.htaccess`,
so on those the layout does most of the work: **point the site's root at
`public/`**. Everything that must never be served (`app/`, `config.php`
and the installer's default storage directory) then has no
URL at all. Never point the root at the project folder itself on these
servers: nothing would map requests into `public/`, and every one of those
paths would be served.

What is left for the server config is the little `public/.htaccess` does
inside `public/`: hidden paths, editor leftovers and the front controller.
The installer is not in `public/` either: `public/index.php` runs it while
`config.php` is missing.

**The storage directory stays out of reach as long as it is outside
`public/`**, which is where the installer suggests it; a path inside
`public/` is refused by the installer, and one edited into `config.php` later
is flagged in Settings → Server requirements. Inside `public/` the encrypted
blobs are reachable by anyone who knows a filename. They stay AES-256-GCM
ciphertext, and `storage_name` is 128 random bits unrelated to the original
filename or upload order, so this is not a content leak, but the size and
existence of a file become observable to someone who already knows its
storage name. To change the location later, edit `storage_path` in
`config.php` and move the directory's contents.

The rules below assume AESBin at the root of the site: for a subdirectory
install, prefix every path (`^/aesbin/`, and so on). On nginx, regex
locations are tried in order and the first match wins, so they go above the
block that passes `.php` to PHP-FPM.

Once these are in place (the rules below), set
`'server_rules_configured' => true` in `config.php`. Nothing on the PHP side
can read your server config, so the dashboard keeps warning until you say
otherwise. On a server that ignores
`.htaccess` that one row is the whole story: the 403 check is not shown there,
because PHP would be asserting something nobody verified.

**nginx:**

```nginx
# public/ only: the application, config.php and the default storage
# directory are one level up and have no URL.
root /path/to/aesbin/public;

# 403 everywhere. Every rule below matches a PATH or a NAME PATTERN, never a
# file on disk, so a guessed name and a real one are refused identically and
# nothing here confirms that a generated filename exists.
#
# index.php is the only PHP code in public/: anything else ending in .php is
# a folder's empty listing stub or a stray file, and is refused rather than
# run. (Caddy below runs a folder's stub for the folder's own URL, as Apache
# does, and answers an empty page: nothing is listed either way.)
location ~ ^/(?!index\.php$).+\.php$                        { return 403; }
# Hidden paths, except .well-known (ACME certificate renewal).
location ~ /\.(?!well-known/)                               { return 403; }
# Editor leftovers, backups, dumps and the PHP error logs some hosts write
# into the site folder, as public/.htaccess lists them. An index.php~ is not
# a .php to PHP-FPM, so without this it would be served as plain text.
location ~ (\.(log|bak|old|orig|save|swp|swo|swn|kate-swp|tmp|rej|sql)|~|/error_log|/php_errorlog)$ { return 403; }

# Not indexed, like everything PHP answers (csp_header() sends the same
# header); here for the static files, as public/.htaccess does. At server
# level, so every location below inherits it. A location that sets its own
# add_header drops this one, so repeat it there. PHP responses get it twice
# with the same value, which is harmless.
add_header X-Robots-Tag "noindex, nofollow" always;

# public/.htaccess declares these as JavaScript; a stock mime.types may not.
# pdf.js (.mjs) is loaded as a module, and a module or module worker refuses
# to run under any other type, so PDF previews would fail. The empty types
# block makes default_type apply whatever mime.types says.
location ~ \.(?:mjs|cjs)$ { types { } default_type text/javascript; }

# security.txt has one address, /.well-known/security.txt: your own file
# there if you put one in public/, else the one AESBin generates. Root
# installs only (a subdirectory install publishes none).
location = /security.txt { return 301 /.well-known/security.txt; }

# Front controller: everything else goes to index.php.
location / { try_files $uri $uri/ /index.php?$query_string; }
```

**Caddy:**

```caddy
# public/ only, as in the nginx block.
root * /path/to/aesbin/public

# Same single status as the nginx block above; see the note there. Caddy's
# regular expressions have no lookahead, so the exceptions are a "not" line.
@blocked {
	path_regexp \.php$|/\.|\.(log|bak|old|orig|save|swp|swo|swn|kate-swp|tmp|rej|sql)$|~$|/(error_log|php_errorlog)$
	not path /index.php /.well-known/*
}
respond @blocked 403

# As in the nginx block: pdf.js (.mjs) runs as a module and needs a
# JavaScript type; file_server keeps a Content-Type already set.
@js path *.mjs *.cjs
header @js Content-Type "text/javascript; charset=utf-8"

# Not indexed, like everything PHP answers; here for the static files, as
# public/.htaccess does. "?" sets it only where the response has none, so PHP
# keeps its own.
header ?X-Robots-Tag "noindex, nofollow"

# security.txt has one address, as in the nginx block. Root installs only.
redir /security.txt /.well-known/security.txt permanent

try_files {path} {path}/ /index.php
php_fastcgi unix//run/php/php-fpm.sock
```

Both also need HTTPS, which AESBin enforces at boot either way (see
[Security Model](security-model.md)). Caddy provides it automatically; on nginx
supply a certificate and, if TLS is terminated upstream, list the proxy in
`security.trusted_proxies`.

---

## Reverse proxies

If you sit behind Cloudflare, a load balancer or an nginx front end, check the
activity log after your first few logins. If every entry carries the same
address, that address is the proxy: PHP is not being told who the visitor is,
so the rate limiter puts everyone in one bucket and `session_ip_lock` cannot
tell sessions apart. AESBin shows a dashboard banner when it detects this.

Most hosts avoid it entirely: LiteSpeed, Apache's `mod_remoteip` and
Cloudflare configured at the origin all restore the client address before PHP
runs, and there `trusted_proxies` should stay empty. Ask your provider for one
of those first; it fixes the problem for every application on the machine.

Where that is not available, name the proxy yourself in `config.php`:

```php
'trusted_proxies' => ['173.245.48.0/20', '10.0.0.0/8'],
```

Only then is `X-Forwarded-For` consulted, and only from those addresses;
otherwise any visitor could claim to be someone else and walk past both
defences. For Cloudflare this means listing their published IP ranges, which
change over time and have to be kept current.

---

## Browser cache and CDNs

On Apache and LiteSpeed `public/.htaccess` lets browsers keep CSS,
JavaScript and images for a year. It is safe because every such URL ends in
`?v=<file date>`: an update changes the date and therefore the URL, and the
browser fetches the new file by itself. Nothing needs clearing.

The one setup that defeats this is a CDN told to ignore query strings when
caching (Cloudflare's "Caching level: Ignore query string", and equivalents
elsewhere): it would treat the old and new URL as one file and keep serving
the old one. Leave query strings in the cache key (Cloudflare's default,
"Standard", does), or purge the CDN's cache after each update.

## Configuration

After installation, `config.php` in the project root holds all settings. The full list of available options with descriptions and defaults is documented in `config-sample.php`.

Key options:

```php
// Custom storage path: any directory writable by PHP.
// Default: a randomly named _storage-xxxxxxxx directory next to public/
// (out of the web root), chosen by the installer
'storage_path' => '/absolute/path/to/your/storage',

// Interface language: 'en' | 'it' | 'de' | 'es' | 'fr'
'locale' => 'en',

// Leave false. Set it only when TLS is genuinely in place but detection
// cannot see it. It does not add HTTPS, it makes AESBin assume it.
'force_https' => false,

// Account recovery gate. "yes" permits the 10-word recovery phrase (a code
// from a file on the server is still required at the moment of use); anything
// else (a missing key, a boolean, even the string "false") disables it.
// Not settable from the dashboard, and returns to 'no' by itself after a
// successful recovery.
'recovery' => 'no',

'security' => [
    'session_idle_timeout' => 600,    // Auto-logout after N seconds of inactivity. 0 = disabled.
    'session_ip_lock'      => false,  // Bind session to client IP address (IPv6: its /64). Off: mobile/VPN users.
    // Addresses allowed to speak for the client. Governs BOTH X-Forwarded-For
    // (for rate limiting and IP lock) and the forwarded-protocol headers used
    // by the HTTPS check. Empty = trust nothing, use REMOTE_ADDR only.
    // List every range your proxy uses, e.g. all of Cloudflare's published
    // ranges, not a sample of them.
    'trusted_proxies'      => [],
],

'captcha' => [
    'enabled' => true,   // Self-hosted ALTCHA PoW captcha on login and recovery.
    'show_footer' => true, // "Protected by ALTCHA" credit link. Set false to hide it.
    'show_logo' => true,   // Small ALTCHA logo inside the widget. Set false to hide it.
    // Proof-of-work effort: 'low' | 'medium' | 'high' (default 'medium').
    // 'high' is altcha.org's recommendation; can reach 30 s on older hardware.
    // Adjustable from the dashboard at any time.
    'level'                => 'medium',
    // Both secrets are auto-generated by the installer and BOTH are required:
    // if either is missing, shorter than the 64 characters a genuine one
    // always is, or identical to the other, ALTCHA is inactive and login
    // still works; the dashboard shows an amber notice. Saving any setting
    // regenerates whichever one is wrong.
    'hmac_secret'          => '…',
    // Enables fast server-side verification (one HMAC instead of a full PBKDF2
    // re-derivation). Auto-generated alongside hmac_secret above and just as
    // required (see the note there).
    'key_signature_secret' => '…',
],

// External Markdown editors offered when previewing a .md file, as
// 'Name' => 'https://…'. Empty ships no suggestion at all: a Markdown preview
// would have to fetch the images and links the document names, so AESBin
// shows the source and explains why. https only.
'markdown_tools' => [],

// Set true once the nginx or Caddy deny rules are in your server config.
// AESBin cannot read that file, so this is how it learns they exist. Ignored
// on Apache and LiteSpeed.
'server_rules_configured' => false,

// WebAuthn Relying Party ID. The installer sets it to your hostname; empty
// falls back to the request's Host header. Changing it hides every passkey
// registered under the old value until you restore it.
'webauthn_rp_id' => '',
```

The idle timeout cannot outlast the server's own session lifetime,
`session.gc_maxlifetime` (PHP's default is 1440 seconds, 24 minutes). AESBin
raises it while it runs, but a cron job (cPanel's, Debian and Ubuntu's
`sessionclean`) and PHP's cleanup started by any other site sharing the
session folder use the server's value, and delete a session idle for longer.
So the Settings card shows that limit next to the field and greys out the
timeouts that do not fit: each needs the timeout plus five minutes, and
"Disabled" a day. A longer one in `config.php` applies as the longest that
fits. To offer more, raise `session.gc_maxlifetime` in the server's PHP
configuration (on cPanel, the MultiPHP INI Editor).

Everything else (upload limits, rate limiting, the activity log, the privacy
page, the contact link) is edited in **Settings** and written back here by the
dashboard. `config-sample.php` documents every key with the same comments this
file uses, and is the reference when writing `config.php` by hand.

---

[← AESBin docs](README.md) · [Project home](../README.md)
