<?php

/**
 * app/actions/system/update_check.php
 *
 * GET /api/system/update/check
 * Auth    : requireKeyHolder()
 * Query   : force=1?  (bypass the cache, at most once a minute)
 * Body    : —
 * Returns : {status, has_update, current, latest?, tag?, download_url?,
 *            release_url?, published_at?, body?, checked_at?, error?,
 *            http_status?}
 *
 * Check the GitHub releases API for a newer version. status: "ok", "none"
 * (no release published), "unreachable", "error", or "unavailable" (no
 * ext-curl).
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireKeyHolder();

// Its own status rather than "unreachable", which would send the owner
// looking at firewalls. A property of the server, so the dashboard shows it
// without waiting for a button press. Nothing else in AESBin needs curl.
if (!function_exists("curl_init")) {
    json_response([
        "status" => "unavailable",
        "has_update" => false,
        "current" => AESBIN_VERSION,
        "error" => __("err_update_no_curl"),
    ]);
}

// In the storage root. integrity_check.php only scans "??/*.bin", so this is
// never reported as an orphan; if either side changes, move this file. Web
// access is denied by the storage .htaccess (bootstrap.php), by sitting
// outside public/ in the default layout, and by the dot-file rule in
// public/.htaccess otherwise.
$cacheFile = AESBIN_FILES . "/.update_check.json";
$cacheTTL = 86400; // 24 hours
$forceMinInterval = 60; // floor on ?force=1, so it cannot be used to spam GitHub

$forceRefresh = ($_GET["force"] ?? "0") === "1";

// Every answer from GitHub, failures included, is cached before it is sent.
$respond = static function (array $result) use ($cacheFile): never {
    @file_put_contents($cacheFile, json_encode($result));
    json_response($result);
};

if (is_file($cacheFile)) {
    $age = time() - (int) filemtime($cacheFile);
    if (
        (!$forceRefresh && $age < $cacheTTL) ||
        ($forceRefresh && $age < $forceMinInterval)
    ) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            // A cached failure carries its message in the language of the
            // request that wrote it; re-translate for this one.
            if (($cached["status"] ?? "") === "unreachable") {
                $cached["error"] = __("err_github_unreachable");
            } elseif (($cached["status"] ?? "") === "error") {
                $cached["error"] = __("err_github_unexpected");
            }
            json_response($cached);
        }
    }
}

// The request below can take up to CURLOPT_TIMEOUT when GitHub is slow or
// filtered. The session is released first, or every other request of this
// session would queue behind its file lock for that long. Nothing after this
// point writes to $_SESSION.
session_write_close();

$apiUrl =
    "https://api.github.com/repos/" . AESBIN_GITHUB_REPO . "/releases/latest";

// cURL, not file_get_contents(): that needs allow_url_fopen, often off on
// hardened hosts, and on PHP 8.5 the deprecated $http_response_header.
// No curl_close(): a no-op since PHP 8.0, deprecated in 8.5.
$raw = false;
$httpStatus = 0;
$ch = curl_init($apiUrl);
if ($ch !== false) {
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        // A renamed repository answers with a redirect; follow a few.
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        // https only, redirects included (no http://, file://, gopher://).
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            "User-Agent: AESBin/" . AESBIN_VERSION,
            "Accept: application/vnd.github+json",
        ],
    ]);
    $raw = curl_exec($ch);
    // After redirects. 0 when no response arrived at all.
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (!is_string($raw)) {
        $raw = false;
    }
}

// ── Could not reach GitHub at all ─────────────────────────────────────────────
// Cached like any answer: otherwise a host whose firewall drops outbound
// traffic would hold a PHP worker for the full timeout on every dashboard
// load. ?force=1 still retries.
if ($raw === false) {
    $result = [
        "status" => "unreachable",
        "has_update" => false,
        "current" => AESBIN_VERSION,
        "error" => __("err_github_unreachable"),
        "checked_at" => time(),
    ];
    $respond($result);
}

// ── Reached GitHub, and it says there is no release ──
// Not a failure: /releases/latest answers 404 for a repository with no
// release yet (e.g. a fork), so there is nothing newer.
if ($httpStatus === 404) {
    $result = [
        "status" => "none",
        "has_update" => false,
        "current" => AESBIN_VERSION,
        "checked_at" => time(),
    ];
    $respond($result);
}

$release = json_decode($raw, true);
// is_string, not isset: a non-string tag_name would be a TypeError in ltrim().
if (
    !is_array($release) ||
    !is_string($release["tag_name"] ?? null) ||
    $release["tag_name"] === ""
) {
    // Rate limiting (403 with JSON), an outage page, a proxy. Cached like
    // unreachable.
    $result = [
        "status" => "error",
        "has_update" => false,
        "current" => AESBIN_VERSION,
        "error" => __("err_github_unexpected"),
        "http_status" => $httpStatus,
        "checked_at" => time(),
    ];
    $respond($result);
}

$latestTag = ltrim($release["tag_name"], "v"); // e.g. "1.0.1" from "v1.0.1"
$currentVer = AESBIN_VERSION; // e.g. "1.0.0"

// version_compare() orders pre-releases: "1.0.0-rc1" < "1.0.0" < "1.0.1".
$hasUpdate = version_compare($latestTag, $currentVer) > 0;
// GitHub's own archive of the tagged sources: what a release is, since
// everything outside public/ has no URL and nothing needs leaving out. Built
// from the repository name and a tag held to version characters, so the URL
// cannot point anywhere else; empty for any other tag, and the client then
// links only the release page.
$downloadUrl = preg_match('/^v?[0-9A-Za-z.\-]+\z/', (string) $release["tag_name"]) === 1
    ? "https://github.com/" . AESBIN_GITHUB_REPO . "/archive/refs/tags/" . rawurlencode((string) $release["tag_name"]) . ".zip"
    : "";

$result = [
    "status" => "ok",
    "has_update" => $hasUpdate,
    "current" => $currentVer,
    "latest" => $latestTag,
    "tag" => $release["tag_name"],
    "download_url" => $downloadUrl,
    // Rendered as a link, so held to the same origin check as download_url:
    // a javascript: or foreign URL from a tampered response never reaches it.
    "release_url" => is_string($release["html_url"] ?? null) &&
        str_starts_with($release["html_url"], "https://github.com/")
        ? $release["html_url"]
        : "",
    "published_at" => is_string($release["published_at"] ?? null) ? $release["published_at"] : "",
    // Release notes, capped as a teaser (full text at release_url). The
    // client inserts it as text, never as HTML.
    "body" => mb_substr(is_string($release["body"] ?? null) ? $release["body"] : "", 0, 600),
    "checked_at" => time(),
];

$respond($result);
