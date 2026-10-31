<?php

/**
 * app/actions/auth/passkey_register.php
 *
 * POST /api/auth/passkey/register
 * Auth    : requireElevated()
 * Body    : {response: {clientDataJSON, attestationObject}, wrapped_master,
 *           wrapped_iv, wrapped_tag}  (wrapped client-side with the
 *           PRF-derived KEK, see passkey.js)
 * Returns : {ok, count}
 *
 * Completes WebAuthn registration. The KEK is the raw PRF output, read
 * client-side from getClientExtensionResults().prf, and never reaches the
 * server. The server stores only the wrapped key and the non-secret prf_salt
 * needed to re-derive the KEK. Storing the KEK would let anyone with DB read
 * access unwrap the vault.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

// Every step of the ceremony is elevation-gated, not just the key handout
// (passkey_register_key.php): otherwise a hijacked session could store a
// garbage-wrapped credential.
$auth->requireElevated();

$body = json_body();

$challenge = $_SESSION["webauthn_challenge"] ?? null;
$mode = $_SESSION["webauthn_mode"] ?? null;
$prfSaltB64 = $_SESSION["webauthn_prf_salt"] ?? null;
if (!$challenge || $mode !== "register") {
    json_response(["error" => __("err_no_reg_challenge")], 400);
}
unset(
    $_SESSION["webauthn_challenge"],
    $_SESSION["webauthn_mode"],
    $_SESSION["webauthn_prf_salt"]
);

require_fields($body, ["wrapped_master", "wrapped_iv", "wrapped_tag"]);
// Stored as sent and returned verbatim at every passkey login, so it must be
// the shape CryptoService writes: 32-byte key, AES-GCM 12-byte IV and 16-byte
// tag, all base64. Anything else could never unwrap.
$wrapShape = ["wrapped_master" => 32, "wrapped_iv" => 12, "wrapped_tag" => 16];
foreach ($wrapShape as $field => $bytes) {
    $raw = base64_decode($body[$field], true);
    if (!is_string($raw) || strlen($raw) !== $bytes) {
        json_response(["error" => __("err_field_invalid", ["field" => $field])], 400);
    }
}
if (!$prfSaltB64) {
    // Minted by passkey_challenge.php in the same session as the challenge.
    json_response(["error" => __("err_prf_salt_missing")], 400);
}
$response = body_object($body, "response", ["clientDataJSON", "attestationObject"]);

try {
    $webAuthn = webauthn_server($config);
    $credential = $webAuthn->processCreate(
        (string) b64_decode_any((string) ($response["clientDataJSON"] ?? "")),
        (string) b64_decode_any((string) ($response["attestationObject"] ?? "")),
        $challenge,
        true, // requireUserVerification
        true // requireUserPresent
    );

    // Locked read-modify-write; see webauthn_lock().
    webauthn_lock($db);
    $data = webauthn_data($db);

    // Capped: webauthn_data is one JSON column rewritten on every login, and
    // ten passkeys is far past any single-user need. passkey_challenge.php
    // has already refused a registration that starts at the limit; this one
    // is for two registrations racing for the last place.
    if (count($data["credentials"]) >= PASSKEY_MAX) {
        json_response(["error" => __("err_passkey_limit")], 409);
    }

    // Label built here, never taken from the request: the settings list is
    // where the owner decides which passkeys are theirs, so the registrant
    // must not choose what it shows. Browser/OS come from the UA (useful but
    // forgeable); the IP is not forgeable and matches the passkey.register
    // log entry.
    $label = client_label(request_ua()) ?? "Passkey " . (count($data["credentials"]) + 1);
    $label .= " · " . request_ip();

    // Same {enc, iv, tag} shape as CryptoService::wrapMasterKey(), computed
    // client-side with the PRF KEK. Stored as-is.
    $data["credentials"][] = [
        "id" => base64_encode($credential->credentialId),
        "publicKey" => base64_encode($credential->credentialPublicKey),
        // 0 for authenticators without a counter (getSignatureCounter()
        // returns null). Policy lives in passkey_verify.php.
        "signCount" => (int) ($webAuthn->getSignatureCounter() ?? 0),
        "name" => $label,
        "created_at" => gmdate("c"),
        "wrapped_master" => $body["wrapped_master"],
        "wrapped_iv" => $body["wrapped_iv"],
        "wrapped_tag" => $body["wrapped_tag"],
        "prf_salt" => $prfSaltB64,
    ];

    webauthn_data_save($db, $data);

    log_activity($db, "passkey.register", null, null, [
        "count" => count($data["credentials"]),
    ]);
    json_response(["ok" => true, "count" => count($data["credentials"])]);
} catch (Throwable $e) {
    // Mostly a ceremony the library rejected (bad attestation, wrong
    // challenge or origin): the request's fault, hence 400.
    error_log("[AESBin] passkey register: " . $e->getMessage());
    json_response(["error" => __("err_passkey_register")], 400);
}
