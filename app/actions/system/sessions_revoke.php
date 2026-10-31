<?php

/**
 * app/actions/system/sessions_revoke.php
 *
 * POST /api/system/sessions/revoke
 * Auth    : requireKeyHolder()
 * Body    : {id: N}          revoke one session (not the current one)
 *        or {others: true}  revoke every session except the current one
 * Returns : {revoked: N}
 *
 * Deletes registry rows; each affected session gets 401 session_revoked on
 * its next request. That path logs nothing, so the entry written here is the
 * only record.
 *
 * Final: a session's master_key_enc is wrapped with a key that only lived in
 * that browser, so nothing can resume it. The current session uses logout.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireKeyHolder();

$body = json_body();
$registry = $auth->sessionRegistry();

// Captured before deleting, while the rows still say which device. Only
// client and ip, as every login entry already carries.
$active = $registry->listActive(session_id());

if (!empty($body["others"])) {
    $targets = array_values(array_filter($active, fn($s) => empty($s["current"])));
    $n = $registry->revokeOthers(session_id());
    if ($n > 0) {
        log_activity($db, "session.revoked", null, null, [
            "sessions" => array_map(
                fn($s) => ["client" => $s["client"], "ip" => $s["ip"]],
                $targets
            ),
        ]);
    }
    json_response(["revoked" => $n]);
}

// body_int(), not a cast: "5x" must not name session 5.
$id = body_int($body["id"] ?? null) ?? 0;
if ($id <= 0) {
    json_response(["error" => __("err_invalid_session_id")], 400);
}

$target = null;
foreach ($active as $s) {
    if ($s["id"] === $id) {
        $target = $s;
        break;
    }
}

if (!$registry->revokeById($id, session_id())) {
    // Row already gone, or the current session.
    json_response(["error" => __("sessions_revoke_failed")], 400);
}

log_activity($db, "session.revoked", null, null, [
    "sessions" => $target ? [["client" => $target["client"], "ip" => $target["ip"]]] : [],
]);
json_response(["revoked" => 1]);
