<?php

/**
 * app/actions/system/trash.php
 *
 * GET /api/trash
 * Auth    : requireAuth()
 * Body    : —
 * Returns : {files[]}
 *
 * List trashed files with decrypted metadata.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();
json_response([
    "files" => $fileSvc->listFiles(
        $auth->sessionMasterKey(),
        null,
        null,
        true // trashed = true
    ),
]);
