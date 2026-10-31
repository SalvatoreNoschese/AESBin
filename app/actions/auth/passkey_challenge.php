<?php

/**
 * app/actions/auth/passkey_challenge.php
 *
 * GET /api/auth/passkey/challenge
 * Auth    : none for mode=login (a session with a CSRF token is required);
 *           requireElevated() for mode=register
 * Query   : mode=login (default) | mode=register
 * Body    : —
 * Returns : {ok, args: PublicKeyCredentialOptions, prf_salt?} (prf_salt on
 *           register only)
 *
 * Generate and store a WebAuthn challenge.
 *
 * PRF: every credential has its own random 32-byte prf_salt, stored with its
 * public key in webauthn_data (not secret, like an IV). On register a fresh
 * salt is returned as prf_salt and kept in the session. On login the
 * authenticator's choice is unknown in advance, so every credential's salt is
 * sent via extensions.prf.evalByCredential.
 */

declare(strict_types=1);

use AESBin\Csrf;
use ReportUri\Passkeys\Binary\ByteBuffer;

defined("AESBIN_APP") || exit;

$mode = $_GET["mode"] ?? "login";

if ($mode === "register") {
    // Every step of registration is elevation-gated
    $auth->requireElevated();
} elseif (!Csrf::issued()) {
    // mode=login is public and stores a challenge in the session. Legitimate
    // callers come from the login page, which already issued a CSRF token.
    // Requiring the token to exist (not to be re-submitted: this is a GET)
    // turns a cookie-less request into a cheap 403 that stores no challenge.
    json_response(["error" => __("auth_required"), "code" => "no_session"], 403);
}

try {
    $webAuthn = webauthn_server($config);

    if ($mode === "register") {
        // Locked: the user_id branch below is a read-modify-write of
        // webauthn_data. See webauthn_lock() in helpers.php.
        webauthn_lock($db);
        $data = webauthn_data($db);
        // Refused here, before the browser is asked for anything: at the
        // check in passkey_register.php the authenticator has already
        // created the passkey, which would stay on the device with nothing
        // on the server to match it.
        if (count($data["credentials"]) >= PASSKEY_MAX) {
            json_response(["error" => __("err_passkey_limit")], 409);
        }
        // excludeCredentials: raw ids of the passkeys already registered.
        $existing = array_map(
            fn($c) => (string) base64_decode((string) ($c["id"] ?? ""), true),
            $data["credentials"]
        );

        // Stable random user id, created at the first registration and reused
        // after, so password managers that key on it (Google Password Manager
        // does) replace an entry instead of adding a duplicate. Persisted at
        // once so concurrent requests agree.
        $userId = base64_decode((string) ($data["user_id"] ?? ""), true);
        if (!is_string($userId) || $userId === "") {
            $userId = random_bytes(32);
            $data["user_id"] = base64_encode($userId);
            webauthn_data_save($db, $data, false);
        }

        $rpId = webauthn_rp_id($config);
        $createArgs = $webAuthn->getCreateArgs(
            $userId, // raw binary; ByteBuffer serialises to base64url in the JSON response
            // userName carries the registration date, shown by account
            // pickers and password managers, so an orphaned entry (deleted
            // here, still in the manager) can be told apart from a fresh one.
            // UTC here; passkey.js rewrites it with the device's own date,
            // in the device's own format.
            "AESBin · " . gmdate("Y-m-d"), // userName
            $rpId, // userDisplayName: plain hostname, matching the manager's preview
            60, // timeout (seconds)
            true, // requireResidentKey: required for PRF with Google Password Manager on Android
            "required", // requireUserVerification: PRF requires mandatory UV
            null, // crossPlatformAttachment: null = no preference
            $existing // excludeCredentialIds: raw binary array
        );

        // Single-user vault: device provenance is never checked. The library
        // already hardcodes "none"; set explicitly so the intent is visible.
        $createArgs->publicKey->attestation = "none";

        // The server declares PRF only (prf: {}); passkey.js fills in
        // eval.first with prf_salt before calling create(), and falls back to
        // one get() on the new credential when the authenticator evaluates
        // PRF only on assertions.
        $prfSalt = random_bytes(32);
        $createArgs->publicKey->extensions->prf = new \stdClass();

        $_SESSION["webauthn_challenge"] = $webAuthn->getChallenge()->getBinaryString();
        $_SESSION["webauthn_mode"] = "register";
        $_SESSION["webauthn_prf_salt"] = base64_encode($prfSalt);
        json_response(["ok" => true, "args" => $createArgs, "prf_salt" => base64_encode($prfSalt)]);
    }

    // Login challenge: every registered credential is allowed.
    $credentials = webauthn_data($db)["credentials"];
    $ids = array_map(fn($c) => (string) base64_decode((string) ($c["id"] ?? ""), true), $credentials);
    if ($ids === []) {
        json_response(["error" => __("err_no_passkeys_registered")], 404);
    }
    // Transport flags all false: no hint, so the browser offers every way it
    // knows to reach the authenticator.
    $getArgs = $webAuthn->getGetArgs(
        $ids,
        60,
        false,
        false,
        false,
        false,
        false,
        "required" // PRF requires mandatory user verification
    );

    // Keyed by base64url credential id (no padding): per spec it must match
    // what the browser uses for assertion.id.
    $evalByCredential = new \stdClass();
    foreach ($credentials as $c) {
        $salt = base64_decode((string) ($c["prf_salt"] ?? ""), true);
        if (!is_string($salt) || $salt === "") {
            continue; // no PRF salt stored: nothing this credential can unwrap
        }
        $entry = new \stdClass();
        $entry->first = new ByteBuffer($salt);
        $evalByCredential->{b64url_encode((string) base64_decode((string) $c["id"], true))} = $entry;
    }
    if ((array) $evalByCredential !== []) {
        $getArgs->publicKey->extensions = new \stdClass();
        $getArgs->publicKey->extensions->prf = new \stdClass();
        $getArgs->publicKey->extensions->prf->evalByCredential = $evalByCredential;
    }

    // Raw bytes, not the (string) cast, which gives hex via __toString().
    $_SESSION["webauthn_challenge"] = $webAuthn->getChallenge()->getBinaryString();
    $_SESSION["webauthn_mode"] = "login";
    json_response(["ok" => true, "args" => $getArgs]);
} catch (Throwable $e) {
    error_log("[AESBin] passkey challenge: " . $e->getMessage());
    json_response(["error" => __("server_error")], 500);
}
