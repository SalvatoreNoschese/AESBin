<?php

/**
 * app/actions/share/view.php
 *
 * GET /api/share/:token/files
 * Auth    : none (public endpoint)
 * Cookie  : SHARE_SESSION_COOKIE_PREFIX + token
 * Body    : —
 * Returns : {files[], meta: {type, target_name, target_desc}}
 *
 * List the files in a share, for a visitor holding a share session.
 * Without one, a 200 with {session: false}: share.js asks first whether a
 * visit is already open in this browser, and "no" is not an error. A
 * cookie whose session has ended (password changed, revoked) is the same
 * "no", and is cleared; an expired or revoked link is then reported by
 * share/auth.php.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$rawSession = share_session_cookie_value($shareToken);
$list = $rawSession !== "" ? $shareSvc->listSharedFiles($shareToken, $rawSession) : null;
if ($list === null) {
    if ($rawSession !== "") {
        share_session_cookie($shareToken, "", 1);
    }
    json_response(["session" => false]);
}
json_response($list);
