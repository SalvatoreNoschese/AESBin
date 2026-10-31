<?php

/**
 * app/actions/auth/totp_secret.php
 *
 * GET /api/auth/totp/secret
 * Auth    : requireElevated()
 * Body    : —
 * Returns : {totp_secret, totp_uri}
 *
 * Return the existing TOTP secret and provisioning URI, to set up a new
 * authenticator.
 *
 * Elevation-gated: a leaked secret is a silent, persistent backdoor. The
 * owner's authenticator keeps working and a password change does not rotate
 * it.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireElevated();

$secret = $auth->getDecryptedTotpSecret();
log_activity($db, "totp.revealed");

json_response([
    "totp_secret" => $secret,
    "totp_uri" => totp_uri($crypto, $secret),
]);
