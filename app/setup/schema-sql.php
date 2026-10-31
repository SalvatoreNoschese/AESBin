<?php

/**
 * app/setup/schema-sql.php — AESBin database schema
 *
 * Wrapped in PHP (like wordlist.php) so the schema is never served as plain
 * text, even if .htaccess protection fails. Returns the raw SQL; index.php
 * substitutes the {P} table prefix and executes each statement.
 *
 * EDITING RULE: no literal semicolons inside quoted text, identifiers,
 * trigger bodies or stored procedures. index.php splits statements with a
 * plain explode(";"), so `DEFAULT 'a;b'` would be cut in half and leave a
 * half-created schema. Whole-line `--` comments are stripped first and may
 * contain semicolons; trailing `--` comments after SQL are not stripped and
 * must not. The unbalanced-quote check in index.php is a backstop, not a
 * parser.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

return <<<'SQL'
-- =============================================================================
-- AESBin — Database Schema, schema_version 1
-- MySQL 5.7.8+ / MariaDB 10.3+ (see docs/installation.md)
--
-- Table prefix: replace {P} with your configured prefix (e.g. aesbin_a3f2c8d1_)
--
-- CRYPTO MODEL:
--   MASTER_KEY (random 32B, never stored plaintext)
--     {P}auth.wrapped_master          = AES-256-GCM(MK, KEK(password))
--     {P}auth.recovery_wrapped_master = AES-256-GCM(MK, KEK(10-words))
--
--   TOTP secret encrypted with MASTER_KEY; readable only while a request
--   holds the unwrapped MASTER_KEY (a login, or revealing it in settings)
--     {P}auth.totp_secret_enc / _iv / _tag
--
--   DEK (random 32B per file)
--     {P}files.enc_key                = AES-256-GCM(DEK, MASTER_KEY)
--   File blob                         = AES-256-GCM(plaintext, DEK) on disk
--
--   Metadata (names, descriptions, MIME types, checksums) encrypted with
--   MASTER_KEY, stored as _enc / _iv / _tag triplets (base64). The AAD of
--   each is built in CryptoService (section "Associated data").
--
-- SINGLE USER: {P}auth.id = 1 only, enforced by CHECK constraint.
--
-- FK POLICY:
--   files  → containers : SET NULL  (trashed files orphan to NULL on container delete)
--   files  → directories: SET NULL  (only trashed files remain when a
--                                    directory is deleted: it trashes its
--                                    active files first)
--   dirs   → containers : RESTRICT  (PHP handles cascades explicitly)
-- =============================================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── Auth (single user, id=1 only) ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS {P}auth (
    id                          INT UNSIGNED NOT NULL DEFAULT 1,

    -- Login credential (Argon2id hash)
    password_hash               VARCHAR(255) NOT NULL,

    -- MASTER_KEY wrapped with KEK = Argon2id(password, kek_salt)
    kek_salt                    BINARY(16)   NOT NULL,
    wrapped_master              VARCHAR(64)  NOT NULL,
    wrapped_master_iv           VARCHAR(32)  NOT NULL,
    wrapped_master_tag          VARCHAR(32)  NOT NULL,

    -- MASTER_KEY wrapped with KEK = Argon2id(recovery_phrase, recovery_kek_salt)
    recovery_kek_salt           BINARY(16)   NOT NULL,
    recovery_wrapped_master     VARCHAR(64)  NOT NULL,
    recovery_wrapped_master_iv  VARCHAR(32)  NOT NULL,
    recovery_wrapped_master_tag VARCHAR(32)  NOT NULL,
    -- Argon2id hash of the recovery phrase, for the phrase check on the
    -- recovery page (recovery_verify.php). The reset itself proves the phrase by
    -- unwrapping recovery_wrapped_master
    recovery_hash               VARCHAR(255) NOT NULL,

    -- TOTP secret encrypted with MASTER_KEY
    -- TOTP is mandatory: unlike password/recovery, there is no "disabled"
    -- state, so this table carries no enabled flag for it.
    totp_secret_enc             VARCHAR(128) NOT NULL,
    totp_secret_iv              VARCHAR(32)  NOT NULL,
    totp_secret_tag             VARCHAR(32)  NOT NULL,
    -- Replay protection: floor(unix_time / 30) value of the last accepted TOTP code.
    -- On each successful TOTP verify the matched step is stored here.
    -- A new code is rejected if its matched step <= this value.
    totp_last_used_step         BIGINT       NULL,

    -- WebAuthn/passkey credentials + per-credential wrapped master key (JSON)
    webauthn_data               JSON         NULL,

    created_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Auto-bumped by MySQL on UPDATE, but only actually moves on a genuine
    -- credential change (password reset, TOTP reset, passkey add/remove):
    -- the frequent per-login writes (TOTP replay-step, passkey sign count,
    -- automatic password rehash) all pin this column to itself in their
    -- own UPDATE, specifically so it keeps meaning "credentials last
    -- changed" rather than "last login". Read by the reminder on the
    -- settings card's credential-reset section (app/views/dashboard.php).
    updated_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    CONSTRAINT {P}chk_single_user CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Containers ────────────────────────────────────────────────────────────────
-- NOTE: name uniqueness is enforced at application level (encrypted names cannot
-- have a DB UNIQUE index). See container/create.php and container/rename.php.
-- name is encrypted with MASTER_KEY: name_enc / name_iv / name_tag (base64)
CREATE TABLE IF NOT EXISTS {P}containers (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name_enc    VARCHAR(512) NOT NULL,
    name_iv     VARCHAR(32)  NOT NULL,
    name_tag    VARCHAR(32)  NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Directories (flat, no nesting) ───────────────────────────────────────────
-- name is encrypted with MASTER_KEY: name_enc / name_iv / name_tag (base64)
CREATE TABLE IF NOT EXISTS {P}directories (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    container_id INT UNSIGNED NOT NULL,
    name_enc     VARCHAR(512) NOT NULL,
    name_iv      VARCHAR(32)  NOT NULL,
    name_tag     VARCHAR(32)  NOT NULL,
    -- Optional description encrypted with MASTER_KEY (nullable: empty = no desc)
    desc_enc     VARCHAR(4096) NULL,
    desc_iv      VARCHAR(32)   NULL,
    desc_tag     VARCHAR(32)   NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dir_container (container_id),
    CONSTRAINT {P}fk_dir_container
        FOREIGN KEY (container_id) REFERENCES {P}containers (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Files ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS {P}files (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    container_id  INT UNSIGNED    NULL,     -- NULL when container was deleted (file still in trash)
    directory_id  INT UNSIGNED    NULL,
    storage_name  VARCHAR(64)     NOT NULL,  -- random hex, never the original name

    -- File name encrypted with MASTER_KEY
    name_enc      VARCHAR(1024)   NOT NULL,
    name_iv       VARCHAR(32)     NOT NULL,
    name_tag      VARCHAR(32)     NOT NULL,

    -- description encrypted with MASTER_KEY (nullable: empty string = no desc)
    desc_enc      VARCHAR(4096)   NULL,
    desc_iv       VARCHAR(32)     NULL,
    desc_tag      VARCHAR(32)     NULL,

    -- mime encrypted with MASTER_KEY
    mime_enc      VARCHAR(256)    NOT NULL,
    mime_iv       VARCHAR(32)     NOT NULL,
    mime_tag      VARCHAR(32)     NOT NULL,

    size          BIGINT UNSIGNED NOT NULL,  -- plaintext size in bytes (not secret)

    -- Checksums of the plaintext content (CRC32, MD5, SHA-1, SHA-256, SHA-512
    -- as a single JSON object), encrypted with MASTER_KEY like name/mime.
    -- Computed once at upload (and recomputed on writeContent() for text-file
    -- edits), never re-derived from ciphertext, since the whole point is to
    -- let a downloader verify against what they actually received.
    hashes_enc    VARCHAR(768)    NULL,
    hashes_iv     VARCHAR(32)     NULL,
    hashes_tag    VARCHAR(32)     NULL,

    -- Downloads and previews by share visitors, through any link (directory
    -- links included). They live on the file, not on the share, so they
    -- survive revocation, expiry and retargeting. A link counts only its
    -- openings (shares.visit_count).
    download_count INT UNSIGNED    NOT NULL DEFAULT 0,
    preview_count  INT UNSIGNED    NOT NULL DEFAULT 0,

    -- AES-256-GCM file content layer
    aes_iv        VARCHAR(32)     NOT NULL,  -- base64, 12-byte IV
    aes_tag       VARCHAR(32)     NOT NULL,  -- base64, 16-byte auth tag

    -- DEK wrapped with MASTER_KEY
    enc_key       VARCHAR(64)     NOT NULL,  -- base64, encrypted 32-byte DEK
    enc_key_iv    VARCHAR(32)     NOT NULL,
    enc_key_tag   VARCHAR(32)     NOT NULL,

    -- Soft delete: stores original path for restore
    trashed_at                    DATETIME     NULL,
    -- encrypted dir name snapshot for restore (MASTER_KEY)
    trashed_from_dir_name_enc     VARCHAR(512) NULL,
    trashed_from_dir_name_iv      VARCHAR(32)  NULL,
    trashed_from_dir_name_tag     VARCHAR(32)  NULL,
    -- encrypted container name snapshot for restore (MASTER_KEY)
    trashed_from_container_name_enc VARCHAR(512) NULL,
    trashed_from_container_name_iv  VARCHAR(32)  NULL,
    trashed_from_container_name_tag VARCHAR(32)  NULL,

    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_storage (storage_name),
    KEY idx_file_container (container_id),
    KEY idx_file_directory (directory_id),
    KEY idx_file_trashed   (trashed_at),

    CONSTRAINT {P}fk_file_container
        FOREIGN KEY (container_id) REFERENCES {P}containers (id) ON DELETE SET NULL,

    CONSTRAINT {P}fk_file_directory
        FOREIGN KEY (directory_id) REFERENCES {P}directories (id) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Shares (authorization only) ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS {P}shares (
    id            INT UNSIGNED             NOT NULL AUTO_INCREMENT,
    token         VARCHAR(64)              NOT NULL,  -- 16 hex chars, public ID
    type          ENUM('file','directory') NOT NULL,
    target_id     INT UNSIGNED             NOT NULL,
    -- KEY encrypted with MASTER_KEY so owner can always recover the full share URL
    key_enc       VARCHAR(128)             NOT NULL,
    key_enc_iv    VARCHAR(32)              NOT NULL,
    key_enc_tag   VARCHAR(32)              NOT NULL,
    -- Optional extra password, on top of the link's own key
    password_hash VARCHAR(255)             NULL,
    expires_at    DATETIME                 NULL,
    -- Openings of this link (each successful visitor authentication). It is
    -- the URL's own history, so a retarget keeps it. Deleted with the link;
    -- downloads and previews are counted on files.
    visit_count   INT UNSIGNED             NOT NULL DEFAULT 0,
    -- Target name encrypted with shareKey, derived from the URL-fragment key (never sent to the server except once, on visitor auth)
    -- Allows share page to show the directory/file name without MASTER_KEY.
    -- shareKey = deriveShareKey(KEY_from_fragment, target_name_salt)
    target_name_enc  VARCHAR(512) NULL,
    target_name_iv   VARCHAR(32)  NULL,
    target_name_tag  VARCHAR(32)  NULL,
    target_name_salt VARCHAR(32)  NULL,
    -- Target description encrypted with shareKey (same mechanism as target_name)
    target_desc_enc  VARCHAR(4096) NULL,
    target_desc_iv   VARCHAR(32)   NULL,
    target_desc_tag  VARCHAR(32)   NULL,
    target_desc_salt VARCHAR(32)   NULL,
    created_at    DATETIME                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_share_token (token),
    KEY idx_share_target (type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Share file keys: DEK re-wrapped per share ─────────────────────────────────
CREATE TABLE IF NOT EXISTS {P}share_file_keys (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    share_id    INT UNSIGNED NOT NULL,
    file_id     INT UNSIGNED NOT NULL,
    -- Opaque handle used in public URLs instead of the real file_id: 6 hex
    -- chars (3 random bytes), unique within a share only. 16.7M values keep
    -- the retry-on-collision in ShareService::storeFileKey() reliable for
    -- shares of millions of files. Never shown to a person.
    file_token  CHAR(6)      NOT NULL,
    -- DEK re-wrapped with shareKey = deriveShareKey(KEY, dek_salt), a keyed BLAKE2b
    dek_enc     VARCHAR(64)  NOT NULL,
    dek_enc_iv  VARCHAR(32)  NOT NULL,
    dek_enc_tag VARCHAR(32)  NOT NULL,
    dek_salt    VARCHAR(32)  NOT NULL,
    -- File metadata re-encrypted with shareKey (same key, same salt)
    -- Allows share visitors to see filename/type/description without MASTER_KEY
    name_enc    VARCHAR(1024) NULL,
    name_iv     VARCHAR(32)   NULL,
    name_tag    VARCHAR(32)   NULL,
    mime_enc    VARCHAR(256)  NULL,
    mime_iv     VARCHAR(32)   NULL,
    mime_tag    VARCHAR(32)   NULL,
    desc_enc    VARCHAR(4096) NULL,
    desc_iv     VARCHAR(32)   NULL,
    desc_tag    VARCHAR(32)   NULL,
    -- Checksums re-encrypted with shareKey (same mechanism as name/mime/desc
    -- above). Lets share visitors see them without MASTER_KEY.
    hashes_enc  VARCHAR(768)  NULL,
    hashes_iv   VARCHAR(32)   NULL,
    hashes_tag  VARCHAR(32)   NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sfk (share_id, file_id),
    UNIQUE KEY uq_sfk_token (share_id, file_token),
    KEY idx_sfk_file (file_id),
    CONSTRAINT {P}fk_sfk_share FOREIGN KEY (share_id) REFERENCES {P}shares (id) ON DELETE CASCADE,
    CONSTRAINT {P}fk_sfk_file  FOREIGN KEY (file_id)  REFERENCES {P}files  (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Share sessions: visitor authorization tokens ──────────────────────────────
CREATE TABLE IF NOT EXISTS {P}share_sessions (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    share_id          INT UNSIGNED NOT NULL,
    access_token_hash VARCHAR(64)  NOT NULL,
    share_key_enc     VARCHAR(128) NOT NULL,
    expires_at        DATETIME     NOT NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ss_token (access_token_hash),
    KEY idx_ss_share   (share_id),
    KEY idx_ss_expires (expires_at),
    CONSTRAINT {P}fk_ss_share FOREIGN KEY (share_id) REFERENCES {P}shares (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Files a visitor session has opened ────────────────────────────────────────
-- One row per session, file and intent: a preview or a download is counted
-- and logged the first time in a session only (ShareService::firstAccess()).
-- Goes with its session (share_sessions_delete()).
CREATE TABLE IF NOT EXISTS {P}share_session_files (
    session_id  INT UNSIGNED NOT NULL,
    file_token  CHAR(6)      NOT NULL,
    intent      ENUM('download', 'preview') NOT NULL,
    PRIMARY KEY (session_id, file_token, intent),
    CONSTRAINT {P}fk_ssf_session FOREIGN KEY (session_id) REFERENCES {P}share_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Owner session registry ────────────────────────────────────────────────────
-- One row per full (authenticated) session, across all browsers/devices.
-- Only the SHA-256 hash of the PHP session id is stored: a DB dump must not
-- enable session hijacking. Revocation = deleting the row; requireAuth()
-- verifies the current session's hash exists here on every request.
-- Created here only, like every other table; a later change to it is a step
-- in app/migrations.php.
CREATE TABLE IF NOT EXISTS {P}sessions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sid_hash    VARCHAR(64)  NOT NULL,
    ip          VARCHAR(45)  NULL,
    ua          VARCHAR(255) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sess_sid (sid_hash),
    KEY idx_sess_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Activity log ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS {P}activity_log (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- One row PER EVENT NAME, forever, not one row per occurrence. See
    -- docs/maintaining.md § the activity log is one row per event, not one row
    -- per occurrence. target/ip/ua/referer/etc. all live per-item inside
    -- meta.items, since which target or visitor an occurrence involves
    -- varies per item, not per row.
    event       VARCHAR(64)  NOT NULL,
    meta        JSON         NULL,
    -- The most recent item's timestamp, kept in sync on every write so the
    -- card list can sort "most recently active first" with a plain
    -- ORDER BY, without parsing meta.items in SQL.
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_al_event (event),
    KEY idx_al_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Consumed captcha challenges (ALTCHA anti-replay) ─────────────────────────
-- verifySolution() is stateless: it only checks the HMAC signature and the
-- expiry baked into the salt, so one solved proof-of-work could otherwise be
-- replayed on every request until it expired. One row per accepted solution,
-- pruned opportunistically once past its own expiry.
CREATE TABLE IF NOT EXISTS {P}captcha_used (
    challenge_hash CHAR(64)  NOT NULL,
    expires_at     DATETIME  NOT NULL,
    PRIMARY KEY (challenge_hash),
    KEY idx_cu_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Rate limiting ───────────────────────────────────────────────────────────
-- Deliberately separate from activity_log, and it must stay that way. This is
-- security state; activity_log is a human-readable history the owner may empty
-- and cap at will. Sharing one table would mean clearing the log for privacy
-- also clears every active lockout. Rows are swept after RATE_LIMIT_TTL.
CREATE TABLE IF NOT EXISTS {P}rate_limit (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket     VARCHAR(32)  NOT NULL,
    ip         VARCHAR(45)  NOT NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_rl_lookup (bucket, ip, created_at),
    -- For rate_limit_sweep(), which bootstrap.php runs on every request: a
    -- full scan there would lock every row.
    KEY idx_rl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Share totals ────────────────────────────────────────────────────────────
-- Every opening, download and preview by a share visitor since installation.
-- The per-link and per-file counters go with their row and activity_log may
-- be emptied, so neither can answer "how much was shared, ever": this can,
-- and it only grows. One row per key in SHARE_TOTAL_KEYS (helpers.php),
-- created by its first increment.
CREATE TABLE IF NOT EXISTS {P}stats (
    stat_key   VARCHAR(32)     NOT NULL,
    stat_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (stat_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Schema version marker ───────────────────────────────────────────────────
-- Records which structural version of the database this is, so the code can
-- tell whether it is talking to a schema it understands.
--
-- schema_version is NOT the application version. It is an integer that moves
-- only when the structure changes; most releases leave it alone. Tying the two
-- together would mean writing a no-op migration for every release.
--
-- Compared at boot against Migrator::targetVersion() in app/includes/Migrator.php:
--   db  <  code  -> a migration is pending. The app does NOT refuse: it sets
--                   AESBIN_MIGRATION_PENDING, serves normally and shows a
--                   banner offering to run the pending steps. Older data that
--                   the new code can still read is not a corruption risk.
--   db  >  code  -> older code on a newer database; the app refuses to run,
--                   because that is the direction that silently corrupts
--                   (see the schema version gate in bootstrap.php)
CREATE TABLE IF NOT EXISTS {P}meta (
    meta_key   VARCHAR(64) NOT NULL,
    meta_value TEXT        NOT NULL,
    updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO {P}meta (meta_key, meta_value) VALUES ('schema_version', '1')
    ON DUPLICATE KEY UPDATE meta_value = meta_value;

SET foreign_key_checks = 1;
SQL;
