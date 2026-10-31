<?php

/**
 * app/includes/RecoveryGate.php — proof-of-filesystem gate for account recovery
 *
 * 'recovery' => 'yes' in config.php permits recovery but does not say who is
 * asking: a thief of the phrase could wait for it to open. The guard file
 * (shared mechanism, see GuardFile.php) asks for a second proof at the
 * moment of the attempt. Not time-limited; wrong codes are rate-limited.
 * The file lives under storage, which is always writable, unlike the app
 * root on hardened installs.
 */

declare(strict_types=1);

use AESBin\CryptoService;

defined("AESBIN_APP") || exit;

/** Directory shown to the owner. The filename inside is never disclosed. */
const RECOVERY_GUARD_DIR = "_recovery";

/** Absolute path of that directory, under the configured storage root. */
function recovery_guard_dir(): string
{
    return AESBIN_FILES . "/" . RECOVERY_GUARD_DIR;
}

/** Label the code is written after, inside the guard file. */
const RECOVERY_GUARD_MARKER = "AESBIN-RECOVERY-CODE";

/**
 * Path of the guard file, or null if there is none. With recovery off this
 * costs one failed stat.
 */
function recovery_guard_file(): ?string
{
    return guard_file_find(recovery_guard_dir());
}

/**
 * Create the guard file. Returns success only, never the code: the code must
 * exist only on disk, or the requester being tested could read it.
 */
function recovery_guard_create(): bool
{
    return guard_file_create(recovery_guard_dir(), RECOVERY_GUARD_MARKER, [
        "AESBin — recovery guard",
        "=======================",
        "",
        "Paste this code into the recovery page to continue.",
        "Delete this file if you did not start a recovery.",
    ]) !== null;
}

/** Read the code back out of the guard file, or null if unreadable. */
function recovery_guard_read(string $path): ?string
{
    return guard_file_read($path, RECOVERY_GUARD_MARKER);
}

/** Remove the guard directory and everything in it. Safe when there is none. */
function recovery_guard_clear(): void
{
    guard_dir_clear(recovery_guard_dir());
}

/**
 * True once this session has entered the code of the guard file that exists now.
 *
 * The session stores the file's name, not a bare true (as setup_guard_passed()
 * does). bootstrap.php drops the flag when it clears a stale guard, but only
 * in a request carrying this session. Bound to the name, a leftover flag
 * cannot open a guard created later when the gate is reopened.
 */
function recovery_guard_passed(): bool
{
    $flag = $_SESSION["recovery_guard_ok"] ?? null;
    $file = recovery_guard_file();
    return is_string($flag) && $file !== null && hash_equals(basename($file), $flag);
}

/**
 * Hash kept in the session for a replacement phrase between
 * recovery_phrase_new.php and recovery_execute.php. A fast hash is enough:
 * the phrase carries 110 bits of entropy, so there is nothing to brute-force.
 */
function recovery_phrase_hash(string $phrase): string
{
    return hash("sha256", "aesbin-recovery-phrase:" . CryptoService::normalizeRecoveryPhrase($phrase));
}

/**
 * Hash kept in the session for the replacement TOTP secret, between
 * recovery_totp_new.php, recovery_totp_verify.php and recovery_execute.php:
 * the session never holds the secret itself, as with the phrase above and in
 * the installer (setup_secret_hash()).
 */
function recovery_totp_hash(string $secret): string
{
    return hash("sha256", "aesbin-recovery-totp:" . $secret);
}

/**
 * Whether recovery is permitted at all (`'recovery' => 'yes'` in config.php).
 *
 * No side effects. Clearing a stale guard is done by bootstrap on every
 * request: a recovery closes the gate inside its own request, so cleanup here
 * would miss exactly that case.
 */
function recovery_gate_open(): bool
{
    return AESBIN_RECOVERY_ENABLED;
}

/**
 * First line of every recovery endpoint: refuse unless the gate is open and,
 * with $guard, the session has entered the guard code. Runs before the body
 * is read, so a stolen phrase never reaches Argon2id. Not logged (scanners
 * hit public URLs daily); the message is English, for the administrator.
 */
function recovery_require_gate(bool $guard = true): void
{
    if (!recovery_gate_open()) {
        json_response(
            ["error" => "Account recovery is disabled. Set 'recovery' => 'yes' in config.php to enable it."],
            403
        );
    }
    if ($guard && !recovery_guard_passed()) {
        json_response(["error" => __("err_recovery_guard_required")], 403);
    }
}
