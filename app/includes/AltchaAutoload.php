<?php

/**
 * app/includes/AltchaAutoload.php — PSR-4 autoloader for the vendored ALTCHA PHP
 * library (altcha-org/altcha-lib-php, app/vendor/altcha-lib-php/).
 *
 * That vendor folder is the library's package root verbatim, so this file
 * provides what upstream's composer.json would: the PSR-4 mapping
 * "AltchaOrg\Altcha\" => app/vendor/altcha-lib-php/src/.
 *
 * Call register_altcha_autoloader() once before the first use of an
 * AltchaOrg\Altcha\* class (see SecurityService::verifyCaptcha()). Maps the
 * namespace, not a file list, so a library update needs no change here.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

function register_altcha_autoloader(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    spl_autoload_register(static function (string $class): void {
        $prefix = "AltchaOrg\\Altcha\\";
        if (str_starts_with($class, $prefix)) {
            $file =
                AESBIN_APP_DIR .
                "/vendor/altcha-lib-php/src/" .
                str_replace("\\", "/", substr($class, strlen($prefix))) .
                ".php";
            if (is_file($file)) {
                require $file;
            }
        }
    });
}
