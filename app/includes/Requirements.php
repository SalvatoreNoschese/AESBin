<?php

declare(strict_types=1);

namespace AESBin;

use PDO;

defined("AESBIN_APP") || exit;

/**
 * app/includes/Requirements.php — what AESBin needs from the server
 *
 * Read by app/setup/index.php to gate the wizard and by the dashboard afterwards.
 * Must not depend on bootstrap.php or config.php, which the installer does
 * not have: anything a check cannot work out alone arrives in $ctx. Of
 * app/includes/ it uses only helpers.php (unmap_ipv4()), which both load first.
 *
 * blocking(): without these nothing works and the installer refuses to start.
 * Not re-checked at runtime, because without sodium or pdo_mysql the app
 * cannot boot far enough to say so. advisory(): AESBin runs, but something
 * degrades quietly (truncated uploads, config.php edits that do not take
 * effect, one logged address for everyone).
 *
 * Labels are technical names and stay untranslated, so an administrator can
 * search for the exact string. Hints are lang keys, shown only on failure.
 */

final class Requirements
{
    /**
     * Minimum PHP. README.md, docs/installation.md and the PHPStan range
     * restate it; bin/lint.sh checks that they agree.
     */
    public const PHP_MIN = "8.3.0";

    /**
     * Oldest database servers supported, checked by the installer. MySQL's
     * floor is JSON_SET() (5.7.8); holding several advisory locks at once
     * needs 5.7.5. MariaDB has both since 10.2; 10.3 is the floor because 10.2
     * reached end-of-life first. CHECK constraints are enforced only from
     * MySQL 8.0.16 / MariaDB 10.2.1, which integrity_check.php allows for.
     * README.md and docs/installation.md restate these; bin/lint.sh checks
     * that they agree.
     */
    public const MYSQL_MIN = "5.7.8";
    public const MARIADB_MIN = "10.3.0";

    /**
     * Whether a server's VERSION() string is at or above the minimum for its
     * family. MariaDB reports "10.11.6-MariaDB[-suffix]", some builds with a
     * "5.5.5-" prefix for old clients; MySQL and its forks a bare number.
     */
    public static function databaseSupported(string $version): bool
    {
        $mariadb = stripos($version, "mariadb") !== false;
        $version = preg_replace('/^5\.5\.5-/', "", $version) ?? $version;
        if (preg_match('/^\d+\.\d+(?:\.\d+)?/', $version, $m) !== 1) {
            return false;
        }
        return version_compare($m[0], $mariadb ? self::MARIADB_MIN : self::MYSQL_MIN, ">=");
    }

    /**
     * A minimum as the documents write it: "8.3", not "8.3.0" (bin/lint.sh
     * compares them the same way).
     */
    public static function short(string $version): string
    {
        return preg_replace('/\.0$/', "", $version) ?? $version;
    }

    /** "MySQL 5.7.8+ / MariaDB 10.3+", for the installer and the dashboard. */
    public static function databaseMinimum(): string
    {
        return "MySQL " . self::short(self::MYSQL_MIN) . "+ / MariaDB " . self::short(self::MARIADB_MIN) . "+";
    }

    /**
     * The connected server: VERSION() as reported, for databaseSupported(),
     * and "<product> <x.y.z>" for people. MariaDB's VERSION() carries its
     * name and packaging ("10.11.19-MariaDB-cll-lve"), MySQL's is a bare
     * number, and Percona is only named in @@version_comment.
     *
     * @return array{version: string, name: string}
     */
    public static function databaseServer(PDO $pdo): array
    {
        $row = $pdo->query("SELECT VERSION() AS v, @@version_comment AS c")->fetch(PDO::FETCH_ASSOC);
        $raw = (string) ($row["v"] ?? "");
        $comment = (string) ($row["c"] ?? "");
        $bare = preg_replace('/^5\.5\.5-/', "", $raw) ?? $raw;
        $number = preg_match('/^\d+(?:\.\d+){1,2}/', $bare, $m) === 1 ? $m[0] : $bare;
        $product = match (true) {
            stripos($bare . " " . $comment, "mariadb") !== false => "MariaDB",
            stripos($comment, "percona") !== false => "Percona Server",
            default => "MySQL",
        };
        return ["version" => $raw, "name" => $product . " " . $number];
    }

    /**
     * The database row of the dashboard's list, in the "required" group like
     * PHP. Not part of blocking(): the installer has no connection yet when it
     * shows that list, and checks the version when it tests the connection.
     *
     * @return array<string, array{label: string, ok: bool, hint: string, group: string, detail: string, detail_always: bool}>
     */
    public static function database(string $version, string $name): array
    {
        return self::inGroup("required", [
            "database" => [
                "label" => self::databaseMinimum(),
                "ok" => self::databaseSupported($version),
                "hint" => "req_hint_db",
                "detail" => $name,
                "detail_always" => true,
            ],
        ]);
    }

    /**
     * Estimated memory used outside the upload path (PDO, rendering, activity
     * queries). Added to the upload cost, not compared with it, because both
     * are paid in the same request. For any real upload limit the 2x term
     * dominates, so an error here moves the bar little.
     */
    public const MEMORY_BASE_BYTES = 16777216; // 16 MiB

    /**
     * Peak memory for a single upload, as a multiple of the file size:
     * FileService reads the whole file with file_get_contents() and holds
     * plaintext and ciphertext at once. The memory_limit note in
     * docs/installation.md quotes the same ratio.
     */
    public const MEMORY_PER_UPLOAD = 2;

    /** 0 means unlimited, which is fine; anything lower than this is not. */
    public const EXEC_TIME_MIN = 30;

    /**
     * Beyond this revalidation interval, and with no usable
     * opcache_invalidate(), a rewritten config.php stays served from the
     * previous compilation long enough to be worth reporting.
     */
    public const OPCACHE_REVALIDATE_MAX = 60;

    private const ADVISORY_ORDER = [
        "root_writable", "storage_writable", "storage_outside_public",
        "upload_max_filesize", "post_max_size", "memory_limit",
        "max_execution_time", "max_input_time", "opcache_revalidate",
        "htaccess", "custom_403", "client_ip",
        "captcha_keys", "ext_curl",
    ];

    /**
     * Running PHP as x.y.z. Not PHP_VERSION: distro builds add a packaging
     * suffix (8.4.24-1+deb12u1).
     */
    public static function phpVersion(): string
    {
        return PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . "." . PHP_RELEASE_VERSION;
    }

    /**
     * The web server, for the line above the list: it decides whether
     * .htaccess applies. The SAPI only when the server name does not already
     * say it ("LiteSpeed · litespeed" says nothing twice).
     */
    public static function environment(): string
    {
        $server = (string) ($_SERVER["SERVER_SOFTWARE"] ?? "?");
        return stripos($server, PHP_SAPI) !== false ? $server : $server . " · " . PHP_SAPI;
    }

    /**
     * @return array<string, array{label: string, ok: bool, hint: string, group: string, detail?: string, detail_always?: bool}>
     */
    public static function blocking(string $appRoot): array
    {
        return self::inGroup("required", [
            "php" => [
                "label" => "PHP " . self::short(self::PHP_MIN) . "+",
                "ok" => version_compare(PHP_VERSION, self::PHP_MIN, ">="),
                "hint" => "req_hint_php",
                "detail" => "PHP " . self::phpVersion(),
                "detail_always" => true,
            ],
            "sodium" => [
                "label" => "ext-sodium",
                "ok" => extension_loaded("sodium"),
                "hint" => "req_hint_sodium",
            ],
            "argon2id" => [
                "label" => "Argon2id",
                "ok" => defined("SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13"),
                "hint" => "req_hint_sodium",
            ],
            "openssl" => [
                "label" => "ext-openssl",
                "ok" => extension_loaded("openssl"),
                "hint" => "req_hint_openssl",
            ],
            "aes_gcm" => [
                "label" => "AES-256-GCM",
                // Without ext-openssl the function does not exist, and calling
                // it would crash the page meant to report that.
                "ok" => extension_loaded("openssl") &&
                    in_array("aes-256-gcm", openssl_get_cipher_methods(), true),
                "hint" => "req_hint_openssl",
            ],
            "pdo_mysql" => [
                "label" => "ext-pdo_mysql",
                "ok" => extension_loaded("pdo_mysql"),
                "hint" => "req_hint_pdo",
            ],
            "mbstring" => [
                "label" => "ext-mbstring",
                "ok" => extension_loaded("mbstring"),
                "hint" => "req_hint_mbstring",
            ],
            "fileinfo" => [
                "label" => "ext-fileinfo",
                "ok" => extension_loaded("fileinfo"),
                "hint" => "req_hint_fileinfo",
            ],
            "root_writable" => [
                "label" => "Writable app directory",
                "ok" => is_writable($appRoot),
                "hint" => "req_hint_root",
                "detail" => $appRoot,
                // Shown when green too: the storage path is typed as an
                // absolute path, and this is the one place that says where
                // the application lives.
                "detail_always" => true,
            ],
        ]);
    }

    /**
     * Stamp every row with its group, so a caller that merged both sets can
     * tell them apart. On any page that renders, the blocking rows are green
     * by necessity; only the advisory ones can differ between healthy installs.
     *
     * @param array<string, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private static function inGroup(string $group, array $rows): array
    {
        foreach ($rows as $key => $row) {
            $rows[$key]["group"] = $group;
        }
        return $rows;
    }

    /**
     * Whether a storage path lies inside $root/public/, where every blob
     * would have a URL. The folder may not exist yet, so its nearest existing
     * ancestor is resolved. Shared by the installer, which refuses such a
     * path, and the dashboard, which warns about one set in config.php.
     */
    public static function storageInPublic(string $storage, string $root): bool
    {
        $public = realpath($root . "/public");
        if ($public === false || $storage === "") {
            return false;
        }
        $probe = rtrim($storage, "/\\");
        while ($probe !== "" && !file_exists($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                break;
            }
            $probe = $parent;
        }
        $real = realpath($probe) ?: $probe;
        return $real === $public || str_starts_with($real . "/", $public . "/");
    }

    /**
     * @param array $ctx  root, storage, max_upload_bytes, trusted_proxies and
     *                    optionally server (defaults to $_SERVER, overridable
     *                    so the proxy check can be tested without a request).
     * "detail" is shown for a failing row: the line that fixes it, or the
     * directory that needs fixing. "detail_always" shows it for a passing row
     * too.
     *
     * @return array<string, array{label: string, ok: bool, hint: string, group: string, detail?: string, detail_always?: bool, optional?: bool}>
     */
    public static function advisory(array $ctx = []): array
    {
        $server = $ctx["server"] ?? $_SERVER;
        $wanted = (int) ($ctx["max_upload_bytes"] ?? SETTINGS_DEFAULTS["max_upload_bytes"]);

        $out = [];

        if (isset($ctx["storage"])) {
            $out["storage_writable"] = [
                "label" => "Writable storage directory",
                "ok" => is_dir($ctx["storage"]) && is_writable($ctx["storage"]),
                "hint" => "req_hint_storage",
                "detail" => (string) $ctx["storage"],
                // The one place the dashboard says where the encrypted files are.
                "detail_always" => true,
            ];
        }

        // The installer refuses a storage folder inside public/; this catches a
        // storage_path edited into it by hand. A warning, not a refusal: the
        // files stay ciphertext, and the owner may have reasons (or a rule)
        // the code cannot see.
        if (isset($ctx["storage"], $ctx["root"])) {
            $out["storage_outside_public"] = [
                "label" => "Storage outside public/",
                "ok" => !self::storageInPublic((string) $ctx["storage"], (string) $ctx["root"]),
                "hint" => "req_hint_storage_public",
                "detail" => (string) $ctx["storage"],
            ];
        }

        if (isset($ctx["root"])) {
            // Same directory as the blocking check, lower stakes: after
            // installation an unwritable root only means config.php cannot be
            // rewritten, so the recovery gate will not close itself.
            $out["root_writable"] = [
                "label" => "Writable app directory",
                "ok" => is_writable($ctx["root"]),
                "hint" => "req_hint_root_runtime",
                "detail" => (string) $ctx["root"],
                "detail_always" => true,
            ];
        }

        // Mirrors SecurityService::captchaOperational() without depending on
        // it; the caller reads both booleans from config. The installer does
        // not pass captcha_enabled, since no secret can exist yet. Not
        // "optional": an admin who turned this on expects it to work, so a
        // missing key raises the dot and the banner.
        if (isset($ctx["captcha_enabled"])) {
            $out["captcha_keys"] = [
                "label" => "ALTCHA keys",
                "ok" => !$ctx["captcha_enabled"] || !empty($ctx["captcha_operational"]),
                "hint" => "req_hint_captcha_keys",
            ];
        }

        // curl is used only by the update check. "optional" rows are shown
        // grey and never raise the dashboard banner: nothing is wrong, and
        // many hosts do not let the owner install it. The installer hint
        // points to GitHub releases, the dashboard one to the update card.
        $out["ext_curl"] = [
            "label" => "ext-curl (update check)",
            "ok" => function_exists("curl_init"),
            "hint" => empty($ctx["installer"]) ? "req_hint_curl_dashboard" : "req_hint_curl",
            "optional" => true,
        ];

        // One row each: they are set separately, and one line naming both
        // does not say which is short. For these two PHP reads 0 as "no
        // limit", as php_upload_limit_bytes() does. The installer's hints
        // speak of the default limit and of Settings as still to come.
        $hintSuffix = empty($ctx["installer"]) ? "" : "_installer";
        $uploadMax = self::iniBytes("upload_max_filesize");
        $out["upload_max_filesize"] = [
            "label" => "upload_max_filesize (" . ini_get("upload_max_filesize") . ")",
            "ok" => $uploadMax <= 0 || $uploadMax >= $wanted,
            "hint" => "req_hint_upload" . $hintSuffix,
        ];

        $postMax = self::iniBytes("post_max_size");
        $out["post_max_size"] = [
            "label" => "post_max_size (" . ini_get("post_max_size") . ")",
            "ok" => $postMax <= 0 || $postMax >= $wanted,
            "hint" => "req_hint_upload" . $hintSuffix,
        ];

        // Measured against the configured upload limit, not a fixed figure:
        // a small but coherent configuration stays green. What this catches
        // is PHP being unable to hold what the application promises to accept.
        $memory = self::iniBytes("memory_limit");
        $memoryNeeded =
            self::MEMORY_BASE_BYTES + $wanted * self::MEMORY_PER_UPLOAD;
        $out["memory_limit"] = [
            "label" => "memory_limit (" . ini_get("memory_limit") . ")",
            "ok" => $memory === -1 || $memory >= $memoryNeeded,
            "hint" => "req_hint_memory" . $hintSuffix,
        ];

        // max_execution_time starts when the script does, after the body has
        // arrived, so it only covers encryption, the write and the queries.
        $exec = (int) ini_get("max_execution_time");
        $out["max_execution_time"] = [
            "label" => "max_execution_time (" . $exec . ")",
            "ok" => $exec === 0 || $exec >= self::EXEC_TIME_MIN,
            "hint" => "req_hint_exec",
        ];

        // max_input_time runs while the body is being received, so this is
        // the limit a slow upload races. -1 means "use max_execution_time",
        // 0 means no limit. PHP compiles in -1 but php.ini-production ships
        // 60, so it is read separately rather than assumed to follow.
        $inputTime = (int) ini_get("max_input_time");
        $out["max_input_time"] = [
            "label" => "max_input_time (" . $inputTime . ")",
            "ok" =>
                $inputTime <= 0 ||
                $inputTime >= self::EXEC_TIME_MIN,
            "hint" => "req_hint_input_time",
        ];

        // OPcache can keep serving the previous config.php after a rewrite.
        // Harmless if opcache_invalidate() works or timestamps are revalidated
        // often enough; otherwise a change waits for a PHP restart.
        $freq = (int) ini_get("opcache.revalidate_freq");
        $out["opcache_revalidate"] = [
            "label" => "OPcache revalidation",
            "ok" =>
                !extension_loaded("Zend OPcache") ||
                !filter_var(ini_get("opcache.enable"), FILTER_VALIDATE_BOOLEAN) ||
                self::opcacheInvalidateUsable() ||
                (filter_var(ini_get("opcache.validate_timestamps"), FILTER_VALIDATE_BOOLEAN) &&
                    $freq <= self::OPCACHE_REVALIDATE_MAX),
            "hint" => "req_hint_opcache",
        ];

        // Every deny rule (and the 403 error_page) lives in .htaccess, so on
        // other servers the owner must port them by hand. PHP cannot verify
        // that, so the owner declares it with server_rules_configured in
        // config.php; otherwise a correctly configured nginx would stay amber
        // forever.
        $readsHtaccess = self::serverReadsHtaccess($server);
        $out["htaccess"] = [
            // Two labels: on Apache the row reports a fact the code checked,
            // elsewhere it repeats what the owner declared.
            "label" => $readsHtaccess
                ? "Server security rules (.htaccess)"
                : "Server security rules (nginx/Caddy)",
            "ok" => !empty($ctx["server_rules_configured"]) || $readsHtaccess,
            "hint" => "req_hint_deny_rules",
        ];

        // .htaccess ships a live "ErrorDocument 403 /index.php", right only at a
        // domain root, so a replaced .htaccess breaks a subdirectory install
        // visibly. The row appears only for a live directive (amber when it is not
        // this install's index.php); a commented-out one or an ignored .htaccess
        // shows nothing. Skipped during installation, which rewrites it last.
        $errorDoc =
            $readsHtaccess && empty($ctx["installer"])
                ? self::errorDocument403((string) ($ctx["root"] ?? ""))
                : null;
        if ($errorDoc !== null) {
            $wanted403 = self::errorDocumentPath((string) ($ctx["base"] ?? ""));
            $out["custom_403"] = [
                "label" => "Custom 403 page",
                "ok" => $errorDoc === $wanted403,
                "hint" => "req_hint_custom_403",
                // The directive itself, untranslated so it can be copied into
                // .htaccess. Renderers print it after the hint when not ok.
                "detail" => self::errorDocumentLine((string) ($ctx["base"] ?? "")),
            ];
            // Offered as a button where the dashboard can make the edit itself.
            if (
                $errorDoc !== $wanted403 &&
                self::errorDocumentFixable((string) ($ctx["root"] ?? ""))
            ) {
                $out["custom_403"]["fix"] = "custom_403";
            }
        }

        $out["client_ip"] = [
            "label" => "Client IP resolution",
            "ok" => !self::proxyIpUnresolved(
                (array) ($ctx["trusted_proxies"] ?? []),
                $server
            ),
            "hint" => "req_hint_client_ip",
        ];

        // Listed by topic, not in the order computed: files, PHP limits, the
        // web server, optional features.
        $out = array_replace(array_intersect_key(array_flip(self::ADVISORY_ORDER), $out), $out);
        return self::inGroup("advisory", $out);
    }

    /**
     * The marked block in the shipped .htaccess and the directive inside it.
     * The leading '#' is optional so a commented-out line is re-enabled
     * rather than left behind. Markers are matched by their words, not the
     * surrounding dashes, so editing that decoration does not break matching.
     */
    private const ERROR_DOCUMENT_BLOCK =
        '#(^\#[^\n]*Custom 403 page[^\n]*$.*?^)[ \t]*\#?[ \t]*ErrorDocument[ \t]+403[ \t]+\S+[ \t]*(\R.*?^\#[^\n]*end Custom 403 page[^\n]*$)#ms';

    /** The ErrorDocument directive an installation at $base needs. */
    public static function errorDocumentLine(string $base): string
    {
        return "ErrorDocument 403 " . self::errorDocumentPath($base);
    }

    /**
     * URL path of this installation's index.php, which answers a refused
     * request with the 403 page (AESBIN_REFUSED in bootstrap.php). $base is
     * decoded (it comes from SCRIPT_NAME), so each segment is percent-encoded:
     * Apache splits the directive on spaces, and an install folder named
     * "my files" would otherwise write a line that fails to parse and takes
     * the site down.
     */
    private static function errorDocumentPath(string $base): string
    {
        $trimmed = rtrim($base, "/");
        $encoded = $trimmed === ""
            ? ""
            : implode("/", array_map("rawurlencode", explode("/", $trimmed)));
        return $encoded . "/index.php";
    }

    /**
     * Whether wireErrorDocument() has something it may change: a writable
     * .htaccess with the shipped block still in it.
     */
    public static function errorDocumentFixable(string $root): bool
    {
        $file = $root . "/public/.htaccess";
        if ($root === "" || !is_file($file) || !is_writable($file)) {
            return false;
        }
        $body = @file_get_contents($file);
        return $body !== false &&
            preg_match(self::ERROR_DOCUMENT_BLOCK, $body) === 1;
    }

    /**
     * Point the .htaccess ErrorDocument at this installation's index.php
     * (the installer's last step, and the dashboard after an upgrade). Only
     * the path inside the marked block changes. False when nothing could be
     * written and the directive is not already right; callers then show
     * errorDocumentLine().
     */
    public static function wireErrorDocument(string $root, string $base): bool
    {
        $file = $root . "/public/.htaccess";
        if (!self::errorDocumentFixable($root)) {
            // Not ours to overwrite; report whether it happens to be right.
            return $root !== "" &&
                self::errorDocument403($root) === self::errorDocumentPath($base);
        }
        $body = @file_get_contents($file);
        if ($body === false) {
            return false;
        }
        $patched = preg_replace_callback(
            self::ERROR_DOCUMENT_BLOCK,
            static fn(array $m): string => $m[1] .
                self::errorDocumentLine($base) .
                $m[2],
            $body,
            1
        );
        if ($patched === null) {
            return false;
        }
        if ($patched === $body) {
            return true;
        }

        // Staged and renamed: a truncated .htaccess is a 500 on every URL,
        // including the one the owner would use to fix it. A fresh temp file
        // does not inherit permissions, so they are copied from the original.
        $perms = @fileperms($file);
        $tmp = staging_path($file);
        if (file_put_contents($tmp, $patched, LOCK_EX) !== strlen($patched)) {
            @unlink($tmp);
            return false;
        }
        if ($perms !== false) {
            @chmod($tmp, $perms & 0777);
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * The path from an active "ErrorDocument 403 <path>" line in the
     * application's .htaccess, or null when there is none or the file is
     * unreadable. Only the local-path form is read; a quoted message or an
     * absolute URL means the owner chose something else.
     */
    private static function errorDocument403(string $root): ?string
    {
        if ($root === "") {
            return null;
        }
        $file = $root . "/public/.htaccess";
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $path = null;
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            // No break: the server acts on the last occurrence.
            if (
                preg_match(
                    '#^[ \\t]*ErrorDocument[ \\t]+403[ \\t]+(/\\S*)[ \\t]*\\z#i',
                    $line,
                    $m
                ) === 1
            ) {
                $path = $m[1];
            }
        }
        return $path;
    }

    /**
     * Whether opcache_invalidate() can actually be called from here.
     * opcache.restrict_api limits the API to scripts under a path prefix, and
     * calls from elsewhere fail with a warning. SCRIPT_FILENAME approximates
     * the caller; realpath() because it can be relative under CLI.
     */
    public static function opcacheInvalidateUsable(): bool
    {
        if (!function_exists("opcache_invalidate")) {
            return false;
        }
        $restrict = (string) ini_get("opcache.restrict_api");
        if ($restrict === "") {
            return true;
        }
        $caller = realpath((string) ($_SERVER["SCRIPT_FILENAME"] ?? ""));
        return $caller !== false && stripos($caller, $restrict) === 0;
    }

    /**
     * Whether a reverse proxy is hiding the visitor's address. True only on
     * positive evidence: no trusted proxies, yet an X-Forwarded-For whose
     * client entry differs from REMOTE_ADDR. False on hosts that restore the
     * address before PHP runs, where an empty trusted_proxies is correct.
     *
     * The installer also uses this to decide whether to seed that list.
     * Seeding it when this is false would make the VISITOR a trusted proxy,
     * and a forged X-Forwarded-For from that address would be believed.
     */
    public static function proxyIpUnresolved(array $trustedProxies, ?array $server = null): bool
    {
        if ($trustedProxies !== []) {
            return false;
        }
        $server ??= $_SERVER;
        $remote = unmap_ipv4((string) ($server["REMOTE_ADDR"] ?? ""));
        $client = unmap_ipv4(trim(explode(",", (string) ($server["HTTP_X_FORWARDED_FOR"] ?? ""))[0]));
        return $remote !== "" &&
            $client !== "" &&
            $client !== $remote &&
            filter_var($client, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Whether the web server reads .htaccess: Apache and LiteSpeed do, nginx,
     * Caddy and others do not. Every deny rule in the project lives in one.
     * The one place this is decided, for the requirements row, the installer
     * and the "Custom 403 page" fix.
     */
    public static function serverReadsHtaccess(?array $server = null): bool
    {
        $server ??= $_SERVER;
        $srv = (string) ($server["SERVER_SOFTWARE"] ?? "");
        return stripos($srv, "apache") !== false || stripos($srv, "litespeed") !== false;
    }

    /**
     * php.ini shorthand ("512M", "2G") to bytes. Returns -1 for "" or "-1";
     * any other value, "0" included, is converted as written.
     */
    public static function iniBytes(string $key): int
    {
        $raw = trim((string) ini_get($key));
        if ($raw === "" || $raw === "-1") {
            return -1;
        }
        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;
        return match ($unit) {
            "g" => $value * 1073741824,
            "m" => $value * 1048576,
            "k" => $value * 1024,
            default => $value,
        };
    }
}
