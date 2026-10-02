<?php
declare(strict_types=1);

/**
 * Database connection (PDO).
 * Connects as the least-privilege account created in database/mutuallink.sql,
 * never as root. Credentials live in config/secrets.php (gitignored —
 * copy config/secrets.example.php to create it).
 */

define('DB_HOST', 'localhost');
define('DB_PORT', (int) (getenv('ML_DB_PORT') ?: 3306));
define('DB_NAME', 'mutuallink');

if (!is_file(__DIR__ . '/secrets.php')) {
    error_log('MutualLink: config/secrets.php is missing. Copy config/secrets.example.php to config/secrets.php and set DB_USER / DB_PASS.');
    http_response_code(500);
    exit('The system is not configured yet: config/secrets.php is missing. Copy config/secrets.example.php and set the database credentials (see README §1).');
}
require __DIR__ . '/secrets.php';

if (!defined('DB_USER') || !defined('DB_PASS')) {
    error_log('MutualLink: config/secrets.php must define DB_USER and DB_PASS.');
    http_response_code(500);
    exit('config/secrets.php is incomplete: it must define DB_USER and DB_PASS (see README §1).');
}

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
