<?php

declare(strict_types=1);

namespace AESBin;

defined("AESBIN_APP") || exit;

/**
 * app/includes/Csrf.php — CSRF protection (defence in depth)
 *
 * SameSite=Strict cookies are the primary defence: a forged cross-site
 * request arrives without the session cookie and fails requireAuth(). This is
 * a second layer for clients that don't honour SameSite.
 *
 * The token travels in a custom header, not a form field: a cross-site <form>
 * POST cannot set one, only same-origin JS (or a fetch that passed CORS
 * preflight). One token per PHP session, shipped as window.AESBIN_CSRF
 * (document-end.php) and sent as X-CSRF-Token by every mutating request.
 *
 * Checked in Auth::requireAuth(), so every authenticated mutating request is
 * covered. Not applied to safe methods, to /api/share/* (no owner session;
 * the share password is the visitor's proof), or to the pre-authentication
 * /api/auth/* endpoints (login, finalize, recovery), where a forged
 * request gains the attacker nothing.
 */

final class Csrf
{
    public const SESSION_KEY = "csrf_token";
    private const HEADER_NAME = "HTTP_X_CSRF_TOKEN";

    /**
     * Return this session's CSRF token, generating it on first use. Tied to
     * the PHP session, not to auth_ok, so it can be issued on pre-login pages.
     */
    public static function token(): string
    {
        if (
            empty($_SESSION[self::SESSION_KEY]) ||
            !is_string($_SESSION[self::SESSION_KEY])
        ) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Has this session already been issued a token? Never creates one, so it
     * answers "did this client load one of our pages first?". Public
     * endpoints that allocate server-side state use it to refuse callers with
     * no session (see app/actions/auth/passkey_challenge.php).
     */
    public static function issued(): bool
    {
        return !empty($_SESSION[self::SESSION_KEY]) &&
            is_string($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Validate the CSRF header against the session token for POST, PUT,
     * PATCH and DELETE; other methods pass. Responds 403 and exits on a
     * missing or wrong token.
     */
    public static function assertValid(): void
    {
        $method = $_SERVER["REQUEST_METHOD"] ?? "GET";
        if (!in_array($method, ["POST", "PUT", "PATCH", "DELETE"], true)) {
            return;
        }

        $sent = $_SERVER[self::HEADER_NAME] ?? "";
        $expected = $_SESSION[self::SESSION_KEY] ?? "";

        if (
            $sent === "" ||
            $expected === "" ||
            !hash_equals($expected, $sent)
        ) {
            json_response(
                ["error" => __("csrf_failed"), "code" => "csrf_failed"],
                403
            );
        }
    }
}
