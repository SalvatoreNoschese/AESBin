<?php

/**
 * app/actions/auth/status.php
 *
 * GET /api/auth/status
 * Auth    : none; requireAuth() for an authenticated session
 * Body    : —
 * Returns : {authenticated}
 *
 * The dashboard's session probe: on tab re-focus, and as the idle timer's
 * keepalive while the owner is active.
 *
 * An authenticated session goes through requireAuth(): the same idle, IP and
 * revocation checks as any other call, so a session that fails them gets the
 * matching 401 here too, and a live one has last_activity refreshed.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

if ($auth->isAuthenticated()) {
    $auth->requireAuth();
}

json_response([
    "authenticated" => $auth->isAuthenticated(),
]);
