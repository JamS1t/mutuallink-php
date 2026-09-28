<?php
declare(strict_types=1);

/**
 * Session hardening, security headers, and anti-CSRF tokens.
 */

const SESSION_IDLE_SECONDS = 900; // 15 minutes

/**
 * Sends security headers and starts a hardened session.
 */
function start_secure_session(): void
{
    // Content Security Policy: only this origin may supply scripts, styles, fonts, images.
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    ini_set('session.use_strict_mode', '1');   // reject uninitialized session IDs
    ini_set('session.use_only_cookies', '1');  // never accept the ID from the URL
    session_name('MUTUALLINK_SID');
    session_set_cookie_params([
        'lifetime' => 0,          // ends when the browser closes
        'path'     => '/',
        'secure'   => $https,     // HTTPS-only when the site runs on HTTPS
        'httponly' => true,       // JavaScript cannot read the cookie (XSS theft)
        'samesite' => 'Strict',   // cookie not sent on cross-site requests (CSRF)
    ]);
    session_start();
}

/**
 * Fingerprint of the browser, used to bind the session to its environment.
 */
function session_fingerprint(): string
{
    return hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
}

/**
 * Called right after a successful login.
 */
function begin_user_session(array $user): void
{
    session_regenerate_id(true); // defeats session fixation
    $_SESSION['user_id']       = (int) $user['user_id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['full_name']     = $user['full_name'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['ua_hash']       = session_fingerprint();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf']          = bin2hex(random_bytes(32)); // fresh token for the new session
}

/**
 * True when the session belongs to a logged-in user, matches the browser
 * that logged in, and has not been idle too long.
 */
function session_is_valid(): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }
    if (!hash_equals($_SESSION['ua_hash'] ?? '', session_fingerprint())) {
        return false; // possible hijacked cookie used from another browser
    }
    if (time() - (int) ($_SESSION['last_activity'] ?? 0) > SESSION_IDLE_SECONDS) {
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Destroys the session completely and expires the cookie.
 */
function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Strict',
        ]);
    }
    session_destroy();
}

/* ---------------- Anti-CSRF tokens ---------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Checks the token from the form field or the X-CSRF-Token header.
 */
function csrf_is_valid(): bool
{
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}
