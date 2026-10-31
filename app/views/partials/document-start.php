<?php

/**
 * app/views/partials/document-start.php — opens the HTML document for every
 * runtime view: doctype, <head> (meta tags, favicons, stylesheets), no-js
 * handling, then <body> and .page-wrap. Closed by document-end.php, which
 * also loads the scripts and ALTCHA.
 *
 * app/setup/index.php does not use it: it runs before config.php exists, without
 * bootstrap.php (no AESBIN_BASE for asset(), no language loaded for __(), no
 * CSP nonce), and draws its own head in app/setup/template.php.
 *
 * Input variables (set before the include):
 *   string $pageTitle     Suffix after the app name in <title> ('' = app name only)
 *   array  $pageCss       Stylesheets relative to public/assets/css/
 *   string $bodyClass     CSS class for <body>
 *   string $headExtra     Raw HTML appended before </head> (e.g. kjua)
 *   string $htmlLang      Override for <html lang> (default Lang::current())
 *   bool   $requiresJs    See below (default true)
 *   OG / Twitter card (optional: omit or null to skip; the title is <title>):
 *   string|null $ogDescription og:description, twitter:description, <meta name="description">
 *   string|null $ogPath        page path for og:url via base_url(); null = bare origin
 */

declare(strict_types=1);

use AESBin\Lang;

defined("AESBIN_APP") || exit;

$pageTitle     = $pageTitle     ?? "";
$pageCss       = $pageCss       ?? [];
$bodyClass     = $bodyClass     ?? "";
// true: class="no-js" on <html>, no-js.js to remove it, and the "JavaScript
// required" overlay; base.css hides .page-wrap under .no-js. For pages that
// cannot work without scripts (login, recovery, dashboard).
// false: none of the three, so the content stays visible. For pages complete
// without JavaScript (privacy, 404).
$requiresJs    = $requiresJs    ?? true;
$htmlLang      = $htmlLang      ?? Lang::current();
$ogDescription = $ogDescription ?? null;
$ogPath        = $ogPath        ?? null;
$_title        = __("app_name") . ($pageTitle !== "" ? " — " . $pageTitle : "");
?>
<!DOCTYPE html>
<html lang="<?= e($htmlLang) ?>"<?= $requiresJs ? ' class="no-js"' : "" ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,interactive-widget=resizes-content">
<meta name="robots" content="noindex,nofollow">
<title><?= e($_title) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(asset("assets/meta/favicon.svg")) ?>">
<link rel="icon" type="image/png" sizes="96x96" href="<?= e(asset("assets/meta/favicon-96x96.png")) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset("assets/meta/favicon-32x32.png")) ?>">
<link rel="icon" type="image/png" sizes="16x16" href="<?= e(asset("assets/meta/favicon-16x16.png")) ?>">
<link rel="icon" href="<?= e(asset("favicon.ico")) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= e(asset("apple-touch-icon.png")) ?>">
<link rel="manifest" href="<?= e(asset("assets/meta/site.webmanifest")) ?>">
<?php if ($requiresJs): ?>
<script src="<?= e(asset("assets/js/no-js.js")) ?>"></script>
<?php endif; ?>
<?php foreach ($pageCss as $_css): ?>
<link rel="stylesheet" href="<?= e(asset("assets/css/" . $_css)) ?>">
<?php endforeach; ?>
<?php if ($ogDescription !== null):
    // Origin and banner are the same for every page; only $ogPath varies.
    // Always https: bootstrap.php refuses to serve anything else. host_label(),
    // not the raw Host header, so only host-name characters reach the tags.
    $ogOrigin = "https://" . host_label();
    $ogImage  = $ogOrigin . asset("assets/img/banner.webp");
    $ogUrl    = $ogPath !== null ? $ogOrigin . base_url($ogPath) : $ogOrigin;
?>
<meta name="description" content="<?= e($ogDescription) ?>">
<link rel="canonical" href="<?= e($ogUrl) ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= e($ogUrl) ?>">
<meta property="og:title" content="<?= e($_title) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:image:width" content="1280">
<meta property="og:image:height" content="640">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($_title) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<meta name="twitter:image" content="<?= e($ogImage) ?>">
<?php endif; ?>
<?php if ($headExtra ?? false): ?>
<?= $headExtra . "\n" ?>
<?php endif; ?>
</head>
<body<?= $bodyClass !== "" ? ' class="' . e($bodyClass) . '"' : "" ?>>
<?= icon_sprite() . "\n" ?>
<?php if ($requiresJs): ?>
<!-- ── No-JS overlay ── shown via .no-js-only when JS is unavailable -->
<div class="noscript-overlay no-js-only">
    <div class="noscript-card">
        <noscript>
            <img class="noscript-img" src="<?= e(asset("assets/img/noscript.webp")) ?>" alt="<?= e(__("noscript_msg")) ?>">
            <div class="noscript-msg"><?= e(__("noscript_msg")) ?></div>
        </noscript>
    </div>
</div>
<?php endif; ?>
<!-- ── Page content: hidden without JS via .no-js .page-wrap, on $requiresJs pages only ── -->
<div class="page-wrap">
