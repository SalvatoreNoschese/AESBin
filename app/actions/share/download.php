<?php

/**
 * app/actions/share/download.php
 *
 * GET /api/share/:token/download/:file_token?intent=preview|download
 * Auth    : none (public endpoint)
 * Cookie  : SHARE_SESSION_COOKIE_PREFIX + token
 * Headers : X-Page-Referrer?
 * Body    : —
 * Returns : binary stream
 *
 * Decrypt a file from a share link and send it. `intent` defaults to
 * "download".
 *
 * Both intents are logged and counted, each with its own counter
 * (download_count, preview_count) and its ordinal ("n") in the log item,
 * once per visitor session and file (ShareService::firstAccess()): the
 * session's opening is already logged, so a repeat reads nothing untraced.
 * The share page never opens a preview on its own, so each one is a
 * visitor's choice; and the intent is client-set, so leaving previews out
 * would let a crafted request read a file untraced.
 *
 * The log keeps a capped, rotating feed of recent items; the two counters
 * are the permanent totals.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$rawSession = share_session_from_request($shareToken);

$intent = ($_GET["intent"] ?? "download") === "preview" ? "preview" : "download";

$result  = $shareSvc->requireValidSession($shareToken, $rawSession);
$key     = $result["key"];
$share   = $result["share"];
$shareId = (int) $share["id"];

$realFileId = $fileSvc->downloadViaShare($fileToken, $shareToken, $key);

if ($shareSvc->firstAccess((int) $share["session_id"], $fileToken, $intent)) {
    // This download's or preview's ordinal, logged as "n".
    $accessNumber = $shareSvc->recordFileAccess($shareId, $fileToken, $intent);
    // X-Page-Referrer, not Referer: this is a same-origin fetch() whose Referer
    // is always the share page (see fetchShareBlob() in share.js).
    $pageReferrer = visitor_referrer((string) ($_SERVER["HTTP_X_PAGE_REFERRER"] ?? ""));
    log_activity($db, "share." . $intent, "file", $realFileId, [
        "share_id" => $shareId,
        "token"    => substr($shareToken, 0, 4),
    ] + ($accessNumber !== null ? ["n" => $accessNumber] : [])
      + ($pageReferrer !== "" ? ["referer" => $pageReferrer] : []));
}
