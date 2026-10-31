<?php

/**
 * app/actions/system/settings.php
 *
 * GET/POST /api/system/settings
 * Auth    : requireKeyHolder(); requireElevated() for the sensitive keys
 * Body    : POST: settings object
 * Returns : GET: {security, captcha, locale, …} · POST: {saved, changed, message?}
 *
 * GET: current config values. POST: validate and rewrite config.php.
 */

declare(strict_types=1);

use AESBin\Lang;

defined("AESBIN_APP") || exit;

// requireKeyHolder(), not requireAuth(): the cookie alone must reach nothing
// beyond logout and the status probe (docs/security-model.md). This endpoint
// decrypts nothing, so without it a leaked cookie could read the settings and
// write the keys that need no elevation.
$auth->requireKeyHolder();

$configFile = AESBIN_ROOT . "/config.php";

if ($method === "GET") {
    $sec = (array) ($config["security"] ?? []);
    json_response([
        "security" => [
            "login_max_attempts" => setting_int($sec, "login_max_attempts"),
            "login_window" => setting_int($sec, "login_window"),
            "session_idle_timeout" => idle_timeout_setting($sec),
            "session_ip_lock" => !empty($sec["session_ip_lock"]),
        ],
        "captcha" => [
            "enabled" => !empty($config["captcha"]["enabled"]),
            "show_footer" => (bool) ($config["captcha"]["show_footer"] ?? true),
            "show_logo" => (bool) ($config["captcha"]["show_logo"] ?? true),
            "level" => captcha_level((array) ($config["captcha"] ?? [])),
            // Secrets are never returned.
        ],
        // The language actually in use, after Lang::load()'s fallback, not a
        // possibly broken config value.
        "locale" => Lang::current(),
        "activity_log_preview" => activity_log_preview_count($config),
        "activity_log_item_cap" => activity_log_item_cap($config),
        // A missing key reads as the default, never as 0 ("no cap beyond
        // PHP's"), the most permissive setting.
        "max_upload_bytes" => setting_int($config, "max_upload_bytes"),
        "max_total_bytes" => setting_int($config, "max_total_bytes"),
        "php_upload_limit" => php_upload_limit_bytes(),
        "storage_used" => storage_used_bytes($db),
        // SETTINGS_DEFAULTS (config_template.php) plus the two booleans and
        // the captcha level, which are not ints and live outside it.
        "defaults" => SETTINGS_DEFAULTS + [
            "session_ip_lock" => false,
            "captcha_enabled" => true,  // default matches render_config_php()
            "captcha_level" => CAPTCHA_LEVEL_DEFAULT,
        ],
        "privacy_enabled" => !empty($config["privacy_enabled"]),
        "privacy_name" => $config["privacy_name"] ?? "",
        "privacy_address" => $config["privacy_address"] ?? "",
        "privacy_vat" => $config["privacy_vat"] ?? "",
        "privacy_email" => $config["privacy_email"] ?? "",
        "contact_enabled" => !empty($config["contact_enabled"]),
        "contact_link" => $config["contact_link"] ?? "",
        "contact_referrer" => !empty($config["contact_referrer"]),
        // id is what /api/auth/passkey/remove takes. Not a secret: every
        // assertion carries it.
        "passkeys" => array_map(
            fn($c) => [
                "id" => $c["id"] ?? "",
                "name" => $c["name"] ?? "Passkey",
                "created_at" => $c["created_at"] ?? "",
            ],
            webauthn_data($db)["credentials"]
        ),
    ]);
}

if ($method === "POST") {
    $body = json_body();

    // Security-relevant keys need elevation: rate limit, IP lock and captcha
    // weaken defences, a lower log cap deletes audit entries, upload limits
    // bound how much can be stored. Locale and log preview do not.
    //
    // array_key_exists(), not isset(), as the privacy_*/contact_* appliers
    // below use: an explicit null would otherwise skip the gate and still
    // reach a writer.
    $elevatedKeys = [
        "security",
        "captcha",
        "privacy_enabled",
        "privacy_name",
        "privacy_address",
        "privacy_vat",
        "privacy_email",
        "contact_enabled",
        "contact_link",
        "contact_referrer",
        "activity_log_item_cap",
        "max_upload_bytes",
        "max_total_bytes",
    ];
    foreach ($elevatedKeys as $k) {
        if (array_key_exists($k, $body)) {
            $auth->requireElevated();
            break;
        }
    }

    // Read as arrays below, so {"security": "x"} must not reach an offset
    // read, and their fields are cast, so [] must not become 0 ("disabled").
    foreach (["security", "captcha"] as $group) {
        if (!isset($body[$group])) {
            continue;
        }
        if (!is_array($body[$group])) {
            json_response(["error" => __("err_field_invalid", ["field" => $group])], 400);
        }
        foreach ($body[$group] as $field => $value) {
            if ($value !== null && !is_scalar($value)) {
                json_response(["error" => __("err_field_invalid", ["field" => "{$group}.{$field}"])], 400);
            }
        }
    }

    // Top-level scalars, normalised (non-scalars rejected) before the casts
    // below.
    foreach (
        [
            "activity_log_preview",
            "activity_log_item_cap",
            "max_upload_bytes",
            "max_total_bytes",
            "locale",
            "privacy_name",
            "privacy_address",
            "privacy_vat",
            "privacy_email",
            "contact_link",
        ]
        as $field
    ) {
        if (isset($body[$field])) {
            normalize_body_field($body, $field);
        }
    }

    // Serialised, and read from the file rather than this request's $config:
    // two saves from two tabs would otherwise each write their own copy and
    // one change would be lost. The writer invalidates OPcache, so the
    // require sees the last save. Held until the request ends.
    try {
        db_advisory_lock($db, "aesbin_settings", "Settings save busy.");
    } catch (RuntimeException) {
        json_response(["error" => __("err_attempt_busy")], 503);
    }
    $fresh = require $configFile;
    $cfg = is_array($fresh) ? $fresh : $config;
    unset($fresh);
    // For the no-op check at the end.
    $cfgBefore = $cfg;

    // Section names for the log entry; the changed values are added from a
    // fixed list at the end (settings_log_changes()).
    $sections = [];

    // ── Security ──────────────────────────────────────────────────────────────
    if (isset($body["security"])) {
        $sections[] = "security";
        $s = $body["security"];
        // An omitted field keeps the saved value, via setting_int() so an
        // invalid hand-edited one falls back to its default rather than fail
        // validation for a field the request never sent. A sent one must be
        // an integer (body_int()): an (int) cast would read "x" as 0, which
        // is "disabled".
        $savedSec = (array) ($cfg["security"] ?? []);
        $sentOrSaved = static fn(string $key): ?int => isset($s[$key])
            ? body_int($s[$key])
            : setting_int($savedSec, $key);
        $maxAttempts = $sentOrSaved("login_max_attempts");
        $window = $sentOrSaved("login_window");
        // Not sent: the one in force (idle_timeout_setting()), which always fits.
        $idleTimeout = isset($s["session_idle_timeout"])
            ? body_int($s["session_idle_timeout"])
            : idle_timeout_setting($savedSec);

        // These three are dropdowns in the UI, so anything outside
        // SETTINGS_ALLOWED is a crafted request: rejected, not clamped.
        if (!in_array($maxAttempts, SETTINGS_ALLOWED["login_max_attempts"], true)) {
            json_response(
                ["error" => __("err_login_max_attempts_range")],
                400
            );
        }
        if (!in_array($window, SETTINGS_ALLOWED["login_window"], true)) {
            json_response(["error" => __("err_login_window_range")], 400);
        }
        if (!in_array($idleTimeout, SETTINGS_ALLOWED["session_idle_timeout"], true)) {
            json_response(["error" => __("err_idle_timeout")], 400);
        }
        // Greyed out in the UI: more than the host keeps a session.
        if (!idle_timeout_fits($idleTimeout)) {
            json_response(["error" => __("err_idle_timeout")], 400);
        }

        $cfg["security"]["login_max_attempts"] = $maxAttempts;
        $cfg["security"]["login_window"] = $window;
        $cfg["security"]["session_idle_timeout"] = $idleTimeout;
        if (isset($s["session_ip_lock"])) {
            $cfg["security"]["session_ip_lock"] = (bool) $s["session_ip_lock"];
        }
    }

    // ── Activity log ──────────────────────────────────────────────────────────
    if (isset($body["activity_log_preview"]) || isset($body["activity_log_item_cap"])) {
        $sections[] = "activity_log";
    }
    if (isset($body["activity_log_preview"])) {
        $preview = body_int($body["activity_log_preview"]);
        // Dropdown values only; rejected, not clamped.
        if (!in_array($preview, SETTINGS_ALLOWED["activity_log_preview"], true)) {
            json_response(["error" => __("err_actlog_rows")], 400);
        }
        $cfg["activity_log_preview"] = $preview;
    }
    if (isset($body["activity_log_item_cap"])) {
        $itemCap = body_int($body["activity_log_item_cap"]);
        if (!in_array($itemCap, SETTINGS_ALLOWED["activity_log_item_cap"], true)) {
            json_response(["error" => __("err_actlog_rows")], 400);
        }
        $cfg["activity_log_item_cap"] = $itemCap;
        // Stored cards are trimmed once config.php is written, below.
    }

    // ── Upload / storage limits ───────────────────────────────────────────────
    if (isset($body["max_upload_bytes"]) || isset($body["max_total_bytes"])) {
        $sections[] = "uploads";
    }
    // 0 means "no limit of our own" for both, so a value that is not an
    // integer is refused rather than cast to 0.
    if (isset($body["max_upload_bytes"])) {
        $v = body_int($body["max_upload_bytes"]);
        if ($v === null || $v < 0) {
            json_response(["error" => __("err_upload_bytes")], 400);
        }
        // Refused above PHP's limit: enforce_size_limits() applies
        // min(setting, PHP limit), so the field would promise more than
        // applies. Zero means "whatever PHP allows".
        $phpCap = php_upload_limit_bytes();
        if ($v > $phpCap) {
            json_response(
                [
                    "error" => __("err_upload_over_php", [
                        "limit" => format_bytes_binary($phpCap),
                    ]),
                ],
                400
            );
        }
        $cfg["max_upload_bytes"] = $v;
    }
    if (isset($body["max_total_bytes"])) {
        $v = body_int($body["max_total_bytes"]);
        if ($v === null || $v < 0) {
            json_response(["error" => __("err_upload_bytes")], 400);
        }
        $cfg["max_total_bytes"] = $v;
    }

    // ── Locale ────────────────────────────────────────────────────────────────
    if (isset($body["locale"])) {
        $sections[] = "locale";
        $locale = (string) preg_replace(
            "/[^a-z_]/",
            "",
            strtolower((string) $body["locale"])
        );
        // Lang::available(), not file_exists(): app/lang/index.php exists too,
        // and saving "index" would crash Lang::load() on every request.
        if (!array_key_exists($locale, Lang::available())) {
            json_response(["error" => __("err_locale") . ": " . $locale], 400);
        }
        $cfg["locale"] = $locale;
    }

    // ── Captcha (ALTCHA PoW only) ─────────────────────────────────────────────
    if (isset($body["captcha"])) {
        $sections[] = "captcha";
        $c = $body["captcha"];

        // Only the flags sent change, as for "security" above: a request
        // carrying just the level must not switch the captcha off.
        foreach (["enabled", "show_footer", "show_logo"] as $flag) {
            if (array_key_exists($flag, $c)) {
                $cfg["captcha"][$flag] = !empty($c[$flag]);
            }
        }

        // Optional (null counts as absent). An unknown level is refused, not
        // read as the default, so "saved" is never reported for it.
        if (isset($c["level"])) {
            if (!is_string($c["level"]) || !array_key_exists($c["level"], CAPTCHA_LEVELS)) {
                json_response(["error" => __("err_field_invalid", ["field" => "captcha.level"])], 400);
            }
            $cfg["captcha"]["level"] = $c["level"];
        }

        // Backfill secrets that are missing, too short or identical
        // (captcha_keys_valid()'s rule). render_config_php() re-checks, but
        // doing it here lets the no-op check see the change.
        if (!captcha_secret_valid($cfg["captcha"]["hmac_secret"] ?? null)) {
            $cfg["captcha"]["hmac_secret"] = bin2hex(random_bytes(32));
        }
        if (
            !captcha_secret_valid($cfg["captcha"]["key_signature_secret"] ?? null) ||
            $cfg["captcha"]["key_signature_secret"] === $cfg["captcha"]["hmac_secret"]
        ) {
            $cfg["captcha"]["key_signature_secret"] = bin2hex(random_bytes(32));
        }
    }

    if (
        array_key_exists("privacy_enabled", $body) ||
        array_key_exists("privacy_name", $body) ||
        array_key_exists("privacy_address", $body) ||
        array_key_exists("privacy_vat", $body) ||
        array_key_exists("privacy_email", $body)
    ) {
        $sections[] = "privacy";
    }
    if (array_key_exists("privacy_enabled", $body)) {
        $cfg["privacy_enabled"] = !empty($body["privacy_enabled"]);
    }
    if (array_key_exists("privacy_name", $body)) {
        // Plain text, never rendered as HTML. Capped, like the fields below:
        // config.php is parsed on every request.
        $cfg["privacy_name"] = mb_substr(trim((string) $body["privacy_name"]), 0, 120);
    }
    if (array_key_exists("privacy_address", $body)) {
        $cfg["privacy_address"] = mb_substr(trim((string) $body["privacy_address"]), 0, 250);
    }
    if (array_key_exists("privacy_vat", $body)) {
        $cfg["privacy_vat"] = mb_substr(trim((string) $body["privacy_vat"]), 0, 50);
    }
    if (array_key_exists("privacy_email", $body)) {
        $email = trim((string) $body["privacy_email"]);
        if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(["error" => __("err_contact_email_invalid")], 400);
        }
        $cfg["privacy_email"] = $email;
    }
    if (
        array_key_exists("contact_enabled", $body) ||
        array_key_exists("contact_link", $body) ||
        array_key_exists("contact_referrer", $body)
    ) {
        $sections[] = "contact";
    }
    if (array_key_exists("contact_enabled", $body)) {
        $cfg["contact_enabled"] = !empty($body["contact_enabled"]);
    }
    if (array_key_exists("contact_referrer", $body)) {
        $cfg["contact_referrer"] = !empty($body["contact_referrer"]);
    }
    if (array_key_exists("contact_link", $body)) {
        $link = trim((string) $body["contact_link"]);
        if ($link !== "" && !contact_link_valid($link)) {
            json_response(["error" => __("err_contact_link_invalid")], 400);
        }
        $cfg["contact_link"] = $link;
    }

    // The /privacy page names a data controller, so it needs a name and an
    // email. Checked on the final state, so clearing them while enabled fails.
    if (
        !empty($cfg["privacy_enabled"]) &&
        (trim((string) ($cfg["privacy_name"] ?? "")) === "" ||
            trim((string) ($cfg["privacy_email"] ?? "")) === "")
    ) {
        json_response(["error" => __("err_privacy_needs_contact")], 400);
    }

    // The footer contact link uses only its own target, independent of the
    // privacy page.
    if (
        !empty($cfg["contact_enabled"]) &&
        trim((string) ($cfg["contact_link"] ?? "")) === ""
    ) {
        json_response(["error" => __("err_contact_needs_link")], 400);
    }

    // Nothing changed: skip the rewrite and the log entry.
    if ($cfg === $cfgBefore) {
        json_response(["saved" => true, "changed" => false]);
    }

    // ── Rebuild config.php ────────────────────────────────────────────────────
    // render_config_php() writes only its declared schema: a hand-added key
    // outside it does not survive (see config_template.php).
    $written = write_config($configFile, $cfg);
    if (!$written) {
        json_response(
            ["error" => __("err_config_write")],
            500
        );
    }

    // A lower cap deletes what is over it now, not only on the next write.
    if (activity_log_item_cap($cfg) < activity_log_item_cap($cfgBefore)) {
        activity_log_trim($db, activity_log_item_cap($cfg));
    }

    // Each changed setting with its old and new value, from a fixed list
    // (SETTINGS_LOG_FIELDS): the captcha secrets never reach the log, and the
    // free-text fields are logged as changed without their content.
    $meta = ["sections" => $sections, "changes" => settings_log_changes($cfgBefore, $cfg)];
    if (in_array("locale", $sections, true)) {
        // So the reload that follows does not depend on config.php being
        // re-read yet. bootstrap.php drops it once config.php agrees.
        $_SESSION["locale_hint"] = $cfg["locale"];
    }
    log_activity($db, "settings.update", null, null, $meta);

    // Confirm a locale change in the new language: the browser only gets the
    // new strings after the reload. Safe this late, as no other __() call
    // follows.
    $out = ["saved" => true, "changed" => true];
    if (in_array("locale", $sections, true)) {
        Lang::load($cfg["locale"]);
        $out["message"] = __("saved");
    }
    json_response($out);
}
