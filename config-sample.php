<?php

/**
 * config-sample.php — AESBin configuration template
 *
 * The installer writes config.php for you. This file documents every key,
 * for writing one by hand: copy it to config.php and fill in your values
 * (give the storage folder and the table prefix a random suffix of your own).
 * NEVER commit config.php to version control.
 */

declare(strict_types=1);

defined('AESBIN_APP') || exit;

return [
    // ── Database ──────────────────────────────────────────────────────────────
    'db' => [
        'host'   => 'localhost',
        'port'   => 3306,
        'name'   => 'your_database',
        'user'   => 'your_user',
        'pass'   => 'your_password',
        // Table prefix. The random suffix keeps several installations apart in one
        // database and the table names unguessable to anything else on it.
        // Example: aesbin_a3f2c8d1_
        // WARNING: changing this after install breaks everything.
        'prefix' => 'aesbin_xxxxxxxx_',
    ],

    // ── Storage ───────────────────────────────────────────────────────────────
    // Absolute path where encrypted file blobs are stored. The installer puts
    // it next to public/, under a randomly named directory: out of the web
    // root with either layout. Keep it outside public/, where it would have a
    // URL (the installer refuses it, the dashboard warns about it); anywhere
    // else PHP can write is fine.
    'storage_path' => __DIR__ . '/_storage-xxxxxxxx',

    // WebAuthn Relying Party ID: the domain your passkeys belong to. A
    // browser only offers a passkey when this matches the site you're on.
    //
    // Format: a bare domain, no https://, no port, no trailing slash.
    //   'vault.example.com': works only there (narrowest, the default)
    //   'example.com':       works on every subdomain too (use if you
    //                         reach the site as both example.com and
    //                         www.example.com)
    //
    // The installer fills this in with your hostname. Empty falls back to the
    // request's Host header, which is not an attack (a forged Host breaks
    // login, it cannot hijack it) but leaves a site reachable under two names
    // handing out passkeys under one and failing under the other. Must be a
    // domain, never an IP ('localhost' is fine).
    //
    // WARNING: changing this doesn't delete existing passkeys, it makes
    // them invisible. They come back if you restore the old value.
    'webauthn_rp_id' => '',

    // ── App ───────────────────────────────────────────────────────────────────
    // HTTPS is required: the app refuses to boot without it (see bootstrap.php).
    // HTTPS is detected automatically from the request and, when configured,
    // from trusted reverse proxies. Set this to true only to force HTTPS mode
    // when the actual HTTPS setup cannot be detected correctly.
    'force_https' => false,

    // For nginx, Caddy and anything else that does not read .htaccess: set
    // true once everything the shipped .htaccess does is in your SERVER
    // config: the deny rules and the 403 error_page, both in
    // docs/installation.md. AESBin cannot read that file, so this is how it
    // learns those rules exist.
    //
    // Ignored on Apache and LiteSpeed, where the shipped .htaccess works and
    // the check reads the server instead. Left false there on purpose: a
    // value stored today would keep asserting itself after a move to nginx,
    // when it would no longer be true.
    'server_rules_configured' => false,

    // External Markdown editors offered when previewing a .md file, as
    // 'Name' => 'https://…'. Empty means the preview explains why it shows the
    // source and suggests nothing: AESBin cannot vouch for a third-party
    // site, so the choice is yours. https only; anything else is ignored.
    //
    //   'markdown_tools' => ['WebTools' => 'https://tools.salvatorenoschese.it/markdown'],
    //
    // That one is run by AESBin's author: it converts in the browser, with no
    // ads and no tracking. It is an example, not a default; any editor works,
    // and shipping none is the deliberate default.
    'markdown_tools' => [],

    // Interface language: 'en' | 'it' | 'de' | 'es' | 'fr'
    'locale'  => 'en',

    // Show real PHP errors in the page instead of the generic message. Useful
    // on shared hosting where the PHP error log is out of reach. Keep false in
    // production: errors carry absolute paths, file names and sometimes
    // fragments of queries. While it is true the dashboard shows a banner
    // saying so, and nothing turns this one off by itself.
    'debug'   => false,

    // Account recovery gate. "yes" enables the 10-word recovery phrase;
    // ANYTHING else ("no", true, 1, a typo, a missing key) disables it.
    //
    // A string compared with ===, not a boolean: in PHP the string "false" is
    // truthy, so 'recovery' => "false" would switch recovery ON while reading
    // as off. Every mistake fails toward disabled.
    //
    // Leave it "no". The phrase can reset the password, the TOTP secret and
    // itself, so while this is "yes" only the one-time code in the storage
    // folder stands between a stolen copy of it and the account. With "no"
    // the recovery endpoints refuse before looking at anything.
    //
    // To recover: set "yes" over FTP/SFTP/SSH, run the recovery, and it
    // returns itself to "no" on success. Editing this file is the proof that
    // the server is yours; it is deliberately not settable from the
    // dashboard, where a stolen session would be enough to open the gate.
    'recovery'       => 'no',

    // ── Security ──────────────────────────────────────────────────────────────
    'security' => [
        // Max failed logins per IP within login_window seconds. 0 = no per-IP limit
        // (the global cap on wrong TOTP codes always applies).
        'login_max_attempts'   => 5,
        // Lockout window in seconds (1800 = 30 min).
        'login_window'         => 1800,
        // Idle session timeout in seconds: 0 (no idle logout; 24 h unused still ends it),
        // 300, 600, 900, 1800 or 3600. Any other value means the default, 600 (10 min).
        // Above the server's session lifetime (session.gc_maxlifetime in php.ini, less
        // 5 min) the longest one that fits applies, as the Settings card shows.
        'session_idle_timeout' => 600,
        // Terminate session immediately if the client IP changes mid-session
        // (for IPv6, if it leaves its /64). Protects against session hijacking.
        // Off by default: on mobile data or a VPN the address changes, and every
        // change logs you out. Turn it on for a fixed connection.
        'session_ip_lock'      => false,
        // Reverse proxies allowed to set X-Forwarded-For, as IPs or CIDRs.
        // Empty = the header is ignored entirely and REMOTE_ADDR is used, which
        // is correct when PHP is reached directly. Behind Cloudflare, nginx or a
        // shared host that proxies, this MUST list your proxy layer. Otherwise
        // every visitor shares one rate-limit bucket (five failed logins from
        // anyone on the internet lock you out) and session_ip_lock can no longer
        // tell sessions apart. Example:
        //   'trusted_proxies' => ['173.245.48.0/20', '103.21.244.0/22'],
        'trusted_proxies'      => [],
    ],

    // ── Upload / storage limits ───────────────────────────────────────────────
    // Per-file upload cap in bytes. 0 = PHP server limit.
    'max_upload_bytes' => 20971520,     // default: 20 MB
    // Total storage quota for the whole instance in bytes. 0 = unlimited.
    'max_total_bytes'  => 10737418240,  // default: 10 GB

    // ── Activity log ──────────────────────────────────────────────────────────
    // One card per event type. Occurrences shown without expanding it (1, 3 or 5).
    'activity_log_preview'  => 3,
    // Occurrences kept per card, in storage and behind "show more" (5, 10, 25 or 50).
    'activity_log_item_cap' => 10,

    // ── CAPTCHA ───────────────────────────────────────────────────────────────
    // ALTCHA: self-hosted PoW challenge, no external services, no API keys.
    'captcha' => [
        'enabled'              => true,
        // The "Protected by ALTCHA" attribution link under the challenge.
        // Shown by default as credit; set to false to hide it.
        'show_footer'          => true,
        // The small ALTCHA logo inside the widget itself. Shown by default as
        // credit, same as show_footer above; set to false to hide it.
        'show_logo'            => true,
        // Proof-of-work effort: 'low' | 'medium' | 'high'. Higher costs bots more
        // but takes longer to solve on your own device at login and recovery:
        // 'high' can pass 30 s on older hardware. Changeable from the dashboard;
        // anything else reads as 'medium'.
        'level'                => 'medium',
        // HMAC secret signing every challenge. Auto-generated by the installer;
        // to make one by hand: php -r "echo bin2hex(random_bytes(32));"
        // BOTH this and key_signature_secret below are required: if either is
        // missing, shorter than 64 characters, or identical to the other,
        // ALTCHA is inactive (login still works) and the dashboard shows an
        // amber notice. Saving any setting regenerates whichever one is wrong.
        'hmac_secret'          => '',
        // Second HMAC secret enabling fast server-side verification (one HMAC
        // instead of a full PBKDF2 re-derivation). Auto-generated alongside
        // hmac_secret above and just as required (see the note there). Made the
        // same way by hand, but never a copy of hmac_secret.
        'key_signature_secret' => '',
    ],

    // ── Privacy & cookie policy page ──────────────────────────────────────────
    // Public page at /privacy describing what this install collects (session
    // cookie, IPs in the activity log, encryption at rest). The default text
    // lives in app/lang/*.php; the privacy_* fields below identify the data
    // controller at the top of the page, always as text, never as HTML.
    'privacy_enabled' => false,   // false → /privacy 404s; true also needs privacy_name and privacy_email
    'privacy_name' => '',        // plain text, shown as the data controller at the top of the privacy page
    'privacy_address' => '',     // plain text, shown alongside privacy_name at the top of the privacy page
    'privacy_vat' => '',         // plain text (VAT/tax ID), shown alongside privacy_name at the top of the privacy page
    'privacy_email' => '',       // plain text; rendered as an obfuscated mailto: link, never as raw HTML

    // ── Contact link ──────────────────────────────────────────────────────────
    'contact_enabled' => false,  // master toggle for the public footer 'contact me' link
    'contact_link' => '',        // an email (obfuscated mailto: link) or an https:// URL such as a contact form; independent of privacy_email above
    'contact_referrer' => false, // link only: true tells the contact site the visitor came from here (origin only, never the page address)
];
