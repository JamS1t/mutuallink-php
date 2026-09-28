<?php
declare(strict_types=1);

/**
 * Database connection (PDO).
 * Connects as the least-privilege account created in database/mutuallink.sql,
 * never as root.
 */

define('DB_HOST', 'localhost');
define('DB_PORT', (int) (getenv('ML_DB_PORT') ?: 3306));
define('DB_NAME', 'mutuallink');
define('DB_USER', 'mutuallink_app');
define('DB_PASS', 'Ml!nk_App_2026');

/**
 * Returns one shared PDO connection per request.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // DSN with utf8mb4 so 4-byte characters are never truncated.
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // strict error reporting
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // rows as associative arrays
        PDO::ATTR_EMULATE_PREPARES   => false,                  // native prepared statements
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Log the real reason; never show connection details to the browser.
        error_log('MutualLink DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('The system cannot reach the database right now. Please contact the administrator.');
    }

    return $pdo;
}
