<?php

/**
 * app/actions/auth/recovery_guard_verify.php
 *
 * POST /api/auth/recovery/guard/verify
 * Auth    : none (public endpoint)
 * Body    : {code}
 * Returns : {status: "guard_ok"}
 *
 * Compare the code with the one in the guard file. On a match the session is
 * flagged, which recovery_verify.php and recovery_execute.php test.
 *
 * The file is not consumed: a recovery spans several requests. bootstrap.php
 * removes it once the gate is shut.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

recovery_require_gate(false);

// Own bucket: a mistyped guard code must not use up the phrase attempts, and
// vice versa.
$security->enforceRateLimit("recovery.guard_fail");

$body = json_body();

// Not require_fields(): a missing code gets this endpoint's own message.
if (isset($body["code"])) {
    normalize_body_field($body, "code");
}

$code = strtoupper(trim($body["code"] ?? ""));
if ($code === "") {
    json_response(["error" => __("err_recovery_guard_code")], 400);
}

$path = recovery_guard_file();
$expected = $path === null ? null : recovery_guard_read($path);

// One answer for no file, unreadable file and wrong code: the owner can see
// the file and tell them apart; nobody else needs to.
if ($expected === null || !hash_equals($expected, $code)) {
    log_activity($db, "recovery.guard_fail");
    usleep(random_int(100000, 300000));
    json_response(["error" => __("err_recovery_guard_code")], 403);
}

// The file's name, not true: see recovery_guard_passed().
$_SESSION["recovery_guard_ok"] = basename((string) $path);
log_activity($db, "recovery.guard_ok");

json_response(["status" => "guard_ok"]);
