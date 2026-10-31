<?php

/**
 * app/includes/GuardFile.php — proof-of-filesystem mechanism shared by the
 * installer (app/setup/_viewme/) and account recovery (_recovery/ in the
 * storage directory).
 *
 * A randomly named file is written into a directory of its own, holding a
 * code that is never sent in any HTTP response. Reading it takes FTP/SFTP/SSH
 * or a hosting file manager, which separates whoever controls the server
 * from whoever found the URL first.
 *
 * Each gate keeps its own directory, lifetime and rules; only the mechanism
 * lives here. The installer's guard is created on first contact and cleared
 * once config.php exists; the recovery guard is created on request and
 * cleared once 'recovery' is back to 'no'.
 *
 * Both directories sit outside public/, so no URL reaches them. The file is
 * still a PHP stub that answers 403, not a .txt: it holds a secret, and a
 * server whose root points at the project folder by mistake would otherwise
 * hand it out. Where PHP does not run either, the 64-bit random filename is
 * the last layer, which is why the pages name the directory and never the
 * file. A dedicated directory also makes "is there a stale guard?" a single
 * stat.
 *
 * Also loaded by the installer, so it depends on helpers.php only (make_dir()).
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

/**
 * Path of the guard file in $dir, or null if there is none. Matched by name
 * shape, since a gate's own bookkeeping file may sit there too.
 */
function guard_file_find(string $dir): ?string
{
    if (!is_dir($dir)) {
        return null;
    }
    foreach (glob($dir . "/*.php") ?: [] as $file) {
        if (preg_match('/^[0-9a-f]{16}\.php\z/', basename($file)) === 1) {
            return $file;
        }
    }
    return null;
}

/**
 * Write a new guard file into $dir and return its code (8 hex characters,
 * 32 bits), or null if it could not be written. The code is for the caller's
 * own bookkeeping only: it must never reach a response.
 *
 * @param string   $marker Label the code is written after, e.g. "AESBIN-GUARD-CODE"
 * @param string[] $lines  Human-readable text above the code
 */
function guard_file_create(string $dir, string $marker, array $lines): ?string
{
    if (!make_dir($dir)) {
        return null;
    }
    $code = strtoupper(bin2hex(random_bytes(4)));
    $path = $dir . "/" . bin2hex(random_bytes(8)) . ".php";
    $body = "<?php http_response_code(403); exit; ?>\n" .
        implode("\n", $lines) . "\n\n" .
        $marker . ": " . $code . "\n";
    if (file_put_contents($path, $body, LOCK_EX) === false) {
        return null;
    }
    @chmod($path, 0640);
    return $code;
}

/** The code written after $marker in the guard file, or null if unreadable. */
function guard_file_read(string $path, string $marker): ?string
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    if (preg_match('/' . preg_quote($marker, "/") . ':\s*([A-F0-9]{8})/i', $raw, $m) !== 1) {
        return null;
    }
    return strtoupper($m[1]);
}

/** Remove a guard directory and everything in it. Safe when there is none. */
function guard_dir_clear(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . "/*") ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}
