<?php

/**
 * bin/tools/config-check.php — a local config.php compared with what
 * render_config_php() writes today. Read-only.
 *
 * Called by bin/lint.sh when a config.php sits in the project root (a local
 * install). The installer and every settings save write config.php through
 * render_config_php(); one written by an older version keeps its old
 * comments and lacks the keys added since, until the next save rewrites it.
 * This says what that save will change, and above all what it will drop.
 *
 *   php bin/tools/config-check.php config.php
 *
 * Output, one line per finding, tab-separated, for lint.sh to word:
 *   SAME                 nothing to do
 *   ADDED   <key>        new in the template, the save writes its default
 *   CHANGED <key>        the value will change (a hand edit outside the
 *                        allowed values, a captcha secret regenerated)
 *   LOST    <key>        not rendered by the template: the save drops it
 *   TEXT    <n>          lines of comments or layout that differ
 * Values are never printed: config.php holds the database password.
 * Exit status: 0 when SAME, 1 when the save will change something, 2 on an
 * error.
 */

declare(strict_types=1);

$path = (string) ($argv[1] ?? "");
if ($path === "" || !is_file($path)) {
    fwrite(STDERR, "usage: config-check.php <config.php>\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
define("AESBIN_APP", true);
define("AESBIN_ROOT", $root);
require $root . "/app/includes/helpers.php";
require $root . "/app/includes/Requirements.php";
require $root . "/app/includes/config_template.php";

$old = require $path;
if (!is_array($old)) {
    fwrite(STDERR, "$path does not return an array\n");
    exit(2);
}
$text = (string) file_get_contents($path);
$new = render_config_php($old);
// The rendered file read back the way PHP reads config.php, without a copy on
// disk: a temp file would be one more place holding the password.
// Opening tag, doc comment and declare() off: inside eval() a declare() is
// never the first statement, and strict_types changes nothing in a file that
// only returns an array.
$back = eval((string) preg_replace('#\A<\?php\s*(/\*.*?\*/\s*)?(declare\(strict_types=1\);)?#s', "", $new));
if (!is_array($back)) {
    fwrite(STDERR, "render_config_php() output does not return an array\n");
    exit(2);
}

if ($new === $text) {
    echo "SAME\n";
    exit(0);
}

/** @return array<string, mixed> dotted key => leaf value */
$flatten = static function (array $a, string $prefix = "") use (&$flatten): array {
    $out = [];
    foreach ($a as $k => $v) {
        $key = $prefix . $k;
        // A list (trusted_proxies) or an empty array is one value, not a branch.
        if (is_array($v) && $v !== [] && !array_is_list($v)) {
            $out += $flatten($v, $key . ".");
        } else {
            $out[$key] = $v;
        }
    }
    return $out;
};
$o = $flatten($old);
$n = $flatten($back);
$lost = array_diff_key($o, $n);
foreach (array_keys(array_diff_key($n, $o)) as $k) {
    echo "ADDED\t$k\n";
}
foreach ($o as $k => $v) {
    if (array_key_exists($k, $n) && $n[$k] !== $v) {
        echo "CHANGED\t$k\n";
    }
}
foreach (array_keys($lost) as $k) {
    echo "LOST\t$k\n";
}
// Lines present on one side only, counted as a multiset: a moved line is not
// a change, a reworded comment is.
$a = array_count_values(explode("\n", $text));
$b = array_count_values(explode("\n", $new));
$diff = 0;
foreach ($a + $b as $line => $_) {
    $diff += abs(($a[$line] ?? 0) - ($b[$line] ?? 0));
}
echo "TEXT\t" . intdiv($diff + 1, 2) . "\n";

exit(1);
