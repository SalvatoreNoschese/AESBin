<?php

/**
 * app/actions/share/update.php
 *
 * POST /api/shares/:id/update
 * Auth    : requireKeyHolder()
 * Body    : {password?: string, expires_in?: int}
 * Returns : {updated, expiry_changed, password_changed, sessions_killed}
 *
 * Change a share's expiry and/or password. Neither feeds key derivation, so
 * the URL keeps working.
 *
 *   password    absent: unchanged | "": remove | other: set
 *   expires_in  absent: unchanged | 0 or null: permanent | N: expires in N seconds
 */

declare(strict_types=1);

use AESBin\ShareService;

defined("AESBIN_APP") || exit;

// Not elevation-gated: the session can already read every file; the
// password only gates visitors. See docs/security-model.md § Elevation.
$auth->requireKeyHolder();

$body = json_body();
// $shareId is set by the router.

// false means "do not touch": null already means "never expires".
$expiresAt = array_key_exists("expires_in", $body) ? ShareService::expiryFromBody($body) : false;

$password = array_key_exists("password", $body) ? ShareService::passwordFromBody($body) : null;

if ($expiresAt === false && $password === null) {
    json_response(["error" => __("err_nothing_to_update")], 400);
}

$result = $shareSvc->updateSettings($shareId, $password, $expiresAt);

json_response([
    "updated" => true,
    "expiry_changed" => $result["expiry_changed"],
    "password_changed" => $result["password_changed"],
    // Visitor sessions ended by setting a password, for the dashboard.
    "sessions_killed" => $result["sessions_killed"],
]);
