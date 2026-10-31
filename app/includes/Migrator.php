<?php

declare(strict_types=1);

namespace AESBin;

use Closure;
use PDO;
use ReflectionFunction;
use RuntimeException;

defined("AESBIN_APP") || exit;

/**
 * app/includes/Migrator.php — engine for the steps declared in app/migrations.php
 *
 * Discovery, validation, chunked execution and the schema_version bump; the
 * steps themselves live in app/migrations.php. Reached only from
 * app/actions/system/migrate.php, which requires an elevated session, so the
 * master key is always available to run().
 *
 * run() takes (PDO, string) for a one-shot step or (PDO, string, int) for a
 * chunked one. Full contract in docs/maintaining.md.
 */

final class Migrator
{
    /**
     * In app/, outside public/ like config.php, so it has no URL; also guarded
     * inside the file. Not in app/setup/, which holds only the installer and
     * its reconnect step.
     */
    public const FILE = AESBIN_APP_DIR . "/migrations.php";

    /** @var array<int, array{description?:string, run:callable}>|null */
    private static ?array $steps = null;
    private static ?array $rawSteps = null;

    /**
     * Load app/migrations.php once per request without validating the callables.
     * bootstrap.php calls targetVersion() on every request, and validating
     * there would cost a ReflectionFunction per step. pending() and step() go
     * through steps(), so a malformed step is still caught before it runs.
     */
    private static function rawSteps(): array
    {
        if (self::$rawSteps !== null) {
            return self::$rawSteps;
        }

        $data = is_file(self::FILE) ? require self::FILE : [];
        if (!is_array($data)) {
            throw new RuntimeException("app/migrations.php must return an array.");
        }
        ksort($data);
        self::$rawSteps = $data;
        return self::$rawSteps;
    }

    /**
     * Load app/migrations.php and validate every step, so a malformed one fails
     * before anything runs. Used by the paths that list or execute migrations.
     */
    private static function steps(): array
    {
        if (self::$steps !== null) {
            return self::$steps;
        }

        $data = self::rawSteps();

        foreach ($data as $version => $spec) {
            if (!is_int($version) || $version < 2) {
                throw new RuntimeException(
                    "app/migrations.php: every step must be keyed by an integer schema " .
                        "version, 2 or above (1 is the baseline schema in " .
                        "app/setup/schema-sql.php); found key " .
                        var_export($version, true) .
                        "."
                );
            }
            if (!is_array($spec) || !isset($spec["run"]) || !is_callable($spec["run"])) {
                throw new RuntimeException(
                    "app/migrations.php: step {$version} does not declare a callable \"run\"."
                );
            }
            // (PDO, string) for a one-shot step, (PDO, string, int) for a chunked one.
            $arity = (new ReflectionFunction(Closure::fromCallable($spec["run"])))->getNumberOfParameters();
            if ($arity < 2 || $arity > 3) {
                throw new RuntimeException(
                    "app/migrations.php: step {$version}'s \"run\" takes {$arity} " .
                        "parameters; it must be 2 (PDO \$db, string \$masterKey) " .
                        "for a one-shot step, or 3 (…, int \$offset) for a " .
                        "chunked one."
                );
            }
        }

        self::$steps = $data;
        return self::$steps;
    }

    /**
     * The schema version this code expects: the highest key in app/migrations.php,
     * or 1 (the baseline in app/setup/schema-sql.php). Derived, so it cannot
     * disagree with the steps.
     */
    public static function targetVersion(): int
    {
        $versions = array_keys(self::rawSteps());
        return $versions ? max($versions) : 1;
    }

    /**
     * Schema version recorded in the database.
     *
     * A missing row means "structure unknown", not version 0. bootstrap.php
     * already refuses that case with a 503; this throws in case the gate is
     * bypassed, since 0 would make every step pending and run them against
     * unconfirmed structure.
     */
    public static function currentVersion(PDO $db): int
    {
        $v = $db
            ->query(
                "SELECT meta_value FROM " . DB_PREFIX . "meta WHERE meta_key = 'schema_version'"
            )
            ->fetchColumn();
        if ($v === false) {
            // migrate.php returns RuntimeException messages to the client
            // verbatim, and DB_PREFIX must not reach it, so the table name goes
            // to error_log only.
            error_log(
                "[AESBin] No schema_version row found in " . DB_PREFIX . "meta. " .
                    "Structure unknown, refusing to guess a version. See " .
                    "the Maintaining guide in the repository, and bootstrap.php's schema gate."
            );
            throw new RuntimeException(
                "Database structure unknown: no schema_version row found. See the server error log for details."
            );
        }
        return (int) $v;
    }

    /**
     * Every step above $from, ascending (steps() is ksorted). A jump of several
     * versions needs no special case: step() applies them in order.
     *
     * @return array<int, array{version:int, description:string}>
     */
    public static function pending(int $from): array
    {
        $out = [];
        foreach (self::steps() as $version => $spec) {
            if ($version <= $from) {
                continue;
            }
            $out[] = [
                "version" => $version,
                "description" => (string) ($spec["description"] ?? "Schema version {$version}"),
            ];
        }
        return $out;
    }

    /**
     * Run one chunk of the lowest pending step. The caller loops, so progress
     * is visible and a failure stops at a known point.
     *
     * @return array{done:bool, next:int, version:int, message:string, all_done:bool}
     */
    public static function step(PDO $db, string $masterKey, int $offset): array
    {
        // One run at a time: the compare-and-swap below guards only the
        // version bump, and two tabs would otherwise both execute the body
        // of the same step. Held until the request ends.
        db_advisory_lock($db, "aesbin_migrate", "Another migration run is in progress.");
        $current = self::currentVersion($db);
        $pending = self::pending($current);

        if (!$pending) {
            // Nothing pending while the database is behind: no step provides the
            // target version. A broken deployment; "all done" would leave the
            // banner offering a button that does nothing.
            $target = self::targetVersion();
            if ($current < $target) {
                // Translated, unlike the other messages here: it is shown in
                // the dashboard as a diagnosis, not a crash.
                throw new RuntimeException(
                    __("migrate_err_no_files", [
                        "target" => $target,
                        "current" => $current,
                    ])
                );
            }
            return [
                "done" => true,
                "next" => 0,
                "version" => $current,
                "message" => "Nothing to do.",
                "all_done" => true,
            ];
        }

        $next = $pending[0];
        $spec = self::steps()[$next["version"]];

        // The signature is the declaration:
        //   2 params (PDO $db, string $masterKey): returns nothing; not throwing
        //   means done. Most steps are a single ALTER TABLE.
        //   3 params (…, int $offset): returns array{done,next,message}, for a
        //   step with too many rows to finish within max_execution_time.
        $arity = (new ReflectionFunction(Closure::fromCallable($spec["run"])))->getNumberOfParameters();

        if ($arity <= 2) {
            ($spec["run"])($db, $masterKey);
            $done = true;
            $message = (string) ($spec["description"] ?? "");
            $nextOffset = 0;
        } else {
            $result = ($spec["run"])($db, $masterKey, $offset);
            $done = (bool) ($result["done"] ?? false);
            $message = (string) ($result["message"] ?? "");
            $nextOffset = (int) ($result["next"] ?? 0);
        }

        if ($done) {
            // The WHERE on the previous value stops two concurrent runs from
            // both advancing the counter.
            $stmt = $db->prepare(
                "UPDATE " . DB_PREFIX . "meta
                    SET meta_value = :new
                  WHERE meta_key = 'schema_version' AND meta_value = :old"
            );
            $stmt->execute([
                ":new" => (string) $next["version"],
                ":old" => (string) $current,
            ]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException(
                    "Could not advance schema_version from {$current} to " .
                        $next["version"] .
                        "; another run may have applied it already."
                );
            }
            log_activity($db, "system.migrate", null, null, [
                "from" => $current,
                "to" => $next["version"],
            ]);
        }

        return [
            "done" => $done,
            "next" => $nextOffset,
            "version" => $next["version"],
            "message" => $message,
            "all_done" => $done && count($pending) === 1,
        ];
    }
}
