<?php

/**
 * app/views/partials/page-footer.php — the visible footer of every runtime view
 * (.page-footer): the © link to help.html, then the privacy and contact
 * links where they apply. Each view includes it where the footer belongs in
 * its layout, which is not always just before document-end.php (the share
 * viewer and the privacy page keep it inside their own container).
 *
 * Input variables (set before the include, all optional):
 *   bool   $footerCenter       Centred under a card (login, recovery, 404)
 *   bool   $footerPrivacy      Show the privacy link. Defaults to
 *                              $privacyEnabled (set by router.php for every
 *                              view); the privacy page sets it false, since
 *                              the link would point to itself.
 *   string $footerContactLink  "Contact me" target, already filtered by
 *                              effective_contact_link(): an email address
 *                              or an https:// URL, where {lang} becomes the
 *                              page's language (contact_link_for()); "" for
 *                              no link. Defaults to $contactLink (set by
 *                              router.php for every view): every page shows
 *                              it but the dashboard, which sets "" since the
 *                              link is for visitors, not the owner.
 *   bool   $footerContactReferrer  A contact URL receives this site's origin
 *                              as the referrer (contact_referrer); otherwise
 *                              none. Defaults to $contactReferrer.
 */

declare(strict_types=1);

use AESBin\Lang;

defined("AESBIN_APP") || exit;

// Defaults for whatever the view left unset.
$footerCenter ??= false;
$footerContactLink ??= $contactLink ?? "";
$footerContactReferrer ??= $contactReferrer ?? false;
$footerPrivacy ??= $privacyEnabled;
?>
<footer class="page-footer<?= $footerCenter ? " page-footer-center" : "" ?>">
    <div class="page-footer-row">
        <?php /* base_url(), not asset(): help.html needs no cache-busting query. */ ?>
        <a href="<?= e(base_url("help.html")) ?>" class="page-footer-brand">
            &copy; <?= e(date("Y")) ?> AESBin
        </a>
        <?php if ($footerPrivacy || $footerContactLink !== ""): ?>
        <nav class="page-footer-nav">
            <?php if ($footerPrivacy): ?>
            <a href="<?= e(base_url("privacy")) ?>" class="page-footer-privacy-link"><?= e(__("privacy_footer_link")) ?></a>
            <?php endif; ?>
            <?php if ($footerContactLink !== "" && contact_link_is_url($footerContactLink)): ?>
            <?php
            /* A contact form: on another site it opens in a new tab, like
               every link that leaves AESBin; inside this installation, in the
               same tab. With contact_referrer it tells the form which site
               the visitor came from: the origin, never the page address (on
               a share page it names the share). The attribute overrides the
               page's policy either way; without contact_referrer the rel says
               the same, for a browser that does not read the attribute. */
            $contactHref = contact_link_for($footerContactLink, Lang::current());
            $contactRel = "nofollow" . ($footerContactReferrer ? "" : " noreferrer");
            $contactTarget = link_is_external($contactHref) ? ' target="_blank"' : "";
            if ($contactTarget !== "") {
                $contactRel = "noopener " . $contactRel;
            }
            ?>
            <a href="<?= e($contactHref) ?>"<?= $contactTarget ?> rel="<?= $contactRel ?>" referrerpolicy="<?= $footerContactReferrer ? "strict-origin" : "no-referrer" ?>" class="page-footer-contact-link"><?= e(__("contact_footer_link")) ?></a>
            <?php elseif ($footerContactLink !== ""): ?>
            <?php /* data-js-required: privacy.css shows it where the link cannot
               be built (the privacy page works without JavaScript). */ ?>
            <span class="page-footer-contact-link" data-js-required="<?= e(__("privacy_email_js_required")) ?>"><?= obfuscate_mailto($footerContactLink, __("contact_footer_link"), "AESBin - Feedback") ?></span>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    </div>
</footer>
