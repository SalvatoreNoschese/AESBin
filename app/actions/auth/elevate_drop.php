<?php

/**
 * app/actions/auth/elevate_drop.php
 *
 * POST /api/auth/elevate/drop
 * Auth    : requireAuth()
 * Body    : —
 * Returns : {ok}
 *
 * End the elevation window early ("lock now"). It only drops privilege, so
 * a plain authenticated session is enough: no password, no rate limit.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();

// Logged only when a window was open: a repeated click ends nothing.
if (isset($_SESSION["elevated_until"])) {
    unset($_SESSION["elevated_until"]);
    log_activity($db, "settings.deelevated", null, null, [
        "reason" => "manual",
    ]);
}

json_response(["ok" => true]);
