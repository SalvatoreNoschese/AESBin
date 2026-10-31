<?php

declare(strict_types=1);

namespace AESBin;

use PDO;
use RuntimeException;
use Throwable;

defined("AESBIN_APP") || exit;

/**
 * app/includes/Auth.php — AESBin authentication service
 *
 * Login verifies the Argon2id hash, derives the KEK, unwraps MASTER_KEY and
 * checks the mandatory TOTP code against the decrypted secret. Given only
 * the password, login() answers totp_required and the client sends both to
 * finalizeLogin(); neither is kept server-side in between.
 *
 * At login the browser generates a 32-byte session key and MASTER_KEY is
 * stored in $_SESSION wrapped with it. Each request resends the key
 * (X-Session-Key) to unwrap MASTER_KEY for that request only, so session
 * files alone are useless. No protection against root with live RAM access.
 *
 * $_SESSION holds auth_ok, master_key_enc/_iv/_tag, totp_pending,
 * csrf_token and the guards' ip, last_activity, elevated_until, plus the
 * short-lived entries of the passkey, recovery and locale flows.
 */

/**
 * Thrown by Auth::resolveSessionKey() / Auth::sessionMasterKey() when the
 * X-Session-Key header is missing, malformed, or does not unwrap MASTER_KEY
 * in $_SESSION. A new tab is in this state: it shares the session cookie but
 * starts with empty sessionStorage. router.php turns it into a 401 that
 * api.js handles (onSessionLost, session-lock overlay).
 */
final class SessionKeyException extends RuntimeException
{
}

class Auth
{
    private PDO $db;
    private CryptoService $crypto;
    private SessionRegistry $sessions;
    private int $idleTimeout = 0; // 0 = disabled
    private bool $ipLock = false;
    private ?SecurityService $security = null;

    public function __construct(PDO $db, CryptoService $crypto)
    {
        $this->db = $db;
        $this->crypto = $crypto;
        $this->sessions = new SessionRegistry($db);
    }

    /** Active session registry, used by the /api/system/sessions endpoints. */
    public function sessionRegistry(): SessionRegistry
    {
        return $this->sessions;
    }

    /** Set inactivity logout threshold (seconds). 0 = disabled. */
    public function setIdleTimeout(int $seconds): void
    {
        $this->idleTimeout = max(0, $seconds);
        // The registry prunes orphaned rows on the same timeout.
        $this->sessions->setIdleTimeout($this->idleTimeout);
    }

    /** Session-to-IP binding (hijack detection). Enabled unless config.php sets it false. */
    public function setIpLock(bool $on): void
    {
        $this->ipLock = $on;
    }

    /** The limiter verifyTotpWithKey() re-checks under its lock. */
    public function setSecurity(SecurityService $security): void
    {
        $this->security = $security;
    }

    // ── Login ─────────────────────────────────────────────────────────────────

    /**
     * Attempt login with password and optional TOTP code.
     *
     * @param string      $password  Raw password
     * @param string|null $totpCode  6-digit TOTP code, null if not yet submitted
     * @return array  status = 'ok' | 'totp_required' | 'key_mismatch'
     */
    public function login(string $password, ?string $totpCode, array $body = []): array
    {
        $row = $this->getRow();

        if (!password_verify($password, $row["password_hash"])) {
            $this->rejectBadPassword();
        }

        if (CryptoService::passwordNeedsRehash($row["password_hash"])) {
            $this->db
                ->prepare(
                    "UPDATE " .
                        DB_PREFIX .
                        // updated_at pinned to itself: an automatic rehash is
                        // not a credential change and must not show as one in
                        // the settings card's "last changed" hint.
                        "auth SET password_hash = :h, updated_at = updated_at WHERE id = 1"
                )
                ->execute([
                    ":h" => CryptoService::hashPassword($password),
                ]);
        }

        // TOTP is mandatory; there is no "disabled" state.
        if (!$totpCode) {
            $this->sessionPartial();
            return ["status" => "totp_required"];
        }

        // Before the code is checked: a request without a usable session key
        // must fail without spending the TOTP step (see verifyTotpWithKey()).
        $sessionKey = $this->resolveSessionKey($body);

        // The TOTP secret is encrypted, so MASTER_KEY is needed first. The
        // password is already verified, so an unwrap failure is a key mismatch.
        try {
            $masterKey = $this->unwrapWithPassword($password, $row);
        } catch (RuntimeException) {
            return $this->keyMismatch($sessionKey);
        }

        return $this->completeTotpLogin($row, $masterKey, $sessionKey, $totpCode);
    }

    /**
     * Second login step, after totp_required: password and TOTP code arrive
     * together, so MASTER_KEY is unwrapped and the code checked in one request.
     * Between steps the session holds only the totp_pending flag.
     */
    public function finalizeLogin(string $password, array $body = []): array
    {
        // finalize.php already checks this; kept here so the method is safe
        // whoever calls it.
        $this->requireTotpPending();

        $row = $this->getRow();
        // finalize.php has already rejected a non-scalar.
        $totpCode = isset($body["totp_code"]) && $body["totp_code"] !== ""
            ? (string) $body["totp_code"]
            : null;
        // As in login(): refused before any TOTP step can be spent.
        $sessionKey = $this->resolveSessionKey($body);

        try {
            $masterKey = $this->unwrapWithPassword($password, $row);
        } catch (RuntimeException) {
            // Either the password is wrong (login() accepted it, so it changed
            // in between, or the client is not auth.js) or it is right and the
            // wrapped key no longer matches it (rare, and worth blocking the
            // session over). Unlike login(), no password_verify() ran in this
            // request, so tell them apart here. The extra Argon2id cost is
            // paid only on failure.
            if (!password_verify($password, $row["password_hash"])) {
                sodium_memzero($sessionKey);
                $this->rejectBadPassword();
            }
            return $this->keyMismatch($sessionKey);
        }

        return $this->completeTotpLogin($row, $masterKey, $sessionKey, $totpCode);
    }

    /**
     * MASTER_KEY unwrapped with the KEK of $password. The KEK never outlives
     * this call. The password is not verified first: a wrong one fails the
     * unwrap.
     *
     * @throws RuntimeException when it does not unwrap
     */
    private function unwrapWithPassword(string $password, array $row): string
    {
        $kek = $this->crypto->deriveKek($password, $row["kek_salt"]);
        try {
            return $this->crypto->unwrapMasterKey(
                $row["wrapped_master"],
                $row["wrapped_master_iv"],
                $row["wrapped_master_tag"],
                $kek
            );
        } finally {
            sodium_memzero($kek);
        }
    }

    /**
     * Both login steps end here once MASTER_KEY is unwrapped: check the TOTP
     * code, then open the full session. Both keys are wiped in the caller's
     * variables (by reference) whatever the outcome.
     *
     * A missing code counts as a wrong one: the check is never skipped. There
     * is nothing to burn on failure, since the code lives only in this
     * request; each retry counts in both TOTP buckets, and
     * verifyTotpWithKey() refuses a step already spent.
     *
     * @param-out null $masterKey
     * @param-out null $sessionKey
     */
    private function completeTotpLogin(array $row, string &$masterKey, string &$sessionKey, ?string $totpCode): array
    {
        if ($totpCode === null || !$this->verifyTotpWithKey($row, $masterKey, $totpCode)) {
            sodium_memzero($masterKey);
            sodium_memzero($sessionKey);
            $this->recordTotpFailure();
            json_response(["error" => __("totp_error")], 403);
        }

        // The MASTER_KEY unwrapped for the TOTP check is reused, rather than
        // paying a second Argon2id derivation (64 MiB) for the same password.
        $this->sessionFull($masterKey, $sessionKey);
        sodium_memzero($masterKey);
        sodium_memzero($sessionKey);
        log_activity($this->db, "login.success");
        return ["status" => "ok"];
    }

    /**
     * A wrong password at either login step. "code" lets auth.js tell this
     * 403 from a wrong TOTP code at the second step and send the user back to
     * the password field.
     */
    private function rejectBadPassword(): never
    {
        log_activity($this->db, "login.failure", null, null, [
            "reason" => "bad_password",
        ]);
        json_response(["error" => __("login_error"), "code" => "bad_password"], 403);
    }

    /**
     * The password is right but no longer unwraps MASTER_KEY: a fresh
     * unauthenticated session, and the status the client shows its banner for.
     *
     * @param-out null $sessionKey
     */
    private function keyMismatch(string &$sessionKey): array
    {
        sodium_memzero($sessionKey);
        log_activity($this->db, "login.key_mismatch");
        $this->sessionBlocked();
        return ["status" => "key_mismatch"];
    }

    // ── Recovery ──────────────────────────────────────────────────────────────

    public function verifyRecoveryPhrase(string $phrase): bool
    {
        $row = $this->getRow();
        return password_verify(
            CryptoService::normalizeRecoveryPhrase($phrase),
            $row["recovery_hash"]
        );
    }

    /**
     * Execute account recovery via the 10-word phrase.
     * Unwraps MASTER_KEY with the recovery KEK and re-wraps it under the
     * requested new credentials. MASTER_KEY itself never changes, so files,
     * shares and metadata stay readable.
     *
     * The three resets are independent and optional (the caller refuses the
     * all-empty case). Every recovery also removes all passkeys and ends all
     * sessions. Everything goes into a SINGLE UPDATE: a partial write would
     * leave an installation nobody can open.
     *
     * @param string      $phrase       The 10-word recovery phrase
     * @param string|null $newPassword  New password, or null to keep the current one
     * @param string|null $newTotpSecret Replacement TOTP secret, already
     *                                  shown and verified by the caller
     *                                  (recovery_totp_verify.php), or null to
     *                                  keep the current one
     * @param string|null $newPhrase    Replacement recovery phrase, already
     *                                  generated and confirmed by the caller,
     *                                  or null to keep the current one
     * @param string      $passkeyRpId  RP ID of the registered passkeys
     *                                  (webauthn_rp_id()), returned with the
     *                                  user handle for the client-side signal
     * @return array{status: string, passkey_user_id?: string, passkey_rp_id?: string, warning?: string}
     *         The JSON payload for recovery_execute.php.
     */
    public function executeRecovery(
        string $phrase,
        ?string $newPassword,
        ?string $newTotpSecret,
        ?string $newPhrase,
        string $passkeyRpId
    ): array {
        $row = $this->getRow();
        $phrase = CryptoService::normalizeRecoveryPhrase($phrase);

        $recoveryKek = $this->crypto->deriveKek(
            $phrase,
            $row["recovery_kek_salt"]
        );
        try {
            $masterKey = $this->crypto->unwrapMasterKey(
                $row["recovery_wrapped_master"],
                $row["recovery_wrapped_master_iv"],
                $row["recovery_wrapped_master_tag"],
                $recoveryKek
            );
        } catch (RuntimeException) {
            sodium_memzero($recoveryKek);
            // Same bucket as recovery_verify.php, so direct calls to
            // /api/auth/recovery/execute are rate limited too.
            log_activity($this->db, "recovery.failure", null, null, [
                "stage" => "execute",
            ]);
            usleep(random_int(100000, 300000));
            json_response(["error" => __("recovery_error")], 403);
        }
        sodium_memzero($recoveryKek);

        $updateFields = [];
        $params = [];
        $response = ["status" => "ok"];

        // ── Password reset ────────────────────────────────────────────────────
        if ($newPassword !== null) {
            $newKekSalt = $this->crypto->generateSalt();
            $newKek = $this->crypto->deriveKek($newPassword, $newKekSalt);
            $newWrapped = $this->crypto->wrapMasterKey($masterKey, $newKek);
            sodium_memzero($newKek);

            $updateFields[] = 'password_hash=:ph, kek_salt=:ks,
                wrapped_master=:wm, wrapped_master_iv=:wmi, wrapped_master_tag=:wmt';
            $params += [
                ":ph" => CryptoService::hashPassword($newPassword),
                ":ks" => $newKekSalt,
                ":wm" => $newWrapped["enc"],
                ":wmi" => $newWrapped["iv"],
                ":wmt" => $newWrapped["tag"],
            ];
        }

        // ── TOTP reset ────────────────────────────────────────────────────────
        if ($newTotpSecret !== null) {
            $encTotp = $this->crypto->encryptMeta($newTotpSecret, $masterKey, CryptoService::AAD_TOTP);

            // The replay marker belongs to the old secret; kept, it could
            // refuse the new authenticator's first code.
            $updateFields[] =
                "totp_secret_enc=:tse, totp_secret_iv=:tsiv, totp_secret_tag=:tstag, totp_last_used_step=NULL";
            $params += [
                ":tse" => $encTotp["enc"],
                ":tsiv" => $encTotp["iv"],
                ":tstag" => $encTotp["tag"],
            ];
        }

        // ── Recovery phrase rotation ──────────────────────────────────────────
        // New salt, KEK, wrapping and hash: five columns that must move
        // together, so they join the single UPDATE below.
        if ($newPhrase !== null && $newPhrase !== "") {
            $newPhrase = CryptoService::normalizeRecoveryPhrase($newPhrase);
            $newRecSalt = $this->crypto->generateSalt();
            $newRecKek = $this->crypto->deriveKek($newPhrase, $newRecSalt);
            $newRecWrapped = $this->crypto->wrapMasterKey($masterKey, $newRecKek);
            sodium_memzero($newRecKek);

            $updateFields[] = 'recovery_hash=:rh, recovery_kek_salt=:rks,
                recovery_wrapped_master=:rwm, recovery_wrapped_master_iv=:rwmi,
                recovery_wrapped_master_tag=:rwmt';
            $params += [
                ":rh" => CryptoService::hashPassword($newPhrase),
                ":rks" => $newRecSalt,
                ":rwm" => $newRecWrapped["enc"],
                ":rwmi" => $newRecWrapped["iv"],
                ":rwmt" => $newRecWrapped["tag"],
            ];

            // Not echoed back: the owner has already saved and confirmed it,
            // and sending it again only puts the secret on the wire twice.
        }

        // ── Passkeys: always removed, never a choice ──────────────────────────
        // A passkey unwraps MASTER_KEY without password or TOTP, so it survives
        // every rotation above, and one registered by an attacker who knew the
        // password would be indistinguishable. user_id is kept so password managers
        // replace their entry on re-registration; webauthn_lock() stops a
        // concurrent registration from writing back over the cleared list.
        webauthn_lock($this->db);
        $wd = webauthn_data($this->db);
        $passkeysRemoved = count($wd["credentials"]);
        $passkeyUserId = (string) ($wd["user_id"] ?? "");
        $updateFields[] =
            "webauthn_data = JSON_SET(COALESCE(webauthn_data, JSON_OBJECT()), '$.credentials', JSON_ARRAY())";

        // Every live session is ended in the same transaction. A session
        // opened with the old credentials would keep reading, and one inside
        // its elevation window could register a passkey afterwards. Deleting
        // the registry rows revokes them (see SessionRegistry). The browser
        // running this recovery has no authenticated session to lose.
        $params[":id"] = 1;
        $this->db->beginTransaction();
        try {
            $this->db
                ->prepare(
                    "UPDATE " .
                        DB_PREFIX .
                        "auth SET " .
                        implode(", ", $updateFields) .
                        " WHERE id = :id"
                )
                ->execute($params);
            $sessionsEnded = $this->db->exec("DELETE FROM " . DB_PREFIX . "sessions");
            $this->db->commit();
        } catch (Throwable $e) {
            db_rollback_if_open($this->db);
            sodium_memzero($masterKey);
            throw $e;
        }

        // Close the gate. Leaving 'recovery' => 'yes' would keep the account
        // open to anyone holding a copy of a phrase that was not rotated; the
        // owner can reopen it with the same FTP access that opened it. The
        // guard file is removed by bootstrap.php on the next request.
        //
        // A write failure does not roll back the recovery (a read-only
        // config.php is deliberate hardening on some hosts), but it is logged
        // and reported in the response.
        global $config;
        $cfgFile = AESBIN_ROOT . "/config.php";
        $cfg = (array) $config;
        $cfg["recovery"] = "no";
        // write_config() also drops the OPcache entry: otherwise the old
        // file, gate open, would keep being served.
        if (!write_config($cfgFile, $cfg)) {
            log_activity($this->db, "recovery.gate_not_closed");
            $response["warning"] = __("err_recovery_gate_not_closed");
        }

        // No auto-login: the owner signs in again with the new credentials.
        sodium_memzero($masterKey);
        if ($newPhrase !== null && $newPhrase !== "") {
            // Its own event: this invalidates a credential the owner may hold
            // on paper, and the log should say so plainly.
            log_activity($this->db, "recovery.phrase_rotated");
        }
        // Lets the recovery page tell the password manager that no AESBin
        // passkey is valid any more (signalAllAcceptedCredentials with an
        // empty list, in auth.js); otherwise it keeps offering the removed
        // ones. The signal is keyed on the WebAuthn user handle, base64url as
        // the browser API expects. Not a secret: every assertion returns it.
        if ($passkeysRemoved > 0 && $passkeyUserId !== "" && $passkeyRpId !== "") {
            $response["passkey_user_id"] = b64url_encode((string) base64_decode($passkeyUserId, true));
            $response["passkey_rp_id"] = $passkeyRpId;
        }

        // Skipped when there was nothing to remove.
        if ($passkeysRemoved > 0) {
            log_activity($this->db, "recovery.passkeys_cleared", null, null, [
                "count" => $passkeysRemoved,
            ]);
        }
        log_activity($this->db, "recovery.success", null, null, [
            "sessions_ended" => (int) $sessionsEnded,
        ]);
        return $response;
    }

    // ── Show credentials (while authenticated) ────────────────────────────────

    /**
     * Decrypt the current TOTP secret for display in settings. The caller
     * gates it (totp_secret.php: requireElevated()); the secret is not stored
     * browser-side.
     */
    public function getDecryptedTotpSecret(): string
    {
        $row = $this->getRow();
        $mk = $this->sessionMasterKey();
        try {
            return $this->crypto->decryptMeta(
                $row["totp_secret_enc"],
                $row["totp_secret_iv"],
                $row["totp_secret_tag"],
                $mk,
                CryptoService::AAD_TOTP
            );
        } finally {
            sodium_memzero($mk);
        }
    }

    // ── Re-authentication ─────────────────────────────────────────────────────

    /**
     * Verify the master password against the stored Argon2id hash.
     *
     * Re-auth gate so that a stolen session alone is not enough for sensitive
     * actions. Credential changes (password, TOTP) have no authenticated path
     * at all: they go through the recovery phrase only.
     */
    public function verifyPassword(string $password): bool
    {
        $row = $this->getRow();
        return password_verify($password, $row["password_hash"]);
    }

    /**
     * Elevation ("sudo mode"): one password confirmation unlocks sensitive
     * settings actions for a fixed, non-sliding window. Enforced by the
     * session timestamp alone; any client-side indicator is cosmetic.
     */
    private const ELEVATION_TTL = 600; // seconds (10 min)

    /** Grant elevation to the current session. Returns the expiry ts. */
    public function grantElevation(): int
    {
        $expires = time() + self::ELEVATION_TTL;
        $_SESSION["elevated_until"] = $expires;
        return $expires;
    }

    /**
     * Gate for sensitive actions: key-holding session AND active elevation
     * window. Otherwise responds 403 reauth_required; the client shows the
     * password modal, calls /api/auth/elevate and retries.
     */
    public function requireElevated(): void
    {
        $this->requireKeyHolder();
        if (($_SESSION["elevated_until"] ?? 0) < time()) {
            // A timestamp still present means a granted window has lapsed.
            // Expiry is only noticed here, at the next gated action. Unset so
            // it is logged once.
            if (isset($_SESSION["elevated_until"])) {
                unset($_SESSION["elevated_until"]);
                log_activity($this->db, "settings.deelevated", null, null, [
                    "reason" => "expired",
                ]);
            }
            json_response(
                ["error" => __("reauth_required"), "code" => "reauth_required"],
                403
            );
        }
    }

    // ── Guards ────────────────────────────────────────────────────────────────

    public function requireAuth(): void
    {
        if (empty($_SESSION["auth_ok"])) {
            json_response(
                ["error" => __("auth_required"), "code" => "auth_required"],
                401
            );
        }

        // ── CSRF (see app/includes/Csrf.php) ──────────────────────────────────────
        Csrf::assertValid();

        $code = $this->sessionFailure();
        if ($code !== null) {
            $message = [
                "ip_changed" => "session_ip_changed",
                "session_expired" => "session_expired",
                "session_revoked" => "session_revoked",
            ][$code];
            json_response(["error" => __($message), "code" => $code], 401);
        }
    }

    /**
     * Whether the dashboard page may be served: an authenticated session that
     * passes the same IP, idle and revocation checks as an API call. A failing
     * session is logged out, so the redirect lands on a clean login.
     */
    public function pageSessionValid(): bool
    {
        return $this->isAuthenticated() && $this->sessionFailure() === null;
    }

    /**
     * The checks every authenticated request goes through after CSRF. Returns
     * null and refreshes last_activity when the session may continue;
     * otherwise logs it out and returns the 401 code.
     */
    private function sessionFailure(): ?string
    {
        // ── IP binding (hijack detection) ─────────────────────────────────────
        // On by default; users whose address changes mid-session (mobile, VPN)
        // can turn it off in Settings. Compared by client_network(): an IPv6
        // temporary address rotates within its /64 without being a different
        // client. $_SESSION["ip"] keeps the full address for the log.
        if (
            $this->ipLock &&
            !empty($_SESSION["ip"]) &&
            client_network((string) $_SESSION["ip"]) !== client_network(request_ip())
        ) {
            // Passed explicitly: logout() wipes $_SESSION first (see $sessionIp).
            $this->logout("ip_changed", $_SESSION["ip"]);
            return "ip_changed";
        }

        // ── Session idle timeout ──────────────────────────────────────────────
        // The client timer normally logs out first, after a warning bar, and
        // its keepalive keeps an active tab inside this limit. This is the
        // safety net (several tabs, JS bypassed). The 30 s grace lets the
        // client log out cleanly before the server returns a 401.
        // SessionRegistry::pruneExpired() relies on this timeout + 30 s.
        if ($this->idleTimeout > 0 && isset($_SESSION["last_activity"])) {
            $serverLimit = $this->idleTimeout + 30;
            if (time() - (int) $_SESSION["last_activity"] > $serverLimit) {
                $this->logout("idle_timeout");
                return "session_expired";
            }
        }

        // ── Session registry (remote revocation) ──────────────────────────────
        // No registry row means revoked (see SessionRegistry.php). Also
        // refreshes last_seen.
        if (!$this->sessions->validateAndTouch(session_id())) {
            $this->logout("session_revoked");
            return "session_revoked";
        }

        $_SESSION["last_activity"] = time();
        return null;
    }

    /**
     * requireAuth() plus proof of this tab's session key (one AES-GCM unwrap,
     * wiped at once), for endpoints that do not decrypt but change what a
     * session can do: revoking shares, managing sessions, elevation. A leaked
     * cookie alone then reaches only logout, status and dropping elevation.
     * Throws SessionKeyException (401 via router.php).
     */
    public function requireKeyHolder(): void
    {
        $this->requireAuth();
        $masterKey = $this->sessionMasterKey();
        sodium_memzero($masterKey);
    }

    public function requireTotpPending(): void
    {
        if (empty($_SESSION["totp_pending"])) {
            json_response(
                [
                    "error" => __("err_no_totp_session"),
                    "code" => "totp_pending",
                ],
                401
            );
        }
    }

    public function isAuthenticated(): bool
    {
        return !empty($_SESSION["auth_ok"]);
    }

    /**
     * This request's MASTER_KEY, unwrapped from $_SESSION with the tab's
     * session key. Methods here wipe it after use; action files keep it for
     * the rest of the request, which ends at their json_response().
     *
     * @throws SessionKeyException when either half is missing or they do not
     *         match (a new tab, a stale key); router.php answers 401.
     */
    public function sessionMasterKey(): string
    {
        $enc = $_SESSION["master_key_enc"] ?? null;
        $iv  = $_SESSION["master_key_iv"]  ?? null;
        $tag = $_SESSION["master_key_tag"] ?? null;
        if (!is_string($enc) || !is_string($iv) || !is_string($tag) || $enc === "") {
            throw new SessionKeyException("MASTER_KEY not in session.");
        }

        $sessionKey = $this->resolveSessionKey();
        try {
            return $this->crypto->decryptMeta($enc, $iv, $tag, $sessionKey, CryptoService::AAD_SESSION_KEY);
        } catch (RuntimeException) {
            throw new SessionKeyException("Session key mismatch: cannot unwrap MASTER_KEY.");
        } finally {
            sodium_memzero($sessionKey);
        }
    }

    public function logout(string $reason = "user", ?string $sessionIp = null): void
    {
        // Deregister while session_id() is still the old one; a no-op if the
        // row is already gone.
        $this->sessions->remove(session_id());

        // Normally the request's own ip. For "ip_changed" the session being
        // torn down belongs to the old ip, which the caller passes in;
        // request_ip() would log the new one as the device disconnecting.
        $ip = $sessionIp ?? request_ip();

        $wasAuthenticated = !empty($_SESSION["auth_ok"]);
        $_SESSION = [];
        // The attributes bootstrap.php sets, or the browser keeps the cookie.
        $params = session_get_cookie_params();
        unset($params["lifetime"]);
        setcookie(session_name(), "", ["expires" => time() - 42000] + $params);
        session_destroy();
        session_start();

        // Several tabs can notice the same session ending, so the log entry is
        // deduplicated (the teardown above is safe to repeat).
        //
        // "session_revoked" is not logged here: sessions_revoke.php already
        // wrote session.revoked when it happened. The other reasons share the
        // "logout" event, with the cause as its reason (activityItemReason()
        // in dashboard.js); SessionRegistry::pruneExpired() also writes
        // idle_timeout entries.
        if (
            $wasAuthenticated &&
            $reason !== "session_revoked" &&
            !recent_duplicate_activity($this->db, "logout", $ip, 5)
        ) {
            log_activity($this->db, "logout", null, null, [
                "reason" => $reason,
                "ip" => $ip,
            ]);
        }
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Decrypt the TOTP secret, verify the code, and enforce replay protection:
     * the matched step is stored so the same code cannot be accepted twice
     * within the ±1-step window.
     */
    private function verifyTotpWithKey(
        array $row,
        string $masterKey,
        string $code
    ): bool {
        // The global login.totp bucket, serialised across every client. The
        // password is proven by now, so only its holder can queue here.
        $this->security?->serializeAttempts("login.totp");

        try {
            $secret = $this->crypto->decryptMeta(
                $row["totp_secret_enc"],
                $row["totp_secret_iv"],
                $row["totp_secret_tag"],
                $masterKey,
                CryptoService::AAD_TOTP
            );
        } catch (RuntimeException) {
            return false;
        }

        $matchedStep = $this->crypto->verifyTotp($secret, $code);
        sodium_memzero($secret);

        if ($matchedStep === false) {
            return false;
        }

        // A step is spent once, and never one older than the last spent. One
        // conditional UPDATE, not read-then-write, so two requests racing with
        // the same code cannot both pass. Two placeholders for one value:
        // native prepares do not allow a name to repeat. updated_at is pinned
        // for the same reason as in login().
        $stmt = $this->db->prepare(
            "UPDATE " .
                DB_PREFIX .
                "auth SET totp_last_used_step = :step, updated_at = updated_at
                  WHERE id = 1
                    AND (totp_last_used_step IS NULL OR totp_last_used_step < :step_cmp)"
        );
        $stmt->execute([":step" => $matchedStep, ":step_cmp" => $matchedStep]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Count a wrong TOTP code: in the per-client login.failure bucket through
     * the log entry, and in the global login.totp bucket, which a guess spread
     * over many addresses cannot dodge (see RATE_LIMIT_GLOBAL_BUCKETS).
     */
    private function recordTotpFailure(): void
    {
        log_activity($this->db, "login.failure", null, null, [
            "reason" => "bad_totp",
        ]);
        rate_limit_record($this->db, "login.totp");
    }

    /**
     * Establish a full authenticated session, encrypting MASTER_KEY with the
     * browser-supplied session key before writing it to $_SESSION.
     *
     * @param string $masterKey  Raw 32-byte MASTER_KEY (will be zeroed by caller)
     * @param string $sessionKey Raw 32-byte key from browser sessionStorage, as
     *                           resolveSessionKey() returns it (length checked)
     */
    private function sessionFull(string $masterKey, string $sessionKey): void
    {
        // The session file gets only ciphertext; see the file header.
        $wrapped = $this->crypto->encryptMeta($masterKey, $sessionKey, CryptoService::AAD_SESSION_KEY);
        // Wipes the temporary sessionFullFromPasskey() passes. sodium_memzero()
        // clears a string only when nothing else shares it, so a caller that
        // holds its own copy wipes that one too.
        sodium_memzero($sessionKey);

        $this->regenerateSession([
            "auth_ok"         => true,
            "master_key_enc"  => $wrapped["enc"],
            "master_key_iv"   => $wrapped["iv"],
            "master_key_tag"  => $wrapped["tag"],
            "last_activity"   => time(),
        ]);

        // From here on requireAuth() requires this row to exist.
        $this->sessions->register(session_id(), request_ip(), request_ua());
    }

    /**
     * Extract and validate the session key from the X-Session-Key header, or
     * from 'session_key' in the JSON body (the login request, which sends the
     * key alongside the credentials).
     */
    private function resolveSessionKey(?array $body = null): string
    {
        $b64 = $_SERVER["HTTP_X_SESSION_KEY"] ?? null;

        if (!$b64 && $body !== null) {
            $b64 = $body["session_key"] ?? null;
        }

        if (!is_string($b64) || $b64 === "") {
            throw new SessionKeyException("Missing session key.");
        }
        $key = base64_decode($b64, true);
        if ($key === false || strlen($key) !== 32) {
            throw new SessionKeyException("Invalid session key.");
        }
        return $key;
    }

    /** Full session after a passkey login, which replaces password and TOTP. */
    public function sessionFullFromPasskey(string $masterKey): void
    {
        $this->sessionFull($masterKey, $this->resolveSessionKey());
    }

    private function sessionPartial(): void
    {
        $this->regenerateSession([
            "auth_ok" => false,
            "totp_pending" => true,
        ]);
    }

    /**
     * After a key mismatch: a fresh unauthenticated session, so nothing of
     * the attempt (a pending TOTP step) survives it. The client shows the
     * mismatch banner from the response.
     */
    private function sessionBlocked(): void
    {
        $this->regenerateSession(["auth_ok" => false]);
    }

    /**
     * A new session id holding $state (plus the client address), for every
     * login-state change; the old id's registry row goes too. A pre-login state
     * keeps the page's CSRF token, which passkey_challenge.php needs after a
     * failed step; a full session gets a fresh one with the dashboard.
     */
    private function regenerateSession(array $state): void
    {
        $this->sessions->remove(session_id());
        $csrf = $_SESSION[Csrf::SESSION_KEY] ?? null;
        session_regenerate_id(true);
        $_SESSION = $state + ["ip" => request_ip()];
        if (empty($state["auth_ok"]) && is_string($csrf)) {
            $_SESSION[Csrf::SESSION_KEY] = $csrf;
        }
    }

    private function getRow(): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM " . DB_PREFIX . "auth WHERE id = 1 LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            json_response(
                ["error" => __("err_not_initialised_setup")],
                500
            );
        }
        return $row;
    }
}
