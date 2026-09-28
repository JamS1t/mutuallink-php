<?php
declare(strict_types=1);

/**
 * Logout: POST + CSRF only, so another site cannot log users out with a link.
 */

require_once __DIR__ . '/config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid()) {
    redirect('dashboard.php');
}

if (!empty($_SESSION['user_id'])) {
    audit_log('logout', 'users', current_user_id(), 'Signed out');
}
logout_user();

start_secure_session(); // fresh, empty session just to carry the goodbye message
flash('success', 'You have been logged out.');
redirect('index.php');
