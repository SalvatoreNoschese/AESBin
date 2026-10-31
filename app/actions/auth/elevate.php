<?php

/**
 * app/actions/auth/elevate.php
 *
 * POST /api/auth/elevate
 * Auth    : requireKeyHolder()
 * Body    : {password}
 * Returns : {ok, expires_at}
 *
 * "Sudo mode": verify the master password and open an elevation window
 * (Auth::ELEVATION_TTL) in which sensitive settings actions (passkeys, TOTP
 * secret reveal, security config) work without further prompts. Enforced by
 * Auth::requireElevated().
 *
 * Failures use their own elevate.failure bucket, not login.failure: typos at
 * the login screen must not lock the settings you need when you suspect a
 * compromise (revoking sessions, removing passkeys).
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireKeyHolder();

$body = json_body();
require_fields($body, ["password"]);

$security->serializeAttempts("elevate.failure");

if (!$auth->verifyPassword($body["password"])) {
    log_activity($db, "elevate.failure");
    json_response(["error" => __("login_error")], 403);
}

$expires = $auth->grantElevation();
log_activity($db, "settings.elevated");

json_response(["ok" => true, "expires_at" => $expires]);
