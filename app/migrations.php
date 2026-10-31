<?php

/**
 * app/migrations.php — database migration steps, one entry per schema version,
 * applied by app/includes/Migrator.php. Returns data and runs nothing on its own.
 * Empty until the first schema change. Full contract and rules:
 * docs/maintaining.md.
 *
 * Adding a step means two changes in the same commit: an entry below, whose
 * key is the new target version, and the matching structure in
 * app/setup/schema-sql.php, so a fresh install isn't told to migrate on day one.
 *
 *     2 => [
 *         "description" => "Add thumbnail columns to files",
 *         "run" => function (PDO $db, string $masterKey): void {
 *             $db->exec("ALTER TABLE " . DB_PREFIX . "files ...");
 *         },
 *     ],
 *
 * Not throwing means done. For a step too large for one request, add a third
 * parameter $offset and return array{done, next, message}: Migrator then
 * calls run() again with "next" until "done". The parameter count is the only
 * signal; there is no flag to set.
 *
 * One key per version: a duplicate silently keeps the last entry.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

return [
    // Nothing yet.
];
