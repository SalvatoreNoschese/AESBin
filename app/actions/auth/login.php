<?php

/**
 * app/actions/auth/login.php
 *
 * POST /api/auth/login
 * Auth    : none (public endpoint)
 * Body    : {password, captcha_token?, totp_code?, session_key?}
 * Returns : {status: "totp_required" | "ok" | "key_mismatch"}
 *
 * Password step. TOTP is mandatory, so a correct password without a code
 * returns totp_required rather than finishing the login.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$body = json_body();
// Presence is checked by hand here, not via require_fields(), so normalise
// explicitly (see normalize_body_field()).
if (isset($body["password"])) {
    normalize_body_field($body, "password");
}

// 1. Rate limit, before the honeypot (see SecurityService::enforceHoneypot()).
$security->enforceRateLimit("login.failure");

// 2. Honeypot, counted as a failed login.
$security->enforceHoneypot($body, "login.failure", ["error" => __("login_error")]);

// 3. Captcha, if operational.
$security->enforceCaptcha($body, "login.captcha_fail");

// Optional field, so require_fields() never normalises it.
if (isset($body["totp_code"])) {
    normalize_body_field($body, "totp_code");
}

// 4. Global TOTP limit, only when a code is present: the password step alone
// must not be blocked by it.
if (isset($body["totp_code"]) && $body["totp_code"] !== "") {
    $security->enforceRateLimit("login.totp");
}

// 5. Auth, one attempt at a time per client: the failure is counted only
// after the Argon2id check (see SecurityService::serializeAttempts()).
$security->serializeAttempts("login.failure");
$result = $auth->login(
    $body["password"] ?? "",
    isset($body["totp_code"]) && $body["totp_code"] !== ""
        ? $body["totp_code"]
        : null,
    $body
);

json_response($result);
