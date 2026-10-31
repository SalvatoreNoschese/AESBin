<?php

/**
 * app/actions/auth/recovery_totp_verify.php
 *
 * POST /api/auth/recovery/totp/verify
 * Auth    : none (public endpoint, behind the recovery guard)
 * Body    : {secret, code}
 * Returns : {ok}
 *
 * Check a code from the authenticator against the candidate secret minted by
 * recovery_totp_new.php, and record in the session that it was verified.
 * recovery_execute.php then accepts that secret, and no other. Mirrors the
 * installer's verify_totp step.
 *
 * No rate limit of its own: the secret is the caller's, so guessing a code for
 * it gains nothing, and the gate and its guard file already bound who can be
 * here.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

recovery_require_gate();

$body = json_body();
foreach (["secret", "code"] as $field) {
    if (isset($body[$field])) {
        normalize_body_field($body, $field);
    }
}
unset($field);

$secret = (string) ($body["secret"] ?? "");
$code = trim((string) ($body["code"] ?? ""));
$expected = $_SESSION["recovery_new_totp_hash"] ?? null;

// Replaced by another tab's recovery_totp_new.php (the session is per
// browser), or never minted: "code" lets the page mint a fresh one by itself.
if ($secret === "" || !is_string($expected) || !hash_equals($expected, recovery_totp_hash($secret))) {
    json_response(["error" => __("err_recovery_totp_expired"), "code" => "totp_expired"], 400);
}

$ok = $crypto->verifyTotp($secret, $code) !== false;
$_SESSION["recovery_new_totp_verified"] = $ok ? $expected : null;

if (!$ok) {
    json_response(["error" => __("totp_error")], 400);
}
json_response(["ok" => true]);
