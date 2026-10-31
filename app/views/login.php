<?php

/**
 * app/views/login.php — login page: password, optional passkey, then TOTP.
 *
 * Bot defences: a per-install honeypot field name, the self-hosted ALTCHA
 * proof-of-work widget, and a countdown when the server answers 429.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$honeypotName = $security->honeypotName();
$captchaEnabled = $security->captchaOperational();
$captchaShowFooter = $security->captchaShowFooter();
$captchaShowLogo = $security->captchaShowLogo();

$pageTitle = __("login");
$pageCss   = ["base.css", "auth.css"];
$bodyClass = "card-body";
$ogPath        = "login";
$ogDescription = __("og_login_desc");
require __DIR__ . "/partials/document-start.php";
?>

<main class="card">
    <?= logo_link("card-logo") ?>
    <!-- Password step: pointless without JS, hidden via .no-js -->
    <div id="step-pw" class="js-required">
        <label class="field-label" for="inp-password"><?= e(__("password")) ?></label>
        <input id="inp-password" type="password" class="field-input" data-pw autocomplete="current-password">

        <?php if ($captchaEnabled): ?>
        <!-- ALTCHA: official widget, auto-solving PoW on load -->
        <altcha-widget id="altcha" challenge="<?= e(base_url("api/auth/captcha/challenge")) ?>" auto="onload" configuration='{"hideFooter":<?= $captchaShowFooter ? "false" : "true" ?>,"hideLogo":<?= $captchaShowLogo ? "false" : "true" ?>}'></altcha-widget>
        <?php endif; ?>

        <button id="btn-login" class="btn btn-primary btn-full" disabled><?= e(__("login")) ?></button>
        <?php /* Passkey button only when at least one passkey is registered. */ ?>
        <?php if (webauthn_data($db)["credentials"] !== []): ?>
        <div class="passkey-section">
            <button id="btn-passkey-login" class="btn btn-ghost btn-full">
                <?= icon("key-round") ?> <?= e(__("passkey_login")) ?>
            </button>
        </div>
        <?php endif; ?>
        <?php /* Hidden while recovery is disabled: /recovery redirects back here. */ ?>
        <?php if (AESBIN_RECOVERY_ENABLED): ?>
        <a href="<?= e(base_url("recovery")) ?>" class="link-sm link-warning"><?= e(__("use_recovery")) ?></a>
        <?php endif; ?>
    </div>

    <!-- TOTP step (hidden until needed) -->
    <div id="step-totp" hidden>
        <p class="card-hint"><?= e(__("totp_code")) ?></p>
        <input id="inp-totp" type="text" aria-label="<?= e(__("totp_code")) ?>" inputmode="numeric" maxlength="6" class="field-input field-otp" placeholder="123456" autocomplete="one-time-code">
        <button id="btn-totp" class="btn btn-primary btn-full" disabled><?= e(__("confirm")) ?></button>
    </div>

    <!-- Key mismatch warning. It points at the recovery phrase, so with
         recovery disabled it explains how to enable it. -->
    <div id="mismatch-banner" class="alert alert-danger" hidden>
        <?= e(__("key_mismatch")) ?>
        <?php if (AESBIN_RECOVERY_ENABLED): ?>
        <br><a href="<?= e(base_url("recovery")) ?>" class="link-sm link-warning"><?= e(__("use_recovery")) ?></a>
        <?php else: ?>
        <br><span class="hint-sm"><?= e(__("recovery_disabled_hint")) ?></span>
        <?php endif; ?>
    </div>

    <!-- Rate-limit countdown -->
    <div id="lockout-banner" class="alert alert-warning" hidden>
        <span id="lockout-msg"></span>
    </div>

    <div id="login-err" class="alert alert-danger" hidden></div>
</main>

<!-- Honeypot: hidden, must stay empty. Name is per-install so scanners can't hardcode it. -->
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
$pageJs = ["helper.js", "passkey.js", "auth.js"];
require __DIR__ . "/partials/document-end.php";
