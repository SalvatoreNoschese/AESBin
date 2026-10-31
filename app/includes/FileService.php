<?php

declare(strict_types=1);

namespace AESBin;

use PDO;
use RuntimeException;
use Throwable;

defined("AESBIN_APP") || exit;

/**
 * app/includes/FileService.php — AESBin file operations
 *
 * Name, MIME, description and checksums are encrypted with MASTER_KEY and
 * bound to storage_name by AAD; plaintext names and types are never stored.
 * Trashed rows also carry container/directory name snapshots for restore().
 *
 * Share visitors go through downloadViaShare(), which uses the share's own
 * wrapped copies and never MASTER_KEY.
 *
 * FK policy: files → containers is SET NULL (trashed files orphan on
 * container delete); container/delete.php handles the order explicitly.
 */

class FileService
{
    private PDO $db;
    private CryptoService $crypto;
    /** Keeps the share copies of a file's metadata in step with it. */
    private ShareService $shares;

    public function __construct(PDO $db, CryptoService $crypto, ShareService $shares)
    {
        $this->db = $db;
        $this->crypto = $crypto;
        $this->shares = $shares;
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    public function upload(
        array $file,
        int $containerId,
        int $dirId,
        string $masterKey,
        ?string $description = null
    ): array {
        $this->validateUpload($file);
        require_container($this->db, $containerId);
        // Files always live in a directory, never at container root.
        $this->assertDirectory($dirId, $containerId);

        // Browsers send a multipart filename with ", CR and LF written as
        // %22, %0D and %0A (HTML's form encoding), and PHP does not undo it:
        // undone here, so filename_clean() sees the real characters (a
        // quote becomes "_", like every character Windows refuses). A name
        // that really held "%22" is read as a quote too; no browser can tell
        // the two apart.
        $sentName = str_replace(["%22", "%0D", "%0A"], ['"', "\r", "\n"], (string) $file["name"]);
        // The response says why a name was not kept, always from the name as
        // sent (NFC, which is no change worth a message): one message, with
        // every reason, however many steps below changed it.
        [$originalName, $adjusted] = filename_for_upload($sentName);
        if (function_exists("normalizer_normalize")) {
            $sentName = normalizer_normalize($sentName, \Normalizer::FORM_C) ?: $sentName;
        }

        $mime = $this->detectMime($file["tmp_name"], $originalName);

        // The sniffed content decides the type, never the name alone:
        //   (1) an editable extension whose content finfo sniffs as text is
        //       trusted, since finfo sniffs .py/.json/.md alike as text/plain,
        //       and the file gets the list's type for it (.md is
        //       text/markdown). A binary renamed to .txt is not sniffed as
        //       text (detectMime() ignores the browser's type) and falls to
        //       (2): corrected if it is a listed format, refused if not.
        //   (2) a format MimeRegistry lists corrects the extension when the
        //       name says another format, reported via corrected_from
        //       (MimeRegistry::resolveSniffed(): a name whose extension lists
        //       the sniffed type is kept).
        //   (3) anything else is refused: zipped, it uploads. Outside (1),
        //       the stored type is finfo's, as it names it, but for text a
        //       name says is Markdown or another listed text format
        //       ("README", "readme.md.exe": MimeRegistry::textByName()).
        //       Preview, editor and inline serving all go by the stored type
        //       and an exact list of it (MimeRegistry::isPreviewable()), never
        //       by the name.
        $claimedMime = MimeRegistry::extToMime($originalName);
        $correctedFrom = null;
        // "added" when the name had no listed extension ("README",
        // "File.v2"), "replaced" when it had a wrong one: the message says
        // which.
        $extensionChange = null;
        $trustClaimed =
            $claimedMime !== null &&
            MimeRegistry::isEditable($claimedMime) &&
            MimeRegistry::looksLikeTextMime($mime);
        if ($trustClaimed) {
            $mime = $claimedMime;
        } else {
            $resolved = MimeRegistry::resolveSniffed($mime, $originalName);
            if ($resolved === null) {
                json_response(
                    ["error" => __("upload_extension_unsupported")],
                    415
                );
            }
            // The name against what was found, by the rule docs/features.md
            // describes: an extension is one only if MimeRegistry lists it
            // (splitKnownExt(), the split rename() uses too). Listed and
            // right, it stays; listed and wrong, it is replaced (a compound
            // one whole, though a tar name holding a compression keeps its
            // ".tar"); unlisted or none, the name stays whole and gets one.
            // What is left is a name too: if it already names the content
            // ("backup.zip.txt", "foto.jpeg.txt", "rec.m4a.txt"), it is the
            // name, and text goes by what it says (textByName():
            // "readme.md.exe" is Markdown).
            $found = (string) $resolved["ext"];
            $last = (string) pathinfo($originalName, PATHINFO_EXTENSION);
            if (strtolower($last) === strtolower($found)) {
                $correctedName = $originalName;
            } else {
                [$stem, $ext] = MimeRegistry::splitKnownExt($originalName);
                $tarName = in_array(strtolower(ltrim($ext, ".")), [...FILENAME_COMPOUND_EXTS, "tar", "tgz"], true);
                if ($tarName && in_array("tar." . strtolower($found), FILENAME_COMPOUND_EXTS, true)) {
                    $stem .= ".tar";
                }
                $byName = MimeRegistry::textByName($stem, $mime);
                $inner = $byName ?? MimeRegistry::resolveSniffed($mime, $stem);
                $innerExt = (string) pathinfo($stem, PATHINFO_EXTENSION);
                if ($innerExt !== "" && $inner !== null && strtolower((string) $inner["ext"]) === strtolower($innerExt)) {
                    // The stem keeps the name: "foto.jpeg.txt" is "foto.jpeg".
                    $resolved = $inner;
                    $correctedName = $stem;
                } else {
                    if ($byName !== null) {
                        $resolved = $byName;
                        $found = $byName["ext"];
                    }
                    $correctedName = $stem . "." . $found;
                }
            }
            $mime = $resolved["mime"];
            // Cleaned again: the name has changed, and the one written is
            // always filename_clean()'s.
            [$correctedName, $again] = filename_clean($correctedName);
            $adjusted = filename_top_reason($adjusted, $again);
            if ($correctedName !== $originalName) {
                $correctedFrom = $sentName;
                $extensionChange = MimeRegistry::splitKnownExt($originalName)[1] === "" ? "added" : "replaced";
                $originalName = $correctedName;
            }
        }

        // Every stored extension in lower case ("IMG.JPG" is "IMG.jpg"): no
        // correction to report, the name is the same.
        $originalName = MimeRegistry::lowerKnownExt($originalName);

        $finalName = $this->uniqueFilename(
            $originalName,
            $containerId,
            $dirId,
            $masterKey
        );

        $plaintext = file_get_contents($file["tmp_name"]);
        if ($plaintext === false) {
            json_response(["error" => __("cannot_read_blob")], 500);
        }
        $plainSize = strlen($plaintext);

        // storageName is the AAD context for every crypto operation on this
        // file, available before the row (and its id) exists.
        $storageName = $this->crypto->generateToken(16);
        ensure_storage_dir($storageName);
        $path = storage_path($storageName);

        // Checksums of the plaintext, so a downloader can verify what they got.
        $hashes = self::computeHashes($plaintext);

        $dek = $this->crypto->generateDek();
        $encrypted = $this->crypto->encryptFile($plaintext, $dek, CryptoService::fileAad($storageName));
        sodium_memzero($plaintext);

        $wrapped = $this->crypto->wrapDek($dek, $masterKey, CryptoService::dekAad($storageName));
        sodium_memzero($dek);

        // Bound to storageName so metadata cannot be swapped between rows.
        $encName = $this->crypto->encryptMeta($finalName, $masterKey, CryptoService::fileMetaAad("name", $storageName));
        $encMime = $this->crypto->encryptMeta($mime, $masterKey, CryptoService::fileMetaAad("mime", $storageName));
        $encHashes = $this->crypto->encryptMeta(
            json_encode($hashes, JSON_THROW_ON_ERROR),
            $masterKey,
            CryptoService::fileMetaAad("hashes", $storageName)
        );
        // Sent with the file by "Create file", so a new file and its
        // description are one request: no second call to fail halfway.
        $encDesc = ($description ?? "") !== ""
            ? $this->crypto->encryptMeta($description, $masterKey, CryptoService::fileMetaAad("desc", $storageName))
            : null;
        $written = file_put_contents($path, $encrypted["ciphertext"], LOCK_EX);
        if ($written !== strlen($encrypted["ciphertext"])) {
            @unlink($path);
            json_response(["error" => __("storage_write_failed")], 500);
        }
        chmod($path, 0640);

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO " .
                    DB_PREFIX .
                    'files
                    (container_id, directory_id, storage_name,
                     name_enc, name_iv, name_tag,
                     mime_enc, mime_iv, mime_tag,
                     hashes_enc, hashes_iv, hashes_tag,
                     desc_enc, desc_iv, desc_tag,
                     size, aes_iv, aes_tag, enc_key, enc_key_iv, enc_key_tag)
                 VALUES
                    (:cid,:did,:sn,
                     :ne,:niv,:ntag,
                     :me,:miv,:mtag,
                     :he,:hiv,:htag,
                     :de,:div,:dtag,
                     :sz,:iv,:tag,:ek,:ekiv,:ektag)'
            );
            $stmt->execute([
                ":cid" => $containerId,
                ":did" => $dirId,
                ":sn" => $storageName,
                ":ne" => $encName["enc"],
                ":niv" => $encName["iv"],
                ":ntag" => $encName["tag"],
                ":me" => $encMime["enc"],
                ":miv" => $encMime["iv"],
                ":mtag" => $encMime["tag"],
                ":he" => $encHashes["enc"],
                ":hiv" => $encHashes["iv"],
                ":htag" => $encHashes["tag"],
                ":de" => $encDesc["enc"] ?? null,
                ":div" => $encDesc["iv"] ?? null,
                ":dtag" => $encDesc["tag"] ?? null,
                ":sz" => $plainSize,
                ":iv" => $encrypted["iv"],
                ":tag" => $encrypted["tag"],
                ":ek" => $wrapped["enc"],
                ":ekiv" => $wrapped["iv"],
                ":ektag" => $wrapped["tag"],
            ]);
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        }

        return [
            "file_id" => (int) $this->db->lastInsertId(),
            "container_id" => $containerId,
            "directory_id" => $dirId,
            "original_name" => $finalName,
            "mime" => $mime,
            "corrected_from" => $correctedFrom,
            "extension_change" => $extensionChange,
            // The name as sent, and why it was not kept (one of
            // FILENAME_REASONS), before any extension correction or "_01".
            "adjusted_from" => $adjusted !== "" ? $sentName : null,
            "adjusted" => $adjusted,
            "size" => $plainSize,
            "size_human" => format_bytes($plainSize),
            "renamed" => $finalName !== $originalName,
        ];
    }

    // ── Download (authenticated user) ─────────────────────────────────────────

    public function download(
        int $fileId,
        string $masterKey,
        bool $inline = false
    ): void {
        $file = $this->fetchRawFile($fileId);

        try {
            $dek = $this->crypto->unwrapDek(
                $file["enc_key"],
                $file["enc_key_iv"],
                $file["enc_key_tag"],
                $masterKey,
                CryptoService::dekAad($file["storage_name"])
            );
        } catch (RuntimeException) {
            json_response(["error" => __("decryption_failed")], 500);
        }

        $name = $this->decryptName($file, $masterKey);
        $mime = $this->decryptMimeField($file, $masterKey);

        $this->sendDecrypted($file, $dek, $name, $mime, $inline);
        sodium_memzero($dek);
    }

    // ── Download (share visitor) ──────────────────────────────────────────────

    /**
     * Decrypt and stream a file for a share visitor.
     *
     * Name and MIME come from the share's own copies in share_file_keys, so
     * no MASTER_KEY is needed. Returns the file id.
     */
    public function downloadViaShare(
        string $fileToken,
        string $shareToken,
        string $key
    ): int {
        // trashed_at is defence in depth: trash() already deletes the file's
        // share_file_keys rows (see there). If that ever changes, an old
        // file_token must still get a 403, never a blob from _trash.
        $stmt = $this->db->prepare(
            "SELECT sfk.* FROM " .
                DB_PREFIX .
                'share_file_keys sfk
               JOIN ' .
                DB_PREFIX .
                'shares s ON s.id = sfk.share_id
               JOIN ' .
                DB_PREFIX .
                'files f ON f.id = sfk.file_id
              WHERE sfk.file_token = :ftok AND s.token = :tok
                AND f.trashed_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([":ftok" => $fileToken, ":tok" => $shareToken]);
        $sfk = $stmt->fetch();
        if (!$sfk) {
            json_response(["error" => __("file_not_in_share")], 403);
        }

        $file = $this->fetchRawFile((int) $sfk["file_id"]);

        try {
            $shareKey = $this->crypto->deriveShareKey($key, ShareService::salt($sfk["dek_salt"]));
        } catch (RuntimeException $e) {
            error_log("[AESBin] downloadViaShare: share_file_keys id " . (int) $sfk["id"] . ": " . $e->getMessage());
            json_response(["error" => __("decryption_failed")], 500);
        }

        try {
            $dek = $this->crypto->unwrapDek(
                $sfk["dek_enc"],
                $sfk["dek_enc_iv"],
                $sfk["dek_enc_tag"],
                $shareKey,
                CryptoService::dekAad($file["storage_name"])
            );
        } catch (RuntimeException) {
            sodium_memzero($shareKey);
            json_response(["error" => __("decryption_failed")], 500);
        }

        $sn = $file["storage_name"];
        $name = $this->crypto->decryptColumn($sfk, "name", $shareKey, CryptoService::fileMetaAad("name", $sn), "download");
        $mime = $this->crypto->decryptColumn($sfk, "mime", $shareKey, CryptoService::fileMetaAad("mime", $sn), "application/octet-stream");
        sodium_memzero($shareKey);

        $this->sendDecrypted($file, $dek, $name, $mime, false);
        sodium_memzero($dek);

        return (int) $sfk["file_id"];
    }

    // ── Trash ─────────────────────────────────────────────────────────────────

    /**
     * Move a file to the trash.
     *
     * @return int Number of file-type shares PERMANENTLY destroyed as a side
     *             effect. Trashing kills the public links pointing at the
     *             file and restore() does not bring them back; callers should
     *             surface this so the loss is never silent.
     */
    public function trash(int $fileId, string $masterKey): int
    {
        $file = $this->fetchRawFile($fileId);
        // A second tab or a double click: trashing again would reset the
        // trash date and log a second file.trash.
        if ($file["trashed_at"] !== null) {
            json_response(["error" => __("err_file_already_trashed")], 409);
        }

        // Name snapshots for the trash view and for restore().
        $encContainerName = $this->snapshotName("container", $file["container_id"], $fileId, $masterKey);
        $encDirName = $this->snapshotName("directory", $file["directory_id"], $fileId, $masterKey);

        // Names only, not descriptions: restore() matches folders by name, and
        // a carried description would have to overwrite or contradict that of
        // an existing same-named folder.

        // Row, shares and blob change together: the blob moves last, inside
        // the transaction, and is moved back if anything fails, so the row
        // and the file on disk never disagree.
        $sn = (string) $file["storage_name"];
        $moved = false;
        $this->db->beginTransaction();
        try {
            // trashed_at IS NULL: of two concurrent requests, one trashes.
            $stmt = $this->db->prepare(
                "UPDATE " .
                    DB_PREFIX .
                    'files
                 SET trashed_at = NOW(),
                     trashed_from_container_name_enc = :cne,
                     trashed_from_container_name_iv  = :cniv,
                     trashed_from_container_name_tag = :cntag,
                     trashed_from_dir_name_enc = :dne,
                     trashed_from_dir_name_iv  = :dniv,
                     trashed_from_dir_name_tag = :dntag
                 WHERE id = :id AND trashed_at IS NULL'
            );
            $stmt->execute([
                ":cne" => $encContainerName ? $encContainerName["enc"] : null,
                ":cniv" => $encContainerName ? $encContainerName["iv"] : null,
                ":cntag" => $encContainerName ? $encContainerName["tag"] : null,
                ":dne" => $encDirName ? $encDirName["enc"] : null,
                ":dniv" => $encDirName ? $encDirName["iv"] : null,
                ":dntag" => $encDirName ? $encDirName["tag"] : null,
                ":id" => $fileId,
            ]);
            if ($stmt->rowCount() !== 1) {
                db_rollback_if_open($this->db);
                json_response(["error" => __("err_file_already_trashed")], 409);
            }

            // Destroy file-type shares and drop every share_file_keys row for
            // the file, directory shares included. This is the primary gate
            // that makes a trashed file vanish from share links
            // (downloadViaShare() resolves through share_file_keys; its
            // trashed_at filter is the second layer).
            //
            // Permanent: restore() does not bring the links back. The
            // share.revoke items and the returned count keep the loss visible.
            $destroyed = destroy_file_shares($this->db, $fileId, "file_trashed");
            $this->db
                ->prepare("DELETE FROM " . DB_PREFIX . "share_file_keys WHERE file_id = :id")
                ->execute([":id" => $fileId]);

            log_activity($this->db, "file.trash", "file", $fileId, [
                "shares_destroyed" => $destroyed,
            ]);

            move_blob($sn, true);
            $moved = true;
            $this->db->commit();
        } catch (Throwable $e) {
            db_rollback_if_open($this->db);
            $this->moveBlobBack($sn, $moved, false);
            throw $e;
        }

        return $destroyed;
    }

    /**
     * Undo a move_blob() whose transaction then failed. Best effort: a failure
     * here is logged and left to integrity_check.php, which reports the blob
     * as misplaced, rather than masking the error being handled.
     */
    private function moveBlobBack(string $storageName, bool $moved, bool $toTrash): void
    {
        if (!$moved) {
            return;
        }
        try {
            move_blob($storageName, $toTrash);
        } catch (Throwable $e) {
            error_log("[AESBin] could not move blob {$storageName} back: " . $e->getMessage());
        }
    }

    /**
     * Re-seal a container or directory name as a snapshot bound to $fileId, for
     * the trash view and restore(). Best-effort: the label is cosmetic, so an
     * unreadable name is logged and skipped, never a 500. Null when none.
     *
     * @param "container"|"directory" $type
     * @return array{enc:string, iv:string, tag:string}|null
     */
    private function snapshotName(string $type, int|string|null $id, int $fileId, string $masterKey): ?array
    {
        if ($id === null) {
            return null;
        }
        $id = (int) $id;
        $table = $type === "container" ? "containers" : "directories";
        $stmt = $this->db->prepare(
            "SELECT name_enc, name_iv, name_tag FROM " . DB_PREFIX . $table . " WHERE id = :id LIMIT 1"
        );
        $stmt->execute([":id" => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        try {
            $name = $this->crypto->decryptMeta(
                $row["name_enc"],
                $row["name_iv"],
                $row["name_tag"],
                $masterKey,
                CryptoService::rowAad($type, $id)
            );
            return $this->crypto->encryptMeta($name, $masterKey, CryptoService::snapshotAad($type, $fileId));
        } catch (Throwable $e) {
            error_log("[AESBin] trash: {$type} name snapshot failed for file {$fileId}: " . $e->getMessage());
            return null;
        }
    }

    // ── Restore ───────────────────────────────────────────────────────────────

    /**
     * Take a file out of the trash, into its folder (recreated by name when
     * gone) under a free name. Returns the name it got, since the one it had
     * may be taken by now, and what had to be recreated for it: "" (nothing),
     * "directory", or "both" (a new container always needs a new folder).
     * Containers and folders never go to the trash; they are only how files
     * are arranged, so recreating one by name is the restore, not a side
     * effect, and the log and the toast say it happened.
     *
     * @return array{name: string, renamed: bool, recreated: string}
     */
    public function restore(int $fileId, string $masterKey): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM " .
                DB_PREFIX .
                "files WHERE id = :id AND trashed_at IS NOT NULL LIMIT 1"
        );
        $stmt->execute([":id" => $fileId]);
        $file = $stmt->fetch();
        if (!$file) {
            json_response(["error" => __("file_not_found")], 404);
        }

        $sn = (string) $file["storage_name"];
        $currentName = $this->crypto->decryptMeta(
            $file["name_enc"],
            $file["name_iv"],
            $file["name_tag"],
            $masterKey,
            CryptoService::fileMetaAad("name", $sn)
        );

        // As in trash(): a recreated folder, the row and the blob change
        // together, the blob last.
        $moved = false;
        $this->db->beginTransaction();
        try {
            // Destination is resolved by name, never by stale id (see
            // findOrCreateContainerFromSnapshot()). Each says whether it had
            // to create the row.
            [$cid, $newContainer] = $this->findOrCreateContainerFromSnapshot($file, $masterKey);
            [$did, $newDirectory] = $this->restoreDirectory($file, $cid, $masterKey);

            // Same _01, _02 … de-duplication as upload.
            $finalName = $this->uniqueFilename($currentName, $cid, $did, $masterKey);

            $params = [":cid" => $cid, ":did" => $did, ":id" => $fileId];
            $nameSet = "";
            if ($finalName !== $currentName) {
                $encName = $this->crypto->encryptMeta($finalName, $masterKey, CryptoService::fileMetaAad("name", $sn));
                $nameSet = ", name_enc = :ne, name_iv = :niv, name_tag = :ntag";
                $params[":ne"] = $encName["enc"];
                $params[":niv"] = $encName["iv"];
                $params[":ntag"] = $encName["tag"];
            }

            // trashed_at IS NOT NULL: of two concurrent restores, one wins.
            $stmt = $this->db->prepare(
                "UPDATE " .
                    DB_PREFIX .
                    'files
                 SET trashed_at = NULL,
                     trashed_from_container_name_enc     = NULL,
                     trashed_from_container_name_iv      = NULL,
                     trashed_from_container_name_tag     = NULL,
                     trashed_from_dir_name_enc           = NULL,
                     trashed_from_dir_name_iv            = NULL,
                     trashed_from_dir_name_tag           = NULL,
                     container_id = :cid, directory_id = :did' .
                    $nameSet .
                    '
                 WHERE id = :id AND trashed_at IS NOT NULL'
            );
            $stmt->execute($params);
            if ($stmt->rowCount() !== 1) {
                db_rollback_if_open($this->db);
                json_response(["error" => __("file_not_found")], 404);
            }

            // A new container always needs a new folder too.
            $recreated = $newContainer ? "both" : ($newDirectory ? "directory" : "");
            log_activity(
                $this->db,
                "file.restore",
                "file",
                $fileId,
                $recreated !== "" ? ["reason" => "recreated_" . $recreated] : []
            );

            move_blob($sn, false);
            $moved = true;
            $this->db->commit();
        } catch (Throwable $e) {
            db_rollback_if_open($this->db);
            $this->moveBlobBack($sn, $moved, true);
            throw $e;
        }
        return ["name" => $finalName, "renamed" => $finalName !== $currentName, "recreated" => $recreated];
    }

    // ── Write content (in-place re-encrypt) ───────────────────────────────────

    public function writeContent(
        int $fileId,
        string $newContent,
        string $masterKey
    ): void {
        $file = $this->fetchRawFile($fileId);

        // Refuse a trashed file before any disk I/O. The UPDATE's trashed_at
        // filter alone is not enough: the new ciphertext would already be
        // written to the ACTIVE path while the real blob sits in _trash,
        // leaving an orphan blob and a 200 for an edit that did not happen.
        if ($file["trashed_at"] !== null) {
            json_response(["error" => __("err_write_trashed")], 409);
        }

        // Only editable types. Enforced here because the endpoint is reachable
        // directly: otherwise a PDF could be overwritten with text while its
        // MIME still says PDF, the relabelling rename() refuses for binaries.
        if (!MimeRegistry::isEditable($this->decryptMimeField($file, $masterKey))) {
            json_response(["error" => __("err_write_not_editable")], 415);
        }

        try {
            $dek = $this->crypto->unwrapDek(
                $file["enc_key"],
                $file["enc_key_iv"],
                $file["enc_key_tag"],
                $masterKey,
                CryptoService::dekAad($file["storage_name"])
            );
        } catch (RuntimeException) {
            json_response(["error" => __("decryption_failed")], 500);
        }

        $encrypted = $this->crypto->encryptFile($newContent, $dek, CryptoService::fileAad($file["storage_name"]));
        sodium_memzero($dek);

        $hashesJson = json_encode(self::computeHashes($newContent), JSON_THROW_ON_ERROR);
        $encHashes = $this->crypto->encryptMeta(
            $hashesJson,
            $masterKey,
            CryptoService::fileMetaAad("hashes", $file["storage_name"])
        );

        $path = storage_path($file["storage_name"]);

        // Blob and IV/tag must change together, so: stage the new blob beside the
        // old one; in one transaction update the row, set the old blob aside and
        // rename the new one in; destroy the old blob only after the commit. Any
        // failure puts the old blob back. The UPDATE's row lock serialises
        // concurrent writes; stage/set-aside names match what integrity_check.php
        // reports as stale *.tmp.
        $tmp = $this->stagingPath($path);
        $written = file_put_contents($tmp, $encrypted["ciphertext"], LOCK_EX);
        if ($written !== strlen($encrypted["ciphertext"])) {
            $this->writeFailed($tmp, $path, $written, strlen($encrypted["ciphertext"]));
        }

        $old = $this->stagingPath($path);
        $oldSetAside = false;
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "UPDATE " .
                    DB_PREFIX .
                    'files
             SET aes_iv = :iv, aes_tag = :tag, size = :sz,
                 hashes_enc = :he, hashes_iv = :hiv, hashes_tag = :htag,
                 updated_at = NOW()
             WHERE id = :id AND trashed_at IS NULL'
            );
            $stmt->execute([
                ":iv" => $encrypted["iv"],
                ":tag" => $encrypted["tag"],
                ":sz" => strlen($newContent),
                ":he" => $encHashes["enc"],
                ":hiv" => $encHashes["iv"],
                ":htag" => $encHashes["tag"],
                ":id" => $fileId,
            ]);
            if ($stmt->rowCount() !== 1) {
                // Trashed since the check at the top.
                db_rollback_if_open($this->db);
                @unlink($tmp);
                json_response(["error" => __("err_write_trashed")], 409);
            }
            // A blob already missing (see integrity_check.php) has nothing to
            // set aside; the edit then restores the file.
            if (file_exists($path)) {
                if (!rename($path, $old)) {
                    throw new RuntimeException("Could not set the previous blob aside.");
                }
                $oldSetAside = true;
            }
            if (!rename($tmp, $path)) {
                throw new RuntimeException("Could not move the edited blob into place.");
            }
            $this->db->commit();
        } catch (Throwable $e) {
            db_rollback_if_open($this->db);
            if ($oldSetAside) {
                // rename() replaces the new blob if it is already in place.
                rename($old, $path);
            }
            $this->writeFailed($tmp, $path, $written, strlen($encrypted["ciphertext"]), $e);
        }

        // Committed: the previous ciphertext is no longer referenced.
        if ($oldSetAside) {
            destroy_blob($old);
        }

        // Visitors of a share see the checksums of what they download.
        $this->shares->syncFileMetadata($fileId, $masterKey, [
            "hashes" => $hashesJson,
        ]);

        log_activity($this->db, "file.write", "file", $fileId);
    }

    // ── Permanent delete ──────────────────────────────────────────────────────

    /**
     * Destroy a trashed file for good: drop the row, then wipe the blob. Only a
     * trashed file, enforced here too, since this is the one irreversible step.
     * shares.target_id has no FK, so file shares are destroyed explicitly.
     */
    public function deletePermanently(int $fileId): void
    {
        $stmt = $this->db->prepare(
            "SELECT storage_name, trashed_at FROM " .
                DB_PREFIX .
                "files WHERE id = :id LIMIT 1"
        );
        $stmt->execute([":id" => $fileId]);
        $file = $stmt->fetch();
        if (!$file) {
            json_response(["error" => __("file_not_found")], 404);
        }

        if ($file["trashed_at"] === null) {
            json_response(["error" => __("err_delete_not_trashed")], 409);
        }

        // Rows first, blob last (see delete_file_row()). trash() already
        // revoked every share on the file, so the reason is its label should
        // one have survived.
        delete_file_row($this->db, $fileId, "file_trashed");

        $path = storage_path($file["storage_name"], true);
        if (file_exists($path)) {
            destroy_blob($path);
        }
    }

    // ── List ──────────────────────────────────────────────────────────────────

    /** List files with decrypted metadata. */
    public function listFiles(
        string $masterKey,
        ?int $containerId = null,
        ?int $dirId = null,
        bool $trashed = false
    ): array {
        if ($trashed) {
            $sql =
                'SELECT f.*
                     FROM ' .
                DB_PREFIX .
                'files f
                     WHERE f.trashed_at IS NOT NULL
                     ORDER BY f.created_at DESC, f.id DESC';
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
        } else {
            // share_active: the file's own live links, the link icon and their
            // number on its badge.
            $sql =
                "SELECT f.*,
                        (SELECT COUNT(*) FROM " . DB_PREFIX . "shares s
                          WHERE s.type = 'file' AND s.target_id = f.id
                            AND (s.expires_at IS NULL OR s.expires_at > NOW())) AS share_active
                   FROM " . DB_PREFIX . "files f
                  WHERE f.trashed_at IS NULL";
            $params = [];

            if ($containerId !== null) {
                $sql .= " AND container_id = :cid";
                $params[":cid"] = $containerId;
            }
            if ($dirId !== null) {
                $sql .= " AND directory_id = :did";
                $params[":did"] = $dirId;
            } elseif ($containerId !== null) {
                $sql .= " AND directory_id IS NULL";
            }
            $sql .= " ORDER BY created_at DESC, id DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }

        $files = $stmt->fetchAll();
        return array_map(
            fn($f) => $this->decryptFileRow($f, $masterKey),
            $files
        );
    }

    // ── Rename ────────────────────────────────────────────────────────────────

    public function rename(
        int $fileId,
        string $masterKey,
        ?string $newName = null,
        ?string $description = null
    ): array {
        $file = $this->fetchRawFile($fileId);

        // fetchRawFile() also returns trashed rows. app/actions/file/rename.php
        // does not filter them, so this is the only check: a trashed file is
        // "not found", not a silent no-op.
        if ($file["trashed_at"] !== null) {
            json_response(["error" => __("file_not_found")], 404);
        }

        $encName = null;
        $encDesc = null;
        $desc = null;
        $newMime = null;
        $effectiveMime = null; // MIME in effect after this call, for the response

        $nameChanged = false;
        $finalName = null; // the name to write, once it differs
        $storedName = null; // the name the file has after this call

        $adjusted = "";
        if ($newName !== null) {
            // The reason comes from the cleaning itself, so a name that only
            // changed normalisation (NFD sent, NFC stored) gets none.
            [$name, $adjusted] = filename_clean($newName);

            // Browsers strip a leading dot from download names ("download.bin"
            // fallback). The UI blocks it too; this is the authoritative check.
            if (str_starts_with($name, ".")) {
                json_response(["error" => __("file_name_leading_dot")], 422);
            }

            $oldName = $this->decryptName($file, $masterKey);
            $currentMime = $this->decryptMimeField($file, $masterKey);

            // A text file follows a new extension that maps to another
            // editable MIME (.txt to .md). Anything else keeps the extension
            // and MIME it had, re-attached whatever is sent, as upload would
            // have: a rename cannot turn a .pdf into a .php, a .jpg into a
            // .txt, or a text file into a .pdf.
            $candidateMime = MimeRegistry::extToMime($name);
            if (
                MimeRegistry::isEditable($currentMime) &&
                $candidateMime !== null &&
                MimeRegistry::isEditable($candidateMime)
            ) {
                $newMime = $candidateMime;
            } else {
                // Extensions as upload reads them (splitKnownExt()): only a
                // listed one is taken off the name typed, so "Report
                // 2026.10.20" keeps its ".20" and gets the file's own.
                [, $origExt] = MimeRegistry::splitKnownExt($oldName);
                [$newBase] = MimeRegistry::splitKnownExt($name);
                // Cleaned again, as every name written is filename_clean()'s.
                $name = sanitize_filename(filename_fit_base($newBase, $origExt) . $origExt);
            }
            $effectiveMime = $newMime ?? $currentMime;
            // As upload stores it: the extension in lower case. Not when the
            // name comes back as it is (the dialog sends it with a
            // description edit): a file stored before keeps its spelling.
            if ($name !== $oldName) {
                $name = MimeRegistry::lowerKnownExt($name);
            }

            $nameChanged = $name !== $oldName;
            $storedName = $name;
            if ($nameChanged || $effectiveMime !== $currentMime) {
                $finalName = $name;
            } else {
                // Same name and type: nothing to write, check or log for it.
                $newMime = null;
            }
        }
        if ($finalName !== null) {
            // Checked against $finalName, not $newName as sent: the
            // sanitising and extension re-attach above can change it.
            // is_filename_unique() holds its GET_LOCK() through the write.
            if (
                !is_filename_unique(
                    $this->db,
                    $this->crypto,
                    $masterKey,
                    $finalName,
                    (int) $file["container_id"],
                    $file["directory_id"] !== null ? (int) $file["directory_id"] : null,
                    $fileId
                )
            ) {
                json_response(["error" => __("name_duplicate_file")], 409);
            }

            $encName = $this->crypto->encryptMeta($finalName, $masterKey, CryptoService::fileMetaAad("name", $file["storage_name"] ?? ""));
        }
        $description = changed_description(
            $this->crypto,
            $description !== null ? mb_substr($description, 0, DESCRIPTION_MAX) : null,
            $file,
            $masterKey,
            CryptoService::fileMetaAad("desc", $file["storage_name"] ?? "")
        );
        if ($description !== null) {
            $desc = $description;
            $encDesc =
                $desc !== ""
                    ? $this->crypto->encryptMeta($desc, $masterKey, CryptoService::fileMetaAad("desc", $file["storage_name"] ?? ""))
                    : null;
        }

        $sets = [];
        $params = [":id" => $fileId];

        if ($encName !== null) {
            $sets[] = "name_enc=:ne, name_iv=:niv, name_tag=:ntag";
            $params += [
                ":ne" => $encName["enc"],
                ":niv" => $encName["iv"],
                ":ntag" => $encName["tag"],
            ];
            if ($newMime !== null) {
                $encMime = $this->crypto->encryptMeta($newMime, $masterKey, CryptoService::fileMetaAad("mime", $file["storage_name"] ?? ""));
                $sets[] = "mime_enc=:menc, mime_iv=:meiv, mime_tag=:metag";
                $params += [
                    ":menc" => $encMime["enc"],
                    ":meiv" => $encMime["iv"],
                    ":metag" => $encMime["tag"],
                ];
            }
        }
        if ($description !== null) {
            $sets[] = "desc_enc=:de, desc_iv=:div, desc_tag=:dtag";
            $params += [
                ":de" => $encDesc ? $encDesc["enc"] : null,
                ":div" => $encDesc ? $encDesc["iv"] : null,
                ":dtag" => $encDesc ? $encDesc["tag"] : null,
            ];
        }

        // What the file is called now: a binary keeps its extension whatever
        // is sent, so the caller learns the name actually stored, and
        // "renamed" says whether it changed.
        $result = ["mime" => $effectiveMime, "name" => $storedName, "renamed" => $nameChanged, "adjusted" => $adjusted];
        if (empty($sets)) {
            return $result;
        }
        $sets[] = "updated_at=NOW()";

        // trashed_at filter is defensive; the check at the top is authoritative.
        $this->db
            ->prepare(
                "UPDATE " .
                    DB_PREFIX .
                    "files SET " .
                    implode(", ", $sets) .
                    " WHERE id = :id AND trashed_at IS NULL"
            )
            ->execute($params);

        $this->shares->syncFileMetadata($fileId, $masterKey, array_filter([
            "name" => $finalName,
            "desc" => $desc,
            "mime" => $newMime,
        ], fn($v) => $v !== null));

        log_activity(
            $this->db,
            $nameChanged && $description !== null
                ? "file.rename_update"
                : ($nameChanged
                    ? "file.rename"
                    : "file.update"),
            "file",
            $fileId,
            // $file is the pre-update row, so this is the OLD name (same AAD).
            $nameChanged
                ? activity_prev_name_enc(
                    $file,
                    CryptoService::fileMetaAad("name", $file["storage_name"])
                )
                : []
        );

        return $result;
    }

    // ── Move ──────────────────────────────────────────────────────────────────

    /**
     * Move a live file to another directory, refusing a name that is taken
     * there (409), as rename() does. Also drops the file from the directory
     * shares it leaves; adding it to the destination's is the caller's
     * ShareService::autoRefreshForDirectory().
     */
    public function move(int $fileId, int $containerId, int $dirId, string $masterKey): void
    {
        $file = $this->fetchRawFile($fileId);
        if ($file["trashed_at"] !== null) {
            json_response(["error" => __("file_not_found")], 404);
        }
        require_container($this->db, $containerId);
        // Files always live in a directory, never at container root.
        $this->assertDirectory($dirId, $containerId);

        // An unreadable name cannot collide with anything, so it skips the
        // check. is_filename_unique() holds its lock through the update.
        $currentName = $this->crypto->decryptColumn(
            $file,
            "name",
            $masterKey,
            CryptoService::fileMetaAad("name", $file["storage_name"])
        );
        if (
            $currentName !== "" &&
            !is_filename_unique($this->db, $this->crypto, $masterKey, $currentName, $containerId, $dirId, $fileId)
        ) {
            json_response(["error" => __("name_duplicate_file")], 409);
        }

        // The directory filter makes a move to where the file already is a
        // no-op, logged as nothing.
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "UPDATE " . DB_PREFIX . "files
                    SET container_id = :cid, directory_id = :did, updated_at = NOW()
                  WHERE id = :id AND trashed_at IS NULL
                    AND (directory_id IS NULL OR directory_id <> :did_cmp)"
            );
            $stmt->execute([
                ":cid" => $containerId,
                ":did" => $dirId,
                ":did_cmp" => $dirId,
                ":id" => $fileId,
            ]);
            $moved = $stmt->rowCount() === 1;
            if ($moved) {
                // Out of every directory share it is no longer in, in the
                // same commit: the owner's refresh afterwards is best-effort
                // and only adds the file to the destination's shares.
                $this->db
                    ->prepare(
                        "DELETE sfk FROM " . DB_PREFIX . "share_file_keys sfk
                           JOIN " . DB_PREFIX . "shares s ON s.id = sfk.share_id
                          WHERE sfk.file_id = :fid AND s.type = 'directory' AND s.target_id <> :did"
                    )
                    ->execute([":fid" => $fileId, ":did" => $dirId]);
                // Logged like a folder move (app/actions/directory/move.php),
                // with where it came from and where it went.
                $meta = activity_name_enc_as($this->db, "directory", $dirId, "to_dir_name")
                    + activity_name_enc_as($this->db, "container", $containerId, "to_container_name");
                if ($file["directory_id"] !== null) {
                    $meta += activity_name_enc_as($this->db, "directory", (int) $file["directory_id"], "from_dir_name");
                }
                if ($file["container_id"] !== null) {
                    $meta += activity_name_enc_as($this->db, "container", (int) $file["container_id"], "from_container_name");
                }
                log_activity($this->db, "file.move", "file", $fileId, $meta);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            db_rollback_if_open($this->db);
            throw $e;
        }
    }

    /**
     * Every live file with its name, MIME and description decrypted, newest
     * first and capped at $limit, for the dashboard's client-side search.
     */
    public function searchIndex(string $masterKey, int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, container_id, directory_id, storage_name,
                    name_enc, name_iv, name_tag,
                    mime_enc, mime_iv, mime_tag,
                    desc_enc, desc_iv, desc_tag,
                    size, created_at
               FROM " . DB_PREFIX . "files
              WHERE trashed_at IS NULL
              ORDER BY created_at DESC, id DESC
              LIMIT :limit"
        );
        $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn(array $f) => [
            "id" => (int) $f["id"],
            "container_id" => (int) $f["container_id"],
            "directory_id" => $f["directory_id"] !== null ? (int) $f["directory_id"] : null,
            "original_name" => $this->crypto->decryptColumn($f, "name", $masterKey, CryptoService::fileMetaAad("name", $f["storage_name"])),
            "mime" => $this->decryptMimeField($f, $masterKey),
            "description" => $this->crypto->decryptColumn($f, "desc", $masterKey, CryptoService::fileMetaAad("desc", $f["storage_name"])),
            "size" => (int) $f["size"],
            "size_human" => format_bytes((int) $f["size"]),
            "created_at" => $f["created_at"],
        ], $stmt->fetchAll());
    }

    public function getDirectoryId(int $fileId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT directory_id FROM " . DB_PREFIX . "files WHERE id = :id AND trashed_at IS NULL LIMIT 1"
        );
        $stmt->execute([":id" => $fileId]);
        $row = $stmt->fetch();
        return $row ? ($row["directory_id"] !== null ? (int) $row["directory_id"] : null) : null;
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /** Decrypt all metadata fields in a raw DB row and return a clean array. */
    private function decryptFileRow(array $f, string $masterKey): array
    {
        $c = $this->crypto;
        $sn = $f["storage_name"];
        $hashes = json_decode($c->decryptColumn($f, "hashes", $masterKey, CryptoService::fileMetaAad("hashes", $sn)), true);
        // Snapshots are set only on trashed rows; null, not "", when absent.
        $snapshot = fn(string $col, string $aad): ?string =>
            ($v = $c->decryptColumn($f, $col, $masterKey, $aad)) !== "" ? $v : null;

        return [
            "id" => (int) $f["id"],
            "container_id" =>
                $f["container_id"] !== null ? (int) $f["container_id"] : null,
            "directory_id" =>
                $f["directory_id"] !== null ? (int) $f["directory_id"] : null,
            "original_name" => $this->decryptName($f, $masterKey),
            "mime" => $this->decryptMimeField($f, $masterKey),
            "description" => $c->decryptColumn($f, "desc", $masterKey, CryptoService::fileMetaAad("desc", $sn)),
            // Omitted rather than an error when absent or unreadable, so the
            // row still renders without its checksum block.
            "hashes" => is_array($hashes) ? $hashes : null,
            "size" => (int) $f["size"],
            "size_human" => format_bytes((int) $f["size"]),
            "created_at" => $f["created_at"],
            "updated_at" => $f["updated_at"] ?? null,
            "trashed_at" => $f["trashed_at"] ?? null,
            "trashed_from_container_name" => $snapshot("trashed_from_container_name", CryptoService::snapshotAad("container", $f["id"])),
            "trashed_from_dir_name" => $snapshot("trashed_from_dir_name", CryptoService::snapshotAad("directory", $f["id"])),
            "is_shared" => !empty($f["share_active"] ?? 0),
            "share_count" => (int) ($f["share_active"] ?? 0),
            "download_count" => (int) ($f["download_count"] ?? 0),
            "preview_count" => (int) ($f["preview_count"] ?? 0),
        ];
    }

    /**
     * Checksums of plaintext content, as a plain assoc array; the caller
     * json_encodes and encrypts it. One definition, so upload() and
     * writeContent() cannot drift on which algorithms are computed.
     */
    private static function computeHashes(string $plaintext): array
    {
        return [
            "crc32" => str_pad(dechex(crc32($plaintext)), 8, "0", STR_PAD_LEFT),
            "md5" => md5($plaintext),
            "sha1" => sha1($plaintext),
            "sha256" => hash("sha256", $plaintext),
            "sha512" => hash("sha512", $plaintext),
        ];
    }

    /**
     * A file row's decrypted name, or the translated "unreadable" placeholder
     * when it does not authenticate. The row needs name_enc/_iv/_tag and storage_name.
     */
    public function decryptName(array $row, string $masterKey): string
    {
        return $this->crypto->decryptColumn($row, "name", $masterKey, CryptoService::fileMetaAad("name", $row["storage_name"] ?? ""), __("name_unreadable"));
    }

    private function decryptMimeField(array $row, string $masterKey): string
    {
        return $this->crypto->decryptColumn(
            $row,
            "mime",
            $masterKey,
            CryptoService::fileMetaAad("mime", $row["storage_name"] ?? ""),
            "application/octet-stream"
        );
    }

    /** A free "{blob}.{12 hex}.tmp" path beside $path (see writeContent()). */
    private function stagingPath(string $path): string
    {
        return $path . "." . bin2hex(random_bytes(6)) . ".tmp";
    }

    /**
     * Log why staging or swapping an edited blob failed, drop the stage file
     * and answer 500. The original blob is in place again at this point.
     */
    private function writeFailed(
        string $tmp,
        string $path,
        int|false $written,
        int $expected,
        ?Throwable $cause = null
    ): never {
        $err = $cause !== null
            ? get_class($cause) . ": " . $cause->getMessage()
            : (error_get_last()["message"] ?? "no PHP error captured");
        @unlink($tmp);
        error_log(sprintf(
            "[AESBin] writeContent failed: %s | written=%s expected=%d dir_writable=%s free_bytes=%s",
            $err,
            var_export($written, true),
            $expected,
            var_export(is_writable(dirname($path)), true),
            var_export(@disk_free_space(dirname($path)), true)
        ));
        json_response(["error" => __("server_error")], 500);
    }

    /**
     * Decrypt a whole blob in memory and send it. Not streamed: AES-GCM
     * authenticates only at the end, and no byte may leave before the tag
     * has been checked. This is what bounds file size by memory_limit (see
     * php_upload_limit_bytes()).
     */
    private function sendDecrypted(
        array $file,
        string $dek,
        string $name,
        string $mime,
        bool $inline
    ): void {
        // Otherwise a client disconnecting during the transfer kills the
        // script before the caller's recordFileAccess()/log_activity() run, and
        // the download goes uncounted. Covers the rest of the request.
        ignore_user_abort(true);

        $inTrash = $file["trashed_at"] !== null;
        $path = storage_path($file["storage_name"], $inTrash);

        if (!file_exists($path)) {
            json_response(
                ["error" => __("file_not_found")],
                404
            );
        }

        $ct = file_get_contents($path);
        if ($ct === false) {
            json_response(["error" => __("server_error")], 500);
        }

        try {
            $pt = $this->crypto->decryptFile(
                $ct,
                $dek,
                $file["aes_iv"],
                $file["aes_tag"],
                CryptoService::fileAad($file["storage_name"])
            );
        } catch (RuntimeException $e) {
            sodium_memzero($ct);
            error_log("[AESBin] download decrypt: " . $e->getMessage());
            json_response(["error" => __("decryption_failed")], 500);
        }
        sodium_memzero($ct);

        // An active type (HTML, SVG, XML…) rendered on this origin could script
        // the dashboard and read the session key. Navigation cannot reach this
        // (it cannot send X-Session-Key; previews use blobs), but it is enforced
        // anyway: only an ALLOWLIST of passive types goes inline, with an extra
        // "sandbox" CSP appended in case a browser renders it. Compared without
        // parameters, so "image/svg+xml; charset=utf-8" is not taken for image/*.
        $lowerMime = strtolower(trim(explode(";", $mime, 2)[0]));
        $inlineSafe = MimeRegistry::isPreviewable($lowerMime) && (
            (str_starts_with($lowerMime, "image/") && $lowerMime !== "image/svg+xml") ||
            str_starts_with($lowerMime, "video/") ||
            str_starts_with($lowerMime, "audio/") ||
            in_array($lowerMime, ["application/pdf", "text/plain"], true)
        );
        $disposition = $inline && $inlineSafe ? "inline" : "attachment";

        // RFC 6266 §5: ASCII "filename" first for agents without filename*
        // support, then "filename*" (RFC 5987, UTF-8) with the real name.
        // Non-ASCII bytes in the fallback become "_", and so do the quote and
        // backslash a quoted-string would read as syntax.
        $asciiName = (string) preg_replace('/[^\x20-\x7E]|["\\\\]/', "_", $name);
        $encodedName = rawurlencode($name);

        // PHP appends "; charset=" . default_charset to any text/* type sent
        // without one. The bytes are the file's own (Windows-1252, UTF-16…),
        // which the server does not know, so no charset is claimed.
        ini_set("default_charset", "");
        header("Content-Type: " . preg_replace('/[\r\n]/', '', $mime));
        header("Content-Length: " . strlen($pt));
        header(
            "Content-Disposition: {$disposition}; filename=\"{$asciiName}\"; filename*=UTF-8''{$encodedName}"
        );
        header("Cache-Control: no-store, no-cache, must-revalidate");
        header("X-Content-Type-Options: nosniff");
        header("Content-Security-Policy: sandbox", false);

        // Every level, not just the top one: a buffer left open would hold
        // part of the output back. A buffer PHP refuses to remove ends the
        // loop instead of spinning on it.
        while (ob_get_level() > 0 && ob_end_clean()) {
        }
        echo $pt;
        sodium_memzero($pt);
    }

    /**
     * A free filename in the directory: "photo.jpg", "photo_01.jpg" …
     * "photo_99.jpg", then a random hex suffix. For upload()/restore(); rename()
     * and move reject a clash instead. One lock, one decrypt pass; returns with
     * the lock STILL HELD to cover the caller's write.
     */
    private function uniqueFilename(
        string $name,
        int $containerId,
        ?int $dirId,
        string $masterKey
    ): string {
        // ".jpg" (".tar.gz" whole) and "photo". Suffixed candidates cut the
        // base to leave room, so none exceeds the name limits.
        [$base, $ext] = filename_split_ext($name);
        $short = filename_fit_base($base, $ext, 3);

        $candidates = [$name];
        for ($i = 1; $i <= 99; $i++) {
            $candidates[] =
                $short . "_" . str_pad((string) $i, 2, "0", STR_PAD_LEFT) . $ext;
        }

        $free = first_available_filename(
            $this->db,
            $this->crypto,
            $masterKey,
            $candidates,
            $containerId,
            $dirId
        );
        if ($free !== null) {
            return $free;
        }

        // Still under the lock taken above.
        return filename_fit_base($base, $ext, 7) . "_" . bin2hex(random_bytes(3)) . $ext;
    }

    private function validateUpload(array $file): void
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE  => __("upload_too_large_server"),
            UPLOAD_ERR_FORM_SIZE => __("upload_too_large_form"),
            UPLOAD_ERR_PARTIAL   => __("upload_incomplete"),
            UPLOAD_ERR_NO_FILE   => __("upload_no_file"),
        ];
        $err = $file["error"] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            json_response(
                ["error" => $errors[$err] ?? __("upload_error_generic")],
                400
            );
        }
        if (!is_uploaded_file($file["tmp_name"])) {
            json_response(
                ["error" => __("upload_security_failed")],
                400
            );
        }
        if (($file["size"] ?? 0) === 0) {
            json_response(["error" => __("upload_empty")], 400);
        }
    }

    /**
     * The file's MIME type from its bytes alone, as finfo sniffs them
     * (ext-fileinfo is a blocking requirement). The type the browser sends is
     * never used: it is client-chosen, and browsers derive it from the
     * extension, so a binary renamed to .txt arrives as text/plain (OWASP File
     * Upload Cheat Sheet; WordPress's wp_check_filetype_and_ext() refuses the
     * same mismatch). What finfo cannot name stays application/octet-stream,
     * which upload() refuses: UTF-16 without a byte-order mark, text holding
     * NUL bytes, unknown binaries.
     *
     * One exception, still decided by the content: finfo needs two bytes, and
     * calls every one-byte file octet-stream. A single byte that is a
     * printable character, a tab or a line break is text.
     */
    private function detectMime(string $path, string $name): string
    {
        // No finfo_close(): the object is freed on scope exit, and PHP 8.5
        // deprecates the call.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $sniffed = $finfo !== false ? finfo_file($finfo, $path) : false;
        // An SVG is one only with an <svg> root declaring a namespace
        // (without one no browser draws the file as an image), whatever
        // libmagic says (svgRoot()):
        //   - libmagic calls text that merely starts with "<svg" an SVG
        //     ("<svg> is the tag I mean…" in notes.txt): kept as text;
        //   - it calls an SVG that starts with a comment, a blank line or
        //     white space (a licence header, as icon sets have) text: one
        //     named .svg is an SVG, but no other text turns into an image.
        // Safe either way: an SVG previews as an image, where its scripts do
        // not run, and is never served inline.
        if ($sniffed === "image/svg+xml" && !self::svgRoot($path)) {
            return "text/plain";
        }
        if (
            strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) === "svg" &&
            in_array($sniffed, ["text/plain", "text/xml", "application/xml"], true) &&
            self::svgRoot($path)
        ) {
            return "image/svg+xml";
        }
        if ($sniffed === false || $sniffed === "application/octet-stream") {
            $head = (string) @file_get_contents($path, false, null, 0, 2);
            if (strlen($head) === 1 && preg_match('/^[\t\n\r\x20-\x7E\x80-\xFF]\z/', $head) === 1) {
                return "text/plain";
            }
            return "application/octet-stream";
        }
        return $sniffed;
    }

    /**
     * True if the file's root element, past BOM, XML declaration, comments
     * and DOCTYPE (an internal subset included: Illustrator's
     * <!DOCTYPE svg [ <!ENTITY ns_svg ...> ]>), is an <svg> (prefixed or
     * not) with an xmlns attribute. Any value counts, an entity such as
     * "&ns_svg;" included; attribute values are read whole, so a ">" inside
     * one does not end the tag.
     */
    private static function svgRoot(string $path): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 65536);
        $rest = (string) preg_replace(
            '/^(?:\xEF\xBB\xBF)?(?:\s+|<\?xml\b.*?\?>|<!--.*?-->|<!DOCTYPE\b[^\[>]*(?:\[.*?\])?\s*>)*/s',
            "",
            $head
        );
        return preg_match('/^<(?:[\w.-]+:)?svg\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*?\sxmlns(?::[\w.-]+)?\s*=/i', $rest) === 1;
    }

    private function assertDirectory(int $id, int $containerId): void
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM " .
                DB_PREFIX .
                "directories WHERE id = :id AND container_id = :cid LIMIT 1"
        );
        $stmt->execute([":id" => $id, ":cid" => $containerId]);
        if (!$stmt->fetch()) {
            json_response(["error" => __("directory_not_found")], 404);
        }
    }

    private function fetchRawFile(int $fileId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM " . DB_PREFIX . "files WHERE id = :id LIMIT 1"
        );
        $stmt->execute([":id" => $fileId]);
        $row = $stmt->fetch();
        if (!$row) {
            json_response(["error" => __("file_not_found")], 404);
        }
        return $row;
    }

    /**
     * Id of the row in $table whose decrypted name matches $name
     * (case-insensitive), or null. Keeps the scope's name lock, so two
     * concurrent restores cannot create the same folder twice.
     *
     * @param "container"|"directory" $type
     */
    private function findByName(string $type, string $name, string $masterKey, ?int $containerId = null): ?int
    {
        $table = $type === "container" ? "containers" : "directories";
        name_scope_lock($this->db, $table, $containerId !== null ? ["container_id" => $containerId] : []);
        $sql = "SELECT id, name_enc, name_iv, name_tag FROM " . DB_PREFIX . $table;
        $params = [];
        if ($containerId !== null) {
            $sql .= " WHERE container_id = :cid";
            $params[":cid"] = $containerId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $wanted = mb_strtolower($name);
        foreach ($stmt->fetchAll() as $row) {
            $existing = $this->crypto->decryptColumn($row, "name", $masterKey, CryptoService::rowAad($type, $row["id"]));
            if ($existing !== "" && mb_strtolower($existing) === $wanted) {
                return (int) $row["id"];
            }
        }
        return null;
    }

    /**
     * The container a restored file goes back into: the one whose name matches
     * the snapshot taken at trash time, recreated under that name if it is
     * gone. Resolved by NAME, never by the stale id: a container renamed or
     * recreated since would otherwise receive files that never belonged to it.
     * Without a readable snapshot, the first container, or a new
     * "restored files" one when there is none.
     *
     * @return array{0: int, 1: bool} [container id, whether it was created]
     */
    private function findOrCreateContainerFromSnapshot(
        array $file,
        string $masterKey
    ): array {
        $snapshotName = $this->crypto->decryptColumn(
            $file,
            "trashed_from_container_name",
            $masterKey,
            CryptoService::snapshotAad("container", $file["id"])
        );
        if ($snapshotName !== "") {
            $found = $this->findByName("container", $snapshotName, $masterKey);
            return $found !== null
                ? [$found, false]
                : [insert_named_row($this->db, $this->crypto, $masterKey, "container", $snapshotName), true];
        }

        $first = $this->db
            ->query("SELECT id FROM " . DB_PREFIX . "containers ORDER BY id ASC LIMIT 1")
            ->fetchColumn();
        return $first !== false
            ? [(int) $first, false]
            : [insert_named_row($this->db, $this->crypto, $masterKey, "container", __("restored_files_label")), true];
    }

    /**
     * The directory a restored file goes back into, by snapshot name, or a
     * "restored files" directory when the snapshot is missing or unreadable.
     *
     * Never null: upload() and move() forbid a file outside a directory, and
     * one would drop out of the listings and skip restore.php's
     * autoRefreshForDirectory().
     *
     * @return array{0: int, 1: bool} [directory id, whether it was created]
     */
    private function restoreDirectory(
        array $file,
        int $cid,
        string $masterKey
    ): array {
        $dirName = $this->crypto->decryptColumn(
            $file,
            "trashed_from_dir_name",
            $masterKey,
            CryptoService::snapshotAad("directory", $file["id"])
        );
        if ($dirName === "") {
            $dirName = __("restored_files_label");
        }
        $found = $this->findByName("directory", $dirName, $masterKey, $cid);
        return $found !== null
            ? [$found, false]
            : [insert_named_row($this->db, $this->crypto, $masterKey, "directory", $dirName, [
                "container_id" => $cid,
            ]), true];
    }
}
