<?php

declare(strict_types=1);

namespace AESBin;

use InvalidArgumentException;
use RuntimeException;

defined("AESBIN_APP") || exit;

/**
 * app/includes/CryptoService.php — AESBin cryptographic operations
 *
 * Key hierarchy:
 *   password → Argon2id(kek_salt) → KEK  (RAM only, never stored)
 *   KEK wraps MASTER_KEY               → wrapped_master (DB)
 *   10-words → Argon2id(recovery_kek_salt) → recovery KEK
 *   recovery KEK wraps MASTER_KEY      → recovery_wrapped_master (DB)
 *   passkey PRF output wraps MASTER_KEY, in the browser (passkey.js)
 *                                      → webauthn_data credentials (DB)
 *   MASTER_KEY wraps per-file DEK      → enc_key (DB, per file)
 *   DEK encrypts file content          → .bin blob (disk)
 *
 * Owner metadata is encrypted with MASTER_KEY via encryptMeta()/decryptMeta(),
 * stored as _enc / _iv / _tag triplets:
 *   TOTP secret         → totp_secret_*  (auth)
 *   names               → name_*         (containers, directories, files)
 *   descriptions        → desc_*         (directories, files; nullable)
 *   file mime, checksums → mime_*, hashes_* (files)
 *
 * Every encrypt/decrypt method takes the AAD as a required argument, with no
 * default, so no call site can drop the binding to its record by omission.
 *
 * Requirements: PHP 8.3+, ext-sodium, ext-openssl
 */

class CryptoService
{
    // AES-256-GCM parameters
    private const KEY_BYTES = 32; // 256-bit key
    private const IV_BYTES = 12; // 96-bit GCM IV (standard)
    private const TAG_BYTES = 16; // 128-bit GCM auth tag
    private const SALT_BYTES = 16; // 128-bit Argon2id salt

    // Argon2id parameters, 64 MiB and t=3: above OWASP's minimums, same
    // memory/time as RFC 9106's memory-constrained profile (libsodium uses p=1).
    private const A2_MEM = 67108864; // 64 MiB
    private const A2_OPS = 3;
    // The algorithm is named at the call, not in a constant here: a class
    // constant naming SODIUM_* makes `new CryptoService()` itself fatal without
    // ext-sodium, and the installer builds one before it can show the
    // requirements screen that reports the missing extension.

    /**
     * Cost of every stored Argon2id verifier (password, recovery phrase, share
     * passwords), pinned rather than left to PHP's defaults, which may change.
     * Equal to the key derivation's: a cheaper verifier would be the weakest
     * target, a dearer one protects nothing since the wrapped MASTER_KEY already
     * confirms a guess. memory_cost is in KiB.
     */
    private const PASSWORD_HASH_OPTIONS = [
        "memory_cost" => self::A2_MEM >> 10,
        "time_cost" => self::A2_OPS,
        "threads" => 1,
    ];

    /**
     * Argon2id verifier for the password, the recovery phrase or a share
     * password, at PASSWORD_HASH_OPTIONS. Every stored verifier is made here.
     */
    public static function hashPassword(string $secret): string
    {
        return password_hash($secret, PASSWORD_ARGON2ID, self::PASSWORD_HASH_OPTIONS);
    }

    /** True when $hash was made with other parameters than hashPassword() uses now. */
    public static function passwordNeedsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::PASSWORD_HASH_OPTIONS);
    }

    /**
     * Minimum master password length, in code points (mb_strlen()). Enforced
     * by the installer and the recovery reset; helper.js mirrors it.
     */
    public const PASSWORD_MIN_LENGTH = 12;

    // RFC 4648 base32 alphabet, the single copy shared by
    // generateTotpSecret() and base32Decode().
    private const BASE32_ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";

    // ── Key generation ────────────────────────────────────────────────────────

    /** Generate a random 256-bit MASTER_KEY. Called only during setup. */
    public function generateMasterKey(): string
    {
        return random_bytes(self::KEY_BYTES);
    }

    /** Generate a random 256-bit DEK. One per uploaded file. */
    public function generateDek(): string
    {
        return random_bytes(self::KEY_BYTES);
    }

    /** Generate a random 128-bit Argon2id salt. Store in DB; not secret. */
    public function generateSalt(): string
    {
        return random_bytes(self::SALT_BYTES);
    }

    /** Generate a cryptographically random hex token. */
    public function generateToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Generate a random TOTP secret (base32-encoded, 20 bytes = 160 bits).
     * RFC 6238 defaults (SHA-1, 6 digits, 30 s), which every authenticator
     * app reads, Stratum included.
     */
    public function generateTotpSecret(): string
    {
        $alphabet = self::BASE32_ALPHABET;
        $bytes = random_bytes(20);
        $secret = "";

        // 20 bytes are four whole 5-byte groups, 8 characters each: no padding.
        for ($i = 0; $i < 20; $i += 5) {
            $chunk = array_map("ord", str_split(substr($bytes, $i, 5)));
            $secret .= $alphabet[($chunk[0] >> 3) & 31];
            $secret .=
                $alphabet[(($chunk[0] & 7) << 2) | (($chunk[1] >> 6) & 3)];
            $secret .= $alphabet[($chunk[1] >> 1) & 31];
            $secret .=
                $alphabet[(($chunk[1] & 1) << 4) | (($chunk[2] >> 4) & 15)];
            $secret .=
                $alphabet[(($chunk[2] & 15) << 1) | (($chunk[3] >> 7) & 1)];
            $secret .= $alphabet[($chunk[3] >> 2) & 31];
            $secret .=
                $alphabet[(($chunk[3] & 3) << 3) | (($chunk[4] >> 5) & 7)];
            $secret .= $alphabet[$chunk[4] & 31];
        }

        return $secret; // 32 base32 characters = 160 bits
    }

    /**
     * Generate a 10-word recovery phrase from the 2048-word BIP-39 English
     * list (app/includes/wordlist.php): 110 bits. Always minted server-side and
     * never accepted from a client, so that entropy is guaranteed.
     */
    public function generateRecoveryPhrase(): string
    {
        $words = require __DIR__ . "/wordlist.php";
        $last = count($words) - 1;
        $phrase = [];
        for ($i = 0; $i < 10; $i++) {
            $phrase[] = $words[random_int(0, $last)];
        }
        return implode(" ", $phrase);
    }

    /**
     * The canonical form of a typed recovery phrase: lower case, one space
     * between words. The phrase is entered in a textarea, where a line break,
     * a doubled space or a phone keyboard's automatic capital would otherwise
     * make the right phrase fail. generateRecoveryPhrase() already produces
     * this form. Every comparison and KEK derivation of a phrase goes
     * through here.
     */
    public static function normalizeRecoveryPhrase(string $phrase): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', " ", $phrase)));
    }

    /**
     * Build the otpauth:// URI for the QR code from the raw base32 secret:
     * at setup, on a TOTP reset and when the owner reveals the secret.
     */
    public function totpUri(
        string $secret,
        string $issuer,
        string $account
    ): string {
        return sprintf(
            "otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30",
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer)
        );
    }

    // ── KEK derivation ────────────────────────────────────────────────────────

    /**
     * Derive a Key Encryption Key (KEK) from a password or phrase using Argon2id.
     *
     * @param string $password  Raw password or 10-word recovery phrase
     * @param string $salt      16-byte binary salt from DB (not secret)
     * @return string           32-byte binary KEK. NEVER store this
     */
    public function deriveKek(string $password, string $salt): string
    {
        if (strlen($salt) !== self::SALT_BYTES) {
            throw new InvalidArgumentException(
                "KEK salt must be exactly 16 bytes."
            );
        }

        $kek = sodium_crypto_pwhash(
            self::KEY_BYTES,
            $password,
            $salt,
            self::A2_OPS,
            self::A2_MEM,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
        // sodium_crypto_pwhash() throws on failure (out of memory included);
        // it never returns false.

        return $kek;
    }

    // ── MASTER_KEY wrap / unwrap ──────────────────────────────────────────────

    /** @return array{enc:string, iv:string, tag:string}  All base64 */
    public function wrapMasterKey(string $masterKey, string $kek): array
    {
        return $this->gcmEncrypt($masterKey, $kek, self::AAD_MASTER_KEY);
    }

    /** @throws RuntimeException on wrong key or tampered data */
    public function unwrapMasterKey(
        string $enc,
        string $iv,
        string $tag,
        string $kek
    ): string {
        return $this->gcmDecrypt($enc, $iv, $tag, $kek, self::AAD_MASTER_KEY);
    }

    // ── Associated data (AAD) ─────────────────────────────────────────────────
    //
    // Every ciphertext is bound to the record it belongs to, and every call
    // site builds that binding here, never by hand. These strings are part of
    // the stored format: changing one makes everything sealed under it
    // undecryptable, with no upgrade path.

    /** The MASTER_KEY wrapped by a password or recovery-phrase KEK. */
    public const AAD_MASTER_KEY = "masterkey:1";

    /** The TOTP secret, sealed with the MASTER_KEY. */
    public const AAD_TOTP = "totp:1";

    /**
     * The MASTER_KEY wrapped with a tab's session key in $_SESSION. Lives
     * only as long as the session, so changing it just ends open sessions.
     */
    public const AAD_SESSION_KEY = "session:1";

    /** A wrapped DEK, bound to its file. */
    public static function dekAad(string $storageName): string
    {
        return "dek:" . $storageName;
    }

    /** A file body, bound to its blob on disk. */
    public static function fileAad(string $storageName): string
    {
        return "file:" . $storageName;
    }

    /**
     * A file's metadata column ("name", "mime", "desc" or "hashes"), bound to
     * the file by storage name rather than row id, so it survives a
     * renumbering re-import. Share copies use the same AAD under their own key.
     */
    public static function fileMetaAad(string $column, string $storageName): string
    {
        return $column . ":" . $storageName;
    }

    /**
     * A container or directory name, bound to its row. The type is part of
     * it, so a name cannot be moved between the two tables.
     *
     * @param "container"|"directory" $type
     */
    public static function rowAad(string $type, int|string $id): string
    {
        return $type . ":" . $id;
    }

    /** A directory's description, bound to its row like its name. */
    public static function directoryDescAad(int|string $id): string
    {
        return "directory_desc:" . $id;
    }

    /**
     * The container or directory name a trashed file remembers, for restore.
     *
     * @param "container"|"directory" $type
     */
    public static function snapshotAad(string $type, int|string $fileId): string
    {
        return $type . "_snap:" . $fileId;
    }

    /**
     * A share's raw KEY. shares.key_enc is wrapped with wrapDek() but is not
     * a file DEK, so it has its own prefix.
     */
    public static function shareKeyAad(string $shareToken): string
    {
        return "sharekey:" . $shareToken;
    }

    /**
     * A share's visitor-facing label, sealed under a key derived from the
     * share KEY and bound to the share token.
     *
     * @param "target_name"|"target_desc" $column
     */
    public static function shareLabelAad(string $column, string $shareToken): string
    {
        return match ($column) {
            "target_name" => "sharename:",
            "target_desc" => "sharedir_desc:",
        } . $shareToken;
    }

    /**
     * A share KEY wrapped for one visitor session (share_sessions), bound to
     * its share so a session row cannot be moved onto another one.
     */
    public static function shareSessionAad(int $shareId): string
    {
        return "sharesess:" . $shareId;
    }

    // ── DEK wrap / unwrap (with MASTER_KEY) ───────────────────────────────────

    /** @return array{enc:string, iv:string, tag:string} */
    public function wrapDek(string $dek, string $masterKey, string $aad): array
    {
        return $this->gcmEncrypt($dek, $masterKey, $aad);
    }

    /** @throws RuntimeException on wrong key or corrupted data */
    public function unwrapDek(
        string $enc,
        string $iv,
        string $tag,
        string $masterKey,
        string $aad
    ): string {
        return $this->gcmDecrypt($enc, $iv, $tag, $masterKey, $aad);
    }

    // ── Metadata encrypt / decrypt (with MASTER_KEY) ──────────────────────────

    /**
     * Encrypt a metadata string (name, description, mime…) with MASTER_KEY.
     *
     * @param string $plaintext  The metadata value to protect
     * @param string $masterKey  From session
     * @param string $aad        Context binding from the AAD builders above; prevents
     *                           ciphertext from being moved to a different record
     * @return array{enc:string, iv:string, tag:string}  All base64
     */
    public function encryptMeta(string $plaintext, string $masterKey, string $aad): array
    {
        return $this->gcmEncrypt($plaintext, $masterKey, $aad);
    }

    /**
     * Decrypt a metadata string produced by encryptMeta().
     *
     * @param string $aad  Must match the value used during encryption exactly
     * @throws RuntimeException  On wrong key, tampered data, or AAD mismatch
     */
    public function decryptMeta(
        string $enc,
        string $iv,
        string $tag,
        string $masterKey,
        string $aad
    ): string {
        return $this->gcmDecrypt($enc, $iv, $tag, $masterKey, $aad);
    }

    /**
     * decryptMeta() for display paths, where one unreadable value must not
     * fail the whole response: $fallback when the triplet is absent or does
     * not authenticate.
     */
    public function decryptMetaOr(
        string $fallback,
        ?string $enc,
        ?string $iv,
        ?string $tag,
        string $key,
        string $aad
    ): string {
        if (!$enc || !$iv || !$tag) {
            return $fallback;
        }
        try {
            return $this->gcmDecrypt($enc, $iv, $tag, $key, $aad);
        } catch (RuntimeException) {
            return $fallback;
        }
    }

    /**
     * decryptMetaOr() for the name_enc/_iv/_tag-style column triplet
     * "{$col}_enc", "{$col}_iv", "{$col}_tag" of a database row.
     */
    public function decryptColumn(
        array $row,
        string $col,
        string $key,
        string $aad,
        string $fallback = ""
    ): string {
        return $this->decryptMetaOr(
            $fallback,
            $row["{$col}_enc"] ?? null,
            $row["{$col}_iv"] ?? null,
            $row["{$col}_tag"] ?? null,
            $key,
            $aad
        );
    }

    /**
     * encryptMeta() for a value kept in a single column: one base64 string,
     * IV ‖ tag ‖ ciphertext (share_sessions.share_key_enc).
     */
    public function encryptPacked(string $plaintext, string $key, string $aad): string
    {
        [$ct, $iv, $tag] = $this->gcmSeal($plaintext, $key, $aad);
        return base64_encode($iv . $tag . $ct);
    }

    /**
     * Decrypt a value produced by encryptPacked().
     *
     * @throws RuntimeException on malformed input, wrong key, tampered data or AAD mismatch
     */
    public function decryptPacked(string $packed, string $key, string $aad): string
    {
        $raw = base64_decode($packed, true);
        if (!is_string($raw) || strlen($raw) <= self::IV_BYTES + self::TAG_BYTES) {
            throw new RuntimeException("Malformed packed AES-GCM value.");
        }
        return $this->gcmOpen(
            substr($raw, self::IV_BYTES + self::TAG_BYTES),
            substr($raw, 0, self::IV_BYTES),
            substr($raw, self::IV_BYTES, self::TAG_BYTES),
            $key,
            $aad
        );
    }

    // ── File content encrypt / decrypt ────────────────────────────────────────

    /**
     * Encrypt a file body with its DEK. $aad: build it with self::fileAad().
     *
     * @return array{ciphertext:string, iv:string, tag:string}  ciphertext raw, iv/tag base64
     */
    public function encryptFile(string $plaintext, string $dek, string $aad): array
    {
        [$ct, $iv, $tag] = $this->gcmSeal($plaintext, $dek, $aad);
        return [
            "ciphertext" => $ct,
            "iv" => base64_encode($iv),
            "tag" => base64_encode($tag),
        ];
    }

    /**
     * Decrypt a file blob with a DEK.
     *
     * @param string $aad  Must match the value used during encryption exactly;
     *                     build it with self::fileAad().
     * @throws RuntimeException   On wrong key or corrupted/tampered file
     */
    public function decryptFile(
        string $ciphertext,
        string $dek,
        string $iv,
        string $tag,
        string $aad
    ): string {
        [$ivRaw, $tagRaw] = $this->decodeGcmParts($iv, $tag);
        return $this->gcmOpen($ciphertext, $ivRaw, $tagRaw, $dek, $aad);
    }

    // ── Share key derivation ──────────────────────────────────────────────────

    /**
     * Subkey from a share's raw key and a random salt (one per file and for the
     * target name/description). Keyed BLAKE2b, not Argon2id: the KEY is 128
     * random bits, so stretching adds nothing and would slow large rewraps.
     * Part of the stored format: changing it makes every share undecryptable.
     */
    public function deriveShareKey(string $shareKey, string $salt): string
    {
        // sodium_crypto_generichash() throws SodiumException (not a
        // RuntimeException) for a key outside 16..64 bytes, which would slip
        // past the share flow's catch blocks as a 500. ShareService validates
        // too, but this method keeps the RuntimeException contract.
        $len = strlen($shareKey);
        if (
            $len < SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN ||
            $len > SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MAX
        ) {
            throw new RuntimeException("Share key has an invalid length.");
        }
        if (strlen($salt) === 0) {
            throw new RuntimeException("Share key salt is empty.");
        }
        return sodium_crypto_generichash($salt, $shareKey, self::KEY_BYTES);
    }

    /**
     * Key wrapping a share KEY in share_sessions.share_key_enc, from the raw
     * session token. HKDF, not sha256(token): that is access_token_hash in the
     * same row. Changing the info string only ends live visitor sessions.
     */
    public function deriveShareSessionKey(string $rawSessionToken): string
    {
        return hash_hkdf("sha256", $rawSessionToken, self::KEY_BYTES, "aesbin:share-session-key:v1");
    }

    // ── TOTP verification ─────────────────────────────────────────────────────

    /**
     * Verify a TOTP code against a decrypted Base32 secret (RFC 6238).
     * Returns the matched time step (floor(unix_time/30)) so the caller can
     * enforce replay protection.
     *
     * @param string $base32Secret  The raw Base32-encoded TOTP secret
     * @param string $code          The 6-digit code submitted by the user
     * @return int|false  Matched step value, or false on failure
     */
    public function verifyTotp(string $base32Secret, string $code): int|false
    {
        if (preg_match('/^\d{6}\z/', $code) !== 1) {
            return false;
        }
        $secret = $this->base32Decode($base32Secret);
        if ($secret === "") {
            return false;
        }
        $time = (int) floor(time() / 30);

        // ±1 step (90 s window, 3 valid codes), per RFC 6238 §5.2. Enough for
        // ordinary phone clock drift.
        foreach ([-1, 0, 1] as $offset) {
            $step = $time + $offset;
            $counter = pack("NN", 0, $step); // 64-bit big-endian counter
            $hash = hash_hmac("sha1", $counter, $secret, true);
            $o = ord($hash[19]) & 0x0f;
            $otp =
                (((ord($hash[$o]) & 0x7f) << 24) |
                    ((ord($hash[$o + 1]) & 0xff) << 16) |
                    ((ord($hash[$o + 2]) & 0xff) << 8) |
                    (ord($hash[$o + 3]) & 0xff)) %
                1_000_000;

            if (
                hash_equals($code, str_pad((string) $otp, 6, "0", STR_PAD_LEFT))
            ) {
                return $step;
            }
        }

        return false;
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    /** SHA-256 hex digest of a raw token. Store this in DB, not the raw token. */
    public function hashToken(string $token): string
    {
        return hash("sha256", $token);
    }

    // ── Internal AES-256-GCM ──────────────────────────────────────────────────

    /**
     * AES-256-GCM with a fresh random IV.
     *
     * @return array{0: string, 1: string, 2: string} raw ciphertext, IV and tag
     */
    private function gcmSeal(string $plaintext, string $key, string $aad): array
    {
        $iv = random_bytes(self::IV_BYTES);
        $ct = openssl_encrypt(
            $plaintext,
            "aes-256-gcm",
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            self::TAG_BYTES
        );
        if ($ct === false) {
            throw new RuntimeException("AES-GCM encryption failed.");
        }
        return [$ct, $iv, $tag];
    }

    /** @throws RuntimeException on tag mismatch (wrong key, tampered data, or AAD mismatch) */
    private function gcmOpen(string $ct, string $iv, string $tag, string $key, string $aad): string
    {
        $pt = openssl_decrypt($ct, "aes-256-gcm", $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($pt === false) {
            throw new RuntimeException(
                "AES-GCM decryption failed: wrong key or tampered data."
            );
        }
        return $pt;
    }

    /** @return array{enc:string, iv:string, tag:string}  All base64 */
    private function gcmEncrypt(string $plaintext, string $key, string $aad): array
    {
        [$ct, $iv, $tag] = $this->gcmSeal($plaintext, $key, $aad);
        return [
            "enc" => base64_encode($ct),
            "iv" => base64_encode($iv),
            "tag" => base64_encode($tag),
        ];
    }

    /**
     * Decode a stored IV and tag, refusing any other length than this class
     * writes: GCM would accept a short tag (easier forgery), and malformed
     * base64 would be a TypeError. Both become a RuntimeException.
     *
     * @return array{0: string, 1: string} raw IV and raw tag
     */
    private function decodeGcmParts(string $iv, string $tag): array
    {
        $ivRaw = base64_decode($iv, true);
        $tagRaw = base64_decode($tag, true);
        if (
            !is_string($ivRaw) ||
            strlen($ivRaw) !== self::IV_BYTES ||
            !is_string($tagRaw) ||
            strlen($tagRaw) !== self::TAG_BYTES
        ) {
            throw new RuntimeException("Malformed AES-GCM parameters.");
        }
        return [$ivRaw, $tagRaw];
    }

    /** @throws RuntimeException on tag mismatch (wrong key, tampered data, or AAD mismatch) */
    private function gcmDecrypt(
        string $enc,
        string $iv,
        string $tag,
        string $key,
        string $aad
    ): string {
        [$ivRaw, $tagRaw] = $this->decodeGcmParts($iv, $tag);
        $encRaw = base64_decode($enc, true);
        if (!is_string($encRaw)) {
            throw new RuntimeException("Malformed AES-GCM ciphertext.");
        }
        return $this->gcmOpen($encRaw, $ivRaw, $tagRaw, $key, $aad);
    }

    /** Decode a base32 string to binary (RFC 4648). */
    private function base32Decode(string $input): string
    {
        $alphabet = self::BASE32_ALPHABET;
        $input = strtoupper(rtrim($input, "="));
        $bits = "";

        foreach (str_split($input) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                // (int) false is 0, which would decode an invalid character as
                // "A". verifyTotp() treats "" as a failed verification.
                return "";
            }
            $bits .= str_pad(decbin($pos), 5, "0", STR_PAD_LEFT);
        }

        $output = "";
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr(bindec($byte));
            }
        }

        return $output;
    }
}
