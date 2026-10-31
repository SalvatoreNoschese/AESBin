<?php

/**
 * app/actions/container/create.php
 *
 * POST /api/containers
 * Auth    : requireAuth()
 * Body    : {name}
 * Returns : {id, name}
 *
 * Create a container (top-level namespace). The name is stored encrypted.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();
$body = json_body();
require_fields($body, ["name"]);
$name = trim($body["name"]);
if (!is_valid_display_name($name)) {
    json_response(["error" => __("err_invalid_name")], 400);
}

$masterKey = $auth->sessionMasterKey();

if (!is_name_unique($db, $crypto, $masterKey, "container", $name)) {
    json_response(["error" => __("name_duplicate_container")], 409);
}

$id = insert_named_row($db, $crypto, $masterKey, "container", $name);
log_activity($db, "container.create", "container", $id);
json_response(["id" => $id, "name" => $name], 201);
