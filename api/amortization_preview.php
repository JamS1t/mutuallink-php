<?php
declare(strict_types=1);

/**
 * POST api/amortization_preview.php   body: {"product_id":1,"principal":"10000","term_months":3}
 * → 200 {"success":true,"data":{"schedule":[...],"total_interest":600,"total_payable":10600,"deductions":{...}},"errors":[]}
 * → 422 with messages when the amount/term is outside the product limits.
 * Nothing is saved; the schedule is recomputed on the server at release.
 */

require_once __DIR__ . '/_bootstrap.php';
api_bootstrap('POST', 'loans', 'create');

$body = read_json_body();
$errors = [];

$productId = filter_var($body['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$term = filter_var($body['term_months'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 60]]);
$principalRaw = is_scalar($body['principal'] ?? null) ? str_replace(',', '', (string) $body['principal']) : '';
$principal = preg_match('/^\d+(\.\d{1,2})?$/', $principalRaw) ? (float) $principalRaw : null;

if ($productId === false) { $errors[] = 'Select a loan product.'; }
if ($term === false) { $errors[] = 'Term must be 1 to 60 months.'; }
if ($principal === null || $principal <= 0) { $errors[] = 'Enter a valid principal amount.'; }

$product = null;
if (!$errors) {
    $stmt = db()->prepare("SELECT min_amount, max_amount, term_months, interest_rate FROM loan_products WHERE product_id = :id AND status = 'active'");
    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch();
    if (!$product) {
        $errors[] = 'Loan product not found.';
    } else {
        if ($principal < (float) $product['min_amount'] || $principal > (float) $product['max_amount']) {
            $errors[] = 'Principal must be between ' . money($product['min_amount']) . ' and ' . money($product['max_amount']) . '.';
        }
        if ($term > (int) $product['term_months']) {
            $errors[] = 'Maximum term for this product is ' . (int) $product['term_months'] . ' months.';
        }
    }
}
if ($errors) {
    json_out(422, null, $errors);
}

$schedule = build_schedule($principal, $term, (float) $product['interest_rate'], date('Y-m-d'));
$interest = money_round(array_sum(array_column($schedule, 'interest_due')));
$deductions = compute_deductions($principal, [
    'service_fee_pct'   => (float) setting('service_fee_pct'),
    'insurance_pct'     => (float) setting('insurance_pct'),
    'cbu_retention_pct' => (float) setting('cbu_retention_pct'),
    'notarial_fee'      => (float) setting('notarial_fee'),
], 0.0);

json_out(200, [
    'schedule'       => $schedule,
    'total_interest' => $interest,
    'total_payable'  => money_round($principal + $interest),
    'deductions'     => $deductions,
]);
