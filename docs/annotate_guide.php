<?php
declare(strict_types=1);

/**
 * Adds numbered callouts (circle + arrow + label) and a step list to every
 * screenshot in documentation/index.html. Safe to re-run: earlier output
 * between <!--ann--> markers is removed first.
 *
 * Usage: php docs/annotate_guide.php
 *
 * Each callout: [target center x, y, target width, height, label x, y, label, step text, padding?]
 * Coordinates are pixels of the screenshot file.
 */

const ANNOTATIONS = [
    '01-login' => [
        [720, 479, 336, 40, 300, 479, 'Username or email', 'Type your username (for example, cashier) or your email address.'],
        [720, 563, 336, 40, 300, 563, 'Password', 'Type your password. The eye button shows what you typed.'],
        [720, 625, 336, 40, 1140, 625, 'Sign in', 'Click Sign in. Five wrong attempts lock sign-in for 30 seconds.'],
    ],
    '02-dashboard' => [
        [125, 260, 236, 40, 470, 520, 'Your menu', 'The sidebar lists only the modules your role may use.'],
        [838, 270, 1150, 250, 560, 600, 'Today at a glance', 'Members, loan portfolio, collections, past-due loans, share capital, savings, and upcoming dues.'],
        [1224, 530, 370, 150, 820, 625, 'Quick actions', 'Shortcuts to the tasks of the signed-in role.'],
        [1224, 760, 370, 260, 760, 845, 'Recent activity', 'Latest entries from the audit log.'],
    ],
    '03-register-member' => [
        [644, 300, 736, 60, 560, 160, 'Personal details', 'Fill in the name, birthdate, civil status, address, and contact numbers.'],
        [462, 742, 352, 40, 125, 742, 'TIN (required)', 'Enter the TIN. FFMPC requires it for membership.'],
        [1222, 333, 330, 40, 1150, 160, 'PMES date', 'Date the applicant attended the pre-membership seminar.'],
        [1197, 508, 290, 30, 1222, 452, 'Signature card', 'Tick when the signature specimen card is received.'],
        [1222, 700, 330, 40, 1222, 820, 'Register member', 'Click to save. The member number and the Share Capital and Regular Savings accounts are created.'],
    ],
    '04-applicant-record' => [
        [606, 365, 650, 290, 1100, 480, 'Requirements checklist', 'Green is done, red is still missing. Here the fee and the share capital are missing.'],
        [1162, 318, 452, 40, 1162, 395, 'Receive membership fee', 'Enter the fee and click Receive & issue OR. The receipt opens.'],
        [325, 606, 80, 26, 125, 720, 'Status: Applicant', 'The member stays an Applicant until the Manager approves.'],
        [426, 769, 290, 30, 830, 772, 'Cannot borrow yet', 'The eligibility summary explains why a loan is not possible yet.'],
    ],
    '05-membership-fee-receipt' => [
        [852, 291, 80, 30, 1110, 291, 'OR number', 'The next number in the official receipt series.'],
        [847, 477, 90, 30, 1110, 477, 'Amount received', 'The membership fee paid by the applicant.'],
        [1062, 35, 136, 38, 1150, 175, 'Print receipt', "Print the member's copy."],
    ],
    '06-share-capital-deposit' => [
        [552, 221, 530, 38, 1000, 105, 'Deposit only', 'Share capital accepts deposits; withdrawals are not allowed.'],
        [417, 335, 260, 48, 1285, 445, 'Amount and date', 'Enter ₱2,000 for the initial share capital.'],
        [388, 536, 204, 38, 650, 650, 'Post and issue receipt', 'Saves the deposit, updates the balance, and issues an OR.'],
    ],
    '07-withdrawal-maintaining-balance' => [
        [677, 221, 280, 38, 950, 120, 'Withdrawal', 'Choose Withdrawal and enter the amount (₱800 here).'],
        [523, 450, 480, 30, 600, 630, 'Passbook presented', 'Required for every withdrawal.'],
        [972, 250, 180, 24, 1285, 300, 'Withdrawable now', 'The most that can be withdrawn while keeping ₱500.'],
        [1278, 59, 300, 92, 1285, 200, 'Refused', '₱800 would break the ₱500 maintaining balance, so the system refuses it.'],
    ],
    '08-passbook' => [
        [452, 222, 80, 70, 452, 360, 'Receipt number', 'Each line carries its own OR number.'],
        [932, 222, 100, 70, 900, 360, 'Running balance', 'The balance after each transaction.'],
        [1343, 91, 162, 38, 960, 100, 'Post transaction', 'Opens the deposit or withdrawal form.'],
    ],
    '09-applicants-list' => [
        [382, 176, 82, 32, 420, 560, 'Applicants filter', 'Shows everyone waiting for approval.'],
        [1220, 343, 68, 24, 1000, 560, 'Status: Applicant', 'Changes to Active after approval.'],
        [1298, 343, 34, 34, 1300, 560, 'Open record', 'Opens the member record, where the Manager approves.'],
    ],
    '10-approve-membership' => [
        [420, 365, 270, 290, 720, 400, 'All requirements met', 'PMES, signature card, TIN, membership fee, and ₱2,000 share capital are all green.'],
        [1163, 287, 452, 40, 1163, 400, 'Approve membership', 'Enabled only when every requirement is met.'],
    ],
    '11-member-record-active' => [
        [314, 201, 58, 26, 640, 138, 'Status: Active', 'The member can now apply for loans.'],
        [550, 276, 560, 40, 1080, 276, 'Record tabs', 'Profile, accounts, loans, and payment history.'],
        [610, 510, 650, 390, 1175, 620, 'Eligibility summary', 'Checked before every loan: membership, payment record, co-maker, collateral, existing loans, share capital.'],
    ],
    '12-loan-application' => [
        [596, 265, 620, 40, 720, 218, 'Loan product', 'Regular, Salary, or Emergency Loan. The allowed range is shown below the field.'],
        [596, 375, 620, 40, 830, 330, 'Principal and term', 'The amount and the number of months.'],
        [596, 461, 620, 40, 660, 416, 'Co-maker', 'Required for every loan.'],
        [596, 610, 604, 210, 1175, 800, 'Collateral', 'Required for members outside the school; loanable up to 30% of the appraised value.'],
        [1175, 390, 466, 480, 1175, 680, 'Eligibility summary', 'Shown beside the form while you encode.'],
    ],
    '12b-amortization-preview' => [
        [456, 522, 78, 745, 456, 560, 'Same principal', '₱4,166.67 every month (equal principal).'],
        [548, 522, 78, 745, 548, 650, 'Interest', '3% of the remaining balance, so it goes down every month.'],
        [642, 522, 82, 745, 642, 740, 'Total due', 'Principal plus interest: ₱5,666.67 in the first month.'],
        [765, 522, 78, 745, 765, 830, 'Semi-monthly', 'Half the monthly amount, for salary deductions on the 15th and 30th.'],
    ],
    '13-loan-pending' => [
        [478, 181, 400, 30, 850, 240, 'Status and progress', 'Pending → Approved → Released → Fully paid.'],
        [650, 520, 760, 420, 650, 795, 'Eligibility summary', 'Information for the credit committee; the system does not decide.'],
        [1236, 470, 370, 300, 1236, 720, 'Details', 'Co-maker, collateral, and how the member will pay.'],
    ],
    '14-loan-decision' => [
        [1223, 333, 330, 32, 880, 315, 'Committee note', 'Optional note from the credit committee meeting.', 4],
        [1223, 384, 330, 38, 880, 395, 'Approve', "Records the Manager's approval.", 4],
        [1223, 430, 330, 38, 880, 470, 'Reject', 'Records a rejection instead.', 4],
    ],
    '15-loan-release' => [
        [1223, 265, 330, 38, 880, 265, 'Release date', 'The date the proceeds are released.', 6],
        [1223, 351, 330, 40, 880, 360, 'Cash or check', 'A check needs its check number.', 6],
        [1223, 560, 330, 250, 880, 560, 'Deductions', 'Insurance 0.56%, service fee 3%, stockshare 2%, notarial ₱200, printing ₱30.', 6],
        [1223, 706, 330, 34, 880, 680, 'Net proceeds', 'What the member actually receives.', 6],
        [1223, 776, 330, 38, 640, 790, 'Release', 'Releases the loan and generates the amortization schedule.', 6],
    ],
    '16-loan-released' => [
        [1352, 91, 116, 38, 1060, 100, 'Schedule', 'Opens the printable schedule.'],
        [818, 240, 110, 30, 1150, 220, 'Outstanding principal', 'Goes down as payments are posted.'],
        [1326, 430, 70, 110, 1250, 313, 'Installment status', 'Paid, Partial, or Unpaid for each month.'],
    ],
    '17-schedule-print' => [
        [1088, 35, 84, 38, 1275, 110, 'Print', "Prints the member's copy."],
        [934, 600, 140, 520, 1275, 600, 'Semi-monthly column', 'The amount for each payroll cut-off.'],
        [905, 955, 370, 100, 1275, 955, 'After-term rule', '3% interest plus 4% penalty per month once the term ends.'],
        [523, 1015, 370, 220, 165, 1015, 'Deductions', 'Deductions at release and the net proceeds.'],
        [720, 1180, 760, 40, 165, 1180, 'Signatures', 'The borrower and the authorized officer sign here.'],
    ],
    '18-payment-choose-loan' => [
        [552, 238, 530, 48, 552, 420, 'Find the member', 'Type a name or member number, then pick from the list.'],
        [1138, 226, 570, 64, 1138, 420, 'Choose the loan', 'Click the loan being paid.'],
    ],
    '19-payment-advance' => [
        [600, 270, 628, 48, 760, 222, 'Amount received', '₱12,000 here, which is more than one installment.', 4],
        [562, 314, 560, 30, 760, 352, 'Quick amounts', 'Fill in the amount due now, the semi-monthly amount, or the full payoff.', 4],
        [600, 524, 628, 150, 800, 650, 'How it will be applied', 'Penalty → interest → principal. Installments 1, 2, and 3 are covered.'],
        [439, 677, 304, 48, 600, 785, 'Post payment and issue OR', 'Saves the payment and opens the receipt.'],
        [1187, 378, 440, 80, 1187, 545, 'Due now / full payoff', 'What is due today, and the amount that closes the loan (no rebate).'],
    ],
    '20-payment-receipt' => [
        [852, 291, 80, 30, 1120, 291, 'OR number', 'One receipt for the whole payment.'],
        [720, 510, 350, 130, 1120, 510, 'Installments covered', 'Every installment this payment settled.'],
        [832, 668, 120, 32, 1120, 668, 'Total paid', 'Split into interest and principal just above.'],
        [850, 741, 90, 26, 1120, 752, 'Remaining balance', 'Outstanding principal after this payment.'],
        [1062, 35, 136, 38, 1150, 180, 'Print receipt', "Print the member's copy."],
    ],
    '21-payment-after-term' => [
        [1187, 291, 432, 64, 1080, 118, 'Term ended', 'The loan term ended 3 months ago.', 6],
        [1187, 380, 432, 78, 760, 440, 'After-term charges', 'Penalty 4% × 3 = ₱360 and interest 3% × 3 = ₱270, collected first.', 6],
        [1187, 517, 432, 34, 1187, 700, 'Due now', '₱1,720 including the after-term charges.', 6],
    ],
    '22-void-receipt' => [
        [712, 492, 466, 62, 1190, 470, 'Reason (required)', 'Why the receipt is voided.'],
        [890, 576, 110, 38, 1190, 576, 'Void receipt', 'Confirms the void; the receipt stays on record.'],
        [1004, 660, 34, 40, 1190, 680, 'Latest receipt only', 'Only the newest receipt of a loan has a Void button.'],
    ],
    '23-voided-payments' => [
        [1200, 497, 240, 46, 1150, 410, 'Marked VOID', 'The voided receipt stays on record with its reason.'],
        [548, 780, 520, 34, 700, 650, 'Due again', 'Installment 3 is due again because the void restored it.'],
    ],
    '24-delinquency' => [
        [1329, 91, 162, 38, 980, 118, 'Update list now', 'Scans every schedule against the payments received.'],
        [978, 196, 270, 92, 700, 283, 'Portfolio at risk', 'Share of the loan portfolio that is past due.'],
        [840, 478, 1140, 36, 1200, 283, 'Aging bracket', 'This loan is 133 days past due, in the 91–180 bracket.'],
        [1339, 811, 76, 42, 900, 674, 'Demand letter', 'Prints a letter for the borrower, with a copy for the co-maker.'],
    ],
    '25-demand-letter' => [
        [569, 570, 452, 118, 960, 560, 'Unpaid installments', 'Listed with their due dates.'],
        [569, 689, 452, 36, 960, 690, 'Total past due', 'Includes the after-term interest and penalty.', 6],
        [500, 849, 100, 24, 165, 849, 'Co-maker copy', 'The co-maker gets a copy and is liable.'],
        [1067, 35, 126, 38, 1275, 110, 'Print letter', 'Print it and have the borrower sign on receipt.'],
    ],
    '26-settings' => [
        [596, 340, 620, 290, 1175, 650, 'Deduction rates', 'Insurance, service fee, stockshare, notarial, and printing fees.'],
        [596, 720, 620, 180, 1175, 750, 'Account minimums', 'Share capital, maintaining balance, time deposit, and CBU.'],
        [1175, 340, 466, 300, 1250, 120, 'Sample check', "Recomputes FFMPC's ₱26,000 sample with the current rates."],
    ],
    '27-reminders' => [
        [1331, 91, 188, 38, 1060, 118, 'Generate reminders', 'Creates messages for dues within 3 days and for overdue loans.'],
        [430, 176, 300, 36, 850, 176, 'To send / Sent / Failed', 'Filter the reminders by status.'],
        [1272, 391, 32, 32, 1080, 600, 'Open in email app', 'Opens a prepared email to the member.', 4],
        [1335, 391, 70, 36, 1320, 600, 'Mark sent or failed', 'Record the result after sending.', 4],
    ],
    '28-user-accounts' => [
        [1365, 91, 118, 38, 1080, 110, 'Add user', 'Create a staff account and choose its role.'],
        [838, 398, 120, 250, 838, 700, 'Role', 'Decides what each person may do.'],
        [1339, 398, 110, 250, 1250, 700, 'Deactivate', 'Accounts are deactivated, never deleted.'],
        [418, 455, 36, 22, 500, 700, 'You', 'You cannot deactivate your own account.'],
    ],
    '29-report-daily-collection' => [
        [582, 176, 600, 40, 1150, 176, 'Report tabs', 'Each role sees only its reports.'],
        [392, 250, 130, 32, 760, 250, 'Date', 'Pick the day to balance.'],
        [895, 322, 110, 50, 1200, 322, 'Per cashier', 'Total collected by each cashier.'],
        [379, 375, 186, 32, 760, 375, 'Export or print', 'Copy, CSV, Excel, or Print.'],
        [1175, 814, 200, 30, 800, 862, 'Totals', 'Cash in and cash out for the day.'],
    ],
    '30-report-salary-deduction' => [
        [794, 176, 200, 40, 1150, 176, 'Salary deduction list', 'Prepared by the Bookkeeper for payroll.'],
        [509, 250, 220, 32, 800, 250, 'Payroll month', 'Choose the month.'],
        [1315, 395, 170, 30, 1250, 650, '15th and 30th', 'The amount to deduct on each cut-off.'],
        [730, 473, 890, 36, 700, 650, 'Total to deduct', 'The sum for the whole list.'],
    ],
    '31-audit-log' => [
        [837, 219, 1110, 44, 1100, 110, 'Filters', 'By date range, user, and record type.'],
        [530, 436, 100, 70, 700, 328, 'Who', 'The user and role that made the change.'],
        [1105, 434, 400, 30, 1000, 328, 'What changed', 'Details of the action, such as the voided OR and its reason.'],
    ],
    '32-access-denied' => [
        [845, 218, 440, 30, 500, 520, 'Blocked', 'The Board / Auditor tried to open Add user.'],
        [845, 317, 160, 80, 1150, 330, 'Back to dashboard', 'Returns to the pages the role may use.'],
    ],
    '33-auditor-share-capital' => [
        [961, 176, 142, 40, 1320, 176, 'Share capital', 'Report tab, read-only for the Board / Auditor.'],
        [660, 307, 760, 50, 1250, 300, 'Summary', 'Total paid-up capital, members, average, and members below the minimum.'],
        [1350, 468, 80, 80, 1250, 690, '% of total', "Each member's share of the total."],
        [379, 364, 186, 32, 800, 364, 'Export or print', 'Copy, CSV, Excel, or Print.'],
    ],
];

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Small icons get a circle, badges a pill, big regions a dashed box, everything else a rounded box. */
function shape_for(float $w, float $h): string
{
    if ($w <= 60 && $h <= 60) {
        return 'circle';
    }
    if ($w >= 400 && $h >= 100 || $h >= 200) {
        return 'area';
    }
    if ($h <= 30 && $w <= 130) {
        return 'pill';
    }
    return 'rect';
}

function overlay(string $slug, array $items, int $w, int $h): string
{
    $svg = '';
    $labels = '';
    foreach ($items as $i => $a) {
        [$cx, $cy, $tw, $th, $lx, $ly, $label] = $a;
        $shape = is_string($a[8] ?? null) ? $a[8] : shape_for($tw, $th);
        $pad = 6;
        $hw = $tw / 2 + $pad;
        $hh = $th / 2 + $pad;

        if ($shape === 'circle') {
            $r = max($hw, $hh);
            $svg .= sprintf('<circle class="ann-halo" cx="%d" cy="%d" r="%.1f"/><circle class="ann-ring" cx="%d" cy="%d" r="%.1f"/>', $cx, $cy, $r, $cx, $cy, $r);
        } else {
            // rect: rounded box · pill: fully rounded · area: dashed box for large regions
            $corner = $shape === 'pill' ? $hh : 8;
            $cls = $shape === 'area' ? 'ann-ring ann-area' : 'ann-ring';
            $box = sprintf('x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="%.1f"', $cx - $hw, $cy - $hh, $hw * 2, $hh * 2, $corner);
            $svg .= '<rect class="ann-halo" ' . $box . '/><rect class="' . $cls . '" ' . $box . '/>';
        }

        // Arrow from the label to the shape's edge (skipped when the label sits inside the shape).
        $dx = $cx - $lx;
        $dy = $cy - $ly;
        $dist = sqrt($dx ** 2 + $dy ** 2);
        $s = $shape === 'circle'
            ? max($hw, $hh) / max($dist, 1)
            : min($dx ? $hw / abs($dx) : INF, $dy ? $hh / abs($dy) : INF);
        if ($s < 0.95) {
            $t = $s + 8 / max($dist, 1); // stop just outside the edge
            $ex = $cx - $dx * $t;
            $ey = $cy - $dy * $t;
            $svg .= sprintf('<line class="ann-halo" x1="%d" y1="%d" x2="%.1f" y2="%.1f"/><line class="ann-line" x1="%d" y1="%d" x2="%.1f" y2="%.1f" marker-end="url(#ah-%s)"/>', $lx, $ly, $ex, $ey, $lx, $ly, $ex, $ey, $slug);
        }
        $labels .= sprintf('<span class="ann-label" style="left:%.2f%%;top:%.2f%%"><b>%d</b><span>%s</span></span>', $lx / $w * 100, $ly / $h * 100, $i + 1, h($label));
    }
    return '<!--ann--><span class="ann" aria-hidden="true"><svg viewBox="0 0 ' . $w . ' ' . $h . '">'
        . '<defs><marker id="ah-' . $slug . '" viewBox="0 0 10 10" refX="7" refY="5" markerWidth="4" markerHeight="4" orient="auto"><path d="M0,0 L10,5 L0,10 z" class="ann-head"/></marker></defs>'
        . $svg . '</svg>' . $labels . '</span><!--/ann-->';
}

function steps(array $items): string
{
    $li = '';
    foreach ($items as $i => $a) {
        $li .= '<li><b>' . ($i + 1) . '</b><span><strong>' . h($a[6]) . '.</strong> ' . h($a[7]) . '</span></li>';
    }
    return '<!--ann--><ol class="ann-steps">' . $li . '</ol><!--/ann-->';
}

$file = __DIR__ . '/../documentation/index.html';
$html = preg_replace('#<!--ann-->.*?<!--/ann-->#s', '', file_get_contents($file));
$html = str_replace('class="shot shot-narrow"', 'class="shot"', $html);

$count = 0;
$html = preg_replace_callback(
    '#(<figure class="shot"><a [^>]*href="screenshots/([^"]+)\.png"><img [^>]*>)(</a><figcaption>.*?</figcaption>)#s',
    function (array $m) use (&$count): string {
        $slug = $m[2];
        if (!isset(ANNOTATIONS[$slug])) {
            return $m[0];
        }
        [$w, $h] = getimagesize(__DIR__ . '/../documentation/screenshots/' . $slug . '.png');
        $count++;
        return $m[1] . overlay($slug, ANNOTATIONS[$slug], $w, $h) . $m[3] . steps(ANNOTATIONS[$slug]);
    },
    $html
);

file_put_contents($file, $html);
echo "Annotated {$count} of " . count(ANNOTATIONS) . " screenshots\n";
