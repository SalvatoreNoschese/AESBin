<?php

/**
 * app/actions/auth/passkey_verify.php
 *
 * POST /api/auth/passkey/verify
 * Auth    : none (public endpoint); step 1 of 2 in passkey login
 * Body    : {id, response: {clientDataJSON, authenticatorData, signature}}
 * Returns : {ok, wrapped_master, wrapped_iv, wrapped_tag}
 *
 * Verifies the WebAuthn signature and returns the credential's wrapped
 * master key for the client to unwrap with its PRF-derived KEK. Not
 * sensitive: useless without the KEK, which never leaves the browser.
 *
 * Split from passkey_authenticate.php because the server can check the
 * passkey on its own, but the master key only once the client sends it back
 * for the TOTP-canary check. A short-lived session marker links the steps.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$body = json_body();

// Own bucket, not login.failure: otherwise a few wrong passwords from anyone
// would also lock out passkey login, the factor that does not involve the
// password. An assertion is not guessable (single-use challenge, signature
// against a stored key), so this is abuse damping.
$security->enforceRateLimit("passkey.failure");

$challenge = $_SESSION["webauthn_challenge"] ?? null;
$mode = $_SESSION["webauthn_mode"] ?? null;
if (!$challenge || $mode !== "login") {
    json_response(["error" => __("err_no_auth_challenge")], 400);
}
unset($_SESSION["webauthn_challenge"], $_SESSION["webauthn_mode"]);

// Locked: the sign-counter update below rewrites the whole document, so a
// concurrent register/remove would otherwise be reverted. See webauthn_lock().
webauthn_lock($db);
$data = webauthn_data($db);
if ($data["credentials"] === []) {
    log_activity($db, "passkey.failure", null, null, [
        "reason" => "no_passkeys",
    ]);
    json_response(["error" => __("err_no_passkeys_registered")], 404);
}

// The browser sends assertion.id as base64url; stored ids are standard
// base64. A non-scalar id (public endpoint) gets the decode failure's answer.
if (isset($body["id"]) && !is_scalar($body["id"])) {
    json_response(["error" => __("err_credential_encoding")], 400);
}
$rawId = b64_decode_any((string) ($body["id"] ?? ""));
if ($rawId === false) {
    json_response(["error" => __("err_credential_encoding")], 400);
}
$response = body_object($body, "response", ["clientDataJSON", "authenticatorData", "signature"]);
$credId = base64_encode($rawId);
$credData = null;
foreach ($data["credentials"] as $c) {
    if (($c["id"] ?? null) === $credId) {
        $credData = $c;
        break;
    }
}
if (!$credData) {
    log_activity($db, "passkey.failure", null, null, [
        "reason" => "unknown_passkey",
    ]);
    json_response(["error" => __("err_unknown_passkey")], 400);
}

try {
    $webAuthn = webauthn_server($config);
    $clientDataJSON = (string) b64_decode_any((string) ($response["clientDataJSON"] ?? ""));
    $authenticatorData = (string) b64_decode_any((string) ($response["authenticatorData"] ?? ""));
    $signature = (string) b64_decode_any((string) ($response["signature"] ?? ""));
    $publicKey = (string) base64_decode((string) $credData["publicKey"], true); // stored as standard base64
    $signCount = (int) ($credData["signCount"] ?? 0);

    // Counter policy, enforced here rather than by the library (null below):
    // - always 0 (usual for synced passkeys): no counter, accepted while the
    //   stored value is 0 too;
    // - lower than a stored non-zero value, 0 included: clone or replay,
    //   refused;
    // - unchanged non-zero: tolerated, for authenticators sharing one counter
    //   across credentials.
    // getSignatureCounter() gives null for 0.
    $webAuthn->processGet(
        $clientDataJSON,
        $authenticatorData,
        $signature,
        $publicKey,
        $challenge,
        null, // let the library skip counter check; we enforce below
        true, // requireUserVerification
        true // requireUserPresent
    );
    $newSignCount = $webAuthn->getSignatureCounter() ?? 0;
    if ($signCount > 0 && $newSignCount < $signCount) {
        log_activity($db, "passkey.failure", null, null, [
            "reason" => "passkey_replay",
        ]);
        json_response(
            ["error" => __("err_passkey_replay")],
            401
        );
    }

    // ── Update sign count ─────────────────────────────────────────────────────
    foreach ($data["credentials"] as &$c) {
        if ($c["id"] === $credId) {
            $c["signCount"] = $newSignCount;
            break;
        }
    }
    unset($c);
    webauthn_data_save($db, $data, false);

    // ── Mark this credential as verified for the next 60s ─────────────────────
    // Checked (and consumed) by passkey_authenticate.php.
    $_SESSION["webauthn_verified_cred"] = $credId;
    $_SESSION["webauthn_verified_at"] = time();

    json_response([
        "ok" => true,
        "wrapped_master" => $credData["wrapped_master"],
        "wrapped_iv" => $credData["wrapped_iv"],
        "wrapped_tag" => $credData["wrapped_tag"],
    ]);
} catch (Throwable $e) {
    error_log("[AESBin] passkey verify: " . $e->getMessage());
    log_activity($db, "passkey.failure", null, null, [
        "reason" => "passkey_verify_error",
    ]);
    json_response(["error" => __("passkey_login_error")], 401);
}
