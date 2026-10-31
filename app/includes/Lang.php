<?php

declare(strict_types=1);

namespace AESBin;

defined("AESBIN_APP") || exit;

/**
 * app/includes/Lang.php — Minimal i18n helper
 *
 * Usage:
 *   Lang::load('it');           // load language (falls back to 'en')
 *   __('key')                   // get translated string
 *   __('key', ['var' => 'val']) // with {var} placeholder substitution
 *
 * Placeholders are {name}, lower case, in every language file, and are filled
 * the same way on the client (fmt() in public/assets/js/helper.js). Named, not
 * positional, so a translation can put them in whatever order its grammar
 * needs; bin/lint.sh checks every language carries the same names.
 */

class Lang
{
    private static array $strings = [];
    private static string $current = "en";

    /**
     * Load a language file from /lang/{locale}.php.
     * Falls back to English if the locale file is missing.
     */
    public static function load(string $locale): void
    {
        // Prevents path traversal.
        $locale = preg_replace("/[^a-z_]/", "", strtolower($locale));
        $file = AESBIN_APP_DIR . "/lang/" . $locale . ".php";

        // is_array(), not a filename check: app/lang/index.php (the directory-listing
        // guard) returns int(1), as would any other non-language file in app/lang/.
        $strings = file_exists($file) ? require $file : null;
        if (!is_array($strings)) {
            $file = AESBIN_APP_DIR . "/lang/en.php";
            $strings = require $file;
            $locale = "en";
        }

        self::$strings = $strings;
        self::$current = $locale;
    }

    /**
     * Translated string with its {name} placeholders filled from $vars:
     * __('hello', ['name' => 'World']). Not HTML-escaped; see __() in helpers.php.
     */
    public static function get(string $key, array $vars = []): string
    {
        return self::fill(self::$strings[$key] ?? $key, $vars);
    }

    /**
     * Replace each {name} in $str that has an entry in $vars, in one pass: a
     * substituted value is never scanned again, and a placeholder with no
     * value is left as it is, so a missing argument shows instead of
     * vanishing. Must match fmt() in helper.js.
     */
    public static function fill(string $str, array $vars): string
    {
        if ($vars === []) {
            return $str;
        }
        return (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static fn(array $m): string => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0],
            $str
        );
    }

    public static function current(): string
    {
        return self::$current;
    }

    /**
     * The subset of the current language's strings used by the given scripts
     * (file names under public/assets/js/), found by scanning their t('…')
     * calls, so a page ships only what its JS uses.
     *
     * Call strings as t('literal'). A dynamic key needs a literal prefix, e.g.
     * t('pw_strength_' + n), which ships every pw_strength_* key. A key built
     * entirely at runtime is declared in the script with a directive:
     *
     *     // @lang-prefix log_
     *
     * which ships every log_* key.
     */
    public static function forScripts(array $scripts): array
    {
        $needed = [];
        foreach ($scripts as $name) {
            $path = AESBIN_ROOT . "/public/assets/js/" . $name;
            if (!is_file($path)) {
                continue;
            }
            $src = file_get_contents($path);
            // t('key') / t("key")
            if (preg_match_all('/(?<![\w.])t\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $src, $m)) {
                foreach ($m[1] as $k) {
                    $needed[$k] = true;
                }
            }
            // t('prefix' + …) or "@lang-prefix prefix": ship every key with that prefix.
            $prefixes = [];
            if (preg_match_all('/(?<![\w.])t\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\+/', $src, $m)) {
                $prefixes = $m[1];
            }
            if (preg_match_all('/@lang-prefix\s+([a-zA-Z0-9_]+)/', $src, $m)) {
                $prefixes = array_merge($prefixes, $m[1]);
            }
            if ($prefixes !== []) {
                foreach (array_unique($prefixes) as $prefix) {
                    foreach (self::$strings as $k => $_) {
                        if (str_starts_with($k, $prefix)) {
                            $needed[$k] = true;
                        }
                    }
                }
            }
        }
        return array_intersect_key(self::$strings, $needed);
    }

    /**
     * All locales in app/lang/, as code => ['name' => 'Italiano (IT)', 'flag' =>
     * 'assets/img/flags/it.svg'] for the language dropdowns (see
     * lang_select()). The name comes from each file's '_lang_name' key, the
     * flag from public/assets/img/flags/<code>.svg, or null without one: adding
     * app/lang/xx.php is all it takes to add a language, the flag is optional.
     */
    public static function available(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        foreach (glob(AESBIN_APP_DIR . "/lang/*.php") ?: [] as $file) {
            $code = basename($file, ".php");
            if (!preg_match('/^[a-z]{2,5}(_[a-z]+)?\z/', $code)) {
                continue;
            }
            $strings = require $file;
            // Skips app/lang/index.php, the directory-listing guard
            // (see bin/lint.sh 3h), which returns int(1).
            if (!is_array($strings)) {
                continue;
            }
            // An SVG, not a flag emoji in the name: Windows has no flag
            // emoji and shows the two letters instead.
            $flag = "assets/img/flags/{$code}.svg";
            $cache[$code] = [
                "name" => ($strings["_lang_name"] ?? strtoupper($code)) . " (" . strtoupper($code) . ")",
                "flag" => is_file(AESBIN_ROOT . "/public/" . $flag) ? $flag : null,
            ];
        }
        ksort($cache);
        return $cache;
    }
}
