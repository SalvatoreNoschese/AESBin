<?php

/**
 * bin/tools/csp-hashes.php — the CSP hashes of static pages, checked or fixed.
 *
 * Called by bin/lint.sh. Pages served without PHP (public/help.html)
 * allow their own <style> and <script> by SHA-256 hash, in a <Files> or
 * <FilesMatch> block of an .htaccess. Change one byte of the block and the
 * page renders unstyled or without its script, with no error anywhere.
 *
 *   php bin/tools/csp-hashes.php [--fix] public/.htaccess public/help.html:style [page:tag …]
 *
 * For every Content-Security-Policy line inside a <Files>/<FilesMatch> block,
 * the files that block applies to are found, and each "<tag>-src" directive
 * must list exactly their hashes. Pages that share a block (a <FilesMatch>
 * naming several) share their hashes. --fix rewrites those directives in
 * place, keeping every other source in them and every other byte of the file.
 *
 * Output, one line per finding, tab-separated, for lint.sh to word in its own
 * language:
 *   OK      <tag> <files>
 *   STALE   <tag> <files> <extra> <missing>
 *                                    fixable: run again with --fix. <extra>:
 *                                    hashes in the .htaccess that no page has;
 *                                    <missing>: page=hash pairs the .htaccess
 *                                    lacks. Space-separated, "-" when empty
 *   FIXED   <tag> <files>
 *   NOBLOCK <tag> <file> <hash>      no CSP line in a block for that file:
 *                                    the hash has to be placed by hand
 *   NOTAG   <tag> <file>             the page has no such element
 * Exit status: 0 when nothing is STALE or NOBLOCK.
 */

declare(strict_types=1);

$args = array_slice($argv, 1);
$fix = false;
if (($args[0] ?? "") === "--fix") {
    $fix = true;
    array_shift($args);
}
$htaccess = (string) array_shift($args);
if ($htaccess === "" || !is_file($htaccess) || $args === []) {
    fwrite(STDERR, "usage: csp-hashes.php [--fix] <.htaccess> <page.html:tag> …\n");
    exit(2);
}

// The element's content between the opening and the closing tag, both at the
// start of a line: exactly the bytes the browser hashes.
$want = []; // tag => [basename => hash]
$exit = 0;
foreach ($args as $pair) {
    [$file, $tag] = array_pad(explode(":", $pair, 2), 2, "");
    $src = (string) @file_get_contents($file);
    if (!preg_match("#^<" . preg_quote($tag, "#") . ">(.*?)^</" . preg_quote($tag, "#") . ">#ms", $src, $m)) {
        echo "NOTAG\t$tag\t" . basename($file) . "\n";
        continue;
    }
    $want[$tag][basename($file)] = "sha256-" . base64_encode(hash("sha256", $m[1], true));
}

$lines = file($htaccess);
if ($lines === false) {
    fwrite(STDERR, "cannot read $htaccess\n");
    exit(2);
}

// Which pages a block applies to: <Files "help.html"> by name, <FilesMatch>
// by its regular expression, as Apache matches them (against the basename).
$applies = static function (array $block, string $name): bool {
    if ($block[0] === "files") {
        return $block[1] === $name;
    }
    $re = "#" . str_replace("#", "\\#", $block[1]) . "#";
    return @preg_match($re, $name) === 1;
};

$stack = [];
$seen = [];   // "tag\0file" => true once a block covers it
$changed = false;
foreach ($lines as $i => $line) {
    if (preg_match('#^\s*<Files\s+"?([^">]+)"?\s*>#i', $line, $m)) {
        $stack[] = ["files", $m[1]];
        continue;
    }
    if (preg_match('#^\s*<FilesMatch\s+"([^"]+)"\s*>#i', $line, $m)) {
        $stack[] = ["match", $m[1]];
        continue;
    }
    if (preg_match('#^\s*</Files(Match)?\s*>#i', $line)) {
        array_pop($stack);
        continue;
    }
    if ($stack === [] || stripos($line, "Content-Security-Policy") === false) {
        continue;
    }
    $block = end($stack);
    foreach ($want as $tag => $pages) {
        $covered = array_filter($pages, static fn($n) => $applies($block, $n), ARRAY_FILTER_USE_KEY);
        if ($covered === []) {
            continue;
        }
        $names = implode(", ", array_keys($covered));
        $expected = array_values(array_unique($covered));
        // (?![\w-]): "style-src" and not the start of "style-src-elem".
        $directive = "#(\b" . preg_quote($tag, "#") . "-src)(?![\w-])((?:\s+[^;\"\s]+)*)#";
        if (!preg_match($directive, $line, $d)) {
            continue; // this CSP line has no such directive: maybe another line does
        }
        foreach (array_keys($covered) as $n) {
            $seen["$tag\0$n"] = true;
        }
        $tokens = preg_split("/\s+/", trim($d[2]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $have = [];
        $other = [];
        foreach ($tokens as $t) {
            if (preg_match("#^'(sha(?:256|384|512)-[^']+)'$#", $t, $h)) {
                $have[] = $h[1];
            } else {
                $other[] = $t;
            }
        }
        $sortedHave = array_values(array_unique($have)); // a repeated hash is harmless
        $sortedWant = $expected;
        sort($sortedHave);
        sort($sortedWant);
        if ($sortedHave === $sortedWant) {
            echo "OK\t$tag\t$names\n";
            continue;
        }
        if (!$fix) {
            $extra = array_diff($sortedHave, $expected);
            $missing = [];
            foreach ($covered as $n => $h) {
                if (!in_array($h, $sortedHave, true)) {
                    $missing[] = "$n=$h";
                }
            }
            echo "STALE\t$tag\t$names\t" . ($extra === [] ? "-" : implode(" ", $extra))
                . "\t" . ($missing === [] ? "-" : implode(" ", $missing)) . "\n";
            $exit = 1;
            continue;
        }
        $new = $d[1] . " " . implode(" ", array_merge($other, array_map(static fn($h) => "'$h'", $expected)));
        $lines[$i] = $line = str_replace($d[0], $new, $line);
        $changed = true;
        echo "FIXED\t$tag\t$names\n";
    }
}

// A page no block covers: the hash may still be in the file another way (a
// policy set outside <Files>), which the previous, simpler check accepted.
$text = implode("", $lines);
foreach ($want as $tag => $pages) {
    foreach ($pages as $name => $hash) {
        if (isset($seen["$tag\0$name"])) {
            continue;
        }
        if (str_contains($text, "'$hash'")) {
            echo "OK\t$tag\t$name\n";
            continue;
        }
        echo "NOBLOCK\t$tag\t$name\t$hash\n";
        $exit = 1;
    }
}

if ($changed) {
    // Beside the original and renamed over it: never a half-written .htaccess.
    $tmp = $htaccess . ".csp-hashes.tmp";
    if (file_put_contents($tmp, $text) !== strlen($text) || !rename($tmp, $htaccess)) {
        @unlink($tmp);
        fwrite(STDERR, "cannot write $htaccess\n");
        exit(2);
    }
}
exit($exit);
