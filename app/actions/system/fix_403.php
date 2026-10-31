<?php

/**
 * app/actions/system/fix_403.php
 *
 * POST /api/system/fix-403
 * Auth    : requireElevated()
 * Body    : —
 * Returns : {ok}
 *
 * Point the .htaccess ErrorDocument directive at this installation's
 * index.php, which serves the 403 page (see
 * Requirements::wireErrorDocument()). Offered by the "Custom 403 page"
 * requirements row, e.g. after an upgrade replaced .htaccess on a
 * subdirectory install.
 *
 * 409 when nothing can safely be changed: server ignores .htaccess, file not
 * writable, or block edited by the owner. The row then shows the directive
 * to paste by hand.
 *
 * Elevated: it writes a file every request passes through.
 */

declare(strict_types=1);

use AESBin\Requirements;

defined("AESBIN_APP") || exit;

$auth->requireElevated();

if (
    !Requirements::serverReadsHtaccess() ||
    !Requirements::errorDocumentFixable(AESBIN_ROOT) ||
    !Requirements::wireErrorDocument(AESBIN_ROOT, AESBIN_BASE)
) {
    json_response(
        ["error" => __("req_fix_403_failed"), "code" => "not_fixable"],
        409
    );
}

log_activity($db, "settings.custom_403", null, null, [
    "to" => Requirements::errorDocumentLine(AESBIN_BASE),
]);

json_response(["ok" => true]);
