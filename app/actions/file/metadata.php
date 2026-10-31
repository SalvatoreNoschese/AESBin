<?php

/**
 * app/actions/file/metadata.php
 *
 * GET /api/files/:id/metadata
 * Auth    : requireAuth()
 * Returns : {id, container_id, directory_id, name}
 *
 * Minimal lookup of a live file. The share retarget modal uses it to
 * preselect the current target's folder and file.
 */

declare(strict_types=1);

use AESBin\CryptoService;

defined("AESBIN_APP") || exit;

$auth->requireAuth();
$masterKey = $auth->sessionMasterKey();

$stmt = $db->prepare(
    "SELECT id, container_id, directory_id, storage_name, name_enc, name_iv, name_tag
       FROM " . DB_PREFIX . "files
      WHERE id = :id AND trashed_at IS NULL LIMIT 1"
);
$stmt->execute([":id" => $fileId]);
$file = $stmt->fetch();
if (!$file) {
    json_response(["error" => __("file_not_found")], 404);
}

json_response([
    "id" => (int) $file["id"],
    "container_id" => (int) $file["container_id"],
    "directory_id" => $file["directory_id"] !== null ? (int) $file["directory_id"] : null,
    "name" => $crypto->decryptColumn($file, "name", $masterKey, CryptoService::fileMetaAad("name", $file["storage_name"])),
]);
