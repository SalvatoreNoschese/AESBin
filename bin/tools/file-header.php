<?php

/**
 * bin/tools/file-header.php — checks that PHP files open with the PSR-12
 * header, in this order and each block followed by one blank line:
 *
 *   <?php
 *   file docblock                     optional
 *   declare(strict_types=1);
 *   namespace X;                      optional
 *   use X\Y;                          optional, one per line
 *   defined('X_APP') || exit;         or define('X_APP', true); or nothing
 *
 * Called by bin/lint.sh. Usage: php bin/tools/file-header.php CONST
 * Reads "<line> <path>" pairs from stdin, where <line> is guard (the file
 * refuses direct access), define (an entry point that defines CONST) or
 * none (tooling: the header without the last line). Prints one line per
 * file that does not match; exit status 1 if there is any.
 */

declare(strict_types=1);

if ($argc !== 2 || preg_match('/^[A-Z][A-Z0-9_]*$/', $argv[1]) !== 1) {
    fwrite(STDERR, "usage: php bin/tools/file-header.php CONST < list\n");
    exit(2);
}
$const = $argv[1];

$steps = [
    'opening tag'    => '<\?php\n\n',
    'file docblock'  => '(?:/\*(?:[^*]|\*(?!/))*\*/\n\n)?',
    'declare'        => 'declare\(strict_types=1\);\n(?:\n|\z)',
    'namespace'      => '(?:namespace [A-Za-z0-9_\\\\]+;\n\n)?',
    'use'            => '(?:(?:use [A-Za-z0-9_\\\\]+(?: as [A-Za-z0-9_]+)?;\n)+\n)?',
];
$last = [
    'guard'  => ["defined('$const') || exit;", "defined(\"$const\") || exit;"],
    'define' => ["define('$const', true);", "define(\"$const\", true);"],
];

$bad = 0;
while (($row = fgets(STDIN)) !== false) {
    $row = rtrim($row, "\n");
    if ($row === '') {
        continue;
    }
    [$want, $file] = explode(' ', $row, 2) + ['', ''];
    $src = @file_get_contents($file);
    if ($src === false) {
        echo "  $file: unreadable\n";
        $bad++;
        continue;
    }
    $src = str_replace("\r\n", "\n", $src);
    $at = 0;
    $error = null;
    foreach ($steps as $name => $re) {
        if (preg_match('#\G' . $re . '#', $src, $m, 0, $at) !== 1) {
            $error = $name === 'opening tag'
                ? 'does not start with "<?php" and a blank line'
                : "no $name where expected (or no blank line after it)";
            break;
        }
        $at += strlen($m[0]);
    }
    if ($error === null && isset($last[$want])) {
        $error = "the first statement after the header is not {$last[$want][0]}";
        $eol = strpos($src, "\n", $at);
        $line = substr($src, $at, $eol === false ? null : $eol - $at);
        if (in_array($line, $last[$want], true)) {
            $next = $eol === false ? '' : substr($src, $eol + 1, 1);
            $error = $next === '' || $next === "\n" ? null : 'no blank line after the guard';
        }
    } elseif ($error === null && $want !== 'none') {
        $error = "unknown check \"$want\"";
    }
    if ($error !== null) {
        $lineNo = substr_count(substr($src, 0, $at), "\n") + 1;
        echo "  $file:$lineNo: $error\n";
        $bad++;
    }
}
exit($bad > 0 ? 1 : 0);
