<?php

/**
 * app/includes/router.php — AESBin request dispatcher
 *
 * Matches method + URI and requires the corresponding action or view file.
 * All API routes return JSON. View routes return HTML pages.
 *
 * Route groups (the full API list is API_ROUTES / API_PATTERN_ROUTES below):
 *   /api/auth/*          — login, elevation, passkeys, account recovery
 *   /api/files/*         — file CRUD, upload, download
 *   /api/containers/*    — container management
 *   /api/directories/*   — directory management
 *   /api/shares/*        — owner share management
 *   /api/share/*         — visitor share endpoints (no user session)
 *   /api/activity, /api/trash, /api/system/* — log, trash, settings, maintenance
 *   /s/{token}           — public share viewer page
 *   /privacy             — public privacy page, when enabled
 *   /.well-known/security.txt — generated RFC 9116 file, at the domain root
 *                          only; /security.txt redirects to it
 *   /login               — login page
 *   /recovery            — recovery page
 *   /                    — dashboard (requires auth, redirects to login otherwise)
 */

declare(strict_types=1);

use AESBin\Lang;
use AESBin\SessionKeyException;

defined("AESBIN_APP") || exit;

$method = $_SERVER["REQUEST_METHOD"];

// Canonical request path (see aesbin_canonical_path() in helpers.php). A
// non-canonical GET or HEAD is redirected to the clean form; other methods
// carry on with it, since a redirected POST would lose its body. The target
// always starts with a single "/", so it can never become "//host".
[$_rawPath, $_rawQuery] = array_pad(
    explode("?", (string) ($_SERVER["REQUEST_URI"] ?? "/"), 2),
    2,
    ""
);
$_cleanPath = aesbin_canonical_path($_rawPath);
if ($_cleanPath !== $_rawPath && in_array($method, ["GET", "HEAD"], true)) {
    header("Location: " . $_cleanPath . ($_rawQuery !== "" ? "?" . $_rawQuery : ""), true, 301);
    exit();
}
$uri = aesbin_route_path($_cleanPath);
// /index.php asked for by hand (with or without a trailing slash, which
// aesbin_route_path() drops): the entry point, not a page, so not an address
// of its own. A GET or HEAD goes home like any other non-canonical path. Any
// other method (a scanner's POST, usually) gets 405 and the methods allowed:
// the file exists, so a 404 would claim it does not, and with no route
// behind it there is nothing to log. The 403 page runs index.php too, but
// bootstrap.php has answered it by now (AESBIN_REFUSED), and its REQUEST_URI
// is the refused address anyway.
if ($uri === "/index.php") {
    if (in_array($method, ["GET", "HEAD"], true)) {
        header("Location: " . base_url("") . ($_rawQuery !== "" ? "?" . $_rawQuery : ""), true, 301);
    } else {
        header("Allow: GET, HEAD");
        http_response_code(405);
    }
    exit();
}
unset($_rawPath, $_rawQuery, $_cleanPath);

// Fetch Metadata headers, set by the browser and not forgeable by page JS:
//   - Sec-Fetch-Mode: navigate or Sec-Fetch-Dest: document is a top-level page
//     load (a typed or bookmarked /api/ URL), so redirect to the dashboard
//     rather than show raw JSON. Either header suffices, since browsers
//     differ on edge cases such as redirects and prefetch.
//   - A Sec-Fetch-Site other than same-origin (<script src>, cross-site
//     fetch(), <img> probe) gets a 403.
// Not a control against programmatic callers: curl, and browsers without
// Fetch Metadata, send neither header and reach the route normally.
$fetchSite = $_SERVER["HTTP_SEC_FETCH_SITE"] ?? null;
$fetchMode = $_SERVER["HTTP_SEC_FETCH_MODE"] ?? null;
$fetchDest = $_SERVER["HTTP_SEC_FETCH_DEST"] ?? null;
if (str_starts_with($uri, "/api/")) {
    if ($fetchMode === "navigate" || $fetchDest === "document") {
        header("Location: " . AESBIN_BASE . "/");
        exit();
    }
    if ($fetchSite !== null && $fetchSite !== "same-origin") {
        json_response(["error" => __("err_same_origin_only")], 403);
    }
}

// API routes with a fixed path: "METHOD path" => action file.
const API_ROUTES = [
    // Auth
    "POST /api/auth/login" => "auth/login",
    "POST /api/auth/finalize" => "auth/finalize",
    "POST /api/auth/logout" => "auth/logout",
    "GET /api/auth/status" => "auth/status",
    "GET /api/auth/session" => "auth/session",
    "GET /api/auth/ratelimit/status" => "auth/ratelimit_status",
    "POST /api/auth/elevate/drop" => "auth/elevate_drop",
    "POST /api/auth/elevate" => "auth/elevate",
    "GET /api/auth/totp/secret" => "auth/totp_secret",
    "GET /api/auth/captcha/challenge" => "auth/captcha_challenge",
    "POST /api/auth/recovery/guard" => "auth/recovery_guard",
    "POST /api/auth/recovery/guard/verify" => "auth/recovery_guard_verify",
    "POST /api/auth/recovery/phrase/new" => "auth/recovery_phrase_new",
    "POST /api/auth/recovery/totp/new" => "auth/recovery_totp_new",
    "POST /api/auth/recovery/totp/verify" => "auth/recovery_totp_verify",
    "POST /api/auth/recovery/verify" => "auth/recovery_verify",
    "POST /api/auth/recovery/execute" => "auth/recovery_execute",
    // Passkeys (WebAuthn)
    "GET /api/auth/passkey/challenge" => "auth/passkey_challenge",
    "GET /api/auth/passkey/register/key" => "auth/passkey_register_key",
    "POST /api/auth/passkey/register" => "auth/passkey_register",
    "POST /api/auth/passkey/verify" => "auth/passkey_verify",
    "POST /api/auth/passkey/authenticate" => "auth/passkey_authenticate",
    "POST /api/auth/passkey/remove" => "auth/passkey_remove",
    // Files
    "GET /api/files/search" => "file/search",
    "GET /api/files" => "file/list",
    "POST /api/files/upload" => "file/upload",
    // Containers
    "GET /api/containers" => "container/list",
    "POST /api/containers" => "container/create",
    // Directories
    "GET /api/directories" => "directory/list",
    "POST /api/directories" => "directory/create",
    // Shares (owner)
    "GET /api/shares" => "share/list",
    "POST /api/shares" => "share/create",
    // Share visitor (no owner session)
    "POST /api/share/auth" => "share/auth",
    // System
    "GET /api/activity" => "system/activity",
    "GET /api/trash" => "system/trash",
    "GET /api/system/settings" => "system/settings",
    "POST /api/system/settings" => "system/settings",
    "POST /api/system/activity/purge" => "system/activity_purge",
    "GET /api/system/captcha/test" => "system/captcha_test",
    "GET /api/system/update/check" => "system/update_check",
    "GET /api/system/status" => "system/status",
    "GET /api/system/sessions" => "system/sessions_list",
    "POST /api/system/sessions/revoke" => "system/sessions_revoke",
    "GET /api/system/integrity" => "system/integrity_check",
    "POST /api/system/integrity/fix" => "system/integrity_fix",
    "POST /api/system/storage-meta" => "system/storage_meta",
    "POST /api/system/fix-403" => "system/fix_403",
    "GET /api/system/migrate" => "system/migrate",
    "POST /api/system/migrate" => "system/migrate",
];

// API routes with parameters: [method, pattern, action file, [variable => type]].
// Captures are assigned in order to the named variables, which the action
// file reads from this scope.
const API_PATTERN_ROUTES = [
    // Files
    ["GET", '#^/api/files/(\d+)/download\z#', "file/download", ["fileId" => "int"]],
    ["POST", '#^/api/files/(\d+)/rename\z#', "file/rename", ["fileId" => "int"]],
    ["GET", '#^/api/files/(\d+)/metadata\z#', "file/metadata", ["fileId" => "int"]],
    ["POST", '#^/api/files/(\d+)/write\z#', "file/write", ["fileId" => "int"]],
    ["POST", '#^/api/files/(\d+)/move\z#', "file/move", ["fileId" => "int"]],
    ["POST", '#^/api/files/(\d+)/trash\z#', "file/trash", ["fileId" => "int"]],
    ["POST", '#^/api/files/(\d+)/restore\z#', "file/restore", ["fileId" => "int"]],
    ["DELETE", '#^/api/files/(\d+)\z#', "file/delete", ["fileId" => "int"]],
    // Containers
    ["POST", '#^/api/containers/(\d+)/rename\z#', "container/rename", ["cid" => "int"]],
    ["DELETE", '#^/api/containers/(\d+)\z#', "container/delete", ["cid" => "int"]],
    // Directories
    ["POST", '#^/api/directories/(\d+)/rename\z#', "directory/rename", ["did" => "int"]],
    ["POST", '#^/api/directories/(\d+)/move\z#', "directory/move", ["did" => "int"]],
    ["DELETE", '#^/api/directories/(\d+)\z#', "directory/delete", ["did" => "int"]],
    // Shares (owner)
    ["DELETE", '#^/api/shares/(\d+)\z#', "share/revoke", ["shareId" => "int"]],
    ["POST", '#^/api/shares/(\d+)/retarget\z#', "share/retarget", ["shareId" => "int"]],
    ["POST", '#^/api/shares/(\d+)/update\z#', "share/update", ["shareId" => "int"]],
    ["POST", '#^/api/shares/(\d+)/refresh\z#', "share/refresh", ["shareId" => "int"]],
    // Share visitor (no owner session)
    ["GET", '#^/api/share/([a-f0-9]{16})/files\z#', "share/view", ["shareToken" => "string"]],
    ["GET", '#^/api/share/([a-f0-9]{16})/download/([a-f0-9]{6})\z#', "share/download", ["shareToken" => "string", "fileToken" => "string"]],
];

// Visitor API calls have no session to carry the language from the /s/{token}
// page, so it is detected per call. Singular /api/share/ only; /api/shares is
// the owner's.
if (str_starts_with($uri, "/api/share/")) {
    Lang::load(detect_visitor_lang());
}

// A fresh tab shares the session cookie but starts with empty sessionStorage,
// so the first Auth::sessionMasterKey() in any action throws
// SessionKeyException. Uncaught, that is an empty 500 the frontend cannot
// tell from a network problem; as a 401 api.js shows the re-login prompt.
// Any other Throwable is logged and answered with JSON.
try {
    $_action = API_ROUTES[$method . " " . $uri] ?? null;
    if ($_action === null) {
        foreach (API_PATTERN_ROUTES as [$_verb, $_pattern, $_file, $_params]) {
            if ($_verb !== $method || preg_match($_pattern, $uri, $_m) !== 1) {
                continue;
            }
            $_i = 0;
            foreach ($_params as $_var => $_type) {
                $_value = $_m[++$_i];
                $$_var = $_type === "int" ? (int) $_value : $_value;
            }
            $_action = $_file;
            break;
        }
    }
    unset($_verb, $_pattern, $_file, $_params, $_m, $_i, $_var, $_type, $_value);
    if ($_action !== null) {
        require AESBIN_APP_DIR . "/actions/" . $_action . ".php";
        exit();
    }
} catch (SessionKeyException $e) {
    // The session is kept: the other tab may be in use. A distinct code lets
    // the frontend offer the choice, and logout happens only if the user picks
    // it. Not logged, since this is ordinary multi-tab use.
    json_response(
        ["error" => __("session_key_conflict"), "code" => "session_key_conflict"],
        401
    );
} catch (Throwable $e) {
    // Keep the response parseable JSON. The message goes to the log only.
    error_log("[AESBin] Unhandled " . get_class($e) . ": " . $e->getMessage());
    json_response(["error" => __("server_error")], 500);
}

// ── HTML pages ────────────────────────────────────────────────────────────────

// $privacyEnabled, $contactLink and $contactReferrer, for the footers of every
// page: bootstrap.php.

// Public share viewer: /s/{token} (16 hex chars)
if (preg_match('#^/s/([a-f0-9]{16})\z#', $uri, $_m)) {
    $shareToken = $_m[1];
    unset($_m);
    $honeypotName = $security->honeypotName();

    // Its Exit button (share.js): ends this link's visit.
    visitor_cookie_forget($uri, $db, $crypto, $shareToken);
    // No session here: the language the visitor picked, else the browser's.
    visitor_lang_choice($uri);
    $availableLangs = Lang::available();
    $shareLang = detect_visitor_lang();
    Lang::load($shareLang);

    require AESBIN_APP_DIR . "/views/share.php";
    exit();
}

// Public privacy & cookie policy: /privacy, no auth. The app/lang/*.php privacy_*
// template plus the data-controller identity from config (privacy_name,
// privacy_address, privacy_vat, privacy_email). With privacy_enabled off it
// falls through to the 404 below.
if ($uri === "/privacy" && $privacyEnabled) {
    visitor_cookie_forget($uri, $db, $crypto);
    visitor_lang_choice($uri);
    $availableLangs = Lang::available();
    $privacyLang = detect_visitor_lang();
    Lang::load($privacyLang);

    // The real retention for this install, read through the same helper as
    // the settings panel so the two cannot disagree.
    $privacyLogItemCap = activity_log_item_cap($config);
    $privacyName = trim((string) ($config["privacy_name"] ?? ""));
    $privacyAddress = trim((string) ($config["privacy_address"] ?? ""));
    $privacyVat = trim((string) ($config["privacy_vat"] ?? ""));
    $privacyEmail = trim((string) ($config["privacy_email"] ?? ""));
    require AESBIN_APP_DIR . "/views/privacy.php";
    exit();
}

// Login page
if ($uri === "/login") {
    if ($auth->isAuthenticated()) {
        redirect("");
    }
    require AESBIN_APP_DIR . "/views/login.php";
    exit();
}

// Recovery page
if ($uri === "/recovery") {
    // Redirect rather than the API's 403: whoever opens this in a browser is
    // almost always the owner who lost their password, and the login screen is
    // where they need to be. recovery_gate_open() rather than the constant, so
    // the recovery flow defines "open" in one place.
    if (!recovery_gate_open()) {
        redirect("login");
    }
    require AESBIN_APP_DIR . "/views/recovery.php";
    exit();
}

// security.txt (RFC 9116). A route, not a file, so it works where
// .well-known cannot be created and its Expires never lapses; see
// security_txt_body(). Before the 404 below, which would otherwise answer it.
// A real public/.well-known/security.txt (the admin's, signed say) is served
// by the web server and never reaches this route. The legacy /security.txt is
// a permanent redirect to it (RFC 9116 section 3 allows a redirect there),
// made by public/.htaccess before any file is looked up, so one at
// public/security.txt is never served; the redirect here is for servers that
// ignore .htaccess and lack the rule from docs/installation.md. Not in a
// subdirectory install (security_txt_served()): there both paths get the 404,
// unlogged for /.well-known/ like any other probe of it.
if (in_array($method, ["GET", "HEAD"], true) && security_txt_served()) {
    if ($uri === "/security.txt") {
        header("Location: " . base_url(".well-known/security.txt"), true, 301);
        exit();
    }
    if ($uri === "/.well-known/security.txt") {
        header("Content-Type: text/plain; charset=utf-8");
        header("Cache-Control: public, max-age=3600");
        if ($method === "GET") {
            echo security_txt_body($config, time());
        }
        exit();
    }
}

// Any non-API path other than "/" matched no route and gets a 404, so
// scanner probes do not get the dashboard's login redirect. Unmatched /api/*
// gets the JSON 404 at the end.
if (!str_starts_with($uri, "/api/") && $uri !== "/") {
    // Referer from $_SERVER, unlike share.js's referer/url (see
    // visitor_failure_context()): a 404 is a real page request.
    //
    // /.well-known/ (RFC 8615) is not logged: browsers probe it automatically
    // (e.g. Android's Digital Asset Links on PWA install) and AESBin serves
    // nothing there but security.txt (above, at the domain root only).
    // Nor is /security.txt, in any case: scanners ask for it everywhere, and
    // it reaches this 404 only in a subdirectory install, which publishes no
    // security.txt by design (security_txt_served()). Everything else stays
    // logged. The response is the same.
    //
    // A malformed share link (truncated, uppercased or with trailing
    // punctuation by a chat app) is a visitor's failed link, logged as one:
    // without address or browser, as the privacy page promises.
    // $uri has lost any trailing slash (aesbin_route_path()), so a bare
    // "/.well-known" is compared on its own.
    if (
        $uri !== "/.well-known" &&
        !str_starts_with($uri, "/.well-known/") &&
        strtolower($uri) !== "/security.txt"
    ) {
        log_activity(
            $db,
            str_starts_with($uri, "/s/") ? "share.token_probe" : "route.not_found",
            null,
            null,
            visitor_failure_context((string) ($_SERVER["HTTP_REFERER"] ?? ""), $uri)
        );
    }
    // A public page like share and privacy: the visitor's language (their
    // pick, else the browser's), not the owner's.
    Lang::load(detect_visitor_lang());
    http_response_code(404);
    $errorCode = 404;
    require AESBIN_APP_DIR . "/views/error.php";
    exit();
}

// Dashboard. Only "/" and unmatched /api/* paths are left at this point.
if ($uri === "/") {
    if (!$auth->pageSessionValid()) {
        redirect("login");
    }
    require AESBIN_APP_DIR . "/views/dashboard.php";
    exit();
}

// Fallback 404
json_response(["error" => __("err_not_found")], 404);
