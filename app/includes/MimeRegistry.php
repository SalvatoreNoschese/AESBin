<?php

declare(strict_types=1);

namespace AESBin;

defined("AESBIN_APP") || exit;

/**
 * app/includes/MimeRegistry.php — the file formats AESBin accepts.
 *
 * One table, FORMATS: each extension with the MIME types PHP's finfo may
 * report for its content, and whether it opens in the text editor and in
 * the preview. An upload is checked against it (resolveSniffed()):
 *
 *   - the content's type is listed under the file's own extension: the
 *     name is kept;
 *   - it is listed under another extension: the file is renamed to it
 *     (a PNG named .exe becomes .png);
 *   - it is not listed: the upload is refused.
 *
 * Text files without an extension are named after their content, or
 * .md / .txt (TEXT_NAMES). Adding a format means adding one line here.
 *
 * What belongs in the list, so that it stays short:
 *
 *   1. formats AESBin does something with: it previews them or edits them;
 *   2. a few everyday formats with neither, that nobody should have to zip:
 *      office documents, archives, installers, fonts.
 *
 * Anything else is uploaded zipped, as the upload help says. Formats that
 * are rare, heavy or with no preview in every browser (camera RAW, TIFF,
 * HEIC, MKV, PSD, AVI...) stay out on purpose.
 */

final class MimeRegistry
{
    /**
     * category => [extension => [MIME types, editable, previewable,
     *                             (optional) name-only types]]
     *
     * Categories and extensions display in this order (upload help, editor
     * dropdown); a category maps to the lang key "cat_{category}".
     *
     * MIME types: the first is the format's own, used for files created in
     * the editor; the others are what finfo may report instead (each PHP
     * version bundles its own libmagic, which names some formats
     * differently: audio/x-wav for wav) or, for a format libmagic cannot
     * tell from a plain ZIP (Android, Java and Firefox packages), the
     * container it is built on, so a file named that way keeps its name.
     * Office documents and EPUB are recognised by their content, so a plain
     * ZIP named .docx becomes .zip.
     *
     * A file whose extension does not match its content goes to the FIRST
     * extension listing the type: "txt" leads the text/plain group, "zip"
     * comes before the packages built on ZIP, and video before audio
     * (video/mp4 is .mp4, not .m4a).
     *
     * Name-only types (optional fourth entry): a file named that way keeps
     * its name, but nothing is ever renamed to it, because other formats
     * share the type: a .jar is a Java archive too, a Linux program or a .so
     * library is ELF too, old Office files and installers are OLE
     * (vnd.ms-office), which some libmagic builds report for them. So
     * an ELF program not named .appimage is refused, not given that name.
     *
     * "previewable": the preview pane shows it. Every image, audio and video
     * format listed has a preview: one that some browser cannot show (HEIC,
     * outside Safari) does not belong in the list.
     */
    private const FORMATS = [
        "data" => [
            "txt" => [["text/plain"], true, true],
            "json" => [["application/json"], true, true],
            "yaml" => [["text/yaml"], true, true],
            "yml" => [["text/yaml"], true, true],
            "xml" => [["text/xml", "application/xml"], true, true],
            "csv" => [["text/csv"], true, true],
            "sql" => [["application/sql"], true, true],
            "md" => [["text/markdown"], true, true],
            "markdown" => [["text/markdown"], true, true],
            "diff" => [["text/x-diff"], true, true],
            "patch" => [["text/x-diff"], true, true],
            "log" => [["text/plain"], true, true],
            "vcf" => [["text/vcard", "text/x-vcard"], true, true],
            "ics" => [["text/calendar"], true, true],
            "srt" => [["application/x-subrip"], true, true],
            "vtt" => [["text/vtt"], true, true],
        ],
        "web" => [
            "html" => [["text/html"], true, true],
            "htm" => [["text/html"], true, true],
            "css" => [["text/css"], true, true],
            "scss" => [["text/x-scss"], true, true],
            "js" => [["text/javascript", "application/javascript"], true, true],
            "mjs" => [["text/javascript"], true, true],
            "php" => [["text/x-php"], true, true],
        ],
        "script" => [
            "py" => [["text/x-python", "text/x-script.python"], true, true],
            "sh" => [["text/x-shellscript", "application/x-sh"], true, true],
            "bash" => [["text/x-shellscript"], true, true],
            "ps1" => [["text/x-powershell"], true, true],
            "bat" => [["text/x-bat"], true, true],
            "cmd" => [["text/x-bat"], true, true],
        ],
        "code" => [
            // finfo tells these apart poorly (Go and Rust sniff as C): the
            // extension is trusted when the content is text (FileService).
            "c" => [["text/x-c"], true, true],
            "h" => [["text/x-c"], true, true],
            "cpp" => [["text/x-c++"], true, true],
            "hpp" => [["text/x-c++"], true, true],
            "cs" => [["text/x-csharp"], true, true],
            "java" => [["text/x-java"], true, true],
            "kt" => [["text/x-kotlin"], true, true],
            "go" => [["text/x-go"], true, true],
            "rs" => [["text/x-rust"], true, true],
            "ts" => [["text/x-typescript"], true, true],
            "swift" => [["text/x-swift"], true, true],
            "rb" => [["text/x-ruby"], true, true],
        ],
        "config" => [
            "env" => [["text/plain"], true, true],
            // Windows setup information, and .ini files, which libmagic may
            // name by their content (setup script, Wine ini).
            "inf" => [["application/x-setupscript"], true, true],
            "ini" => [["text/plain", "application/x-wine-extension-ini", "application/x-setupscript"], true, true],
            "conf" => [["text/plain"], true, true],
            "cfg" => [["text/plain"], true, true],
            "dockerfile" => [["text/x-dockerfile"], true, true],
            "hosts" => [["text/plain"], true, true],
            "toml" => [["application/toml"], true, true],
            // Certificates and keys in PEM, the text form (a binary DER file is
            // refused: zipped, it uploads).
            "pem" => [["application/x-pem-file"], true, true],
            "crt" => [["application/x-x509-ca-cert"], true, true],
            // Also a Keynote presentation, which is a ZIP inside.
            "key" => [["application/x-pem-file"], true, true, ["application/zip"]],
        ],
        "documents" => [
            "pdf" => [["application/pdf"], false, true],
            "epub" => [["application/epub+zip"], false, false],
            "doc" => [["application/msword"], false, false, ["application/vnd.ms-office"]],
            "docx" => [["application/vnd.openxmlformats-officedocument.wordprocessingml.document"], false, false],
            "xls" => [["application/vnd.ms-excel"], false, false, ["application/vnd.ms-office"]],
            "xlsx" => [["application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"], false, false],
            "ppt" => [["application/vnd.ms-powerpoint"], false, false, ["application/vnd.ms-office"]],
            "pptx" => [["application/vnd.openxmlformats-officedocument.presentationml.presentation"], false, false],
            "odt" => [["application/vnd.oasis.opendocument.text"], false, false],
            "ods" => [["application/vnd.oasis.opendocument.spreadsheet"], false, false],
            "odp" => [["application/vnd.oasis.opendocument.presentation"], false, false],
            // Text inside, but not safely editable as plain text.
            "rtf" => [["text/rtf"], false, false],
        ],
        "images" => [
            "jpg" => [["image/jpeg"], false, true],
            "jpeg" => [["image/jpeg"], false, true],
            "png" => [["image/png"], false, true],
            "gif" => [["image/gif"], false, true],
            "webp" => [["image/webp"], false, true],
            "avif" => [["image/avif"], false, true],
            "svg" => [["image/svg+xml"], false, true],
            "ico" => [["image/x-icon", "image/vnd.microsoft.icon"], false, true],
            "bmp" => [["image/bmp", "image/x-ms-bmp"], false, true],
        ],
        "video" => [
            "mp4" => [["video/mp4"], false, true],
            "m4v" => [["video/x-m4v", "video/mp4"], false, true],
            "webm" => [["video/webm"], false, true],
            "mov" => [["video/quicktime"], false, true],
        ],
        "audio" => [
            "mp3" => [["audio/mpeg"], false, true],
            "m4a" => [["audio/mp4", "audio/x-m4a", "video/mp4"], false, true],
            "aac" => [["audio/aac", "audio/x-aac", "audio/x-hx-aac-adts"], false, true],
            "ogg" => [["audio/ogg"], false, true],
            "opus" => [["audio/opus", "audio/ogg"], false, true],
            "wav" => [["audio/wav", "audio/x-wav", "audio/wave", "audio/vnd.wave"], false, true],
            "flac" => [["audio/flac", "audio/x-flac"], false, true],
        ],
        "archives" => [
            "zip" => [["application/zip"], false, false],
            "7z" => [["application/x-7z-compressed"], false, false],
            "rar" => [["application/vnd.rar", "application/x-rar", "application/x-rar-compressed"], false, false],
            "tar" => [["application/x-tar"], false, false],
            "gz" => [["application/gzip", "application/x-gzip"], false, false],
            "tgz" => [["application/gzip", "application/x-gzip"], false, false],
            "bz2" => [["application/x-bzip2"], false, false],
            "xz" => [["application/x-xz"], false, false],
            "zst" => [["application/zstd", "application/x-zstd"], false, false],
            "iso" => [["application/x-iso9660-image"], false, false],
            // Firefox extension: a ZIP inside.
            "xpi" => [["application/x-xpinstall", "application/zip"], false, false],
        ],
        "executables" => [
            "exe" => [["application/x-msdownload", "application/x-dosexec", "application/vnd.microsoft.portable-executable"], false, false],
            "msi" => [["application/x-msi"], false, false, ["application/vnd.ms-office"]],
            // Android apps, and the split-app bundles (ZIPs of APKs).
            "apk" => [["application/vnd.android.package-archive", "application/zip"], false, false, ["application/java-archive"]],
            "apkm" => [["application/zip"], false, false],
            // Java programs: a ZIP inside, which libmagic names a Java archive
            // when the jar tool made it.
            "jar" => [["application/java-archive", "application/x-java-archive", "application/zip"], false, false],
            "apks" => [["application/zip"], false, false],
            "xapk" => [["application/zip"], false, false],
            "deb" => [["application/vnd.debian.binary-package", "application/x-debian-package"], false, false],
            "rpm" => [["application/x-rpm"], false, false],
            // A Linux program (ELF).
            "appimage" => [["application/x-appimage"], false, false, ["application/x-executable", "application/x-pie-executable", "application/x-sharedlib"]],
        ],
        "fonts" => [
            // sfnt is the container of TrueType and OpenType alike.
            "ttf" => [["font/ttf", "font/sfnt", "application/font-sfnt", "application/x-font-ttf"], false, false],
            "otf" => [["font/otf", "application/vnd.ms-opentype", "font/sfnt", "application/font-sfnt"], false, false],
            "woff" => [["font/woff", "application/font-woff"], false, false],
            "woff2" => [["font/woff2"], false, false],
        ],
    ];

    /**
     * Extension-less text files named after a format: README is Markdown.
     * Other extension-less text gets the extension of its type, or .txt.
     */
    private const TEXT_NAMES = [
        "readme" => "md",
        "changelog" => "md",
        "contributing" => "md",
    ];

    /**
     * FORMATS without the categories: extension => [MIME types, editable,
     * previewable, name-only types?], in the same order. Built once.
     *
     * @var array<string,array{0:list<string>,1:bool,2:bool,3?:list<string>}>|null
     */
    private static ?array $byExt = null;

    /**
     * MIME type => the first extension listing it, name-only types aside:
     * [extension, editable, previewable]. Built once.
     *
     * @var array<string,array{0:string,1:bool,2:bool}>|null
     */
    private static ?array $byMime = null;

    /** @return array<string,array{0:list<string>,1:bool,2:bool,3?:list<string>}> */
    private static function byExt(): array
    {
        return self::$byExt ??= array_merge(...array_values(self::FORMATS));
    }

    /** @return array<string,array{0:string,1:bool,2:bool}> */
    private static function byMime(): array
    {
        if (self::$byMime === null) {
            self::$byMime = [];
            foreach (self::byExt() as $ext => [$mimes, $editable, $previewable]) {
                foreach ($mimes as $mime) {
                    self::$byMime[$mime] ??= [(string) $ext, $editable, $previewable];
                }
            }
        }
        return self::$byMime;
    }

    /**
     * A name split into base and extension, where only a listed extension
     * counts (a compound ".tar.gz" whole, see FILENAME_COMPOUND_EXTS):
     * "photo.png" is ["photo", ".png"], but "File.v2", "Invoice no. 12" and
     * "Report 2026.10.20" are all base. Upload and rename both go by it, so
     * neither ever drops a part of a name it does not recognise.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitKnownExt(string $name): array
    {
        [$base, $ext] = filename_split_ext($name);
        if (substr_count($ext, ".") > 1) {
            return [$base, $ext];
        }
        return self::extToMime($name) !== null ? [$base, $ext] : [$name, ""];
    }

    /**
     * For text content and a name without its (wrong) extension: the
     * extension and MIME the name itself gives, or null. finfo sniffs all
     * text alike, so, as for an upload named "notes.md", a listed text
     * extension the name already has is trusted ("readme.md.exe" is
     * "readme.md", Markdown), and an extension-less README and the like are
     * Markdown (TEXT_NAMES), unless named otherwise ("README.txt").
     *
     * @return array{ext: string, mime: string}|null
     */
    public static function textByName(string $stem, string $mime): ?array
    {
        if (!self::looksLikeTextMime($mime)) {
            return null;
        }
        $own = strtolower(pathinfo($stem, PATHINFO_EXTENSION));
        $ownMime = $own !== "" ? self::extToMime($stem) : null;
        if ($ownMime !== null && self::isEditable($ownMime)) {
            return ["ext" => $own, "mime" => $ownMime];
        }
        return $ownMime === null ? self::textNamed($stem) : null;
    }

    /**
     * A README and the like (TEXT_NAMES) as Markdown: ["ext", "mime"], or
     * null for any other name. $name is the whole name, any unlisted "dot
     * part" included, so "README.old" is not one.
     *
     * @return array{ext: string, mime: string}|null
     */
    private static function textNamed(string $name): ?array
    {
        $named = self::TEXT_NAMES[strtolower($name)] ?? null;
        return $named !== null ? ["ext" => $named, "mime" => self::byExt()[$named][0][0]] : null;
    }

    /**
     * $name with its listed extension in lower case, as every stored name has
     * it: "IMG_0001.JPG" is "IMG_0001.jpg", "Backup.TAR.GZ" "Backup.tar.gz".
     * An unlisted dot part is name, so it keeps its case.
     */
    public static function lowerKnownExt(string $name): string
    {
        [$base, $ext] = self::splitKnownExt($name);
        return $base . strtolower($ext);
    }

    /** The format's own MIME for the filename's extension, or null if not listed. */
    public static function extToMime(string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return self::byExt()[$ext][0][0] ?? null;
    }

    /**
     * The name an upload is stored under, from the type finfo sniffed and the
     * name it arrived with: ["ext" => extension, "mime" => MIME to store], or
     * null when the type is not in FORMATS (refused). The stored type is
     * finfo's own, lowercased, except for an extension-less README and the
     * like, stored as Markdown.
     */
    public static function resolveSniffed(string $mime, string $filename): ?array
    {
        $mime = strtolower($mime);
        $ownAsGiven = pathinfo($filename, PATHINFO_EXTENSION);
        $own = strtolower($ownAsGiven);

        // Listed under the file's own extension, name-only types included:
        // keep the name (upload() lower-cases the extension).
        $format = self::byExt()[$own] ?? null;
        if ($format !== null && in_array($mime, [...$format[0], ...($format[3] ?? [])], true)) {
            return ["ext" => $ownAsGiven, "mime" => $mime];
        }

        // Text, under a name whose extension does not say so: README.md,
        // LICENSE.txt, script.sh, and a text type the list lacks
        // (text/x-perl) is still text, .txt: "script.pl" (".pl" unlisted, so
        // name) becomes "script.pl.txt", "Makefile.exe" "Makefile.txt".
        if (self::looksLikeTextMime($mime)) {
            return self::textNamed($format === null ? $filename : (string) pathinfo($filename, PATHINFO_FILENAME))
                ?? ["ext" => self::byMime()[$mime][0] ?? "txt", "mime" => $mime];
        }

        // Listed under another extension: rename. Not listed: refuse.
        $ext = self::byMime()[$mime][0] ?? null;
        return $ext !== null ? ["ext" => $ext, "mime" => $mime] : null;
    }

    /**
     * True if $mime opens in the inline text editor: its FORMATS flag, or,
     * for a type not listed, any text/*.
     */
    public static function isEditable(?string $mime): bool
    {
        if ($mime === null || $mime === "") {
            return false;
        }
        return self::byMime()[$mime][1] ?? str_starts_with($mime, "text/");
    }

    /**
     * True if a sniffed MIME is plausibly text: any text/* (text/rtf included:
     * this is about content, not editability) or an editable type
     * (application/json, application/xml...). FileService::upload() trusts
     * an editable extension only when this holds, since finfo sniffs .py,
     * .json and .md alike as text/plain.
     */
    public static function looksLikeTextMime(?string $mime): bool
    {
        if ($mime === null || $mime === "") {
            return false;
        }
        return str_starts_with($mime, "text/") || self::isEditable($mime);
    }

    /**
     * True if the preview pane shows $mime: its FORMATS flag under any of
     * the names listed, or, for a type not listed, any editable text. An
     * exact list, not the image/ video/ audio/ prefix. Mirrored in JS
     * through jsRules().
     */
    public static function isPreviewable(?string $mime): bool
    {
        if ($mime === null || $mime === "") {
            return false;
        }
        return self::byMime()[$mime][2] ?? self::isEditable($mime);
    }

    /**
     * category => [extension => MIME], in FORMATS order, empty categories
     * skipped. Feeds the upload help and, with $editableOnly, the editor's
     * extension dropdown (whose data-mime types a new file).
     *
     * @return array<string,array<string,string>>
     */
    public static function byCategory(bool $editableOnly = false): array
    {
        $out = [];
        foreach (self::FORMATS as $category => $formats) {
            foreach ($formats as $ext => [$mimes, $editable]) {
                if (!$editableOnly || $editable) {
                    $out[$category][$ext] = $mimes[0];
                }
            }
        }
        return $out;
    }

    /**
     * Extensions of the binary formats with a preview, in FORMATS order:
     * the upload help lists them, text files aside.
     *
     * @return list<string>
     */
    public static function previewExtensions(): array
    {
        $out = [];
        foreach (self::byExt() as $ext => [, $editable, $previewable]) {
            if ($previewable && !$editable) {
                $out[] = (string) $ext;
            }
        }
        return $out;
    }

    /**
     * For window.AESBIN_MIME_RULES: the exceptions to the text/* convention
     * (non-text types that edit or preview as text, "extra"; text/* types
     * that do not, "exceptions") and every non-text type with a preview
     * ("media").
     */
    public static function jsRules(): array
    {
        $rules = [
            "editable" => ["extra" => [], "exceptions" => []],
            "previewable" => ["extra" => [], "exceptions" => [], "media" => []],
        ];
        foreach (self::byMime() as $mime => [, $editable, $previewable]) {
            $isText = str_starts_with($mime, "text/");
            if ($editable !== $isText) {
                $rules["editable"][$editable ? "extra" : "exceptions"][] = $mime;
            }
            if ($previewable && !$editable) {
                $rules["previewable"]["media"][] = $mime;
            } elseif ($previewable !== $isText) {
                $rules["previewable"][$previewable ? "extra" : "exceptions"][] = $mime;
            }
        }
        foreach ($rules as $group => $lists) {
            foreach ($lists as $name => $list) {
                sort($list);
                $rules[$group][$name] = $list;
            }
        }
        return $rules;
    }
}
