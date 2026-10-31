<?php

/**
 * app/setup/template.php — the installer's only template.
 *
 * app/setup/index.php makes every decision and then requires this file. The body
 * depends on whether index.php refused to go further:
 *
 *   $gateScreen set    the gate: no HTTPS, the guard file could not be
 *                      written, or the guard code has not been entered yet.
 *   $gateScreen unset  the requirements screen, then the five-step wizard
 *                      (hidden until the requirements are acknowledged,
 *                      absent when a blocking check failed).
 *
 * Markup only: no header(), no superglobals, no file reads. Status code,
 * security headers and CSP nonce are sent by index.php beforehand.
 *
 * Not built from app/views/partials/*: those need config.php, Lang and asset(),
 * none of which exist while the installer is reachable.
 */

declare(strict_types=1);

use AESBin\Requirements;

defined("AESBIN_APP") || exit;

$isGate = isset($gateScreen);
$gateForm = $isGate ? $gateScreen["form"] : null;

// The gate's nonce comes from setup_gate(); the wizard's is set with its CSP
// header at the end of index.php.
$nonce = $isGate ? $gateScreen["nonce"] : $setupNonce;
?>
<!DOCTYPE html>
<html lang="<?= e($_setupLang) ?>" class="no-js">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,interactive-widget=resizes-content">
<meta name="robots" content="noindex,nofollow">
<title>AESBin Setup</title>
<script src="<?= $baseUrlAttr ?>/assets/js/no-js.js<?= asset_version("assets/js/no-js.js") ?>"></script>
<?php if (!$isGate): ?>
<!-- kjua for TOTP QR code (MIT). Wizard only: the gate has no QR to draw. -->
<script src="<?= $baseUrlAttr ?>/vendor/kjua/kjua.min.js<?= asset_version("vendor/kjua/kjua.min.js") ?>"></script>
<?php endif; ?>
<link rel="stylesheet" href="<?= $baseUrlAttr ?>/assets/css/base.css<?= asset_version("assets/css/base.css") ?>">
<link rel="stylesheet" href="<?= $baseUrlAttr ?>/assets/css/setup.css<?= asset_version("assets/css/setup.css") ?>">
</head>
<body>
<?= icon_sprite() . "\n" ?>

<!-- ── No-JS overlay ── shown via .no-js-only when JS is unavailable.
     Applies to both screens: the wizard needs JavaScript at every step, and
     the gate's submit button stays disabled without it (see
     setup_looks_automated() in index.php). -->
<div class="noscript-overlay no-js-only">
    <div class="noscript-card">
        <noscript>
            <img class="noscript-img" src="<?= $baseUrlAttr ?>/assets/img/noscript.webp<?= asset_version("assets/img/noscript.webp") ?>" alt="<?= setup_t("noscript_msg") ?>">
            <div class="noscript-msg"><?= setup_t("noscript_msg") ?></div>
        </noscript>
    </div>
</div>

<main class="card">
    <h1><img class="logo-mark" src="<?= $baseUrlAttr ?>/assets/meta/favicon-96x96.png<?= asset_version("assets/meta/favicon-96x96.png") ?>" alt=""> AESBin Setup</h1>
    <p class="sub">
        <span data-t="setup_subtitle">
            <?= setup_t("setup_subtitle") ?>
        </span>
    </p>

<?php if ($isGate): ?>
    <!-- ══ GATE ═══════════════════════════════════════════════════════════ -->
    <?php if (count($_setupLangs) > 1): ?>
        <form method="get" class="setup-gate-lang">
            <?= lang_select($_setupLang, ["id" => "gate-lang", "class" => "setup-gate-lang-sel", "name" => "lang", "aria-label" => setup_tr("language")], $baseUrl . "/") ?>
            <noscript><button type="submit" class="btn">&raquo;</button></noscript>
        </form>
        <?php /* setup.js is not loaded on the gate and the CSP refuses inline
           onchange=, so the select is wired here under the nonce. */ ?>
        <script nonce="<?= e($nonce) ?>">
            document.getElementById('gate-lang')
                .addEventListener('change', function () { this.form.submit(); });
        </script>
        <script src="<?= $baseUrlAttr ?>/assets/js/lang-select.js<?= asset_version("assets/js/lang-select.js") ?>"></script>
        <?php endif; ?>

        <!-- Escaped already: setup_gate() builds these with setup_t(),
             which runs e(). Escaping again would print entities. -->
        <h2 class="setup-gate-title"><?= $gateScreen["title"] ?></h2>
        <p class="sub setup-gate-body"><?= $gateScreen["body"] ?></p>

        <?php if ($gateForm !== null): ?>
        <div class="alert alert-danger<?= $gateScreen["error"] !== "" ? " show" : "" ?>"><?= $gateScreen["error"] ?></div>

        <p class="setup-gate-filename">
            <?= setup_t("setup_guard_file_hint") ?><br>
            <!-- No part of the name is printed. Its random part is the last layer
                 of this defence when the file's own stub is not executed;
                 printing it would hand it to exactly the party it exists to
                 stop. See setup_guard_file() in index.php. -->
            <code>app/setup/_viewme/<span class="setup-gate-redacted">&hellip;</span>.php</code>
        </p>

        <form method="post" class="setup-gate-form" id="setup-gate-form">
            <!-- Honeypot, moved off-screen rather than display:none: some bots
                 skip hidden fields but fill in everything else. Its name derives
                 from the guard filename, so it differs per installation. No
                 label, as on the application's own honeypots: it is
                 aria-hidden, and only a bot ever reaches it. -->
            <div class="setup-gate-hp" aria-hidden="true">
                <input type="text" id="<?= e($gateForm["honeypot"]) ?>" name="<?= e($gateForm["honeypot"]) ?>" tabindex="-1" autocomplete="off" value="">
            </div>

            <input type="hidden" name="setup_guard_jsproof" id="setup-gate-jsproof" value="">
            <input type="text" name="setup_guard_code" id="setup-gate-code" required minlength="8" maxlength="8" pattern="[0-9A-Fa-f]{8}" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXXXXXX" aria-label="<?= setup_t("setup_guard_field") ?>">

            <!-- Starts disabled. The script below fills in the proof field and
                 enables the button only while the field holds eight hexadecimal
                 characters, the only shape a guard code has. Without
                 JavaScript it stays disabled, and a hand-made submission is
                 refused by setup_looks_automated() in index.php. -->
            <button type="submit" class="btn btn-primary" id="setup-gate-submit" disabled><?= setup_t("continue") ?></button>
        </form>

        <noscript><p class="setup-gate-foot"><?= setup_t("setup_guard_needs_js") ?></p></noscript>

        <script nonce="<?= e($nonce) ?>">
            (function () {
                var f = document.getElementById('setup-gate-jsproof');
                var b = document.getElementById('setup-gate-submit');
                var c = document.getElementById('setup-gate-code');
                if (!f || !b || !c) { return; }
                f.value = <?= json_encode($gateForm["jsproof"], JSON_FOR_SCRIPT) ?>;
                var sync = function () {
                    b.disabled = !/^[0-9A-Fa-f]{8}$/.test(c.value.trim());
                };
                c.addEventListener('input', sync);
                sync();
                c.focus();
            })();
        </script>
        <?php endif; ?>
<?php else: ?>
    <!-- ══ WIZARD ═════════════════════════════════════════════════════════ -->
    <?php
    // One list for blocking and advisory checks, so a host with problems
    // shows the whole picture.
    //
    // Union, not array_merge: for root_writable, the one key both lists
    // define, the left (blocking) entry wins, because at install time an
    // unwritable root stops config.php being written. helpers.php does the
    // opposite: once installed, it only stops the recovery gate closing itself.
    $allChecks = $reqs + $reqsSoft;
    $softFail = array_filter($reqsSoft, static fn(array $r): bool => !$r["ok"]);
    ?>
    <!-- Language first, above everything: the requirements screen has to be
         readable before the wizard it guards, so the select cannot sit inside
         step 1, which is hidden at that point. -->
    <div class="setup-lang-card">
        <label>
        <?= icon("globe") ?> <span data-t="language">
            <?= setup_t("language") ?>
        </span>
        </label>
        <?php if (count($_setupLangs) > 1): ?>
        <?= lang_select($_setupLang, ["id" => "locale", "class" => "setup-lang-select"], $baseUrl . "/", true) ?>
        <?php else: ?>
        <input type="hidden" id="locale" value="<?= e(array_key_first($_setupLangs) ?? "en") ?>">
        <?php endif; ?>
    </div>

    <!-- Pre-flight screen. Always expanded and always first: this answers
         "will this work on my host" before a single field is filled in. The
         wizard below stays hidden until it is acknowledged, so the list does
         not push the form off the bottom of a phone screen. -->
    <div id="step-check">
        <?php
        // data-t on a span, not the h2: the switcher replaces textContent,
        // which would take the icon with it.
        $checkKey = !$allOk
            ? "setup_reqs_some_fail"
            : ($softFail === [] ? "setup_reqs_all_ok" : "setup_reqs_some_warn");
        ?>
        <h2 class="<?= !$allOk ? "fail" : ($softFail === [] ? "ok" : "warn") ?>">
            <?= !$allOk ? icon("circle-x", "ic-err") : ($softFail === [] ? icon("circle-check", "ic-ok") : icon("triangle-alert", "ic-warn")) ?>
            <span data-t="<?= $checkKey ?>"><?= setup_t($checkKey) ?></span>
        </h2>
        <p class="reqs-env"><?= e(Requirements::environment()) ?></p>
        <?php /* Two groups: a failed required check stops the install; a
           failed advisory one lets it proceed at some cost. */ ?>
        <?php foreach ([true, false] as $_required): ?>
        <?php $_groupKey = $_required ? "reqs_group_required" : "reqs_group_advisory"; ?>
        <h3 class="reqs-group" data-t="<?= $_groupKey ?>"><?= setup_t($_groupKey) ?></h3>
        <ul class="reqs">
            <?php foreach ($allChecks as $key => $r): ?>
            <?php $blocking = isset($reqs[$key]); ?>
            <?php if ($blocking !== $_required): ?>
            <?php continue; ?>
            <?php endif; ?>
            <?php $_off = !$r["ok"] && !$blocking && !empty($r["optional"]); ?>
            <li class="<?= $r["ok"] ? "ok" : ($blocking ? "fail" : ($_off ? "off" : "warn")) ?>">
                <?= $r["ok"] ? icon("circle-check", "ic-ok") : ($blocking ? icon("circle-x", "ic-err") : ($_off ? icon("circle-minus", "ic-muted") : icon("triangle-alert", "ic-warn"))) ?>
                <?= e($r["label"]) ?>
                <?php if (!$r["ok"]): ?>
                <span class="reqs-hint" data-t="<?= e($r["hint"]) ?>"><?= e(setup_tr($r["hint"])) ?></span>
                <?php /* Outside the data-t span: the language switcher replaces
                   that span's text and would wipe the directive. */ ?>
                <?php if (!empty($r["detail"])): ?>
                <code class="reqs-detail"><?= e($r["detail"]) ?></code>
                <?php endif; ?>
                <?php elseif (!empty($r["detail_always"]) && !empty($r["detail"])): ?>
                <code class="reqs-detail"><?= e($r["detail"]) ?></code>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endforeach; ?>

        <?php /* Apart from the lists: the driver is known now, the server
           version only once step 1 connects. Without the driver the
           pdo_mysql row above already stops the install. */ ?>
        <?php $_dbNote = $reqs["pdo_mysql"]["ok"] ? "setup_req_db_note" : "setup_req_db_note_nodriver"; ?>
        <p class="<?= $reqs["pdo_mysql"]["ok"] ? "reqs-note" : "reqs-note fail" ?>">
            <?= $reqs["pdo_mysql"]["ok"] ? icon("info") : icon("circle-x", "ic-err") ?> <?= e(Requirements::databaseMinimum()) ?>
            <span class="reqs-hint" data-t="<?= e($_dbNote) ?>"><?= e(setup_tr($_dbNote)) ?></span>
        </p>

        <?php if (!$allOk): ?>
        <div class="alert alert-danger show">
            <span data-t="setup_reqs_fix">
                <?= setup_t("setup_reqs_fix") ?>
            </span>
        </div>
        <?php else: ?>
        <button class="btn btn-primary" id="btn-check">
            <span data-t="setup_continue"><?= setup_t("setup_continue") ?></span> →
        </button>
        <?php endif; ?>
    </div>

    <?php if ($allOk): ?>
    <div id="wizard" hidden>

    <!-- Step indicator -->
    <div class="steps">
        <?php foreach ([
            "setup_step_db",
            "setup_step_storage",
            "setup_step_pw2fa",
            "setup_step_recovery",
            "setup_step_install",
        ] as $_i => $_stepKey): ?>
        <div class="step<?= $_i === 0 ? " active" : "" ?>" id="si-<?= $_i + 1 ?>">
            <?= $_i + 1 ?>. <span data-t="<?= $_stepKey ?>"><?= setup_t($_stepKey) ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── STEP 1: Database ── -->
    <div class="step-panel active" id="panel-1">
        <fieldset>
            <legend>
                <span data-t="setup_db_legend"><?= setup_t("setup_db_legend") ?></span>
            </legend>
            <div class="row">
                <div>
                    <label for="db_host"><span data-t="setup_host"><?= setup_t("setup_host") ?></span></label>
                    <input type="text" id="db_host" value="localhost" autocomplete="off" spellcheck="false">
                </div>
                <div>
                    <label for="db_port"><span data-t="setup_port"><?= setup_t("setup_port") ?></span></label>
                    <input type="number" id="db_port" value="3306" min="1" max="65535">
                </div>
            </div>
            <label for="db_name"><span data-t="setup_db_name"><?= setup_t("setup_db_name") ?></span></label>
            <input type="text" id="db_name" placeholder="aesbin" autocomplete="off" spellcheck="false">
            <label for="db_user"><span data-t="setup_db_user"><?= setup_t("setup_db_user") ?></span></label>
            <input type="text" id="db_user" autocomplete="off" spellcheck="false">
            <label for="db_pass"><span data-t="password"><?= setup_t("password") ?></span></label>
            <input type="password" id="db_pass" data-pw autocomplete="new-password">
            <?php /* Generated, never typed: a random suffix is the point. Shown
               in the summary before installing. */ ?>
            <input type="hidden" id="db_prefix" value="<?= e($suggestedPrefix) ?>">
        </fieldset>

        <div id="global-err" class="alert alert-danger"></div>
        <div class="db-status" id="db-status"></div>
        <div class="step-actions">
            <button type="button" class="btn btn-primary" id="btn-step1">
                <span data-t="setup_test_btn"><?= setup_t("setup_test_btn") ?></span> →
            </button>
        </div>
    </div>

    <!-- ── STEP 2: Storage ── -->
    <div class="step-panel" id="panel-2">
        <fieldset>
            <legend>
                <span data-t="setup_storage"><?= setup_t("setup_storage") ?></span>
            </legend>
            <label for="storage_path" class="sr-only"><span data-t="setup_storage"><?= setup_t("setup_storage") ?></span></label>
            <input type="text" id="storage_path" value="<?= e($suggestedStorage) ?>" required autocomplete="off" spellcheck="false">
            <p class="hint">
                <span data-t="setup_storage_hint"><?= setup_t("setup_storage_hint") ?></span>
            </p>
        </fieldset>

        <div id="storage-err" class="alert alert-danger"></div>
        <!-- Non-blocking: shown once, and the next click continues. -->
        <div id="storage-warn" class="alert alert-warning"></div>
        <div class="step-actions">
            <button type="button" class="btn btn-ghost" id="btn-back2">
                ← <span data-t="setup_back"><?= setup_t("setup_back") ?></span>
            </button>
            <button type="button" class="btn btn-primary" id="btn-step2">
                <span data-t="continue"><?= setup_t("continue") ?></span> →
            </button>
        </div>
    </div>

    <!-- ── STEP 3: Password + TOTP ── -->
    <div class="step-panel" id="panel-3">
        <fieldset>
            <legend>
                <span data-t="setup_password"><?= setup_t("setup_password") ?></span>
            </legend>
            <label for="pw1">
                <span data-t="password"><?= setup_t("password") ?></span>
                <span class="hint-muted"><span data-t="setup_pw_min"><?= setup_t("setup_pw_min") ?></span></span>
            </label>
            <input type="password" id="pw1" data-pw autocomplete="new-password">
            <?= pw_meter() ?>
            <label for="pw2"><span data-t="password_confirm"><?= setup_t("password_confirm") ?></span></label>
            <input type="password" id="pw2" data-pw autocomplete="new-password">
            <p class="hint">
                <span data-t="setup_password_hint"><?= setup_t("setup_password_hint") ?></span>
            </p>
        </fieldset>

        <fieldset>
            <legend>
                <span data-t="setup_totp"><?= setup_t("setup_totp") ?></span>
            </legend>
            <p class="hint">
                <span data-t="setup_totp_hint"><?= setup_t("setup_totp_hint") ?></span>
            </p>
            <div id="totp-qr"></div>
            <div class="totp-secret" id="totp-secret-text">
                <code><span data-t="setup_generating"><?= setup_t("setup_generating") ?></span></code>
            </div>
            <button type="button" class="btn btn-ghost btn-mt" data-copy-target="totp-secret-text">
                <?= icon("copy") ?> <span data-t="copy"><?= setup_t("copy") ?></span>
            </button>
            <label for="totp-code"><span data-t="setup_totp_enter"><?= setup_t("setup_totp_enter") ?></span></label>
            <input type="text" id="totp-code" inputmode="numeric" maxlength="6" placeholder="123456" autocomplete="one-time-code">
            <div id="totp-err" class="alert alert-danger"></div>
        </fieldset>
        <div class="step-actions">
            <button type="button" class="btn btn-ghost" id="btn-back3">
                ← <span data-t="setup_back"><?= setup_t("setup_back") ?></span>
            </button>
            <button type="button" class="btn btn-primary" id="btn-step3">
                <span data-t="continue"><?= setup_t("continue") ?></span> →
            </button>
        </div>
    </div>

    <!-- ── STEP 4: Recovery phrase ── -->
    <div class="step-panel" id="panel-4">
        <p class="hint">
            <span data-t="setup_recovery_hint"><?= setup_t("setup_recovery_hint") ?></span>
        </p>
        <p class="hint hint-dim"><?= icon("server") ?> <?= $currentHost ?></p>
        <div class="phrase-box" id="phrase-box">
            <span data-t="setup_generating"><?= setup_t("setup_generating") ?></span>
        </div>
        <div class="phrase-actions">
            <button type="button" class="btn btn-ghost" id="btn-copy-phrase" data-copy-target="phrase-box">
                <?= icon("copy") ?> <span data-t="copy"><?= setup_t("copy") ?></span>
            </button>
            <button type="button" class="btn btn-ghost" id="btn-dl-phrase">
                <?= icon("download") ?> <span data-t="setup_recovery_dl"><?= setup_t("setup_recovery_dl") ?></span>
            </button>
        </div>
        <div class="alert alert-warning show">
            <span data-t="setup_recovery_warn"><?= setup_t("setup_recovery_warn") ?></span>
        </div>
        <label class="phrase-check">
            <input type="checkbox" id="phrase-check" disabled>
            <span data-t="setup_recovery_check"><?= setup_t("setup_recovery_check") ?></span>
        </label>
        <div class="step-actions">
            <button type="button" class="btn btn-ghost" id="btn-back4">
                ← <span data-t="setup_back"><?= setup_t("setup_back") ?></span>
            </button>
            <button type="button" class="btn btn-primary" id="btn-step4" disabled>
                <span data-t="continue"><?= setup_t("continue") ?></span> →
            </button>
        </div>
    </div>

    <!-- ── STEP 5: Install ── -->
    <div class="step-panel" id="panel-5">
        <p class="setup-step-intro">
            <span data-t="setup_install_intro"><?= setup_t("setup_install_intro") ?></span>
        </p>
        <dl id="install-summary">
            <dt data-t="setup_db_name"><?= setup_t("setup_db_name") ?></dt>
            <dd id="sum-db"></dd>
            <dt data-t="setup_prefix"><?= setup_t("setup_prefix") ?></dt>
            <dd id="sum-prefix"></dd>
            <dt data-t="setup_app_dir"><?= setup_t("setup_app_dir") ?></dt>
            <dd><?= e($appRoot) ?></dd>
            <dt data-t="setup_storage"><?= setup_t("setup_storage") ?></dt>
            <dd id="sum-storage"></dd>
            <dt data-t="language"><?= setup_t("language") ?></dt>
            <dd id="sum-lang"></dd>
        </dl>
        <div class="step-actions">
            <button type="button" class="btn btn-ghost" id="btn-back5">
                ← <span data-t="setup_back"><?= setup_t("setup_back") ?></span>
            </button>
            <button type="button" class="btn btn-primary" id="btn-install">
                <?= icon("lock") ?> <span data-t="setup_install_btn"><?= setup_t("setup_install_btn") ?></span>
            </button>
        </div>
        <div id="install-err" class="alert alert-danger"></div>
        <!-- Advisories from a successful install: exposed storage, or a 403
             page that could not be wired up (never both), and the
             security.txt note for a subdirectory install. -->
        <div id="install-warning" class="alert alert-warning"></div>
        <a href="<?= $baseUrlAttr ?>/login" class="btn btn-primary" id="btn-install-done" hidden>
            <span data-t="setup_continue"><?= setup_t("setup_continue") ?></span> →
        </a>
    </div>

    <!-- ── Existing installation(s) found at step 1 ── -->
    <div id="panel-existing" hidden>
        <div class="alert alert-warning alert-block">
            <?= icon("triangle-alert", "ic-warn") ?> <span id="existing-intro" data-t="setup_existing_one"><?= setup_t("setup_existing_one") ?></span>
        </div>

        <div id="existing-list" hidden></div>

        <!-- Reconnect form, shown once an installation is picked. The storage
             field is filled from what that installation recorded when it was
             installed, and stays editable. -->
        <div id="existing-reconnect-form" hidden>
            <fieldset class="existing-reconnect-fieldset">
                <legend>
                    <span id="existing-legend" data-t="setup_reconnect_legend"><?= setup_t("setup_reconnect_legend") ?></span>
                    <span id="existing-selected-prefix"></span>
                </legend>
                <p class="hint">
                    <span data-t="setup_reconnect_hint"><?= setup_t("setup_reconnect_hint") ?></span>
                </p>
                <label for="existing-storage"><span data-t="setup_storage"><?= setup_t("setup_storage") ?></span></label>
                <input type="text" id="existing-storage" required autocomplete="off" spellcheck="false">
                <p class="hint">
                    <span id="existing-storage-hint"></span>
                </p>
                <p class="hint">
                    <span data-t="setup_reconnect_note"><?= setup_t("setup_reconnect_note") ?></span>
                </p>
                <button type="button" class="btn btn-primary btn-mt-sm" id="btn-existing-reconnect">
                    <?= icon("key-round") ?> <span data-t="setup_reconnect_btn"><?= setup_t("setup_reconnect_btn") ?></span>
                </button>
                <div id="existing-err" class="alert alert-danger"></div>
            </fieldset>
        </div>

        <fieldset>
            <legend>
                <span data-t="setup_fresh_legend"><?= setup_t("setup_fresh_legend") ?></span>
            </legend>
            <p class="hint">
                <span data-t="setup_fresh_hint"><?= setup_t("setup_fresh_hint") ?></span>
            </p>
            <button type="button" class="btn btn-ghost btn-full" id="btn-existing-fresh">
                <?= icon("plus") ?> <span data-t="setup_fresh_btn"><?= setup_t("setup_fresh_btn") ?></span>
            </button>
        </fieldset>
        <button type="button" class="btn btn-ghost btn-mt" id="btn-existing-back">
            ← <span data-t="setup_back"><?= setup_t("setup_back") ?></span>
        </button>
    </div>

    </div>
    <?php endif; ?>
<?php endif; ?>
</main>

<?php if (!$isGate): ?>
<?php /* Every setup string in every language, so the selector switches
   without a reload (setup.js t()). JSON_HEX_* so a "</script>" inside a
   translation cannot end this inline script. */ ?>
<script nonce="<?= e($setupNonce) ?>">
window.SETUP_LANGS = <?= json_encode($_allLangs, JSON_FOR_SCRIPT) ?>;
window.SETUP_CURRENT_LANG = <?= json_encode($_setupLang, JSON_FOR_SCRIPT) ?>;
// helper.js reads its own few strings ("Copied!") from here.
window.AESBIN_LANG = window.SETUP_LANGS[window.SETUP_CURRENT_LANG] || {};
</script>
<script src="<?= $baseUrlAttr ?>/assets/js/helper.js<?= asset_version("assets/js/helper.js") ?>"></script>
<script src="<?= $baseUrlAttr ?>/assets/js/lang-select.js<?= asset_version("assets/js/lang-select.js") ?>"></script>
<script src="<?= $baseUrlAttr ?>/assets/js/setup.js<?= asset_version("assets/js/setup.js") ?>"></script>
<?php endif; ?>
</body>
</html>
