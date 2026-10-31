<?php

/**
 * app/actions/directory/list.php
 *
 * GET /api/directories
 * Auth    : requireAuth()
 * Body    : —
 * Query   : container_id?  (all directories when absent)
 * Returns : {directories[]}
 *
 * List directories with decrypted names and descriptions.
 */

declare(strict_types=1);

use AESBin\CryptoService;

defined("AESBIN_APP") || exit;

$auth->requireAuth();
$masterKey = $auth->sessionMasterKey();

// Share figures:
//   share_active         the directory's own live links: the link icon, and
//                        their number on its badge.
//   share_active_inside  links on files inside it. Deleting the directory
//                        trashes those files and destroys their links, so the
//                        pre-delete warning must count them too.
//
// Comments stay out of the SQL: PDO scans the whole string for placeholders.
$sql =
    'SELECT d.id, d.container_id, d.name_enc, d.name_iv, d.name_tag,
               d.desc_enc, d.desc_iv, d.desc_tag, d.created_at,
               (SELECT COUNT(*) FROM ' .
    DB_PREFIX .
    'files f
                 WHERE f.directory_id = d.id AND f.trashed_at IS NULL) AS file_count,
               (SELECT COUNT(*) FROM ' .
    DB_PREFIX .
    "shares s
                 WHERE s.type = 'directory' AND s.target_id = d.id
                   AND (s.expires_at IS NULL OR s.expires_at > NOW())) AS share_active,
               (SELECT COUNT(*) FROM " .
    DB_PREFIX .
    "shares s2 JOIN " .
    DB_PREFIX .
    "files f2 ON f2.id = s2.target_id
                 WHERE s2.type = 'file' AND f2.directory_id = d.id
                   AND f2.trashed_at IS NULL
                   AND (s2.expires_at IS NULL OR s2.expires_at > NOW())) AS share_active_inside
          FROM " .
    DB_PREFIX .
    "directories d";
$params = [];
$cid = query_int("container_id");
if ($cid !== null) {
    $sql .= " WHERE d.container_id = :cid";
    $params[":cid"] = $cid;
}
$sql .= " ORDER BY d.created_at ASC, d.id ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);

$dirs = array_map(function ($r) use ($crypto, $masterKey) {
    return [
        "id" => (int) $r["id"],
        "container_id" => (int) $r["container_id"],
        "name" => $crypto->decryptColumn($r, "name", $masterKey, CryptoService::rowAad("directory", $r["id"]), __("name_unreadable")),
        "description" => $crypto->decryptColumn($r, "desc", $masterKey, CryptoService::directoryDescAad($r["id"])),
        "file_count" => (int) $r["file_count"],
        "is_shared" => !empty($r["share_active"]),
        "share_count" => (int) $r["share_active"],
        // Deleting would destroy at least one link. Drives the delete
        // warning, not the link icon.
        "has_shares" => !empty($r["share_active"]) ||
            !empty($r["share_active_inside"]),
        "created_at" => $r["created_at"],
    ];
}, $stmt->fetchAll());

json_response(["directories" => $dirs]);
