<?php

/**
 * app/views/dashboard.php — the owner's single-page app: files, shares, trash,
 * activity, settings and all modals. Most content is filled in by
 * dashboard.js; the banners and a few values are rendered here.
 */

declare(strict_types=1);

use AESBin\Lang;
use AESBin\Migrator;
use AESBin\MimeRegistry;
use AESBin\Requirements;
use AESBin\Vendor;

defined("AESBIN_APP") || exit;

$pageCss   = ["base.css", "preview.css", "dashboard.css"];
$bodyClass = "app-body";
$headExtra = '<script src="' . e(asset("vendor/kjua/kjua.min.js")) . '" defer></script>';

// Same cached source as the gear-icon dot and the requirements card. Needed
// early by the ALTCHA card.
$captchaKeysMissing = !(runtime_requirements()["captcha_keys"]["ok"] ?? true);

// Trash badge count, rendered server-side so it is right on any entry point
// (#settings bookmark, reload on #activity), not only on views that call the
// info endpoint. updateTrashBadge()/bumpTrashBadge() keep it current after.
$trashCount = 0;
$sharesExpiring = 0;
try {
    $trashCount = trash_file_count($db);
    $sharesExpiring = shares_expiring_soon_count($db);
} catch (Throwable) {
    // Not worth failing the page over; JS refreshes it.
}

// When credentials were last changed, for the reminder on the Reset Login
// card. Best-effort: the card renders without it.
$authUpdatedAt = null;
try {
    $authUpdatedAt = $db
        ->query("SELECT updated_at FROM " . DB_PREFIX . "auth WHERE id = 1")
        ->fetchColumn();
} catch (Throwable) {
    // The card renders without the date.
}

// Expiry choices of the create and edit share dialogs, in seconds. The server
// accepts any value up to ShareService::MAX_EXPIRES_IN; these are the offer.
$shareExpiryOptions = "";
foreach ([3600 => "dur_1h", 86400 => "dur_24h", 604800 => "dur_7d", 2592000 => "dur_30d"] as $_secs => $_label) {
    $shareExpiryOptions .= "<option value=\"{$_secs}\">" . e(__($_label)) . "</option>\n";
}
unset($_secs, $_label);

require __DIR__ . "/partials/document-start.php";
?>

<!-- App header, hidden without JS: the noscript card below has its own logo -->
<header class="app-header js-required">
    <a class="app-logo" href="<?= e(base_url()) ?>"><?= logo_mark() ?> <span class="app-logo-text"><?= e(__("app_name")) ?></span></a>
    <div class="app-header-right">
        <button class="btn-icon" id="btn-search-toggle" aria-expanded="false" aria-controls="search-bar" title="<?= e(__("search")) ?>"><?= icon("search") ?><span class="header-btn-label"><?= e(__("search")) ?></span></button>
        <button class="btn-icon<?= $sharesExpiring > 0 ? " has-alert" : "" ?>" id="btn-shares-nav" title="<?= e(__("active_shares")) ?>"><?= icon("link") ?><span class="header-btn-label"><?= e(__("shares_nav")) ?></span></button>
        <button class="btn-icon" id="btn-activity-nav" title="<?= e(__("activity")) ?>"><?= icon("scroll-text") ?><span class="header-btn-label"><?= e(__("activity")) ?></span></button>
        <button class="btn-icon" id="btn-trash-nav" title="<?= e(__("trash")) ?>"><?= icon("trash-2") ?><span class="header-btn-label"><?= e(__("trash")) ?></span><span class="icon-badge" id="btn-trash-badge"<?= $trashCount > 0
    ? ">" . e($trashCount > 99 ? "99+" : (string) $trashCount)
    : " hidden>" ?></span></button>
        <button class="btn-icon<?= runtime_requirements_failing() ? " has-alert" : "" ?>" id="btn-settings" title="<?= e(__("settings")) ?>"><?= icon("settings") ?><span class="header-btn-label"><?= e(__("settings")) ?></span></button>
        <button class="btn-icon" id="btn-logout" title="<?= e(__("logout")) ?>"><?= icon("log-out") ?><span class="header-btn-label"><?= e(__("logout")) ?></span></button>
    </div>
</header>

<!-- Search: bar and results stick together below the header, so results
     stay in view when the search is opened from further down the page. -->
<div class="search-dock">
    <div id="search-bar" class="search-bar" hidden>
        <input id="search-input" type="search" aria-label="<?= e(__("search_placeholder")) ?>" placeholder="<?= e(__("search_placeholder")) ?>" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false">
    </div>
    <div id="search-results" class="search-results-panel" hidden></div>
</div>

<!-- Idle session warning bar (hidden until countdown starts) -->
<div id="idle-warn-bar" class="idle-warn-bar" hidden>
    <span class="idle-warn-text">
        <?= icon("alarm-clock") ?> <?= e(__("session_expiring_in")) ?> <strong id="idle-warn-seconds">60</strong>s
    </span>
    <button type="button" class="btn btn-ghost btn-sm idle-warn-btn">
        <?= e(__("stay_logged_in")) ?>
    </button>
</div>

<!-- Main: entirely JS-populated, hidden via .no-js for no-JS visitors -->
<main id="main" class="js-required">

    <!-- ═══ HOME / DASHBOARD ═══ -->
    <!-- Unified nav breadcrumb, populated by JS for every view -->
    <nav id="app-nav" class="app-nav"></nav>

    <?php if (AESBIN_MIGRATION_PENDING): ?>
    <!-- Rendered server-side, so it appears even when data endpoints fail on
         the outdated schema. -->
    <?php
    // pending() throws on a malformed migration file. Show the "cannot apply"
    // state rather than take the whole page down.
    try {
        $_pendingMigrations = Migrator::pending(AESBIN_DB_SCHEMA_VERSION);
        $_migrateError = null;
    } catch (Throwable $e) {
        error_log("[AESBin] migration discovery failed: " . $e->getMessage());
        $_pendingMigrations = [];
        $_migrateError = $e->getMessage();
    }
    ?>
    <div id="migrate-bar" class="update-bar">
        <?php if ($_pendingMigrations): ?>
        <span id="migrate-bar-msg">
            <?= icon("database") ?> <?= e(__("migrate_banner_msg")) ?>
            <?= e(__("migrate_will_apply")) ?>
            <?= e(
                implode(
                    ", ",
                    array_map(
                        static fn(array $m): string =>
                            "#" . (int) $m["version"] . " " . $m["description"],
                        $_pendingMigrations
                    )
                )
            ) ?>
        </span>
        <div class="update-bar-actions">
            <button id="migrate-bar-run" class="btn btn-primary btn-sm">
                <?= e(__("migrate_run")) ?>
            </button>
        </div>
        <?php else: ?>
        <?php // No file supplies the declared version (an incomplete
              // upload), so there is nothing to run: no button. ?>
        <span id="migrate-bar-msg">
            <?= icon("triangle-alert", "ic-warn") ?> <?= e(
                $_migrateError ??
                    __("migrate_err_no_files", [
                        "target" => Migrator::targetVersion(),
                        "current" => AESBIN_DB_SCHEMA_VERSION,
                    ])
            ) ?>
        </span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (AESBIN_RECOVERY_ENABLED): ?>
    <?php /* Shown on every load while recovery is enabled. A successful
       recovery disables it automatically; an abandoned one leaves it open. */ ?>
    <div class="integrity-bar">
        <span><?= icon("triangle-alert", "ic-warn") ?> <?= e(__("recovery_open_banner")) ?></span>
    </div>
    <?php endif; ?>

    <?php $_storageMeta = storage_meta_state($db); ?>
    <?php if ($_storageMeta["state"] !== "ok"): ?>
    <?php /* The row only matters to a future reconnect from the installer, so
       this is a notice with a one-click fix, not an alarm. */ ?>
    <div id="storage-meta-bar" class="update-bar">
        <span><?= icon("folders") ?> <?= e(
            $_storageMeta["state"] === "missing"
                ? __("storage_meta_missing_banner", ["path" => AESBIN_FILES])
                : __("storage_meta_differs_banner", [
                    "recorded" => (string) $_storageMeta["recorded"],
                    "current" => AESBIN_FILES,
                ])
        ) ?></span>
        <div class="update-bar-actions">
            <button id="storage-meta-fix" class="btn btn-primary btn-sm">
                <?= e(__("storage_meta_fix")) ?>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <?php if (AESBIN_DEBUG): ?>
    <?php /* Like the recovery banner, but nothing turns debug off by itself. */ ?>
    <div class="integrity-bar">
        <span><?= icon("triangle-alert", "ic-warn") ?> <?= e(__("debug_open_banner")) ?></span>
    </div>
    <?php endif; ?>

    <div id="update-bar" class="update-bar" hidden>
        <span id="update-bar-msg"></span>
        <div class="update-bar-actions">
            <!-- Updating replaces the code and keeps config.php and storage:
                 the steps are in docs/updating.md. -->
            <a href="https://github.com/<?= e(AESBIN_GITHUB_REPO) ?>/blob/main/docs/updating.md" <?= EXT_LINK_ATTRS ?> class="btn btn-ghost btn-sm" title="<?= e(__("update_keep_hint")) ?>"><?= icon("info") ?> <?= e(__("update_how_to")) ?></a>
            <a id="update-bar-release" href="#" <?= EXT_LINK_ATTRS ?> class="btn btn-ghost btn-sm"><?= icon("file-text") ?> <?= e(__("update_view_release")) ?></a>
            <a id="update-bar-download" href="#" <?= EXT_LINK_ATTRS ?> download class="btn btn-primary btn-sm"><?= icon("download") ?> <?= e(__("download")) ?></a>
        </div>
    </div>

    <!-- Storage integrity warning banner (hidden until a problem is found) -->
    <div id="integrity-bar" class="integrity-bar" hidden>
        <span id="integrity-bar-msg"></span>
        <div class="integrity-bar-actions">
            <button id="integrity-bar-details" class="btn btn-ghost btn-sm">
                <?= icon("search") ?> <?= e(__("integrity_view_details")) ?>
            </button>
        </div>
    </div>

    <!-- Other active sessions banner (hidden unless other sessions exist) -->
    <div id="sessions-bar" class="integrity-bar" hidden>
        <span id="sessions-bar-msg"></span>
        <div class="integrity-bar-actions">
            <button id="sessions-bar-details" class="btn btn-ghost btn-sm">
                <?= icon("search") ?> <?= e(__("sessions_view_details")) ?>
            </button>
            <button id="sessions-bar-revoke" class="btn btn-danger btn-sm">
                <?= e(__("sessions_terminate_others")) ?>
            </button>
        </div>
    </div>

    <section id="view-home" class="view active">

        <div class="home-grid" id="home-grid">
            <!-- populated by JS -->
        </div>
    </section>

    <!-- ═══ FILES (single container) ═══ -->
    <section id="view-files" class="view" hidden>
        <div class="view-header">
            <div class="view-actions" id="file-view-actions" hidden>
                <button id="btn-new-dir"  class="btn btn-ghost btn-sm">
                    + <?= e(__("new_directory")) ?>
                </button>
                <button id="btn-new-file" class="btn btn-ghost btn-sm">
                    <?= icon("pencil") ?> <?= e(__("create_file")) ?>
                </button>
            </div>
        </div>
        <?php // Ticked on every page load: sending a photo as it is must be a choice. ?>
        <label id="upload-strip" class="upload-strip">
            <input id="upload-strip-meta" type="checkbox" checked autocomplete="off">
            <?= e(__("upload_strip_meta")) ?>
        </label>
        <div id="drop-zone" class="drop-zone">
            <span class="drop-icon"><?= icon("upload") ?></span>
            <span><?= e(__("drop_to_upload")) ?></span>
            <?php // Follows the box above: what is cleaned, and what is not. ?>
            <span id="upload-meta-note" class="upload-meta-note"><?= e(__("upload_meta_note_on")) ?></span>
            <input id="file-input" type="file" multiple hidden>
        </div>
        <details id="upload-formats" class="upload-formats-helper">
            <summary><?= e(__("upload_help_title")) ?>&nbsp;<?= icon("info") ?></summary>
            <?php // What FileService::upload() accepts: MimeRegistry, generated,
                  // so the list cannot drift from the check. ?>
            <p>
                <?php // Opens the limits card in Settings (dashboard.js). ?>
                <a href="#settings" id="upload-help-limit"><?= e(__("upload_help_limit", ["limit" => format_bytes_binary(upload_file_limit_bytes((array) ($config ?? [])))])) ?></a>
            </p>
            <?php foreach (MimeRegistry::byCategory() as $cat => $exts): ?>
            <p>
                <strong><?= e(__("cat_" . $cat)) ?>:</strong>
                <?= e(implode(", ", array_keys($exts))) ?>
            </p>
            <?php endforeach; ?>
            <p><?= e(__("upload_help_zip")) ?></p>
            <p><?= e(__("upload_help_preview", ["formats" => implode(", ", MimeRegistry::previewExtensions())])) ?></p>
            <p><?= e(__("upload_help_rename")) ?></p>
            <p><?= e(__("upload_help_noext")) ?></p>
        </details>
        <?php // Filled and shown by loadFiles() inside a shared directory. ?>
        <p id="dir-shared-notice" class="dir-shared-note" hidden></p>
        <?= sort_bar("sort-bar", ["name", "date", "size"]) ?>
        <div id="upload-progress" class="upload-progress" hidden></div>
        <div class="file-list" id="file-list"></div>
        <div id="no-files" class="empty-state" hidden><?= e(__("no_files")) ?></div>
    </section>

    <!-- ═══ SHARES ═══ -->
    <section id="view-shrs" class="view" hidden>
        <label class="split-toggle settings-block shr-list-toggle" hidden>
            <input type="checkbox" id="shr-list-split-toggle"> <?= e(__("share_key_separate")) ?>
        </label>
        <?= sort_bar("shr-sort-bar", ["name", "date", "visits"]) ?>
        <div id="shr-list" class="shr-list"></div>
    </section>

    <!-- ═══ TRASH ═══ -->
    <section id="view-trash" class="view" hidden>
        <div class="view-header">
            <?php // Shown by loadTrash() while the trash holds anything. ?>
            <div class="view-actions" id="trash-view-actions" hidden>
                <button id="btn-trash-empty" class="btn btn-danger btn-sm">
                    <?= icon("trash-2") ?> <?= e(__("trash_empty_btn")) ?>
                </button>
            </div>
        </div>
        <div class="file-list" id="trash-list"></div>
        <div id="no-trash" class="empty-state" hidden><?= e(__("trash_empty")) ?></div>
    </section>

    <!-- ═══ ACTIVITY ═══ -->
    <section id="view-activity" class="view" hidden>
        <p class="hint-sm activity-legend"><span class="legend-dot legend-owner"></span> <?= e(__("activity_legend_dashboard")) ?></p>
        <p class="hint-sm activity-legend"><span class="legend-dot legend-visitor"></span> <?= e(__("activity_legend_visitor")) ?></p>
        <p id="activity-count" class="hint-sm"></p>
        <div id="activity-list" class="activity-list"></div>
    </section>

    <!-- ═══ SETTINGS ═══ -->
    <section id="view-settings" class="view" hidden>
        <?php if (runtime_requirements_failing()): ?>
        <?php /* Only in Settings, not on every page: the owner cannot always fix
           these (a host's OPcache settings, say). Elsewhere the gear icon
           carries a dot. The button scrolls to the first failing check in
           the requirements card. */ ?>
        <div id="reqs-bar" class="integrity-bar">
            <span><?= icon("triangle-alert", "ic-warn") ?> <?= e(__("reqs_banner")) ?></span>
            <div class="integrity-bar-actions">
                <button id="reqs-bar-open" class="btn btn-ghost btn-sm">
                    <?= e(__("reqs_banner_open")) ?>
                </button>
            </div>
        </div>
        <?php endif; ?>
        <?php /* Unsaved changes in Settings: shown and filled by syncUnsaved()
           in dashboard.js, which names the cards; the button opens the first
           of them. Each such card carries the gear's dot after its title. */ ?>
        <div id="unsaved-bar" class="integrity-bar" hidden>
            <span><?= icon("triangle-alert", "ic-warn") ?> <span id="unsaved-bar-msg"></span></span>
            <div class="integrity-bar-actions">
                <button id="unsaved-bar-open" class="btn btn-ghost btn-sm">
                    <?= e(__("set_unsaved_bar_open")) ?>
                </button>
            </div>
        </div>
        <p class="hint-sm settings-hint elevate-legend">
            <span><span class="legend-dot legend-gated"></span> <?= e(__("elevate_legend")) ?></span>
            <button id="btn-elevation-open" class="btn btn-ghost btn-sm">
                <?= icon("lock-open") ?> <?= e(__("elevate_open")) ?>
            </button>
            <button id="btn-elevation-drop" class="btn btn-ghost btn-sm" hidden>
                <?= icon("lock") ?> <?= e(__("elevate_drop")) ?>
            </button>
        </p>
        <div class="settings-grid">

        <?php /* Two fixed columns, not CSS columns: a card never moves to the
           other side when one above it expands (privacy, contact). Left: access
           and security; right: the instance. One column on narrow screens,
           left first. */ ?>
        <div class="settings-col">

            <!-- Language / Lingua -->
            <div class="settings-card">
                <h3><?= icon("globe") ?> <?= e(__("language")) ?></h3>
                <p class="hint-sm settings-hint"><?= e(__("set_lang_desc")) ?></p>
                <?= lang_select(Lang::current(), [
                    "id" => "set-locale",
                    "class" => "field-input",
                    "aria-label" => __("language"),
                ], base_url(""), true) ?>
                <?php // Every settings Save button starts disabled: nothing differs from
                      // the saved values until dashboard.js has loaded them and a field
                      // changes (wireDirtyToggle()). Enabled in the markup, it would
                      // flash on until that first check ran. ?>
                <button id="btn-save-locale" class="btn btn-primary settings-save-btn" disabled>
                    <?= e(__("save")) ?>
                </button>
                <div id="locale-msg" class="alert" hidden></div>
            </div>

            <!-- Reset Login -->
            <div class="settings-card" id="creds-card">
                <h3><?= icon("rectangle-ellipsis") ?> <?= e(__("set_creds_title")) ?></h3>
                <p class="hint-sm settings-hint"><?= e(__("reset_via_recovery")) ?></p>
                <?php if ($authUpdatedAt): ?>
                <p class="hint-sm settings-hint" id="creds-last-changed" data-updated-at="<?= e($authUpdatedAt) ?>"></p>
                <?php endif; ?>
                <?php if (AESBIN_RECOVERY_ENABLED): ?>
                <a href="<?= e(base_url("recovery")) ?>" class="btn btn-ghost">
                    <?= e(__("set_creds_goto")) ?> →
                </a>
                <?php else: ?>
                <?php /* Recovery cannot be enabled from here, so a stolen session
                   cannot open it. The instructions need server access. */ ?>
                <p class="hint-sm settings-hint"><?= e(__("recovery_disabled_hint")) ?></p>
                <button class="btn btn-ghost" disabled>
                    <?= e(__("set_creds_goto")) ?> →
                </button>
                <?php endif; ?>
            </div>

            <!-- TOTP -->
            <div class="settings-card gated">
                <h3><?= icon("smartphone") ?> <?= e(__("set_totp_title")) ?></h3>
                <p class="hint-sm settings-hint">
                    <?= e(__("set_totp_desc")) ?>
                </p>
                <button id="btn-show-totp" class="btn btn-ghost">
                    <?= icon("eye") ?> <?= e(__("set_totp_show")) ?>
                </button>
                <div id="totp-reveal-wrap" hidden class="settings-block">
                    <div id="totp-reveal-qr" class="settings-hint"></div>
                    <div id="totp-reveal-secret" class="totp-secret-box"></div>
                    <button type="button" class="copy-btn" data-copy-target="totp-reveal-secret">
                        <?= icon("copy") ?> <?= e(__("copy")) ?>
                    </button>
                    <p class="hint-sm settings-sub settings-sub-warn">
                        <?= icon("triangle-alert", "ic-warn") ?> <?= e(__("totp_reveal_warning")) ?>
                    </p>
                </div>
                <div id="totp-msg" class="alert" hidden></div>
            </div>

            <!-- Passkeys -->
            <div class="settings-card passkey-section gated">
                <h3><?= icon("key-round") ?> <?= e(__("passkey_setup")) ?></h3>
                <p class="hint-sm settings-hint"><?= e(__("passkey_hint")) ?></p>
                <div id="passkey-list" class="passkey-list"></div>
                <div class="card-actions-row">
                    <button id="btn-register-passkey" class="btn btn-ghost btn-sm">
                        + <?= e(__("passkey_register")) ?>
                    </button>
                </div>
                <?php /* WebAuthn lets the site neither choose nor exclude a provider,
                         so the warning about providers without PRF has to come
                         before registration. */ ?>
                <p id="passkey-provider-hint" class="hint-sm settings-hint"><?= icon("lightbulb") ?> <?= e(__("passkey_provider_hint")) ?></p>
                <div id="passkey-msg" class="alert" hidden></div>
            </div>

            <!-- Active sessions: this card only opens the modal (as does the banner) -->
            <div class="settings-card">
                <h3><?= icon("monitor-smartphone") ?> <?= e(__("sessions_title")) ?></h3>
                <p class="hint-sm settings-hint"><?= e(__("sessions_hint")) ?></p>
                <div class="card-actions-row">
                    <button id="btn-open-sessions" class="btn btn-ghost btn-sm">
                        <?= e(__("sessions_manage")) ?>
                    </button>
                </div>
            </div>

            <!-- Session Timeout -->
            <div class="settings-card gated">
                <h3><?= icon("alarm-clock") ?> <?= e(__("set_session_title")) ?></h3>
                <p class="hint-sm settings-hint">
                    <?= e(__("set_session_desc")) ?>
                </p>
                <label class="field-label" for="set-session-timeout"><?= e(__("set_session_idle")) ?><?php
                    // The host's own limit (AESBIN_SESSION_GC_LIMIT): longer timeouts are greyed out.
                    if (AESBIN_SESSION_GC_LIMIT > 0): ?> (<?= e(__("set_session_php_limit", [
                        "minutes" => intdiv(AESBIN_SESSION_GC_LIMIT, 60),
                    ])) ?>)<?php endif ?></label>
                <select id="set-session-timeout" class="field-input">
                    <?= render_setting_options("session_idle_timeout", "idle_timeout_fits") ?>
                </select>
                <p class="hint-sm settings-sub">
                    <?= icon("lightbulb") ?> <?= e(__("set_session_hint")) ?>
                </p>
                <label class="field-check-row settings-block">
                    <input type="checkbox" id="set-session-ip-lock">
                    <?= e(__("set_session_ip_lock")) ?>
                </label>
                <p class="hint-sm settings-sub">
                    <?= icon("triangle-alert", "ic-warn") ?> <?= e(__("set_session_ip_hint")) ?>
                </p>
                <p class="settings-default-row"><?= e(__("set_session_default", [
                    "minutes" => (int) (SETTINGS_DEFAULTS["session_idle_timeout"] / 60),
                    "ip_lock" => __("set_session_ip_lock_short"),
                ])) ?></p>
                <button id="btn-save-session-timeout" class="btn btn-primary settings-save-btn" disabled>
                    <?= e(__("save")) ?>
                </button>
                <div id="session-timeout-msg" class="alert" hidden></div>
            </div>

            <!-- Rate limiting -->
            <div class="settings-card gated">
                <h3><?= icon("gauge") ?> <?= e(__("set_rl_title")) ?></h3>
                <p class="hint-sm settings-hint"><?= e(__("set_rl_desc")) ?></p>
                <div class="settings-flex-row">
                    <div class="settings-flex-col">
                        <label class="field-label" for="set-rl-max"><?= e(__("set_rl_max")) ?></label>
                        <select id="set-rl-max" class="field-input">
                            <?= render_setting_options("login_max_attempts") ?>
                        </select>
                    </div>
                    <div class="settings-flex-col">
                        <label class="field-label" for="set-rl-window"><?= e(__("set_rl_window")) ?></label>
                        <select id="set-rl-window" class="field-input">
                            <?= render_setting_options("login_window") ?>
                        </select>
                    </div>
                </div>
                <p class="hint-sm settings-hint">
                    <?= icon("lightbulb") ?> <?= e(__("set_rl_recover")) ?>
                </p>
                <p class="settings-default-row"><?= e(__("set_rl_default", [
                    "attempts" => SETTINGS_DEFAULTS["login_max_attempts"],
                    "minutes" => (int) (SETTINGS_DEFAULTS["login_window"] / 60),
                ])) ?></p>
                <button id="btn-save-ratelimit" class="btn btn-primary settings-save-btn" disabled>
                    <?= e(__("save")) ?>
                </button>
                <div id="rl-msg" class="alert settings-msg-wrap" hidden></div>
            </div>

            <!-- ALTCHA -->
            <div class="settings-card gated">
                <h3><?= icon("bot") ?> <?= e(__("set_captcha_title")) ?></h3>
                <p class="hint-sm settings-hint"><?= e(__("set_captcha_desc")) ?></p>
                <?php if ($captchaKeysMissing): ?>
                <p class="hint-sm settings-sub settings-sub-warn">
                    <?= icon("triangle-alert", "ic-warn") ?> <?= e(__("captcha_keys_missing")) ?>
                </p>
                <?php endif; ?>
                <label class="field-check-row">
                    <input type="checkbox" id="set-captcha-enabled">
                    <?= e(__("set_captcha_enabled")) ?>
                </label>
                <label class="field-check-row">
                    <input type="checkbox" id="set-captcha-show-footer" checked>
                    <?= e(__("set_captcha_show_footer")) ?>
                </label>
                <label class="field-check-row">
                    <input type="checkbox" id="set-captcha-show-logo" checked>
                    <?= e(__("set_captcha_show_logo")) ?>
                </label>
                <label class="field-label" for="set-captcha-level"><?= e(__("set_captcha_level")) ?></label>
                <select id="set-captcha-level" class="field-input input-sm">
                    <?= render_captcha_level_options() ?>
                </select>
                <span class="hint-sm settings-block-label" id="set-captcha-level-label"></span>
                <!-- Benchmark: solves one real challenge at the selected level
                     (app/actions/system/captcha_test.php) and reports the time. The
                     widget is created in the hidden host and removed afterwards. -->
                <button type="button" id="btn-captcha-test" class="btn btn-ghost btn-sm"><?= icon("timer") ?> <?= e(__("set_captcha_test")) ?></button>
                <span class="hint-sm settings-block-label" id="set-captcha-test-result" aria-live="polite"></span>
                <div id="captcha-test-host" hidden></div>
                <?php
                // Lowercased for this sentence only; the label is capitalised elsewhere.
                $captchaLevelDefaultLabel = mb_strtolower(
                    __(CAPTCHA_LEVEL_LABELS[CAPTCHA_LEVEL_DEFAULT][0])
                );
                ?>
                <p class="settings-default-row"><?= e(__("set_captcha_level_default", [
                    "level" => $captchaLevelDefaultLabel,
                    "footer" => __("set_captcha_show_footer_short"),
                    "logo" => __("set_captcha_show_logo_short"),
                ])) ?></p>
                <button id="btn-save-captcha" class="btn btn-primary settings-save-btn" disabled>
                    <?= e(__("save")) ?>
                </button>
                <div id="captcha-msg" class="alert settings-msg-wrap" hidden></div>
            </div>
        </div>

        <div class="settings-col">

            <!-- Upload / storage limits -->
            <div class="settings-card gated" id="set-upload-card">
                <h3><?= icon("upload") ?> <?= e(__("set_upload_title")) ?></h3>
                <p class="hint-sm settings-hint"><?= e(__("set_upload_desc")) ?></p>
                <div id="storage-usage-wrap" hidden class="settings-hint">
                    <div id="storage-usage-trk" class="usage-trk"><div id="storage-usage-fill" class="usage-fill"></div></div>
                    <span class="hint-sm" id="storage-usage-label"></span>
                </div>
                <div class="settings-flex-row">
                    <div class="settings-flex-col">
                        <label class="field-label" for="set-upload-maxfile"><?= e(__("set_upload_maxfile")) ?></label>
                        <input id="set-upload-maxfile" type="number" min="0" step="1" class="field-input" value="<?= (int) (SETTINGS_DEFAULTS["max_upload_bytes"] / 1048576) ?>" placeholder="<?= (int) (SETTINGS_DEFAULTS["max_upload_bytes"] / 1048576) ?>">
                        <span class="hint-sm settings-hint-block" id="set-upload-maxfile-hint"></span>
                    </div>
                    <div class="settings-flex-col">
                        <label class="field-label" for="set-upload-quota"><?= e(__("set_upload_quota")) ?></label>
                        <input id="set-upload-quota" type="number" min="0" step="0.1" class="field-input" value="<?= (int) (SETTINGS_DEFAULTS["max_total_bytes"] / 1073741824) ?>" placeholder="<?= (int) (SETTINGS_DEFAULTS["max_total_bytes"] / 1073741824) ?>">
                        <span class="hint-sm settings-hint-block"><?= e(__("set_upload_zero")) ?></span>
                    </div>
                </div>
                <?php /* Full width: too long for a half-width column. */ ?>
                <p class="hint-sm settings-hint" id="set-upload-phpcap"></p>
                <p class="settings-default-row"><?= e(__("set_upload_default", [
                    "file_mb" => (int) (SETTINGS_DEFAULTS["max_upload_bytes"] / 1048576),
                    "total_gb" => (int) (SETTINGS_DEFAULTS["max_total_bytes"] / 1073741824),
                ])) ?></p>
                <button id="btn-save-upload" class="btn btn-primary settings-save-btn" disabled>
                    <?= e(__("save")) ?>
                </button>
                <div id="upload-msg" class="alert settings-msg-wrap" hidden></div>
            </div>

            <!-- Activity log limits -->
            <div class="settings-card gated">
                <h3><?= icon("scroll-text") ?> <?= e(__("set_actlog_title")) ?></h3>
                <p class="hint-sm settings-hint">
                    <?= e(__("set_actlog_desc")) ?>
                </p>
                <label class="field-label" for="set-activity-preview"><?= e(__("set_actlog_preview")) ?></label>
                <select id="set-activity-preview" class="field-input input-sm">
                    <?= render_setting_options("activity_log_preview") ?>
                </select>
                <span class="hint-sm settings-block-label" id="set-activity-preview-label"></span>
                <label class="field-label" for="set-activity-item-cap"><?= e(__("set_actlog_item_cap")) ?></label>
                <select id="set-activity-item-cap" class="field-input input-sm">
                    <?= render_setting_options("activity_log_item_cap") ?>
                </select>
                <span class="hint-sm settings-block-label" id="set-activity-item-cap-label"></span>
                <p class="settings-default-row"><?= e(__("set_actlog_default", [
                    "shown" => SETTINGS_DEFAULTS["activity_log_preview"],
                    "kept" => SETTINGS_DEFAULTS["activity_log_item_cap"],
                ])) ?></p>
                <!-- No .settings-save-btn: .settings-save-wrap already supplies the
                     top margin for this row. -->
                <div class="settings-btn-row settings-save-wrap">
                    <button id="btn-save-activity-limit" class="btn btn-primary" disabled>
                        <?= e(__("save")) ?>
                    </button>
                    <button id="btn-purge-activity" class="btn btn-danger">
                        <?= icon("trash-2") ?> <?= e(__("set_actlog_purge")) ?>
                    </button>
                </div>
                <div id="activity-limit-msg" class="alert settings-msg-wrap" hidden></div>
            </div>

            <!-- Privacy & cookie policy page (collapsible) -->
            <details class="settings-card settings-details gated">
                <summary class="settings-details-summary">
                    <span><?= icon("file-text") ?> <?= e(__("set_privacy_title")) ?></span>
                </summary>
                <p class="hint-sm settings-hint">
                    <?= e(__("set_privacy_desc")) ?>
                </p>
                <label class="field-check-row settings-block">
                    <input type="checkbox" id="set-privacy-enabled">
                    <?= e(__("set_privacy_enabled")) ?>
                </label>
                <p class="hint-sm settings-inline-hint settings-block">
                    <?= e(__("set_privacy_fields_hint")) ?>
                </p>
                <div id="contact-fields-wrap" hidden>
                    <label class="field-label settings-block" for="set-privacy-name">
                        <?= e(__("data_controller")) ?><span class="required-mark" id="privacy-name-required">*</span>
                    </label>
                    <input type="text" id="set-privacy-name" class="field-input" autocomplete="off" maxlength="120" placeholder="<?= e(__("set_privacy_name_placeholder")) ?>">
                    <label class="field-label settings-block" for="set-privacy-address">
                        <?= e(__("set_privacy_address_label")) ?>
                    </label>
                    <input type="text" id="set-privacy-address" class="field-input" autocomplete="off" maxlength="250" placeholder="<?= e(__("set_privacy_address_placeholder")) ?>">
                    <label class="field-label settings-block" for="set-privacy-vat">
                        <?= e(__("set_privacy_vat_label")) ?>
                    </label>
                    <input type="text" id="set-privacy-vat" class="field-input" autocomplete="off" maxlength="50" placeholder="<?= e(__("set_privacy_vat_placeholder")) ?>">
                    <label class="field-label settings-block" for="set-privacy-email">
                        <?= e(__("set_privacy_email_label")) ?><span class="required-mark" id="privacy-email-required">*</span>
                        <span class="hint-sm settings-inline-hint">
                            <?= e(__("set_privacy_email_hint")) ?>
                            <?php if (security_txt_served()): ?>
                                <?= e(__("set_privacy_email_sectxt")) ?>
                            <?php endif; ?>
                        </span>
                    </label>
                    <input type="email" id="set-privacy-email" class="field-input" autocomplete="off" placeholder="<?= e(__("set_email_placeholder")) ?>">
                </div>
                <div class="settings-save-row settings-block">
                    <button id="btn-save-privacy" class="btn btn-primary settings-save-btn" disabled>
                        <?= e(__("save")) ?>
                    </button>
                    <a href="<?= e(base_url("privacy")) ?>" id="privacy-preview-link" class="link-inline" hidden><?= e(__("privacy_footer_link")) ?></a>
                </div>
                <div id="privacy-msg" class="alert" hidden></div>
            </details>

            <!-- "Contact me" footer link, independent of the privacy page. The
            "use privacy email" link only prefills the field once. -->
            <details class="settings-card settings-details gated">
                <summary class="settings-details-summary">
                    <span><?= icon("mail") ?> <?= e(__("set_contact_card_title")) ?></span>
                </summary>
                <p class="hint-sm settings-hint">
                    <?= e(__("set_contact_card_desc", ["lang" => "{lang}"])) ?>
                    <?php if (security_txt_served()): ?>
                        <?= e(__("set_contact_sectxt")) ?>
                    <?php endif; ?>
                </p>
                <label class="field-check-row settings-block">
                    <input type="checkbox" id="set-contact-enabled">
                    <?= e(__("set_contact_enabled_label")) ?>
                </label>
                <div id="contact-card-fields" hidden>
                    <label class="field-label settings-block" for="set-contact-link">
                        <?= e(__("set_contact_link_label")) ?><span class="required-mark" id="contact-link-required">*</span>
                    </label>
                    <?php /* type="text": an https:// URL is as valid as an email here. */ ?>
                    <input type="text" id="set-contact-link" class="field-input" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="500" placeholder="<?= e(__("set_contact_link_placeholder")) ?>">
                    <button type="button" id="btn-use-privacy-email" class="link-inline" hidden></button>
                    <?php /* Shown for an https:// link only (syncContactCard()):
                       a mailto: has no referrer. */ ?>
                    <label class="field-check-row settings-block" id="contact-referrer-row" hidden>
                        <input type="checkbox" id="set-contact-referrer">
                        <?= e(__("set_contact_referrer_label")) ?>
                    </label>
                    <p class="hint-sm settings-hint" id="contact-referrer-hint" hidden><?= e(__("set_contact_referrer_hint")) ?></p>
                </div>
                <button id="btn-save-contact" class="btn btn-primary settings-save-btn" disabled>
                    <?= e(__("save")) ?>
                </button>
                <div id="contact-msg" class="alert" hidden></div>
            </details>

            <!-- Updates -->
            <?php /* Without ext-curl there is no check to run
               (app/actions/system/update_check.php). The disabled button also tells
               dashboard.js to skip the automatic check. */ ?>
            <?php $_updatesAvailable = function_exists("curl_init"); ?>
            <div class="settings-card">
                <h3><?= icon("bell") ?> <?= e(__("set_update_title")) ?></h3>
                <p class="hint-sm settings-hint"><?= e(__("set_update_desc")) ?></p>
                <p class="hint-sm settings-hint">
                    <?= e(__("update_keep_hint")) ?>
                    <a href="https://github.com/<?= e(AESBIN_GITHUB_REPO) ?>/blob/main/docs/updating.md" <?= EXT_LINK_ATTRS ?>><?= e(__("update_how_to")) ?></a>
                </p>
                <div id="update-check-result" class="hint-sm settings-hint"><?php if (!$_updatesAvailable): ?><?= icon("info") ?> <?= e(__("err_update_no_curl")) ?><?php endif; ?></div>
                <div class="card-actions-row">
                    <button id="btn-check-updates" class="btn btn-ghost btn-sm"<?= $_updatesAvailable ? "" : " disabled" ?>>
                        <?= e(__("update_check_now")) ?>
                    </button>
                </div>
            </div>

            <!-- Storage integrity -->
            <div class="settings-card">
                <h3><?= icon("shield-check") ?> <?= e(__("set_integrity_title")) ?></h3>
                <p class="hint-sm settings-hint"><?= e(__("set_integrity_desc")) ?></p>
                <div id="integrity-check-result" class="hint-sm settings-hint"></div>
                <div class="card-actions-row">
                    <button id="btn-check-integrity" class="btn btn-ghost btn-sm">
                        <?= e(__("integrity_check_now")) ?>
                    </button>
                </div>
            </div>

            <!-- Server requirements -->
            <?php /* Rendered, not fetched: every check is local. The requirements
               banner scrolls to this id. */ ?>
            <?php /* Collapsed like the other read-only cards while every check
                     passes, with the result in its title; open when one does not,
                     so a warning is never behind a click. The banner's "See which"
                     opens it too (dashboard.js). */ ?>
            <?php $_reqsWarn = runtime_requirements_failing(); ?>
            <?php /* A failing check: the gear's dot after the title (.is-flagged),
               as on a card with unsaved changes; the lines that fail carry
               their own warning icon. */ ?>
            <details class="settings-card settings-details<?= $_reqsWarn ? " is-flagged" : "" ?>" id="reqs-card"<?= $_reqsWarn ? " open" : "" ?>>
                <summary class="settings-details-summary">
                    <span><?= icon("stethoscope") ?> <?= e(__("set_reqs_title")) ?><?= $_reqsWarn ? "" : " " . icon("circle-check", "ic-ok") ?></span>
                </summary>
                <p class="hint-sm settings-hint"><?= e(__("set_reqs_info")) ?></p>
                <p class="reqs-env"><?= e(Requirements::environment()) ?></p>
                <?php /* Required checks are green on a page that rendered (the app
                   would not boot otherwise), except the database version: a server
                   downgraded after the install still connects. */ ?>
                <?php foreach (["required", "advisory"] as $_group): ?>
                <h4 class="reqs-group"><?= e(__("reqs_group_" . $_group)) ?></h4>
                <ul class="reqs-list">
                    <?php foreach (runtime_requirements() as $r): ?>
                    <?php if (($r["group"] ?? "") !== $_group): ?>
                    <?php continue; ?>
                    <?php endif; ?>
                    <?php /* ok, warning, or an optional feature that is off (greyed out). */ ?>
                    <?php $_off = !$r["ok"] && !empty($r["optional"]); ?>
                    <li class="<?= $r["ok"] ? "reqs-ok" : ($_off ? "reqs-off" : "reqs-warn") ?>">
                        <?= $r["ok"] ? icon("circle-check", "ic-ok") : ($_off ? icon("circle-minus", "ic-muted") : icon("triangle-alert", "ic-warn")) ?> <?= e($r["label"]) ?>
                        <?php if (!$r["ok"]): ?>
                        <span class="reqs-hint"><?= e(__($r["hint"])) ?></span>
                        <?php /* The verbatim fix line, kept apart from the translated hint. */ ?>
                        <?php if (!empty($r["detail"])): ?>
                        <code class="reqs-detail"><?= e($r["detail"]) ?></code>
                        <?php endif; ?>
                        <?php /* Where the app can apply the fix itself. */ ?>
                        <?php if (($r["fix"] ?? "") === "custom_403"): ?>
                        <button type="button" class="btn btn-ghost btn-sm reqs-fix" id="req-fix-403">
                            <?= icon("wrench") ?> <?= e(__("req_fix_btn")) ?>
                        </button>
                        <?php endif; ?>
                        <?php elseif (!empty($r["detail_always"]) && !empty($r["detail"])): ?>
                        <code class="reqs-detail"><?= e($r["detail"]) ?></code>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endforeach; ?>
                <?php /* Reports the result of a fix. */ ?>
                <div id="reqs-msg" class="alert settings-msg-wrap" hidden></div>
            </details>

            <!-- Backup: reading only, so collapsed like the other such cards -->
            <details class="settings-card settings-details">
                <summary class="settings-details-summary">
                    <span><?= icon("database-backup") ?> <?= e(__("set_backup_title")) ?></span>
                </summary>
                <p class="hint-sm settings-hint"><?= __("backup_info") ?></p>
            </details>

            <!-- About -->
            <div class="settings-card">
                <h3><?= logo_mark() ?> <?= e(__("app_name")) ?> <?= e(AESBIN_VERSION) ?> (AGPL v3) — DB v<?= (int) AESBIN_DB_SCHEMA_VERSION ?></h3>
                <p class="hint-sm settings-hint">
                    <?= e(__("about_author")) ?>
                    <a href="https://salvatorenoschese.it" <?= EXT_LINK_ATTRS ?>>
                        Salvatore Noschese <?= icon("user") ?>
                    </a>
                </p>
                <div class="card-actions-row">
                    <a href="https://github.com/<?= e(AESBIN_GITHUB_REPO) ?>" <?= EXT_LINK_ATTRS ?> class="btn btn-ghost btn-sm">
                        <?= icon("star") ?> <?= e(__("about_github")) ?>
                    </a>
                    <a href="https://donate.salvatorenoschese.it/" <?= EXT_LINK_ATTRS ?> class="btn btn-ghost btn-sm about-donate-btn">
                        <?= icon("heart") ?> <?= e(__("about_donate")) ?>
                    </a>
                </div>
                <details class="about-credits">
                    <summary><?= e(__("about_credits")) ?>&nbsp;<?= icon("info") ?></summary>
                    <?php // From app/includes/Vendor.php, the source of truth for versions. ?>
                    <ul class="about-credits-list">
                        <?php foreach (Vendor::all() as $lib): ?>
                            <li>
                                <span class="vendor-head"><a href="<?= e($lib["url"]) ?>" <?= EXT_LINK_ATTRS ?>><?= e($lib["name"]) ?></a><?php if ($lib["version"] !== null): ?> <span class="vendor-version">(v<?= e($lib["version"]) ?>)</span><?php endif; ?></span>
                                <span class="vendor-desc"><?= e(__("credit_" . $lib["role"])) ?> (<?= e($lib["license"]) ?>)</span>
                            </li>
                        <?php endforeach; ?>
                        <?php // Not a library: the static sprite in public/assets/img/icons.svg.
                              // Where the others show a version, this says what it is (the source
                              // release is in the sprite's header); a technical term, so no lang string. ?>
                        <li>
                            <span class="vendor-head"><a href="https://lucide.dev" <?= EXT_LINK_ATTRS ?>>Lucide</a> <span class="vendor-version">(Static SVG)</span></span>
                            <span class="vendor-desc"><?= e(__("credit_icons")) ?> (ISC)</span>
                        </li>
                    </ul>
                </details>
            </div>
        </div>
    </div>
    </section>
</main>

<!-- ══════ MODALS ══════ -->
<div id="dlg-overlay" class="dlg-overlay" hidden>
    <!-- Share create -->
    <div id="dlg-lnk" class="modal" role="dialog" aria-modal="true" aria-labelledby="dlg-lnk-title" hidden>
        <div class="modal-header">
            <h3 id="dlg-lnk-title"><?= e(__("share_create")) ?></h3>
            <button type="button" class="dlg-close" aria-label="<?= e(__("close")) ?>" title="<?= e(__("close")) ?>">✕</button>
        </div>
        <div class="modal-body">
            <p id="shr-target" class="shr-target-name" hidden></p>
            <div id="shr-existing" hidden>
                <p class="hint-sm settings-hint settings-hint-warn">
                    <?= icon("triangle-alert", "ic-warn") ?> <?= e(__("share_active_links")) ?>
                </p>
                <label class="split-toggle settings-block">
                    <input type="checkbox" id="shr-existing-split-toggle">
                    <?= e(__("share_key_separate")) ?>
                </label>
                <div id="shr-existing-list" class="shr-existing-list"></div>
                <hr class="shr-divider">
            </div>
            <p class="hint-sm dlg-lnk-hint"><?= e(__("share_new_link")) ?></p>
            <label class="field-label" for="shr-pw">
                <?= e(__("share_password")) ?>
                <span class="label-optional">(<?= e(__("optional")) ?>)</span>
            </label>
            <!-- data-pw-mask, not type="password": a per-link passphrase, not a
                 site login, so password managers must not offer to save it.
                 initPasswordToggles() in helper.js falls back to a real
                 password field where CSS masking is unsupported. -->
            <input id="shr-pw" type="text" class="field-input" data-pw data-pw-mask
                   autocomplete="off" autocapitalize="off" spellcheck="false"
                   data-lpignore="true" data-1p-ignore data-bwignore="true"
                   data-form-type="other">
            <label class="field-label" for="shr-exp"><?= e(__("share_expires")) ?></label>
            <select id="shr-exp" class="field-input">
                <option value=""><?= e(__("share_never")) ?></option>
                <?= $shareExpiryOptions ?>
            </select>
        </div>
        <div class="modal-footer">
            <button class="dlg-close btn btn-ghost">
                <?= e(__("cancel")) ?>
            </button>
            <button id="btn-create-shr" class="btn btn-primary">
                <?= e(__("share_create")) ?>
            </button>
        </div>
    </div>

    <!-- Share result -->
    <div id="dlg-lnk-result" class="modal" role="dialog" aria-modal="true" aria-labelledby="dlg-lnk-result-title" hidden>
        <div class="modal-header">
            <h3 id="dlg-lnk-result-title"><?= e(__("share_link")) ?></h3>
            <button type="button" class="dlg-close" aria-label="<?= e(__("close")) ?>" title="<?= e(__("close")) ?>">✕</button>
        </div>
        <div class="modal-body">
            <label class="split-toggle">
                <input type="checkbox" id="shr-split-toggle">
                <?= e(__("share_key_separate")) ?>
            </label>
            <p id="shr-result-meta" class="hint-sm shr-result-meta"></p>
            <div id="shr-url-full">
                <label class="field-label" for="shr-result-url"><?= e(__("share_link")) ?></label>
                <div class="shr-url-row">
                    <input id="shr-result-url" type="text" class="field-input" readonly>
                    <button type="button" id="btn-copy-shr" class="btn btn-ghost btn-sm" aria-label="<?= e(__("copy")) ?>" title="<?= e(__("copy")) ?>"><?= icon("copy") ?></button>
                </div>
            </div>
            <div id="shr-url-split" hidden>
                <label class="field-label" for="shr-split-url">URL</label>
                <div class="shr-url-row">
                    <input id="shr-split-url" type="text" class="field-input" readonly>
                    <button type="button" id="btn-copy-split-url" class="btn btn-ghost btn-sm" aria-label="<?= e(__("copy")) ?>" title="<?= e(__("copy")) ?>"><?= icon("copy") ?></button>
                </div>
                <label class="field-label settings-block" for="shr-split-key">
                    <?= e(__("share_key_label")) ?>
                </label>
                <div class="shr-url-row">
                    <input id="shr-split-key" type="text" class="field-input" readonly>
                    <button type="button" id="btn-copy-split-key" class="btn btn-ghost btn-sm" aria-label="<?= e(__("copy")) ?>" title="<?= e(__("copy")) ?>"><?= icon("copy") ?></button>
                </div>
                <p class="hint-sm dlg-lnk-note"><?= __("share_split_note") ?></p>
            </div>
            <div id="shr-qr-output" class="settings-block"></div>
        </div>
    </div>

    <!-- File info / edit -->
    <div id="modal-file-info" class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="fileinfo-title" hidden>
        <div class="modal-header">
            <h3 id="fileinfo-title" class="modal-title-text">
                <?= e(__("loading")) ?>
            </h3>
            <button type="button" class="dlg-close" aria-label="<?= e(__("close")) ?>" title="<?= e(__("close")) ?>">✕</button>
        </div>
        <div class="modal-body">
            <label class="field-label" id="fileinfo-name-label" for="fileinfo-name">
                <?= e(__("sort_name")) ?>
            </label>
            <!-- Name field: select for editable files, readonly badge for binary -->
            <div id="fileinfo-name-wrap" class="fileinfo-name-wrap">
                <input id="fileinfo-name" type="text" class="field-input" autocomplete="off" maxlength="<?= FILENAME_MAX_CHARS ?>">
                <select id="fileinfo-ext" class="field-input fileinfo-ext-sel">
                    <?php
                    // See MimeRegistry::byCategory(). The modal serves both create
                    // and edit (openFileModal() in dashboard.js).
                    ?>
                    <?php foreach (MimeRegistry::byCategory(true) as $cat => $exts): ?>
                    <optgroup label="<?= e(__("cat_" . $cat)) ?>">
                        <?php foreach ($exts as $ext => $mime): ?>
                        <option value="<?= e($ext) ?>" data-mime="<?= e($mime) ?>"><?= e($ext) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
                <span id="fileinfo-ext-badge" class="fileinfo-ext-badge" hidden></span>
            </div>
            <label class="field-label settings-block" for="fileinfo-desc">
                <?= e(__("create_file_desc")) ?>
            </label>
            <!-- Same widget as the folder description (dirinfo-desc). Newlines
                 are kept on display by white-space: pre-line on .file-sub-desc. -->
            <textarea id="fileinfo-desc" class="field-input field-textarea" rows="3" maxlength="<?= DESCRIPTION_MAX ?>" placeholder="<?= e(__("desc_placeholder")) ?>"></textarea>
            <?php $descTarget = "fileinfo-desc"; require __DIR__ . "/partials/desc-toolbar.php"; ?>
            <div id="fileinfo-content-wrap">
                <div class="editor-toolbar">
                    <label class="field-label" for="fileinfo-textarea"><?= e(__("create_file_content")) ?></label>
                </div>
                <textarea id="fileinfo-textarea" class="field-input editor-textarea editor-textarea-compact" placeholder="<?= e(__("content_placeholder")) ?>"></textarea>
                <?php /* Encoding and line endings of the text: what decodeText()
                   (helper.js) found when the file opened, UTF-8 and LF for a
                   new one. Changing either converts the file on save. The
                   values are read by fileFormatFromInputs() in dashboard.js;
                   the names are the same in every language. */ ?>
                <div class="fileinfo-name-wrap fileinfo-format">
                    <select id="fileinfo-encoding" class="field-input" aria-label="<?= e(__("file_encoding_label")) ?>" title="<?= e(__("file_encoding_label")) ?>">
                        <option value="utf-8">UTF-8</option>
                        <option value="utf-8-bom">UTF-8 (BOM)</option>
                        <option value="utf-16le">UTF-16 LE</option>
                        <option value="utf-16be">UTF-16 BE</option>
                        <option value="windows-1252">Windows-1252</option>
                    </select>
                    <select id="fileinfo-eol" class="field-input" aria-label="<?= e(__("file_eol_label")) ?>" title="<?= e(__("file_eol_label")) ?>">
                        <option value="lf">LF (Unix)</option>
                        <option value="crlf">CRLF (Windows)</option>
                        <option value="cr">CR</option>
                    </select>
                </div>
                <details id="fileinfo-format-help" class="upload-formats-helper fileinfo-format-help">
                    <summary><?= e(__("file_format_summary")) ?>&nbsp;<?= icon("info") ?></summary>
                    <p><?= e(__("file_format_hint")) ?></p>
                    <?php // The names are the menus' own, the same in every language. ?>
                    <p><strong>UTF-8</strong>: <?= e(__("file_format_help_utf8")) ?></p>
                    <p><strong>UTF-8 (BOM)</strong>: <?= e(__("file_format_help_bom")) ?></p>
                    <p><strong>UTF-16 LE / BE</strong>: <?= e(__("file_format_help_utf16")) ?></p>
                    <p><strong>Windows-1252</strong>: <?= e(__("file_format_help_win1252")) ?></p>
                    <p><strong>LF / CRLF / CR</strong>: <?= e(__("file_format_help_eol")) ?></p>
                    <p><?= e(__("file_format_help_grey")) ?></p>
                    <p>
                        <a href="https://github.com/<?= e(AESBIN_GITHUB_REPO) ?>/blob/main/docs/features.md#-text-encoding-and-line-endings" <?= EXT_LINK_ATTRS ?>>
                            <?= e(__("file_format_help_more")) ?> <?= icon("external-link") ?>
                        </a>
                    </p>
                </details>
            </div>
            <p id="fileinfo-status" class="hint-sm settings-msg-wrap"></p>
        </div>
        <div class="modal-footer">
            <?php /* Filled and shown by updateFormatOptions() in dashboard.js.
               In the footer, a row of its own above the buttons: the footer
               does not scroll, so the line is in sight however little of the
               body a phone's keyboard leaves. */ ?>
            <p id="fileinfo-format-note" class="hint-sm fileinfo-format-note" hidden></p>
            <p id="fileinfo-id" class="hint-sm modal-footer-note" hidden></p>
            <button class="dlg-close btn btn-ghost"><?= e(__("cancel")) ?></button>
            <button id="btn-fileinfo-save" class="btn btn-primary">
                <?= e(__("save")) ?>
            </button>
        </div>
    </div>

    <!-- Share retarget: point an existing link at another file/directory.
         The public URL does not change. -->
    <div id="modal-retarget" class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-retarget-title" hidden>
        <div class="modal-header">
            <h3 id="modal-retarget-title"><?= icon("repeat") ?> <?= e(__("share_retarget")) ?></h3>
            <button type="button" class="dlg-close" aria-label="<?= e(__("close")) ?>" title="<?= e(__("close")) ?>">✕</button>
        </div>
        <div class="modal-body">
            <p class="hint-sm"><?= e(__("share_retarget_hint")) ?></p>
            <label class="field-label" for="rt-dir"><?= e(__("share_retarget_dir")) ?></label>
            <select id="rt-dir" class="field-input"></select>
            <label class="field-label" for="rt-file"><?= e(__("share_retarget_file")) ?></label>
            <select id="rt-file" class="field-input"></select>
        </div>
        <div class="modal-footer">
            <button class="dlg-close btn btn-ghost"><?= e(__("cancel")) ?></button>
            <button id="btn-rt-save" class="btn btn-primary"><?= e(__("save")) ?></button>
        </div>
    </div>

    <!-- Share settings: expiry and password, on a link that already exists.
         Neither feeds any key derivation, so the URL is untouched by this. -->
    <div id="modal-shr-edit" class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-shr-edit-title" hidden>
        <div class="modal-header">
            <h3 id="modal-shr-edit-title"><?= icon("sliders-horizontal") ?> <?= e(__("share_edit")) ?></h3>
            <button type="button" class="dlg-close" aria-label="<?= e(__("close")) ?>" title="<?= e(__("close")) ?>">✕</button>
        </div>
        <div class="modal-body">
            <p id="se-target" class="shr-target-name" hidden></p>
            <p class="hint-sm"><?= e(__("share_edit_hint")) ?></p>

            <label class="field-label" for="se-exp"><?= e(__("share_expires")) ?></label>
            <select id="se-exp" class="field-input">
                <!-- Empty = leave unchanged; "Never" (0) is an explicit change. -->
                <option value=""><?= e(__("share_edit_keep")) ?></option>
                <option value="0"><?= e(__("share_never")) ?></option>
                <?= $shareExpiryOptions ?>
            </select>

            <label class="field-label" for="se-pw"><?= e(__("share_password")) ?></label>
            <input id="se-pw" type="text" class="field-input" data-pw data-pw-mask
                   autocomplete="off" autocapitalize="off" spellcheck="false"
                   data-lpignore="true" data-1p-ignore data-bwignore="true"
                   data-form-type="other">
            <p id="se-pw-current" class="hint-sm"></p>
            <label class="split-toggle" id="se-pw-remove-wrap" hidden>
                <input type="checkbox" id="se-pw-remove">
                <?= e(__("share_edit_pw_remove")) ?>
            </label>
        </div>
        <div class="modal-footer">
            <button class="dlg-close btn btn-ghost"><?= e(__("cancel")) ?></button>
            <button id="btn-se-save" class="btn btn-primary"><?= e(__("save")) ?></button>
        </div>
    </div>

    <!-- Move -->
    <div id="modal-move" class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-move-title" hidden>
        <div class="modal-header">
            <h3 id="modal-move-title"><?= icon("folder-input") ?> <?= e(__("move")) ?></h3>
            <button type="button" class="dlg-close" aria-label="<?= e(__("close")) ?>" title="<?= e(__("close")) ?>">✕</button>
        </div>
        <div class="modal-body">
            <p id="move-current" class="hint-sm settings-hint"></p>
            <p class="field-label" id="move-tree-label"><?= e(__("destination")) ?></p>
            <div id="move-tree" class="move-tree" role="group" aria-labelledby="move-tree-label"></div>
            <p id="move-hint" class="hint-sm settings-msg-wrap">
                <?= e(__("move_hint")) ?>
            </p>
            <div id="move-msg" class="alert settings-msg-wrap" hidden></div>
        </div>
        <div class="modal-footer">
            <button class="dlg-close btn btn-ghost"><?= e(__("cancel")) ?></button>
            <button id="btn-move-ok" class="btn btn-primary">
                <?= e(__("move")) ?>
            </button>
        </div>
    </div>

    <!-- Directory info / edit -->
    <div id="modal-dir-info" class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-dir-info-title" hidden>
        <div class="modal-header">
            <h3 id="modal-dir-info-title"><?= icon("folder") ?> <?= e(__("folder_info")) ?></h3>
            <button type="button" class="dlg-close" aria-label="<?= e(__("close")) ?>" title="<?= e(__("close")) ?>">✕</button>
        </div>
        <div class="modal-body">
            <label class="field-label" for="dirinfo-name"><?= e(__("sort_name")) ?></label>
            <input id="dirinfo-name" type="text" class="field-input" maxlength="<?= DISPLAY_NAME_MAX_CHARS ?>">
            <label class="field-label settings-block" for="dirinfo-desc">
                <?= e(__("description")) ?>
                <span class="label-optional">(<?= e(__("optional")) ?>)</span>
            </label>
            <textarea id="dirinfo-desc" class="field-input field-textarea" rows="3" maxlength="<?= DESCRIPTION_MAX ?>" placeholder="<?= e(__("desc_placeholder")) ?>"></textarea>
            <?php $descTarget = "dirinfo-desc"; require __DIR__ . "/partials/desc-toolbar.php"; ?>
            <p id="dirinfo-status" class="hint-sm settings-msg-wrap"></p>
        </div>
        <div class="modal-footer">
            <p id="dirinfo-id" class="hint-sm modal-footer-note"></p>
            <button class="dlg-close btn btn-ghost"><?= e(__("cancel")) ?></button>
            <button id="btn-dirinfo-save" class="btn btn-primary">
                <?= e(__("save")) ?>
            </button>
        </div>
    </div>

    <!-- Text prompt (promptModal() in dashboard.js) -->
    <div id="modal-input" class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-input-title" hidden>
        <div class="modal-header">
            <h3 id="modal-input-title"><?= e(__("prompt_enter")) ?></h3>
            <button type="button" class="dlg-close" aria-label="<?= e(__("close")) ?>" title="<?= e(__("close")) ?>">✕</button>
        </div>
        <div class="modal-body">
            <input id="modal-input-field" type="text" class="field-input" aria-labelledby="modal-input-title">
        </div>
        <div class="modal-footer">
            <p id="modal-input-hint" class="hint-sm modal-footer-note" hidden></p>
            <button id="modal-input-cancel" class="btn btn-ghost">
                <?= e(__("cancel")) ?>
            </button>
            <button id="modal-input-ok" class="btn btn-primary"><?= e(__("confirm")) ?></button>
        </div>
    </div>

    <!-- Confirm delete -->
    <div id="dlg-ask" class="modal" role="dialog" aria-modal="true" aria-labelledby="dlg-ask-title" hidden>
        <div class="modal-header">
            <h3 id="dlg-ask-title"><?= e(__("delete_permanent")) ?></h3>
            <button type="button" class="dlg-close" aria-label="<?= e(__("close")) ?>" title="<?= e(__("close")) ?>">✕</button>
        </div>
        <div class="modal-body">
            <p id="dlg-ask-msg">
                <?= e(__("delete_confirm")) ?>
            </p>
        </div>
        <div class="modal-footer">
            <button id="btn-dlg-cancel" class="dlg-close btn btn-ghost">
                <?= e(__("cancel")) ?>
            </button>
            <button id="btn-dlg-ok" class="btn btn-danger">
                <?= e(__("delete")) ?>
            </button>
        </div>
    </div>

    <!-- Elevation: the master password again, for the gated actions -->
    <div id="modal-elevate" class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-elevate-title" hidden>
        <div class="modal-header">
            <h3 id="modal-elevate-title"><?= icon("lock-keyhole") ?> <?= e(__("elevate_title")) ?></h3>
            <button type="button" class="dlg-close" aria-label="<?= e(__("close")) ?>" title="<?= e(__("close")) ?>">✕</button>
        </div>
        <div class="modal-body">
            <p class="hint-sm"><?= e(__("elevate_hint")) ?></p>
            <form id="elevate-form" autocomplete="off">
                <input type="password" id="elevate-pw" class="field-input" aria-label="<?= e(__("password")) ?>" data-pw autocomplete="current-password" placeholder="<?= e(__("password")) ?>">
            </form>
        </div>
        <div class="modal-footer">
            <p id="elevate-err" class="hint-sm text-danger modal-footer-note" hidden></p>
            <button id="elevate-cancel" class="btn btn-ghost btn-sm">
                <?= e(__("cancel")) ?>
            </button>
            <button id="elevate-submit" class="btn btn-primary btn-sm" disabled>
                <?= e(__("confirm")) ?>
            </button>
        </div>
    </div>

    <!-- Active sessions -->
    <div id="modal-sessions" class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-sessions-title" hidden>
        <div class="modal-header">
            <h3 id="modal-sessions-title"><?= icon("monitor-smartphone") ?> <?= e(__("sessions_title")) ?></h3>
            <button type="button" class="dlg-close" aria-label="<?= e(__("close")) ?>" title="<?= e(__("close")) ?>">✕</button>
        </div>
        <div class="modal-body">
            <p class="hint-sm"><?= e(__("sessions_hint")) ?></p>
            <div id="sessions-list-modal"></div>
        </div>
        <div class="modal-footer">
            <button id="btn-refresh-sessions-modal" class="btn btn-ghost btn-sm">
                <?= e(__("sessions_refresh")) ?>
            </button>
            <button id="btn-revoke-others-modal" class="btn btn-danger btn-sm" hidden>
                <?= e(__("sessions_terminate_others")) ?>
            </button>
        </div>
    </div>

    <!-- Storage integrity details -->
    <div id="modal-integrity" class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="modal-integrity-title" hidden>
        <div class="modal-header">
            <h3 id="modal-integrity-title"><?= icon("search") ?> <?= e(__("integrity_modal_title")) ?></h3>
            <button type="button" class="dlg-close" aria-label="<?= e(__("close")) ?>" title="<?= e(__("close")) ?>">✕</button>
        </div>
        <div class="modal-body">
            <p id="integrity-checked-at" class="hint-sm"></p>
            <div class="integrity-section" id="integrity-section-missing">
                <div class="integrity-section-title">
                    <?= icon("triangle-alert", "ic-warn") ?> <?= e(__("integrity_missing_label")) ?>
                    <span id="integrity-missing-count" class="count-badge"></span>
                </div>
                <div id="integrity-missing-list" class="integrity-list"></div>
            </div>
            <div class="integrity-section" id="integrity-section-orphan">
                <div class="integrity-section-title">
                    <?= icon("trash-2") ?> <?= e(__("integrity_orphan_label")) ?>
                    <span id="integrity-orphan-count" class="count-badge"></span>
                </div>
                <div id="integrity-orphan-list" class="integrity-list"></div>
            </div>
            <div class="integrity-section" id="integrity-section-misplaced">
                <div class="integrity-section-title">
                    <?= icon("arrow-left-right") ?> <?= e(__("integrity_misplaced_label")) ?>
                    <span id="integrity-misplaced-count" class="count-badge"></span>
                </div>
                <div id="integrity-misplaced-list" class="integrity-list"></div>
            </div>
            <div class="integrity-section" id="integrity-section-duplicate">
                <div class="integrity-section-title">
                    <?= icon("files") ?> <?= e(__("integrity_duplicate_label")) ?>
                    <span id="integrity-duplicate-count" class="count-badge"></span>
                </div>
                <div id="integrity-duplicate-list" class="integrity-list"></div>
            </div>
            <div class="integrity-section" id="integrity-section-tmp">
                <div class="integrity-section-title">
                    <?= icon("eraser") ?> <?= e(__("integrity_tmp_label")) ?>
                    <span id="integrity-tmp-count" class="count-badge"></span>
                </div>
                <div id="integrity-tmp-list" class="integrity-list"></div>
            </div>
            <div class="integrity-section" id="integrity-section-schema">
                <div class="integrity-section-title">
                    <?= icon("wrench") ?> <?= e(__("integrity_schema_label")) ?>
                    <span id="integrity-schema-count" class="count-badge"></span>
                </div>
                <div id="integrity-schema-list" class="integrity-list"></div>
            </div>
            <div class="integrity-section" id="integrity-section-storage" hidden>
                <div class="integrity-section-title">
                    <?= icon("database") ?> <?= e(__("integrity_storage_label")) ?>
                </div>
                <div id="integrity-storage-list" class="integrity-list"></div>
                <p class="hint-sm"><?= e(__("integrity_storage_hint")) ?></p>
            </div>
            <div class="integrity-section" id="integrity-section-multiuser">
                <div class="integrity-section-title">
                    <?= icon("siren", "ic-err") ?> <?= e(__("integrity_multiuser_label")) ?>
                </div>
                <p id="integrity-multiuser-detail" class="hint-sm"></p>
            </div>
            <div id="integrity-all-clear" class="integrity-empty" hidden>
                <?= e(__("integrity_all_clear")) ?>
            </div>
        </div>
        <div class="modal-footer">
            <button class="dlg-close btn btn-ghost">
                <?= e(__("cancel")) ?>
            </button>
            <button id="btn-integrity-recheck" class="btn btn-primary">
                <?= icon("refresh-cw") ?> <?= e(__("integrity_recheck")) ?>
            </button>
        </div>
    </div>
</div>

<!-- ══════ SESSION LOCK OVERLAY ══════ -->
<div id="session-lock" class="session-lock" hidden aria-modal="true" role="alertdialog" aria-labelledby="session-lock-title" aria-describedby="session-lock-msg">
    <div class="session-lock-card">
        <div class="session-lock-icon"><?= icon("lock") ?></div>
        <h2 id="session-lock-title" class="session-lock-title">
            <?= e(__("session_lock_title")) ?>
        </h2>
        <p id="session-lock-msg" class="session-lock-msg"></p>
        <button type="button" id="session-lock-btn" class="btn btn-primary session-lock-btn">
            <?= e(__("back_to_login")) ?>
        </button>
        <p id="session-lock-help" class="session-lock-help" hidden></p>
    </div>
</div>

<?php
// No contact link: that one is for visitors, not the logged-in owner.
$footerContactLink = "";
require __DIR__ . "/partials/page-footer.php";
?>

<?php
$jsVars = [
    "AESBIN_BASE" => AESBIN_BASE,
    // Read by the migration banner to report "1 → 2" once the run finishes.
    "AESBIN_DB_SCHEMA" => AESBIN_DB_SCHEMA_VERSION,
    "AESBIN_IDLE_TIMEOUT" => AESBIN_IDLE_TIMEOUT,
    "AESBIN_SHARE_EXPIRING_SOON" => SHARE_EXPIRING_SOON,
    // So the lock/unlock buttons survive a reload. Cosmetic only: the
    // session timestamp checked by requireElevated() is the gate.
    "AESBIN_ELEVATED_UNTIL" => (int) ($_SESSION["elevated_until"] ?? 0),
    // The ALTCHA widget for the settings card's "Test on this device" button,
    // which works even with the captcha off or unconfigured (see
    // captcha_test.php). Not loaded with the page: loadAltcha() in
    // dashboard.js adds it the first time Settings opens.
    "AESBIN_ALTCHA" => [
        "src" => asset("vendor/altcha/altcha.umd.min.cjs"),
        "lang" => Lang::current(),
        "i18n" => altcha_i18n_strings(),
    ],
];
$pageJs = [
    "helper.js",
    "lang-select.js",
    "api.js",
    "gotop.js",
    "preview.js",
    "strip-meta.js",
    "defer:passkey.js",
    "defer:dashboard.js",
];
require __DIR__ . "/partials/document-end.php";
