<?php

/**
 * app/actions/file/write.php
 *
 * POST /api/files/:id/write
 * Auth    : requireAuth()
 * Body    : {content_b64} | {content}
 * Returns : {written: true}
 *
 * Overwrite a file's content (inline text editor). Re-encrypts the blob.
 *
 * content_b64 is the new content as base64 of its bytes, which is what the
 * dashboard sends: a file that is not UTF-8 (Windows-1252, UTF-16) is stored
 * back byte for byte, and a JSON string could not carry it. content, a plain
 * string and therefore UTF-8, stays for other clients.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();
$masterKey = $auth->sessionMasterKey();

$body = json_body();
// normalize_body_field(): otherwise {"content": ["x"]} would be written as
// the literal "Array".
if (isset($body["content_b64"])) {
    normalize_body_field($body, "content_b64");
    // Strict: a character outside the base64 alphabet is refused, not skipped.
    $content = base64_decode($body["content_b64"], true);
    if ($content === false) {
        json_response(["error" => __("err_field_invalid", ["field" => "content_b64"])], 400);
    }
} elseif (isset($body["content"])) {
    normalize_body_field($body, "content");
    $content = $body["content"];
} else {
    json_response(["error" => __("err_content_required")], 400);
}
if (trim($content) === "") {
    json_response(["error" => __("write_empty")], 400);
}

// Same limits as an upload. The overwrite replaces the current size rather
// than adding to it.
$sizeStmt = $db->prepare("SELECT COALESCE(size, 0) FROM " . DB_PREFIX . "files WHERE id = :id");
$sizeStmt->execute([":id" => $fileId]);
enforce_size_limits($db, $config, strlen($content), (int) $sizeStmt->fetchColumn());

$fileSvc->writeContent($fileId, $content, $masterKey);
json_response(["written" => true]);
