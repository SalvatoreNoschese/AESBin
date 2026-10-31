<?php

/**
 * app/actions/share/create.php
 *
 * POST /api/shares
 * Auth    : requireAuth()
 * Body    : {type, target_id, password?, expires_in?}  expires_in in seconds;
 *           0, null or absent = never
 * Returns : {share_id, token, share_url, expires_at, file_count?}
 *
 * Create a share link for a file or directory. file_count is returned for
 * directory shares.
 *
 * Not elevation-gated: an authenticated session can already decrypt every
 * file, so a link exposes nothing new. Elevation guards what protects the
 * instance, not content the session can already read. See
 * docs/security-model.md § Elevation.
 */

declare(strict_types=1);

use AESBin\ShareService;

defined("AESBIN_APP") || exit;

$auth->requireAuth();
$body = json_body();
require_fields($body, ["type", "target_id"]);
if (!in_array($body["type"], ["file", "directory"], true)) {
    json_response(["error" => __("err_share_type_invalid")], 400);
}
// Absent or null both mean "never expires", as in the create dialog's default.
$expires = ShareService::expiryFromBody($body);
$password = ShareService::passwordFromBody($body);
if ($password === "") {
    $password = null;
}
$targetId = body_id($body, "target_id");
$masterKey = $auth->sessionMasterKey();
$result =
    $body["type"] === "file"
        ? $shareSvc->createFileShare(
            $targetId,
            $masterKey,
            $password,
            $expires
        )
        : $shareSvc->createDirectoryShare(
            $targetId,
            $masterKey,
            $password,
            $expires
        );
json_response($result, 201);
