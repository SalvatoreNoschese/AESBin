<?php

/**
 * app/actions/directory/move.php
 *
 * POST /api/directories/:id/move
 * Auth    : requireAuth()
 * Body    : {container_id}
 * Returns : {moved: true}
 *
 * Move a directory to a different container.
 */

declare(strict_types=1);

use AESBin\CryptoService;

defined("AESBIN_APP") || exit;

$auth->requireAuth();
$body = json_body();
if (empty($body["container_id"])) {
    json_response(["error" => __("err_container_id_required")], 400);
}
$newCid = body_id($body, "container_id");

$masterKey = $auth->sessionMasterKey();

$stmt = $db->prepare(
    'SELECT container_id, name_enc, name_iv, name_tag
       FROM ' .
        DB_PREFIX .
        "directories WHERE id = :id LIMIT 1"
);
$stmt->execute([":id" => $did]);
$dir = $stmt->fetch();
if (!$dir) {
    json_response(["error" => __("directory_not_found")], 404);
}
$curCid = (int) $dir["container_id"];

if ($newCid === $curCid) {
    json_response(["error" => __("move_same_container")], 422);
}

require_container($db, $newCid);

// No name collision in the destination. The collision check needs the name,
// so an unreadable one fails the request (logged) rather than degrading as
// the snapshots in trash() do.
try {
    $name = $crypto->decryptMeta(
        $dir["name_enc"],
        $dir["name_iv"],
        $dir["name_tag"],
        $masterKey,
        CryptoService::rowAad("directory", $did)
    );
} catch (Throwable $e) {
    error_log("[AESBin] directory/move: name decrypt failed for " . $did . ": " . $e->getMessage());
    json_response(["error" => __("server_error")], 500);
}
if (
    !is_name_unique(
        $db,
        $crypto,
        $masterKey,
        "directory",
        $name,
        ["container_id" => $newCid],
        $did
    )
) {
    json_response(["error" => __("name_duplicate_directory")], 409);
}

// Keep the files' container_id in step. Metadata only, no re-encryption.
$db->beginTransaction();
try {
    $db->prepare(
        "UPDATE " .
            DB_PREFIX .
            "directories SET container_id = :cid WHERE id = :id"
    )->execute([":cid" => $newCid, ":id" => $did]);
    $db->prepare(
        "UPDATE " .
            DB_PREFIX .
            "files SET container_id = :cid WHERE directory_id = :id"
    )->execute([":cid" => $newCid, ":id" => $did]);
    $db->commit();
} catch (Throwable $e) {
    db_rollback_if_open($db);
    error_log("[AESBin] " . get_class($e) . ": " . $e->getMessage());
    json_response(["error" => __("err_move_failed")], 500);
}

log_activity(
    $db,
    "directory.move",
    "directory",
    $did,
    activity_name_enc_as($db, "container", $curCid, "from_container_name")
        + activity_name_enc_as($db, "container", $newCid, "to_container_name")
);
json_response(["moved" => true]);
