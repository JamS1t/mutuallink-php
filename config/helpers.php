<?php
declare(strict_types=1);

/**
 * Shared helpers: output escaping, input validation (error accumulator),
 * flash messages, audit trail, counters, and JSON responses.
 */

const ACCOUNT_TYPES = [
    'regular_savings'  => 'Regular Savings',
    'share_capital'    => 'Share Capital',
    'capital_build_up' => 'Capital Build-Up',
    'time_deposit'     => 'Time Deposit',
];

// Only these accounts allow withdrawals; share capital and CBU are withdrawal-locked.
const WITHDRAWABLE = ['regular_savings', 'time_deposit'];

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

/** Y-m-d date from the query string, or the default when missing/invalid. */
function get_date(string $key, string $default): string
{
    $v = $_GET[$key] ?? '';
    $d = is_string($v) ? DateTime::createFromFormat('!Y-m-d', $v) : false;
    return ($d && $d->format('Y-m-d') === $v) ? $v : $default;
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

/* ---------------- Savings posting (DFD process 3.0) ----------------
 * Writes one ledger line and updates the account balance.
 * MUST be called inside a transaction: the account row is locked
 * (SELECT … FOR UPDATE) so two postings can never both read the same
 * starting balance. Business-rule violations throw DomainException.
 *
 * @param int $direction +1 adds to the balance, -1 subtracts
 * @return array{txn_id:int, or_no:?string, balance:float}
 */
function savings_entry(int $savingsId, string $txnType, float $amount, int $direction, string $date,
                       ?string $remarks = null, ?int $reversesTxnId = null, bool $issueOr = true): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT balance, status FROM savings_accounts WHERE savings_id = :id FOR UPDATE');
    $stmt->execute([':id' => $savingsId]);
    $acct = $stmt->fetch();
    if (!$acct) {
        throw new DomainException('Savings account not found.');
    }
    if ($acct['status'] !== 'active') {
        throw new DomainException('This account is closed.');
    }
    $newBalance = money_round((float) $acct['balance'] + $direction * $amount);
    if ($newBalance < 0) {
        throw new DomainException('Insufficient balance: available ' . money($acct['balance']) . '.');
    }
    $orNo = $issueOr ? next_or_no() : null;

    $pdo->prepare(
        'INSERT INTO savings_transactions (savings_id, txn_date, txn_type, amount, running_balance, or_no, posted_by, reverses_txn_id, remarks)
         VALUES (:s, :d, :t, :a, :rb, :or, :u, :rev, :rem)'
    )->execute([
        ':s' => $savingsId, ':d' => $date, ':t' => $txnType, ':a' => $amount, ':rb' => $newBalance,
        ':or' => $orNo, ':u' => current_user_id(), ':rev' => $reversesTxnId, ':rem' => $remarks,
    ]);
    $txnId = (int) $pdo->lastInsertId();

    $upd = $pdo->prepare('UPDATE savings_accounts SET balance = :b WHERE savings_id = :id');
    $upd->execute([':b' => $newBalance, ':id' => $savingsId]);

    return ['txn_id' => $txnId, 'or_no' => $orNo, 'balance' => $newBalance];
}

/* ---------------- Eligibility summary (DFD process 4.2) ----------------
 * Reads the member profile and payment history and returns one summary,
 * replacing the manual retrieval of several records. It informs the
 * credit committee; it does not decide the loan.
 */
function member_eligibility(int $memberId): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT m.status, m.member_type,
                COALESCE((SELECT balance FROM savings_accounts WHERE member_id = m.member_id AND account_type = 'share_capital'), 0) AS share_capital,
                (SELECT COUNT(*) FROM loans WHERE member_id = m.member_id AND status = 'released') AS active_loans,
                (SELECT COALESCE(SUM(outstanding_balance), 0) FROM loans WHERE member_id = m.member_id AND status = 'released') AS outstanding,
                (SELECT COUNT(*) FROM amortization_schedule s JOIN loans l ON l.loan_id = s.loan_id
                  WHERE l.member_id = m.member_id AND l.status = 'released' AND s.status <> 'paid' AND s.due_date < CURDATE()) AS past_due,
                (SELECT COUNT(*) FROM payments p JOIN amortization_schedule s ON s.schedule_id = p.schedule_id JOIN loans l ON l.loan_id = p.loan_id
                  WHERE l.member_id = m.member_id AND p.status = 'posted' AND p.mode <> 'offset' AND p.payment_date > s.due_date) AS late_payments,
                (SELECT COUNT(*) FROM loans WHERE member_id = m.member_id AND status = 'paid') AS paid_loans
           FROM members m WHERE m.member_id = :id"
    );
    $stmt->execute([':id' => $memberId]);
    $d = $stmt->fetch();
    if (!$d) {
        return ['items' => [], 'data' => []];
    }
    $minShare = (float) setting('min_share_capital');
    $items = [];
    $items[] = $d['status'] === 'active'
        ? ['ok', 'Active membership', 'Member is in good standing.']
        : ['bad', 'Inactive membership', 'Reactivate the member before any new loan.'];
    $items[] = (float) $d['share_capital'] >= $minShare
        ? ['ok', 'Share capital', money($d['share_capital']) . ' (minimum ' . money($minShare) . ')']
        : ['bad', 'Share capital below minimum', money($d['share_capital']) . ' of the required ' . money($minShare)];
    $items[] = (int) $d['past_due'] === 0
        ? ['ok', 'No past-due installments', 'All installments due so far are paid.']
        : ['bad', 'Has past-due installments', (int) $d['past_due'] . ' installment(s) are overdue.'];
    $late = (int) $d['late_payments'];
    $items[] = $late === 0
        ? ['ok', 'Payment record', 'No late payments on record; ' . (int) $d['paid_loans'] . ' loan(s) fully paid.']
        : [$late <= 2 ? 'warn' : 'bad', 'Payment record', "$late payment(s) were made after the due date."];
    $items[] = (int) $d['active_loans'] === 0
        ? ['ok', 'Existing loans', 'No outstanding loan.']
        : ['warn', 'Existing loans', (int) $d['active_loans'] . ' released loan(s), ' . money($d['outstanding']) . ' outstanding. Can be offset as previous-loan deduction on renewal.'];
    $items[] = $d['member_type'] === 'outside'
        ? ['warn', 'Collateral required', 'Member is from outside the school: collateral must be submitted.']
        : ['ok', 'Collateral', 'School-based member: collateral not required.'];
    $items[] = ['info', 'Co-maker', 'A co-maker must sign the application.'];

    return ['items' => $items, 'data' => $d];
}

function eligibility_list(array $items): string
{
    $icons = ['ok' => 'fa-check-circle ok', 'warn' => 'fa-exclamation-circle warn', 'bad' => 'fa-times-circle bad', 'info' => 'fa-info-circle text-muted'];
    $html = '<ul class="check-list">';
    foreach ($items as [$level, $label, $detail]) {
        $html .= '<li><i class="fas ' . $icons[$level] . ' mt-1" aria-hidden="true"></i><div><strong>' . e($label)
            . '</strong><span class="sr-only"> (' . e($level) . ')</span><div class="small text-muted">' . e($detail) . '</div></div></li>';
    }
    return $html . '</ul>';
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
