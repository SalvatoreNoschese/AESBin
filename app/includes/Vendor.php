<?php

declare(strict_types=1);

namespace AESBin;

defined("AESBIN_APP") || exit;

/**
 * app/includes/Vendor.php — inventory of the vendored third-party libraries.
 *
 * No Composer or npm, so no lockfile: this list records which version of each
 * library is in the tree. About → Credits renders from it; the table in
 * docs/vendored-libraries.md mirrors it (bin/lint.sh 3c).
 *
 * Update 'version' in the same commit as the library; a stale version is
 * worse than none. Declared rather than detected, since most minified builds
 * carry no reliable version marker.
 */

final class Vendor
{
    /**
     * Vendored libraries, in the order shown in About → Credits. The comment on
     * each entry names its folder.
     * version: the exact release shipped, or null (rendered without a badge)
     *          when the build carries no version marker.
     */
    private const LIBRARIES = [
        [
            // public/vendor/altcha/
            "name" => "ALTCHA",
            "version" => "3.5.0",
            "url" => "https://github.com/altcha-org/altcha",
            "license" => "MIT",
            "role" => "altcha",
        ],
        [
            // app/vendor/altcha-lib-php/
            "name" => "ALTCHA-PHP",
            "version" => "2.3.0", // upstream package root, verbatim; see docs/vendored-libraries.md § altcha-lib-php
            "url" => "https://github.com/altcha-org/altcha-lib-php",
            "license" => "MIT",
            "role" => "altcha_php",
        ],
        [
            // public/vendor/kjua/
            "name" => "kjua",
            "version" => "0.10.0",
            "url" => "https://github.com/lrsjng/kjua",
            "license" => "MIT",
            "role" => "kjua",
        ],
        [
            // public/vendor/pdfjs/
            "name" => "PDF.js",
            "version" => "6.4.299",
            "url" => "https://github.com/mozilla/pdf.js",
            "license" => "Apache-2.0",
            "role" => "pdfjs",
        ],
        [
            // public/vendor/prism/: only prism.min.js, concatenated from unmodified
            // upstream components. The theme
            // (public/assets/css/prism-aesbin.css) is ours and lives outside
            // vendor/.
            "name" => "Prism",
            "version" => "1.30.0", // hand-assembled; recipe in docs/vendored-libraries.md § Prism
            "url" => "https://github.com/PrismJS/prism",
            "license" => "MIT",
            "role" => "prism",
        ],
        [
            // app/vendor/passkeys-php/: see docs/vendored-libraries.md § passkeys-php and
            // NOTICE.md there for attribution to Lukas Buchs, whose code this
            // library builds on.
            "name" => "passkeys-php",
            "version" => "2.0.1", // upstream package root, verbatim; see docs/vendored-libraries.md § passkeys-php
            "url" => "https://github.com/report-uri/passkeys-php",
            "license" => "MIT",
            "role" => "webauthn",
        ],
        // Lucide is not listed here on purpose: it is a static SVG sprite
        // (public/assets/img/icons.svg), not a vendored library with a folder
        // and a version to check. Its credit is in app/views/dashboard.php.
    ];

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return self::LIBRARIES;
    }
}
