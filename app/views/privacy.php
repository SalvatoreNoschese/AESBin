<?php

/**
 * app/views/privacy.php — public privacy & cookie policy page.
 *
 * Variables set by router.php:
 *   $availableLangs, $privacyLang,
 *   $privacyLogItemCap, $privacyName, $privacyAddress, $privacyVat, $privacyEmail,
 *   $contactLink, $contactReferrer
 *
 * The sections (privacy_* keys in app/lang/*.php) describe what stock AESBin
 * does: session cookie, IP in the activity log, encryption at rest. The
 * data-controller fields (settings → privacy card) are plain text, always
 * escaped; the page takes no raw HTML.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$pageTitle     = __("privacy_title");
$pageCss       = ["base.css", "privacy.css"];
$bodyClass     = "aesbin-page";
$requiresJs    = false;
$htmlLang      = $privacyLang;
$ogPath        = "privacy";
$ogDescription = __("privacy_intro");
// No session on this page; see partials/document-end.php.
$skipCsrfToken = true;
// The cookie table's delete links (visitor_cookie_forget()).
$forgetUrl = fn(string $cookie): string => base_url("privacy") . "?forget_cookie=" . $cookie;

require __DIR__ . "/partials/document-start.php";
?>

<div>
    <header class="aesbin-header">
        <?= logo_link("aesbin-header-logo") ?>
        <div class="aesbin-header-right">
            <?php if (count($availableLangs) > 1): ?>
            <form class="privacy-lang-form" method="get" action="<?= e(base_url("privacy")) ?>">
                <?= lang_select($privacyLang, [
                    "class" => "page-lang-sel",
                    "id" => "privacy-lang-sel",
                    "name" => "lang",
                    "aria-label" => __("language"),
                ], base_url("")) ?>
                <!-- privacy.js submits on change; without JS this button does. -->
                <noscript>
                    <button type="submit" class="btn btn-ghost privacy-lang-go-btn" aria-label="<?= e(__("language")) ?>">&raquo;</button>
                </noscript>
            </form>
            <?php endif; ?>
        </div>
    </header>

    <main class="aesbin-container">
        <h1 class="privacy-page-title"><?= e(__("privacy_title")) ?></h1>

        <?php if ($privacyName !== "" || $privacyAddress !== "" || $privacyVat !== "" || $privacyEmail !== ""): ?>
        <section class="privacy-section privacy-holder">
            <h2><?= e(__("data_controller")) ?></h2>
            <?php if ($privacyName !== ""): ?>
            <p><?= e($privacyName) ?></p>
            <?php endif; ?>
            <?php if ($privacyAddress !== ""): ?>
            <p><?= e($privacyAddress) ?></p>
            <?php endif; ?>
            <?php if ($privacyVat !== ""): ?>
            <p><?= e(__("privacy_vat_prefix")) ?> <?= e($privacyVat) ?></p>
            <?php endif; ?>
            <?php if ($privacyEmail !== ""): ?>
            <p><span class="privacy-email-js" data-js-required="<?= e(__("privacy_email_js_required")) ?>"><?= obfuscate_mailto($privacyEmail, $privacyEmail) ?></span></p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <p class="privacy-intro"><?= e(__("privacy_intro")) ?></p>

        <section class="privacy-section" id="cookie">
            <h2><?= e(__("privacy_s_cookie_title")) ?></h2>
            <p><?= e(__("privacy_s_cookie_body")) ?></p>
            <?php // Every cookie AESBin can set, and whether this browser holds it
                  // now. Only AESBin's: others on the same domain are not ours to
                  // list. Only the language one is deleted from here: the session
                  // one is recreated by the next owner page anyway (logout starts
                  // a fresh, empty session; see Auth::logout()). ?>
            <div class="privacy-table-wrap">
                <table class="privacy-table">
                    <thead>
                        <tr>
                            <th><?= e(__("privacy_ck_col_name")) ?></th>
                            <th><?= e(__("privacy_ck_col_purpose")) ?></th>
                            <th><?= e(__("privacy_ck_col_duration")) ?></th>
                            <th><?= e(__("privacy_ck_col_here")) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td data-label="<?= e(__("privacy_ck_col_name")) ?>"><code><?= e(SESSION_COOKIE) ?></code></td>
                            <td data-label="<?= e(__("privacy_ck_col_purpose")) ?>"><?= e(__("privacy_ck_session_purpose")) ?></td>
                            <td data-label="<?= e(__("privacy_ck_col_duration")) ?>"><?= e(__("privacy_ck_session_duration")) ?></td>
                            <td data-label="<?= e(__("privacy_ck_col_here")) ?>"><?= isset($_COOKIE[SESSION_COOKIE])
                                ? '<span class="privacy-ck-present">' . e(__("privacy_ck_present")) . "</span>"
                                : e(__("privacy_ck_absent")) ?></td>
                        </tr>
                        <tr>
                            <td data-label="<?= e(__("privacy_ck_col_name")) ?>"><code><?= e(VISITOR_LANG_COOKIE) ?></code></td>
                            <td data-label="<?= e(__("privacy_ck_col_purpose")) ?>"><?= e(__("privacy_ck_lang_purpose")) ?></td>
                            <td data-label="<?= e(__("privacy_ck_col_duration")) ?>"><?= e(__("privacy_ck_lang_duration")) ?></td>
                            <td data-label="<?= e(__("privacy_ck_col_here")) ?>">
                                <?php if (isset($_COOKIE[VISITOR_LANG_COOKIE])): ?>
                                <span class="privacy-ck-present"><?= e(__("privacy_ck_present")) ?></span>
                                <?php // Its value too: a language code, nothing secret, and seeing it
                                      // shows it holds nothing else. Shown as sent, escaped and cut, so
                                      // a hand-edited value reads as what it is. The session cookie's
                                      // value is never printed: it is the session itself. ?>
                                <?php // is_string(): a cookie named with [x] after it arrives as an array. ?>
                                (<code><?= e(is_string($_COOKIE[VISITOR_LANG_COOKIE]) ? mb_substr($_COOKIE[VISITOR_LANG_COOKIE], 0, 16) : "?") ?></code>)
                                <?php // ?forget_cookie=lang deletes it; see visitor_cookie_forget(). ?>
                                · <a href="<?= e($forgetUrl("lang")) ?>"><?= e(__("privacy_cookie_delete")) ?></a>
                                <?php else: ?>
                                <?= e(__("privacy_ck_absent")) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <?php // One per link opened in the last hour (share_session_cookie()):
                                  // the name ends with the link's token, shown as "…". The value
                                  // is never printed: it is the session itself. ?>
                            <?php $shareCookies = count(share_session_cookies()); ?>
                            <td data-label="<?= e(__("privacy_ck_col_name")) ?>"><code><?= e(SHARE_SESSION_COOKIE_PREFIX) ?>…</code></td>
                            <td data-label="<?= e(__("privacy_ck_col_purpose")) ?>"><?= e(__("privacy_ck_share_purpose")) ?></td>
                            <td data-label="<?= e(__("privacy_ck_col_duration")) ?>"><?= e(__("privacy_ck_share_duration")) ?></td>
                            <td data-label="<?= e(__("privacy_ck_col_here")) ?>">
                                <?php if ($shareCookies > 0): ?>
                                <span class="privacy-ck-present"><?= e(__("privacy_ck_present")) ?></span> (<?= $shareCookies ?>)
                                <?php // ?forget_cookie=share ends the visits; see visitor_cookie_forget(). ?>
                                · <a href="<?= e($forgetUrl("share")) ?>"><?= e(__("privacy_cookie_delete")) ?></a>
                                <?php else: ?>
                                <?= e(__("privacy_ck_absent")) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="privacy-section">
            <h2><?= e(__("privacy_s_log_title")) ?></h2>
            <p><?= e(__("privacy_s_log_body", ["rows" => $privacyLogItemCap, "days" => ACTIVITY_UNAUTH_TTL_DAYS])) ?></p>
        </section>

        <section class="privacy-section">
            <h2><?= e(__("privacy_s_files_title")) ?></h2>
            <p><?= e(__("privacy_s_files_body")) ?></p>
        </section>
    </main>

    <?php
    $footerPrivacy = false;
    require __DIR__ . "/partials/page-footer.php";
    ?>
</div>

<?php
$jsVars = [];
$pageJs = count($availableLangs) > 1 ? ["lang-select.js", "privacy.js"] : [];
$pageJs[] = "gotop.js";
require __DIR__ . "/partials/document-end.php";
