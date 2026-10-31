<?php

/**
 * app/includes/helpers.php — global utility functions, loaded by bootstrap.php and
 * by the installer (app/setup/index.php), which uses a few of them.
 */

declare(strict_types=1);

use AESBin\CryptoService;
use AESBin\FileService;
use AESBin\Lang;
use AESBin\Requirements;

defined("AESBIN_APP") || exit;

// ── URL helpers ───────────────────────────────────────────────────────────────

/**
 * Build a root-relative path from the app base path, e.g.
 * base_url('favicon.ico') → '/favicon.ico' or '/aesbin/favicon.ico'.
 */
function base_url(string $path = ""): string
{
    $base = defined("AESBIN_BASE") ? AESBIN_BASE : "";
    return $base . "/" . ltrim($path, "/");
}

/**
 * The path the router matches, from a canonical one: no trailing slash, and
 * the base path stripped so routing works in a subdirectory (with AESBIN_BASE
 * "/aesbin", "/aesbin/api/files" becomes "/api/files"). Stripped on whole
 * segments only: "/aesbinary" is not rewritten to "ary". The root is "/".
 *
 * The request path arrives percent-encoded, while AESBIN_BASE comes from
 * SCRIPT_NAME, which the server has already decoded: the leading segments
 * are compared decoded, or a folder named "my files" (sent as "my%20files")
 * would never match and every route would 404.
 */
function aesbin_route_path(string $canonicalPath): string
{
    $path = "/" . trim($canonicalPath, "/");
    if (AESBIN_BASE !== "") {
        $segments = explode("/", $path);
        $depth = substr_count(AESBIN_BASE, "/");
        if (
            count($segments) > $depth &&
            rawurldecode(implode("/", array_slice($segments, 0, $depth + 1))) === AESBIN_BASE
        ) {
            $path = "/" . implode("/", array_slice($segments, $depth + 1));
        }
    }
    return $path;
}

/**
 * Canonical form of a request path: repeated slashes collapsed, "." and ".."
 * segments resolved, and backslashes, spaces and control characters treated
 * as separators (a browser reads "/\host" in a Location header as "//host",
 * an open redirect). A trailing slash is kept. Not parse_url(), which
 * returns false on inputs such as "//x:y".
 */
function aesbin_canonical_path(string $path): string
{
    // ?? "/": preg_replace() returns null on a PCRE error.
    $path = preg_replace('#[\\\\\x00-\x20\x7f]#', "/", $path) ?? "/";
    $out = [];
    foreach (explode("/", $path) as $seg) {
        if ($seg === "" || $seg === ".") {
            continue;
        }
        if ($seg === "..") {
            array_pop($out);
            continue;
        }
        $out[] = $seg;
    }
    $clean = "/" . implode("/", $out);
    // Keep a trailing slash ("/login/"); a final "." or ".." counts as one.
    if ($out !== [] && preg_match('#/(?:\.{1,2})?$#', $path)) {
        $clean .= "/";
    }
    return $clean;
}

/**
 * URL for a static asset (path relative to the app root) with its
 * cache-buster; see asset_version().
 */
function asset(string $path): string
{
    return base_url($path) . asset_version($path);
}

/**
 * "?v=<modification time>" for a static file (path relative to public/, as in
 * its URL), or "" when it is missing. public/.htaccess caches static files for a
 * year, so every URL printed for one carries this: a changed file gets a new
 * URL. On its own for the callers whose base is not AESBIN_BASE (the
 * installer, lang_select()'s $assetBase); everything else uses asset().
 */
function asset_version(string $path): string
{
    $full = (defined("AESBIN_ROOT") ? AESBIN_ROOT : "") . "/public/" . ltrim($path, "/");
    return is_file($full) ? "?v=" . filemtime($full) : "";
}

/**
 * The owner's session cookie. __Host- requires Secure, Path=/ and no Domain,
 * so a subdirectory install still owns the whole host's name.
 */
const SESSION_COOKIE = "__Host-aesbin_session";

/**
 * Cookie holding the language a visitor picked on the share or privacy page.
 * Set only by that choice (visitor_lang_choice()), and holding only the code.
 * __Host-, like SESSION_COOKIE: the browser then refuses one set by another
 * subdomain or over plain HTTP, and it is scoped to this host's Path=/, so it
 * follows the same one-installation-per-host rule.
 */
const VISITOR_LANG_COOKIE = "__Host-aesbin_lang";

/**
 * Prefix of the cookie carrying a share visitor's session
 * (ShareService::authenticate(), SESSION_TTL); the link's token completes the
 * name, so each link has its own. __Host-, as OWASP recommends for session
 * identifiers: Secure, no Domain and Path=/, so no other subdomain of this
 * site can set or overwrite it (__Host- requires Path=/, hence the token in
 * the name rather than in the path). HttpOnly: no script on the page, an
 * injected one included, can read it. SameSite=Strict: no other site can make
 * the browser send it. It holds a random token and nothing about the visitor.
 */
const SHARE_SESSION_COOKIE_PREFIX = "__Host-aesbin_share_";

/** Set the visitor session cookie of a link, expiring with its session. */
function share_session_cookie(string $shareToken, string $rawToken, int $expires): void
{
    setcookie(SHARE_SESSION_COOKIE_PREFIX . $shareToken, $rawToken, [
        "expires" => $expires,
        "path" => "/",
        "secure" => true,
        "httponly" => true,
        "samesite" => "Strict",
    ]);
}

/** The visitor session this request carries for a link, "" without one. */
function share_session_cookie_value(string $shareToken): string
{
    $raw = $_COOKIE[SHARE_SESSION_COOKIE_PREFIX . $shareToken] ?? "";
    return is_string($raw) ? $raw : "";
}

/** Every link visit this browser holds: link token => session token. */
function share_session_cookies(): array
{
    $visits = [];
    foreach ($_COOKIE as $name => $raw) {
        $name = (string) $name;
        $shareToken = substr($name, strlen(SHARE_SESSION_COOKIE_PREFIX));
        if (str_starts_with($name, SHARE_SESSION_COOKIE_PREFIX) && preg_match('/^[a-f0-9]{16}\z/', $shareToken)
            && is_string($raw) && $raw !== "") {
            $visits[$shareToken] = $raw;
        }
    }
    return $visits;
}

/** share_session_cookie_value(), or a 401 without one. */
function share_session_from_request(string $shareToken): string
{
    $raw = share_session_cookie_value($shareToken);
    if ($raw === "") {
        json_response(["error" => __("err_share_session_required"), "code" => "share_session_required"], 401);
    }
    return $raw;
}

/**
 * Preferred language of a visitor to the public pages (share, privacy, 404)
 * and of /api/share/*, first match wins:
 *   - the language they picked from the menu (VISITOR_LANG_COOKIE);
 *   - Accept-Language, then English.
 * A ?lang= in the address is only the menu's pick on its way to the cookie
 * (visitor_lang_choice()), never a language by itself. The share page and
 * its API calls read the same two, so their messages always agree. None of
 * these has an owner session.
 *
 * @return string  An available language code, e.g. 'en', 'it', 'de'
 */
function detect_visitor_lang(): string
{
    $availableLangs = Lang::available();

    // is_string(): a cookie named with [x] after it arrives as an array.
    $picked = $_COOKIE[VISITOR_LANG_COOKIE] ?? "";
    if (is_string($picked) && isset($availableLangs[$picked])) {
        return $picked;
    }

    $accept = $_SERVER["HTTP_ACCEPT_LANGUAGE"] ?? "";
    foreach (explode(",", $accept) as $part) {
        $code = strtolower(trim(explode(";", $part)[0]));
        $code = preg_replace("/[^a-z_]/", "", str_replace("-", "_", $code));
        $primary = substr($code, 0, 2);
        if (isset($availableLangs[$code])) {
            return $code;
        }
        if (isset($availableLangs[$primary])) {
            return $primary;
        }
    }

    return "en";
}

/**
 * Handle ?lang=xx, the share/privacy language menu (a GET form, so it works
 * without JavaScript): stores the choice in VISITOR_LANG_COOKIE and redirects
 * without it. The fragment (#KEY) survives. Only from this installation's own
 * pages: from anywhere else (a link in an email or on another site, a typed
 * address) the parameter is dropped with the same redirect, and the page
 * shows in the visitor's own language, so no link can choose it for them.
 *
 * @param string $routePath  The router's path ("/privacy", "/s/{token}"),
 *                           so the redirect never leaves this installation.
 */
function visitor_lang_choice(string $routePath): void
{
    if (!isset($_GET["lang"])) {
        return;
    }
    // is_string(): ?lang[]=x makes this an array.
    $code = is_string($_GET["lang"]) ? $_GET["lang"] : null;
    if ($code !== null && isset(Lang::available()[$code]) && request_from_own_page()) {
        visitor_lang_cookie($code, time() + 365 * 86400);
    }
    $query = $_GET;
    unset($query["lang"]);
    $qs = http_build_query($query);
    header("Location: " . base_url(ltrim($routePath, "/")) . ($qs !== "" ? "?" . $qs : ""), true, 303);
    exit();
}

/** Set VISITOR_LANG_COOKIE; "" with an expiry in the past deletes it. */
function visitor_lang_cookie(string $code, int $expires): void
{
    // __Host- requires Secure, Path=/ and no Domain. Deleting uses the same
    // attributes as setting.
    setcookie(VISITOR_LANG_COOKIE, $code, [
        "expires" => $expires,
        "path" => "/",
        "secure" => true,
        "httponly" => true,
        "samesite" => "Lax",
    ]);
}

/**
 * ?forget_cookie=, from this installation's own pages only (as
 * visitor_lang_choice()): =lang deletes the language cookie; =share ends
 * link visits, on the server too, so a copy of a cookie opens nothing.
 * On the privacy page (its delete links) every visit this browser holds;
 * on a link's page (its Exit button, share.js) only that link's. Then back
 * to the same page without the parameter: the privacy page at its cookie
 * table, a link's page with its #KEY, which the browser keeps across the
 * redirect, asking for the password again.
 *
 * @param string  $routePath   The router's path ("/privacy", "/s/{token}").
 * @param ?string $shareToken  The link of the page, null on the privacy page.
 */
function visitor_cookie_forget(string $routePath, PDO $db, CryptoService $crypto, ?string $shareToken = null): void
{
    $forget = $_GET["forget_cookie"] ?? null;
    if (!in_array($forget, ["lang", "share"], true) || !request_from_own_page()) {
        return;
    }
    if ($forget === "lang") {
        visitor_lang_cookie("", 1);
    }
    foreach ($forget === "share" ? share_session_cookies() : [] as $token => $raw) {
        if ($shareToken === null || $token === $shareToken) {
            share_sessions_delete($db, "access_token_hash = :hash", [":hash" => $crypto->hashToken($raw)]);
            share_session_cookie($token, "", 1);
        }
    }
    $query = $_GET;
    unset($query["forget_cookie"]);
    $qs = http_build_query($query);
    $fragment = $routePath === "/privacy" ? "#cookie" : "";
    header("Location: " . base_url(ltrim($routePath, "/")) . ($qs !== "" ? "?" . $qs : "") . $fragment, true, 303);
    exit();
}

/**
 * Whether this navigation started on one of this installation's own pages,
 * as a pick from its language menu does. SameSite only governs when a cookie
 * is sent, not when one is accepted, so the check is on the request:
 * Sec-Fetch-Site (set by the browser, not forgeable by page script), and,
 * for a browser without Fetch Metadata, a Referer naming this host.
 */
function request_from_own_page(): bool
{
    $site = $_SERVER["HTTP_SEC_FETCH_SITE"] ?? null;
    if ($site !== null) {
        return $site === "same-origin";
    }
    // Host names only: Host may carry a port, and so may the Referer.
    $from = (string) parse_url((string) ($_SERVER["HTTP_REFERER"] ?? ""), PHP_URL_HOST);
    return $from !== "" && strtolower($from) === request_host();
}

/**
 * The security headers every PHP response carries, whoever answers: the
 * application (through csp_header()) and the installer (app/setup/index.php),
 * which does not load bootstrap.php. One list, so the two cannot drift
 * apart; public/.htaccess repeats it for the static files. The
 * Content-Security-Policy is not part of it: each of the two has its own.
 */
function security_headers(): void
{
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()");
    header("Cross-Origin-Opener-Policy: same-origin");
    // No other site may embed these responses (images, downloads, scripts).
    header("Cross-Origin-Resource-Policy: same-origin");
    // Nothing here is meant for search results, and the header covers what a
    // <meta name="robots"> cannot: JSON, downloads, previews. robots.txt
    // allows crawling, so that crawlers get to read it.
    header("X-Robots-Tag: noindex, nofollow");

    // Without this, heuristic caching can serve a stale authenticated page
    // after a deploy or leave sensitive content readable after logout.
    header("Cache-Control: no-store, max-age=0");
}

/**
 * Emit the Content-Security-Policy and companion security headers.
 * All scripts are vendored, so no external origins and no admin-configurable
 * exceptions. Sent from PHP because some shared hosts (e.g. Altervista) do
 * not apply mod_headers; the .htaccess block still covers static assets
 * where it works.
 */
function csp_header(): void
{
    security_headers();

    // No Strict-Transport-Security: it covers the whole host, so it is the
    // server's to set, not the app's (docs/security-model.md § Design notes).

    $script = ["'self'"];
    if (defined("AESBIN_NONCE")) {
        $script[] = "'nonce-" . AESBIN_NONCE . "'";
    }
    // ALTCHA PoW spawns its worker via inline Blob URL.
    $worker = ["'self'", "blob:"];

    // The style nonce exists for one consumer: the ALTCHA bundle injects an
    // inline <style> on load (it reads the nonce from document.currentScript).
    // See docs/vendored-libraries.md § altcha (widget). Nothing else may use inline
    // styles.
    $style = ["'self'"];
    if (defined("AESBIN_NONCE")) {
        $style[] = "'nonce-" . AESBIN_NONCE . "'";
    }

    $csp =
        "default-src 'none'" .
        "; script-src " .
        implode(" ", $script) .
        "; style-src " .
        implode(" ", $style) .
        "; img-src 'self' data: blob:" .
        "; media-src 'self' blob:" .
        "; worker-src " .
        implode(" ", $worker) .
        "; manifest-src 'self'" .
        "; connect-src 'self'" .
        "; frame-ancestors 'none'" .
        "; base-uri 'self'" .
        "; form-action 'self'" .
        "; object-src 'none'";
    header("Content-Security-Policy: " . $csp);
}

/**
 * ALTCHA widget strings from the lang files ("altcha_*" keys), for the
 * views to pass to window.$altcha.i18n.set().
 */
function altcha_i18n_strings(): array
{
    return [
        "ariaLinkLabel"        => __("altcha_aria_link_label"),
        "cancel"               => __("cancel"),
        "enterCode"            => __("altcha_enter_code"),
        "enterCodeAria"        => __("altcha_enter_code_aria"),
        "enterCodeFromImage"   => __("altcha_enter_code_from_image"),
        "error"                => __("altcha_error"),
        "expired"              => __("altcha_expired"),
        "footer"               => __("altcha_footer"),
        "getAudioChallenge"    => __("altcha_get_audio_challenge"),
        "label"                => __("altcha_label"),
        "loading"              => __("altcha_loading"),
        "reload"               => __("altcha_reload"),
        "verificationRequired" => __("altcha_verification_required"),
        "verified"             => __("altcha_verified"),
        "verifying"            => __("altcha_verifying"),
        "verify"               => __("altcha_verify"),
        "waitAlert"            => __("altcha_wait_alert"),
    ];
}

/**
 * The plain 503 page for a boot that cannot continue (database unreachable,
 * app/migrations.php unreadable, schema mismatch). $messageHtml is fixed text
 * from the caller, never request or driver data: the detail goes to the
 * error log, since this page is public.
 */
function aesbin_unavailable(string $messageHtml, int $retryAfter): never
{
    http_response_code(503);
    header("Content-Type: text/html; charset=utf-8");
    header("Retry-After: " . $retryAfter);
    echo "<!doctype html><meta charset=utf-8>" .
        "<title>Temporarily unavailable</title>" .
        "<h1>Temporarily unavailable</h1>" .
        $messageHtml;
    exit();
}

/**
 * Redirect to a URL relative to the app base path and exit.
 */
function redirect(string $path = ""): never
{
    header("Location: " . base_url($path));
    exit();
}

// ── HTTP / API responses ──────────────────────────────────────────────────────

/**
 * Send a JSON response and exit.
 *
 * @param array $data    Response payload
 * @param int   $status  HTTP status code
 */
function json_response(array $data, int $status = 200): never
{
    // Download endpoints stream the file body before touching the database.
    // If that later work throws, the router lands here after the body is sent;
    // emitting JSON would append it to the user's file. The router's error_log
    // entry is the record.
    if (headers_sent()) {
        exit();
    }
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    // csp_header() has already sent nosniff and no-store for every request.
    // Filenames are arbitrary user bytes. INVALID_UTF8_SUBSTITUTE turns one
    // non-UTF-8 name into U+FFFD instead of failing the whole listing;
    // THROW_ON_ERROR stays for real bugs (recursion, INF/NAN, depth).
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE |
            JSON_THROW_ON_ERROR
    );
    echo $json;
    exit();
}

/**
 * Parse the raw JSON request body.
 * Returns an empty array on empty or malformed input.
 */
function json_body(): array
{
    $raw = (string) file_get_contents("php://input");
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * External Markdown editors for the preview help panel, as [name => url].
 *
 * Empty unless config.php lists some: a shipped list of third-party sites
 * would outlive any change of ownership. Entries are validated, not trusted.
 *
 * @return array<string, string>
 */
function markdown_tools(): array
{
    global $config;
    $out = [];
    foreach ((array) ($config["markdown_tools"] ?? []) as $name => $url) {
        if (!is_string($name) || !is_string($url)) {
            continue;
        }
        // https only: an http link here would downgrade a reader arriving from
        // a page that is itself required to be encrypted.
        if (!preg_match('#^https://#', $url)) {
            continue;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            continue;
        }
        $out[$name] = $url;
    }
    return $out;
}

/**
 * Runtime health checks: Requirements::blocking() plus advisory() with the
 * running app's context. An "optional" row is a feature that is simply off
 * when it fails, never counted as a problem.
 *
 * @return array<string, array{label: string, ok: bool, hint: string, group: string, detail?: string, optional?: bool}>
 */
function runtime_requirements(): array
{
    // Cached per request: the dashboard asks twice (banner and card).
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    global $config, $db;

    try {
        $dbServer = Requirements::databaseServer($db);
    } catch (PDOException) {
        $dbServer = ["version" => "", "name" => "?"];
    }

    // Blocking checks are always green here but are listed so the dashboard
    // and installer agree on what counts as a requirement. array_merge, not +:
    // for root_writable the advisory (runtime) wording wins; app/setup/template.php
    // merges the other way round to keep the install-time wording.
    // The database right after PHP: array_merge keeps "php" in first place
    // when the full blocking list repeats it.
    $blocking = Requirements::blocking(AESBIN_ROOT);
    return $cache = array_merge(
        ["php" => $blocking["php"]],
        Requirements::database($dbServer["version"], $dbServer["name"]),
        $blocking,
        Requirements::advisory([
            "root" => AESBIN_ROOT,
            "base" => AESBIN_BASE,
            "storage" => AESBIN_FILES,
            "max_upload_bytes" => setting_int((array) ($config ?? []), "max_upload_bytes"),
            "trusted_proxies" => request_ip_trusted_proxies(),
            "server_rules_configured" => !empty($config["server_rules_configured"]),
            "captcha_enabled" => !empty($config["captcha"]["enabled"]),
            // Must match SecurityService::captchaOperational(); see
            // captcha_keys_valid() in config_template.php.
            "captcha_operational" => !empty($config["captcha"]["enabled"]) &&
                captcha_keys_valid(
                    $config["captcha"]["hmac_secret"] ?? null,
                    $config["captcha"]["key_signature_secret"] ?? null
                ),
        ])
    );
}

function runtime_requirements_failing(): bool
{
    foreach (runtime_requirements() as $r) {
        if (!$r["ok"] && empty($r["optional"])) {
            return true;
        }
    }
    return false;
}

/**
 * Trusted reverse-proxy list, injected once from bootstrap.php.
 * Empty (the default) = X-Forwarded-For is ignored entirely.
 */
function request_ip_trusted_proxies(?array $set = null): array
{
    static $proxies = [];
    if ($set !== null) {
        $proxies = $set;
    }
    return $proxies;
}

/** True if $ip falls inside $cidr ("10.0.0.0/8", "2001:db8::/32", or a bare IP). */
function ip_in_cidr(string $ip, string $cidr): bool
{
    if (!str_contains($cidr, "/")) {
        $ipBin = inet_pton($ip);
        return $ipBin !== false && $ipBin === inet_pton($cidr);
    }
    [$subnet, $bits] = explode("/", $cidr, 2);
    $ipBin = inet_pton($ip);
    $subBin = inet_pton($subnet);
    if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
        return false; // mixed IPv4/IPv6, or malformed
    }
    // Digits only: an (int) cast would turn a typo like "/24x" into 0, which
    // matches every address and would trust the whole internet as a proxy.
    // /0 is rejected for the same reason. preg_match, since ext-ctype is
    // optional.
    if ($bits === "" || preg_match('/^\d+\z/', $bits) !== 1) {
        return false;
    }
    $bits = (int) $bits;
    $maxBits = strlen($ipBin) * 8;
    if ($bits < 1 || $bits > $maxBits) {
        return false;
    }
    $bytes = intdiv($bits, 8);
    $rem = $bits % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
        return false;
    }
    if ($rem === 0) {
        return true;
    }
    $mask = chr(0xFF << (8 - $rem) & 0xFF);
    return (($ipBin[$bytes] & $mask) === ($subBin[$bytes] & $mask));
}

/**
 * "::ffff:a.b.c.d" as "a.b.c.d"; any other string unchanged. A dual-stack
 * socket reports IPv4 clients in the mapped form, whose first 64 bits are
 * all zero: client_network() would put every IPv4 client in one bucket, and
 * an IPv4 trusted_proxies entry would never match.
 */
function unmap_ipv4(string $ip): string
{
    $bin = @inet_pton($ip);
    if (is_string($bin) && strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
        return (string) inet_ntop(substr($bin, 12));
    }
    return $ip;
}

/**
 * Current visitor IP: REMOTE_ADDR, since X-Forwarded-For is client-supplied.
 * With security.trusted_proxies set, X-Forwarded-For is walked from the
 * right and the first untrusted address wins, so a prepended one is ignored.
 */
function request_ip(): string
{
    $remote = unmap_ipv4((string) ($_SERVER["REMOTE_ADDR"] ?? ""));
    $trusted = request_ip_trusted_proxies();
    if ($trusted === [] || $remote === "") {
        return $remote;
    }

    $isTrusted = static function (string $ip) use ($trusted): bool {
        foreach ($trusted as $cidr) {
            if (ip_in_cidr($ip, (string) $cidr)) {
                return true;
            }
        }
        return false;
    };

    if (!$isTrusted($remote)) {
        return $remote; // direct hit, not via our proxy
    }

    $fwd = $_SERVER["HTTP_X_FORWARDED_FOR"] ?? "";
    if ($fwd === "") {
        return $remote;
    }
    $chain = array_map("trim", explode(",", $fwd));
    for ($i = count($chain) - 1; $i >= 0; $i--) {
        $candidate = $chain[$i];
        // Strip a :port or [v6]:port suffix some proxies append.
        if (str_starts_with($candidate, "[")) {
            $candidate = substr($candidate, 1, (int) strpos($candidate, "]") - 1);
        } elseif (substr_count($candidate, ":") === 1) {
            $candidate = explode(":", $candidate)[0];
        }
        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            return $remote; // malformed chain: do not guess
        }
        $candidate = unmap_ipv4($candidate);
        if (!$isTrusted($candidate)) {
            return $candidate;
        }
    }
    return $remote; // every hop was ours
}

/**
 * The request's Host header in lower case, without a port: what
 * parse_url(…, PHP_URL_HOST) returns for a URL on this site.
 */
function request_host(): string
{
    $host = strtolower((string) ($_SERVER["HTTP_HOST"] ?? ""));
    return (string) preg_replace('/:\d+$/', "", $host);
}

/**
 * The request's Host header reduced to the characters a host name can hold,
 * port included, or "localhost". A label only (the TOTP account, the note
 * beside the installer's recovery phrase, the pages' og:url), never used for
 * a decision.
 */
function host_label(): string
{
    $host = strtolower((string) ($_SERVER["HTTP_HOST"] ?? ""));
    $host = (string) preg_replace('/[^a-z0-9.:\-\[\]]/', "", $host);
    return $host !== "" ? $host : "localhost";
}

/**
 * The otpauth:// URI for a TOTP secret. The installer and the application
 * both build it here, so an authenticator entry added again after a reveal
 * or a reset reads the same.
 */
function totp_uri(CryptoService $crypto, string $secret): string
{
    return $crypto->totpUri($secret, "AESBin", host_label());
}

/** Current visitor User-Agent, sanitised like any other visitor string. */
function request_ua(): string
{
    return sanitize_visitor_string((string) ($_SERVER["HTTP_USER_AGENT"] ?? ""));
}

/**
 * Strip control characters and force valid UTF-8 on an untrusted string.
 * The cut is on a character boundary: invalid UTF-8 is rejected by MySQL
 * under STRICT_TRANS_TABLES (Database.php).
 */
function sanitize_visitor_string(string $raw, int $maxLen = 512): string
{
    if ($raw === "") {
        return "";
    }
    $raw = (string) preg_replace('/[\x00-\x1F\x7F]/', "", $raw);
    $raw = mb_convert_encoding($raw, "UTF-8", "UTF-8");
    return mb_substr($raw, 0, $maxLen, "UTF-8");
}

/**
 * A referrer worth recording: sanitised, and empty when it points at this
 * instance. A self-referrer (language switch, in-page navigation) says nothing
 * about where a link came from and would put the share token in the log.
 *
 * The host comes from the request, not configuration, so an instance reachable
 * under two names still recognises its own pages.
 */
function visitor_referrer(string $raw, int $maxLen = 512): string
{
    $ref = sanitize_visitor_string($raw, $maxLen);
    if ($ref === "") {
        return "";
    }
    $host = parse_url($ref, PHP_URL_HOST);
    if (is_string($host) && strcasecmp($host, request_host()) === 0) {
        return "";
    }
    return $ref;
}

/**
 * {referer, url?} for a share visitor's FAILED attempt. Both come from the
 * request body, captured by share.js on the page load (an AJAX Referer is
 * this app). They describe how a link circulates, not who is asking, unlike
 * ip/ua (never stored for visitors). Empty values are omitted. $includeUrl
 * is false for share.failure, where target_id already names the share.
 */
function visitor_failure_context(
    string $pageReferrer = "",
    string $pageUrl = "",
    bool $includeUrl = true
): array {
    $out = [];
    $ref = visitor_referrer($pageReferrer);
    if ($ref !== "") {
        $out["referer"] = $ref;
    }
    if ($includeUrl) {
        $path = sanitize_visitor_string($pageUrl);
        if ($path !== "") {
            $out["url"] = $path;
        }
    }
    return $out;
}

// ── Validation ────────────────────────────────────────────────────────────────

/**
 * Assert that required fields are present and non-empty in a request body.
 * Exits with 400 on the first missing field.
 */
function require_fields(array &$body, array $fields): void
{
    foreach ($fields as $f) {
        if (!isset($body[$f])) {
            json_response(["error" => __("err_field_required", ["field" => $f])], 400);
        }
        normalize_body_field($body, $f);
        // After normalising, so {"password": false} is rejected like "".
        if ($body[$f] === "") {
            json_response(["error" => __("err_field_required", ["field" => $f])], 400);
        }
    }
}

/**
 * Validate one body field as a scalar and normalise it to a string, in place.
 *
 * Non-scalars (arrays, null) get a 400; int, float and bool are cast, since under
 * strict_types {"totp_code": 123456} would otherwise be a TypeError (500).
 * Separate from require_fields() because login, finalize and totp check
 * presence themselves to keep their own error messages.
 */
function normalize_body_field(array &$body, string $field): void
{
    if (!is_scalar($body[$field] ?? null)) {
        json_response(["error" => __("err_field_invalid", ["field" => $field])], 400);
    }
    $body[$field] = is_bool($body[$field])
        ? ($body[$field] ? "1" : "")
        : (string) $body[$field];
}

/**
 * A nested object of a request body (the WebAuthn "response"), with each of
 * $keys checked as normalize_body_field() checks a top-level one: [] when
 * absent, a 400 when it is not an object or one of $keys is not a scalar.
 *
 * @param string[] $keys
 * @return array<string, mixed>
 */
function body_object(array $body, string $field, array $keys): array
{
    $value = $body[$field] ?? [];
    if (!is_array($value)) {
        json_response(["error" => __("err_field_invalid", ["field" => $field])], 400);
    }
    foreach ($keys as $key) {
        if (isset($value[$key]) && !is_scalar($value[$key])) {
            json_response(["error" => __("err_field_invalid", ["field" => "{$field}.{$key}"])], 400);
        }
    }
    return $value;
}

/**
 * A table prefix cannot be a bound parameter (it is concatenated into DDL and
 * every query), so it is restricted to a plain identifier. Checked by the
 * installer on input and by bootstrap.php on every boot, in case config.php
 * was edited by hand.
 */
function valid_db_prefix(string $prefix): bool
{
    return preg_match('/^[A-Za-z0-9_]{1,40}\z/', $prefix) === 1;
}

/**
 * An integer from a decoded request body: an int, or a string of digits with
 * an optional leading minus. Anything else is null, so "abc" or "5x" is
 * refused by the caller instead of becoming 0 or 5 under an (int) cast.
 */
function body_int(mixed $value): ?int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && preg_match('/^-?\d{1,18}\z/', $value) === 1) {
        return (int) $value;
    }
    return null;
}

/**
 * A row id from a request body: a 400 unless the field is a positive integer
 * (see body_int()). An (int) cast would read "5x" as 5 and an array as 1,
 * each naming a real row.
 */
function body_id(array $body, string $field): int
{
    $id = body_int($body[$field] ?? null);
    if ($id === null || $id < 1) {
        json_response(["error" => __("err_field_invalid", ["field" => $field])], 400);
    }
    return $id;
}

/**
 * An optional integer query parameter: null when absent, a 400 when present
 * but not an integer. ?container_id[]=1 arrives as an array, and (int) of a
 * non-empty array is 1: another container, silently.
 */
function query_int(string $key): ?int
{
    if (!isset($_GET[$key])) {
        return null;
    }
    $value = body_int($_GET[$key]);
    if ($value === null) {
        json_response(["error" => __("err_field_invalid", ["field" => $key])], 400);
    }
    return $value;
}

/** Longest description a file or directory keeps, in characters. */
const DESCRIPTION_MAX = 250;

/**
 * The optional "description" body field: null when absent or null (leave the
 * stored one alone), otherwise a string cut to DESCRIPTION_MAX ("" clears it).
 * Truncated rather than rejected: nothing looks descriptions up.
 */
function body_description(array &$body): ?string
{
    if (($body["description"] ?? null) === null) {
        return null;
    }
    normalize_body_field($body, "description");
    return mb_substr($body["description"], 0, DESCRIPTION_MAX);
}

/**
 * The description sent with a rename, or null when it is the one already
 * stored ($row's desc_* columns, decrypted with $aad). The dashboard sends
 * it only when edited, but a direct API call may send it unchanged, and an
 * unchanged value is neither rewritten nor logged as an update. A stored
 * value that does not decrypt counts as different, so it can be replaced.
 */
function changed_description(CryptoService $crypto, ?string $desc, array $row, string $key, string $aad): ?string
{
    if ($desc === null) {
        return null;
    }
    if (empty($row["desc_enc"])) {
        return $desc === "" ? null : $desc;
    }
    try {
        $old = $crypto->decryptMeta(
            (string) $row["desc_enc"],
            (string) $row["desc_iv"],
            (string) $row["desc_tag"],
            $key,
            $aad
        );
    } catch (RuntimeException) {
        return $desc;
    }
    return $desc === $old ? null : $desc;
}

/**
 * Name limits. Characters bound what a person can type; bytes bound what the
 * encrypted column holds, which 4-byte UTF-8 reaches first (see "Column
 * sizes and encrypted data" in docs/maintaining.md). Filenames are held to
 * 255 bytes, the limit of most filesystems a download lands on.
 */
const DISPLAY_NAME_MAX_CHARS = 120;
const DISPLAY_NAME_MAX_BYTES = 384;
const FILENAME_MAX_CHARS = 200;
const FILENAME_MAX_BYTES = 255;

/**
 * Characters no stored name may hold, all of them invisible or controls:
 *
 *   - C0 and C1 controls;
 *   - bidirectional formatting characters: a right-to-left override before
 *     "gpj.exe" makes an executable display as "…exe.jpg" in the listing, on
 *     the share page and in the save dialog;
 *   - the invisible ones Unicode marks Default_Ignorable_Code_Point (UAX #44)
 *     that no script needs to display correctly: soft hyphen, zero-width
 *     space, word joiner and its kin, the byte-order mark, the Hangul
 *     fillers, the deprecated format characters. Each lets two names look
 *     identical, the concern UTS #39 (Unicode Security Mechanisms) names.
 *
 * Kept on purpose, as UAX #31 keeps them in context: the zero-width joiner
 * and non-joiner (emoji sequences, Persian, Indic scripts) and the variation
 * selectors (an emoji's colour form). dashboard.js mirrors this list for the
 * name an upload row shows before the server answers.
 */
const NAME_FORBIDDEN_CHARS = '/[\x00-\x1F\x7F\x{0080}-\x{009F}\x{00AD}\x{061C}\x{115F}\x{1160}\x{180E}' .
    '\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{3164}\x{FEFF}' .
    '\x{FFA0}\x{FFF9}-\x{FFFB}]/u';

/**
 * Leading and trailing characters a name loses: white space of any script,
 * and, at the end, dots (Windows drops both silently, so a download would
 * not keep the name shown).
 */
const NAME_EDGE_SPACE = '[\s\x{00A0}\x{3000}]';

/**
 * Compound extensions kept whole when a name is split: "archive.tar.gz"
 * de-duplicates as "archive_01.tar.gz", not "archive.tar_01.gz".
 */
const FILENAME_COMPOUND_EXTS = ["tar.gz", "tar.bz2", "tar.xz", "tar.zst"];

/**
 * A filename split into base and extension, the dot included ("photo",
 * ".jpg"). A compound extension (FILENAME_COMPOUND_EXTS) counts as one; a
 * leading dot alone is not an extension (".htaccess" is all base).
 *
 * @return array{0: string, 1: string}
 */
function filename_split_ext(string $name): array
{
    $lower = strtolower($name);
    foreach (FILENAME_COMPOUND_EXTS as $compound) {
        $suffix = "." . $compound;
        if (strlen($name) > strlen($suffix) && str_ends_with($lower, $suffix)) {
            return [substr($name, 0, -strlen($suffix)), substr($name, -strlen($suffix))];
        }
    }
    $dot = strrpos($name, ".");
    if ($dot === false || $dot === 0) {
        return [$name, ""];
    }
    return [substr($name, 0, $dot), substr($name, $dot)];
}

/**
 * $base cut so that $base, $reserve more characters (a "_01" suffix) and
 * $ext together fit FILENAME_MAX_CHARS and FILENAME_MAX_BYTES. The
 * extension is never cut here: filename_clean() caps it first. Cut on
 * whole characters (mb_substr, then mb_strcut), never inside one, and never
 * left ending on a space or a dot.
 */
function filename_fit_base(string $base, string $ext, int $reserve = 0): string
{
    $chars = FILENAME_MAX_CHARS - mb_strlen($ext) - $reserve;
    $bytes = FILENAME_MAX_BYTES - strlen($ext) - $reserve;
    $cut = $base;
    if (mb_strlen($cut) > $chars) {
        $cut = mb_substr($cut, 0, max(1, $chars));
    }
    if (strlen($cut) > $bytes) {
        $cut = mb_strcut($cut, 0, max(1, $bytes), "UTF-8");
    }
    if ($cut === $base) {
        return $base;
    }
    // A cut can end on a space or a dot ("a.b" cut to "a.", then "a..txt"):
    // dropped, as filename_clean() drops them, unless nothing else is left.
    $trimmed = (string) preg_replace('/(?:' . NAME_EDGE_SPACE . '|\.)+$/u', "", $cut);
    return $trimmed !== "" ? $trimmed : $cut;
}

/**
 * Whether Windows reads $name as a device rather than a file: CON, PRN, AUX,
 * NUL, COM1-COM9, LPT1-LPT9 and the superscript COM¹²³/LPT¹²³, in any case
 * and whatever follows the FIRST dot ("NUL.txt and NUL.tar.gz are both
 * equivalent to NUL"), as listed in "Naming Files, Paths, and Namespaces"
 * on Microsoft Learn. Spaces before that dot are dropped first, as Windows
 * drops trailing spaces. Other systems have no such names: "lpt.md",
 * "COM0.txt" or "CONSOLE.txt" are ordinary everywhere.
 */
function filename_windows_device(string $name): bool
{
    $stem = rtrim(explode(".", $name, 2)[0], " ");
    return preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9¹²³]|LPT[1-9¹²³])\z/iu', $stem) === 1;
}

/**
 * Why filename_clean() changed a name, most telling first: one code, for
 * the message that says so (name_reason_* in app/lang).
 */
const FILENAME_REASONS = ["reserved", "characters", "invisible", "dots", "length"];

/**
 * The most telling of several FILENAME_REASONS codes ("" ignored), by the
 * order of that list: a name cleaned in more than one pass reports the
 * reason that matters most, not the one found first.
 */
function filename_top_reason(string ...$reasons): string
{
    foreach (FILENAME_REASONS as $reason) {
        if (in_array($reason, $reasons, true)) {
            return $reason;
        }
    }
    return "";
}

/**
 * A filename made safe to store and serve, and why it changed: [name, reason],
 * reason "" when the name is kept as sent (NFC aside), else the most telling
 * of FILENAME_REASONS. Follows the OWASP File Upload Cheat Sheet for names
 * the user keeps (the stored blob has a random name of its own): letters of
 * any script, digits, spaces, case and common punctuation stay; what causes
 * trouble goes, and the rules are Windows' (Microsoft Learn, "Naming Files,
 * Paths, and Namespaces"), the strictest system a download lands on:
 *
 *   - NFC-normalised (UAX #15), valid UTF-8;
 *   - invisible  no control or invisible character (NAME_FORBIDDEN_CHARS);
 *   - characters the ones Windows refuses, < > : " / \ | ? *, each run of
 *                them replaced by one "_";
 *   - dots       no runs of dots ("a..b" is "a.b"), no leading or trailing
 *                white space, no trailing dot;
 *   - reserved   a Windows device name (CON, NUL, COM1…, before any
 *                extension) gets a leading "_";
 *   - length     at most FILENAME_MAX_CHARS characters and
 *                FILENAME_MAX_BYTES bytes, cut from the base, never inside
 *                the extension (capped at 15 bytes);
 *   - never empty: "untitled".
 *
 * Idempotent: the steps run until the name stops changing, so a cut that
 * leaves a trailing dot, or a dot run that leaves a device name, is caught,
 * and cleaning a clean name changes nothing. A leading dot is left to the
 * callers: upload strips it (filename_for_upload()), rename refuses it.
 *
 * No basename(): a name is never a path here, so "/" and "\" are replaced
 * like the other characters. Cutting at them would turn a rename to
 * "Invoice 1/2026.pdf" into "2026.pdf". (api.js sends an upload's "/" and
 * "\" as "_" for the same reason: PHP would cut there.)
 *
 * @return array{0: string, 1: string}
 */
function filename_clean(string $name): array
{
    $found = [];
    // NFC is no change worth a message: api.js already sends it.
    if (function_exists("normalizer_normalize")) {
        $n = normalizer_normalize($name, Normalizer::FORM_C);
        if ($n !== false) {
            $name = $n;
        }
    }
    // ASCII controls first: a byte-wise match, safe before the name is
    // known to be UTF-8.
    $name = (string) preg_replace('/[\x00-\x1F\x7F]/', "", $name, -1, $count);
    if ($count > 0) {
        $found["invisible"] = true;
    }
    // A multipart filename from a legacy client arrives in its own locale;
    // ISO-8859-1 never fails and gets a latin-1 "café.txt" right instead of
    // dropping the byte. Before any measuring or cutting.
    if (!mb_check_encoding($name, "UTF-8")) {
        $name = mb_convert_encoding($name, "UTF-8", "ISO-8859-1");
    }

    // One rule: replace, and remember why when it changed something.
    $step = static function (string $re, string $to, string $reason) use (&$name, &$found): void {
        $name = (string) preg_replace($re, $to, $name, -1, $count);
        if ($count > 0) {
            $found[$reason] = true;
        }
    };
    for ($pass = 0; $pass < 4; $pass++) {
        $before = $name;
        $step(NAME_FORBIDDEN_CHARS, "", "invisible");
        $step('/[<>:"\/\\\\|?*]+/', "_", "characters");
        $step('/\.{2,}/', ".", "dots");
        $step('/^' . NAME_EDGE_SPACE . '+|(?:' . NAME_EDGE_SPACE . '|\.)+\z/u', "", "dots");

        [$base, $ext] = filename_split_ext($name);
        if (filename_windows_device($base . $ext)) {
            $base = "_" . $base;
            $found["reserved"] = true;
        }
        // A pathological extension (".aaaa…") is capped so the base keeps room.
        if (strlen($ext) > 15) {
            $ext = mb_strcut($ext, 0, 15, "UTF-8");
            $found["length"] = true;
        }
        $fit = filename_fit_base($base, $ext);
        if ($fit !== $base) {
            $found["length"] = true;
        }
        $name = $fit . $ext;
        if ($name === $before) {
            break;
        }
    }

    if ($name === "") {
        $name = "untitled";
    }
    foreach (FILENAME_REASONS as $reason) {
        if (isset($found[$reason])) {
            return [$name, $reason];
        }
    }
    return [$name, ""];
}

/** filename_clean()'s name alone. */
function sanitize_filename(string $name): string
{
    return filename_clean($name)[0];
}

/**
 * The name an upload is stored under, before its extension is checked
 * against the content, and why it changed: as filename_clean(), with leading
 * dots stripped and the rest cleaned again until it starts with none
 * (".nul.txt" is "_nul.txt", ". foo.txt" is "foo.txt", ". .txt" is "txt").
 * An upload has no hidden files: ".htaccess" becomes "htaccess", then
 * "htaccess.txt" from the content. rename() refuses a leading dot instead,
 * as it can say so.
 *
 * @return array{0: string, 1: string}
 */
function filename_for_upload(string $sent): array
{
    [$name, $reason] = filename_clean($sent);
    // Until none is left: ". .txt" loses the dot, then the space, and would
    // start with a dot again. Each pass may find a weightier reason
    // ("..nul.txt": dots first, then a reserved name).
    while (str_starts_with($name, ".")) {
        [$name, $again] = filename_clean(ltrim($name, "."));
        $reason = filename_top_reason($reason, $again !== "" ? $again : "dots");
    }
    return [$name, $reason];
}

/**
 * Human-readable size in decimal (1000-based) units, as browsers show in the
 * download dialog. File sizes use this; see format_bytes_binary() for the rest.
 */
function format_bytes(int $bytes): string
{
    return format_bytes_in($bytes, 1000);
}

/**
 * Human-readable size in binary (1024-based) units labelled KB/MB/GB. For
 * values that are binary to begin with: the quota card (a 10 GB quota must
 * read back as "10.00 GB") and upload-limit messages.
 */
function format_bytes_binary(int $bytes): string
{
    return format_bytes_in($bytes, 1024);
}

/**
 * The shared body of format_bytes() and format_bytes_binary(), in steps of
 * $base. KB drops trailing zeros and, from 100 up, the decimals; MB and GB
 * always show two. A value that rounds up to the next step is shown in that
 * unit ("1.00 MB", never "1000 KB"). The language's decimal separator, no
 * thousands separator. Must match fmtSize() in helper.js.
 */
function format_bytes_in(int $bytes, int $base): string
{
    if ($bytes < $base) {
        return $bytes . " B";
    }
    $units = ["KB", "MB", "GB"];
    $value = $bytes / $base;
    foreach ($units as $i => $unit) {
        $isKb = $unit === "KB";
        $rounded = round($value, $isKb && $value >= 100 ? 0 : 2);
        if ($rounded < $base || $i === count($units) - 1) {
            return ($isKb
                ? str_replace(".", decimal_separator(), (string) $rounded)
                : number_format($rounded, 2, decimal_separator(), "")) . " " . $unit;
        }
        $value /= $base;
    }
    return $bytes . " B"; // not reached
}

/**
 * The decimal separator of the current language (decimal_sep in app/lang,
 * as decimalSep() in helper.js): no ext-intl needed. A full stop before any
 * language is loaded.
 */
function decimal_separator(): string
{
    $sep = __("decimal_sep");
    return $sep === "decimal_sep" ? "." : $sep;
}

/**
 * The per-file upload ceiling actually enforced: the smaller of
 * upload_max_filesize/post_max_size (0 or -1 = unlimited, ignored) and the
 * largest file memory_limit can hold (otherwise a small inherited
 * memory_limit fails as a fatal error mid-decrypt on download).
 *
 * The memory term inverts Requirements' bar, MEMORY_BASE_BYTES +
 * MEMORY_PER_UPLOAD x file, so the health check and enforcement agree.
 */
function php_upload_limit_bytes(): int
{
    $limits = [];
    foreach (["upload_max_filesize", "post_max_size"] as $ini) {
        $bytes = Requirements::iniBytes($ini);
        if ($bytes > 0) {
            $limits[$ini] = $bytes;
        }
    }

    $memLimit = Requirements::iniBytes("memory_limit");
    if ($memLimit > 0) {
        // Kept even at zero: too little memory means no room, not no limit.
        $limits["memory_limit"] = intdiv(
            max(0, $memLimit - Requirements::MEMORY_BASE_BYTES),
            Requirements::MEMORY_PER_UPLOAD
        );
    }

    return $limits ? (int) min($limits) : PHP_INT_MAX;
}

/**
 * The size one file may have: the max_upload_bytes setting, capped by what
 * PHP accepts (php_upload_limit_bytes()); PHP's alone when the setting is 0.
 * enforce_size_limits() refuses above it, the upload help shows it.
 */
function upload_file_limit_bytes(array $config): int
{
    $phpCap = php_upload_limit_bytes();
    $maxFile = setting_int($config, "max_upload_bytes");
    return $maxFile > 0 ? min($maxFile, $phpCap) : $phpCap;
}

/**
 * Refuse with 413 or 507 a file of $size bytes that would exceed the per-file
 * cap or the instance quota. $replacing is the size of the file an edit
 * overwrites. setting_int() means a missing key gets the default, never
 * "no limit". Messages use binary units, like the settings fields.
 */
function enforce_size_limits(PDO $db, array $config, int $size, int $replacing = 0): void
{
    $maxFile = upload_file_limit_bytes($config);
    if ($size > $maxFile) {
        json_response(
            ["error" => __("upload_too_large", ["max" => format_bytes_binary($maxFile)])],
            413
        );
    }

    $quota = setting_int($config, "max_total_bytes");
    if ($quota > 0) {
        $used = storage_used_bytes($db);
        if ($used - $replacing + $size > $quota) {
            json_response(
                [
                    "error" => __("upload_quota_exceeded", [
                        "used" => format_bytes_binary($used),
                        "quota" => format_bytes_binary($quota),
                    ]),
                ],
                507
            );
        }
    }
}

/** Total bytes currently stored (active + trashed blobs), for quota checks. */
function storage_used_bytes(PDO $db): int
{
    return (int) $db
        ->query("SELECT COALESCE(SUM(size), 0) FROM " . DB_PREFIX . "files")
        ->fetchColumn();
}

// ── Storage paths ─────────────────────────────────────────────────────────────

/**
 * Absolute path to an encrypted blob:
 * {AESBIN_FILES|AESBIN_TRASH}/{first2hex}/{storage_name}.bin. The prefix
 * directory keeps any single directory from growing too large.
 *
 * @param string $storageName  Random hex storage identifier
 * @param bool   $trash        True to look in the trash directory
 */
function storage_path(string $storageName, bool $trash = false): string
{
    $base = $trash ? AESBIN_TRASH : AESBIN_FILES;
    $prefix = substr($storageName, 0, 2);
    return "{$base}/{$prefix}/{$storageName}.bin";
}

/**
 * A stage file FileService::writeContent() leaves when a write is cut short:
 * the blob's own name plus ".{12 hex}.tmp" (FileService::stagingPath()).
 * integrity_check.php reports only this shape and integrity_fix.php deletes
 * only this shape, so what is listed can always be fixed.
 */
const STAGING_FILE_PATTERN = '/^[a-f0-9]{32}\.bin\.[a-f0-9]{12}\.tmp\z/';

/** Seconds before a stage file counts as orphaned, not a write in progress. */
const STAGING_ORPHAN_MIN_AGE = 3600;

/**
 * How the storage_path row of {P}meta compares with config.php's storage_path.
 *
 * The row is the storage folder this installation last confirmed: written by
 * the install, and afterwards only by the owner through the dashboard
 * (app/actions/system/storage_meta.php), which first checks that the folder
 * holds the files. The installer's reconnect form starts from it. config.php
 * stays authoritative for where files are read and written; a disagreement
 * raises a banner, and removing the rows of missing files waits until the
 * two agree (storage_path_confirmed()).
 *
 * @return array{state: "ok"|"missing"|"differs", recorded: ?string}
 */
function storage_meta_state(PDO $db): array
{
    try {
        $stmt = $db->prepare(
            "SELECT meta_value FROM " . DB_PREFIX . "meta WHERE meta_key = 'storage_path' LIMIT 1"
        );
        $stmt->execute();
        $value = $stmt->fetchColumn();
    } catch (PDOException) {
        // A missing meta table is a schema problem bootstrap.php reports.
        return ["state" => "ok", "recorded" => null];
    }
    if (!is_string($value) || $value === "") {
        return ["state" => "missing", "recorded" => null];
    }
    $recorded = rtrim($value, "/");
    return [
        "state" => $recorded === AESBIN_FILES ? "ok" : "differs",
        "recorded" => $recorded,
    ];
}

/**
 * A blob that is not where storage_path() looks for it: in a shard folder
 * other than its first two characters, in either tree. Returned as
 * [path, trash] for the first one found, or null. Only for a storage_name
 * the database holds (32 hex characters), so the glob pattern carries no
 * wildcard from outside.
 *
 * @return array{0: string, 1: bool}|null
 */
function storage_stray_blob(string $storageName): ?array
{
    if (preg_match('/^[a-f0-9]{32}\z/', $storageName) !== 1) {
        return null;
    }
    foreach ([false, true] as $trash) {
        $root = $trash ? AESBIN_TRASH : AESBIN_FILES;
        foreach (glob($root . "/??/" . $storageName . ".bin") ?: [] as $path) {
            if ($path !== storage_path($storageName, $trash)) {
                return [$path, $trash];
            }
        }
    }
    return null;
}

/**
 * True when config.php's storage folder is the one recorded for this
 * installation (storage_meta_state()). Destructive repairs wait for it: a
 * wrong storage_path (mistyped at reconnect, a folder moved by hand) shows as
 * files missing, and deleting their rows would destroy wrapped DEKs that
 * fixing config.php makes whole again. An explicit, recorded path rather
 * than a guess from what the folder holds, which a few uploads into the
 * wrong folder would skew.
 */
function storage_path_confirmed(PDO $db): bool
{
    return storage_meta_state($db)["state"] === "ok";
}

/**
 * True when the storage folder in config.php holds at least half of the
 * blobs the database lists (in either tree, in any shard folder), or the
 * database lists none. The check before that folder is recorded as this
 * installation's (storage_meta.php): a folder missing most of the files is
 * the wrong one, uploads made into it since notwithstanding. The folder is
 * listed once, so the cost grows with the number of files, not with the
 * number missing.
 */
function storage_path_plausible(PDO $db): bool
{
    $onDisk = [];
    foreach ([AESBIN_FILES, AESBIN_TRASH] as $root) {
        foreach (glob($root . "/??/*.bin", GLOB_NOSORT) ?: [] as $path) {
            $onDisk[basename($path, ".bin")] = true;
        }
    }
    $total = 0;
    $missing = 0;
    $stmt = $db->query("SELECT storage_name FROM " . DB_PREFIX . "files");
    while (($name = $stmt->fetchColumn()) !== false) {
        $total++;
        if (!isset($onDisk[(string) $name])) {
            $missing++;
        }
    }
    return $total === 0 || $missing * 2 < $total;
}

/**
 * Create $dir (0750, parents included) with the index.php stub every shipped
 * directory carries, so a server that lists directories shows nothing here
 * either; an existing directory without it gets it. True when $dir exists
 * afterwards. Used for every directory AESBin creates at run time: the
 * storage root, _trash, the shard directories and the two guard directories.
 */
function make_dir(string $dir): bool
{
    // A concurrent request may create it between the check and mkdir().
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return false;
    }
    $stub = $dir . "/index.php";
    if (!is_file($stub)) {
        @file_put_contents($stub, "<?php // Silence...\n", LOCK_EX);
    }
    return true;
}

/**
 * Ensure the storage subdirectory for a storage_name exists (0750).
 *
 * Shard directories are never removed once created: there are at most 256
 * per tree, and removing an empty one could race a concurrent write that
 * has just created it.
 */
function ensure_storage_dir(string $storageName, bool $trash = false): void
{
    $base = $trash ? AESBIN_TRASH : AESBIN_FILES;
    $dir = $base . "/" . substr($storageName, 0, 2);
    if (!make_dir($dir)) {
        throw new RuntimeException("Could not create storage directory: " . basename($dir));
    }
}

/**
 * Overwrite a whole stored blob with random bytes, in 64 KiB chunks.
 *
 * Best-effort: on journaling, copy-on-write or wear-levelling storage (ext4
 * data=journal, btrfs, ZFS, SSDs) the originals may survive. It stops naive
 * undelete; the real protection is that the blob is AES-256-GCM ciphertext
 * whose key is gone.
 */
function wipe_blob(string $path): void
{
    $sz = (int) @filesize($path);
    if ($sz <= 0) {
        return;
    }
    // "r+b", not "wb": "w" truncates first, and the filesystem is then free to
    // put the random bytes in new blocks and leave the old ones untouched.
    $fh = @fopen($path, "r+b");
    if ($fh === false) {
        return;
    }
    $chunk = 65536;
    for ($written = 0; $written < $sz; $written += $chunk) {
        fwrite($fh, random_bytes(max(1, min($chunk, $sz - $written))));
    }
    fflush($fh);
    fclose($fh);
}

/**
 * Wipe and unlink one stored blob. Every deletion path goes through this.
 */
function destroy_blob(string $path): void
{
    wipe_blob($path);
    unlink($path);
}

/**
 * Move a blob between the active and the trash tree. Throws if rename()
 * fails, so the row and the blob location cannot silently disagree. A blob
 * already missing is left to integrity_check.php, which reports the row.
 */
function move_blob(string $storageName, bool $toTrash): void
{
    ensure_storage_dir($storageName, $toTrash);
    $src = storage_path($storageName, !$toTrash);
    if (file_exists($src) && !rename($src, storage_path($storageName, $toTrash))) {
        throw new RuntimeException("Could not move blob: " . basename($src));
    }
}

/** Answer 404 unless container $id exists. */
function require_container(PDO $db, int $id): void
{
    $stmt = $db->prepare("SELECT id FROM " . DB_PREFIX . "containers WHERE id = :id LIMIT 1");
    $stmt->execute([":id" => $id]);
    if (!$stmt->fetch()) {
        json_response(["error" => __("container_not_found")], 404);
    }
}

/** Answer 404 unless directory $id exists. */
function require_directory(PDO $db, int $id): void
{
    $stmt = $db->prepare("SELECT id FROM " . DB_PREFIX . "directories WHERE id = :id LIMIT 1");
    $stmt->execute([":id" => $id]);
    if (!$stmt->fetch()) {
        json_response(["error" => __("directory_not_found")], 404);
    }
}

/**
 * First step of a folder or container delete: trash every live file in it.
 * Runs OUTSIDE the delete's transaction, because trash() moves blobs on disk
 * and a rollBack() would restore the rows but not the files (the "misplaced"
 * drift integrity_check.php reports). Each trash() is self-consistent, so a
 * later failure leaves a visible, retryable state.
 *
 * @param "container_id"|"directory_id" $column
 * @return int File-type share links destroyed by trash(), for the reported
 *             total.
 */
function trash_files_in(PDO $db, FileService $fileSvc, string $column, int $id, string $masterKey): int
{
    $stmt = $db->prepare(
        "SELECT id FROM " . DB_PREFIX . "files WHERE {$column} = :id AND trashed_at IS NULL"
    );
    $stmt->execute([":id" => $id]);
    $destroyed = 0;
    foreach ($stmt->fetchAll() as $row) {
        $destroyed += $fileSvc->trash((int) $row["id"], $masterKey);
    }
    return $destroyed;
}

/**
 * Inside a folder or container delete's transaction: hard-delete the
 * directory-type shares of the directories in $dirNames and return what
 * log_deleted_directories() needs to record them after the commit.
 *
 * @param array<int, array<string, mixed>|null> $dirNames directory id =>
 *        its name from activity_capture_name_enc(), captured before the delete
 * @return list<array{share_id: int, visit_count: int, directory_id: int}>
 */
function revoke_directory_shares(PDO $db, array $dirNames): array
{
    if (!$dirNames) {
        return [];
    }
    $ids = array_map("intval", array_keys($dirNames));
    $stmt = $db->prepare(
        "SELECT id, target_id, visit_count FROM " . DB_PREFIX .
        "shares WHERE type = 'directory' AND target_id IN (" .
        implode(",", array_fill(0, count($ids), "?")) . ")"
    );
    $stmt->execute($ids);
    $revoked = [];
    foreach ($stmt->fetchAll() as $share) {
        $revoked[] = [
            "share_id" => (int) $share["id"],
            "visit_count" => (int) $share["visit_count"],
            "directory_id" => (int) $share["target_id"],
        ];
        share_delete($db, (int) $share["id"]);
    }
    return $revoked;
}

/**
 * The log of a folder or container delete, for its directories: one
 * share.revoke item per link revoke_directory_shares() destroyed, on the
 * directory it served (not on the container deleted around it), then one
 * directory.delete item per directory, with the number of its links lost
 * (shares_destroyed, as file.trash carries). Each side names the other, so
 * the two cards read as cause and effect.
 *
 * Written after the commit, so the transaction does not hold the
 * activity_log row locks while it deletes. The rows are gone by then, so the
 * names captured before the delete and the final visit counts go into the
 * items, as ShareService::destroyShare() records them. docs/features.md
 * promises the loss is never silent.
 *
 * @param list<array{share_id: int, visit_count: int, directory_id: int}> $revoked
 * @param array<int, array<string, mixed>|null> $dirNames as for revoke_directory_shares()
 * @param "directory_deleted"|"container_deleted" $reason
 */
function log_deleted_directories(PDO $db, array $revoked, array $dirNames, string $reason): void
{
    $lost = [];
    foreach ($revoked as $r) {
        $lost[$r["directory_id"]] = ($lost[$r["directory_id"]] ?? 0) + 1;
        log_activity($db, "share.revoke", "directory", $r["directory_id"], [
            "share_id" => $r["share_id"],
            "reason" => $reason,
            "visit_count" => $r["visit_count"],
        ] + ($dirNames[$r["directory_id"]] ?? []));
    }
    foreach ($dirNames as $dirId => $dirName) {
        log_activity($db, "directory.delete", "directory", $dirId, [
            "shares_destroyed" => $lost[$dirId] ?? 0,
        ] + ($dirName ?? []));
    }
}

/**
 * For a folder or container delete, inside its transaction: lock the parent
 * row, then confirm no live file points at it. False when one arrived after
 * the caller trashed the contents.
 *
 * @param "directories"|"containers" $table
 * @param "directory_id"|"container_id" $fileColumn
 */
function lock_and_confirm_emptied(PDO $db, string $table, string $fileColumn, int $id): bool
{
    $db->prepare("SELECT id FROM " . DB_PREFIX . $table . " WHERE id = :id FOR UPDATE")
        ->execute([":id" => $id]);
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM " . DB_PREFIX . "files WHERE {$fileColumn} = :id AND trashed_at IS NULL"
    );
    $stmt->execute([":id" => $id]);
    return (int) $stmt->fetchColumn() === 0;
}

// ── Share rows ────────────────────────────────────────────────────────────────

/**
 * Delete one share together with its visitor sessions and wrapped file keys.
 *
 * The child tables also cascade, but are deleted explicitly in case the
 * foreign keys did not survive a dump and re-import (see "wrong_storage" in
 * integrity_check.php). Runs inside the caller's transaction, if any.
 */
function share_delete(PDO $db, int $shareId): void
{
    share_sessions_delete($db, "share_id = :id", [":id" => $shareId]);
    foreach (["share_file_keys" => "share_id", "shares" => "id"] as $table => $col) {
        $db->prepare("DELETE FROM " . DB_PREFIX . $table . " WHERE {$col} = :id")
            ->execute([":id" => $shareId]);
    }
}

/**
 * Delete the visitor sessions matching $where (a condition on
 * share_sessions, written here, never from the request), with the files each
 * one has opened. Those cascade too, but go explicitly for the same reason
 * as in share_delete(). Returns how many sessions went.
 */
function share_sessions_delete(PDO $db, string $where, array $params = []): int
{
    $db->prepare(
        "DELETE FROM " . DB_PREFIX . "share_session_files WHERE session_id IN
            (SELECT id FROM " . DB_PREFIX . "share_sessions WHERE {$where})"
    )->execute($params);
    $stmt = $db->prepare("DELETE FROM " . DB_PREFIX . "share_sessions WHERE {$where}");
    $stmt->execute($params);
    return $stmt->rowCount();
}

/** Keys of {P}stats: share visitors' openings, downloads and previews, ever. */
const SHARE_TOTAL_KEYS = ["visits", "downloads", "previews"];

/**
 * Add one to a share total (see share_stats()). One statement that also
 * creates a missing row, so a database restored without {P}stats rows heals
 * itself. Never throws: a statistic must not fail the visitor's request.
 */
function share_total_bump(PDO $db, string $key): void
{
    if (!in_array($key, SHARE_TOTAL_KEYS, true)) {
        return;
    }
    try {
        $db->prepare(
            "INSERT INTO " . DB_PREFIX . "stats (stat_key, stat_value) VALUES (:k, 1)
             ON DUPLICATE KEY UPDATE stat_value = stat_value + 1"
        )->execute([":k" => $key]);
    } catch (Throwable $e) {
        error_log("[AESBin] share total {$key}: " . $e->getMessage());
    }
}

/**
 * The dashboard's share figures: live links now, and the share totals since
 * installation (0 for a key never bumped).
 *
 * @return array{links: int, visits: int, downloads: int, previews: int}
 */
function share_stats(PDO $db): array
{
    $stats = [
        "links" => (int) $db->query(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "shares
              WHERE expires_at IS NULL OR expires_at > NOW()"
        )->fetchColumn(),
        "visits" => 0,
        "downloads" => 0,
        "previews" => 0,
    ];
    foreach ($db->query("SELECT stat_key, stat_value FROM " . DB_PREFIX . "stats")->fetchAll() as $row) {
        if (in_array($row["stat_key"], SHARE_TOTAL_KEYS, true)) {
            $stats[$row["stat_key"]] = (int) $row["stat_value"];
        }
    }
    return $stats;
}

/**
 * Destroy every file share aimed at $fileId and return the count. Each gets a
 * share.revoke item, logged first so the log can still name the file.
 *
 * shares.target_id is polymorphic with no foreign key, so every path that
 * trashes or deletes a file row must call this.
 */
function destroy_file_shares(PDO $db, int $fileId, string $reason): int
{
    $stmt = $db->prepare(
        "SELECT id, visit_count FROM " . DB_PREFIX . "shares WHERE type = 'file' AND target_id = :id"
    );
    $stmt->execute([":id" => $fileId]);
    $shares = $stmt->fetchAll();
    foreach ($shares as $share) {
        $shareId = (int) $share["id"];
        log_activity($db, "share.revoke", "file", $fileId, [
            "share_id" => $shareId,
            "reason" => $reason,
            // As ShareService::destroyShare(): the row is gone, so this is
            // the only record of the count left.
            "visit_count" => (int) $share["visit_count"],
        ]);
        share_delete($db, $shareId);
    }
    return count($shares);
}

/**
 * Delete one file row and every share on it, in one transaction. The blob is
 * the caller's business, and comes after: a failure before the commit then
 * leaves the file whole, and one after it an orphan blob that
 * integrity_check.php reports. The reverse order could leave a row whose blob
 * is already wiped.
 */
function delete_file_row(PDO $db, int $fileId, string $reason): void
{
    $db->beginTransaction();
    try {
        destroy_file_shares($db, $fileId, $reason);
        $db->prepare("DELETE FROM " . DB_PREFIX . "files WHERE id = :id")
            ->execute([":id" => $fileId]);
        $db->commit();
    } catch (Throwable $e) {
        db_rollback_if_open($db);
        throw $e;
    }
}

// ── Activity log ──────────────────────────────────────────────────────────────

/**
 * Short display label from a User-Agent, e.g. "Firefox · Android".
 * Display only. Order matters: Edge and Opera contain "Chrome", Chrome
 * contains "Safari".
 */
function client_label(?string $ua): ?string
{
    if (!$ua) {
        return null;
    }
    $browser = match (true) {
        str_contains($ua, "Firefox/"),
        str_contains($ua, "FxiOS/") => "Firefox",
        str_contains($ua, "Edg/"),
        str_contains($ua, "EdgiOS/") => "Edge",
        str_contains($ua, "OPR/"),
        str_contains($ua, "Opera") => "Opera",
        str_contains($ua, "SamsungBrowser/") => "Samsung Internet",
        str_contains($ua, "Chrome/"),
        str_contains($ua, "CriOS/") => "Chrome",
        str_contains($ua, "Safari/") => "Safari",
        default => null,
    };
    $os = match (true) {
        str_contains($ua, "Android") => "Android",
        str_contains($ua, "iPhone"),
        str_contains($ua, "iPad"),
        str_contains($ua, "iPod") => "iOS",
        str_contains($ua, "Windows") => "Windows",
        str_contains($ua, "Mac OS X"),
        str_contains($ua, "Macintosh") => "macOS",
        str_contains($ua, "CrOS") => "ChromeOS",
        str_contains($ua, "Linux") => "Linux",
        default => null,
    };
    if ($browser !== null && $os !== null) {
        return $browser . " · " . $os;
    }
    return $browser ?? $os;
}

/**
 * Events of unauthenticated share visitors, logged without ip/user_agent:
 * they never agreed to be recorded (docs/features.md). Rate limiting still
 * works from rate_limit, whose rows expire.
 */
const VISITOR_EVENTS = [
    "share.visit",
    "share.preview",
    "share.download",
    "share.failure",
    "share.token_probe",
    // Not route.not_found: a 404 is not a visit to shared content, and IP/UA
    // is what tells the owner's stale device from a scanner.
];

/**
 * Events nobody performs: an expiry is found, by the owner's share list or
 * activity log, or by a visitor opening the dead link. The finder's ip/ua
 * would describe no actor, and in the visitor case would tie a visitor to
 * the share (see SECURITY.md). Not visitor events: the card stays owner-side.
 */
const ACTORLESS_EVENTS = ["share.expire"];

/**
 * True when an event originates from a share visitor rather than the owner.
 *
 * log_activity() (store an address?) and recent_duplicate_activity() (dedup
 * by address?) must agree, or an ip-less row looked up by ip never matches.
 */
function activity_event_is_visitor(string $event): bool
{
    return in_array($event, VISITOR_EVENTS, true);
}

/**
 * True when an event has no actor (ACTORLESS_EVENTS): stored without ip/ua,
 * but still an owner-side card.
 */
function activity_event_is_actorless(string $event): bool
{
    return in_array($event, ACTORLESS_EVENTS, true);
}

/**
 * Package the pre-rename name ciphertext (from the row the caller read before
 * updating) as prev_name_* meta, so a rename reads "old → new".
 * log_activity() captures the new name itself.
 *
 * $aad must be what the blob was sealed under: CryptoService::fileMetaAad()
 * for files (a rename keeps the storage name), ::rowAad() otherwise.
 */
function activity_prev_name_enc(array $row, string $aad): array
{
    if (empty($row["name_enc"])) {
        return [];
    }
    return [
        "prev_name_enc" => $row["name_enc"],
        "prev_name_iv" => $row["name_iv"],
        "prev_name_tag" => $row["name_tag"],
        "prev_name_aad" => $aad,
    ];
}

/**
 * A live link that ends within this many seconds is "expiring soon": a dot on
 * the links icon and a card at the top of the shares page (dashboard.js),
 * so it can be extended from its edit dialog before it dies. Nowhere else, on purpose.
 */
const SHARE_EXPIRING_SOON = 86400;

/** Live links expiring within SHARE_EXPIRING_SOON, for the server-rendered dot. */
function shares_expiring_soon_count(PDO $db): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM " . DB_PREFIX . "shares
          WHERE expires_at > NOW() AND expires_at <= NOW() + INTERVAL :s SECOND"
    );
    $stmt->execute([":s" => SHARE_EXPIRING_SOON]);
    return (int) $stmt->fetchColumn();
}

/**
 * How many files are in the trash. Shared by /api/system/status and the
 * server-rendered badge in dashboard.php so they count the same thing.
 */
function trash_file_count(PDO $db): int
{
    return (int) $db
        ->query(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "files WHERE trashed_at IS NOT NULL"
        )
        ->fetchColumn();
}

/**
 * Copy an object's encrypted name into log meta, so the item can still be
 * named after the object is deleted or renamed.
 *
 * Ciphertext only: activity_log.meta is plain JSON, and a readable name there
 * would break docs/security-model.md. Returns null if the row is gone; the
 * caller logs without it.
 */
function activity_capture_name_enc(PDO $db, string $type, int $id): ?array
{
    // AAD differs by type: files are bound to the storage name (no row id,
    // so it survives a renumbering re-import), directories and containers to
    // their id. It is stored with the ciphertext; a wrong AAD would fail
    // silently on read.
    $table = match ($type) {
        "file" => "files",
        "directory" => "directories",
        "container" => "containers",
        default => null,
    };
    if ($table === null) {
        return null;
    }

    try {
        $cols = $type === "file"
            ? "name_enc, name_iv, name_tag, storage_name"
            : "name_enc, name_iv, name_tag";
        $stmt = $db->prepare(
            "SELECT " . $cols . " FROM " . DB_PREFIX . $table . " WHERE id = :id LIMIT 1"
        );
        $stmt->execute([":id" => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            "name_enc" => $row["name_enc"],
            "name_iv" => $row["name_iv"],
            "name_tag" => $row["name_tag"],
            "name_aad" => $type === "file"
                ? CryptoService::fileMetaAad("name", $row["storage_name"])
                : CryptoService::rowAad($type, $id),
        ];
    } catch (Throwable) {
        return null;
    }
}

/**
 * activity_capture_name_enc() under another prefix, for an item that names
 * more than its target: "prev_name" (a retarget's previous target),
 * "from_container_name", "to_dir_name"… decorate_activity_item() below
 * decrypts every prefix in its list and strips the ciphertext. Empty when
 * the object is gone.
 */
function activity_name_enc_as(PDO $db, string $type, int $id, string $prefix): array
{
    $sealed = activity_capture_name_enc($db, $type, $id);
    if ($sealed === null) {
        return [];
    }
    $out = [];
    foreach ($sealed as $key => $value) {
        $out[$prefix . substr($key, strlen("name"))] = $value;
    }
    return $out;
}

/**
 * The settings a settings.update item lists one by one, with how their
 * values are logged: "text" fields (the privacy identity, the contact link)
 * only as set, cleared or changed, since the log is not encrypted; the rest
 * old and new value. The captcha secrets are never here. Keys are paths into
 * config.php ("security.session_ip_lock"); dashboard.js labels them with the
 * log_setting_* keys and formats each kind.
 */
const SETTINGS_LOG_FIELDS = [
    "locale" => "locale",
    "security.session_idle_timeout" => "seconds",
    "security.session_ip_lock" => "bool",
    "security.login_max_attempts" => "int",
    "security.login_window" => "seconds",
    "captcha.enabled" => "bool",
    "captcha.level" => "level",
    "captcha.show_footer" => "bool",
    "captcha.show_logo" => "bool",
    "activity_log_preview" => "int",
    "activity_log_item_cap" => "int",
    "max_upload_bytes" => "bytes",
    "max_total_bytes" => "bytes",
    "privacy_enabled" => "bool",
    "privacy_name" => "text",
    "privacy_address" => "text",
    "privacy_vat" => "text",
    "privacy_email" => "text",
    "contact_enabled" => "bool",
    "contact_link" => "text",
    "contact_referrer" => "bool",
];

/**
 * What changed between two config arrays, as settings.update items list it:
 * [{field, kind, from, to}] for values, [{field, kind: "text", change:
 * "set"|"cleared"|"changed"}] for text. Fields absent from a config read as
 * null, so a key added by the save shows as null → value.
 *
 * @return list<array<string, mixed>>
 */
function settings_log_changes(array $before, array $after): array
{
    $read = static function (array $cfg, string $path) {
        foreach (explode(".", $path) as $part) {
            if (!is_array($cfg) || !array_key_exists($part, $cfg)) {
                return null;
            }
            $cfg = $cfg[$part];
        }
        return $cfg;
    };
    $changes = [];
    foreach (SETTINGS_LOG_FIELDS as $field => $kind) {
        $from = $read($before, $field);
        $to = $read($after, $field);
        if ($kind === "bool") {
            $from = $from === null ? null : (bool) $from;
            $to = (bool) $to;
        }
        if ($from === $to) {
            continue;
        }
        if ($kind === "text") {
            $hadValue = trim((string) $from) !== "";
            $hasValue = trim((string) $to) !== "";
            if (!$hadValue && !$hasValue) {
                continue;
            }
            $changes[] = [
                "field" => $field,
                "kind" => $kind,
                "change" => !$hadValue ? "set" : (!$hasValue ? "cleared" : "changed"),
            ];
            continue;
        }
        $changes[] = ["field" => $field, "kind" => $kind, "from" => $from, "to" => $to];
    }
    return $changes;
}

/**
 * Append one occurrence to $event's row, creating it on first use; items are
 * capped (docs/maintaining.md § the activity log is one row per event). Also
 * drives the rate-limit buckets. Never put keys, passwords or tokens in $meta.
 */
function log_activity(
    PDO $db,
    string $event,
    ?string $targetType = null,
    ?int $targetId = null,
    array $meta = []
): void {
    // Each item records the name the object had at that moment (as
    // ciphertext, see activity_capture_name_enc()). An explicit name_enc in
    // $meta wins: deletion call sites capture it before the row is gone.
    if ($targetId !== null && !isset($meta["name_enc"])) {
        if (in_array($targetType, ["file", "directory", "container"], true)) {
            $captured = activity_capture_name_enc($db, $targetType, $targetId);
            if ($captured !== null) {
                $meta += $captured;
            }
        } elseif ($targetType === "share") {
            // A share has no name: capture its target's. real_target_type/id
            // are stored in plain (not sensitive) so the UI can badge the item
            // with the object the name belongs to.
            $stmt = $db->prepare(
                "SELECT type, target_id FROM " . DB_PREFIX . "shares WHERE id = :id LIMIT 1"
            );
            $stmt->execute([":id" => $targetId]);
            $shareRow = $stmt->fetch();
            if ($shareRow) {
                $meta["real_target_type"] = $shareRow["type"];
                $meta["real_target_id"] = (int) $shareRow["target_id"];
                $captured = activity_capture_name_enc($db, $shareRow["type"], (int) $shareRow["target_id"]);
                if ($captured !== null) {
                    $meta += $captured;
                }
            }
        }
    }

    // Rate limiting runs first, so a failure in the log write below can never
    // stop the counting. Mapping: RATE_LIMIT_BUCKETS, RATE_LIMIT_BUCKET_OF,
    // RATE_LIMIT_CLEARED_BY.
    $bucket = RATE_LIMIT_BUCKET_OF[$event]
        ?? (in_array($event, RATE_LIMIT_BUCKETS, true) ? $event : null);
    if ($bucket !== null) {
        rate_limit_record($db, $bucket);
    } elseif (isset(RATE_LIMIT_CLEARED_BY[$event])) {
        rate_limit_clear($db, RATE_LIMIT_CLEARED_BY[$event]);
    }

    // One row per event with capped items, so a flood (e.g. token probes)
    // only rotates its own row. $meta may override "at", "ip" and "ua": the
    // session-expiry sweep logs on behalf of another client, after the fact.
    $item = ["at" => gmdate("Y-m-d H:i:s")];
    // See VISITOR_EVENTS and ACTORLESS_EVENTS.
    if (!activity_event_is_visitor($event) && !activity_event_is_actorless($event)) {
        $item["ip"] = request_ip();
        $item["ua"] = request_ua();
    }
    if ($targetType !== null) {
        $item["target_type"] = $targetType;
    }
    if ($targetId !== null) {
        $item["target_id"] = $targetId;
    }
    $item = $meta + $item;

    global $config;
    $itemCap = activity_log_item_cap((array) ($config ?? []));

    // Read-modify-write under a row lock, or concurrent hits on the same
    // event would overwrite each other's items. Inside a caller's
    // transaction, the outer one owns commit/rollback.
    $ownTxn = !$db->inTransaction();
    if ($ownTxn) {
        $db->beginTransaction();
    }
    try {
        // The row is created before it is locked. A locking read of a row
        // that does not exist yet takes only a gap lock, which two requests
        // can hold at once; their INSERTs would then deadlock each other.
        $db->prepare(
            "INSERT INTO " . DB_PREFIX . "activity_log (event, created_at, meta)
             VALUES (:ev, :at, '{\"items\": []}')
             ON DUPLICATE KEY UPDATE id = id"
        )->execute([":ev" => $event, ":at" => $item["at"]]);

        $stmt = $db->prepare(
            "SELECT id, meta FROM " . DB_PREFIX . "activity_log WHERE event = :ev FOR UPDATE"
        );
        $stmt->execute([":ev" => $event]);
        $row = $stmt->fetch();

        $rowMeta = json_decode((string) $row["meta"], true);
        $rowMeta = is_array($rowMeta) ? $rowMeta : [];
        $items = is_array($rowMeta["items"] ?? null) ? $rowMeta["items"] : [];
        // Prepended, then sorted newest first by timestamp: a back-dated item
        // (the session-expiry sweep) lands where it belongs, and usort() is
        // stable, so among items of the same second the new one stays first
        // and the cap below drops the oldest.
        array_unshift($items, $item);
        usort($items, fn($x, $y) => strcmp((string) ($y["at"] ?? ""), (string) ($x["at"] ?? "")));
        // No lifetime total here: the share totals live in {P}stats.
        $rowMeta["items"] = array_slice($items, 0, $itemCap);
        // Write order in microseconds, for cards whose latest items share a
        // second: created_at has whole seconds, so an unlock and the save it
        // allowed would otherwise sort by row id, which is when the event
        // type was first logged. Only when this item is the card's newest; a
        // back-dated one (the session-expiry sweep) does not move the card.
        if (($rowMeta["items"][0] ?? null) === $item) {
            $rowMeta["seq"] = (int) round(microtime(true) * 1000000);
        }

        $db->prepare(
            "UPDATE " . DB_PREFIX . "activity_log SET meta = :meta, created_at = :at WHERE id = :id"
        )->execute([
            ":meta" => json_encode($rowMeta, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            ":at" => (string) ($rowMeta["items"][0]["at"] ?? $item["at"]),
            ":id" => (int) $row["id"],
        ]);

        if ($ownTxn) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTxn) {
            db_rollback_if_open($db);
        }
        throw $e;
    }
}

/**
 * Cut every card down to $cap items, after the cap is lowered: the privacy
 * page promises at most that many occurrences are kept, and a card whose
 * event does not fire again would otherwise keep the old number (and show
 * them again if the cap went back up). A few dozen rows at most.
 */
function activity_log_trim(PDO $db, int $cap): void
{
    $db->beginTransaction();
    try {
        $rows = $db->query("SELECT id, meta FROM " . DB_PREFIX . "activity_log FOR UPDATE")->fetchAll();
        $update = $db->prepare("UPDATE " . DB_PREFIX . "activity_log SET meta = :meta WHERE id = :id");
        foreach ($rows as $row) {
            $meta = json_decode((string) $row["meta"], true);
            if (!is_array($meta) || !is_array($meta["items"] ?? null) || count($meta["items"]) <= $cap) {
                continue;
            }
            $meta["items"] = array_slice($meta["items"], 0, $cap);
            $update->execute([
                ":meta" => json_encode($meta, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                ":id" => (int) $row["id"],
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        db_rollback_if_open($db);
        throw $e;
    }
}

/**
 * Events logged with an address that need not be the owner's: a request for
 * a path that does not exist, and failed attempts made before anyone is
 * authenticated. On top of the per-event cap, their items expire after
 * ACTIVITY_UNAUTH_TTL, as the privacy page says: on an instance that sees
 * few of them, the cap alone would keep a scanner's address for months.
 * Successful logins, the owner's own actions and elevate.failure (which needs
 * a session) keep only the cap.
 */
const ACTIVITY_UNAUTH_EVENTS = [
    "route.not_found",
    "login.failure",
    "login.captcha_fail",
    "login.key_mismatch",
    "passkey.failure",
    "passkey.key_mismatch",
    "recovery.failure",
    "recovery.captcha_fail",
    "recovery.guard_fail",
];

/** Days an ACTIVITY_UNAUTH_EVENTS item is kept. Quoted by the privacy page. */
const ACTIVITY_UNAUTH_TTL_DAYS = 90;

/** At most one expiry pass per this many seconds (see activity_log_expire()). */
const ACTIVITY_EXPIRE_INTERVAL = 3600;

/**
 * Drop ACTIVITY_UNAUTH_EVENTS items older than ACTIVITY_UNAUTH_TTL_DAYS, and
 * a card left with none. Called by bootstrap.php on every request, so it runs
 * whether or not those events fire again or the dashboard is opened; the
 * mtime of a marker in the storage directory limits the work to one pass an
 * hour, and the check costs a stat in between. Never fatal: a failure is
 * logged and the request goes on.
 */
function activity_log_expire(PDO $db): void
{
    $marker = AESBIN_FILES . "/.activity_expire";
    $last = @filemtime($marker);
    if ($last !== false && time() - $last < ACTIVITY_EXPIRE_INTERVAL) {
        return;
    }
    @touch($marker);

    // Items store "at" as UTC "Y-m-d H:i:s", which compares as a string.
    $cutoff = gmdate("Y-m-d H:i:s", time() - ACTIVITY_UNAUTH_TTL_DAYS * 86400);
    $in = implode(",", array_fill(0, count(ACTIVITY_UNAUTH_EVENTS), "?"));

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "SELECT id, meta FROM " . DB_PREFIX . "activity_log WHERE event IN ({$in}) FOR UPDATE"
        );
        $stmt->execute(ACTIVITY_UNAUTH_EVENTS);
        $update = $db->prepare(
            "UPDATE " . DB_PREFIX . "activity_log SET meta = :meta, created_at = :at WHERE id = :id"
        );
        $delete = $db->prepare("DELETE FROM " . DB_PREFIX . "activity_log WHERE id = :id");
        foreach ($stmt->fetchAll() as $row) {
            $meta = json_decode((string) $row["meta"], true);
            if (!is_array($meta) || !is_array($meta["items"] ?? null)) {
                continue;
            }
            $kept = array_values(array_filter(
                $meta["items"],
                fn($item) => !is_array($item) || (string) ($item["at"] ?? $cutoff) >= $cutoff
            ));
            if (count($kept) === count($meta["items"])) {
                continue;
            }
            // An empty card is removed, as "flush now" does: it would only
            // show an event type with nothing in it.
            if ($kept === []) {
                $delete->execute([":id" => (int) $row["id"]]);
                continue;
            }
            $meta["items"] = $kept;
            $update->execute([
                ":meta" => json_encode($meta, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                // Items are newest first (log_activity()).
                ":at" => (string) ($kept[0]["at"] ?? $cutoff),
                ":id" => (int) $row["id"],
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        db_rollback_if_open($db);
        error_log("[AESBin] activity log expiry failed: " . $e->getMessage());
    }
}

/**
 * The current name of a file, folder or container for an activity item, or
 * null once it is gone or unreadable.
 */
function resolve_activity_target_name(
    PDO $db,
    CryptoService $crypto,
    string $masterKey,
    string $type,
    int $id
): ?string {
    // The same lookup a deletion uses to capture the name, and the same AAD.
    $sealed = activity_capture_name_enc($db, $type, $id);
    if ($sealed === null) {
        return null;
    }
    $name = $crypto->decryptColumn($sealed, "name", $masterKey, $sealed["name_aad"]);
    return $name !== "" ? $name : null;
}

/**
 * Decorate one occurrence. Resolution is per item: the target varies within
 * a card.
 */
function decorate_activity_item(
    PDO $db,
    CryptoService $crypto,
    string $masterKey,
    array $item
): array {
    // The raw UA stays server-side; the client gets a label ("Firefox · Android").
    if (isset($item["ua"])) {
        $item["client"] = client_label((string) $item["ua"]);
        unset($item["ua"]);
    }

    $targetType = $item["target_type"] ?? null;
    $targetId = isset($item["target_id"]) ? (int) $item["target_id"] : null;

    // Names captured at log time are ciphertext (see
    // activity_capture_name_enc()), with the AAD of their own table: the name,
    // and on a rename the previous one. Stripped afterwards so no ciphertext
    // leaves the server.
    // activity_name_enc_as() prefixes too: a move's origin and destination.
    foreach (["name", "prev_name", "from_container_name", "from_dir_name", "to_container_name", "to_dir_name"] as $field) {
        if (!isset($item[$field . "_enc"])) {
            continue;
        }
        // An unreadable blob leaves the field unset: the item still shows its id.
        $plain = $crypto->decryptColumn($item, $field, $masterKey, (string) ($item[$field . "_aad"] ?? ""));
        if ($plain !== "") {
            $item[$field] = $plain;
        }
        unset(
            $item[$field . "_enc"],
            $item[$field . "_iv"],
            $item[$field . "_tag"],
            $item[$field . "_aad"]
        );
    }

    // Only for an item without a captured name: the client shows item.name
    // first, so a lookup and decrypt would be wasted on the others.
    $targetName = null;
    if (!isset($item["name"]) && $targetId !== null) {
        if (in_array($targetType, ["file", "directory", "container"], true)) {
            $targetName = resolve_activity_target_name($db, $crypto, $masterKey, $targetType, $targetId);
        } elseif ($targetType === "share") {
            // log_activity() normally records a share item's name; failing
            // that, the link's current target.
            $sStmt = $db->prepare(
                "SELECT type, target_id FROM " . DB_PREFIX . "shares WHERE id = :id LIMIT 1"
            );
            $sStmt->execute([":id" => $targetId]);
            $share = $sStmt->fetch();
            if ($share) {
                $targetName = resolve_activity_target_name(
                    $db,
                    $crypto,
                    $masterKey,
                    $share["type"],
                    (int) $share["target_id"]
                );
            }
        }
    }
    $item["target_name"] = $targetName;

    return $item;
}

/**
 * Where the logged objects still live. Bounded by the log itself (event
 * types × item_cap), not by the size of the vault.
 *
 * @param array<int, array<string, mixed>> $cards
 * @return array<string, object> keyed by type, each an id → location map
 */
function activity_live_targets(PDO $db, array $cards): array
{
    $ids = ["file" => [], "directory" => [], "container" => []];
    foreach ($cards as $card) {
        foreach ($card["items"] as $item) {
            // A share item names the object its link pointed to.
            $type = $item["real_target_type"] ?? $item["target_type"] ?? null;
            $id = $item["real_target_id"] ?? $item["target_id"] ?? null;
            // $type is null for an item that names no object: tested first,
            // as a null array key is deprecated since PHP 8.5.
            if ($type !== null && isset($ids[$type]) && $id !== null) {
                $ids[$type][(int) $id] = true;
            }
        }
    }

    $live = ["file" => [], "directory" => [], "container" => []];
    $queries = [
        "file" => "SELECT id, container_id, directory_id FROM " . DB_PREFIX . "files
                    WHERE trashed_at IS NULL AND container_id IS NOT NULL AND id IN (%s)",
        "directory" => "SELECT id, container_id FROM " . DB_PREFIX . "directories WHERE id IN (%s)",
        "container" => "SELECT id FROM " . DB_PREFIX . "containers WHERE id IN (%s)",
    ];
    foreach ($queries as $type => $sql) {
        if (!$ids[$type]) {
            continue;
        }
        $list = array_keys($ids[$type]);
        $stmt = $db->prepare(sprintf($sql, implode(",", array_fill(0, count($list), "?"))));
        $stmt->execute($list);
        foreach ($stmt->fetchAll() as $r) {
            $live[$type][(int) $r["id"]] = match ($type) {
                "file" => [
                    "c" => (int) $r["container_id"],
                    "d" => $r["directory_id"] !== null ? (int) $r["directory_id"] : null,
                ],
                "directory" => ["c" => (int) $r["container_id"]],
                // An object, so json_encode() writes {} and not [].
                default => new stdClass(),
            };
        }
    }
    // Objects, not lists: the client looks ids up by key.
    return array_map(fn($m) => (object) $m, $live);
}

/**
 * Events the limiter counts, in {P}rate_limit rather than the activity log,
 * which the owner may clear. "share.failure", "file.search" and
 * "login.totp" are recorded by their callers instead.
 */
const RATE_LIMIT_BUCKETS = [
    "login.failure",
    "login.captcha_fail",
    "passkey.failure",
    "elevate.failure",
    "recovery.failure",
    "recovery.captcha_fail",
    // Separate from recovery.failure so a mistyped guard code does not use up
    // the recovery phrase attempts, nor the reverse.
    "recovery.guard_fail",
    // Tokens matching no share. Loose threshold ($maxOverride in
    // app/actions/share/auth.php): reloading a dead link is the usual cause, and a
    // 64-bit token is not enumerable.
    "share.token_probe",
];

/**
 * Success events, and the buckets each one clears for the current IP.
 *
 * Proving ownership ends a self-inflicted lockout. The failed attempts remain
 * in activity_log. Captcha buckets are cleared by nothing: solving a
 * proof-of-work proves nothing about ownership.
 */
const RATE_LIMIT_CLEARED_BY_LOGIN = [
    "login.failure",
    "login.totp",
    "passkey.failure",
    "elevate.failure",
    "recovery.failure",
    "recovery.guard_fail",
];
const RATE_LIMIT_CLEARED_BY = [
    "login.success" => RATE_LIMIT_CLEARED_BY_LOGIN,
    "passkey.login" => RATE_LIMIT_CLEARED_BY_LOGIN,
    // The strongest proof of ownership there is, and it ends in /login.
    "recovery.success" => RATE_LIMIT_CLEARED_BY_LOGIN,
    "settings.elevated" => ["elevate.failure"],
    // share.visit clears a per-share bucket, which this table cannot express:
    // ShareService::authenticate() calls rate_limit_clear() itself.
];

/**
 * Failure events that feed a bucket they are not named after, keeping their
 * own log label. Passkey login can fail at the WebAuthn assertion
 * ("passkey.failure") or at the TOTP-canary check on the returned master key
 * ("passkey.key_mismatch"); both count towards one limit for the flow.
 */
const RATE_LIMIT_BUCKET_OF = [
    "passkey.key_mismatch" => "passkey.failure",
    // Reached only after password_verify() passes, so it is a login attempt.
    "login.key_mismatch" => "login.failure",
];

/**
 * How long a rate-limit row is kept before it can be swept (seconds). Twice
 * the longest login_window (SETTINGS_ALLOWED): a lockout only ever reads rows
 * inside one window, so nothing older is needed. Kept short because a failed
 * share attempt stores the visitor's address here.
 */
const RATE_LIMIT_TTL = 7200; // 2 h

/**
 * Buckets counted across all clients. Argon2id makes per-client limits
 * enough for passwords, but a TOTP code (a million values, three valid)
 * would fall to a botnet, so its count is global and stays on even with
 * login_max_attempts = 0. The cost: whoever holds the password can keep TOTP
 * logins blocked; recovery clears the bucket and can change the password.
 */
const RATE_LIMIT_GLOBAL_BUCKETS = ["login.totp"];

/**
 * Failures a global bucket takes before it blocks, per login_window, when
 * login_max_attempts is lower (see SecurityService::rateLimitStatus()).
 */
const RATE_LIMIT_GLOBAL_MAX = 20;

/**
 * Who a rate-limit row is counted against: "*" for a global bucket,
 * otherwise client_network() (the /64 for IPv6).
 */
function rate_limit_subject(string $bucket): string
{
    if (in_array($bucket, RATE_LIMIT_GLOBAL_BUCKETS, true)) {
        return "*";
    }
    return client_network(request_ip());
}

/**
 * The unit a client is recognised by: the /64 for IPv6, the address itself
 * for IPv4. Shared by rate_limit_subject() and the session IP lock
 * (Auth::requireAuth()) so they agree on "the same client".
 *
 * An IPv6 host holds a whole /64 and rotates temporary addresses within it
 * (RFC 4941/8981). Per address, the IP lock would log the owner out on each
 * rotation and the limiter would give one client 2^64 budgets.
 */
function client_network(string $ip): string
{
    $bin = @inet_pton($ip);
    if (is_string($bin) && strlen($bin) === 16) {
        return (string) inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . "/64";
    }
    return $ip;
}

/**
 * Delete rate-limit rows older than RATE_LIMIT_TTL. Run once per request by
 * bootstrap.php, so a row never outlives the TTL by much even when no further
 * failure arrives: the privacy page promises visitors' addresses are gone
 * after two hours. One index range scan (idx_rl_created).
 */
function rate_limit_sweep(PDO $db): void
{
    $db->prepare("DELETE FROM " . DB_PREFIX . "rate_limit WHERE created_at < :cutoff")
        ->execute([":cutoff" => gmdate("Y-m-d H:i:s", time() - RATE_LIMIT_TTL)]);
}

/** Record one failure in $bucket for the current subject. */
function rate_limit_record(PDO $db, string $bucket): void
{
    $db->prepare(
        "INSERT INTO " . DB_PREFIX . "rate_limit (bucket, ip, created_at) VALUES (:b, :ip, NOW())"
    )->execute([":b" => $bucket, ":ip" => rate_limit_subject($bucket)]);
}

/** Clear the given buckets, each for its own subject (see rate_limit_subject()). */
function rate_limit_clear(PDO $db, array $buckets): void
{
    $stmt = $db->prepare(
        "DELETE FROM " . DB_PREFIX . "rate_limit WHERE ip = :ip AND bucket = :b"
    );
    foreach ($buckets as $bucket) {
        $stmt->execute([":ip" => rate_limit_subject($bucket), ":b" => $bucket]);
    }
}

// Dedup window for recent_duplicate_activity(), independent of any session
// TTL.
const ACTIVITY_DEDUP_WINDOW = 3600; // 1 hour

/**
 * True if $event (optionally narrowed to ip/target) was already logged within
 * $windowSeconds, judged by the event's most recent item. For collapsing
 * repeats of one user action (a browser retry, two tabs logging out the same
 * session), not for suppressing genuinely repeated actions.
 */
function recent_duplicate_activity(
    PDO $db,
    string $event,
    string $ip,
    int $windowSeconds,
    ?string $targetType = null,
    ?int $targetId = null
): bool {
    // One row per event (uq_al_event), as log_activity() writes it.
    $stmt = $db->prepare(
        "SELECT meta FROM " . DB_PREFIX . "activity_log WHERE event = :ev"
    );
    $stmt->execute([":ev" => $event]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }

    $rowMeta = json_decode((string) $row["meta"], true) ?: [];
    $items = is_array($rowMeta["items"] ?? null) ? $rowMeta["items"] : [];
    if (!$items) {
        return false;
    }
    // Items are newest first.
    $latest = $items[0];

    // Visitor items carry no ip; see activity_event_is_visitor().
    if (!activity_event_is_visitor($event) && ($latest["ip"] ?? null) !== $ip) {
        return false;
    }
    if ($targetType !== null && ($latest["target_type"] ?? null) !== $targetType) {
        return false;
    }
    if ($targetId !== null && ($latest["target_id"] ?? null) !== $targetId) {
        return false;
    }

    $itemTime = strtotime(($latest["at"] ?? "") . " UTC");
    return $itemTime !== false && $itemTime > time() - $windowSeconds;
}

// ── HTML escaping ─────────────────────────────────────────────────────────────

/**
 * json_encode() flags for a value printed inside an inline <script>: the
 * HEX_* flags keep "</script>", quotes and "&" from ending or breaking the
 * block, and invalid UTF-8 is substituted rather than failing the encode.
 */
const JSON_FOR_SCRIPT = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE |
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

/** Escape a string for safe HTML output. */
function e(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

/**
 * The attributes of every link that leaves the site, for the views: a new tab
 * that cannot reach this one, no referrer (the rel and the attribute, for
 * browsers that know only one) and nofollow. Same set as EXT_LINK in
 * helper.js, which builds the links JavaScript prints.
 */
const EXT_LINK_ATTRS = 'target="_blank" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer"';

/**
 * An icon from the sprite (public/assets/img/icons.svg), sized to the text
 * around it and drawn in its colour. Decorative: the label next to it, or the
 * title/aria-label of its button, says what it means. $class adds a colour
 * modifier from base.css (ic-ok, ic-warn, ic-err, ic-muted). Mirrors icon()
 * in helper.js.
 */
function icon(string $name, string $class = ""): string
{
    return '<svg class="ic' . ($class !== "" ? " " . e($class) : "") . '" aria-hidden="true">'
        . '<use href="#i-' . e($name) . '"></use></svg>';
}

/**
 * A sort bar: the dashboard's files view and shares list, and the share
 * page. One button per key, in the order given, the first one active;
 * nextSort(), paintSortBar() and bindSortBar() in helper.js drive it.
 * Hidden: each page shows it once there are two items to order.
 *
 * @param list<string> $keys name, date, size, visits
 */
function sort_bar(string $id, array $keys): string
{
    // icon() spelled out, so bin/lint.sh sees which symbols are drawn.
    $known = [
        "name" => [icon("arrow-down-a-z"), "sort_name"],
        "date" => [icon("calendar"), "sort_date"],
        "size" => [icon("package"), "sort_size"],
        "visits" => [icon("footprints"), "sort_visits"],
    ];
    $html = '<div class="sort-bar" id="' . e($id) . '" hidden>'
        . '<span class="sort-label">' . e(__("sort_by")) . ':</span>';
    foreach ($keys as $i => $key) {
        [$svg, $label] = $known[$key];
        $html .= '<button type="button" class="sort-btn' . ($i === 0 ? " active" : "") . '" data-sort="' . e($key) . '">'
            . $svg . " " . e(__($label))
            . '<span class="sort-dir" aria-hidden="true"></span></button>';
    }
    return $html . "</div>";
}

/**
 * The strength meter under a new-password field (installer and recovery
 * page): a bar and a word, both painted by paintPwStrength() in helper.js.
 */
function pw_meter(): string
{
    return '<div class="pw-meter"><div class="pw-meter-fill" id="pw-fill"></div></div>'
        . '<div class="pw-meter-label" id="pw-label" aria-live="polite"></div>';
}

/** The brand mark (the favicon) shown before the app name in page headers. */
function logo_mark(): string
{
    return '<img class="logo-mark" src="' . e(asset("assets/meta/favicon-96x96.png")) . '" alt="">';
}

/**
 * The mark and the app name, a link to this installation's home: the
 * header of the share and privacy pages (aesbin-header-logo), the card of
 * the login, recovery, 404 and share steps (card-logo).
 */
function logo_link(string $class): string
{
    return '<a class="' . e($class) . '" href="' . e(base_url("")) . '">' . logo_mark() . " " . e(__("app_name")) . "</a>";
}

/**
 * The icon sprite, inlined once per page right after <body> so that icon()
 * and its JavaScript twin resolve without a request. Inline rather than an
 * external <use href="icons.svg#…">, which would depend on how each browser
 * applies the CSP to SVG resources. The licence comment is dropped: it lives
 * in the file itself.
 */
function icon_sprite(): string
{
    $svg = @file_get_contents(dirname(__DIR__, 2) . "/public/assets/img/icons.svg");
    return $svg === false ? "" : trim((string) preg_replace("/<!--.*?-->/s", "", $svg));
}

/**
 * A language <select> whose options carry their flag in data-flag, for
 * lang-select.js to build a dropdown with flags (option text cannot show an
 * image, and flag emoji are letters on Windows). Without JavaScript the
 * select remains, with the current flag beside it.
 *
 * @param array<string, string> $attrs  The <select>'s attributes, escaped here.
 * @param string $assetBase  URL prefix for the flags, ending in "/":
 *                           base_url("") in the app, the install URL in setup.
 * @param bool $block  Full width (settings, installer) rather than inline.
 */
function lang_select(string $current, array $attrs, string $assetBase, bool $block = false): string
{
    $options = "";
    $currentFlag = null;
    foreach (Lang::available() as $code => $info) {
        $flag = $info["flag"] !== null
            ? $assetBase . $info["flag"] . asset_version($info["flag"])
            : "";
        if ($code === $current) {
            $currentFlag = $flag;
        }
        $options .= sprintf(
            "<option value=\"%s\" data-flag=\"%s\"%s>%s</option>\n",
            e((string) $code),
            e($flag),
            $code === $current ? " selected" : "",
            e($info["name"])
        );
    }
    $attrHtml = "";
    foreach ($attrs as $name => $value) {
        $attrHtml .= " " . e($name) . "=\"" . e($value) . "\"";
    }
    // No src at all without a flag: src="" is an invalid URL.
    return sprintf(
        "<span class=\"lang-flag-sel%s\"><img class=\"lang-flag\"%s alt=\"\"><select%s>\n%s</select></span>",
        $block ? " lang-flag-sel-block" : "",
        $currentFlag ? " src=\"" . e($currentFlag) . "\"" : " hidden",
        $attrHtml,
        $options
    );
}

/**
 * Per-request registry of obfuscated mailto entries. obfuscate_mailto() adds
 * to it; document-end.php reads it (no argument) to emit
 * window.AESBIN_OBFMAIL and add mailto-inject.js, so a view needs nothing
 * else.
 */
function obfmail_registry(?array $add = null): array
{
    static $registry = [];
    if ($add !== null) {
        $registry[] = $add;
    }
    return $registry;
}

/**
 * An email address as it goes after "mailto:" (RFC 6068): the local part with
 * every character outside unreserved and the few delimiters RFC 6068 lets
 * through percent-encoded. FILTER_VALIDATE_EMAIL accepts "a?b#c@example.org",
 * which written as it is would make "?" start the query and "#" the
 * fragment. The domain is left alone: a valid one holds none of them.
 */
function mailto_address(string $email): string
{
    $at = strrpos($email, "@");
    if ($at === false) {
        return $email;
    }
    $local = (string) preg_replace_callback(
        "/[^A-Za-z0-9\\-._~!$'()*+,;:]/",
        fn (array $m): string => strtoupper(rawurlencode($m[0])),
        substr($email, 0, $at)
    );
    return $local . substr($email, $at);
}

/**
 * An empty <span id="..."> that mailto-inject.js turns into a mailto link
 * (data in obfmail_registry()). Without JS nothing renders; pages that must
 * work without it add their own <noscript> text, never the address.
 *
 * @param string $email     Address to obfuscate (already validated by the caller).
 * @param string $label     Visible text. Defaults to the address itself.
 * @param string $subject   Optional mailto "subject=" prefill.
 * @param string $class     Optional CSS class(es) for the span itself.
 */
function obfuscate_mailto(string $email, string $label = "", string $subject = "", string $class = ""): string
{
    // Reversed hex: no "@" or "." for a regex to find, and not obviously hex.
    $scramble = fn (string $str): string => strrev(bin2hex($str));
    // The label is scrambled too: it defaults to the address, and
    // privacy.php passes the address as the label.
    $label = $label !== "" ? $label : $email;
    static $n = 0;
    $id = "obfmail-" . (++$n);
    obfmail_registry([
        "id" => $id,
        // The link target, encoded for a mailto: URI; the label shows the
        // address as typed.
        "m" => $scramble(mailto_address($email)),
        "l" => $scramble($label),
        "s" => $scramble($subject),
    ]);
    return sprintf(
        '<span id="%s"%s></span>',
        e($id),
        $class !== "" ? ' class="' . e($class) . '"' : ""
    );
}

/**
 * Whether the /privacy page is published: the flag is on and the data
 * controller's name and email are filled in, so a fresh install never shows
 * an empty page. The settings toggle reads the raw flag; everything else
 * asks this.
 */
function privacy_page_enabled(array $config): bool
{
    if (empty($config["privacy_enabled"])) {
        return false;
    }
    return trim((string) ($config["privacy_name"] ?? "")) !== "" &&
        trim((string) ($config["privacy_email"] ?? "")) !== "";
}

/**
 * Target of the "Contact me" footer link, or "" to hide it: an email address
 * (obfuscated mailto:) or an https:// URL, such as a contact form on the main
 * site. Independent of the privacy email (the dashboard's copy button is a
 * one-time prefill).
 */
function effective_contact_link(array $config): string
{
    if (empty($config["contact_enabled"] ?? false)) {
        return "";
    }
    return trim((string) ($config["contact_link"] ?? ""));
}

/**
 * Whether this install answers /.well-known/security.txt: only at the root of
 * its domain. RFC 9116 reads the file from the domain's root and applies it
 * to the whole domain, which an install in a subdirectory does not own (the
 * main site does), so there it is not generated: a file at /<base>/.well-known/
 * would never be read, and a redirect from the root is the main site's call.
 * The same choice as WordPress's security.txt plugins, which refuse a site URL
 * with a path.
 */
function security_txt_served(): bool
{
    return AESBIN_BASE === "";
}

/**
 * Body of the RFC 9116 security.txt, built per request so it never expires and
 * needs no file (a host without a .well-known directory serves it too).
 * Contact lines, most preferred first:
 *   1. the admin's contact link, when enabled and valid (an email becomes
 *      mailto:, a URL has {lang} filled with "en");
 *   2. else the privacy page's email, when that page is published and the
 *      address is valid (mailto:);
 *   3. always last, the project's private GitHub advisory form, so a flaw in
 *      the code has a way upstream and the file is never empty.
 * Each Contact has a "#" comment above it (RFC 9116 section 2.1) saying what
 * it is for: the site's own contact for the site, the advisory form for the
 * code, which cannot act on one site. Without a site contact, the comment says
 * so, so a site problem is not sent upstream for lack of anywhere else.
 * Policy is the project's SECURITY.md, which says the same at length.
 * Preferred-Languages is English, plus the install's interface language
 * (config "locale") when that is another one.
 * No Canonical: the only source for it is the request's Host header, which
 * the client chooses, and on an unsigned file it proves nothing (anyone who
 * copies the file can edit it too). The field is optional.
 * Expires is rolling on purpose: the field warns of contact data left stale,
 * and these lines are read from the current config on every request.
 */
function security_txt_body(array $config, int $now): string
{
    $site = "";
    $link = effective_contact_link($config);
    if ($link !== "" && contact_link_valid($link)) {
        $link = contact_link_for($link, "en");
        $site = contact_link_is_url($link) ? $link : "mailto:" . mailto_address($link);
    } elseif (privacy_page_enabled($config)) {
        $email = trim((string) ($config["privacy_email"] ?? ""));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $site = "mailto:" . mailto_address($email);
        }
    }
    $upstream = "https://github.com/" . AESBIN_GITHUB_REPO . "/security/advisories/new";

    $langs = ["en"];
    $locale = strtolower(trim((string) ($config["locale"] ?? "")));
    // A shipped interface language only (a bare code is already a valid
    // language tag); Lang::available() would load every language file.
    if (
        $locale !== "en" &&
        preg_match('/^[a-z]{2,3}\z/', $locale) === 1 &&
        is_file(AESBIN_APP_DIR . "/lang/" . $locale . ".php")
    ) {
        $langs[] = $locale;
    }

    $out = "";
    if ($site !== "") {
        $out .= "# This site: its configuration, its content, the links it shares.\n"
            . "Contact: " . $site . "\n"
            . "# The software it runs (AESBin): flaws in the code itself, which\n"
            . "# affect every installation. Not for problems with this site alone.\n";
    } else {
        $out .= "# This site lists no security contact of its own. The form below is\n"
            . "# for flaws in the code of the software it runs (AESBin), which\n"
            . "# affect every installation; it cannot act on this site.\n";
    }
    return $out
        . "Contact: " . $upstream . "\n"
        . "Expires: " . gmdate("Y-m-d\\TH:i:s.000\\Z", $now + 15552000) . "\n"
        . "Policy: https://github.com/" . AESBIN_GITHUB_REPO . "/blob/main/SECURITY.md\n"
        . "Preferred-Languages: " . implode(", ", $langs) . "\n";
}

/** True when a contact link is an https:// URL rather than an email address. */
function contact_link_is_url(string $link): bool
{
    return str_starts_with(strtolower($link), "https://");
}

/**
 * Whether a contact link is usable: a valid email address, or an https:// URL
 * with a host. Plain http:// is refused, like every other link the app prints.
 * A URL may hold {lang} (see contact_link_for()), checked as a language code.
 */
function contact_link_valid(string $link): bool
{
    if (!contact_link_is_url($link)) {
        return filter_var($link, FILTER_VALIDATE_EMAIL) !== false;
    }
    // {lang} in the path or query only: a host that changes with the
    // language would point each visitor at a different site.
    $sample = contact_link_for($link, "en");
    $host = (string) parse_url($sample, PHP_URL_HOST);
    return filter_var($sample, FILTER_VALIDATE_URL) !== false
        && $host !== ""
        && $host === (string) parse_url(contact_link_for($link, "it"), PHP_URL_HOST)
        && strlen($link) <= 500;
}

/**
 * The contact link as a page in $lang shows it: in a URL, {lang} becomes
 * that language code ("it", "en"...), so one link can open a contact form
 * in the visitor's language. An email address is returned as it is.
 */
function contact_link_for(string $link, string $lang): string
{
    return contact_link_is_url($link) ? str_replace("{lang}", rawurlencode($lang), $link) : $link;
}

/**
 * Whether a URL leads outside this installation: another host, or a path
 * outside its base (another application on the same domain). Such a link
 * opens in a new tab, like every other link to another site; one inside
 * stays in the tab, and inside the installed app.
 */
function link_is_external(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $path = (string) parse_url($url, PHP_URL_PATH);
    return $host !== request_host() || !str_starts_with($path . "/", base_url(""));
}

// ── WebAuthn Relying Party ID ─────────────────────────────────────────────────

/**
 * The Relying Party ID for every WebAuthn ceremony: the value pinned in
 * config.php, or the request's host when that key is absent or empty.
 *
 * The fallback strips the port, since an RP ID is a bare domain. Using the
 * Host header is not exploitable (a credential registered for one host cannot
 * be asserted for another), but the installer pins it anyway.
 */
function webauthn_rp_id(array $config): string
{
    $pinned = (string) ($config["webauthn_rp_id"] ?? "");
    return $pinned !== "" ? $pinned : request_host();
}

/**
 * Passkeys one installation keeps. Checked when a registration starts
 * (passkey_challenge.php), before the authenticator creates anything, and
 * again when it ends (passkey_register.php). The number is also written in
 * the err_passkey_limit message of every language file.
 */
const PASSKEY_MAX = 10;

/** Standard base64 → base64url without padding, the encoding WebAuthn uses. */
function b64url_encode(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), "+/", "-_"), "=");
}

/**
 * Decode base64url or standard base64, padded or not: what a browser sends and
 * what is stored are not the same flavour. False on malformed input.
 */
function b64_decode_any(string $s): string|false
{
    $s = strtr($s, "-_", "+/");
    return base64_decode($s . str_repeat("=", (4 - strlen($s) % 4) % 4), true);
}

/** The WebAuthn server object every passkey ceremony uses. */
function webauthn_server(array $config): \ReportUri\Passkeys\WebAuthn
{
    require_once AESBIN_APP_DIR . "/vendor/passkeys-php/src/WebAuthn.php";
    // true: ByteBuffer serialises as base64url, the encoding browsers expect.
    return new \ReportUri\Passkeys\WebAuthn("AESBin", webauthn_rp_id($config), true);
}

/**
 * {P}auth.webauthn_data decoded, always with a "credentials" list. Callers
 * that write it back must hold webauthn_lock() from before this read.
 */
function webauthn_data(PDO $db): array
{
    $raw = $db->query("SELECT webauthn_data FROM " . DB_PREFIX . "auth WHERE id = 1")->fetchColumn();
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $data = is_array($data) ? $data : [];
    $data["credentials"] = is_array($data["credentials"] ?? null) ? array_values($data["credentials"]) : [];
    return $data;
}

/**
 * Write {P}auth.webauthn_data back. $credentialChange false keeps updated_at
 * (the settings card's "last changed"), for sign-counter updates and the
 * user id bootstrap.
 */
function webauthn_data_save(PDO $db, array $data, bool $credentialChange = true): void
{
    $db->prepare(
        "UPDATE " . DB_PREFIX . "auth SET webauthn_data = :d" .
            ($credentialChange ? "" : ", updated_at = updated_at") . " WHERE id = 1"
    )->execute([":d" => json_encode($data, JSON_THROW_ON_ERROR)]);
}

// ── Advisory locks ────────────────────────────────────────────────────────────

/**
 * Advisory lock name scoped to this installation. GET_LOCK() names are
 * server-wide: a fixed one would serialise every AESBin on a shared host and
 * let another customer hold it. The hash keeps it unique, unguessable and
 * under 64 characters. Every GET_LOCK/RELEASE_LOCK goes through here.
 */
function db_lock_name(PDO $db, string $key): string
{
    static $scope = null;
    if ($scope === null) {
        $scope = (string) $db->query("SELECT DATABASE()")->fetchColumn() . "\0" . DB_PREFIX;
    }
    return "aesbin:" . substr(hash("sha256", $scope . "\0" . $key), 0, 48);
}

/**
 * Acquire a MySQL advisory lock (GET_LOCK) with a 5 s timeout. MySQL releases
 * it when the connection closes; callers may release it earlier with
 * db_advisory_unlock().
 *
 * @throws RuntimeException if the lock cannot be taken within the timeout.
 */
function db_advisory_lock(PDO $db, string $key, string $failureMessage): void
{
    $stmt = $db->prepare("SELECT GET_LOCK(:k, 5)");
    $stmt->execute([":k" => db_lock_name($db, $key)]);
    if (!(bool) $stmt->fetchColumn()) {
        throw new RuntimeException($failureMessage);
    }
}

/** Release a lock taken with db_advisory_lock() before the connection ends. */
function db_advisory_unlock(PDO $db, string $key): void
{
    $db->prepare("SELECT RELEASE_LOCK(:k)")->execute([":k" => db_lock_name($db, $key)]);
}

/**
 * Serialise every read-modify-write of {P}auth.webauthn_data, a JSON document
 * rewritten whole, so overlapping requests cannot discard each other's
 * changes. Held until the connection closes; nothing releases it.
 *
 * @throws RuntimeException if the lock cannot be taken; callers must let it
 *         surface, since proceeding unserialised is what this prevents.
 */
function webauthn_lock(PDO $db): void
{
    db_advisory_lock(
        $db,
        "aesbin_webauthn_data",
        "Could not acquire the passkey lock. Please retry."
    );
}

// ── Transactions and schema ───────────────────────────────────────────────────

/**
 * Roll back the open transaction, if any. rollBack() with none open (DDL
 * committed implicitly, or beginTransaction() failed) throws and would mask
 * the error being handled.
 */
function db_rollback_if_open(PDO $db): void
{
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

/**
 * The named CHECK constraints app/setup/schema-sql.php creates, each with the
 * statement that re-adds it. integrity_check.php reports a missing one and
 * integrity_fix.php runs its statement; add an entry here when a migration
 * introduces another.
 *
 * @return array<string, string> constraint name => ALTER TABLE statement
 */
function schema_check_constraints(): array
{
    return [
        DB_PREFIX . "chk_single_user" =>
            "ALTER TABLE " . DB_PREFIX . "auth ADD CONSTRAINT " . DB_PREFIX . "chk_single_user CHECK (id = 1)",
    ];
}

// ── Named rows and name uniqueness (encrypted columns) ────────────────────────

/**
 * Insert a container or directory row and return its id. The name's AAD
 * holds the id, known only after the INSERT, so the row goes in with a
 * placeholder and is re-encrypted in the same transaction.
 *
 * @param "container"|"directory" $type
 * @param array<string, int>      $extra Additional plain columns, e.g. ["container_id" => 3]
 */
function insert_named_row(
    PDO $db,
    CryptoService $crypto,
    string $masterKey,
    string $type,
    string $name,
    array $extra = []
): int {
    $table = DB_PREFIX . ($type === "container" ? "containers" : "directories");
    $ownTxn = !$db->inTransaction();
    if ($ownTxn) {
        $db->beginTransaction();
    }
    try {
        $placeholder = $crypto->encryptMeta("__pending__", $masterKey, CryptoService::rowAad($type, 0));
        $cols = array_keys($extra);
        $db->prepare(
            "INSERT INTO {$table} (" . implode(", ", [...$cols, "name_enc", "name_iv", "name_tag"]) . ")" .
                " VALUES (" . implode(", ", [...array_map(fn($c) => ":{$c}", $cols), ":e", ":i", ":t"]) . ")"
        )->execute(
            array_combine(array_map(fn($c) => ":{$c}", $cols), array_values($extra)) +
                [":e" => $placeholder["enc"], ":i" => $placeholder["iv"], ":t" => $placeholder["tag"]]
        );
        $id = (int) $db->lastInsertId();

        $enc = $crypto->encryptMeta($name, $masterKey, CryptoService::rowAad($type, $id));
        $db->prepare(
            "UPDATE {$table} SET name_enc = :e, name_iv = :i, name_tag = :t WHERE id = :id"
        )->execute([":e" => $enc["enc"], ":i" => $enc["iv"], ":t" => $enc["tag"], ":id" => $id]);

        if ($ownTxn) {
            $db->commit();
        }
        return $id;
    } catch (Throwable $e) {
        if ($ownTxn) {
            db_rollback_if_open($db);
        }
        throw $e;
    }
}

/**
 * Validate a container/directory name: non-empty, within
 * DISPLAY_NAME_MAX_CHARS and DISPLAY_NAME_MAX_BYTES, and none of
 * NAME_FORBIDDEN_CHARS (a folder name shows on the share page too). Overflow
 * must be prevented, not caught: a truncated AEAD ciphertext never
 * authenticates. Filenames use sanitize_filename() instead, which strips.
 */
function is_valid_display_name(string $name): bool
{
    return $name !== "" &&
        mb_strlen($name) <= DISPLAY_NAME_MAX_CHARS &&
        strlen($name) <= DISPLAY_NAME_MAX_BYTES &&
        preg_match(NAME_FORBIDDEN_CHARS, $name) === 0;
}

/**
 * Take the advisory lock that serialises name checks and name writes in one
 * scope (a table, narrowed by $where) and return its key, for RELEASE_LOCK.
 * Scoped so writes to different containers or folders do not block each
 * other. Every name uniqueness check goes through here, as does restore(),
 * which may recreate a folder by name.
 *
 * @param array<string, int|string> $where
 */
function name_scope_lock(PDO $db, string $table, array $where = []): string
{
    $scopeParts = [$table];
    foreach ($where as $col => $val) {
        $scopeParts[] = "{$col}:{$val}";
    }
    $lockKey = "aesbin_name_unique_" . implode("_", $scopeParts);
    db_advisory_lock($db, $lockKey, "Could not acquire name uniqueness lock. Please retry.");
    return $lockKey;
}

/**
 * Check a container or directory name is unique within $where (encrypted
 * names cannot have a UNIQUE index), case-insensitively, under an advisory
 * lock. The lock stays held to cover the caller's write and is freed with
 * the connection; callers must not release it.
 *
 * @param "container"|"directory" $type
 * @param array<string, int>      $where Scope columns: container_id only
 */
function is_name_unique(
    PDO $db,
    CryptoService $crypto,
    string $masterKey,
    string $type,
    string $name,
    array $where = [],
    ?int $excludeId = null
): bool {
    // Table and column names are concatenated into the SQL, so both come
    // from fixed lists.
    $table = match ($type) {
        "container" => "containers",
        "directory" => "directories",
    };
    foreach (array_keys($where) as $col) {
        if ($col !== "container_id") {
            throw new InvalidArgumentException("is_name_unique(): unsupported scope column.");
        }
    }

    $lockKey = name_scope_lock($db, $table, $where);

    try {
        $clauses = [];
        $params = [];
        foreach ($where as $col => $val) {
            $clauses[] = "`{$col}` = :{$col}";
            $params[":{$col}"] = $val;
        }
        if ($excludeId !== null) {
            $clauses[] = "id <> :excl_id";
            $params[":excl_id"] = $excludeId;
        }
        $stmt = $db->prepare(
            "SELECT id, name_enc, name_iv, name_tag FROM " . DB_PREFIX . $table .
                ($clauses ? " WHERE " . implode(" AND ", $clauses) : "")
        );
        $stmt->execute($params);

        $nameLower = mb_strtolower($name);
        foreach ($stmt->fetchAll() as $row) {
            // An unreadable row decrypts to "" and collides with nothing.
            $existing = $crypto->decryptColumn($row, "name", $masterKey, CryptoService::rowAad($type, $row["id"]));
            if ($existing !== "" && mb_strtolower($existing) === $nameLower) {
                return false;
            }
        }
        return true;
    } catch (Throwable $e) {
        // No answer, so the caller will not write: release.
        db_advisory_unlock($db, $lockKey);
        throw $e;
    }
}

/**
 * Check a filename is unique in a directory (or container root), ignoring
 * trashed files. For callers that reject a collision with 409; to pick a free
 * name instead, see FileService::uniqueFilename(). Lock contract as for
 * is_name_unique().
 */
function is_filename_unique(
    PDO $db,
    CryptoService $crypto,
    string $masterKey,
    string $name,
    int $containerId,
    ?int $directoryId,
    ?int $excludeId = null
): bool {
    return first_available_filename(
        $db,
        $crypto,
        $masterKey,
        [$name],
        $containerId,
        $directoryId,
        $excludeId
    ) !== null;
}

/**
 * First name in $candidates free in the given scope, or null if all are
 * taken. Lock contract as for is_name_unique(). Takes a list so
 * FileService::uniqueFilename()'s up to 100 candidates cost one lock and one
 * decrypt pass.
 */
function first_available_filename(
    PDO $db,
    CryptoService $crypto,
    string $masterKey,
    array $candidates,
    int $containerId,
    ?int $directoryId,
    ?int $excludeId = null
): ?string {
    if ($candidates === []) {
        return null;
    }

    $lockKey = name_scope_lock($db, "files", [
        "container_id" => $containerId,
        "directory_id" => $directoryId ?? "root",
    ]);

    try {
        $sql =
            "SELECT id, name_enc, name_iv, name_tag, storage_name FROM " .
            DB_PREFIX .
            'files
               WHERE container_id = :cid AND trashed_at IS NULL';
        $params = [":cid" => $containerId];

        if ($directoryId !== null) {
            $sql .= " AND directory_id = :did";
            $params[":did"] = $directoryId;
        } else {
            $sql .= " AND directory_id IS NULL";
        }
        if ($excludeId !== null) {
            $sql .= " AND id <> :excl";
            $params[":excl"] = $excludeId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $taken = [];
        foreach ($rows as $row) {
            // An unreadable row decrypts to "" and collides with nothing.
            $existing = $crypto->decryptColumn($row, "name", $masterKey, CryptoService::fileMetaAad("name", $row["storage_name"]));
            if ($existing !== "") {
                $taken[mb_strtolower($existing)] = true;
            }
        }

        foreach ($candidates as $candidate) {
            if (!isset($taken[mb_strtolower((string) $candidate)])) {
                return (string) $candidate;
            }
        }
        return null;
    } catch (Throwable $e) {
        // See is_name_unique().
        db_advisory_unlock($db, $lockKey);
        throw $e;
    }
}

/**
 * Global shortcut for Lang::get().
 *
 * Not HTML-escaped: views wrap it in e(). The exceptions are the strings
 * with intentional markup ('backup_info', 'share_split_note',
 * 'altcha_footer'), which are output raw and must never interpolate a
 * caller-supplied value.
 */
function __(string $key, array $vars = []): string
{
    return Lang::get($key, $vars);
}
