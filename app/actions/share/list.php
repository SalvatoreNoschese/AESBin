<?php

/**
 * app/actions/share/list.php
 *
 * GET /api/shares
 * Auth    : requireAuth()
 * Body    : —
 * Returns : {shares[]}
 *
 * List all active share links with metadata.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();
json_response([
    "shares" => $shareSvc->listOwnerShares($auth->sessionMasterKey()),
]);
