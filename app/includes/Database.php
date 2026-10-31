<?php

declare(strict_types=1);

namespace AESBin;

use PDO;

defined("AESBIN_APP") || exit;

/**
 * app/includes/Database.php — PDO singleton
 *
 * bootstrap.php calls Database::connect() once and stores the result in the
 * global $db, which action files use directly. A second connect() returns the
 * same PDO.
 */

class Database
{
    private static ?PDO $instance = null;

    /**
     * Create the PDO connection.
     *
     * @param array $cfg  DB config array from config.php
     */
    public static function connect(array $cfg): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $dsn = sprintf(
            "mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
            $cfg["host"] ?? "localhost",
            $cfg["port"] ?? 3306,
            $cfg["name"]
        );

        self::$instance = new PDO($dsn, $cfg["user"], $cfg["pass"], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        self::prepareSession(self::$instance);

        return self::$instance;
    }

    /**
     * Session settings every connection needs: this one and the installer's
     * (setup_pdo()), which writes rows with the same defaults.
     */
    public static function prepareSession(PDO $pdo): void
    {
        // UTC whatever the server's timezone: NOW()/CURRENT_TIMESTAMP must
        // agree with PHP's gmdate()/time(). Code parsing DATETIME values
        // appends " UTC" on the strength of this.
        $pdo->exec("SET time_zone = '+00:00'");

        // STRICT_TRANS_TABLES, for hosts that turn off this MySQL 5.7+ /
        // MariaDB 10.2.4+ default. Without it an oversized value is truncated
        // silently, and a truncated AES-GCM ciphertext in an _enc column never
        // authenticates again: the data is lost.
        //
        // Appended, so the server's other modes survive. Safe here: no GROUP
        // BY queries, no zero-date inserts, every NOT NULL column supplied.
        $pdo->exec(
            "SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@sql_mode, ''), 'STRICT_TRANS_TABLES')"
        );
    }
}
