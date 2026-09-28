<?php
declare(strict_types=1);

/**
 * Loaded first by every entry point (index, dashboard, logout, api/*).
 */

date_default_timezone_set('Asia/Manila');
ini_set('display_errors', '0'); // errors go to the log, never to the browser
ini_set('log_errors', '1');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/loan_calc.php';

start_secure_session();
