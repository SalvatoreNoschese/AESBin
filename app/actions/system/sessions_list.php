<?php

/**
 * app/actions/system/sessions_list.php
 *
 * GET /api/system/sessions
 * Auth    : requireKeyHolder()
 * Body    : —
 * Returns : {sessions: [{id, ip, client, created_at, last_seen, current}]}
 *
 * List active sessions, most recently seen first. listActive() prunes
 * expired rows first, so dead sessions are never shown.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireKeyHolder();

json_response([
    "sessions" => $auth->sessionRegistry()->listActive(session_id()),
]);
