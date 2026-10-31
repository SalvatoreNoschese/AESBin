<?php

/**
 * app/actions/auth/recovery_totp_new.php
 *
 * POST /api/auth/recovery/totp/new
 * Auth    : none (public endpoint, behind the recovery guard)
 * Body    : {}
 * Returns : {totp_secret, totp_uri}
 *
 * Generate the replacement TOTP secret and keep its hash in the session.
 * Nothing is stored in the database: recovery_execute.php commits it, and
 * only once recovery_totp_verify.php has seen a valid code for it, as the
 * installer does. A secret shown only in the response that activates it,
 * unverified, could lock the owner out: password alone does not log in, and
 * recovery has already removed every passkey and closed its own gate.
 *
 * A new secret replaces the previous candidate and clears its verification.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

recovery_require_gate();

$secret = $crypto->generateTotpSecret();
$_SESSION["recovery_new_totp_hash"] = recovery_totp_hash($secret);
unset($_SESSION["recovery_new_totp_verified"]);

json_response([
    "totp_secret" => $secret,
    "totp_uri" => totp_uri($crypto, $secret),
]);
