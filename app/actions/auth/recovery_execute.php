<?php

/**
 * app/actions/auth/recovery_execute.php
 *
 * POST /api/auth/recovery/execute
 * Auth    : none (public endpoint)
 * Body    : {phrase, new_password?, new_password_confirm?, reset_totp?,
 *            new_totp_secret?, rotate_phrase?, new_phrase?, captcha_token?}
 * Returns : {status: "ok", passkey_user_id?, passkey_rp_id?, warning?}
 *           Neither a new TOTP secret nor a rotated phrase is returned: both
 *           were shown, and the secret verified, before this call.
 *
 * new_phrase (with rotate_phrase) must be the phrase recovery_phrase_new.php
 * minted in this session; new_totp_secret (with reset_totp) the secret
 * recovery_totp_new.php minted and recovery_totp_verify.php saw a valid code
 * for. Anything else is refused.
 *
 * Verify the recovery phrase and reset any combination (at least one) of
 * password, TOTP secret and recovery phrase. MASTER_KEY itself never changes,
 * only what unlocks it, so all files, shares and names survive.
 *
 * Always, on success: every passkey is removed and every session ended (see
 * Auth::executeRecovery()).
 */

declare(strict_types=1);

use AESBin\CryptoService;

defined("AESBIN_APP") || exit;

// Both halves of the flow are equally public, so both carry the same locks.
// See recovery_require_gate().
recovery_require_gate();

$body = json_body();

// Before the honeypot (see SecurityService::enforceHoneypot()). Same bucket
// as recovery_verify.php.
$security->enforceRateLimit("recovery.failure");

// Honeypot, counted as a wrong phrase.
$security->enforceHoneypot($body, "recovery.failure", ["error" => __("recovery_error")]);

require_fields($body, ["phrase"]);

// Optional fields, normalised like the required one: an array here would
// reach strlen() and 500 a public endpoint.
foreach (["new_password", "new_password_confirm", "new_phrase", "new_totp_secret"] as $field) {
    if (isset($body[$field])) {
        normalize_body_field($body, $field);
    }
}
unset($field);

$phrase = trim($body["phrase"]);
// An empty field means "keep the current password", like an absent one.
$newPw = ($body["new_password"] ?? "") !== "" ? $body["new_password"] : null;
$resetTotp = !empty($body["reset_totp"]);
$rotatePhrase = !empty($body["rotate_phrase"]);

// Independent choices: a new TOTP secret or phrase must not force a password
// change.
if ($newPw === null && !$resetTotp && !$rotatePhrase) {
    json_response(
        ["error" => __("err_recovery_execute_empty")],
        400
    );
}
if ($newPw !== null) {
    if (mb_strlen($newPw) < CryptoService::PASSWORD_MIN_LENGTH) {
        json_response(["error" => __("password_too_short")], 400);
    }
    if ($newPw !== (string) ($body["new_password_confirm"] ?? $newPw)) {
        json_response(["error" => __("password_mismatch")], 400);
    }
}

// ── Phrase rotation: only the candidate minted for this session ───────────────
// See recovery_phrase_new.php.
$newPhrase = null;
if ($rotatePhrase) {
    $newPhrase = trim((string) ($body["new_phrase"] ?? ""));
    $expected = $_SESSION["recovery_new_phrase_hash"] ?? null;
    if (
        $newPhrase === "" ||
        !is_string($expected) ||
        !hash_equals($expected, recovery_phrase_hash($newPhrase))
    ) {
        // Generate step skipped, session recycled, or a phrase this server
        // did not issue. Refusing keeps the current phrase working: the safe
        // way to fail.
        // "code": the page mints a fresh phrase by itself (auth.js).
        json_response(["error" => __("err_recovery_phrase_expired"), "code" => "phrase_expired"], 400);
    }
}

// ── TOTP reset: only the secret verified in this session ──────────────────────
// See recovery_totp_new.php. Checked before the captcha and the phrase, so a
// missing verification costs neither a token nor an attempt.
$newTotpSecret = null;
if ($resetTotp) {
    $newTotpSecret = (string) ($body["new_totp_secret"] ?? "");
    $verified = $_SESSION["recovery_new_totp_verified"] ?? null;
    if (
        $newTotpSecret === "" ||
        !is_string($verified) ||
        !hash_equals($verified, recovery_totp_hash($newTotpSecret))
    ) {
        // "code": the page mints a fresh secret by itself (auth.js). A
        // verification is cleared by recovery_totp_new.php (another tab: the
        // secret this page holds is replaced and cannot be verified again)
        // or by a wrong code in recovery_totp_verify.php, which the page
        // cannot send once verified, since it locks the code field.
        json_response(["error" => __("err_recovery_totp_unverified"), "code" => "totp_unverified"], 400);
    }
}

// Captcha, as in recovery_verify.php. This endpoint verifies the phrase
// itself, so a captcha only on recovery_verify could be bypassed by calling
// it directly.
$security->enforceCaptcha($body, "recovery.captcha_fail");

// The phrase is checked in executeRecovery() (Argon2id); see
// SecurityService::serializeAttempts().
$security->serializeAttempts("recovery.failure");

// All requested changes are applied in one statement: a half-applied recovery
// could lock the owner out for good.
$response = $auth->executeRecovery(
    $phrase,
    $newPw,
    $newTotpSecret,
    $newPhrase,
    // Same RP ID as registration (a pinned parent domain would not match
    // location.hostname), for the client-side signal after recovery.
    webauthn_rp_id($config)
);

// Spent: the same phrase or secret must not be committed twice.
unset(
    $_SESSION["recovery_new_phrase_hash"],
    $_SESSION["recovery_new_totp_hash"],
    $_SESSION["recovery_new_totp_verified"]
);

json_response($response);
