<?php

/**
 * app/actions/auth/passkey_authenticate.php
 *
 * POST /api/auth/passkey/authenticate
 * Auth    : none (public endpoint); step 2 of 2 in passkey login
 * Body    : {master_key}  base64, unwrapped client-side with the
 *           PRF-derived KEK (see passkey.js)
 * Returns : {ok}
 *
 * Requires a signature verified by passkey_verify.php in the last 60 seconds;
 * this endpoint never sees the WebAuthn assertion.
 *
 * The KEK is the raw PRF output and never leaves the browser, so the client
 * unwraps MASTER_KEY itself and sends it over HTTPS (the same trust boundary
 * the password flow crosses with a raw password).
 *
 * The signature proves possession of the authenticator, not that this key is
 * genuine. So the key must decrypt totp_secret_enc (present for every
 * account): the AES-GCM tag makes a wrong key fail here rather than corrupt
 * the session and every file encrypted afterwards.
 */

declare(strict_types=1);

use AESBin\CryptoService;

defined("AESBIN_APP") || exit;

$body = json_body();

// Same bucket as passkey_verify.php. A 256-bit key is not guessable, so this
// is abuse damping, but every failure path feeds a bucket.
$security->enforceRateLimit("passkey.failure");

// Freshness flag only, one use. Not bound to a credential: every passkey
// wraps the same MASTER_KEY, so which one was used carries no authorisation
// meaning. The real proof is the TOTP-canary decryption below.
$verifiedFlag = $_SESSION["webauthn_verified_cred"] ?? null;
$verifiedAt = $_SESSION["webauthn_verified_at"] ?? 0;
unset($_SESSION["webauthn_verified_cred"], $_SESSION["webauthn_verified_at"]);

if (!$verifiedFlag || time() - $verifiedAt > 60) {
    json_response(
        ["error" => __("err_passkey_verify_expired")],
        401
    );
}

require_fields($body, ["master_key"]);

$stmt = $db->prepare(
    "SELECT totp_secret_enc, totp_secret_iv, totp_secret_tag FROM " .
        DB_PREFIX .
        "auth WHERE id = 1"
);
$stmt->execute();
$row = $stmt->fetch();
if (!$row) {
    json_response(["error" => __("err_not_initialised")], 500);
}

$masterKey = base64_decode($body["master_key"] ?? "", true);
if ($masterKey === false || strlen($masterKey) !== 32) {
    json_response(["error" => __("err_master_key_malformed")], 401);
}

try {
    $canary = $crypto->decryptMeta(
        $row["totp_secret_enc"],
        $row["totp_secret_iv"],
        $row["totp_secret_tag"],
        $masterKey,
        CryptoService::AAD_TOTP
    );
    sodium_memzero($canary);
} catch (RuntimeException) {
    sodium_memzero($masterKey);
    // "passkey.key_mismatch", not passkey_verify.php's "passkey.failure": a
    // different log entry, but RATE_LIMIT_BUCKET_OF (helpers.php) maps both
    // to one bucket.
    log_activity($db, "passkey.key_mismatch", null, null, [
        "reason" => "master_key_verify",
    ]);
    json_response(["error" => __("err_master_key_verify")], 401);
}

// ── Create full session (passkey replaces password + TOTP) ────────────────────
$auth->sessionFullFromPasskey($masterKey);
sodium_memzero($masterKey);

log_activity($db, "passkey.login");
json_response(["ok" => true]);
