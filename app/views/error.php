<?php

/**
 * app/views/error.php — the 404 page (no route matched, router.php) and the
 * 403 page (a request the .htaccess deny rules refused, bootstrap.php).
 *
 * Uses the login/recovery card layout. Unlike those pages it sets
 * $skipCsrfToken (no session here, see AESBIN_SESSION_ROUTE in bootstrap.php)
 * and $requiresJs = false, so it renders in curl and text browsers.
 *
 * Input: int $errorCode, 403 or 404.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

[$errorTitle, $errorMessage] = $errorCode === 403
    ? [__("err_403_title"), __("err_403_message")]
    : [__("err_404_title"), __("err_404_message")];
$pageTitle = $errorTitle;
$pageCss   = ["base.css", "error.css"];
$bodyClass = "card-body";
$requiresJs = false;
$pageJs    = [];
$skipCsrfToken = true;

require __DIR__ . "/partials/document-start.php";
?>

<main class="card error-card">
    <?= logo_link("card-logo") ?>

    <p class="error-code" aria-hidden="true"><?= (int) $errorCode ?></p>
    <h1 class="error-title"><?= e($errorTitle) ?></h1>
    <p class="card-hint"><?= e($errorMessage) ?></p>
    <?php // The address asked for (for a 403, the one refused: an ErrorDocument
          // keeps it in REQUEST_URI). AESBin runs on HTTPS only, and
          // host_label() keeps the Host header to host characters. ?>
    <p class="request-url"><?= e("https://" . host_label() . (string) ($_SERVER["REQUEST_URI"] ?? "/")) ?></p>

    <a class="btn btn-primary btn-full" href="<?= e(base_url()) ?>">
        <?= e(__("err_back")) ?>
    </a>
</main>

<?php $footerCenter = true; require __DIR__ . "/partials/page-footer.php"; ?>

<?php require __DIR__ . "/partials/document-end.php"; ?>
