<?php

/**
 * app/actions/file/search.php
 *
 * GET /api/files/search
 * Auth    : requireAuth()
 * Body    : —
 * Returns : {files[], total, capped}
 *
 * Returns every live file with decrypted metadata; the dashboard caches it
 * (_searchCache) and matches names client-side. Names are ciphertext, so SQL
 * has nothing to match on. A heavy request, linear in file count and bounded
 * by $searchLimit: one per session while nothing changes, and one more for
 * the first search after each upload, rename, move or delete, which empty
 * the dashboard's cache.
 *
 * Rate-limited per hit (like share.token_probe), not per failure: every call
 * here succeeds.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireAuth();
// Before the limiter records anything: the cookie alone reaches nothing.
$masterKey = $auth->sessionMasterKey();

// Only bounds a client that bypasses _searchCache. The threshold is counted
// over login_window (5 to 60 minutes), and ordinary work refetches after
// every change to the files, so it is set where a person tidying a vault by
// hand does not reach it. Like every per-client bucket it is off when
// login_max_attempts is 0.
$security->enforceRateLimit("file.search", 300);
rate_limit_record($db, "file.search");

// Rows decrypted per call; `capped` tells the client the list is incomplete.
$searchLimit = 5000;

// One row past the limit, so exactly $searchLimit files is not "capped".
$files = $fileSvc->searchIndex($masterKey, $searchLimit + 1);
$capped = count($files) > $searchLimit;
if ($capped) {
    array_pop($files);
}

json_response([
    "files" => $files,
    "total" => count($files),
    "capped" => $capped,
]);
