<?php

/**
 * app/actions/share/auth.php
 *
 * POST /api/share/auth
 * Auth    : none (public endpoint)
 * Body    : {token, key, password?, refresh?, page_referrer?, page_url?}
 * Returns : {expires_at, expires_in}; the session goes in its cookie
 *           (share_session_cookie())
 *
 * Authenticate a share link (optional password) and open a short-lived
 * visitor session. Its token goes in an HttpOnly cookie of its own for the
 * link (share_session_cookie()), never in the body: the page's script never
 * holds it, and a reload or another tab of the same browser keeps the visit,
 * as does the key typed in on the same browser (no password again).
 * `refresh: true` marks share.js's silent renewal (_renewSession()): it
 * still logs a share.visit (the flag is client-set, so it must not buy an
 * untraced read) but does not bump visit_count.
 *
 * page_referrer/page_url: document.referrer and location.pathname from the
 * original /s/:token page load. This request's own Referer/REQUEST_URI would
 * be useless for the log (see visitor_failure_context() in helpers.php).
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$body = json_body();

// Optional fields are cast below, and the honeypot branch logs two of them
// before require_fields() runs.
foreach (["page_referrer", "page_url", "refresh", "password"] as $optional) {
    if (isset($body[$optional])) {
        normalize_body_field($body, $optional);
    }
}

// 1. Coarse rate limit, before the honeypot (see
// SecurityService::enforceHoneypot()). The per-share
// lockout (share.failure#{id}) lives in ShareService::authenticate(); this
// covers tokens matching no share. Threshold 50, not login_max_attempts: the
// usual cause is a visitor reloading an expired link, and a 16-hex token is
// 64 bits, so a low limit would cost more than the enumeration it prevents.
$security->enforceRateLimit("share.token_probe", 50);

// 2. Honeypot, counted as an unknown token.
$security->enforceHoneypot(
    $body,
    "share.token_probe",
    ["error" => __("share_invalid"), "code" => "share_invalid"],
    visitor_failure_context(
        (string) ($body["page_referrer"] ?? ""),
        (string) ($body["page_url"] ?? "")
    )
);

require_fields($body, ["token", "key"]);
$session = $shareSvc->authenticate(
    $body["token"],
    $body["key"],
    (string) ($body["password"] ?? ""),
    (bool) ($body["refresh"] ?? false),
    $security,
    (string) ($body["page_referrer"] ?? ""),
    (string) ($body["page_url"] ?? "")
);
// The token authenticate() matched, held to 16 lowercase hex characters
// there (fetchByToken()), so safe in the name.
share_session_cookie(
    $body["token"],
    $session["session_token"],
    $session["expires_ts"]
);
json_response([
    "expires_at" => $session["expires_at"],
    "expires_in" => $session["expires_ts"] - time(),
]);
