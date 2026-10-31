<?php

/**
 * app/actions/file/trash.php
 *
 * POST /api/files/:id/trash
 * Auth    : requireAuth()
 * Body    : —
 * Returns : {trashed: true, shares_destroyed}
 *
 * Move a file to the trash (soft delete; the blob is kept).
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();

// Destroys the file's share links permanently (restore() does not recreate
// them), so the count is returned. Not elevation-gated; see
// docs/security-model.md § Elevation.
$sharesDestroyed = $fileSvc->trash($fileId, $auth->sessionMasterKey());
json_response([
    "trashed" => true,
    // Always present, 0 included.
    "shares_destroyed" => $sharesDestroyed,
]);
