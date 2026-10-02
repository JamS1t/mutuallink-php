<?php
declare(strict_types=1);

/**
 * TEMPLATE — copy this file to config/secrets.php and edit the copy.
 * config/secrets.php holds the real credentials and is GITIGNORED; it is
 * required by config/db.php and must never be committed.
 *
 * The password here must match the mutuallink_app account created by
 * database/mutuallink.sql. CHANGE it at install:
 *   ALTER USER 'mutuallink_app'@'localhost' IDENTIFIED BY 'your-new-password';
 * (The placeholder below — like every password shipped in git history —
 * must be treated as compromised; always rotate at install.)
 */

if (!defined('DB_USER')) {
    define('DB_USER', 'mutuallink_app');
    define('DB_PASS', 'ChangeThis_App_Pass_2026');
}
