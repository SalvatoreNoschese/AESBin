<?php

/**
 * app/actions/auth/captcha_challenge.php
 *
 * GET /api/auth/captcha/challenge
 * Auth    : none (public endpoint)
 * Body    : —
 * Returns : ALTCHA V2 challenge {parameters: {algorithm, nonce, salt, cost,
 *           keyLength, keyPrefix, expiresAt, …}, signature}, as serialised
 *           by the vendored altcha-org/altcha library.
 *
 * See SecurityService::createCaptchaChallenge().
 */

declare(strict_types=1);

use AESBin\SecurityService;

defined("AESBIN_APP") || exit;

// Unconfigured secrets: 503 rather than a challenge nobody can trust.
// verifyCaptcha() passes through in the same state, and the 503 keeps the
// widget from appearing at all.
if (!$security->captchaOperational()) {
    json_response(["error" => __("captcha_keys_missing"), "code" => "captcha_unconfigured"], 503);
}

json_response(SecurityService::createCaptchaChallenge(
    $security->captchaLevel(),
    $security->captchaSecret(),
    $security->captchaKeySecret()
));
