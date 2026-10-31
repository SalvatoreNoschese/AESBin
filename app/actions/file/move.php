<?php

/**
 * app/actions/file/move.php
 *
 * POST /api/files/:id/move
 * Auth    : requireAuth()
 * Body    : {container_id, directory_id}
 * Returns : {moved: true}
 *
 * Move a file to another directory. 409 if the destination already holds a
 * file with this name. Unlike upload()/restore(), no free name is picked: a
 * move is explicit, and a silent rename would surprise.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();
$body = json_body();
if (empty($body["container_id"])) {
    json_response(["error" => __("err_container_id_required")], 400);
}
$destCid = body_id($body, "container_id");
// Files always live in a directory, never at container root.
$destDirId = body_id($body, "directory_id");

$masterKey = $auth->sessionMasterKey();

// move() refuses a name taken in the destination and drops the file from
// the source directory's shares.
$fileSvc->move($fileId, $destCid, $destDirId, $masterKey);
// Add the moved file to any active shares of the destination directory.
$shareSvc->autoRefreshForDirectory($destDirId, $masterKey);

json_response(["moved" => true]);
