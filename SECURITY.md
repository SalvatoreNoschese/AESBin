# Security Policy

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Use GitHub's private [Security Advisories][adv] form. It opens a channel only
you and the maintainer can see, and needs no email address from either side.

[adv]: https://github.com/SalvatoreNoschese/AESBin/security/advisories/new

That form is for flaws in AESBin's code, which affect every installation. A
problem with one particular site running AESBin (a misconfigured server, an
exposed directory, content shared from it) is for whoever runs that site: the
maintainer has no access to it and cannot fix it. An installation at the
root of its domain publishes a `/.well-known/security.txt`: when it has two
`Contact` lines the first is the site's own, the advisory form is always the
last, and a comment above each says which is for what.

Include what you found, how to reproduce it, and what an attacker gains. A proof
of concept helps but is not required.

AESBin is maintained by one person in their spare time, so a reply may not be
immediate. If two weeks pass with no response, assume the message was lost
rather than ignored, and ping publicly without disclosing details.

## Supported versions

Only the latest release receives security fixes.

## In scope

Anything that breaks the guarantees in the [threat model](docs/security-model.md#threat-model):
recovering file content, filenames or MIME types without the master password, a
passkey or the recovery phrase; reaching the dashboard without valid
credentials; bypassing the elevation gate on a sensitive action; reading or
modifying a share without holding both its token **and** its key; XSS, CSRF, SQL
injection, path traversal, SSRF; and any rate-limit or captcha bypass that makes
brute forcing practical.

Also in scope: anything that makes a share **visitor** identifiable. Visitors
have no account, and nothing that could identify one (address, browser) is
stored where it could later be joined back to what they opened: not in the
activity log, not in the visitor-session table. The share page sets two
cookies at most: the visit's session, one per link, `__Host-` and HttpOnly
and valid for an hour (a random token, nothing about the visitor), and the
language code when the visitor picks a language from the menu. Within that
hour the browser stays unlocked on the link: whoever uses the same browser,
a shared computer included, opens it without the password. Addresses are still used
for rate limiting, in their own table and for two hours. A failed attempt on a
link (wrong key or password) is counted against that link, so for that window
the table does pair an address with the share it failed on; a successful
opening deletes that pairing, and nothing there records what was opened.

Two limits to that statement. The referring page, when the browser sends one,
is recorded with a visit: it describes how a link circulates, not who opened
it. And a request for a path that does not exist (`route.not_found`) is logged
with address and browser like any other non-share request, even when it comes
from someone who also holds a share link.

One consequence looks like a bug but is not: the per-share visit counter counts
*openings*, not people. Telling two visitors apart would require storing an
identifier, which is exactly what is avoided. A reload within the visit (the
link's session cookie, an hour in the same browser) is the same opening; the
same person coming back later, or from another browser, is a new one.

## Out of scope

- Attacks that assume the server is already compromised. During an active
  session the server unwraps the master key for each request; this is
  documented, not a bug.
- Attacks requiring physical access to an unlocked, logged-in device.
- Vulnerabilities in vendored dependencies that are already fixed upstream.
  Those are dependency updates rather than AESBin bugs, but report them anyway;
  pinned versions are listed in docs/vendored-libraries.md.
- Missing hardening headers with no demonstrated impact.
- Self-XSS, and anything that requires the owner to attack their own
  single-user instance.
- Social engineering, and scanner output with no manual verification.

## Accepted risks

Known, documented, and chosen on purpose:

- **Master key handled by the server during a session.** The PHP session
  holds it wrapped with a per-tab key, and each request unwraps it in memory.
  The trade-off that makes AESBin deployable on ordinary shared hosting.
- **The per-tab key travels in a request header.** Every authenticated request
  carries it as `X-Session-Key`, inside TLS. Whatever terminates TLS sees it:
  your own reverse proxy, a CDN, a WAF. That party already sees everything
  else in clear too (the password at login, every file uploaded or
  downloaded), so it is inside the trust boundary whatever AESBin does. The
  specific risk is a *log*: one that records request headers keeps the cookie
  and this key side by side, and anyone who reads it can replay them against
  the server as you until the session ends. `session_ip_lock` (off by default; on, it ties the session to the client's address),
  a short `session_idle_timeout` and logging out bound that window. Do not log
  request headers on the path to AESBin, or exclude this one.
- **Each password check costs 64 MiB.** Argon2id is memory-hard by design,
  and libsodium allocates that memory outside PHP's `memory_limit`. Attempts
  from one client are serialised, but many clients checking passwords at once
  can hold 64 MiB per busy PHP worker; the worker pool, the captcha and the
  per-client limits are what bound it.
- **Plaintext upload temp file.** PHP writes the uploaded file into its own temp
  directory before AESBin ever sees it, and therefore before encryption.
- **Retargetable share links.** A share URL is not a stable reference to a
  piece of content: the owner can repoint it at any time, from a logged-in
  session. That is the feature.
- **WebAssembly disabled in pdf.js.** JBIG2, CCITT Fax and JPEG 2000 decode
  through the pure-JavaScript decoders in `public/vendor/pdfjs/nowasm/` instead, so
  that `script-src` need not carry `'wasm-unsafe-eval'`. Those decoders are
  required, since they run in WebAssembly's place. Scanned documents render
  more slowly.
- **Wrong TOTP codes are limited globally, not only per client.** A code is
  six digits reached only with the correct password, so a guess spread across
  many addresses would make a per-client limit meaningless. The price: someone
  who already has your password can keep TOTP logins blocked for as long as
  they keep failing on purpose. Passkey login and recovery are unaffected; a
  recovery clears the block, and at that point the password is what needs
  changing.
- **The storage directory is out of reach by layout, not by a rule.** The installer
  puts the encrypted blobs next to `public/`, which has no URL with either
  layout: `public/` as the document root, or the top `.htaccess` mapping every
  request into `public/`. An `.htaccess` written into the storage directory at
  first boot denies it as well, on Apache. What is left: a storage directory
  edited into `config.php` inside `public/` on nginx or Caddy, which ignore
  `.htaccess` (the installer refuses such a path), or a
  server pointed at the project folder instead of `public/` without the top
  `.htaccess` being read. There, a blob is served to anyone who knows its
  filename. Size and existence leak, never content: the file is AES-256-GCM
  ciphertext, and `storage_name` is 128 random bits unrelated to the original
  name or upload order. The installer warns about a reachable path on a server
  that ignores `.htaccess`. No PHP application can protect a static path its
  web server has already decided to serve.
- **The login page shows whether a passkey exists.** The passkey button
  appears only when one is registered. Multi-user sites show it always because
  they cannot know who is signing in; with a single account that constraint
  does not apply. The bit revealed does not help an attacker: using a passkey
  still takes the device and its unlock, and without one the password and TOTP
  remain the only way in, as they would be anyway. For the same reason the
  login challenge lists the registered credential IDs and their PRF salts to
  any visitor of the login page: a browser needs them to pick the right
  passkey. Neither is secret: an ID only names a credential, and a salt is
  useless without the authenticator that holds the key.
- **Contact email obfuscation stops bots, not people.** The optional "Contact
  me" address is hex-encoded and reversed on the server, and a small script
  rebuilds the `mailto:` link in the browser; there is no `<noscript>` copy.
  That defeats page scrapers and the standard decoders bots try, per
  [Spencer Mortensen's benchmarks](https://spencermortensen.com/articles/email-obfuscation/).
  Anyone who reads the few lines of JavaScript can still recover the address;
  no client-side technique can prevent that. Where that matters, point the
  link at a contact form instead (an `https://` address in the same field):
  then no address is published at all.
  The generated `/.well-known/security.txt` (at the domain root only) is the
  exception to the obfuscation: an email used there (the "Contact me"
  address, or the privacy page's email when no "Contact me" link is enabled
  and the page is published) is in plain text, since the format has no way
  to hide it. A contact form avoids that too.
- **A new browser tab has to log in.** The session key that wraps the
  MASTER_KEY in the PHP session lives in `sessionStorage`, which is per-tab,
  so a tab you open later has a valid session cookie but no key, and lands on
  the lock screen. A `BroadcastChannel` could pass the key between tabs, but it
  is shared by the whole origin: the public share viewer and `/privacy` could
  open it too, and a script injected into either could take the key from a
  logged-in tab. On one origin an injected script and a legitimate new tab look
  identical, so no message-passing scheme can tell them apart. One extra login
  is the price of the key never leaving the tab that created it.
- **The installer window is gated by a file only the server owner can read.**
  While `config.php` does not exist, the installer answers at the site's
  address (`public/index.php` hands every request to `app/setup/index.php`) to
  anyone, and accepts database credentials from the request body. Left open, that is a
  full takeover: a caller could point the installer, or its reconnect step
  `verify_existing`, at a database they control and have AESBin write a
  `config.php` pointing your installation at their infrastructure, with a
  `storage_path` of their choosing.

  What closes it: on first contact the installer creates `app/setup/_viewme/`,
  writes a randomly named `.php` file into it containing an 8-character code,
  and refuses to proceed (JSON endpoints included) until that code is
  submitted. Neither the code nor the filename appears in any HTTP response.
  Reading the code therefore requires filesystem access to the server: the
  folder is outside `public/`, so it has no URL. The stub's own refusal and
  the unguessable name are further layers for a server pointed at the project
  folder instead of `public/`. Every denial answers 403, so a correct guess at
  the filename looks the same as a wrong one.

  Two wrong codes are free; after that the wait escalates to 5, 10, 20 and 40
  minutes, capped at an hour, counted in a file since there is no database yet.
  A honeypot field and a JavaScript-populated field filter out untargeted
  scanners before they reach that counter, and nothing more: neither stops an
  attacker who reads the page. The residual risk: anyone who can already read
  files on your server can complete the install, and a host where PHP cannot
  write into `app/setup/` cannot create the guard at all (the installer says so
  and stops). The guard file is deleted as soon as `config.php` is written.
- **The installer refuses plain HTTP.** It runs before `bootstrap.php`, so it
  carries its own copy of the rule the app enforces at boot. The request must
  arrive over HTTPS, or carry a forwarded-protocol header from a reverse proxy
  that terminated it. That header is accepted as a *claim* rather than proof:
  it can be forged, but forging it only lowers the forger's own protection,
  and refusing it would make AESBin uninstallable on the shared hosting it
  targets. Local development is no exception: the application refuses plain
  HTTP at boot, so an install finished over it could not be used. Serve the
  development copy over HTTPS too (CONTRIBUTING.md).
