<?php

/**
 * app/actions/auth/session.php
 *
 * GET /api/auth/session
 * Auth    : none (public endpoint)
 * Body    : —
 * Returns : {ok, csrf}
 *
 * A session with a CSRF token for a login page whose own is gone: the page
 * stayed open (an installed app resumed from the background, say) while the
 * server dropped its session or the browser its cookie. passkey.js calls it
 * when the passkey challenge answers no_session, then asks again, so the tap
 * goes on without a reload. It does what loading the login page does
 * (bootstrap.php starts the session, Csrf::token() issues the token), and
 * no more: the challenge still refuses a request without a token.
 */

declare(strict_types=1);

use AESBin\Csrf;

defined("AESBIN_APP") || exit;

json_response(["ok" => true, "csrf" => Csrf::token()]);
