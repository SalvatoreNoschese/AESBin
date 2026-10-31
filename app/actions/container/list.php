<?php

/**
 * app/actions/container/list.php
 *
 * GET /api/containers
 * Auth    : requireAuth()
 * Body    : —
 * Returns : {containers[]}
 *
 * List all containers with decrypted names.
 */

declare(strict_types=1);

use AESBin\CryptoService;

defined("AESBIN_APP") || exit;

$auth->requireAuth();
$masterKey = $auth->sessionMasterKey();

$stmt = $db->prepare(
    "SELECT id, name_enc, name_iv, name_tag, created_at FROM " .
        DB_PREFIX .
        "containers ORDER BY created_at ASC, id ASC"
);
$stmt->execute();
$rows = $stmt->fetchAll();

$containers = array_map(function ($r) use ($crypto, $masterKey) {
    return [
        "id" => (int) $r["id"],
        "name" => $crypto->decryptColumn($r, "name", $masterKey, CryptoService::rowAad("container", $r["id"]), __("name_unreadable")),
        "created_at" => $r["created_at"],
    ];
}, $rows);

json_response(["containers" => $containers]);
