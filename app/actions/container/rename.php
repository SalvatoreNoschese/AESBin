<?php

/**
 * app/actions/container/rename.php
 *
 * POST /api/containers/:id/rename
 * Auth    : requireAuth()
 * Body    : {name}
 * Returns : {renamed: true, name}
 *
 * Rename a container.
 */

declare(strict_types=1);

use AESBin\CryptoService;

defined("AESBIN_APP") || exit;

$auth->requireAuth();
$body = json_body();
require_fields($body, ["name"]);
$name = trim($body["name"]);
if (!is_valid_display_name($name)) {
    json_response(["error" => __("err_invalid_name")], 400);
}

$masterKey = $auth->sessionMasterKey();

// Must exist, or a bad id would answer {"renamed": true} and log a rename
// that never happened.
$exists = $db->prepare(
    "SELECT id, name_enc, name_iv, name_tag FROM " .
        DB_PREFIX .
        "containers WHERE id = :id LIMIT 1"
);
$exists->execute([":id" => $cid]);
$row = $exists->fetch();
if (!$row) {
    json_response(["error" => __("container_not_found")], 404);
}

// Same name: nothing to write and nothing to log.
if ($crypto->decryptColumn($row, "name", $masterKey, CryptoService::rowAad("container", $cid)) === $name) {
    json_response(["renamed" => true, "name" => $name]);
}

if (!is_name_unique($db, $crypto, $masterKey, "container", $name, [], $cid)) {
    json_response(["error" => __("name_duplicate_container")], 409);
}

$enc = $crypto->encryptMeta($name, $masterKey, CryptoService::rowAad("container", $cid));
$db->prepare(
    "UPDATE " .
        DB_PREFIX .
        "containers SET name_enc=:e, name_iv=:i, name_tag=:t WHERE id=:id"
)->execute([
    ":e" => $enc["enc"],
    ":i" => $enc["iv"],
    ":t" => $enc["tag"],
    ":id" => $cid,
]);

log_activity(
    $db,
    "container.rename",
    "container",
    $cid,
    activity_prev_name_enc($row, CryptoService::rowAad("container", $cid))
);
json_response(["renamed" => true, "name" => $name]);
