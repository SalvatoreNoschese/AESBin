<?php

/**
 * app/actions/system/storage_meta.php
 *
 * POST /api/system/storage-meta
 * Auth    : requireElevated()
 * Body    : —
 * Returns : {ok, storage_path}
 *
 * Record config.php's storage_path in {P}meta, where the installer's
 * reconnect form starts (see storage_meta_state() in helpers.php). For a
 * missing row, or a stale one after the folder was moved by hand.
 *
 * Refused when that folder misses half the files the database lists or more:
 * config.php is then the wrong one, and the integrity banner reports it.
 *
 * Elevated: the row decides where a future reconnect looks for every file.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireElevated();

if (!storage_path_plausible($db)) {
    json_response(
        ["error" => __("storage_meta_refused"), "code" => "storage_implausible"],
        409
    );
}

$before = storage_meta_state($db);
$db->prepare(
    "INSERT INTO " . DB_PREFIX . "meta (meta_key, meta_value) VALUES ('storage_path', :p)
     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
)->execute([":p" => AESBIN_FILES]);

log_activity($db, "settings.storage_meta", null, null, [
    "from" => $before["recorded"] ?? "",
    "to" => AESBIN_FILES,
]);

json_response(["ok" => true, "storage_path" => AESBIN_FILES]);
