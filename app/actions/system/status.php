<?php

/**
 * app/actions/system/status.php
 *
 * GET /api/system/status
 * Auth    : requireKeyHolder()
 * Body    : —
 * Returns : {aesbin_version, php_version, file_count, total_size,
 *            total_size_human, trash_count, quota_bytes, quota_human,
 *            passkey_count, db_schema_version, db_version}
 *
 * Instance facts for the dashboard's filesystem card. total_size counts the
 * trash too: it is what the quota is measured against.
 */

declare(strict_types=1);

use AESBin\Migrator;
use AESBin\Requirements;

defined("AESBIN_APP") || exit;

$auth->requireKeyHolder();

$fileCount = (int) $db
    ->query("SELECT COUNT(*) FROM " . DB_PREFIX . "files WHERE trashed_at IS NULL")
    ->fetchColumn();

// Shared with the header badge.
$trash = trash_file_count($db);

// The same figures enforce_size_limits() compares, so the card and the
// refusal agree: trashed blobs count, and a missing key reads as the default.
$quota = setting_int($config, "max_total_bytes");
$totalSize = storage_used_bytes($db);

$passkeyCount = count(webauthn_data($db)["credentials"]);

// bootstrap.php has already checked the schema marker, so this should not
// throw; if it does, show "—" rather than break the card.
try {
    $dbSchemaVersion = Migrator::currentVersion($db);
} catch (Throwable) {
    $dbSchemaVersion = null;
}

try {
    $dbServer = Requirements::databaseServer($db);
    $dbVersion = $dbServer["version"] !== "" ? $dbServer["name"] : null;
} catch (Throwable) {
    $dbVersion = null;
}

json_response([
    "aesbin_version" => AESBIN_VERSION,
    "php_version" => Requirements::phpVersion(),
    "file_count" => $fileCount,
    "total_size" => $totalSize,
    // Binary, to match quota_human: the quota is set in GB of 1024^3
    // (see format_bytes_binary()). File sizes elsewhere are decimal.
    "total_size_human" => format_bytes_binary($totalSize),
    "trash_count" => $trash,
    "quota_bytes" => $quota > 0 ? $quota : null,
    "quota_human" => $quota > 0 ? format_bytes_binary($quota) : null,
    "passkey_count" => $passkeyCount,
    "db_schema_version" => $dbSchemaVersion,
    "db_version" => $dbVersion,
]);
