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

// Only these accounts allow over-the-counter withdrawals. Share capital can also be
// withdrawn, but only with the approval of the Board of Directors (clarification A18,
// enforced in savings_post.php); capital build-up is withdrawal-locked.
const WITHDRAWABLE = ['regular_savings', 'time_deposit'];

const LOANABLE_BASIS = ['fixed' => 'Fixed minimum and maximum', 'collateral' => 'Appraised value of collateral', 'net_pay' => "Member's net pay"];

const COLLATERAL_TYPES = ['none' => 'None', 'real_estate' => 'Real estate (land title)', 'vehicle' => 'Vehicle (OR/CR)', 'other' => 'Other'];

// How members pay their loans (questionnaire 6.1)
const REPAYMENT_MODES = ['cash' => 'Cash over the counter', 'salary_deduction' => 'Salary deduction', 'bank_deposit' => 'Bank deposit',
                         'field_collection' => 'Collector / field collection', 'e_wallet' => 'GCash / e-wallet'];

/** Regular Loan limit: a share of the collateral's appraised value (₱3,000,000 title → ₱900,000 at 30%). */
function loanable_from_collateral(float $appraisedValue): float
{
    return money_round($appraisedValue * (float) setting('collateral_loanable_pct') / 100);
}

/**
 * FFMPC account minimums (questionnaire 3.1, 3.3):
 * regular savings keeps a ₱500 maintaining balance; a time deposit starts at ₱10,000 and is
 * either kept at or above that or withdrawn in full.
 *
 * @return array{opening: float, maintaining: float, full_withdrawal_allowed: bool}
 */
function savings_limits(string $accountType): array
{
    return match ($accountType) {
        'regular_savings' => ['opening' => (float) setting('min_regular_savings'), 'maintaining' => (float) setting('min_regular_savings'), 'full_withdrawal_allowed' => false],
        'time_deposit'    => ['opening' => (float) setting('min_time_deposit'), 'maintaining' => (float) setting('min_time_deposit'), 'full_withdrawal_allowed' => true],
        default           => ['opening' => 0.0, 'maintaining' => 0.0, 'full_withdrawal_allowed' => false],
    };
}

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
    return str_replace('Cbu', 'CBU', ucwords(str_replace('_', ' ', (string) $value)));
}

function fmt_date(?string $date, string $format = 'M d, Y'): string
{
    return $date ? date($format, strtotime($date)) : '—';
}

/** Bootstrap badge for a status value; the text is always shown, not just the color. */
function badge(?string $status): string
{
    $map = [
        'active' => 'success', 'inactive' => 'secondary', 'closed' => 'secondary', 'applicant' => 'warning',
        'pending' => 'warning', 'approved' => 'info', 'rejected' => 'danger', 'released' => 'primary',
        'paid' => 'success', 'cancelled' => 'secondary', 'unpaid' => 'light', 'partial' => 'warning',
        'posted' => 'success', 'void' => 'danger', 'sent' => 'success', 'failed' => 'danger',
        'deposit' => 'success', 'withdrawal' => 'warning', 'reversal' => 'danger', 'interest' => 'info',
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

/**
 * Success toast with an Undo button for NON-financial actions (mark reminder sent,
 * remove unsent reminder, edit profile). The toast carries the fields of the reverse
 * action; mutuallink.js submits them (with a fresh CSRF token) when Undo is clicked.
 * The message is NOT flashed separately — the undo toast itself announces it.
 * Anything touching money, void, or reverse keeps the confirm (+reason) modal instead.
 *
 * @param string $url    URL the reverse action posts to (same PRG + CSRF flow)
 * @param array<string,string> $fields POST fields of the reverse action
 */
function flash_undo(string $message, string $url, array $fields): void
{
    $_SESSION['flash_undo'] = ['message' => $message, 'url' => $url, 'fields' => $fields];
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/* ---------------- Input validation (Error Accumulator pattern) ----------------
 * Each helper validates one field, appends a message to $errors on failure,
 * and returns the clean value. Messages are recorded under the FIELD KEY so the
 * re-rendered form can show them inline next to the input (error_summary() and
 * field_feedback()); cross-field rules added as plain strings fall back to the
 * error summary and Toastr. Input is validated, not "sanitized";
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
        $errors[$key] = "$label is required.";
    } elseif (mb_strlen($v) > $max) {
        $errors[$key] = "$label must be at most $max characters.";
    }
    return $v;
}

function opt(array &$errors, string $key, string $label, int $max = 255): ?string
{
    $v = input($key);
    if ($v !== '' && mb_strlen($v) > $max) {
        $errors[$key] = "$label must be at most $max characters.";
    }
    return $v === '' ? null : $v;
}

function pattern_in(array &$errors, string $key, string $label, string $regex, string $hint, bool $required = false): ?string
{
    $v = input($key);
    if ($v === '') {
        if ($required) {
            $errors[$key] = "$label is required.";
        }
        return null;
    }
    if (!preg_match($regex, $v)) {
        $errors[$key] = "$label $hint";
    }
    return $v;
}

function email_in(array &$errors, string $key, string $label, bool $required = false): ?string
{
    $v = input($key);
    if ($v === '') {
        if ($required) {
            $errors[$key] = "$label is required.";
        }
        return null;
    }
    if (filter_var($v, FILTER_VALIDATE_EMAIL) === false || mb_strlen($v) > 150) {
        $errors[$key] = "$label must be a valid email address.";
    }
    return $v;
}

/** Money: positive, at most 2 decimals, no thousands separators. */
function money_in(array &$errors, string $key, string $label, bool $required = true, float $min = 0.01, float $max = 99999999.99): ?float
{
    $v = str_replace(',', '', input($key));
    if ($v === '') {
        if ($required) {
            $errors[$key] = "$label is required.";
        }
        return null;
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $v) || filter_var($v, FILTER_VALIDATE_FLOAT) === false) {
        $errors[$key] = "$label must be an amount with up to 2 decimals.";
        return null;
    }
    $f = (float) $v;
    if ($f < $min || $f > $max) {
        $errors[$key] = "$label must be between " . number_format($min, 2) . ' and ' . number_format($max, 2) . '.';
    }
    return $f;
}

function int_in(array &$errors, string $key, string $label, int $min, int $max): ?int
{
    $v = filter_var(input($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    if ($v === false) {
        $errors[$key] = "$label must be a whole number from $min to $max.";
        return null;
    }
    return $v;
}

function date_in(array &$errors, string $key, string $label, bool $required = true): ?string
{
    $v = input($key);
    if ($v === '') {
        if ($required) {
            $errors[$key] = "$label is required.";
        }
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) {
        $errors[$key] = "$label must be a valid date.";
        return null;
    }
    return $v;
}

/** Whitelist check: value must be one of the allowed keys. */
function enum_in(array &$errors, string $key, string $label, array $allowed): string
{
    $v = input($key);
    if (!in_array($v, $allowed, true)) {
        $errors[$key] = "Please select a valid $label.";
    }
    return $v;
}

/** Current deduction rates from Settings, in the shape compute_deductions() expects. */
function deduction_rates(): array
{
    return [
        'service_fee_pct' => (float) setting('service_fee_pct'),
        'insurance_pct'   => (float) setting('insurance_pct'),
        'stockshare_pct'  => (float) setting('stockshare_pct'),
        'notarial_fee'    => (float) setting('notarial_fee'),
        'other_fee'       => (float) setting('other_fee'),
    ];
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

/** Plain numbered OR series like the cooperative's receipt booklet (e.g. 025952). */
function next_or_no(): string
{
    return format_or((int) next_counter('or_counter'));
}

function format_or(int $n): string
{
    return str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}

function next_member_no(): string
{
    return 'FFMPC-' . date('Y') . '-' . str_pad((string) next_counter('member_counter'), 4, '0', STR_PAD_LEFT);
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

/**
 * Moves validation errors toward the user: field-keyed messages travel to the
 * re-rendered form as inline errors (take_field_errors()), while messages that
 * have no field (cross-field rules) keep the Toastr fallback.
 */
function flash_errors(array $errors): void
{
    foreach ($errors as $k => $msg) {
        if (is_int($k)) {
            flash('error', $msg);
        } else {
            $_SESSION['field_errors'][$k] ??= (string) $msg;
        }
    }
}

/** Field-keyed errors for the form being rendered; clears the one-shot store. */
function take_field_errors(): array
{
    $fields = $_SESSION['field_errors'] ?? [];
    unset($_SESSION['field_errors']);
    return $fields;
}

/** DOM id of a field's inline message — the target of the error summary links. */
function field_error_id(string $key): string
{
    return 'err-' . $key;
}

/** Bootstrap class marking a failed field ('' when valid). */
function invalid_class(array $errors, string $key): string
{
    return isset($errors[$key]) ? ' is-invalid' : '';
}

/** ARIA wiring for a failed field: aria-invalid + pointer to its message ('' when valid). */
function invalid_attrs(array $errors, string $key): string
{
    return isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . field_error_id($key) . '"' : '';
}

/** The inline message under an input ('' when the field is valid). d-block: radio
 * groups and input-groups have no .is-invalid sibling for Bootstrap's own display rule. */
function field_feedback(array $errors, string $key): string
{
    return isset($errors[$key])
        ? '<div class="invalid-feedback d-block" id="' . field_error_id($key) . '">' . e($errors[$key]) . '</div>'
        : '';
}

/** Error summary block: anchor-linked list at the top of the form ('' when no errors). */
function error_summary(array $errors): string
{
    if (!$errors) {
        return '';
    }
    $items = '';
    foreach ($errors as $k => $msg) {
        $msg = e((string) $msg);
        $items .= is_int($k)
            ? '<li>' . $msg . '</li>'
            : '<li><a href="#' . field_error_id((string) $k) . '">' . $msg . '</a></li>';
    }
    return '<div id="error-summary" class="alert alert-danger" role="alert" tabindex="-1">'
        . '<strong class="d-block mb-1"><i class="fas fa-exclamation-triangle mr-1" aria-hidden="true"></i>Please fix the following:</strong>'
        . '<ul class="mb-0 pl-3 small">' . $items . '</ul></div>';
}

/* ---------------- Interface language (Settings toggle, default English) ----------------
 * High-frequency verb labels only — sentences stay English so nothing gets lost
 * in translation on financial records.
 */
const I18N = [
    'en'  => ['post' => 'Post', 'void' => 'Void', 'due' => 'Due', 'payoff' => 'Payoff',
              'withdraw' => 'Withdraw', 'reverse' => 'Reverse', 'deposit' => 'Deposit'],
    'ceb' => ['post' => 'I-post', 'void' => 'Kanselahon', 'due' => 'Due', 'payoff' => 'Bayaran tanan',
              'withdraw' => 'Kuhaa', 'reverse' => 'Balihon', 'deposit' => 'Ugpong'],
    'fil' => ['post' => 'I-post', 'void' => 'Kanselahin', 'due' => 'Due', 'payoff' => 'Bayaran lahat',
              'withdraw' => 'I-withdraw', 'reverse' => 'Baliktarin', 'deposit' => 'I-deposito'],
];

const I18N_NAMES = ['en' => 'English', 'ceb' => 'Cebuano', 'fil' => 'Filipino'];

function ui_lang(): string
{
    $l = setting('ui_lang');
    return isset(I18N[$l]) ? $l : 'en';
}

/** Translated high-frequency verb label ('post', 'void', 'due', 'payoff', 'withdraw', 'reverse', 'deposit'). */
function t(string $key): string
{
    return I18N[ui_lang()][$key] ?? I18N['en'][$key] ?? $key;
}

/* ---------------- Glossary (cooperative jargon on the forms) ---------------- */

const GLOSSARY = [
    'stockshare'   => 'Stockshare: the share of every loan (set in Settings) that goes to the member\'s share capital — savings they own in the cooperative, deducted from the proceeds at release.',
    'cbu'          => 'Capital Build-Up (CBU): a fixed monthly savings contribution that builds the cooperative\'s capital. CBU is withdrawal-locked.',
    'offset'       => 'Offset: on renewal, an old loan is settled out of the new loan\'s proceeds — the remaining balance is deducted and the old loan closes.',
    'maintaining'  => 'Maintaining balance: the minimum a regular savings account must keep. Withdrawals may not bring the balance below it.',
    'amortization' => 'Amortization: the monthly payment schedule — every installment is part principal and part interest.',
    'net_pay'      => 'Monthly net pay: the salary left after deductions. A Salary Loan\'s monthly amortization must not exceed it.',
];

function glossary_term(string $key): string
{
    return GLOSSARY[$key] ?? $key;
}

/**
 * Focusable "?" button that explains a cooperative term on hover AND keyboard focus
 * (a Bootstrap tooltip, with a real accessible name — not title-only).
 */
function glossary_btn(string $key): string
{
    $term = glossary_term($key);
    $word = trim(strtok($term, ':') ?: $key);
    return '<button type="button" class="btn btn-link ml-gloss p-0 align-baseline" data-toggle="tooltip" data-placement="top" data-boundary="window"'
        . ' title="' . e($term) . '" aria-label="What does ' . e($word) . ' mean?"><i class="fas fa-question-circle" aria-hidden="true"></i></button>';
}

/* ---------------- Recent members (per-session, no new tables) ----------------
 * The last members this user served TODAY, shown as a quick strip on the
 * cashier's posting screens. Lives in $_SESSION only.
 */

function remember_member_served(int $memberId, string $name, string $memberNo): void
{
    if ($memberId <= 0 || $name === '') {
        return;
    }
    $today = date('Y-m-d');
    $list = $_SESSION['recent_members'] ?? ['date' => $today, 'members' => []];
    if (($list['date'] ?? '') !== $today) {
        $list = ['date' => $today, 'members' => []]; // a new day starts an empty strip
    }
    unset($list['members'][$memberId]);
    $list['members'][$memberId] = ['name' => $name, 'no' => $memberNo];
    $_SESSION['recent_members'] = ['date' => $today, 'members' => array_slice($list['members'], -5, null, true)];
}

/** @return list<array{member_id:int, name:string, no:string}> last served first */
function recent_members(): array
{
    $list = $_SESSION['recent_members'] ?? [];
    if (($list['date'] ?? '') !== date('Y-m-d')) {
        return [];
    }
    $out = [];
    foreach (array_reverse($list['members'] ?? [], true) as $id => $m) {
        $out[] = ['member_id' => (int) $id, 'name' => (string) $m['name'], 'no' => (string) $m['no']];
    }
    return $out;
}

/* ---------------- Step indicator for multi-screen flows ---------------- */

/** Progress steps (find member → choose loan → post); $current is zero-based. */
function steps_nav(array $steps, int $current): string
{
    $out = '<nav aria-label="Progress"><ol class="ml-steps mb-3">';
    foreach ($steps as $i => $label) {
        $state = $i < $current ? 'done' : ($i === $current ? 'current' : 'todo');
        $out .= '<li class="ml-step ' . $state . '"' . ($i === $current ? ' aria-current="step"' : '') . '>'
            . '<span class="ml-step-dot" aria-hidden="true">' . ($i < $current ? '<i class="fas fa-check"></i>' : e((string) ($i + 1))) . '</span>'
            . '<span class="ml-step-label">' . e($label) . '</span></li>';
    }
    return $out . '</ol></nav>';
}

/* ---------------- Savings posting (DFD process 3.0) ----------------
 * Writes one ledger line and updates the account balance.
 * MUST be called inside a transaction: the account row is locked
 * (SELECT … FOR UPDATE) so two postings can never both read the same
 * starting balance. Business-rule violations throw DomainException.
 *
 * $interestPeriod records the quarter/term an INTEREST posting belongs to
 * (clarification A17); the uq_savings_txn_period unique key then makes a
 * double posting for the same period impossible in the database.
 *
 * @param int $direction +1 adds to the balance, -1 subtracts
 * @return array{txn_id:int, or_no:?string, balance:float}
 */
function savings_entry(int $savingsId, string $txnType, float $amount, int $direction, string $date,
                       ?string $remarks = null, ?int $reversesTxnId = null, bool $issueOr = true,
                       bool $passbookPresented = false, ?string $interestPeriod = null): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT balance, status, account_type FROM savings_accounts WHERE savings_id = :id FOR UPDATE');
    $stmt->execute([':id' => $savingsId]);
    $acct = $stmt->fetch();
    if (!$acct) {
        throw new DomainException('Savings account not found.');
    }
    if ($acct['status'] !== 'active') {
        throw new DomainException('This account is closed.');
    }
    $balance = (float) $acct['balance'];
    $newBalance = money_round($balance + $direction * $amount);
    if ($newBalance < 0) {
        throw new DomainException('Insufficient balance: available ' . money($balance) . '.');
    }

    // Over-the-counter deposits and withdrawals follow FFMPC's account minimums
    if ($issueOr) {
        $lim = savings_limits($acct['account_type']);
        $name = ACCOUNT_TYPES[$acct['account_type']];
        if ($txnType === 'withdrawal') {
            if (!$passbookPresented) {
                throw new DomainException('A withdrawal needs the member\'s passbook. A lost passbook requires a notarized affidavit of loss.');
            }
            $fullClose = $lim['full_withdrawal_allowed'] && $newBalance == 0.0;
            if (!$fullClose && $newBalance < $lim['maintaining']) {
                throw new DomainException("$name must keep " . money($lim['maintaining']) . '. Up to '
                    . money(max(0, $balance - $lim['maintaining'])) . ' can be withdrawn'
                    . ($lim['full_withdrawal_allowed'] ? ', or the whole ' . money($balance) . '.' : '.'));
            }
        }
        if ($txnType === 'deposit' && $balance == 0.0 && $amount < $lim['opening']) {
            throw new DomainException("The opening deposit for $name must be at least " . money($lim['opening']) . '.');
        }
    }
    $orNo = $issueOr ? next_or_no() : null;

    $pdo->prepare(
        'INSERT INTO savings_transactions (savings_id, txn_date, txn_type, amount, running_balance, or_no, posted_by, reverses_txn_id, passbook_presented, remarks, interest_period)
         VALUES (:s, :d, :t, :a, :rb, :or, :u, :rev, :pb, :rem, :per)'
    )->execute([
        ':s' => $savingsId, ':d' => $date, ':t' => $txnType, ':a' => $amount, ':rb' => $newBalance,
        ':or' => $orNo, ':u' => current_user_id(), ':rev' => $reversesTxnId, ':pb' => $passbookPresented ? 1 : 0, ':rem' => $remarks,
        ':per' => $interestPeriod,
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
    // FFMPC's eligibility rules (questionnaire 4.2): good payment record, a co-maker, and
    // collateral for members from outside the school. Share capital is shown for information.
    $minShare = (float) setting('min_share_capital');
    $items = [];
    $items[] = match ($d['status']) {
        'active'    => ['ok', 'Active membership', 'Member is in good standing.'],
        'applicant' => ['bad', 'Membership not yet approved', 'The Manager must approve the membership application before any loan.'],
        default     => ['bad', 'Inactive membership', 'Reactivate the member before any new loan.'],
    };
    $late = (int) $d['late_payments'];
    if ((int) $d['past_due'] > 0) {
        $items[] = ['bad', 'Payment record: has past-due installments', (int) $d['past_due'] . ' installment(s) are overdue right now.'];
    } elseif ($late > 0) {
        $items[] = [$late <= 2 ? 'warn' : 'bad', 'Payment record', "$late payment(s) were made after the due date."];
    } else {
        $items[] = ['ok', 'Good payment record', 'No late or past-due payments; ' . (int) $d['paid_loans'] . ' loan(s) fully paid.'];
    }
    $items[] = ['info', 'Co-maker required', 'A co-maker must sign the application together with the borrower.'];
    $items[] = $d['member_type'] === 'outside'
        ? ['warn', 'Collateral required', 'Member is from outside the school: collateral or real estate must be submitted.']
        : ['ok', 'Collateral', 'School-based member: collateral needed only for a Regular Loan.'];
    $items[] = (int) $d['active_loans'] === 0
        ? ['ok', 'Existing loans', 'No outstanding loan.']
        : ['warn', 'Existing loans', (int) $d['active_loans'] . ' released loan(s), ' . money($d['outstanding']) . ' outstanding. Can be offset as previous-loan deduction on renewal.'];
    $items[] = (float) $d['share_capital'] >= $minShare
        ? ['ok', 'Share capital', money($d['share_capital']) . ' (required ' . money($minShare) . ')']
        : ['warn', 'Share capital below the required amount', money($d['share_capital']) . ' of ' . money($minShare) . '.'];

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
