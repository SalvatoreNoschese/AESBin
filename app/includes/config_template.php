<?php

/**
 * app/includes/config_template.php — renders and writes config.php
 *
 * Used by the installer and the settings save, so both produce the same
 * commented, human-editable file. Only the keys rendered here are written:
 * a new config key must be added here or it will not survive a save.
 */

declare(strict_types=1);

use AESBin\Requirements;

defined("AESBIN_APP") || exit;

/**
 * Factory defaults for the numeric and locale settings (see
 * docs/maintaining.md). render_config_php(), settings.php and dashboard.php's
 * <select> options read from here; config-sample.php keeps a hand-maintained
 * copy.
 *
 * The booleans are not here: session_ip_lock is off unless set (a missing
 * key reads as false), and captcha enabled has explicit logic in
 * render_config_php().
 */
const SETTINGS_DEFAULTS = [
    "login_max_attempts" => 5,
    "login_window" => 1800,
    "session_idle_timeout" => 600,
    "max_upload_bytes" => 20971520, // 20 MB
    "max_total_bytes" => 10737418240, // 10 GB
    "activity_log_preview" => 3,
    "activity_log_item_cap" => 10,
    "locale" => "en",
];

/**
 * Allowed values for the dropdown settings, keyed like SETTINGS_DEFAULTS.
 * Any other value comes from a hand edit. Keys absent here (the byte limits)
 * are free-range for setting_int().
 */
const SETTINGS_ALLOWED = [
    "login_max_attempts" => [0, 3, 5, 10],
    "login_window" => [300, 900, 1800, 3600],
    "session_idle_timeout" => [0, 300, 600, 900, 1800, 3600],
    "activity_log_preview" => [1, 3, 5],
    "activity_log_item_cap" => [5, 10, 25, 50],
];

/**
 * $cfg[$key] ($cfg being the full config or a sub-array such as
 * $cfg["security"]), or SETTINGS_DEFAULTS[$key] when it is absent, not an
 * int, or outside its SETTINGS_ALLOWED list.
 */
function setting_int(array $cfg, string $key): int
{
    $default = SETTINGS_DEFAULTS[$key];
    $val = $cfg[$key] ?? null;
    $allowed = SETTINGS_ALLOWED[$key] ?? null;

    // is_int, not a cast: (int) turns "1800foo" into 1800 and "" into 0, which
    // could pass the whitelist. Only a hand edit produces a non-int here.
    if (!is_int($val)) {
        return $default;
    }

    return $allowed === null || in_array($val, $allowed, true) ? $val : $default;
}

/**
 * Lang keys for duration options, keyed by seconds. Used only for
 * SETTING_DURATION_KEYS; other dropdowns show the number itself.
 */
const SETTING_DURATION_LABELS = [
    300 => "dur_5min",
    600 => "dur_10min",
    900 => "dur_15min",
    1800 => "dur_30min",
    3600 => "dur_1h",
];
const SETTING_DURATION_KEYS = ["login_window", "session_idle_timeout"];

/**
 * The <option>s for a dropdown setting, as ready-to-echo HTML built from
 * SETTINGS_ALLOWED and SETTINGS_DEFAULTS (see docs/maintaining.md).
 *
 * 0 renders as "Disabled": it means that in both lists containing it
 * (login_max_attempts, session_idle_timeout).
 */
function render_setting_options(string $key, ?callable $offered = null): string
{
    $out = "";
    foreach (SETTINGS_ALLOWED[$key] as $val) {
        if ($val === 0) {
            $label = __("disabled");
        } elseif (in_array($key, SETTING_DURATION_KEYS, true)) {
            $label = __(SETTING_DURATION_LABELS[$val] ?? (string) $val);
        } else {
            $label = (string) $val;
        }
        $sel = $val === SETTINGS_DEFAULTS[$key] ? " selected" : "";
        $off = $offered !== null && !$offered($val) ? " disabled" : "";
        $out .= "<option value=\"" . $val . "\"" . $sel . $off . ">" . e($label) . "</option>\n";
    }
    return $out;
}

/**
 * Seconds a session file is kept past the idle timeout (bootstrap.php raises
 * session.gc_maxlifetime to timeout + this), so PHP never deletes one the
 * timeout has not ended yet.
 */
const SESSION_GC_MARGIN = 300;

/** Seconds an untimed session (idle timeout 0) is kept: it ends after a day unused. */
const SESSION_UNTIMED_LIFETIME = 86400;

/**
 * Seconds a session must be kept for an idle timeout ($seconds, 0 = none):
 * what bootstrap.php raises session.gc_maxlifetime to.
 */
function idle_timeout_lifetime(int $seconds): int
{
    return $seconds > 0 ? $seconds + SESSION_GC_MARGIN : SESSION_UNTIMED_LIFETIME;
}

/**
 * Whether the host keeps a session long enough for an idle timeout
 * ($seconds, 0 = none): within its own lifetime (AESBIN_SESSION_GC_LIMIT).
 * No limit known (0, or the constant not defined): always.
 */
function idle_timeout_fits(int $seconds): bool
{
    $limit = defined("AESBIN_SESSION_GC_LIMIT") ? AESBIN_SESSION_GC_LIMIT : 0;
    return $limit === 0 || idle_timeout_lifetime($seconds) <= $limit;
}

/**
 * The idle timeout in force: the saved one (setting_int(), so an invalid
 * value reads as the default), or, when the host would delete the session
 * sooner (idle_timeout_fits()), the longest offered one that fits; the
 * shortest when none does. Read by bootstrap.php and the Settings card, so
 * the card shows what applies.
 */
function idle_timeout_setting(array $security): int
{
    $saved = setting_int($security, "session_idle_timeout");
    if (idle_timeout_fits($saved)) {
        return $saved;
    }
    $timed = array_filter(SETTINGS_ALLOWED["session_idle_timeout"], fn(int $v): bool => $v > 0);
    $fitting = array_filter($timed, "idle_timeout_fits");
    return $fitting !== [] ? max($fitting) : min($timed);
}

/** PBKDF2 iterations per ALTCHA proof-of-work attempt, the same at every level. */
const CAPTCHA_PBKDF2_COST = 5000;

/** Seconds an ALTCHA challenge stays valid, and its used-solution record kept. */
const CAPTCHA_EXPIRES = 600;

/**
 * Minimum length of an ALTCHA secret (captcha.hmac_secret,
 * captcha.key_signature_secret). AESBin always writes
 * bin2hex(random_bytes(32)), 64 characters, so anything shorter is a
 * truncated paste or placeholder and is treated as missing.
 */
const CAPTCHA_SECRET_MIN_LENGTH = 64;

/**
 * True when $secret is long enough to be a genuine ALTCHA secret. Shared by
 * render_config_php() (regenerate on save), SecurityService::captchaOperational()
 * (widget shown or not) and runtime_requirements() (dashboard notice).
 *
 * mixed, not ?string: the value comes straight from config.php, and a hand
 * edit such as 'hmac_secret' => 12345 must read as invalid, not throw a
 * TypeError on every request.
 */
function captcha_secret_valid(mixed $secret): bool
{
    return is_string($secret) && strlen($secret) >= CAPTCHA_SECRET_MIN_LENGTH;
}

/**
 * True when both ALTCHA secrets are valid and different from each other.
 * Both are required: hmac_secret signs every challenge and
 * key_signature_secret speeds up verifying it. The distinctness check
 * catches one value pasted into both fields.
 */
function captcha_keys_valid(mixed $hmacSecret, mixed $keySecret): bool
{
    if (!captcha_secret_valid($hmacSecret) || !captcha_secret_valid($keySecret)) {
        return false;
    }
    return $hmacSecret !== $keySecret;
}

/**
 * ALTCHA proof-of-work levels: the counter range passed to
 * SecurityService::createCaptchaChallenge(). The widget runs PBKDF2 (at
 * CAPTCHA_PBKDF2_COST iterations) once per counter step, so solve time is
 * linear in the counter.
 *
 * "high" is altcha.org's recommendation for PBKDF2/SHA-256 ("Deterministic
 * Effort Mode", https://altcha.org/docs/integration/proof-of-work-captcha/):
 * a few seconds on a current device, over 30 on an old laptop. Only the
 * owner ever solves it, so the owner picks. Rate limiting, the password and
 * TOTP are the brute-force defence; the PoW is extra friction for bots.
 *
 * Verification does not depend on the level, so changing it never
 * invalidates an issued challenge.
 */
const CAPTCHA_LEVELS = [
    "low" => [1000, 2500],
    "medium" => [2500, 5000],
    "high" => [5000, 10000],
];
/**
 * Not "high": the default must work on whatever device the owner first logs
 * in from, and half a minute on old hardware pushes people to turn it off.
 */
const CAPTCHA_LEVEL_DEFAULT = "medium";

/**
 * Label and hint language keys per level, spelled out rather than built as
 * "set_captcha_level_" . $level so bin/lint.sh's orphan check can see them.
 */
const CAPTCHA_LEVEL_LABELS = [
    "low" => ["set_captcha_level_low", "set_captcha_level_low_hint"],
    "medium" => ["set_captcha_level_medium", "set_captcha_level_medium_hint"],
    "high" => ["set_captcha_level_high", "set_captcha_level_high_hint"],
];

/**
 * The level from $cap (the config's "captcha" sub-array), or
 * CAPTCHA_LEVEL_DEFAULT when missing or unknown, as setting_int() does for
 * numeric settings.
 */
function captcha_level(array $cap): string
{
    $val = $cap["level"] ?? null;
    return is_string($val) && array_key_exists($val, CAPTCHA_LEVELS) ? $val : CAPTCHA_LEVEL_DEFAULT;
}

/**
 * <option>s for the ALTCHA level select. Each carries its hint in
 * data-hint, so dashboard.js needs no lang keys for it.
 */
function render_captcha_level_options(): string
{
    $out = "";
    foreach (CAPTCHA_LEVEL_LABELS as $level => [$labelKey, $hintKey]) {
        $sel = $level === CAPTCHA_LEVEL_DEFAULT ? " selected" : "";
        $out .= "<option value=\"" . e($level) . "\" data-hint=\"" . e(__($hintKey)) . "\"" . $sel . ">" . e(__($labelKey)) . "</option>\n";
    }
    return $out;
}

function activity_log_preview_count(array $cfg): int
{
    return setting_int($cfg, "activity_log_preview");
}

function activity_log_item_cap(array $cfg): int
{
    return setting_int($cfg, "activity_log_item_cap");
}

function render_config_php(array $cfg): string
{
    $ex = static fn($v): string => var_export($v, true);
    $db = $cfg["db"] ?? [];
    $sec = $cfg["security"] ?? [];
    $cap = $cfg["captcha"] ?? [];
    $forceHttps = !empty($cfg["force_https"]) ? "true" : "false";
    $serverRules = !empty($cfg["server_rules_configured"]) ? "true" : "false";
    // Rendered by hand like trusted_proxies; see the note there.
    $mdTools = array_filter(
        (array) ($cfg["markdown_tools"] ?? []),
        static fn($v, $k) => is_string($k) && is_string($v),
        ARRAY_FILTER_USE_BOTH
    );
    $debug = !empty($cfg["debug"]) ? "true" : "false";
    // Written back as found; only "yes" stays "yes". settings.php must never
    // take this key from a request body: the gate keeps an intruder with a
    // stolen session out of recovery. The only writer of "no" is the
    // auto-disable after a successful recovery (Auth::executeRecovery()).
    $recovery = (($cfg["recovery"] ?? "no") === "yes") ? "'yes'" : "'no'";
    // A hand-written config.php without this key runs with the captcha off
    // (SecurityService::captchaEnabled()); the first save writes it as on.
    // That cannot lock anyone out, since the secrets are generated below in
    // the same render.
    $capEnabled = ($cap["enabled"] ?? true) ? "true" : "false";
    // show_footer and show_logo are attribution, not controls, so a missing
    // key keeps the credit visible.
    $capShowFooter = ($cap["show_footer"] ?? true) ? "true" : "false";
    $capShowLogo = ($cap["show_logo"] ?? true) ? "true" : "false";

    // Generate a secret that is missing or too short, so a truncated one is
    // replaced rather than used. hmac_secret goes first so the check below
    // compares against its final value.
    if (!captcha_secret_valid($cap["hmac_secret"] ?? null)) {
        $cap["hmac_secret"] = bin2hex(random_bytes(32));
    }
    // Also regenerated when equal to hmac_secret; see captcha_keys_valid().
    if (
        !captcha_secret_valid($cap["key_signature_secret"] ?? null) ||
        $cap["key_signature_secret"] === $cap["hmac_secret"]
    ) {
        $cap["key_signature_secret"] = bin2hex(random_bytes(32));
    }

    $out = "<?php\n\n";
    $out .= "/**\n * AESBin configuration. Keep this file private.\n */\n\n";
    $out .= "declare(strict_types=1);\n\n";
    $out .= "defined('AESBIN_APP') || exit;\n\n";
    $out .= "return [\n";

    $out .= "    // ── Database ──────────────────────────────────────────────────────────────\n";
    $out .= "    'db' => [\n";
    $out .= "        'host'   => " . $ex($db["host"] ?? "") . ",\n";
    $out .= "        'port'   => " . (int) ($db["port"] ?? 3306) . ",\n";
    $out .= "        'name'   => " . $ex($db["name"] ?? "") . ",\n";
    $out .= "        'user'   => " . $ex($db["user"] ?? "") . ",\n";
    $out .= "        'pass'   => " . $ex($db["pass"] ?? "") . ",\n";
    $out .=
        "        // Table prefix. The random suffix keeps several installations apart in one\n" .
        "        // database and the table names unguessable to anything else on it.\n" .
        "        // Example: aesbin_a3f2c8d1_\n" .
        "        // WARNING: changing this after install breaks everything.\n";
    $out .= "        'prefix' => " . $ex($db["prefix"] ?? "") . ",\n";
    $out .= "    ],\n";
    $out .= "\n";

    $out .=
        "    // ── Storage ───────────────────────────────────────────────────────────────\n" .
        "    // Absolute path where encrypted file blobs are stored. The installer puts\n" .
        "    // it next to public/, under a randomly named directory: out of the web\n" .
        "    // root with either layout. Keep it outside public/, where it would have a\n" .
        "    // URL (the installer refuses it, the dashboard warns about it); anywhere\n" .
        "    // else PHP can write is fine.\n";
    $out .= "    'storage_path' => " . $ex($cfg["storage_path"] ?? "") . ",\n";
    $out .= "\n";
    $out .=
        "    // WebAuthn Relying Party ID: the domain your passkeys belong to. A\n" .
        "    // browser only offers a passkey when this matches the site you're on.\n" .
        "    //\n" .
        "    // Format: a bare domain, no https://, no port, no trailing slash.\n" .
        "    //   'vault.example.com': works only there (narrowest, the default)\n" .
        "    //   'example.com':       works on every subdomain too (use if you\n" .
        "    //                         reach the site as both example.com and\n" .
        "    //                         www.example.com)\n" .
        "    //\n" .
        "    // The installer fills this in with your hostname. Empty falls back to the\n" .
        "    // request's Host header, which is not an attack (a forged Host breaks\n" .
        "    // login, it cannot hijack it) but leaves a site reachable under two names\n" .
        "    // handing out passkeys under one and failing under the other. Must be a\n" .
        "    // domain, never an IP ('localhost' is fine).\n" .
        "    //\n" .
        "    // WARNING: changing this doesn't delete existing passkeys, it makes\n" .
        "    // them invisible. They come back if you restore the old value.\n";
    $out .= "    'webauthn_rp_id' => " . $ex($cfg["webauthn_rp_id"] ?? "") . ",\n";
    $out .= "\n";
    $out .=
        "    // ── App ───────────────────────────────────────────────────────────────────\n" .
        "    // HTTPS is required: the app refuses to boot without it (see bootstrap.php).\n" .
        "    // HTTPS is detected automatically from the request and, when configured,\n" .
        "    // from trusted reverse proxies. Set this to true only to force HTTPS mode\n" .
        "    // when the actual HTTPS setup cannot be detected correctly.\n";
    $out .= "    'force_https' => {$forceHttps},\n";
    $out .= "\n";
    $out .=
        "    // For nginx, Caddy and anything else that does not read .htaccess: set\n" .
        "    // true once everything the shipped .htaccess does is in your SERVER\n" .
        "    // config: the deny rules and the 403 error_page, both in\n" .
        "    // docs/installation.md. AESBin cannot read that file, so this is how it\n" .
        "    // learns those rules exist.\n" .
        "    //\n" .
        "    // Ignored on Apache and LiteSpeed, where the shipped .htaccess works and\n" .
        "    // the check reads the server instead. Left false there on purpose: a\n" .
        "    // value stored today would keep asserting itself after a move to nginx,\n" .
        "    // when it would no longer be true.\n";
    $out .= "    'server_rules_configured' => {$serverRules},\n";
    $out .= "\n";
    $out .=
        "    // External Markdown editors offered when previewing a .md file, as\n" .
        "    // 'Name' => 'https://…'. Empty means the preview explains why it shows the\n" .
        "    // source and suggests nothing: AESBin cannot vouch for a third-party\n" .
        "    // site, so the choice is yours. https only; anything else is ignored.\n" .
        "    //\n" .
        "    //   'markdown_tools' => ['WebTools' => 'https://tools.salvatorenoschese.it/markdown'],\n" .
        "    //\n" .
        "    // That one is run by AESBin's author: it converts in the browser, with no\n" .
        "    // ads and no tracking. It is an example, not a default; any editor works,\n" .
        "    // and shipping none is the deliberate default.\n";
    $out .= "    'markdown_tools' => [" .
        implode(", ", array_map(
            static fn($k, $v) => $ex((string) $k) . " => " . $ex((string) $v),
            array_keys($mdTools),
            $mdTools
        )) .
        "],\n";
    $out .= "\n";
    $out .= "    // Interface language: 'en' | 'it' | 'de' | 'es' | 'fr'\n";
    $out .= "    'locale'  => " . $ex($cfg["locale"] ?? SETTINGS_DEFAULTS["locale"]) . ",\n";
    $out .= "\n";
    $out .=
        "    // Show real PHP errors in the page instead of the generic message. Useful\n" .
        "    // on shared hosting where the PHP error log is out of reach. Keep false in\n" .
        "    // production: errors carry absolute paths, file names and sometimes\n" .
        "    // fragments of queries. While it is true the dashboard shows a banner\n" .
        "    // saying so, and nothing turns this one off by itself.\n";
    $out .= "    'debug'   => {$debug},\n";
    $out .= "\n";
    $out .=
        "    // Account recovery gate. \"yes\" enables the 10-word recovery phrase;\n" .
        "    // ANYTHING else (\"no\", true, 1, a typo, a missing key) disables it.\n" .
        "    //\n" .
        "    // A string compared with ===, not a boolean: in PHP the string \"false\" is\n" .
        "    // truthy, so 'recovery' => \"false\" would switch recovery ON while reading\n" .
        "    // as off. Every mistake fails toward disabled.\n" .
        "    //\n" .
        "    // Leave it \"no\". The phrase can reset the password, the TOTP secret and\n" .
        "    // itself, so while this is \"yes\" only the one-time code in the storage\n" .
        "    // folder stands between a stolen copy of it and the account. With \"no\"\n" .
        "    // the recovery endpoints refuse before looking at anything.\n" .
        "    //\n" .
        "    // To recover: set \"yes\" over FTP/SFTP/SSH, run the recovery, and it\n" .
        "    // returns itself to \"no\" on success. Editing this file is the proof that\n" .
        "    // the server is yours; it is deliberately not settable from the\n" .
        "    // dashboard, where a stolen session would be enough to open the gate.\n";
    $out .= "    'recovery'       => {$recovery},\n";
    $out .= "\n";

    $out .= "    // ── Security ──────────────────────────────────────────────────────────────\n";
    $out .= "    'security' => [\n";
    $out .= "        // Max failed logins per IP within login_window seconds. 0 = no per-IP limit\n" .
        "        // (the global cap on wrong TOTP codes always applies).\n";
    $out .= "        'login_max_attempts'   => " . setting_int($sec, "login_max_attempts") . ",\n";
    $out .= "        // Lockout window in seconds (1800 = 30 min).\n";
    $out .= "        'login_window'         => " . setting_int($sec, "login_window") . ",\n";
    $out .= "        // Idle session timeout in seconds: 0 (no idle logout; 24 h unused still ends it),\n";
    $out .= "        // 300, 600, 900, 1800 or 3600. Any other value means the default, 600 (10 min).\n";
    $out .= "        // Above the server's session lifetime (session.gc_maxlifetime in php.ini, less\n";
    $out .= "        // 5 min) the longest one that fits applies, as the Settings card shows.\n";
    $out .= "        'session_idle_timeout' => " . setting_int($sec, "session_idle_timeout") . ",\n";
    $out .=
        "        // Terminate session immediately if the client IP changes mid-session\n" .
        "        // (for IPv6, if it leaves its /64). Protects against session hijacking.\n" .
        "        // Off by default: on mobile data or a VPN the address changes, and every\n" .
        "        // change logs you out. Turn it on for a fixed connection.\n";
    $out .= "        'session_ip_lock'      => " . (!empty($sec["session_ip_lock"]) ? "true" : "false") . ",\n";
    $proxies = array_values((array) ($sec["trusted_proxies"] ?? []));
    $out .=
        "        // Reverse proxies allowed to set X-Forwarded-For, as IPs or CIDRs.\n" .
        "        // Empty = the header is ignored entirely and REMOTE_ADDR is used, which\n" .
        "        // is correct when PHP is reached directly. Behind Cloudflare, nginx or a\n" .
        "        // shared host that proxies, this MUST list your proxy layer. Otherwise\n" .
        "        // every visitor shares one rate-limit bucket (five failed logins from\n" .
        "        // anyone on the internet lock you out) and session_ip_lock can no longer\n" .
        "        // tell sessions apart. Example:\n" .
        "        //   'trusted_proxies' => ['173.245.48.0/20', '103.21.244.0/22'],\n";
    // By hand, not var_export(), whose multi-line "array (\n  0 => ...\n)"
    // layout would break the alignment of this block.
    $out .=
        "        'trusted_proxies'      => [" .
        implode(", ", array_map($ex, $proxies)) .
        "],\n";
    $out .= "    ],\n";
    $out .= "\n";

    $out .= "    // ── Upload / storage limits ───────────────────────────────────────────────\n";
    $out .= "    // Per-file upload cap in bytes. 0 = PHP server limit.\n";
    $out .= "    'max_upload_bytes' => " . setting_int($cfg, "max_upload_bytes") . ",     // default: 20 MB\n";
    $out .= "    // Total storage quota for the whole instance in bytes. 0 = unlimited.\n";
    $out .= "    'max_total_bytes'  => " . setting_int($cfg, "max_total_bytes") . ",  // default: 10 GB\n";
    $out .= "\n";

    $out .= "    // ── Activity log ──────────────────────────────────────────────────────────\n";
    $out .= "    // One card per event type. Occurrences shown without expanding it (1, 3 or 5).\n";
    $out .= "    'activity_log_preview'  => " . activity_log_preview_count($cfg) . ",\n";
    $out .= "    // Occurrences kept per card, in storage and behind \"show more\" (5, 10, 25 or 50).\n";
    $out .= "    'activity_log_item_cap' => " . activity_log_item_cap($cfg) . ",\n";
    $out .= "\n";

    $out .= "    // ── CAPTCHA ───────────────────────────────────────────────────────────────\n";
    $out .= "    // ALTCHA: self-hosted PoW challenge, no external services, no API keys.\n";
    $out .= "    'captcha' => [\n";
    $out .= "        'enabled'              => {$capEnabled},\n";
    $out .=
        "        // The \"Protected by ALTCHA\" attribution link under the challenge.\n" .
        "        // Shown by default as credit; set to false to hide it.\n";
    $out .= "        'show_footer'          => {$capShowFooter},\n";
    $out .=
        "        // The small ALTCHA logo inside the widget itself. Shown by default as\n" .
        "        // credit, same as show_footer above; set to false to hide it.\n";
    $out .= "        'show_logo'            => {$capShowLogo},\n";
    $out .=
        "        // Proof-of-work effort: 'low' | 'medium' | 'high'. Higher costs bots more\n" .
        "        // but takes longer to solve on your own device at login and recovery:\n" .
        "        // 'high' can pass 30 s on older hardware. Changeable from the dashboard;\n" .
        "        // anything else reads as 'medium'.\n";
    $out .= "        'level'                => " . $ex(captcha_level($cap)) . ",\n";
    $out .=
        "        // HMAC secret signing every challenge. Auto-generated by the installer;\n" .
        "        // to make one by hand: php -r \"echo bin2hex(random_bytes(32));\"\n" .
        "        // BOTH this and key_signature_secret below are required: if either is\n" .
        "        // missing, shorter than " . CAPTCHA_SECRET_MIN_LENGTH . " characters, or identical to the other,\n" .
        "        // ALTCHA is inactive (login still works) and the dashboard shows an\n" .
        "        // amber notice. Saving any setting regenerates whichever one is wrong.\n";
    $out .= "        'hmac_secret'          => " . $ex($cap["hmac_secret"] ?? "") . ",\n";
    $out .=
        "        // Second HMAC secret enabling fast server-side verification (one HMAC\n" .
        "        // instead of a full PBKDF2 re-derivation). Auto-generated alongside\n" .
        "        // hmac_secret above and just as required (see the note there). Made the\n" .
        "        // same way by hand, but never a copy of hmac_secret.\n";
    $out .= "        'key_signature_secret' => " . $ex($cap["key_signature_secret"] ?? "") . ",\n";
    $out .= "    ],\n";

    // A missing privacy_enabled means off: the page needs a controller name
    // and email, which a fresh install lacks, and with it on every settings
    // save would fail validation.
    $privacyEnabled = !empty($cfg["privacy_enabled"] ?? false);
    $privacyName = $cfg["privacy_name"] ?? "";
    $privacyAddress = $cfg["privacy_address"] ?? "";
    $privacyVat = $cfg["privacy_vat"] ?? "";
    $privacyEmail = $cfg["privacy_email"] ?? "";
    $out .= "\n";
    $out .= "    // ── Privacy & cookie policy page ──────────────────────────────────────────\n";
    $out .=
        "    // Public page at /privacy describing what this install collects (session\n" .
        "    // cookie, IPs in the activity log, encryption at rest). The default text\n" .
        "    // lives in app/lang/*.php; the privacy_* fields below identify the data\n" .
        "    // controller at the top of the page, always as text, never as HTML.\n";
    $out .=
        "    'privacy_enabled' => " .
        ($privacyEnabled ? "true" : "false") .
        ",   // false → /privacy 404s; true also needs privacy_name and privacy_email\n";
    $out .=
        "    'privacy_name' => " .
        $ex($privacyName) .
        ",        // plain text, shown as the data controller at the top of the privacy page\n";
    $out .=
        "    'privacy_address' => " .
        $ex($privacyAddress) .
        ",     // plain text, shown alongside privacy_name at the top of the privacy page\n";
    $out .=
        "    'privacy_vat' => " .
        $ex($privacyVat) .
        ",         // plain text (VAT/tax ID), shown alongside privacy_name at the top of the privacy page\n";
    $out .=
        "    'privacy_email' => " .
        $ex($privacyEmail) .
        ",       // plain text; rendered as an obfuscated mailto: link, never as raw HTML\n";

    // "Contact me" footer link, independent of the privacy page; see
    // effective_contact_link() in helpers.php.
    $contactEnabled = !empty($cfg["contact_enabled"] ?? false);
    $contactLink = $cfg["contact_link"] ?? "";
    $contactReferrer = !empty($cfg["contact_referrer"] ?? false);
    $out .= "\n";
    $out .= "    // ── Contact link ──────────────────────────────────────────────────────────\n";
    $out .=
        "    'contact_enabled' => " .
        ($contactEnabled ? "true" : "false") .
        ",  // master toggle for the public footer 'contact me' link\n";
    $out .=
        "    'contact_link' => " .
        $ex($contactLink) .
        ",        // an email (obfuscated mailto: link) or an https:// URL such as a contact form; independent of privacy_email above\n";
    $out .=
        "    'contact_referrer' => " .
        ($contactReferrer ? "true" : "false") .
        ", // link only: true tells the contact site the visitor came from here (origin only, never the page address)\n";

    $out .= "];\n";
    return $out;
}

/**
 * Rewrite config.php from render_config_php(). Only the keys it renders
 * survive; see the file header.
 */
function write_config(string $path, array $cfg): bool
{
    return write_config_file($path, render_config_php($cfg));
}

/**
 * Write config.php atomically via a temp file in the same directory and
 * rename(). A direct file_put_contents() truncates first, so a failed write
 * (disk full, quota) would leave a broken or empty config, taking the app
 * down or exposing the installer again. Then the file's OPcache entry is
 * dropped, so the next request reads the new settings.
 */
function write_config_file(string $path, string $data): bool
{
    $tmp = staging_path($path);
    if (file_put_contents($tmp, $data, LOCK_EX) !== strlen($data)) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0640);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    invalidate_opcache($path);
    return true;
}

/**
 * Drop OPcache's bytecode for a file just rewritten, so a config.php change
 * takes effect before opcache.revalidate_freq elapses. No-op where the call
 * is unavailable (no OPcache, disabled function, opcache.restrict_api).
 */
function invalidate_opcache(string $path): void
{
    if (Requirements::opcacheInvalidateUsable()) {
        @opcache_invalidate($path, true);
    }
}

/**
 * A temp name beside $path for a write staged and renamed into place. Hidden
 * (leading dot), so a copy left behind by a killed process is refused by the
 * dotfile rule on every supported server, not only by Apache's extension
 * allow-list: a stray config.php copy holds the database password.
 */
function staging_path(string $path): string
{
    return dirname($path) . "/." . basename($path) . "." . bin2hex(random_bytes(6)) . ".tmp";
}
