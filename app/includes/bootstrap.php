<?php

/**
 * app/includes/bootstrap.php — AESBin application initialisation
 *
 * Loaded by public/index.php before routing.
 * Handles: config detection, constants, service instantiation, session start.
 * Works in webroot (/) or any subdirectory (/aesbin/, etc.).
 */

declare(strict_types=1);

use AESBin\Auth;
use AESBin\CryptoService;
use AESBin\Database;
use AESBin\FileService;
use AESBin\Lang;
use AESBin\Migrator;
use AESBin\SecurityService;
use AESBin\ShareService;

defined("AESBIN_APP") || exit;

// ── Error handling ────────────────────────────────────────────────────────────
// Safe default before config.php is readable; the 'debug' key below overrides it.
ini_set("display_errors", "0");
ini_set("log_errors", "1");
// Keep argument values out of logged stack traces: passwords, data keys and
// plaintext are passed as arguments, and a fatal in CryptoService would
// otherwise write them to the unencrypted error log. Frame names and line
// numbers are kept.
ini_set("zend.exception_ignore_args", "1");
error_reporting(E_ALL);
// "X-Powered-By: PHP/8.x" (expose_php) tells a scanner the exact version.
// Here, before anything can answer, so redirects and errors go without it
// too; the installer, which does not load this file, removes it as well.
header_remove("X-Powered-By");

// ── Installation root (the folder above public/) ──────────────────────────────
// Holds config.php, public/ (the web root) and app/. This file is
// app/includes/bootstrap.php, two levels down.
define("AESBIN_ROOT", dirname(__DIR__, 2));
// The code that is not served: includes/, actions/, views/, lang/, vendor/,
// setup/ and migrations.php. Everything under it is found through this
// constant, the owner's files (config.php) and public/ through AESBIN_ROOT.
define("AESBIN_APP_DIR", AESBIN_ROOT . "/app");

define("AESBIN_VERSION", "1.0.0");

// The schema version this code expects is not a constant: it is
// Migrator::targetVersion(), so adding a migration step is a single change.

// Used by the update check and the "About" links.
define("AESBIN_GITHUB_REPO", "SalvatoreNoschese/AESBin");

// ── Base URL (works in subdirectory) ──────────────────────────────────────────
// SCRIPT_NAME is /index.php with public/ as the document root, and
// /public/index.php when the root .htaccess maps requests into public/: the
// public URLs are the same either way, so "/public" is not part of the base.
$_script = $_SERVER["SCRIPT_NAME"] ?? "/index.php";
define("AESBIN_BASE", preg_replace('#/public$#', "", rtrim(dirname($_script), "/")));
unset($_script);

// ── Config check: redirect to setup if not installed ──────────────────────────
$_configFile = AESBIN_ROOT . "/config.php";
if (!file_exists($_configFile)) {
    // public/index.php runs the installer instead while app/setup/ is there.
    http_response_code(503);
    die("<h1>AESBin: missing config.php.</h1><p>Re-upload app/setup/ to run the installer, or restore config.php</p>");
}
$config = require $_configFile;
unset($_configFile);

// Loaded before the rest of the services for the checks below
// (valid_db_prefix(), ip_in_cidr(), setting_int()). Both files only define
// constants and functions.
require_once AESBIN_APP_DIR . "/includes/helpers.php";
require_once AESBIN_APP_DIR . "/includes/config_template.php";

// 'debug' => true in config.php displays PHP errors. Errors before this point
// (a broken config.php) still get the safe default. Kept as a constant so the
// dashboard can show a banner: left on, it leaks absolute paths, file names
// and query fragments to anyone who can trigger an error.
define("AESBIN_DEBUG", !empty($config["debug"]));
if (AESBIN_DEBUG) {
    ini_set("display_errors", "1");
}

// ── Storage constants ─────────────────────────────────────────────────────────
// No fallback for storage_path. The installer gives it a random suffix
// (e.g. _storage-a1b2c3d4) so the folder holding every encrypted file is not
// guessable, as defence in depth behind the deny rules. A fixed fallback would
// silently put files in a guessable path, so a missing key refuses to run.
if (empty($config["storage_path"]) || !is_string($config["storage_path"])) {
    http_response_code(503);
    die(
        "<h1>AESBin: storage_path is not configured.</h1>" .
            "<p>config.php is missing the top-level <code>storage_path</code> key, " .
            "or it is empty. This is normally set once by the installer, with a " .
            "random suffix (e.g. <code>_storage-a1b2c3d4</code>) so the folder " .
            "isn't a guessable name; see config-sample.php for the exact format. " .
            "Add it back, or restore a config.php from before it was removed.</p>"
    );
}
$_storagePath = rtrim($config["storage_path"], "/");
// Files: storage/{2-char prefix}/{filename}         e.g. storage/ab/random.bin
// Trash: storage/_trash/{2-char prefix}/{filename}  e.g. storage/_trash/cd/random.bin
// The underscore sorts _trash above the 2-char prefix dirs in listings.
define("AESBIN_FILES", $_storagePath);
define("AESBIN_TRASH", $_storagePath . "/_trash");

// ── Ensure storage directories and protection exist ───────────────────────────
foreach ([AESBIN_FILES, AESBIN_TRASH] as $_sdir) {
    make_dir($_sdir);
}
unset($_sdir);

unset($_storagePath);

// ── Deny direct access to .bin blobs, regardless of storage_path ──────────────
// .htaccess applies to subdirectories and _trash lives under AESBIN_FILES, so
// one file at the storage root covers everything. It denies the whole
// directory, so the 403 is the same whether a blob exists or not. A plain
// deny rather than mod_rewrite [F]: it applies before a handler is chosen and
// does not depend on RewriteEngine. Apache 2.4 syntax, as in public/.htaccess.
$_storageHtaccess = AESBIN_FILES . "/.htaccess";
if (!is_file($_storageHtaccess)) {
    @file_put_contents(
        $_storageHtaccess,
        "# Deny everything under the storage directory: encrypted blobs only,\n" .
            "# never served directly. AESBin recreates this file if it is missing.\n" .
            "Require all denied\n"
    );
}
unset($_storageHtaccess);

// ── DB prefix ─────────────────────────────────────────────────────────────────
define("DB_PREFIX", $config["db"]["prefix"] ?? "aesbin_");
// Concatenated into every query, so held to the installer's rule in case
// config.php is edited by hand.
if (!is_string(DB_PREFIX) || !valid_db_prefix(DB_PREFIX)) {
    http_response_code(503);
    die("<h1>AESBin: invalid db.prefix in config.php.</h1><p>Letters, digits and underscores only, at most 40.</p>");
}

// Account recovery gate, a constant so no later include can flip it.
// Only the exact string "yes" enables it: a missing key, null, boolean or typo
// all disable recovery, and "false" in quotes fails closed where a truthy
// check would open the gate.
define("AESBIN_RECOVERY_ENABLED", ($config["recovery"] ?? "no") === "yes");

// ── HTTPS detection (handles reverse proxies: Altervista, Cloudflare, nginx) ──
// The native HTTPS variable, or X-Forwarded-Proto / X-Forwarded-SSL /
// CloudFront-Forwarded-Proto. Those headers are client-supplied, so they count
// only from an address in security.trusted_proxies (the same gate request_ip()
// applies to X-Forwarded-For); otherwise any client could claim HTTPS.
// 'force_https' is an explicit override for proxies that send no header.

$_https_native =
    !empty($_SERVER["HTTPS"]) && strtolower($_SERVER["HTTPS"]) !== "off";

// Empty by default: forwarded headers are trusted only from named proxies.
// Set here, before its first use below; request_ip() reads the same list.
request_ip_trusted_proxies(
    array_values((array) ($config["security"]["trusted_proxies"] ?? []))
);

$_https_proxy_trusted = false;
$_remote = unmap_ipv4((string) ($_SERVER["REMOTE_ADDR"] ?? ""));
foreach (request_ip_trusted_proxies() as $_cidr) {
    if ($_remote !== "" && ip_in_cidr($_remote, (string) $_cidr)) {
        $_https_proxy_trusted = true;
        break;
    }
}

$_https_fwd =
    $_https_proxy_trusted &&
    (strtolower($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https" ||
        strtolower($_SERVER["HTTP_X_FORWARDED_SSL"] ?? "") === "on" ||
        strtolower($_SERVER["HTTP_CLOUDFRONT_FORWARDED_PROTO"] ?? "") === "https");
unset($_https_proxy_trusted, $_remote, $_cidr);
define(
    "AESBIN_HTTPS",
    $_https_native || $_https_fwd || !empty($config["force_https"])
);
unset($_https_native, $_https_fwd);

// HTTPS is mandatory. Browsers silently refuse the __Host- session cookie on
// plain HTTP, so sessions would never persist, and key material must not cross
// the wire unencrypted. 'force_https' exists for a TLS-terminating proxy that
// does not forward the protocol, not as a way to run without HTTPS.
if (!AESBIN_HTTPS) {
    http_response_code(503);
    die(
        "<h1>AESBin requires HTTPS.</h1>" .
            "<p>This request arrived over plain HTTP. Serve the app over HTTPS, " .
            "or if you're behind a reverse proxy that terminates TLS, either " .
            "list the proxy in <code>security.trusted_proxies</code> (so its " .
            "X-Forwarded-Proto header is honoured) or set " .
            "<code>'force_https' =&gt; true</code> in config.php.</p>"
    );
}

// ── The host's session lifetime ───────────────────────────────────────────────
// Read before the session block below raises session.gc_maxlifetime. The
// raise holds only for AESBin's own requests: a cron job (cPanel, Debian and
// Ubuntu's sessionclean) and PHP's cleanup started by any other site sharing
// the session folder use this value, and delete a session idle for longer.
// The Settings card offers only the timeouts that fit (idle_timeout_fits()).
define("AESBIN_SESSION_GC_LIMIT", (int) ini_get("session.gc_maxlifetime"));

// ── Session idle timeout constant (0 = disabled) ──────────────────────────────
// idle_timeout_setting(), not ?? 0: a missing or non-whitelisted value reads as
// the SETTINGS_DEFAULTS value (600s) the dashboard shows, not as "disabled",
// and one the host would cut short (above) as the longest that fits.
define(
    "AESBIN_IDLE_TIMEOUT",
    idle_timeout_setting($config["security"] ?? [])
);

// A request the .htaccess deny rules refused. Their "ErrorDocument 403
// <base>/index.php" has the server answer it with this script: the refused
// address stays in REQUEST_URI, and REDIRECT_STATUS says why (set by the
// server for an internal redirect; a client cannot send it). Answered below,
// before the database, with app/views/error.php.
define("AESBIN_REFUSED", ($_SERVER["REDIRECT_STATUS"] ?? "") === "403");

// ── Session ───────────────────────────────────────────────────────────────────
// Only owner-facing routes get a session: the dashboard, /login, /recovery and
// /api/* except the visitor-facing /api/share/*. session_start() alone creates
// the session file and sends Set-Cookie, and the privacy page, README and
// SECURITY.md promise share visitors no PHP session: their cookies are the
// visit to a link (share_session_cookie()) and the language they pick (see
// visitor_lang_choice()). Other routes
// run without a session even if the browser has one, so a share behaves the
// same for the owner and a stranger. aesbin_route_path(), as in router.php,
// so both agree on the route.
$_sessPath = aesbin_route_path(
    aesbin_canonical_path(explode("?", (string) ($_SERVER["REQUEST_URI"] ?? "/"), 2)[0])
);
define(
    "AESBIN_SESSION_ROUTE",
    !AESBIN_REFUSED && (
        in_array($_sessPath, ["/", "/login", "/recovery"], true) ||
        (str_starts_with($_sessPath, "/api/") && !str_starts_with($_sessPath, "/api/share/"))
    )
);
unset($_sessPath);

if (AESBIN_SESSION_ROUTE && session_status() === PHP_SESSION_NONE) {
    // __Host- requires Secure, Path=/ and no Domain. HTTPS is guaranteed
    // by the check above.
    session_name(SESSION_COOKIE);
    // Refuse session IDs PHP never issued. Login regenerates the id, but a
    // session that never logs in keeps it, so a planted id would be shared
    // with whoever planted it. Set here, not in .htaccess, so it holds on
    // every SAPI regardless of AllowOverride. Must precede session_start().
    ini_set("session.use_strict_mode", "1");
    // Cookie only. With use_trans_sid on, PHP writes the id into links and
    // forms, leaking it through the address bar, Referer and history.
    ini_set("session.use_only_cookies", "1");
    ini_set("session.use_trans_sid", "0");
    // PHP's default (1440 s) would let its garbage collector delete a session
    // file before a longer idle timeout, or a disabled one, is reached. Only
    // for AESBin's own requests: the host's value (AESBIN_SESSION_GC_LIMIT,
    // above) caps the timeout. See docs/installation.md.
    $_gcLifetime = idle_timeout_lifetime(AESBIN_IDLE_TIMEOUT);
    if ((int) ini_get("session.gc_maxlifetime") < $_gcLifetime) {
        ini_set("session.gc_maxlifetime", (string) $_gcLifetime);
    }
    unset($_gcLifetime);
    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => AESBIN_HTTPS,
        "httponly" => true,
        "samesite" => "Strict",
    ]);
    session_start();
}

// ── Autoload ──────────────────────────────────────────────────────────────────
require_once AESBIN_APP_DIR . "/includes/Database.php";
require_once AESBIN_APP_DIR . "/includes/CryptoService.php";
require_once AESBIN_APP_DIR . "/includes/Lang.php";
require_once AESBIN_APP_DIR . "/includes/MimeRegistry.php";
require_once AESBIN_APP_DIR . "/includes/Vendor.php";
require_once AESBIN_APP_DIR . "/includes/Csrf.php";
require_once AESBIN_APP_DIR . "/includes/SessionRegistry.php";
require_once AESBIN_APP_DIR . "/includes/Auth.php";
require_once AESBIN_APP_DIR . "/includes/FileService.php";
require_once AESBIN_APP_DIR . "/includes/ShareService.php";
require_once AESBIN_APP_DIR . "/includes/SecurityService.php";
require_once AESBIN_APP_DIR . "/includes/Migrator.php";
require_once AESBIN_APP_DIR . "/includes/GuardFile.php";
require_once AESBIN_APP_DIR . "/includes/RecoveryGate.php";
require_once AESBIN_APP_DIR . "/includes/Requirements.php";

// ── Stale guard files ─────────────────────────────────────────────────────────
// A guard-code file is removed once the state that justifies it is gone.
// Checked on every request, not only in the flows that use the files: a
// completed recovery closes the gate inside its own request, so nothing in
// that flow would come back to clean up. Each file has its own directory, so
// with nothing to clean both checks are a single failed stat.
guard_dir_clear(AESBIN_APP_DIR . "/setup/_viewme");

if (!AESBIN_RECOVERY_ENABLED) {
    if (is_dir(recovery_guard_dir())) {
        recovery_guard_clear();
        // If the gate is reopened later, the code must be read again.
        unset($_SESSION["recovery_guard_ok"], $_SESSION["recovery_new_phrase_hash"]);
    }
}

// A per-request CSP nonce authorizes our inline bootstrap scripts; every
// asset is vendored locally (see csp_header()).
define("AESBIN_NONCE", bin2hex(random_bytes(16)));
csp_header();

// ── Language ──────────────────────────────────────────────────────────────────
// A locale just saved from the dashboard is taken from the session, because
// without a usable opcache_invalidate() the old config.php can be served for
// up to opcache.revalidate_freq seconds, exactly when the reload arrives.
//
// The hint is dropped when config.php agrees, not after one request: the
// timed polls in api.js and dashboard.js can land between the save and the
// reload and would consume it. Accepted gap: hand-editing config.php to a
// third locale right after saving keeps that browser on the saved language
// until its session expires.
// is_string(): Lang::load() takes a string, and a hand-edited non-string
// value would otherwise be a TypeError on every request.
$_locale = is_string($config["locale"] ?? null) ? $config["locale"] : "en";
if (isset($_SESSION["locale_hint"])) {
    if ($_locale === $_SESSION["locale_hint"]) {
        unset($_SESSION["locale_hint"]);
    } else {
        $_locale = (string) $_SESSION["locale_hint"];
    }
}
Lang::load($_locale);
unset($_locale);

// Whether the public /privacy page is enabled, and the "Contact me" link
// (effective_contact_link(), independent of the privacy page): the footer of
// every page (partials/page-footer.php), the 403 below included.
$privacyEnabled = privacy_page_enabled($config);
$contactLink = effective_contact_link($config);
$contactReferrer = !empty($config["contact_referrer"]);

// ── Refused request (AESBIN_REFUSED) ──────────────────────────────────────────
// Not logged and no database: scanners probe these paths by the thousand, and
// the answer is the same for every one. The visitor's language, as on the
// 404 page.
if (AESBIN_REFUSED) {
    Lang::load(detect_visitor_lang());
    http_response_code(403);
    $errorCode = 403;
    require AESBIN_APP_DIR . "/views/error.php";
    exit();
}

// ── Database ──────────────────────────────────────────────────────────────────
// A refused connection (server down, credentials changed) gets the plain
// unavailable page, not an empty 500. The driver message names host and user,
// so it goes to the log only.
try {
    $db = Database::connect($config["db"]);
} catch (PDOException $e) {
    error_log("[AESBin] database connection failed: " . $e->getMessage());
    aesbin_unavailable(
        "<p>The database cannot be reached. If you administer this site, the " .
            "server error log has the exact error.</p>",
        60
    );
}

// ── Schema version gate ───────────────────────────────────────────────────────
// One indexed lookup. The mismatch directions are not symmetric:
//
//   db < code   Migration outstanding. The app runs and the dashboard shows a
//               banner; public share links stay up for visitors.
//
//   db > code   Older code over a newer database. Refused: it would silently
//               write rows the newer schema interprets differently.
//
//   no marker   Structure unknown. Refused; the error log gets the statement
//               that adds the marker once the structure is confirmed.
$_schemaVersion = null;
try {
    $_row = $db
        ->query(
            "SELECT meta_value FROM " . DB_PREFIX . "meta WHERE meta_key = 'schema_version'"
        )
        ->fetchColumn();
    if ($_row !== false) {
        $_schemaVersion = (int) $_row;
    }
} catch (Throwable) {
    // Table absent: handled as "no marker" below.
}

define("AESBIN_DB_SCHEMA_VERSION", $_schemaVersion ?? 0);

// targetVersion() throws on a malformed app/migrations.php. This runs on every
// request, so log the real message and serve the plain unavailable page
// rather than a fatal error.
try {
    $_targetVersion = Migrator::targetVersion();
} catch (Throwable $e) {
    error_log("[AESBin] app/migrations.php failed to load: " . $e->getMessage());
    aesbin_unavailable(
        "<p>The database migration file could not be read. If you " .
            "administer this site, the server error log has the exact error.</p>",
        300
    );
}

define(
    "AESBIN_MIGRATION_PENDING",
    $_schemaVersion !== null && $_schemaVersion < $_targetVersion
);

if (!AESBIN_MIGRATION_PENDING && $_schemaVersion !== $_targetVersion) {
    $_expected = $_targetVersion;

    // Public page, so no table prefix, table names or SQL (the prefix is
    // configurable so it need not be guessable). Detail goes to the error log.
    if ($_schemaVersion === null) {
        error_log(
            "[AESBin] No schema version marker found. If the structure already " .
                "matches schema version {$_expected}, create it with:\n" .
                "CREATE TABLE " . DB_PREFIX . "meta (\n" .
                "    meta_key   VARCHAR(64) NOT NULL,\n" .
                "    meta_value TEXT        NOT NULL,\n" .
                "    updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP\n" .
                "                           ON UPDATE CURRENT_TIMESTAMP,\n" .
                "    PRIMARY KEY (meta_key)\n" .
                ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n" .
                "INSERT INTO " . DB_PREFIX . "meta (meta_key, meta_value) " .
                "VALUES ('schema_version', '{$_expected}');"
        );
        $_msg =
            "<p>The database is not ready. If you administer this site, the " .
            "server error log explains what to do; the procedure is also in " .
            "the Maintaining guide (help.html#maintaining).</p>";
    } else {
        error_log(
            "[AESBin] Schema version mismatch: database is at {$_schemaVersion}, " .
                "this code expects {$_expected}. Older code is running over a " .
                "database a newer release already upgraded: restore the newer " .
                "code, do not downgrade the database."
        );
        $_msg =
            "<p>The database is not ready. If you administer this site, the " .
            "server error log explains what to do.</p>";
    }

    aesbin_unavailable($_msg, 3600);
}
unset($_schemaVersion, $_row, $_targetVersion, $_expected, $_msg);

// ── Services ──────────────────────────────────────────────────────────────────
$crypto = new CryptoService();
$auth = new Auth($db, $crypto);
if (AESBIN_IDLE_TIMEOUT > 0) {
    $auth->setIdleTimeout(AESBIN_IDLE_TIMEOUT);
}
// Off unless set (see session_ip_lock in config_template.php).
$auth->setIpLock(!empty($config["security"]["session_ip_lock"]));

// Opportunistic session-expiry sweep: the first request after a session goes
// idle logs it with the real last-seen-derived time (see
// SessionRegistry::pruneExpired()). One indexed SELECT when nothing expired.
$auth->sessionRegistry()->pruneExpired();

// Expired rate-limit rows, which may hold a visitor's address (see
// rate_limit_sweep()).
rate_limit_sweep($db);

// Addresses from failed attempts and 404s, past ACTIVITY_UNAUTH_TTL_DAYS
// (see activity_log_expire(); at most one pass an hour).
activity_log_expire($db);

$shareSvc = new ShareService($db, $crypto);
$fileSvc = new FileService($db, $crypto, $shareSvc);

// security.* plus captcha.* under "captcha".
$security = new SecurityService(
    $db,
    array_merge($config["security"] ?? [], [
        "captcha" => $config["captcha"] ?? [],
    ])
);
$auth->setSecurity($security);
