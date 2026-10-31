<?php

/**
 * app/actions/auth/ratelimit_status.php
 *
 * GET /api/auth/ratelimit/status?event=login|recovery
 * Auth    : none (public endpoint)
 * Body    : —
 * Returns : {blocked, retry_after}
 *
 * Passive read of this client's login or recovery rate-limit state, so a
 * page loaded while blocked can show the countdown. Logs nothing and never
 * counts as an attempt.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

// Whitelisted: never exposes an arbitrary bucket.
$eventMap = [
    "login" => "login.failure",
    "recovery" => "recovery.failure",
];
// is_string(): ?event[]=x is an array, and an array offset is a TypeError.
$requested = $_GET["event"] ?? "";
$event = is_string($requested) ? ($eventMap[$requested] ?? null) : null;
if ($event === null) {
    json_response(["error" => __("err_invalid_event")], 400);
}

$retryAfter = $security->rateLimitStatus($event);

json_response([
    "blocked" => $retryAfter !== null,
    "retry_after" => $retryAfter,
]);
