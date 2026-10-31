<?php

/**
 * app/actions/share/refresh.php
 *
 * POST /api/shares/:id/refresh
 * Auth    : requireAuth()
 * Body    : —
 * Returns : {refreshed, new_files}
 *
 * Re-sync a directory share with its folder: add files that arrived since
 * and drop those that left. The URL is unchanged; to kill a link, revoke it.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();
// Before the lookup below, which would otherwise answer a bare cookie.
$masterKey = $auth->sessionMasterKey();

// $shareId is set by the router. refreshDirectoryShare() quietly does nothing
// for a share that is gone (it also runs after every upload), so a stale
// dashboard is answered here.
$exists = $db->prepare(
    "SELECT 1 FROM " . DB_PREFIX . "shares
      WHERE id = :id AND type = 'directory' AND (expires_at IS NULL OR expires_at > NOW())"
);
$exists->execute([":id" => $shareId]);
if (!$exists->fetchColumn()) {
    json_response(["error" => __("share_not_found")], 404);
}
$added = $shareSvc->refreshDirectoryShare($shareId, $masterKey);
json_response(["refreshed" => true, "new_files" => $added]);
