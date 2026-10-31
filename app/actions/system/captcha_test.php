<?php

/**
 * app/actions/system/captcha_test.php
 *
 * GET /api/system/captcha/test?level=low|medium|high
 * Auth    : requireKeyHolder()
 * Body    : —
 * Returns : an ALTCHA V2 challenge, same shape as /api/auth/captcha/challenge
 *
 * Backs the ALTCHA card's "Test on this device" button: a challenge built
 * like the login one, so its solve time matches login on the same device.
 *
 * Signed with a throwaway per-request key, so a solution verifies nowhere
 * (not on /login or /recovery) and the test works before the captcha is
 * configured. Authenticated, so anonymous visitors cannot fetch cheap
 * challenges here.
 */

declare(strict_types=1);

use AESBin\SecurityService;

defined("AESBIN_APP") || exit;

$auth->requireKeyHolder();

$level = $_GET["level"] ?? "";
if (!is_string($level) || !array_key_exists($level, CAPTCHA_LEVELS)) {
    json_response(["error" => __("err_field_invalid", ["field" => "level"])], 400);
}

// No key-signature secret: nothing will ever verify this challenge.
json_response(SecurityService::createCaptchaChallenge($level, bin2hex(random_bytes(32))));
