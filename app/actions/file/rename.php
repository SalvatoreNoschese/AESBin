<?php

/**
 * app/actions/file/rename.php
 *
 * POST /api/files/:id/rename
 * Auth    : requireAuth()
 * Body    : {name, description?}
 * Returns : {renamed, name, mime, adjusted}
 *
 * Rename a file and optionally update its description. `mime` may change
 * with the extension. `name` is the name stored after the call (a binary
 * keeps its own extension whatever is sent) and `renamed` whether it
 * changed; `name` is null when only the description was sent. `adjusted`
 * says why the name sent was not kept as typed, one of FILENAME_REASONS
 * (filename_clean()), or "" ("length" only on upload: a rename over the
 * limits is refused above).
 *
 * Existence and uniqueness are checked in FileService::rename(), against the
 * name actually written (after filename_clean() and extension handling), not
 * the name as sent.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();
$body = json_body();
require_fields($body, ["name"]);
$masterKey = $auth->sessionMasterKey();
$newName = trim($body["name"]);
$desc = body_description($body);

if ($newName === "") {
    json_response(["error" => __("file_name_empty")], 400);
}
// filename_clean() cuts a longer name silently; refuse it here, where the
// reason can be given. Both counts: 200 emoji are 800 bytes.
if (mb_strlen($newName) > FILENAME_MAX_CHARS || strlen($newName) > FILENAME_MAX_BYTES) {
    json_response([
        "error" => __("err_name_too_long", ["chars" => FILENAME_MAX_CHARS, "bytes" => FILENAME_MAX_BYTES]),
    ], 400);
}

$result = $fileSvc->rename($fileId, $masterKey, $newName, $desc);
json_response($result);
