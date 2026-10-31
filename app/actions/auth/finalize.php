<?php

/**
 * app/actions/auth/finalize.php
 *
 * POST /api/auth/finalize
 * Auth    : totp_pending session state
 * Body    : {password, totp_code, session_key?}
 * Returns : {status: "ok" | "key_mismatch"}
 *
 * Second step of the TOTP login. The client re-sends the password (never kept
 * server-side) with the TOTP code, so MASTER_KEY can be unwrapped and the code
 * checked against the decrypted secret in one request. Neither is kept in
 * $_SESSION between the steps (see Auth::finalizeLogin()).
 *
 * A missing totp_code is not a 400: finalizeLogin() counts it as a wrong code,
 * so omitting it neither skips the check nor avoids the limiter.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireTotpPending();

$security->enforceRateLimit("login.failure");
// Every call here verifies a TOTP code, so the global bucket applies.
$security->enforceRateLimit("login.totp");

$body = json_body();
if (empty($body["password"])) {
    json_response(["error" => __("err_password_required")], 400);
}
normalize_body_field($body, "password");

// Optional field, so normalised by hand (see normalize_body_field()).
if (isset($body["totp_code"])) {
    normalize_body_field($body, "totp_code");
}

// The password is checked again here (Argon2id); see login.php.
$security->serializeAttempts("login.failure");

json_response($auth->finalizeLogin($body["password"], $body));
