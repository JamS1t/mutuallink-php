<?php
declare(strict_types=1);

// Rates and defaults the Manager can change without touching code.
$title = 'Settings';
$subtitle = 'Loan deductions, account minimums, the OR series, reminders, and aging brackets. Only the Manager can change these.';
$canEdit = can('settings', 'update');

// key => [validator type, min, max]
$editable = [
    'service_fee_pct'         => ['pct', 0, 20],
    'insurance_pct'           => ['pct', 0, 20],
    'stockshare_pct'          => ['pct', 0, 20],
    'notarial_fee'            => ['money', 0, 10000],
    'other_fee'               => ['money', 0, 10000],
    'collateral_loanable_pct' => ['pct', 1, 100],
    'min_share_capital'       => ['money', 0, 1000000],
    'min_regular_savings'     => ['money', 0, 1000000],
    'min_time_deposit'        => ['money', 0, 10000000],
    'cbu_monthly'             => ['money', 0, 100000],
    'membership_fee'          => ['money', 1, 100000],
    'savings_interest_pct'    => ['pct', 0.01, 100],
    'reminder_lead_days'      => ['int', 1, 30],
    'aging_brackets'          => ['brackets', 0, 0],
    'or_counter'              => ['or', 0, 0],
    'ui_lang'                 => ['lang', 0, 0], // high-frequency verb labels: English / Cebuano / Filipino
];
// Setting rows added later (no schema change — same settings table)
$langSettingKeys = ['ui_lang'];
$groups = [
    'Loan deductions (FFMPC sample computation)' => ['insurance_pct', 'service_fee_pct', 'stockshare_pct', 'notarial_fee', 'other_fee'],
    'Loanable amount' => ['collateral_loanable_pct'],
    'Accounts' => ['min_share_capital', 'min_regular_savings', 'min_time_deposit', 'cbu_monthly'],
    'Fees and interest (FFMPC clarifications A15, A17)' => ['membership_fee', 'savings_interest_pct'],
    'Monitoring' => ['reminder_lead_days', 'aging_brackets'],
    'Official receipts' => ['or_counter'],
    'Interface language (verb labels)' => ['ui_lang'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('settings', 'update');
    $errors = [];
    $values = [];
    $labels = db()->query('SELECT setting_key, label FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($editable as $key => [$type, $min, $max]) {
        $label = $labels[$key] ?? $key;
        if ($type === 'lang') {
            $v = input($key);
            if (!in_array($v, array_keys(I18N), true)) {
                $errors[$key] = 'Choose a valid interface language.';
            }
            $values[$key] = $v;
        } elseif ($type === 'int') {
            $v = int_in($errors, $key, $label, $min, $max);
            $values[$key] = (string) $v;
        } elseif ($type === 'or') {
            // The Manager controls the OR series. It may only move forward, so a number is never issued twice.
            $v = filter_var(input($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 999999]]);
            $current = (int) setting('or_counter');
            if ($v === false) {
                $errors[] = 'Last OR number issued must be a whole number up to 999999.';
            } elseif ($v < $current) {
                $errors[] = 'The OR series can only move forward (currently at ' . format_or($current) . ').';
            }
            $values[$key] = (string) ($v === false ? $current : $v);
        } elseif ($type === 'brackets') {
            $raw = str_replace(' ', '', input($key));
            if (!preg_match('/^\d{1,4}(,\d{1,4}){1,9}$/', $raw) || parse_brackets($raw) !== array_map('intval', explode(',', $raw))) {
                $errors[] = 'Aging brackets must be increasing whole numbers separated by commas, e.g. 30,60,90,180,365.';
            }
            $values[$key] = $raw;
        } else {
            $v = money_in($errors, $key, $label, true, (float) $min, (float) $max);
            $values[$key] = number_format((float) $v, 2, '.', '');
        }
    }
    if ($errors) {
        flash_errors($errors);
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $old = $pdo->query('SELECT setting_key, setting_value FROM settings FOR UPDATE')->fetchAll(PDO::FETCH_KEY_PAIR);
            if ((int) $values['or_counter'] < (int) $old['or_counter']) {
                $values['or_counter'] = $old['or_counter']; // a receipt was issued meanwhile; never move backwards
            }
            $upd = $pdo->prepare('UPDATE settings SET setting_value = :v WHERE setting_key = :k');
            $changes = [];
            foreach ($values as $k => $v) {
                if (($old[$k] ?? null) !== $v) {
                    if (in_array($k, $langSettingKeys, true) && !array_key_exists($k, $old)) {
                        $pdo->prepare('INSERT INTO settings (setting_key, setting_value, label) VALUES (:k, :v, :l)')
                            ->execute([':k' => $k, ':v' => $v, ':l' => 'Interface language (verb labels)']);
                    } else {
                        $upd->execute([':v' => $v, ':k' => $k]);
                    }
                    $changes[] = "$k: " . ($old[$k] ?? '(not set)') . " → $v";
                }
            }
            if ($changes) {
                audit_log('update', 'settings', null, implode('; ', $changes));
            }
            $pdo->commit();
            flash('success', $changes ? 'Settings saved (' . count($changes) . ' change(s)).' : 'No changes to save.');
            redirect('dashboard.php?page=settings');
        } catch (Throwable $e) {
            db_failure($e);
        }
    }
}

$settings = db()->query('SELECT setting_key, setting_value, label FROM settings ORDER BY setting_key')->fetchAll(PDO::FETCH_UNIQUE);
foreach ($langSettingKeys as $k) {
    if (!isset($settings[$k])) {
        $settings[$k] = ['setting_value' => 'en', 'label' => 'Interface language (verb labels)']; // defaults to English until saved
    }
}
// Cooperative jargon on this form gets a focusable explainer
$glossaryKeys = ['stockshare_pct' => 'stockshare', 'cbu_monthly' => 'cbu', 'min_regular_savings' => 'maintaining'];
$example = compute_deductions(26000.0, deduction_rates(), 0.0);
$errors = take_field_errors();
?>
<div class="row">
  <div class="col-lg-7">
    <div class="card card-primary card-outline">
      <form method="post" action="" class="ml-form" novalidate data-dirty-guard>
        <?= csrf_field() ?>
        <?= error_summary($errors) ?>
        <div class="card-body">
          <?php foreach ($groups as $groupLabel => $keys): ?>
            <h3 class="h6 text-muted text-uppercase small mt-2 mb-3"><?= e($groupLabel) ?></h3>
            <?php foreach ($keys as $key): $s = $settings[$key]; $type = $editable[$key][0]; ?>
              <div class="form-group row">
                <label for="<?= e($key) ?>" class="col-md-7 col-form-label"><?= e($s['label']) ?><?= isset($glossaryKeys[$key]) ? ' ' . glossary_btn($glossaryKeys[$key]) : '' ?></label>
                <div class="col-md-5">
                  <?php if ($type === 'lang'): ?>
                    <select id="<?= e($key) ?>" name="<?= e($key) ?>" class="custom-select<?= invalid_class($errors, $key) ?>" <?= $canEdit ? '' : 'disabled' ?><?= invalid_attrs($errors, $key) ?>>
                      <?php foreach (I18N_NAMES as $code => $name): ?>
                        <option value="<?= e($code) ?>" <?= old($key, $s['setting_value']) === $code ? 'selected' : '' ?>><?= e($name) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <?= field_feedback($errors, $key) ?>
                  <?php else: ?>
                    <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" class="form-control text-right<?= invalid_class($errors, $key) ?>" <?= $canEdit ? '' : 'readonly' ?>
                           inputmode="<?= $type === 'brackets' ? 'text' : ($type === 'or' || $type === 'int' ? 'numeric' : 'decimal') ?>" value="<?= e(old($key, $s['setting_value'])) ?>"<?= invalid_attrs($errors, $key) ?>>
                    <?= field_feedback($errors, $key) ?>
                  <?php endif; ?>
                  <?php if ($type === 'or'): ?><small class="form-text text-muted">Next receipt will be No. <?= e(format_or((int) $s['setting_value'] + 1)) ?>. Set this to match the booklet; it can only move forward.</small><?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <hr>
          <div class="small text-muted">Last member no. issued: <strong><?= e('FFMPC-' . date('Y') . '-' . str_pad($settings['member_counter']['setting_value'], 4, '0', STR_PAD_LEFT)) ?></strong> (managed by the system).</div>
        </div>
        <?php if ($canEdit): ?>
          <div class="card-footer bg-white"><button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save settings</button></div>
        <?php endif; ?>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Check: FFMPC's ₱26,000 sample loan</h3></div>
      <div class="card-body">
        <table class="table table-sm mb-2">
          <tr><td>Loan insurance</td><td class="num"><?= e(money($example['insurance'])) ?></td></tr>
          <tr><td>Service fee</td><td class="num"><?= e(money($example['service_fee'])) ?></td></tr>
          <tr><td>Stockshare</td><td class="num"><?= e(money($example['stockshare'])) ?></td></tr>
          <tr><td>Notarial fee</td><td class="num"><?= e(money($example['notarial_fee'])) ?></td></tr>
          <tr><td>Others (printing)</td><td class="num"><?= e(money($example['other_fee'])) ?></td></tr>
          <tr class="border-top"><td>Total deductions</td><td class="num"><?= e(money($example['total'])) ?></td></tr>
          <tr class="font-weight-bold"><td>Net proceeds</td><td class="num"><?= e(money($example['net'])) ?></td></tr>
        </table>
        <p class="small text-muted mb-0">With the default rates this matches the cooperative's own computation sheet (total deductions ₱1,675.60, net ₱24,324.40). Interest (3%) and the after-term penalty (4%) are set per loan product.</p>
      </div>
    </div>
  </div>
</div>
