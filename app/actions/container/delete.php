<?php

/**
 * app/actions/container/delete.php
 *
 * DELETE /api/containers/:id
 * Auth    : requireAuth()
 * Body    : —
 * Returns : {deleted: true, shares_destroyed}
 *
 * Delete a container and its directories, trashing all its files.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();
$masterKey = $auth->sessionMasterKey();

// 0. Must exist, or a bad id would answer {"deleted": true} and log a
//    deletion that never happened.
require_container($db, $cid);

// Destroys every share on a directory or file inside the container. Not
// elevation-gated; see docs/security-model.md § Elevation.
//
// 1. Trash the live files, outside the transaction below (see
//    trash_files_in()).
$filesharesDestroyed = trash_files_in($db, $fileSvc, "container_id", $cid, $masterKey);

// Captured before the delete, while the name can still be resolved.
$deletedName = activity_capture_name_enc($db, "container", $cid);

try {
    // DB work only from here.
    $db->beginTransaction();

    // See directory/delete.php.
    if (!lock_and_confirm_emptied($db, "containers", "container_id", $cid)) {
        db_rollback_if_open($db);
        json_response(["error" => __("err_delete_raced")], 409);
    }

    // 2. The directories go with the container, so each one is named in the
    //    log as it would be if deleted on its own, and the directory links
    //    are revoked on the directory they served.
    $stmt = $db->prepare("SELECT id FROM " . DB_PREFIX . "directories WHERE container_id = :cid");
    $stmt->execute([":cid" => $cid]);
    $dirNames = [];
    foreach ($stmt->fetchAll() as $row) {
        $dirNames[(int) $row["id"]] = activity_capture_name_enc($db, "directory", (int) $row["id"]);
    }
    $revoked = revoke_directory_shares($db, $dirNames);

    // 3. Delete directory records. files.directory_id is ON DELETE SET NULL;
    //    trashed files keep a name snapshot (trashed_from_dir_name_*).
    $db->prepare(
        "DELETE FROM " . DB_PREFIX . "directories WHERE container_id = :cid"
    )->execute([":cid" => $cid]);

    // 4. Delete the container. files.container_id is ON DELETE SET NULL; the
    //    origin survives in trashed_from_container_name_*.
    $db->prepare(
        "DELETE FROM " . DB_PREFIX . "containers WHERE id = :cid"
    )->execute([":cid" => $cid]);

    $db->commit();

    log_deleted_directories($db, $revoked, $dirNames, "container_deleted");
    log_activity(
        $db,
        "container.delete",
        "container",
        $cid,
        $deletedName ?? []
    );
    json_response([
        "deleted" => true,
        // Directory links revoked here plus file links destroyed by trash().
        "shares_destroyed" => count($revoked) + $filesharesDestroyed,
    ]);
} catch (Throwable $e) {
    db_rollback_if_open($db);
    error_log("[AESBin] " . get_class($e) . ": " . $e->getMessage());
    json_response(["error" => __("server_error")], 500);
}
