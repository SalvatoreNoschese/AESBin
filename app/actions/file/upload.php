<?php

/**
 * app/actions/file/upload.php
 *
 * POST /api/files/upload
 * Auth    : requireAuth()
 * Body    : multipart/form-data {file, container_id, directory_id,
 *           description?}
 * Returns : {file_id, container_id, directory_id, original_name, mime,
 *            corrected_from, extension_change, adjusted_from, adjusted,
 *            size, size_human, renamed}
 *
 * Upload and encrypt a file. Enforces the per-file limit and total quota.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();
// First: the cookie alone must not reach the quota figures below.
$masterKey = $auth->sessionMasterKey();

// A body larger than post_max_size makes PHP drop $_FILES/$_POST entirely.
if (
    empty($_FILES) &&
    empty($_POST) &&
    (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0
) {
    json_response(
        [
            "error" => __("upload_too_large", [
                // Binary: ini K/M/G suffixes are 1024-based, so "8M" shows
                // as "8.00 MB", not "8.39 MB".
                "max" => format_bytes_binary(php_upload_limit_bytes()),
            ]),
        ],
        413
    );
}
// A field named "file[]" arrives as arrays of names, sizes and errors.
if (empty($_FILES["file"]) || !is_string($_FILES["file"]["name"] ?? null)) {
    json_response(["error" => __("err_no_file_field")], 400);
}
if (empty($_POST["container_id"])) {
    json_response(["error" => __("err_container_id_required")], 400);
}
// A multipart field named "container_id[]" arrives as an array: body_id()
// refuses it. Files always live in a directory, so directory_id is required.
$containerId = body_id($_POST, "container_id");
$dirId = body_id($_POST, "directory_id");

enforce_size_limits($db, $config, (int) ($_FILES["file"]["size"] ?? 0));

// Optional, from "Create file": stored with the file, not by a second call.
$_post = $_POST;
$description = body_description($_post);
unset($_post);

$result = $fileSvc->upload($_FILES["file"], $containerId, $dirId, $masterKey, $description);
log_activity($db, "file.upload", "file", $result["file_id"]);

// Add the new file to the directory's active shares right away.
$shareSvc->autoRefreshForDirectory($dirId, $masterKey);

json_response($result, 201);
