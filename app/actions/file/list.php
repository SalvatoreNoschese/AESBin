<?php

/**
 * app/actions/file/list.php
 *
 * GET /api/files
 * Auth    : requireAuth()
 * Query   : container_id?, directory_id?  (container_id alone lists the
 *           container root; neither lists every live file)
 * Body    : —
 * Returns : {files[]}
 *
 * List live files with decrypted metadata. The trash has its own endpoint
 * (system/trash.php).
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();
json_response([
    "files" => $fileSvc->listFiles(
        $auth->sessionMasterKey(),
        query_int("container_id"),
        query_int("directory_id")
    ),
]);
