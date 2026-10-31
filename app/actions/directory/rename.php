<?php

/**
 * app/actions/directory/rename.php
 *
 * POST /api/directories/:id/rename
 * Auth    : requireAuth()
 * Body    : {name, description?}
 * Returns : {renamed: true, name}
 *
 * Rename a directory and optionally update its description.
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

$stmt = $db->prepare(
    "SELECT container_id, name_enc, name_iv, name_tag, desc_enc, desc_iv, desc_tag FROM " .
        DB_PREFIX .
        "directories WHERE id = :id LIMIT 1"
);
$stmt->execute([":id" => $did]);
$row = $stmt->fetch();
if (!$row) {
    json_response(["error" => __("directory_not_found")], 404);
}

// Same order as container/rename.php: an unchanged name needs no
// uniqueness check. An unreadable old name counts as changed.
try {
    $oldName = $crypto->decryptMeta(
        $row["name_enc"],
        $row["name_iv"],
        $row["name_tag"],
        $masterKey,
        CryptoService::rowAad("directory", $did)
    );
    $nameChanged = $name !== $oldName;
} catch (RuntimeException) {
    $nameChanged = true;
}

$desc = changed_description(
    $crypto,
    body_description($body),
    $row,
    $masterKey,
    CryptoService::directoryDescAad($did)
);
// Same name and no new description: nothing to write and nothing to log.
if (!$nameChanged && $desc === null) {
    json_response(["renamed" => true, "name" => $name]);
}

if (
    $nameChanged &&
    !is_name_unique(
        $db,
        $crypto,
        $masterKey,
        "directory",
        $name,
        ["container_id" => (int) $row["container_id"]],
        $did
    )
) {
    json_response(["error" => __("name_duplicate_directory")], 409);
}

$encName = $crypto->encryptMeta($name, $masterKey, CryptoService::rowAad("directory", $did));

$sets = "name_enc = :ne, name_iv = :ni, name_tag = :nt";
$params = [":ne" => $encName["enc"], ":ni" => $encName["iv"], ":nt" => $encName["tag"], ":id" => $did];
if ($desc !== null) {
    $encDesc = $desc !== "" ? $crypto->encryptMeta($desc, $masterKey, CryptoService::directoryDescAad($did)) : null;
    $sets .= ", desc_enc = :de, desc_iv = :di, desc_tag = :dt";
    $params += [":de" => $encDesc["enc"] ?? null, ":di" => $encDesc["iv"] ?? null, ":dt" => $encDesc["tag"] ?? null];
}
$db->prepare("UPDATE " . DB_PREFIX . "directories SET {$sets} WHERE id = :id")->execute($params);

log_activity(
    $db,
    $nameChanged && $desc !== null
        ? "directory.rename_update"
        : ($nameChanged
            ? "directory.rename"
            : "directory.update"),
    "directory",
    $did,
    $nameChanged ? activity_prev_name_enc($row, CryptoService::rowAad("directory", $did)) : []
);

// Sync the shares' encrypted copies. $desc null means "not in this request":
// the share's copy stays untouched rather than being wiped.
$shareSvc->syncDirectoryShareMeta($did, $masterKey, $name, $desc);

json_response(["renamed" => true, "name" => $name]);
