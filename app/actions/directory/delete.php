<?php

/**
 * app/actions/directory/delete.php
 *
 * DELETE /api/directories/:id
 * Auth    : requireAuth()
 * Body    : —
 * Returns : {deleted: true, shares_destroyed}
 *
 * Delete a directory and trash all its files.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();
$masterKey = $auth->sessionMasterKey();

// 0. Must exist; see container/delete.php.
require_directory($db, $did);

// Destroys every share on the directory or a file inside it. Not
// elevation-gated; see docs/security-model.md § Elevation.
//
// 1. Trash the live files, outside the transaction below (see
//    trash_files_in()).
$filesharesDestroyed = trash_files_in($db, $fileSvc, "directory_id", $did, $masterKey);

// Captured before the delete, while the name can still be resolved.
$deletedName = activity_capture_name_enc($db, "directory", $did);

try {
    // DB work only from here.
    $db->beginTransaction();

    // An upload, restore or move that landed after step 1 would survive
    // with its directory_id set to NULL. The row lock holds new ones off
    // (their foreign-key check waits on it); the count catches the rest.
    if (!lock_and_confirm_emptied($db, "directories", "directory_id", $did)) {
        db_rollback_if_open($db);
        json_response(["error" => __("err_delete_raced")], 409);
    }

    // 2. Hard-delete directory-type shares, logged after the commit.
    $revoked = revoke_directory_shares($db, [$did => $deletedName]);

    // 3. Delete directory (files.directory_id is ON DELETE SET NULL)
    $db->prepare(
        "DELETE FROM " . DB_PREFIX . "directories WHERE id = :did"
    )->execute([":did" => $did]);

    $db->commit();

    log_deleted_directories($db, $revoked, [$did => $deletedName], "directory_deleted");
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
