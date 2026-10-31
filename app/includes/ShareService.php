<?php

declare(strict_types=1);

namespace AESBin;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

defined("AESBIN_APP") || exit;

/**
 * app/includes/ShareService.php — AESBin share system
 *
 * Each shared file's DEK and metadata are re-encrypted under a shareKey and
 * stored in share_file_keys. The visitor flow never uses MASTER_KEY.
 *
 * URL model: /s/TOKEN#KEY. TOKEN (16 hex) is public and identifies the
 * share in the DB and logs. KEY (32 hex) is the secret. As a fragment it
 * stays out of access logs and Referer (not out of browser history), but it
 * is sent once over HTTPS in the /api/share/auth body, since decryption is
 * server-side.
 *
 * shareKey = keyed BLAKE2b(KEY, salt); see CryptoService::deriveShareKey()
 * for why not Argon2id. The same derivation covers the DEK and all share
 * metadata.
 */

class ShareService
{
    public const SESSION_TTL = 3600;

    private PDO $db;
    private CryptoService $crypto;

    public function __construct(PDO $db, CryptoService $crypto)
    {
        $this->db = $db;
        $this->crypto = $crypto;
    }

    /**
     * A share password from a request body: null when absent or null, ""
     * when empty (update.php: remove it), otherwise the string as typed.
     * Whitespace-only is refused: it could be set but never typed back
     * convincingly, and visitors would face a link nobody can open.
     */
    public static function passwordFromBody(array &$body): ?string
    {
        if (!isset($body["password"])) {
            return null;
        }
        normalize_body_field($body, "password");
        $password = (string) $body["password"];
        if ($password !== "" && trim($password) === "") {
            json_response(["error" => __("err_field_invalid", ["field" => "password"])], 400);
        }
        return $password;
    }

    /** Longest expiry a link accepts: 10 years, far past the UI's 30 days. */
    private const MAX_EXPIRES_IN = 315360000;

    /**
     * The expires_at for a request body's "expires_in" (seconds from now,
     * 0 = never): a UTC DATETIME string, or null for a permanent link.
     * Bounded so a huge value is a 400, not a year DATETIME cannot store.
     */
    public static function expiryFromBody(array $body): ?string
    {
        // body_int(), not a cast: "7d" or "abc" must be a 400, not 7 seconds
        // or a permanent link. null (JSON null) means permanent, as absent.
        $raw = $body["expires_in"] ?? 0;
        $seconds = body_int($raw);
        if ($seconds === null || $seconds < 0 || $seconds > self::MAX_EXPIRES_IN) {
            json_response(["error" => __("err_share_expires_invalid")], 400);
        }
        return $seconds > 0 ? gmdate("Y-m-d H:i:s", time() + $seconds) : null;
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function createFileShare(
        int $fileId,
        string $masterKey,
        ?string $password = null,
        ?string $expiresAt = null
    ): array {
        $file = $this->fetchFile($fileId);
        $token = $this->crypto->generateToken(8);
        $key = $this->crypto->generateToken(16);

        // One transaction: a share row whose file key failed to store could
        // never prove its KEY, so it would be a dead link that still lists.
        // No labels: a file share's visitor sees the file's own name, which
        // syncFileMetadata() keeps current; a sealed copy here
        // would keep serving the name it had when the link was made.
        $shareId = $this->inTransaction(function () use ($token, $fileId, $key, $masterKey, $password, $expiresAt, $file): int {
            $shareId = $this->insertShare(
                $token,
                "file",
                $fileId,
                $key,
                $masterKey,
                $password,
                $expiresAt
            );
            $this->storeFileKey($file, $masterKey, $shareId, $key);
            return $shareId;
        });

        log_activity($this->db, "share.create", "file", $fileId, [
            "share_id" => $shareId,
            // Whether, never which: the password itself is never logged.
            "password" => $password !== null && $password !== "",
            "expires_at" => $expiresAt,
        ]);
        return $this->buildResult($shareId, $token, $key, $expiresAt);
    }

    public function createDirectoryShare(
        int $directoryId,
        string $masterKey,
        ?string $password = null,
        ?string $expiresAt = null
    ): array {
        [$dirName, $dirDesc] = $this->directoryLabels($directoryId, $masterKey);
        $files = $this->directoryFiles($directoryId);

        $token = $this->crypto->generateToken(8);
        $key = $this->crypto->generateToken(16);

        // One transaction, as in createFileShare(). A file whose DEK will not
        // unwrap is skipped, as in retarget(); anything else aborts.
        $count = 0;
        $shareId = $this->inTransaction(function () use ($token, $directoryId, $key, $masterKey, $password, $expiresAt, $dirName, $dirDesc, $files, &$count): int {
            $shareId = $this->insertShare(
                $token,
                "directory",
                $directoryId,
                $key,
                $masterKey,
                $password,
                $expiresAt,
                $dirName,
                $dirDesc
            );
            [$count] = $this->storeFileKeys($files, $masterKey, $shareId, $key, "create");
            return $shareId;
        });

        log_activity($this->db, "share.create", "directory", $directoryId, [
            "share_id" => $shareId,
            "files" => $count,
            "password" => $password !== null && $password !== "",
            "expires_at" => $expiresAt,
        ]);
        $result = $this->buildResult($shareId, $token, $key, $expiresAt);
        $result["file_count"] = $count;
        return $result;
    }

    /**
     * Run $work in a transaction and return its result; roll back and rethrow
     * on any failure.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function inTransaction(callable $work): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            db_rollback_if_open($this->db);
            throw $e;
        }
    }

    // ── Visitor authentication ────────────────────────────────────────────────

    /**
     * Rate-limit bucket for failed attempts on ONE share. Per share because a
     * success clears it: with a global bucket, visits to any password-less link
     * would reset the count between guesses on another. Not a log event, so
     * rejectVisitor() records it itself.
     */
    public static function failureBucket(int $shareId): string
    {
        return "share.failure#" . $shareId;
    }

    /**
     * Verify a share's KEY (and password, if set) and issue a visitor session
     * token. Takes no IP or user agent: nothing identifying a visitor is stored
     * (see VISITOR_EVENTS); the IP only feeds the rate limiter. A success logs
     * the referrer, a failure also the page URL (visitor_failure_context());
     * a referrer naming this instance is dropped.
     */
    public function authenticate(
        string $token,
        string $key,
        string $password = "",
        bool $refresh = false,
        ?SecurityService $security = null,
        string $pageReferrer = "",
        string $pageUrl = ""
    ): array {
        // Opportunistic cleanup of expired visitor sessions; no cron needed.
        share_sessions_delete($this->db, "expires_at < NOW()");

        $share = $this->fetchByToken($token, $pageReferrer, $pageUrl);
        $shareId = (int) $share["id"];

        // Per-share lockout lives here, not in the action, because the bucket
        // name needs the resolved token. The action applies the coarse
        // share.token_probe limit first, which bounds unknown tokens.
        $security?->enforceRateLimit(self::failureBucket($shareId));

        // Step 0: shape check before any crypto. A real KEY is always
        // generateToken(16), 32 lowercase hex. A malformed one gets the same
        // response as a wrong one, so "malformed" vs "incorrect" is no oracle.
        if (preg_match('/^[a-f0-9]{32}\z/', $key) !== 1) {
            $this->rejectVisitor($shareId, "key", $pageReferrer, $pageUrl);
        }

        // Step 1: prove the KEY by unwrapping one file DEK. storage_name is
        // the AAD storeFileKey() sealed it under.
        $stmt = $this->db->prepare(
            "SELECT sfk.*, f.storage_name FROM " . DB_PREFIX . "share_file_keys sfk" .
                " JOIN " . DB_PREFIX . "files f ON f.id = sfk.file_id" .
                " WHERE sfk.share_id = :sid LIMIT 1"
        );
        $stmt->execute([":sid" => $shareId]);
        $sfk = $stmt->fetch();

        // Wiped in finally, before rejectVisitor(), which exits: a finally
        // block does not run on exit().
        $shareKey = null;
        $keyProven = false;
        try {
            if ($sfk) {
                $shareKey = $this->crypto->deriveShareKey($key, self::salt($sfk["dek_salt"]));
                $dek = $this->crypto->unwrapDek(
                    $sfk["dek_enc"],
                    $sfk["dek_enc_iv"],
                    $sfk["dek_enc_tag"],
                    $shareKey,
                    CryptoService::dekAad($sfk["storage_name"])
                );
                sodium_memzero($dek);
            } elseif ($share["type"] === "directory" && !empty($share["target_name_enc"])) {
                // Empty directory share: the GCM-authenticated directory
                // name proves the KEY instead.
                $shareKey = $this->crypto->deriveShareKey($key, self::salt($share["target_name_salt"]));
                $this->crypto->decryptMeta(
                    $share["target_name_enc"],
                    $share["target_name_iv"],
                    $share["target_name_tag"],
                    $shareKey,
                    CryptoService::shareLabelAad("target_name", (string) $share["token"])
                );
            } else {
                // Nothing can prove the KEY, so nothing is served.
                throw new RuntimeException("Share has nothing to verify the key against.");
            }
            $keyProven = true;
        } catch (RuntimeException) {
            // Handled below, once the key is wiped.
        } finally {
            if ($shareKey !== null) {
                sodium_memzero($shareKey);
            }
        }
        if (!$keyProven) {
            $this->rejectVisitor($shareId, "key", $pageReferrer, $pageUrl);
        }

        // A visit to this link already open in this browser (its cookie):
        // the password was checked when it opened, so the right key goes on
        // with it, as a reload does, without a new opening. A link opened
        // without its #KEY reaches this point. Not for share.js's renewal,
        // which comes when that visit is ending.
        $openVisit = $refresh ? "" : share_session_cookie_value($token);
        if ($openVisit !== "" && ($open = $this->findSession($token, $openVisit)) !== null) {
            sodium_memzero($open["key"]);
            $expiresTs = time() + (int) $open["share"]["session_left"];
            return [
                "session_token" => $openVisit,
                "expires_at" => gmdate("Y-m-d H:i:s", $expiresTs),
                "expires_ts" => $expiresTs,
            ];
        }

        // Step 2: optional password, only after the KEY is verified.
        if ($share["password_hash"] !== null) {
            // An empty password is the initial "prompt me" round-trip. It skips
            // password_verify() so a KEY holder cannot burn Argon2id CPU outside
            // the limiter. A 200, not an error: asking for the password is the
            // normal way in, and an error status would show in the visitor's
            // console. A wrong one is a 401 (rejectVisitor()). A real share
            // password is never "" (an empty one is stored as NULL).
            if ($password === "") {
                json_response(["password_required" => true]);
            }
            // Per share and client, around the Argon2id work below.
            $security?->serializeAttempts(self::failureBucket($shareId));
            if (!password_verify($password, $share["password_hash"])) {
                $this->rejectVisitor($shareId, "password", $pageReferrer, $pageUrl);
            }
        }

        // Step 3: issue the visitor session token.
        $rawToken = $this->crypto->generateToken(32);
        $tokenHash = $this->crypto->hashToken($rawToken);
        $encryptedKey = $this->encryptKeyForSession(
            $key,
            $rawToken,
            (int) $share["id"]
        );
        $expiresTs = time() + self::SESSION_TTL;
        $expires = gmdate("Y-m-d H:i:s", $expiresTs);

        // Only while the password is still the one checked above: a password
        // the owner sets meanwhile ends every visitor session
        // (updateSettings()), and a session inserted after that sweep would
        // have skipped it.
        $stmt = $this->db->prepare(
            "INSERT INTO " . DB_PREFIX . "share_sessions
                (share_id, access_token_hash, share_key_enc, expires_at)
             SELECT id, :hash, :ske, :exp FROM " . DB_PREFIX . "shares
              WHERE id = :sid AND password_hash <=> :ph"
        );
        $stmt->execute([
            ":sid" => $share["id"],
            ":hash" => $tokenHash,
            ":ske" => $encryptedKey,
            ":exp" => $expires,
            ":ph" => $share["password_hash"],
        ]);
        if ($stmt->rowCount() !== 1) {
            json_response(["error" => __("share_enter_password"), "code" => "password_required"], 401);
        }

        // Proven access clears THIS share's failure bucket only. Done
        // directly rather than via the log write, and not gated on $refresh,
        // which runs the same proof.
        rate_limit_clear($this->db, [self::failureBucket($shareId)]);

        // One share.visit item per call, refreshes included ($refresh comes from
        // the body, so gating on it would allow untraced reads). It targets the
        // share, so the history survives a retarget. visit_count counts openings,
        // not people, and is bumped first so the item carries this opening's
        // ordinal ("n").
        $openingNumber = null;
        if (!$refresh) {
            // LAST_INSERT_ID(expr) is per-connection, so a concurrent visit
            // cannot hand us its number, as UPDATE-then-SELECT could.
            try {
                $this->db
                    ->prepare(
                        "UPDATE " . DB_PREFIX . "shares
                            SET visit_count = LAST_INSERT_ID(visit_count + 1)
                          WHERE id = :id"
                    )
                    ->execute([":id" => $share["id"]]);
                $openingNumber = (int) $this->db->query("SELECT LAST_INSERT_ID()")->fetchColumn();
                share_total_bump($this->db, "visits");
            } catch (Throwable) {
                // Never block the response; the item goes without a number.
            }
        }

        log_activity($this->db, "share.visit", "share", (int) $share["id"], [
            "token" => substr($token, 0, 4),
        ] + ($openingNumber !== null ? ["n" => $openingNumber] : [])
          + (($ref = visitor_referrer($pageReferrer)) !== "" ? ["referer" => $ref] : []));

        return [
            "session_token" => $rawToken,
            "expires_at" => $expires,
            "expires_ts" => $expiresTs,
        ];
    }

    // ── Session validation ────────────────────────────────────────────────────

    public function requireValidSession(
        string $shareToken,
        string $rawSessionToken
    ): array {
        return $this->findSession($shareToken, $rawSessionToken) ??
            json_response(["error" => __("share_invalid"), "code" => "share_invalid"], 403);
    }

    /**
     * The share and the visitor's KEY for a session of THIS link that is
     * still open, or null: a session of another link, an ended one (owner
     * changed the password, revoked, expired) or a forged token.
     *
     * @return ?array{share: array, key: string}
     */
    public function findSession(
        string $shareToken,
        string $rawSessionToken
    ): ?array {
        $hash = $this->crypto->hashToken($rawSessionToken);
        $stmt = $this->db->prepare(
            'SELECT s.*, ss.share_key_enc, ss.id AS session_id,
                    TIMESTAMPDIFF(SECOND, NOW(), ss.expires_at) AS session_left
               FROM ' .
                DB_PREFIX .
                'shares s
               JOIN ' .
                DB_PREFIX .
                'share_sessions ss ON ss.share_id = s.id
              WHERE s.token = :stok AND ss.access_token_hash = :hash
                AND ss.expires_at > NOW()
                AND (s.expires_at IS NULL OR s.expires_at > NOW())
              LIMIT 1'
        );
        $stmt->execute([":stok" => $shareToken, ":hash" => $hash]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $key = $this->decryptKeyFromSession(
            $row["share_key_enc"],
            $rawSessionToken,
            (int) $row["id"]
        );
        return ["share" => $row, "key" => $key];
    }

    // ── List shared files (for visitor) ───────────────────────────────────────

    /**
     * The file list for a share visitor, decrypted with the KEY from the
     * visitor's session.
     *
     * Only files already in share_file_keys are listed. Wrapping needs
     * MASTER_KEY, so new files in a shared directory are wrapped on the
     * owner's side: autoRefreshForDirectory() (upload, move, restore) and
     * share/refresh.php. Null without an open session of this link.
     */
    public function listSharedFiles(
        string $shareToken,
        string $rawSessionToken
    ): ?array {
        $result = $this->findSession($shareToken, $rawSessionToken);
        if ($result === null) {
            return null;
        }
        $visitorKey = $result["key"];
        $share = $result["share"];

        $stmt = $this->db->prepare(
            'SELECT sfk.*, f.size, f.created_at, f.storage_name
               FROM ' .
                DB_PREFIX .
                'share_file_keys sfk
               JOIN ' .
                DB_PREFIX .
                'files f ON f.id = sfk.file_id
              WHERE sfk.share_id = :sid AND f.trashed_at IS NULL
              ORDER BY f.created_at ASC, f.id ASC'
        );
        $stmt->execute([":sid" => $share["id"]]);
        $rows = $stmt->fetchAll();
        $files = [];

        foreach ($rows as $sfk) {
            // One unreadable row skips that file, never the list.
            try {
                $shareKey = $this->crypto->deriveShareKey($visitorKey, self::salt($sfk["dek_salt"]));
            } catch (RuntimeException) {
                continue;
            }

            $name = $this->crypto->decryptColumn($sfk, "name", $shareKey, CryptoService::fileMetaAad("name", $sfk["storage_name"] ?? ""), __("name_unreadable"));
            $mime = $this->crypto->decryptColumn($sfk, "mime", $shareKey, CryptoService::fileMetaAad("mime", $sfk["storage_name"] ?? ""), "application/octet-stream");
            $desc = "";
            if (!empty($sfk["desc_enc"])) {
                $desc = $this->crypto->decryptColumn($sfk, "desc", $shareKey, CryptoService::fileMetaAad("desc", $sfk["storage_name"] ?? ""));
            }
            $hashes = null;
            if (!empty($sfk["hashes_enc"])) {
                $decoded = json_decode(
                    $this->crypto->decryptColumn($sfk, "hashes", $shareKey, CryptoService::fileMetaAad("hashes", $sfk["storage_name"] ?? "")),
                    true
                );
                $hashes = is_array($decoded) ? $decoded : null;
            }
            sodium_memzero($shareKey);

            $files[] = [
                "file_token" => $sfk["file_token"],
                "original_name" => $name,
                "mime" => $mime,
                "description" => $desc,
                "hashes" => $hashes,
                "size" => (int) $sfk["size"],
                "size_human" => format_bytes((int) $sfk["size"]),
                "created_at" => $sfk["created_at"],
            ];
        }

        $isDir = $share["type"] === "directory";
        return [
            "files" => $files,
            // Seconds the visitor session has left: share.js renews it just
            // before, instead of meeting the 401 of a cookie gone with its hour.
            "session_expires_in" => max(0, (int) $share["session_left"]),
            "meta" => [
                "type" => $share["type"],
                // A password link offers the visitor an "Exit" (visitor_cookie_forget()).
                "password" => $share["password_hash"] !== null,
                // Directory shares only (see createFileShare()).
                "target_name" => $isDir ? $this->openLabel($share, "target_name", $visitorKey) : "",
                "target_desc" => $isDir ? $this->openLabel($share, "target_desc", $visitorKey) : "",
            ],
        ];
    }

    /**
     * Owner side: wrap files added to a shared directory since the last
     * refresh, and drop key rows for files no longer in it. Returns how many
     * files were newly wrapped.
     */
    public function refreshDirectoryShare(int $shareId, string $masterKey): int
    {
        // A transaction holding the share row: two refreshes of one share
        // would otherwise insert the same key row twice (uq_sfk), and one
        // racing retarget() could re-wrap the old target's files under the
        // new one.
        return $this->inTransaction(function () use ($shareId, $masterKey): int {
            $stmt = $this->db->prepare(
                "SELECT * FROM " . DB_PREFIX . "shares WHERE id = :id AND type = 'directory' FOR UPDATE"
            );
            $stmt->execute([":id" => $shareId]);
            $share = $stmt->fetch();
            if (!$share) {
                return 0;
            }

            $allFiles = $this->directoryFiles((int) $share["target_id"]);

            $stmt = $this->db->prepare(
                "SELECT file_id FROM " . DB_PREFIX . "share_file_keys WHERE share_id = :sid"
            );
            $stmt->execute([":sid" => $shareId]);
            $wrapped = array_flip(array_column($stmt->fetchAll(), "file_id"));

            // Prune key rows for files no longer in this directory (moved
            // out). Before the unwrap below, which pruning does not need.
            $currentIds = array_flip(array_column($allFiles, "id"));
            $del = $this->db->prepare(
                "DELETE FROM " . DB_PREFIX . "share_file_keys WHERE share_id = :sid AND file_id = :fid"
            );
            foreach (array_keys($wrapped) as $wrappedFileId) {
                if (!isset($currentIds[$wrappedFileId])) {
                    $del->execute([":sid" => $shareId, ":fid" => $wrappedFileId]);
                }
            }

            try {
                $key = $this->unwrapKey($share, $masterKey);
            } catch (RuntimeException) {
                return 0;
            }

            try {
                [$added] = $this->storeFileKeys(
                    array_filter($allFiles, fn($f) => !isset($wrapped[$f["id"]])),
                    $masterKey,
                    $shareId,
                    $key,
                    "refresh"
                );
            } finally {
                sodium_memzero($key);
            }
            return $added;
        });
    }

    /**
     * Refresh every active directory share of $dirId. Called after upload,
     * move and restore so visitors see new files without the owner opening
     * the Shares view. Never fails the file operation that called it: an
     * error is logged, and the share catches up at its next refresh.
     */
    public function autoRefreshForDirectory(int $dirId, string $masterKey): void
    {
        if ($dirId <= 0) {
            return;
        }
        try {
            $stmt = $this->db->prepare(
                "SELECT id FROM " .
                    DB_PREFIX .
                    'shares
                  WHERE type      = \'directory\'
                    AND target_id = :did
                    AND (expires_at IS NULL OR expires_at > NOW())'
            );
            $stmt->execute([":did" => $dirId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $shareId) {
                try {
                    $this->refreshDirectoryShare((int) $shareId, $masterKey);
                } catch (Throwable $e) {
                    error_log("[AESBin] share auto-refresh: share " . (int) $shareId . ": " . get_class($e) . ": " . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log("[AESBin] share auto-refresh: directory {$dirId}: " . get_class($e) . ": " . $e->getMessage());
        }
    }

    /**
     * A file left out of a share because its key or metadata would not
     * unwrap. The share still works for the rest; the log says which one.
     */
    private function logSkippedFile(string $operation, array $file, int $shareId, Throwable $e): void
    {
        error_log(
            "[AESBin] share {$operation}: skipped file " . (int) ($file["id"] ?? 0) .
                " on share {$shareId}: " . $e->getMessage()
        );
    }

    // ── Owner: list shares ────────────────────────────────────────────────────

    /**
     * Destroy every expired share, as fetchByToken() does per token, so
     * links nobody visits again do not keep their keys. Run by the owner's
     * share list and activity log, which then show the result.
     */
    public function sweepExpired(): void
    {
        $expStmt = $this->db->prepare(
            "SELECT id, type, target_id, visit_count FROM " .
                DB_PREFIX .
                "shares WHERE expires_at IS NOT NULL AND expires_at < NOW()"
        );
        $expStmt->execute();
        foreach ($expStmt->fetchAll() as $expired) {
            $this->destroyShare((int) $expired["id"], $expired, "share.expire");
        }
    }

    public function listOwnerShares(string $masterKey): array
    {
        // Best-effort: the SELECT below still filters on expires_at in case a
        // destroy fails.
        $this->sweepExpired();

        $stmt = $this->db->prepare(
            'SELECT s.id, s.token, s.type, s.target_id,
                    s.key_enc, s.key_enc_iv, s.key_enc_tag,
                    (s.password_hash IS NOT NULL) AS password_protected,
                    s.expires_at, s.created_at, s.visit_count,
                    (SELECT COUNT(*) FROM ' . DB_PREFIX . 'share_file_keys sfk
                      WHERE sfk.share_id = s.id) AS file_count,
                    f.name_enc AS file_name_enc, f.name_iv AS file_name_iv, f.name_tag AS file_name_tag,
                    f.storage_name AS file_storage_name,
                    f.container_id AS file_container_id, f.directory_id AS file_directory_id,
                    d.name_enc AS dir_name_enc,  d.name_iv AS dir_name_iv,  d.name_tag AS dir_name_tag,
                    d.container_id AS dir_container_id
               FROM ' .
                DB_PREFIX .
                'shares s
               LEFT JOIN ' .
                DB_PREFIX .
                'files f
                      ON (s.type = \'file\' AND f.id = s.target_id)
               LEFT JOIN ' .
                DB_PREFIX .
                'directories d
                      ON (s.type = \'directory\' AND d.id = s.target_id)
              WHERE s.expires_at IS NULL OR s.expires_at > NOW()
              ORDER BY s.created_at DESC, s.id DESC'
        );
        $stmt->execute();
        $shares = $stmt->fetchAll();

        foreach ($shares as &$s) {
            $displayName = __("name_unreadable");
            if (!empty($s["file_name_enc"])) {
                $displayName = $this->crypto->decryptColumn($s, "file_name", $masterKey, CryptoService::fileMetaAad("name", $s["file_storage_name"] ?? ""), $displayName);
            } elseif (!empty($s["dir_name_enc"])) {
                $displayName = $this->crypto->decryptColumn($s, "dir_name", $masterKey, CryptoService::rowAad("directory", $s["target_id"] ?? ""), $displayName);
            }
            $s["display_name"] = $displayName;
            $s["visit_count"] = (int) $s["visit_count"];
            $s["file_count"] = (int) $s["file_count"];

            // Where the client navigates to find the share's source.
            $s["nav_container_id"] =
                (int) ($s["type"] === "file"
                    ? $s["file_container_id"] ?? 0
                    : $s["dir_container_id"] ?? 0);
            $s["nav_directory_id"] =
                $s["type"] === "file"
                    ? ($s["file_directory_id"] !== null
                        ? (int) $s["file_directory_id"]
                        : null)
                    : (int) $s["target_id"];

            unset(
                $s["file_name_enc"],
                $s["file_name_iv"],
                $s["file_name_tag"],
                $s["file_storage_name"],
                $s["dir_name_enc"],
                $s["dir_name_iv"],
                $s["dir_name_tag"],
                $s["file_container_id"],
                $s["file_directory_id"],
                $s["dir_container_id"]
            );

            try {
                $key = $this->unwrapKey($s, $masterKey);
                $s["share_url"] = base_url("s/" . $s["token"]) . "#" . $key;
                sodium_memzero($key);
            } catch (RuntimeException) {
                $s["share_url"] = base_url("s/" . $s["token"]);
            }
            unset($s["key_enc"], $s["key_enc_iv"], $s["key_enc_tag"]);
        }
        unset($s);

        return $shares;
    }

    // ── Revoke ────────────────────────────────────────────────────────────────

    public function revoke(int $shareId): void
    {
        $stmt = $this->db->prepare(
            "SELECT type, target_id, visit_count FROM " .
                DB_PREFIX .
                "shares WHERE id = :id LIMIT 1"
        );
        $stmt->execute([":id" => $shareId]);
        $share = $stmt->fetch();
        if (!$share) {
            json_response(["error" => __("share_not_found")], 404);
        }

        if (!$this->destroyShare($shareId, $share, "share.revoke")) {
            json_response(["error" => __("server_error")], 500);
        }
    }

    /**
     * Delete a share with its sessions and file keys: for revoke() and for the
     * expiry sweep wherever a loaded row turns out expired; only the log reason
     * differs. $share needs type, target_id and visit_count. Returns false
     * instead of responding, since the sweep must not fail a visitor's request;
     * only revoke() turns false into a 500.
     */
    private function destroyShare(
        int $shareId,
        array $share,
        string $activityAction
    ): bool {
        // One transaction, so an interruption cannot leave a shares row that
        // still resolves by token but has no file keys.
        try {
            $this->db->beginTransaction();
            share_delete($this->db, $shareId);
            $this->db->commit();
        } catch (Throwable $e) {
            db_rollback_if_open($this->db);
            error_log(
                "[AESBin] " .
                    $activityAction .
                    ": aborted for share " .
                    $shareId .
                    ": " .
                    get_class($e) .
                    ": " .
                    $e->getMessage()
            );
            return false;
        }

        log_activity($this->db, $activityAction, $share["type"], (int) $share["target_id"], [
            "share_id" => $shareId,
            // The row is gone, so this is the only record of the count left.
            "visit_count" => (int) ($share["visit_count"] ?? 0),
        ]);
        return true;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * storeFileKey() for each of $files. A file whose key or metadata will
     * not unwrap is skipped and logged under $operation, not a failure; a
     * database error (PDOException, itself a RuntimeException) is rethrown,
     * so the caller's transaction aborts instead of silently dropping files.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array{0: int, 1: int} [stored, skipped]
     */
    private function storeFileKeys(
        array $files,
        string $masterKey,
        int $shareId,
        string $key,
        string $operation
    ): array {
        $stored = 0;
        $skipped = 0;
        foreach ($files as $file) {
            try {
                $this->storeFileKey($file, $masterKey, $shareId, $key);
                $stored++;
            } catch (PDOException $e) {
                throw $e;
            } catch (RuntimeException $e) {
                $skipped++;
                $this->logSkippedFile($operation, $file, $shareId, $e);
            }
        }
        return [$stored, $skipped];
    }

    /**
     * Wrap one file's DEK and metadata for a share, under
     * shareKey = deriveShareKey(KEY, dek_salt).
     */
    private function storeFileKey(
        array $file,
        string $masterKey,
        int $shareId,
        string $key
    ): void {
        $sn = (string) ($file["storage_name"] ?? "");
        $dek = $this->crypto->unwrapDek(
            $file["enc_key"],
            $file["enc_key_iv"],
            $file["enc_key_tag"],
            $masterKey,
            CryptoService::dekAad($sn)
        );

        $dkSalt = $this->crypto->generateSalt();
        $shareKey = $this->crypto->deriveShareKey($key, $dkSalt);

        $wrapped = $this->crypto->wrapDek($dek, $shareKey, CryptoService::dekAad($sn));
        sodium_memzero($dek);

        $plainName = $this->crypto->decryptColumn($file, "name", $masterKey, CryptoService::fileMetaAad("name", $sn), "file");
        $plainMime = $this->crypto->decryptColumn($file, "mime", $masterKey, CryptoService::fileMetaAad("mime", $sn), "application/octet-stream");
        $plainDesc = "";
        if (!empty($file["desc_enc"])) {
            $plainDesc = $this->crypto->decryptColumn($file, "desc", $masterKey, CryptoService::fileMetaAad("desc", $sn));
        }
        // Without hashes_enc the share simply shows no checksums.
        $plainHashes = "";
        if (!empty($file["hashes_enc"])) {
            $plainHashes = $this->crypto->decryptColumn($file, "hashes", $masterKey, CryptoService::fileMetaAad("hashes", $sn));
        }

        $encName = $this->crypto->encryptMeta($plainName, $shareKey, CryptoService::fileMetaAad("name", $sn));
        $encMime = $this->crypto->encryptMeta($plainMime, $shareKey, CryptoService::fileMetaAad("mime", $sn));
        $encDesc =
            $plainDesc !== ""
                ? $this->crypto->encryptMeta($plainDesc, $shareKey, CryptoService::fileMetaAad("desc", $sn))
                : null;
        $encHashes =
            $plainHashes !== ""
                ? $this->crypto->encryptMeta($plainHashes, $shareKey, CryptoService::fileMetaAad("hashes", $sn))
                : null;

        sodium_memzero($shareKey);

        // Opaque per-share file handle, used in public URLs instead of the
        // file id. 6 hex chars: see the file_token column comment in
        // app/setup/schema-sql.php for the width. Retried on collision
        // (uq_sfk_token).
        $fileToken = "";
        $ck = $this->db->prepare(
            "SELECT 1 FROM " . DB_PREFIX . "share_file_keys WHERE share_id = :s AND file_token = :t LIMIT 1"
        );
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = $this->crypto->generateToken(3);
            $ck->execute([":s" => $shareId, ":t" => $candidate]);
            if (!$ck->fetch()) {
                $fileToken = $candidate;
                break;
            }
        }
        if ($fileToken === "") {
            throw new RuntimeException("Could not generate unique file_token after 10 attempts.");
        }

        $this->db
            ->prepare(
                "INSERT INTO " .
                    DB_PREFIX .
                    'share_file_keys
                (share_id, file_id, file_token, dek_enc, dek_enc_iv, dek_enc_tag, dek_salt,
                 name_enc, name_iv, name_tag,
                 mime_enc, mime_iv, mime_tag,
                 desc_enc, desc_iv, desc_tag,
                 hashes_enc, hashes_iv, hashes_tag)
             VALUES (:sid, :fid, :ftok, :enc, :iv, :tag, :salt,
                     :ne, :niv, :ntag,
                     :me, :miv, :mtag,
                     :de, :div, :dtag,
                     :he, :hiv, :htag)'
            )
            ->execute([
                ":sid" => $shareId,
                ":fid" => $file["id"],
                ":ftok" => $fileToken,
                ":enc" => $wrapped["enc"],
                ":iv" => $wrapped["iv"],
                ":tag" => $wrapped["tag"],
                ":salt" => base64_encode($dkSalt),
                ":ne" => $encName["enc"],
                ":niv" => $encName["iv"],
                ":ntag" => $encName["tag"],
                ":me" => $encMime["enc"],
                ":miv" => $encMime["iv"],
                ":mtag" => $encMime["tag"],
                ":de" => $encDesc ? $encDesc["enc"] : null,
                ":div" => $encDesc ? $encDesc["iv"] : null,
                ":dtag" => $encDesc ? $encDesc["tag"] : null,
                ":he" => $encHashes ? $encHashes["enc"] : null,
                ":hiv" => $encHashes ? $encHashes["iv"] : null,
                ":htag" => $encHashes ? $encHashes["tag"] : null,
            ]);
    }

    /**
     * Re-seal target_name/target_desc in every share of a directory after it
     * is renamed or re-described.
     *
     * $newDesc: null leaves the share's description alone (name-only
     * rename); "" clears it.
     */
    public function syncDirectoryShareMeta(
        int $directoryId,
        string $masterKey,
        string $newName,
        ?string $newDesc = null
    ): void {
        $stmt = $this->db->prepare(
            "SELECT * FROM " . DB_PREFIX . "shares WHERE type = 'directory' AND target_id = :did"
        );
        $stmt->execute([":did" => $directoryId]);

        foreach ($stmt->fetchAll() as $share) {
            try {
                $key = $this->unwrapKey($share, $masterKey);
                $token = (string) $share["token"];
                $sets = [];
                $params = [":id" => (int) $share["id"]];

                // The stored salt is reused when readable; the IV is always fresh.
                if ($newName !== "") {
                    $sets[] = self::LABEL_SET["tn"];
                    $params += self::labelParams("tn", $this->sealLabel($key, $token, "target_name", $newName, $share["target_name_salt"]));
                }
                if ($newDesc !== null) {
                    $sets[] = self::LABEL_SET["td"];
                    $params += self::labelParams("td", $this->sealLabel($key, $token, "target_desc", $newDesc, $share["target_desc_salt"]));
                }
                sodium_memzero($key);

                if ($sets) {
                    $this->db
                        ->prepare("UPDATE " . DB_PREFIX . "shares SET " . implode(", ", $sets) . " WHERE id = :id")
                        ->execute($params);
                }
            } catch (Throwable $e) {
                // One bad share never blocks the rename.
                error_log("[AESBin] syncDirectoryShareMeta: share " . (int) $share["id"] . ": " . $e->getMessage());
            }
        }
    }

    /**
     * Change a share's expiry and/or password without changing its URL.
     * Neither takes part in key derivation (the password is checked after the
     * KEY), so token, key and wrapped DEKs are untouched.
     *
     * $password: null leaves it alone, "" removes it, anything else sets it.
     * $expiresAt: false leaves it alone, null makes it permanent, a datetime
     * string sets it.
     *
     * @return array{expiry_changed: bool, password_changed: bool, sessions_killed: int}
     */
    public function updateSettings(
        int $shareId,
        ?string $password,
        false|null|string $expiresAt
    ): array {
        $share = $this->fetchLiveShare($shareId);

        $sets = [];
        $params = [":id" => $shareId];

        $expiryChanged = false;
        // An unchanged expiry is not a change (and must not log a
        // share.update describing nothing).
        $sameExpiry =
            $expiresAt !== false &&
            (string) $expiresAt === (string) $share["expires_at"];
        if ($expiresAt !== false && !$sameExpiry) {
            $sets[] = "expires_at = :exp";
            $params[":exp"] = $expiresAt;
            $expiryChanged = true;
        }

        $passwordChanged = false;
        $passwordAdded = false;
        // Removing a password the share does not have is no change either.
        if ($password === "" && $share["password_hash"] === null) {
            $password = null;
        }
        if ($password !== null) {
            $sets[] = "password_hash = :pw";
            $params[":pw"] =
                $password === "" ? null : CryptoService::hashPassword($password);
            $passwordChanged = true;
            $passwordAdded = $password !== "";
        }

        if (!$sets) {
            return [
                "expiry_changed" => false,
                "password_changed" => false,
                "sessions_killed" => 0,
            ];
        }

        $this->db
            ->prepare(
                "UPDATE " . DB_PREFIX . "shares SET " . implode(", ", $sets) .
                    " WHERE id = :id"
            )
            ->execute($params);

        // Setting or changing a password ends existing visitor sessions;
        // otherwise anyone already inside would keep access for up to
        // SESSION_TTL. Removing one needs no sweep.
        $killed = 0;
        if ($passwordAdded) {
            $killed = share_sessions_delete($this->db, "share_id = :id", [":id" => $shareId]);
        }

        // The password itself is never logged, only the fact that it changed.
        log_activity(
            $this->db,
            "share.update",
            $share["type"],
            (int) $share["target_id"],
            [
                "share_id" => $shareId,
                "expiry" => $expiryChanged,
                // Old and new expiry (null = never), shown as "from → to".
                "expires_from" => $share["expires_at"],
                "expires_to" => $expiryChanged ? $expiresAt : $share["expires_at"],
                "password" => $passwordChanged
                    ? ($passwordAdded
                        ? ($share["password_hash"] !== null ? "changed" : "set")
                        : "removed")
                    : false,
            ]
        );

        return [
            "expiry_changed" => $expiryChanged,
            "password_changed" => $passwordChanged,
            "sessions_killed" => $killed,
        ];
    }

    /**
     * A share row for an owner edit (updateSettings(), retarget()), or 404.
     * An expired one is swept and answered as not found, never revived: a
     * fresh expiry or target from a stale dashboard would report a link the
     * next sweep destroys.
     */
    private function fetchLiveShare(int $shareId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM " . DB_PREFIX . "shares WHERE id = :id LIMIT 1"
        );
        $stmt->execute([":id" => $shareId]);
        $share = $stmt->fetch();
        if ($share && self::isExpired($share)) {
            $this->destroyShare($shareId, $share, "share.expire");
            $share = false;
        }
        if (!$share) {
            json_response(["error" => __("share_not_found")], 404);
        }
        return $share;
    }

    /**
     * Point a share at a different file or directory, keeping its token,
     * KEY, password and expiry, so the public URL stays the same.
     *
     * The KEY is recovered from key_enc, the old share_file_keys rows are
     * dropped, and the new target's files are wrapped as at creation. Labels
     * are re-sealed with fresh salts under the unchanged token AADs.
     */
    public function retarget(
        int $shareId,
        string $newType,
        int $newTargetId,
        string $masterKey
    ): array {
        if (!in_array($newType, ["file", "directory"], true)) {
            json_response(["error" => __("err_invalid_target_type")], 400);
        }

        $share = $this->fetchLiveShare($shareId);
        if (
            $share["type"] === $newType &&
            (int) $share["target_id"] === $newTargetId
        ) {
            json_response(["error" => __("share_retarget_same")], 409);
        }
        $token = (string) $share["token"];

        try {
            $key = $this->unwrapKey($share, $masterKey);
        } catch (RuntimeException) {
            json_response(["error" => __("decryption_failed")], 500);
        }

        // Files are wrapped here, not left to the dashboard's refresh in
        // loadShares(), so an API-only caller still gets a working share.
        if ($newType === "file") {
            $file = $this->fetchFile($newTargetId); // 404s if missing/trashed
            $files = [$file];
            // No labels on a file share (see createFileShare()).
            $targetName = "";
            $targetDesc = "";
        } else {
            [$targetName, $targetDesc] = $this->directoryLabels($newTargetId, $masterKey);
            $files = $this->directoryFiles($newTargetId);
        }

        $labels = self::labelParams("tn", $this->sealLabel($key, $token, "target_name", $targetName)) +
            self::labelParams("td", $this->sealLabel($key, $token, "target_desc", $targetDesc));

        // One transaction, so an interruption cannot leave the share pointed
        // at its new target with no file keys.
        $count = 0;
        $skipped = 0;
        try {
            $this->db->beginTransaction();

            // Waits out a refreshDirectoryShare() in progress, and makes one
            // that starts now see the new target (see there).
            $this->db
                ->prepare("SELECT id FROM " . DB_PREFIX . "shares WHERE id = :id FOR UPDATE")
                ->execute([":id" => $shareId]);

            $this->db
                ->prepare(
                    "DELETE FROM " . DB_PREFIX . "share_file_keys WHERE share_id = :sid"
                )
                ->execute([":sid" => $shareId]);

            $this->db
                ->prepare(
                    "UPDATE " .
                        DB_PREFIX .
                        "shares SET type = :type, target_id = :tid, " .
                        self::LABEL_SET["tn"] . ", " . self::LABEL_SET["td"] .
                        " WHERE id = :id"
                )
                ->execute([":type" => $newType, ":tid" => $newTargetId, ":id" => $shareId] + $labels);

            // An unwrappable file is skipped and counted, not a failure.
            [$count, $skipped] = $this->storeFileKeys($files, $masterKey, $shareId, $key, "retarget");
            // A file share with no key row cannot prove its KEY: every
            // visitor would be refused. Keep the old target instead.
            if ($newType === "file" && $count === 0) {
                throw new RuntimeException("Target file key does not unwrap.");
            }

            $this->db->commit();
        } catch (Throwable $e) {
            db_rollback_if_open($this->db);
            sodium_memzero($key);
            error_log(
                "[AESBin] retarget: aborted for share " .
                    $shareId . ": " . get_class($e) . ": " . $e->getMessage()
            );
            json_response(["error" => __("server_error")], 500);
        }
        sodium_memzero($key);

        // The previous target's name as prev_name, so the item reads
        // "old → new" like a rename.
        log_activity($this->db, "share.retarget", $newType, $newTargetId, [
            "share_id" => $shareId,
            "files" => $count,
            "skipped" => $skipped,
            "prev_target_type" => $share["type"],
            "prev_target_id" => (int) $share["target_id"],
        ] + activity_name_enc_as($this->db, (string) $share["type"], (int) $share["target_id"], "prev_name"));
        return [
            "share_id" => $shareId,
            "type" => $newType,
            "files" => $count,
            "skipped" => $skipped,
        ];
    }

    // ── Statistics ────────────────────────────────────────────────────────────

    /**
     * True the first time this visitor session previews (or downloads) this
     * file: a reload, a second preview, a download retried count and log once.
     * A new session (another tab, or after SESSION_TTL) counts again. If the
     * row cannot be written the access counts, as it would without the table.
     *
     * @param "download"|"preview" $intent
     */
    public function firstAccess(int $sessionId, string $fileToken, string $intent): bool
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT IGNORE INTO " . DB_PREFIX . "share_session_files (session_id, file_token, intent)
                 VALUES (:sid, :tok, :intent)"
            );
            $stmt->execute([":sid" => $sessionId, ":tok" => $fileToken, ":intent" => $intent]);
            return $stmt->rowCount() === 1;
        } catch (Throwable $e) {
            error_log("[AESBin] share access dedup skipped: " . $e->getMessage());
            return true;
        }
    }

    /**
     * Atomically increment a shared file's download_count or preview_count,
     * after the stream. Returns the new value (this one's ordinal, for the log)
     * or null if no row changed; LAST_INSERT_ID(expr) is per-connection, and
     * rowCount() guards against a stale value.
     *
     * @param "download"|"preview" $intent
     */
    public function recordFileAccess(int $shareId, string $fileToken, string $intent): ?int
    {
        // Column from a fixed pair, never from the request.
        $col = $intent === "preview" ? "preview_count" : "download_count";
        try {
            $stmt = $this->db->prepare(
                "UPDATE " . DB_PREFIX . "files f
                   JOIN " . DB_PREFIX . "share_file_keys sfk ON sfk.file_id = f.id
                    SET f.{$col} = LAST_INSERT_ID(f.{$col} + 1)
                  WHERE sfk.share_id = :sid AND sfk.file_token = :tok"
            );
            $stmt->execute([":sid" => $shareId, ":tok" => $fileToken]);
            if ($stmt->rowCount() === 0) {
                return null;
            }
            // Read first, before any other statement on this connection.
            $n = (int) $this->db->query("SELECT LAST_INSERT_ID()")->fetchColumn();
            share_total_bump($this->db, $intent === "preview" ? "previews" : "downloads");
            return $n;
        } catch (Throwable) {
            return null;
        }
    }

    private function insertShare(
        string $token,
        string $type,
        int $targetId,
        string $key,
        string $masterKey,
        ?string $password,
        ?string $expiresAt,
        string $targetName = "",
        string $targetDesc = ""
    ): int {
        $encryptedKey = $this->crypto->wrapDek($key, $masterKey, CryptoService::shareKeyAad($token));

        $this->db
            ->prepare(
                "INSERT INTO " .
                    DB_PREFIX .
                    'shares
                (token, type, target_id, key_enc, key_enc_iv, key_enc_tag,
                 password_hash, expires_at,
                 target_name_enc, target_name_iv, target_name_tag, target_name_salt,
                 target_desc_enc, target_desc_iv, target_desc_tag, target_desc_salt)
             VALUES (:tok, :type, :tid, :ke, :kei, :ket, :ph, :exp,
                     :tne, :tniv, :tntag, :tnsal,
                     :tde, :tdiv, :tdtag, :tdsal)'
            )
            ->execute([
                ":tok" => $token,
                ":type" => $type,
                ":tid" => $targetId,
                ":ke" => $encryptedKey["enc"],
                ":kei" => $encryptedKey["iv"],
                ":ket" => $encryptedKey["tag"],
                ":ph" =>
                    $password !== null
                        ? CryptoService::hashPassword($password)
                        : null,
                ":exp" => $expiresAt,
            ] + self::labelParams("tn", $this->sealLabel($key, $token, "target_name", $targetName))
                + self::labelParams("td", $this->sealLabel($key, $token, "target_desc", $targetDesc)));
        return (int) $this->db->lastInsertId();
    }

    private function buildResult(
        int $shareId,
        string $token,
        string $key,
        ?string $expiresAt
    ): array {
        return [
            "share_id" => $shareId,
            "token" => $token,
            "share_url" => base_url("s/" . $token) . "#" . $key,
            "expires_at" => $expiresAt,
        ];
    }

    /** The share KEY wrapped for a new visitor session (share_sessions.share_key_enc). */
    private function encryptKeyForSession(
        string $key,
        string $rawSessionToken,
        int $shareId
    ): string {
        $k = $this->crypto->deriveShareSessionKey($rawSessionToken);
        try {
            return $this->crypto->encryptPacked($key, $k, CryptoService::shareSessionAad($shareId));
        } finally {
            sodium_memzero($k);
        }
    }

    /** The share KEY of a visitor session; 403 when it does not unwrap. */
    private function decryptKeyFromSession(
        string $enc,
        string $rawSessionToken,
        int $shareId
    ): string {
        $k = $this->crypto->deriveShareSessionKey($rawSessionToken);
        try {
            $key = $this->crypto->decryptPacked($enc, $k, CryptoService::shareSessionAad($shareId));
        } catch (RuntimeException) {
            $key = null;
        } finally {
            sodium_memzero($k);
        }
        if ($key === null) {
            json_response(["error" => __("err_session_key_decrypt")], 403);
        }
        return $key;
    }

    private function fetchByToken(string $token, string $pageReferrer = "", string $pageUrl = ""): array
    {
        // Only the shape generateToken(8) writes. The column's collation
        // ignores case and trailing spaces, so "ABCD…" or "abcd… " would
        // otherwise find the share, and share/auth.php would name the visit
        // cookie after the token as typed (one with a space is refused by
        // setcookie(), a 500 after the visit was already counted). Answered
        // as a token that matches no share.
        $row = false;
        if (preg_match('/^[a-f0-9]{16}\z/', $token) === 1) {
            $stmt = $this->db->prepare(
                "SELECT * FROM " . DB_PREFIX . "shares WHERE token = :tok LIMIT 1"
            );
            $stmt->execute([":tok" => $token]);
            $row = $stmt->fetch();
        }

        // Expired looks exactly like revoked or unknown: holding a token must
        // not tell anyone that a link once worked. Destroyed here, then
        // answered below by the same branch as an unknown token; the response
        // is unconditional even if the best-effort sweep fails.
        if ($row && self::isExpired($row)) {
            $this->destroyShare((int) $row["id"], $row, "share.expire");
            $row = false;
        }

        if (!$row) {
            // 403, identical to a wrong KEY, so the response does not reveal
            // whether a share exists. share.token_probe is its own bucket
            // because an unknown token belongs to no per-share one.
            log_activity($this->db, "share.token_probe", null, null, visitor_failure_context($pageReferrer, $pageUrl));
            usleep(random_int(100000, 300000));
            json_response(
                ["error" => __("share_invalid"), "code" => "share_invalid"],
                403
            );
        }

        return $row;
    }

    /**
     * Re-seal a file's metadata in every share_file_keys row after a rename or
     * edit, so visitors see what the owner sees. $fields maps "name", "mime",
     * "desc", "hashes" to new plaintext ("" clears). Each row's shareKey is
     * re-derived from its stored salt; an unreadable row skips that share, never
     * the caller's operation. No-op for an unshared file.
     *
     * @param array<string, string> $fields
     */
    public function syncFileMetadata(int $fileId, string $masterKey, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        // Column names are concatenated into the SQL.
        foreach (array_keys($fields) as $col) {
            if (!in_array($col, ["name", "mime", "desc", "hashes"], true)) {
                throw new InvalidArgumentException("syncFileMetadata(): unsupported column.");
            }
        }

        $stmt = $this->db->prepare(
            "SELECT sfk.id, sfk.dek_salt, f.storage_name,
                    s.key_enc, s.key_enc_iv, s.key_enc_tag, s.token
               FROM " . DB_PREFIX . "share_file_keys sfk
               JOIN " . DB_PREFIX . "shares s ON s.id = sfk.share_id
               JOIN " . DB_PREFIX . "files f ON f.id = sfk.file_id
              WHERE sfk.file_id = :fid"
        );
        $stmt->execute([":fid" => $fileId]);

        foreach ($stmt->fetchAll() as $row) {
            try {
                $rawKey = $this->unwrapKey($row, $masterKey);
                try {
                    $shareKey = $this->crypto->deriveShareKey($rawKey, self::salt($row["dek_salt"]));
                } finally {
                    sodium_memzero($rawKey);
                }

                $sets = [];
                $params = [":id" => (int) $row["id"]];
                foreach ($fields as $col => $value) {
                    if ($value === "") {
                        $sets[] = "{$col}_enc = NULL, {$col}_iv = NULL, {$col}_tag = NULL";
                        continue;
                    }
                    $sealed = $this->crypto->encryptMeta(
                        $value,
                        $shareKey,
                        CryptoService::fileMetaAad($col, (string) $row["storage_name"])
                    );
                    $sets[] = "{$col}_enc = :{$col}e, {$col}_iv = :{$col}i, {$col}_tag = :{$col}t";
                    $params += [
                        ":{$col}e" => $sealed["enc"],
                        ":{$col}i" => $sealed["iv"],
                        ":{$col}t" => $sealed["tag"],
                    ];
                }
                sodium_memzero($shareKey);

                $this->db
                    ->prepare("UPDATE " . DB_PREFIX . "share_file_keys SET " . implode(", ", $sets) . " WHERE id = :id")
                    ->execute($params);
            } catch (PDOException $e) {
                throw $e;
            } catch (RuntimeException $e) {
                error_log("[AESBin] share metadata sync skipped share_file_keys id " . (int) $row["id"] . ": " . $e->getMessage());
            }
        }
    }

    /** SET clause for one label's four columns, placeholders as labelParams() names them. */
    private const LABEL_SET = [
        "tn" => "target_name_enc = :tne, target_name_iv = :tniv, target_name_tag = :tntag, target_name_salt = :tnsal",
        "td" => "target_desc_enc = :tde, target_desc_iv = :tdiv, target_desc_tag = :tdtag, target_desc_salt = :tdsal",
    ];

    /**
     * A share's raw KEY (the URL fragment), unwrapped from shares.key_enc.
     *
     * @throws RuntimeException when it does not authenticate
     */
    private function unwrapKey(array $share, string $masterKey): string
    {
        return $this->crypto->unwrapDek(
            $share["key_enc"],
            $share["key_enc_iv"],
            $share["key_enc_tag"],
            $masterKey,
            CryptoService::shareKeyAad((string) $share["token"])
        );
    }

    /**
     * A stored base64 salt, decoded. A false passed on to deriveShareKey()
     * would be a TypeError under strict_types, which no share-flow
     * catch (RuntimeException) handles: a bare 500 on a public endpoint.
     *
     * @throws RuntimeException on a missing or corrupt salt
     */
    public static function salt(?string $b64): string
    {
        $salt = base64_decode((string) $b64, true);
        if ($salt === false || $salt === "") {
            throw new RuntimeException("Corrupted share salt.");
        }
        return $salt;
    }

    /**
     * Seal a visitor-facing label (target name or description) for the share
     * page, under a key derived from the share KEY and a salt stored beside
     * it. $saltB64 keeps the salt already stored when it is still readable.
     *
     * @param "target_name"|"target_desc" $col
     * @return array{enc:string, iv:string, tag:string, salt:string}|null  null for an empty label
     */
    private function sealLabel(string $key, string $token, string $col, string $plaintext, ?string $saltB64 = null): ?array
    {
        if ($plaintext === "") {
            return null;
        }
        try {
            $salt = self::salt($saltB64);
        } catch (RuntimeException) {
            $salt = $this->crypto->generateSalt();
        }
        $labelKey = $this->crypto->deriveShareKey($key, $salt);
        $sealed = $this->crypto->encryptMeta($plaintext, $labelKey, CryptoService::shareLabelAad($col, $token));
        sodium_memzero($labelKey);
        return $sealed + ["salt" => base64_encode($salt)];
    }

    /**
     * The visitor-facing label $col of $share, decrypted with the share KEY,
     * or "" when there is none or it is unreadable: a label is never worth
     * failing the share page over.
     *
     * @param "target_name"|"target_desc" $col
     */
    private function openLabel(array $share, string $col, string $key): string
    {
        if (empty($share["{$col}_enc"])) {
            return "";
        }
        try {
            $labelKey = $this->crypto->deriveShareKey($key, self::salt($share["{$col}_salt"]));
        } catch (RuntimeException) {
            return "";
        }
        $plain = $this->crypto->decryptColumn($share, $col, $labelKey, CryptoService::shareLabelAad($col, (string) $share["token"]));
        sodium_memzero($labelKey);
        return $plain;
    }

    /**
     * Bound values for one label's columns (see LABEL_SET), all NULL for
     * no label.
     *
     * @param "tn"|"td" $p
     */
    private static function labelParams(string $p, ?array $sealed): array
    {
        return [
            ":{$p}e" => $sealed["enc"] ?? null,
            ":{$p}iv" => $sealed["iv"] ?? null,
            ":{$p}tag" => $sealed["tag"] ?? null,
            ":{$p}sal" => $sealed["salt"] ?? null,
        ];
    }

    /**
     * Count, log and answer a failed visitor attempt on one share: a wrong
     * KEY (403 share_invalid) or a wrong password (401 password_required).
     * The random delay matches fetchByToken()'s.
     *
     * @param "key"|"password" $reason
     */
    private function rejectVisitor(int $shareId, string $reason, string $pageReferrer, string $pageUrl): never
    {
        rate_limit_record($this->db, self::failureBucket($shareId));
        log_activity(
            $this->db,
            "share.failure",
            "share",
            $shareId,
            ["reason" => $reason] + visitor_failure_context($pageReferrer, $pageUrl, false)
        );
        usleep(random_int(100000, 300000));
        if ($reason === "password") {
            json_response(["error" => __("share_enter_password"), "code" => "password_required"], 401);
        }
        json_response(["error" => __("share_invalid"), "code" => "share_invalid"], 403);
    }

    /**
     * Whether the share has expired: the SQL filters' "expires_at > NOW()",
     * inverted. expires_at is UTC, hence the explicit zone.
     */
    private static function isExpired(array $share): bool
    {
        return $share["expires_at"] !== null &&
            strtotime($share["expires_at"] . " UTC") <= time();
    }

    /**
     * Decrypted name and description of a directory, for a share's labels.
     * 404 when it does not exist, rather than creating a dead link.
     *
     * @return array{0: string, 1: string}
     */
    private function directoryLabels(int $directoryId, string $masterKey): array
    {
        $stmt = $this->db->prepare(
            "SELECT name_enc, name_iv, name_tag, desc_enc, desc_iv, desc_tag
               FROM " . DB_PREFIX . "directories WHERE id = :id LIMIT 1"
        );
        $stmt->execute([":id" => $directoryId]);
        $row = $stmt->fetch();
        if (!$row) {
            json_response(["error" => __("directory_not_found")], 404);
        }
        return [
            $this->crypto->decryptColumn($row, "name", $masterKey, CryptoService::rowAad("directory", $directoryId)),
            $this->crypto->decryptColumn($row, "desc", $masterKey, CryptoService::directoryDescAad($directoryId)),
        ];
    }

    /** Every live file row in a directory. */
    private function directoryFiles(int $directoryId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM " . DB_PREFIX . "files WHERE directory_id = :did AND trashed_at IS NULL"
        );
        $stmt->execute([":did" => $directoryId]);
        return $stmt->fetchAll();
    }

    private function fetchFile(int $fileId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM " .
                DB_PREFIX .
                "files WHERE id = :id AND trashed_at IS NULL LIMIT 1"
        );
        $stmt->execute([":id" => $fileId]);
        $row = $stmt->fetch();
        if (!$row) {
            json_response(["error" => __("file_not_found")], 404);
        }
        return $row;
    }
}
