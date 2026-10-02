<?php
declare(strict_types=1);

/**
 * Role-Based Access Control matrix and route guards (middleware functions).
 *
 * One matrix drives three things: the route guards, the sidebar menu,
 * and which action buttons are rendered. The UI can never offer an action
 * the server would refuse.
 */

const ROLES = [
    'manager'      => 'Manager',
    'cashier'      => 'Cashier',
    'loan_officer' => 'Loan Officer',
    'bookkeeper'   => 'Bookkeeper',
    'auditor'      => 'Board / Auditor',
];

const ALL_ROLES = ['manager', 'cashier', 'loan_officer', 'bookkeeper', 'auditor'];

// module => action => roles allowed
const RBAC = [
    'dashboard'     => ['view'   => ALL_ROLES],
    'profile'       => ['update' => ALL_ROLES],
    'users'         => ['view' => ['manager'], 'create' => ['manager'], 'update' => ['manager'], 'delete' => ['manager']],
    'members'       => ['view' => ALL_ROLES,
                        'create' => ['manager', 'cashier'],
                        'update' => ['manager', 'cashier', 'loan_officer'],
                        'delete' => ['manager'],
                        'approve' => ['manager'],   // approves a membership application
                        'fee'     => ['cashier']],  // collects the membership fee (issues an OR)
    'savings'       => ['view' => ALL_ROLES,
                        'create' => ['manager', 'cashier'],
                        'update' => ['manager', 'bookkeeper'],
                        'delete' => ['manager']],
    'savings_txn'   => ['view' => ALL_ROLES, 'create' => ['cashier'], 'delete' => ['bookkeeper']],
    'savings_interest' => ['view' => ['manager', 'bookkeeper'], 'post' => ['manager', 'bookkeeper']],  // posts the quarterly/term interest (A17)
    'products'      => ['view' => ALL_ROLES, 'create' => ['manager'], 'update' => ['manager'], 'delete' => ['manager']],
    'settings'      => ['view' => ALL_ROLES, 'update' => ['manager']],
    'loans'         => ['view' => ALL_ROLES,
                        'create'  => ['loan_officer'],
                        'approve' => ['manager'],
                        'release' => ['manager'],
                        'cancel'  => ['loan_officer']],
    'payments'      => ['view' => ALL_ROLES, 'create' => ['cashier'], 'void' => ['bookkeeper']],
    'delinquency'   => ['view' => ['manager', 'loan_officer', 'bookkeeper', 'auditor'], 'create' => ['loan_officer']],
    'notifications' => ['view' => ['manager', 'loan_officer'],
                        'create' => ['loan_officer'], 'update' => ['loan_officer'], 'delete' => ['loan_officer']],
    'reports'       => ['view' => ALL_ROLES],
    'report_daily'  => ['view' => ['manager', 'cashier', 'bookkeeper', 'auditor']],
    'eod_close'     => ['view' => ['manager', 'cashier', 'bookkeeper', 'auditor']],  // end-of-day balancing of the posted receipts
    'reconcile'     => ['view' => ['manager', 'bookkeeper', 'auditor']],             // schedule/balance vs ledger integrity check
    'report_loans'  => ['view' => ['manager', 'loan_officer', 'auditor']],
    'report_aging'  => ['view' => ['manager', 'loan_officer', 'auditor']],
    'report_savings'=> ['view' => ['manager', 'bookkeeper', 'auditor']],
    'report_share'  => ['view' => ['manager', 'bookkeeper', 'auditor']],
    'report_salary' => ['view' => ['manager', 'bookkeeper', 'auditor']],  // the bookkeeper prepares the deduction list (6.6)
    'audit'         => ['view' => ['manager', 'bookkeeper', 'auditor']],
];

final class ForbiddenException extends RuntimeException
{
}

/**
 * Authorization check used by guards, menus, and buttons.
 */
function can(string $module, string $action = 'view'): bool
{
    $role = $_SESSION['role'] ?? null;
    return $role !== null && in_array($role, RBAC[$module][$action] ?? [], true);
}

function current_user_id(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

/**
 * Route guard 1 — Authentication.
 * Valid session + fingerprint + idle check, then re-reads the account so a
 * deactivated user or a changed role takes effect on the very next request.
 */
function require_login(bool $json = false): void
{
    $ok = session_is_valid();

    if ($ok) {
        $stmt = db()->prepare('SELECT role, status, full_name FROM users WHERE user_id = :id');
        $stmt->execute([':id' => current_user_id()]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'active') {
            $ok = false;
        } else {
            $_SESSION['role']      = $row['role'];
            $_SESSION['full_name'] = $row['full_name'];
        }
    }

    if (!$ok) {
        logout_user();
        if ($json) {
            json_out(401, null, ['Your session has ended. Please log in again.']);
        }
        start_secure_session();
        flash('warning', 'Please log in to continue.');
        header('Location: index.php');
        exit;
    }
}

/**
 * Route guard 2 — Authorization (RBAC).
 * Pages: throws ForbiddenException, which the router renders as the 403 page.
 */
function require_permission(string $module, string $action = 'view', bool $json = false): void
{
    if (can($module, $action)) {
        return;
    }
    if ($json) {
        json_out(403, null, ['You do not have permission for this action.']);
    }
    throw new ForbiddenException();
}

/**
 * Route guard 3 — CSRF check on every state-changing request.
 */
function require_csrf(bool $json = false): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || csrf_is_valid()) {
        return;
    }
    if ($json) {
        json_out(403, null, ['Security token expired. Refresh the page and try again.']);
    }
    flash('error', 'Security token expired. Please try again.');
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'dashboard.php'));
    exit;
}

/* ---------------- Sign-in throttle (DB-backed) ----------------
 * MAX_LOGIN_FAILURES failed sign-ins for one identifier (username/email as
 * submitted) from one IP lock the PAIR out for LOGIN_LOCK_MINUTES. The
 * counter lives in the login_attempts table, so clearing cookies or moving
 * to a new session no longer bypasses it (the old session counter did).
 */

const MAX_LOGIN_FAILURES = 5;
const LOGIN_LOCK_MINUTES = 15;

/** Seconds before another sign-in attempt is allowed for this identifier+IP (0 = not locked). */
function login_lock_seconds(string $identifier): int
{
    $stmt = db()->prepare('SELECT locked_until FROM login_attempts WHERE identifier = :i AND ip = :ip');
    $stmt->execute([':i' => $identifier, ':ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    $until = $stmt->fetchColumn();
    return $until ? max(0, strtotime((string) $until) - time()) : 0;
}

/**
 * Records one failed attempt; at MAX_LOGIN_FAILURES locks the identifier+IP
 * (and audits the lock). Returns the seconds locked, 0 when not yet locked.
 */
function login_register_failure(string $identifier): int
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO login_attempts (identifier, ip, failures, last_attempt)
         VALUES (:i, :ip, 1, NOW())
         ON DUPLICATE KEY UPDATE failures = failures + 1, last_attempt = NOW()'
    )->execute([':i' => $identifier, ':ip' => $ip]);
    $stmt = $pdo->prepare('SELECT failures FROM login_attempts WHERE identifier = :i AND ip = :ip');
    $stmt->execute([':i' => $identifier, ':ip' => $ip]);
    if ((int) $stmt->fetchColumn() < MAX_LOGIN_FAILURES) {
        return 0;
    }
    $pdo->prepare('UPDATE login_attempts SET locked_until = DATE_ADD(NOW(), INTERVAL :m MINUTE), failures = 0
                    WHERE identifier = :i AND ip = :ip')
        ->execute([':m' => LOGIN_LOCK_MINUTES, ':i' => $identifier, ':ip' => $ip]);
    audit_log('login_locked', 'login_attempts', null,
        "Too many sign-in failures for \"$identifier\" from IP $ip: locked for " . LOGIN_LOCK_MINUTES . ' minutes');
    return LOGIN_LOCK_MINUTES * 60;
}

/** A successful sign-in clears the counter for that identifier+IP. */
function login_clear_failures(string $identifier): void
{
    db()->prepare('DELETE FROM login_attempts WHERE identifier = :i AND ip = :ip')
        ->execute([':i' => $identifier, ':ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
}
