<?php

/**
 * app/actions/auth/logout.php
 *
 * POST /api/auth/logout
 * Auth    : requireAuth()
 * Body    : —
 * Returns : {status: "logged_out"}
 *
 * Destroy the current session and expire its cookie.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();
$auth->logout();
json_response(["status" => "logged_out"]);
