<?php
declare(strict_types=1);

/**
 * POST api/keepalive.php
 * → 200 {"success":true,"data":{"ok":true,"idle_seconds":900},"errors":[]}
 *
 * Refreshes the session's activity timestamp (require_login → session_is_valid
 * touches last_activity) so a user who is actively working is not logged out
 * mid-form. Called by the idle-warning modal "Stay logged in" button.
 * An expired session answers 401 and the front end returns to the login page.
 */

require_once __DIR__ . '/_bootstrap.php';
api_bootstrap('POST', 'dashboard', 'view'); // every signed-in role may keep its own session alive

json_out(200, ['ok' => true, 'idle_seconds' => SESSION_IDLE_SECONDS]);
