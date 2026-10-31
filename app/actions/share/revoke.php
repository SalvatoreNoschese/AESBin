<?php

/**
 * app/actions/share/revoke.php
 *
 * DELETE /api/shares/:id
 * Auth    : requireKeyHolder()
 * Body    : —
 * Returns : {revoked: true}
 *
 * Revoke (delete) a share link.
 *
 * Not elevation-gated: it only takes access away. See
 * docs/security-model.md § Elevation.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireKeyHolder();
$shareSvc->revoke($shareId);
json_response(["revoked" => true]);
