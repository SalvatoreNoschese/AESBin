<?php

/**
 * app/views/partials/document-end.php — closes the HTML document opened by
 * document-start.php: .page-wrap, the nonce'd window.* config vars, the page
 * script tags, </body> and </html>. Nothing visible: the footer the user
 * sees is page-footer.php.
 *
 * Input variables (set them before the include):
 *   array  $jsVars          ['WINDOW_VAR_NAME' => value], json_encode'd
 *   array  $pageJs          Scripts relative to public/assets/js/.
 *                           Plain string = blocking; prefix 'defer:' = deferred.
 *   string $bodyExtra       Raw HTML appended before </body>
 *   bool   $captchaEnabled  Load the ALTCHA widget
 *   bool   $skipCsrfToken   Omit AESBIN_CSRF (sessionless public pages)
 *
 * obfuscate_mailto() calls made earlier in the request add AESBIN_OBFMAIL and
 * mailto-inject.js automatically.
 */

declare(strict_types=1);

use AESBin\Csrf;
use AESBin\Lang;
use AESBin\MimeRegistry;

defined("AESBIN_APP") || exit;

$jsVars = $jsVars ?? [];
$pageJs = $pageJs ?? [];
// File name => deferred?, parsed once for the checks and the tags below.
$_scripts = [];
foreach ($pageJs as $_js) {
    $_defer = str_starts_with($_js, "defer:");
    $_scripts[$_defer ? substr($_js, 6) : $_js] = $_defer;
}
$_scriptNames = array_keys($_scripts);
$bodyExtra = $bodyExtra ?? "";
$captchaEnabled = $captchaEnabled ?? false;

// The page's scripts declare which strings they need (their t('…') calls).
// Ship exactly those to window.AESBIN_LANG, unless a view set it explicitly.
if (!isset($jsVars["AESBIN_LANG"])) {
    $jsVars["AESBIN_LANG"] = Lang::forScripts($_scriptNames);
}

// One token per PHP session (app/includes/Csrf.php). Owner-facing pages always
// have a session (AESBIN_SESSION_ROUTE in bootstrap.php), so the login page
// has a token ready for the totp/finalize steps.
//
// The public views (share, privacy, 404) must set $skipCsrfToken: without a
// session the token could never validate, and they call no CSRF-protected
// endpoint (the share API is token-authenticated).
if (!isset($jsVars["AESBIN_CSRF"]) && empty($skipCsrfToken)) {
    $jsVars["AESBIN_CSRF"] = Csrf::token();
}

// Editable/previewable MIME rules for dashboard.js, preview.js and share.js,
// from the same MimeRegistry data the server uses. Small enough to always ship.
if (!isset($jsVars["AESBIN_MIME_RULES"])) {
    $jsVars["AESBIN_MIME_RULES"] = MimeRegistry::jsRules();
}

// The compound extensions the server keeps whole (filename_split_ext()):
// the dashboard's file dialog locks ".tar.gz" as one, and helper.js sorts
// "archive.tar.gz" before "archive_01.tar.gz" on every page that lists files.
if (!isset($jsVars["AESBIN_COMPOUND_EXTS"]) && in_array("helper.js", $_scriptNames, true)) {
    $jsVars["AESBIN_COMPOUND_EXTS"] = FILENAME_COMPOUND_EXTS;
}

// External Markdown editors preview.js offers for a .md source. Empty unless
// the owner lists some in config.php: the package cannot vouch for
// third-party sites.
if (!isset($jsVars["AESBIN_MD_TOOLS"]) && in_array("preview.js", $_scriptNames, true)) {
    $jsVars["AESBIN_MD_TOOLS"] = markdown_tools();
}

// What preview.js loads on demand, with asset()'s ?v= like every other
// static URL: the .htaccess caches static files for a year, and only a new
// URL makes a browser fetch an updated copy.
if (!isset($jsVars["AESBIN_PREVIEW_ASSETS"]) && in_array("preview.js", $_scriptNames, true)) {
    $jsVars["AESBIN_PREVIEW_ASSETS"] = [
        "pdf" => asset("vendor/pdfjs/pdf.min.mjs"),
        "pdfWorker" => asset("vendor/pdfjs/pdf.worker.min.mjs"),
        "prism" => asset("vendor/prism/prism.min.js"),
        "prismCss" => asset("assets/css/prism-aesbin.css"),
    ];
}

// obfuscate_mailto() only registers data and prints an empty
// <span id="obfmail-N">; mailto-inject.js fills it in, so it is added
// whenever the registry is non-empty.
$obfmailData = obfmail_registry();
if ($obfmailData) {
    $jsVars["AESBIN_OBFMAIL"] = $obfmailData;
    $_scripts["mailto-inject.js"] ??= false;
}

?>
</div><!-- /.page-wrap -->
<?php if ($jsVars): ?>
<script nonce="<?= AESBIN_NONCE ?>">
<?php foreach ($jsVars as $_name => $_val): ?>
window.<?= $_name ?> = <?= json_encode($_val, JSON_FOR_SCRIPT) ?>;
<?php endforeach; ?>
</script>
<?php endif; ?>
<?php if ($captchaEnabled): ?>
<!-- ALTCHA -->
<?php // Classic script, not type="module": only then is document.currentScript
      // set. The nonce is not for script-src ('self' covers it); the widget
      // copies it onto its injected <style> to satisfy style-src.
      // See docs/vendored-libraries.md § altcha (widget). ?>
<script src="<?= e(asset("vendor/altcha/altcha.umd.min.cjs")) ?>" nonce="<?= AESBIN_NONCE ?>"></script>
<script nonce="<?= AESBIN_NONCE ?>">
// Widget i18n strings come from the app language files (app/lang/*.php).
globalThis.$altcha?.i18n.set(<?= json_encode(Lang::current(), JSON_FOR_SCRIPT) ?>, <?= json_encode(altcha_i18n_strings(), JSON_FOR_SCRIPT) ?>);
</script>
<!-- /ALTCHA -->
<?php endif; ?>
<?php foreach ($_scripts as $_src => $_defer): ?>
<script src="<?= e(asset("assets/js/" . $_src)) ?>"<?= $_defer ? " defer" : "" ?>></script>
<?php endforeach; ?>
<?= $bodyExtra ?>
</body>
</html>
