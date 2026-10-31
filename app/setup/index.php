<?php

/**
 * app/setup/index.php — AESBin multi-step installer. Makes every decision;
 * app/setup/template.php draws the result.
 *
 * Order (security-relevant): redirect if config.php exists, language, the
 * gates (transport, then guard code), requirements, AJAX endpoints,
 * template. setup_gate() exits on refusal, so no endpoint runs past a gate.
 *
 * Steps: 1. database and prefix (or reconnect to an existing installation);
 * 2. storage path; 3. master password and TOTP; 4. recovery phrase;
 * 5. install: schema, keys, config.php, then the login page.
 *
 * The session holds only the guard flag and hashes of the TOTP secret and
 * phrase; credentials and password stay in the browser until the install
 * request.
 *
 * Out of the web root: public/index.php requires this file, for every
 * request, for as long as config.php does not exist. Once it does, nothing
 * reaches it any more, so it needs no switch of its own.
 */

declare(strict_types=1);

use AESBin\CryptoService;
use AESBin\Database;
use AESBin\Lang;
use AESBin\Requirements;

defined("AESBIN_APP") || exit;

// As in bootstrap.php, which is not loaded here. The installer passes the
// database password, master password, TOTP secret and recovery phrase as
// function arguments: a fatal with display_errors on would print them, and a
// stack trace would keep them in the error log.
ini_set("display_errors", "0");
ini_set("log_errors", "1");
ini_set("zend.exception_ignore_args", "1");

/**
 * The config.php written by an install or a reconnect: render_config_php()
 * (shared with the settings save, which owns every default) fed with the
 * values only the installer knows. Detects HTTPS internally.
 */
function build_config_php(array $db, string $storage, string $locale): string
{
    // The gate has already refused "none", so this is native or proxy.
    $tlsState = setup_tls_state();

    // force_https only for the proxy case, where PHP sees plain HTTP and
    // bootstrap.php would otherwise refuse to boot. With native HTTPS it would
    // only hide a future misconfiguration.
    $forceHttps = $tlsState === "proxy";

    // Seeded only on positive evidence that PHP is not seeing the client (the
    // dashboard's check). A forwarded protocol header alone is not enough:
    // many hosts terminate TLS in front of PHP and still pass the client
    // address, and seeding REMOTE_ADDR there would trust the visitor as a
    // proxy, letting it forge X-Forwarded-For past the rate limiter and
    // session_ip_lock. $remote is validated here because this caller writes it
    // into config.php.
    $security = [];
    $remote = unmap_ipv4((string) ($_SERVER["REMOTE_ADDR"] ?? ""));
    if (
        $tlsState === "proxy" &&
        Requirements::proxyIpUnresolved([]) &&
        filter_var($remote, FILTER_VALIDATE_IP) !== false
    ) {
        $security["trusted_proxies"] = [$remote];
    }

    // Pin the WebAuthn RP ID. Left empty, the passkey endpoints fall back to
    // HTTP_HOST on every request. Not exploitable (the browser rejects an rpId
    // that is not a registrable suffix of the origin, so a forged Host only
    // breaks login), but passkeys would then depend on a request header.
    //
    // An rpId is a bare lower-case domain: no scheme, no port, never an IP
    // address (browsers refuse one). An install reached by IP, or with no
    // usable Host header, pins nothing, so passkeys start working once the
    // site is reached by name. "localhost" passes through as is, for local
    // development.
    $rpId = strtolower((string) ($_SERVER["HTTP_HOST"] ?? ""));
    $rpId = preg_replace('/:\d+$/', "", $rpId) ?? "";          // strip :8080
    $rpId = filter_var(trim($rpId, "[]"), FILTER_VALIDATE_IP) !== false
        ? ""
        : (preg_replace('/[^a-z0-9.\-]/', "", $rpId) ?? "");     // host chars only

    return render_config_php([
        "db" => $db,
        "storage_path" => $storage,
        "force_https" => $forceHttps,
        "locale" => $locale,
        "webauthn_rp_id" => $rpId,
        "security" => $security,
        // Its secrets are generated in the same render, so the challenge
        // works from the first request.
        "captcha" => ["enabled" => true],
    ]);
}

// The installation root (config.php, public/) and the code directory under
// it: this file is app/setup/index.php.
$appDir = dirname(__DIR__);
$appRoot = dirname($appDir);
$configFile = $appRoot . "/config.php";

// ── Already installed → redirect ──────────────────────────────────────────────
if (is_file($configFile)) {
    $base = setup_base_path();
    header("Location: " . ($base ?: "/"));
    exit();
}

require_once $appDir . "/includes/helpers.php";
require_once $appDir . "/includes/Database.php";
require_once $appDir . "/includes/CryptoService.php";
require_once $appDir . "/includes/Requirements.php";
require_once $appDir . "/includes/config_template.php";
require_once $appDir . "/includes/GuardFile.php";
// Lang needs only these two, which bootstrap.php would define.
define("AESBIN_ROOT", $appRoot);
define("AESBIN_APP_DIR", $appDir);
require_once $appDir . "/includes/Lang.php";

// ── Language support ──────────────────────────────────────────────────────────
// Priority: ?lang=, then Accept-Language, then English. is_string() because
// ?lang[]=x makes it an array. setup_locale() validates the rest.
if (isset($_GET["lang"]) && is_string($_GET["lang"])) {
    $_wantedLang = $_GET["lang"];
} else {
    // "it-IT,it;q=0.9,en;q=0.8" → "it"
    preg_match("/^([a-zA-Z]{2})/", (string) ($_SERVER["HTTP_ACCEPT_LANGUAGE"] ?? "en"), $_m);
    $_wantedLang = $_m[1] ?? "en";
}
[$_setupLang, $_setupStrings] = setup_locale($_wantedLang, $appRoot);
unset($_wantedLang, $_m);

/** HTML-escaped translation for setup UI output. */
function setup_t(string $key): string
{
    global $_setupStrings;
    return e($_setupStrings[$key] ?? $key);
}
/** Raw (unescaped) translation for values used in PHP logic. */
function setup_tr(string $key): string
{
    global $_setupStrings;
    return $_setupStrings[$key] ?? $key;
}

/**
 * True if every named key of a decoded JSON body is absent, null or scalar.
 *
 * The fields go into string interpolation and var_export(), like elsewhere
 * (normalize_body_field()). {"db_host": []} would give "mysql:host=Array";
 * {"storage": []} would be written into config.php as an array and break the
 * next boot. Only the guard-code holder can reach this, but that is no reason
 * to accept it.
 */
function setup_body_scalars(array $body, array $keys): bool
{
    foreach ($keys as $key) {
        if (isset($body[$key]) && !is_scalar($body[$key])) {
            return false;
        }
    }
    return true;
}

// No AESBIN_BASE yet: see setup_base_path().
$baseUrl = setup_base_path();
// Escaped for template.php, which prints only pre-escaped values. SCRIPT_NAME
// is server-generated, so this is defence in depth. $baseUrl stays raw for
// the Location header of the guard-code redirect.
$baseUrlAttr = e($baseUrl);

// The installer answers at the application's base URL only. The front
// controller hands it every request while config.php is missing, so /login,
// a share link or an old /setup/index.php bookmark land here as well: a page
// load is sent to the one address, with its query (?lang=) kept. Requests
// that are not page loads (the wizard's own POSTs) are left alone.
$_setupPath = rawurldecode((string) parse_url((string) ($_SERVER["REQUEST_URI"] ?? "/"), PHP_URL_PATH));
if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "GET" && $_setupPath !== $baseUrl . "/") {
    $_setupQuery = (string) ($_SERVER["QUERY_STRING"] ?? "");
    header("Location: " . $baseUrl . "/" . ($_setupQuery !== "" ? "?" . $_setupQuery : ""));
    exit();
}
unset($_setupPath);

$crypto = new CryptoService();

// Languages for both screens' selectors (lang_select()).
$_setupLangs = Lang::available();

// ═════════════════════════════════════════════════════════════════════════════
// PRE-INSTALL GATES
// ═════════════════════════════════════════════════════════════════════════════
//
// Transport security, then proof that whoever reached this URL controls the
// server's files. Both run before $action is dispatched, so they cover the
// AJAX endpoints too.
//
// There is no database yet, hence no activity_log or rate limiter: every
// defence here is file- or session-based.

// ── Transport security ────────────────────────────────────────────────────────

/**
 * How TLS reaches this request; plain HTTP is refused, since this page
 * collects every secret (the .htaccess redirect is no substitute).
 *   native: $_SERVER["HTTPS"].
 *   proxy:  a forwarded-protocol header; only a claim, but the norm on
 *           shared hosting, and forging it only hurts the forger.
 *   none:   refused, local development included (the app refuses it too).
 *
 * @return "native"|"proxy"|"none"
 */
function setup_tls_state(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (
        !empty($_SERVER["HTTPS"]) &&
        strtolower((string) $_SERVER["HTTPS"]) !== "off"
    ) {
        return $cached = "native";
    }

    $fwd =
        strtolower((string) ($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "")) === "https" ||
        strtolower((string) ($_SERVER["HTTP_X_FORWARDED_SSL"] ?? "")) === "on" ||
        strtolower((string) ($_SERVER["HTTP_CLOUDFRONT_FORWARDED_PROTO"] ?? "")) === "https";

    return $cached = $fwd ? "proxy" : "none";
}

/** The installer's own session cookie; see setup_session_start(). */
const SETUP_SESSION_COOKIE = "__Host-aesbin_setup";

/**
 * Open the installer's session once, with hardened cookie attributes
 * (bootstrap.php is not loaded here). A second session_start() would print a
 * warning into setup.js's JSON. Secure always, since the transport gate runs
 * first; its own name, so it never meets a leftover app session.
 */
function setup_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    session_name(SETUP_SESSION_COOKIE);
    // As in bootstrap.php, which is not loaded here.
    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");
    ini_set("session.use_trans_sid", "0");
    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => true,
        "httponly" => true,
        "samesite" => "Strict",
    ]);
    session_start();
}

// ── Guard file ────────────────────────────────────────────────────────────────

/**
 * The installer's guard directory: app/setup/_viewme/{16 hex}.php (mechanism in
 * app/includes/GuardFile.php). The name is easy to spot in an FTP listing.
 * Removed by bootstrap.php once config.php exists.
 */
function setup_guard_dir(): string
{
    return __DIR__ . "/_viewme";
}

const SETUP_GUARD_MARKER = "AESBIN-GUARD-CODE";

function setup_guard_file(): ?string
{
    return guard_file_find(setup_guard_dir());
}

/** Create the guard file. Throws when it cannot be written. */
function setup_create_guard(): void
{
    $code = guard_file_create(setup_guard_dir(), SETUP_GUARD_MARKER, [
        "AESBin — setup guard",
        "====================",
        "",
        "Paste this code into the installer page to continue.",
        "Delete this file if you abandon the installation.",
    ]);
    if ($code === null) {
        throw new RuntimeException("Cannot write the setup guard file.");
    }
}

/**
 * True once this session has entered the code of the guard file now on disk.
 * The session stores the unlocked filename, not a flag: a new guard has a
 * new random name, so an old session cannot walk back into the installer.
 */
function setup_guard_passed(): bool
{
    setup_session_start();
    $unlocked = $_SESSION["setup_guard_ok"] ?? null;
    if (!is_string($unlocked) || $unlocked === "") {
        return false;
    }
    $current = setup_guard_file();
    return $current !== null && hash_equals(basename($current), $unlocked);
}

// ── Attempt throttling ────────────────────────────────────────────────────────
// No database yet, so the counter is a file next to the guard. Per client
// address, so someone else's noise cannot lock the owner out, except behind a
// reverse proxy, where every client shares one address (trusted_proxies does
// not exist yet). Deleting app/setup/_viewme/ resets it, as the lockout message
// says.

/** Wrong codes allowed before any wait: the owner is the likeliest to typo. */
const SETUP_GUARD_FREE_ATTEMPTS = 2;

/** Wait after the first counted failure, in seconds. Doubles from there. */
const SETUP_GUARD_LOCKOUT_BASE = 300; // 5 minutes

/** Ceiling on the wait: uncapped doubling would strand a fumbling owner. */
const SETUP_GUARD_LOCKOUT_MAX = 3600; // 1 hour

/** 5, 10, 20, 40 minutes, capped at an hour. */
function setup_guard_lockout_seconds(int $failures): int
{
    $steps = $failures - SETUP_GUARD_FREE_ATTEMPTS;
    if ($steps < 1) {
        return 0;
    }
    return (int) min(
        SETUP_GUARD_LOCKOUT_MAX,
        SETUP_GUARD_LOCKOUT_BASE * (2 ** ($steps - 1))
    );
}

function setup_attempts_file(): string
{
    // Clearing the folder clears both. Cannot match the guard's name shape.
    return setup_guard_dir() . "/attempts.php";
}

/**
 * Read the attempt map, dropping entries older than the lockout window.
 *
 * @return array<string, array{n:int, t:int}>  keyed by hashed client address
 */
function setup_attempts_read(): array
{
    $raw = @file_get_contents(setup_attempts_file());
    if ($raw === false) {
        return [];
    }
    // Everything after the stub's closing PHP tag is the JSON payload.
    $pos = strpos($raw, "?>");
    $json = $pos === false ? $raw : substr($raw, $pos + 2);
    $data = json_decode(trim($json), true);
    if (!is_array($data)) {
        return [];
    }

    // Kept for twice the maximum lockout, not for the entry's own wait:
    // dropping an entry when its wait expires, capped one included, would
    // reset the escalation to the free attempts.
    $now = time();
    $out = [];
    foreach ($data as $key => $entry) {
        if (
            is_string($key) &&
            is_array($entry) &&
            isset($entry["n"], $entry["t"]) &&
            $now - (int) $entry["t"] < 2 * SETUP_GUARD_LOCKOUT_MAX
        ) {
            $out[$key] = ["n" => (int) $entry["n"], "t" => (int) $entry["t"]];
        }
    }
    return $out;
}

/** Persist the attempt map, wrapped in the same refusing stub as the guard. */
function setup_attempts_write(array $data): void
{
    $body = "<?php http_response_code(403); exit; ?>\n" .
        json_encode($data, JSON_UNESCAPED_SLASHES);
    @file_put_contents(setup_attempts_file(), $body, LOCK_EX);
    @chmod(setup_attempts_file(), 0640);
}

/** Key for the current client, hashed so the file holds no plaintext IPs. */
function setup_attempts_key(): string
{
    // The same client unit as the application's rate limiter (IPv6 by /64).
    $ip = client_network(unmap_ipv4((string) ($_SERVER["REMOTE_ADDR"] ?? "?")));
    return substr(hash("sha256", $ip), 0, 16);
}

/** Seconds left before this address may try again, or 0 if it may try now. */
function setup_guard_lockout_remaining(): int
{
    $entry = setup_attempts_read()[setup_attempts_key()] ?? null;
    if ($entry === null) {
        return 0;
    }
    $wait = setup_guard_lockout_seconds($entry["n"]);
    if ($wait === 0) {
        return 0;
    }
    return max(0, $entry["t"] + $wait - time());
}

/** Count one wrong code against the current address. */
function setup_guard_record_failure(): void
{
    $data = setup_attempts_read();
    $key = setup_attempts_key();
    $n = ($data[$key]["n"] ?? 0) + 1;
    // The wait is measured from the last failure, so hammering the form does
    // not run the clock down.
    $data[$key] = ["n" => $n, "t" => time()];
    setup_attempts_write($data);
}

/** Forget this address's failures, once the right code arrives. */
function setup_guard_clear_failures(): void
{
    $data = setup_attempts_read();
    unset($data[setup_attempts_key()]);
    setup_attempts_write($data);
}

// ── Bot deterrents ────────────────────────────────────────────────────────────
// Volume filters, not protection: trivial for a targeted attacker to skip.
// They keep scanner traffic from reaching the throttle above.

/**
 * Honeypot field name, derived from the guard filename so it differs per
 * install. SecurityService::honeypotName() seeds from DB_PREFIX, which does
 * not exist yet.
 */
function setup_honeypot_name(string $guardFile): string
{
    return "field_" . substr(hash("sha256", basename($guardFile) . "_hp"), 0, 8);
}

/**
 * True when the submission looks automated: honeypot filled in, or the JS
 * proof missing. The wizard needs JavaScript anyway.
 */
function setup_looks_automated(array $post, string $guardFile): bool
{
    $hp = setup_honeypot_name($guardFile);
    if (isset($post[$hp]) && trim((string) $post[$hp]) !== "") {
        return true;
    }
    $expected = $_SESSION["setup_guard_jsproof"] ?? "";
    $given = (string) ($post["setup_guard_jsproof"] ?? "");
    return $expected === "" || !hash_equals((string) $expected, $given);
}

/** Per-session token the gate page's inline script echoes back. */
function setup_jsproof_token(): string
{
    setup_session_start();
    if (empty($_SESSION["setup_guard_jsproof"])) {
        $_SESSION["setup_guard_jsproof"] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION["setup_guard_jsproof"];
}

// ── Web server and storage checks ─────────────────────────────────────────────

/**
 * The application's base path: "" or e.g. "/aesbin", derived from
 * public/index.php's SCRIPT_NAME exactly as bootstrap.php later derives
 * AESBIN_BASE (without "/public", which is in SCRIPT_NAME when the root
 * .htaccess maps requests into public/). The one place it is computed, so
 * the redirects and the ErrorDocument path agree.
 */
function setup_base_path(): string
{
    $script = (string) ($_SERVER["SCRIPT_NAME"] ?? "/index.php");
    return (string) preg_replace('#/public$#', "", rtrim(dirname($script), "/"));
}

/**
 * The directory the web server serves as its root, or "". SCRIPT_FILENAME
 * minus SCRIPT_NAME; DOCUMENT_ROOT (unreliable on chrooted hosting) only as a
 * fallback, and only if it contains public/: either public/ itself or, with
 * the root .htaccess, the application root above it.
 */
function setup_webroot(string $appRoot): string
{
    $file = str_replace("\\", "/", (string) ($_SERVER["SCRIPT_FILENAME"] ?? ""));
    $url = str_replace("\\", "/", (string) ($_SERVER["SCRIPT_NAME"] ?? ""));
    if ($file !== "" && $url !== "" && str_ends_with($file, $url)) {
        $root = substr($file, 0, -strlen($url));
        if ($root !== "") {
            return realpath($root) ?: $root;
        }
    }

    $docRoot = (string) ($_SERVER["DOCUMENT_ROOT"] ?? "");
    if ($docRoot === "") {
        return "";
    }
    $docRoot = realpath($docRoot) ?: $docRoot;
    $realPublic = realpath($appRoot . "/public") ?: $appRoot . "/public";
    return str_starts_with($realPublic . "/", rtrim($docRoot, "/") . "/")
        ? $docRoot
        : "";
}

/**
 * True when the storage path is inside the document root, i.e. the web server
 * could serve the blobs. bootstrap.php's .htaccess there covers Apache only.
 * The leak is bounded (ciphertext, 128-bit storage_name) but free to avoid.
 *
 * realpath()ed so a symlink or "/../" cannot make an exposed path look external.
 */
function setup_storage_is_exposed(string $storagePath, string $appRoot): bool
{
    // Both roots: setup_webroot() may return "", and anything under public/
    // is reachable by definition. The rest of the application root is not
    // when public/ is the document root, or when the root .htaccess maps every
    // request into public/; setup_webroot() reports it when it is the web root.
    $bases = [];
    $webroot = setup_webroot($appRoot);
    if ($webroot !== "") {
        $bases[] = $webroot;
    }
    $bases[] = realpath($appRoot . "/public") ?: $appRoot . "/public";

    // The directory may not exist yet: resolve its nearest existing ancestor.
    $probe = $storagePath;
    while ($probe !== "" && !file_exists($probe)) {
        $parent = dirname($probe);
        if ($parent === $probe) {
            break;
        }
        $probe = $parent;
    }
    $real = realpath($probe) ?: $storagePath;

    // Neither base is empty: setup_webroot()'s "" is never added, and the
    // public/ one always has a path.
    foreach ($bases as $base) {
        if ($real === $base || str_starts_with($real, rtrim($base, "/") . "/")) {
            return true;
        }
    }
    return false;
}

/**
 * True when $storagePath, or its nearest existing ancestor, is writable.
 * bootstrap.php creates the directory with a silent @mkdir(), so a bad path
 * has to be caught here, before config.php is written.
 */
function setup_storage_is_writable(string $storagePath): bool
{
    $probe = $storagePath;
    while ($probe !== "" && !file_exists($probe)) {
        $parent = dirname($probe);
        if ($parent === $probe) {
            return false;
        }
        $probe = $parent;
    }
    return $probe !== "" && is_writable($probe);
}

/**
 * Validate and normalise a storage path typed into the wizard. Absolute only
 * (bootstrap.php resolves it from each request's working directory); the
 * application root and its ancestors are refused, since files get written
 * into it.
 *
 * @return array{0: ?string, 1: string}  [language key of the error or null,
 *                                        normalised path]
 */
function setup_storage_check(string $storagePath, string $appRoot): array
{
    $path = trim($storagePath);
    if (strlen($path) > 1) {
        $path = rtrim($path, "/\\");
    }

    $absolute =
        str_starts_with($path, "/") ||
        preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    $traversal = preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $path) === 1;
    if (
        $path === "" ||
        !$absolute ||
        $traversal ||
        str_contains($path, "\0") ||
        strlen($path) > 1024
    ) {
        return ["setup_storage_invalid", $path];
    }

    $root = realpath($appRoot) ?: $appRoot;
    $real = file_exists($path) ? realpath($path) : false;
    if (
        $real !== false &&
        ($real === $root || str_starts_with($root . "/", rtrim($real, "/") . "/"))
    ) {
        return ["setup_storage_in_root", $path];
    }

    // Inside public/ every blob would have a URL: refused outright, whatever
    // the server. Anywhere else is the owner's choice.
    if (Requirements::storageInPublic($path, $appRoot)) {
        return ["setup_storage_in_public", $path];
    }

    if (!setup_storage_is_writable($path)) {
        return ["setup_storage_not_writable", $path];
    }
    return [null, $path];
}

/**
 * Storage path recorded by the installation under $prefix, or null (no row
 * or no meta table).
 *
 * Written by the install action and later only by the dashboard, when it
 * differs from config.php. A starting value for the editable reconnect field.
 */
function setup_recorded_storage(PDO $pdo, string $prefix): ?string
{
    if (!valid_db_prefix($prefix)) {
        return null;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT meta_value FROM `{$prefix}meta` WHERE meta_key = 'storage_path' LIMIT 1"
        );
        $stmt->execute();
        $value = $stmt->fetchColumn();
    } catch (PDOException) {
        return null;
    }
    return is_string($value) && $value !== "" ? $value : null;
}

// ── Request plumbing ──────────────────────────────────────────────────────────

/** Answer an AJAX action and stop. */
function setup_reply(array $payload): never
{
    // The same set as the pages: no-store, nosniff and, like everything
    // AESBin answers, not for search results.
    security_headers();
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * The database fields of a wizard request, validated, or null.
 *
 * Shared by the three actions that connect. Host and database name are
 * interpolated into a DSN, where ";" and "=" are syntax: both are refused.
 *
 * @return array{host: string, port: int, name: string, user: string, pass: string, prefix: string}|null
 */
function setup_db_fields(array $body): ?array
{
    $keys = ["db_host", "db_port", "db_name", "db_user", "db_pass", "prefix"];
    if (!setup_body_scalars($body, $keys)) {
        return null;
    }
    $f = [
        "host" => trim((string) ($body["db_host"] ?? "")),
        "port" => (int) ($body["db_port"] ?? 3306),
        "name" => trim((string) ($body["db_name"] ?? "")),
        "user" => trim((string) ($body["db_user"] ?? "")),
        "pass" => (string) ($body["db_pass"] ?? ""),
        "prefix" => trim((string) ($body["prefix"] ?? "")),
    ];
    if ($f["host"] === "") {
        $f["host"] = "localhost";
    }
    if (
        $f["name"] === "" ||
        $f["user"] === "" ||
        !valid_db_prefix($f["prefix"]) ||
        $f["port"] < 1 ||
        $f["port"] > 65535 ||
        preg_match('/[;=]/', $f["host"] . $f["name"]) === 1
    ) {
        return null;
    }
    return $f;
}

/**
 * Connect with fields from setup_db_fields(). A PDOException carries host,
 * database and user in its message: callers log it and answer flat.
 */
function setup_pdo(array $f): PDO
{
    $pdo = new PDO(
        "mysql:host={$f["host"]};port={$f["port"]};dbname={$f["name"]};charset=utf8mb4",
        $f["user"],
        $f["pass"],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]
    );
    // UTC and strict mode, as the application's connection: the rows written
    // here (auth, the first container and folder, meta) carry timestamps
    // the dashboard reads as UTC.
    Database::prepareSession($pdo);
    return $pdo;
}

/**
 * A locale that exists, and its strings: [code, strings]. Falls back to
 * English unless app/lang/<code>.php returns an array, which also rules out the
 * app/lang/index.php listing guard.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function setup_locale(string $wanted, string $appRoot): array
{
    $code = preg_replace("/[^a-z_]/", "", strtolower($wanted)) ?? "";
    $file = $appRoot . "/app/lang/" . $code . ".php";
    $strings = $code !== "" && is_file($file) ? require $file : null;
    if (!is_array($strings)) {
        return ["en", require $appRoot . "/app/lang/en.php"];
    }
    return [$code, $strings];
}

// ── Secrets the installer generates ───────────────────────────────────────────

/**
 * What the session keeps of the TOTP secret and the recovery phrase: a hash,
 * never the value.
 *
 * Both are generated here with known entropy (160 and 110 bits), so a plain
 * hash is sufficient, and an abandoned session file holds nothing worth
 * stealing. The browser keeps the plaintext and sends it back; matching the
 * hash proves it is the value this server issued.
 */
function setup_secret_hash(string $secret): string
{
    return hash("sha256", "aesbin-setup:" . $secret);
}

/** True when $given is the secret whose hash sits in $_SESSION[$slot]. */
function setup_secret_matches(string $given, string $slot): bool
{
    $stored = $_SESSION[$slot] ?? null;
    return is_string($stored) &&
        $given !== "" &&
        hash_equals($stored, setup_secret_hash($given));
}

/**
 * End the installer's session and any application session the browser still
 * holds, before handing over to the application.
 *
 * An application cookie (__Host-aesbin_session) left from a previous
 * installation on this host would reach the new one with auth_ok set and a
 * MASTER_KEY it cannot unwrap.
 */
function setup_end_session(): void
{
    setup_session_start();
    $_SESSION = [];
    session_destroy();
    // The same attributes setup_session_start() and bootstrap.php set, or
    // the browser keeps the cookie.
    $expired = [
        "expires" => time() - 3600,
        "path" => "/",
        "secure" => true,
        "httponly" => true,
        "samesite" => "Strict",
    ];
    setcookie(SETUP_SESSION_COOKIE, "", $expired);
    setcookie(SESSION_COOKIE, "", $expired);
}

/**
 * The security headers of every installer page, gate screens and wizard
 * alike, with a fresh CSP nonce for its inline script, which is returned.
 */
function setup_page_headers(): string
{
    $nonce = base64_encode(random_bytes(16));
    header_remove("X-Powered-By"); // as app/includes/bootstrap.php
    // The application's own list (helpers.php), X-Robots-Tag included. No
    // HSTS, as everywhere in the app: it is the server's to set
    // (docs/security-model.md § Design notes).
    security_headers();
    header(
        "Content-Security-Policy: default-src 'self'; " .
            "script-src 'self' 'nonce-{$nonce}'; " .
            "style-src 'self'; img-src 'self' data:; " .
            "form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'"
    );
    return $nonce;
}

/**
 * Refuse to go further: send the headers, draw the gate screen, stop.
 *
 * Never returns, so the rest of this file (above all the AJAX dispatch)
 * cannot run after a refusal.
 *
 * @param string      $titleKey  Language key for the heading
 * @param string      $bodyKey   Language key for the explanation
 * @param int         $status    HTTP status
 * @param string|null $guardFile Guard file path when the code form is wanted
 * @param string      $error     Pre-escaped error message for the form
 */
function setup_gate(
    string $titleKey,
    string $bodyKey,
    int $status,
    ?string $guardFile = null,
    string $error = ""
): never {
    // The template reads these from file scope.
    global $_setupLang, $_setupLangs, $baseUrl, $baseUrlAttr;

    http_response_code($status);
    header("Content-Type: text/html; charset=utf-8");
    $nonce = setup_page_headers();

    $gateScreen = [
        "title" => setup_t($titleKey),
        "body" => setup_t($bodyKey),
        "nonce" => $nonce,
        "error" => $error,
        // null when this screen carries no form.
        "form" => $guardFile === null
            ? null
            : [
                "honeypot" => setup_honeypot_name($guardFile),
                "jsproof" => setup_jsproof_token(),
            ],
    ];

    require __DIR__ . "/template.php";
    exit();
}

if (setup_tls_state() === "none") {
    // Refused outright: see setup_tls_state(). bootstrap.php would refuse to
    // serve the app over plain HTTP anyway.
    setup_gate(
        "setup_https_required_title",
        "setup_https_required_body",
        403
    );
}

// Guard code. Otherwise whoever reaches this URL first could point the
// install at a database they control and take the instance over. The code
// never appears in a response, so continuing needs filesystem access.
//
// Without config.php, public/index.php hands every request to this file,
// recovery included, so this covers them too.
if (!setup_guard_passed()) {
    $_guardFile = setup_guard_file();

    if ($_guardFile === null) {
        // First contact: create the file, show the instructions, stop.
        try {
            setup_create_guard();
        } catch (Throwable $e) {
            error_log("[AESBin setup] guard file: " . $e->getMessage());
            setup_gate(
                "setup_guard_write_failed_title",
                "setup_guard_write_failed_body",
                500
            );
        }
        $_guardFile = setup_guard_file();
    }

    $_guardErr = "";
    if (
        $_SERVER["REQUEST_METHOD"] === "POST" &&
        isset($_POST["setup_guard_code"]) &&
        is_string($_POST["setup_guard_code"]) &&
        $_guardFile !== null
    ) {
        // Held until the request ends: the check below and the failure it may
        // record are one read-modify-write, and parallel requests would
        // otherwise all pass the check before any of them counts. A separate
        // file, since the attempts file itself is rewritten under LOCK_EX.
        $_lock = @fopen(setup_guard_dir() . "/attempts.lock", "c");
        if ($_lock !== false) {
            flock($_lock, LOCK_EX);
        }

        // Throttle before comparing or counting, so a blocked address cannot
        // push its lockout forward or learn anything from timing.
        $_given = strtoupper(trim($_POST["setup_guard_code"]));
        $_wait = setup_guard_lockout_remaining();
        if ($_wait > 0) {
            $_guardErr = setup_t("setup_guard_throttled");
        } elseif (preg_match('/^[A-F0-9]{8}\z/', $_given) !== 1) {
            // Not eight hex characters: a slip, not a guess. Not compared and
            // not counted, so a stray Enter cannot lock the owner out.
            $_guardErr = setup_t("setup_guard_format");
        } elseif (setup_looks_automated($_POST, $_guardFile)) {
            // Answered like a wrong code but not counted, so a scanner cannot
            // spend the owner's attempt budget.
            usleep(random_int(200000, 400000));
            $_guardErr = setup_t("setup_guard_wrong");
        } else {
            $_expected = guard_file_read($_guardFile, SETUP_GUARD_MARKER);
            if ($_expected !== null && hash_equals($_expected, $_given)) {
                setup_guard_clear_failures();
                setup_session_start();
                session_regenerate_id(true);
                // The filename, not true: see setup_guard_passed().
                $_SESSION["setup_guard_ok"] = basename((string) $_guardFile);
                // Post/Redirect/Get to a locally built path, never REQUEST_URI.
                // $_setupLang is reduced to [a-z_] and matched to an existing
                // lang file, so it is safe in the URL.
                header(
                    "Location: " . ($baseUrl ?: "") . "/?lang=" .
                        rawurlencode($_setupLang)
                );
                exit();
            }
            setup_guard_record_failure();
            usleep(random_int(200000, 400000));
            // No database yet, so the error log is the only trace.
            error_log(
                "[AESBin setup] wrong guard code from " .
                    ($_SERVER["REMOTE_ADDR"] ?? "?")
            );
            $_guardErr = setup_guard_lockout_remaining() > 0
                ? setup_t("setup_guard_throttled")
                : setup_t("setup_guard_wrong");
        }
    }

    setup_gate(
        "setup_guard_title",
        "setup_guard_body",
        403,
        $_guardFile,
        $_guardErr
    );
}
unset($_guardFile, $_guardErr, $_expected, $_given, $_wait, $_lock);

// ── Requirements ──────────────────────────────────────────────────────────────
// Shared with the dashboard (app/includes/Requirements.php). Only the blocking
// half gates the wizard.
$reqs = Requirements::blocking($appRoot);
// trusted_proxies empty: with no config.php yet, the client-IP check should
// answer whether PHP sees the visitor at all. No storage: it is chosen at
// step 2.
$reqsSoft = Requirements::advisory([
    "root" => $appRoot,
    "base" => setup_base_path(),
    "trusted_proxies" => [],
    "installer" => true,
]);
$allOk = true;
foreach ($reqs as $_r) {
    if (!$_r["ok"]) {
        $allOk = false;
        break;
    }
}
unset($_r);

// ── AJAX endpoints (called by JS during setup steps) ──────────────────────────
$action = $_GET["action"] ?? "";

// With a blocking requirement missing the wizard is not rendered, but the
// handlers are still reachable (stale setup.js, a hand-made POST). install
// would then fail inside CryptoService after creating the tables, so every
// action is refused.
if ($action !== "" && !$allOk) {
    http_response_code(503);
    setup_reply(["ok" => false, "error" => setup_tr("setup_reqs_fix")]);
}

// The actions are fetch() calls from setup.js on this same page: one that
// the browser marks as coming from anywhere else (Sec-Fetch-Site, which page
// script cannot set) is refused. An older browser that sends no
// Sec-Fetch-Site passes: the session cookie (SameSite=Strict) and the guard
// code still stand in front.
$_fetchSite = $_SERVER["HTTP_SEC_FETCH_SITE"] ?? null;
if ($action !== "" && $_fetchSite !== null && $_fetchSite !== "same-origin") {
    http_response_code(403);
    setup_reply(["ok" => false, "error" => setup_tr("csrf_failed")]);
}
unset($_fetchSite);

// ── Database connection test (step 1) ─────────────────────────────────────────
// Also the wizard's branch point: existing installations are returned with
// their recorded storage paths, to reconnect to or install beside.
if ($action === "test_db" && $_SERVER["REQUEST_METHOD"] === "POST") {
    $f = setup_db_fields(json_body());
    if ($f === null) {
        setup_reply(["ok" => false, "error" => setup_tr("setup_db_required")]);
    }

    try {
        $pdo = setup_pdo($f);

        // Refused before anything is created: an older server accepts the
        // schema and fails later, on the first JSON query.
        $dbServer = Requirements::databaseServer($pdo);
        if (!Requirements::databaseSupported($dbServer["version"])) {
            setup_reply([
                "ok" => false,
                "error" => Lang::fill(setup_tr("setup_db_version_unsupported"), [
                    "server" => $dbServer["name"],
                    "mysql" => Requirements::short(Requirements::MYSQL_MIN),
                    "mariadb" => Requirements::short(Requirements::MARIADB_MIN),
                ]),
            ]);
        }

        // Two probes: the wildcard matches the default "aesbin_" family, the
        // exact one a custom prefix. This only picks the screen; the check in
        // the install action is what prevents installing over an account.
        // information_schema because SHOW TABLES takes no placeholder under
        // native prepares.
        $stmt = $pdo->prepare(
            "SELECT table_name FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name LIKE 'aesbin\\_%\\_auth'"
        );
        $stmt->execute();
        $existingPrefixes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
            // Table name minus "auth". Must pass valid_db_prefix(): it is
            // interpolated into SQL below, and verify_existing requires it.
            $candidate = substr((string) $table, 0, -4);
            if (valid_db_prefix($candidate)) {
                $existingPrefixes[] = $candidate;
            }
        }

        $stmt = $pdo->prepare(
            "SELECT table_name FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = :t"
        );
        $stmt->execute([":t" => $f["prefix"] . "auth"]);
        if (
            $stmt->fetchColumn() !== false &&
            !in_array($f["prefix"], $existingPrefixes, true)
        ) {
            $existingPrefixes[] = $f["prefix"];
        }

        $installs = [];
        foreach ($existingPrefixes as $existingPrefix) {
            $installs[] = [
                "prefix" => $existingPrefix,
                "storage" => setup_recorded_storage($pdo, $existingPrefix),
            ];
        }

        setup_reply([
            "ok" => true,
            "server" => $dbServer["name"],
            "existing" => $installs !== [],
            "installs" => $installs,
        ]);
    } catch (PDOException $e) {
        // Never echo the driver message: it carries host, database and user,
        // and differentiated errors would make this a port-scanning oracle.
        error_log("[AESBin setup] test_db: " . $e->getMessage());
        setup_reply(["ok" => false, "error" => setup_tr("setup_db_connect_failed")]);
    }
}

// ── Storage path check (step 2, and the reconnect form) ───────────────────────
// The same validation as install and verify_existing, run early so errors
// show where the path was typed. Read-only. "warning" never blocks.
if ($action === "check_storage" && $_SERVER["REQUEST_METHOD"] === "POST") {
    $body = json_body();
    if (!setup_body_scalars($body, ["storage"])) {
        setup_reply(["ok" => false, "error" => setup_tr("setup_storage_invalid")]);
    }
    [$storageErr, $storage] = setup_storage_check(
        (string) ($body["storage"] ?? ""),
        $appRoot
    );
    if ($storageErr !== null) {
        setup_reply(["ok" => false, "error" => setup_tr($storageErr)]);
    }
    setup_reply([
        "ok" => true,
        "storage" => $storage,
        "warning" => !Requirements::serverReadsHtaccess() &&
            setup_storage_is_exposed($storage, $appRoot)
            ? setup_tr("setup_storage_exposed_hint")
            : null,
    ]);
}

// ── TOTP secret (entering the password step) ──────────────────────────────────
// A new secret on every call, since the page holds the only plaintext copy.
// It also voids any earlier verification.
if ($action === "totp_init" && $_SERVER["REQUEST_METHOD"] === "POST") {
    setup_session_start();
    $secret = $crypto->generateTotpSecret();
    $_SESSION["setup_totp_hash"] = setup_secret_hash($secret);
    unset($_SESSION["setup_totp_verified"]);
    setup_reply([
        "ok" => true,
        "totp_secret" => $secret,
        "totp_uri" => totp_uri($crypto, $secret),
    ]);
}

// ── TOTP verification ─────────────────────────────────────────────────────────
// Recorded server-side, so install does not have to trust setup.js that the
// authenticator produced a valid code for this secret.
if ($action === "verify_totp" && $_SERVER["REQUEST_METHOD"] === "POST") {
    setup_session_start();
    $body = json_body();
    if (!setup_body_scalars($body, ["code", "secret"])) {
        setup_reply(["ok" => false]);
    }
    $secret = (string) ($body["secret"] ?? "");
    $code = trim((string) ($body["code"] ?? ""));
    if (!setup_secret_matches($secret, "setup_totp_hash")) {
        setup_reply(["ok" => false]);
    }
    $ok = $crypto->verifyTotp($secret, $code) !== false;
    $_SESSION["setup_totp_verified"] = $ok ? setup_secret_hash($secret) : null;
    setup_reply(["ok" => $ok]);
}

// ── Recovery phrase ───────────────────────────────────────────────────────────
// Only its hash stays in the session, so install can check it gets back the
// phrase issued here.
if ($action === "gen_phrase" && $_SERVER["REQUEST_METHOD"] === "POST") {
    setup_session_start();
    $phrase = $crypto->generateRecoveryPhrase();
    $_SESSION["setup_phrase_hash"] = setup_secret_hash($phrase);
    setup_reply(["ok" => true, "phrase" => $phrase]);
}

// ── Install ───────────────────────────────────────────────────────────────────
// Everything arrives in this request body; the session contributes only the
// hashes (see setup_secret_hash()).
if ($action === "install" && $_SERVER["REQUEST_METHOD"] === "POST") {
    setup_session_start();
    $body = json_body();

    $f = setup_db_fields($body);
    if (
        $f === null ||
        !setup_body_scalars($body, ["storage", "locale", "password", "totp_secret", "phrase"])
    ) {
        setup_reply(["ok" => false, "error" => setup_tr("setup_db_required")]);
    }

    [$storageErr, $storage] = setup_storage_check(
        (string) ($body["storage"] ?? ""),
        $appRoot
    );
    if ($storageErr !== null) {
        setup_reply(["ok" => false, "error" => setup_tr($storageErr)]);
    }

    // The default container and directory are named in the chosen language.
    [$locale, $_setupStrings] = setup_locale((string) ($body["locale"] ?? "en"), $appRoot);

    $password = (string) ($body["password"] ?? "");
    $totpSec = (string) ($body["totp_secret"] ?? "");
    $phrase = (string) ($body["phrase"] ?? "");

    if (mb_strlen($password) < CryptoService::PASSWORD_MIN_LENGTH) {
        setup_reply(["ok" => false, "error" => setup_tr("password_too_short")]);
    }

    // Issued here and verified with a code before a vault is built on it.
    $verified = $_SESSION["setup_totp_verified"] ?? null;
    if (
        !setup_secret_matches($totpSec, "setup_totp_hash") ||
        !is_string($verified) ||
        !hash_equals($verified, setup_secret_hash($totpSec))
    ) {
        setup_reply(["ok" => false, "error" => setup_tr("setup_totp_required")]);
    }

    if (!setup_secret_matches($phrase, "setup_phrase_hash")) {
        setup_reply(["ok" => false, "error" => setup_tr("setup_phrase_mismatch")]);
    }

    $prefix = $f["prefix"];

    // Set once connected: tells a refused connection from a failure further
    // on (schema, keys), which needs a different message.
    $pdo = null;
    try {
        $pdo = setup_pdo($f);

        // Never install over an existing account row. The auth INSERT below
        // is ON DUPLICATE KEY UPDATE and would replace wrapped_master with a
        // new MASTER_KEY, making every stored blob undecryptable. The UI
        // routes existing installations to verify_existing, but a direct POST
        // reaches this. A half-finished install (tables, no auth row) can
        // still be completed.
        try {
            $chk = $pdo->query("SELECT 1 FROM `{$prefix}auth` WHERE id = 1 LIMIT 1");
            $alreadyInstalled = $chk !== false && $chk->fetchColumn() !== false;
        } catch (PDOException) {
            $alreadyInstalled = false;
        }
        if ($alreadyInstalled) {
            setup_reply(["ok" => false, "error" => setup_tr("setup_existing")]);
        }

        // Whole-line comments go first, so their semicolons are not split on.
        $schema = str_replace("{P}", $prefix, (string) (require __DIR__ . "/schema-sql.php"));
        $schema = preg_replace('/^\s*--.*$/m', "", $schema) ?? "";

        // A context-free split on ";", valid only under the rule stated at
        // the top of schema-sql.php. An odd quote count means the split
        // landed inside a literal: fail rather than run half a statement.
        foreach (array_filter(array_map("trim", explode(";", $schema))) as $sql) {
            if (substr_count($sql, "'") % 2 !== 0) {
                throw new RuntimeException(
                    "Schema statement split inside a string literal: " .
                        "app/setup/schema-sql.php must not contain literal " .
                        "semicolons inside quoted text."
                );
            }
            $pdo->exec($sql);
        }

        // Offered as the starting value on reconnect (setup_recorded_storage()).
        // verify_existing never writes it, since that would record whatever
        // was typed; the dashboard updates it (app/actions/system/storage_meta.php).
        $pdo->prepare(
            "INSERT INTO `{$prefix}meta` (meta_key, meta_value) VALUES ('storage_path', :p)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
        )->execute([":p" => $storage]);

        // MASTER_KEY lives in this request's memory only.
        $masterKey = $crypto->generateMasterKey();

        $kekSalt = $crypto->generateSalt();
        $kek = $crypto->deriveKek($password, $kekSalt);
        $wm = $crypto->wrapMasterKey($masterKey, $kek);
        sodium_memzero($kek);

        $rKekSalt = $crypto->generateSalt();
        $rKek = $crypto->deriveKek($phrase, $rKekSalt);
        $rwm = $crypto->wrapMasterKey($masterKey, $rKek);
        sodium_memzero($rKek);

        $encTotp = $crypto->encryptMeta($totpSec, $masterKey, CryptoService::AAD_TOTP);

        // ON DUPLICATE KEY UPDATE completes a run that died after creating the
        // tables; an existing row was already refused above.
        $pdo->prepare(
            "INSERT INTO `{$prefix}auth`
                (id, password_hash, kek_salt,
                 wrapped_master, wrapped_master_iv, wrapped_master_tag,
                 recovery_kek_salt,
                 recovery_wrapped_master, recovery_wrapped_master_iv, recovery_wrapped_master_tag,
                 recovery_hash,
                 totp_secret_enc, totp_secret_iv, totp_secret_tag)
                VALUES (1,:ph,:ks,:wm,:wmi,:wmt,:rks,:rwm,:rwmi,:rwmt,:rh,:tse,:tsiv,:tstag)
                ON DUPLICATE KEY UPDATE
                    password_hash=VALUES(password_hash), kek_salt=VALUES(kek_salt),
                    wrapped_master=VALUES(wrapped_master), wrapped_master_iv=VALUES(wrapped_master_iv),
                    wrapped_master_tag=VALUES(wrapped_master_tag),
                    recovery_kek_salt=VALUES(recovery_kek_salt),
                    recovery_wrapped_master=VALUES(recovery_wrapped_master),
                    recovery_wrapped_master_iv=VALUES(recovery_wrapped_master_iv),
                    recovery_wrapped_master_tag=VALUES(recovery_wrapped_master_tag),
                    recovery_hash=VALUES(recovery_hash),
                    totp_secret_enc=VALUES(totp_secret_enc),
                    totp_secret_iv=VALUES(totp_secret_iv),
                    totp_secret_tag=VALUES(totp_secret_tag)"
        )->execute([
            ":ph" => CryptoService::hashPassword($password),
            ":ks" => $kekSalt,
            ":wm" => $wm["enc"],
            ":wmi" => $wm["iv"],
            ":wmt" => $wm["tag"],
            ":rks" => $rKekSalt,
            ":rwm" => $rwm["enc"],
            ":rwmi" => $rwm["iv"],
            ":rwmt" => $rwm["tag"],
            ":rh" => CryptoService::hashPassword($phrase),
            ":tse" => $encTotp["enc"],
            ":tsiv" => $encTotp["iv"],
            ":tstag" => $encTotp["tag"],
        ]);

        // Default container and directory, named under the same id-bound AAD
        // as all others (CryptoService::rowAad()).
        $encContainerName = $crypto->encryptMeta(
            setup_tr("setup_default_container"),
            $masterKey,
            CryptoService::rowAad("container", 1),
        );
        $pdo->prepare(
            "INSERT INTO `{$prefix}containers` (id, name_enc, name_iv, name_tag)
                VALUES (1, :e, :i, :t)
                ON DUPLICATE KEY UPDATE name_enc=VALUES(name_enc), name_iv=VALUES(name_iv), name_tag=VALUES(name_tag)"
        )->execute([
            ":e" => $encContainerName["enc"],
            ":i" => $encContainerName["iv"],
            ":t" => $encContainerName["tag"],
        ]);

        $encDirName = $crypto->encryptMeta(
            setup_tr("setup_default_directory"),
            $masterKey,
            CryptoService::rowAad("directory", 1),
        );
        $pdo->prepare(
            "INSERT INTO `{$prefix}directories` (id, container_id, name_enc, name_iv, name_tag)
                VALUES (1, 1, :e, :i, :t)
                ON DUPLICATE KEY UPDATE
                    name_enc=VALUES(name_enc), name_iv=VALUES(name_iv), name_tag=VALUES(name_tag)"
        )->execute([
            ":e" => $encDirName["enc"],
            ":i" => $encDirName["iv"],
            ":t" => $encDirName["tag"],
        ]);

        sodium_memzero($masterKey);

        if (!write_config_file($configFile, build_config_php($f, $storage, $locale))) {
            setup_reply(["ok" => false, "error" => setup_tr("setup_config_write_failed")]);
        }
        guard_dir_clear(setup_guard_dir());

        // Advisories for the final screen. The first two exclude each other:
        // the storage one needs .htaccess ignored, the 403 one needs it read.
        // The security.txt note, for a subdirectory install, can join either.
        $advisories = [];
        if (
            !Requirements::serverReadsHtaccess() &&
            setup_storage_is_exposed($storage, $appRoot)
        ) {
            $advisories[] = setup_tr("setup_storage_exposed_warning");
        }
        $base = setup_base_path();
        if (
            Requirements::serverReadsHtaccess() &&
            !Requirements::wireErrorDocument($appRoot, $base)
        ) {
            $advisories[] =
                setup_tr("setup_error_document_manual") .
                "\n\n" .
                Requirements::errorDocumentLine($base);
        }
        // Not a problem, but the only moment the owner is sure to read it:
        // a subdirectory install publishes no security.txt (see
        // security_txt_served() in helpers.php), and docs say why.
        if ($base !== "") {
            $advisories[] = setup_tr("setup_security_txt_subdir");
        }

        setup_end_session();
        setup_reply([
            "ok" => true,
            "warning" => $advisories ? implode("\n\n", $advisories) : null,
        ]);
    } catch (Throwable $e) {
        // Never the raw exception: it can carry host, database, user, table
        // and column names.
        error_log("[AESBin setup] install: " . get_class($e) . ": " . $e->getMessage());
        setup_reply([
            "ok" => false,
            "error" => setup_tr($pdo === null ? "setup_db_connect_failed" : "setup_install_error"),
        ]);
    }
}

// ── Reconnect to an existing installation ─────────────────────────────────────
// Rebuilds config.php for a database that already holds an auth row. No
// recovery phrase needed: the guard code already proves filesystem access.
//
// The storage path is checked for usability, not for being the right folder.
// A wrong one shows up in the dashboard's first integrity check as every file
// missing; nothing is destroyed.
if ($action === "verify_existing" && $_SERVER["REQUEST_METHOD"] === "POST") {
    $body = json_body();
    $f = setup_db_fields($body);
    if ($f === null || !setup_body_scalars($body, ["storage", "locale"])) {
        setup_reply(["ok" => false, "error" => setup_tr("setup_db_required")]);
    }

    [$storageErr, $storage] = setup_storage_check(
        (string) ($body["storage"] ?? ""),
        $appRoot
    );
    if ($storageErr !== null) {
        setup_reply(["ok" => false, "error" => setup_tr($storageErr)]);
    }
    [$locale] = setup_locale((string) ($body["locale"] ?? "en"), $appRoot);

    try {
        $pdo = setup_pdo($f);
        $stmt = $pdo->prepare("SELECT id FROM `{$f["prefix"]}auth` WHERE id = 1 LIMIT 1");
        $stmt->execute();
        if ($stmt->fetch(PDO::FETCH_ASSOC) === false) {
            setup_reply(["ok" => false, "error" => setup_tr("setup_verify_failed")]);
        }

        if (!write_config_file($configFile, build_config_php($f, $storage, $locale))) {
            setup_reply(["ok" => false, "error" => setup_tr("setup_config_write_failed")]);
        }
        guard_dir_clear(setup_guard_dir());
        error_log("[AESBin setup] reconnected to prefix " . $f["prefix"]);

        setup_end_session();
        setup_reply(["ok" => true]);
    } catch (Throwable $e) {
        error_log("[AESBin setup] verify_existing: " . $e->getMessage());
        setup_reply(["ok" => false, "error" => setup_tr("setup_verify_failed")]);
    }
}

// ── Page render ───────────────────────────────────────────────────────────────
setup_session_start();

// Same shape as newPrefix() in setup.js.
$suggestedPrefix = "aesbin_" . bin2hex(random_bytes(4)) . "_";

// Shown beside the recovery phrase, so it can be filed with its instance.
$currentHost = e(host_label());

// ── Client-side language bundle ───────────────────────────────────────────────

/**
 * Every setup string, for every language, so the selector can switch without
 * a reload. $shared lists keys outside setup_ used through data-t or t().
 */
function setup_client_strings(string $appRoot): array
{
    $shared = [
        "continue", "copied", "copy", "language", "password", "password_confirm",
        "password_mismatch", "password_too_short", "pw_toggle_label", "pw_strength_0",
        "pw_strength_1", "pw_strength_2", "pw_strength_3", "pw_strength_4",
        // helper.js: downloadRecoveryPhrase(), the copy buttons, fetchJson().
        "recovery_file_title", "recovery_file_note", "copy_failed", "err_network",
    ];

    $out = [];
    foreach (glob($appRoot . "/app/lang/*.php") ?: [] as $file) {
        $code = basename($file, ".php");
        $all = require $file;
        // app/lang/index.php (listing guard) returns int(1), which
        // array_filter() would reject with a TypeError.
        if (!is_array($all)) {
            continue;
        }
        $out[$code] = array_filter(
            $all,
            static fn($k) => str_starts_with($k, "setup_") ||
                // Used by the requirements screen through data-t.
                str_starts_with($k, "req_hint_") ||
                str_starts_with($k, "reqs_group_") ||
                in_array($k, $shared, true),
            ARRAY_FILTER_USE_KEY
        );
    }
    return $out;
}

$_allLangs = setup_client_strings($appRoot);

// ── Suggested storage path ────────────────────────────────────────────────────
// Next to public/, inside the application: out of the web root with either
// layout (public/ as the document root, or the top .htaccess mapping every
// request into it), and in the folder a backup of the site already takes.
// The owner may type another. Random suffix all the same, so the folder is
// not guessable where the server is pointed at the project folder and
// ignores .htaccess.
$suggestedStorage = $appRoot . "/_storage-" . bin2hex(random_bytes(4));

$setupNonce = setup_page_headers();

// ── Render ────────────────────────────────────────────────────────────────────
// No gate refused (setup_gate() never returns), so this draws the wizard.
require __DIR__ . "/template.php";
