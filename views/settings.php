<?php
declare(strict_types=1);

// Rates and defaults the Manager can change without touching code.
$title = 'Settings';
$subtitle = 'Deduction rates, eligibility minimum, reminder lead time, and aging brackets. Only the Manager can change these.';
$canEdit = can('settings', 'update');

// key => [validator type, min, max]
$editable = [
    'service_fee_pct'    => ['pct', 0, 20],
    'insurance_pct'      => ['pct', 0, 20],
    'cbu_retention_pct'  => ['pct', 0, 20],
    'notarial_fee'       => ['money', 0, 10000],
    'min_share_capital'  => ['money', 0, 1000000],
    'reminder_lead_days' => ['int', 1, 30],
    'aging_brackets'     => ['brackets', 0, 0],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('settings', 'update');
    $errors = [];
    $values = [];
    $labels = db()->query('SELECT setting_key, label FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($editable as $key => [$type, $min, $max]) {
        $label = $labels[$key] ?? $key;
        if ($type === 'int') {
            $v = int_in($errors, $key, $label, $min, $max);
            $values[$key] = (string) $v;
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
            $old = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
            $upd = $pdo->prepare('UPDATE settings SET setting_value = :v WHERE setting_key = :k');
            $changes = [];
            foreach ($values as $k => $v) {
                if (($old[$k] ?? null) !== $v) {
                    $upd->execute([':v' => $v, ':k' => $k]);
                    $changes[] = "$k: {$old[$k]} → $v";
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
$example = compute_deductions(10000.0, [
    'service_fee_pct' => (float) $settings['service_fee_pct']['setting_value'], 'insurance_pct' => (float) $settings['insurance_pct']['setting_value'],
    'cbu_retention_pct' => (float) $settings['cbu_retention_pct']['setting_value'], 'notarial_fee' => (float) $settings['notarial_fee']['setting_value'],
], 0.0);
?>
<div class="row">
  <div class="col-lg-7">
    <div class="card card-primary card-outline">
      <form method="post" action="" class="ml-form" novalidate>
        <?= csrf_field() ?>
        <div class="card-body">
          <?php foreach ($editable as $key => [$type]): $s = $settings[$key]; ?>
            <div class="form-group row">
              <label for="<?= e($key) ?>" class="col-md-7 col-form-label"><?= e($s['label']) ?></label>
              <div class="col-md-5">
                <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" class="form-control text-right" <?= $canEdit ? '' : 'readonly' ?>
                       inputmode="<?= $type === 'brackets' ? 'text' : 'decimal' ?>" value="<?= e(old($key, $s['setting_value'])) ?>">
              </div>
            </div>
          <?php endforeach; ?>
          <hr>
          <div class="small text-muted">
            Last OR issued: <strong><?= e('OR-' . date('Y') . '-' . str_pad($settings['or_counter']['setting_value'], 6, '0', STR_PAD_LEFT)) ?></strong> ·
            Last member no.: <strong><?= e('FFMC-' . date('Y') . '-' . str_pad($settings['member_counter']['setting_value'], 4, '0', STR_PAD_LEFT)) ?></strong>
            (counters are managed by the system).
          </div>
        </div>
        <?php if ($canEdit): ?>
          <div class="card-footer bg-white"><button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save settings</button></div>
        <?php endif; ?>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Example: ₱10,000 loan</h3></div>
      <div class="card-body">
        <table class="table table-sm mb-2">
          <tr><td>Service fee</td><td class="num"><?= e(money($example['service_fee'])) ?></td></tr>
          <tr><td>Insurance</td><td class="num"><?= e(money($example['insurance'])) ?></td></tr>
          <tr><td>CBU retention</td><td class="num"><?= e(money($example['cbu_retention'])) ?></td></tr>
          <tr><td>Notarial fee</td><td class="num"><?= e(money($example['notarial_fee'])) ?></td></tr>
          <tr class="font-weight-bold border-top"><td>Net proceeds</td><td class="num"><?= e(money($example['net'])) ?></td></tr>
        </table>
        <p class="small text-muted mb-0">Current values are placeholders to be confirmed with the FFMC bookkeeper. Interest and penalty rates are set per loan product.</p>
      </div>
    </div>
  </div>
</div>
