<?php

/**
 * app/actions/share/retarget.php
 *
 * POST /api/shares/:id/retarget
 * Auth    : requireAuth()
 * Body    : {type: "file"|"directory", target_id}
 * Returns : {retargeted, type, files, skipped}
 *
 * Point an existing share at a different file or directory. The public URL
 * (token + key) is unchanged, so a posted link keeps working while the
 * content behind it is updated.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

// Not elevation-gated; see docs/security-model.md § Elevation.
$auth->requireAuth();
$masterKey = $auth->sessionMasterKey();

$body = json_body();
require_fields($body, ["type", "target_id"]);
// $shareId is set by the router.

$result = $shareSvc->retarget(
    $shareId,
    $body["type"],
    body_id($body, "target_id"),
    $masterKey
);

json_response([
    "retargeted" => true,
    "type" => $result["type"],
    "files" => $result["files"],
    // Files whose DEK would not unwrap, so a partial retarget is not
    // reported as full success. Always present, 0 included.
    "skipped" => $result["skipped"],
]);
