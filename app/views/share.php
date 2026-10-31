<?php

/**
 * app/views/share.php — public share viewer.
 *
 * Security model:
 *   - Token (path)  → sent to server, looks up the share record
 *   - KEY  (fragment #KEY) → not part of the requested URL, so it stays out of
 *     logs and Referer headers; share.js sends it once in the /api/share/auth
 *     body, and decryption happens server-side
 *
 * Variables set by router.php:
 *   $shareToken, $honeypotName, $availableLangs, $shareLang,
 *   $privacyEnabled, $contactLink, $contactReferrer
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$pageTitle     = __("share_files");
$pageCss       = ["base.css", "preview.css", "share.css"];
$bodyClass     = "aesbin-page";
$htmlLang      = $shareLang;
$ogPath        = "s/" . $shareToken;
$ogDescription = __("og_share_desc");
// Visitors are anonymous, so outbound links must not reveal which instance
// sent them. A meta tag, so it does not conflict with the app-wide header.
$headExtra = '<meta name="referrer" content="no-referrer">';

// No token, and no session minted per anonymous visitor; see
// partials/document-end.php.
$skipCsrfToken = true;

require __DIR__ . "/partials/document-start.php";
?>

<!-- File viewer: before the cards, which share.css hides, footer included,
     while it shows. -->
<main id="aesbin-viewer" hidden>
    <header class="aesbin-header">
        <?= logo_link("aesbin-header-logo") ?>
        <div class="aesbin-header-right">
            <span id="sv-share-url" hidden></span>
            <?php // "Copy link" without JavaScript and on desktop; share.js turns it
                  // into "Share" (the phone's own share sheet) where that works. ?>
            <button type="button" class="copy-btn" id="sv-share-btn" data-copy-target="sv-share-url">
                <?= icon("link") ?> <?= e(__("copy_link")) ?>
            </button>
            <?php if (count($availableLangs) > 1): ?>
            <?= lang_select($shareLang, [
                "class" => "page-lang-sel",
                "id" => "sv-lang-sel",
                "aria-label" => __("language"),
            ], base_url("")) ?>
            <?php endif; ?>
        </div>
    </header>
    <div class="aesbin-container">
        <div id="aesbin-dir-hdr" class="sv-dir-hdr" hidden>
            <div class="sv-title-row">
                <p class="sv-dir-hdr-name" id="aesbin-dir-name"></p>
                <?php // Password links only (share.js): ends this link's visit
                      // (visitor_cookie_forget()), so the password is asked again.
                      // Beside the folder's name, or moved beside the file's. ?>
                <button type="button" class="btn btn-ghost btn-sm" id="sv-exit-btn" hidden>
                    <?= icon("log-out") ?> <?= e(__("share_exit")) ?>
                </button>
            </div>
            <p id="aesbin-dir-desc" class="hint-sm sv-dir-desc" hidden></p>
        </div>
        <?= sort_bar("sv-sort-bar", ["name", "date", "size"]) ?>
        <div class="file-list" id="aesbin-file-list"></div>
        <div id="aesbin-no-files" class="empty-state" hidden>
            <?= e(__("share_dir_empty")) ?>
        </div>
    </div>
    <?php require __DIR__ . "/partials/page-footer.php"; ?>
</main>

<?php // The steps before the files (loading, key, password, error): one card at
      // a time, with the footer of the login and 404 pages under it. ?>
<div class="sv-cards">
<!-- Loading -->
<main id="aesbin-loading" class="aesbin-screen">
    <div class="card">
        <?= logo_link("card-logo") ?>
        <p class="card-hint js-required">
            <?= e(__("loading")) ?>
        </p>
    </div>
</main>

<!-- Key input (no #KEY in URL) -->
<main id="aesbin-key-screen" class="aesbin-screen" hidden>
    <div class="card">
        <?= logo_link("card-logo") ?>
        <p class="card-hint"><?= e(__("share_key_hint")) ?></p>
        <?php /* Masked like the password below, and for the same reason
           text and not type="password": a link's key does not belong in a
           password manager. */ ?>
        <input id="aesbin-key-inp" type="text" class="field-input" data-pw data-pw-mask
               aria-label="<?= e(__("share_key_label")) ?>"
               placeholder="<?= e(__("share_key_label")) ?>" autocomplete="off" autocapitalize="off"
               spellcheck="false" data-lpignore="true" data-1p-ignore
               data-bwignore="true" data-form-type="other">
        <button id="aesbin-key-btn" class="btn btn-primary btn-full">
            <?= e(__("share_unlock")) ?>
        </button>
        <div id="aesbin-key-err" class="alert alert-danger" hidden></div>
    </div>
</main>

<!-- Password prompt -->
<main id="aesbin-pw-screen" class="aesbin-screen" hidden>
    <div class="card">
        <?= logo_link("card-logo") ?>
        <p class="card-hint"><?= e(__("share_enter_password")) ?></p>
        <!-- Masked text, not type="password": this is one link's passphrase,
             not a site login, so password managers must not offer to save it.
             initPasswordToggles() in helper.js falls back to a real password
             field where CSS masking is unsupported. -->
        <input id="aesbin-pw-inp" type="text" class="field-input" data-pw data-pw-mask
               aria-label="<?= e(__("password")) ?>"
               placeholder="<?= e(__("password")) ?>" autocomplete="off" autocapitalize="off"
               spellcheck="false" data-lpignore="true" data-1p-ignore
               data-bwignore="true" data-form-type="other">
        <button id="aesbin-unlock-btn" class="btn btn-primary btn-full">
            <?= e(__("share_unlock")) ?>
        </button>
        <!-- The password is never stored; the visit (its cookie, an hour) keeps
             the link open on a reload. Say both. -->
        <p class="card-hint aesbin-pw-note"><?= e(__("share_password_not_stored")) ?></p>
        <div id="aesbin-pw-err" class="alert alert-danger" hidden></div>
    </div>
</main>

<!-- Error -->
<main id="aesbin-error-screen" class="aesbin-screen" hidden>
    <div class="card">
        <?= logo_link("card-logo") ?>
        <div class="alert alert-danger" id="aesbin-err-msg"></div>
    </div>
</main>
<?php $footerCenter = true; require __DIR__ . "/partials/page-footer.php"; ?>
</div>

<!-- Honeypot -->
<div class="hp-wrap" aria-hidden="true">
    <input type="text" name="<?= e($honeypotName) ?>" id="<?= e($honeypotName) ?>" tabindex="-1" autocomplete="off" value="">
</div>

<?php
$jsVars = [
    "SHARE_TOKEN" => $shareToken,
    "AESBIN_BASE" => AESBIN_BASE,
    "AESBIN_HP_NAME" => $honeypotName,
];
$pageJs = ["helper.js", "lang-select.js", "preview.js", "share.js", "gotop.js"];
require __DIR__ . "/partials/document-end.php";
