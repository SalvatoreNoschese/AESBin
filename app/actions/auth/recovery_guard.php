<?php

/**
 * app/actions/auth/recovery_guard.php
 *
 * POST /api/auth/recovery/guard
 * Auth    : none (public endpoint)
 * Body    : {}
 * Returns : {status: "armed", dir: "_recovery"}
 *
 * Write the guard file whose code the next step asks for, or reuse the
 * existing one. The response carries only the folder (what the owner needs to
 * find it over FTP), never the code or filename.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

// Gate shut: nothing to arm. bootstrap.php already removed any leftover file.
recovery_require_gate(false);

// A file write on a public endpoint, so throttled in the wrong-code bucket.
// The reuse below means the ordinary case writes nothing.
$security->enforceRateLimit("recovery.guard_fail");

// Same answer whether or not a file already existed: otherwise anyone could
// tell whether a recovery is in progress.
if (recovery_guard_file() === null && !recovery_guard_create()) {
    // Storage is writable on any working install, so this is a broken state
    // that would leave the owner no way in. Say so now rather than as a
    // "wrong code" later.
    log_activity($db, "recovery.guard_unwritable");
    json_response(["error" => __("err_recovery_guard_unwritable")], 500);
}

json_response(["status" => "armed", "dir" => RECOVERY_GUARD_DIR]);
