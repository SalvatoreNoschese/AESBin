<?php

/**
 * public/index.php — AESBin entry point, the only PHP code in public/ (the
 * other index.php files are one-line stubs that stop directory listings).
 * The application, and the installer, sit one level up, out of the web root.
 *
 * AESBIN_APP lets every included file refuse direct access.
 */

declare(strict_types=1);

define("AESBIN_APP", true);

// No config.php yet: the installer, in app/setup/ out of the web root, answers
// every request until it has written one, except one the deny rules refused
// (AESBIN_REFUSED in bootstrap.php), which gets a bare 403.
if (!is_file(dirname(__DIR__) . "/config.php") && is_file(dirname(__DIR__) . "/app/setup/index.php")) {
    if (($_SERVER["REDIRECT_STATUS"] ?? "") === "403") {
        http_response_code(403);
        exit();
    }
    require dirname(__DIR__) . "/app/setup/index.php";
    exit();
}

require_once dirname(__DIR__) . "/app/includes/bootstrap.php";
require_once dirname(__DIR__) . "/app/includes/router.php";
