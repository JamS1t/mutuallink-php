"""
Builds the screenshots and callouts of documentation/index.html.

    python docs/build_guide.py capture   # walk the sample scenario, save PNGs + docs/guide_marks.json
    python docs/build_guide.py           # inject the callouts into documentation/index.html (safe to re-run)

Every highlight box is measured from the live page at the moment the
screenshot is taken, so a shape can never drift from its target. The label
chip is attached to its box (no arrows, no hand-typed coordinates).

`capture` POSTS TRANSACTIONS (registers a member, files and releases a loan,
posts and voids payments). Point it only at a throwaway copy of the system
(a copy of the code running on a copy of the database with the sample
members Juan Dela Cruz and Ana Reyes):

    set GUIDE_BASE=http://127.0.0.1:8099/

It refuses to run against the WampServer address.
Requires: pip install playwright (uses the installed Microsoft Edge).
"""
import html
import json
import os
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DOC = ROOT / 'documentation' / 'index.html'
SHOTS = ROOT / 'documentation' / 'screenshots'
MARKS = Path(__file__).with_name('guide_marks.json')
BASE = os.environ.get('GUIDE_BASE', '')
PASSWORD = os.environ.get('GUIDE_PASSWORD', 'Password123')
W = 1440


# ───────────────────────── capture ─────────────────────────

COLUMN_JS = """(t, [head, rows]) => {
  const ths = [...t.querySelectorAll('thead th')];
  const i = ths.findIndex(th => th.innerText.trim().toLowerCase().startsWith(head.toLowerCase()));
  if (i < 0 || !t.getBoundingClientRect().width) return null;
  const cells = [ths[i], ...[...t.querySelectorAll('tbody tr')].slice(0, rows).map(r => r.children[i]).filter(Boolean)];
  const rs = cells.map(c => c.getBoundingClientRect()).filter(r => r.width);
  const x0 = Math.min(...rs.map(r => r.left)), y0 = Math.min(...rs.map(r => r.top));
  const x1 = Math.max(...rs.map(r => r.right)), y1 = Math.max(...rs.map(r => r.bottom));
  return {x: x0, y: y0, width: x1 - x0, height: y1 - y0};
}"""


INK_JS = """() => {
  const out = [];
  const shown = el => el.checkVisibility({checkOpacity: true, checkVisibilityCSS: true});
  const add = r => { if (r.width > 2 && r.height > 2 && r.right > 0 && r.left < innerWidth) out.push([r.left, r.top + scrollY, r.width, r.height].map(Math.round)); };
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  const range = document.createRange();
  for (let n; (n = walker.nextNode());) {
    const el = n.parentElement;
    if (!n.nodeValue.trim() || !el || el.closest('script, style, option') || !shown(el)) continue;
    range.selectNodeContents(n);
    for (const r of range.getClientRects()) add(r);
  }
  for (const el of document.querySelectorAll('input:not([type=hidden]), select, textarea, button, .btn, img, canvas, i, .badge, .kpi-icon, .avatar, .toast'))
    if (shown(el)) add(el.getBoundingClientRect());
  return out;
}"""


class Walk:
    """One browser, one signed-in role at a time. snap() = screenshot + measured marks."""

    def __init__(self, browser, only):
        self.browser, self.only, self.ctx, self.pg = browser, only, None, None
        self.out = json.loads(MARKS.read_text(encoding='utf-8')) if MARKS.exists() else {}

    def login(self, user):
        if self.ctx:
            self.ctx.close()
        self.ctx = self.browser.new_context(viewport={'width': W, 'height': 900}, device_scale_factor=1)
        self.pg = self.ctx.new_page()
        self.pg.goto(BASE)
        if user:
            self.pg.fill('#login_id', user)
            self.pg.fill('#password', PASSWORD)
            self.pg.click('button[type=submit]')
            self.pg.wait_for_url('**/dashboard.php*')
        return self.pg

    def go(self, query):
        self.pg.goto(BASE + 'dashboard.php?page=' + query)
        self.pg.wait_for_load_state('networkidle')

    def submit(self, selector):
        self.pg.click(selector)
        self.pg.wait_for_load_state('networkidle')

    def lookup(self, field, text, pick=None):
        """Type into a member-lookup box; optionally click the result containing `pick`."""
        self.pg.fill(field, '')
        self.pg.type(field, text, delay=30)
        self.pg.wait_for_selector('.lookup-results .list-group-item')
        if pick:
            self.pg.click(f'.lookup-results .list-group-item:has-text("{pick}")')
            self.pg.wait_for_load_state('networkidle')

    def ask(self, selector, reason=None):
        """Click a button whose form opens the confirmation dialog; leaves the dialog open."""
        self.pg.click(selector)
        self.pg.wait_for_selector('.ml-confirm-ok', state='visible')
        if reason:
            self.pg.fill('#ml-reason', reason)
        self.pg.wait_for_timeout(400)  # modal fade

    def confirm(self):
        self.pg.click('.ml-confirm-ok')
        self.pg.wait_for_load_state('networkidle')

    def box(self, sel):
        """Box (page pixels) of a target: a selector, a list of selectors (union), or ('col', table, header[, rows])."""
        if isinstance(sel, tuple) and sel[0] == 'col':
            tables = self.pg.locator(sel[1])
            arg = [sel[2], sel[3] if len(sel) > 3 else 12]
            boxes = [b for b in (tables.nth(i).evaluate(COLUMN_JS, arg) for i in range(tables.count())) if b][:1]
        else:
            many = isinstance(sel, list)
            boxes = []
            for s in (sel if many else [sel]):
                loc = self.pg.locator(s)
                for i in range(loc.count()):
                    b = loc.nth(i).bounding_box()
                    if b and b['width'] > 0 and b['height'] > 0:
                        boxes.append(b)
                        if not many:
                            break  # a single selector means its first visible match
                if boxes and not many:
                    break
        if not boxes:
            return None
        # clip to the window: a wide table can scroll sideways inside its card
        x0 = max(0, min(b['x'] for b in boxes))
        y0 = min(b['y'] for b in boxes)
        x1 = min(W, max(b['x'] + b['width'] for b in boxes))
        y1 = max(b['y'] + b['height'] for b in boxes)
        sy = 0 if isinstance(sel, tuple) else self.pg.evaluate('window.scrollY')
        return [round(x0), round(y0 + sy), round(x1 - x0), round(y1 - y0)]

    def snap(self, slug, marks, toast=False, wait=300, top=None):
        """top: selector of the element the picture should start at (crops the head of a long page)."""
        if self.only and slug not in self.only:
            return
        pg = self.pg
        pg.evaluate('window.scrollTo(0, 0)')
        if not toast:
            pg.evaluate("document.querySelectorAll('#toast-container').forEach(e => e.remove())")
        pg.wait_for_timeout(wait)

        def measure():
            found = []
            for sel, label, text in marks:
                b = self.box(sel)
                if b is None:
                    print(f'  !! {slug}: no element for "{label}" ({sel})')
                    continue
                found.append({'x': b[0], 'y': b[1], 'w': b[2], 'h': b[3], 'label': label, 'text': text})
            return found

        found = measure()
        page_h = pg.evaluate('Math.max(document.documentElement.scrollHeight, document.body.scrollHeight)')
        lowest = max([m['y'] + m['h'] for m in found] or [0])
        h = int(min(max(900, lowest + 70), max(900, page_h)))
        if h > 900:
            # A target is below the fold: grow the window to fit, then measure again in that layout,
            # so the picture and the boxes always come from the same rendering.
            pg.set_viewport_size({'width': W, 'height': h})
            pg.wait_for_timeout(250)
            found = measure()
        y0 = 0
        if top:
            y0 = max(0, min([self.box(top)[1]] + [m['y'] for m in found]) - 60)
            for m in found:
                m['y'] -= y0
        # everything drawn on the page (text lines, fields, buttons, icons): labels must not cover it
        ink = [[x, y - y0, bw, bh] for x, y, bw, bh in pg.evaluate(INK_JS) if y + bh > y0 and y < h]
        pg.screenshot(path=str(SHOTS / f'{slug}.png'), clip={'x': 0, 'y': y0, 'width': W, 'height': h - y0})
        if h > 900:
            pg.set_viewport_size({'width': W, 'height': 900})
        h -= y0
        self.out[slug] = {'w': W, 'h': h, 'marks': found, 'ink': ink}
        print(f'  {slug}: {len(found)}/{len(marks)} marks, {W}x{h}')

    def save(self):
        # one screenshot per line keeps the file diffable without thousands of lines of coordinates
        body = ',\n'.join(json.dumps(k) + ': ' + json.dumps(v, ensure_ascii=False, separators=(',', ':')) for k, v in self.out.items())
        MARKS.write_text('{\n' + body + '\n}\n', encoding='utf-8')


def card(title):
    return f'.card:has(.card-title:has-text("{title}"))'


def btn(text):
    return f':is(button, a.btn):has-text("{text}"):visible'


def group(field):
    """A form field together with its label."""
    return f'.form-group:has(#{field})'


def row(text):
    return f'tr:has-text("{text}")'


MENU = '.nav-sidebar'
ACTIONS = '.content-header .no-print'
TOAST = '#toast-container .toast'


def scenario(w):
    """The sample walkthrough, role by role. Order matters: each step builds on the one before."""
    glance = '.content .row:has(.kpi)'
    search_btn = 'button[data-target="#ml-search-modal"]'

    pg = w.login(None)
    w.snap('01-login', [
        ('.input-group:has(#login_id)', 'Username or email', 'Type your username (for example, cashier) or your email address.'),
        ('.input-group:has(#password)', 'Password', 'Type your password. The eye button shows what you typed.'),
        (btn('Sign in'), 'Sign in', 'Click Sign in. Five wrong attempts lock sign-in for 15 minutes.'),
        ('a:has-text("System guide")', 'System guide', 'Opens this guide from the sign-in page.'),
    ])

    # ════════ Cashier: registers the applicant, receives the fee and the share capital ════════
    pg = w.login('cashier')
    w.snap('dash-cashier', [
        (MENU, 'Your menu', 'Only the modules a Cashier may use.'),
        (glance, 'Today at a glance', "Today's collections and deposits come first for the Cashier."),
        (card('Quick actions'), 'Quick actions', 'Post a loan payment, post a deposit or withdrawal, register a member, open reports.'),
        (search_btn, 'Find member', 'Search any member from any page (Ctrl+K or /).'),
    ], wait=1000)

    w.go('members')
    w.snap('members-list', [
        (btn('Register member'), 'Register member', 'Opens the registration form (Cashier and Manager).'),
        ('.card-header .btn-group', 'Status filter', 'Active, Applicants, Inactive, or All.'),
        ('.dt-buttons', 'Export or print', 'Copy, CSV, Excel, or Print the list.'),
        ('.dataTables_filter', 'Search', 'Filters the table as you type.'),
        (('col', 'table', 'Actions'), 'Actions', 'Open the record (eye) or edit the profile (pen).'),
    ])

    w.go('member_form')
    for field, value in {'last_name': 'Santos', 'first_name': 'Pedro', 'middle_name': 'Garcia', 'birthdate': '1990-05-20',
                         'address': 'Zone 2, Baybay City, Leyte', 'contact_no': '09191234567', 'email': 'pedro.santos@example.com',
                         'occupation': 'Store owner', 'monthly_income': '30000', 'tin': '321-654-987', 'pmes_date': '2026-09-20'}.items():
        pg.fill('#' + field, value)
    pg.click('label[for=signature_on_file]')
    pg.select_option('#member_type', 'outside')
    w.snap('03-register-member', [
        (card('Personal information'), 'Personal details', 'Name, birthdate, civil status, address, mobile number, and email. Fields with * are required.'),
        (group('tin'), 'TIN (required)', 'FFMPC requires the TIN for membership.'),
        (group('pmes_date'), 'PMES date', 'Date the applicant attended the pre-membership seminar.'),
        ('.custom-control:has(#signature_on_file)', 'Signature card', 'Tick when the signature specimen card is received.'),
        (group('member_type'), 'Member type', 'Members from outside the school must give collateral for loans.'),
        (btn('Register member'), 'Register member', 'Saves the applicant; the member number and the Share Capital and Regular Savings accounts are created.'),
    ])
    w.submit(btn('Register member'))
    pid = re.search(r'id=(\d+)', pg.url).group(1)
    w.snap('04-applicant-record', [
        ('.check-list', 'Requirements checklist', 'Green is done, red is still missing. Here the fee and the share capital are missing.'),
        (btn('issue OR'), 'Receive membership fee', 'The fee is fixed (₱250, set in Settings). One click records it and opens the receipt.'),
        ('.stat-strip .stat:first-child', 'Status: Applicant', 'Stays an Applicant until the Manager approves.'),
        ('.nav-tabs', 'Record tabs', 'Eligibility, profile, accounts, loans, and payment history.'),
    ])
    w.submit(btn('issue OR'))
    w.snap('05-membership-fee-receipt', [
        ('.receipt tr:has-text("No.") td:last-child', 'OR number', 'The next number in the official receipt series.'),
        ('.receipt tr:has-text("AMOUNT")', 'Amount received', 'The membership fee paid by the applicant.'),
        (btn('thermal'), 'Print (thermal 80mm)', 'Narrow layout for the receipt printer.'),
        (btn('Print (report)'), 'Print (report)', 'Full layout for the office printer.'),
        (btn('Back'), 'Back', 'Returns to the member record.'),
    ])

    w.go(f'member_view&id={pid}')
    pg.click('a[href="#tab-accounts"]')
    share = re.search(r'id=(\d+)', pg.get_attribute('#tab-accounts tr:has-text("Share Capital") a', 'href')).group(1)
    w.snap('member-accounts', [
        ('a[href="#tab-accounts"]', 'Accounts tab', "The member's savings and share capital accounts."),
        (btn('Open another account'), 'Open another account', 'Adds a Capital Build-Up or Time Deposit account.'),
        ('#tab-accounts tr:has-text("Share Capital") a', 'Open passbook', 'Opens the passbook, where transactions are posted.'),
    ])

    w.go(f'savings_post&id={share}')
    pg.fill('#amount', '2000')
    pg.fill('#remarks', 'Initial share capital')
    w.snap('06-share-capital-deposit', [
        ('.btn-group-toggle', 'Deposit or withdraw', 'Deposit is selected. A share capital withdrawal needs a BOD resolution.'),
        ('.form-row:has(#amount)', 'Amount and date', 'Enter ₱2,000 for the initial share capital; the date defaults to today.'),
        (btn('issue receipt'), 'Post and issue receipt', 'Saves the deposit, updates the balance, and issues an OR.'),
        ('.col-lg-3 .card', 'Current balance', 'The balance before this transaction, with the rules of the account.'),
    ])
    w.submit(btn('issue receipt'))
    w.snap('savings-receipt', [
        ('.receipt tr:has-text("No.") td:last-child', 'OR number', 'Every deposit and withdrawal gets its own receipt number.'),
        ('.receipt tr:has-text("AMOUNT")', 'Amount', 'The amount deposited or withdrawn.'),
        ('.receipt tr:has-text("Balance after")', 'Balance after', 'The running balance written to the passbook.'),
        ('.print-actions', 'Print', 'Thermal 80mm or full report layout.'),
    ])

    w.go(f'savings&member_id={pid}')
    w.snap('savings-accounts', [
        (glance, 'Totals per account type', 'Click a tile to list only that type.'),
        ('tbody tr:first-child td:last-child', 'Passbook / Post', 'Open the passbook, or post a deposit or withdrawal directly.'),
        (card('Open an account'), 'Open an account', 'Choose the account type and click Open account. One account of each type per member.'),
        ('.dt-buttons', 'Export or print', 'Copy, CSV, Excel, or Print.'),
    ])

    w.go('savings_post&id=4')
    pg.click('label:has(input[value=withdrawal])')
    pg.fill('#amount', '700')
    pg.click('label[for=passbook_presented]')
    w.submit(btn('issue receipt'))
    w.snap('07-withdrawal-maintaining-balance', [
        ('.btn-group-toggle', 'Withdraw', 'Choose Withdraw and enter the amount (₱700 here).'),
        ('.custom-control:has(#passbook_presented)', 'Passbook presented', 'Required for every withdrawal.'),
        ('li:has-text("Withdrawable now")', 'Withdrawable now', 'The most that can be withdrawn while keeping the maintaining balance.'),
        (TOAST, 'Refused', '₱700 would go below the ₱500 maintaining balance, so the system refuses it and says how much is allowed.'),
    ], toast=True)

    w.go('passbook&id=4')
    w.snap('08-passbook', [
        (('col', 'table', 'OR no.'), 'Receipt number', 'Each line carries its own OR number; interest lines have none.'),
        (('col', 'table', 'Balance'), 'Running balance', 'The balance after each transaction.'),
        (btn('Post transaction'), 'Post transaction', 'Opens the deposit or withdrawal form.'),
        (btn('Print'), 'Print', 'Prints the passbook ledger.'),
    ])

    # ════════ Manager: approves the membership ════════
    pg = w.login('manager')
    w.go('members&status=applicant')
    w.snap('09-applicants-list', [
        ('.card-header .btn-group a:has-text("Applicants")', 'Applicants filter', 'Shows everyone waiting for approval.'),
        (('col', 'table', 'Status'), 'Status: Applicant', 'Changes to Active after approval.'),
        ('tbody tr:first-child td:last-child', 'Actions', 'Eye: open the record, where the Manager approves. Pen: edit the profile. Red button: deactivate (members are never deleted; refused while a loan is pending or running).'),
    ])
    w.go(f'member_view&id={pid}')
    w.snap('10-approve-membership', [
        ('.check-list', 'All requirements met', 'PMES, signature card, TIN, membership fee, and ₱2,000 share capital are all green.'),
        (btn('Approve membership'), 'Approve membership', 'Enabled only when every requirement is met.'),
    ])
    w.ask(btn('Approve membership'))
    w.confirm()
    w.snap('11-member-record-active', [
        ('.stat-strip .stat:first-child', 'Status: Active', 'The member can now apply for loans.'),
        ('.nav-tabs', 'Record tabs', 'Eligibility, profile, accounts, loans, and payment history.'),
        ('#tab-eligibility .col-lg-7', 'Eligibility summary', 'Checked before every loan: membership, payment record, co-maker, collateral, existing loans, share capital.'),
        (btn('Edit profile'), 'Edit profile', 'Manager, Cashier, and Loan Officer can correct the profile.'),
    ])

    # ════════ Loan Officer: files the application ════════
    pg = w.login('loanofficer')
    w.snap('dash-loanofficer', [
        (MENU, 'Your menu', 'Only the modules a Loan Officer may use.'),
        (glance, 'Today at a glance', 'Applications in process, past-due loans, and dues in 7 days come first.'),
        (card('Delinquency summary'), 'Delinquency summary', 'Accounts and amount past due, with the worst accounts listed.'),
        (card('Quick actions'), 'Quick actions', 'New loan application, update the delinquency list, open reports.'),
    ], wait=1000)

    w.go('loan_form')
    w.lookup('#loan-lookup', 'santos')
    w.snap('loan-find-member', [
        ('#loan-lookup', 'Find the member', 'Type a last name, first name, or member number.'),
        ('#loan-results', 'Pick the member', 'Only active members can borrow; applicants must be approved first.'),
    ])
    pg.click('#loan-results .list-group-item:has-text("Santos")')
    pg.wait_for_load_state('networkidle')
    pg.select_option('#product_id', label='Regular Loan')
    pg.fill('#principal', '50000')
    pg.fill('#term_months', '12')
    w.lookup('#co-maker-search', 'dela', 'Dela Cruz')
    pg.select_option('#collateral_type', 'real_estate')
    pg.fill('#collateral', 'TCT No. 12345, Brgy. Gaas, 1,082 sq m')
    pg.fill('#collateral_value', '3000000')
    pg.wait_for_selector('#preview-body tr')
    w.snap('12-loan-application', [
        (group('product_id'), 'Loan product', 'Regular, Salary, or Emergency Loan. The allowed range is shown below the field.'),
        ('.form-row:has(#principal)', 'Principal and term', 'The amount and the number of months.'),
        ('.form-group:has(#co-maker-search)', 'Co-maker', 'Required for every loan; must be an active FFMPC member, picked from the list.'),
        ('fieldset', 'Collateral', 'Required for a Regular Loan and for members outside the school; loanable up to 30% of the appraised value.'),
        ('.form-row:has(#net_pay)', 'Net pay and how to pay', 'Net pay is for a Salary Loan: the monthly amortization must not exceed it.'),
        (card('Eligibility summary'), 'Eligibility summary', 'Shown beside the form while you encode.'),
        (btn('Submit application'), 'Submit application', 'Records the application as Pending.'),
    ])
    preview = card('Amortization preview') + ' table'
    w.snap('12b-amortization-preview', [
        (('col', preview, 'Principal'), 'Same principal', 'An equal slice of principal every month.'),
        (('col', preview, 'Interest'), 'Interest', '3% of the remaining balance, so it goes down every month.'),
        (('col', preview, 'Total due'), 'Total due', 'Principal plus interest.'),
        (('col', preview, 'Semi-monthly'), 'Semi-monthly', 'Half the monthly amount, for salary deductions on the 15th and 30th.'),
        ('#preview-totals', 'Totals', 'Total interest and total payable over the term.'),
    ], top=card('Amortization preview'))
    w.submit(btn('Submit application'))
    lid = re.search(r'id=(\d+)', pg.url).group(1)
    w.snap('13-loan-pending', [
        ('.loan-main .card:first-child .d-flex', 'Status and progress', 'Applied → Approved → Released → Fully paid.'),
        (card('Eligibility summary'), 'Eligibility summary', 'Information for the credit committee; the system does not decide.'),
        (card('Details'), 'Details', 'Co-maker, collateral, and how the member will pay.'),
        (btn('Edit'), 'Edit', 'A pending application can still be corrected.'),
        (btn('Cancel application'), 'Cancel application', 'For example when the member withdraws it; a reason is asked.'),
    ])

    # ════════ Manager: decides, releases, and administers ════════
    pg = w.login('manager')
    w.snap('02-dashboard', [
        (MENU, 'Your menu', 'The sidebar lists only the modules your role may use. The Manager sees all of them.'),
        (glance, 'Today at a glance', 'Applications in process, loans awaiting release, past-due loans, portfolio, collections, members, share capital, and savings.'),
        (card('Approvals'), 'Approvals & releases queue', 'Pending and approved loans waiting for the Manager.'),
        (card('Quick actions'), 'Quick actions', 'Shortcuts to the tasks of the signed-in role.'),
        (card('Recent activity'), 'Recent activity', 'Latest entries from the audit log.'),
    ], wait=1000)

    w.go(f'loan_view&id={lid}')
    pg.fill('#approve-note', 'Approved in CreCom meeting Oct 7')
    w.snap('14-loan-decision', [
        (card('Eligibility summary'), 'Eligibility summary', 'What the credit committee looked at.'),
        (group('approve-note'), 'Committee note', 'Optional note from the credit committee meeting.'),
        (btn('Approve'), 'Approve', "Records the Manager's approval. Whoever encoded the application cannot decide it."),
        (btn('Reject'), 'Reject', 'Records a rejection instead; a reason is required.'),
    ])
    w.ask(btn('Approve'))
    w.confirm()
    pg.select_option('#release_mode', 'check')
    pg.fill('#check_no', '0012345')
    release = card('Release loan')
    w.snap('15-loan-release', [
        (group('release_date'), 'Release date', 'The date the proceeds are released.'),
        ('.form-row:has(#release_mode)', 'Cash or check', 'A check needs its check number.'),
        (release + ' table', 'Deductions and net proceeds', 'Insurance 0.56%, service fee 3%, stockshare 2%, notarial ₱200, printing ₱30. Interest is not deducted in advance.'),
        (btn('Release and generate'), 'Release', 'Releases the loan and generates the amortization schedule.'),
    ], top=release)
    w.ask(btn('Release and generate'))
    w.confirm()
    schedule = card('Amortization schedule') + ' table'
    w.snap('16-loan-released', [
        (btn('Schedule'), 'Schedule', 'Opens the printable schedule.'),
        ('.stat-strip .stat:last-child', 'Outstanding principal', 'Goes down as payments are posted.'),
        (('col', schedule, 'Status'), 'Installment status', 'Paid, Partial, or Unpaid for each month.'),
        (card('Currently due'), 'Currently due', 'The installment due next, what is due now, and the full payoff amount.'),
        (card('Details'), 'Details', 'Who applied, approved, and released, with the deductions at release.'),
    ])
    w.go(f'schedule_print&id={lid}')
    w.snap('17-schedule-print', [
        (btn('Print'), 'Print', "Prints the member's copy."),
        ('.print-doc .row.small.mb-3', 'Borrower and loan', 'Borrower, co-maker, collateral, principal, term, and rate.'),
        (('col', '.print-doc table', 'Semi-monthly'), 'Semi-monthly column', 'The amount for each payroll cut-off.'),
        ('.print-doc .row.small table', 'Deductions', 'Deductions at release and the net proceeds.'),
        ('.print-doc .row.small p.mb-1', 'After-term rule', '3% interest plus 4% penalty per month, computed per day, on the balance + unpaid interest once the term ends.'),
        ('.print-doc .row.mt-5', 'Signatures', 'The borrower and the authorized officer sign here.'),
    ])

    w.go('products')
    regular = card('Regular Loan')
    w.snap('37-loan-products', [
        (btn('Add product'), 'Add product', 'Create a new loan product.'),
        (regular, 'Product card', 'Rate, loanable basis, limits, and term.'),
        (regular + ' ' + btn('Edit'), 'Edit', 'Changes apply to future applications.'),
        (regular + ' ' + btn('Deactivate'), 'Deactivate', 'Hides the product from new applications; existing loans keep their terms.'),
    ])
    w.go('product_form&id=1')
    w.snap('38-product-form', [
        (group('product_name'), 'Product name', 'Shown in the application form and the products page.'),
        (['.form-group:has(#min_amount)', '.form-group:has(#max_amount)'], 'Amount limits', 'The minimum and maximum principal the product allows.'),
        (['.form-group:has(#term_months)', '.form-group:has(#interest_rate)', '.form-group:has(#penalty_rate)'], 'Term and rates', 'Months, the monthly interest (3% diminishing), and the after-term penalty (4%).'),
        (group('loanable_basis'), 'Loanable basis', 'Appraised value (30%), monthly net pay, or a fixed amount.'),
        (btn('Save product'), 'Save product', 'New settings affect only future applications.'),
    ])

    w.go('settings')
    w.snap('26-settings', [
        (['h3:has-text("Loan deductions")', '.form-group.row:has-text("Other fees")'], 'Deduction rates', 'Insurance, service fee, stockshare, notarial, and printing fees taken at release.'),
        (['h3:has-text("Loanable amount")', '.form-group.row:has-text("appraised value")'], 'Loanable amount', "Share of the collateral's appraised value that can be borrowed (30%)."),
        (['h3:has-text("Accounts")', '.form-group.row:has-text("Capital build-up contribution")'], 'Account minimums', 'Share capital, maintaining balance, time deposit, and CBU.'),
        (['h3:has-text("Fees and interest")', '.form-group.row:has-text("Savings interest:")'], 'Fees and interest', 'The fixed membership fee and the savings interest rate.'),
        (['h3:has-text("Monitoring")', '.form-group.row:has-text("Aging bracket")'], 'Monitoring', 'Days before due for reminders, and the aging brackets.'),
        (['h3:has-text("Official receipts")', '.form-group.row:has-text("Last official receipt")'], 'OR series', 'Set the last number issued to match the booklet; it can only move forward.'),
        (['h3:has-text("Interface language")', '.form-group.row:has(select)'], 'Verb labels', 'Language of the action words on buttons (for example Post, Void).'),
        (btn('Save settings'), 'Save settings', 'Only the Manager can change settings; other roles see them read-only.'),
        (card('Check'), 'Sample check', "Recomputes FFMPC's ₱26,000 sample with the current rates."),
    ])
    w.go('notifications')
    w.snap('40-email-settings', [
        ('#email-settings p', 'How it works', 'The scheduled task sends the pending reminders through this account.'),
        ('.form-row:has(#smtp_host)', 'SMTP host and port', 'smtp.gmail.com on 587 (STARTTLS) or 465 (SSL).'),
        ('.form-row:has(#smtp_user)', 'Username and app password', 'A Gmail App Password (16 letters), never the sign-in password. Saved masked.'),
        (group('smtp_from'), 'From address', "The cooperative's email address shown to members."),
        (btn('Save email settings'), 'Save email settings', 'Manager only.'),
    ], top='#email-settings')

    w.go('users')
    w.snap('28-user-accounts', [
        (btn('Add user'), 'Add user', 'Create a staff account and choose its role.'),
        (('col', 'table', 'Role'), 'Role', 'Decides what each person may do.'),
        ('tbody tr:first-child ' + btn('Edit'), 'Edit', 'Change the name, role, or status, or reset the password.'),
        ('tbody tr:first-child ' + btn('Deactivate'), 'Deactivate', 'Accounts are deactivated, never deleted.'),
    ])
    w.go('user_form')
    w.snap('user-form', [
        (['.form-group:has(#full_name)', '.form-group:has(#email)'], 'Name, username, email', 'The username or the email is used to sign in.'),
        (group('role'), 'Role', 'Manager, Cashier, Loan Officer, Bookkeeper, or Board / Auditor.'),
        (group('password'), 'Password', 'The first password; the user changes it after signing in.'),
        (card('What each role can do'), 'What each role can do', 'A reminder of the duties of each role.'),
        (btn('Create account'), 'Create account', 'Saves the new staff account.'),
    ])

    w.go('passbook&id=6')
    w.snap('43-account-close', [
        ('.col-lg-3 .kpi-value', 'Zero balance', 'An account can be closed only at ₱0.00.'),
        (btn('Close account'), 'Close account', 'Manager only. The passbook history is kept, and the account can be reopened later.'),
    ])
    w.ask(btn('Close account'))
    w.confirm()

    w.go('reconcile')
    w.snap('35-reconcile', [
        ('.alert', 'Result', 'Green when every stored balance matches its ledger.'),
        (card('Loans'), 'Loans check', 'For every released loan: the unpaid schedule total must equal the stored outstanding balance.'),
        (card('Savings'), 'Savings check', 'For every account: the sum of its transactions (reversals applied) must equal the stored balance.'),
    ])

    # ════════ Cashier: loan payments, end of day ════════
    pg = w.login('cashier')
    w.go('payment_post')
    w.lookup('input[data-member-lookup="#pay-results"]', 'dela')
    w.snap('payment-find-member', [
        ('input[data-member-lookup="#pay-results"]', 'Find the member', 'Type a name or member number.'),
        ('#pay-results', 'Pick the member', 'Click the member in the list.'),
    ])
    pg.click('#pay-results .list-group-item:has-text("Dela Cruz")')
    pg.wait_for_load_state('networkidle')
    w.snap('18-payment-choose-loan', [
        (card('1. Find the member'), 'Find the member', 'Search again to change the member.'),
        (card('2. Choose the loan') + ' .list-group-item', 'Choose the loan', 'Click the loan being paid; the next due date and balance are shown.'),
    ])
    pg.click('a.list-group-item:has-text("Loan #3")')
    pg.wait_for_load_state('networkidle')
    pg.fill('#payment-amount', '12000')
    w.snap('19-payment-advance', [
        ('#payment-amount', 'Amount received', '₱12,000 here, which is more than one installment.'),
        (['button[data-fill-amount]'], 'Quick fill', 'Fill in the amount due now, the semi-monthly amount, or the full payoff.'),
        (group('mode'), 'Mode of payment', 'Cash, salary deduction, or another mode.'),
        ('table:has(#split-penalty)', 'How it will be applied', 'Penalty → interest → principal. The installments covered are listed.'),
        (btn('issue OR'), 'Post payment and issue OR', 'Saves the payment and opens the receipt.'),
        ('.col-lg-5 .card', 'Due now / full payoff', 'What is due today, and the amount that closes the loan (no rebate).'),
    ])
    w.submit(btn('issue OR'))
    w.snap('20-payment-receipt', [
        ('.receipt tr:has-text("No.") td:last-child', 'OR number', 'One receipt for the whole payment.'),
        (['.receipt tr:has-text("Installment")'], 'Installments covered', 'Every installment this payment settled.'),
        ('.receipt tr:has-text("TOTAL PAID")', 'Total paid', 'Split into interest and principal just above.'),
        ('.receipt tr:has-text("Remaining loan balance")', 'Remaining balance', 'Outstanding principal after this payment.'),
        ('.print-actions', 'Print', 'Thermal 80mm or full report layout.'),
    ])
    w.go('payment_post&loan_id=3')  # a second posting by mistake, voided later by the Bookkeeper
    pg.fill('#payment-amount', '500')
    w.submit(btn('issue OR'))

    w.go('payment_post&loan_id=4')
    w.snap('21-payment-after-term', [
        ('.col-lg-5 .alert-danger', 'Term ended', 'The loan term has ended; charges now accrue per day.'),
        (['.col-lg-5 tr:has-text("after term")'], 'After-term charges', 'Unpaid balance + unpaid interest is charged 3% interest + 4% penalty per month, computed per day, collected first.'),
        ('.col-lg-5 tr.font-weight-bold', 'Due now', 'The total including the after-term charges.'),
        ('form:has(#as_of)', 'Payment received on', 'Recompute when the payment was received on an earlier date.'),
    ])
    w.go('payments')
    w.snap('payments-list', [
        (btn('Post payment'), 'Post payment', 'Cashier only.'),
        ('form:has(#from)', 'Date range', 'Filter the receipts by date.'),
        (('col', 'table', 'OR no.'), 'OR number', 'Click a number to open and reprint the receipt.'),
        (('col', 'table', 'Status'), 'Status', 'Posted or Void.'),
        ('.dt-buttons', 'Export or print', 'Copy, CSV, Excel, or Print.'),
    ])
    w.go('reports')
    w.snap('29-report-daily-collection', [
        ('.content .nav-pills', 'Report tabs', 'Each role sees only its reports.'),
        ('form:has(#date)', 'Date', 'Pick the day to balance.'),
        ('.content .stat-strip', 'Totals', 'Cash in, cash out, net for the day, receipts issued, and the total per cashier.'),
        ('.dt-buttons', 'Export or print', 'Copy, CSV, Excel, or Print.'),
    ])

    pg.click(search_btn)
    pg.wait_for_selector('#ml-search-input', state='visible')
    pg.type('#ml-search-input', 'reyes', delay=30)
    pg.wait_for_selector('#ml-search-results .list-group-item')
    w.snap('41-global-member-search', [
        (search_btn, 'Find member', 'On every page, in the top bar (Ctrl+K or /).'),
        ('#ml-search-input', 'Search box', 'Type a name or member number.'),
        ('#ml-search-results', 'Instant results', 'Jump to the record; the actions shown follow your role.'),
    ], wait=500)
    w.go('home')
    pg.click('.navbar-nav.ml-auto .dropdown > a')
    w.snap('account-menu', [
        ('.dropdown-menu.show .dropdown-item-text', 'Your role', 'The role you are signed in with.'),
        ('.dropdown-menu.show a:has-text("Change password")', 'Change password', 'Opens the password page.'),
        ('.dropdown-menu.show button:has-text("Log out")', 'Log out', 'Always log out before leaving the computer.'),
    ], wait=1000)
    w.go('profile')
    w.snap('39-profile', [
        (group('current_password'), 'Current password', 'Proves it is you before the password can change.'),
        (['.form-group:has(#new_password)', '.form-group:has(#confirm_password)'], 'New password', 'At least 8 characters with letters and numbers, typed twice.'),
        (btn('Save new password'), 'Save new password', 'Changes the password of your own account.'),
    ])

    # ════════ Loan Officer: delinquency, demand letter, reminders ════════
    pg = w.login('loanofficer')
    w.go('delinquency')
    w.submit(btn('Update list now'))
    if pg.locator('.ml-confirm-ok:visible').count():
        w.confirm()
    w.snap('24-delinquency', [
        (btn('Update list now'), 'Update list now', 'Scans every schedule against the payments received (Loan Officer).'),
        (glance, 'Summary', 'Past-due loans, amount past due, portfolio at risk, and the snapshot date.'),
        (card('Aging summary'), 'Aging summary', 'Loans and amounts per bracket: 1–30, 31–60, 61–90, 91–180, 181–365, over 365 days.'),
        (card('Delinquency list') + ' tbody tr:first-child', 'Past-due account', 'Member, contact number, co-maker, days past due, and bracket.'),
        (btn('Demand letter'), 'Demand letter', 'Prints a letter for the borrower, with a copy for the co-maker.'),
    ])
    pg.click(btn('Demand letter'))
    pg.wait_for_load_state('networkidle')
    w.snap('25-demand-letter', [
        (btn('Print letter'), 'Print letter', 'Print it and have the borrower sign on receipt.'),
        ('.print-doc table', 'Unpaid installments', 'Listed with their due dates, plus the after-term interest and penalty.'),
        ('.print-doc tr:has-text("Total amount past due")', 'Total past due', 'The amount demanded.'),
        ('.print-doc p:has-text("cc:")', 'Co-maker copy', 'The co-maker gets a copy and is liable.'),
        ('.print-doc .row.mt-5', 'Signatures', 'Signed for the cooperative; the borrower signs on receipt.'),
    ])
    w.go('notifications')
    w.submit(btn('Generate reminders'))
    w.snap('27-reminders', [
        (btn('Generate reminders'), 'Generate reminders', 'Creates messages for dues within 3 days and for overdue loans.'),
        ('.card-header .nav-pills', 'To send / Sent / Failed', 'Filter the reminders by status.'),
        (('col', 'table', 'Message'), 'Message', 'The text that will be sent to the member.'),
        (('col', 'table', 'Status'), 'Status', 'Pending reminders with an email address are sent by the scheduled task.'),
        (('col', 'table', 'Actions'), 'Send by hand', 'Open in the SMS or email app, then mark sent or failed, or remove.'),
    ])
    w.go('reports')
    pg.click('.content .nav-pills a:has-text("Loan status")')
    pg.wait_for_load_state('networkidle')
    w.snap('44-report-loan-status', [
        ('.content .nav-pills a.active', 'Loan status tab', 'One of the report tabs; each role sees only its reports.'),
        (card('Loan status by product'), 'By product', 'Loans and balances per product and status.'),
        (card('Released (active) loans'), 'Released loans', 'Every running loan with its outstanding balance. Export or print.'),
    ])
    pg.click('.content .nav-pills a:has-text("Delinquency")')
    pg.wait_for_load_state('networkidle')
    w.snap('45-report-aging', [
        ('.content .nav-pills a.active', 'Delinquency & aging tab', 'The aging view of the whole portfolio.'),
        ('.content .stat-strip', 'Brackets', 'Loans and amount past due in each aging bracket.'),
        ('.content table', 'Past-due loans', 'Every past-due loan with its days past due, live as of today.'),
    ])

    # ════════ Bookkeeper: interest, corrections, reports ════════
    pg = w.login('bookkeeper')
    w.snap('dash-bookkeeper', [
        (MENU, 'Your menu', 'Only the modules a Bookkeeper may use.'),
        (glance, 'Today at a glance', 'Members, portfolio, collections, deposits, and past-due loans.'),
        (card('Quick actions'), 'Quick actions', 'Open reports.'),
        (card('Recent activity'), 'Recent activity', 'Latest entries from the audit log.'),
    ], wait=1000)
    w.go('savings_interest')
    pg.fill('#period', '2027-Q1')
    w.snap('36-savings-interest', [
        (group('period'), 'Quarter label', 'For example 2027-Q1. Accounts already posted for the label are skipped.'),
        (btn('Post quarterly interest'), 'Post quarterly interest', 'Credits 1% of the balance to every active regular savings account in one run.'),
        (card('Time deposit'), 'Time deposits', '1% per term, posted per account with its own term label.'),
        (card('Regular savings accounts'), 'Accounts and interest', 'Each balance with the interest it will earn and the date of the last posting.'),
    ])
    w.go('passbook&id=3')
    w.snap('42-passbook-reversal', [
        ('tbody tr:has-text("Reversed")', 'Reversed line', 'Greyed out with a Reversed badge; the original stays on record.'),
        ('tbody tr:has-text("Reversal of")', 'Reversing entry', 'The correcting line, with the reason.'),
        ('tbody form[data-confirm-reason] button', 'Reverse', 'On every receipt line that can still be corrected (Bookkeeper).'),
    ])
    w.go('passbook&id=4')
    w.ask('tbody tr:has-text("000011") button', 'Posted to the wrong account')
    w.snap('reversal-dialog', [
        ('.modal.show .ml-confirm-text', 'What will happen', 'A reversing entry is added; the original stays on record.'),
        ('#ml-reason', 'Reason (required)', 'Why the entry is reversed (3 to 200 characters).'),
        ('.ml-confirm-ok', 'Reverse entry', 'Confirms the reversal; the cash returns to the box that day.'),
    ])
    w.confirm()

    w.go('loan_view&id=3')
    payments = card('Payments')
    void_row = payments + ' tbody tr:has(form)'
    # the payments table is wider than its card at this window size: bring the Void column into view
    pg.eval_on_selector(payments + ' .table-responsive', 'e => e.scrollLeft = e.scrollWidth')
    w.snap('void-button', [
        (void_row, 'Latest receipt', 'Only the newest posted receipt of a loan can be voided.'),
        (void_row + ' td:last-child', 'Void', 'Bookkeeper only; a reason is asked.'),
    ], top=payments)
    w.ask(void_row + ' button', 'Posted twice by mistake')
    w.snap('22-void-receipt', [
        ('.modal.show .ml-confirm-text', 'What will happen', 'The installments and balance are restored; the receipt stays on record marked VOID.'),
        ('#ml-reason', 'Reason (required)', 'Why the receipt is voided.'),
        ('.ml-confirm-ok', 'Void receipt', 'Confirms the void.'),
    ])
    w.confirm()
    w.snap('23-voided-payments', [
        (payments + ' tbody tr:first-child td:nth-child(9)', 'Marked VOID', 'The voided receipt stays on record with its reason.'),
        (card('Currently due'), 'Due again', 'The balance and the amount due are back to what they were before the voided payment.'),
    ], top=payments)
    w.go('passbook&id=6')
    w.snap('account-reopen', [
        ('.col-lg-3 .badge', 'Closed', 'A closed account accepts no transactions.'),
        (btn('Reopen account'), 'Reopen account', 'Manager or Bookkeeper, for example when a resigned member returns.'),
    ])
    w.go('eod_close')
    w.snap('34-eod-close', [
        ('form:has(#date)', 'Business date', 'Pick the day to balance, then click Show.'),
        ('.content .stat-strip', 'Receipts by type', 'Every posted receipt of the day grouped by type, with counts and totals.'),
        ('tbody tr:has-text("reversal")', 'Savings reversal', 'A reversal books the cash back on the day it returned.'),
        ('tfoot', 'Grand total (cash box)', 'Cash in minus cash out: the number that must match the counted cash.'),
    ])
    w.go('reports')
    pg.click('.content .nav-pills a:has-text("Savings")')
    pg.wait_for_load_state('networkidle')
    w.snap('46-report-savings', [
        ('.content .nav-pills a.active', 'Savings tab', 'Movements and balances for savings and share capital.'),
        ('form:has(#from)', 'Date range', 'Filter the movement period.'),
        (card('Balances per member'), 'Balances per member', 'Per account type with totals; export or print.'),
    ])
    pg.click('.content .nav-pills a:has-text("Salary deduction")')
    pg.wait_for_load_state('networkidle')
    pg.fill('#month', '2027-03')  # a month in which the sample salary-deduction loan has an installment due
    w.submit('form:has(#month) ' + btn('Show'))
    w.snap('30-report-salary-deduction', [
        ('.content .nav-pills a.active', 'Salary deduction list', 'Prepared by the Bookkeeper for payroll.'),
        ('form:has(#month)', 'Payroll month', 'Choose the month.'),
        (('col', '.content table', '15th'), '15th', 'Half of the monthly amount, deducted on the first cut-off.'),
        (('col', '.content table', '30th'), '30th', 'The other half, deducted on the second cut-off.'),
        ('tfoot', 'Total to deduct', 'The sum for the whole list.'),
    ])
    w.go('audit')
    w.snap('31-audit-log', [
        ('form:has(#from)', 'Filters', 'By date range, user, and record type.'),
        (('col', 'table', 'User', 6), 'Who', 'The user and role that made the change.'),
        (('col', 'table', 'Details', 6), 'What changed', 'Details of the action, such as the voided OR and its reason.'),
        ('.dt-buttons', 'Export or print', 'Copy, CSV, Excel, or Print.'),
    ])

    # ════════ Board / Auditor: read-only ════════
    pg = w.login('auditor')
    w.snap('dash-auditor', [
        (MENU, 'Your menu', 'Records, reports, reconciliation, and the audit log, all read-only.'),
        (glance, 'Today at a glance', 'Members, portfolio, collections, deposits, past-due loans, share capital, and savings.'),
        (card('Quick actions'), 'Quick actions', 'Open reports.'),
        (card('Recent activity'), 'Recent activity', 'Latest entries from the audit log.'),
    ], wait=1000)
    w.go('member_view&id=2')
    w.snap('auditor-member-record', [
        ('.content-header', 'No action buttons', 'The Board / Auditor can open every record, but there is nothing to edit, post, or approve.'),
        ('.nav-tabs', 'Record tabs', 'Eligibility, profile, accounts, loans, and payment history.'),
    ])
    w.go('reports')
    pg.click('.content .nav-pills a:has-text("Share capital")')
    pg.wait_for_load_state('networkidle')
    w.snap('33-auditor-share-capital', [
        ('.content .nav-pills a.active', 'Share capital', 'Report tab, read-only for the Board / Auditor.'),
        ('.content .stat-strip', 'Summary', 'Total paid-up capital, members, average, and members below the minimum.'),
        ('.dt-buttons', 'Export or print', 'Copy, CSV, Excel, or Print.'),
    ])
    w.go('user_form')
    w.snap('32-access-denied', [
        ('h2:has-text("permission")', 'Blocked', 'The Board / Auditor tried to open Add user by typing its address.'),
        (btn('Back to dashboard'), 'Back to dashboard', 'Returns to the pages the role may use.'),
    ])


def capture(only):
    if not BASE or re.match(r'https?://(localhost|127\.0\.0\.1)(:80)?/mutuallink', BASE):
        sys.exit('Set GUIDE_BASE to a throwaway copy of the system (capture posts transactions). See the docstring.')
    from playwright.sync_api import sync_playwright
    SHOTS.mkdir(exist_ok=True)
    with sync_playwright() as p:
        browser = p.chromium.launch(channel='msedge')
        w = Walk(browser, set(only))
        try:
            scenario(w)
        finally:
            w.save()
            browser.close()


# ───────────────────────── inject ─────────────────────────

PAD = 6          # breathing room between a target and its highlight
CHIP_H = 34      # label chip height, in screenshot pixels
FONT = 19


def chip_width(label):
    return round(CHIP_H + 4 + len(label) * FONT * 0.55 + 14)


def shape_for(w, h):
    """Small icons get a circle, badges a pill, big regions a dashed box, everything else a rounded box."""
    if w <= 60 and h <= 60:
        return 'circle'
    if (w >= 400 and h >= 110) or h >= 200:
        return 'area'
    if h <= 30 and w <= 130:
        return 'pill'
    return 'rect'


def overlap(a, b):
    return a[0] < b[0] + b[2] and b[0] < a[0] + a[2] and a[1] < b[1] + b[3] and b[1] < a[1] + a[3]


CELL = 6         # the page is checked for free space on a grid of this many pixels
REACH = 560      # how far a label may move away from its target to find free space
GAP = 30         # every label stands this far off its box, so there is always room for its arrow


def ink_table(ink, width, height):
    """Summed-area table over the cells that have something drawn on them: 'is this rectangle empty?' in O(1)."""
    cols, rows = width // CELL + 1, height // CELL + 1
    t = [[0] * (cols + 1) for _ in range(rows + 1)]
    for x, y, w, h in ink:
        for r in range(max(0, y // CELL), min(rows, (y + h) // CELL + 1)):
            row = t[r + 1]
            for c in range(max(0, x // CELL), min(cols, (x + w) // CELL + 1)):
                row[c + 1] = 1
    for r in range(1, rows + 1):
        acc = 0
        for c in range(1, cols + 1):
            acc += t[r][c]
            t[r][c] = acc + t[r - 1][c]
    return t


def inked(t, r, margin=3):
    """How many drawn cells the rectangle (plus a small margin) touches."""
    x0, y0 = max(0, int(r[0] - margin) // CELL), max(0, int(r[1] - margin) // CELL)
    x1 = min(len(t[0]) - 2, int(r[0] + r[2] + margin) // CELL)
    y1 = min(len(t) - 2, int(r[1] + r[3] + margin) // CELL)
    return t[y1 + 1][x1 + 1] - t[y0][x1 + 1] - t[y1 + 1][x0] + t[y0][x0]


def gap_between(a, b):
    """Distance between two rectangles (0 when they touch or overlap)."""
    dx = max(b[0] - (a[0] + a[2]), a[0] - (b[0] + b[2]), 0)
    dy = max(b[1] - (a[1] + a[3]), a[1] - (b[1] + b[3]), 0)
    return (dx * dx + dy * dy) ** 0.5


def place_chips(marks, boxes, width, height, ink):
    """
    Put each label on EMPTY space: never on page content, on another target, or on another label.
    Right beside its box when there is room; otherwise the nearest free spot, joined by an arrow.
    """
    table = ink_table(ink, width, height)
    chips = []
    for i, (x, y, w, h) in enumerate(boxes):
        cw = chip_width(marks[i]['label'])
        gap = GAP
        own = (x, y, w, h)
        is_area = shape_for(marks[i]['w'], marks[i]['h']) == 'area'
        # other small targets (fields, buttons, badges) are off limits; a box that contains this
        # one is its own row or card, and the blank part of a big dashed region is fair game
        holds = lambda b: b[0] <= x and b[1] <= y and b[0] + b[2] >= x + w and b[1] + b[3] >= y + h
        blocked = [b for j, b in enumerate(boxes) if j != i and shape_for(marks[j]['w'], marks[j]['h']) != 'area' and not holds(b)]
        others = [b for j, b in enumerate(boxes) if j != i and not holds(b)]  # an arrow should not run across any of these
        if not is_area:
            blocked.append(own)

        def free(cx, cy):
            r = (cx, cy, cw, CHIP_H)
            if cx < 4 or cy < 4 or cx + cw > width - 4 or cy + CHIP_H > height - 4:
                return False
            if any(overlap((cx - 5, cy - 5, cw + 10, CHIP_H + 10), c) for c in chips) or any(overlap(r, b) for b in blocked):
                return False
            return not inked(table, r)

        # 1. beside the box, in order of preference
        near = [(x, y - CHIP_H - gap), (x, y + h + gap), (x + w - cw, y - CHIP_H - gap), (x + w - cw, y + h + gap),
                (x + w + gap, y + h / 2 - CHIP_H / 2), (x - cw - gap, y + h / 2 - CHIP_H / 2)]
        spot = next((c for c in near if free(*c)), None)
        # 2. the nearest empty spot anywhere within reach (an arrow will join it to the box)
        #    (a spot whose arrow would run across another target or label counts as much further away)
        if spot is None:
            best = REACH
            for cy in range(4, height - CHIP_H - 4, CELL):
                if cy + CHIP_H < y - REACH or cy > y + h + REACH:
                    continue
                for cx in range(4, width - cw - 4, CELL):
                    r = (cx, cy, cw, CHIP_H)
                    d = gap_between(r, own)
                    if GAP - 4 <= d < best and free(cx, cy):
                        d += 220 * crossings(link(r, own), others + chips)
                        if d < best:
                            best, spot = d, (cx, cy)
        # 3. a page with no empty space at all: beside the box, on the least content
        if spot is None:
            spot = min(near, key=lambda c: inked(table, (c[0], c[1], cw, CHIP_H)) + 1000 * (c[0] < 4 or c[1] < 4 or c[0] + cw > width - 4 or c[1] + CHIP_H > height - 4))
            spot = (min(max(spot[0], 4), width - cw - 4), min(max(spot[1], 4), height - CHIP_H - 4))
        chips.append((round(spot[0]), round(spot[1]), cw, CHIP_H))
    return chips


def link(chip, box):
    """The straight line from a label to its box: (leaves the chip at, lands on the box at)."""
    clamp = lambda v, lo, hi: max(lo, min(v, hi))
    bx, by = box[0] + box[2] / 2, box[1] + box[3] / 2
    px, py = clamp(bx, chip[0], chip[0] + chip[2]), clamp(by, chip[1], chip[1] + chip[3])
    qx, qy = clamp(px, box[0], box[0] + box[2]), clamp(py, box[1], box[1] + box[3])
    return px, py, qx, qy


def crossings(line, rects):
    """How many of the rectangles the line passes through (sampled every few pixels)."""
    px, py, qx, qy = line
    n = max(1, int(((qx - px) ** 2 + (qy - py) ** 2) ** 0.5 // 6))
    pts = [(px + (qx - px) * k / n, py + (qy - py) * k / n) for k in range(1, n)]
    return sum(any(r[0] < a < r[0] + r[2] and r[1] < b < r[1] + r[3] for a, b in pts) for r in rects)


def arrow(chip, box):
    """A line with an arrowhead from the label to the edge of its box; nothing when the label sits right beside it."""
    px, py, qx, qy = link(chip, box)
    length = ((qx - px) ** 2 + (qy - py) ** 2) ** 0.5
    if length < 9:
        return ''
    ux, uy = (qx - px) / length, (qy - py) / length
    head = min(13, length - 2)
    tip = (qx - ux * 2, qy - uy * 2)
    base = (tip[0] - ux * head, tip[1] - uy * head)
    left = (base[0] - uy * 7, base[1] + ux * 7)
    right = (base[0] + uy * 7, base[1] - ux * 7)
    line = f'x1="{px:.0f}" y1="{py:.0f}" x2="{base[0]:.0f}" y2="{base[1]:.0f}"'
    head = f'{tip[0]:.0f},{tip[1]:.0f} {left[0]:.0f},{left[1]:.0f} {right[0]:.0f},{right[1]:.0f}'
    return (f'<line class="ann-line-halo" stroke="#fff" {line}/><polygon class="ann-head-halo" fill="#fff" stroke="#fff" points="{head}"/>'
            f'<line class="ann-line" stroke="#f97316" {line}/><polygon class="ann-head" fill="#f97316" points="{head}"/>')


def overlay(entry):
    width, height = entry['w'], entry['h']
    marks = entry['marks']
    boxes = [(m['x'] - PAD, m['y'] - PAD, m['w'] + 2 * PAD, m['h'] + 2 * PAD) for m in marks]
    chips = place_chips(marks, boxes, width, height, entry.get('ink', []))
    shapes, tags = '', ''
    for i, ((x, y, w, h), m) in enumerate(zip(boxes, marks)):
        kind = shape_for(m['w'], m['h'])
        if kind == 'circle':
            geo = f'cx="{x + w / 2:.0f}" cy="{y + h / 2:.0f}" r="{max(w, h) / 2:.0f}"'
            shapes += f'<circle class="ann-halo" fill="none" {geo}/><circle class="ann-ring" fill="none" {geo}/>'
        else:
            geo = f'x="{x}" y="{y}" width="{w}" height="{h}" rx="{h / 2 if kind == "pill" else 8:.0f}"'
            shapes += f'<rect class="ann-halo" fill="none" {geo}/><rect class="ann-ring{" ann-area" if kind == "area" else ""}" fill="none" {geo}/>'
        cx, cy, cw, ch = chips[i]
        mid = ch // 2
        shapes += arrow(chips[i], (x, y, w, h))
        tags += (f'<g class="ann-chip" transform="translate({cx} {cy})">'
                 f'<rect class="ann-chip-bg" width="{cw}" height="{ch}" rx="{mid}"/>'
                 f'<circle class="ann-num-bg" cx="{mid}" cy="{mid}" r="{mid - 3}"/>'
                 f'<text class="ann-num" x="{mid}" y="{mid + 1}">{i + 1}</text>'
                 f'<text class="ann-text" x="{ch + 4}" y="{mid + 1}" textLength="{cw - ch - 18}" lengthAdjust="spacingAndGlyphs">{html.escape(m["label"])}</text>'
                 '</g>')
    return (f'<!--ann--><span class="ann" aria-hidden="true"><svg viewBox="0 0 {width} {height}">'
            f'{shapes}{tags}</svg></span><!--/ann-->')


def steps(entry):
    items = ''.join(f'<li><b>{i + 1}</b><span><strong>{html.escape(m["label"])}.</strong> {html.escape(m["text"])}</span></li>'
                    for i, m in enumerate(entry['marks']))
    return f'<!--ann--><ol class="ann-steps">{items}</ol><!--/ann-->'


def inject():
    marks = json.loads(MARKS.read_text(encoding='utf-8'))
    doc = re.sub(r'<!--ann-->.*?<!--/ann-->', '', DOC.read_text(encoding='utf-8'), flags=re.S)
    doc = re.sub(r'((?:guide\.css|screenshots/[\w-]+\.png))\?v=\d+', r'\1', doc)  # versions are re-added below
    used, missing = set(), set()

    def figure(m):
        slug = m.group(2)
        entry = marks.get(slug)
        if not entry:
            missing.add(slug)
            return m.group(0)
        used.add(slug)
        # keep the <img> honest about the real pixel size, and load the long page lazily
        img = re.sub(r'\s(width|height|loading)="[^"]*"', '', m.group(3))
        img = img[:-1] + f' width="{entry["w"]}" height="{entry["h"]}" loading="lazy">'
        return m.group(1) + img + overlay(entry) + m.group(4) + (steps(entry) if entry['marks'] else '')

    doc = re.sub(r'(<figure class="shot"><a [^>]*href="screenshots/([^"]+)\.png">)(<img [^>]*>)(</a><figcaption>.*?</figcaption>)',
                 figure, doc, flags=re.S)
    shown = len(set(re.findall(r'href="screenshots/([^"]+)\.png"', doc)))
    doc = re.sub(r'(<div><strong>)\d+(</strong><span>Screenshots</span></div>)', rf'\g<1>{shown}\g<2>', doc)
    # .htaccess lets browsers keep CSS and PNG for a month. A stale guide.css paints the callouts
    # solid black, and a stale screenshot no longer matches its boxes, so every URL carries the
    # file's own modification time: it changes exactly when the file does.
    css = DOC.with_name('guide.css')
    doc = doc.replace('href="guide.css"', f'href="guide.css?v={int(css.stat().st_mtime)}"')
    doc = re.sub(r'screenshots/([\w-]+)\.png', lambda m: f'{m.group(0)}?v={int((SHOTS / (m.group(1) + ".png")).stat().st_mtime)}', doc)
    DOC.write_text(doc, encoding='utf-8', newline='\n')
    print(f'Annotated {len(used)} screenshots ({shown} in the page).')
    if missing:
        print('No marks for:', ', '.join(sorted(missing)))
    unused = sorted(set(marks) - used)
    if unused:
        print('Captured but not in the page:', ', '.join(unused))


if __name__ == '__main__':
    if sys.argv[1:2] == ['capture']:
        capture(sys.argv[2:])
    else:
        inject()
