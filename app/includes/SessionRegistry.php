<?php

declare(strict_types=1);

namespace AESBin;

use PDO;

defined("AESBIN_APP") || exit;

/**
 * app/includes/SessionRegistry.php — Active session registry
 *
 * Every full session has a row in {P}sessions, so the owner can see and end
 * sessions across devices. Only the SHA-256 of the session id is stored.
 * requireAuth() checks the row on every request: a hash with no row, for
 * whatever reason, is treated as revoked (401). Idle rows are pruned on
 * every request and logged as "logout"/idle_timeout.
 */

class SessionRegistry
{
    private PDO $db;
    /** App idle timeout in seconds (0 = disabled). Drives orphan pruning. */
    private int $idleTimeout = 0;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function setIdleTimeout(int $seconds): void
    {
        $this->idleTimeout = max(0, $seconds);
    }

    // ── Lifecycle hooks (called from Auth) ────────────────────────────────────

    /** Register a freshly created full session (called from sessionFull()). */
    public function register(string $sid, ?string $ip, ?string $ua = null): void
    {
        // A collision is practically impossible after session_regenerate_id(),
        // but a reused id must refresh the row, not throw.
        $stmt = $this->db->prepare(
            "INSERT INTO " . DB_PREFIX . "sessions
                (sid_hash, ip, ua, created_at, last_seen)
             VALUES (:h, :ip, :ua, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                ip = VALUES(ip), ua = VALUES(ua),
                created_at = NOW(), last_seen = NOW()"
        );
        $stmt->execute([
            ":h"  => self::hash($sid),
            ":ip" => $ip,
            ":ua" => $ua !== null ? mb_substr($ua, 0, 255) : null,
        ]);
    }

    /**
     * Verify the session is still registered and refresh its last_seen.
     * Returns false if the row is gone: the caller must end the session.
     *
     * last_seen writes are throttled to once per 60 s, so the common case is
     * a single indexed SELECT.
     */
    public function validateAndTouch(string $sid): bool
    {
        $h = self::hash($sid);
        $stmt = $this->db->prepare(
            "SELECT id, last_seen FROM " . DB_PREFIX . "sessions
              WHERE sid_hash = :h LIMIT 1"
        );
        $stmt->execute([":h" => $h]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        // " UTC" is required: last_seen comes from NOW() on a connection
        // pinned to +00:00 (see Database.php), and without the suffix
        // strtotime() uses PHP's timezone. West of UTC last_seen would then
        // never be refreshed, and pruneExpired() would end live sessions.
        // ShareService and SecurityService do the same.
        if (strtotime((string) $row["last_seen"] . " UTC") < time() - 60) {
            $upd = $this->db->prepare(
                "UPDATE " . DB_PREFIX . "sessions
                    SET last_seen = NOW() WHERE id = :id"
            );
            $upd->execute([":id" => $row["id"]]);
        }
        return true;
    }

    /** Remove the row for a session being logged out / destroyed. */
    public function remove(string $sid): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM " . DB_PREFIX . "sessions WHERE sid_hash = :h"
        );
        $stmt->execute([":h" => self::hash($sid)]);
    }

    // ── Owner-facing operations (list / revoke endpoints) ─────────────────────

    /**
     * List active sessions, most recent activity first. Prunes first so the
     * list never shows sessions already dead server-side.
     */
    public function listActive(string $currentSid): array
    {
        $this->pruneExpired();
        $stmt = $this->db->query(
            "SELECT id, sid_hash, ip, ua, created_at, last_seen
               FROM " . DB_PREFIX . "sessions
              ORDER BY last_seen DESC, id DESC"
        );
        $h = self::hash($currentSid);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                "id"         => (int) $row["id"],
                "ip"         => $row["ip"],
                // A short label ("Firefox · Android"), not the raw UA.
                "client"     => client_label($row["ua"]),
                "created_at" => $row["created_at"],
                "last_seen"  => $row["last_seen"],
                "current"    => hash_equals($h, (string) $row["sid_hash"]),
            ];
        }
        return $out;
    }

    /**
     * Revoke one session by row id. Refuses the current session (use the
     * normal logout for that). Returns true if a row was deleted.
     */
    public function revokeById(int $id, string $currentSid): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM " . DB_PREFIX . "sessions
              WHERE id = :id AND sid_hash <> :h"
        );
        $stmt->execute([":id" => $id, ":h" => self::hash($currentSid)]);
        return $stmt->rowCount() > 0;
    }

    /** Revoke every session except the current one. Returns count deleted. */
    public function revokeOthers(string $currentSid): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM " . DB_PREFIX . "sessions WHERE sid_hash <> :h"
        );
        $stmt->execute([":h" => self::hash($currentSid)]);
        return $stmt->rowCount();
    }

    /**
     * Delete and log rows of sessions that cannot be alive any more. Runs on
     * every request, so one indexed SELECT when nothing expired. Dead means idle
     * past timeout + 120 s (requireAuth() ends them at + 30 s), or without a
     * timeout past max(gc_maxlifetime, 24 h). Each log entry carries the
     * session's own ip/ua and the moment it crossed the threshold.
     */
    public function pruneExpired(): void
    {
        $ttl = $this->idleTimeout > 0
            ? $this->idleTimeout + 120
            : max((int) ini_get("session.gc_maxlifetime"), 86400);
        $stmt = $this->db->prepare(
            "SELECT id, ip, ua, last_seen FROM " . DB_PREFIX . "sessions
              WHERE last_seen < NOW() - INTERVAL :ttl SECOND"
        );
        $stmt->bindValue(":ttl", $ttl, PDO::PARAM_INT);
        $stmt->execute();
        $expired = $stmt->fetchAll();

        if (!$expired) {
            return;
        }

        // Each row is logged only by the request whose DELETE removed it, so
        // two requests sweeping at once never log one expiry twice. The age
        // is checked again: a session touched since the SELECT stays.
        $del = $this->db->prepare(
            "DELETE FROM " . DB_PREFIX . "sessions
              WHERE id = :id AND last_seen < NOW() - INTERVAL :ttl SECOND"
        );
        $del->bindValue(":ttl", $ttl, PDO::PARAM_INT);
        foreach ($expired as $s) {
            $del->bindValue(":id", (int) $s["id"], PDO::PARAM_INT);
            $del->execute();
            if ($del->rowCount() !== 1) {
                continue;
            }
            // last_seen is a UTC DATETIME string (see validateAndTouch()),
            // the same format log_activity() uses for "at".
            $at = $this->idleTimeout > 0
                ? gmdate(
                    "Y-m-d H:i:s",
                    strtotime((string) $s["last_seen"] . " UTC") + $this->idleTimeout
                )
                : (string) $s["last_seen"];
            log_activity($this->db, "logout", null, null, [
                "reason" => "idle_timeout",
                "ip" => $s["ip"],
                "ua" => $s["ua"],
                "at" => $at,
            ]);
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private static function hash(string $sid): string
    {
        return hash("sha256", $sid);
    }
}
