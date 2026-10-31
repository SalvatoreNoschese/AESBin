<?php

/**
 * app/actions/auth/passkey_register_key.php
 *
 * GET /api/auth/passkey/register/key
 * Auth    : requireElevated()
 * Body    : —
 * Returns : {ok, master_key}  base64
 *
 * Hands the session's master key to the client so it can wrap it with the
 * PRF-derived KEK during registration (register() in passkey.js). The KEK
 * stays client-only, so the wrapping has to happen there.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

// Elevated, not merely authenticated: a passkey is a permanent credential
// that replaces password + TOTP and survives a password change.
$auth->requireElevated();

$masterKey = $auth->sessionMasterKey();
$encoded = base64_encode($masterKey);
sodium_memzero($masterKey);
json_response(["ok" => true, "master_key" => $encoded]);
