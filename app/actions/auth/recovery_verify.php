<?php

/**
 * app/actions/auth/recovery_verify.php
 *
 * POST /api/auth/recovery/verify
 * Auth    : none (public endpoint)
 * Body    : {phrase, captcha_token?}
 * Returns : {status: "phrase_ok"}
 *
 * Verify the 10-word recovery phrase as a UX pre-check only. No token or
 * session flag is issued: Auth::executeRecovery() re-verifies the phrase from
 * scratch, so this endpoint is not part of the trust chain.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

// Before the rate limit and body checks; see recovery_require_gate().
recovery_require_gate();

$body = json_body();

// Not require_fields(): a missing phrase gets its own message below.
if (isset($body["phrase"])) {
    normalize_body_field($body, "phrase");
}

// Before the honeypot (see SecurityService::enforceHoneypot()).
$security->enforceRateLimit("recovery.failure");

// Honeypot, counted as a wrong phrase.
$security->enforceHoneypot($body, "recovery.failure", ["error" => __("recovery_error")]);

// Captcha, if operational.
$security->enforceCaptcha($body, "recovery.captcha_fail");

if (empty($body["phrase"])) {
    json_response(["error" => __("err_phrase_required")], 400);
}

// Argon2id below; see SecurityService::serializeAttempts().
$security->serializeAttempts("recovery.failure");

if (!$auth->verifyRecoveryPhrase(trim($body["phrase"]))) {
    log_activity($db, "recovery.failure");
    usleep(random_int(100000, 300000));
    json_response(["error" => __("recovery_error")], 403);
}

json_response(["status" => "phrase_ok"]);
