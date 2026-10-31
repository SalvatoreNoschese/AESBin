<?php

/**
 * app/actions/system/migrate.php
 *
 * GET  /api/system/migrate: what is outstanding
 * POST /api/system/migrate: run one chunk of the lowest pending migration
 * Auth    : requireElevated()
 * Body    : POST: {offset?}
 * Returns : GET: {db_schema, code_schema, pending}
 *           POST: {done, next, version, message, all_done}
 *
 * Runs inside the application so DB_PREFIX, AESBIN_FILES and the session's
 * MASTER_KEY are already resolved, rather than re-derived by a standalone
 * script.
 *
 * Elevated: a migration rewrites structure and sometimes data, with no undo.
 */

declare(strict_types=1);

use AESBin\Migrator;

defined("AESBIN_APP") || exit;

$auth->requireElevated();

/**
 * Run $work, turning a failure into the JSON the dashboard expects.
 *
 * PDOException must be caught before RuntimeException, which it extends: its
 * message (raw SQL, DB_PREFIX) must never reach the client, while a Migrator
 * RuntimeException is written for the dashboard and passed through.
 */
$run = function (callable $work): never {
    try {
        json_response($work());
    } catch (PDOException $e) {
        error_log("[AESBin] migration DB error: " . $e->getMessage());
        $error = __("err_migration_failed");
    } catch (RuntimeException $e) {
        error_log("[AESBin] migration failed: " . $e->getMessage());
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log("[AESBin] migration failed: " . get_class($e) . ": " . $e->getMessage());
        $error = __("err_migration_failed");
    }
    json_response(["error" => $error, "code" => "migration_failed"], 500);
};

if ($method === "GET") {
    $run(function () use ($db): array {
        $current = Migrator::currentVersion($db);
        return [
            "db_schema" => $current,
            "code_schema" => Migrator::targetVersion(),
            "pending" => Migrator::pending($current),
        ];
    });
}

$body = json_body();
$offset = body_int($body["offset"] ?? 0);
if ($offset === null || $offset < 0) {
    json_response(["error" => __("err_field_invalid", ["field" => "offset"])], 400);
}

// Every step gets the key whether or not it uses it; see app/includes/Migrator.php.
$masterKey = $auth->sessionMasterKey();

$run(function () use ($db, $masterKey, $offset): array {
    return Migrator::step($db, $masterKey, $offset);
});
