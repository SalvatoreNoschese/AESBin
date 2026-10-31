<?php

/**
 * app/actions/file/restore.php
 *
 * POST /api/files/:id/restore
 * Auth    : requireAuth()
 * Body    : —
 * Returns : {restored: true, name, renamed, recreated}: the name it got,
 *           whether the one it had was taken, and what was recreated for it
 *           ("", "directory" or "both"; see FileService::restore())
 *
 * Restore a file from the trash into the directory it came from, found by
 * name and recreated if it is gone (see FileService::restore()).
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();
$masterKey = $auth->sessionMasterKey();
$restored = $fileSvc->restore($fileId, $masterKey);

// Add the restored file to its directory's active shares.
$did = $fileSvc->getDirectoryId($fileId);
if ($did !== null) {
    $shareSvc->autoRefreshForDirectory($did, $masterKey);
}

json_response(["restored" => true] + $restored);
