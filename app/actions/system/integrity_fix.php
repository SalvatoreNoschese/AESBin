<?php

/**
 * app/actions/system/integrity_fix.php
 *
 * POST /api/system/integrity/fix
 * Auth    : requireElevated()
 * Body    : {action, ...}  action and its fields:
 *             remove_missing    {file_id}
 *             delete_orphan     {path, location}
 *             fix_misplaced     {file_id}
 *             delete_duplicate  {file_id, stray_location}
 *             delete_tmp_orphan {name, location}
 *             add_missing_check {constraint}
 * Returns : {fixed: true}
 *
 * Fixes the drift integrity_check.php reports. multi_user has no fix here:
 * deleting the wrong row could destroy the only wrapped MASTER_KEY.
 *
 * The report is a snapshot, so every action re-checks the current state and
 * refuses (409) anything that no longer matches.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

// Elevated: every branch permanently deletes a row or a blob, or alters the
// schema. api.js handles the 403 reauth_required with the password modal.
$auth->requireElevated();

$body = json_body();
require_fields($body, ["action"]);
$action = $body["action"];

/** The row a file-level fix acts on, re-read now rather than trusted from the report. */
$loadFileRow = function (int $fileId) use ($db): array {
    $stmt = $db->prepare(
        "SELECT storage_name, trashed_at FROM " . DB_PREFIX . "files WHERE id = :id LIMIT 1"
    );
    $stmt->execute([":id" => $fileId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(["error" => __("file_not_found")], 404);
    }
    return $row;
};

switch ($action) {
    case "remove_missing": {
        require_fields($body, ["file_id"]);
        $fileId = body_id($body, "file_id");

        $row = $loadFileRow($fileId);

        // Only from the storage folder recorded for this installation:
        // elsewhere the files are more likely in the wrong place than lost
        // (the report's storage_suspect), and deleting rows would destroy
        // wrapped DEKs that fixing config.php makes whole again.
        if (!storage_path_confirmed($db)) {
            json_response(
                ["error" => __("integrity_remove_refused"), "code" => "storage_implausible"],
                409
            );
        }

        // Both trees, and any shard folder: a blob that turned up since the
        // report is misplaced, not missing, and deleting its row would
        // destroy the only wrapped DEK for it.
        if (
            file_exists(storage_path($row["storage_name"], false)) ||
            file_exists(storage_path($row["storage_name"], true)) ||
            storage_stray_blob((string) $row["storage_name"]) !== null
        ) {
            json_response(
                ["error" => __("integrity_no_longer_missing")],
                409
            );
        }

        delete_file_row($db, $fileId, "file_missing");

        log_activity($db, "integrity.remove_missing", "file", $fileId);
        break;
    }

    case "delete_orphan": {
        require_fields($body, ["path", "location"]);
        $relPath = (string) $body["path"];
        $location = $body["location"];

        // "xx/name.bin" as integrity_check.php reports it: one shard folder
        // (no leading dot, so never ".."), one file, nothing else. The path
        // below therefore never leaves the storage tree.
        if (preg_match('#^[^./\x5c][^/\x5c]/[^./\x5c][^/\x5c]*\.bin\z#', $relPath) !== 1) {
            json_response(["error" => __("err_invalid_path")], 400);
        }
        if (!in_array($location, ["active", "trash"], true)) {
            json_response(["error" => __("err_invalid_location")], 400);
        }
        $isTrash = $location === "trash";
        $path = ($isTrash ? AESBIN_TRASH : AESBIN_FILES) . "/" . $relPath;
        if (!is_file($path)) {
            json_response(["error" => __("integrity_no_longer_orphan")], 409);
        }

        // Never the copy a row reads, and never the only copy of a listed
        // file: a stray of one goes only while the right copy is in place.
        $storageName = basename($relPath, ".bin");
        $stmt = $db->prepare(
            "SELECT trashed_at FROM " . DB_PREFIX . "files WHERE storage_name = :sn LIMIT 1"
        );
        $stmt->execute([":sn" => $storageName]);
        $row = $stmt->fetch();
        if ($row) {
            $own = storage_path($storageName, $row["trashed_at"] !== null);
            if ($path === $own || !is_file($own)) {
                json_response(["error" => __("integrity_no_longer_orphan")], 409);
            }
        }

        destroy_blob($path);

        log_activity($db, "integrity.delete_orphan", "file", null, [
            "storage_name_prefix" => substr($storageName, 0, 4),
            "location" => $location,
        ]);
        break;
    }

    case "fix_misplaced": {
        require_fields($body, ["file_id"]);
        $fileId = body_id($body, "file_id");

        $row = $loadFileRow($fileId);

        $storageName = (string) $row["storage_name"];
        $shouldBeTrash = $row["trashed_at"] !== null;
        $expectedPath = storage_path($storageName, $shouldBeTrash);
        // On the other side of the trash, or in a wrong shard folder.
        $wrongPath = storage_path($storageName, !$shouldBeTrash);
        if (!file_exists($wrongPath)) {
            $wrongPath = storage_stray_blob($storageName)[0] ?? "";
        }

        if (file_exists($expectedPath) || $wrongPath === "") {
            json_response(["error" => __("integrity_no_longer_misplaced")], 409);
        }

        ensure_storage_dir($storageName, $shouldBeTrash);
        if (!rename($wrongPath, $expectedPath)) {
            json_response(["error" => __("err_move_failed")], 500);
        }

        log_activity($db, "integrity.fix_misplaced", "file", $fileId);
        break;
    }

    case "delete_duplicate": {
        require_fields($body, ["file_id", "stray_location"]);
        $fileId = body_id($body, "file_id");
        $strayLocation = $body["stray_location"];

        if (!in_array($strayLocation, ["active", "trash"], true)) {
            json_response(["error" => __("err_invalid_stray_location")], 400);
        }
        $strayIsTrash = $strayLocation === "trash";

        $row = $loadFileRow($fileId);

        $storageName = $row["storage_name"];
        $rowIsTrashed = $row["trashed_at"] !== null;
        // Only the copy the row does not expect may go; never the real one.
        $expectedStrayLocation = $rowIsTrashed ? "active" : "trash";
        if ($strayLocation !== $expectedStrayLocation) {
            json_response(
                ["error" => __("integrity_no_longer_duplicate")],
                409
            );
        }

        $legitPath = storage_path($storageName, $rowIsTrashed);
        $strayPath = storage_path($storageName, $strayIsTrash);
        if (!file_exists($legitPath) || !file_exists($strayPath)) {
            json_response(
                ["error" => __("integrity_no_longer_duplicate")],
                409
            );
        }

        destroy_blob($strayPath);

        log_activity($db, "integrity.delete_duplicate", "file", $fileId, [
            "stray_location" => $strayLocation,
        ]);
        break;
    }

    case "delete_tmp_orphan": {
        require_fields($body, ["name", "location"]);
        $name = $body["name"];
        $location = $body["location"];

        // The shape integrity_check.php reports. No separator can match it,
        // so the path below never leaves the storage tree.
        if (preg_match(STAGING_FILE_PATTERN, $name) !== 1) {
            json_response(["error" => __("err_invalid_name")], 400);
        }
        if (!in_array($location, ["active", "trash"], true)) {
            json_response(["error" => __("err_invalid_location")], 400);
        }

        // "{storage_name}.bin" is the first 36 characters.
        $path = storage_path(substr($name, 0, 32), $location === "trash") . substr($name, 36);

        // Same age floor as the report: a write started after the scan may
        // still be in flight.
        $mtime = @filemtime($path);
        if ($mtime === false || time() - $mtime < STAGING_ORPHAN_MIN_AGE) {
            json_response(["error" => __("integrity_no_longer_orphan")], 409);
        }

        destroy_blob($path);

        log_activity($db, "integrity.delete_tmp_orphan", null, null, [
            "location" => $location,
        ]);
        break;
    }

    case "add_missing_check": {
        require_fields($body, ["constraint"]);
        $constraint = $body["constraint"];

        // Only selects an entry; the ALTER text never comes from the client.
        $fixes = schema_check_constraints();
        if (!isset($fixes[$constraint])) {
            json_response(["error" => __("err_unknown_action")], 400);
        }

        // Already present counts as success. The table is absent before
        // MySQL 8.0.16 / MariaDB 10.3.10 (see integrity_check.php): refuse
        // with 409 rather than 500.
        try {
            $check = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
                  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = :n"
            );
            $check->execute([":n" => $constraint]);
        } catch (PDOException) {
            json_response(["error" => __("integrity_check_unsupported")], 409);
        }
        if ((int) $check->fetchColumn() === 0) {
            // ADD CONSTRAINT validates existing rows, so chk_single_user
            // fails while a stray auth row exists (multi_user). Readable 409
            // instead of a 500.
            if ($constraint === DB_PREFIX . "chk_single_user") {
                $stray = $db
                    ->query(
                        "SELECT COUNT(*) FROM " . DB_PREFIX . "auth WHERE id != 1"
                    )
                    ->fetchColumn();
                if ((int) $stray > 0) {
                    json_response(
                        ["error" => __("integrity_check_blocked_multiuser")],
                        409
                    );
                }
            }
            $db->exec($fixes[$constraint]);
            log_activity($db, "integrity.add_missing_check", null, null, [
                "constraint" => $constraint,
            ]);
        }
        break;
    }

    default:
        json_response(["error" => __("err_unknown_action")], 400);
}

json_response(["fixed" => true]);
