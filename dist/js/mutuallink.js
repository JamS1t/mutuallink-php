/* ==========================================================
   MutualLink front-end behavior (jQuery + Fetch API).
   No inline scripts anywhere: pages pass data through data-* attributes,
   and all server data is rendered with textContent (never innerHTML)
   to prevent DOM-based XSS.
   ========================================================== */
(function ($) {
  'use strict';

  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  var CSRF = csrfMeta ? csrfMeta.getAttribute('content') : '';

  function peso(n) {
    return '₱ ' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function el(tag, text, className) {
    var node = document.createElement(tag);
    if (text !== undefined && text !== null) node.textContent = String(text);
    if (className) node.className = className;
    return node;
  }

  /**
   * Fetch wrapper for the JSON endpoints: sends the CSRF header and the
   * session cookie (same origin), checks response.ok, parses the envelope.
   * Every successful response also pushes the idle-warning clock forward,
   * because the server counts this request as activity.
   */
  var extendIdle = function () {}; // replaced by the session-idle block below
  async function apiFetch(url, options) {
    options = options || {};
    var init = {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF }
    };
    if (options.body !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(options.body);
    }
    var response = await fetch(url, init);
    var payload;
    try { payload = await response.json(); } catch (e) { payload = { success: false, errors: ['Unexpected server response.'] }; }
    if (response.ok) { extendIdle(); }
    if (!response.ok) {
      if (response.status === 401) { window.location.href = 'index.php'; }
      var err = new Error((payload.errors || ['Request failed.']).join(' '));
      err.payload = payload;
      throw err;
    }
    return payload.data;
  }

  /* ---------- Toastr flash messages ---------- */
  // escapeHtml: messages can contain names typed by users, so render them as text.
  toastr.options = { escapeHtml: true, closeButton: true, progressBar: true, newestOnTop: true, positionClass: 'toast-top-right', timeOut: 5000, preventDuplicates: true };
  // Screen readers announce every toast from one live region (WCAG 4.1.3): toastr creates
  // #toast-container on the first toast — give it status/assertive semantics the first time.
  toastr.options.onShown = function () {
    var container = document.getElementById('toast-container');
    if (container && !container.hasAttribute('role')) {
      container.setAttribute('role', 'status');
      container.setAttribute('aria-live', 'assertive');
    }
  };
  var flashEl = document.getElementById('ml-flash');
  if (flashEl) {
    try {
      JSON.parse(flashEl.getAttribute('data-messages') || '[]').forEach(function (m) {
        var type = { success: 'success', error: 'error', warning: 'warning', info: 'info' }[m.type] || 'info';
        // Validation errors stay until closed, so a list of problems can be read in full.
        toastr[type](m.message, '', type === 'error' ? { timeOut: 0, extendedTimeOut: 0 } : {});
      });
    } catch (e) { /* ignore malformed flash data */ }

    /* ---------- Undo toast for non-financial actions (mark sent/failed, remove unsent reminder, edit profile) ---------- */
    // The server stores the reverse action's fields via flash_undo(); submitting them goes
    // through the same PRG + CSRF flow as any other form.
    try {
      var undo = JSON.parse(flashEl.getAttribute('data-undo') || 'null');
      if (undo && undo.message && undo.url && undo.fields) {
        var $toast = toastr.success(undo.message, '', { timeOut: 12000, extendedTimeOut: 5000 });
        var undoBtn = el('button', 'Undo', 'btn btn-sm btn-light ml-2');
        undoBtn.type = 'button';
        undoBtn.addEventListener('click', function () {
          var form = document.createElement('form');
          form.method = 'post';
          form.action = undo.url;
          var csrf = document.createElement('input');
          csrf.type = 'hidden'; csrf.name = 'csrf_token'; csrf.value = CSRF;
          form.appendChild(csrf);
          Object.keys(undo.fields).forEach(function (k) {
            var input = document.createElement('input');
            input.type = 'hidden'; input.name = k; input.value = String(undo.fields[k] ?? '');
            form.appendChild(input);
          });
          document.body.appendChild(form);
          form.submit();
        });
        $toast.find('.toast-message').append(undoBtn);
      }
    } catch (e) { /* ignore malformed undo data */ }
  }

  /* ---------- Show / hide password ---------- */
  $(document).on('click', '[data-toggle-password]', function () {
    var input = document.querySelector(this.getAttribute('data-toggle-password'));
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    $(this).find('i').toggleClass('fa-eye fa-eye-slash');
  });

  /* ---------- Loading state on submit (prevents double posting) ---------- */
  $(document).on('submit', 'form.ml-form', function () {
    // Forms needing confirmation only show the spinner once the user has confirmed;
    // the beforeunload guard may not treat a cancelled submit as "saved".
    if (this.hasAttribute('data-confirm') && this.dataset.confirmed !== '1') return;
    this.dataset.submitted = '1';
    var $btn = $(this).find('button[type="submit"]').not('[formnovalidate]');
    if ($btn.prop('disabled')) return false;
    $btn.prop('disabled', true).each(function () {
      this.dataset.label = this.innerHTML;
      $(this).prepend('<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>');
    });
  });

  /* ---------- Confirmation modal for destructive actions ---------- */
  var $modal = null;
  var pendingForm = null;
  function buildModal() {
    $modal = $(
      '<div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="ml-confirm-title" aria-hidden="true">' +
      '<div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">' +
      '<div class="modal-header"><h5 class="modal-title" id="ml-confirm-title">Please confirm</h5>' +
      '<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>' +
      '<div class="modal-body"><p class="ml-confirm-text mb-0"></p>' +
      '<div class="ml-confirm-reason mt-3" hidden><label for="ml-reason" class="small font-weight-bold">Reason (required)</label>' +
      '<textarea id="ml-reason" class="form-control" rows="2" maxlength="255"></textarea></div></div>' +
      '<div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>' +
      '<button type="button" class="btn btn-danger ml-confirm-ok">Confirm</button></div>' +
      '</div></div></div>'
    ).appendTo('body');

    $modal.on('click', '.ml-confirm-ok', function () {
      if (!pendingForm) return;
      var needsReason = pendingForm.hasAttribute('data-confirm-reason');
      var reason = $modal.find('#ml-reason').val().trim();
      if (needsReason && reason.length < 3) {
        $modal.find('#ml-reason').addClass('is-invalid').trigger('focus');
        return;
      }
      if (needsReason) {
        var field = pendingForm.querySelector('input[name="reason"]');
        if (!field) { field = el('input'); field.type = 'hidden'; field.name = 'reason'; pendingForm.appendChild(field); }
        field.value = reason;
      }
      pendingForm.dataset.confirmed = '1';
      $modal.modal('hide');
      if (typeof pendingForm.requestSubmit === 'function') { pendingForm.requestSubmit(); } else { pendingForm.submit(); }
    });
  }

  $(document).on('submit', 'form[data-confirm]', function (e) {
    if (this.dataset.confirmed === '1') { this.dataset.confirmed = ''; return true; }
    e.preventDefault();
    e.stopImmediatePropagation();
    if (!$modal) buildModal();
    pendingForm = this;
    $modal.find('.ml-confirm-text').text(this.getAttribute('data-confirm'));
    var needsReason = this.hasAttribute('data-confirm-reason');
    $modal.find('.ml-confirm-reason').prop('hidden', !needsReason);
    $modal.find('#ml-reason').val('').removeClass('is-invalid');
    $modal.find('.ml-confirm-ok').text(this.getAttribute('data-confirm-button') || 'Confirm');
    $modal.modal('show');
    return false;
  });

  /* ---------- Inline validation errors: bring the first failed field into view ---------- */
  // Server-rendered forms mark failed inputs with .is-invalid (aria-invalid + the message
  // div); after re-render, put the first one under the keyboard/caret immediately.
  (function focusFirstInvalid() {
    var first = document.querySelector('form .form-control.is-invalid, form .custom-select.is-invalid');
    if (first) {
      first.scrollIntoView({ block: 'center', behavior: 'smooth' });
      first.focus({ preventScroll: true });
    }
  })();

  /* ---------- Unsaved-changes guard (opt-in via data-dirty-guard) ---------- */
  $(document).on('input change', 'form[data-dirty-guard] input, form[data-dirty-guard] select, form[data-dirty-guard] textarea', function () {
    if (this.form && !this.form.hasAttribute('data-dirty')) this.form.setAttribute('data-dirty', '1');
  });
  $(window).on('beforeunload', function (e) {
    var dirty = Array.prototype.some.call(document.forms, function (f) {
      return f.hasAttribute('data-dirty-guard') && f.getAttribute('data-dirty') === '1' && f.getAttribute('data-submitted') !== '1';
    });
    if (dirty) {
      e.preventDefault();
      e.returnValue = ''; // Chrome requires returnValue to show the dialog
      return '';
    }
  });

  /* ---------- Money inputs: digits only while typing, thousands separators on blur ---------- */
  // The server stays authoritative (money_in strips separators and validates).
  function sanitizeMoney(v) {
    v = v.replace(/[^0-9.]/g, '');
    var dot = v.indexOf('.');
    if (dot !== -1) {
      v = v.slice(0, dot + 1) + v.slice(dot + 1).replace(/\./g, '');
      if (v.length - dot - 1 > 2) v = v.slice(0, dot + 3); // clamp to 2 decimals
    }
    return v;
  }
  $(document).on('input', 'input[inputmode="decimal"]', function () {
    var clean = sanitizeMoney(this.value);
    if (clean !== this.value) {
      var caret = Math.max(0, this.selectionStart - (this.value.length - clean.length));
      this.value = clean;
      try { this.setSelectionRange(caret, caret); } catch (e) { /* input types without selection */ }
    }
  });
  $(document).on('blur', 'input[inputmode="decimal"]', function () { formatMoneyBlur(this); });
  $(document).on('focus', 'input[inputmode="decimal"]', function () {
    // Plain number while editing; separators return on blur.
    this.value = String(this.value).replace(/,/g, '');
  });
  function formatMoneyBlur(input) {
    var n = parseFloat(String(input.value).replace(/,/g, ''));
    if (input.value !== '' && !isNaN(n) && n >= 0) {
      input.value = n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
  }

  /* ---------- Glossary tooltips (cooperative jargon; open on focus for keyboard users) ---------- */
  $('.ml-gloss').each(function () { $(this).tooltip({ boundary: 'window' }); });

  /* ---------- Session idle warning + keepalive ---------- */
  // Before SESSION_IDLE_SECONDS runs out, offer "Stay logged in", which pings
  // api/keepalive.php to refresh the session's activity timestamp.
  var idleMeta = document.querySelector('meta[name="session-idle"]');
  if (idleMeta) {
    var idleLimit = Number(idleMeta.getAttribute('content')) || 900;
    var warnBefore = 120; // seconds of warning before the server drops the session
    var idleDeadline = Date.now() + idleLimit * 1000;
    var idleModalShown = false;
    var idleCountdown = null;
    var idleTimer = null;
    extendIdle = function () { idleDeadline = Date.now() + idleLimit * 1000; };

    function buildIdleModal() {
      idleModalShown = true;
      var $m = $(
        '<div class="modal fade" id="ml-idle-modal" tabindex="-1" role="dialog" aria-labelledby="ml-idle-title" aria-hidden="true">' +
        '<div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">' +
        '<div class="modal-header"><h5 class="modal-title" id="ml-idle-title"><i class="far fa-clock mr-2" aria-hidden="true"></i>Still there?</h5>' +
        '<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>' +
        '<div class="modal-body"><p class="mb-2">For your protection, MutualLink signs you out after ' + Math.round(idleLimit / 60) + ' minutes of inactivity.</p>' +
        '<p class="mb-0">You will be signed out in <strong id="ml-idle-countdown" class="font-weight-bold">…</strong></p></div>' +
        '<div class="modal-footer"><button type="button" class="btn btn-primary" id="ml-idle-stay">Stay logged in</button></div>' +
        '</div></div></div>'
      ).appendTo('body');
      idleCountdown = document.getElementById('ml-idle-countdown');
      idleTimer = setInterval(function () {
        var left = Math.round((idleDeadline - Date.now()) / 1000);
        if (left <= 0) { // server session has ended → back to the login screen
          clearInterval(idleTimer);
          window.location.href = 'index.php';
          return;
        }
        idleCountdown.textContent = Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0');
      }, 1000);
      return $m;
    }

    setInterval(function () {
      if (Date.now() >= idleDeadline - warnBefore * 1000) {
        if (!idleModalShown) buildIdleModal().modal('show');
      }
    }, 1000);

    $(document).on('click', '#ml-idle-stay', async function () {
      try {
        await apiFetch('api/keepalive.php', { method: 'POST' }); // extends the server clock AND this countdown
        $('#ml-idle-modal').modal('hide');
      } catch (e) { /* 401 sends the user to the login page */ }
    });
  }

  /* ---------- Global member search (navbar): Ctrl+K or "/" opens ---------- */
  var searchModal = document.getElementById('ml-search-modal');
  if (searchModal) {
    var searchInput = document.getElementById('ml-search-input');
    var searchResults = document.getElementById('ml-search-results');
    var searchTimer = null;

    function searchAction(href, icon, label, cls) {
      var a = el('a', null, 'btn btn-sm ' + cls + ' ml-1 mb-1');
      a.href = href;
      var i = document.createElement('i');
      i.className = icon + ' mr-1'; i.setAttribute('aria-hidden', 'true');
      a.appendChild(i);
      a.appendChild(document.createTextNode(label));
      return a;
    }

    function renderSearchResults(rows, q) {
      searchResults.replaceChildren();
      if (!rows.length) {
        searchResults.appendChild(el('div', 'No active member matches \u201C' + q + '\u201D.', 'list-group-item text-muted small'));
        return;
      }
      rows.forEach(function (m) {
        var item = el('div', null, 'list-group-item d-flex flex-wrap align-items-center');
        var open = el('a', null, 'font-weight-bold mr-auto');
        open.href = 'dashboard.php?page=member_view&id=' + encodeURIComponent(m.member_id);
        open.textContent = m.name;
        open.appendChild(el('span', ' \u00B7 ' + m.member_no, 'text-muted small font-weight-normal'));
        item.appendChild(open);
        if (searchModal.getAttribute('data-can-payment')) {
          item.appendChild(searchAction('dashboard.php?page=payment_post&member_id=' + encodeURIComponent(m.member_id),
            'fas fa-cash-register', 'Post payment', 'btn-outline-primary'));
        }
        if (searchModal.getAttribute('data-can-savings')) {
          item.appendChild(searchAction('dashboard.php?page=member_view&id=' + encodeURIComponent(m.member_id) + '#tab-accounts',
            'fas fa-book-open', 'Passbook', 'btn-light border'));
        }
        if (searchModal.getAttribute('data-can-loan')) {
          item.appendChild(searchAction('dashboard.php?page=loan_form&member_id=' + encodeURIComponent(m.member_id),
            'fas fa-file-signature', 'New loan', 'btn-light border'));
        }
        searchResults.appendChild(item);
      });
    }

    $(searchInput).on('input', function () {
      var q = this.value.trim();
      clearTimeout(searchTimer);
      if (q.length < 2) { searchResults.replaceChildren(); return; }
      searchTimer = setTimeout(async function () {
        try {
          renderSearchResults(await apiFetch('api/member_lookup.php?q=' + encodeURIComponent(q)), q);
        } catch (e) {
          searchResults.replaceChildren(el('div', e.message, 'list-group-item text-danger small'));
        }
      }, 250);
    });

    function openSearch() {
      $(searchModal).modal('show'); // focus moves in on shown.bs.modal below
    }
    $(document).on('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && String(e.key).toLowerCase() === 'k') { e.preventDefault(); openSearch(); return; }
      var tag = (e.target.tagName || '').toLowerCase();
      var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;
      if (e.key === '/' && !typing) { e.preventDefault(); openSearch(); }
    });
    // Bootstrap fires this through jQuery AFTER it has focused the modal root itself,
    // so this is the reliable moment to move focus into the search box (keyboard only:
    // open with Ctrl+K or / and type immediately — no click needed).
    $(searchModal).on('shown.bs.modal', function () {
      searchInput.focus();
      searchInput.select(); // a previous search gets replaced when you retype
    });
  }

  /* ---------- Receipt: announce it by moving keyboard focus to the heading ---------- */
  var receiptHeading = document.getElementById('receipt-heading');
  if (receiptHeading) receiptHeading.focus({ preventScroll: true });

  /* ---------- Deep link to a Bootstrap tab (…member_view&id=1#tab-accounts) ---------- */
  if (location.hash && /^#[a-z0-9_-]+$/i.test(location.hash)) {
    var tab = document.querySelector('.nav-tabs a[href="' + location.hash + '"], .nav-pills a[href="' + location.hash + '"]');
    if (tab) $(tab).tab('show');
  }

  /* ---------- DataTables ---------- */
  $('table.js-datatable').each(function () {
    var $t = $(this);
    var exportable = $t.data('export') === true || $t.data('export') === 'true';
    var order = $t.data('order') || [];
    var dt = $t.DataTable({
      responsive: true,
      autoWidth: false,
      pageLength: Number($t.data('page-length')) || 25,
      order: order,
      language: { search: '', searchPlaceholder: 'Search…', emptyTable: $t.data('empty') || 'No records yet.' },
      columnDefs: [{ targets: 'no-sort', orderable: false }],
      dom: exportable ? "<'row'<'col-md-6'B><'col-md-6'f>>rt<'row'<'col-md-5'i><'col-md-7'p>>" : "<'row'<'col-md-6'l><'col-md-6'f>>rt<'row'<'col-md-5'i><'col-md-7'p>>",
      buttons: exportable ? [
        { extend: 'copy', className: 'btn-sm btn-light' },
        { extend: 'csv', className: 'btn-sm btn-light' },
        { extend: 'excel', className: 'btn-sm btn-light' },
        { extend: 'print', className: 'btn-sm btn-light', title: $t.data('title') || document.title }
      ] : []
    });
    return dt;
  });

  /* ---------- Print buttons; data-print="thermal" switches the receipt to
     the ~80mm thermal layout for that print run (restored after printing) ---------- */
  $(document).on('click', '[data-print]', function () {
    var mode = String($(this).data('print') || '');
    if (mode) document.body.setAttribute('data-print-mode', mode);
    else document.body.removeAttribute('data-print-mode');
    window.print();
  });
  $(window).on('afterprint', function () { document.body.removeAttribute('data-print-mode'); });

  /* ---------- Bring a target field into view (sticky mobile summary bars) ---------- */
  $(document).on('click', '[data-focus]', function () {
    var target = document.querySelector(this.getAttribute('data-focus') || '');
    if (target) {
      target.scrollIntoView({ block: 'center', behavior: 'smooth' });
      target.focus({ preventScroll: true });
    }
  });
  if (document.querySelector('.ml-sticky-bar')) document.body.classList.add('has-ml-sticky-bar');

  /* ---------- Live existence check (username, email, member no.) ---------- */
  var checkTimers = {};
  $(document).on('input blur', '[data-check]', function () {
    var input = this;
    var field = input.getAttribute('data-check');
    var value = input.value.trim();
    var $feedback = $(input).siblings('.invalid-feedback.js-exists');
    var form = input.form;
    clearTimeout(checkTimers[field]);
    if (value.length < 3) {
      input.classList.remove('is-invalid', 'is-valid');
      input.dataset.exists = '';
      toggleSubmit(form);
      return;
    }
    checkTimers[field] = setTimeout(async function () {
      try {
        var data = await apiFetch('api/check_existence.php', {
          method: 'POST',
          body: { field: field, value: value, exclude_id: Number(input.getAttribute('data-exclude-id') || 0) }
        });
        input.dataset.exists = data.exists ? '1' : '';
        input.classList.toggle('is-invalid', data.exists);
        input.classList.toggle('is-valid', !data.exists);
        $feedback.text(data.exists ? input.getAttribute('data-check-message') : '');
      } catch (e) {
        input.dataset.exists = '';
        input.classList.remove('is-invalid', 'is-valid');
      }
      toggleSubmit(form);
    }, 350);
  });
  function toggleSubmit(form) {
    if (!form) return;
    var blocked = form.querySelector('[data-check][data-exists="1"]') !== null;
    $(form).find('button[type="submit"]').prop('disabled', blocked);
  }

  /* ---------- Clickable table rows: click anywhere on a row with data-href to open it ---------- */
  // Keyboard users get the same affordance: Tab reaches the row, Enter/Space opens it.
  function initRowTabs() {
    document.querySelectorAll('tr[data-href]').forEach(function (row) {
      if (!row.hasAttribute('tabindex')) row.setAttribute('tabindex', '0');
    });
  }
  initRowTabs();
  $(document).on('draw.dt', initRowTabs); // DataTables re-creates rows on paging/filtering
  $(document).on('keydown', 'tr[data-href]', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    if ($(e.target).closest('a, button, form, input, select').length) return;
    e.preventDefault();
    window.location.href = this.getAttribute('data-href');
  });
  $(document).on('click', 'tr[data-href]', function (e) {
    if ($(e.target).closest('a, button, form, input, select, .btn').length) return;
    window.location.href = this.getAttribute('data-href');
  });

  /* ---------- Member lookup (search box → results list) ---------- */
  var lookupTimer = null;
  $(document).on('input', '[data-member-lookup]', function () {
    var input = this;
    var list = document.querySelector(input.getAttribute('data-member-lookup'));
    var template = input.getAttribute('data-select-url') || '';
    var q = input.value.trim();
    clearTimeout(lookupTimer);
    if (!list) return;
    if (q.length < 2) { list.replaceChildren(); return; }
    lookupTimer = setTimeout(async function () {
      try {
        var rows = await apiFetch('api/member_lookup.php?q=' + encodeURIComponent(q));
        list.replaceChildren();
        if (!rows.length) { list.appendChild(el('div', 'No active member matches “' + q + '”.', 'list-group-item text-muted small')); return; }
        rows.forEach(function (m) {
          var a = el('a', null, 'list-group-item list-group-item-action');
          if (template) {
            a.href = template.replace('{id}', encodeURIComponent(m.member_id));
          } else {
            // Fill mode (co-maker picker): clicking selects the member in place, no navigation
            a.href = '#';
            a.addEventListener('click', function (ev) {
              ev.preventDefault();
              var fill = document.querySelector(input.getAttribute('data-fill-target') || '');
              var nameEl = document.querySelector(input.getAttribute('data-fill-name') || '');
              if (fill) fill.value = m.member_id;
              if (nameEl) {
                var span = nameEl.querySelector('span');
                if (span) span.textContent = m.name + ' · ' + m.member_no;
                nameEl.classList.remove('d-none');
              }
              input.value = m.name;
              list.replaceChildren();
            });
          }
          a.appendChild(el('strong', m.name));
          a.appendChild(el('span', ' · ' + m.member_no, 'text-muted small'));
          list.appendChild(a);
        });
      } catch (e) {
        list.replaceChildren(el('div', e.message, 'list-group-item text-danger small'));
      }
    }, 300);
  });

  /* ---------- Share capital withdrawal: BOD resolution only needed when withdrawing ---------- */
  var bodGroup = document.getElementById('bod-resolution-group');
  if (bodGroup) {
    var syncBod = function () {
      var checked = document.querySelector('[name="txn_type"][value="withdrawal"]:checked');
      bodGroup.classList.toggle('d-none', !checked);
    };
    $(document).on('change', '[name="txn_type"]', syncBod);
    syncBod();
  }

  /* ---------- Amortization preview on the loan application form ---------- */
  var loanForm = document.getElementById('loan-form');
  if (loanForm) {
    var previewBody = document.getElementById('preview-body');
    var previewTotals = document.getElementById('preview-totals');
    var previewTimer = null;
    var runPreview = function () {
      clearTimeout(previewTimer);
      previewTimer = setTimeout(async function () {
        var product = loanForm.querySelector('[name="product_id"]');
        var principal = loanForm.querySelector('[name="principal"]').value;
        var term = loanForm.querySelector('[name="term_months"]').value;
        var opt = product.options[product.selectedIndex];
        var hint = document.getElementById('product-hint');
        if (hint && opt && opt.dataset.min) {
          hint.textContent = 'Allowed: ' + peso(opt.dataset.min) + ' – ' + peso(opt.dataset.max) + ', up to ' + opt.dataset.term + ' months at ' + opt.dataset.rate + '% per month.';
        }
        var netPayWarn = document.getElementById('preview-netpay');
        if (!product.value || !principal || !term) { previewBody.replaceChildren(); previewTotals.textContent = ''; netPayWarn.hidden = true; return; }
        try {
          var data = await apiFetch('api/amortization_preview.php', {
            method: 'POST',
            body: { product_id: Number(product.value), principal: principal, term_months: Number(term),
                    repayment_mode: loanForm.querySelector('[name="repayment_mode"]').value }
          });
          previewBody.replaceChildren();
          data.schedule.forEach(function (r) {
            var tr = document.createElement('tr');
            tr.appendChild(el('td', r.installment_no));
            tr.appendChild(el('td', r.due_date));
            tr.appendChild(el('td', peso(r.principal_due), 'num'));
            tr.appendChild(el('td', peso(r.interest_due), 'num'));
            tr.appendChild(el('td', peso(r.total_due), 'num font-weight-bold'));
            tr.appendChild(el('td', peso(r.semi_monthly), 'num text-muted'));
            tr.appendChild(el('td', peso(r.balance), 'num'));
            previewBody.appendChild(tr);
          });
          previewTotals.textContent = 'Total interest ' + peso(data.total_interest) + ' · Total payable ' + peso(data.total_payable) +
            ' · Est. net proceeds ' + peso(data.deductions.net);
          var netPayRaw = loanForm.querySelector('[name="net_pay"]').value.replace(/,/g, '');
          var netPay = parseFloat(netPayRaw);
          netPayWarn.hidden = true;
          if (opt && opt.dataset.basis === 'net_pay' && netPay > 0 && data.schedule.length) {
            var firstDue = Number(data.schedule[0].total_due);
            if (firstDue > netPay) {
              netPayWarn.textContent = 'Monthly amortization ' + peso(firstDue) + ' exceeds the monthly net pay of ' + peso(netPay) +
                ' — a Salary Loan may not cost more per month than the member earns.';
              netPayWarn.hidden = false;
            }
          }
        } catch (e) {
          previewBody.replaceChildren();
          previewTotals.textContent = e.message;
          netPayWarn.hidden = true;
        }
      }, 350);
    };
    $(loanForm).on('input change', 'input, select', runPreview);
    runPreview();
  }

  /* ---------- Payment split preview (display only; the server recomputes) ----------
     Same order as allocate_payment(): penalty after term → interest after term →
     each installment oldest first (interest, then principal). Works in centavos. */
  var amountInput = document.getElementById('payment-amount');
  if (amountInput && amountInput.dataset.alloc) {
    var alloc = JSON.parse(amountInput.dataset.alloc);
    var c = function (n) { return Math.round(Number(n) * 100); };
    var render = function () {
      var left = c(String(amountInput.value || 0).replace(/,/g, ''));
      var take = function (due) { var t = Math.min(left, c(due)); left -= t; return t; };
      var pen = take(alloc.penalty);
      var interest = take(alloc.pd_interest);
      var principal = 0;
      var covered = [];
      alloc.installments.forEach(function (inst) {
        if (left <= 0) return;
        var i = take(inst.interest);
        var p = take(inst.principal);
        interest += i; principal += p;
        if (i + p > 0) covered.push(inst.no);
      });
      document.getElementById('split-penalty').textContent = peso(pen / 100);
      document.getElementById('split-interest').textContent = peso(interest / 100);
      document.getElementById('split-principal').textContent = peso(principal / 100);
      document.getElementById('split-installments').textContent = covered.length ? covered.join(', ') : '—';
      var warn = document.getElementById('split-excess');
      warn.hidden = left <= 0;
      warn.textContent = left > 0 ? 'Amount is ' + peso(left / 100) + ' more than the full payoff of ' + peso(amountInput.dataset.payoff) + '.' : '';
    };
  $(amountInput).on('input', render);
  $(document).on('click', '[data-fill-amount]', function () {
    amountInput.value = this.getAttribute('data-fill-amount'); // plain number; the split preview strips separators anyway
    render();
  });
  render();
  }

  /* ---------- Charts (Chart.js 2.x) ---------- */
  document.querySelectorAll('canvas[data-chart]').forEach(function (canvas) {
    var labels = JSON.parse(canvas.getAttribute('data-labels') || '[]');
    var values = JSON.parse(canvas.getAttribute('data-values') || '[]');
    new Chart(canvas.getContext('2d'), {
      type: canvas.getAttribute('data-chart'),
      data: { labels: labels, datasets: [{ label: canvas.getAttribute('data-label') || '', data: values, backgroundColor: 'rgba(31,107,129,.8)', borderRadius: 4 }] },
      options: {
        maintainAspectRatio: false,
        legend: { display: false },
        scales: { yAxes: [{ ticks: { beginAtZero: true, callback: function (v) { return peso(v).replace('.00', ''); } } }], xAxes: [{ gridLines: { display: false } }] },
        tooltips: { callbacks: { label: function (item) { return peso(item.yLabel); } } }
      }
    });

    // Screen-reader mirror of the chart: a visually hidden table with the same numbers.
    var wrap = document.createElement('div');
    wrap.className = 'sr-only';
    var table = document.createElement('table');
    var caption = document.createElement('caption');
    caption.textContent = canvas.getAttribute('data-label') || 'Chart data';
    var head = document.createElement('thead');
    var headRow = document.createElement('tr');
    ['Period', 'Value'].forEach(function (h) {
      var th = document.createElement('th');
      th.scope = 'col';
      th.textContent = h;
      headRow.appendChild(th);
    });
    head.appendChild(headRow);
    var body = document.createElement('tbody');
    labels.forEach(function (label, i) {
      var tr = document.createElement('tr');
      var th = document.createElement('th');
      th.scope = 'row';
      th.textContent = label;
      tr.appendChild(th);
      tr.appendChild(el('td', values[i]));
      body.appendChild(tr);
    });
    table.appendChild(caption);
    table.appendChild(head);
    table.appendChild(body);
    wrap.appendChild(table);
    canvas.after(wrap);
  });
})(jQuery);
