<?php

/**
 * bin/tools/regex-anchors.php — run by bin/lint.sh (5k). In PHP, "$" also
 * matches before a final newline: '/^[a-f0-9]{16}$/' accepts "abc…\n". A
 * pattern that checks a whole value ends with \z instead; only a multi-line
 * pattern (/m) keeps "$", where line ends are the point. Prints file:line
 * for each single-quoted pattern in app/ (vendored code and language files
 * aside) that is anchored with "^" and ends with "$", and exits 1 if any.
 */

declare(strict_types=1);

$root = __DIR__ . "/../../app";
$found = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = $file->getPathname();
    if (!str_ends_with($path, ".php") || str_contains($path, "/vendor/") || str_contains($path, "/lang/")) {
        continue;
    }
    foreach (file($path) ?: [] as $i => $line) {
        // A literal such as '#^…$#i': opening quote, delimiter, "^", the
        // pattern, "$", the same delimiter, flags, closing quote.
        if (preg_match_all('~\'([#/\~])\^.*?\$\1([a-zA-Z]*)\'~', $line, $hits, PREG_SET_ORDER) === 0) {
            continue;
        }
        foreach ($hits as $hit) {
            if (!str_contains($hit[2], "m") && !str_contains($hit[2], "D")) {
                $found[] = substr($path, strlen($root) - 3) . ":" . ($i + 1);
            }
        }
    }
}
foreach ($found as $where) {
    echo $where, "\n";
}
exit($found === [] ? 0 : 1);
