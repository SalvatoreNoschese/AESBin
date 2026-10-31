<?php

/**
 * app/actions/system/integrity_check.php
 *
 * GET /api/system/integrity
 * Auth    : requireAuth()
 * Body    : —
 * Returns : {checked_at, ok, missing[], orphans[], misplaced[], duplicates[],
 *            tmp_orphans[], schema_checks: {missing[], wrong_storage[],
 *            supported}, multi_user, storage_suspect, storage_path, counts}
 *
 * Reconciles the `files` table with the blobs on disk (active + trash) to
 * catch drift from changes made outside the app: rows without blobs, blobs
 * without rows, blobs in the wrong folder, blobs in both, stale .tmp staging
 * files. Also schema checks: missing named CHECK constraints (fixable), wrong
 * engine/collation, and more than one {P}auth row (never auto-fixed:
 * deleting the wrong one could destroy the only wrapped MASTER_KEY).
 *
 * Never cached: the report holds decrypted file names, and caching them on
 * disk would defeat encryption at rest.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();

$masterKey = $auth->sessionMasterKey();

// ── DB side: every row, split by trashed state ────────────────────────────────
$stmt = $db->query(
    "SELECT id, storage_name, trashed_at, size, name_enc, name_iv, name_tag
       FROM " .
        DB_PREFIX .
        "files"
);
$dbActive = []; // storage_name => row
$dbTrashed = []; // storage_name => row
foreach ($stmt->fetchAll() as $row) {
    if ($row["trashed_at"] === null) {
        $dbActive[$row["storage_name"]] = $row;
    } else {
        $dbTrashed[$row["storage_name"]] = $row;
    }
}

// ── Disk side: every .bin blob actually present ───────────────────────────────
// Pattern "??" matches exactly the 2-character shard dirs, never "_trash".
// A blob counts as present only at the path storage_path() reads it from:
// a 32-hex name in the shard folder of its first two characters. Anything
// else (a blob copied into the wrong shard, a file with another name) is a
// stray, listed by its path in the tree, since downloads would never find it.
$diskActive = []; // storage_name => bytes on disk
$diskTrashed = [];
$strays = []; // [location, path "xx/name.bin", name, bytes]
foreach (["active" => AESBIN_FILES, "trash" => AESBIN_TRASH] as $location => $root) {
    foreach (glob($root . "/??/*.bin") ?: [] as $path) {
        $file = basename($path);
        $name = basename($path, ".bin");
        $shard = basename(dirname($path));
        $bytes = filesize($path) ?: 0;
        if (preg_match('/^[a-f0-9]{32}\z/', $name) === 1 && substr($name, 0, 2) === $shard) {
            if ($location === "active") {
                $diskActive[$name] = $bytes;
            } else {
                $diskTrashed[$name] = $bytes;
            }
        } else {
            $strays[] = [$location, $shard . "/" . $file, $name, $bytes];
        }
    }
}

// ── Orphaned .tmp staging files ───────────────────────────────────────────────
// writeContent() stages beside the blob before rename(), so an interrupted
// write leaves the stage file behind (see STAGING_FILE_PATTERN). Reported by
// name and tree, never by server path.
$tmpOrphans = [];
foreach (["active" => AESBIN_FILES, "trash" => AESBIN_TRASH] as $location => $root) {
    foreach (glob($root . "/??/*.tmp") ?: [] as $path) {
        $name = basename($path);
        $mtime = filemtime($path);
        if (
            preg_match(STAGING_FILE_PATTERN, $name) === 1 &&
            $mtime !== false &&
            time() - $mtime >= STAGING_ORPHAN_MIN_AGE
        ) {
            $tmpOrphans[] = [
                "name" => $name,
                "location" => $location,
                "size" => filesize($path) ?: 0,
                "age_seconds" => time() - $mtime,
            ];
        }
    }
}

// ── Reconcile ─────────────────────────────────────────────────────────────────
// A row whose blob sits only in a wrong shard folder is misplaced, not
// missing: the first such copy, by storage_name.
$strayOf = [];
foreach ($strays as [$location, $relPath, $name]) {
    if ((isset($dbActive[$name]) || isset($dbTrashed[$name])) &&
        !isset($diskActive[$name]) && !isset($diskTrashed[$name]) && !isset($strayOf[$name])) {
        $strayOf[$name] = [$location, $relPath];
    }
}

$missing = [];
foreach ([false => $dbActive, true => $dbTrashed] as $trashed => $rows) {
    foreach ($rows as $name => $row) {
        if (!isset($diskActive[$name]) && !isset($diskTrashed[$name]) && !isset($strayOf[$name])) {
            $missing[] = [
                "file_id" => (int) $row["id"],
                "name" => $fileSvc->decryptName($row, $masterKey),
                "storage_name" => $name,
                "size" => (int) $row["size"],
                "trashed" => (bool) $trashed,
            ];
        }
    }
}

// Every orphan carries its path in the tree, which integrity_fix.php
// deletes by: for a stray, the storage_name alone would point elsewhere.
$orphans = [];
foreach ($diskActive as $name => $bytes) {
    if (!isset($dbActive[$name]) && !isset($dbTrashed[$name])) {
        $orphans[] = [
            "storage_name" => $name,
            "path" => substr($name, 0, 2) . "/" . $name . ".bin",
            "size" => $bytes,
            "location" => "active",
        ];
    }
}
foreach ($diskTrashed as $name => $bytes) {
    if (!isset($dbTrashed[$name]) && !isset($dbActive[$name])) {
        $orphans[] = [
            "storage_name" => $name,
            "path" => substr($name, 0, 2) . "/" . $name . ".bin",
            "size" => $bytes,
            "location" => "trash",
        ];
    }
}
// A stray that is not the only copy of a listed file: removable like any
// orphan (integrity_fix.php checks again that the right copy is there).
foreach ($strays as [$location, $relPath, $name, $bytes]) {
    if (($strayOf[$name] ?? null) !== [$location, $relPath]) {
        $orphans[] = ["storage_name" => $name, "path" => $relPath, "size" => $bytes, "location" => $location];
    }
}

$misplaced = [];
// In a wrong shard folder: moved back to where storage_path() reads it.
foreach ($strayOf as $name => [$location, $relPath]) {
    $row = $dbActive[$name] ?? $dbTrashed[$name];
    $misplaced[] = [
        "file_id" => (int) $row["id"],
        "name" => $fileSvc->decryptName($row, $masterKey),
        "storage_name" => $name,
        "expected" => isset($dbActive[$name]) ? "active" : "trash",
        "found_in" => $location,
        "found_path" => $relPath,
    ];
}
foreach ($dbActive as $name => $row) {
    if (!isset($diskActive[$name]) && isset($diskTrashed[$name])) {
        $misplaced[] = [
            "file_id" => (int) $row["id"],
            "name" => $fileSvc->decryptName($row, $masterKey),
            "storage_name" => $name,
            "expected" => "active",
            "found_in" => "trash",
        ];
    }
}
foreach ($dbTrashed as $name => $row) {
    if (!isset($diskTrashed[$name]) && isset($diskActive[$name])) {
        $misplaced[] = [
            "file_id" => (int) $row["id"],
            "name" => $fileSvc->decryptName($row, $masterKey),
            "storage_name" => $name,
            "expected" => "trash",
            "found_in" => "active",
        ];
    }
}

// ── Duplicates: same storage_name present in BOTH active and trash ────────────
// The row is satisfied either way, so missing/misplaced cannot see this. The
// copy in the location the row does not expect is the stray.
$duplicates = [];
foreach (array_intersect_key($diskActive, $diskTrashed) as $name => $_) {
    if (isset($dbActive[$name])) {
        $row = $dbActive[$name];
        $strayLocation = "trash"; // row says active; trash copy is the stray one
    } elseif (isset($dbTrashed[$name])) {
        $row = $dbTrashed[$name];
        $strayLocation = "active"; // row says trashed; active copy is the stray one
    } else {
        // No row at all: already reported as two orphans above.
        continue;
    }
    $duplicates[] = [
        "file_id" => (int) $row["id"],
        "name" => $fileSvc->decryptName($row, $masterKey),
        "storage_name" => $name,
        "stray_location" => $strayLocation,
        "stray_size" => $strayLocation === "trash"
            ? $diskTrashed[$name]
            : $diskActive[$name],
    ];
}

// ── Schema drift: named CHECK constraints from schema-sql.php ─────────────────
// Diffed against schema_check_constraints(). information_schema.
// CHECK_CONSTRAINTS exists only from MySQL 8.0.16 / MariaDB 10.3.10, newer
// than the supported minimum (5.7 / 10.3), and MySQL 5.7 ignores CHECK
// clauses anyway. On older servers the result is "cannot verify", not
// "missing": a Fix button would run an ALTER the server silently discards.
$schemaChecksSupported = true;
$missingChecks = [];

// Engine/collation drift, see the note on "wrong_storage" below.
$wrongStorage = [];
try {
    $stmt = $db->prepare(
        "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE :pfx"
    );
    // Escaped: "_" is a LIKE wildcard, and another installation's tables
    // could match.
    $stmt->execute([":pfx" => addcslashes(DB_PREFIX, "%_\\") . "%"]);
    foreach ($stmt->fetchAll() as $t) {
        $bad = [];
        if (strcasecmp((string) $t["ENGINE"], "InnoDB") !== 0) {
            $bad[] = (string) $t["ENGINE"];
        }
        if (strcasecmp((string) $t["TABLE_COLLATION"], "utf8mb4_unicode_ci") !== 0) {
            $bad[] = (string) $t["TABLE_COLLATION"];
        }
        if ($bad) {
            $wrongStorage[] = $t["TABLE_NAME"] . " (" . implode(", ", $bad) . ")";
        }
    }
} catch (PDOException) {
    // Skip rather than fail the whole report.
}
try {
    $existingChecks = array_column(
        $db
            ->query(
                "SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
                  WHERE CONSTRAINT_SCHEMA = DATABASE()"
            )
            ->fetchAll(),
        "CONSTRAINT_NAME"
    );
    $missingChecks = array_values(array_diff(array_keys(schema_check_constraints()), $existingChecks));
} catch (PDOException) {
    $schemaChecksSupported = false;
}

// ── Schema drift: more than one row in {P}auth ────────────────────────────────
// Impossible while chk_single_user exists. Detection only: which row to
// delete is not this script's call (see integrity_fix.php).
$authRows = (int) $db->query("SELECT COUNT(*) AS c FROM " . DB_PREFIX . "auth")->fetch()["c"];

// ── Files missing while storage_path is not the recorded one ──────────────────
// Almost certainly a wrong storage_path (mistyped at reconnect, or a folder
// moved by hand), not a loss. Reported with the path so the banner names the
// fix, and integrity_fix.php refuses to remove rows in that state (see
// storage_path_confirmed()). Rows and blobs are intact.
$storageSuspect = $missing !== [] && !storage_path_confirmed($db);

$result = [
    "checked_at" => gmdate("c"),
    "ok" =>
        !$missing &&
        !$orphans &&
        !$misplaced &&
        !$duplicates &&
        !$tmpOrphans &&
        !$missingChecks &&
        !$wrongStorage &&
        $authRows <= 1,
    "missing" => $missing,
    "orphans" => $orphans,
    "misplaced" => $misplaced,
    "duplicates" => $duplicates,
    "tmp_orphans" => $tmpOrphans,
    "schema_checks" => [
        "missing" => $missingChecks,
        // Engine or collation differs from the schema. A restore hazard:
        // phpMyAdmin's export drops ENGINE/CHARSET from tables with a CHECK
        // constraint, so a re-import gets the server defaults. Never
        // auto-fixed: CONVERT TO CHARACTER SET rewrites every row and can
        // change column definitions where an index hits its length limit.
        "wrong_storage" => $wrongStorage,
        // false without information_schema.CHECK_CONSTRAINTS (see above).
        "supported" => $schemaChecksSupported,
    ],
    "multi_user" => $authRows > 1 ? $authRows : null,
    "storage_suspect" => $storageSuspect,
    "storage_path" => AESBIN_FILES,
    "counts" => [
        "db_active" => count($dbActive),
        "db_trashed" => count($dbTrashed),
        "disk_active" => count($diskActive),
        "disk_trashed" => count($diskTrashed),
        "missing" => count($missing),
        "orphans" => count($orphans),
        "misplaced" => count($misplaced),
        "duplicates" => count($duplicates),
        "tmp_orphans" => count($tmpOrphans),
        "schema_missing" => count($missingChecks) + count($wrongStorage),
    ],
];

json_response($result);
