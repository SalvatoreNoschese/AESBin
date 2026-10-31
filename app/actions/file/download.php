<?php

/**
 * app/actions/file/download.php
 *
 * GET /api/files/:id/download?inline=1&intent=preview|download
 * Auth    : requireAuth()
 * Body    : —
 * Returns : binary stream
 *
 * Decrypt a file and send it as a download or inline preview.
 *
 * Logged even for the owner: with a compromised session, file access is the
 * signal worth noticing. Deduped against the event's most recent item (see
 * recent_duplicate_activity()), so reopening one file does not flood the log
 * but alternating files still logs each. `intent` separates a preview from a
 * download, as on the share side.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();
$inline = isset($_GET["inline"]) && $_GET["inline"] === "1";
$intent = ($_GET["intent"] ?? "download") === "preview" ? "preview" : "download";

$fileSvc->download($fileId, $auth->sessionMasterKey(), $inline);

$event = $intent === "preview" ? "file.preview" : "file.download";
if (!recent_duplicate_activity($db, $event, request_ip(), ACTIVITY_DEDUP_WINDOW, "file", $fileId)) {
    log_activity($db, $event, "file", $fileId);
}
