<?php
declare(strict_types=1);

/**
 * Shared helpers: output escaping, input validation (error accumulator),
 * flash messages, audit trail, counters, and JSON responses.
 */

/* ---------------- Output ---------------- */

/** Context-aware output escaping for HTML text and attributes. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money(mixed $amount): string
{
    return '₱ ' . number_format((float) $amount, 2);
}

/** "capital_build_up" → "Capital Build Up" */
function label(?string $value): string
{
    return ucwords(str_replace('_', ' ', (string) $value));
}

function fmt_date(?string $date, string $format = 'M d, Y'): string
{
    return $date ? date($format, strtotime($date)) : '—';
}

/** Bootstrap badge for a status value; the text is always shown, not just the color. */
function badge(?string $status): string
{
    $map = [
        'active' => 'success', 'inactive' => 'secondary', 'closed' => 'secondary',
        'pending' => 'warning', 'approved' => 'info', 'rejected' => 'danger', 'released' => 'primary',
        'paid' => 'success', 'cancelled' => 'secondary', 'unpaid' => 'light', 'partial' => 'warning',
        'posted' => 'success', 'void' => 'danger', 'sent' => 'success', 'failed' => 'danger',
        'deposit' => 'success', 'withdrawal' => 'warning', 'reversal' => 'danger',
        'upcoming' => 'info', 'overdue' => 'danger',
    ];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge badge-' . $class . '">' . e(label($status)) . '</span>';
}

/* ---------------- Flash messages (shown with Toastr) ---------------- */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/* ---------------- Input validation (Error Accumulator pattern) ----------------
 * Each helper validates one field, appends a message to $errors on failure,
 * and returns the clean value. Input is validated, not "sanitized";
 * escaping happens on output with e().
 */

function input(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

/** Returned form values after a failed submit, so the user does not retype. */
function old(string $key, mixed $default = ''): string
{
    return $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST[$key]) && is_string($_POST[$key])
        ? $_POST[$key]
        : (string) ($default ?? '');
}

function req(array &$errors, string $key, string $label, int $max = 255): string
{
    $v = input($key);
    if ($v === '') {
        $errors[] = "$label is required.";
    } elseif (mb_strlen($v) > $max) {
        $errors[] = "$label must be at most $max characters.";
    }
    return $v;
}

function opt(array &$errors, string $key, string $label, int $max = 255): ?string
{
    $v = input($key);
    if ($v !== '' && mb_strlen($v) > $max) {
        $errors[] = "$label must be at most $max characters.";
    }
    return $v === '' ? null : $v;
}

function pattern_in(array &$errors, string $key, string $label, string $regex, string $hint, bool $required = false): ?string
{
    $v = input($key);
    if ($v === '') {
        if ($required) {
            $errors[] = "$label is required.";
        }
        return null;
    }
    if (!preg_match($regex, $v)) {
        $errors[] = "$label $hint";
    }
    return $v;
}

function email_in(array &$errors, string $key, string $label, bool $required = false): ?string
{
    $v = input($key);
    if ($v === '') {
        if ($required) {
            $errors[] = "$label is required.";
        }
        return null;
    }
    if (filter_var($v, FILTER_VALIDATE_EMAIL) === false || mb_strlen($v) > 150) {
        $errors[] = "$label must be a valid email address.";
    }
    return $v;
}

/** Money: positive, at most 2 decimals, no thousands separators. */
function money_in(array &$errors, string $key, string $label, bool $required = true, float $min = 0.01, float $max = 99999999.99): ?float
{
    $v = str_replace(',', '', input($key));
    if ($v === '') {
        if ($required) {
            $errors[] = "$label is required.";
        }
        return null;
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $v) || filter_var($v, FILTER_VALIDATE_FLOAT) === false) {
        $errors[] = "$label must be an amount with up to 2 decimals.";
        return null;
    }
    $f = (float) $v;
    if ($f < $min || $f > $max) {
        $errors[] = "$label must be between " . number_format($min, 2) . ' and ' . number_format($max, 2) . '.';
    }
    return $f;
}

function int_in(array &$errors, string $key, string $label, int $min, int $max): ?int
{
    $v = filter_var(input($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    if ($v === false) {
        $errors[] = "$label must be a whole number from $min to $max.";
        return null;
    }
    return $v;
}

function date_in(array &$errors, string $key, string $label, bool $required = true): ?string
{
    $v = input($key);
    if ($v === '') {
        if ($required) {
            $errors[] = "$label is required.";
        }
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) {
        $errors[] = "$label must be a valid date.";
        return null;
    }
    return $v;
}

/** Whitelist check: value must be one of the allowed keys. */
function enum_in(array &$errors, string $key, string $label, array $allowed): string
{
    $v = input($key);
    if (!in_array($v, $allowed, true)) {
        $errors[] = "Please select a valid $label.";
    }
    return $v;
}

/** Positive integer ID from the query string (0 when invalid). */
function get_id(string $key = 'id'): int
{
    $v = filter_var($_GET[$key] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $v === false ? 0 : $v;
}

function post_id(string $key): int
{
    $v = filter_var(input($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $v === false ? 0 : $v;
}

/** Password policy: 8+ characters with at least one letter and one digit. */
function password_policy_ok(string $password): bool
{
    return strlen($password) >= 8 && strlen($password) <= 72
        && preg_match('/[A-Za-z]/', $password) && preg_match('/\d/', $password);
}

const PASSWORD_OPTIONS = ['cost' => 12];

/* ---------------- Settings, counters, audit ---------------- */

function setting(string $key): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return (string) ($cache[$key] ?? '');
}

/**
 * Next value of a counter stored in settings. Must run inside a transaction:
 * the row lock (FOR UPDATE) guarantees two cashiers never get the same number.
 */
function next_counter(string $key): int
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :k FOR UPDATE');
    $stmt->execute([':k' => $key]);
    $next = (int) $stmt->fetchColumn() + 1;
    $pdo->prepare('UPDATE settings SET setting_value = :v WHERE setting_key = :k')
        ->execute([':v' => (string) $next, ':k' => $key]);
    return $next;
}

function next_or_no(): string
{
    return 'OR-' . date('Y') . '-' . str_pad((string) next_counter('or_counter'), 6, '0', STR_PAD_LEFT);
}

function next_member_no(): string
{
    return 'FFMC-' . date('Y') . '-' . str_pad((string) next_counter('member_counter'), 4, '0', STR_PAD_LEFT);
}

/** Records who did what; the audit log is insert-only. */
function audit_log(string $action, string $table, ?int $recordId = null, string $details = ''): void
{
    db()->prepare(
        'INSERT INTO audit_log (user_id, action, table_affected, record_id, details, ip_address)
         VALUES (:u, :a, :t, :r, :d, :ip)'
    )->execute([
        ':u'  => current_user_id() ?: null,
        ':a'  => $action,
        ':t'  => $table,
        ':r'  => $recordId,
        ':d'  => mb_substr($details, 0, 500),
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

/**
 * Standard handling of an unexpected database error inside a page:
 * roll back, log the real error, show a safe message.
 */
function db_failure(Throwable $e): void
{
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    error_log('MutualLink error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    flash('error', 'Something went wrong; nothing was saved. Please try again.');
}

/** Moves validation errors into flash messages (all shown at once). */
function flash_errors(array $errors): void
{
    foreach ($errors as $msg) {
        flash('error', $msg);
    }
}

/* ---------------- JSON responses (API endpoints) ---------------- */

function json_out(int $status, mixed $data = null, array $errors = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $status >= 200 && $status < 300,
        'data'    => $data,
        'errors'  => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
