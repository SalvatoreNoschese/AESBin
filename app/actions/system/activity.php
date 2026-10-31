<?php

/**
 * app/actions/system/activity.php
 *
 * GET /api/activity
 * Auth    : requireAuth() + session key (sessionMasterKey())
 * Body    : —
 * Returns : {cards[], item_cap, preview, share_stats, live_share_ids[], live_targets}
 *
 * One card per event type that ever fired, no pagination; see
 * docs/maintaining.md. There are a few dozen event types, so the result is
 * bounded; item_cap bounds the items inside each card and is applied here,
 * so lowering it takes effect on the next load.
 *
 * target_name is resolved live only for an item without item.name, which
 * is captured at log time and wins: it is what the target was called at that
 * occurrence. real_count likewise comes from the item, not the running total.
 *
 * share_stats ({links, visits, downloads, previews}): the live links, and
 * every share visitor's openings, downloads and previews since installation
 * from {P}stats, which outlive deleted links and files and are not touched by
 * emptying the log.
 *
 * live_targets ({file: {id: {c, d}}, directory: {id: {c}}, container: {id: {}}}):
 * where each file, folder and container the log names still lives, so the
 * client can turn its name into a link to it. Trashed files and deleted
 * objects are absent and stay plain text.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();

// Required, not optional, and first: the log holds addresses and referrers,
// and the session cookie alone must not reach it, nor the sweep below (see
// Auth::requireKeyHolder()).
$masterKey = $auth->sessionMasterKey();

// Before the read, so links that expired unvisited show as share.expire.
$shareSvc->sweepExpired();

$itemCap = activity_log_item_cap($config);
$preview = activity_log_preview_count($config);

$stmt = $db->prepare(
    "SELECT id, event, meta, created_at
       FROM " .
        DB_PREFIX .
        "activity_log
      ORDER BY created_at DESC, id DESC"
);
$stmt->execute();
$rows = $stmt->fetchAll();
// Within one second, the order the cards were written in (meta.seq, see
// log_activity()), not their row ids: an unlock and the save it allowed
// usually share a second. A few dozen rows, so sorted here rather than with
// a JSON expression MySQL and MariaDB write differently.
$_seq = static function (array $row): int {
    $m = json_decode((string) $row["meta"], true);
    return is_array($m) ? (int) ($m["seq"] ?? 0) : 0;
};
usort(
    $rows,
    static fn(array $a, array $b): int =>
        strcmp((string) $b["created_at"], (string) $a["created_at"])
            ?: $_seq($b) <=> $_seq($a)
            ?: (int) $b["id"] <=> (int) $a["id"]
);
unset($_seq);

$cards = [];
foreach ($rows as $row) {
    $rowMeta = $row["meta"] ? json_decode($row["meta"], true) : [];
    $items = is_array($rowMeta["items"] ?? null) ? $rowMeta["items"] : [];
    $items = array_slice($items, 0, $itemCap);

    $decorated = [];
    foreach ($items as $item) {
        $item = decorate_activity_item($db, $crypto, $masterKey, $item);

        // "n" is the ordinal captured at log time by ShareService. Never read
        // live from visit_count/download_count/preview_count, which would
        // misnumber every older item. A session refresh has no "n" and shows
        // no badge.
        if (in_array($row["event"], ["share.visit", "share.download", "share.preview"], true)) {
            $item["real_count"] = isset($item["n"]) ? (int) $item["n"] : null;
        }

        $decorated[] = $item;
    }

    $cards[] = [
        "event" => $row["event"],
        "items" => $decorated,
        "created_at" => $row["created_at"],
        // From VISITOR_EVENTS, as log_activity() uses, not inferred from a
        // missing ip. Constant per event, so one flag per card.
        "visitor" => activity_event_is_visitor($row["event"]),
        // ACTORLESS_EVENTS, from the constant like "visitor": no address by
        // design, said in words where one would be.
        "actorless" => activity_event_is_actorless($row["event"]),
    ];
}

// Links still live, so the client can make "Link 12" jump to its card and
// leave a revoked or expired one as plain text.
$liveShareIds = array_map(
    "intval",
    $db->query(
        "SELECT id FROM " . DB_PREFIX . "shares WHERE expires_at IS NULL OR expires_at > NOW()"
    )->fetchAll(PDO::FETCH_COLUMN)
);

json_response([
    "cards" => $cards,
    "item_cap" => $itemCap,
    "preview" => $preview,
    "share_stats" => share_stats($db),
    "live_share_ids" => $liveShareIds,
    "live_targets" => activity_live_targets($db, $cards),
]);
