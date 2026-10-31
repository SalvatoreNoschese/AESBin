<?php

/**
 * app/actions/directory/create.php
 *
 * POST /api/directories
 * Auth    : requireAuth()
 * Body    : {container_id, name, description?}
 * Returns : {id, container_id, name}
 *
 * Create a directory inside a container. Name and description are stored
 * encrypted.
 */

declare(strict_types=1);

use AESBin\CryptoService;

defined("AESBIN_APP") || exit;

$auth->requireAuth();
// Before the container lookup below, which would otherwise answer a bare
// cookie.
$masterKey = $auth->sessionMasterKey();
$body = json_body();
require_fields($body, ["container_id", "name"]);
$cid = body_id($body, "container_id");
$name = trim($body["name"]);
if (!is_valid_display_name($name)) {
    json_response(["error" => __("err_invalid_name")], 400);
}
$desc = body_description($body);

require_container($db, $cid);

if (
    !is_name_unique($db, $crypto, $masterKey, "directory", $name, [
        "container_id" => $cid,
    ])
) {
    json_response(["error" => __("name_duplicate_directory")], 409);
}

// The description's AAD holds the id, like the name's, so it is sealed once
// the row exists, in the same transaction.
$db->beginTransaction();
try {
    $id = insert_named_row($db, $crypto, $masterKey, "directory", $name, ["container_id" => $cid]);
    if ($desc !== null && $desc !== "") {
        $encDesc = $crypto->encryptMeta($desc, $masterKey, CryptoService::directoryDescAad($id));
        $db->prepare(
            "UPDATE " . DB_PREFIX . "directories SET desc_enc = :de, desc_iv = :di, desc_tag = :dt WHERE id = :id"
        )->execute([":de" => $encDesc["enc"], ":di" => $encDesc["iv"], ":dt" => $encDesc["tag"], ":id" => $id]);
    }
    $db->commit();
} catch (Throwable $e) {
    db_rollback_if_open($db);
    throw $e;
}
log_activity($db, "directory.create", "directory", $id);
json_response(["id" => $id, "container_id" => $cid, "name" => $name], 201);
