<?php

/**
 * app/actions/system/activity_purge.php
 *
 * POST /api/system/activity/purge
 * Auth    : requireElevated()  (the log is the audit trail, so wiping it
 *           costs the same as lowering its item cap)
 * Body    : —
 * Returns : {purged: true}
 *
 * Delete every card now. One is written right after, so an emptied log shows
 * a deliberate flush rather than an intruder cleaning up.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireElevated();

// History only. Lockout state ({prefix}rate_limit) is untouched, so a purge
// never resets an attacker's lockout; so are the share totals ({prefix}stats).
$db->exec("DELETE FROM " . DB_PREFIX . "activity_log");
log_activity($db, "activity_log.purge");

json_response(["purged" => true]);
