<?php

/**
 * app/actions/file/delete.php
 *
 * DELETE /api/files/:id
 * Auth    : requireElevated()
 * Body    : —
 * Returns : {deleted}
 *
 * Permanently delete a file from the trash: wipes the blob, drops the row,
 * destroys any file-type share still on it. FileService::deletePermanently()
 * enforces that the file is already trashed.
 *
 * Elevation-gated, unlike the recoverable trash/revoke/retarget: this wipes
 * the owner's only copy (see docs/security-model.md § Elevation).
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireElevated();
// Captured before the row goes, while the name can still be resolved.
$deletedName = activity_capture_name_enc($db, "file", $fileId);
$fileSvc->deletePermanently($fileId);
log_activity(
    $db,
    "file.delete",
    "file",
    $fileId,
    $deletedName ?? []
);
json_response(["deleted" => true]);
