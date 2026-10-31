<?php

/**
 * app/views/recovery.php — account recovery page.
 *
 * Resets any combination of password, TOTP secret and the recovery phrase
 * itself, unlocked by the current 10-word phrase.
 *
 * Step 0 is the guard file: a code that exists only in a randomly named file
 * on the server, so reaching the phrase field takes FTP/SFTP/SSH access. Only
 * the directory name is shown; the filename and the code are never sent in
 * any response.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$honeypotName = $security->honeypotName();
$captchaEnabled = $security->captchaOperational();
$captchaShowFooter = $security->captchaShowFooter();
$captchaShowLogo = $security->captchaShowLogo();

$pageTitle = __("recovery");
$pageCss   = ["base.css", "auth.css"];
$bodyClass = "card-body";
$ogPath        = "recovery";
$ogDescription = __("og_recovery_desc");
$headExtra     = '<script src="' . e(asset("vendor/kjua/kjua.min.js")) . '" defer></script>';
require __DIR__ . "/partials/document-start.php";
?>

<main class="card">
    <?= logo_link("card-logo") ?>
    <h2 class="recovery-subtitle js-required"><?= e(__("recovery")) ?></h2>
    <p id="recovery-hint-intro" class="card-hint js-required" hidden><?= e(__("recovery_hint")) ?></p>

    <!-- Step 0: guard code. Pointless without JS, hidden via .no-js -->
    <div id="step-guard" class="js-required">
        <p class="card-hint card-hint-spaced"><?= e(__("recovery_guard_hint")) ?></p>
        <p class="hint-sm"><?= e(__("recovery_guard_where", ["dir" => RECOVERY_GUARD_DIR])) ?></p>
        <label class="field-label" for="inp-guard"><?= e(__("recovery_guard_code")) ?></label>
        <input id="inp-guard" class="field-input" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="8" placeholder="A1B2C3D4">
        <button id="btn-verify-guard" class="btn btn-primary btn-full" disabled>
            <?= e(__("continue")) ?>
        </button>
    </div>

    <!-- Step 1: phrase, reachable only once the guard code has been accepted -->
    <div id="step-phrase" class="js-required" hidden>
        <label class="field-label" for="inp-phrase">
            <?= e(__("recovery_phrase")) ?>
        </label>
        <textarea id="inp-phrase" class="field-input field-textarea" rows="4" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" placeholder="abandon ability able about …"></textarea>

        <?php if ($captchaEnabled): ?>
        <!-- ALTCHA: see login.php. -->
        <altcha-widget id="altcha" challenge="<?= e(base_url('api/auth/captcha/challenge')) ?>" auto="onload" configuration='{"hideFooter":<?= $captchaShowFooter ? "false" : "true" ?>,"hideLogo":<?= $captchaShowLogo ? "false" : "true" ?>}'></altcha-widget>
        <?php endif; ?>

        <button id="btn-verify-phrase" class="btn btn-primary btn-full" disabled>
            <?= e(__("continue")) ?>
        </button>
    </div>

    <!-- Step 2: choose what to reset -->
    <div id="step-choose" hidden>
        <p class="card-hint card-hint-spaced">
            <?= e(__("recovery_phrase_verified")) ?>
        </p>

        <label class="field-check-row">
            <input type="checkbox" id="chk-reset-pw" checked>
            <?= e(__("new_password")) ?>
        </label>

        <div id="wrap-new-pw" class="recovery-wrap-pw">
            <label class="field-label" for="inp-newpw"><?= e(__("new_password")) ?></label>
            <input id="inp-newpw" type="password" class="field-input" data-pw autocomplete="new-password">
            <?= pw_meter() ?>
            <label class="field-label" for="inp-newpw2"><?= e(__("password_confirm")) ?></label>
            <input id="inp-newpw2" type="password" class="field-input" data-pw autocomplete="new-password">
        </div>

        <label class="field-check-row recovery-totp-check">
            <input type="checkbox" id="chk-reset-totp">
            <?= e(__("reset_totp_label")) ?>
        </label>

        <!-- Shown when the box above is ticked. The new secret is shown and a
             code from the authenticator verified before anything is written;
             confirm stays locked until then (as in the installer). -->
        <div id="wrap-new-totp" hidden>
            <p class="hint-sm card-hint-spaced-sm"><?= e(__("recovery_totp_generated")) ?></p>
            <div id="recovery-totp-qr" class="recovery-totp-qr"></div>
            <div id="recovery-totp-secret" class="totp-secret-box"></div>
            <button type="button" class="copy-btn copy-btn-center" data-copy-target="recovery-totp-secret">
                <?= icon("copy") ?> <?= e(__("copy")) ?>
            </button>
            <label class="field-label" for="inp-new-totp"><?= e(__("totp_code")) ?></label>
            <input id="inp-new-totp" type="text" inputmode="numeric" maxlength="6" class="field-input field-otp" placeholder="123456" autocomplete="one-time-code">
            <button type="button" id="btn-verify-new-totp" class="btn btn-ghost btn-full">
                <?= e(__("recovery_totp_verify_btn")) ?>
            </button>
            <p id="new-totp-status" class="hint-sm" aria-live="polite" hidden></p>
        </div>

        <label class="field-check-row recovery-totp-check">
            <input type="checkbox" id="chk-rotate-phrase">
            <?= e(__("rotate_phrase_label")) ?>
        </label>

        <!-- Shown when the box above is ticked. The new phrase is displayed
             before anything is written, and confirm stays locked until it has
             been copied or downloaded and acknowledged (as in the installer). -->
        <div id="wrap-new-phrase" hidden>
            <p class="hint-sm recovery-save-warning">
                <?= icon("triangle-alert", "ic-warn") ?> <?= e(__("rotate_phrase_warning")) ?>
            </p>
            <div id="new-phrase-box" class="totp-secret-box"></div>
            <p id="new-phrase-status" class="hint-sm" aria-live="polite" hidden></p>
            <div class="recovery-phrase-actions">
                <button type="button" class="copy-btn" id="btn-copy-new-phrase" data-copy-target="new-phrase-box">
                    <?= icon("copy") ?> <?= e(__("copy")) ?>
                </button>
                <button type="button" class="copy-btn" id="btn-dl-new-phrase">
                    <?= icon("download") ?> <?= e(__("rotate_phrase_download")) ?>
                </button>
            </div>
            <label class="field-check-row">
                <input type="checkbox" id="chk-phrase-saved" disabled>
                <?= e(__("rotate_phrase_saved")) ?>
            </label>
        </div>

        <?php if ($captchaEnabled): ?>
        <!-- The widget is in the (now hidden) phrase step; this explains why the
             button waits while it solves a fresh token. Set by auth.js
             (checkResetGate). -->
        <p id="recovery-captcha-wait" class="hint-sm" aria-live="polite" hidden></p>
        <?php endif; ?>
        <!-- Recovery always ends every session and removes every passkey
             (Auth::executeRecovery()). -->
        <p class="hint-sm"><?= icon("info") ?> <?= e(__("recovery_resets_note")) ?></p>
        <button id="btn-execute-recovery" class="btn btn-primary btn-full">
            <?= e(__("confirm")) ?>
        </button>
    </div>

    <!-- Rate-limit countdown -->
    <div id="recovery-lockout-banner" class="alert alert-warning" hidden>
        <span id="recovery-lockout-msg"></span>
    </div>

    <div id="recovery-err" class="alert alert-danger" hidden></div>
    <div id="recovery-ok" class="alert alert-success" hidden></div>

    <a href="<?= e(base_url("login")) ?>" id="recovery-back" class="link-sm js-required">
        <?= e(__("back")) ?>
    </a>
</main>

<!-- Honeypot -->
<div class="hp-wrap" aria-hidden="true">
    <input type="text" name="<?= e($honeypotName) ?>" id="<?= e($honeypotName) ?>" tabindex="-1" autocomplete="off" value="">
</div>

<?php $footerCenter = true; require __DIR__ . "/partials/page-footer.php"; ?>

<?php
$jsVars = [
    "AESBIN_BASE" => AESBIN_BASE,
    "AESBIN_HP_NAME" => $honeypotName,
    "AESBIN_CAPTCHA" => ["enabled" => $captchaEnabled],
];
$pageJs = ["helper.js", "auth.js"];
require __DIR__ . "/partials/document-end.php";
